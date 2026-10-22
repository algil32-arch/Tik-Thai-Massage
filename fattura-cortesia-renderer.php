<?php

function invoicePdfEscapeText(string $value): string
{
    $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
    if ($encoded === false) {
        $encoded = '';
    }
    $encoded = preg_replace('/[\x00-\x1F\x7F]/', ' ', $encoded) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
}

function invoicePdfText(string $text, float $x, float $y, string $font = 'F1', int $size = 10, array $color = [0.32, 0.24, 0.19]): string
{
    return sprintf(
        "%.3F %.3F %.3F rg BT /%s %d Tf %.2F %.2F Td (%s) Tj ET\n",
        $color[0],
        $color[1],
        $color[2],
        $font,
        $size,
        $x,
        $y,
        invoicePdfEscapeText($text)
    );
}

function invoicePdfWrap(string $text, int $lineLength): array
{
    $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', trim($text));
    if ($encoded === false || $encoded === '') {
        return [];
    }
    $encoded = preg_replace('/[\x00-\x1F\x7F]/', ' ', $encoded) ?? '';
    $wrapped = wordwrap($encoded, $lineLength, "\n", true);
    $utf8 = iconv('Windows-1252', 'UTF-8//IGNORE', $wrapped);
    return explode("\n", $utf8 === false ? '' : $utf8);
}

function invoicePdfRect(float $x, float $y, float $width, float $height, array $color): string
{
    return sprintf(
        "%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
        $color[0],
        $color[1],
        $color[2],
        $x,
        $y,
        $width,
        $height
    );
}

function invoicePdfLine(float $x1, float $y1, float $x2, float $y2, array $color, float $width = 0.5): string
{
    return sprintf(
        "%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
        $color[0],
        $color[1],
        $color[2],
        $width,
        $x1,
        $y1,
        $x2,
        $y2
    );
}

function invoicePdfAddress(array $data, string $prefix): array
{
    $lines = [];
    $address = trim((string)($data[$prefix . '_indirizzo'] ?? ''));
    $postal = trim((string)($data[$prefix . '_cap'] ?? ''));
    $city = trim((string)($data[$prefix . '_citta'] ?? ''));
    $province = trim((string)($data[$prefix . '_provincia'] ?? ''));
    $cityLine = trim($postal . ' ' . $city . ($province !== '' ? ' (' . $province . ')' : ''));
    if ($address !== '') {
        array_push($lines, ...invoicePdfWrap($address, 37));
    }
    if ($cityLine !== '') {
        array_push($lines, ...invoicePdfWrap($cityLine, 37));
    }
    $vat = trim((string)($data[$prefix . '_piva'] ?? ''));
    if ($prefix === 'fornitore' && $vat === '') {
        $vat = '18730391002';
    }
    if ($vat !== '') {
        $lines[] = 'P.IVA: ' . $vat;
    }
    $taxCode = trim((string)($data[$prefix . '_cf'] ?? ''));
    if ($prefix === 'fornitore' && $taxCode === '') {
        $taxCode = 'SRBNTP85C69Z241F';
    }
    if ($taxCode !== '') {
        $lines[] = 'C.F.: ' . $taxCode;
    }
    $email = trim((string)($data[$prefix . '_email'] ?? ''));
    if ($prefix === 'fornitore' && $email === '') {
        $email = 'info@thaitikmassage.it';
    }
    if ($email !== '') {
        $lines[] = 'Email: ' . $email;
    }
    return $lines;
}

function invoicePdfCenteredText(string $text, float $centerX, float $y, string $font, int $size, array $color): string
{
    $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
    $length = $encoded === false ? 0 : strlen($encoded);
    return invoicePdfText($text, max(45, $centerX - ($length * $size * 0.25)), $y, $font, $size, $color);
}

function invoicePdfPaethPredictor(int $left, int $above, int $upperLeft): int
{
    $estimate = $left + $above - $upperLeft;
    $leftDistance = abs($estimate - $left);
    $aboveDistance = abs($estimate - $above);
    $upperLeftDistance = abs($estimate - $upperLeft);
    if ($leftDistance <= $aboveDistance && $leftDistance <= $upperLeftDistance) {
        return $left;
    }
    if ($aboveDistance <= $upperLeftDistance) {
        return $above;
    }
    return $upperLeft;
}

function invoicePdfReadPngLogo(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $png = file_get_contents($path);
    if ($png === false) {
        return null;
    }
    if (substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        throw new RuntimeException('Impossibile leggere il logo PNG del sito.');
    }
    $offset = 8;
    $width = 0;
    $height = 0;
    $bitDepth = 0;
    $colorType = 0;
    $compressedData = '';
    while ($offset + 12 <= strlen($png)) {
        $chunkLength = unpack('Nlength', substr($png, $offset, 4))['length'];
        $chunkType = substr($png, $offset + 4, 4);
        $chunkData = substr($png, $offset + 8, $chunkLength);
        if ($chunkType === 'IHDR') {
            $header = unpack('Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace', $chunkData);
            $width = (int)$header['width'];
            $height = (int)$header['height'];
            $bitDepth = (int)$header['bitDepth'];
            $colorType = (int)$header['colorType'];
            if ($bitDepth !== 8 || $colorType !== 6 || (int)$header['interlace'] !== 0) {
                throw new RuntimeException('Il logo PNG deve essere RGBA a 8 bit e non interlacciato.');
            }
        } elseif ($chunkType === 'IDAT') {
            $compressedData .= $chunkData;
        } elseif ($chunkType === 'IEND') {
            break;
        }
        $offset += 12 + $chunkLength;
    }
    if ($width <= 0 || $height <= 0 || $compressedData === '') {
        throw new RuntimeException('Il file PNG del logo non contiene dati validi.');
    }
    $filtered = gzuncompress($compressedData);
    $bytesPerPixel = 4;
    $rowLength = $width * $bytesPerPixel;
    if ($filtered === false || strlen($filtered) !== $height * ($rowLength + 1)) {
        throw new RuntimeException('Impossibile decodificare i pixel del logo PNG.');
    }

    $rgb = '';
    $alpha = '';
    $previousRow = array_fill(0, $rowLength, 0);
    $sourceOffset = 0;
    for ($y = 0; $y < $height; $y++) {
        $filter = ord($filtered[$sourceOffset++]);
        $row = [];
        for ($x = 0; $x < $rowLength; $x++) {
            $rawByte = ord($filtered[$sourceOffset++]);
            $left = $x >= $bytesPerPixel ? $row[$x - $bytesPerPixel] : 0;
            $above = $previousRow[$x];
            $upperLeft = $x >= $bytesPerPixel ? $previousRow[$x - $bytesPerPixel] : 0;
            if ($filter === 1) {
                $rawByte += $left;
            } elseif ($filter === 2) {
                $rawByte += $above;
            } elseif ($filter === 3) {
                $rawByte += intdiv($left + $above, 2);
            } elseif ($filter === 4) {
                $rawByte += invoicePdfPaethPredictor($left, $above, $upperLeft);
            } elseif ($filter !== 0) {
                throw new RuntimeException('Il logo PNG usa un filtro non supportato.');
            }
            $row[$x] = $rawByte & 0xff;
        }
        for ($x = 0; $x < $rowLength; $x += $bytesPerPixel) {
            $rgb .= chr($row[$x]) . chr($row[$x + 1]) . chr($row[$x + 2]);
            $alpha .= chr($row[$x + 3]);
        }
        $previousRow = $row;
    }

    return [
        'width' => $width,
        'height' => $height,
        'image_bytes' => gzcompress($rgb, 9),
        'image_filter' => 'FlateDecode',
        'mask_bytes' => gzcompress($alpha, 9),
    ];
}

function invoicePdfLoadLogo(?string $assetDirectory = null): ?array
{
    $assetDirectory ??= __DIR__;
    $logoPath = $assetDirectory . '/invoice-template-logo.jpg';
    $maskPath = $assetDirectory . '/invoice-template-logo-mask.deflate';
    if (is_file($logoPath) && is_readable($logoPath) && is_file($maskPath) && is_readable($maskPath)) {
        $logoBytes = file_get_contents($logoPath);
        $maskBytes = file_get_contents($maskPath);
        $logoInfo = $logoBytes !== false ? getimagesize($logoPath) : false;
        if ($logoBytes !== false && $maskBytes !== false && $logoInfo !== false && ($logoInfo['mime'] ?? '') === 'image/jpeg') {
            $width = (int)$logoInfo[0];
            $height = (int)$logoInfo[1];
            $maskPixels = gzuncompress($maskBytes);
            if ($maskPixels !== false && strlen($maskPixels) === $width * $height) {
                return [
                    'width' => $width,
                    'height' => $height,
                    'image_bytes' => $logoBytes,
                    'image_filter' => 'DCTDecode',
                    'mask_bytes' => $maskBytes,
                ];
            }
        }
    }

    $siteLogoPath = $assetDirectory . '/thai-tik-logo-alternativo.png';
    return is_file($siteLogoPath) ? invoicePdfReadPngLogo($siteLogoPath) : null;
}

function renderInvoiceCourtesyPdf(
    string $invoiceNumber,
    array $data,
    bool $courtesyCopy = false,
    ?string $assetDirectory = null
): string
{
    $orange = [0.66, 0.25, 0.02];
    $ink = [0.30, 0.19, 0.13];
    $muted = [0.43, 0.36, 0.30];
    $olive = [0.75, 0.72, 0.56];
    $line = [0.82, 0.78, 0.69];
    $pale = [0.98, 0.97, 0.93];
    $stream = invoicePdfRect(0, 0, 612, 792, [1, 1, 1]);

    $logo = invoicePdfLoadLogo($assetDirectory);

    $supplier = trim((string)($data['fornitore_ragione_sociale'] ?? ''));
    if ($supplier === '') {
        $supplier = trim((string)($data['fornitore_nome'] ?? '') . ' ' . (string)($data['fornitore_cognome'] ?? ''));
    }
    $customer = trim((string)($data['cliente_ragione_sociale'] ?? ''));
    if ($customer === '') {
        $customer = trim((string)($data['cliente_nome'] ?? '') . ' ' . (string)($data['cliente_cognome'] ?? ''));
    }
    $customerLines = array_merge(invoicePdfWrap($customer, 34), invoicePdfAddress($data, 'cliente'));
    if ($logo !== null) {
        $stream .= "q 110 0 0 96 47 628 cm /Logo Do Q\n";
    } else {
        $stream .= invoicePdfText('THAI TIK MASSAGE', 57, 684, 'F3', 16, $orange);
        $stream .= invoicePdfLine(55, 672, 166, 672, $olive, 1.2);
    }
    $stream .= invoicePdfText('Fattura n. ' . $invoiceNumber, 397, 678, 'F3', 17, $orange);
    if ($courtesyCopy) {
        $stream .= invoicePdfText('COPIA DI CORTESIA', 398, 660, 'F3', 9, $muted);
    }

    $issueDate = trim((string)($data['data_emissione'] ?? date('Y-m-d')));
    $issueTimestamp = strtotime($issueDate);
    $issueDate = $issueTimestamp === false ? $issueDate : date('d/m/Y', $issueTimestamp);
    $stream .= invoicePdfText('Data fattura: ' . $issueDate, 48, 575, 'F1', 11, $orange);
    $dueDate = trim((string)($data['data_scadenza'] ?? ''));
    if ($dueDate !== '') {
        $dueTimestamp = strtotime($dueDate);
        $dueDate = $dueTimestamp === false ? $dueDate : date('d/m/Y', $dueTimestamp);
        $stream .= invoicePdfText('Data di scadenza: ' . $dueDate, 48, 558, 'F2', 10, $orange);
    }
    $paymentLabel = trim((string)($data['modalita_pagamento'] ?? ''));
    if ($paymentLabel !== '') {
        $stream .= invoicePdfText('Pagamento: ' . $paymentLabel, 48, 541, 'F1', 9, $muted);
    }

    $stream .= invoicePdfText('FATTURA A', 342, 575, 'F3', 11, $orange);
    foreach (array_slice($customerLines, 0, 6) as $index => $text) {
        $stream .= invoicePdfText($text, 342, 558 - ($index * 16), 'F4', 10, $orange);
    }

    $service = trim((string)($data['servizio_nome'] ?? 'Prestazione professionale'));
    $quantity = (float)($data['quantita'] ?? 1.0);
    $net = (float)($data['importo_netto'] ?? 0.0);
    $vat = (float)($data['iva_totale'] ?? 0.0);
    $stamp = (float)($data['importo_bollo'] ?? 0.0);
    $total = (float)($data['importo_totale'] ?? ($net + $vat));
    $unitPrice = (float)($data['prezzo_unitario'] ?? $net);
    $vatRate = (float)($data['iva_percentuale'] ?? 0.0);
    $rows = [];
    foreach (($data['righe'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = [
            'description' => (string)($row['descrizione'] ?? $row['description'] ?? ''),
            'quantity' => (float)($row['quantita'] ?? $row['quantity'] ?? 1.0),
            'unit_price' => (float)($row['prezzo_unitario'] ?? $row['unit_price'] ?? 0.0),
            'net' => (float)($row['importo_netto'] ?? $row['net'] ?? 0.0),
            'vat' => (float)($row['importo_iva'] ?? $row['vat'] ?? 0.0),
        ];
    }
    if ($rows === []) {
        $rows[] = [
            'description' => $service,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'net' => $net,
            'vat' => $vat,
        ];
    }
    if ($stamp > 0 && (int)($data['bollo_addebitato'] ?? 0) === 1) {
        $rows[] = ['description' => 'Imposta di bollo', 'quantity' => 1.0, 'unit_price' => $stamp, 'net' => $stamp, 'vat' => 0.0];
    }

    $tableX = 42.0;
    $tableWidth = 528.0;
    $tableTop = 470.0;
    $headerHeight = 28.0;
    $stream .= invoicePdfRect($tableX, $tableTop - $headerHeight, $tableWidth, $headerHeight, $olive);
    $headerText = [1, 1, 1];
    $stream .= invoicePdfText('DESCRIZIONE', 54, $tableTop - 18, 'F3', 8, $headerText);
    $stream .= invoicePdfCenteredText('QUANTITÀ', 320, $tableTop - 18, 'F3', 8, $headerText);
    $stream .= invoicePdfCenteredText('PREZZO', 392, $tableTop - 18, 'F3', 8, $headerText);
    $stream .= invoicePdfCenteredText('SUBTOTALE', 465, $tableTop - 18, 'F3', 8, $headerText);
    $stream .= invoicePdfCenteredText('IVA', 535, $tableTop - 18, 'F3', 8, $headerText);
    $stream .= invoicePdfLine($tableX, $tableTop - $headerHeight, $tableX + $tableWidth, $tableTop - $headerHeight, $line);
    $columnLines = [290, 352, 430, 500];
    foreach ($columnLines as $columnX) {
        $stream .= invoicePdfLine($columnX, $tableTop, $columnX, $tableTop - $headerHeight, [1, 1, 1], 0.5);
    }

    $cursorY = $tableTop - $headerHeight;
    foreach ($rows as $rowIndex => $row) {
        $descriptionLines = invoicePdfWrap((string)$row['description'], 38);
        if ($descriptionLines === []) {
            $descriptionLines = [''];
        }
        $rowHeight = max(26, (count($descriptionLines) * 13) + 11);
        if ($rowIndex % 2 === 1) {
            $stream .= invoicePdfRect($tableX, $cursorY - $rowHeight, $tableWidth, $rowHeight, [1, 1, 1]);
        }
        foreach ($descriptionLines as $lineIndex => $descriptionLine) {
            $stream .= invoicePdfText($descriptionLine, 48, $cursorY - 18 - ($lineIndex * 13), 'F1', 10, $orange);
        }
        $quantityText = floor((float)$row['quantity']) === (float)$row['quantity']
            ? number_format((float)$row['quantity'], 0, ',', '.')
            : number_format((float)$row['quantity'], 2, ',', '.');
        $stream .= invoicePdfCenteredText($quantityText, 320, $cursorY - 18, 'F1', 10, $orange);
        $stream .= invoicePdfCenteredText(number_format((float)$row['unit_price'], 2, ',', '.'), 391, $cursorY - 18, 'F1', 10, $orange);
        $stream .= invoicePdfCenteredText(number_format((float)$row['net'], 2, ',', '.'), 465, $cursorY - 18, 'F1', 10, $orange);
        $stream .= invoicePdfCenteredText(number_format((float)$row['vat'], 2, ',', '.'), 535, $cursorY - 18, 'F1', 10, $orange);
        $cursorY -= $rowHeight;
        $stream .= invoicePdfLine($tableX, $cursorY, $tableX + $tableWidth, $cursorY, $line);
    }
    $stream .= invoicePdfLine($tableX, $tableTop, $tableX, $cursorY, $line);
    $stream .= invoicePdfLine($tableX + $tableWidth, $tableTop, $tableX + $tableWidth, $cursorY, $line);
    foreach ($columnLines as $columnX) {
        $stream .= invoicePdfLine($columnX, $tableTop - $headerHeight, $columnX, $cursorY, $line);
    }

    $summaryTop = $cursorY - 22;
    $stream .= invoicePdfText('SUBTOTALE', 390, $summaryTop, 'F3', 10, $orange);
    $stream .= invoicePdfText('EUR ' . number_format($net + $vat, 2, ',', '.'), 500, $summaryTop, 'F1', 10, $orange);
    $summaryTop -= 16;
    if ($stamp > 0 && (int)($data['bollo_addebitato'] ?? 0) === 1) {
        $stream .= invoicePdfText('Imposta di bollo', 390, $summaryTop, 'F1', 10, $orange);
        $stream .= invoicePdfText('EUR ' . number_format($stamp, 2, ',', '.'), 500, $summaryTop, 'F1', 10, $orange);
        $summaryTop -= 16;
    }
    if ($vat > 0) {
        $stream .= invoicePdfText('IVA (' . number_format($vatRate, 2, ',', '.') . '%)', 390, $summaryTop, 'F1', 10, $orange);
        $stream .= invoicePdfText('EUR ' . number_format($vat, 2, ',', '.'), 500, $summaryTop, 'F1', 10, $orange);
        $summaryTop -= 16;
    }
    $stream .= invoicePdfText('Totale', 423, $summaryTop - 1, 'F3', 11, $orange);
    $stream .= invoicePdfText('EUR ' . number_format($total, 2, ',', '.'), 500, $summaryTop - 1, 'F1', 10, $orange);

    $footerY = 148;
    $natura = trim((string)($data['natura_iva'] ?? ''));
    if ($natura === 'N2.2' || ((float)$vat === 0.0 && (string)($data['fornitore_regime_fiscale'] ?? '') === 'RF19')) {
        $stream .= invoicePdfCenteredText('Operazione senza applicazione dell’IVA, effettuata ai sensi dell’articolo 1, commi da 54 a 89, L. n. 190 del 2014', 306, 220, 'F4', 9, $orange);
        $stream .= invoicePdfCenteredText('così come modificato dalla L. n. 208 del 2015 e dalla L. n. 145 del 2018', 306, 205, 'F4', 9, $orange);
        $stream .= invoicePdfCenteredText('Regime forfettario · Natura IVA N2.2', 306, 190, 'F4', 8, $orange);
    } elseif ($natura !== '') {
        $stream .= invoicePdfCenteredText('Natura IVA: ' . $natura, 306, 212, 'F4', 9, $orange);
    }
    if ($stamp > 0 && (int)($data['bollo_virtuale'] ?? 0) === 1) {
        $stream .= invoicePdfCenteredText('Imposta di bollo da 2 euro assolta sull’originale per importi maggiori di 77,47 euro', 306, 178, 'F4', 9, $orange);
    }
    $stream .= invoicePdfLine(45, $footerY, 550, $footerY, $line);
    $supplierFooter = array_merge(
        invoicePdfWrap($supplier, 40),
        invoicePdfAddress($data, 'fornitore')
    );
    foreach (array_slice($supplierFooter, 0, 8) as $index => $text) {
        $stream .= invoicePdfText($text, 48, 132 - ($index * 9), $index === 0 ? 'F2' : 'F4', 8, $orange);
    }
    $stream .= invoicePdfText('Professione esercitata ai sensi della Legge 14 gennaio 2013, n. 4', 48, 90, 'F4', 8, $orange);
    $bankName = trim((string)($data['fornitore_banca'] ?? ''));
    $bankBic = trim((string)($data['fornitore_swift'] ?? ''));
    $bankIban = trim((string)($data['fornitore_iban'] ?? ''));
    if ($bankName !== '') {
        $stream .= invoicePdfText($bankName, 330, 132, 'F4', 9, $orange);
    }
    if ($bankBic !== '') {
        $stream .= invoicePdfText('SWIFT/BIC: ' . $bankBic, 330, 118, 'F4', 9, $orange);
    }
    if ($bankIban !== '') {
        $stream .= invoicePdfText('Numero del conto corrente: ' . $bankIban, 330, 104, 'F4', 9, $orange);
    }
    if ($courtesyCopy) {
        $stream .= invoicePdfText('COPIA DI CORTESIA · Non sostituisce il documento fiscale elettronico.', 48, 57, 'F4', 8, $orange);
    }

    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Italic /Encoding /WinAnsiEncoding >>',
    ];
    if ($logo !== null) {
        $logoObjectId = count($objects) + 1;
        $maskObjectId = $logoObjectId + 1;
        $contentObjectId = $maskObjectId + 1;
        $objects[2] =         '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R /F4 7 0 R >> /XObject << /Logo ' . $logoObjectId . ' 0 R >> >> /Contents ' . $contentObjectId . ' 0 R >>';
        $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . $logo['width'] . ' /Height ' . $logo['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /' . $logo['image_filter'] . ' /SMask ' . $maskObjectId . ' 0 R /Length ' . strlen($logo['image_bytes']) . " >>\nstream\n" . $logo['image_bytes'] . "\nendstream";
        $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . $logo['width'] . ' /Height ' . $logo['height'] . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen($logo['mask_bytes']) . " >>\nstream\n" . $logo['mask_bytes'] . "\nendstream";
    } else {
        $contentObjectId = count($objects) + 1;
        $objects[2] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R /F4 7 0 R >> >> /Contents ' . $contentObjectId . ' 0 R >>';
    }
    $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n$object\nendobj\n";
    }
    $xref = strlen($pdf);
    $objectCount = count($objects);
    $pdf .= "xref\n0 " . ($objectCount + 1) . "\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer\n<< /Size " . ($objectCount + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}
