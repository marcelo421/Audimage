<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Http\JsonResponder;

require_once __DIR__ . '/bootstrap.php';

// logout is a state-changing POST just like login/register — it needs the
// same CSRF check, otherwise a third-party site can force-logout a user.
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
