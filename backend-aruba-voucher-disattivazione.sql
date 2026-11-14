-- Eseguire una sola volta dopo backend-aruba-vouchers.sql.
ALTER TABLE gift_vouchers ADD COLUMN disabled_at DATETIME NULL DEFAULT NULL;
