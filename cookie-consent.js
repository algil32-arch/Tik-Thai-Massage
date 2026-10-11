(() => {
  'use strict';

  const storageKey = 'thaitik-consent-v1';
  const lifetime = 180 * 24 * 60 * 60 * 1000;
  let preferences = { maps: false, translation: false };
  let hasChoice = false;
  let storageWarning = '';
  let returnFocus = null;

  function readChoice() {
    try {
      const raw = localStorage.getItem(storageKey);
      if (!raw) return null;
      const value = JSON.parse(raw);
      if (!value || value.version !== 1 || typeof value.maps !== 'boolean' ||
          typeof value.translation !== 'boolean' || !Number.isFinite(value.savedAt) ||
          value.savedAt > Date.now()) {
        console.warn('Preferenze cookie non valide: i servizi facoltativi restano disattivati.');
        return null;
      }
      if (Date.now() - value.savedAt >= lifetime) return null;
      return value;
    } catch (error) {
      console.warn('Impossibile leggere le preferenze cookie.', error);
      storageWarning = 'Il browser non permette di leggere le preferenze salvate. Puoi scegliere per questa pagina.';
      return null;
    }
  }

  const saved = readChoice();
  if (saved) {
    preferences = { maps: saved.maps, translation: saved.translation };
    hasChoice = true;
  }

  const banner = document.createElement('section');
  banner.className = 'consent-banner';
  banner.setAttribute('aria-label', 'Cookie e servizi esterni');
  banner.innerHTML = `
    <div class="consent-copy">
      <h2>La tua scelta, il tuo benessere</h2>
      <p>Puoi navigare e prenotare senza autorizzare servizi facoltativi.
      Google Maps e il traduttore GTranslate si caricano solo con il tuo consenso.
      <a href="documenti-legali-premium.html#cookie">Leggi la Cookie Policy</a>.</p>
    </div>
    <div class="consent-actions">
      <button type="button" data-consent-action="accept">Accetta tutti</button>
      <button type="button" data-consent-action="reject">Rifiuta i non necessari</button>
      <button type="button" data-consent-action="customize">Personalizza</button>
    </div>`;
  banner.hidden = hasChoice;

  const dialog = document.createElement('dialog');
  dialog.className = 'consent-dialog';
  dialog.setAttribute('aria-labelledby', 'consent-title');
  dialog.innerHTML = `
    <h2 id="consent-title">Preferenze cookie</h2>
    <p>Le funzioni essenziali e la prenotazione restano disponibili anche rifiutando.
    I servizi esterni autorizzati possono ricevere il tuo indirizzo IP e usare proprie tecnologie.</p>
    <p><strong>Funzioni necessarie: sempre disponibili.</strong>
    La scelta viene conservata sul tuo dispositivo per 180 giorni.</p>
    <label class="consent-option"><input type="checkbox" name="maps">
      <span><strong>Google Maps</strong>Mappe incorporate nelle pagine delle sedi.
      I link esterni alle mappe restano sempre disponibili.</span></label>
    <label class="consent-option"><input type="checkbox" name="translation">
      <span><strong>GTranslate</strong>Traduzione tramite un servizio esterno.
      Senza autorizzazione il sito rimane in italiano.</span></label>
    <p>Se disattivi una traduzione gi&agrave; autorizzata, la pagina verr&agrave; ricaricata
    per interrompere il servizio. Salva prima eventuali dati inseriti nei moduli.</p>
    <p><a href="documenti-legali-premium.html#cookie">Cookie Policy e informazioni sui fornitori</a></p>
    <div class="consent-actions">
      <button type="button" data-consent-action="save">Salva preferenze</button>
      <button type="button" data-consent-action="reject">Rifiuta i non necessari</button>
      <button type="button" data-consent-action="close">Chiudi senza modificare</button>
    </div>`;

  const notice = document.createElement('div');
  notice.className = 'consent-notice';
  notice.setAttribute('role', 'status');
  notice.hidden = true;
  document.body.append(banner, dialog, notice);

  function showNotice(message) {
    const text = document.createElement('span');
    text.textContent = message;
    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.textContent = 'Chiudi avviso';
    dismiss.addEventListener('click', () => { notice.hidden = true; });
    notice.replaceChildren(text, dismiss);
    notice.hidden = false;
  }

  function applyMaps() {
    document.querySelectorAll('iframe[data-consent-src]').forEach(frame => {
      const placeholder = frame.parentElement.querySelector('.map-consent-placeholder');
      frame.hidden = !preferences.maps;
      if (preferences.maps) {
        if (!frame.hasAttribute('src')) frame.src = frame.dataset.consentSrc;
      } else {
        frame.removeAttribute('src');
      }
      if (placeholder) placeholder.hidden = preferences.maps;
    });
  }

  function closeDialog() {
    dialog.close();
    if (returnFocus && returnFocus.isConnected) returnFocus.focus();
  }

  function openPreferences() {
    if (dialog.open) return;
    returnFocus = document.activeElement;
    dialog.querySelector('[name="maps"]').checked = preferences.maps;
    dialog.querySelector('[name="translation"]').checked = preferences.translation;
    dialog.showModal();
  }

  function saveChoice(next) {
    const revokeTranslation = preferences.translation && !next.translation;
    const value = { version: 1, ...next, savedAt: Date.now() };
    let persisted = true;
    try {
      localStorage.setItem(storageKey, JSON.stringify(value));
    } catch (error) {
      persisted = false;
      console.warn('Impossibile salvare le preferenze cookie.', error);
      if (preferences.translation) {
        showNotice('Impossibile salvare la modifica e interrompere in modo affidabile il traduttore: la scelta precedente resta attiva. Consenti la memoria del sito nel browser e riprova, oppure cancella i dati del sito e ricarica la pagina.');
        return;
      }
      if (next.translation || next.maps) {
        showNotice('Impossibile salvare il consenso: i nuovi servizi facoltativi non sono stati attivati. Consenti la memoria del sito nel browser e riprova. Puoi continuare a navigare e prenotare.');
        return;
      }
      showNotice('Preferenze applicate a questa pagina, ma non salvate: il browser impedisce la memorizzazione. Alla prossima pagina dovrai scegliere nuovamente.');
    }
    preferences = next;
    hasChoice = true;
    banner.hidden = true;
    if (dialog.open) closeDialog();
    applyMaps();
    window.dispatchEvent(new CustomEvent('thaitik:consent', { detail: { ...preferences } }));
    // Reload removes third-party scripts and their timers after a persisted revocation.
    if (revokeTranslation && persisted) location.reload();
  }

  window.thaiTikConsent = {
    allows: category => preferences[category] === true,
    openPreferences
  };

  function handleAction(event) {
    const button = event.target.closest('[data-consent-action]');
    if (!button) return;
    switch (button.dataset.consentAction) {
      case 'accept': saveChoice({ maps: true, translation: true }); break;
      case 'reject': saveChoice({ maps: false, translation: false }); break;
      case 'customize': openPreferences(); break;
      case 'close': closeDialog(); break;
      case 'save':
        saveChoice({
          maps: dialog.querySelector('[name="maps"]').checked,
          translation: dialog.querySelector('[name="translation"]').checked
        });
        break;
    }
  }
  banner.addEventListener('click', handleAction);
  dialog.addEventListener('click', handleAction);
  dialog.addEventListener('cancel', event => {
    event.preventDefault();
    closeDialog();
  });

  document.querySelectorAll('[data-open-consent]').forEach(button => {
    button.addEventListener('click', openPreferences);
  });
  const footerLinks = document.querySelector('footer .footer-legal-links, footer .legal-links');
  if (footerLinks) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'consent-footer-button';
    button.textContent = 'Preferenze cookie';
    button.addEventListener('click', openPreferences);
    footerLinks.append(button);
  }

  window.addEventListener('storage', event => {
    if (event.key !== storageKey && event.key !== null) return;
    const value = readChoice();
    const revokeTranslation = preferences.translation && !value?.translation;
    preferences = value ? { maps: value.maps, translation: value.translation } : { maps: false, translation: false };
    hasChoice = Boolean(value);
    banner.hidden = hasChoice;
    applyMaps();
    window.dispatchEvent(new CustomEvent('thaitik:consent', { detail: { ...preferences } }));
    if (revokeTranslation) location.reload();
  });

  applyMaps();
  if (storageWarning) showNotice(storageWarning);
})();
