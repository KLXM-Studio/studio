/*
 * Einstiegs-Varianten der Kits (Core\Blocks\Hero): Hintergrundvideo, Vorher/Nachher-Regler, Laufzeile.
 * Wird nur auf Seiten geladen, deren Einstieg eine dieser Varianten nutzt (<script type="module"> aus Hero::script()).
 * Barrierefreiheit: Bewegung von selbst nur ohne „Bewegung reduzieren“ (auch wenn die Einstellung zur Laufzeit wechselt) und
 * immer mit sichtbarer Schaltfläche (WCAG 2.2.2). Video: Play/Pause-Schalter (Core\Blocks\Hero::videoToggle) erscheint auch bei
 * „Bewegung reduzieren“ – dann auf „abspielen“; ohne JavaScript steht alles still (Standbild, ruhige Zeile, beide Bilder nebeneinander).
 */
const d = document, calm = matchMedia('(prefers-reduced-motion: reduce)'), lean = matchMedia('(prefers-reduced-data: reduce)');

// Schaltfläche mit wechselnder Beschriftung: <button data-…-toggle><span data-l-pause="…" data-l-play="…">
// (Video-Schalter: auch title; .is-paused zeigt das Play-Symbol, sonst das Pause-Symbol)
const toggle = (btn, playing) => {
  if (!btn) return;
  const l = btn.querySelector('[data-l-pause]');
  if (l) {
    l.textContent = playing ? l.dataset.lPause : l.dataset.lPlay;
    if (btn.hasAttribute('title')) btn.title = l.textContent;
  }
  btn.classList.toggle('is-paused', !playing);
};

// „Bewegung reduzieren“: Wahl im Darstellungs-Knopf der Website (<html data-motion="reduce"|"full">) vor der Systemeinstellung
const still = () => { const m = d.documentElement.getAttribute('data-motion'); return m === 'reduce' || (m !== 'full' && calm.matches); };

// ------------------------------------------------------------ Hintergrundvideo: stumm, Schleife, nur sichtbar abspielen
// Von selbst nur ohne „Bewegung reduzieren“ und „Daten sparen“; dann steht der Schalter auf „abspielen“ – die bewusste
// Wahl der Besucherin (Klick) gilt, bis sie wieder klickt, auch wenn die Einstellung zur Laufzeit wechselt.
d.querySelectorAll('[data-hero-video]').forEach(v => {
  if (v.closest('[data-hero-slider]')) return;   // Einstieg als Slider: hero-slider.js steuert Videos mit dem Knopf der Leiste
  const box = v.closest('[data-hero-video-box]') || v.parentElement;
  const btn = box.querySelector('[data-hero-video-toggle]');
  let choice = null, seen = false;
  const auto = () => !still() && !lean.matches && !navigator.connection?.saveData;
  const want = () => choice ?? auto();
  const sync = () => {
    if (want() && seen) {
      v.preload = 'auto';
      v.play().then(() => { if (!v.paused) box.classList.add('is-playing'); toggle(btn, !v.paused); }).catch(() => toggle(btn, false));
    } else {
      if (!v.paused) v.pause();
      toggle(btn, want());   // außerhalb des Bildes bleibt die Absicht stehen (läuft weiter, sobald sichtbar)
    }
  };
  if (btn) {
    toggle(btn, false);
    btn.hidden = false;
    btn.addEventListener('click', () => { choice = !want(); seen = true; sync(); });
  }
  new IntersectionObserver(([e]) => { seen = e.isIntersecting; sync(); }).observe(box);
  calm.addEventListener('change', () => choice === null && sync());
  new MutationObserver(() => choice === null && sync()).observe(d.documentElement, { attributes: true, attributeFilter: ['data-motion'] });
});

// ------------------------------------------------------------ Vorher/Nachher: Schieberegler (input range, Tastatur: Pfeile, Pos1/Ende)
d.querySelectorAll('[data-hero-compare]').forEach(box => {
  const r = box.querySelector('input[type=range]');
  if (!r) return;
  const set = () => { box.style.setProperty('--hx-pos', r.value + '%'); r.setAttribute('aria-valuetext', r.value + ' %'); };
  r.hidden = false;
  box.classList.add('is-ready');
  r.addEventListener('input', set);
  set();
});

// ------------------------------------------------------------ Laufzeile: läuft nur mit Skript, hält per Schaltfläche, Maus und Fokus (CSS)
d.querySelectorAll('[data-hero-marquee]').forEach(box => {
  const btn = box.querySelector('[data-hero-marquee-toggle]');
  const live = () => {
    box.classList.toggle('is-live', !calm.matches);
    if (btn) btn.hidden = calm.matches;
  };
  btn?.addEventListener('click', () => toggle(btn, box.classList.toggle('is-paused') === false));
  calm.addEventListener('change', live);
  live();
});
