/*
 * Schnelleinrichtung (Core\Blueprint\QuickConfig, app/Admin/views/quickconfig/index.php): Vorschau der Akzentfarbe beim Wählen –
 * Knopf und Link in der Farbe, Kontrast zu Weiß (Schrift auf dem Knopf) und zum hellen Hintergrund. Gespeichert wird erst mit dem
 * Formular; der Server dunkelt bei Bedarf ab (Design::readable, mindestens 4,5 : 1). Ohne JavaScript zeigt die Vorschau die
 * gespeicherte Farbe.
 */
import { t } from './_i18n.js';

const lum = hex => {
  const c = hex.replace('#', '').match(/../g).map(x => parseInt(x, 16) / 255).map(v => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
};
const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };

export function initQuickConfig(scope = document) {
  const input = scope.querySelector?.('[data-qc-color]');
  const box = scope.querySelector?.('[data-qc-preview]');
  if (!input || !box || input._qc) return;
  input._qc = true;
  const out = box.querySelector('[data-qc-contrast]');
  const show = () => {
    const c = input.value;
    if (!/^#[0-9a-f]{6}$/i.test(c)) return;
    const onWhite = ratio(c, '#ffffff');
    box.style.setProperty('--qc-a', c);
    box.style.setProperty('--qc-on', onWhite >= 4.5 ? '#ffffff' : '#111111');
    if (out) out.textContent = onWhite >= 4.5
      ? t('Kontrast {r} : 1 – gut lesbar.', { r: onWhite.toFixed(1) })
      : t('Kontrast {r} : 1 – wird beim Speichern abgedunkelt, damit Text lesbar bleibt.', { r: onWhite.toFixed(1) });
  };
  input.addEventListener('input', show);
  show();
}
