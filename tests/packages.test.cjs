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
      const amounts = [...row.matchAll(/(\d+) €(?:<small>| \/ seduta)/g)].map(match => Number(match[1]));
      const price = prices[rowIndex];
      const unit5 = Math.ceil(price * 95 / 100);
      const unit10 = Math.ceil(price * 90 / 100);
      assert.deepEqual(amounts, [unit5 * 5, unit5, unit10 * 10, unit10], `${file}, formula ${rowIndex}`);
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
  treatments.forEach(([file]) => assert.match(read(file), /href="details\.css"/));
  assert.equal(tables(read('prenota.html')).length, 0);
});
