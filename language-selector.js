window.gtranslateSettings = {
  default_language: 'it',
  languages: ['it', 'en', 'th', 'es'],
  wrapper_selector: '.gtranslate_wrapper',
  flag_size: 18
};

const translationWidget = document.createElement('script');
translationWidget.src = 'https://cdn.gtranslate.net/widgets/latest/float.js';
translationWidget.defer = true;
document.head.append(translationWidget);