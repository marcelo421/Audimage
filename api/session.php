<?php
declare(strict_types=1);

use App\Http\JsonResponder;

require_once __DIR__ . '/bootstrap.php';

if (!empty($_SESSION['user'])) {
    JsonResponder::respond(['ok' => true, 'user' => $_SESSION['user']]);
}

JsonResponder::respond(['ok' => false, 'user' => null]);
