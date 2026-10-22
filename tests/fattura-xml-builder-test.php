<?php

require_once dirname(__DIR__) . '/fattura-xml-builder.php';

function assertInvoiceXml(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

assertInvoiceXml(feSupplierVat(['fornitore_piva' => '12345678901']) === '12345678901', 'La partita IVA valida non viene accettata.');
assertInvoiceXml(feSupplierVat([]) === '18730391002', 'La partita IVA predefinita del professionista non viene usata quando il campo è vuoto.');
assertInvoiceXml(feSupplierTaxCode(['fornitore_cf' => 'SRBNTP85C69Z241F']) === 'SRBNTP85C69Z241F', 'Il codice fiscale del professionista non viene mantenuto.');
assertInvoiceXml(feSupplierTaxCode([]) === 'SRBNTP85C69Z241F', 'Il codice fiscale predefinito del professionista non viene usato quando manca.');
assertInvoiceXml(feSupplierEmail([]) === 'info@thaitikmassage.it', 'L’email predefinita del professionista non viene usata quando manca.');

foreach ([
    ['fornitore_piva' => '00000000000'],
    ['fornitore_piva' => '1234567890'],
] as $invalidSupplier) {
    try {
        feSupplierVat($invalidSupplier);
        throw new RuntimeException('Una partita IVA fornitore non valida è stata accettata.');
    } catch (InvalidArgumentException) {
    }
}
if (!class_exists(DOMDocument::class)) {
    echo "Supplier VAT checks passed; XML assertions skipped because the PHP DOM extension is unavailable.\n";
    exit(0);
}

$xml = buildFatturaElettronicaXml([
    'fornitore_piva' => '12345678901',
    'fornitore_nome' => 'Tik',
    'fornitore_cognome' => 'Thai',
    'fornitore_regime_fiscale' => 'RF19',
    'cliente_nome' => 'Anna',
    'cliente_cognome' => 'Rossi',
    'line_descrizione' => 'Massaggio Thai',
    'line_quantita' => 1,
    'line_prezzo_unitario' => 35,
    'line_importo_netto' => 35,
    'line_importo_iva' => 0,
    'line_iva_percentuale' => 0,
    'natura_iva' => 'N2.2',
    'importo_totale' => 35,
    'data_emissione' => '2026-06-13',
    'numero_fattura' => 'TS-2026-00001',
]);

$document = new DOMDocument();
assertInvoiceXml($document->loadXML($xml), 'L’XML prodotto non è valido.');
assertInvoiceXml(
    $document->documentElement?->namespaceURI === 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2',
    'Il namespace FatturaPA non è corretto.'
);
assertInvoiceXml($document->getElementsByTagName('CedentePrestatore')->item(0)?->getElementsByTagName('CodiceFiscale')->item(0)?->textContent === 'SRBNTP85C69Z241F', 'Il codice fiscale del professionista non è riportato nel cedente.');
assertInvoiceXml($document->getElementsByTagName('Contatti')->item(0)?->getElementsByTagName('Email')->item(0)?->textContent === 'info@thaitikmassage.it', 'L’email del professionista non è riportata nei contatti del cedente.');
assertInvoiceXml($document->getElementsByTagName('ImponibileImporto')->item(0)?->textContent === '35.00', 'L’imponibile riepilogato non coincide con la riga da 35 euro.');
assertInvoiceXml($document->getElementsByTagName('ImponibileImposta')->length === 0, 'È presente il nodo riepilogo errato ImponibileImposta.');
assertInvoiceXml($document->getElementsByTagName('ImportoTotaleDocumento')->item(0)?->textContent === '35.00', 'Il totale documento non coincide con imponibile più imposta.');
assertInvoiceXml(strpos($xml, 'Professione esercitata ai sensi della Legge 14 gennaio 2013, n. 4') !== false, 'La frase obbligatoria non è inclusa nel file XML Aruba.');

$reference = $document->getElementsByTagName('RiferimentoNormativo')->item(0)?->textContent ?? '';
assertInvoiceXml($reference !== '' && strlen($reference) <= 100, 'Il riferimento normativo supera il limite di 100 caratteri o è vuoto.');

echo "FatturaPA XML builder checks passed.\n";
