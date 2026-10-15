const { test } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { giftVoucherQuote } = require('../gift-voucher.js');

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
