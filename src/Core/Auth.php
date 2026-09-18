<?php

namespace Construction\Core;

/**
 * Stateless bearer-token auth (HMAC-signed userId+expiry, no server-side
 * session store) — this app runs as a PHP function on Vercel, so a
 * filesystem/session-based auth store won't survive across invocations.
 * Consumed by public/index.php's auth middleware and AuthController.
 */
final class Auth
{
    private const TTL_SECONDS = 60 * 60 * 24 * 30; // 30 days

    private static function secret(): string
    {
        return getenv('APP_KEY') ?: 'construction-co-ke-dev-only-insecure-default-key';
    }

    public static function issueToken(int $userId): string
    {
        $payload = $userId . '.' . (time() + self::TTL_SECONDS);
        $signature = hash_hmac('sha256', $payload, self::secret());

        return base64_encode($payload . '.' . $signature);
    }

    public static function verifyToken(?string $token): ?int
    {
        if (!$token) {
            return null;
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return null;
        }

        $parts = explode('.', $decoded);
        if (count($parts) !== 3) {
            return null;
        }

        [$userId, $expiry, $signature] = $parts;
        $expected = hash_hmac('sha256', $userId . '.' . $expiry, self::secret());

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        if ((int) $expiry < time()) {
            return null;
        }

        return (int) $userId;
    }
}
