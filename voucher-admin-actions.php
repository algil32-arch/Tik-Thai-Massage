<?php

function voucherRemoveOrDisable(PDO $pdo, int $id): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM gift_vouchers WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $voucher = $stmt->fetch();
        if (!$voucher) throw new InvalidArgumentException('Voucher non trovato.');
        if ($voucher['status'] === 'pending') {
            throw new InvalidArgumentException('Verifica prima il pagamento SumUp: un checkout ancora in corso non può essere disattivato.');
        }
        $stmt = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM gift_voucher_uses WHERE voucher_id = :uses)
                  + (SELECT COUNT(*) FROM gift_voucher_bookings WHERE voucher_id = :bookings)'
        );
        $stmt->execute([':uses' => $id, ':bookings' => $id]);
        $hasHistory = (int)$stmt->fetchColumn() > 0;
        if ($voucher['source'] === 'manual' && !$hasHistory) {
            $stmt = $pdo->prepare("DELETE FROM gift_vouchers WHERE id = :id AND source = 'manual'");
            $stmt->execute([':id' => $id]);
            $message = 'Voucher manuale senza utilizzi eliminato definitivamente.';
        } else {
            $stmt = $pdo->prepare('UPDATE gift_vouchers SET disabled_at = COALESCE(disabled_at, NOW()) WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $message = 'Voucher disattivato: codice non più utilizzabile, storico conservato.';
        }
        $pdo->commit();
        return $message;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
