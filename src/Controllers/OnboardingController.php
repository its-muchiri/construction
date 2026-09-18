<?php

namespace Construction\Controllers;

use Construction\Config\Database;
use Construction\Core\Request;
use Construction\Core\Response;

/**
 * Provider onboarding — Tier 3 KYC depth (see
 * planning/00-portfolio/shared-architecture.md's trust-tiering table):
 * national ID, KRA PIN, business registration, and — for equipment
 * owners specifically — proof of ownership/hire-purchase plus insurance
 * (see equipment_listings in database/schema.sql). This is the deepest
 * KYC bar of any platform in the portfolio; approval may require manual
 * verification beyond what this endpoint automates (see
 * planning/03-construction-co-ke/user-flows.md step 3).
 */
final class OnboardingController
{
    public function submit(Request $request): void
    {
        $db = Database::connection();
        $providerId = $request->user['id'] ?? null;

        $requiredDocs = ['national_id', 'kra_pin', 'business_registration'];
        $documents = $request->input('documents', []);
        $submittedTypes = array_column($documents, 'document_type');

        foreach ($requiredDocs as $required) {
            if (!in_array($required, $submittedTypes, true)) {
                Response::error("Missing required document: {$required}", 422, ['required' => $requiredDocs]);
                return;
            }
        }

        $stmt = $db->prepare(
            'INSERT INTO kyc_documents (user_id, document_type, file_reference, verification_status)
             VALUES (:user_id, :document_type, :file_reference, \'pending\')'
        );

        $submitted = [];
        foreach ($documents as $document) {
            $stmt->execute([
                'user_id' => $providerId,
                'document_type' => $document['document_type'],
                'file_reference' => $document['file_reference'],
            ]);
            $submitted[] = (int) $db->lastInsertId();
        }

        $stmt = $db->prepare('UPDATE users SET status = \'pending_verification\' WHERE id = :id');
        $stmt->execute(['id' => $providerId]);

        // NOTE: equipment_listings and crew_listings each carry their own
        // ownership/insurance/certification KYC document references (see
        // database/schema.sql) submitted separately when the provider
        // creates a specific listing, not as part of this general
        // application step.
        Response::json(['kyc_document_ids' => $submitted, 'status' => 'pending_verification'], 201);
    }
}
