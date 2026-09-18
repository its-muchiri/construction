<?php

namespace Construction\Core;

use PDO;

/**
 * Construction.co.ke's implementation of the shared Escrow & Commission
 * Engine (planning/00-portfolio/shared-architecture.md module 2), configured
 * for this platform's Tier 3 behavior: **per-milestone** holds (not one hold
 * per booking, unlike laundry.co.ke/event.co.ke's reference implementation —
 * see database-schema.md's project_milestones table) with a
 * retention-percentage held back on the final milestone pending a
 * post-completion inspection window (user-flows.md steps 8-10,
 * shared-architecture.md's trust-tiering table).
 *
 * Real B2C disbursement (moving KES to the provider's M-Pesa/bank account)
 * and a scheduled payout-batch job are out of scope for this pass, same as
 * every other platform in the portfolio — release() records the payout as a
 * `payments` row with status='pending'. Since this app runs as stateless
 * Vercel PHP functions with no cron/queue, the inspection-window auto-release
 * (user-flows.md step 10: "if no dispute is raised... the final tranche
 * auto-releases") is implemented as a lazy check —
 * releaseRetentionIfDue() runs opportunistically whenever a booking/its
 * milestones are read (see ProjectController::show/listMilestones) rather
 * than via a background job.
 */
final class Escrow
{
    /** Proposed defaults per prd.md's Revenue Line table — needs stakeholder input (open-questions.md notes this as unresolved). */
    private const DEFAULT_COMMISSION_PERCENTAGE = [
        'equipment_rental' => 12.0,
        'labor_contract' => 10.0,
    ];

    /** Tier 3 default per shared-architecture.md's trust-tiering table. */
    public const DEFAULT_RETENTION_PERCENTAGE = 10.0;

    /** Tier 3 default inspection window per shared-architecture.md / open-questions.md. */
    public const INSPECTION_WINDOW_DAYS = 7;

    /**
     * Opens an escrow hold for a milestone's completed charge, and links it
     * back to the milestone. Called once a payment (STK callback, or a
     * synchronous card charge) confirms success.
     */
    public static function holdForMilestone(
        PDO $db,
        int $paymentId,
        int $milestoneId,
        int $bookingId,
        float $amount,
        float $retentionPercentage
    ): void {
        $stmt = $db->prepare(
            'INSERT INTO escrow_transactions
                (payment_id, booking_id, held_amount, retention_percentage, release_condition, release_at, status)
             VALUES (:payment_id, :booking_id, :held_amount, :retention_percentage, \'customer_confirmation\', NULL, \'held\')'
        );
        $stmt->execute([
            'payment_id' => $paymentId,
            'booking_id' => $bookingId,
            'held_amount' => $amount,
            'retention_percentage' => $retentionPercentage,
        ]);
        $escrowId = (int) $db->lastInsertId();

        $update = $db->prepare('UPDATE project_milestones SET escrow_transaction_id = :escrow_id WHERE id = :id');
        $update->execute(['escrow_id' => $escrowId, 'id' => $milestoneId]);
    }

    /**
     * Releases a milestone's held escrow to the booking's provider minus
     * platform commission, on customer (or Site Inspector) confirmation.
     * For the final milestone of a booking with a nonzero retention
     * percentage, only the non-retained portion releases now; the retained
     * portion stays held and the booking enters its inspection window (see
     * releaseRetentionIfDue()). No-ops (returns null) if nothing is held for
     * this milestone yet (customer confirmed before funding it).
     *
     * @return array{commission_amount:float,payout_amount:float,retained_amount:float}|null
     */
    public static function releaseMilestone(PDO $db, array $milestone, array $booking): ?array
    {
        if (!$milestone['escrow_transaction_id']) {
            return null;
        }

        $stmt = $db->prepare('SELECT * FROM escrow_transactions WHERE id = :id AND status = \'held\' FOR UPDATE');
        $stmt->execute(['id' => $milestone['escrow_transaction_id']]);
        $escrow = $stmt->fetch();

        if (!$escrow) {
            return null;
        }

        $providerId = (int) $booking['provider_id'];
        $isFinalMilestone = self::isFinalMilestone($db, (int) $milestone['booking_id'], (int) $milestone['sequence_number']);

        $heldAmount = (float) $escrow['held_amount'];
        $retentionPercentage = $isFinalMilestone ? (float) $escrow['retention_percentage'] : 0.0;
        $commissionRate = self::commissionRate($db, (string) $booking['booking_type']);

        $commissionAmount = round($heldAmount * $commissionRate / 100, 2);
        $retainedAmount = round($heldAmount * $retentionPercentage / 100, 2);
        $payoutAmount = round($heldAmount - $commissionAmount - $retainedAmount, 2);

        $paymentStmt = $db->prepare('SELECT method FROM payments WHERE id = :id');
        $paymentStmt->execute(['id' => $escrow['payment_id']]);
        $originalMethod = $paymentStmt->fetchColumn() ?: 'mpesa_stk';

        $insertPayment = $db->prepare(
            'INSERT INTO payments (user_id, booking_id, type, method, amount, currency, status, created_at, updated_at)
             VALUES (:user_id, :booking_id, :type, :method, :amount, \'KES\', :status, NOW(), NOW())'
        );

        // Platform commission — a ledger entry, not an external transfer.
        $insertPayment->execute([
            'user_id' => $providerId,
            'booking_id' => $milestone['booking_id'],
            'type' => 'commission',
            'method' => $originalMethod,
            'amount' => $commissionAmount,
            'status' => 'completed',
        ]);

        // Provider payout — the actual outbound leg, executed later by a
        // payout-batch job against Daraja's B2C API (not built this pass).
        $insertPayment->execute([
            'user_id' => $providerId,
            'booking_id' => $milestone['booking_id'],
            'type' => 'payout',
            'method' => 'mpesa_b2c',
            'amount' => $payoutAmount,
            'status' => 'pending',
        ]);

        $milestoneStmt = $db->prepare('UPDATE project_milestones SET status = \'released\' WHERE id = :id');

        if ($retainedAmount > 0) {
            // Final milestone with a retention hold: keep the retained slice
            // in escrow and open the inspection window instead of marking
            // this milestone (and the escrow row) fully released yet.
            $db->prepare('UPDATE escrow_transactions SET status = \'partially_released\', held_amount = :retained WHERE id = :id')
                ->execute(['retained' => $retainedAmount, 'id' => $escrow['id']]);

            $windowEnds = (new \DateTimeImmutable('+' . self::INSPECTION_WINDOW_DAYS . ' days'))->format('Y-m-d H:i:s');
            $db->prepare(
                'UPDATE construction_bookings SET status = \'inspection_window\', inspection_window_ends_at = :ends, updated_at = NOW() WHERE id = :id'
            )->execute(['ends' => $windowEnds, 'id' => $milestone['booking_id']]);

            // The milestone itself is "released" from the customer's
            // perspective (the non-retained portion paid out); the retained
            // slice is tracked on the escrow row until the window elapses.
            $milestoneStmt->execute(['id' => $milestone['id']]);
        } else {
            $db->prepare('UPDATE escrow_transactions SET status = \'released\', released_at = NOW() WHERE id = :id')
                ->execute(['id' => $escrow['id']]);
            $milestoneStmt->execute(['id' => $milestone['id']]);

            if ($isFinalMilestone) {
                $db->prepare('UPDATE construction_bookings SET status = \'completed\', updated_at = NOW() WHERE id = :id')
                    ->execute(['id' => $milestone['booking_id']]);
            }
        }

        return [
            'commission_amount' => $commissionAmount,
            'payout_amount' => $payoutAmount,
            'retained_amount' => $retainedAmount,
        ];
    }

    /**
     * Lazy auto-release for the inspection-window-held retention slice
     * (user-flows.md step 10). Called opportunistically on booking reads.
     * No-ops unless the booking is in 'inspection_window', the window has
     * elapsed, and no open/under_review dispute exists for it.
     *
     * @return array{payout_amount:float}|null
     */
    public static function releaseRetentionIfDue(PDO $db, array $booking): ?array
    {
        if ($booking['status'] !== 'inspection_window' || !$booking['inspection_window_ends_at']) {
            return null;
        }
        if (strtotime((string) $booking['inspection_window_ends_at']) > time()) {
            return null;
        }

        $disputeStmt = $db->prepare(
            "SELECT COUNT(*) FROM disputes WHERE booking_id = :booking_id AND status IN ('open', 'under_review')"
        );
        $disputeStmt->execute(['booking_id' => $booking['id']]);
        if ((int) $disputeStmt->fetchColumn() > 0) {
            return null;
        }

        $escrowStmt = $db->prepare(
            'SELECT * FROM escrow_transactions WHERE booking_id = :booking_id AND status = \'partially_released\' FOR UPDATE'
        );
        $escrowStmt->execute(['booking_id' => $booking['id']]);
        $escrow = $escrowStmt->fetch();
        if (!$escrow) {
            return null;
        }

        $payoutAmount = (float) $escrow['held_amount'];
        $paymentStmt = $db->prepare('SELECT method FROM payments WHERE id = :id');
        $paymentStmt->execute(['id' => $escrow['payment_id']]);
        $originalMethod = $paymentStmt->fetchColumn() ?: 'mpesa_stk';

        $db->prepare(
            'INSERT INTO payments (user_id, booking_id, type, method, amount, currency, status, created_at, updated_at)
             VALUES (:user_id, :booking_id, \'payout\', :method, :amount, \'KES\', \'pending\', NOW(), NOW())'
        )->execute([
            'user_id' => $booking['provider_id'],
            'booking_id' => $booking['id'],
            'method' => $originalMethod,
            'amount' => $payoutAmount,
        ]);

        $db->prepare('UPDATE escrow_transactions SET status = \'released\', released_at = NOW() WHERE id = :id')
            ->execute(['id' => $escrow['id']]);
        $db->prepare('UPDATE construction_bookings SET status = \'completed\', updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $booking['id']]);

        return ['payout_amount' => $payoutAmount];
    }

    private static function isFinalMilestone(PDO $db, int $bookingId, int $sequenceNumber): bool
    {
        $stmt = $db->prepare('SELECT MAX(sequence_number) FROM project_milestones WHERE booking_id = :booking_id');
        $stmt->execute(['booking_id' => $bookingId]);
        return (int) $stmt->fetchColumn() === $sequenceNumber;
    }

    private static function commissionRate(PDO $db, string $bookingType): float
    {
        $stmt = $db->prepare(
            "SELECT value, commission_type FROM commission_rules
             WHERE category = :category
                AND effective_from <= NOW()
                AND (effective_to IS NULL OR effective_to > NOW())
             ORDER BY effective_from DESC LIMIT 1"
        );
        $stmt->execute(['category' => $bookingType]);
        $rule = $stmt->fetch();

        if ($rule && $rule['commission_type'] === 'percentage') {
            return (float) $rule['value'];
        }

        return self::DEFAULT_COMMISSION_PERCENTAGE[$bookingType] ?? 12.0;
    }
}
