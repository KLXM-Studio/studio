/*
 * Einstieg als Slider (Core\Blocks\HeroSlider) – lädt nur auf Seiten mit Slider (Merkmal '@heroslider', Theme::conditionalJs).
 * Ohne Skript steht die erste Folie still. Mit Skript: Folien übereinander (.is-ready), Wechsel mit Übergang (.is-moving, nur ohne
 * „Bewegung reduzieren“ – sonst harte Schnitte und kein Auto-Wechsel), Pause/Play (gemeinsamer Schalter, data-hs-toggle), Pause bei
 * Maus und Fokus, Punkte/Nummern und Pfeile, Pfeiltasten/Pos1/Ende in der Steuerung, Wischen auf Touch. Inaktive Folien sind inert.
 * Hintergrundvideos der Folien steuert derselbe Knopf (keine eigenen Video-Schalter im Slider; hero.mjs lässt sie aus).
 * Live-Region (aria-live="polite") nur nach Bedienung; beim automatischen Wechsel „off“. Keine Inline-Stile: Werte per CSSOM.
 */
(() => {
  const d = document, calm = matchMedia('(prefers-reduced-motion: reduce)'), lean = matchMedia('(prefers-reduced-data: reduce)');
  // „Bewegung reduzieren“: Wahl im Darstellungs-Knopf der Website (<html data-motion>) vor der Systemeinstellung (wie hero.mjs)
  const still = () => { const m = d.documentElement.getAttribute('data-motion'); return m === 'reduce' || (m !== 'full' && calm.matches); };
  const label = (btn, playing) => {
    const l = btn && btn.querySelector('[data-l-pause]');
    if (!l) return;
    l.textContent = playing ? l.dataset.lPause : l.dataset.lPlay;
    btn.title = l.textContent;
    btn.classList.toggle('is-paused', !playing);
  };
  // Titel Wort für Wort: Textknoten in <span class="hs-w"> (Verzögerung --i), vorgelesen wird der ganze Titel (aria-label)
  const words = h => {
    if (!h || h.dataset.hsWords) return;
    h.dataset.hsWords = '1';
    h.setAttribute('aria-label', h.textContent.replace(/\s+/g, ' ').trim());
    let i = 0;
    const walk = n => [...n.childNodes].forEach(c => {
      if (c.nodeType === 1) return walk(c);
      if (c.nodeType !== 3 || !c.textContent.trim()) return;
      const f = d.createDocumentFragment();
      c.textContent.split(/(\s+)/).forEach(w => {
        if (!w) return;
        if (/^\s+$/.test(w)) { f.append(w); return; }
        const s = d.createElement('span');
        s.className = 'hs-w';
        s.setAttribute('aria-hidden', 'true');
        s.style.setProperty('--i', String(i++));
        s.textContent = w;
        f.append(s);
      });
      c.replaceWith(f);
    });
    walk(h);
  };

  d.querySelectorAll('[data-hero-slider]').forEach(root => {
    const track = root.querySelector('.hs__track');
    const slides = track ? [...track.children].filter(s => s.classList.contains('hs__slide')) : [];
    if (slides.length < 2) return;
    const ctrl = root.querySelector('.hs__ctrl');
    const dots = [...root.querySelectorAll('[data-hs-go]')];
    const btn = root.querySelector('[data-hs-toggle]');
    const ms = Number(root.dataset.interval) || 0;
    const tv = getComputedStyle(root).getPropertyValue('--hs-t').trim(), t = (parseFloat(tv) || 900) / (/ms$/.test(tv) || !tv ? 1000 : 1);
    let cur = 0, timer = 0, hold = 0, choice = null, leave = 0, seen = false;
    // Ein Knopf für alle Bewegung: run() = Wahl der Besucherin, sonst „läuft, außer bei Bewegung reduzieren“
    const run = () => choice ?? !still();
    const playing = () => ms > 0 && run();   // Auto-Wechsel
    // Hintergrundvideos der Folien (ohne eigenen Schalter, Hero::$inSlider): nur das der aktiven Folie, nur sichtbar und wenn die
    // Bewegung läuft – von selbst nie bei „Daten sparen“; inaktive Folien pausieren
    const vids = slides.map(s => [...s.querySelectorAll('video[data-hero-video]')]);
    const sync = () => vids.forEach((list, i) => list.forEach(v => {
      const box = v.closest('[data-hero-video-box]') || v.parentElement;
      if (i === cur && seen && run() && (choice !== null || (!lean.matches && !navigator.connection?.saveData))) {
        v.preload = 'auto';
        v.play().then(() => { if (!v.paused) box.classList.add('is-playing'); }).catch(() => {});
      } else if (!v.paused) v.pause();
    }));

    slides.forEach((s, i) => {
      s.hidden = false;
      s.inert = i !== cur;
      const h = d.getElementById(s.dataset.hsTitle || '') || s.querySelector('h1, h2');
      if (h) {
        h.classList.add('hs-title');
        if (root.classList.contains('hs--words')) words(h);
      }
      // Kenburns zum Fokuspunkt: transform-origin = object-position des Bildes (Klasse des Fokuspunkts aus der Mediathek)
      if (root.classList.contains('hs--kb')) s.querySelectorAll('img').forEach(img => img.style.setProperty('transform-origin', getComputedStyle(img).objectPosition || 'center'));
    });
    root.style.setProperty('--hs-dur', (ms || 7000) + 'ms');
    const motion = () => root.classList.toggle('is-moving', !still());
    root.classList.add('is-ready');
    motion();
    if (ctrl) ctrl.hidden = false;

    const schedule = () => {
      clearTimeout(timer);
      if (playing() && !hold) timer = setTimeout(() => go(cur + 1, false), ms);
    };
    const go = (n, manual, dir) => {
      n = (n + slides.length) % slides.length;
      if (n === cur) return;
      const prev = slides[cur], next = slides[n];
      root.classList.toggle('is-back', (dir ?? (n > cur ? 1 : -1)) < 0);
      clearTimeout(leave);
      slides.forEach(s => s !== prev && s.classList.remove('is-leaving'));
      prev.classList.remove('is-active');
      prev.classList.add('is-leaving');
      prev.inert = true;
      next.inert = false;
      next.classList.add('is-active');
      leave = setTimeout(() => prev.classList.remove('is-leaving'), still() ? 0 : t * 1000 + 50);
      // Ansage nur nach Bedienung (Pfeil, Punkt, Taste, Wischen) – beim automatischen Wechsel still
      track.setAttribute('aria-live', manual ? 'polite' : 'off');
      dots.forEach((b, i) => i === n ? b.setAttribute('aria-current', 'true') : b.removeAttribute('aria-current'));
      cur = n;
      sync();
      schedule();
    };

    if (btn) {
      label(btn, run());
      btn.hidden = false;
      btn.addEventListener('click', () => {
        choice = !run();
        label(btn, run());
        if (playing()) track.setAttribute('aria-live', 'off');
        sync();
        schedule();
      });
    }
    root.querySelector('[data-hs-prev]')?.addEventListener('click', () => go(cur - 1, true, -1));
    root.querySelector('[data-hs-next]')?.addEventListener('click', () => go(cur + 1, true, 1));
    dots.forEach(b => b.addEventListener('click', () => go(Number(b.dataset.hsGo), true)));

    // Tastatur in der Steuerung: Pfeile = zurück/weiter, Pos1/Ende = erste/letzte Folie (Fokus wandert mit, wenn er auf einem Punkt liegt)
    ctrl?.addEventListener('keydown', e => {
      const k = { ArrowLeft: cur - 1, ArrowRight: cur + 1, Home: 0, End: slides.length - 1 }[e.key];
      if (k === undefined || e.altKey || e.ctrlKey || e.metaKey) return;
      e.preventDefault();
      const onDot = dots.includes(d.activeElement);
      go(k, true, e.key === 'ArrowLeft' || e.key === 'Home' ? -1 : 1);
      if (onDot) dots[cur]?.focus();
    });

    // Pause bei Maus über dem Slider und bei Fokus darin (ändert den Knopf nicht – APG Carousel)
    root.addEventListener('mouseenter', () => { hold |= 1; clearTimeout(timer); });
    root.addEventListener('mouseleave', () => { hold &= ~1; schedule(); });
    root.addEventListener('focusin', () => { hold |= 2; clearTimeout(timer); });
    root.addEventListener('focusout', e => { if (!root.contains(e.relatedTarget)) { hold &= ~2; schedule(); } });

    // Wischen (Touch, Stift): waagrecht ≥ 40 px und deutlich mehr als senkrecht; danach kein Klick auf einen Link der Folie
    let x0 = null, y0 = 0, swiped = false;
    track.addEventListener('pointerdown', e => { if (e.pointerType !== 'mouse') { x0 = e.clientX; y0 = e.clientY; swiped = false; } });
    track.addEventListener('pointercancel', () => { x0 = null; });
    track.addEventListener('pointerup', e => {
      if (x0 === null) return;
      const dx = e.clientX - x0, dy = e.clientY - y0;
      x0 = null;
      if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy) * 1.5) return;
      swiped = true;
      const rtl = getComputedStyle(root).direction === 'rtl';
      go(cur + ((dx < 0) !== rtl ? 1 : -1), true, (dx < 0) !== rtl ? 1 : -1);
    });
    track.addEventListener('click', e => { if (swiped) { swiped = false; e.preventDefault(); e.stopPropagation(); } }, true);

    // Seite im Hintergrund: kein Wechsel; „Bewegung reduzieren“ zur Laufzeit: harte Schnitte, ohne eigene Wahl kein Auto-Wechsel
    d.addEventListener('visibilitychange', () => d.hidden ? clearTimeout(timer) : schedule());
    const recheck = () => { motion(); label(btn, run()); sync(); schedule(); };
    new IntersectionObserver(([e]) => { seen = e.isIntersecting; sync(); }).observe(root);
    calm.addEventListener('change', recheck);
    new MutationObserver(recheck).observe(d.documentElement, { attributes: true, attributeFilter: ['data-motion'] });
    schedule();
  });
})();
