<?php
require_once __DIR__ . '/fattura-cortesia-renderer.php';
require_once __DIR__ . '/voucher-model.php';

function voucherTimesItalicWidth(string $encoded, int $size): float
{
    static $widths = null;
    if ($widths === null) {
        // Standard PDF Times-Italic advance widths, indexed by WinAnsi byte minus 32.
        $widths = array_map('intval', explode(' ',
            '250 333 420 500 500 833 778 333 333 333 500 675 250 675 250 278 ' .
            '500 500 500 500 500 500 500 500 500 500 333 333 675 675 675 500 ' .
            '920 611 611 667 722 611 611 722 722 333 444 667 556 833 667 722 ' .
            '611 722 611 500 556 722 611 833 611 556 556 389 278 389 422 500 ' .
            '333 500 500 444 500 444 278 500 500 278 278 444 278 722 500 500 ' .
            '500 500 389 389 278 500 444 667 444 444 389 400 275 400 541 0 ' .
            '500 0 333 500 556 889 500 500 333 1000 500 333 944 0 556 0 ' .
            '0 333 333 556 556 350 500 1000 333 980 389 333 667 0 389 556 ' .
            '250 389 500 500 500 500 275 500 333 760 276 500 675 333 760 333 ' .
            '400 675 300 300 333 500 523 250 333 300 310 500 750 750 750 500 ' .
            '611 611 611 611 611 611 889 667 611 611 611 611 333 333 333 333 ' .
            '722 667 722 722 722 722 722 675 722 722 722 722 722 556 611 500 ' .
            '500 500 500 500 500 500 667 444 444 444 444 444 278 278 278 278 ' .
            '500 500 500 500 500 500 500 675 500 500 500 500 500 444 500 444'
        ));
    }
    $units = 0;
    foreach (str_split($encoded) as $character) {
        $width = $widths[ord($character) - 32] ?? 0;
        if ($width === 0) {
            throw new RuntimeException('Il testo del voucher contiene un carattere non supportato nel PDF.');
        }
        $units += $width;
    }
    return $units * $size / 1000;
}

function voucherHelveticaWidth(string $text, int $size): float
{
    // Standard PDF Helvetica advance widths for the ASCII booking instructions.
    static $widths = [
        278, 278, 355, 556, 556, 889, 667, 222, 333, 333, 389, 584, 278, 584, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        222, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];
    $units = 0;
    foreach (str_split($text) as $character) {
        $width = $widths[ord($character) - 32] ?? null;
        if ($width === null) {
            throw new RuntimeException('Il testo delle istruzioni contiene un carattere non supportato nel PDF.');
        }
        $units += $width;
    }
    return $units * $size / 1000;
}

function voucherWrapInstructions(string $text, int $size, float $maxWidth): array
{
    $lines = [];
    $line = '';
    foreach (explode(' ', $text) as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        if (voucherHelveticaWidth($candidate, $size) <= $maxWidth) {
            $line = $candidate;
            continue;
        }
        if ($line !== '') {
            $lines[] = $line;
            $line = '';
        }
        foreach (str_split($word) as $character) {
            if (voucherHelveticaWidth($line . $character, $size) > $maxWidth) {
                if ($line === '') {
                    throw new RuntimeException('La larghezza disponibile per le istruzioni del voucher è insufficiente.');
                }
                $lines[] = $line;
                $line = '';
            }
            $line .= $character;
        }
    }
    if ($line !== '') $lines[] = $line;
    return $lines;
}

function voucherPdf(array $voucher, bool $preview): string
{
    $logo = invoicePdfLoadLogo();
    if ($logo === null) {
        throw new RuntimeException('Impossibile caricare il logo del voucher.');
    }
    $street = trim((string)($voucher['studio_address'] ?? ''));
    $city = trim((string)($voucher['studio_city'] ?? ''));
    $postalCode = trim((string)($voucher['studio_postal_code'] ?? ''));
    $address = implode(', ', array_filter([$street, trim($postalCode . ' ' . $city)], static fn($part) => $part !== ''));
    if ($street === '' || $city === '' || $postalCode === '') {
        $address = ($address !== '' ? $address . ' (dati incompleti)' : 'Non disponibile') . ': contatta lo studio.';
    }
    $lines = [
        ['Voucher regalo', 25],
        [$preview ? 'ANTEPRIMA - NON VALIDO / NON PAGATO' : 'Codice: ' . $voucher['voucher_code'], 13],
        [voucherGreeting($voucher), 12],
        ['Siamo certi che per lei sarà un’esperienza unica! Faremo del nostro meglio perché lo sia.', 12],
        ['Durata della seduta: ' . $voucher['duration_minutes'] . ' minuti', 12],
        ['Formula: ' . $voucher['sessions_per_item'] . ' sedute x ' . $voucher['quantity'], 12],
        ['Totale sedute regalate: ' . $voucher['total_sessions'], 15],
        ['Sede: ' . voucherStudioName($voucher['studio_name']), 12],
        ['Indirizzo: ' . $address, 11],
        [$preview ? 'Validita: 12 mesi dalla conferma del pagamento' :
            'Valido fino al: ' . (new DateTimeImmutable($voucher['valid_until']))->format('d/m/Y H:i'), 12],
        ['COME PRENOTARE', 14],
        ['1. Vai su www.thaitikmassage.it e scegli Prenota un massaggio.', 11],
        ['2. Seleziona la sede, il trattamento e la durata indicati su questo voucher.', 11],
        ['3. Scegli "Prenota con voucher regalo", inserisci il codice e seleziona data e orario.', 11],
        ['4. Verifica il codice in fondo alla pagina e invia la richiesta di appuntamento.', 11],
        ['Email facoltativa: inseriscila per ricevere la conferma e gestire meglio la prenotazione.', 11],
        ['Per annullare online: www.thaitikmassage.it/annulla-voucher.php entro 3 ore prima.', 11],
        ['Informazioni: info@thaitikmassage.it', 11],
    ];
    if (($voucher['source'] ?? 'online') !== 'manual') {
        array_splice($lines, 7, 0, [['Importo totale: ' . number_format((float)$voucher['amount'], 2, ',', '.') . ' EUR', 15]]);
    }
    if ($voucher['studio_name'] === 'APS Studio Montesacro') {
        $lines[] = ['Chi non e gia socio dovra iscriversi all APS al momento del primo appuntamento. Iscrizione ed eventuale quota sono gestite direttamente dall associazione.', 10];
    }
    $stream = "0.99 0.98 0.96 rg 0 0 595 842 re f\n"
        . "0.72 0.59 0.35 RG 0.9 w 27 22 541 798 re S\n"
        . "0.84 0.77 0.62 RG 0.35 w 33 28 529 786 re S\n"
        . "0.72 0.59 0.35 RG 0.5 w 55 760 m 185 760 l S 410 760 m 540 760 l S\n"
        . "q 105 0 0 91.36 245 716 cm /Logo Do Q\n";
    $y = 697;
    $bookingInstructions = false;
    foreach ($lines as $index => [$text, $size]) {
        $isHeading = $text === 'Voucher regalo' || $text === 'COME PRENOTARE';
        $isGreeting = $index === 2 || $index === 3;
        if ($index === 2) $y -= 10;
        if (str_starts_with($text, 'Durata della seduta:')) $y -= 12;
        if ($text === 'COME PRENOTARE') {
            $y -= 18;
            $bookingInstructions = true;
        }
        $font = $text === 'Voucher regalo' ? 'F2' : ($isHeading ? 'F3' : ($isGreeting ? 'F4' : 'F1'));
        $color = $isHeading ? '0.50 0.27 0.22' : '0.19 0.14 0.12';
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($encoded === false) {
            throw new RuntimeException('Impossibile generare il documento del voucher.');
        }
        $encoded = str_replace(["\r", "\n"], ['', ' '], $encoded);
        $wrappedLines = $bookingInstructions && !$isHeading
            ? voucherWrapInstructions($encoded, $size, 480)
            : explode("\n", wordwrap($encoded, (int)floor(480 / ($size * .65)), "\n", true));
        foreach ($wrappedLines as $line) {
            if ($y < 45) {
                throw new RuntimeException('Il contenuto del voucher supera lo spazio disponibile nel PDF.');
            }
            if ($isGreeting) {
                $x = 297.5 - voucherTimesItalicWidth($line, $size) / 2;
                $line = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
                $stream .= sprintf("0.19 0.14 0.12 rg BT /%s %d Tf %.3F %d Td (%s) Tj ET\n", $font, $size, $x, $y, $line);
            } else {
                $line = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
                $stream .= "$color rg BT /$font $size Tf 55 $y Td ($line) Tj ET\n";
            }
            $y -= $size + 4;
        }
        $y -= 4;
    }
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 8 0 R /F3 9 0 R /F4 10 0 R >> /XObject << /Logo 6 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream",
        '<< /Type /XObject /Subtype /Image /Width ' . $logo['width'] . ' /Height ' . $logo['height']
            . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /' . $logo['image_filter']
            . ' /SMask 7 0 R /Length ' . strlen($logo['image_bytes']) . " >>\nstream\n" . $logo['image_bytes'] . "\nendstream",
        '<< /Type /XObject /Subtype /Image /Width ' . $logo['width'] . ' /Height ' . $logo['height']
            . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen($logo['mask_bytes'])
            . " >>\nstream\n" . $logo['mask_bytes'] . "\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Times-Italic /Encoding /WinAnsiEncoding >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n$object\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}
