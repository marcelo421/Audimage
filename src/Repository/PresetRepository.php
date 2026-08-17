<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class PresetRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int, array> */
    public function findAllForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, shape, color_mode, intensity, theme, created_at
             FROM presets WHERE user_id = :user_id ORDER BY created_at DESC'
        );
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function findOneForUser(int $userId, int $presetId): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, shape, color_mode, intensity, theme
             FROM presets WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':id' => $presetId, ':user_id' => $userId]);
        return $stmt->fetch();
    }

    public function create(int $userId, string $name, string $shape, string $colorMode, int $intensity, string $theme): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO presets (user_id, name, shape, color_mode, intensity, theme)
             VALUES (:user_id, :name, :shape, :color_mode, :intensity, :theme)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
            ':shape' => $shape,
            ':color_mode' => $colorMode,
            ':intensity' => $intensity,
            ':theme' => $theme,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    // Delete scoped by user_id, not just id — this is what prevents
    // user A from deleting user B's preset by guessing an ID (IDOR).
    public function deleteForUser(int $userId, int $presetId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM presets WHERE id = :id AND user_id = :user_id');
        $stmt->execute([':id' => $presetId, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }
}
