<?php
declare(strict_types=1);

use App\Exception\TooManyRequestsException;
use App\Http\JsonResponder;
use App\Http\Request;
use App\Http\Csrf;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

// Reenvia o link de confirmação de e-mail.
// Garante CSRF e responde de forma genérica para não expor se a conta já foi verificada.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validateRequest()) {
    JsonResponder::respond(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
}

try {
    $data = Request::getJsonBody();
    $email = trim((string)($data['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        JsonResponder::respond(['ok' => false, 'message' => 'Email inválido.'], 400);
    }

    $emailVerificationService->resend($email, $_SERVER['REMOTE_ADDR'] ?? 'unknown');

    JsonResponder::respond([
        'ok' => true,
        'message' => 'Se o email estiver cadastrado e pendente de confirmação, um novo link foi enviado.',
    ]);
} catch (\Throwable $e) {
    if ($e instanceof TooManyRequestsException) {
        JsonResponder::respond(['ok' => false, 'message' => $e->getMessage()], 429);
    }
    error_log($e->getMessage());
    JsonResponder::serverError();
}
