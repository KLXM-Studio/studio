/*
 * Wissensbereich (Core\Data\Knowledge::frame, Stile css/knowledge.css) – lädt nur auf Detailseiten von Wissens-Einträgen ('@knowledge').
 *  - Schublade: Auf dem Telefon (und im Modus „Fokus“ auch breit) öffnet der Knopf „Kapitel“ [data-kn-toggle] die Seitenleiste
 *    [data-kn-drawer]; schließen mit ×, Esc oder Klick daneben, der Fokus springt hinein und zurück zum Knopf. Ohne Skript steht die
 *    Seitenleiste über dem Text (.kn ohne .is-js).
 *  - Mitlaufende Abschnitte: Links „In diesem Kapitel“ [data-kn-sec] bekommen aria-current="true" für den Abschnitt im Blick.
 *  - Kapitel finden [data-kn-search]: Tippen filtert die Kapitel aus dem Index [data-kn-index] (Titel, Nummer, Abschnitte,
 *    Schlagworte; ohne Groß-/Kleinschreibung, ä = ae) – Treffer mit Pfad, Pfeiltasten wählen, Enter öffnet; Enter ohne Auswahl
 *    sendet das Formular (alle Texte). Esc leert. „/“ oder Strg/⌘+K springen ins Suchfeld (öffnet Schublade bzw. Menü).
 *  - Leiste oben [data-kn-bar]: sitzt unter einem festen Kopf der Website und zeigt den Lesefortschritt im Kapitel.
 */
(() => {
  const root = document.querySelector('[data-kn]');
  if (!root) return;
  root.classList.add('is-js');
  const btn = root.querySelector('[data-kn-toggle]');
  const side = root.querySelector('[data-kn-drawer]');
  const close = root.querySelector('[data-kn-close]');
  const drawerMode = () => root.classList.contains('kn--focus') || matchMedia('(max-width:959.98px)').matches;
  if (btn && side) {
    btn.hidden = false;
    if (close) close.hidden = false;
    const set = (open, focus = true) => {
      root.classList.toggle('is-open', open);
      btn.setAttribute('aria-expanded', String(open));
      if (drawerMode()) side.toggleAttribute('inert', !open); else side.removeAttribute('inert');
      // Fokus, sobald die Schublade sichtbar ist – Kits mit kurzer Übergangszeit bei „Bewegung reduzieren“ verzögern die Sichtbarkeit
      // der Links um einige Bilder, daher bis zu 20 Versuche
      const into = (el, n = 0) => { el.focus({ preventScroll: true }); if (document.activeElement !== el && n < 20) requestAnimationFrame(() => into(el, n + 1)); };
      if (open && focus) { const el = side.querySelector('[aria-current="page"]') || side.querySelector('a,input,button'); if (el) into(el); }
      else if (!open && focus && side.contains(document.activeElement)) btn.focus();
    };
    const sync = () => set(false, false);
    sync();
    matchMedia('(max-width:959.98px)').addEventListener?.('change', sync);
    btn.addEventListener('click', () => set(!root.classList.contains('is-open')));
    close?.addEventListener('click', () => set(false));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && root.classList.contains('is-open')) set(false); });
    document.addEventListener('click', (e) => {
      if (root.classList.contains('is-open') && !side.contains(e.target) && !btn.contains(e.target)) set(false);
    });
    // Sprung zu einem Abschnitt aus der Schublade: Schublade zu
    side.addEventListener('click', (e) => { if (e.target.closest('a[href^="#"]') && drawerMode()) set(false, false); });
  }
  // ---- Kapitel finden (Live-Filter)
  const form = root.querySelector('[data-kn-search]');
  const input = form?.querySelector('input[name="q"]');
  let idx = [];
  try { idx = JSON.parse(form?.querySelector('[data-kn-index]')?.textContent || '[]'); } catch { idx = []; }
  if (form && input && idx.length) {
    const hits = form.querySelector('[data-kn-hits]'), count = form.querySelector('[data-kn-count]');
    const nav = root.querySelector('.kn-nav'), all = root.querySelector('.kn-side__all');
    const fold = (t) => String(t).toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    // Titel: Teil eines Worts genügt (Wiederbe-wald-ung); Stichworte nur am Wortanfang – sonst findet „rot“ auch „trotzdem“
    idx.forEach((it) => { it.f = fold(`${it.n} ${it.t}`); it.fk = fold(it.k); it.fw = ' ' + it.fk.replace(/[^a-z0-9]+/g, ' '); });
    const esc = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
    // Markieren: jedes Wort, Vergleich über die gefaltete Form (Zeichen für Zeichen zurück auf den Originaltext abgebildet)
    const mark = (text, words) => {
      let map = [], f = '';
      [...text].forEach((ch, i) => { const x = fold(ch); for (let k = 0; k < x.length; k++) map.push(i); f += x; });
      const on = new Array(text.length).fill(false);
      for (const w of words) { let p = f.indexOf(w); while (p > -1) { for (let k = p; k < p + w.length; k++) on[map[k]] = true; p = f.indexOf(w, p + w.length); } }
      let out = '', open = false;
      [...text].forEach((ch, i) => { if (on[i] !== open) { out += on[i] ? '<mark>' : '</mark>'; open = on[i]; } out += esc(ch); });
      return out + (open ? '</mark>' : '');
    };
    const T = (k) => (document.documentElement.lang || 'de').startsWith('de') ? k[0] : k[1];
    let sel = -1, list = [];
    const id = (i) => `${hits.id}-${i}`;
    const select = (i) => {
      sel = i;
      [...hits.children].forEach((li, j) => li.setAttribute('aria-selected', String(j === i)));
      if (i > -1) { input.setAttribute('aria-activedescendant', id(i)); hits.children[i].scrollIntoView({ block: 'nearest' }); } else input.removeAttribute('aria-activedescendant');
    };
    const run = () => {
      const words = fold(input.value).split(/\s+/).filter(Boolean);
      const on = words.length > 0;
      if (nav) nav.hidden = on;
      if (all) all.hidden = on;
      hits.hidden = !on;
      input.setAttribute('aria-expanded', String(on));
      if (!on) { hits.innerHTML = ''; count.textContent = ''; list = []; select(-1); return; }
      list = idx.map((it) => {
        let score = 0;
        for (const w of words) {
          if (it.f.includes(w)) score += it.f.startsWith(w) || it.f.includes(' ' + w) ? 3 : 2;
          else if (it.fw.includes(' ' + w)) score += 1;
          else return null;
        }
        return { it, score };
      }).filter(Boolean).sort((a, b) => b.score - a.score).slice(0, 40).map((x) => x.it);
      hits.innerHTML = list.map((it, i) => {
        const kw = it.fk && words.some((w) => !it.f.includes(w)) ? it.k.split(' · ').filter((k) => words.some((w) => (' ' + fold(k).replace(/[^a-z0-9]+/g, ' ')).includes(' ' + w))).slice(0, 2).join(' · ') : '';
        return `<li role="option" id="${id(i)}" aria-selected="false"><a class="kn-hit" href="${esc(it.u)}" tabindex="-1"${it.c ? ' aria-current="page"' : ''}>`
          + `<span class="kn-hit__t">${it.n ? `<span class="kn-num">${esc(it.n)}</span> ` : ''}${mark(it.t, words)}</span>`
          + (it.p ? `<span class="kn-hit__p">${esc(it.p)}</span>` : '')
          + (kw ? `<span class="kn-hit__k">${mark(kw, words)}</span>` : '') + '</a></li>';
      }).join('');
      count.textContent = list.length === 0 ? T(['Keine Kapitel – Enter durchsucht alle Texte', 'No chapters – press Enter to search all texts'])
        : list.length === 1 ? T(['1 Kapitel', '1 chapter']) : T([`${list.length} Kapitel`, `${list.length} chapters`]);
      select(list.length ? 0 : -1);
    };
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-controls', hits.id);
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.addEventListener('input', run);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!list.length) return;
        e.preventDefault();
        select((sel + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length);
      } else if (e.key === 'Enter' && sel > -1 && list[sel] && !e.isComposing) {
        e.preventDefault();
        location.href = list[sel].u;
      } else if (e.key === 'Escape' && input.value) {
        e.preventDefault(); e.stopPropagation();
        input.value = ''; run();
      }
    });
    // Ein Treffer gewählt, aber Mauszeiger: Klick auf den Link reicht (tabindex -1 nur für die Tastatur-Reihenfolge)
    hits.addEventListener('pointermove', (e) => { const li = e.target.closest('li'); if (li) select([...hits.children].indexOf(li)); });
    // „/“ oder Strg/⌘+K: ins Suchfeld (Schublade bzw. Menü oben vorher öffnen)
    const key = form.querySelector('.kn-search__key');
    if (key && matchMedia('(hover:hover)').matches) key.hidden = false;
    document.addEventListener('keydown', (e) => {
      const typing = e.target.closest?.('input,textarea,select,[contenteditable="true"]');
      if (!((e.key === '/' && !typing && !e.metaKey && !e.ctrlKey && !e.altKey) || (e.key.toLowerCase() === 'k' && (e.metaKey || e.ctrlKey)))) return;
      e.preventDefault();
      root.querySelector('details.kn-menu')?.setAttribute('open', '');
      if (btn && side && !root.classList.contains('is-open') && getComputedStyle(side).visibility === 'hidden') btn.click();
      const go = (n = 0) => { input.focus({ preventScroll: false }); input.select(); if (document.activeElement !== input && n < 20) requestAnimationFrame(() => go(n + 1)); };
      go();
    });
  }

  // ---- Leiste oben: unter einem festen/klebenden Kopf der Website, Lesefortschritt im Kapitel (--kn-p 0…1)
  const bar = root.querySelector('[data-kn-bar]');
  if (bar) {
    // fester bzw. klebender Kopf der Website (nicht der Wissensbereich selbst)
    const head = [...document.querySelectorAll('header, [role="banner"]')].find((h) => !root.contains(h) && /sticky|fixed/.test(getComputedStyle(h).position));
    const main = root.querySelector('.kn-main');
    let raf = 0;
    const tick = () => {
      raf = 0;
      const off = head ? Math.max(0, head.getBoundingClientRect().bottom) : 0;
      root.style.setProperty('--kn-bar-top', `${Math.round(off)}px`);
      const r = main.getBoundingClientRect(), span = r.height - innerHeight * .6;
      bar.style.setProperty('--kn-p', String(Math.min(1, Math.max(0, span > 0 ? -r.top / span : 1))));
    };
    const on = () => { if (!raf) raf = requestAnimationFrame(tick); };
    addEventListener('scroll', on, { passive: true });
    addEventListener('resize', on, { passive: true });
    tick();
  }

  const links = [...root.querySelectorAll('[data-kn-sec]')];
  if (!links.length || !('IntersectionObserver' in window)) return;
  const heads = [...new Set(links.map((a) => decodeURIComponent(a.hash.slice(1))))].map((id) => document.getElementById(id)).filter(Boolean);
  const mark = (id) => links.forEach((a) => (decodeURIComponent(a.hash.slice(1)) === id ? a.setAttribute('aria-current', 'true') : a.removeAttribute('aria-current')));
  const seen = new Map();
  const io = new IntersectionObserver((entries) => {
    entries.forEach((en) => seen.set(en.target.id, en.isIntersecting));
    const firstVisible = heads.find((h) => seen.get(h.id));
    if (firstVisible) { mark(firstVisible.id); return; }
    // Kein Kopf im Blick: letzter Kopf oberhalb des Fensters
    const above = heads.filter((h) => h.getBoundingClientRect().top < 0).pop();
    if (above) mark(above.id);
  }, { rootMargin: '-10% 0px -60% 0px' });
  heads.forEach((h) => io.observe(h));
})();
