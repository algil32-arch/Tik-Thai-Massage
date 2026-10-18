<?php

function voucherPdf(array $voucher, bool $preview): string
{
    $lines = [
        ['THAI TIK MASSAGE', 23],
        ['Voucher regalo', 28],
        [$preview ? 'ANTEPRIMA - NON VALIDO / NON PAGATO' : 'Codice: ' . $voucher['voucher_code'], 13],
        ['Per: ' . $voucher['recipient_name'], 17],
        [$voucher['service_name'], 15],
        ['Durata della seduta: ' . $voucher['duration_minutes'] . ' minuti', 12],
        ['Formula: ' . $voucher['sessions_per_item'] . ' sedute x ' . $voucher['quantity'], 12],
        ['Totale sedute regalate: ' . $voucher['total_sessions'], 15],
        ['Importo totale: ' . number_format((float)$voucher['amount'], 2, ',', '.') . ' EUR', 15],
        ['Sede: ' . $voucher['studio_name'], 12],
        [$preview ? 'Validita: 12 mesi dalla conferma del pagamento' :
            'Valido fino al: ' . (new DateTimeImmutable($voucher['valid_until']))->format('d/m/Y H:i'), 12],
        ['Prenota sul sito con il codice: scegli "Prenota con voucher regalo".', 11],
        ['info@thaitikmassage.it | www.thaitikmassage.it', 11],
    ];
    if ($voucher['studio_name'] === 'APS Studio Montesacro') {
        $lines[] = ['Chi non e gia socio dovra iscriversi all APS al momento del primo appuntamento. Iscrizione ed eventuale quota sono gestite direttamente dall associazione.', 10];
    }
    $stream = "0.98 0.96 0.93 rg 0 0 595 842 re f\n0.55 0.27 0.22 RG 35 100 525 635 re S\n";
    $y = 680;
    foreach ($lines as [$text, $size]) {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($encoded === false) {
            throw new RuntimeException('Impossibile generare il documento del voucher.');
        }
        $encoded = str_replace(["\r", "\n"], ['', ' '], $encoded);
        foreach (explode("\n", wordwrap($encoded, (int)floor(480 / ($size * .65)), "\n", true)) as $line) {
            $line = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $stream .= "0.19 0.14 0.12 rg BT /F1 $size Tf 55 $y Td ($line) Tj ET\n";
            $y -= max(28, $size + 12);
        }
        $y -= 6;
    }
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n$object\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}
