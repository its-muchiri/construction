<?php

namespace Construction\Controllers;

use Construction\Config\Database;
use Construction\Core\Auth;
use Construction\Core\Request;
use Construction\Core\Response;

/**
 * Identity module (shared-architecture.md module referenced by every other
 * controller via $request->user, which the auth middleware in
 * public/index.php populates from the bearer token this controller issues).
 * Customers become 'active' immediately; providers land in
 * 'pending_verification' until Tier 3 KYC is approved — see
 * OnboardingController and user-flows.md's supply-side journey step 3.
 */
final class AuthController
{
    public function signup(Request $request): void
    {
        $phone = trim((string) $request->input('phone_number', ''));
        $password = (string) $request->input('password', '');
        $fullName = trim((string) $request->input('full_name', ''));
        $accountType = $request->input('account_type', 'customer');

        if ($phone === '' || $password === '' || $fullName === '') {
            Response::error('phone_number, password, and full_name are required', 422);
            return;
        }
        if (!in_array($accountType, ['customer', 'provider'], true)) {
            Response::error('account_type must be customer or provider', 422);
            return;
        }
        if (strlen($password) < 6) {
            Response::error('password must be at least 6 characters', 422);
            return;
        }

        $db = Database::connection();

        $stmt = $db->prepare('SELECT id FROM users WHERE phone_number = :phone');
        $stmt->execute(['phone' => $phone]);
        if ($stmt->fetch()) {
            Response::error('An account with this phone number already exists', 409);
            return;
        }

        $status = $accountType === 'provider' ? 'pending_verification' : 'active';

        $stmt = $db->prepare(
            'INSERT INTO users (phone_number, email, password_hash, full_name, account_type, status, created_at, updated_at)
             VALUES (:phone, :email, :password_hash, :full_name, :account_type, :status, NOW(), NOW())'
        );
        $stmt->execute([
            'phone' => $phone,
            'email' => $request->input('email'),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name' => $fullName,
            'account_type' => $accountType,
            'status' => $status,
        ]);
        $userId = (int) $db->lastInsertId();

        Response::json([
            'token' => Auth::issueToken($userId),
            'user' => ['id' => $userId, 'full_name' => $fullName, 'account_type' => $accountType, 'status' => $status],
        ], 201);
    }

    public function login(Request $request): void
    {
        $phone = trim((string) $request->input('phone_number', ''));
        $password = (string) $request->input('password', '');

        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM users WHERE phone_number = :phone');
        $stmt->execute(['phone' => $phone]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Response::unauthorized('Invalid phone number or password');
            return;
        }

        Response::json([
            'token' => Auth::issueToken((int) $user['id']),
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'account_type' => $user['account_type'],
                'status' => $user['status'],
            ],
        ]);
    }

    public function me(Request $request): void
    {
        if (!$request->user) {
            Response::unauthorized();
            return;
        }

        Response::json($request->user);
    }
}
