<?php
declare(strict_types=1);

use App\Exception\TooManyRequestsException;
use App\Http\JsonResponder;
use App\Http\Request;
use App\Http\Csrf;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

// Validate CSRF token (header `X-CSRF-Token`) for state-changing requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validateRequest()) {
    JsonResponder::respond(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
}

try {
    $data = Request::getJsonBody();
    $result = $authService->login(trim($data['user'] ?? ''), $data['pass'] ?? '');
    JsonResponder::respond($result);
} catch (\Throwable $e) {
    if ($e instanceof TooManyRequestsException) {
        JsonResponder::respond(['ok' => false, 'message' => $e->getMessage()], 429);
    }
    error_log($e->getMessage());
    JsonResponder::serverError();
}
