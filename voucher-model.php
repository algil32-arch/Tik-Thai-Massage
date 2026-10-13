<?php

function voucherQuote(string $price, int $sessionsPerItem, int $quantity): array
{
    if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $price)
        || !in_array($sessionsPerItem, [1, 5, 10], true)
        || $quantity < 1 || $quantity > 20) {
        throw new InvalidArgumentException('Seleziona una formula e una quantità da 1 a 20.');
    }
    $parts = explode('.', $price);
    $cents = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
    if ($cents < 1) {
        throw new InvalidArgumentException('Il prezzo del servizio non è valido.');
    }
    $unitCents = $cents;
    if ($sessionsPerItem !== 1) {
        $percent = $sessionsPerItem === 5 ? 95 : 90;
        $unitCents = intdiv($cents * $percent + 9999, 10000) * 100;
    }
    $totalCents = $unitCents * $sessionsPerItem * $quantity;
    if ($totalCents > 99999999) {
        throw new InvalidArgumentException('Importo del voucher troppo elevato.');
    }
    return [
        'unit_cents' => $unitCents,
        'total_cents' => $totalCents,
        'total_sessions' => $sessionsPerItem * $quantity,
        'amount' => number_format($totalCents / 100, 2, '.', ''),
    ];
}

function voucherCustomer(array $input): array
{
    $limits = ['name' => 150, 'email' => 255, 'phone' => 50, 'customer_type' => 10,
        'ragione_sociale' => 255, 'codice_fiscale' => 16, 'partita_iva' => 11,
        'codice_destinatario' => 7, 'pec' => 255, 'indirizzo' => 255, 'citta' => 100, 'cap' => 10];
    $customer = [];
    foreach ($limits as $key => $limit) {
        if (isset($input[$key]) && !is_string($input[$key])) {
            throw new InvalidArgumentException('Dati acquirente non validi.');
        }
        $value = trim($input[$key] ?? '');
        if (strlen($value) > $limit || preg_match('/[\x00-\x1f]/', $value)) {
            throw new InvalidArgumentException('Controlla i dati acquirente: campi troppo lunghi o caratteri non validi.');
        }
        $customer[$key] = $value;
    }
    if ($customer['name'] === '' || !filter_var($customer['email'], FILTER_VALIDATE_EMAIL)
        || $customer['indirizzo'] === '' || $customer['citta'] === '' || $customer['cap'] === ''
        || !in_array($customer['customer_type'], ['privato', 'azienda'], true)) {
        throw new InvalidArgumentException('Inserisci nome, email e dati di fatturazione dell’acquirente.');
    }
    if ($customer['customer_type'] === 'privato' && $customer['codice_fiscale'] === '') {
        throw new InvalidArgumentException('Inserisci il codice fiscale dell’acquirente.');
    }
    if ($customer['customer_type'] === 'azienda' && ($customer['ragione_sociale'] === ''
        || $customer['partita_iva'] === '' || ($customer['pec'] === '' && $customer['codice_destinatario'] === ''))) {
        throw new InvalidArgumentException('Inserisci ragione sociale, partita IVA e codice destinatario o PEC.');
    }
    if ($customer['pec'] !== '' && !filter_var($customer['pec'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Indirizzo PEC non valido.');
    }
    return $customer;
}

function voucherValidUntil(DateTimeImmutable $paidAt): DateTimeImmutable
{
    $nextYear = $paidAt->modify('first day of this month')->modify('+12 months');
    $day = min((int)$paidAt->format('d'), (int)$nextYear->format('t'));
    return $nextYear->setDate((int)$nextYear->format('Y'), (int)$nextYear->format('m'), $day);
}

function voucherCanUse(array $voucher, DateTimeImmutable $now): bool
{
    return $voucher['status'] === 'paid' && (int)$voucher['remaining_sessions'] > 0
        && $voucher['valid_until'] !== null && new DateTimeImmutable($voucher['valid_until']) > $now;
}

function voucherPaymentMatches(array $voucher, array $checkout, string $currency): bool
{
    return isset($checkout['checkout_reference'], $checkout['currency'], $checkout['amount'])
        && hash_equals($voucher['checkout_reference'], (string)$checkout['checkout_reference'])
        && strtoupper((string)$checkout['currency']) === $currency
        && abs((float)$checkout['amount'] - (float)$voucher['amount']) < 0.005;
}
