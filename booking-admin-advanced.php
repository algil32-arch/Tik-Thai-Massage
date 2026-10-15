<?php
require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/booking-voucher.php';

try {
  $pdo = dbConnection();
} catch (Throwable $e) {
  http_response_code(503);
  die('Database non raggiungibile. Verifica la configurazione privata e i dati Aruba.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['admin_csrf'], $_POST['csrf_token'])) {
        http_response_code(400);
        die('Sessione scaduta. Ricarica la pagina e riprova.');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_invoice') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            http_response_code(400);
            die('Identificativo appuntamento non valido.');
        }
        ?>
        <!doctype html>
        <html lang="it">
          <head><meta charset="UTF-8"><title>Apertura fatturazione</title></head>
          <body>
            <form id="invoice-forward" method="post" action="fattura-da-pagamento.php">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="create">
              <input type="hidden" name="appointment_id" value="<?= (int)$id ?>">
              <button type="submit">Continua alla creazione fattura</button>
            </form>
            <script>document.getElementById('invoice-forward').submit();</script>
          </body>
        </html>
        <?php
        exit;
    }

    if ($action === 'confirm') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE appuntamenti SET stato = 'confermato' WHERE id = :id AND stato = 'in_attesa'");
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() !== 1) {
            http_response_code(400);
            die('Appuntamento non in attesa: non è possibile confermare una prenotazione annullata. Crea una nuova richiesta.');
        }
        header('Location: booking-admin-advanced.php');
        exit;
    }

    if ($action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            bookingVoucherCancel($pdo, $id);
        } catch (Throwable $e) {
            error_log('Booking cancellation failed: ' . $e->getMessage());
            http_response_code(503);
            die('Annullamento non completato. Verifica il registro voucher e riprova; nessuna modifica salvata.');
        }
        header('Location: booking-admin-advanced.php');
        exit;
    }

    if ($action === 'delete') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            header('Location: booking-admin-advanced.php?delete=error&msg=' . rawurlencode('Identificativo appuntamento non valido.'));
            exit;
        }

        try {
            $pdo->beginTransaction();
            $appointmentStmt = $pdo->prepare(
                'SELECT id, metodo_pagamento, stato_pagamento
                 FROM appuntamenti
                 WHERE id = :id
                 FOR UPDATE'
            );
            $appointmentStmt->execute([':id' => $id]);
            $appointment = $appointmentStmt->fetch();
            if (!$appointment) {
                throw new RuntimeException('Appuntamento non trovato.');
            }
            if ($appointment['stato_pagamento'] === 'pagato') {
                throw new RuntimeException('Un appuntamento pagato non può essere eliminato.');
            }

            $checks = [
                ['SELECT COUNT(*) FROM pagamenti WHERE id_appuntamento = :id', 'L’appuntamento è collegato a uno o più pagamenti.'],
                ['SELECT COUNT(*) FROM sumup_payment_intents WHERE appointment_id = :id', 'L’appuntamento è collegato a una richiesta SumUp; conserva la traccia di pagamento.'],
                ['SELECT COUNT(*) FROM gift_voucher_bookings WHERE appointment_id = :id', 'L’appuntamento è collegato a un voucher; annullalo per ripristinare la seduta invece di eliminarlo.'],
            ];
            foreach ($checks as [$sql, $message]) {
                $check = $pdo->prepare($sql);
                $check->execute([':id' => $id]);
                if ((int)$check->fetchColumn() > 0) {
                    throw new RuntimeException($message);
                }
            }

            $invoiceNumberCheck = $pdo->prepare(
                'SELECT COUNT(*) FROM fatture
                 WHERE numero_fattura LIKE :suffix OR note LIKE :reference'
            );
            $invoiceNumberCheck->execute([
                ':suffix' => '%-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT),
                ':reference' => '%[appuntamento:' . $id . ']%',
            ]);
            if ((int)$invoiceNumberCheck->fetchColumn() > 0) {
                throw new RuntimeException('Esiste una fattura che potrebbe riferirsi a questo appuntamento: elimina prima la fattura se non è pagata.');
            }

            $pdo->prepare('DELETE FROM appuntamenti WHERE id = :id')->execute([':id' => $id]);
            $pdo->commit();
            header('Location: booking-admin-advanced.php?delete=ok&msg=' . rawurlencode('Appuntamento eliminato dal database.'));
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = $exception instanceof RuntimeException && !($exception instanceof PDOException)
                ? $exception->getMessage()
                : 'Eliminazione non completata. Nessuna modifica salvata.';
            if (!$exception instanceof RuntimeException || $exception instanceof PDOException) {
                error_log('Appointment deletion failed: ' . $exception->getMessage());
            }
            header('Location: booking-admin-advanced.php?delete=error&msg=' . rawurlencode($message));
            exit;
        }
    }

}

$stmt = $pdo->query(
        "SELECT a.id, a.data_appuntamento, a.ora_inizio, a.stato, a.metodo_pagamento, a.stato_pagamento,
          s.nome AS servizio, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.email AS cliente_email, c.telefono AS cliente_telefono,
          c.tipo_cliente, c.ragione_sociale, c.codice_fiscale, c.partita_iva, c.codice_destinatario, c.pec,
          c.indirizzo AS cliente_indirizzo, c.citta AS cliente_citta, c.cap AS cliente_cap,
          st.nome AS studio_nome, serv.prezzo AS prezzo_servizio
     FROM appuntamenti a
     LEFT JOIN servizi serv ON serv.id = a.id_servizio
     LEFT JOIN clienti c ON c.id = a.id_cliente
     LEFT JOIN studi st ON st.id = a.id_studio
     LEFT JOIN servizi s ON s.id = a.id_servizio
     ORDER BY a.data_appuntamento DESC, a.ora_inizio DESC"
);
$appointments = $stmt->fetchAll();
$deleteMessage = isset($_GET['delete']) ? trim((string)($_GET['msg'] ?? '')) : '';
$deleteMessageType = (string)($_GET['delete'] ?? '') === 'ok' ? 'success' : 'error';
?>
<!doctype html>
<html lang="it">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin prenotazioni avanzato</title>
    <style>
      body {
        margin: 0;
        background: #f7f1ea;
        font-family: Arial, sans-serif;
        color: #241d1a;
      }
      .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 32px 20px;
      }
      h1 {
        margin-bottom: 20px;
      }
      table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        box-shadow: 0 8px 18px rgba(0,0,0,0.05);
      }
      th, td {
        border: 1px solid #e5d9cd;
        padding: 12px 10px;
        text-align: left;
        vertical-align: top;
      }
      th {
        background: #2a201d;
        color: #fff;
      }
      .status {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 999px;
        background: #f0e6dd;
        color: #5f4237;
        font-size: 12px;
        font-weight: bold;
      }
      .actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
      }
      .btn {
        display: inline-block;
        min-height: 36px;
        padding: 8px 12px;
        border: 0;
        cursor: pointer;
        color: white;
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
      }
      .confirm { background: #2a201d; }
      .cancel { background: #8b4438; }
      .invoice { background: #4a5b3d; }
      .empty {
        padding: 20px;
        border: 1px solid #e5d9cd;
        background: white;
      }
      .overview {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 22px;
      }
      .chip {
        padding: 10px 14px;
        background: #fff;
        border: 1px solid #e5d9cd;
        font-size: 13px;
        color: #3b2d2a;
      }
      .link-row {
        display: flex;
        justify-content: flex-end;
        margin-bottom: 18px;
      }
      .link-row a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 40px;
        padding: 8px 14px;
        background: #2a201d;
        color: #fff;
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
      }
      .link-row { flex-wrap: wrap; gap: 10px; }
      .message { padding: 12px 14px; margin: 0 0 18px; border: 1px solid #e5d9cd; background: #fff; }
      .message.success { color: #2f5d3f; }
      .message.error { color: #8b4438; }
    </style>
  </head>
  <body>
    <div class="container">
      <h1>Admin prenotazioni</h1>
      <div class="link-row">
        <a href="fattura-manuale.php">Nuova fattura manuale</a>
        <a href="fatture-admin.php">Gestione fatture</a>
      </div>
      <div class="overview">
        <div class="chip">Area gestione appuntamenti</div>
        <div class="chip">Conferma, annulla e fattura</div>
      </div>
      <?php if ($deleteMessage !== ''): ?>
        <p class="message <?= htmlspecialchars($deleteMessageType) ?>" role="status"><?= htmlspecialchars($deleteMessage) ?></p>
      <?php endif; ?>
      <?php if (empty($appointments)): ?>
        <div class="empty">Nessuna prenotazione trovata.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Studio</th>
              <th>Cliente</th>
              <th>Servizio</th>
              <th>Prezzo</th>
              <th>Data</th>
              <th>Ora</th>
              <th>Pagamento</th>
              <th>Dati fiscali</th>
              <th>Stato</th>
              <th>Azioni</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($appointments as $booking): ?>
              <tr>
                <td><?= htmlspecialchars((string)$booking['id']) ?></td>
                <td><?= htmlspecialchars((string)$booking['studio_nome']) ?></td>
                <td>
                  <?= htmlspecialchars((string)$booking['cliente_nome'] . ' ' . ($booking['cliente_cognome'] ?? '')) ?><br>
                  <small><?= htmlspecialchars((string)$booking['cliente_email']) ?></small>
                </td>
                <td><?= htmlspecialchars((string)$booking['servizio']) ?></td>
                <td><?= htmlspecialchars((string)$booking['prezzo_servizio']) ?> €</td>
                <td><?= htmlspecialchars((string)$booking['data_appuntamento']) ?></td>
                <td><?= htmlspecialchars((string)$booking['ora_inizio']) ?></td>
                <td><?= htmlspecialchars((string)$booking['metodo_pagamento']) ?><br><small><?= htmlspecialchars((string)$booking['stato_pagamento']) ?></small></td>
                <td>
                  <?php if (($booking['tipo_cliente'] ?? '') === 'azienda'): ?>
                    <?= htmlspecialchars((string)$booking['ragione_sociale']) ?><br>
                    <small>P.IVA <?= htmlspecialchars((string)$booking['partita_iva']) ?></small>
                    <small><?= htmlspecialchars((string)($booking['codice_destinatario'] ?: $booking['pec'])) ?></small>
                  <?php else: ?>
                    <small>CF <?= htmlspecialchars((string)$booking['codice_fiscale']) ?></small>
                  <?php endif; ?><br>
                  <small><?= htmlspecialchars(trim((string)$booking['cliente_indirizzo'] . ', ' . (string)$booking['cliente_cap'] . ' ' . (string)$booking['cliente_citta'])) ?></small>
                  <?php if (!empty($booking['cliente_telefono'])): ?><br><small>Tel. <?= htmlspecialchars((string)$booking['cliente_telefono']) ?></small><?php endif; ?>
                </td>
                <td><span class="status"><?= htmlspecialchars((string)$booking['stato']) ?></span></td>
                <td>
                  <div class="actions">
                    <form method="post" action="booking-admin-advanced.php">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8') ?>" />
                      <input type="hidden" name="action" value="confirm" />
                      <input type="hidden" name="id" value="<?= (int)$booking['id'] ?>" />
                      <button class="btn confirm" type="submit">Conferma</button>
                    </form>
                    <form method="post" action="booking-admin-advanced.php">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8') ?>" />
                      <input type="hidden" name="action" value="cancel" />
                      <input type="hidden" name="id" value="<?= (int)$booking['id'] ?>" />
                      <button class="btn cancel" type="submit">Annulla</button>
                    </form>
                    <?php if (($booking['stato'] ?? '') === 'confermato' && $booking['metodo_pagamento'] !== 'voucher'): ?>
                      <form method="post" action="fattura-da-pagamento.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8') ?>" />
                        <input type="hidden" name="action" value="create" />
                        <input type="hidden" name="appointment_id" value="<?= (int)$booking['id'] ?>" />
                        <button class="btn invoice" type="submit">Genera fattura</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" action="booking-admin-advanced.php" onsubmit="return confirm('Eliminare definitivamente dal database questo appuntamento? L’azione è consentita solo se non è collegato a pagamenti, fatture, SumUp o voucher.');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8') ?>" />
                      <input type="hidden" name="action" value="delete" />
                      <input type="hidden" name="id" value="<?= (int)$booking['id'] ?>" />
                      <button class="btn cancel" type="submit">Elimina</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </body>
</html>
