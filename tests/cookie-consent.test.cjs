const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'cookie-consent.js'), 'utf8');
const key = 'thaitik-consent-v1';

function boot(raw = null, storageBlocked = false) {
  const nodes = [];
  const listeners = {};
  const data = new Map(raw === null ? [] : [[key, raw]]);
  let reloads = 0;
  const document = {
    activeElement: null,
    querySelectorAll: () => [],
    querySelector: () => null,
    createElement: () => ({
      hidden: false,
      open: false,
      events: {},
      setAttribute() {},
      replaceChildren() {},
      addEventListener(name, listener) { this.events[name] = listener; }
    }),
    body: { append: (...elements) => nodes.push(...elements) }
  };
  const window = {
    addEventListener: (name, listener) => { listeners[name] = listener; },
    dispatchEvent() {}
  };
  vm.runInNewContext(source, {
    document, window, Date, console: { warn() {} },
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
    location: { reload: () => reloads++ },
    localStorage: {
      getItem(name) {
        if (storageBlocked) throw new Error('Storage blocked');
        return data.get(name) ?? null;
      },
      setItem: (name, value) => {
        if (storageBlocked) throw new Error('Storage blocked');
        data.set(name, value);
      }
    }
  });
  return {
    nodes, data, listeners, api: window.thaiTikConsent,
    reloads: () => reloads,
    action: action => nodes[0].events.click({
      target: { closest: () => ({ dataset: { consentAction: action } }) }
    })
  };
}

test('first visit and unknown categories are denied by default', () => {
  const state = boot();
  assert.equal(state.api.allows('maps'), false);
  assert.equal(state.api.allows('translation'), false);
  assert.equal(state.api.allows('marketing'), false);
  assert.equal(state.nodes[0].hidden, false);
});

test('accept and reject persist explicit choices', () => {
  const state = boot();
  state.action('accept');
  assert.equal(state.api.allows('maps'), true);
  assert.equal(state.api.allows('translation'), true);
  assert.equal(state.nodes[0].hidden, true);
  state.action('reject');
  assert.equal(JSON.parse(state.data.get(key)).maps, false);
  assert.equal(JSON.parse(state.data.get(key)).translation, false);
  assert.equal(state.reloads(), 1);
});

test('valid choices apply separately without showing the banner', () => {
  const state = boot(JSON.stringify({ version: 1, maps: true, translation: false, savedAt: Date.now() }));
  assert.equal(state.api.allows('maps'), true);
  assert.equal(state.api.allows('translation'), false);
  assert.equal(state.nodes[0].hidden, true);
});

test('expired, malformed and future choices fail closed', () => {
  for (const raw of [
    '{broken',
    'null',
    JSON.stringify({ version: 1, maps: 'true', translation: false, savedAt: Date.now() }),
    JSON.stringify({ version: 1, maps: true, translation: true, savedAt: Date.now() + 86400000 }),
    JSON.stringify({ version: 1, maps: true, translation: true, savedAt: Date.now() - 180 * 86400000 })
  ]) {
    const state = boot(raw);
    assert.equal(state.api.allows('maps'), false);
    assert.equal(state.api.allows('translation'), false);
    assert.equal(state.nodes[0].hidden, false);
  }
});

test('unavailable storage reports a problem and never enables optional services', () => {
  const state = boot(null, true);
  state.action('accept');
  assert.equal(state.api.allows('maps'), false);
  assert.equal(state.api.allows('translation'), false);
  assert.equal(state.nodes[2].hidden, false);
  state.action('reject');
  assert.equal(state.nodes[0].hidden, true);
});

test('cross-tab revocation disables services and reloads the active translator', () => {
  const state = boot(JSON.stringify({ version: 1, maps: true, translation: true, savedAt: Date.now() }));
  state.data.clear();
  state.listeners.storage({ key: null });
  assert.equal(state.api.allows('maps'), false);
  assert.equal(state.api.allows('translation'), false);
  assert.equal(state.reloads(), 1);
});

test('all public pages load consent before the language widget and have no remote font requests', () => {
  const pages = ['index', 'prenota', 'prenota-massaggio', 'prenota-voucher', 'dove-trovarmi', 'massaggio-thai-tradizionale',
    'massaggio-sportivo', 'massaggio-aromaterapia', 'tok-sen', 'foot-massage',
    'storia-massaggio-thai', 'documenti-legali-premium', 'footer-mobile-first'];
  for (const name of pages) {
    const html = fs.readFileSync(path.join(root, `${name}.html`), 'utf8');
    assert.match(html, /src="cookie-consent\.js" defer/);
    assert.match(html, /href="cookie-consent\.css"/);
    assert.doesNotMatch(html, /fonts\.googleapis\.com|fonts\.gstatic\.com/);
    const languageIndex = html.indexOf('src="language-selector.js"');
    if (languageIndex !== -1) assert.ok(html.indexOf('src="cookie-consent.js"') < languageIndex);
  }
  const maps = fs.readFileSync(path.join(root, 'dove-trovarmi.html'), 'utf8');
  assert.equal((maps.match(/data-consent-src=/g) || []).length, 2);
  assert.doesNotMatch(maps, /\s src="https:\/\/www\.google\.com\/maps/);
});
