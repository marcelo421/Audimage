<?php
declare(strict_types=1);

use App\Http\JsonResponder;
use App\Http\Csrf;

require_once __DIR__ . '/bootstrap.php';

// Only POST is accepted, and it must carry a valid CSRF token — otherwise
// any third-party page could force a logout via a simple GET (img/script tag).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validateRequest()) {
    JsonResponder::respond(['ok' => false, 'message' => 'Invalid CSRF token'], 403);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

JsonResponder::respond(['ok' => true]);
