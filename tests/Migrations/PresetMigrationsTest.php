<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PresetMigrationsTest extends TestCase
{
    public function testPresetMigrationAddsColorColumnInIdempotentWay(): void
    {
        $files = glob(__DIR__ . '/../../migrations/*.sql');
        $this->assertNotEmpty($files);

        $migrationSql = '';
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            $this->assertIsString($sql);

            if (str_contains($sql, 'ALTER TABLE') && str_contains($sql, 'presets')) {
                $migrationSql .= $sql;
            }
        }

        $this->assertStringContainsString('ALTER TABLE presets', $migrationSql);
        $this->assertStringContainsString('ADD COLUMN', $migrationSql);
        $this->assertStringContainsString('color', $migrationSql);
        $this->assertStringContainsString('IF NOT EXISTS', $migrationSql);
    }
}
