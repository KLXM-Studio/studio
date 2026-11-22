/*
 * Popover-Menüs der Kopfleiste ([data-pop], app/Admin/views/_areaswitch.php, _topbar.php): Umschalter Netzwerk ↔ Website,
 * Favoriten, Seitenbaum, Konto und Hilfe, Quellen-Auswahl der Suche. Öffnen/Schließen und Escape übernimmt der Browser (popover/popovertarget);
 * hier: Lage am Knopf (Top Layer – darf über die Leiste hinausragen; ≤ 600 px Blatt von unten per CSS), aria-expanded,
 * Tastatur (↑ ↓ Pos1 Ende, Fokus aufs erste Element bzw. den Filter), Filter ab > 8 Einträgen.
 */
const d = document;
const SHEET = matchMedia('(max-width:600px)');
// Einträge für die Pfeiltasten: Menüpunkte bzw. (Favoriten-Liste ohne Menü-Rolle) Links und Knöpfe
const items = pop => {
  const m = [...pop.querySelectorAll('[role^=menuitem]')];
  return (m.length ? m : [...pop.querySelectorAll('a[href],button:not([disabled]),input.adm-fav__name')]).filter(el => !el.hidden && el.offsetParent !== null);
};

function place(pop, btn) {
  pop.style.top = pop.style.left = pop.style.right = '';
  if (SHEET.matches || !btn) return;
  const r = btn.getBoundingClientRect(), w = pop.offsetWidth, h = pop.offsetHeight, vw = innerWidth, vh = innerHeight;
  let left = pop.classList.contains('adm-pop--end') ? r.right - w : r.left;
  left = Math.max(12, Math.min(left, vw - w - 12));
  let top = r.bottom + 6;
  if (top + h > vh - 12 && r.top - h - 6 > 12) top = r.top - h - 6;
  pop.style.left = left + 'px';
  pop.style.top = Math.max(12, top) + 'px';
}

export function initPopovers() {
  const pops = [...d.querySelectorAll('[data-pop]')];
  if (!pops.length || !HTMLElement.prototype.hasOwnProperty('popover')) return;
  for (const pop of pops) {
    const btns = [...d.querySelectorAll(`[popovertarget="${pop.id}"][data-pop-btn]`)];
    btns.forEach(b => b.setAttribute('aria-expanded', 'false'));
    let opener = null;
    btns.forEach(b => b.addEventListener('click', () => { opener = b; }));
    const filter = pop.querySelector('[data-pop-filter]');
    pop.addEventListener('toggle', e => {
      const open = e.newState === 'open';
      btns.forEach(b => b.setAttribute('aria-expanded', String(open)));
      if (!open) { if (filter) { filter.value = ''; applyFilter(pop, ''); } return; }
      const btn = opener && opener.offsetParent !== null ? opener : btns.find(b => b.offsetParent !== null);
      place(pop, btn);
      const first = filter || pop.querySelector('[role^=menuitem][aria-current],[role^=menuitem][aria-checked=true]') || items(pop)[0];
      first?.focus({ preventScroll: true });
    });
    filter?.addEventListener('input', () => applyFilter(pop, filter.value));
    pop.addEventListener('keydown', e => {
      // Schieber (z. B. Schriftgröße im Konto-Menü) behält Pfeile, Pos1 und Ende
      if (e.target.matches?.('input[type=range]')) return;
      const list = items(pop);
      const i = list.indexOf(d.activeElement);
      let n = null;
      if (e.key === 'ArrowDown') n = i < 0 ? 0 : (i + 1) % list.length;
      else if (e.key === 'ArrowUp') n = i <= 0 ? list.length - 1 : i - 1;
      else if (e.key === 'Home' && e.target !== filter) n = 0;
      else if (e.key === 'End' && e.target !== filter) n = list.length - 1;
      else if (e.key === 'Enter' && e.target === filter && list[0]) { e.preventDefault(); list[0].click(); return; }
      if (n === null || !list.length) return;
      e.preventDefault();
      list[n].focus();
    });
    addEventListener('resize', () => { if (pop.matches(':popover-open')) place(pop, btns.find(b => b.offsetParent !== null)); });
  }
}

function applyFilter(pop, q) {
  q = q.trim().toLowerCase();
  let shown = 0;
  pop.querySelectorAll('[data-filter-text]').forEach(el => {
    const on = !q || el.dataset.filterText.includes(q) || el.textContent.toLowerCase().includes(q);
    el.hidden = !on;
    if (on) shown++;
  });
  const none = pop.querySelector('[data-pop-none]');
  if (none) none.hidden = shown > 0;
}
