const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'studio-hours.js'), 'utf8');
const names = ['Studio Prati', 'APS Studio Montesacro'];

async function load(data, status = 200, failure = null) {
  const labels = names.map(name => ({ dataset: { studioHours: name }, textContent: '' }));
  const errors = [];
  await vm.runInNewContext(source, {
    document: { querySelectorAll: () => labels },
    fetch: async (url, options) => {
      assert.equal(url, 'php-booking-backend.php?mode=hours');
      assert.equal(options.headers.Accept, 'application/json');
      if (failure) throw failure;
      return { ok: status === 200, status, json: async () => data };
    },
    console: { error: (...args) => errors.push(args) }
  });
  return { labels, errors };
}

test('studio hours display only the schedule of the matching venue', async () => {
  const result = await load({ success: true, studios: [
    { nome: names[1], hours: [{ weekday: 7, start: '10:00', end: '13:00' }] },
    { nome: names[0], hours: [
      { weekday: 1, start: '09:00', end: '12:00' },
      { weekday: 1, start: '14:00', end: '18:00' }
    ] }
  ] });
  assert.equal(result.labels[0].textContent, 'Lunedì 09:00–12:00 · Lunedì 14:00–18:00');
  assert.equal(result.labels[1].textContent, 'Domenica 10:00–13:00');
  assert.equal(result.errors.length, 0);
});

test('missing studios and unconfigured hours have explicit messages', async () => {
  const result = await load({ success: true, studios: [{ nome: names[0], hours: [] }] });
  assert.equal(result.labels[0].textContent, 'Orari non ancora configurati');
  assert.equal(result.labels[1].textContent, 'Sede non disponibile');
});

test('HTTP, network and malformed schedule failures are visible and logged', async () => {
  for (const [data, status, failure] of [
    [null, 503],
    [null, 200, new Error('Network unavailable')],
    [{ success: false, studios: [] }, 200],
    [{ success: true, studios: null }, 200],
    ...[
      null,
      [{ weekday: 8, start: '09:00', end: '10:00' }],
      [{ weekday: 1, start: '25:00', end: '26:00' }],
      [{ weekday: 1, start: '12:00', end: '10:00' }]
    ].map(hours => [{ success: true, studios: [{ nome: names[0], hours }] }, 200])
  ]) {
    const result = await load(data, status, failure);
    for (const label of result.labels) assert.match(label.textContent, /Orari temporaneamente non disponibili/);
    assert.equal(result.errors.length, 1);
  }
});
