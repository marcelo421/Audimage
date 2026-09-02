-- Presets table: server-side storage scoped by user_id (closes the IDOR risk
-- of storing presets client-side in localStorage, where any user could see/edit
-- another user's data by manipulating local state).
CREATE TABLE IF NOT EXISTS presets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  shape VARCHAR(50) NOT NULL,
  color_mode VARCHAR(20) NOT NULL,
  intensity INT NOT NULL,
  theme VARCHAR(50) NOT NULL,
  color VARCHAR(20) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_presets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_presets_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
