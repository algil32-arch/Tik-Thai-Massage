<?php

function feMoney(float $value): string
{
    return number_format($value, 2, '.', '');
}

function feSafeAscii(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($normalized === false) {
        $normalized = $value;
    }
    $normalized = preg_replace('/[^A-Za-z0-9 .,:;\-\/()]/', ' ', $normalized) ?? '';
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';
    return trim($normalized);
}

function feText(DOMDocument $doc, DOMElement $parent, string $name, string $value): DOMElement
{
    $node = $doc->createElement($name);
    $node->appendChild($doc->createTextNode($value));
    $parent->appendChild($node);
    return $node;
}

function feSupplierVat(array $payload): string
{
    $rawSupplierVat = trim((string)($payload['fornitore_piva'] ?? ''));
    $supplierVat = $rawSupplierVat === ''
        ? '18730391002'
        : (preg_replace('/\D+/', '', $rawSupplierVat) ?? '');
    if (preg_match('/^\d{11}$/', $supplierVat) !== 1 || $supplierVat === '00000000000') {
        throw new InvalidArgumentException('La partita IVA del fornitore deve contenere 11 cifre e non può essere tutta zero; correggere i dati fiscali del professionista.');
    }
    return $supplierVat;
}

function feSupplierTaxCode(array $payload): string
{
    $supplierCf = strtoupper(trim((string)($payload['fornitore_cf'] ?? '')));
    if ($supplierCf === '') {
        $supplierCf = 'SRBNTP85C69Z241F';
    }
    if (preg_match('/^[A-Z0-9]{16}$/', $supplierCf) !== 1) {
        throw new InvalidArgumentException('Il codice fiscale del professionista non è valido; correggere i dati fiscali del profilo.');
    }
    return $supplierCf;
}

function feSupplierEmail(array $payload): string
{
    $supplierEmail = trim((string)($payload['fornitore_email'] ?? ''));
    if ($supplierEmail === '') {
        $supplierEmail = 'info@thaitikmassage.it';
    }
    if (filter_var($supplierEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('L’email del professionista non è valida; correggere i dati del profilo.');
    }
    return $supplierEmail;
}

function buildFatturaElettronicaXml(array $payload): string
{
    $supplierVat = feSupplierVat($payload);
    $supplierCf = feSupplierTaxCode($payload);
    $supplierEmail = feSupplierEmail($payload);
    $customerVat = preg_replace('/\D+/', '', (string)($payload['cliente_piva'] ?? '')) ?? '';
    $customerCf = strtoupper(trim((string)($payload['cliente_cf'] ?? '')));

    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;

    $root = $doc->createElementNS('http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2', 'p:FatturaElettronica');
    $root->setAttribute('versione', 'FPR12');
    $doc->appendChild($root);

    $header = $doc->createElement('FatturaElettronicaHeader');
    $root->appendChild($header);

    $recipientCode = strtoupper(trim((string)($payload['cliente_codice_destinatario'] ?? $payload['codice_destinatario'] ?? '')));
    if ($recipientCode === '') {
        $recipientCode = '0000000';
    }
    $customerPec = trim((string)($payload['cliente_pec'] ?? ''));

    $datiTrasmissione = $doc->createElement('DatiTrasmissione');
    $header->appendChild($datiTrasmissione);
    $idTrasmittente = $doc->createElement('IdTrasmittente');
    $datiTrasmissione->appendChild($idTrasmittente);
    feText($doc, $idTrasmittente, 'IdPaese', 'IT');
    feText($doc, $idTrasmittente, 'IdCodice', $supplierVat);
    feText($doc, $datiTrasmissione, 'ProgressivoInvio', str_pad((string)($payload['progressivo_invio'] ?? $payload['id'] ?? 1), 5, '0', STR_PAD_LEFT));
    feText($doc, $datiTrasmissione, 'FormatoTrasmissione', 'FPR12');
    feText($doc, $datiTrasmissione, 'CodiceDestinatario', $recipientCode);
    if ($recipientCode === '0000000' && $customerPec !== '') {
        feText($doc, $datiTrasmissione, 'PECDestinatario', $customerPec);
    }

    $cedente = $doc->createElement('CedentePrestatore');
    $header->appendChild($cedente);
    $cedenteDatiAnagrafici = $doc->createElement('DatiAnagrafici');
    $cedente->appendChild($cedenteDatiAnagrafici);
    $idFiscaleIva = $doc->createElement('IdFiscaleIVA');
    $cedenteDatiAnagrafici->appendChild($idFiscaleIva);
    feText($doc, $idFiscaleIva, 'IdPaese', 'IT');
    feText($doc, $idFiscaleIva, 'IdCodice', $supplierVat);
    feText($doc, $cedenteDatiAnagrafici, 'CodiceFiscale', $supplierCf);

    $cedenteAnagrafica = $doc->createElement('Anagrafica');
    $cedenteDatiAnagrafici->appendChild($cedenteAnagrafica);
    $supplierBusinessName = trim((string)($payload['fornitore_ragione_sociale'] ?? ''));
    if ($supplierBusinessName !== '') {
        feText($doc, $cedenteAnagrafica, 'Denominazione', feSafeAscii($supplierBusinessName));
    } else {
        feText($doc, $cedenteAnagrafica, 'Nome', feSafeAscii((string)($payload['fornitore_nome'] ?? 'NOME')));
        feText($doc, $cedenteAnagrafica, 'Cognome', feSafeAscii((string)($payload['fornitore_cognome'] ?? 'COGNOME')));
    }

    $regime = trim((string)($payload['fornitore_regime_fiscale'] ?? ''));
    if ($regime === '') {
        $regime = ((float)($payload['iva_totale'] ?? 0.0) === 0.0) ? 'RF19' : 'RF01';
    }
    feText($doc, $cedenteDatiAnagrafici, 'RegimeFiscale', $regime);

    $cedenteSede = $doc->createElement('Sede');
    $cedente->appendChild($cedenteSede);
    feText($doc, $cedenteSede, 'Indirizzo', feSafeAscii((string)($payload['fornitore_indirizzo'] ?? 'ND')) ?: 'ND');
    feText($doc, $cedenteSede, 'CAP', str_pad(substr((preg_replace('/\D+/', '', (string)($payload['fornitore_cap'] ?? '00100')) ?? '00100'), 0, 5), 5, '0', STR_PAD_LEFT));
    feText($doc, $cedenteSede, 'Comune', feSafeAscii((string)($payload['fornitore_citta'] ?? 'Roma')) ?: 'Roma');
    $supplierProvince = strtoupper(trim((string)($payload['fornitore_provincia'] ?? 'RM')));
    feText($doc, $cedenteSede, 'Provincia', $supplierProvince !== '' ? substr($supplierProvince, 0, 2) : 'RM');
    feText($doc, $cedenteSede, 'Nazione', 'IT');

    $contatti = $doc->createElement('Contatti');
    $cedente->appendChild($contatti);
    feText($doc, $contatti, 'Email', $supplierEmail);

    $cessionario = $doc->createElement('CessionarioCommittente');
    $header->appendChild($cessionario);
    $cessionarioDatiAnagrafici = $doc->createElement('DatiAnagrafici');
    $cessionario->appendChild($cessionarioDatiAnagrafici);
    if ($customerVat !== '') {
        $idFiscaleIvaCliente = $doc->createElement('IdFiscaleIVA');
        $cessionarioDatiAnagrafici->appendChild($idFiscaleIvaCliente);
        feText($doc, $idFiscaleIvaCliente, 'IdPaese', 'IT');
        feText($doc, $idFiscaleIvaCliente, 'IdCodice', $customerVat);
    }
    if ($customerCf !== '') {
        feText($doc, $cessionarioDatiAnagrafici, 'CodiceFiscale', $customerCf);
    }

    $cessionarioAnagrafica = $doc->createElement('Anagrafica');
    $cessionarioDatiAnagrafici->appendChild($cessionarioAnagrafica);
    $customerBusinessName = trim((string)($payload['cliente_ragione_sociale'] ?? $payload['ragione_sociale'] ?? ''));
    if ($customerBusinessName !== '') {
        feText($doc, $cessionarioAnagrafica, 'Denominazione', feSafeAscii($customerBusinessName));
    } else {
        feText($doc, $cessionarioAnagrafica, 'Nome', feSafeAscii((string)($payload['cliente_nome'] ?? 'Cliente')));
        $surname = trim((string)($payload['cliente_cognome'] ?? ''));
        if ($surname !== '') {
            feText($doc, $cessionarioAnagrafica, 'Cognome', feSafeAscii($surname));
        }
    }

    $cessionarioSede = $doc->createElement('Sede');
    $cessionario->appendChild($cessionarioSede);
    feText($doc, $cessionarioSede, 'Indirizzo', feSafeAscii((string)($payload['cliente_indirizzo'] ?? 'ND')) ?: 'ND');
    feText($doc, $cessionarioSede, 'CAP', str_pad(substr((preg_replace('/\D+/', '', (string)($payload['cliente_cap'] ?? '00100')) ?? '00100'), 0, 5), 5, '0', STR_PAD_LEFT));
    feText($doc, $cessionarioSede, 'Comune', feSafeAscii((string)($payload['cliente_citta'] ?? 'Roma')) ?: 'Roma');
    $customerProvince = strtoupper(trim((string)($payload['cliente_provincia'] ?? 'RM')));
    feText($doc, $cessionarioSede, 'Provincia', $customerProvince !== '' ? substr($customerProvince, 0, 2) : 'RM');
    feText($doc, $cessionarioSede, 'Nazione', 'IT');

    $body = $doc->createElement('FatturaElettronicaBody');
    $root->appendChild($body);

    $datiGenerali = $doc->createElement('DatiGenerali');
    $body->appendChild($datiGenerali);
    $datiGeneraliDocumento = $doc->createElement('DatiGeneraliDocumento');
    $datiGenerali->appendChild($datiGeneraliDocumento);
    feText($doc, $datiGeneraliDocumento, 'TipoDocumento', (string)($payload['tipo_documento'] ?? 'TD01'));
    feText($doc, $datiGeneraliDocumento, 'Divisa', 'EUR');
    feText($doc, $datiGeneraliDocumento, 'Data', (string)($payload['data_emissione'] ?? date('Y-m-d')));
    feText($doc, $datiGeneraliDocumento, 'Numero', feSafeAscii((string)($payload['numero_fattura'] ?? '')));

    $total = (float)($payload['importo_totale'] ?? 0.0);
    $stampAmount = (float)($payload['importo_bollo'] ?? 0.0);
    $useVirtualStamp = (int)($payload['bollo_virtuale'] ?? 0) === 1;
    if (!$useVirtualStamp && $stampAmount <= 0 && $total > 77.47) {
        $useVirtualStamp = true;
        $stampAmount = 2.00;
    }
    if ($useVirtualStamp && $stampAmount > 0) {
        $datiBollo = $doc->createElement('DatiBollo');
        $datiGeneraliDocumento->appendChild($datiBollo);
        feText($doc, $datiBollo, 'BolloVirtuale', 'SI');
        feText($doc, $datiBollo, 'ImportoBollo', feMoney($stampAmount));
    }
    feText($doc, $datiGeneraliDocumento, 'ImportoTotaleDocumento', feMoney($total));

    $lines = $payload['righe'] ?? [];
    if (!is_array($lines) || count($lines) === 0) {
        $lineNet = (float)($payload['line_importo_netto'] ?? $total);
        $lineVat = (float)($payload['line_importo_iva'] ?? 0.0);
        $lineRate = (float)($payload['line_iva_percentuale'] ?? 0.0);
        $lines = [[
            'descrizione' => (string)($payload['line_descrizione'] ?? 'Prestazione'),
            'quantita' => (float)($payload['line_quantita'] ?? 1.0),
            'prezzo_unitario' => (float)($payload['line_prezzo_unitario'] ?? $lineNet),
            'importo_netto' => $lineNet,
            'iva_percentuale' => $lineRate,
            'importo_iva' => $lineVat,
        ]];
    }

    $datiBeniServizi = $doc->createElement('DatiBeniServizi');
    $body->appendChild($datiBeniServizi);

    $lineNumber = 1;
    $grouped = [];
    $defaultNature = trim((string)($payload['natura_iva'] ?? ''));
    $referenceLaw = 'L. 190/2014, art. 1, commi 54-89';
    foreach ($lines as $line) {
        $qty = (float)($line['quantita'] ?? 1.0);
        $unitPrice = (float)($line['prezzo_unitario'] ?? 0.0);
        $lineNet = (float)($line['importo_netto'] ?? 0.0);
        $vatRate = (float)($line['iva_percentuale'] ?? 0.0);
        $lineVat = (float)($line['importo_iva'] ?? 0.0);
        $nature = trim((string)($line['natura'] ?? $defaultNature));
        if ($vatRate == 0.0 && $nature === '') {
            $nature = 'N2.2';
        }

        $dettaglio = $doc->createElement('DettaglioLinee');
        $datiBeniServizi->appendChild($dettaglio);
        feText($doc, $dettaglio, 'NumeroLinea', (string)$lineNumber++);
        feText($doc, $dettaglio, 'Descrizione', feSafeAscii((string)($line['descrizione'] ?? 'Prestazione')) ?: 'Prestazione');
        feText($doc, $dettaglio, 'Quantita', feMoney($qty));
        feText($doc, $dettaglio, 'PrezzoUnitario', feMoney($unitPrice));
        feText($doc, $dettaglio, 'PrezzoTotale', feMoney($lineNet));
        feText($doc, $dettaglio, 'AliquotaIVA', feMoney($vatRate));
        if ($vatRate == 0.0 && $nature !== '') {
            feText($doc, $dettaglio, 'Natura', $nature);
        }

        $key = feMoney($vatRate) . '|' . $nature;
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'rate' => $vatRate,
                'nature' => $nature,
                'net' => 0.0,
                'vat' => 0.0,
            ];
        }
        $grouped[$key]['net'] += $lineNet;
        $grouped[$key]['vat'] += $lineVat;
    }

    foreach ($grouped as $group) {
        $riepilogo = $doc->createElement('DatiRiepilogo');
        $datiBeniServizi->appendChild($riepilogo);
        feText($doc, $riepilogo, 'AliquotaIVA', feMoney((float)$group['rate']));
        if ((float)$group['rate'] == 0.0 && $group['nature'] !== '') {
            feText($doc, $riepilogo, 'Natura', (string)$group['nature']);
        }
        feText($doc, $riepilogo, 'ImponibileImporto', feMoney((float)$group['net']));
        feText($doc, $riepilogo, 'Imposta', feMoney((float)$group['vat']));
        if ((float)$group['rate'] == 0.0 && $group['nature'] !== '') {
            feText($doc, $riepilogo, 'RiferimentoNormativo', $referenceLaw);
        }
    }

    $datiPagamento = $doc->createElement('DatiPagamento');
    $body->appendChild($datiPagamento);
    feText($doc, $datiPagamento, 'CondizioniPagamento', (string)($payload['condizioni_pagamento'] ?? 'TP02'));
    $dettaglioPagamento = $doc->createElement('DettaglioPagamento');
    $datiPagamento->appendChild($dettaglioPagamento);
    feText($doc, $dettaglioPagamento, 'ModalitaPagamento', (string)($payload['modalita_pagamento'] ?? 'MP05'));
    feText($doc, $dettaglioPagamento, 'ImportoPagamento', feMoney($total));

    return $doc->saveXML() ?: '';
}
