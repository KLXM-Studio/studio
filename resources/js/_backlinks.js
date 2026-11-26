/*
 * Rückverknüpfungen im Formular eines Eintrags (app/Admin/views/data/_backlinks.php, Core\Data\Backlinks):
 *  - „+ … verknüpfen …“: Auswahlfenster (_picker.js) der anderen Tabelle, gewählte Einträge bekommen das Verknüpfungsfeld gesetzt
 *  - „+ … neu“: Formular der anderen Tabelle im Dialog (iframe, ?eingebettet=1, Feld vorbelegt über ?mit[feld]=ID); nach dem
 *    Speichern schließt der Dialog und die Liste lädt neu
 *  - „×“: Verknüpfung lösen (Rückfrage)
 * Nur die Liste wird nachgeladen (Abschnitt #rueckverknuepfungen), das Formular selbst bleibt unberührt (ungespeicherte Eingaben bleiben).
 */
import { t } from './_i18n.js';
import { openEntryPicker } from './_picker.js';

const d = document;
const csrf = () => d.getElementById('adm-csrf')?.value || d.querySelector('input[name=_csrf]')?.value || '';

export function initBacklinks(ask, toast) {
  const box = d.querySelector('[data-backlinks]');
  if (!box || box._bl) return;
  box._bl = true;
  const target = box.dataset.target;

  const post = (table, body) => fetch(box.dataset.api + encodeURIComponent(table), {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
    body: JSON.stringify({ ...body, target, _csrf: csrf() }),
  }).then(r => r.json()).catch(() => ({ ok: false }));

  // Nur den Abschnitt neu laden (Seite per fetch, Abschnitt austauschen) – Eingaben im Formular bleiben erhalten
  const reload = async () => {
    try {
      const html = await (await fetch(location.href, { credentials: 'same-origin', headers: { Accept: 'text/html' } })).text();
      const fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('rueckverknuepfungen');
      const cur = d.getElementById('rueckverknuepfungen');
      if (fresh && cur) {
        const open = [...cur.querySelectorAll('details[open]')].map(x => x.closest('[data-bl-group]')?.dataset.field + '|' + x.className);
        cur.replaceWith(fresh);
        fresh.querySelectorAll('details').forEach(x => { if (open.includes(x.closest('[data-bl-group]')?.dataset.field + '|' + x.className)) x.open = true; });
        box._bl = false;
        initBacklinks(ask, toast);
      }
    } catch { location.reload(); }
  };

  box.addEventListener('click', async e => {
    const g = e.target.closest('[data-bl-group]');
    if (!g) return;
    const table = g.dataset.table, field = g.dataset.field;

    if (e.target.closest('[data-bl-unlink]')) {
      const li = e.target.closest('[data-bl-row]');
      const title = li.querySelector('.dt-bl__title')?.textContent.trim() || '';
      if (!(await ask({ title: t('Verknüpfung mit „{title}“ lösen?', { title }), ok: t('Lösen') }))) return;
      const res = await post(table, { action: 'unlink', field, row: li.dataset.blRow });
      if (!res.ok) { toast?.(res.error || t('Das hat nicht geklappt.'), 'error'); return; }
      toast?.(t('Verknüpfung gelöst.'));
      reload();
      return;
    }

    if (e.target.closest('[data-bl-link]')) {
      const tb = { handle: table, name: g.dataset.tname, singular: g.dataset.singular, url: box.dataset.picker + encodeURIComponent(table) };
      const picked = await openEntryPicker({ tables: [tb], multiple: true, selected: [], title: t('{name} verknüpfen', { name: g.dataset.tname }) });
      if (!picked?.length) return;
      const res = await post(table, { action: 'link', field, rows: picked.map(p => p.id) });
      if (!res.ok) { toast?.(res.error || t('Das hat nicht geklappt.'), 'error'); return; }
      toast?.(t('{n} verknüpft.', { n: res.done }));
      reload();
      return;
    }

    const nw = e.target.closest('[data-bl-new]');
    if (nw && !e.metaKey && !e.ctrlKey && !e.shiftKey) {
      e.preventDefault();
      openNew(nw.href, g.dataset.singular, table).then(saved => { if (saved) { toast?.(t('Gespeichert und verknüpft.')); reload(); } });
    }
  });
}

// Formular der anderen Tabelle im Dialog; „gespeichert“, sobald das iframe auf der Seite eines Eintrags (…/{table}/{id}) landet
function openNew(href, singular, table) {
  return new Promise(resolve => {
    const dlg = d.createElement('dialog');
    dlg.className = 'adm-dialog dt-bl-dlg';
    dlg.setAttribute('aria-label', t('{singular} neu', { singular }));
    const u = new URL(href, location.href);
    u.searchParams.set('eingebettet', '1');
    dlg.innerHTML = `<div class="adm-dialog__head"><h2>${t('{singular} neu', { singular }).replace(/[<>&]/g, '')}</h2>`
      + `<button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-close>${t('Schließen')}</button></div>`
      + `<iframe class="dt-bl-dlg__frame" title="${t('{singular} neu', { singular }).replace(/"/g, '')}" src="${u.href}"></iframe>`;
    d.body.append(dlg);
    let saved = false;
    const done = () => { dlg.close(); dlg.remove(); resolve(saved); };
    dlg.querySelector('[data-close]').addEventListener('click', done);
    dlg.addEventListener('cancel', e => { e.preventDefault(); done(); });
    const re = new RegExp('/admin/data/' + table.replace(/[^a-z0-9_]/gi, '') + '/(\\d+)(?:$|[?#])');
    dlg.querySelector('iframe').addEventListener('load', ev => {
      try {
        const loc = ev.target.contentWindow.location;
        if (re.test(loc.pathname + loc.search)) { saved = true; done(); }
      } catch { /* andere Herkunft – ignorieren */ }
    });
    dlg.showModal();
  });
}
