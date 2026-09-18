<?php

namespace Construction\Controllers;

use Construction\Config\Database;
use Construction\Core\Request;
use Construction\Core\Response;

final class PaymentController
{
    public function stkPush(Request $request): void
    {
        Response::json(['status' => 'stk_push_initiated', 'checkout_request_id' => null], 202);
    }

    public function card(Request $request): void
    {
        // Card is the common path here given high transaction values —
        // see planning/03-construction-co-ke/prd.md.
        Response::json(['status' => 'card_charge_initiated'], 202);
    }

    public function mpesaCallback(Request $request): void
    {
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
            'SELECT * FROM payments WHERE user_id = :user_id AND type = \'payout\' ORDER BY created_at DESC'
        );
        $stmt->execute(['user_id' => $request->user['id'] ?? null]);

        Response::json($stmt->fetchAll());
    }
}
