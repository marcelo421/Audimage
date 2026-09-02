<?php
declare(strict_types=1);

use App\Http\Csrf;
use App\Http\JsonResponder;

require_once __DIR__ . '/bootstrap.php';

// Ensure a token exists and return it to the client. Credentials/cookies included.
$token = Csrf::ensureToken();
JsonResponder::respond(['ok' => true, 'csrf' => $token]);
