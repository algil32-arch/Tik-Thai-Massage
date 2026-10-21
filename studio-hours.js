(async function loadStudioHours() {
  const labels = [...document.querySelectorAll('[data-studio-hours]')];
  if (labels.length === 0) return;
  const weekdays = ['Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato', 'Domenica'];
  try {
    const response = await fetch('php-booking-backend.php?mode=hours', { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error(`Studio hours request failed: ${response.status}`);
    const data = await response.json();
    if (data.success !== true || !Array.isArray(data.studios)) {
      throw new Error('Invalid studio hours response');
    }
    for (const label of labels) {
      const studio = data.studios.find(item => item.nome === label.dataset.studioHours);
      if (!studio) {
        label.textContent = 'Sede non disponibile';
        continue;
      }
      if (!Array.isArray(studio.hours) || studio.hours.some(item =>
        !Number.isInteger(item.weekday) || item.weekday < 1 || item.weekday > 7
        || !/^([01]\d|2[0-3]):[0-5]\d$/.test(item.start)
        || !/^([01]\d|2[0-3]):[0-5]\d$/.test(item.end)
        || item.start >= item.end)) {
        throw new Error('Invalid studio schedule');
      }
      label.textContent = studio.hours.length
        ? studio.hours.map(item => `${weekdays[item.weekday - 1]} ${item.start}–${item.end}`).join(' · ')
        : 'Orari non ancora configurati';
    }
  } catch (error) {
    console.error('Impossibile caricare gli orari delle sedi:', error);
    for (const label of labels) label.textContent = 'Orari temporaneamente non disponibili. Contatta lo studio.';
  }
})();
