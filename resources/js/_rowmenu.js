/*
 * Zeilenmenü (⋯) in Eintragslisten (app/Admin/views/data/entries.php): Popover (popovertarget, ohne Skript bedienbar – dann
 * mittig) – hier an den Knopf gesetzt, rechtsbündig darunter bzw. darüber, wenn unten kein Platz ist; Fokus auf den ersten Punkt.
 */
let done = false;
export function initRowMenus() {
  if (done) return;
  done = true;
  document.addEventListener('toggle', e => {
    const pop = e.target;
    if (!(pop instanceof HTMLElement) || !pop.matches('.dt-rowmenu__pop') || e.newState !== 'open') return;
    const btn = document.querySelector(`[popovertarget="${CSS.escape(pop.id)}"]`);
    if (!btn) return;
    const r = btn.getBoundingClientRect();
    pop.dataset.placed = '';
    const w = pop.offsetWidth, h = pop.offsetHeight;
    const top = r.bottom + 4 + h > innerHeight - 8 ? Math.max(8, r.top - 4 - h) : r.bottom + 4;
    pop.style.setProperty('top', top + 'px');
    pop.style.setProperty('left', Math.max(8, Math.min(r.right - w, innerWidth - w - 8)) + 'px');
    pop.querySelector('.dt-rowmenu__item')?.focus();
  }, true);
}
