(() => {
  const header = document.querySelector('.site-header');
  if (!header) return;

  let previousScrollY = window.scrollY;
  let ticking = false;

  const updateHeaderVisibility = () => {
    const currentScrollY = window.scrollY;
    const scrollDelta = currentScrollY - previousScrollY;

    if (currentScrollY <= header.offsetHeight || scrollDelta < -4) {
      header.classList.remove('is-hidden');
    } else if (scrollDelta > 4 && !header.contains(document.activeElement)) {
      header.classList.add('is-hidden');
    }

    previousScrollY = currentScrollY;
    ticking = false;
  };

  window.addEventListener('scroll', () => {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(updateHeaderVisibility);
  }, { passive: true });
})();
