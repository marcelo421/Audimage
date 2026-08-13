<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_lifetime', '0');
session_name('audimage_session');

if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)) {
    ini_set('session.cookie_secure', '1');
}

session_start();

$host = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: 'audimage';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$charset = 'utf8mb4';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

function buildDsn(string $host, string $dbName, string $charset): string
{
    return "mysql:host={$host};dbname={$dbName};charset={$charset}";
}

try {
    $pdo = new PDO(buildDsn($host, $dbName, $charset), $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'could not find driver') !== false) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Extensão PDO MySQL não está habilitada no PHP. Ative o módulo pdo_mysql no XAMPP.']);
        exit;
    }

    try {
        $bootstrap = new PDO("mysql:host={$host};charset={$charset}", $dbUser, $dbPass, $options);
        $bootstrap->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO(buildDsn($host, $dbName, $charset), $dbUser, $dbPass, $options);
    } catch (PDOException $bootstrapException) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Falha ao conectar ao banco de dados.']);
        exit;
    }
}

$pdo->exec('CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

function enforceRateLimit(string $scope, string $identifier): void
{
    $limitFile = __DIR__ . '/.rate_limits.json';
    $now = time();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = hash('sha256', $scope . ':' . $identifier . ':' . $ip);
    $windowSeconds = 900;
    $maxAttempts = 8;

    $data = [];
    if (is_file($limitFile)) {
        $raw = @file_get_contents($limitFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
    }

    foreach ($data as $entryKey => $entry) {
        if (!is_array($entry) || !isset($entry['window_start']) || ($now - (int)$entry['window_start']) > $windowSeconds) {
            unset($data[$entryKey]);
        }
    }

    if (!isset($data[$key]) || !is_array($data[$key])) {
        $data[$key] = ['count' => 0, 'window_start' => $now];
    }

    $entry = &$data[$key];
    if (($now - (int)$entry['window_start']) > $windowSeconds) {
        $entry = ['count' => 0, 'window_start' => $now];
    }

    $entry['count'] = (int)$entry['count'] + 1;
    if ((int)$entry['count'] > $maxAttempts) {
        @file_put_contents($limitFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        usleep(750000);
        respond(['ok' => false, 'message' => 'Muitas tentativas. Tente novamente mais tarde.'], 429);
    }

    @file_put_contents($limitFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function readJsonBody(): array
{
    $payload = file_get_contents('php://input');
    if (!$payload) {
        return [];
    }

    $data = json_decode($payload, true);
    if (!is_array($data)) {
        respond(['ok' => false, 'message' => 'JSON inválido.'], 400);
    }

    return $data;
}

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
