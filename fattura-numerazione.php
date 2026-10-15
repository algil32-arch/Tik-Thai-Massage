<?php

function invoiceNumberLockName(string $issueDate): string
{
    $year = substr($issueDate, 0, 4);
    if (preg_match('/^\d{4}$/', $year) !== 1) {
        throw new InvalidArgumentException('Anno fattura non valido.');
    }
    return 'thaitik_invoice_number_' . $year;
}

function acquireInvoiceNumberLock(PDO $pdo, string $issueDate): string
{
    $lockName = invoiceNumberLockName($issueDate);
    $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $stmt->execute([':lock_name' => $lockName]);
    if ((int)$stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Impossibile riservare il progressivo fattura. Riprova.');
    }
    return $lockName;
}

function releaseInvoiceNumberLock(PDO $pdo, string $lockName): void
{
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
    $stmt->execute([':lock_name' => $lockName]);
}

function nextInvoiceNumber(PDO $pdo, string $issueDate): string
{
    $year = substr($issueDate, 0, 4);
    $stmt = $pdo->prepare('SELECT numero_fattura FROM fatture WHERE numero_fattura LIKE :prefix');
    $stmt->execute([':prefix' => 'TS-' . $year . '-%']);
    return nextInvoiceNumberFromExisting($stmt->fetchAll(PDO::FETCH_COLUMN), $issueDate);
}

function nextInvoiceNumberFromExisting(array $existingNumbers, string $issueDate): string
{
    $year = substr($issueDate, 0, 4);
    if (preg_match('/^\d{4}$/', $year) !== 1) {
        throw new InvalidArgumentException('Anno fattura non valido.');
    }
    $highest = 0;
    foreach ($existingNumbers as $number) {
        if (preg_match('/^TS-' . preg_quote($year, '/') . '-(\d{5})(?:$|-ANN-)/', (string)$number, $matches) === 1) {
            $highest = max($highest, (int)$matches[1]);
        }
    }

    do {
        if ($highest >= 99999) {
            throw new RuntimeException('Progressivo fattura annuale esaurito.');
        }
        $highest++;
        $candidate = sprintf('TS-%s-%05d', $year, $highest);
        $pathBase = __DIR__ . '/documents/fatture/';
        $fileExists = is_file($pathBase . 'pdf/' . $candidate . '.pdf')
            || is_file($pathBase . 'xml/' . $candidate . '.xml')
            || is_file($pathBase . 'copie/' . $candidate . '_copia_di_cortesia.pdf');
    } while ($fileExists);

    return $candidate;
}
