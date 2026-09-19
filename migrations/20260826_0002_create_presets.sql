-- Esta migration corrige a estrutura da tabela de presets.
-- A versão antiga tinha a tabela criada sem a coluna color, então este bloco adiciona
-- essa coluna de forma segura para bases antigas e também para bancos novos.
--
-- Em linguagem simples: garante que cada preset também tenha uma cor salva.
ALTER TABLE presets
  ADD COLUMN IF NOT EXISTS color VARCHAR(20) NOT NULL AFTER theme;
