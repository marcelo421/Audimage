INSERT INTO users (username, email, password_hash, email_verified_at)
VALUES (
  'adm_audimage',
  'adm_audimage@audimage.local',
  '$2y$10$hPiU6WWMEa9aFirclR7ZOeE6yIRniLufIl54VGM4jwr9TQv3BJPO2',
  CURRENT_TIMESTAMP
)
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP);
