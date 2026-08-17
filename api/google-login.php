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
    $credential = trim($data['credential'] ?? '');
    if ($credential === '') {
        JsonResponder::respond(['ok' => false, 'message' => 'Credencial do Google ausente.'], 400);
    }

    // Signature/issuer/audience/expiry verified locally against Google's
    // JWKS — no call to the tokeninfo debug endpoint.
    $claims = $googleTokenVerifier->verify($credential);

    $result = $authService->googleLogin($claims['email'], $claims['name']);
    JsonResponder::respond($result->toArray());
} catch (DomainException $e) {
    JsonResponder::fromDomainException($e);
} catch (\RuntimeException $e) {
    // Thrown by GoogleTokenVerifier for any validation failure.
    JsonResponder::respond(['ok' => false, 'message' => 'Token do Google inválido.'], 401);
} catch (\Throwable $e) {
    error_log($e->getMessage());
    JsonResponder::serverError();
}
