<?php
declare(strict_types=1);

use App\Exception\TooManyRequestsException;
use App\Http\JsonResponder;
use App\Http\Request;
use App\Http\Csrf;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

// Unlike verify-email.php, this is a POST triggered by the user submitting
// a form inside our own SPA (after following the emailed link to
// index.html?reset_token=...), not a raw click from the email client — so
// normal CSRF validation applies here.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validateRequest()) {
    JsonResponder::respond(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
}

try {
    $data = Request::getJsonBody();
    $token = trim((string)($data['token'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if ($token === '') {
        JsonResponder::respond(['ok' => false, 'message' => 'Token ausente.'], 400);
    }

    // Dedicated limit on the confirm step, per IP — defense in depth against
    // scripted abuse of this endpoint. The token itself (256 bits) is not
    // practically brute-forceable, so this isn't the primary control, but
    // it keeps this endpoint consistent with the rest of the app's posture.
    $rateLimiter->enforce('password-reset-confirm', $_SERVER['REMOTE_ADDR'] ?? 'unknown');

    $result = $passwordResetService->resetPassword($token, $password);
    JsonResponder::respond($result, $result['ok'] ? 200 : 400);
} catch (\Throwable $e) {
    if ($e instanceof TooManyRequestsException) {
        JsonResponder::respond(['ok' => false, 'message' => $e->getMessage()], 429);
    }
    error_log($e->getMessage());
    JsonResponder::serverError();
}
