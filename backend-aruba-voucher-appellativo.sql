-- Eseguire una sola volta dopo backend-aruba-vouchers.sql.
-- I voucher esistenti restano senza appellativo e usano una formula neutra.
ALTER TABLE gift_vouchers
  ADD COLUMN recipient_title ENUM('sig','sigra') NULL DEFAULT NULL;
