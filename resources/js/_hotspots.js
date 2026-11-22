/**
 * Marker-Editor für den Feldtyp „hotspots“ (Core\Hotspots::renderInput): Bild aus dem Bildfeld desselben Formulars mit allen
 * Markierungen darüber; darunter die Wiederholgruppe mit einer Zeile je Markierung (Titel, Form, Lage, Text, Link, Glossar).
 * Die Zeilen bleiben die Quelle der Werte – der Editor liest sie und schreibt Lage/Form zurück (Ereignis input, damit Vorschau
 * und bedingte Felder folgen).
 *  - Klick ins Bild: neue Markierung an der Stelle (Umschalt + Klick: Eckpunkt an die gewählte Fläche anfügen).
 *  - Ziehen (Zeiger) verschiebt Markierungen, Ecken und Eckpunkte; Tastatur: Pfeile 1 %, mit Umschalt 5 %, mit Alt 0,1 %,
 *    Entf entfernt (Markierung bzw. Eckpunkt), Eingabe öffnet die Zeile, Bild auf/ab wählt die vorige/nächste Markierung.
 *  - Reihenfolge = Nummer: ↑/↓ der Zeilen. Weitere Editoren (Extension::hotspotEditor) im eingebetteten Fenster, postMessage.
 * Schnittstelle (window.CMSAdmin.hotspots): get(feld) → {v: 1, items}, set(feld, daten), normalize(daten).
 */
import { t } from './_i18n.js';
import { esc } from './_html.js';

const SHAPES = ['point', 'circle', 'rect', 'poly'];
const r2 = (v) => Math.round(v * 100) / 100;
const clamp = (v, a = 0, b = 100) => Math.max(a, Math.min(b, v));
const num = (v, def = null) => { const n = parseFloat(String(v ?? '').replace(',', '.')); return Number.isFinite(n) ? n : def; };

/** Punkte „x,y x,y …“ bzw. [[x,y], …] → [[x,y], …] */
export function parsePoints(raw) {
  const list = Array.isArray(raw) ? raw : String(raw || '').trim().split(/[\s;]+/).filter(Boolean).map((p) => p.split(','));
  return list.map((p) => [num(Array.isArray(p) ? p[0] : p?.x), num(Array.isArray(p) ? p[1] : p?.y)])
    .filter(([x, y]) => x !== null && y !== null).slice(0, 64).map(([x, y]) => [r2(clamp(x)), r2(clamp(y))]);
}
const pointsText = (pts) => pts.map(([x, y]) => `${r2(x)},${r2(y)}`).join(' ');

/** Mittelpunkt (wie Core\Hotspots::anchor) */
export function anchor(h) {
  if (h.shape === 'rect') return [r2(h.x + h.w / 2), r2(h.y + h.h / 2)];
  if (h.shape === 'poly' && h.points?.length >= 3) {
    let a = 0, cx = 0, cy = 0;
    const p = h.points;
    for (let i = 0; i < p.length; i++) {
      const [x0, y0] = p[i], [x1, y1] = p[(i + 1) % p.length], f = x0 * y1 - x1 * y0;
      a += f; cx += (x0 + x1) * f; cy += (y0 + y1) * f;
    }
    if (Math.abs(a) < 1e-6) return [r2(p.reduce((s, q) => s + q[0], 0) / p.length), r2(p.reduce((s, q) => s + q[1], 0) / p.length)];
    return [r2(cx / (3 * a)), r2(cy / (3 * a))];
  }
  return [h.x, h.y];
}

/** Daten im Format Version 1 bereinigen (gleiche Regeln wie Core\Hotspots::normalize, der Server prüft beim Speichern erneut) */
export function normalize(raw) {
  let data = raw;
  if (typeof data === 'string') { try { data = JSON.parse(data); } catch { data = null; } }
  const list = Array.isArray(data) ? data : Array.isArray(data?.items) ? data.items : [];
  const items = [];
  for (const it of list) {
    if (!it || typeof it !== 'object' || items.length >= 60) continue;
    const shape = SHAPES.includes(it.shape) ? it.shape : 'point';
    const h = { id: /^[A-Za-z0-9_-]{1,40}$/.test(it.id || '') ? it.id : '', shape };
    if (shape === 'poly') {
      h.points = parsePoints(it.points);
      if (h.points.length < 3) { delete h.points; h.shape = 'point'; }
    }
    if (h.shape !== 'poly') {
      const x = num(it.x), y = num(it.y);
      if (x === null || y === null) continue;
      h.x = r2(clamp(x)); h.y = r2(clamp(y));
    }
    if (h.shape === 'circle') h.r = r2(clamp(num(it.r, 5), 0.5));
    if (h.shape === 'rect') { h.w = r2(clamp(num(it.w, 10), 0.5, 100 - h.x)); h.h = r2(clamp(num(it.h, 10), 0.5, 100 - h.y)); }
    for (const k of ['title', 'text', 'link', 'link_label', 'term', 'icon']) h[k] = typeof it[k] === 'string' ? it[k] : (k === 'text' && typeof it.content === 'string' ? it.content : '');
    items.push(h);
  }
  return { v: 1, items };
}

const fields = new WeakMap();   // Feld → Steuerung

export function initHotspots(scope = document) {
  scope.querySelectorAll?.('[data-hotspots]').forEach((field) => { if (!fields.has(field)) fields.set(field, setup(field)); });
}
export const hotspots = {
  get: (field) => fields.get(field)?.get() ?? null,
  set: (field, data) => fields.get(field)?.set(data),
  normalize,
};

function setup(field) {
  let cfg = {};
  try { cfg = JSON.parse(field.dataset.hotspots || '{}'); } catch { /* leer */ }
  const ed = field.querySelector('[data-hs-ed]'), stage = field.querySelector('[data-hs-stage]'), live = field.querySelector('[data-hs-live]');
  const bar = field.querySelector('[data-hs-bar]'), count = field.querySelector('.hs-field__count');
  const rep = field.querySelector('.rep'), items = rep.querySelector('.rep-items');
  const root = field.closest('form') || field.getRootNode();
  ed.hidden = false;
  ed.setAttribute('aria-describedby', ed.querySelector('.hs-ed__hint')?.id || '');
  const rows = () => [...items.children].filter((r) => r.classList.contains('rep-item'));
  const inp = (row, k) => row.querySelector(`:scope .rep-fields [name$="[${k}]"]`);
  let active = -1, writing = false, mediaId = 0;

  // ---------------------------------------------------------------- Zeilen lesen / schreiben
  const read = (row) => {
    const v = (k) => inp(row, k)?.value ?? '';
    const shape = SHAPES.includes(v('shape')) ? v('shape') : 'point';
    const h = { id: v('id'), shape, x: num(v('x')), y: num(v('y')), r: num(v('r')), w: num(v('w')), h: num(v('h')), title: v('title'),
      text: v('text'), link: v('link'), link_label: v('link_label'), term: v('term'), icon: v('icon') };
    if (shape === 'poly') h.points = parsePoints(v('points'));
    return h;
  };
  const geom = (h) => {
    // Fehlende Angaben für die Anzeige ergänzen (neue Zeile, gerade umgeschaltete Form)
    const g = { ...h, x: h.x ?? 50, y: h.y ?? 50 };
    if (g.shape === 'circle') g.r = g.r ?? 6;
    if (g.shape === 'rect') { g.w = g.w ?? 15; g.h = g.h ?? 15; }
    if (g.shape === 'poly' && !(g.points?.length >= 3)) g.points = null;
    return g;
  };
  const write = (row, vals) => {
    writing = true;
    for (const [k, val] of Object.entries(vals)) {
      const el = inp(row, k);
      if (!el) continue;
      if (k === 'text') { const a = row.querySelector('.rte-area'); if (a) a.innerHTML = val; }
      if (el.tagName === 'SELECT' && val !== '' && ![...el.options].some((o) => o.value === val)) el.add(new Option(val, val));
      el.value = typeof val === 'number' ? String(r2(val)) : val;
      el.dispatchEvent(new Event('input', { bubbles: true }));
    }
    writing = false;
  };
  const writeGeom = (row, g) => {
    const vals = {};
    if (g.shape === 'poly') { vals.points = pointsText(g.points); [vals.x, vals.y] = anchor(g); }
    else { vals.x = g.x; vals.y = g.y; }
    if (g.shape === 'circle') vals.r = g.r;
    if (g.shape === 'rect') { vals.w = g.w; vals.h = g.h; }
    write(row, vals);
  };

  // ---------------------------------------------------------------- Bild
  const imgInput = () => cfg.image ? root.querySelector?.(`[name="${CSS.escape(cfg.image)}"]`) : null;
  const imgSrc = () => {
    const prev = imgInput()?.closest('.media-field')?.querySelector('.media-field-preview img');
    return prev?.getAttribute('src') || '';
  };

  // ---------------------------------------------------------------- Zeichnen
  let frame = null;
  const render = () => {
    const src = imgSrc();
    mediaId = +(imgInput()?.value || 0);
    const list = rows().map((r) => geom(read(r)));
    if (count) count.textContent = list.length === 1 ? t('1 Markierung') : t('{n} Markierungen', { n: list.length });
    if (!src) { stage.innerHTML = `<p class="hs-ed__empty">${esc(t('Zuerst ein Bild wählen.'))}</p>`; frame = null; return; }
    const ae = stage.getRootNode().activeElement;
    const focusKey = ae && stage.contains(ae) ? ae.dataset?.key : null;
    if (active >= list.length) active = list.length - 1;
    let svg = '', pins = '', handles = '';
    list.forEach((g, i) => {
      const n = i + 1, on = i === active ? ' is-on' : '';
      if (g.shape === 'circle') svg += `<ellipse class="hs-ed__area${on}" data-i="${i}" cx="${g.x}" cy="${g.y}" rx="${g.r}" ry="${g.r}" data-ratio></ellipse>`;
      if (g.shape === 'rect') svg += `<rect class="hs-ed__area${on}" data-i="${i}" x="${g.x}" y="${g.y}" width="${g.w}" height="${g.h}"></rect>`;
      if (g.shape === 'poly' && g.points) svg += `<polygon class="hs-ed__area${on}" data-i="${i}" points="${pointsText(g.points)}"></polygon>`;
      const [ax, ay] = g.shape === 'poly' && g.points ? anchor(g) : g.shape === 'rect' ? anchor(g) : [g.x, g.y];
      const name = (g.title || g.term || '').trim();
      pins += `<button type="button" class="hs-ed__pin${on}${g.shape !== 'point' ? ' hs-ed__pin--area' : ''}" data-i="${i}" data-key="p${i}" style="left:${ax}%;top:${ay}%"`
        + ` aria-label="${esc(t('Markierung {n}', { n }) + (name ? ': ' + name : '') + ', ' + t('{x} % waagerecht, {y} % senkrecht', { x: Math.round(ax), y: Math.round(ay) }))}"`
        + ` aria-pressed="${i === active}">${n}</button>`;
      if (i !== active) return;
      const hnd = (k, x, y, label) => `<button type="button" class="hs-ed__h" data-i="${i}" data-h="${k}" data-key="h${i}-${k}" style="left:${x}%;top:${y}%" aria-label="${esc(label)}"></button>`;
      if (g.shape === 'rect') handles += hnd('se', g.x + g.w, g.y + g.h, t('Größe von Markierung {n} (Ecke unten rechts)', { n }));
      if (g.shape === 'circle') handles += hnd('r', clamp(g.x + g.r), g.y, t('Radius von Markierung {n}', { n }));
      if (g.shape === 'poly' && g.points) g.points.forEach(([x, y], k) => { handles += hnd(String(k), x, y, t('Eckpunkt {k} von Markierung {n}', { k: k + 1, n })); });
    });
    stage.innerHTML = `<div class="hs-ed__frame" data-hs-frame><img class="hs-ed__img" src="${esc(src)}" alt="" draggable="false">`
      + `<svg class="hs-ed__svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">${svg}</svg>${pins}${handles}</div>`;
    frame = stage.querySelector('[data-hs-frame]');
    // Kreise: Radius in % der Breite → senkrecht im Verhältnis des Bildes
    const img = frame.querySelector('img');
    const ratio = () => frame.querySelectorAll('ellipse[data-ratio]').forEach((el) => {
      const k = img.naturalWidth && img.naturalHeight ? img.naturalWidth / img.naturalHeight : 1;
      el.setAttribute('ry', String(+el.getAttribute('rx') * k));
    });
    if (img.complete) ratio(); else img.addEventListener('load', ratio, { once: true });
    if (focusKey) frame.querySelector(`[data-key="${focusKey}"]`)?.focus({ preventScroll: true });
    rows().forEach((r, i) => r.classList.toggle('is-hs-active', i === active));
  };
  let queued = false;
  const later = () => { if (queued) return; queued = true; requestAnimationFrame(() => { queued = false; render(); }); };

  // ---------------------------------------------------------------- Auswahl, Ansage
  const say = (msg) => { live.textContent = ''; setTimeout(() => { live.textContent = msg; }, 30); };
  const select = (i, focus = true) => {
    active = i;
    render();
    if (focus) frame?.querySelector(`[data-key="p${i}"]`)?.focus({ preventScroll: true });
  };
  const openRow = (i) => {
    const row = rows()[i];
    if (!row) return;
    const tg = row.querySelector('.rep-toggle');
    if (tg?.getAttribute('aria-expanded') === 'false') tg.click();
    row.scrollIntoView({ block: 'nearest', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    inp(row, 'title')?.focus({ preventScroll: true });
  };

  // ---------------------------------------------------------------- Neue Markierung
  const addRow = (vals) => {
    if (cfg.max && rows().length >= cfg.max) { say(t('Höchstens {max} Markierungen.', { max: cfg.max })); return null; }
    const btn = rep.querySelector(':scope > [data-rep="add"]');
    if (!btn) return null;
    btn.click();
    const row = rows().at(-1);
    if (row && vals) write(row, vals);
    return row;
  };
  const pos = (e) => {
    const b = frame.getBoundingClientRect();
    return [r2(clamp(((e.clientX - b.left) / b.width) * 100)), r2(clamp(((e.clientY - b.top) / b.height) * 100))];
  };

  // ---------------------------------------------------------------- Ziehen
  let drag = null, dragEnd = 0;
  stage.addEventListener('pointerdown', (e) => {
    if (!frame || e.button !== 0) return;
    const el = e.target.closest('.hs-ed__pin,.hs-ed__h');
    if (!el) return;
    e.preventDefault();
    const i = +el.dataset.i, row = rows()[i];
    if (!row) return;
    if (active !== i) { active = i; render(); }
    const again = frame.querySelector(`[data-key="${el.dataset.key}"]`) || el;
    again.focus({ preventScroll: true });
    drag = { i, row, h: el.dataset.h ?? null, start: pos(e), g: geom(read(row)), moved: false, id: e.pointerId };
    stage.setPointerCapture?.(e.pointerId);
  });
  stage.addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    const [px, py] = pos(e), dx = px - drag.start[0], dy = py - drag.start[1];
    if (!drag.moved && Math.hypot(dx, dy) < 0.6) return;
    drag.moved = true;
    writeGeom(drag.row, move(drag.g, drag.h, dx, dy, px, py));
    render();
  });
  const end = (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    const d = drag;
    drag = null;
    dragEnd = Date.now();
    stage.releasePointerCapture?.(e.pointerId);
    if (d.moved) { const g = geom(read(d.row)); const [x, y] = anchor(g); say(t('Markierung {n}: {x} % waagerecht, {y} % senkrecht', { n: d.i + 1, x: Math.round(x), y: Math.round(y) })); }
    frame?.querySelector(`[data-key="${d.h === null ? 'p' + d.i : 'h' + d.i + '-' + d.h}"]`)?.focus({ preventScroll: true });
  };
  stage.addEventListener('pointerup', end);
  stage.addEventListener('pointercancel', end);

  /** Neue Lage beim Ziehen bzw. per Tastatur: h = null (ganze Markierung), 'se' (Rechteck), 'r' (Kreis), Nummer (Eckpunkt) */
  function move(g0, h, dx, dy, px, py) {
    const g = { ...g0, points: g0.points?.map((p) => [...p]) };
    if (h === null) {
      if (g.shape === 'poly' && g.points) {
        const minX = Math.min(...g.points.map((p) => p[0])), maxX = Math.max(...g.points.map((p) => p[0]));
        const minY = Math.min(...g.points.map((p) => p[1])), maxY = Math.max(...g.points.map((p) => p[1]));
        const mx = clamp(dx, -minX, 100 - maxX), my = clamp(dy, -minY, 100 - maxY);
        g.points = g.points.map(([x, y]) => [r2(x + mx), r2(y + my)]);
      } else if (g.shape === 'rect') { g.x = r2(clamp(g0.x + dx, 0, 100 - g.w)); g.y = r2(clamp(g0.y + dy, 0, 100 - g.h)); }
      else { g.x = r2(clamp(g0.x + dx)); g.y = r2(clamp(g0.y + dy)); }
    } else if (h === 'se') { g.w = r2(clamp((px ?? g0.x + g0.w + dx) - g.x, 0.5, 100 - g.x)); g.h = r2(clamp((py ?? g0.y + g0.h + dy) - g.y, 0.5, 100 - g.y)); }
    else if (h === 'r') { g.r = r2(clamp(Math.abs((px ?? g0.x + g0.r + dx) - g.x), 0.5)); }
    else if (g.points?.[+h]) { const [x, y] = g0.points[+h]; g.points[+h] = [r2(clamp(px ?? x + dx)), r2(clamp(py ?? y + dy))]; }
    return g;
  }

  // ---------------------------------------------------------------- Klick ins Bild, Tastatur
  stage.addEventListener('click', (e) => {
    if (!frame) return;
    const el = e.target.closest('.hs-ed__pin,.hs-ed__h');
    if (el || Date.now() - dragEnd < 400) return;   // Auswahl/Ziehen macht pointerdown (danach kein neuer Punkt)
    if (!e.target.closest('[data-hs-frame]')) return;
    const [x, y] = pos(e);
    const row = rows()[active], g = row ? geom(read(row)) : null;
    if (e.shiftKey && g?.shape === 'poly' && g.points) {
      writeGeom(row, { ...g, points: [...g.points, [x, y]] });
      render();
      say(t('Eckpunkt {k} angefügt.', { k: g.points.length + 1 }));
      return;
    }
    const added = addRow({ shape: 'point', x, y });
    if (added) { active = rows().length - 1; render(); say(t('Markierung {n} gesetzt: {x} % waagerecht, {y} % senkrecht', { n: active + 1, x: Math.round(x), y: Math.round(y) })); }
  });
  // Tastatur: Fokus auf einer Markierung wählt sie (Ecken/Eckpunkte erscheinen)
  stage.addEventListener('focusin', (e) => {
    const el = e.target.closest?.('.hs-ed__pin');
    if (el && +el.dataset.i !== active && !drag) { active = +el.dataset.i; render(); }
  });
  stage.addEventListener('keydown', (e) => {
    const el = e.target.closest?.('.hs-ed__pin,.hs-ed__h');
    if (!el) return;
    const i = +el.dataset.i, row = rows()[i], h = el.dataset.h ?? null;
    if (!row) return;
    const step = e.shiftKey ? 5 : e.altKey ? 0.1 : 1;
    const dir = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
    if (dir) {
      e.preventDefault();
      const g = geom(read(row));
      // Rechteck-Ecke/Radius mit den Pfeilen: Größe ändern
      writeGeom(row, move(g, h, dir[0], dir[1]));
      render();
      const g2 = geom(read(row)), [x, y] = h !== null && g2.shape === 'poly' ? g2.points[+h] : anchor(g2);
      say(h === 'se' ? t('{w} % breit, {h} % hoch', { w: Math.round(g2.w), h: Math.round(g2.h) }) : h === 'r' ? t('Radius {r} %', { r: Math.round(g2.r) })
        : t('{x} % waagerecht, {y} % senkrecht', { x: Math.round(x), y: Math.round(y) }));
    } else if (e.key === 'Delete' || e.key === 'Backspace') {
      e.preventDefault();
      const g = geom(read(row));
      if (h !== null && g.shape === 'poly' && g.points) {
        if (g.points.length <= 3) { say(t('Eine Fläche braucht mindestens drei Eckpunkte.')); return; }
        g.points.splice(+h, 1);
        writeGeom(row, g);
        render();
        frame?.querySelector(`[data-key="p${i}"]`)?.focus();
        return;
      }
      row.querySelector('[data-rep="remove"]')?.click();   // fragt nach und setzt den Fokus
    } else if (e.key === 'Enter' && h === null) {
      e.preventDefault();
      openRow(i);
    } else if ((e.key === 'PageDown' || e.key === 'PageUp') && h === null) {
      e.preventDefault();
      const n = rows().length;
      if (n) select((i + (e.key === 'PageDown' ? 1 : n - 1)) % n);
    } else if (e.key === 'Escape' && active >= 0) {
      active = -1; render();
    }
  });

  // ---------------------------------------------------------------- Zeilen → Editor
  rep.addEventListener('input', () => { if (!writing) later(); });
  rep.addEventListener('change', (e) => {
    const sel = e.target.closest('select');
    const row = sel?.closest('.rep-item');
    if (!row || !sel.name.endsWith('[shape]')) { later(); return; }
    // Form umgeschaltet: fehlende Maße aus der bisherigen Lage
    const g = read(row), x = g.x ?? 50, y = g.y ?? 50;
    if (g.shape === 'rect' && (g.w === null || g.h === null)) write(row, { x: clamp(x - 7.5, 0, 85), y: clamp(y - 7.5, 0, 85), w: 15, h: 15 });
    if (g.shape === 'circle' && g.r === null) write(row, { r: 6 });
    if (g.shape === 'poly' && (g.points?.length ?? 0) < 3) write(row, { points: pointsText([[x, clamp(y - 7)], [clamp(x + 7), clamp(y + 6)], [clamp(x - 7), clamp(y + 6)]]) });
    active = rows().indexOf(row);
    later();
  });
  rep.addEventListener('focusin', (e) => {
    const row = e.target.closest('.rep-item');
    const i = row ? rows().indexOf(row) : -1;
    if (i >= 0 && i !== active) { active = i; later(); }
  });
  // Zeilen hinzugefügt, entfernt, umsortiert
  new MutationObserver(later).observe(items, { childList: true });
  // Bild gewechselt
  const watchImg = () => {
    const mf = imgInput()?.closest('.media-field');
    if (!mf || mf._hsWatch) return;
    mf._hsWatch = true;
    new MutationObserver(later).observe(mf.querySelector('.media-field-preview'), { childList: true, subtree: true, attributes: true });
  };
  watchImg();
  root.addEventListener?.('input', (e) => { if (e.target?.name === cfg.image) { watchImg(); later(); } });

  // ---------------------------------------------------------------- Weitere Editoren (Erweiterungen)
  const ico = (n) => window.CMSAdmin?.ico?.(n) || '';
  bar.innerHTML = (cfg.editors || []).map((x, k) => `<button type="button" class="btn btn--small" data-hs-editor="${k}"${x.title ? ` title="${esc(x.title)}"` : ''}>${ico(x.icon)} ${esc(x.label)}</button>`).join('');
  bar.hidden = !(cfg.editors || []).length;
  bar.addEventListener('click', (e) => {
    const b = e.target.closest('[data-hs-editor]');
    const x = b && cfg.editors[+b.dataset.hsEditor];
    if (x) openEditor(x, b);
  });
  function openEditor(x, btn) {
    const u = new URL(x.url, location.origin);
    u.searchParams.set('format', 'hotspots');
    u.searchParams.set('embed', '1');
    if (mediaId) u.searchParams.set('media', String(mediaId));
    const dlg = document.createElement('dialog');
    dlg.className = 'hs-dlg';
    dlg.setAttribute('aria-label', x.label);
    dlg.innerHTML = `<iframe title="${esc(x.label)}" src="${esc(u.pathname + u.search)}"></iframe>`;
    field.append(dlg);
    const win = () => dlg.querySelector('iframe')?.contentWindow;
    const done = () => { removeEventListener('message', onMsg); if (dlg.open) dlg.close(); dlg.remove(); btn?.focus?.({ preventScroll: true }); };
    const onMsg = (ev) => {
      if (ev.origin !== location.origin || ev.source !== win() || ev.data?.source !== 'klxm-hotspot-editor') return;
      if (ev.data.type === 'ready') {
        const img = imgInput()?.closest('.media-field')?.querySelector('.media-field-preview img');
        win().postMessage({ source: 'klxm-hotspots', type: 'init', v: 1, data: get(),
          media: mediaId ? { id: mediaId, url: img?.getAttribute('src') || '', width: img?.naturalWidth || 0, height: img?.naturalHeight || 0, alt: '' } : null }, location.origin);
      } else if (ev.data.type === 'apply') {
        set(ev.data.data);
        say(t('Markierungen übernommen.'));
        if (ev.data.close) done();
      } else if (ev.data.type === 'close') done();
    };
    addEventListener('message', onMsg);
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); done(); });
    dlg.showModal();
    dlg.querySelector('iframe').focus();
  }

  // ---------------------------------------------------------------- Schnittstelle
  function get() {
    return normalize(rows().map((r) => { const h = read(r); return h.shape === 'poly' ? h : { ...h, points: undefined }; }));
  }
  function set(data) {
    const { items: list } = normalize(data);
    const keep = document.activeElement;
    rows().forEach((r) => r.remove());
    for (const h of list) {
      const vals = { title: h.title, shape: h.shape, text: h.text, link: h.link, link_label: h.link_label, term: h.term, icon: h.icon, id: h.id };
      if (h.shape === 'poly') { vals.points = pointsText(h.points); [vals.x, vals.y] = anchor(h); } else { vals.x = h.x; vals.y = h.y; }
      if (h.shape === 'circle') vals.r = h.r;
      if (h.shape === 'rect') { vals.w = h.w; vals.h = h.h; }
      addRow(vals);
    }
    active = -1;
    rep.dispatchEvent(new Event('input', { bubbles: true }));
    keep?.focus?.({ preventScroll: true });
    render();
    field.dispatchEvent(new CustomEvent('cms:hotspots', { bubbles: true, composed: true, detail: get() }));
  }

  render();
  return { get, set };
}
