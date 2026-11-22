/**
 * Schriftgröße der Verwaltung (Core\UiScale): Maße der Verwaltungs-CSS mitwachsen lassen.
 *
 * Die Quellen rechnen in px. Beim Bauen werden die Längen umgeschrieben – die Quellen bleiben lesbar und unverändert:
 *   toRem()    Verwaltung (eigenes Dokument): 12px → .75rem. Die Wurzel <html class="adm-ui" data-fs="125"> setzt die
 *              Schriftgröße (resources/css/_uiscale.css, in pt angegeben, damit sie selbst nicht umgeschrieben wird).
 *   toScaled() Schatten-Wurzeln auf der Website (rem gehört dort dem Kit): 12px → calc(12px*var(--adm-s,1)); --adm-s setzt
 *              :host(.adm-ui[data-fs=…]) bzw. erbt es von <html class="adm-ui"> in der Verwaltung.
 * Unverändert bleiben: Haarlinien (|Wert| ≤ 1px), Bedingungen von @media/@supports (Bildschirmbreite bleibt
 * Bildschirmbreite), @property-Blöcke, Zeichenketten und url(…). Bedingungen von @container rechnet toRem() mit um (rem bezieht
 * sich dort auf die Wurzel – schmale Spalten schalten bei großer Schrift früher um); toScaled() lässt sie (var() ist dort nicht erlaubt).
 */

/** Ersetzt px-Werte außerhalb von Zeichenketten, url() und At-Regel-Bedingungen; fn(zahl, original) → Ersatz */
export function mapPx(css, fn, { container = false } = {}) {
  let out = '';
  let i = 0;
  const n = css.length;
  while (i < n) {
    const c = css[i];
    // Zeichenketten unverändert
    if (c === '"' || c === "'") {
      let j = i + 1;
      while (j < n && css[j] !== c) j += css[j] === '\\' ? 2 : 1;
      out += css.slice(i, j + 1); i = j + 1; continue;
    }
    // Kommentare (falls nicht minifiziert)
    if (c === '/' && css[i + 1] === '*') {
      const j = css.indexOf('*/', i + 2); const e = j < 0 ? n : j + 2;
      out += css.slice(i, e); i = e; continue;
    }
    // url(…) unverändert
    if ((c === 'u' || c === 'U') && /^url\(/i.test(css.slice(i, i + 4)) && !/[\w-]/.test(css[i - 1] || '')) {
      let j = i + 4, depth = 1;
      while (j < n && depth) { if (css[j] === '(') depth++; else if (css[j] === ')') depth--; else if (css[j] === '"' || css[j] === "'") { const q = css[j]; j++; while (j < n && css[j] !== q) j += css[j] === '\\' ? 2 : 1; } j++; }
      out += css.slice(i, j); i = j; continue;
    }
    // At-Regeln: Bedingung bis „{“ bzw. „;“ unverändert; @property-Block ganz
    if (c === '@') {
      if (container && /^@container\b/i.test(css.slice(i, i + 11))) { out += '@container'; i += 10; continue; }
      let j = i + 1;
      while (j < n && css[j] !== '{' && css[j] !== ';') {
        if (css[j] === '"' || css[j] === "'") { const q = css[j]; j++; while (j < n && css[j] !== q) j += css[j] === '\\' ? 2 : 1; }
        j++;
      }
      if (/^@property\b/i.test(css.slice(i, i + 9)) && css[j] === '{') {
        const e = css.indexOf('}', j); j = e < 0 ? n - 1 : e;
      }
      out += css.slice(i, j + 1); i = j + 1; continue;
    }
    // Zahl mit Einheit px (nicht Teil eines Bezeichners wie --abc-12px)
    if (/[\d.]/.test(c) && !/[\w-]/.test(css[i - 1] || '') || (c === '-' && /[\d.]/.test(css[i + 1] || '') && !/[\w-]/.test(css[i - 1] || ''))) {
      const m = /^-?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?([a-z%]*)/i.exec(css.slice(i, i + 40));
      if (m) {
        const tok = m[0];
        if (m[1].toLowerCase() === 'px' && !/[\w-]/.test(css[i + tok.length] || '')) out += fn(parseFloat(tok), tok);
        else out += tok;
        i += tok.length; continue;
      }
    }
    out += c; i++;
  }
  return out;
}

const num = v => {
  const s = String(+v.toFixed(4));
  return s.replace(/^(-?)0\./, '$1.');
};
const keep = v => Math.abs(v) <= 1;

/** 12px → .75rem (Verwaltung) */
export const toRem = css => mapPx(css, (v, tok) => (keep(v) ? tok : (v === 0 ? '0' : num(v / 16) + 'rem')), { container: true });

/** 12px → calc(12px*var(--adm-s,1)) (Schatten-Wurzeln) */
export const toScaled = css => mapPx(css, (v, tok) => (keep(v) ? tok : `calc(${num(v)}px*var(--adm-s,1))`));

/** Selbstprüfung (node tools/adm-scale.mjs) */
if (import.meta.url === `file://${process.argv[1]}`) {
  const cases = [
    ['a{padding:12px 1px -8px;width:calc(100% - 110px)}', 'a{padding:.75rem 1px -.5rem;width:calc(100% - 6.875rem)}'],
    ['@media (max-width:900px){a{top:24px}}', '@media (max-width:900px){a{top:1.5rem}}'],
    ['a{background:url("x-12px.svg");content:"12px";--g-12px:3px}', 'a{background:url("x-12px.svg");content:"12px";--g-12px:.1875rem}'],
    ['@property --x{syntax:"<length>";initial-value:12px;inherits:false}a{b:0px}', '@property --x{syntax:"<length>";initial-value:12px;inherits:false}a{b:0px}'],
    ['a{font:400 15px/1.55 x;margin:.5px}', 'a{font:400 .9375rem/1.55 x;margin:.5px}'],
    ['@container dtlist (max-width:860px){a{gap:8px}}', '@container dtlist (max-width:53.75rem){a{gap:.5rem}}'],
  ];
  let bad = 0;
  for (const [i, want] of cases) { const got = toRem(i); if (got !== want) { bad++; console.log('FEHLER', i, '→', got, '≠', want); } }
  const s = toScaled('a{top:24px;left:1px}');
  if (s !== 'a{top:calc(24px*var(--adm-s,1));left:1px}') { bad++; console.log('FEHLER toScaled', s); }
  console.log(bad ? `${bad} Fehler` : 'adm-scale: ok');
  process.exit(bad ? 1 : 0);
}
