<?php

namespace Construction\Controllers;

use Construction\Config\Database;
use Construction\Core\Request;
use Construction\Core\Response;

/**
 * Platform Admin console API — the KYC approval step of
 * planning/03-construction-co-ke/user-flows.md's supply-side journey
 * (step 3: "Platform Admin reviews and approves/rejects the submission").
 * Until an admin approves, a provider stays `pending_verification` and
 * cannot list equipment/crew or submit quotes (see
 * AdminController::isVerifiedProvider and its callers).
 *
 * Dispute queue/resolution lives in DisputeController (already
 * admin-guarded); the /admin page just drives both.
 */
final class AdminController
{
    /** Documents every provider must have on file before approval — mirrors OnboardingController. */
    private const REQUIRED_DOCS = ['national_id', 'kra_pin', 'business_registration'];

    /**
     * Shared gate for the provider-only write endpoints: Tier 3 KYC means
     * a provider is only "verified" (users.status = 'active') once an admin
     * has approved their documents.
     */
    public static function isVerifiedProvider(?array $user): bool
    {
        return $user !== null && $user['account_type'] === 'provider' && $user['status'] === 'active';
    }

    public function kycQueue(Request $request): void
    {
        if (!$this->requireAdmin($request)) {
            return;
        }

        $db = Database::connection();
        $providers = $db->query(
            "SELECT u.id, u.full_name, u.phone_number, u.email, u.created_at
             FROM users u
             WHERE u.account_type = 'provider' AND u.status = 'pending_verification'
               AND EXISTS (SELECT 1 FROM kyc_documents d WHERE d.user_id = u.id AND d.verification_status = 'pending')
             ORDER BY u.created_at ASC"
        )->fetchAll();

        $docStmt = $db->prepare(
            'SELECT id, document_type, file_reference, verification_status
             FROM kyc_documents WHERE user_id = :user_id ORDER BY id ASC'
        );
        foreach ($providers as &$provider) {
            $docStmt->execute(['user_id' => $provider['id']]);
            $provider['documents'] = $docStmt->fetchAll();
        }
        unset($provider);

        Response::json($providers);
    }

    public function kycDecision(Request $request): void
    {
        if (!$this->requireAdmin($request)) {
            return;
        }

        $decision = $request->input('decision');
        if (!in_array($decision, ['approve', 'reject'], true)) {
            Response::error("decision must be 'approve' or 'reject'", 422);
            return;
        }

        $db = Database::connection();
        $providerId = (int) $request->params['id'];

        $stmt = $db->prepare("SELECT id, status FROM users WHERE id = :id AND account_type = 'provider'");
        $stmt->execute(['id' => $providerId]);
        $provider = $stmt->fetch();
        if (!$provider) {
            Response::notFound('Provider not found');
            return;
        }

        $docStmt = $db->prepare(
            "SELECT document_type FROM kyc_documents WHERE user_id = :id AND verification_status = 'pending'"
        );
        $docStmt->execute(['id' => $providerId]);
        $pendingTypes = array_column($docStmt->fetchAll(), 'document_type');
        if (!$pendingTypes) {
            Response::error('This provider has no pending KYC documents to review', 409);
            return;
        }

        if ($decision === 'approve') {
            // Tier 3 depth: refuse to approve a partial submission, even
            // though OnboardingController::submit already enforces it — a
            // rejected-then-resubmitted provider could otherwise slip through
            // with only the replacement documents pending.
            $verifiedStmt = $db->prepare(
                "SELECT document_type FROM kyc_documents WHERE user_id = :id AND verification_status = 'verified'"
            );
            $verifiedStmt->execute(['id' => $providerId]);
            $onFile = array_merge($pendingTypes, array_column($verifiedStmt->fetchAll(), 'document_type'));
            $missing = array_values(array_diff(self::REQUIRED_DOCS, $onFile));
            if ($missing) {
                Response::error('Cannot approve: missing required documents', 422, ['missing' => $missing]);
                return;
            }
        }

        $docStatus = $decision === 'approve' ? 'verified' : 'rejected';
        $userStatus = $decision === 'approve' ? 'active' : 'pending_verification';

        $db->beginTransaction();
        try {
            $db->prepare(
                "UPDATE kyc_documents
                 SET verification_status = :doc_status, verified_by = :admin_id, verified_at = NOW()
                 WHERE user_id = :user_id AND verification_status = 'pending'"
            )->execute(['doc_status' => $docStatus, 'admin_id' => $request->user['id'], 'user_id' => $providerId]);

            $db->prepare('UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id')
                ->execute(['status' => $userStatus, 'id' => $providerId]);

            $db->prepare(
                'INSERT INTO audit_log (actor_id, action, entity_type, entity_id, before_state, after_state, created_at)
                 VALUES (:actor_id, :action, \'user\', :entity_id, :before_state, :after_state, NOW())'
            )->execute([
                'actor_id' => $request->user['id'],
                'action' => 'kyc_' . $decision,
                'entity_id' => $providerId,
                'before_state' => json_encode(['status' => $provider['status']]),
                'after_state' => json_encode(['status' => $userStatus, 'notes' => $request->input('notes')]),
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Response::json(['id' => $providerId, 'decision' => $decision, 'status' => $userStatus]);
    }

    private function requireAdmin(Request $request): bool
    {
        if (!$request->user) {
            Response::unauthorized('Sign in as a Platform Admin');
            return false;
        }
        if ($request->user['account_type'] !== 'admin') {
            Response::forbidden('Platform Admin access only');
            return false;
        }

        return true;
    }
}
