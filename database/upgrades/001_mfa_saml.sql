-- Upgrade for installs created before TOTP/SAML support.
-- Fresh installs get these columns from schema.sql; run this only on
-- existing databases (each statement fails harmlessly if already applied).

ALTER TABLE users ADD COLUMN mfa_recovery_codes JSON NULL AFTER mfa_enabled;
ALTER TABLE users ADD COLUMN mfa_last_counter BIGINT NULL AFTER mfa_recovery_codes;
