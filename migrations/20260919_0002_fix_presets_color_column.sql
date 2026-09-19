-- Esta correção atende a ambientes antigos que já tinham a tabela presets criada
-- sem a coluna color. O problema aconteceu porque uma migration anterior tentou criar
-- a tabela novamente, mas como ela já existia, o bloco foi ignorado.
--
-- Em linguagem simples: se a coluna color ainda não existir, ela é adicionada agora
-- sem quebrar um banco novo nem um banco antigo já em uso.
ALTER TABLE presets
  ADD COLUMN IF NOT EXISTS color VARCHAR(20) NOT NULL DEFAULT '#ffffff' AFTER theme;
