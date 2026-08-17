<?php
declare(strict_types=1);

use App\Exception\DomainException;
use App\Http\Csrf;
use App\Http\JsonResponder;
use App\Http\Request;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

$userId = Request::requireAuthenticatedUserId();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        JsonResponder::respond(['ok' => true, 'presets' => $presetService->listForUser($userId)]);
    }

    if ($method === 'POST') {
        if (!Csrf::validateRequest()) {
            JsonResponder::respond(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
        }
        $data = Request::getJsonBody();
        $preset = $presetService->create($userId, $data);
        JsonResponder::respond(['ok' => true, 'preset' => $preset], 201);
    }

    JsonResponder::respond(['ok' => false, 'message' => 'Método não permitido.'], 405);
} catch (DomainException $e) {
    JsonResponder::fromDomainException($e);
} catch (\Throwable $e) {
    error_log($e->getMessage());
    JsonResponder::serverError();
}
