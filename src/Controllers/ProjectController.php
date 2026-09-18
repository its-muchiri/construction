<?php

namespace Construction\Controllers;

use Construction\Config\Database;
use Construction\Core\Escrow;
use Construction\Core\Request;
use Construction\Core\Response;

/**
 * Maps to the "Bookings / Projects", "Milestones", and the condition-report
 * part of "Platform-Specific Resources" in
 * planning/03-construction-co-ke/api-endpoints.md. This is a quote-based
 * booking model (not direct booking) — see user-flows.md's Primary
 * Customer Journey steps 1-4.
 */
final class ProjectController
{
    public function create(Request $request): void
    {
        if (!$request->user) {
            Response::unauthorized('Sign in as a customer to post a project');
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO construction_bookings
                (customer_id, booking_type, category, status, location_address, location_lat, location_lng,
                 rental_start_date, rental_end_date, project_start_date, project_end_date,
                 total_contract_value, retention_percentage, created_at, updated_at)
             VALUES (:customer_id, :booking_type, :category, \'open_for_quotes\', :location_address, :location_lat, :location_lng,
                 :rental_start, :rental_end, :project_start, :project_end,
                 0, :retention_percentage, NOW(), NOW())'
        );

        $stmt->execute([
            'customer_id' => $request->user['id'] ?? null,
            'booking_type' => $request->input('booking_type'),
            'category' => $request->input('category'),
            'location_address' => $request->input('location_address'),
            'location_lat' => $request->input('location_lat'),
            'location_lng' => $request->input('location_lng'),
            'rental_start' => $request->input('rental_start_date'),
            'rental_end' => $request->input('rental_end_date'),
            'project_start' => $request->input('project_start_date'),
            'project_end' => $request->input('project_end_date'),
            // TODO: retention percentage should come from this platform's
            // trust-tier config (see shared-architecture.md), not a request input.
            'retention_percentage' => $request->input('retention_percentage', 10),
        ]);

        Response::json(['id' => (int) $db->lastInsertId(), 'status' => 'open_for_quotes'], 201);
    }

    public function index(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->query('SELECT * FROM construction_bookings WHERE status = \'open_for_quotes\' ORDER BY created_at DESC');

        Response::json($stmt->fetchAll());
    }

    public function show(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM construction_bookings WHERE id = :id');
        $stmt->execute(['id' => $request->params['id']]);
        $booking = $stmt->fetch();

        if (!$booking) {
            Response::notFound('Project not found');
            return;
        }

        // Lazy auto-release of the inspection-window-held retention slice —
        // see src/Core/Escrow.php's docblock for why this is a read-time
        // check rather than a scheduled job.
        Escrow::releaseRetentionIfDue($db, $booking);
        $stmt->execute(['id' => $request->params['id']]);
        $booking = $stmt->fetch();

        Response::json($booking);
    }

    public function submitQuote(Request $request): void
    {
        if (!$request->user) {
            Response::unauthorized('Sign in as a provider to submit a quote');
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO project_quotes (booking_id, provider_id, quoted_amount, proposed_start_date, conditions, status, created_at)
             VALUES (:booking_id, :provider_id, :quoted_amount, :proposed_start_date, :conditions, \'submitted\', NOW())'
        );
        $stmt->execute([
            'booking_id' => $request->params['id'],
            'provider_id' => $request->user['id'] ?? null,
            'quoted_amount' => $request->input('quoted_amount'),
            'proposed_start_date' => $request->input('proposed_start_date'),
            'conditions' => $request->input('conditions'),
        ]);

        Response::json(['id' => (int) $db->lastInsertId(), 'status' => 'submitted'], 201);
    }

    /**
     * Default milestone split (30/40/30) — open-questions.md #3 leaves
     * whether this is negotiable as an unresolved stakeholder decision;
     * applying it as a platform default here is the assumption taken (see
     * MVP_STATUS.md's changelog).
     */
    private const DEFAULT_MILESTONE_SPLIT = [
        ['description' => 'Mobilization', 'percentage' => 30.0],
        ['description' => 'Progress milestone', 'percentage' => 40.0],
        ['description' => 'Final completion & retention', 'percentage' => 30.0],
    ];

    public function acceptQuote(Request $request): void
    {
        if (!$request->user) {
            Response::unauthorized('Sign in as the customer to accept a quote');
            return;
        }

        $db = Database::connection();

        $bookingStmt = $db->prepare('SELECT * FROM construction_bookings WHERE id = :id');
        $bookingStmt->execute(['id' => $request->params['id']]);
        $booking = $bookingStmt->fetch();
        if (!$booking) {
            Response::notFound('Project not found');
            return;
        }
        if ((int) $booking['customer_id'] !== (int) $request->user['id']) {
            Response::forbidden('Only the customer who posted this project can accept a quote');
            return;
        }

        $quoteStmt = $db->prepare('SELECT * FROM project_quotes WHERE id = :quote_id AND booking_id = :booking_id');
        $quoteStmt->execute(['quote_id' => $request->params['quoteId'], 'booking_id' => $request->params['id']]);
        $quote = $quoteStmt->fetch();
        if (!$quote) {
            Response::notFound('Quote not found');
            return;
        }

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE project_quotes SET status = \'accepted\' WHERE id = :quote_id', [\PDO::ATTR_EMULATE_PREPARES => true])
                ->execute(['quote_id' => $quote['id']]);
            $db->prepare('UPDATE project_quotes SET status = \'rejected\' WHERE booking_id = :booking_id AND id != :quote_id AND status = \'submitted\'', [\PDO::ATTR_EMULATE_PREPARES => true])
                ->execute(['booking_id' => $request->params['id'], 'quote_id' => $quote['id']]);

            $db->prepare(
                'UPDATE construction_bookings
                 SET provider_id = :provider_id, total_contract_value = :amount, status = \'quote_accepted\', updated_at = NOW()
                 WHERE id = :booking_id'
            )->execute([
                'provider_id' => $quote['provider_id'],
                'amount' => $quote['quoted_amount'],
                'booking_id' => $request->params['id'],
            ]);

            $totalValue = (float) $quote['quoted_amount'];
            $milestoneStmt = $db->prepare(
                'INSERT INTO project_milestones (booking_id, sequence_number, description, percentage_of_total, amount, status)
                 VALUES (:booking_id, :sequence_number, :description, :percentage, :amount, \'pending\')'
            );
            foreach (self::DEFAULT_MILESTONE_SPLIT as $index => $milestone) {
                $milestoneStmt->execute([
                    'booking_id' => $request->params['id'],
                    'sequence_number' => $index + 1,
                    'description' => $milestone['description'],
                    'percentage' => $milestone['percentage'],
                    'amount' => round($totalValue * $milestone['percentage'] / 100, 2),
                ]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Response::json(['id' => (int) $request->params['id'], 'status' => 'quote_accepted']);
    }

    public function updateStatus(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE construction_bookings SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['status' => $request->input('status'), 'id' => $request->params['id']]);

        Response::json(['id' => (int) $request->params['id'], 'status' => $request->input('status')]);
    }

    public function cancel(Request $request): void
    {
        // TODO: cancellation/refund policy for a mobilized-but-not-started
        // project — see open-questions.md #7.
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE construction_bookings SET status = \'cancelled\', updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $request->params['id']]);

        Response::json(['id' => (int) $request->params['id'], 'status' => 'cancelled']);
    }

    public function listMilestones(Request $request): void
    {
        $db = Database::connection();

        $bookingStmt = $db->prepare('SELECT * FROM construction_bookings WHERE id = :id');
        $bookingStmt->execute(['id' => $request->params['id']]);
        $booking = $bookingStmt->fetch();
        if ($booking) {
            Escrow::releaseRetentionIfDue($db, $booking);
        }

        $stmt = $db->prepare('SELECT * FROM project_milestones WHERE booking_id = :booking_id ORDER BY sequence_number ASC');
        $stmt->execute(['booking_id' => $request->params['id']]);

        Response::json($stmt->fetchAll());
    }

    public function requestMilestoneSignoff(Request $request): void
    {
        if (!$request->user) {
            Response::unauthorized('Sign in as the provider to request sign-off');
            return;
        }

        $db = Database::connection();
        [$milestone, $booking] = $this->findMilestoneAndBooking($db, $request->params['id'], $request->params['milestoneId']);
        if (!$milestone) {
            Response::notFound('Milestone not found');
            return;
        }
        if ((int) $booking['provider_id'] !== (int) $request->user['id']) {
            Response::forbidden('Only the assigned provider can request sign-off on this milestone');
            return;
        }
        if (!$milestone['escrow_transaction_id']) {
            Response::error('This milestone has not been funded into escrow yet', 409);
            return;
        }

        $stmt = $db->prepare('UPDATE project_milestones SET status = \'provider_requested_signoff\' WHERE id = :id');
        $stmt->execute(['id' => $request->params['milestoneId']]);

        Response::json(['id' => (int) $request->params['milestoneId'], 'status' => 'provider_requested_signoff']);
    }

    public function confirmMilestone(Request $request): void
    {
        if (!$request->user) {
            Response::unauthorized('Sign in as the customer to confirm this milestone');
            return;
        }

        $db = Database::connection();
        [$milestone, $booking] = $this->findMilestoneAndBooking($db, $request->params['id'], $request->params['milestoneId']);
        if (!$milestone) {
            Response::notFound('Milestone not found');
            return;
        }
        if ((int) $booking['customer_id'] !== (int) $request->user['id']) {
            Response::forbidden('Only the customer who owns this project can confirm a milestone');
            return;
        }
        if (!$milestone['escrow_transaction_id']) {
            Response::error('This milestone has not been funded into escrow yet — nothing to release', 409);
            return;
        }
        if ($milestone['status'] === 'released') {
            Response::error('This milestone has already been released', 409);
            return;
        }

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE project_milestones SET status = \'customer_confirmed\', confirmed_at = NOW() WHERE id = :id')
                ->execute(['id' => $request->params['milestoneId']]);

            $milestone['status'] = 'customer_confirmed';
            $result = Escrow::releaseMilestone($db, $milestone, $booking);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        if ($result === null) {
            Response::error('Escrow release failed — no funds held for this milestone', 409);
            return;
        }

        Response::json([
            'id' => (int) $request->params['milestoneId'],
            'status' => 'customer_confirmed',
            'escrow' => $result['retained_amount'] > 0 ? 'inspection_window' : 'released',
            'payout_amount' => $result['payout_amount'],
            'commission_amount' => $result['commission_amount'],
            'retained_amount' => $result['retained_amount'],
        ]);
    }

    /** @return array{0: array<string,mixed>|null, 1: array<string,mixed>|null} */
    private function findMilestoneAndBooking(\PDO $db, mixed $bookingId, mixed $milestoneId): array
    {
        $stmt = $db->prepare('SELECT * FROM project_milestones WHERE id = :id AND booking_id = :booking_id');
        $stmt->execute(['id' => $milestoneId, 'booking_id' => $bookingId]);
        $milestone = $stmt->fetch();
        if (!$milestone) {
            return [null, null];
        }

        $bookingStmt = $db->prepare('SELECT * FROM construction_bookings WHERE id = :id');
        $bookingStmt->execute(['id' => $bookingId]);
        $booking = $bookingStmt->fetch();

        return [$milestone ?: null, $booking ?: null];
    }

    public function submitConditionReport(Request $request): void
    {
        if (!$request->user) {
            Response::unauthorized('Sign in as the provider or a Site Inspector to submit a condition report');
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO equipment_condition_reports
                (booking_id, equipment_listing_id, report_type, submitted_by, photo_urls, video_url,
                 condition_notes, meter_reading_hours, independently_verified, created_at)
             VALUES (:booking_id, :equipment_listing_id, :report_type, :submitted_by, :photo_urls, :video_url,
                 :condition_notes, :meter_reading_hours, :independently_verified, NOW())'
        );
        $stmt->execute([
            'booking_id' => $request->params['id'],
            'equipment_listing_id' => $request->input('equipment_listing_id'),
            'report_type' => $request->input('report_type'),
            'submitted_by' => $request->user['id'] ?? null,
            'photo_urls' => json_encode($request->input('photo_urls', [])),
            'video_url' => $request->input('video_url'),
            'condition_notes' => $request->input('condition_notes'),
            'meter_reading_hours' => $request->input('meter_reading_hours'),
            'independently_verified' => (bool) $request->input('independently_verified', false),
        ]);

        Response::json(['id' => (int) $db->lastInsertId()], 201);
    }

    public function getConditionReports(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM equipment_condition_reports WHERE booking_id = :booking_id ORDER BY created_at ASC');
        $stmt->execute(['booking_id' => $request->params['id']]);

        Response::json($stmt->fetchAll());
    }
}
