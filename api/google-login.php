<?php
declare(strict_types=1);

use App\Exception\AuthException;
use App\Exception\TooManyRequestsException;
use App\Http\JsonResponder;
use App\Http\Request;
use App\Http\Csrf;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validateRequest()) {
    JsonResponder::respond(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
}

try {
    $data = Request::getJsonBody();
    $result = $authService->googleLogin(trim($data['credential'] ?? ''));
    JsonResponder::respond($result->toArray());
} catch (TooManyRequestsException $e) {
    JsonResponder::respond(['ok' => false, 'message' => $e->getMessage()], 429);
} catch (AuthException $e) {
    JsonResponder::respond(['ok' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
} catch (\Throwable $e) {
    error_log($e->getMessage());
    JsonResponder::serverError();
}
