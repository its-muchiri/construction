<?php

namespace Construction\Controllers;

use Construction\Config\Database;
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

    public function acceptQuote(Request $request): void
    {
        $db = Database::connection();

        // TODO: create project_milestones from the default (or negotiated)
        // split — see open-questions.md #3 — once accepted.
        $stmt = $db->prepare('UPDATE project_quotes SET status = \'accepted\' WHERE id = :quote_id');
        $stmt->execute(['quote_id' => $request->params['quoteId']]);

        // MySQL's multi-table UPDATE...JOIN has no Postgres equivalent —
        // Postgres uses UPDATE...FROM instead — so this branches on the
        // active driver; see Database::driver() and
        // planning/00-portfolio/ui-implementation-plan.md for why both
        // exist (Vercel's Marketplace has no MySQL-compatible database).
        $sql = Database::driver() === 'pgsql'
            ? 'UPDATE construction_bookings b
               SET provider_id = q.provider_id, total_contract_value = q.quoted_amount, status = \'quote_accepted\', updated_at = NOW()
               FROM project_quotes q
               WHERE q.id = :quote_id AND b.id = :booking_id'
            : 'UPDATE construction_bookings b
               JOIN project_quotes q ON q.id = :quote_id
               SET b.provider_id = q.provider_id, b.total_contract_value = q.quoted_amount, b.status = \'quote_accepted\', b.updated_at = NOW()
               WHERE b.id = :booking_id';
        $stmt = $db->prepare($sql);
        $stmt->execute(['quote_id' => $request->params['quoteId'], 'booking_id' => $request->params['id']]);

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
        $stmt = $db->prepare('SELECT * FROM project_milestones WHERE booking_id = :booking_id ORDER BY sequence_number ASC');
        $stmt->execute(['booking_id' => $request->params['id']]);

        Response::json($stmt->fetchAll());
    }

    public function requestMilestoneSignoff(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE project_milestones SET status = \'provider_requested_signoff\' WHERE id = :id');
        $stmt->execute(['id' => $request->params['milestoneId']]);

        Response::json(['id' => (int) $request->params['milestoneId'], 'status' => 'provider_requested_signoff']);
    }

    public function confirmMilestone(Request $request): void
    {
        // TODO: call into the shared Escrow Engine (v2, milestone release —
        // see shared-architecture.md and build-sequencing-roadmap.md module 10)
        // to release this tranche.
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE project_milestones SET status = \'customer_confirmed\', confirmed_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $request->params['milestoneId']]);

        Response::json(['id' => (int) $request->params['milestoneId'], 'status' => 'customer_confirmed', 'escrow' => 'release_pending']);
    }

    public function submitConditionReport(Request $request): void
    {
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
