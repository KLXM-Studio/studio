/*
 * PROTOTYP Variante B: Suche direkt in der Kopfleiste (app/Admin/views/_topbar.php) – nach dem Vorbild der Quickbar für REDAXO 6,
 * auf Basis derselben Suche wie die Befehlspalette (/admin/api/search, Core-Rechte). Quelle links wählen oder per Kürzel
 * (p: d: m: k: t: f: >), ohne Eingabe Favoriten + zuletzt geöffnet (Browser) + was der Server ohne Eingabe liefert.
 * ↑↓ wählen · ↵ öffnen · ⌘↵ neuer Tab · → / ← Zusatzaktion · ⌘D Favorit · esc leeren, dann schließen.
 * Dazu: Kopfleiste blendet beim Herunterscrollen aus und beim Hochscrollen ein (nie bei offenem Menü/Fokus in der Leiste).
 */
import { t } from './_i18n.js';
import { ico } from './_icons.js';
import { esc, csrf } from './_html.js';

const d = document;
const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
const RECENT = 'adm-qb-recent';
const SCOPES = { 'p:': 'p', 'd:': 'd', 'm:': 'm', 'k:': 'k', 't:': 't', 'f:': 'f', '>': 'c' };
const LABELS = { '': t('Alles'), 'p:': t('Seiten'), 'd:': t('Daten'), 'm:': t('Medien'), 'k:': t('Kontakte'), 't:': t('Termine'), 'f:': t('Favoriten'), '>': t('Befehle') };

// Gruppe der Suche → Quelle (Prototyp: über die Überschrift; endgültig liefert der Server die Quelle je Gruppe)
function scopeOf(label) {
  const l = label.toLowerCase();
  if (l === t('Favoriten').toLowerCase()) return 'f';
  if (l === t('Aktionen').toLowerCase()) return 'c';
  if (l === t('Seiten').toLowerCase()) return 'p';
  if (l === t('Medien').toLowerCase()) return 'm';
  if (/kontakt/.test(l)) return 'k';
  if (/termin|kalender|veranstalt/.test(l)) return 't';
  if (/einstellung|hilfe|hinweis/.test(l)) return 'c';
  return 'd';
}

const recent = {
  get() { try { return JSON.parse(localStorage.getItem(RECENT) || '[]'); } catch { return []; } },
  add(it) { try { const l = recent.get().filter(x => x.url !== it.url); l.unshift({ title: it.title, sub: it.sub || '', url: it.url, ico: it.ico || '' }); localStorage.setItem(RECENT, JSON.stringify(l.slice(0, 6))); } catch { /* gesperrt */ } },
};

export function initQuickbar() {
  const box = d.querySelector('[data-qb]');
  const bar = d.querySelector('[data-tb]');
  if (bar) headroom(bar);
  if (!box) return;
  const input = box.querySelector('[data-qb-input]'), panel = box.querySelector('[data-qb-panel]'), list = panel.querySelector('.adm-qb__list');
  const scopeLabel = box.querySelector('[data-qb-scope-label]');
  const endpoint = box.dataset.searchEndpoint, favEndpoint = box.dataset.favEndpoint;
  let items = [], active = 0, act = 0, timer, ctrl;

  const parse = () => {
    const v = input.value;
    const m = v.match(/^\s*(>|[pdmktf]:)\s*/i);
    return m ? { pre: m[1].toLowerCase(), q: v.slice(m[0].length) } : { pre: '', q: v };
  };
  const syncScope = () => {
    const { pre } = parse();
    scopeLabel.textContent = LABELS[pre] || LABELS[''];
    d.querySelectorAll('[data-qb-scope]').forEach(b => b.setAttribute('aria-checked', String(b.dataset.qbScope === pre)));
  };
  const open = on => { panel.hidden = !on; input.setAttribute('aria-expanded', String(on)); bar?.classList.remove('is-hidden'); };

  function hl(text, q) {
    let h = esc(text || '');
    for (const w of q.trim().split(/\s+/).filter(Boolean)) h = h.replace(new RegExp('(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig'), '<mark>$1</mark>');
    return h;
  }

  async function load() {
    const { pre, q } = parse();
    const want = SCOPES[pre] || '';
    ctrl?.abort(); ctrl = new AbortController();
    let groups = [];
    try {
      const r = await fetch(endpoint + '?q=' + encodeURIComponent(q.trim()), { headers: { Accept: 'application/json' }, signal: ctrl.signal, credentials: 'same-origin' });
      groups = (await r.json()).groups || [];
    } catch (e) { if (e.name === 'AbortError') return; }
    if (!q.trim()) {
      const rc = recent.get();
      if (rc.length) groups.splice(groups[0]?.label === t('Favoriten') ? 1 : 0, 0, { label: t('Zuletzt geöffnet'), items: rc, scope: 'r' });
    }
    if (want) groups = groups.filter(g => (g.scope || scopeOf(g.label)) === want);
    items = []; let html = '';
    for (const g of groups) {
      if (!g.items?.length) continue;
      html += `<div class="adm-qb__g" role="presentation">${esc(g.label)}</div>`;
      for (const it of g.items) {
        const i = items.push(it) - 1;
        const icon = it.thumb ? `<img src="${esc(it.thumb)}" alt="">` : ico(it.ico || 'file-text');
        const acts = [t('Öffnen'), ...(it.alt ? [t('Ansehen')] : []), t('Neuer Tab')];
        html += `<a class="adm-qb__it" role="option" id="adm-qb-${i}" data-i="${i}" href="${esc(it.url)}" aria-selected="false">
          <span class="adm-qb__ic">${icon}</span><span class="adm-qb__t"><b>${hl(it.title, q)}</b><small>${esc(it.sub || '')}</small></span>
          <span class="adm-qb__acts">${acts.map((a, k) => `<span class="adm-qb__act" data-act="${k}">${esc(a)}</span>`).join('')}</span></a>`;
      }
    }
    list.innerHTML = html || `<p class="adm-qb__empty">${esc(q ? t('Keine Treffer für „{q}“.', { q }) : t('Tippen zum Suchen – Kürzel p: d: m: k: t: f: oder > für Befehle.'))}</p>`;
    active = 0; act = 0; mark();
  }
  function mark() {
    list.querySelectorAll('.adm-qb__it').forEach(el => {
      const on = +el.dataset.i === active;
      el.classList.toggle('is-active', on);
      el.setAttribute('aria-selected', String(on));
      el.querySelectorAll('.adm-qb__act').forEach(a => a.classList.toggle('is-sel', on && +a.dataset.act === act));
    });
    const el = list.querySelector('#adm-qb-' + active);
    if (el) { el.scrollIntoView({ block: 'nearest' }); input.setAttribute('aria-activedescendant', el.id); } else input.removeAttribute('aria-activedescendant');
  }
  function go(it, newTab) {
    if (!it) return;
    recent.add(it);
    const acts = ['open', ...(it.alt ? ['alt'] : []), 'tab'];
    const a = newTab ? 'tab' : acts[act] || 'open';
    if (a === 'tab') window.open(it.url, '_blank', 'noopener');
    else location.href = a === 'alt' ? it.alt : it.url;
  }
  async function toggleFav(it) {
    if (!it || !favEndpoint) return;
    const url = it.url.replace(location.origin, '');
    await fetch(favEndpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify({ url, title: it.title, icon: it.ico || '' }) }).catch(() => null);
    load();
  }

  input.addEventListener('focus', () => { open(true); load(); });
  input.addEventListener('input', () => { syncScope(); clearTimeout(timer); timer = setTimeout(load, 110); });
  input.addEventListener('keydown', e => {
    const n = items.length;
    if (e.key === 'ArrowDown') { e.preventDefault(); if (n) { active = (active + 1) % n; act = 0; mark(); } }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (n) { active = (active - 1 + n) % n; act = 0; mark(); } }
    else if (e.key === 'ArrowRight' && n && input.selectionStart === input.value.length) { e.preventDefault(); act = Math.min(act + 1, (items[active].alt ? 2 : 1)); mark(); }
    else if (e.key === 'ArrowLeft' && act > 0) { e.preventDefault(); act--; mark(); }
    else if (e.key === 'Enter') { e.preventDefault(); go(items[active], e.metaKey || e.ctrlKey || e.shiftKey); }
    else if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'd') { e.preventDefault(); toggleFav(items[active]); }
    else if (e.key === 'Escape') { e.preventDefault(); if (input.value) { input.value = ''; syncScope(); load(); } else { open(false); input.blur(); } }
  });
  list.addEventListener('mousemove', e => { const el = e.target.closest('[data-i]'); if (el && +el.dataset.i !== active) { active = +el.dataset.i; act = 0; mark(); } });
  list.addEventListener('mousedown', e => e.preventDefault());   // Fokus bleibt im Feld
  list.addEventListener('click', e => { const el = e.target.closest('[data-i]'); if (!el) return; e.preventDefault(); const a = e.target.closest('[data-act]'); if (a) act = +a.dataset.act; go(items[+el.dataset.i], e.metaKey || e.ctrlKey); });
  input.addEventListener('blur', () => setTimeout(() => { if (!box.contains(d.activeElement)) open(false); }, 120));

  // Quelle wählen: setzt das Kürzel ins Feld, behält den Suchbegriff
  d.querySelectorAll('[data-qb-scope]').forEach(b => b.addEventListener('click', () => {
    const { q } = parse();
    const pre = b.dataset.qbScope;
    input.value = (pre ? pre + (pre === '>' ? ' ' : '') : '') + q;
    d.getElementById('adm-qb-scope')?.hidePopover?.();
    syncScope(); input.focus(); load();
  }));

  // ⌘K / Strg+K: Feld in der Leiste statt der modalen Palette (auf schmalen Bildschirmen bleibt die Palette)
  d.addEventListener('keydown', e => {
    if ((e.metaKey || e.ctrlKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'k' && input.offsetParent !== null) {
      e.preventDefault(); e.stopImmediatePropagation(); bar?.classList.remove('is-hidden'); input.focus(); input.select();
    }
  }, true);

  // „Diese Seite merken“ im Favoriten-Menü: nutzt den Stern der Seite (resources/js/_favorites.js)
  d.querySelector('[data-tb-fav-here]')?.addEventListener('click', () => {
    d.getElementById('adm-tb-fav')?.hidePopover?.();
    d.querySelector('.adm-star')?.click();
  });
  d.querySelectorAll('[data-kbd]').forEach(k => { k.textContent = isMac ? '⌘K' : t('Strg') + ' K'; });
}

// Kopfleiste: beim Herunterscrollen ausblenden (ab 80 px, nach 8 px Bewegung), beim Hochscrollen einblenden; nie bei offenem
// Menü, offener Trefferliste oder Fokus in der Leiste. Der Platz bleibt (sticky) – der Inhalt springt nicht.
function headroom(bar) {
  let last = scrollY, ticking = false;
  const busy = () => bar.contains(d.activeElement) || bar.querySelector('[data-qb-panel]:not([hidden])') || d.querySelector('.adm-pop:popover-open');
  addEventListener('scroll', () => {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(() => {
      const y = scrollY, dy = y - last;
      if (Math.abs(dy) > 8) {
        bar.classList.toggle('is-hidden', dy > 0 && y > 80 && !busy());
        last = y;
      }
      if (y < 80) bar.classList.remove('is-hidden');
      ticking = false;
    });
  }, { passive: true });
  bar.addEventListener('focusin', () => bar.classList.remove('is-hidden'));
}
