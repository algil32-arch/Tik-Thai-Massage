function giftVoucherQuote(price, sessionsPerItem, quantity) {
  const cents = Math.round(Number(price) * 100);
  if (!Number.isSafeInteger(cents) || cents < 1 || ![1, 5, 10].includes(sessionsPerItem)
      || !Number.isInteger(quantity) || quantity < 1 || quantity > 20) {
    throw new Error('Seleziona un trattamento, una formula e una quantità da 1 a 20.');
  }
  const unitCents = sessionsPerItem === 1 ? cents
    : Math.ceil(cents * (sessionsPerItem === 5 ? 95 : 90) / 10000) * 100;
  return { itemCents: unitCents * sessionsPerItem, totalCents: unitCents * sessionsPerItem * quantity, totalSessions: sessionsPerItem * quantity };
}

function initGiftVoucher({ studios, services, preview, loadSumUpSdk }) {
  const form = document.getElementById('giftVoucherForm');
  const status = document.getElementById('giftStatus');
  const selection = document.getElementById('giftVoucherSelection');
  const select = document.getElementById('giftService');
  const pay = document.getElementById('giftPay');
  const verify = document.getElementById('giftVerify');
  const paymentActions = document.getElementById('giftPaymentActions');
  const newButton = document.getElementById('giftNew');
  const panel = document.getElementById('giftPreview');
  const buyer = document.getElementById('giftBuyerFields');
  const storageKey = 'thaitik-gift-payment';
  const money = cents => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(cents / 100);
  const prati = studios.find(studio => studio.name === 'Studio Prati');
  const catalog = services.filter(service => prati && Number(service.id_studio) === Number(prati.id));
  let token = null;
  let paidVoucher = null;
  let pending = false;
  let paymentTimer = null;
  let recoveryOnly = false;

  if (buyer.childElementCount === 0) {
    for (const id of ['name', 'email', 'phone', 'billingFields']) {
      const source = document.getElementById(id);
      if (!source) continue;
      const clone = (id === 'billingFields' ? source : source.closest('.field')).cloneNode(true);
      clone.querySelectorAll('[id]').forEach(element => { element.id = `gift-${element.id}`; });
      if (clone.id) clone.id = `gift-${clone.id}`;
      clone.querySelectorAll('label[for]').forEach(label => { label.htmlFor = `gift-${label.htmlFor}`; });
      clone.querySelectorAll('input').forEach(input => { input.value = ''; input.disabled = false; });
      buyer.appendChild(clone);
    }
  }
  function updateBuyer() {
    const company = document.getElementById('gift-customerType').value === 'azienda';
    for (const id of ['companyNameField', 'vatField', 'recipientField', 'pecField', 'billingHelp']) {
      document.getElementById(`gift-${id}`).hidden = !company;
    }
    document.getElementById('gift-taxCodeField').hidden = company;
    for (const id of ['companyName', 'vatNumber']) document.getElementById(`gift-${id}`).required = company;
    document.getElementById('gift-taxCode').required = !company;
    for (const id of ['name', 'email', 'billingAddress', 'billingCity', 'billingPostalCode']) {
      document.getElementById(`gift-${id}`).required = true;
    }
  }
  document.getElementById('gift-customerType').addEventListener('change', updateBuyer);
  updateBuyer();

  select.replaceChildren(new Option('Seleziona il trattamento', ''));
  for (const service of catalog) {
    select.add(new Option(`${service.name} · ${service.durationMinutes} min · ${money(Math.round(service.price * 100))}`, String(service.id)));
  }
  select.disabled = catalog.length === 0;
  pay.disabled = preview || catalog.length === 0;
  status.textContent = preview
    ? 'Anteprima locale: puoi provare il form, stampare e scaricare l’anteprima. Pagamento disabilitato.'
    : catalog.length ? 'Il voucher sarà valido solo dopo la conferma del pagamento SumUp.'
      : 'Catalogo voucher non disponibile. Contatta lo studio.';

  function readGift() {
    const service = catalog.find(item => Number(item.id) === Number(select.value));
    if (!service) throw new Error('Seleziona un trattamento.');
    const recipient = document.getElementById('giftRecipient').value.trim();
    if (!recipient) throw new Error('Inserisci il nome del destinatario.');
    const sessions = Number(document.getElementById('giftFormula').value);
    const quantity = Number(document.getElementById('giftQuantity').value);
    const quote = giftVoucherQuote(service.price, sessions, quantity);
    return { recipient_name: recipient, service_id: service.id, service_name: service.name,
      price: Number(service.price).toFixed(2), duration_minutes: service.durationMinutes,
      sessions_per_item: sessions, quantity, total_sessions: quote.totalSessions, amount: (quote.totalCents / 100).toFixed(2) };
  }
  function updateQuote() {
    try {
      const service = catalog.find(item => Number(item.id) === Number(select.value));
      const quote = giftVoucherQuote(service?.price, Number(document.getElementById('giftFormula').value), Number(document.getElementById('giftQuantity').value));
      document.getElementById('giftItemAmount').textContent = money(quote.itemCents);
      document.getElementById('giftSessions').textContent = quote.totalSessions;
      document.getElementById('giftTotal').textContent = money(quote.totalCents);
    } catch (error) {
      for (const id of ['giftItemAmount', 'giftSessions', 'giftTotal']) document.getElementById(id).textContent = '—';
      if (select.value) status.textContent = error.message;
    }
  }
  selection.addEventListener('input', updateQuote);
  selection.addEventListener('change', updateQuote);
  function showPreview(voucher, paid) {
    panel.replaceChildren();
    const logo = document.createElement('img');
    logo.src = 'thai-tik-logo-alternativo.png';
    logo.alt = 'Thai Tik Massage';
    panel.appendChild(logo);
    const heading = document.createElement('h3');
    heading.textContent = 'Voucher regalo';
    panel.appendChild(heading);
    const lines = [paid ? `Codice: ${voucher.voucher_code}` : 'ANTEPRIMA — NON VALIDO / NON PAGATO',
      `Per: ${voucher.recipient_name}`, `${voucher.service_name} · ${voucher.duration_minutes} minuti`,
      `Formula: ${voucher.sessions_per_item} sedute × ${voucher.quantity}`,
      `Totale sedute regalate: ${voucher.total_sessions}`, `Importo totale: ${money(Math.round(Number(voucher.amount) * 100))}`,
      'Sede: Studio Prati',
      paid ? `Valido fino al: ${new Date(voucher.valid_until.replace(' ', 'T')).toLocaleDateString('it-IT')}` : 'Validità: 12 mesi dalla conferma del pagamento',
      'Prenota sul sito scegliendo "Prenota con voucher regalo" e inserisci il codice.', 'info@thaitikmassage.it · www.thaitikmassage.it'];
    lines.forEach(text => { const line = document.createElement('p'); line.textContent = text; panel.appendChild(line); });
    panel.hidden = false;
  }
  function currentVoucher() {
    if (paidVoucher) return paidVoucher;
    if (token) throw new Error('Prima di stampare verifica il pagamento del voucher in corso.');
    return readGift();
  }
  document.getElementById('giftPrint').addEventListener('click', () => {
    try {
      showPreview(currentVoucher(), Boolean(paidVoucher));
      document.body.classList.add('gift-voucher-print');
      window.print();
    } catch (error) { status.textContent = error.message; }
  });
  window.addEventListener('afterprint', () => document.body.classList.remove('gift-voucher-print'));
  document.getElementById('giftDownload').addEventListener('click', async () => {
    const button = document.getElementById('giftDownload');
    button.disabled = true;
    try {
      const voucher = currentVoucher();
      const response = paidVoucher
        ? await fetch(`voucher.php?action=pdf&token=${encodeURIComponent(token)}`, { cache: 'no-store' })
        : await fetch('voucher.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'preview', ...voucher }) });
      if (!response.ok || !response.headers.get('content-type')?.startsWith('application/pdf')) {
        const data = await response.json();
        throw new Error(data.message || 'Download non disponibile.');
      }
      const url = URL.createObjectURL(await response.blob());
      const link = document.createElement('a');
      link.href = url;
      link.download = paidVoucher ? `${voucher.voucher_code}.pdf` : 'anteprima-voucher.pdf';
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 10000);
    } catch (error) { status.textContent = error.message || 'Download non riuscito.'; }
    finally { button.disabled = false; }
  });
  function lockForm(locked) {
    selection.disabled = locked;
    buyer.querySelectorAll('input, select').forEach(control => { control.disabled = locked; });
    pay.disabled = locked || preview || !catalog.length;
  }
  async function api(input) {
    const response = await fetch('voucher.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input) });
    const data = await response.json();
    if (!response.ok || !data.success) {
      const error = new Error(data.message || 'Operazione voucher non riuscita.');
      error.code = data.code;
      throw error;
    }
    return data;
  }
  async function verifyPayment() {
    if (!token || pending) return;
    pending = true;
    verify.disabled = true;
    try {
      const result = await api({ action: 'verify', token });
      const voucher = result.voucher;
      if (voucher.status === 'paid') {
        clearInterval(paymentTimer);
        paidVoucher = voucher;
        showPreview(voucher, true);
        document.getElementById('gift-sumup-card').replaceChildren();
        status.textContent = `Pagamento confermato. Voucher ${voucher.voucher_code} valido per 12 mesi. Nessun appuntamento prenotato.`;
        if (result.notifications && (!result.notifications.customer_sent || !result.notifications.studio_sent)) {
          status.textContent += '\nUna o più notifiche email non sono state inviate: scarica il voucher e contatta lo studio.';
        }
        document.getElementById('giftPrint').textContent = 'Stampa voucher';
        document.getElementById('giftDownload').textContent = 'Scarica voucher PDF';
        newButton.hidden = false;
      } else if (['expired', 'failed'].includes(voucher.status)) {
        clearInterval(paymentTimer);
        document.getElementById('gift-sumup-card').replaceChildren();
        status.textContent = 'Pagamento scaduto o non riuscito. Nessun voucher valido emesso.';
        newButton.hidden = false;
      } else {
        status.textContent = 'Pagamento non ancora confermato. Completa il checkout o verifica nuovamente; non effettuare un secondo acquisto.';
        if (recoveryOnly) {
          await loadSumUpSdk();
          window.SumUpCard.mount({
            id: 'gift-sumup-card', checkoutId: result.checkout_id, locale: 'it-IT',
            onResponse: type => { if (type === 'success') verifyPayment(); }
          });
          recoveryOnly = false;
          paymentTimer = setInterval(verifyPayment, 15000);
        }
      }
    } catch (error) {
      status.textContent = `${error.message}\nAcquisto in verifica: non effettuare un secondo pagamento.`;
    } finally { pending = false; verify.disabled = false; }
  }
  verify.addEventListener('click', verifyPayment);
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (pending || preview || token) return;
    try {
      const gift = readGift();
      document.getElementById('giftBuyerDetails').open = true;
      if (!form.reportValidity()) return;
      const payload = Object.fromEntries(new FormData(form));
      if (payload.customer_type === 'azienda' && !payload.codice_destinatario.trim() && !payload.pec.trim()) {
        throw new Error('Inserisci il codice destinatario o la PEC dell’acquirente.');
      }
      token = [...crypto.getRandomValues(new Uint8Array(32))].map(value => value.toString(16).padStart(2, '0')).join('');
      try { sessionStorage.setItem(storageKey, token); }
      catch (error) { token = null; throw new Error('Impossibile conservare il riferimento del pagamento in questa scheda. Abilita lo storage prima di acquistare.'); }
      pending = true;
      lockForm(true);
      paymentActions.hidden = false;
      status.textContent = 'Creazione del checkout SumUp…';
      const data = await api({ ...payload, ...gift, action: 'create', token });
      showPreview(data.voucher, false);
      recoveryOnly = true;
      await loadSumUpSdk();
      window.SumUpCard.mount({
        id: 'gift-sumup-card', checkoutId: data.checkout_id, locale: 'it-IT',
        onResponse: type => {
          if (type === 'success') verifyPayment();
          else if (type === 'error' || type === 'fail') status.textContent = 'Pagamento non completato. Riprova nel checkout o verifica il pagamento.';
        }
      });
      recoveryOnly = false;
      status.textContent = `Completa il pagamento di ${money(Math.round(Number(data.voucher.amount) * 100))}. Il checkout dura 15 minuti; nessun appuntamento viene prenotato.`;
      paymentTimer = setInterval(verifyPayment, 15000);
    } catch (error) {
      if (error.code === 'VALIDATION_ERROR' && token) {
        try {
          sessionStorage.removeItem(storageKey);
          token = null;
          lockForm(false);
          paymentActions.hidden = true;
        } catch (storageError) {
          status.textContent = 'Impossibile cancellare il riferimento del pagamento. Contatta lo studio prima di riprovare.';
          return;
        }
      }
      status.textContent = `${error.message || 'Acquisto non completato.'}${token ? '\nConserva questa scheda e verifica il pagamento prima di riprovare. In caso di errore contatta lo studio.' : ''}`;
    } finally { pending = false; }
  });
  newButton.addEventListener('click', () => {
    try { sessionStorage.removeItem(storageKey); }
    catch (error) { status.textContent = 'Impossibile cancellare il riferimento del vecchio acquisto. Riprova o usa una nuova scheda.'; return; }
    clearInterval(paymentTimer);
    token = null;
    paidVoucher = null;
    recoveryOnly = false;
    paymentActions.hidden = true;
    newButton.hidden = true;
    panel.hidden = true;
    lockForm(false);
    document.getElementById('giftPrint').textContent = 'Stampa anteprima';
    document.getElementById('giftDownload').textContent = 'Scarica anteprima PDF';
    status.textContent = 'Puoi creare un nuovo voucher.';
  });
  try { token = sessionStorage.getItem(storageKey); }
  catch (error) { status.textContent += '\nStorage non disponibile: l’acquisto è disabilitato finché non viene abilitato.'; pay.disabled = true; }
  if (token && !preview) {
    recoveryOnly = true;
    lockForm(true);
    paymentActions.hidden = false;
    status.textContent = 'È presente un acquisto in corso. Verifica per recuperare il voucher, senza effettuare un nuovo pagamento.';
    verifyPayment();
  }
}

if (typeof module !== 'undefined') module.exports = { giftVoucherQuote };
