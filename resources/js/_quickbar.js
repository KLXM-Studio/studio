/*
 * Kopfleiste der Verwaltung (app/Admin/views/_topbar.php):
 * - Suche direkt in der Leiste – dieselbe Suche wie die Befehlspalette (/admin/api/search, Rechte im Core). Quelle links wählen
 *   oder per Kürzel im Feld (p: Seiten · d: Daten · m: Medien · k: Kontakte · t: Termine · f: Favoriten · > Befehle), der Server
 *   filtert (?scope=…). Ohne Eingabe: Favoriten, zuletzt geöffnet (dieser Browser), zuletzt bearbeitet, Aktionen.
 *   ↑↓ wählen · Bild↑/Bild↓ vorige/nächste Gruppe · ↵ öffnen · ⌘↵/Strg+↵/⇧↵ neuer Tab · → / ← Zusatzaktion (z. B. „Ansehen“) ·
 *   ⌘D/Strg+D Favorit · esc leert, dann schließt. ⌘K/Strg+K springt ins Feld (schmal: Befehlspalette, _spotlight.js).
 * - Stern-Menü: „Diese Seite merken“ nutzt den Stern der Seite (_favorites.js), der Stern der Leiste folgt 'adm:favorites'.
 * - Ein-/Ausblenden beim Scrollen (Headroom): aus ab 80 px nach unten, ein beim Hochscrollen; nie bei offenem Menü, offener
 *   Trefferliste oder Fokus in der Leiste; Fokus und ⌘K holen sie zurück. Sticky – der Inhalt springt nicht.
 */
import { t } from './_i18n.js';
import { ico } from './_icons.js';
import { esc, csrf } from './_html.js';

const d = document;
const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
const RECENT = 'adm.qb.recent';
const SCOPES = { 'p:': 'p', 'd:': 'd', 'm:': 'm', 'k:': 'k', 't:': 't', 'f:': 'f', '>': 'c' };

const recent = {
  key: () => RECENT + '.' + (d.querySelector('#adm-fav')?.dataset.favUser || ''),
  get() { try { return JSON.parse(localStorage.getItem(recent.key()) || '[]'); } catch { return []; } },
  add(it) {
    if (it.cmd) return;   // Befehle nicht unter „Zuletzt geöffnet“
    try {
      const l = recent.get().filter(x => x.url !== it.url);
      l.unshift({ title: it.title, sub: it.sub || '', url: it.url, ico: it.ico || '', alt: it.alt || null });
      localStorage.setItem(recent.key(), JSON.stringify(l.slice(0, 5)));
    } catch { /* privat/gesperrt */ }
  },
};

export function initQuickbar() {
  const bar = d.querySelector('[data-tb]');
  if (!bar) return;
  headroom(bar);
  initFavStar(bar);
  const box = bar.querySelector('[data-qb]');
  if (!box) return;
  const input = box.querySelector('[data-qb-input]'), panel = box.querySelector('[data-qb-panel]'), list = panel.querySelector('.adm-qb__list');
  const scopeLabel = box.querySelector('[data-qb-scope-label]');
  const scopeBtns = [...d.querySelectorAll('[data-qb-scope]')];
  const labels = Object.fromEntries(scopeBtns.map(b => [b.dataset.qbScope, b.querySelector('span')?.textContent || '']));
  const endpoint = box.dataset.searchEndpoint, favEndpoint = box.dataset.favEndpoint;
  let items = [], groupStart = [], active = 0, act = 0, timer, ctrl;

  const parse = () => {
    const v = input.value;
    const m = v.match(/^\s*(>|[pdmktf]:)\s*/i);
    return m && labels[m[1].toLowerCase()] !== undefined ? { pre: m[1].toLowerCase(), q: v.slice(m[0].length) } : { pre: '', q: v };
  };
  const syncScope = () => {
    const { pre } = parse();
    scopeLabel.textContent = labels[pre] || labels[''];
    scopeBtns.forEach(b => b.setAttribute('aria-checked', String(b.dataset.qbScope === pre)));
  };
  const open = on => { panel.hidden = !on; input.setAttribute('aria-expanded', String(on)); if (on) bar.classList.remove('is-hidden'); };
  const acts = it => ['open', ...(it.alt ? ['alt'] : []), 'tab'];

  function hl(text, q) {
    let h = esc(text || '');
    for (const w of q.trim().split(/\s+/).filter(Boolean)) h = h.replace(new RegExp('(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')(?![^<]*>)', 'ig'), '<mark>$1</mark>');
    return h;
  }

  async function load() {
    const { pre, q } = parse();
    const scope = SCOPES[pre] || '';
    ctrl?.abort(); ctrl = new AbortController();
    let groups = [];
    try {
      const r = await fetch(endpoint + '?q=' + encodeURIComponent(q.trim()) + (scope ? '&scope=' + scope : ''), { headers: { Accept: 'application/json' }, signal: ctrl.signal, credentials: 'same-origin' });
      groups = (await r.json()).groups || [];
    } catch (e) {
      if (e.name === 'AbortError') return;
      list.innerHTML = `<p class="adm-qb__empty">${esc(t('Suche nicht verfügbar.'))}</p>`;
      return;
    }
    if (!q.trim() && !scope) {
      const rc = recent.get();
      if (rc.length) groups.splice(groups[0]?.scope === 'f' ? 1 : 0, 0, { label: t('Zuletzt geöffnet'), scope: 'o', items: rc });
    }
    items = []; groupStart = [];
    let html = '';
    groups.forEach((g, gi) => {
      if (!g.items?.length) return;
      html += `<div class="adm-qb__g" role="presentation" id="adm-qb-g${gi}">${esc(g.label)}</div>`;
      groupStart.push(items.length);
      for (const it of g.items) {
        const i = items.push(it) - 1;
        const icon = it.thumb ? `<img src="${esc(it.thumb)}" alt="">` : it.ico ? ico(it.ico) : it.glyph ? `<b>${esc(it.glyph)}</b>` : ico('file-text');
        const names = { open: t('Öffnen'), alt: t('Ansehen'), tab: t('Neuer Tab') };
        html += `<a class="adm-qb__it" role="option" id="adm-qb-${i}" data-i="${i}" href="${esc(it.url)}" aria-selected="false" aria-describedby="adm-qb-g${gi}">
          <span class="adm-qb__ic" aria-hidden="true">${icon}</span><span class="adm-qb__t"><b>${hl(it.title, q)}</b><small>${esc(it.sub || '')}</small></span>
          <span class="adm-qb__acts" aria-hidden="true">${acts(it).map((a, k) => `<span class="adm-qb__act" data-act="${k}">${esc(names[a])}</span>`).join('')}</span></a>`;
      }
    });
    list.innerHTML = html || `<p class="adm-qb__empty">${esc(q.trim() ? t('Keine Treffer für „{q}“.', { q: q.trim() }) : t('Tippen zum Suchen – Kürzel p: d: m: k: t: f: oder > für Befehle.'))}</p>`;
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
    // „Assistent fragen …“ (Core\AI\Assistant): Chat-Fenster öffnen statt zu navigieren
    if (it.cmd === 'assistant' && !newTab && window.cmsAssistant) { open(false); input.blur(); window.cmsAssistant.open({ q: it.q || '' }); return; }
    recent.add(it);
    const a = newTab ? 'tab' : acts(it)[act] || 'open';
    if (a === 'tab') window.open(it.url, '_blank', 'noopener');
    else location.href = a === 'alt' ? it.alt : it.url;
  }
  // ⌘D: Treffer merken bzw. (schon Favorit) entfernen – Liste und Stern aktualisieren sich über 'adm:favorites-set'
  async function toggleFav(it) {
    if (!it || !favEndpoint || it.cmd) return;
    let url;
    // gemerkt wird eine Adresse der Verwaltung – bei Seiten (Treffer öffnet den Editor auf der Website) die Seite in der Verwaltung
    const adminUrl = x => { try { const u = new URL(x, location.href); return u.origin === location.origin && /\/admin(\/|$|\?|#)/.test(u.pathname) ? u.pathname + u.search + u.hash : null; } catch { return null; } };
    url = adminUrl(it.url) || (it.alt ? adminUrl(it.alt) : null);
    if (!url) return;
    let favs = [];
    try { favs = JSON.parse(d.getElementById('adm-fav-data')?.textContent || '[]'); } catch { /* leer */ }
    const known = favs.find(f => f.href === it.url || f.href === url || f.href === it.alt);
    const res = await fetch(favEndpoint + (known ? '/remove' : ''), { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify(known ? { url: known.url } : { url, title: it.title, icon: it.glyph || '' }) }).then(r => r.json()).catch(() => null);
    if (Array.isArray(res?.favorites)) {
      const json = d.getElementById('adm-fav-data');
      if (json) json.textContent = JSON.stringify(res.favorites.map(f => ({ ...f, href: f.href || f.url })));
      d.dispatchEvent(new CustomEvent('adm:favorites-set', { detail: res.favorites }));
    }
    const live = d.getElementById('adm-live');
    if (live) { live.textContent = ''; setTimeout(() => { live.textContent = res?.ok ? (known ? t('Aus Favoriten entfernt') : t('Zu Favoriten hinzugefügt')) : (res?.error || t('Speichern fehlgeschlagen.')); }, 40); }
    load();
  }
  const jumpGroup = dir => {
    if (!groupStart.length) return;
    let g = groupStart.findIndex((s, k) => active >= s && (k === groupStart.length - 1 || active < groupStart[k + 1]));
    g = (g + dir + groupStart.length) % groupStart.length;
    active = groupStart[g]; act = 0; mark();
  };

  input.addEventListener('focus', () => { open(true); load(); });
  input.addEventListener('input', () => { syncScope(); clearTimeout(timer); timer = setTimeout(load, 110); });
  input.addEventListener('keydown', e => {
    const n = items.length;
    if (panel.hidden && e.key !== 'Escape') open(true);
    if (e.key === 'ArrowDown') { e.preventDefault(); if (n) { active = (active + 1) % n; act = 0; mark(); } }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (n) { active = (active - 1 + n) % n; act = 0; mark(); } }
    else if (e.key === 'PageDown') { e.preventDefault(); jumpGroup(1); }
    else if (e.key === 'PageUp') { e.preventDefault(); jumpGroup(-1); }
    else if (e.key === 'ArrowRight' && n && input.selectionStart === input.value.length) { e.preventDefault(); act = Math.min(act + 1, acts(items[active]).length - 1); mark(); }
    else if (e.key === 'ArrowLeft' && act > 0) { e.preventDefault(); act--; mark(); }
    else if (e.key === 'Enter') { e.preventDefault(); go(items[active], e.metaKey || e.ctrlKey || e.shiftKey); }
    else if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'd') { e.preventDefault(); toggleFav(items[active]); }
    else if (e.key === 'Escape') { e.preventDefault(); if (input.value) { input.value = ''; syncScope(); load(); } else { open(false); input.blur(); } }
  });
  list.addEventListener('mousemove', e => { const el = e.target.closest('[data-i]'); if (el && +el.dataset.i !== active) { active = +el.dataset.i; act = 0; mark(); } });
  list.addEventListener('mousedown', e => e.preventDefault());   // Fokus bleibt im Feld
  list.addEventListener('click', e => {
    const el = e.target.closest('[data-i]');
    if (!el) return;
    e.preventDefault();
    const a = e.target.closest('[data-act]');
    act = a ? +a.dataset.act : 0;
    go(items[+el.dataset.i], e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1);
  });
  input.addEventListener('blur', () => setTimeout(() => { if (!box.contains(d.activeElement)) open(false); }, 120));
  d.addEventListener('click', e => { if (!panel.hidden && !box.contains(e.target)) open(false); });

  // Quelle wählen: setzt das Kürzel ins Feld und behält den Suchbegriff
  scopeBtns.forEach(b => b.addEventListener('click', () => {
    const { q } = parse();
    const pre = b.dataset.qbScope;
    input.value = (pre ? pre + ' ' : '') + q;
    d.getElementById('adm-qb-scope')?.hidePopover?.();
    syncScope(); input.focus(); load();
  }));

  // ⌘K / Strg+K: Feld in der Leiste statt der modalen Palette (schmal ist das Feld ausgeblendet → Palette)
  d.addEventListener('keydown', e => {
    if ((e.metaKey || e.ctrlKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'k' && input.offsetParent !== null) {
      e.preventDefault(); e.stopImmediatePropagation();
      bar.classList.remove('is-hidden'); input.focus(); input.select();
    }
  }, true);
  d.querySelectorAll('[data-kbd]').forEach(k => { k.textContent = isMac ? '⌘K' : t('Strg') + ' K'; });
}

// Stern der Leiste: gefüllt, wenn die aktuelle Seite gemerkt ist; „Diese Seite merken“ = Stern der Seite (_favorites.js)
function initFavStar(bar) {
  const btn = bar.querySelector('[data-tb-fav-btn]'), here = d.querySelector('[data-tb-fav-here]'), label = here?.querySelector('[data-tb-fav-label]');
  d.addEventListener('adm:favorites', e => {
    const on = !!e.detail?.here;
    btn?.classList.toggle('is-on', on);
    if (label) label.textContent = on ? label.dataset.on : label.dataset.off;
  });
  here?.addEventListener('click', () => {
    const star = d.querySelector('.adm-star');
    if (star) star.click();
  });
}

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
