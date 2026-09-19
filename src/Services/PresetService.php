<?php

declare(strict_types=1);

namespace App\Services;

use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Repository\PresetRepository;

class PresetService
{
    // Lista de formas permitidas para o visualizador. Isso valida dados vindos do frontend.
    private const ALLOWED_SHAPES = ['barras', 'onda', 'circulos', 'espelho', 'pontos', 'radial', 'poligonos', 'linha'];
    // Modos de cor aceitos pela interface.
    private const ALLOWED_COLOR_MODES = ['gradient', 'solid'];
    // Temas visuais válidos para o app.
    private const ALLOWED_THEMES = ['roxo', 'petróleo', 'verde', 'preto', 'cinza', 'violeta', 'rosa', 'ouro'];

    public function __construct(private PresetRepository $presets)
    {
    }

    /**
     * Lista todos os presets do usuário.
     * Essa camada apenas delega para o repositório, mas mantém a regra de negócio centralizada.
     */
    public function listForUser(int $userId): array
    {
        return $this->presets->findAllForUser($userId);
    }

    /**
     * Cria um novo preset após validar todas as entradas do formulário.
     * Isso evita salvar valores inválidos ou maliciosos no banco.
     */
    public function create(int $userId, array $input): array
    {
        // Normaliza os dados vindos da camada HTTP/JS.
        $name = trim((string)($input['name'] ?? ''));
        $shape = (string)($input['shape'] ?? '');
        $colorMode = (string)($input['colorMode'] ?? '');
        $intensity = (int)($input['intensity'] ?? 0);
        $theme = (string)($input['theme'] ?? '');

        // Validações básicas do preset.
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

        // Cria no banco e pega o ID gerado.
        $id = $this->presets->create($userId, $name, $shape, $colorMode, $intensity, $theme);

        // Retorna o preset em um formato pronto para uso pela interface.
        return [
            'id' => $id,
            'name' => $name,
            'shape' => $shape,
            'color_mode' => $colorMode,
            'intensity' => $intensity,
            'theme' => $theme,
        ];
    }

    /**
     * Remove um preset do usuário autenticado.
     * Se não achar o item, lança exceção para mostrar que não existe.
     */
    public function delete(int $userId, int $presetId): void
    {
        if (!$this->presets->deleteForUser($userId, $presetId)) {
            throw new NotFoundException('Preset não encontrado.');
        }
    }
}
