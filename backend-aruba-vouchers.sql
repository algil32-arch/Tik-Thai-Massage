CREATE TABLE IF NOT EXISTS gift_vouchers (
  id INT PRIMARY KEY AUTO_INCREMENT,
  token CHAR(64) NOT NULL UNIQUE,
  payload_hash CHAR(64) NOT NULL,
  id_studio INT NOT NULL,
  id_servizio INT NOT NULL,
  service_name VARCHAR(150) NOT NULL,
  duration_minutes INT NOT NULL,
  recipient_name VARCHAR(150) NOT NULL,
  sessions_per_item INT NOT NULL,
  quantity INT NOT NULL,
  total_sessions INT NOT NULL,
  remaining_sessions INT NOT NULL DEFAULT 0,
  amount DECIMAL(10,2) NOT NULL,
  customer_data TEXT NOT NULL,
  status ENUM('pending','paid','failed','expired') NOT NULL DEFAULT 'pending',
  checkout_id VARCHAR(100) NULL UNIQUE,
  checkout_reference VARCHAR(100) NOT NULL UNIQUE,
  transaction_id VARCHAR(100) NULL,
  voucher_code VARCHAR(40) NULL UNIQUE,
  checkout_expires_at DATETIME NOT NULL,
  paid_at DATETIME NULL,
  valid_until DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_studio) REFERENCES studi(id),
  FOREIGN KEY (id_servizio) REFERENCES servizi(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gift_voucher_uses (
  id INT PRIMARY KEY AUTO_INCREMENT,
  voucher_id INT NOT NULL,
  request_token CHAR(64) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (voucher_id) REFERENCES gift_vouchers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
