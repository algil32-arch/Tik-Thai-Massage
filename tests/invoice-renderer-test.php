<?php

require_once dirname(__DIR__) . '/fattura-cortesia-renderer.php';

$invoiceData = [
    'fornitore_nome' => 'Tik Thai',
    'fornitore_cognome' => 'Massage',
    'fornitore_piva' => '18730391002',
    'fornitore_cf' => 'SRBNTP85C69Z241F',
    'fornitore_email' => 'info@thaitikmassage.it',
    'fornitore_indirizzo' => 'Via Roma 12',
    'fornitore_cap' => '00100',
    'fornitore_citta' => 'Roma',
    'fornitore_provincia' => 'RM',
    'fornitore_regime_fiscale' => 'RF19',
    'cliente_nome' => 'Anna',
    'cliente_cognome' => 'Rossi',
    'cliente_indirizzo' => 'Via Garibaldi 4',
    'cliente_cap' => '20100',
    'cliente_citta' => 'Milano',
    'cliente_provincia' => 'MI',
    'servizio_nome' => 'Massaggio Thai tradizionale con oli aromatici, trattamento personalizzato',
    'quantita' => 1,
    'prezzo_unitario' => 100,
    'iva_percentuale' => 0,
    'natura_iva' => 'N2.2',
    'importo_netto' => 100,
    'iva_totale' => 0,
    'importo_bollo' => 2,
    'bollo_addebitato' => 1,
    'bollo_virtuale' => 1,
    'importo_totale' => 102,
    'data_emissione' => '2026-06-13',
    'data_scadenza' => '2026-07-13',
    'modalita_pagamento' => 'Pagamento online (MP05)',
];

$invoice = renderInvoiceCourtesyPdf('TS-2026-00001', $invoiceData);
$courtesy = renderInvoiceCourtesyPdf('TS-2026-00001', $invoiceData, true);
$supplierDefaults = renderInvoiceCourtesyPdf('TS-2026-00004', array_diff_key($invoiceData, array_flip([
    'fornitore_piva',
    'fornitore_cf',
    'fornitore_email',
])));
$nonChargedStamp = renderInvoiceCourtesyPdf('TS-2026-00002', array_replace($invoiceData, [
    'bollo_addebitato' => 0,
    'importo_totale' => 100,
]));

if (!str_starts_with($invoice, '%PDF-1.4') || !str_ends_with($invoice, "%%EOF\n")) {
    throw new RuntimeException('La fattura non ha una struttura PDF valida.');
}
foreach (['TS-2026-00001', 'Anna Rossi', 'Massaggio Thai tradizionale', 'EUR 102,00', 'N2.2', 'P.IVA: 18730391002', 'C.F.: SRBNTP85C69Z241F', 'Email: info@thaitikmassage.it'] as $expected) {
    if (strpos($invoice, $expected) === false) {
        throw new RuntimeException('Testo fattura mancante: ' . $expected);
    }
}
foreach (['P.IVA: 18730391002', 'C.F.: SRBNTP85C69Z241F', 'Email: info@thaitikmassage.it'] as $expected) {
    if (strpos($courtesy, $expected) === false) {
        throw new RuntimeException('Dato del professionista mancante nella copia di cortesia: ' . $expected);
    }
    if (strpos($supplierDefaults, $expected) === false) {
        throw new RuntimeException('Dato predefinito del professionista mancante nella fattura originale: ' . $expected);
    }
}
foreach (['/MediaBox [0 0 612 792]', 'Data di scadenza: 13/07/2026', 'FATTURA A', 'DESCRIZIONE', 'SUBTOTALE', 'Imposta di bollo'] as $expected) {
    if (strpos($invoice, $expected) === false) {
        throw new RuntimeException('Il layout del modello fattura non contiene: ' . $expected);
    }
}
if (strpos($invoice, 'COPIA DI CORTESIA') !== false || strpos($courtesy, 'COPIA DI CORTESIA') === false) {
    throw new RuntimeException('La dicitura della copia di cortesia non è coerente.');
}
if (strpos($courtesy, 'Non sostituisce il documento fiscale elettronico') === false) {
    throw new RuntimeException('La copia di cortesia manca della relativa avvertenza.');
}
if (strpos($nonChargedStamp, 'EUR 2,00') !== false || strpos($nonChargedStamp, 'Imposta di bollo da 2 euro assolta') === false) {
    throw new RuntimeException('Il bollo virtuale non addebitato non è rappresentato correttamente.');
}

$fallbackDirectory = sys_get_temp_dir() . '/invoice-logo-fallback-' . bin2hex(random_bytes(4));
if (!mkdir($fallbackDirectory) || !copy(dirname(__DIR__) . '/thai-tik-logo-alternativo.png', $fallbackDirectory . '/thai-tik-logo-alternativo.png')) {
    throw new RuntimeException('Impossibile preparare il test del logo alternativo.');
}
try {
    $fallbackLogo = invoicePdfLoadLogo($fallbackDirectory);
    if ($fallbackLogo['width'] !== 575 || $fallbackLogo['height'] !== 500 || $fallbackLogo['image_filter'] !== 'FlateDecode') {
        throw new RuntimeException('Il logo del sito non viene usato come fallback.');
    }
} finally {
    unlink($fallbackDirectory . '/thai-tik-logo-alternativo.png');
    rmdir($fallbackDirectory);
}

$emptyAssetDirectory = sys_get_temp_dir() . '/invoice-logo-empty-' . bin2hex(random_bytes(4));
if (!mkdir($emptyAssetDirectory)) {
    throw new RuntimeException('Impossibile preparare il test senza logo.');
}
try {
    $noLogoPdf = renderInvoiceCourtesyPdf('TS-2026-00003', $invoiceData, false, $emptyAssetDirectory);
    if (invoicePdfLoadLogo($emptyAssetDirectory) !== null || strpos($noLogoPdf, 'THAI TIK MASSAGE') === false) {
        throw new RuntimeException('La fattura deve poter essere generata anche senza file logo.');
    }
} finally {
    rmdir($emptyAssetDirectory);
}

echo "Invoice renderer checks passed.\n";
