<?php
declare(strict_types=1);

use App\Http\JsonResponder;
use App\Http\Csrf;

require_once __DIR__ . '/bootstrap.php';

// Validate CSRF token (header `X-CSRF-Token`) for state-changing requests,
// consistent with login.php / register.php / google-login.php.
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
