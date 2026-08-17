<?php
declare(strict_types=1);

use App\Exception\DomainException;
use App\Http\Csrf;
use App\Http\JsonResponder;
use App\Http\Request;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

$userId = Request::requireAuthenticatedUserId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validateRequest()) {
    JsonResponder::respond(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
}

try {
    $data = Request::getJsonBody();
    $presetId = (int)($data['id'] ?? 0);
    $presetService->delete($userId, $presetId);
    JsonResponder::respond(['ok' => true]);
} catch (DomainException $e) {
    JsonResponder::fromDomainException($e);
} catch (\Throwable $e) {
    error_log($e->getMessage());
    JsonResponder::serverError();
}
