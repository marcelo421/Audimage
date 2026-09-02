<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class PresetRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function findAllForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, shape, color_mode AS colorMode, intensity, theme, color, created_at AS createdAt
             FROM presets WHERE user_id = :user_id ORDER BY created_at DESC'
        );
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function create(
        int $userId,
        string $name,
        string $shape,
        string $colorMode,
        int $intensity,
        string $theme,
        string $color
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO presets (user_id, name, shape, color_mode, intensity, theme, color)
             VALUES (:user_id, :name, :shape, :color_mode, :intensity, :theme, :color)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
            ':shape' => $shape,
            ':color_mode' => $colorMode,
            ':intensity' => $intensity,
            ':theme' => $theme,
            ':color' => $color,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    // Ownership check baked into the WHERE clause — a user can only ever
    // delete rows that belong to them, regardless of the id supplied.
    public function deleteForUser(int $presetId, int $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM presets WHERE id = :id AND user_id = :user_id');
        $stmt->execute([':id' => $presetId, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }
}
