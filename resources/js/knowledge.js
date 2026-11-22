/*
 * Wissensbereich (Core\Data\Knowledge::frame, Stile css/knowledge.css) – lädt nur auf Detailseiten von Wissens-Einträgen ('@knowledge').
 *  - Schublade: Auf dem Telefon (und im Modus „Fokus“ auch breit) öffnet der Knopf „Kapitel“ [data-kn-toggle] die Seitenleiste
 *    [data-kn-drawer]; schließen mit ×, Esc oder Klick daneben, der Fokus springt hinein und zurück zum Knopf. Ohne Skript steht die
 *    Seitenleiste über dem Text (.kn ohne .is-js).
 *  - Mitlaufende Abschnitte: Links „In diesem Kapitel“ [data-kn-sec] bekommen aria-current="true" für den Abschnitt im Blick.
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
