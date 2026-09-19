<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Http\JsonResponder;

require_once __DIR__ . '/bootstrap.php';

// Gera ou recupera um token CSRF para o frontend.
// Em termos simples: isso protege formulários e requisições AJAX contra abuso externo.
$token = Csrf::ensureToken();
JsonResponder::respond(['ok' => true, 'csrf' => $token]);
