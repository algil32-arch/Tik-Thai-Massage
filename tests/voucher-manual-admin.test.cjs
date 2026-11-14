const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { giftVoucherQuote } = require('../gift-voucher.js');

test('Manual form filters treatments, proposes package prices and allows overrides', () => {
  const listeners = {};
  const element = (id, value) => ({
    id, value,
    addEventListener(event, callback) { listeners[`${id}:${event}`] = callback; },
  });
  const studio = element('manual-studio', '');
  const service = element('manual-service', '');
  service.options = [
    { value: '', dataset: {} },
    { value: '3', dataset: { studio: '2', price: '60.00' } },
    { value: '4', dataset: { studio: '5', price: '80.00' } },
  ];
  Object.defineProperty(service, 'selectedOptions', {
    get() { return [this.options.find(option => option.value === this.value)]; },
  });
  const formula = element('manual-formula', '3');
  const quantity = element('manual-quantity', '2');
  const amount = element('manual-amount', '');
  const elements = Object.fromEntries([studio, service, formula, quantity, amount].map(item => [item.id, item]));
  vm.runInNewContext(fs.readFileSync(require.resolve('../voucher-manual-admin.js'), 'utf8'), {
    document: { getElementById: id => elements[id] }, giftVoucherQuote,
  });
  studio.value = '2';
  listeners['manual-studio:change']();
  assert.equal(service.options[1].disabled, false);
  assert.equal(service.options[2].disabled, true);
  service.value = '3';
  listeners['manual-service:change']();
  assert.equal(amount.value, '340.00');
  amount.value = '155.50';
  assert.equal(amount.value, '155.50');
  formula.value = '6';
  listeners['manual-formula:change']();
  assert.equal(amount.value, '650.00');
  studio.value = '5';
  listeners['manual-studio:change']();
  assert.equal(service.value, '');
  assert.equal(amount.value, '');
  assert.equal(service.options[1].disabled, true);
  assert.equal(service.options[2].disabled, false);
});
