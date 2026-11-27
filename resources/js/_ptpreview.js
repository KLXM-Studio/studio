/*
 * Seitenbaum → Vorschau als Seitenleiste (Markup: app/Admin/views/pages/index.php, [data-ptpv]).
 * Zeigt die markierte Seite über /admin/pages/{id}/vorschau (ohne Werkzeugleiste) in einem skalierten Rahmen:
 * Gerätebreite Mobil 390 (quer 844) bzw. Desktop 1280 (hoch 800), nach der Breite skaliert und in voller Höhe der Leiste
 * (Desktop: Leiste automatisch breiter, Breite ziehbar); Entwurf (Arbeitsstand) oder Live.
 * Folgt der Auswahl im Baum (aria-selected), merkt sich offen/Gerät/Ausrichtung/Fassung (localStorage).
 * Öffnen: Augen-Knopf je Zeile ([data-ptpv-row], neben Online/Offline). Ereignis „ptpv:open“ (detail: Baumknoten) öffnet die Leiste für eine Seite – z. B. aus dem Kontextmenü.
 */
import { t } from './_i18n.js';

const SIZES = { mobile: [390, 844], tablet: [820, 1180], desktop: [1280, 800] };   // natürliche Ausrichtung: Mobil/Tablet hoch, Desktop quer
const NATURAL = { mobile: 'portrait', tablet: 'portrait', desktop: 'landscape' };

export function initPagePreview() {
  const box = document.querySelector('[data-ptpv]');
  if (!box) return;
  const $ = s => box.querySelector(s);
  const tree = document.querySelector('.pt-tree');
  const toggle = document.querySelector('[data-ptpv-toggle]');
  const frame = $('[data-ptpv-frame]'), stage = $('[data-ptpv-stage]'), empty = $('[data-ptpv-empty]');
  const title = $('[data-ptpv-title]'), openLink = $('[data-ptpv-open]'), scaleEl = $('[data-ptpv-scale]');
  const acts = $('[data-ptpv-acts]'), editLink = $('[data-ptpv-edit]'), setLink = $('[data-ptpv-settings]');
  let pageUrl = '';
  const base = box.dataset.base;
  const store = {
    get(k, d) { try { return JSON.parse(localStorage.getItem('ptpv:' + k)) ?? d; } catch { return d; } },
    set(k, v) { try { localStorage.setItem('ptpv:' + k, JSON.stringify(v)); } catch { /* privates Fenster */ } },
  };
  let dev = store.get('dev', 'mobile'), orient = store.get('orient', NATURAL[dev] || 'portrait'), stand = store.get('stand', '');
  if (!SIZES[dev]) dev = 'mobile';
  let pageId = null;

  const dims = () => {
    const [a, b] = SIZES[dev] || SIZES.mobile;
    const natural = NATURAL[dev] || 'portrait';
    return orient === natural ? [a, b] : [b, a];
  };
  const fit = () => {
    if (box.hidden) return;
    // Füllend: nach der Gerätebreite skalieren, Höhe = ganze Leiste (die Seite scrollt darin wie auf dem Gerät)
    const [w] = dims();
    const sw = Math.max(0, stage.clientWidth - 24), sh = Math.max(0, stage.clientHeight - 24);
    const sc = Math.min(1, sw / w) || 1;
    const h = Math.round(sh / sc);
    frame.style.width = w + 'px';
    frame.style.height = h + 'px';
    frame.style.transform = `translateX(-50%) scale(${sc})`;
    scaleEl.textContent = `${w} px · ${Math.round(sc * 100)} %`;
  };
  const load = () => {
    if (acts) acts.hidden = !pageId;
    if (!pageId) { frame.hidden = true; empty.hidden = false; openLink.hidden = true; return; }
    const url = `${base}/${pageId}/vorschau${stand ? '?stand=' + stand : ''}`;
    if (frame.dataset.src !== url) { frame.dataset.src = url; frame.src = url; }
    frame.hidden = false; empty.hidden = true; openLink.hidden = false; openLink.href = url;
  };
  const select = n => {
    pageId = n?.dataset.id || null;
    pageUrl = n?.dataset.url || '';
    // Hauptknopf „Im Frontend bearbeiten“ und „Einstellungen“ (Seitenformular) für die gezeigte Seite
    if (editLink) editLink.href = pageUrl ? pageUrl + '?edit=1' : '#';
    if (setLink) setLink.href = pageId ? `${base}/${pageId}` : '#';
    tree?.querySelectorAll('[data-ptpv-row]').forEach(b => b.setAttribute('aria-pressed', String(!box.hidden && b.closest('.pt-node')?.dataset.id === pageId)));
    title.textContent = n?.dataset.title ? `${t('Vorschau')}: ${n.dataset.title}` : t('Vorschau');
    load();
  };
  const sync = () => {
    box.querySelectorAll('[data-dev]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.dev === dev)));
    box.querySelectorAll('[data-orient]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.orient === orient)));
    box.querySelectorAll('[data-stand]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.stand === stand)));
    box.dataset.dev = dev;
    document.documentElement.classList.toggle('ptpv-wide', dev !== 'mobile');
    requestAnimationFrame(fit);
  };
  // Breite per Griff ziehen (Maus/Touch) bzw. Pfeiltasten; gemerkt
  const grip = $('[data-ptpv-grip]');
  const setW = px => {
    const w = Math.round(Math.max(320, Math.min(innerWidth - 360, px)));
    document.documentElement.style.setProperty('--ptpv-user', w + 'px');
    store.set('w', w); fit();
  };
  const savedW = store.get('w', 0);
  if (savedW) document.documentElement.style.setProperty('--ptpv-user', savedW + 'px');
  grip?.addEventListener('pointerdown', e => {
    e.preventDefault(); grip.setPointerCapture(e.pointerId); box.classList.add('is-resizing');
    const move = ev => setW(innerWidth - ev.clientX);
    const up = () => { box.classList.remove('is-resizing'); grip.removeEventListener('pointermove', move); grip.removeEventListener('pointerup', up); };
    grip.addEventListener('pointermove', move); grip.addEventListener('pointerup', up);
  });
  grip?.addEventListener('keydown', e => {
    const k = { ArrowLeft: 40, ArrowRight: -40 }[e.key];
    if (k) { e.preventDefault(); setW(box.getBoundingClientRect().width + k); }
  });
  grip?.addEventListener('dblclick', () => { document.documentElement.style.removeProperty('--ptpv-user'); store.set('w', 0); requestAnimationFrame(fit); });
  const open = (on, node) => {
    box.hidden = !on;
    toggle?.setAttribute('aria-pressed', String(on));
    document.documentElement.classList.toggle('has-ptpv', on);
    store.set('open', on);
    if (on) {
      select(node || tree?.querySelector('.pt-node[aria-selected=true]') || null);
      requestAnimationFrame(fit);
    } else {
      frame.removeAttribute('src'); delete frame.dataset.src;
      tree?.querySelectorAll('[data-ptpv-row][aria-pressed=true]').forEach(b => b.setAttribute('aria-pressed', 'false'));
      (tree?.querySelector('.pt-node[aria-selected=true] [data-ptpv-row]') || toggle)?.focus({ preventScroll: true });
    }
  };

  toggle?.addEventListener('click', () => open(box.hidden));
  // Augen-Knopf je Zeile: Vorschau dieser Seite öffnen; dieselbe Seite noch einmal = schließen
  tree?.addEventListener('click', e => {
    const b = e.target.closest('[data-ptpv-row]');
    if (!b) return;
    const n = b.closest('.pt-node');   // markiert wird die Zeile vom Klick-Handler des Baums (admin.js)
    if (!box.hidden && n?.dataset.id === pageId) { open(false); return; }
    open(true, n);
  });
  $('[data-ptpv-close]').addEventListener('click', () => open(false));
  box.addEventListener('keydown', e => { if (e.key === 'Escape') { e.stopPropagation(); open(false); } });
  box.addEventListener('click', e => {
    const b = e.target.closest('[data-dev],[data-orient],[data-stand]');
    if (!b) return;
    if (b.dataset.dev) {
      if (dev !== b.dataset.dev) orient = NATURAL[b.dataset.dev] || 'portrait';   // neues Gerät: natürliche Ausrichtung
      dev = b.dataset.dev;
    }
    if (b.dataset.orient) orient = b.dataset.orient;
    if ('stand' in b.dataset) stand = b.dataset.stand;
    store.set('dev', dev); store.set('orient', orient); store.set('stand', stand);
    sync(); fit(); load();
  });
  // Auswahl im Baum folgen
  if (tree) {
    new MutationObserver(list => {
      if (box.hidden) return;
      for (const m of list) if (m.target.getAttribute?.('aria-selected') === 'true') { select(m.target); break; }
    }).observe(tree, { subtree: true, attributes: true, attributeFilter: ['aria-selected'] });
  }
  document.addEventListener('ptpv:open', e => open(true, e.detail || null));
  // Doppelklick auf einen Abschnitt der Vorschau: Editor genau dort öffnen (?edit=1#b-…, gleiche Herkunft)
  frame.addEventListener('load', () => {
    try {
      frame.contentDocument?.addEventListener('dblclick', e => {
        const sec = e.target.closest?.('[id^="b-"]');
        if (sec && pageUrl) { e.preventDefault(); location.href = pageUrl + '?edit=1#' + sec.id; }
      });
    } catch { /* andere Herkunft */ }
  });
  if ('ResizeObserver' in window) new ResizeObserver(fit).observe(stage); else addEventListener('resize', fit);
  sync();
  if (store.get('open', false) && matchMedia('(min-width: 900px)').matches) open(true);
}
