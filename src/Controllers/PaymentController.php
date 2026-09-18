<?php

namespace Construction\Controllers;

use Construction\Config\Database;
use Construction\Core\Escrow;
use Construction\Core\Mpesa;
use Construction\Core\Request;
use Construction\Core\Response;
use PDOException;
use Throwable;

/**
 * M-Pesa Daraja + card payment endpoints, funding a single
 * project_milestones tranche per charge (construction bookings are paid in
 * milestones, unlike the single-payment-per-booking model on the rest of
 * the portfolio — see database-schema.md and src/Core/Escrow.php). The
 * Daraja client lives in src/Core/Mpesa.php and the escrow hold/release
 * logic in src/Core/Escrow.php, mirrored from laundry.co.ke's reference
 * implementation. See shared-architecture.md's M-Pesa callback idempotency
 * section for the idempotency contract mpesaCallback() implements.
 */
final class PaymentController
{
    /**
     * Initiates an M-Pesa STK Push for a milestone's amount. Records a
     * pending `payments` row (linked to the milestone via milestone_id, a
     * construction.co.ke-specific extension of the shared payments table)
     * keyed by Daraja's CheckoutRequestID — mpesaCallback() reconciles it
     * once Safaricom calls back and opens the escrow hold.
     */
    public function stkPush(Request $request): void
    {
        if (!$request->user) {
            Response::unauthorized('Sign in as the customer to fund this milestone');
            return;
        }

        [$milestone, $booking, $error] = $this->resolveFundableMilestone($request);
        if ($error !== null) {
            Response::error($error['message'], $error['status']);
            return;
        }

        $phone = trim((string) $request->input('phone', $request->user['phone_number'] ?? ''));
        if ($phone === '') {
            Response::error('A phone number is required to send the M-Pesa prompt', 422);
            return;
        }
        $phone = Mpesa::normalizePhone($phone);
        if (!preg_match('/^254(7|1)\d{8}$/', $phone)) {
            Response::error('Enter a valid Kenyan phone number (e.g. 07XXXXXXXX)', 422);
            return;
        }

        $amount = (float) $milestone['amount'];

        if (!Mpesa::isConfigured()) {
            Response::error(
                'M-Pesa is not configured in this environment (MPESA_* env vars are empty) — '
                    . 'STK Push cannot be sent. See .env.example.',
                503
            );
            return;
        }

        try {
            $daraja = Mpesa::stkPush(
                $phone,
                $amount,
                'MILESTONE' . $milestone['id'],
                'construction.co.ke project #' . $booking['id'] . ' — ' . $milestone['description']
            );
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Could not reach M-Pesa: ' . $e->getMessage(), 502);
            return;
        }

        $checkoutRequestId = $daraja['CheckoutRequestID'] ?? null;
        if (!$checkoutRequestId) {
            Response::error('M-Pesa did not return a CheckoutRequestID', 502);
            return;
        }

        $db = Database::connection();
        $insert = $db->prepare(
            'INSERT INTO payments (user_id, booking_id, milestone_id, type, method, amount, currency, external_reference, status, created_at, updated_at)
             VALUES (:user_id, :booking_id, :milestone_id, \'charge\', \'mpesa_stk\', :amount, \'KES\', :external_reference, \'pending\', NOW(), NOW())'
        );
        $insert->execute([
            'user_id' => $request->user['id'],
            'booking_id' => $booking['id'],
            'milestone_id' => $milestone['id'],
            'amount' => $amount,
            'external_reference' => $checkoutRequestId,
        ]);

        Response::json([
            'status' => 'stk_push_initiated',
            'milestone_id' => (int) $milestone['id'],
            'checkout_request_id' => $checkoutRequestId,
            'merchant_request_id' => $daraja['MerchantRequestID'] ?? null,
            'customer_message' => $daraja['CustomerMessage'] ?? 'Enter your M-Pesa PIN on your phone to complete payment.',
        ], 202);
    }

    /**
     * Card gateway is this platform's other MVP-critical rail per prd.md
     * (given transaction values up to KES 5,000,000+, STK Push alone is a
     * poor fit) but no vendor has been selected/authorized yet —
     * CARD_GATEWAY_* env vars are placeholders in .env.example with no
     * chosen provider. Fails soft the same way stkPush does when M-Pesa is
     * unconfigured, rather than faking a successful charge. See
     * MVP_STATUS.md's Known issues.
     */
    public function card(Request $request): void
    {
        if (!$request->user) {
            Response::unauthorized('Sign in as the customer to fund this milestone');
            return;
        }

        [, , $error] = $this->resolveFundableMilestone($request);
        if ($error !== null) {
            Response::error($error['message'], $error['status']);
            return;
        }

        if (!getenv('CARD_GATEWAY_SECRET_KEY') || !getenv('CARD_GATEWAY_PUBLIC_KEY')) {
            Response::error(
                'Card payments are not configured in this environment (CARD_GATEWAY_* env vars are empty, '
                    . 'no gateway vendor selected yet) — pay this milestone with M-Pesa STK Push instead.',
                503
            );
            return;
        }

        // No card gateway vendor is wired up yet even when keys are present
        // (open-questions.md does not resolve which one) — see
        // MVP_STATUS.md's Known issues rather than faking a charge here.
        Response::error('Card payments are not implemented yet.', 501);
    }

    /**
     * M-Pesa STK Push callback receiver. Idempotent per
     * shared-architecture.md: every inbound callback is logged to
     * payment_callbacks_log keyed by CheckoutRequestID (UNIQUE) before any
     * processing; a duplicate delivery hits the unique constraint and is
     * treated as a no-op success rather than reprocessed.
     */
    public function mpesaCallback(Request $request): void
    {
        $payload = $request->body;
        if (empty($payload)) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $callback = $payload['Body']['stkCallback'] ?? null;
        $checkoutRequestId = $callback['CheckoutRequestID'] ?? null;

        if (!is_array($callback) || !$checkoutRequestId) {
            error_log('M-Pesa callback received with no recognizable stkCallback payload: ' . json_encode($payload));
            Response::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
            return;
        }

        $db = Database::connection();

        try {
            $logStmt = $db->prepare(
                'INSERT INTO payment_callbacks_log (checkout_request_id, raw_payload, created_at) VALUES (:id, :payload, NOW())'
            );
            $logStmt->execute(['id' => $checkoutRequestId, 'payload' => json_encode($payload)]);
        } catch (PDOException $e) {
            // SQLSTATE 23000 (MySQL duplicate entry) / 23505 (Postgres unique
            // violation) — we've already processed this CheckoutRequestID.
            // Per Daraja's integration guidance, ack success without
            // reprocessing rather than erroring.
            if ($e->getCode() === '23000' || $e->getCode() === '23505') {
                Response::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
                return;
            }
            throw $e;
        }

        $resultCode = (int) ($callback['ResultCode'] ?? 1);

        $paymentStmt = $db->prepare('SELECT * FROM payments WHERE external_reference = :ref AND method = \'mpesa_stk\' LIMIT 1');
        $paymentStmt->execute(['ref' => $checkoutRequestId]);
        $payment = $paymentStmt->fetch();

        if (!$payment) {
            error_log("M-Pesa callback for unknown CheckoutRequestID {$checkoutRequestId}");
            $this->markCallbackProcessed($db, $checkoutRequestId);
            Response::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
            return;
        }

        $db->beginTransaction();
        try {
            if ($resultCode === 0) {
                $metadata = self::flattenCallbackMetadata($callback);

                $update = $db->prepare(
                    'UPDATE payments SET status = \'completed\', external_reference = :ref, updated_at = NOW() WHERE id = :id'
                );
                $update->execute([
                    'ref' => (string) ($metadata['MpesaReceiptNumber'] ?? $checkoutRequestId),
                    'id' => $payment['id'],
                ]);

                if ($payment['milestone_id']) {
                    $this->holdMilestoneEscrow($db, (int) $payment['id'], (int) $payment['milestone_id'], (float) $payment['amount']);
                }
            } else {
                $update = $db->prepare('UPDATE payments SET status = \'failed\', updated_at = NOW() WHERE id = :id');
                $update->execute(['id' => $payment['id']]);
            }

            $this->markCallbackProcessed($db, $checkoutRequestId);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log((string) $e);
        }

        // Daraja expects this exact ack envelope regardless of our internal
        // outcome — a non-success ack just makes Safaricom retry delivery.
        Response::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function myEarnings(Request $request): void
    {
        if (!$request->user) {
            Response::unauthorized('Sign in as a provider to view earnings');
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM payments WHERE user_id = :user_id AND type IN (\'payout\', \'commission\') ORDER BY created_at DESC'
        );
        $stmt->execute(['user_id' => $request->user['id'] ?? null]);

        Response::json($stmt->fetchAll());
    }

    /**
     * Resolves and validates the milestone a payment endpoint is funding:
     * belongs to the caller, is the next unfunded milestone in sequence
     * (milestones fund one at a time as the project reaches them, per
     * user-flows.md step 4), and isn't already funded.
     *
     * @return array{0: array<string,mixed>|null, 1: array<string,mixed>|null, 2: array{message:string,status:int}|null}
     */
    private function resolveFundableMilestone(Request $request): array
    {
        $milestoneId = (int) $request->input('milestone_id', 0);
        if ($milestoneId <= 0) {
            return [null, null, ['message' => 'milestone_id is required', 'status' => 422]];
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM project_milestones WHERE id = :id');
        $stmt->execute(['id' => $milestoneId]);
        $milestone = $stmt->fetch();
        if (!$milestone) {
            return [null, null, ['message' => 'Milestone not found', 'status' => 404]];
        }

        $bookingStmt = $db->prepare('SELECT * FROM construction_bookings WHERE id = :id');
        $bookingStmt->execute(['id' => $milestone['booking_id']]);
        $booking = $bookingStmt->fetch();
        if (!$booking) {
            return [null, null, ['message' => 'Project not found', 'status' => 404]];
        }
        if ((int) $booking['customer_id'] !== (int) $request->user['id']) {
            return [null, null, ['message' => 'This project does not belong to you', 'status' => 403]];
        }
        if ($milestone['escrow_transaction_id']) {
            return [null, null, ['message' => 'This milestone has already been funded', 'status' => 409]];
        }

        $priorUnfundedStmt = $db->prepare(
            'SELECT COUNT(*) FROM project_milestones
             WHERE booking_id = :booking_id AND sequence_number < :sequence_number AND escrow_transaction_id IS NULL'
        );
        $priorUnfundedStmt->execute([
            'booking_id' => $milestone['booking_id'],
            'sequence_number' => $milestone['sequence_number'],
        ]);
        if ((int) $priorUnfundedStmt->fetchColumn() > 0) {
            return [null, null, ['message' => 'Fund this project\'s earlier milestones first', 'status' => 409]];
        }

        return [$milestone, $booking, null];
    }

    private function holdMilestoneEscrow(\PDO $db, int $paymentId, int $milestoneId, float $amount): void
    {
        $milestoneStmt = $db->prepare('SELECT * FROM project_milestones WHERE id = :id');
        $milestoneStmt->execute(['id' => $milestoneId]);
        $milestone = $milestoneStmt->fetch();
        if (!$milestone) {
            return;
        }

        $bookingStmt = $db->prepare('SELECT * FROM construction_bookings WHERE id = :id');
        $bookingStmt->execute(['id' => $milestone['booking_id']]);
        $booking = $bookingStmt->fetch();
        if (!$booking) {
            return;
        }

        $maxSequenceStmt = $db->prepare('SELECT MAX(sequence_number) FROM project_milestones WHERE booking_id = :booking_id');
        $maxSequenceStmt->execute(['booking_id' => $milestone['booking_id']]);
        $isFinalMilestone = (int) $maxSequenceStmt->fetchColumn() === (int) $milestone['sequence_number'];

        $retentionPercentage = $isFinalMilestone ? (float) $booking['retention_percentage'] : 0.0;

        Escrow::holdForMilestone($db, $paymentId, $milestoneId, (int) $milestone['booking_id'], $amount, $retentionPercentage);
    }

    private function markCallbackProcessed(\PDO $db, string $checkoutRequestId): void
    {
        $stmt = $db->prepare('UPDATE payment_callbacks_log SET processed_at = NOW() WHERE checkout_request_id = :id');
        $stmt->execute(['id' => $checkoutRequestId]);
    }

    /** @return array<string,mixed> */
    private static function flattenCallbackMetadata(array $callback): array
    {
        $items = $callback['CallbackMetadata']['Item'] ?? [];
        $flat = [];
        foreach ($items as $item) {
            if (isset($item['Name'])) {
                $flat[$item['Name']] = $item['Value'] ?? null;
            }
        }
        return $flat;
    }
}
