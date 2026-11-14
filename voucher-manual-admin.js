(() => {
  const studio = document.getElementById('manual-studio');
  const service = document.getElementById('manual-service');
  const formula = document.getElementById('manual-formula');
  const quantity = document.getElementById('manual-quantity');
  const amount = document.getElementById('manual-amount');

  function filterServices() {
    for (const option of service.options) {
      if (!option.value) continue;
      option.hidden = option.dataset.studio !== studio.value;
      option.disabled = option.hidden;
    }
    if (service.selectedOptions[0]?.disabled) service.value = '';
  }

  function updateQuote() {
    const option = service.selectedOptions[0];
    if (!option?.value) {
      amount.value = '';
      return;
    }
    try {
      const quote = giftVoucherQuote(option.dataset.price, Number(formula.value), Number(quantity.value));
      amount.value = (quote.totalCents / 100).toFixed(2);
    } catch (error) {
      amount.value = '';
    }
  }

  studio.addEventListener('change', () => { filterServices(); updateQuote(); });
  service.addEventListener('change', updateQuote);
  formula.addEventListener('change', updateQuote);
  quantity.addEventListener('input', updateQuote);
  filterServices();
})();
