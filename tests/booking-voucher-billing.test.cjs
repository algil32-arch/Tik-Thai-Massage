const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('Billing fields are skipped only for the currently verified manual voucher', () => {
  const page = fs.readFileSync(require.resolve('../prenota-massaggio.html'), 'utf8');
  const invoice = page.match(/function updateInvoiceFields\(\) \{[\s\S]*?\n      \}/)[0];
  const signature = page.match(/function bookingVoucherSelection\(\) \{[\s\S]*?\n      \}/)[0];
  const ids = ['customerType', 'billingFields', 'billingNotice', 'companyNameField', 'vatField',
    'recipientField', 'pecField', 'billingHelp', 'taxCodeField', 'companyName', 'taxCode',
    'vatNumber', 'billingAddress', 'billingCity', 'billingPostalCode', 'bookingVoucherCode'];
  const elements = Object.fromEntries(ids.map(id => [id, { value: '', hidden: false, disabled: false, required: false }]));
  elements.customerType.value = 'privato';
  elements.bookingVoucherCode.value = 'TTM-AAAAAAAAAAAAAAAAAAAA';
  const payment = { value: 'voucher' };
  const context = vm.createContext({
    document: {
      getElementById: id => elements[id],
      querySelector: () => payment,
    },
    selected: { studioId: 1, serviceId: 2, date: '2027-01-01', time: '10:00' },
    checkedBookingVoucher: '',
    checkedVoucherBillingRequired: true,
  });

  vm.runInContext(`${signature}\n${invoice}`, context);
  context.updateInvoiceFields();
  assert.equal(elements.billingFields.hidden, false);
  assert.equal(elements.taxCode.required, true);

  context.checkedBookingVoucher = context.bookingVoucherSelection();
  context.checkedVoucherBillingRequired = false;
  context.updateInvoiceFields();
  assert.equal(elements.billingFields.hidden, true);
  assert.equal(elements.billingFields.disabled, true);

  elements.bookingVoucherCode.value = 'TTM-BBBBBBBBBBBBBBBBBBBB';
  context.updateInvoiceFields();
  assert.equal(elements.billingFields.hidden, false);
  assert.equal(elements.billingFields.disabled, false);

  context.checkedBookingVoucher = context.bookingVoucherSelection();
  context.checkedVoucherBillingRequired = true;
  context.updateInvoiceFields();
  assert.equal(elements.billingFields.hidden, false);

  context.checkedVoucherBillingRequired = false;
  payment.value = 'studio';
  context.updateInvoiceFields();
  assert.equal(elements.billingFields.hidden, false);
  elements.customerType.value = 'azienda';
  context.updateInvoiceFields();
  assert.equal(elements.vatNumber.required, true);
  assert.equal(elements.taxCode.required, false);
});

test('Voucher verification is beside booking and email becomes optional for voucher only', () => {
  const page = fs.readFileSync(require.resolve('../prenota-massaggio.html'), 'utf8');
  const submitRow = page.match(/<div class="submit-row">([\s\S]*?)<\/div>/)[1];
  assert.match(submitRow, /id="checkBookingVoucher"/);
  assert.match(submitRow, /form="bookingRequestForm"/);
  assert.match(page, /getElementById\('email'\)\.required = !useVoucher/);
  assert.match(page, /!email && paymentMethod !== 'voucher'/);
});
