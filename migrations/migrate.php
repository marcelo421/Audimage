<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use App\Database\Connection;

echo "Running migrations...\n";

try {
    $pdo = Connection::createFromEnv();
} catch (Throwable $e) {
    echo "Failed to connect to DB: " . $e->getMessage() . "\n";
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure migrations table
$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$migrationsDir = __DIR__ . DIRECTORY_SEPARATOR;
$files = glob($migrationsDir . '*.sql');
sort($files, SORT_STRING);

$appliedStmt = $pdo->prepare('SELECT COUNT(1) FROM migrations WHERE filename = :filename');
$insertStmt = $pdo->prepare('INSERT INTO migrations (filename) VALUES (:filename)');

$new = 0;
foreach ($files as $file) {
    $basename = basename($file);
    $appliedStmt->execute([':filename' => $basename]);
    $exists = (int)$appliedStmt->fetchColumn() > 0;
    if ($exists) {
        continue;
    }

    echo "Applying: {$basename}...\n";
    $sql = file_get_contents($file);
    if ($sql === false) {
        echo "Failed to read file {$basename}\n";
        continue;
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $insertStmt->execute([':filename' => $basename]);
        $pdo->commit();
        $new++;
        echo "Applied: {$basename}\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "Failed to apply {$basename}: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "Migrations complete. New applied: {$new}\n";
