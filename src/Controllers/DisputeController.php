<?php

namespace Construction\Controllers;

use Construction\Config\Database;
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
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO disputes (booking_id, raised_by, category, description, evidence_urls, status, created_at)
             VALUES (:booking_id, :raised_by, :category, :description, :evidence_urls, \'open\', NOW())'
        );
        $stmt->execute([
            'booking_id' => $request->params['id'],
            'raised_by' => $request->user['id'] ?? null,
            'category' => $request->input('category'),
            'description' => $request->input('description'),
            'evidence_urls' => json_encode($request->input('evidence_urls', [])),
        ]);

        // TODO: if category is "equipment_damage", auto-attach this
        // booking's equipment_condition_reports as baseline evidence —
        // see user-flows.md's Equipment-Damage Dispute Flow step 2.
        Response::json(['id' => (int) $db->lastInsertId(), 'status' => 'open'], 201);
    }

    public function index(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->query('SELECT * FROM disputes WHERE status IN (\'open\', \'under_review\') ORDER BY created_at ASC');

        Response::json($stmt->fetchAll());
    }

    public function resolve(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE disputes SET status = :status, resolved_by = :resolved_by, resolution_notes = :notes, resolved_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $request->input('status'),
            'resolved_by' => $request->user['id'] ?? null,
            'notes' => $request->input('resolution_notes'),
            'id' => $request->params['id'],
        ]);

        Response::json(['id' => (int) $request->params['id'], 'status' => $request->input('status')]);
    }
}
