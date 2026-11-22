// Auswahl von Blockarten (app/Admin/views/_block_choice.php – Rolle bearbeiten, Blöcke der Website): je Gruppe ein Kästchen „alle“
// (zeigt auch „teilweise“), in „Rolle bearbeiten“ wählt ein Klick in die Liste „Nur ausgewählte“ mit aus. Ohne JavaScript: Kästchen je Art.
export function initBlockChoice(d = document) {
  d.querySelectorAll('[data-blockchoice]').forEach(box => {
    const items = g => [...g.querySelectorAll('input[type=checkbox]:not([data-blockchoice-all]):not([disabled])')];
    const sync = g => {
      const all = g.querySelector('[data-blockchoice-all]'), list = items(g), n = list.filter(i => i.checked).length;
      if (!all) return;
      all.disabled = !list.length;
      all.checked = list.length > 0 && n === list.length;
      all.indeterminate = n > 0 && n < list.length;
    };
    const some = box.closest('form')?.querySelector('input[name="blocks_mode"][value="some"]');
    box.querySelectorAll('.bc__group').forEach(g => {
      sync(g);
      g.querySelector('[data-blockchoice-all]')?.addEventListener('change', e => { items(g).forEach(i => { i.checked = e.target.checked; }); sync(g); if (some) some.checked = true; });
      g.addEventListener('change', e => { if (!e.target.matches('[data-blockchoice-all]')) { sync(g); if (some) some.checked = true; } });
    });
  });
}
