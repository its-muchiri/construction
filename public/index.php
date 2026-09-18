<?php

require __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

use Construction\Config\Database;
use Construction\Core\Auth;
use Construction\Core\Request;
use Construction\Core\Response;
use Construction\Core\Router;

// Off by default so an unconfigured environment (e.g. a fresh Vercel deploy
// with no database wired yet) never leaks internal file paths/stack traces
// to a public response; set APP_DEBUG=true locally (see .env.example) to
// see real errors while developing.
$debug = getenv('APP_DEBUG') === 'true';
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

$router = new Router();
require __DIR__ . '/../routes/api.php';
if (is_file(__DIR__ . '/../routes/web.php')) {
    require __DIR__ . '/../routes/web.php';
}

try {
    $request = Request::fromGlobals();

    // Auth middleware: resolves a bearer token into $request->user, consumed
    // by every controller that writes on behalf of a signed-in user. Fails
    // soft (leaves $request->user null) if the token is missing/invalid/
    // expired, or if the database isn't reachable yet in this environment —
    // downstream controllers are responsible for rejecting unauthenticated
    // writes themselves (see Response::unauthorized() usages).
    $userId = Auth::verifyToken($request->bearerToken());
    if ($userId !== null) {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT id, full_name, phone_number, email, account_type, status FROM users WHERE id = :id'
            );
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();
            if ($user) {
                $request->user = $user;
            }
        } catch (\Throwable $e) {
            error_log((string) $e);
        }
    }

    $router->dispatch($request);
} catch (\Throwable $e) {
    error_log((string) $e);
    if ($debug) {
        throw $e;
    }
    // TEMP diagnostic: reveal the real exception to whoever holds APP_KEY,
    // via a header only the operator knows — removed once this pass's
    // acceptQuote 500 is diagnosed. See MVP_STATUS.md.
    if (hash_equals(getenv('APP_KEY') ?: '', $request->headers['X-Debug-Key'] ?? '')) {
        Response::error($e->getMessage(), 500, ['trace' => explode("\n", $e->getTraceAsString())]);
        return;
    }
    Response::error('Internal server error — this environment may not have a database connected yet.', 500);
}
