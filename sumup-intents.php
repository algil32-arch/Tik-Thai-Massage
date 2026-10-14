<?php

const SUMUP_INTENT_TTL_MINUTES = 15;

function sumUpIntentByToken(PDO $pdo, string $token): ?array
{
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT i.*, s.nome AS studio_nome, sv.nome AS servizio_nome
         FROM sumup_payment_intents i
         INNER JOIN studi s ON s.id = i.id_studio
         INNER JOIN servizi sv ON sv.id = i.id_servizio
         WHERE i.token = :token
         LIMIT 1"
    );
    $stmt->execute([':token' => $token]);
    $intent = $stmt->fetch();

    return $intent ?: null;
}

function sumUpIntentCustomer(array $intent): array
{
    $customer = json_decode((string)$intent['customer_data'], true);
    if (!is_array($customer)) {
        throw new RuntimeException('Dati cliente dell’intento di pagamento non validi.');
    }

    return $customer;
}

function finalizePaidSumUpIntent(PDO $pdo, int $intentId, ?string $transactionId): array
{
    try {
        $pdo->beginTransaction();

        $professionalLock = $pdo->prepare(
            'SELECT p.id
             FROM professionisti p
             INNER JOIN sumup_payment_intents i ON i.id_professionista = p.id
             WHERE i.id = :intent
             FOR UPDATE'
        );
        $professionalLock->execute([':intent' => $intentId]);
        if (!$professionalLock->fetch()) {
            throw new RuntimeException('Intento di pagamento non trovato.');
        }

        $intentStmt = $pdo->prepare(
            "SELECT i.*, s.nome AS studio_nome, sv.nome AS servizio_nome
             FROM sumup_payment_intents i
             INNER JOIN studi s ON s.id = i.id_studio
             INNER JOIN servizi sv ON sv.id = i.id_servizio
             WHERE i.id = :intent
             FOR UPDATE"
        );
        $intentStmt->execute([':intent' => $intentId]);
        $intent = $intentStmt->fetch();
        if (!$intent) {
            throw new RuntimeException('Intento di pagamento non trovato.');
        }

        if ($intent['appointment_id'] !== null) {
            $pdo->commit();
            return [
                'booking_id' => (int)$intent['appointment_id'],
                'recorded' => false,
                'notifications' => ['studio_sent' => false, 'customer_sent' => false],
            ];
        }
        if ($intent['status'] !== 'pending') {
            throw new RuntimeException('Questo intento di pagamento non è più attivo.');
        }

        $customer = sumUpIntentCustomer($intent);
        $customerStmt = $pdo->prepare(
            'INSERT INTO clienti (nome, cognome, ragione_sociale, email, telefono, codice_fiscale, partita_iva, codice_destinatario, pec, indirizzo, citta, cap, tipo_cliente, created_at)
             VALUES (:nome, :cognome, :ragione_sociale, :email, :telefono, :codice_fiscale, :partita_iva, :codice_destinatario, :pec, :indirizzo, :citta, :cap, :tipo, NOW())'
        );
        $customerStmt->execute([
            ':nome' => $customer['nome'],
            ':cognome' => $customer['cognome'],
            ':ragione_sociale' => $customer['ragione_sociale'],
            ':email' => $customer['email'],
            ':telefono' => $customer['telefono'],
            ':codice_fiscale' => $customer['codice_fiscale'],
            ':partita_iva' => $customer['partita_iva'],
            ':codice_destinatario' => $customer['codice_destinatario'],
            ':pec' => $customer['pec'],
            ':indirizzo' => $customer['indirizzo'],
            ':citta' => $customer['citta'],
            ':cap' => $customer['cap'],
            ':tipo' => $customer['tipo_cliente'],
        ]);
        $customerId = (int)$pdo->lastInsertId();

        $bookingStmt = $pdo->prepare(
            "INSERT INTO appuntamenti
                (id_studio, id_professionista, id_cliente, id_servizio, data_appuntamento, ora_inizio, ora_fine,
                 stato, metodo_pagamento, stato_pagamento, sumup_checkout_id, sumup_checkout_reference,
                 sumup_transaction_id, note, created_at)
             VALUES
                (:studio, :professionista, :cliente, :servizio, :date, :time, :time_end,
                 'in_attesa', 'online', 'pagato', :checkout, :reference, :transaction, :note, NOW())"
        );
        $bookingStmt->execute([
            ':studio' => $intent['id_studio'],
            ':professionista' => $intent['id_professionista'],
            ':cliente' => $customerId,
            ':servizio' => $intent['id_servizio'],
            ':date' => $intent['data_appuntamento'],
            ':time' => $intent['ora_inizio'],
            ':time_end' => $intent['ora_fine'],
            ':checkout' => $intent['sumup_checkout_id'],
            ':reference' => $intent['sumup_checkout_reference'],
            ':transaction' => $transactionId !== '' ? $transactionId : null,
            ':note' => $intent['note'],
        ]);
        $bookingId = (int)$pdo->lastInsertId();

        $updateStmt = $pdo->prepare(
            "UPDATE sumup_payment_intents
             SET status = 'paid', appointment_id = :booking, sumup_transaction_id = :transaction,
                 customer_data = '{}', note = NULL, updated_at = NOW()
             WHERE id = :intent"
        );
        $updateStmt->execute([
            ':booking' => $bookingId,
            ':transaction' => $transactionId !== '' ? $transactionId : null,
            ':intent' => $intentId,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $notifications = sendBookingNotifications($bookingId, [
        'name' => trim($customer['nome'] . ' ' . $customer['cognome']),
        'studio' => $intent['studio_nome'],
        'service' => $intent['servizio_nome'],
        'date' => $intent['data_appuntamento'],
        'time' => substr((string)$intent['ora_inizio'], 0, 5),
        'price' => $intent['amount'],
        'notes' => $intent['note'],
    ], (string)$customer['email']);

    return [
        'booking_id' => $bookingId,
        'recorded' => true,
        'notifications' => $notifications,
    ];
}

function expireSumUpPaymentIntents(PDO $pdo, int $professionalId, string $date): void
{
    $stmt = $pdo->prepare(
        "SELECT id, sumup_checkout_id
         FROM sumup_payment_intents
         WHERE status = 'pending' AND expires_at <= NOW()
           AND id_professionista = :professionista AND data_appuntamento = :date
         ORDER BY expires_at
         LIMIT 5"
    );
    $stmt->execute([':professionista' => $professionalId, ':date' => $date]);

    foreach ($stmt->fetchAll() as $expiredIntent) {
        try {
            $checkout = retrieveSumUpCheckout((string)$expiredIntent['sumup_checkout_id']);
            $status = strtoupper((string)($checkout['status'] ?? ''));

            if ($status === 'PAID') {
                $transactionId = isset($checkout['transactions'][0]['id'])
                    ? trim((string)$checkout['transactions'][0]['id'])
                    : null;
                finalizePaidSumUpIntent($pdo, (int)$expiredIntent['id'], $transactionId);
                continue;
            }

            if (in_array($status, ['NEW', 'PENDING'], true)) {
                $checkout = deactivateSumUpCheckout((string)$expiredIntent['sumup_checkout_id']);
                $status = strtoupper((string)($checkout['status'] ?? 'EXPIRED'));
                if ($status === 'PAID') {
                    $transactionId = isset($checkout['transactions'][0]['id'])
                        ? trim((string)$checkout['transactions'][0]['id'])
                        : null;
                    finalizePaidSumUpIntent($pdo, (int)$expiredIntent['id'], $transactionId);
                    continue;
                }
                if (in_array($status, ['NEW', 'PENDING'], true)) {
                    $status = 'EXPIRED';
                }
            }

            if (in_array($status, ['FAILED', 'EXPIRED'], true)) {
                $updateStmt = $pdo->prepare(
                    "UPDATE sumup_payment_intents
                     SET status = :status, customer_data = '{}', note = NULL, updated_at = NOW()
                     WHERE id = :intent AND status = 'pending'"
                );
                $updateStmt->execute([
                    ':status' => $status === 'FAILED' ? 'failed' : 'expired',
                    ':intent' => $expiredIntent['id'],
                ]);
            }
        } catch (Throwable $e) {
            error_log('SumUp intent cleanup failed for intent #' . $expiredIntent['id'] . ': ' . $e->getMessage());
        }
    }
}
