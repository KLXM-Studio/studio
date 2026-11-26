/*
 * Seitenleiste schmal/breit: Knopf [data-side-mini] in der Titelzeile schaltet body.adm--mini (resources/css/_sidemini.css).
 * Zustand im Cookie adm_side (1 Jahr) – der Server setzt die Klasse schon beim Laden (views/layout.php), daher kein Springen.
 * Ausfahren im schmalen Zustand: body.is-side-peek – Zeiger 150 ms über der Leiste oder Tastaturfokus darin. Nach dem Einklappen
 * per Knopf bleibt die Leiste zu, bis der Zeiger sie einmal verlassen hat (sonst klappt sie sofort wieder auf).
 */
import { t } from './_i18n.js';

export function initSideMini() {
  const btn = document.querySelector('[data-side-mini]'), side = document.getElementById('adm-side'), body = document.body;
  if (!btn || !side) return;
  let timer = 0, hold = false, animT = 0;
  const anim = () => { body.classList.add('is-side-anim'); clearTimeout(animT); animT = setTimeout(() => body.classList.remove('is-side-anim'), 260); };
  const peek = on => { clearTimeout(timer); if (body.classList.contains('is-side-peek') !== on && body.classList.contains('adm--mini')) anim(); body.classList.toggle('is-side-peek', on && body.classList.contains('adm--mini')); };
  const label = on => t(on ? 'Seitenleiste breit (mit Beschriftung)' : 'Seitenleiste schmal (nur Symbole)');
  const set = on => {
    if (body.classList.contains('adm--mini') !== on) anim();
    body.classList.toggle('adm--mini', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.title = label(on);
    btn.setAttribute('aria-label', label(on));
    document.cookie = 'adm_side=' + (on ? 'mini' : 'wide') + ';path=/;max-age=31536000;SameSite=Lax';
  };
  set(body.classList.contains('adm--mini'));
  btn.addEventListener('click', () => {
    const on = !body.classList.contains('adm--mini');
    set(on);
    peek(false);
    if (on) { hold = true; btn.blur(); }
  });
  side.addEventListener('mouseenter', () => { if (!hold) { clearTimeout(timer); timer = setTimeout(() => peek(true), 150); } });
  side.addEventListener('mouseleave', () => { hold = false; peek(false); });
  side.addEventListener('focusin', e => { if (e.target !== btn || !hold) peek(true); });
  side.addEventListener('focusout', e => { if (!side.contains(e.relatedTarget) && !side.matches(':hover')) peek(false); });
}
