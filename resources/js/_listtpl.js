/*
 * Feld „Vorlage“ der Datenliste (Core\Blocks\ListTemplates::renderInput) – Seitenleiste des Editors und Formulare der Verwaltung.
 *  - zeigt nur die Vorlagen der gewählten Tabelle (Kacheln mit data-table), „Eigene Einstellungen“ immer;
 *  - neuer Block (data-new, noch ohne Tabelle): nach der Wahl der Tabelle deren Standard-Vorlage vorwählen;
 *  - „Anpassen (Vorlage lösen)“: Werte der Vorlage in die Felder übernehmen, danach eigene Einstellungen;
 *  - „Als Vorlage speichern …“ (Recht design.blocks): aktuelle Einstellungen per POST /admin/api/list-templates als neue Vorlage.
 * Ohne JavaScript bleiben es Optionsfelder; ausgeblendete Darstellungsfelder steuert 'show_if' (admin.js initShowIf).
 */
import { csrf } from './_html.js';
import { t } from './_i18n.js';
import { toast } from './_toast.js';

const $ = (s, el) => el.querySelector(s);
const $$ = (s, el) => [...el.querySelectorAll(s)];
const fire = (el, ...types) => types.forEach(ty => el.dispatchEvent(new Event(ty, { bubbles: true })));

/** Werte (wie im Block: f[name], f[name][]) setzen – Auswahl, Text, Ja/Nein, Feldlisten */
function setValues(form, values) {
  for (const [k, v] of Object.entries(values || {})) {
    const multi = $$(`[name="f[${CSS.escape(k)}][]"]`, form).filter(i => i.type === 'checkbox');
    if (multi.length) { const want = new Set([].concat(v || []).map(String)); multi.forEach(c => { c.checked = want.has(c.value); }); continue; }
    $$(`[name="f[${CSS.escape(k)}]"]`, form).forEach(el => {
      if (el.type === 'hidden' && $$(`[name="f[${CSS.escape(k)}]"]`, form).some(x => x.type === 'checkbox')) return;   // Ja/Nein: Platzhalter „0“
      if (el.type === 'checkbox') el.checked = !!v && v !== '0';
      else if (el.type === 'radio') el.checked = String(v ?? '') === el.value;
      else el.value = v == null ? '' : String(v);
      fire(el, 'change');
    });
  }
}

/** Werte des Formulars als Objekt (nur oberste Ebene f[name] / f[name][]) */
function values(form) {
  const out = {};
  for (const [name, v] of new FormData(form)) {
    const m = name.match(/^f\[([^\]]+)\](\[\])?$/);
    if (!m) continue;
    if (m[2]) { if (v !== '') (out[m[1]] ||= []).push(v); else out[m[1]] ||= []; } else out[m[1]] = v;
  }
  return out;
}

export function initListTemplates(scope = document) {
  $$('[data-ltpl]', scope).forEach(box => {
    if (box._ltpl) return; box._ltpl = true;
    const form = box.closest('form, .cms-drawer, .adm-fields') || scope;
    const tableSel = $('[data-datatable]', form);
    const own = $('input[type=radio][value=""]', box);
    const detach = $('[data-ltpl-detach]', box), manage = $('[data-ltpl-manage]', box);
    let isNew = box.hasAttribute('data-new');
    const checked = () => $('input[type=radio]:checked', box);
    const sync = () => {
      const c = checked();
      if (detach) detach.hidden = !c || c.value === '';
      $$('.ltpl-tile', box).forEach(l => l.classList.toggle('is-on', l.contains(c)));
    };
    const filter = (fromUser = false) => {
      const tbl = tableSel?.value || '';
      $$('.ltpl-tile[data-table]', box).forEach(l => { l.hidden = l.dataset.table !== tbl; });
      if (manage) { manage.hidden = !tbl; manage.href = manage.href.replace(/\/admin\/data.*$/, '/admin/data' + (tbl ? '/' + encodeURIComponent(tbl) + '/vorlagen' : '')); }
      let c = checked();
      if (c && c.closest('.ltpl-tile')?.hidden) { own.checked = true; c = own; fire(own, 'change'); }
      // Neuer Block: nach der Wahl der Tabelle deren Standard vorwählen (bestehende Blöcke bleiben, wie sie sind)
      if (fromUser && isNew && tbl && (!c || c.value === '')) {
        const std = $(`.ltpl-tile[data-default][data-table="${CSS.escape(tbl)}"] input`, box);
        if (std) { std.checked = true; fire(std, 'input', 'change'); }
      }
      if (fromUser && tbl) isNew = false;
      sync();
    };
    tableSel?.addEventListener('change', () => filter(true));
    box.addEventListener('change', sync);
    filter(false);

    detach?.addEventListener('click', () => {
      const c = checked();
      if (!c || c.value === '') return;
      let opts = {};
      try { opts = JSON.parse(c.dataset.options || '{}'); } catch {}
      setValues(form, opts);
      own.checked = true;
      fire(own, 'input', 'change');
      toast(t('Vorlage gelöst – die Einstellungen stehen jetzt in diesem Block und lassen sich ändern.'));
    });

    const saveBtn = $('[data-ltpl-save]', box), nameIn = $('[data-ltpl-name]', box);
    const doSave = async () => {
      const name = nameIn.value.trim(), tbl = tableSel?.value || '';
      if (!tbl) { toast(t('Bitte zuerst eine Tabelle wählen.'), 'warn'); return; }
      if (!name) { nameIn.focus(); toast(t('Bitte einen Namen angeben.'), 'warn'); return; }
      saveBtn.disabled = true;
      try {
        const r = await fetch(box.dataset.save, { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
          body: JSON.stringify({ type: box.dataset.block, table: tbl, name, data: values(form) }) });
        const res = await r.json().catch(() => ({}));
        if (!r.ok || !res.ok) throw new Error(res.error || res.message || t('Speichern fehlgeschlagen.'));
        const id = 'ltpl-n-' + Math.random().toString(36).slice(2, 9);
        const label = document.createElement('label');
        label.className = 'ltpl-tile'; label.htmlFor = id; label.dataset.table = res.table;
        const input = Object.assign(document.createElement('input'), { type: 'radio', id, name: own.name, value: res.key });
        input.dataset.options = JSON.stringify(res.options || {});
        const nm = Object.assign(document.createElement('span'), { className: 'ltpl-tile__name', textContent: res.name });
        label.append(input);
        label.insertAdjacentHTML('beforeend', res.svg || '');   // vom Server erzeugtes Vorschaubild (nur Formen, ListTemplates::preview)
        label.append(nm);
        $('.ltpl-grid', box).append(label);
        input.checked = true;
        nameIn.value = '';
        $('[data-ltpl-savebox]', box)?.removeAttribute('open');
        fire(input, 'input', 'change');
        toast(t('Vorlage „{name}“ gespeichert und gewählt.', { name: res.name }));
      } catch (e) {
        toast(String(e.message || e), 'error');
      } finally { saveBtn.disabled = false; }
    };
    saveBtn?.addEventListener('click', doSave);
    nameIn?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); doSave(); } });
  });
}
