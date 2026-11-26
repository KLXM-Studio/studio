/*
 * Auswahlfenster für Einträge (Core, Teil von admin.js) – ein Fenster für alle Verweise auf Einträge (Core\Data\Picker):
 * Verknüpfungsfelder („Zugehörige Termine“), Feldtyp „entrypick“ (Einträge im Slider, in „Highlights“).
 *
 *  - Kopf: Suche, Filter Zeitraum (Kalender: kommende, vergangene, alle, Monate), Status und Auswahlfelder der Tabelle (z. B. Thema);
 *    bei mehreren Tabellen (entrypick) eine Tabellenwahl.
 *  - Liste (role=listbox, aria-multiselectable): Vorschaubild, Titel, Datum bzw. Kategorie, „Entwurf“; Klick/Leertaste wählt,
 *    Einzelauswahl übernimmt sofort. „Weitere laden“ blättert. Vorschau rechts (bzw. unten) für den aktiven Eintrag.
 *  - Fuß: Anzahl, „Übernehmen“, „+ {Einzahl} neu“ (legt an und wählt).
 * Tastatur: ↑/↓ bewegen, Leertaste wählen, Enter übernehmen, Buchstaben springen in die Suche, Esc bricht ab.
 *
 * openEntryPicker({ tables: [{handle, name, singular, url, create?}], multiple, selected: [{table, id, title}] , title })
 *   → Promise: Liste [{table, id, title, sub, thumb, status}] in Auswahlreihenfolge oder null (abgebrochen)
 */
import { layerBox } from './_shadow.js';
import { t } from './_i18n.js';
import { esc, csrf } from './_html.js';

const d = document;
let dlg = null, st = null;
const q = s => dlg.querySelector(s);
const key = (table, id) => table + ':' + id;

function build() {
  const box = layerBox();
  dlg = box.querySelector('#cms-ep');
  if (dlg) return;
  box.insertAdjacentHTML('beforeend', `<dialog id="cms-ep" class="adm-dialog lp epk" aria-labelledby="cms-ep-t">
    <form method="dialog" class="lp__form" novalidate>
      <div class="adm-dialog__head"><h2 id="cms-ep-t"></h2>
        <button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-ep-cancel>${esc(t('Abbrechen'))}</button></div>
      <div class="epk__bar">
        <select class="epk__sel" data-ep-table aria-label="${esc(t('Tabelle'))}" hidden></select>
        <input type="search" class="lp__q" data-ep-q aria-controls="cms-ep-list" aria-label="${esc(t('Einträge suchen'))}" autocomplete="off" spellcheck="false">
      </div>
      <div class="epk__filters" data-ep-filters></div>
      <div class="epk__main">
        <div class="epk__col">
          <ul class="lp__list epk__list" id="cms-ep-list" role="listbox" tabindex="0" aria-labelledby="cms-ep-t" data-ep-list></ul>
          <button type="button" class="adm-btn adm-btn--small adm-btn--ghost epk__more" data-ep-more hidden>${esc(t('Weitere laden'))}</button>
        </div>
        <aside class="epk__prev" data-ep-prev aria-live="polite"><p class="lp__empty">${esc(t('Eintrag wählen, um die Vorschau zu sehen.'))}</p></aside>
      </div>
      <p class="lp__error" data-ep-error role="alert" hidden></p>
      <div class="adm-row lp__actions epk__actions">
        <button type="submit" class="adm-btn adm-btn--primary" data-ep-ok>${esc(t('Übernehmen'))}</button>
        <button type="button" class="adm-btn adm-btn--ghost" data-ep-new hidden></button>
        <span class="pgp__count" data-ep-count aria-live="polite"></span>
      </div>
      <p class="lp__keys f-help">${esc(t('Tastatur: ↑/↓ bewegen · Leertaste wählen · Enter übernehmen · Esc abbrechen'))}</p>
    </form></dialog>`);
  dlg = box.querySelector('#cms-ep');
  wire();
}

function wire() {
  dlg.querySelectorAll('[data-ep-cancel]').forEach(b => b.addEventListener('click', () => dlg.close('cancel')));
  dlg.addEventListener('close', () => {
    if (!st) return;
    const s = st; st = null;
    s.resolve(dlg.returnValue === 'ok' ? [...s.sel.values()] : null);
  });
  q('form').addEventListener('submit', e => { e.preventDefault(); dlg.close('ok'); });
  let tm = 0;
  q('[data-ep-q]').addEventListener('input', () => { clearTimeout(tm); tm = setTimeout(() => load(true), 200); });
  q('[data-ep-q]').addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); move(1); q('[data-ep-list]').focus(); }
    if (e.key === 'Enter') { e.preventDefault(); if (!st.multiple && st.active) toggle(st.active); else dlg.close('ok'); }
  });
  q('[data-ep-table]').addEventListener('change', e => { st.table = st.tables.find(x => x.handle === e.target.value) || st.tables[0]; st.filter = {}; st.when = ''; st.status = ''; load(true); });
  q('[data-ep-filters]').addEventListener('change', e => {
    const s = e.target.closest('select'); if (!s) return;
    if (s.dataset.k === 'when') st.when = s.value; else if (s.dataset.k === 'status') st.status = s.value; else st.filter[s.dataset.k] = s.value;
    load(true);
  });
  q('[data-ep-more]').addEventListener('click', () => { st.page++; load(false); });
  const list = q('[data-ep-list]');
  list.addEventListener('click', e => { const o = e.target.closest('[role=option]'); if (o) { activate(o); toggle(o); } });
  list.addEventListener('mouseover', e => { const o = e.target.closest('[role=option]'); if (o && o !== st.hover) { st.hover = o; preview(o); } });
  list.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); move(e.key === 'ArrowDown' ? 1 : -1); }
    else if (e.key === 'Home' || e.key === 'End') { e.preventDefault(); const all = opts(); if (all.length) activate(e.key === 'Home' ? all[0] : all[all.length - 1]); }
    else if (e.key === ' ') { e.preventDefault(); if (st.active) toggle(st.active); }
    else if (e.key === 'Enter') { e.preventDefault(); if (!st.multiple && st.active) toggle(st.active); else dlg.close('ok'); }
    else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) { const i = q('[data-ep-q]'); i.focus(); i.value += e.key; i.dispatchEvent(new Event('input')); e.preventDefault(); }
  });
  q('[data-ep-new]').addEventListener('click', create);
}

const opts = () => [...q('[data-ep-list]').querySelectorAll('[role=option]')];
function activate(o) {
  opts().forEach(x => x.classList.toggle('is-active', x === o));
  st.active = o;
  q('[data-ep-list]').setAttribute('aria-activedescendant', o.id);
  o.scrollIntoView({ block: 'nearest' });
  preview(o);
}
function move(dir) {
  const all = opts(); if (!all.length) return;
  const i = all.indexOf(st.active);
  activate(all[Math.max(0, Math.min(all.length - 1, i < 0 ? 0 : i + dir))]);
}
function toggle(o) {
  const it = st.items.get(o.dataset.key); if (!it) return;
  if (!st.multiple) { st.sel.clear(); st.sel.set(o.dataset.key, it); dlg.close('ok'); return; }
  if (st.sel.has(o.dataset.key)) st.sel.delete(o.dataset.key); else st.sel.set(o.dataset.key, it);
  o.setAttribute('aria-selected', st.sel.has(o.dataset.key) ? 'true' : 'false');
  count();
}
function count() {
  const n = st.sel.size;
  q('[data-ep-count]').textContent = st.multiple ? (n ? t('{n} ausgewählt', { n }) : t('Nichts ausgewählt')) : '';
}
function err(msg) { const p = q('[data-ep-error]'); p.textContent = msg || ''; p.hidden = !msg; }

function filtersHtml(res) {
  const sel = (k, label, options, cur) => `<label class="epk__f"><span>${esc(label)}</span><select data-k="${esc(k)}">`
    + options.map(([v, l]) => `<option value="${esc(v)}"${v === cur ? ' selected' : ''}>${esc(l)}</option>`).join('') + '</select></label>';
  let h = '';
  if (res.calendar) {
    h += sel('when', t('Zeitraum'), [['upcoming', t('Kommende')], ['past', t('Vergangene')], ['all', t('Alle')], ...Object.entries(res.months || {})], st.when || 'upcoming');
  }
  h += sel('status', t('Status'), [['', t('Alle')], ['published', t('Online')], ['draft', t('Entwurf')]], st.status || '');
  for (const f of res.filters || []) h += sel(f.name, f.label, [['', t('Alle')], ...Object.entries(f.options)], st.filter[f.name] || '');
  return h;
}

function rowHtml(it) {
  const k = key(it.table, it.id);
  return `<li role="option" id="cms-ep-${esc(k.replace(':', '-'))}" class="lp__opt epk__opt${it.past ? ' is-past' : ''}" data-key="${esc(k)}" aria-selected="${st.sel.has(k) ? 'true' : 'false'}">`
    + (st.multiple ? '<span class="pgp__cb epk__cb" aria-hidden="true"></span>' : '')
    + (it.thumb ? `<img class="lp__thumb" src="${esc(it.thumb)}" alt="" loading="lazy">` : '')
    + `<span class="lp__txt"><span class="lp__label">${esc(it.title)}</span>${it.sub ? `<span class="lp__meta">${esc(it.sub)}</span>` : ''}</span>`
    + (it.status === 'draft' ? `<span class="lp__badge lp__badge--draft">${esc(t('Entwurf'))}</span>` : '') + '</li>';
}

async function load(reset) {
  const s = st, tb = s.table;
  if (reset) { s.page = 1; q('[data-ep-list]').innerHTML = ''; s.active = null; }
  const p = new URLSearchParams({ q: q('[data-ep-q]').value.trim(), status: s.status, page: String(s.page) });
  if (s.when) p.set('when', s.when);
  for (const [k, v] of Object.entries(s.filter)) if (v) p.set(`f[${k}]`, v);
  const seq = ++s.seq;
  q('[data-ep-list]').setAttribute('aria-busy', 'true');
  try {
    const r = await fetch(tb.url + '?' + p, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const res = await r.json();
    if (st !== s || seq !== s.seq) return;
    if (!res.ok) throw new Error(res.error || r.statusText);
    if (reset) q('[data-ep-filters]').innerHTML = filtersHtml(res);
    const list = q('[data-ep-list]');
    res.items.forEach(it => { it.table = tb.handle; s.items.set(key(tb.handle, it.id), it); });
    list.insertAdjacentHTML('beforeend', res.items.map(rowHtml).join(''));
    if (!opts().length) list.innerHTML = `<li class="lp__empty" role="presentation">${esc(q('[data-ep-q]').value.trim() ? t('Keine Treffer.') : t('Keine Einträge.'))}</li>`;
    q('[data-ep-more]').hidden = res.page >= res.pages;
    if (reset && !s.active && opts()[0]) preview(opts()[0]);
    err('');
  } catch (e) {
    if (st === s) err(t('Einträge konnten nicht geladen werden.') + ' ' + (e.message || ''));
  } finally {
    if (st === s) q('[data-ep-list]').removeAttribute('aria-busy');
  }
}

async function preview(o) {
  const s = st, it = s.items.get(o.dataset.key); if (!it) return;
  const tb = s.tables.find(x => x.handle === it.table) || s.table;
  const box = q('[data-ep-prev]');
  let p = s.prev.get(o.dataset.key);
  if (!p) {
    try {
      const r = await fetch(tb.url + '/' + it.id, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      p = await r.json();
    } catch { p = null; }
    if (!p?.ok || st !== s) return;
    s.prev.set(o.dataset.key, p);
  }
  if (s.active && s.active !== o && s.hover !== o) return;
  box.innerHTML = (p.image ? `<img class="epk__img" src="${esc(p.image)}" alt="">` : '')
    + `<h3 class="epk__pt">${esc(p.title)}</h3>`
    + `<p class="epk__pm">${esc([p.sub, p.status_label].filter(Boolean).join(' · '))}</p>`
    + (p.text ? `<p class="epk__px">${esc(p.text)}</p>` : '')
    + `<p class="epk__pl"><a href="${esc(p.edit)}" target="_blank" rel="noopener">${esc(t('Bearbeiten'))}</a>`
    + (p.view ? ` · <a href="${esc(p.view)}" target="_blank" rel="noopener">${esc(t('Ansehen'))}</a>` : '') + '</p>';
}

async function create() {
  const tb = st.table; if (!tb.create) return;
  const title = q('[data-ep-q]').value.trim() || prompt(t('Neu anlegen ({singular}):', { singular: tb.singular }));
  if (!title?.trim()) return;
  try {
    const r = await fetch(tb.create, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify({ title: title.trim() }) });
    const res = await r.json();
    if (!res.ok) { err(res.error || t('Anlegen nicht möglich.')); return; }
    const it = { table: tb.handle, id: res.id, title: res.title, sub: '', thumb: '', status: 'draft', created: true };
    st.items.set(key(tb.handle, res.id), it);
    if (!st.multiple) st.sel.clear();
    st.sel.set(key(tb.handle, res.id), it);
    if (!st.multiple) { dlg.close('ok'); return; }
    q('[data-ep-q]').value = '';
    count();
    await load(true);
  } catch (e) { err(e.message); }
}

export function openEntryPicker(o = {}) {
  build();
  if (st) { const s = st; st = null; s.resolve(null); }
  const tables = (o.tables || []).filter(x => x?.url);
  if (!tables.length) return Promise.resolve(null);
  return new Promise(resolve => {
    st = { resolve, tables, table: tables.find(x => x.handle === o.table) || tables[0], multiple: o.multiple !== false, sel: new Map(), items: new Map(), prev: new Map(),
      page: 1, seq: 0, filter: {}, when: '', status: '', active: null, hover: null };
    for (const it of o.selected || []) { const k = key(it.table, it.id); st.sel.set(k, it); st.items.set(k, it); }
    q('#cms-ep-t').textContent = o.title || (st.multiple ? t('{name} auswählen', { name: st.table.name }) : t('{name} auswählen', { name: st.table.singular }));
    const ts = q('[data-ep-table]');
    ts.hidden = tables.length < 2;
    ts.innerHTML = tables.map(x => `<option value="${esc(x.handle)}"${x === st.table ? ' selected' : ''}>${esc(x.name)}</option>`).join('');
    const nb = q('[data-ep-new]');
    nb.hidden = !st.table.create;
    nb.textContent = '+ ' + t('{singular} neu', { singular: st.table.singular });
    q('[data-ep-q]').value = '';
    q('[data-ep-q]').placeholder = t('Suchen …');
    q('[data-ep-ok]').hidden = !st.multiple;
    q('[data-ep-prev]').innerHTML = `<p class="lp__empty">${esc(t('Eintrag wählen, um die Vorschau zu sehen.'))}</p>`;
    count();
    err('');
    dlg.returnValue = '';
    dlg.showModal();
    q('[data-ep-q]').focus();
    load(true);
  });
}

/** Daten zu gewählten IDs nachladen (Chips: Datum, Bild) → Map id → item */
export async function entryInfo(url, ids) {
  if (!ids.length) return new Map();
  try {
    const r = await fetch(url + '?ids=' + ids.join(','), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const res = await r.json();
    return new Map((res.items || []).map(it => [String(it.id), it]));
  } catch { return new Map(); }
}

// ------------------------------------------------------------------ Feldtyp „entrypick“ (Core\Data\EntryPick::render)
function chipHtml(it, name) {
  const v = key(it.table, it.id), meta = [it.table_name, it.sub, it.status === 'draft' ? t('Entwurf') : ''].filter(Boolean).join(' · ');
  return `<li class="pgf__chip relp__chip" data-value="${esc(v)}" draggable="true">`
    + `<button type="button" class="relp__grip" data-ep-move aria-label="${esc(t('„{label}“ verschieben (Alt+Pfeil hoch/runter)', { label: it.title }))}" title="${esc(t('Ziehen oder Alt+Pfeil hoch/runter'))}">⋮⋮</button>`
    + (it.thumb ? `<img class="relp__thumb" src="${esc(it.thumb)}" alt="" loading="lazy">` : '')
    + `<span class="pgf__txt"><span class="pgf__label">${esc(it.title)}</span>${meta ? `<span class="pgf__trail">${esc(meta)}</span>` : ''}</span>`
    + `<button type="button" class="pgf__x" data-ep-remove aria-label="${esc(t('„{label}“ entfernen', { label: it.title }))}" title="${esc(t('Entfernen'))}">×</button>`
    + `<input type="hidden" name="${esc(name)}[]" value="${esc(v)}"></li>`;
}

/** Chips einer Liste ziehbar machen (Maus) und per Alt+Pfeil verschieben (Tastatur); done() nach jeder Änderung */
export function sortableChips(list, done, grip = '[data-ep-move]') {
  let drag = null;
  list.addEventListener('dragstart', e => { drag = e.target.closest('li'); if (!drag) return; drag.classList.add('is-drag'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', drag.dataset.value || drag.dataset.id || ''); });
  list.addEventListener('dragover', e => {
    if (!drag) return; e.preventDefault();
    const over = e.target.closest('li'); if (!over || over === drag || over.parentNode !== list) return;
    const r = over.getBoundingClientRect();
    over[(e.clientY - r.top) > r.height / 2 ? 'after' : 'before'](drag);
  });
  list.addEventListener('drop', e => { if (drag) e.preventDefault(); });
  list.addEventListener('dragend', () => { if (!drag) return; drag.classList.remove('is-drag'); drag = null; done(); });
  list.addEventListener('keydown', e => {
    const g = e.target.closest(grip); if (!g || !e.altKey || !['ArrowUp', 'ArrowDown'].includes(e.key)) return;
    e.preventDefault();
    const li = g.closest('li'), sib = e.key === 'ArrowUp' ? li.previousElementSibling : li.nextElementSibling;
    if (!sib) return;
    sib[e.key === 'ArrowUp' ? 'before' : 'after'](li);
    g.focus();
    done();
  });
}

export function initEntryPickFields(scope = d) {
  scope.querySelectorAll('[data-entrypick]').forEach(box => {
    if (box._init) return; box._init = true;
    let tables = [];
    try { tables = JSON.parse(box.dataset.tables || '[]'); } catch {}
    const chips = box.querySelector('[data-ep-chips]');
    const changed = () => {
      const n = chips.children.length;
      box.querySelector('[data-ep-empty]').hidden = n > 0;
      box.querySelector('[data-ep-count]').textContent = n ? t('{n} ausgewählt', { n }) : '';
      box.dispatchEvent(new Event('input', { bubbles: true }));
      box.dispatchEvent(new Event('change', { bubbles: true }));
    };
    sortableChips(chips, changed);
    box.addEventListener('click', async e => {
      const rm = e.target.closest('[data-ep-remove]');
      if (rm) {
        const li = rm.closest('li'), next = (li.nextElementSibling || li.previousElementSibling)?.querySelector('[data-ep-remove]');
        li.remove(); changed();
        (next || box.querySelector('[data-ep-pick]'))?.focus();
        return;
      }
      if (!e.target.closest('[data-ep-pick]')) return;
      const selected = [...chips.children].map(li => {
        const [table, id] = li.dataset.value.split(':');
        return { table, id, title: li.querySelector('.pgf__label')?.textContent || li.dataset.value, _html: li.outerHTML };
      });
      const r = await openEntryPicker({ tables, multiple: true, selected, title: box.querySelector('legend')?.textContent?.replace(/\s*\((optional|optional:?[^)]*)\)\s*$/i, '').trim() });
      box.querySelector('[data-ep-pick]').focus();
      if (!r) return;
      const max = +box.dataset.max || 12;
      chips.innerHTML = r.slice(0, max).map(it => it._html || chipHtml({ ...it, table_name: tables.find(x => x.handle === it.table)?.name || '' }, box.dataset.name)).join('');
      changed();
    });
  });
}
