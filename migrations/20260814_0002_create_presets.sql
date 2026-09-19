-- Esta migration cria a tabela de presets, que guarda as configurações visuais
-- salvas por cada usuário no servidor. Antes, isso era salvo só no navegador,
-- e isso podia causar conflitos entre usuários e dispositivos.
--
-- Em linguagem simples: cada linha desta tabela representa um preset salvo por um usuário.
CREATE TABLE IF NOT EXISTS presets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  shape VARCHAR(40) NOT NULL,
  color_mode VARCHAR(20) NOT NULL,
  intensity SMALLINT UNSIGNED NOT NULL,
  theme VARCHAR(40) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_presets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_presets_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
