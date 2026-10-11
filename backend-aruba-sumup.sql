ALTER TABLE appuntamenti
  ADD COLUMN sumup_checkout_id VARCHAR(100) NULL AFTER stato_pagamento,
  ADD COLUMN sumup_checkout_reference VARCHAR(100) NULL AFTER sumup_checkout_id,
  ADD COLUMN sumup_transaction_id VARCHAR(100) NULL AFTER sumup_checkout_reference,
  ADD UNIQUE KEY uq_appuntamenti_sumup_checkout (sumup_checkout_id),
  ADD UNIQUE KEY uq_appuntamenti_sumup_reference (sumup_checkout_reference);
