<?php
declare(strict_types=1);

use App\Exception\DomainException;
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
    $result = $authService->register(
        trim($data['user'] ?? ''),
        trim($data['email'] ?? ''),
        $data['pass'] ?? ''
    );
    JsonResponder::respond($result->toArray());
} catch (DomainException $e) {
    JsonResponder::fromDomainException($e);
} catch (\Throwable $e) {
    error_log($e->getMessage());
    JsonResponder::serverError();
}
