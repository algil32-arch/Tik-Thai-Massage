const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('Booking landing explains venue differences and separates massage and gift journeys', () => {
  const landing = read('prenota.html');
  assert.match(landing, /Studio Prati/);
  assert.match(landing, /APS Jaqueline/);
  assert.match(landing, /pagare online con SumUp/);
  assert.match(landing, /socia professionista/);
  assert.match(landing, /iscriversi al momento del primo appuntamento/);
  assert.match(landing, /voucher valido per questa sede/);
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
  assert.match(gift, /id="giftStudio"/);
  assert.match(gift, /sede indicata sul voucher/);
});

test('Online payment and voucher booking are enabled for the APS venue', () => {
  const massage = read('prenota-massaggio.html');
  const backend = read('php-booking-backend.php');
  const voucher = read('voucher.php');
  assert.match(massage, /onlineRadio\.disabled = Boolean\(activeCheckout\)/);
  assert.match(massage, /voucherRadio\.disabled = Boolean\(activeCheckout\)/);
  assert.doesNotMatch(backend, /paymentMethod === '(?:online|voucher)' && \$studioRecord\['nome'\]/);
  assert.doesNotMatch(voucher, /s\.nome = 'Studio Prati'/);
});

test('Partner pages preselect the correct studio and booking cards link to their details', () => {
  const massage = read('prenota-massaggio.html');
  const resolver = massage.match(/function resolveInitialStudio\(value\) \{[\s\S]*?\n      \}/)[0];
  const locationUrl = massage.match(/function getStudioLocationUrl\(studio\) \{[\s\S]*?\n      \}/)[0];
  const studios = [
    { id: 9001, name: 'Studio Prati' },
    { id: 9002, name: 'APS Studio Montesacro' }
  ];
  const context = vm.createContext({ studios });
  vm.runInContext(`${resolver}\n${locationUrl}`, context);
  for (const [page, expectedId, studio] of [
    ['centro-prati.html', 9001, studios[0]],
    ['centro-monte-sacro.html', 9002, studios[1]]
  ]) {
    const link = read(page).match(/href="(prenota-massaggio\.html\?studio=[^"]+)"/)[1];
    const value = new URL(link, 'https://www.thaitikmassage.it/').searchParams.get('studio');
    assert.equal(context.resolveInitialStudio(value), expectedId);
    assert.equal(context.getStudioLocationUrl(studio), page);
  }
});
