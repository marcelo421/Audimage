<?php
declare(strict_types=1);

use App\Http\JsonResponder;
use App\Http\Request;
use App\Http\Csrf;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

// Rota exclusiva do admin: lista usuários e permite alterar a permissão
// (subscription_status) manualmente pelo dashboard.
$sessionUser = $_SESSION['user'] ?? null;
if (!is_array($sessionUser) || ($sessionUser['username'] ?? '') !== 'adm_audimage') {
    JsonResponder::respond(['ok' => false, 'message' => 'Acesso restrito.'], 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$allowedStatuses = ['active', 'trialing', 'inactive', 'past_due'];

try {
    // GET: lista todos os usuários.
    if ($method === 'GET') {
        JsonResponder::respond(['ok' => true, 'users' => $userRepository->findAll()]);
    }

    // Qualquer operação que muda estado exige CSRF válido.
    if (!Csrf::validateRequest()) {
        JsonResponder::respond(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
    }

    // POST: altera a permissão (subscription_status) de um usuário.
    if ($method === 'POST') {
        $data = Request::getJsonBody();
        $targetId = (int)($data['id'] ?? 0);
        $status = (string)($data['status'] ?? '');

        if ($targetId <= 0 || !in_array($status, $allowedStatuses, true)) {
            JsonResponder::respond(['ok' => false, 'message' => 'Dados inválidos.'], 400);
        }

        $target = $userRepository->findById($targetId);
        if (!$target) {
            JsonResponder::respond(['ok' => false, 'message' => 'Usuário não encontrado.'], 404);
        }
        if (($target['username'] ?? '') === 'adm_audimage') {
            JsonResponder::respond(['ok' => false, 'message' => 'Não é possível alterar o usuário admin.'], 400);
        }

        $updated = $userRepository->updateSubscriptionStatusById($targetId, $status);
        JsonResponder::respond(['ok' => $updated]);
    }

    JsonResponder::respond(['ok' => false, 'message' => 'Método não permitido.'], 405);
} catch (\Throwable $e) {
    error_log($e->getMessage());
    JsonResponder::serverError();
}
