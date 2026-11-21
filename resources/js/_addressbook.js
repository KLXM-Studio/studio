/*
 * Adressbuch-Ansicht (app/Admin/views/data/addressbook.php, Core\Data\AddressBook): ohne JavaScript tragen Links und Formulare
 * alles; hier wird es flüssig:
 * - Listen, Kontakte, Buchstaben, Zurück: nur Teile nachladen (?_teil=app|namen|mehr|karte), Adresse per History API
 * - Suche während der Eingabe (serverseitig), „/“ springt in die Suche, ↑/↓/Pos1/Ende in der Namensliste
 * - Weitere Namen beim Scrollen (IntersectionObserver), Sprungleiste A–Z
 * - Ziehen auf eine Liste (auch mehrere ausgewählte), Auswahl + „Zu Liste hinzufügen/entfernen“, Etiketten der Karte – per fetch
 */
import { toast } from './_toast.js';
import { csrf } from './_html.js';

export function initAddressBook() {
  if (!document.querySelector('[data-ab]')) return;
  const app = () => document.querySelector('[data-ab]');
  const narrow = () => matchMedia('(max-width: 900px)').matches;
  let ctrl = null;
  let io = null;

  const partUrl = (href, part, extra = {}) => {
    const u = new URL(href, location.href);
    u.searchParams.set('_teil', part);
    for (const [k, v] of Object.entries(extra)) u.searchParams.set(k, v);
    return u;
  };
  const cleanUrl = href => {
    const u = new URL(href, location.href);
    ['_teil', 'bis'].forEach(k => u.searchParams.delete(k));
    return u.pathname + u.search;
  };
  const get = async (u) => {
    ctrl?.abort();
    ctrl = new AbortController();
    const r = await fetch(u, { headers: { 'X-Requested-With': 'fetch' }, signal: ctrl.signal, credentials: 'same-origin' });
    if (!r.ok) throw new Error(String(r.status));
    return r.text();
  };
  const fail = e => { if (e?.name !== 'AbortError') toast(e?.message && /^\d+$/.test(e.message) ? 'Fehler ' + e.message : String(e?.message || e), 'error'); };
  const html = s => { const t = document.createElement('template'); t.innerHTML = s.trim(); return t.content; };
  const go = (href, push) => { if (push === 'push') history.pushState({ ab: 1 }, '', cleanUrl(href)); else if (push === 'replace') history.replaceState({ ab: 1 }, '', cleanUrl(href)); };

  // Ganze Ansicht ersetzen (Liste/Filter gewechselt, Suche, Zurück) – Suchfeld behält Fokus und Cursor
  const loadApp = async (href, push = 'push', { keepSearch = false } = {}) => {
    const q = app().querySelector('[data-ab-q]');
    const hadFocus = keepSearch && document.activeElement === q;
    const sel = hadFocus ? [q.selectionStart, q.selectionEnd] : null;
    try {
      const frag = html(await get(partUrl(href, 'app')));
      const next = frag.querySelector('[data-ab]');
      if (!next) return;
      if (hadFocus) next.querySelector('[data-ab-q]').value = q.value;
      app().replaceWith(next);
      go(href, push);
      wire();
      if (hadFocus) { const nq = app().querySelector('[data-ab-q]'); nq.focus(); try { nq.setSelectionRange(...sel); } catch { /* type=search */ } }
    } catch (e) { fail(e); }
  };

  // Karte eines Kontakts – Liste bleibt stehen
  const loadCard = async (a, push = 'push', focus = false) => {
    const href = a.href || a;
    try {
      const s = await get(partUrl(href, 'karte'));
      const box = app();
      box.querySelector('[data-ab-card]').innerHTML = s;
      const id = new URL(href, location.href).searchParams.get('k');
      box.querySelectorAll('.ab-item').forEach(li => {
        const on = li.dataset.id === id;
        li.classList.toggle('is-sel', on);
        const link = li.querySelector('.ab-item__a');
        if (on) link.setAttribute('aria-current', 'true'); else link.removeAttribute('aria-current');
      });
      pane(id ? 'card' : 'names');
      go(href, push);
      if (focus || narrow()) box.querySelector('#ab-card-name')?.focus();
    } catch (e) { fail(e); }
  };

  const pane = p => {
    const box = app();
    box.classList.remove('is-pane-src', 'is-pane-names', 'is-pane-card');
    box.classList.add('is-pane-' + p);
  };

  // Weitere/vorherige Namen nachladen
  const more = async (link) => {
    if (link.dataset.busy) return;
    link.dataset.busy = '1';
    const scroll = link.closest('[data-ab-scroll]');
    const rows = scroll.querySelector('[data-ab-rows]');
    const total = +scroll.dataset.total, page = +scroll.dataset.page;
    const prev = link.dataset.abMore === 'prev';
    const from = +link.dataset.from;
    const to = prev ? +link.dataset.to : Math.min(total, from + page);
    try {
      const frag = html(await get(partUrl(location.href, 'mehr', { ab: String(from), bis: String(to) })));
      if (prev) {
        // Doppelten Buchstaben-Trenner am Übergang entfernen, Scroll-Position halten
        const first = rows.firstElementChild;
        const lastNew = frag.lastElementChild;
        const h = scroll.scrollHeight;
        if (first?.classList.contains('ab-letter') && lastNew && first.dataset.abLetterHead === letterOf(frag)) first.remove();
        rows.prepend(frag);
        scroll.scrollTop += scroll.scrollHeight - h;
        if (from > 0) { link.dataset.from = String(Math.max(0, from - page)); link.dataset.to = String(from); delete link.dataset.busy; } else link.remove();
      } else {
        rows.append(frag);
        if (to < total) { link.dataset.from = String(to); link.textContent = link.textContent.replace(/\d+/, String(total - to)); delete link.dataset.busy; } else link.remove();
      }
      wireDrag();
    } catch (e) { delete link.dataset.busy; fail(e); }
  };
  const letterOf = frag => { const items = [...frag.querySelectorAll('.ab-letter')]; return items.length ? items[items.length - 1].dataset.abLetterHead : ''; };

  const observe = () => {
    io?.disconnect();
    if (!('IntersectionObserver' in window)) return;
    const scroll = app().querySelector('[data-ab-scroll]');
    if (!scroll) return;
    io = new IntersectionObserver(es => es.forEach(en => { if (en.isIntersecting && en.target.dataset.abMore === 'next') more(en.target); }), { root: scroll, rootMargin: '300px' });
    scroll.querySelectorAll('[data-ab-more="next"]').forEach(l => io.observe(l));
  };

  // Ziehen auf eine Liste (bei Auswahl: alle ausgewählten)
  const wireDrag = () => {
    app().querySelectorAll('.ab-item').forEach(li => { if (!li.draggable) li.draggable = true; });
  };
  const checkedIds = () => [...app().querySelectorAll('.ab-check:checked')].map(c => c.value);
  const assign = async (ids, list, op, label) => {
    const fd = new FormData();
    fd.append('_csrf', csrf());
    fd.append('liste', list);
    fd.append('op', op);
    ids.forEach(id => fd.append('ids[]', id));
    try {
      const r = await fetch(app().dataset.abAssign, { method: 'POST', body: fd, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const j = await r.json().catch(() => ({ ok: false, message: 'Fehler ' + r.status }));
      toast(j.message || label, j.ok ? 'ok' : 'error');
      if (j.counts) counts(j.counts);
      return j.ok;
    } catch (e) { fail(e); return false; }
  };
  const counts = c => {
    const box = app();
    for (const [k, n] of Object.entries(c.lists || {})) { const el = box.querySelector(`[data-ab-count="${CSS.escape(k)}"]`); if (el) el.textContent = n; }
    ['offline', 'nofoto', 'nomail'].forEach(k => { const el = box.querySelector(`[data-ab-count="~${k}"]`); if (el && k in c) el.textContent = c[k]; });
  };
  const refreshCard = () => {
    const sel = app().querySelector('.ab-item.is-sel .ab-item__a');
    const k = new URL(location.href).searchParams.get('k');
    if (sel || k) loadCard(sel?.href || location.href, null);
  };

  const selCount = () => {
    const box = app();
    const n = checkedIds().length;
    const el = box.querySelector('[data-ab-selcount]');
    if (el) el.textContent = (n === 1 ? box.dataset.abTSel1 : box.dataset.abTSel).replace('{n}', n);
    box.querySelectorAll('.ab-check').forEach(c => c.closest('.ab-item')?.classList.toggle('is-checked', c.checked));
  };

  // Ereignisse, die am Element der Ansicht hängen (nach jedem Ersetzen neu)
  const wire = () => {
    const box = app();
    if (!box || box.dataset.wired) return;
    box.dataset.wired = '1';
    wireDrag();
    observe();
    selCount();
    // Gewählten Kontakt sichtbar machen
    box.querySelector('.ab-item.is-sel')?.scrollIntoView({ block: 'nearest' });

    let timer = 0;
    box.querySelector('[data-ab-q]')?.addEventListener('input', e => {
      clearTimeout(timer);
      const form = e.target.form;
      timer = setTimeout(() => {
        const u = new URL(form.action, location.href);
        new FormData(form).forEach((v, k) => { if (String(v) !== '') u.searchParams.set(k, String(v)); });
        const k = new URL(location.href).searchParams.get('k');
        if (k) u.searchParams.set('k', k);
        loadApp(u.href, 'replace', { keepSearch: true });
      }, 220);
    });
    box.querySelector('[data-ab-search]')?.addEventListener('submit', e => {
      e.preventDefault();
      clearTimeout(timer);
      const u = new URL(e.target.action, location.href);
      new FormData(e.target).forEach((v, k) => { if (String(v) !== '') u.searchParams.set(k, String(v)); });
      loadApp(u.href, 'push', { keepSearch: true });
    });
  };

  // Klicks (delegiert, gilt auch für ersetzte Teile)
  document.addEventListener('click', e => {
    const box = e.target.closest?.('[data-ab]');
    if (!box || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest('a[href]');
    if (!a || a.hasAttribute('download') || a.target === '_blank') return;
    const u = new URL(a.href, location.href);
    if (u.origin !== location.origin || u.pathname !== new URL(box.dataset.abBase, location.href).pathname) return;   // Bearbeiten, Abonnieren, andere Adressbücher: normal
    if (a.matches('.ab-item__a')) { e.preventDefault(); loadCard(a, 'push'); return; }
    if (a.matches('[data-ab-more]')) { e.preventDefault(); more(a); return; }
    if (a.matches('[data-ab-letter]')) {
      e.preventDefault();
      const head = box.querySelector(`[data-ab-letter-head="${CSS.escape(a.dataset.abLetter)}"]`);
      const scroll = box.querySelector('[data-ab-scroll]');
      if (head && scroll) {
        scroll.scrollTop = head.offsetTop - scroll.offsetTop;
        head.nextElementSibling?.querySelector('.ab-item__a')?.focus({ preventScroll: true });
      } else loadApp(a.href, 'replace');
      return;
    }
    if (a.matches('[data-ab-pane]')) {
      e.preventDefault();
      if (a.dataset.abPane === 'src') { pane('src'); go(a.href, 'push'); box.querySelector('.ab-src a[aria-current]')?.focus(); return; }
      pane('names');
      go(a.href, 'push');
      box.querySelector('.ab-item.is-sel .ab-item__a')?.focus();
      return;
    }
    if (a.matches('[data-ab-nav], [data-ab-select]')) { e.preventDefault(); loadApp(a.href, 'push'); }
  });

  // Tastatur: „/“ → Suche; ↑/↓/Pos1/Ende in der Namensliste
  document.addEventListener('keydown', e => {
    const box = app();
    if (!box) return;
    if (e.key === '/' && !e.target.closest('input, textarea, select, [contenteditable]')) {
      const q = box.querySelector('[data-ab-q]');
      if (q && q.offsetParent) { e.preventDefault(); q.focus(); q.select(); }
      return;
    }
    const cur = e.target.closest?.('.ab-item__a');
    if (cur && box.contains(cur) && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) {
      const links = [...box.querySelectorAll('.ab-item__a')];
      const i = links.indexOf(cur);
      const n = e.key === 'ArrowDown' ? links[i + 1] : e.key === 'ArrowUp' ? links[i - 1] : e.key === 'Home' ? links[0] : links[links.length - 1];
      if (n) { e.preventDefault(); n.focus(); n.scrollIntoView({ block: 'nearest' }); }
      if (!n && e.key === 'ArrowDown') box.querySelector('[data-ab-more="next"]')?.click();
    } else if (e.key === 'ArrowDown' && e.target.matches?.('[data-ab-q]')) {
      e.preventDefault();
      box.querySelector('.ab-item__a')?.focus();
    }
  });

  // Auswahl
  document.addEventListener('change', e => {
    if (e.target.matches?.('.ab-check')) selCount();
  });

  // Formulare: Auswahl-Leiste (Hinzufügen/Entfernen), Etiketten der Karte – ohne Neuladen; vCard-Knopf lädt normal herunter
  document.addEventListener('submit', async e => {
    const form = e.target;
    const box = form.closest?.('[data-ab]');
    if (!box) return;
    const btn = e.submitter;
    if (btn?.getAttribute('formaction')) return;   // vCard
    if (form.matches('[data-ab-selbar]')) {
      e.preventDefault();
      const ids = checkedIds();
      if (!ids.length) { toast(box.dataset.abTNone, 'info'); return; }
      const list = form.elements.liste.value;
      const op = btn?.value === 'remove' ? 'remove' : 'add';
      // Auswahl bleibt stehen (Zähler kommen mit der Antwort); nur wenn die angezeigte Liste sich ändert, Namen neu laden
      if (await assign(ids, list, op) && new URL(location.href).searchParams.get('liste') === list) loadApp(location.href, null);
      else refreshCard();
      return;
    }
    if (form.matches('[data-ab-tagform]')) {
      e.preventDefault();
      const fd = new FormData(form);
      if (!fd.get('liste')) { form.elements.liste?.focus(); return; }
      if (await assign([String(fd.get('ids[]'))], String(fd.get('liste')), String(fd.get('op')))) {
        const cur = new URL(location.href).searchParams;
        // In der gewählten Liste entfernt: Namensliste neu, sonst nur die Karte
        if (cur.get('liste') === fd.get('liste') && fd.get('op') === 'remove') loadApp(location.href, null);
        else refreshCard();
        setTimeout(() => app().querySelector('#ab-card-lists')?.closest('.ab-grp')?.querySelector('select, button')?.focus(), 250);
      }
    }
  });

  // Ziehen
  let dragIds = [];
  document.addEventListener('dragstart', e => {
    const li = e.target.closest?.('[data-ab] .ab-item');
    if (!li) return;
    const sel = checkedIds();
    dragIds = sel.includes(li.dataset.id) ? sel : [li.dataset.id];
    li.classList.add('is-dragging');
    e.dataTransfer.effectAllowed = 'copy';
    e.dataTransfer.setData('text/plain', li.querySelector('.ab-item__n')?.textContent.trim() || '');
  });
  document.addEventListener('dragend', () => {
    dragIds = [];
    document.querySelectorAll('.ab-item.is-dragging, .ab-src .is-drop').forEach(el => el.classList.remove('is-dragging', 'is-drop'));
  });
  document.addEventListener('dragover', e => {
    const t = e.target.closest?.('[data-ab-drop]');
    if (!t || !dragIds.length) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    document.querySelectorAll('.ab-src .is-drop').forEach(el => el !== t && el.classList.remove('is-drop'));
    t.classList.add('is-drop');
  });
  document.addEventListener('dragleave', e => {
    const t = e.target.closest?.('[data-ab-drop]');
    if (t && !t.contains(e.relatedTarget)) t.classList.remove('is-drop');
  });
  document.addEventListener('drop', async e => {
    const t = e.target.closest?.('[data-ab-drop]');
    if (!t || !dragIds.length) return;
    e.preventDefault();
    t.classList.remove('is-drop');
    const ids = dragIds;
    dragIds = [];
    if (await assign(ids, t.dataset.abDrop, 'add', t.dataset.abLabel)) refreshCard();
  });

  // Zurück/Vor im Browser
  window.addEventListener('popstate', () => { if (app()) loadApp(location.href, null); });

  wire();
}
