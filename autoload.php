<?php

declare(strict_types=1);

// Auto-loader do projeto.
// Em termos simples: quando o código faz "new App\X\Y()", o PHP localiza
// o arquivo correspondente automaticamente na pasta src/ sem precisar de require manual.
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';

    // Só processa classes que começam com App\.
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    // Remove o prefixo App\ e monta o caminho do arquivo.
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    // Se o arquivo existir, ele é carregado na memória.
    if (is_file($file)) {
        require $file;
    }
});
