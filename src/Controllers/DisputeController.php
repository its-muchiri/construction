<?php

namespace Construction\Controllers;

use Construction\Config\Database;
use Construction\Core\Escrow;
use Construction\Core\Request;
use Construction\Core\Response;

/**
 * Category taxonomy (planning/03-construction-co-ke/database-schema.md):
 * equipment_damage, work_quality, non_completion, payment_delay, other.
 *
 * The equipment_damage resolution logic (comparing handover/return
 * equipment_condition_reports, applying the retention percentage, and
 * routing liability beyond it) is explicitly UNRESOLVED — see
 * open-questions.md #1-#2 (insurance partnership vs. deposit-only model).
 * This controller only stores/lists/marks-resolved; it does not implement
 * the liability calculation.
 */
final class DisputeController
{
    public function store(Request $request): void
    {
        if (!$request->user) {
            Response::unauthorized('Sign in to raise a dispute');
            return;
        }

        $db = Database::connection();
        $category = $request->input('category');
        $evidenceUrls = $request->input('evidence_urls', []);
        if (!is_array($evidenceUrls)) {
            $evidenceUrls = [];
        }

        // Auto-attach this booking's handover/return condition reports as
        // baseline evidence for equipment_damage disputes — user-flows.md's
        // Equipment-Damage Dispute Flow step 2. Condition reports are the
        // central evidence table for this flow (database-schema.md); the
        // Site Inspector compares handover vs. return photos/notes against
        // whatever additional evidence the claimant attaches here.
        if ($category === 'equipment_damage') {
            $reportStmt = $db->prepare(
                'SELECT report_type, photo_urls, video_url FROM equipment_condition_reports WHERE booking_id = :booking_id ORDER BY created_at ASC'
            );
            $reportStmt->execute(['booking_id' => $request->params['id']]);
            foreach ($reportStmt->fetchAll() as $report) {
                $photos = json_decode((string) $report['photo_urls'], true) ?: [];
                foreach ($photos as $photoUrl) {
                    $evidenceUrls[] = $photoUrl;
                }
                if ($report['video_url']) {
                    $evidenceUrls[] = $report['video_url'];
                }
            }
        }

        $stmt = $db->prepare(
            'INSERT INTO disputes (booking_id, raised_by, category, description, evidence_urls, status, created_at)
             VALUES (:booking_id, :raised_by, :category, :description, :evidence_urls, \'open\', NOW())'
        );
        $stmt->execute([
            'booking_id' => $request->params['id'],
            'raised_by' => $request->user['id'] ?? null,
            'category' => $category,
            'description' => $request->input('description'),
            'evidence_urls' => json_encode(array_values(array_unique($evidenceUrls))),
        ]);

        // Freezes Escrow::releaseRetentionIfDue's inspection-window
        // auto-release (it already independently checks for an open
        // dispute) and surfaces the dispute on the booking's own status.
        $db->prepare('UPDATE construction_bookings SET status = \'disputed\', updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $request->params['id']]);

        Response::json(['id' => (int) $db->lastInsertId(), 'status' => 'open'], 201);
    }

    public function index(Request $request): void
    {
        if (!$request->user || $request->user['account_type'] !== 'admin') {
            Response::forbidden('Only a Platform Admin or Site Inspector can view the dispute queue');
            return;
        }

        $db = Database::connection();
        $stmt = $db->query('SELECT * FROM disputes WHERE status IN (\'open\', \'under_review\') ORDER BY created_at ASC');

        Response::json($stmt->fetchAll());
    }

    public function resolve(Request $request): void
    {
        if (!$request->user || $request->user['account_type'] !== 'admin') {
            Response::forbidden('Only a Platform Admin or Site Inspector can resolve a dispute');
            return;
        }

        $db = Database::connection();
        $disputeStmt = $db->prepare('SELECT * FROM disputes WHERE id = :id');
        $disputeStmt->execute(['id' => $request->params['id']]);
        $dispute = $disputeStmt->fetch();
        if (!$dispute) {
            Response::notFound('Dispute not found');
            return;
        }

        $status = $request->input('status');
        $stmt = $db->prepare(
            'UPDATE disputes SET status = :status, resolved_by = :resolved_by, resolution_notes = :notes, resolved_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'resolved_by' => $request->user['id'],
            'notes' => $request->input('resolution_notes'),
            'id' => $request->params['id'],
        ]);

        // Un-freeze the booking now that this dispute is no longer open —
        // fall back to whichever state the escrow hold implies (still
        // within the inspection window, or ready to auto-complete) rather
        // than assuming 'completed' outright, since resolving one dispute
        // doesn't guarantee no other open dispute remains on the booking.
        if (str_starts_with((string) $status, 'resolved_') || $status === 'escalated') {
            $openStmt = $db->prepare(
                "SELECT COUNT(*) FROM disputes WHERE booking_id = :booking_id AND status IN ('open', 'under_review')"
            );
            $openStmt->execute(['booking_id' => $dispute['booking_id']]);
            if ((int) $openStmt->fetchColumn() === 0) {
                $escrowStmt = $db->prepare(
                    'SELECT COUNT(*) FROM escrow_transactions WHERE booking_id = :booking_id AND status = \'partially_released\''
                );
                $escrowStmt->execute(['booking_id' => $dispute['booking_id']]);
                $restoredStatus = (int) $escrowStmt->fetchColumn() > 0 ? 'inspection_window' : 'completed';
                $db->prepare('UPDATE construction_bookings SET status = :status, updated_at = NOW() WHERE id = :id')
                    ->execute(['status' => $restoredStatus, 'id' => $dispute['booking_id']]);

                $bookingStmt = $db->prepare('SELECT * FROM construction_bookings WHERE id = :id');
                $bookingStmt->execute(['id' => $dispute['booking_id']]);
                $booking = $bookingStmt->fetch();
                if ($booking) {
                    Escrow::releaseRetentionIfDue($db, $booking);
                }
            }
        }

        Response::json(['id' => (int) $request->params['id'], 'status' => $status]);
    }
}
