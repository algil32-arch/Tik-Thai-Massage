const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('Booking landing explains venue differences and separates massage and gift journeys', () => {
  const landing = read('prenota.html');
  assert.match(landing, /Studio Prati/);
  assert.match(landing, /APS Jaqueline/);
  assert.match(landing, /pagare online con SumUp/);
  assert.match(landing, /esclusivamente in sede/);
  assert.match(landing, /href="prenota-massaggio\.html"/);
  assert.match(landing, /href="prenota-voucher\.html"/);
  assert.match(landing, /location\.replace\(`prenota-massaggio\.html\$\{window\.location\.search\}`\)/);
});

test('Massage booking and gift voucher purchase have separate forms', () => {
  const massage = read('prenota-massaggio.html');
  const gift = read('prenota-voucher.html');
  assert.match(massage, /id="bookingRequestForm"/);
  assert.match(massage, /function loadCatalog\(\)/);
  assert.match(massage, /loadCatalog\(\);\s*<\/script>/);
  assert.match(massage, /function renderStudios\(\)/);
  assert.match(massage, /function renderServices\(\)/);
  assert.doesNotMatch(massage, /id="giftVoucherForm"/);
  assert.match(gift, /id="giftVoucherForm"/);
  assert.doesNotMatch(gift, /id="bookingRequestForm"/);
  assert.match(gift, /name="recipient_name"/);
  assert.match(gift, /name="codice_fiscale"/);
  assert.match(gift, /id="gift-sumup-card"/);
});
