INSERT INTO users (username, email, password_hash, email_verified_at, subscription_status)
VALUES
  (
    'demo_ativo',
    'demo.ativo@audimage.local',
    '$2y$10$mRRGnVY3MELCZAqS/Lrie./dJ/zq/8GSyO4xCUpYXNtkYx1TdKlOy',
    CURRENT_TIMESTAMP,
    'active'
  ),
  (
    'demo_bloqueado',
    'demo.bloqueado@audimage.local',
    '$2y$10$ZqcyIW.cAGKxNjvyp6SnaeUyAJ/msyR79QvVfviiZ8KPB9/j2e1me',
    CURRENT_TIMESTAMP,
    'inactive'
  )
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  email_verified_at = VALUES(email_verified_at),
  subscription_status = VALUES(subscription_status);