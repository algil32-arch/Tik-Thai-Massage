const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { giftVoucherQuote, giftVoucherGreeting, giftVoucherStudioName } = require('../gift-voucher.js');
const fs = require('node:fs');

test('Browser and server agree on every voucher formula and quantity', () => {
  for (const price of [35, 50, 60, 70, 80, 100, 60.50]) {
    for (const sessions of [1, 3, 6]) {
      for (const quantity of [1, 2, 20]) {
        const quote = giftVoucherQuote(price, sessions, quantity);
        const php = execFileSync('php', ['-r', `require 'voucher-model.php'; echo json_encode(voucherQuote('${price}', ${sessions}, ${quantity}));`], { cwd: require('node:path').join(__dirname, '..'), encoding: 'utf8' });
        const server = JSON.parse(php);
        assert.equal(quote.totalCents, server.total_cents);
        assert.equal(quote.totalSessions, server.total_sessions);
        assert.equal(quote.itemCents * quantity, quote.totalCents);
        if (sessions === 1) {
          assert.equal(quote.itemCents, Math.round(price * 100));
        } else {
          const unit = Math.ceil(price * (sessions === 3 ? 0.95 : 0.9));
          const packageAmount = unit * sessions;
          const expected = packageAmount === 171 ? 170 : Math.ceil(packageAmount / 5) * 5;
          assert.equal(quote.itemCents, expected * 100);
        }
      }
    }
  }
});

test('Gift voucher packages apply the agreed rounded totals', () => {
  for (const [price, sessions, expected] of [
    [60, 3, 170], [60, 6, 325], [80, 3, 230], [80, 6, 435],
    [70, 3, 205], [70, 6, 380], [35, 3, 105], [35, 6, 195],
    [50, 3, 145], [50, 6, 270]
  ]) {
    assert.equal(giftVoucherQuote(price, sessions, 1).itemCents, expected * 100);
  }
});

test('Browser rejects invalid formulas and quantities', () => {
  for (const input of [[0, 1, 1], [60, 5, 1], [60, 10, 1], [60, 3, 0], [60, 3, 21], [60, 3, 1.5]]) {
    assert.throws(() => giftVoucherQuote(...input));
  }
});

test('Printable browser voucher includes personalized message and instructions', () => {
  const script = fs.readFileSync(require.resolve('../gift-voucher.js'), 'utf8');
  assert.match(script, /giftVoucherGreeting\(voucher\)/);
  assert.match(script, /un massaggio "\$\{voucher\.service_name\}"/);
  assert.match(script, /massaggiatrice certificata Thailandese/);
  assert.match(script, /Email facoltativa/);
  assert.match(script, /annulla-voucher\.php/);
});

test('Gift greeting uses selected title or a neutral legacy greeting', () => {
  const voucher = { recipient_name: 'Anna Rossi', service_name: 'Thai' };
  assert.match(giftVoucherGreeting({ ...voucher, recipient_title: 'sigra' }), /^Sig\.ra Anna Rossi,/);
  assert.match(giftVoucherGreeting({ ...voucher, recipient_title: 'sig' }), /^Sig\. Anna Rossi,/);
  assert.match(giftVoucherGreeting(voucher), /^Per Anna Rossi è stato scelto/);
  assert.throws(() => giftVoucherGreeting({ ...voucher, recipient_title: 'invalid' }));
  assert.equal(giftVoucherStudioName('Studio Prati'), 'Studio Prati - C/O Barbiere Prati lounge di estetica');
  assert.equal(giftVoucherStudioName('APS Studio Montesacro'), 'APS Studio Monte Sacro');
});
