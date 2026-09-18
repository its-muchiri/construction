<?php

namespace Construction\Controllers;

use Construction\Config\Database;
use Construction\Core\Request;
use Construction\Core\Response;

/**
 * Equipment and crew listings — the searchable inventory providers create
 * so customers can browse before posting a project request (see
 * planning/03-construction-co-ke/api-endpoints.md's "Platform-Specific
 * Resources" group) and the public provider profile.
 */
final class ListingController
{
    public function createEquipment(Request $request): void
    {
        if (!$request->user) {
            Response::unauthorized('Sign in as a provider to list equipment');
            return;
        }
        if (!AdminController::isVerifiedProvider($request->user)) {
            Response::forbidden('Complete Tier 3 KYC verification before listing equipment');
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO equipment_listings
                (provider_id, equipment_category, make_model, year_of_manufacture, daily_rate, weekly_rate,
                 ownership_kyc_document_id, insurance_kyc_document_id, status)
             VALUES (:provider_id, :category, :make_model, :year, :daily_rate, :weekly_rate,
                 :ownership_doc_id, :insurance_doc_id, \'active\')'
        );
        $stmt->execute([
            'provider_id' => $request->user['id'] ?? null,
            'category' => $request->input('equipment_category'),
            'make_model' => $request->input('make_model'),
            'year' => $request->input('year_of_manufacture'),
            'daily_rate' => $request->input('daily_rate'),
            'weekly_rate' => $request->input('weekly_rate'),
            'ownership_doc_id' => $request->input('ownership_kyc_document_id'),
            'insurance_doc_id' => $request->input('insurance_kyc_document_id'),
        ]);

        Response::json(['id' => (int) $db->lastInsertId()], 201);
    }

    public function searchEquipment(Request $request): void
    {
        $db = Database::connection();
        $category = $request->query['category'] ?? null;

        if ($category) {
            $stmt = $db->prepare(
                'SELECT l.* FROM equipment_listings l JOIN users u ON u.id = l.provider_id
                 WHERE l.status = \'active\' AND u.status = \'active\' AND l.equipment_category = :category'
            );
            $stmt->execute(['category' => $category]);
        } else {
            $stmt = $db->query(
                'SELECT l.* FROM equipment_listings l JOIN users u ON u.id = l.provider_id
                 WHERE l.status = \'active\' AND u.status = \'active\''
            );
        }

        Response::json($stmt->fetchAll());
    }

    public function createCrew(Request $request): void
    {
        if (!$request->user) {
            Response::unauthorized('Sign in as a provider to list a crew');
            return;
        }
        if (!AdminController::isVerifiedProvider($request->user)) {
            Response::forbidden('Complete Tier 3 KYC verification before listing a crew');
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO crew_listings (provider_id, trade, team_size, day_rate, certification_kyc_document_id, status)
             VALUES (:provider_id, :trade, :team_size, :day_rate, :certification_doc_id, \'active\')'
        );
        $stmt->execute([
            'provider_id' => $request->user['id'] ?? null,
            'trade' => $request->input('trade'),
            'team_size' => $request->input('team_size'),
            'day_rate' => $request->input('day_rate'),
            'certification_doc_id' => $request->input('certification_kyc_document_id'),
        ]);

        Response::json(['id' => (int) $db->lastInsertId()], 201);
    }

    public function searchCrew(Request $request): void
    {
        $db = Database::connection();
        $trade = $request->query['trade'] ?? null;

        if ($trade) {
            $stmt = $db->prepare(
                'SELECT l.* FROM crew_listings l JOIN users u ON u.id = l.provider_id
                 WHERE l.status = \'active\' AND u.status = \'active\' AND l.trade = :trade'
            );
            $stmt->execute(['trade' => $trade]);
        } else {
            $stmt = $db->query(
                'SELECT l.* FROM crew_listings l JOIN users u ON u.id = l.provider_id
                 WHERE l.status = \'active\' AND u.status = \'active\''
            );
        }

        Response::json($stmt->fetchAll());
    }

    public function providerProfile(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, full_name, status FROM users WHERE id = :id AND account_type = \'provider\' AND status = \'active\'');
        $stmt->execute(['id' => $request->params['id']]);
        $provider = $stmt->fetch();

        if (!$provider) {
            Response::notFound('Provider not found or not yet verified');
            return;
        }

        $equipmentStmt = $db->prepare('SELECT * FROM equipment_listings WHERE provider_id = :id AND status = \'active\'');
        $equipmentStmt->execute(['id' => $request->params['id']]);
        $provider['equipment_listings'] = $equipmentStmt->fetchAll();

        $crewStmt = $db->prepare('SELECT * FROM crew_listings WHERE provider_id = :id AND status = \'active\'');
        $crewStmt->execute(['id' => $request->params['id']]);
        $provider['crew_listings'] = $crewStmt->fetchAll();

        Response::json($provider);
    }
}
