<?php
declare(strict_types=1);

use App\Http\JsonResponder;
use App\Http\Request;
use App\Http\Csrf;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dependencies.php';

if (empty($_SESSION['user']['id'])) {
    JsonResponder::respond(['ok' => false, 'message' => 'Não autenticado.'], 401);
}

$userId = (int)$_SESSION['user']['id'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $presets = $presetRepository->findAllForUser($userId);
        JsonResponder::respond(['ok' => true, 'presets' => $presets]);
    }

    // All state-changing methods require a valid CSRF token.
    if (!Csrf::validateRequest()) {
        JsonResponder::respond(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
    }

    if ($method === 'POST') {
        $data = Request::getJsonBody();

        $name = trim((string)($data['name'] ?? ''));
        $shape = trim((string)($data['shape'] ?? ''));
        $colorMode = trim((string)($data['colorMode'] ?? ''));
        $intensity = (int)($data['intensity'] ?? 100);
        $theme = trim((string)($data['theme'] ?? ''));
        $color = trim((string)($data['color'] ?? '#a78bfa'));

        if ($name === '' || $shape === '' || $colorMode === '' || $theme === '') {
            JsonResponder::respond(['ok' => false, 'message' => 'Dados do preset incompletos.'], 400);
        }
        if (mb_strlen($name) > 100) {
            JsonResponder::respond(['ok' => false, 'message' => 'Nome do preset muito longo.'], 400);
        }
        if ($intensity < 0 || $intensity > 1000) {
            JsonResponder::respond(['ok' => false, 'message' => 'Intensidade inválida.'], 400);
        }
        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            $color = '#a78bfa';
        }

        $id = $presetRepository->create($userId, $name, $shape, $colorMode, $intensity, $theme, $color);
        JsonResponder::respond(['ok' => true, 'id' => $id]);
    }

    if ($method === 'DELETE') {
        parse_str(file_get_contents('php://input') ?: '', $body);
        $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
        if ($id <= 0) {
            JsonResponder::respond(['ok' => false, 'message' => 'Id inválido.'], 400);
        }

        // deleteForUser scopes the DELETE by user_id, so a user can never
        // remove a preset that isn't theirs, even by guessing/enumerating ids.
        $deleted = $presetRepository->deleteForUser($id, $userId);
        JsonResponder::respond(['ok' => $deleted]);
    }

    JsonResponder::respond(['ok' => false, 'message' => 'Método não permitido.'], 405);
} catch (\Throwable $e) {
    error_log($e->getMessage());
    JsonResponder::serverError();
}
