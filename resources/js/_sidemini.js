/*
 * Seitenleiste schmal/breit: Knopf [data-side-mini] in der Titelzeile schaltet body.adm--mini (resources/css/_sidemini.css).
 * Zustand im Cookie adm_side (1 Jahr) – der Server setzt die Klasse schon beim Laden (views/layout.php), daher kein Springen.
 */
import { t } from './_i18n.js';

export function initSideMini() {
  const btn = document.querySelector('[data-side-mini]');
  if (!btn) return;
  const label = on => t(on ? 'Seitenleiste breit (mit Beschriftung)' : 'Seitenleiste schmal (nur Symbole)');
  const set = on => {
    document.body.classList.toggle('adm--mini', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.title = label(on);
    btn.setAttribute('aria-label', label(on));
    document.cookie = 'adm_side=' + (on ? 'mini' : 'wide') + ';path=/;max-age=31536000;SameSite=Lax';
  };
  set(document.body.classList.contains('adm--mini'));
  btn.addEventListener('click', () => {
    const on = !document.body.classList.contains('adm--mini');
    set(on);
    if (on) btn.blur();   // sonst hält :focus-within die Leiste offen
  });
}
