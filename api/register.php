<?php
declare(strict_types=1);

use App\Exception\AuthException;
use App\Exception\TooManyRequestsException;
use App\Http\JsonResponder;
use App\Http\Request;
use App\Http\Csrf;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

// Endpoint de cadastro de novo usuário.
// Também exige POST e validação CSRF para impedir requisições forjadas de fora do app.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validateRequest()) {
    JsonResponder::respond(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
}

try {
    // Ler os campos de usuário, email e senha enviados pelo frontend.
    $data = Request::getJsonBody();
    $result = $authService->register(
        trim($data['user'] ?? ''),
        trim($data['email'] ?? ''),
        $data['pass'] ?? ''
    );

    // Envia email de verificação, mas sem bloquear o cadastro mesmo se o e-mail falhar.
    try {
        if (isset($emailVerificationService) && $result !== null) {
            $user = $result->user();
            @$emailVerificationService->sendVerificationEmail((int)$user['id'], (string)$user['email'], (string)$user['username']);
        }
    } catch (\Throwable $e) {
        error_log('[REGISTER] Failed to send verification email: ' . $e->getMessage());
    }

    JsonResponder::respond($result->toArray());
} catch (TooManyRequestsException $e) {
    JsonResponder::respond(['ok' => false, 'message' => $e->getMessage()], 429);
} catch (AuthException $e) {
    JsonResponder::respond(['ok' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
} catch (\Throwable $e) {
    error_log($e->getMessage());
    JsonResponder::serverError();
}
