<?php
declare(strict_types=1);

use App\Http\JsonResponder;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

// Retorna o usuário atual se a sessão for válida e a conta puder acessar o app.
if (!empty($_SESSION['user'])) {
    $account = $userRepository->findById((int)($_SESSION['user']['id'] ?? 0));
    $hasAccess = $account
        && (($account['username'] ?? '') === 'adm_audimage'
            || in_array($account['subscription_status'] ?? 'inactive', ['active', 'trialing'], true));

    if ($hasAccess) {
        JsonResponder::respond(['ok' => true, 'user' => $_SESSION['user']]);
    }

    $_SESSION = [];
}

JsonResponder::respond(['ok' => false, 'user' => null]);
