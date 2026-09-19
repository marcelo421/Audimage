ALTER TABLE users
  ADD COLUMN subscription_status VARCHAR(20) NOT NULL DEFAULT 'inactive' AFTER email_verified_at,
  ADD COLUMN stripe_customer_id VARCHAR(255) NULL AFTER subscription_status,
  ADD COLUMN stripe_subscription_id VARCHAR(255) NULL AFTER stripe_customer_id,
  ADD COLUMN subscription_current_period_end TIMESTAMP NULL AFTER stripe_subscription_id,
  ADD INDEX idx_users_stripe_customer_id (stripe_customer_id),
  ADD INDEX idx_users_stripe_subscription_id (stripe_subscription_id);

UPDATE users
SET subscription_status = 'active'
WHERE username = 'adm_audimage';