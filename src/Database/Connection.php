<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;

class Connection
{
    /**
     * Cria a conexão com o banco usando as variáveis de ambiente do projeto.
     */
    public static function createFromEnv(): PDO
    {
        // Leitura das configurações do banco. Se não existir, usa valores padrão locais.
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $dbName = getenv('DB_NAME') ?: 'audimage';
        $dbUser = getenv('DB_USER') ?: 'root';
        $dbPass = getenv('DB_PASS') ?: '';
        $charset = 'utf8mb4';

        // Opções de segurança e comportamento da PDO.
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // DSN do MySQL. Exemplo: mysql:host=127.0.0.1;dbname=audimage;charset=utf8mb4.
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $dbName, $charset);

        try {
            // Cria o objeto de conexão usado por todos os repositórios do projeto.
            return new PDO($dsn, $dbUser, $dbPass, $options);
        } catch (PDOException $e) {
            // Repassa a falha para a camada que chamou, para mostrar o erro real.
            throw $e;
        }
    }
}
