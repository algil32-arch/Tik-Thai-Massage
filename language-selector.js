window.gtranslateSettings = {
  default_language: 'it',
  languages: ['it', 'en', 'th', 'es'],
  wrapper_selector: '.gtranslate_wrapper',
  flag_size: 18
};

let translationWidget = null;

function updateTranslation() {
  const allowed = window.thaiTikConsent?.allows('translation') === true;
  const wrapper = document.querySelector('.gtranslate_wrapper');
  if (!wrapper) return;
  if (!allowed) {
    if (translationWidget) {
      translationWidget.remove();
      translationWidget = null;
    }
    wrapper.replaceChildren();
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'translation-consent-button';
    button.textContent = 'Lingue / Languages';
    button.addEventListener('click', () => window.thaiTikConsent.openPreferences());
    wrapper.append(button);
    return;
  }
  if (translationWidget) return;
  wrapper.replaceChildren();
  translationWidget = document.createElement('script');
  translationWidget.src = 'https://cdn.gtranslate.net/widgets/latest/float.js';
  translationWidget.defer = true;
  const loadingWidget = translationWidget;
  translationWidget.addEventListener('load', () => {
    if (translationWidget !== loadingWidget) return;
    const flagLabels = {
      it: 'Italiano',
      en: 'English',
      th: 'ไทย',
      es: 'Español'
    };
    wrapper.querySelectorAll('.gt_options a[data-gt-lang]').forEach(link => {
      const language = link.dataset.gtLang;
      if (!Object.prototype.hasOwnProperty.call(flagLabels, language)) return;
      const image = link.querySelector('img');
      if (!image) return;
      image.src = `flags/${language}.svg`;
      image.alt = '';
      link.setAttribute('aria-label', flagLabels[language]);
      link.title = flagLabels[language];
    });
  });
  translationWidget.addEventListener('error', () => {
    if (translationWidget !== loadingWidget) return;
    console.error('Impossibile caricare il traduttore GTranslate.');
    wrapper.textContent = 'Traduzione non disponibile. Il sito resta in italiano.';
    translationWidget.remove();
    translationWidget = null;
  });
  document.head.append(translationWidget);
}

window.addEventListener('thaitik:consent', updateTranslation);
updateTranslation();