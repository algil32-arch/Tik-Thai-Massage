const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { giftVoucherQuote } = require('../gift-voucher.js');

test('Browser and server agree on every voucher formula and quantity', () => {
  for (const price of [35, 50, 60, 70, 80, 100, 60.50]) {
    for (const sessions of [1, 5, 10]) {
      for (const quantity of [1, 2, 20]) {
        const quote = giftVoucherQuote(price, sessions, quantity);
        const php = execFileSync('php', ['-r', `require 'voucher-model.php'; echo json_encode(voucherQuote('${price}', ${sessions}, ${quantity}));`], { cwd: require('node:path').join(__dirname, '..'), encoding: 'utf8' });
        const server = JSON.parse(php);
        assert.equal(quote.totalCents, server.total_cents);
        assert.equal(quote.totalSessions, server.total_sessions);
        assert.equal(quote.itemCents * quantity, quote.totalCents);
      }
    }
  }
});

test('Browser rejects invalid formulas and quantities', () => {
  for (const input of [[0, 1, 1], [60, 3, 1], [60, 5, 0], [60, 5, 21], [60, 5, 1.5]]) {
    assert.throws(() => giftVoucherQuote(...input));
  }
});
