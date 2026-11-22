/*
 * Schriftgröße der Verwaltung (Core\UiScale): Schieber im Konto-Menü der Kopfleiste und unter Konto → Darstellung
 * (app/Admin/views/account/_uiscale.php). Beim Ziehen sofort data-fs an <html class="adm-ui"> (Live-Vorschau, CSS in
 * resources/css/_uiscale.css), gespeichert wird beim Loslassen bzw. per Tastatur (change) – per fetch, ohne Neuladen.
 * Mehrere Schieber auf einer Seite laufen gleich. Ohne JavaScript speichert der Knopf „Speichern“ das Formular.
 */
const d = document;

export function initUiScale(csrf) {
  const forms = [...d.querySelectorAll('form[data-uiscale]')];
  if (!forms.length) return;
  const html = d.documentElement;
  const steps = (forms[0].dataset.steps || '90,100,112,125,140').split(',').map(Number);
  const def = Number(forms[0].dataset.default || 100);
  const text = fs => fs + ' %';
  let saveTimer = 0, seq = 0;

  const apply = fs => {
    if (fs === def) delete html.dataset.fs; else html.dataset.fs = String(fs);
    for (const f of forms) {
      const r = f.querySelector('[data-uiscale-range]');
      if (r && Number(r.value) !== steps.indexOf(fs)) r.value = String(steps.indexOf(fs));
      r?.setAttribute('aria-valuetext', text(fs));
      const out = f.querySelector('[data-uiscale-out]');
      if (out) out.textContent = text(fs);
      const reset = f.querySelector('[data-uiscale-reset]');
      if (reset) reset.disabled = fs === def;
    }
    // Lage offener Popover (Kopfleiste) und andere Maße neu berechnen – die Oberfläche ist größer bzw. kleiner geworden
    dispatchEvent(new Event('resize'));
  };

  const status = (msg, form) => {
    for (const f of forms) { const s = f.querySelector('[data-uiscale-status]'); if (s) s.textContent = f === form ? msg : ''; }
  };

  const save = (fs, form) => {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(async () => {
      const my = ++seq;
      try {
        const r = await fetch(form.action, { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify({ fs }) });
        if (!r.ok) throw new Error(String(r.status));
        if (my === seq) status(form.dataset.savedText || '', form);
      } catch {
        if (my === seq) status(form.dataset.errorText || '', form);
      }
    }, 250);
  };

  for (const form of forms) {
    const range = form.querySelector('[data-uiscale-range]');
    if (!range) continue;
    form.querySelector('[data-uiscale-save]')?.setAttribute('hidden', '');
    const pick = () => steps[Math.max(0, Math.min(steps.length - 1, Number(range.value)))] ?? def;
    range.addEventListener('input', () => { status('', form); apply(pick()); });
    range.addEventListener('change', () => save(pick(), form));
    form.querySelector('[data-uiscale-reset]')?.addEventListener('click', e => {
      e.preventDefault();
      apply(def);
      save(def, form);
      range.focus();
    });
    form.addEventListener('submit', e => { e.preventDefault(); save(pick(), form); });
  }
}
