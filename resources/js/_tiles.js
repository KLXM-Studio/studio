/*
 * Auswahl als Kacheln (Core\Fields::renderTiles, z. B. Raster des Layout-Blocks): Kachel setzt das ausgeblendete Auswahlfeld und
 * meldet die Änderung (input + change) – Seitenleiste und Vorschau reagieren wie bei einem normalen Auswahlfeld.
 * Ohne JavaScript bleibt das Auswahlfeld sichtbar (.f-tiles__select), die Kacheln sind dann ausgeblendet.
 */
export function initTiles(scope = document) {
  scope.querySelectorAll('[data-tiles]').forEach(box => {
    if (box.dataset.tilesInit) return;
    box.dataset.tilesInit = '1';
    // Schemabilder (Varianten): Kacheln in <details class="f-vpick"> – zugeklappt nur die gewählte Variante
    const pick = box.closest('.f-vpick');
    const sel = (pick || box).parentElement.querySelector('#' + CSS.escape(box.dataset.tiles));
    if (!sel) return;
    box.classList.add('is-ready');
    (pick || box).nextElementSibling?.classList.add('is-hidden');
    const sync = () => {
      box.querySelectorAll('.f-tile').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.value === sel.value)));
      const on = box.querySelector('.f-tile[aria-pressed=true]'), cur = pick?.querySelector('.f-vpick__cur');
      if (on && cur) cur.innerHTML = (on.querySelector('svg')?.outerHTML || '') + `<span class="f-vpick__lbl">${on.querySelector('.f-tile__label')?.innerHTML || ''}</span>`;
    };
    box.addEventListener('click', e => {
      const b = e.target.closest('.f-tile');
      if (!b || sel.value === b.dataset.value) return;
      sel.value = b.dataset.value;
      sync();
      sel.dispatchEvent(new Event('input', { bubbles: true }));
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      if (pick) { pick.open = false; pick.querySelector('summary')?.focus(); }
    });
    sel.addEventListener('change', sync);
  });
}
