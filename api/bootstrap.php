<?php
declare(strict_types=1);

// Este arquivo roda antes de qualquer endpoint da API.
// Ele carrega o autoload, lê o .env e configura a sessão HTTP do projeto.
require_once __DIR__ . '/../autoload.php';

loadDotEnv(__DIR__ . '/../.env');

use App\Http\SecurityHeaders;

// Aplica cabeçalhos de segurança básicos do app para reforçar proteção.
SecurityHeaders::apply();

// Configura a sessão com comportamento mais seguro e restritivo.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_lifetime', '0');
session_name('audimage_session');

if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)) {
    ini_set('session.cookie_secure', '1');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Lê variáveis do arquivo .env e as coloca em ambiente do PHP.
// Isso permite que o código use getenv('DB_HOST'), getenv('GOOGLE_CLIENT_ID'), etc.
function loadDotEnv(string $envFile): void
{
    if (!is_file($envFile)) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $trimmed, 2), 2, '');
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        // Evita sobrescrever uma variável já existente no ambiente do sistema.
        if (getenv($key) === false) {
            $value = preg_replace('/^"(.*)"$/', '$1', $value);
            $value = preg_replace("/^'(.*)'$/", '$1', $value);
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
