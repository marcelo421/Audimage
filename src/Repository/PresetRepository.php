<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class PresetRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Busca todos os presets de um usuário específico.
     * A ideia é devolver só os dados daquele usuário, em ordem de criação mais recente primeiro.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllForUser(int $userId): array
    {
        // SELECT principal do módulo de presets: pega nome, forma, tema, cor e data de criação.
        $stmt = $this->pdo->prepare(
            'SELECT id, name, shape, color_mode AS colorMode, intensity, theme, color, created_at AS createdAt
             FROM presets WHERE user_id = :user_id ORDER BY created_at DESC'
        );
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Cria um novo preset para o usuário.
     * Cada preset guarda uma configuração visual salva no banco, não no navegador.
     */
    public function create(
        int $userId,
        string $name,
        string $shape,
        string $colorMode,
        int $intensity,
        string $theme,
        string $color
    ): int {
        // INSERT que salva a configuração do preset em uma linha da tabela presets.
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
        // Retorna o ID gerado para que a camada de serviço possa devolver o preset criado.
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Remove um preset só se ele pertencer ao usuário correto.
     * Isso evita que alguém apague o preset de outra pessoa manipulando o ID.
     */
    public function deleteForUser(int $presetId, int $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM presets WHERE id = :id AND user_id = :user_id');
        $stmt->execute([':id' => $presetId, ':user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }
}
