<?php

declare(strict_types=1);

namespace App\Services;

use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Repository\PresetRepository;

class PresetService
{
    private const ALLOWED_SHAPES = ['barras', 'onda', 'circulos', 'espelho', 'pontos', 'radial', 'poligonos', 'linha'];
    private const ALLOWED_COLOR_MODES = ['gradient', 'solid'];
    private const ALLOWED_THEMES = ['roxo', 'petróleo', 'verde', 'preto', 'cinza', 'violeta', 'rosa', 'ouro'];

    public function __construct(private PresetRepository $presets)
    {
    }

    public function listForUser(int $userId): array
    {
        return $this->presets->findAllForUser($userId);
    }

    public function create(int $userId, array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        $shape = (string)($input['shape'] ?? '');
        $colorMode = (string)($input['colorMode'] ?? '');
        $intensity = (int)($input['intensity'] ?? 0);
        $theme = (string)($input['theme'] ?? '');

        if ($name === '' || mb_strlen($name) > 120) {
            throw new ValidationException('Nome do preset inválido.');
        }
        if (!in_array($shape, self::ALLOWED_SHAPES, true)) {
            throw new ValidationException('Forma visual inválida.');
        }
        if (!in_array($colorMode, self::ALLOWED_COLOR_MODES, true)) {
            throw new ValidationException('Modo de cor inválido.');
        }
        if (!in_array($theme, self::ALLOWED_THEMES, true)) {
            throw new ValidationException('Tema inválido.');
        }
        if ($intensity < 50 || $intensity > 400) {
            throw new ValidationException('Intensidade fora do intervalo permitido (50-400).');
        }

        $id = $this->presets->create($userId, $name, $shape, $colorMode, $intensity, $theme);

        return [
            'id' => $id,
            'name' => $name,
            'shape' => $shape,
            'color_mode' => $colorMode,
            'intensity' => $intensity,
            'theme' => $theme,
        ];
    }

    public function delete(int $userId, int $presetId): void
    {
        if (!$this->presets->deleteForUser($userId, $presetId)) {
            throw new NotFoundException('Preset não encontrado.');
        }
    }
}
