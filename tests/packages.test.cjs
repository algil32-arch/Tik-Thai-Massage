const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');

const root = path.join(__dirname, '..');
const treatments = [
  ['massaggio-thai-tradizionale.html', [60, 80]],
  ['massaggio-sportivo.html', [60]],
  ['massaggio-aromaterapia.html', [60, 80, 100]],
  ['tok-sen.html', [70]],
  ['foot-massage.html', [35, 50]]
];
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const tables = html => [...html.matchAll(/<table>\s*<caption>Pacchetti[\s\S]*?<\/table>/g)].map(match => match[0]);
const normalize = table => table.replace(/\s+/g, ' ').trim();

test('Home and treatment pages show the same packages for all nine formulas', () => {
  const home = tables(read('index.html'));
  assert.equal(home.length, treatments.length);
  treatments.forEach(([file, prices], index) => {
    const detail = tables(read(file));
    assert.equal(detail.length, 1, file);
    assert.equal(normalize(home[index]), normalize(detail[0]), file);
    const rows = [...detail[0].matchAll(/<tr><th scope="row">[\s\S]*?<\/tr>/g)];
    assert.equal(rows.length, prices.length, file);
    rows.forEach(([row], rowIndex) => {
      const amounts = [...row.matchAll(/(\d+(?:,\d{2})?) €(?:<small>| \/ seduta)/g)]
        .map(match => Number(match[1].replace(',', '.')));
      const price = prices[rowIndex];
      const unit3 = Math.ceil(price * 95 / 100);
      const unit6 = Math.ceil(price * 90 / 100);
      const package3 = unit3 * 3 === 171 ? 170 : Math.ceil(unit3 * 3 / 5) * 5;
      const package6 = Math.ceil(unit6 * 6 / 5) * 5;
      assert.deepEqual(amounts, [package3, Math.round(package3 / 3 * 100) / 100,
        package6, Math.round(package6 / 6 * 100) / 100], `${file}, formula ${rowIndex}`);
    });
    assert.doesNotMatch(detail[0], /%|scont|arrotond/i);
    assert.doesNotMatch(read(file), /class="package-note"|class="package-contact"/);
  });
  assert.doesNotMatch(read('index.html'), /class="package-note"|class="package-contact"/);
});

test('Package styles load on the home and detail pages without changing booking services', () => {
  assert.match(read('index.html'), /href="packages\.css"/);
  assert.match(read('details.css'), /@import url\("packages\.css"\)/);
  assert.ok(fs.existsSync(path.join(root, 'packages.css')));
  assert.ok(fs.existsSync(path.join(root, 'listino-trattamenti.pdf')));
  assert.ok(fs.existsSync(path.join(root, 'listino-trattamenti.html')));
  treatments.forEach(([file]) => assert.match(read(file), /href="details\.css"/));
  assert.equal(tables(read('prenota.html')).length, 0);
});

test('Printable price list has two pages and describes every treatment', () => {
  const list = read('listino-trattamenti.html');
  assert.equal((list.match(/<section class="page/g) || []).length, 2);
  assert.match(list, /<h1 id="price-title">Massaggi singoli<\/h1>/);
  assert.match(list, /<h1 id="package-title">Percorsi benessere<\/h1>/);
  const [singlePage, packagePage] = [...list.matchAll(/<section class="page[^"]*"[\s\S]*?<\/section>/g)].map(match => match[0]);
  assert.equal((singlePage.match(/class="single-price"/g) || []).length, 9);
  assert.equal((singlePage.match(/class="treatment-description"/g) || []).length, 9);
  assert.equal((packagePage.match(/class="package-total"/g) || []).length, 18);
  assert.doesNotMatch(packagePage, /class="single-price"|class="treatment-description"/);
  assert.match(packagePage, /Percorso 3 sedute/);
  assert.match(packagePage, /Percorso 6 sedute/);
  assert.doesNotMatch(list, /<img\b|thai-tik-logo/i);
});
