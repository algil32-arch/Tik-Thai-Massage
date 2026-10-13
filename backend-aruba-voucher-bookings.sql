ALTER TABLE appuntamenti
  MODIFY COLUMN metodo_pagamento ENUM('studio','online','voucher') NOT NULL DEFAULT 'studio';

CREATE TABLE IF NOT EXISTS gift_voucher_bookings (
  id INT PRIMARY KEY AUTO_INCREMENT,
  voucher_id INT NOT NULL,
  appointment_id INT NOT NULL UNIQUE,
  request_token CHAR(64) NOT NULL UNIQUE,
  payload_hash CHAR(64) NOT NULL,
  restored_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (voucher_id) REFERENCES gift_vouchers(id),
  FOREIGN KEY (appointment_id) REFERENCES appuntamenti(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
