<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;

class Connection
{
    public static function createFromEnv(): PDO
    {
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

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $dbName, $charset);

        try {
            return new PDO($dsn, $dbUser, $dbPass, $options);
        } catch (PDOException $e) {
            throw $e;
        }
    }
}
