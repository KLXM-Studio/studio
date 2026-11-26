/*
 * Löschen mit Neuzuordnung (Core\Data\Reassign): Vor dem Löschen fragt die Verwaltung, wer auf die Einträge verweist
 * (GET /admin/api/neuzuordnung/{tabelle}?ids=…). Ohne Verweise: die gewohnte Rückfrage. Mit Verweisen: je Feld
 * „Leer lassen“ oder „Einem anderen Eintrag zuordnen“ (Auswahlfenster derselben Tabelle).
 * askDelete({ handle, ids, title, body, ask }) → null (abgebrochen) oder { 'tabelle.feld': Ziel-ID, … } (leer = nichts umhängen).
 */
import { t } from './_i18n.js';
import { esc } from './_html.js';
import { openEntryPicker } from './_picker.js';

const d = document;

export async function askDelete({ handle, ids, title, body = '', ask, api }) {
  let info = null;
  try {
    info = await (await fetch(api + encodeURIComponent(handle) + '?ids=' + ids.join(','), { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
  } catch { info = null; }
  if (!info?.ok || !info.groups?.length) return (await ask({ title, body, ok: t('Löschen') })) ? {} : null;
  return dialog(info, ids, title, body);
}

function dialog(info, ids, title, body) {
  return new Promise(resolve => {
    const dlg = d.createElement('dialog');
    dlg.className = 'adm-dialog adm-dialog--small cms-confirm ra-dlg';
    dlg.setAttribute('aria-labelledby', 'ra-t');
    const groups = info.groups.map((g, i) => `
      <fieldset class="ra-g" data-ra-key="${esc(g.key)}">
        <legend>${esc(g.label)}:</legend>
        <label class="f-check"><input type="radio" name="ra-${i}" value="empty" checked> <span>${esc(t('Leer lassen'))}</span></label>
        <label class="f-check ra-g__to"><input type="radio" name="ra-${i}" value="to"> <span>${esc(t('Einem anderen Eintrag zuordnen'))}</span></label>
        <span class="ra-g__pick"><button type="button" class="adm-btn adm-btn--small" data-ra-pick aria-haspopup="dialog">${esc(t('{singular} wählen …', { singular: info.table.singular }))}</button>
          <span class="ra-g__chosen" data-ra-chosen></span></span>
      </fieldset>`).join('');
    dlg.innerHTML = `<h2 id="ra-t">${esc(title)}</h2>
      ${body ? `<p>${esc(body)}</p>` : ''}
      <p>${esc(t('Andere Einträge verweisen darauf. Was soll mit diesen Verweisen geschehen?'))}</p>
      ${groups}
      <p class="lp__error ra-err" role="alert" hidden></p>
      <div class="cms-confirm__foot"><span class="cms-confirm__right">
        <button type="button" class="adm-btn" data-r="cancel">${esc(t('Abbrechen'))}</button>
        <button type="button" class="adm-btn adm-btn--danger" data-r="ok">${esc(t('Löschen'))}</button>
      </span></div>`;
    d.body.append(dlg);
    const chosen = new Map();
    const err = m => { const p = dlg.querySelector('.ra-err'); p.textContent = m; p.hidden = !m; };
    let result = null;
    dlg.addEventListener('click', async e => {
      const pick = e.target.closest('[data-ra-pick]');
      if (pick) {
        const fs = pick.closest('[data-ra-key]');
        const tb = { handle: info.table.handle, name: info.table.name, singular: info.table.singular, url: info.table.url };
        const got = await openEntryPicker({ tables: [tb], multiple: false, selected: [], title: t('Neu zuordnen zu …') });
        const it = got?.[0];
        if (!it) return;
        if (ids.map(Number).includes(Number(it.id))) { err(t('Bitte einen Eintrag wählen, der nicht mitgelöscht wird.')); return; }
        err('');
        chosen.set(fs.dataset.raKey, Number(it.id));
        fs.querySelector('[data-ra-chosen]').textContent = '→ ' + (it.title || '#' + it.id);
        fs.querySelector('input[value=to]').checked = true;
        return;
      }
      const b = e.target.closest('[data-r]');
      if (!b) return;
      if (b.dataset.r === 'ok') {
        const map = {};
        for (const fs of dlg.querySelectorAll('[data-ra-key]')) {
          if (!fs.querySelector('input[value=to]').checked) continue;
          const id = chosen.get(fs.dataset.raKey);
          if (!id) { err(t('Bitte einen Eintrag wählen oder „Leer lassen“.')); fs.querySelector('[data-ra-pick]').focus(); return; }
          map[fs.dataset.raKey] = id;
        }
        result = map;
      }
      dlg.close();
    });
    dlg.addEventListener('close', () => { dlg.remove(); resolve(result); });
    dlg.showModal();
    dlg.querySelector('[data-r=cancel]').focus();
  });
}
