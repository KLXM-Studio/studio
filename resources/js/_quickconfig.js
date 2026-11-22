/*
 * Schnelleinrichtung (Core\Blueprint\QuickConfig, app/Admin/views/quickconfig/index.php), Schritt Erscheinungsbild:
 * Paletten aus der Akzentfarbe (Core\Blueprint\Palette) mit Live-Vorschau. Die Paletten berechnet der Server (GET …/paletten?color=),
 * damit Ableitung und Kontrastprüfung nur an einer Stelle stehen; die Vorschau setzt nur CSS-Variablen. Gespeichert wird erst mit
 * dem Formular. Ohne JavaScript: Auswahl der zum Laden berechneten Paletten, Vorschau nach dem Speichern.
 */
import { t } from './_i18n.js';

const lum = hex => {
  const c = hex.replace('#', '').match(/../g).map(x => parseInt(x, 16) / 255).map(v => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
};
const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
const VARS = { accent: '--qc-a', on_accent: '--qc-on', accent_strong: '--qc-as', secondary: '--qc-b', surface: '--qc-surface', line: '--qc-line',
  dark_section: '--qc-dark', ink: '--qc-ink', text: '--qc-text', background: '--qc-bg' };

export function initQuickConfig(scope = document) {
  const input = scope.querySelector?.('[data-qc-color]');
  const box = scope.querySelector?.('[data-qc-preview]');
  const pals = scope.querySelector?.('[data-qc-pals]');
  if (!input || !box || input._qc) return;
  input._qc = true;
  const out = scope.querySelector('[data-qc-contrast]');
  const radios = () => [...(pals?.querySelectorAll('input[name=palette]') || [])];
  const colorsOf = r => { try { return JSON.parse(r?.dataset.qcColors || '{}'); } catch { return {}; } };
  const show = () => {
    const sel = radios().find(r => r.checked) || radios()[0];
    const c = colorsOf(sel);
    for (const [k, v] of Object.entries(VARS)) if (c[k]) box.style.setProperty(v, c[k]);
    if (out && c.accent) {
      const r = ratio(c.accent, c.background || '#ffffff');
      out.textContent = c.accent.toLowerCase() === input.value.toLowerCase()
        ? t('Kontrast {r} : 1 – gut lesbar.', { r: r.toFixed(1) })
        : t('Für gute Lesbarkeit angepasst: {color} (Kontrast {r} : 1).', { color: c.accent, r: r.toFixed(1) });
    }
  };
  let timer = 0;
  const reload = () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      const url = input.dataset.qcPalettes;
      if (!url || !/^#[0-9a-f]{6}$/i.test(input.value)) return;
      try {
        const res = await fetch(url + '?color=' + encodeURIComponent(input.value), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const data = await res.json();
        for (const p of data.palettes || []) {
          const r = radios().find(x => x.value === p.key);
          if (!r) continue;
          r.dataset.qcColors = JSON.stringify(p.colors);
          r.closest('label')?.querySelectorAll('[data-sw]').forEach(sw => sw.style.setProperty('background', p.colors[sw.dataset.sw] || 'transparent'));
        }
        show();
      } catch { /* Vorschau bleibt beim letzten Stand */ }
    }, 180);
  };
  input.addEventListener('input', reload);
  pals?.addEventListener('change', show);
  show();
}
