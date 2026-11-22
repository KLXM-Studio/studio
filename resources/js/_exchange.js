/*
 * Austausch (Core\Data\Exchange, app/Admin/views/data/import.php): vCard- bzw. ICS-Datei ziehen und sofort zur Vorschau schicken.
 * Ohne JavaScript bleibt das normale Formular mit Dateiauswahl.
 * - Import-Seite: Ablagefläche [data-imp-drop] im Formular [data-imp-form] – Datei wählen oder loslassen → Vorschau
 * - Adressbuch und Kalender ([data-imp-target]): Datei auf die Ansicht ziehen → Hinweis „loslassen zum Importieren“ → Vorschau
 */
import { csrf } from './_html.js';

const hasFiles = ev => [...(ev.dataTransfer?.types || [])].includes('Files');

export function initExchange() {
  const form = document.querySelector('[data-imp-form]');
  if (form) {
    const drop = form.querySelector('[data-imp-drop]');
    const input = form.querySelector('[data-imp-file]');
    const name = form.querySelector('[data-imp-name]');
    const show = () => {
      if (name) name.textContent = input.files[0]?.name || '';
      drop?.classList.toggle('has-file', input.files.length > 0);
    };
    input?.addEventListener('change', () => { show(); if (input.files.length) form.requestSubmit(); });
    if (drop && input) {
      ['dragenter', 'dragover'].forEach(t => drop.addEventListener(t, ev => { if (!hasFiles(ev)) return; ev.preventDefault(); drop.classList.add('is-over'); }));
      ['dragleave', 'dragend'].forEach(t => drop.addEventListener(t, () => drop.classList.remove('is-over')));
      drop.addEventListener('drop', ev => {
        ev.preventDefault();
        drop.classList.remove('is-over');
        const f = ev.dataTransfer?.files;
        if (!f?.length) return;
        const dt = new DataTransfer();
        dt.items.add(f[0]);
        input.files = dt.files;
        show();
        form.requestSubmit();
      });
    }
  }

  // Ansicht Adressbuch/Kalender: ganze Fläche nimmt die Datei an (Teile der Adressbuch-Ansicht werden ersetzt → Delegation)
  if (!document.querySelector('[data-imp-target]')) return;
  let depth = 0;
  let over = null;
  const target = () => document.querySelector('[data-imp-target]');
  const hide = () => { depth = 0; over?.remove(); over = null; target()?.classList.remove('is-drop'); };
  document.addEventListener('dragenter', ev => {
    const t = target();
    if (!t || !hasFiles(ev)) return;
    depth++;
    if (over) return;
    over = document.createElement('div');
    over.className = 'imp-over';
    over.setAttribute('role', 'status');
    over.textContent = t.dataset.impLabel || '';
    t.classList.add('is-drop');
    t.append(over);
  });
  document.addEventListener('dragover', ev => { if (target() && hasFiles(ev)) ev.preventDefault(); });
  document.addEventListener('dragleave', () => { if (over && --depth <= 0) hide(); });
  document.addEventListener('drop', ev => {
    const t = target();
    if (!t || !hasFiles(ev)) return;
    ev.preventDefault();
    hide();
    const file = ev.dataTransfer.files[0];
    if (!file) return;
    const f = document.createElement('form');
    f.method = 'post';
    f.enctype = 'multipart/form-data';
    f.action = t.dataset.impTarget;
    f.hidden = true;
    for (const [k, v] of [['_csrf', csrf()], ['step', 'preview']]) {
      const i = document.createElement('input');
      i.type = 'hidden';
      i.name = k;
      i.value = v;
      f.append(i);
    }
    const inp = document.createElement('input');
    inp.type = 'file';
    inp.name = 'datei';
    const dt = new DataTransfer();
    dt.items.add(file);
    inp.files = dt.files;
    f.append(inp);
    document.body.append(f);
    f.submit();
  });
}
