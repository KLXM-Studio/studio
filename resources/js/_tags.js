/*
 * Schlagworte (Core\Data\Tags, Mehrfachauswahl mit freier Eingabe): Chips mit ×, Eingabe mit Vorschlägen aus den vorhandenen
 * Schlagworten, Enter/Komma übernimmt, „Meinten Sie …?“ bei ähnlicher Schreibweise (Groß/klein, Umlaute, 1–2 Tippfehler).
 * Quelle der Wahrheit bleiben die Ankreuzfelder (versteckt) – das Formular sendet wie ohne JavaScript.
 */
import { t } from './_i18n.js';
import { esc } from './_html.js';

const fold = s => s.toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss').replace(/[^a-z0-9]+/g, '');
const norm = s => s.replace(/\s+/g, ' ').trim().slice(0, 60);
function lev(a, b) {
  if (Math.abs(a.length - b.length) > 2) return 9;
  const m = Array.from({ length: a.length + 1 }, (_, i) => [i]);
  for (let j = 1; j <= b.length; j++) m[0][j] = j;
  for (let i = 1; i <= a.length; i++) for (let j = 1; j <= b.length; j++) m[i][j] = Math.min(m[i - 1][j] + 1, m[i][j - 1] + 1, m[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
  return m[a.length][b.length];
}

function setup(fs) {
  if (fs._tags) return;
  fs._tags = true;
  const list = fs.querySelector('[data-tags-list]');
  const fallback = fs.querySelector('[data-tags-new]');
  const name = list.querySelector('input[type=checkbox]')?.name || fallback.name;
  const legend = fs.querySelector('legend')?.textContent.trim() || '';
  list.hidden = true;
  fallback.closest('label').hidden = true;
  fallback.disabled = true;
  const id = 'tg-' + Math.random().toString(36).slice(2, 8);
  const ui = document.createElement('div');
  ui.className = 'f-tags';
  ui.innerHTML = `<ul class="f-tags__chips" role="list"></ul>
    <input type="text" class="f-tags__in" list="${id}" autocomplete="off" placeholder="${esc(t('Schlagwort eingeben, Enter übernimmt'))}" aria-label="${esc(t('{label}: Schlagwort hinzufügen', { label: legend }))}">
    <datalist id="${id}"></datalist><p class="f-tags__hint" aria-live="polite" hidden></p>`;
  list.before(ui);
  const chips = ui.querySelector('.f-tags__chips'), input = ui.querySelector('.f-tags__in'), dl = ui.querySelector('datalist'), hint = ui.querySelector('.f-tags__hint');
  const boxes = () => [...list.querySelectorAll('input[type=checkbox]')];
  const paint = () => {
    chips.innerHTML = boxes().filter(b => b.checked).map(b => `<li class="f-tags__chip">${esc(b.value)} <button type="button" data-tag-x="${esc(b.value)}" aria-label="${esc(t('„{tag}“ entfernen', { tag: b.value }))}">×</button></li>`).join('');
    dl.innerHTML = boxes().filter(b => !b.checked).map(b => `<option value="${esc(b.value)}">`).join('');
  };
  const add = raw => {
    for (const part of raw.split(/[,;]/)) {
      const v = norm(part);
      if (!v) continue;
      const hit = boxes().find(b => b.value.toLowerCase() === v.toLowerCase());
      if (hit) hit.checked = true;
      else list.insertAdjacentHTML('beforeend', `<label class="f-check"><input type="checkbox" name="${esc(name)}" value="${esc(v)}" checked> <span>${esc(v)}</span></label>`);
    }
    paint();
    list.dispatchEvent(new Event('change', { bubbles: true }));
  };
  const similar = v => {
    const f = fold(v);
    if (!f) return null;
    const max = f.length <= 4 ? 1 : 2;
    let best = null, bd = 9;
    for (const b of boxes()) {
      if (b.value.toLowerCase() === v.toLowerCase()) return null;
      const g = fold(b.value), d = g === f ? 0 : lev(f, g);
      if (d <= max && d < bd) { best = b.value; bd = d; }
    }
    return best;
  };
  const showHint = () => {
    const v = norm(input.value), s = v ? similar(v) : null;
    hint.hidden = !s;
    if (s) hint.innerHTML = `${esc(t('Meinten Sie'))} <button type="button" class="f-tags__sugg" data-tag-use="${esc(s)}">„${esc(s)}“</button>?`;
  };
  input.addEventListener('keydown', e => {
    if ((e.key === 'Enter' || e.key === ',') && input.value.trim()) { e.preventDefault(); add(input.value); input.value = ''; hint.hidden = true; }
    else if (e.key === 'Backspace' && !input.value) { const last = boxes().filter(b => b.checked).pop(); if (last) { last.checked = false; paint(); } }
  });
  input.addEventListener('input', showHint);
  input.addEventListener('change', () => { const v = norm(input.value); if (v && boxes().some(b => b.value === v)) { add(v); input.value = ''; hint.hidden = true; } });
  input.addEventListener('blur', () => { if (input.value.trim() && !similar(input.value)) { add(input.value); input.value = ''; } });
  ui.addEventListener('click', e => {
    const x = e.target.closest('[data-tag-x]');
    if (x) { const b = boxes().find(b => b.value === x.dataset.tagX); if (b) b.checked = false; paint(); input.focus(); return; }
    const u = e.target.closest('[data-tag-use]');
    if (u) { add(u.dataset.tagUse); input.value = ''; hint.hidden = true; input.focus(); }
  });
  list.addEventListener('tags:repaint', paint);   // von außen angehakt (KI-Vorschläge, _ai_ui.js)
  paint();
}

export function initTags(scope = document) {
  scope.querySelectorAll('[data-tags]').forEach(setup);
}
