/*
 * Datum und Uhrzeit mit dem barrierefreien Picker a11y_datetime (Fork von flatpickr, MIT) – Progressive Enhancement.
 *
 * Ohne JavaScript bleiben die Felder echte <input type="date|time|datetime-local"> mit dem Format des Servers
 * (Y-m-d, H:i, Y-m-d\TH:i). Mit JavaScript wird das Feld zum versteckten Träger dieses Werts (Name, ID, Wert bleiben),
 * davor sitzt ein sichtbares Textfeld mit deutscher bzw. englischer Anzeige (TT.MM.JJJJ, HH:MM) und Kalender.
 *
 *   initDatetime(scope, { base, lang, theme, mode, css })
 *     base   Ordner public/assets/js/ (URL) – Skript, Stylesheet und Sprachdatei liegen unter
 *            ../vendor/a11y_datetime/{version}/ (Version setzt tools/build.mjs, A11Y_DT_VERSION)
 *     lang   'de' | 'en' (Anzeige, Monatsnamen, Ansagen); Wochenbeginn immer Montag
 *     theme  Klasse am Kalender: 'dt-adm' (Token --adm-*, admin.css) | 'dt-site' (Token --kit-*, css/datetime.css)
 *     mode   'picker' (immer) | 'desktop' (Geräte ohne Maus/Touchpad: Auswahl des Systems) | 'native' (nie)
 *     css    zusätzliche Stylesheets je Wurzel (Website: css/datetime.css)
 *
 * Ausnahmen: data-native am Feld (bleibt beim Browser), readonly-Felder. Ein Skript, das am Feld .value setzt,
 * .focus() aufruft oder disabled/aria-invalid/required ändert, wirkt auch auf das sichtbare Feld (Spiegelung).
 * Typwechsel (z. B. „ganztägig“, _dateonly.js): retype(feld, () => { feld.type = …; feld.value = …; }).
 * Kalender: im selben Dialog bzw. derselben Schatten-Wurzel wie das Feld (Stylesheets dort), Lage per CSSOM (CSP).
 */
const d = document;
const SELF = d.currentScript?.src || '';   // admin.js (klassisches Skript); im Modul datetime.mjs kommt base als Option
// eslint-disable-next-line no-undef
const VER = typeof A11Y_DT_VERSION === 'string' ? A11Y_DT_VERSION : '';
export const DT_SEL = 'input[type=date],input[type=time],input[type=datetime-local]';
const FMT = { date: 'Y-m-d', time: 'H:i', 'datetime-local': 'Y-m-d\\TH:i' };
const ALT = {
  de: { date: 'd.m.Y', time: 'H:i', 'datetime-local': 'd.m.Y H:i' },
  en: { date: 'd/m/Y', time: 'H:i', 'datetime-local': 'd/m/Y H:i' },
};
const PH = {
  de: { date: 'TT.MM.JJJJ', time: 'HH:MM', 'datetime-local': 'TT.MM.JJJJ HH:MM' },
  en: { date: 'DD/MM/YYYY', time: 'HH:MM', 'datetime-local': 'DD/MM/YYYY HH:MM' },
};
const VALUE = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

let libP = null;
const localeP = {};
const script = src => new Promise((ok, no) => {
  const s = d.createElement('script');
  s.src = src; s.onload = ok; s.onerror = no;
  d.head.append(s);
});
const vendor = base => new URL('../vendor/a11y_datetime/' + VER + '/', base || SELF || new URL('/assets/js/', location.href)).href;
function lib(o) {
  const v = vendor(o.base);
  libP ||= script(v + 'a11y_datetime.min.js').then(() => window.a11y_datetime || window.flatpickr);
  if (o.lang !== 'de') return libP;
  return (localeP.de ||= libP.then(F => (F.l10ns?.de ? F : script(v + 'l10n/de.min.js').then(() => F))));
}

/** Stylesheets einmal je Wurzel (Dokument bzw. Schatten-Wurzel der Seitenleiste/Ebene) */
const cssDone = new WeakSet();
function css(root, o) {
  if (cssDone.has(root)) return;
  cssDone.add(root);
  for (const href of [vendor(o.base) + 'a11y_datetime.min.css', ...(o.css || [])]) {
    const into = root === d ? d.head : root;
    if ([...into.querySelectorAll('link[rel=stylesheet]')].some(l => l.href === href)) continue;
    const l = d.createElement('link');
    l.rel = 'stylesheet'; l.href = href;
    root === d ? d.head.append(l) : root.prepend(l);
  }
}

/** Geräte ohne feinen Zeiger (Telefon, Tablet ohne Maus) */
const touchOnly = () => matchMedia('(hover: none) and (pointer: coarse)').matches;

/** Getippte Werte: Anzeigeformat, ISO (2026-11-21) und zweistellige Jahre (21.11.26) */
function parser(F, kind, lang) {
  return (str, format) => {
    const s = String(str).trim();
    let m;
    if ((m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{1,2}):(\d{2}))?/))) return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0));
    if (kind === 'time' && (m = s.match(/^(\d{1,2})[:.]?(\d{2})$/))) { const t = new Date(); t.setHours(+m[1], +m[2], 0, 0); return t; }
    const re = lang === 'de' ? /^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})(?:,?\s+(\d{1,2})[:.](\d{2}))?$/ : /^(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})(?:,?\s+(\d{1,2})[:.](\d{2}))?$/;
    if ((m = s.match(re))) {
      const y = m[3].length === 2 ? 2000 + +m[3] : +m[3];
      const dt = new Date(y, +m[2] - 1, +m[1], +(m[4] || 0), +(m[5] || 0));
      return dt.getMonth() === +m[2] - 1 ? dt : undefined;   // 31.02. → ungültig statt 03.03.
    }
    return F.parseDate(s, format);
  };
}

/**
 * Lage des Kalenders: unter dem Feld (sonst darüber), im sichtbaren Bereich. Bezug ist der Container, in dem der Kalender
 * liegt (Dialog, Schatten-Host, Seite) – so stimmt sie auch in festen Seitenleisten und in Dialogen mit Bildlauf.
 */
function place(fp, el) {
  const cal = fp.calendarContainer;
  if (!cal) return;
  const ref = (el || fp._positionElement).getBoundingClientRect();
  const op = cal.offsetParent;
  let ox = -scrollX, oy = -scrollY;
  if (op && !((op === d.body || op === d.documentElement) && getComputedStyle(op).position === 'static')) {
    const r = op.getBoundingClientRect();
    ox = r.left + op.clientLeft - op.scrollLeft;
    oy = r.top + op.clientTop - op.scrollTop;
  }
  const w = cal.offsetWidth, h = cal.offsetHeight, vw = d.documentElement.clientWidth, vh = innerHeight;
  const below = vh - ref.bottom >= h + 6 || ref.top < h + 6;
  const top = below ? ref.bottom + 4 : ref.top - h - 4;
  const left = Math.max(8, Math.min(ref.left, vw - w - 8));
  cal.style.setProperty('top', Math.round(top - oy) + 'px');
  cal.style.setProperty('left', Math.round(left - ox) + 'px');
  cal.style.setProperty('right', 'auto');
  cal.classList.toggle('dt-above', !below);
}

/** Erstes sinnvolles Ziel im geöffneten Kalender: gewählter Tag, heute, erster wählbarer Tag – bzw. die Uhrzeit */
function focusInside(fp) {
  const c = fp.calendarContainer;
  if (!c) return;
  const t = fp.config.noCalendar
    ? c.querySelector('.flatpickr-time button, .flatpickr-time input')
    : c.querySelector('.flatpickr-day.selected') || c.querySelector('.flatpickr-day.today:not(.flatpickr-disabled)')
      || c.querySelector('.flatpickr-day:not(.flatpickr-disabled):not(.prevMonthDay):not(.nextMonthDay)');
  t?.focus();
}

/** Sprungmarken auf das (versteckte) Originalfeld, z. B. aus der Fehlerübersicht: sichtbares Feld fokussieren */
const linkDone = new WeakSet();
function links(root) {
  if (linkDone.has(root)) return;
  linkDone.add(root);
  root.addEventListener('click', e => {
    const a = e.target.closest?.('a[href^="#"]');
    if (!a || a.getAttribute('href').length < 2) return;
    const id = decodeURIComponent(a.getAttribute('href').slice(1));
    const inp = (root.getElementById ? root : d).getElementById(id);
    if (!inp?._dt) return;
    e.preventDefault();
    const alt = inp._dt.fp.altInput;
    alt.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    alt.focus({ preventScroll: true });
  });
}

/**
 * Tabellenstruktur für Screenreader: a11y_datetime 5.3.3 legt die Tage (gridcell) ohne Zeilen direkt ins Raster und die
 * Wochentage (columnheader) außerhalb davon ab (axe aria-required-children/-parent, WCAG 1.3.1). Daher ein eigenes Raster
 * (.dt-grid, role=grid) neben den Tagen: seine Zeilen (role=row) übernehmen per aria-owns die Wochentage bzw. je sieben Tage;
 * der Tage-Container wird zur reinen Präsentation. Das DOM, nach dem der Picker navigiert (daysContainer.children[0] = Monat),
 * bleibt unverändert. Nach jedem Monatswechsel neu (Beobachter am Tage-Container).
 */
function gridRows(fp) {
  const days = fp.daysContainer, cal = fp.calendarContainer;
  if (!days || !cal || !days.children[0]) return;
  let grid = cal.querySelector('.dt-grid');
  if (!grid) {
    grid = d.createElement('div');
    grid.className = 'dt-grid';
    grid.setAttribute('role', 'grid');
    grid.setAttribute('aria-label', days.getAttribute('aria-label') || '');
    days.after(grid);
  }
  days.setAttribute('role', 'presentation');
  days.removeAttribute('aria-label');
  grid.replaceChildren();
  const base = cal.id || (cal.id = 'dt-' + Math.random().toString(36).slice(2, 9));
  const row = (cells, k) => {
    if (!cells.length) return;
    const r = d.createElement('div');
    r.setAttribute('role', 'row');
    r.setAttribute('aria-owns', cells.map((c, i) => (c.id ||= `${base}-${k}-${i}`)).join(' '));
    grid.append(r);
  };
  row([...(fp.weekdayContainer?.querySelectorAll('[role=columnheader]') || [])].slice(0, 7), 'h');
  const list = [...days.children[0].children];
  for (let i = 0; i < list.length; i += 7) row(list.slice(i, i + 7), 'w' + i / 7);
}

function enhance(inp, F, o) {
  const kind = inp.type, lang = o.lang === 'de' ? 'de' : 'en';
  const fmt = FMT[kind], root = inp.getRootNode();
  const step = +(inp.getAttribute('step') || 60);
  const box = inp.closest('dialog,[popover]') || (root instanceof ShadowRoot ? root : d.body);
  const cfg = {
    dateFormat: fmt,
    altInput: true,
    altFormat: ALT[lang][kind],
    ariaDateFormat: lang === 'de' ? 'l, j. F Y' : 'l j F Y',   // Ansage der Tage: „Sonntag, 22. November 2026“
    altInputClass: [...inp.classList].filter(c => c !== 'flatpickr-input').concat('dt-alt').join(' '),
    allowInput: true,           // tippen bleibt möglich (Tastatur, Screenreader); Kalender auf Wunsch
    parseDate: parser(F, kind, lang),
    enableTime: kind !== 'date',
    noCalendar: kind === 'time',
    time_24hr: true,
    locale: lang === 'de' && F.l10ns?.de ? 'de' : { firstDayOfWeek: 1 },
    disableMobile: true,        // Telefon: entweder dieser Picker oder (mode 'desktop') gar keiner – nie die Kopie von flatpickr
    appendTo: box,
    // Jahresauswahl: Geburtsdaten (autocomplete=bday) weit zurück, sonst ± 30 Jahre; das Jahr lässt sich auch eintippen
    yearRange: /\bbday\b/.test(inp.autocomplete) ? { past: 120, future: 0 } : { past: 30, future: 30 },
    yearWheelManualInput: true,
    position: place,
  };
  if (kind !== 'date') {
    if (step % 60) cfg.enableSeconds = true;
    else if (step > 60 && step < 3600) cfg.minuteIncrement = step / 60;
  }
  if (kind === 'time') {
    if (inp.min) cfg.minTime = inp.min;
    if (inp.max) cfg.maxTime = inp.max;
  } else {
    if (inp.min) cfg.minDate = inp.min;
    if (inp.max) cfg.maxDate = inp.max;
  }
  const fp = F(inp, cfg);
  const alt = fp.altInput;
  if (!alt) { fp.destroy?.(); return; }
  fp.calendarContainer?.classList.add('dt', o.theme || 'dt-adm');
  let gridMo = null;
  if (fp.daysContainer) {
    gridRows(fp);
    gridMo = new MutationObserver(recs => {
      if (recs.some(r => r.addedNodes.length)) gridRows(fp);
    });
    gridMo.observe(fp.daysContainer, { childList: true });
  }
  // a11y_datetime 5.3.3 setzt diese Beschriftungen fest auf Englisch (nicht in der Sprachdatei)
  if (lang === 'de') {
    for (const [el, label] of [[fp.closeButton, 'Kalender schließen'], [fp.daysContainer, 'Tage'], [fp.prevMonthNav, 'Vorheriger Monat'], [fp.nextMonthNav, 'Nächster Monat']]) {
      el?.setAttribute('aria-label', label);
    }
  }
  inp._dtType = kind;
  inp.dataset.dtOn = '';

  // Sichtbares Feld: Beschriftung, Format-Hinweis, Rolle wie das „Date Picker Combobox“-Muster (WAI-ARIA APG)
  if (inp.id) alt.id = inp.id + '-dt';
  if (!inp.placeholder) alt.placeholder = PH[lang][kind];
  alt.autocomplete = 'off';
  alt.setAttribute('role', 'combobox');
  alt.setAttribute('aria-autocomplete', 'none');
  alt.spellcheck = false;
  // Telefon/Tablet: keine Bildschirmtastatur über dem Kalender (Auswahl per Antippen; Pflichtfeld-Prüfung bleibt, anders als readonly)
  if (touchOnly()) alt.inputMode = 'none';
  alt.size = Math.max(alt.placeholder.length, 10);
  const labels = inp.id ? [...root.querySelectorAll(`label[for="${CSS.escape(inp.id)}"]`)] : [];
  labels.forEach(l => { l.htmlFor = alt.id; });

  // Spiegeln: Attribute, die Skripte oder Fehlermeldungen am Originalfeld setzen
  const MIRROR = ['disabled', 'required', 'aria-invalid', 'aria-describedby', 'aria-errormessage'];
  const copy = a => (inp.hasAttribute(a) ? alt.setAttribute(a, inp.getAttribute(a)) : alt.removeAttribute(a));
  MIRROR.forEach(copy);
  const mo = new MutationObserver(recs => { recs.forEach(r => copy(r.attributeName)); if (inp.disabled) fp.close(); });
  mo.observe(inp, { attributes: true, attributeFilter: MIRROR });

  // .value = … und .focus() am Originalfeld wirken auf den Picker
  let busy = false;
  Object.defineProperty(inp, 'value', {
    configurable: true,
    get() { return VALUE.get.call(this); },
    set(v) {
      VALUE.set.call(this, v);
      if (busy) return;
      busy = true;
      try { v ? fp.setDate(v, false, fmt) : fp.clear(false); } finally { busy = false; }
    },
  });
  inp.focus = opts => alt.focus(opts);

  // Tastatur: Pfeil nach unten (auch mit Alt) öffnet den Kalender und setzt den Fokus hinein; Enter öffnet ihn,
  // solange nichts getippt wurde (sonst übernimmt Enter den getippten Wert)
  let atFocus = alt.value;
  const onFocus = () => { atFocus = alt.value; };
  const onKey = e => {
    // Getippt und mit Tab weiter (auch in den offenen Kalender): Wert übernehmen, bevor der Fokus wandert
    if (e.key === 'Tab' && alt.value !== atFocus) { fp.setDate(alt.value, true, cfg.altFormat); atFocus = alt.value; return; }
    if (fp.isOpen || alt.disabled) return;
    if (e.key === 'ArrowDown' || (e.key === 'Enter' && alt.value === atFocus)) {
      e.preventDefault();
      e.stopImmediatePropagation();
      fp.open();
      requestAnimationFrame(() => focusInside(fp));
    }
  };
  alt.addEventListener('focus', onFocus);
  alt.addEventListener('keydown', onKey, true);

  // Bildlauf in Seitenleisten/Dialogen: Kalender folgt dem Feld
  const follow = () => { if (fp.isOpen) place(fp); };
  const scrollRoots = [d, ...(root !== d ? [root] : [])];
  fp.config.onOpen.push(() => scrollRoots.forEach(r => r.addEventListener('scroll', follow, true)));
  fp.config.onClose.push(() => scrollRoots.forEach(r => r.removeEventListener('scroll', follow, true)));

  inp._dt = {
    fp, o,
    destroy() {
      mo.disconnect(); gridMo?.disconnect();
      scrollRoots.forEach(r => r.removeEventListener('scroll', follow, true));
      labels.forEach(l => { l.htmlFor = inp.id; });
      delete inp.value; delete inp.focus; delete inp._dtType; delete inp._dt;
      inp.removeAttribute('data-dt-on');
      fp.destroy();
    },
  };
}

/** Feldtyp ändern (Datum ↔ Datum + Uhrzeit): Picker abbauen, fn() ändert Typ/Wert, Picker neu aufbauen */
export function retype(inp, fn) {
  const dt = inp._dt;
  if (!dt) { fn(); return; }
  const o = dt.o;
  dt.destroy();
  fn();
  initDatetime(inp, o);
}

/** Typ des Felds wie im HTML (auch wenn der Picker es gerade versteckt) */
export const dtType = inp => inp._dtType || inp.type;

export function initDatetime(scope = d, o = {}) {
  const mode = o.mode || 'picker';
  if (mode === 'native' || (mode === 'desktop' && touchOnly()) || !VER) return;
  const list = [...(scope.matches?.(DT_SEL) ? [scope] : []), ...(scope.querySelectorAll?.(DT_SEL) || [])]
    .filter(i => !i._dt && !i.readOnly && !i.hasAttribute('data-native') && !i.closest('.flatpickr-calendar'));
  if (!list.length) return;
  lib(o).then(F => {
    if (typeof F !== 'function') return;
    for (const inp of list) {
      if (inp._dt || !inp.isConnected || !FMT[inp.type]) continue;
      css(inp.getRootNode(), o);
      links(inp.getRootNode());
      try { enhance(inp, F, o); } catch (err) { console.error('[datetime]', err); }
    }
  }).catch(err => console.error('[datetime]', err));
}

/**
 * Später eingefügte Felder (Dialoge, Seitenleisten, Wiederholungen …) automatisch erweitern: beobachtet die Wurzel.
 */
const watched = new WeakSet();
export function watchDatetime(root, o) {
  if (!root || watched.has(root) || !('MutationObserver' in window)) return;
  watched.add(root);
  let queued = [];
  new MutationObserver(recs => {
    const first = !queued.length;
    for (const r of recs) for (const n of r.addedNodes) if (n.nodeType === 1 && (n.matches(DT_SEL) || n.firstElementChild)) queued.push(n);
    if (first && queued.length) queueMicrotask(() => { const q = queued; queued = []; q.forEach(n => n.isConnected && initDatetime(n, o)); });
  }).observe(root === d ? d.documentElement : root, { childList: true, subtree: true });
}
