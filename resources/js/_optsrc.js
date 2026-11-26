/*
 * Auswahl aus einer Liste (Core\Data\OptionSource, Feld-Einstellung options_from): am Feld „+ … neu“ (Formular der Liste im Dialog,
 * wie bei Rückverknüpfungen) und „Liste bearbeiten“ (Liste im Dialog). Danach lädt das Feld seine Möglichkeiten neu
 * (GET /admin/api/optionen/{tabelle}) – Auswahl und übrige Eingaben im Formular bleiben; ein neuer Eintrag wird gleich gewählt.
 */
import { t } from './_i18n.js';
import { esc } from './_html.js';
import { openNew } from './_backlinks.js';

const d = document;

async function refresh(box, pickNew) {
  const field = box.closest('.f');
  if (!field) return;
  let res = null;
  try { res = await (await fetch(box.dataset.optsrcApi, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json(); } catch { return; }
  if (!res?.ok) return;
  const sel = field.querySelector('select');
  if (sel) {
    const before = new Set([...sel.options].map(o => o.value));
    const cur = sel.value;
    const empty = [...sel.options].find(o => o.value === '');
    sel.innerHTML = (empty ? empty.outerHTML : '') + res.options.map(([k, l]) => `<option value="${esc(k)}">${esc(l)}</option>`).join('');
    const fresh = pickNew ? res.options.find(([k]) => !before.has(k)) : null;
    sel.value = fresh ? fresh[0] : cur;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    return;
  }
  const list = field.querySelector('.f-multi');
  const hidden = field.querySelector('input[type=hidden][name$="[]"]');
  if (!list || !hidden) return;
  const before = new Set([...list.querySelectorAll('input[type=checkbox]')].map(i => i.value));
  const checked = new Set([...list.querySelectorAll('input:checked')].map(i => i.value));
  const fresh = pickNew ? res.options.find(([k]) => !before.has(k)) : null;
  if (fresh) checked.add(fresh[0]);
  list.innerHTML = res.options.map(([k, l]) => `<label class="f-check"><input type="checkbox" name="${esc(hidden.name)}" value="${esc(k)}"${checked.has(k) ? ' checked' : ''}> <span>${esc(l)}</span></label>`).join('');
  list.dispatchEvent(new Event('change', { bubbles: true }));
}

function openList(href, title) {
  return new Promise(resolve => {
    const dlg = d.createElement('dialog');
    dlg.className = 'adm-dialog dt-bl-dlg';
    dlg.setAttribute('aria-label', title);
    const u = new URL(href, location.href);
    u.searchParams.set('eingebettet', '1');
    dlg.innerHTML = `<div class="adm-dialog__head"><h2>${esc(title)}</h2><button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-close>${esc(t('Fertig'))}</button></div>`
      + `<iframe class="dt-bl-dlg__frame" title="${esc(title)}" src="${esc(u.href)}"></iframe>`;
    d.body.append(dlg);
    // Links in der Liste behalten die eingebettete Ansicht (ohne Seitenleiste)
    dlg.querySelector('iframe').addEventListener('load', ev => {
      try {
        const w = ev.target.contentWindow;
        if (!w.location.search.includes('eingebettet=1')) { const v = new URL(w.location.href); v.searchParams.set('eingebettet', '1'); w.location.replace(v.href); }
      } catch { /* andere Herkunft */ }
    });
    const done = () => { dlg.close(); dlg.remove(); resolve(); };
    dlg.querySelector('[data-close]').addEventListener('click', done);
    dlg.addEventListener('cancel', e => { e.preventDefault(); done(); });
    dlg.showModal();
  });
}

export function initOptSrc() {
  d.addEventListener('click', async e => {
    const a = e.target.closest('.f-optsrc a');
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey) return;
    const box = a.closest('[data-optsrc]');
    e.preventDefault();
    if (a.hasAttribute('data-optsrc-new')) {
      const saved = await openNew(a.href, a.textContent.replace(/^\+\s*/, '').replace(/\s+neu$/, ''), box.dataset.optsrc);
      if (saved) refresh(box, true);
    } else {
      await openList(a.href, a.textContent.trim());
      refresh(box, false);
    }
  });
}
