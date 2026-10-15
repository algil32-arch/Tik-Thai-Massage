<?php

require_once dirname(__DIR__) . '/fattura-numerazione.php';

function assertInvoiceNumber(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

assertInvoiceNumber(
    nextInvoiceNumberFromExisting(['TS-2026-00001', 'TS-2026-00003', 'BOZZA-2026-00004'], '2026-10-15') === 'TS-2026-00004',
    'Il nuovo progressivo non continua dopo il numero massimo dello stesso anno.'
);
assertInvoiceNumber(
    nextInvoiceNumberFromExisting(['TS-2025-00012', 'TS-2026-00002', 'TS-2026-00008-ANN-3'], '2026-10-15') === 'TS-2026-00009',
    'Sono stati considerati numeri di un altro anno oppure è stato riutilizzato un progressivo archiviato.'
);
assertInvoiceNumber(
    nextInvoiceNumberFromExisting([], '2027-01-01') === 'TS-2027-00001',
    'Il progressivo non riparte da 00001 per un anno senza fatture.'
);

try {
    nextInvoiceNumberFromExisting([], 'invalid');
    throw new RuntimeException('Una data di emissione non valida è stata accettata.');
} catch (InvalidArgumentException) {
}

echo "Invoice numbering checks passed.\n";
