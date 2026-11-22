/*
 * Zeilenmenü – eine Bedienung für alle Listen der Verwaltung (Core\RowMenu, Entwicklerhandbuch → Oberfläche → Zeilenmenü):
 *
 * Vom Server (RowMenu::render/pop): Knopf „⋯“ mit popovertarget + <div class="rm-pop" popover role="menu"> – ohne Skript bedienbar.
 * Hier: an den Knopf bzw. Mauszeiger setzen, Telefon als Blatt von unten, Pfeiltasten/Pos1/Ende/Buchstaben, Tab schließt,
 * Rechtsklick und Umschalt+F10/Menütaste in [data-rowmenu="id"] öffnen dasselbe Menü, „Link kopieren“ in die Zwischenablage.
 *
 * Aus Skripten (Seitenbaum, Medien): openMenu({ items, title, x, y, anchor, root, back }) baut dasselbe Menü;
 * items: { key, label, icon?, group?, run?|href?, danger?, keys?, title?, sep? } – Reihenfolge, Symbole und Gruppen wie ORDER/ICONS/GROUPS
 * (wie im PHP); mehr als COLS Punkte → Gruppen mit Überschrift in zwei Spalten (Telefon: Blatt, einspaltig), ←/→ wechseln die Spalte.
 *
 * Mehrfachauswahl ([data-rm-select] mit [data-rm-check], [data-rm-checkall], Leiste [data-rm-bar] aus RowMenu::bar):
 * Zähler, Leiste ab einer Auswahl, Rechtsklick auf eine gewählte Zeile (mehr als eine gewählt) → Aktionen der Leiste als Menü.
 */
import { ico } from './_icons.js';
import { esc } from './_html.js';
import { t } from './_i18n.js';
import { toast } from './_toast.js';

export const ORDER = ['open', 'settings', 'view', 'preview', 'child', 'duplicate', 'move', 'menu', 'publish', 'versions', 'copy', 'export', 'task', 'ext', 'delete'];
export const ICONS = {
  open: 'pencil-simple', settings: 'gear-six', view: 'arrow-square-out', preview: 'eye', child: 'file-plus', duplicate: 'copy', move: 'arrows-down-up',
  menu: 'list', publish: 'globe', unpublish: 'eye-slash', versions: 'clock-counter-clockwise', copy: 'link', export: 'export', task: 'list-checks',
  ext: 'puzzle-piece', delete: 'trash',
};
/** Gruppe je Aktion (wie RowMenu::GROUPS), Reihenfolge der Gruppen, Schwelle für zwei Spalten (RowMenu::COLS) */
export const GROUPS = {
  open: 'edit', settings: 'edit', view: 'edit', preview: 'edit', child: 'create', duplicate: 'create', move: 'create',
  menu: 'visibility', publish: 'visibility', unpublish: 'visibility', versions: 'share', copy: 'share', export: 'share', task: 'share',
  ext: 'ext', delete: 'danger',
};
export const GROUP_ORDER = ['edit', 'create', 'visibility', 'share', 'ext', 'danger'];
export const COLS = 8;
const groupLabel = g => ({ edit: t('Bearbeiten'), create: t('Anlegen & Ordnen'), visibility: t('Sichtbarkeit'), share: t('Teilen & Verlauf'), ext: t('Erweiterungen'), danger: t('Löschen') })[g] || t('Erweiterungen');
const SHEET = '(max-width: 640px)';
const ITEM = '.rm-item:not([aria-disabled="true"])';

/** Punkte nach ORDER sortieren (stabil), unbekannte Schlüssel wie Erweiterungen vor „Löschen“ */
export function sortItems(items) {
  const rank = k => { const i = ORDER.indexOf(k || 'ext'); return i < 0 ? ORDER.indexOf('ext') : i; };
  return items.filter(Boolean).map((it, n) => ({ it, n })).sort((a, b) => rank(a.it.key) - rank(b.it.key) || a.n - b.n).map(x => x.it);
}

/** Gruppe eines Punkts: it.group, sonst nach Schlüssel; gefährliche Punkte immer „danger“ */
export function groupOf(it) {
  if (it.danger || it.key === 'delete') return 'danger';
  if (it.group && it.group !== 'danger' && GROUP_ORDER.includes(it.group)) return it.group;
  return GROUPS[it.key || 'ext'] || 'ext';
}

/** Sortierte Punkte in Gruppen: [{ group, items: [[index, item], …] }] (Index = Stelle in der sortierten Liste) */
export function groupItems(sorted) {
  const by = {};
  sorted.forEach((it, i) => (by[groupOf(it)] ||= []).push([i, it]));
  return GROUP_ORDER.filter(g => by[g]).map(g => ({ group: g, items: by[g] }));
}

/** Gruppen auf zwei Spalten verteilen (wie RowMenu::split): Gruppen bleiben ganz, vordere links, links darf höher sein */
export function splitGroups(groups) {
  if (groups.length < 2) return [groups];
  const w = groups.map(g => g.items.length + 1), total = w.reduce((a, b) => a + b, 0);
  let best = 1, diff = Infinity, left = 0;
  for (let k = 1; k < groups.length; k++) {
    left += w[k - 1];
    const d = Math.abs(total - 2 * left) + (left < total - left ? 1 : 0);
    if (d < diff) { diff = d; best = k; }
  }
  return [groups.slice(0, best), groups.slice(best)];
}

/** Menü platzieren: Blatt auf dem Telefon, sonst am Punkt (Rechtsklick) bzw. rechtsbündig unter/über dem Knopf */
function place(pop, at) {
  pop.dataset.placed = '';
  if (matchMedia(SHEET).matches) { pop.dataset.sheet = ''; pop.style.removeProperty('top'); pop.style.removeProperty('left'); return; }
  delete pop.dataset.sheet;
  const w = pop.offsetWidth, h = pop.offsetHeight;
  let top, left;
  if (at?.x != null) {   // am Mauszeiger: rechts/unten davon, sonst links/oben – nie aus dem Fenster
    left = at.x + w > innerWidth - 8 ? at.x - w : at.x;
    top = at.y + h > innerHeight - 8 ? Math.max(8, Math.min(at.y - h, innerHeight - 8 - h)) : at.y;
  }
  else {
    const r = (at?.anchor || pop._btn)?.getBoundingClientRect();
    if (!r) return;
    top = r.bottom + 4 + h > innerHeight - 8 ? Math.max(8, r.top - 4 - h) : r.bottom + 4;
    left = r.right - w;
  }
  pop.style.setProperty('top', Math.round(top) + 'px');
  pop.style.setProperty('left', Math.round(Math.max(8, Math.min(left, innerWidth - w - 8))) + 'px');
}

const items = pop => [...pop.querySelectorAll(ITEM)];
let seq = 0;
const activeIn = pop => pop.getRootNode().activeElement;

/** ←/→ in zweispaltigen Menüs: Punkt der anderen Spalte auf derselben Höhe (sonst nichts) */
function sideways(pop, cur, dir) {
  const col = cur?.closest('.rm-col');
  const cols = [...pop.querySelectorAll('.rm-col')].filter(c => c.offsetParent && c.querySelector(ITEM));
  if (!col || cols.length < 2) return null;
  const a = cols[cols.indexOf(col) + dir];
  if (!a || a.offsetLeft === col.offsetLeft) return null;   // Blatt/schmal: Spalten untereinander
  const y = cur.getBoundingClientRect().top;
  return [...a.querySelectorAll(ITEM)].reduce((best, b) => Math.abs(b.getBoundingClientRect().top - y) < Math.abs(best.getBoundingClientRect().top - y) ? b : best);
}

/** Tastatur im Menü: ↑/↓ (Lesereihenfolge: linke Spalte, rechte Spalte, Löschen), ←/→ Spalte wechseln, Pos1/Ende, Anfangsbuchstabe, Tab schließt */
function keys(pop, e) {
  const list = items(pop), cur = activeIn(pop), i = list.indexOf(cur);
  const go = n => { e.preventDefault(); list[(n + list.length) % list.length]?.focus(); };
  if (e.key === 'ArrowDown') go(i + 1);
  else if (e.key === 'ArrowUp') go(i < 0 ? -1 : i - 1);
  else if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
    const rtl = getComputedStyle(pop).direction === 'rtl';
    const hit = sideways(pop, cur, (e.key === 'ArrowRight') !== rtl ? 1 : -1);
    if (hit) { e.preventDefault(); hit.focus(); }
  }
  else if (e.key === 'Home') go(0);
  else if (e.key === 'End') go(-1);
  else if (e.key === 'Tab') { e.preventDefault(); close(pop); }
  else if (e.key === 'Escape' && pop._manual) { e.preventDefault(); close(pop); }
  else if (e.key.length === 1 && /\S/.test(e.key) && !e.ctrlKey && !e.metaKey && !e.altKey) {
    const k = e.key.toLowerCase(), from = i + 1;
    const hit = [...list.slice(from), ...list.slice(0, from)].find(b => b.textContent.trim().toLowerCase().startsWith(k));
    if (hit) { e.preventDefault(); hit.focus(); }
  }
}

function close(pop) {
  try { pop.hidePopover(); } catch { /* schon zu */ }
}


/**
 * Kontextmenü am Mauszeiger: als „manual“-Popover öffnen. Bei „auto“ schließt der Browser es beim Loslassen der rechten
 * Maustaste wieder (der Klick begann vor dem Menü, also „außerhalb“). Geschlossen wird dann selbst: Klick daneben, Escape,
 * Scrollen, Fensterwechsel.
 */
function asContext(pop) {
  if (pop.getAttribute('popover') !== 'manual') { pop._wasAuto = true; pop.setAttribute('popover', 'manual'); }
  pop._manual = true;
  const away = e => { if (!pop.contains(e.target)) close(pop); };
  const esc = e => { if (e.key === 'Escape') { e.preventDefault(); close(pop); } };
  const off = () => close(pop);
  setTimeout(() => {   // erst nach dem Loslassen der rechten Taste
    document.addEventListener('pointerdown', away, true);
    document.addEventListener('keydown', esc, true);
    window.addEventListener('scroll', off, { capture: true, once: true });
    window.addEventListener('blur', off, { once: true });
  });
  pop.addEventListener('toggle', function done(e) {
    if (e.newState !== 'closed') return;
    pop.removeEventListener('toggle', done);
    document.removeEventListener('pointerdown', away, true);
    document.removeEventListener('keydown', esc, true);
    window.removeEventListener('scroll', off, { capture: true });
    window.removeEventListener('blur', off);
    pop._manual = false;
    if (pop._wasAuto) { pop._wasAuto = false; pop.setAttribute('popover', 'auto'); }
  });
}

/** Menü vom Server öffnen – an einer Stelle (Rechtsklick) oder am Knopf */
export function openPop(pop, at = null) {
  if (!pop) return;
  pop._at = at;
  if (pop.matches(':popover-open')) { place(pop, at); items(pop)[0]?.focus(); return; }
  if (at && at.x != null) asContext(pop);
  try { pop.showPopover(); } catch { /* kein Popover-API: Server-Fallback bleibt */ }
}

/**
 * Menü aus Skript bauen (Seitenbaum, Medien) – dieselbe Gestalt wie RowMenu::pop.
 * root: Element für das Menü (z. B. <dialog> oder Schatten-Wurzel), back: Fokus danach (Standard: vorher fokussiertes Element)
 */
export function openMenu({ items: list, title = '', x = null, y = null, anchor = null, root = null, back = null }) {
  const host = root || document.body;
  host.querySelectorAll(':scope > .rm-pop[data-rm-js]').forEach(p => p.remove());
  const pop = document.createElement('div');
  pop.className = 'rm-pop'; pop.dataset.rmJs = ''; pop.setAttribute('role', 'menu'); pop.setAttribute('popover', 'auto');
  if (title) pop.setAttribute('aria-label', title);
  const sorted = sortItems(list.filter(it => it && it.label));
  const uid = 'rm' + (++seq);
  const item = ([i, it], danger, first) => {
    const inner = `${it.icon === false ? '' : ico(it.icon || ICONS[it.key] || 'circle')}<span>${esc(it.label)}</span>${it.keys ? `<kbd aria-hidden="true">${esc(it.keys)}</kbd>` : ''}`;
    const attrs = ` class="rm-item${danger ? ' is-danger' : ''}" role="menuitem" data-i="${i}" data-rm-key="${esc(it.key || 'ext')}"${it.title ? ` title="${esc(it.title)}"` : ''}${it.aria ? ` aria-keyshortcuts="${esc(it.aria)}"` : ''}${it.disabled ? ' aria-disabled="true"' : ''}`;
    return (it.sep && !first ? '<hr role="separator">' : '')
      + (it.href && !it.run ? `<a href="${esc(it.href)}"${attrs}${it.target ? ` target="${esc(it.target)}" rel="noopener"` : ''}>${inner}</a>` : `<button type="button"${attrs}>${inner}</button>`);
  };
  const groups = groupItems(sorted);
  const danger = groups.at(-1)?.group === 'danger' ? groups.pop() : null;
  const grouped = sorted.length > COLS;
  const cols = grouped ? splitGroups(groups) : [groups];
  const grp = g => `<div class="rm-grp" role="group" aria-labelledby="${uid}-g-${g.group}"><p class="rm-grp__h" id="${uid}-g-${g.group}">${esc(groupLabel(g.group))}</p>${g.items.map((x, n) => item(x, false, n === 0)).join('')}</div>`;
  let html = title ? `<p class="rm-pop__title" aria-hidden="true">${esc(title)}</p>` : '';
  html += '<div class="rm-cols" role="none">' + cols.filter(c => c.length).map(c => `<div class="rm-col" role="none">${c.map(grp).join('<hr role="separator">')}</div>`).join('');
  if (danger) html += (groups.length ? '<hr role="separator" class="rm-sep-danger">' : '') + danger.items.map(x => item(x, true, true)).join('');
  html += '</div>';
  if (grouped) pop.classList.add('rm-pop--grouped');
  if (cols.length > 1) pop.classList.add('rm-pop--cols');
  pop.innerHTML = html;
  const ret = back || (host.getRootNode?.() || document).activeElement || document.activeElement;
  pop._manual = false;
  pop.addEventListener('click', e => {
    const b = e.target.closest('[data-i]'); if (!b || b.getAttribute('aria-disabled') === 'true') return;
    const it = sorted[+b.dataset.i];
    if (it.run) { e.preventDefault(); close(pop); it.run(); } else close(pop);
  });
  pop.addEventListener('keydown', e => { e.stopPropagation(); keys(pop, e); });   // auch in Schatten-Wurzeln
  pop.addEventListener('toggle', e => {
    if (e.newState === 'open') { place(pop, pop._at); items(pop)[0]?.focus(); return; }
    setTimeout(() => { pop.remove(); if (ret?.isConnected && !pop.contains(document.activeElement)) ret.focus?.({ preventScroll: true }); });
  });
  host.append(pop);
  pop._at = x != null ? { x, y } : { anchor };
  if (x != null) asContext(pop);
  try { pop.showPopover(); } catch { pop.classList.add('is-open'); place(pop, pop._at); items(pop)[0]?.focus(); }
  return pop;
}

// ------------------------------------------------------------------ Mehrfachauswahl
function selectionOf(box) {
  return [...box.querySelectorAll('[data-rm-check]')].filter(c => c.checked);
}
export function syncSelection(box) {
  if (!box) return;
  const n = selectionOf(box).length, all = box.querySelectorAll('[data-rm-check]').length;
  const bar = box.querySelector('[data-rm-bar]');
  if (bar && !bar.hasAttribute('data-rm-always')) bar.hidden = !n;
  box.querySelectorAll('[data-rm-selcount]').forEach(el => { el.textContent = n ? (n === 1 ? box.dataset.rmT1 || t('1 ausgewählt') : (box.dataset.rmTn || t('{n} ausgewählt')).replace('{n}', n)) : ''; });
  box.querySelectorAll('[data-rm-checkall]').forEach(c => { c.checked = n > 0 && n === all; c.indeterminate = n > 0 && n < all; });
  box.querySelectorAll('[data-rm-check]').forEach(c => c.closest('[data-rowmenu], tr, li')?.classList.toggle('is-checked', c.checked));
}

/** Aktionen der Leiste als Menü (Rechtsklick auf eine gewählte Zeile, wenn mehr als eine gewählt ist) */
function bulkMenu(box, at) {
  const bar = box.querySelector('[data-rm-bar]');
  const btns = bar ? [...bar.querySelectorAll('[data-rm-bulk]')].filter(b => !b.disabled) : [];
  if (!btns.length) return false;
  const n = selectionOf(box).length;
  openMenu({
    title: (box.dataset.rmTn || t('{n} ausgewählt')).replace('{n}', n), ...at,
    items: btns.map(b => ({ key: b.dataset.rmKey, label: b.textContent.trim(), icon: b.querySelector('use')?.getAttribute('href')?.split('#i-')[1], run: () => b.click() })),
  });
  return true;
}

let done = false;
export function initRowMenus() {
  if (done) return;
  done = true;
  const d = document;
  // Server-Menüs: beim Öffnen platzieren und ersten Punkt fokussieren
  d.addEventListener('toggle', e => {
    const pop = e.target;
    if (!(pop instanceof HTMLElement) || !pop.matches('.rm-pop')) return;
    if (e.newState !== 'open') { pop._at = null; return; }
    pop._btn = d.querySelector(`[popovertarget="${CSS.escape(pop.id)}"]`) || pop._btn;
    place(pop, pop._at);
    items(pop)[0]?.focus();
  }, true);
  // Tastatur in allen Menüs (auch in Schatten-Wurzeln, solange das Ereignis herausläuft)
  d.addEventListener('keydown', e => {
    const inMenu = e.composedPath().find(n => n instanceof HTMLElement && n.classList?.contains('rm-pop'));
    if (inMenu) { keys(inMenu, e); return; }
    // Umschalt+F10 / Menütaste auf einer Zeile
    if (e.key !== 'ContextMenu' && !(e.shiftKey && e.key === 'F10')) return;
    const host = e.target.closest?.('[data-rowmenu]');
    if (!host) return;
    e.preventDefault();
    const pop = d.getElementById(host.dataset.rowmenu);
    const btn = d.querySelector(`[popovertarget="${CSS.escape(host.dataset.rowmenu)}"]`);
    const r = (btn?.offsetParent ? btn : e.target).getBoundingClientRect();
    openPop(pop, btn?.offsetParent ? { anchor: btn } : { x: r.left + 24, y: r.bottom });
  });
  // Rechtsklick auf Zeile/Karte/Knoten: dasselbe Menü am Mauszeiger (Felder, Textauswahl und Umschalt behalten das Browsermenü)
  d.addEventListener('contextmenu', e => {
    const host = e.target.closest?.('[data-rowmenu]');
    if (!host || e.shiftKey || e.target.closest('input:not([type=checkbox]),textarea,select,[contenteditable]') || String(getSelection?.() || '') !== '') return;
    const box = host.closest('[data-rm-select]');
    const check = host.querySelector('[data-rm-check]');
    e.preventDefault();
    if (box && check?.checked && selectionOf(box).length > 1 && bulkMenu(box, { x: e.clientX, y: e.clientY })) return;
    openPop(d.getElementById(host.dataset.rowmenu), { x: e.clientX, y: e.clientY });
  });
  // Klick auf einen Punkt: Link kopieren; JS-Aktionen schließen das Menü
  d.addEventListener('click', e => {
    const c = e.target.closest?.('.rm-pop [data-rm-copy]');
    if (c) {
      e.preventDefault();
      const u = c.dataset.rmCopy;
      (navigator.clipboard ? navigator.clipboard.writeText(u) : Promise.reject()).then(() => toast(t('Link kopiert'), 'ok'), () => prompt(t('Link kopieren'), u));
      close(c.closest('.rm-pop'));
      return;
    }
    const b = e.target.closest?.('.rm-pop:not([data-rm-js]) button.rm-item[type=button]');
    if (b) setTimeout(() => close(b.closest('.rm-pop')));
  });
  // Mehrfachauswahl
  d.addEventListener('change', e => {
    const box = e.target.closest?.('[data-rm-select]');
    if (!box) return;
    if (e.target.matches('[data-rm-checkall]')) box.querySelectorAll('[data-rm-check]').forEach(c => { c.checked = e.target.checked; });
    if (e.target.matches('[data-rm-check],[data-rm-checkall]')) syncSelection(box);
  });
  d.querySelectorAll('[data-rm-select]').forEach(syncSelection);
  addEventListener('resize', () => d.querySelectorAll('.rm-pop:popover-open').forEach(p => place(p, p._at)));
}
