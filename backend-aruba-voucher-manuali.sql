-- Eseguire una sola volta dopo backend-aruba-vouchers.sql, anche sui nuovi database.
ALTER TABLE gift_vouchers
  ADD COLUMN source ENUM('online','manual') NOT NULL DEFAULT 'online';
