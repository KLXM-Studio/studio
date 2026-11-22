/*
 * Video-Links in der Lightbox (Core\VideoLinks): Links mit data-video-lb="youtube|vimeo" + data-video-id öffnen nach Einwilligung
 * den Player in einem <dialog class="cms-lb cms-lb--video"> (Stile css/video-lightbox.css, vom Kit ersetzbar). Lädt nur, wenn die
 * Einstellung an ist und die Seite solche Links hat.
 *  - Einwilligung beim Klick prüfen (spätere Änderungen gelten ab dem nächsten Klick): window.cmsConsent.embed(anbieter) der
 *    Erweiterung consent_kit (true/false); null = kein Dienst für den Anbieter → Freigabe „{Anbieter}-Videos künftig direkt laden“
 *    des Video-Blocks (localStorage, wie resources/js/embed.js). Keine eigene Cookie-Auswertung.
 *  - Ohne Einwilligung: normaler Link (kein Nachladen, keine Anfrage an den Anbieter) – oder mit data-hint="1" ein Zwei-Klick-Hinweis
 *    in der Lightbox (Vorschaubild nur aus dem lokalen Zwischenspeicher, data-video-poster).
 *  - Player: nur youtube-nocookie.com bzw. player.vimeo.com, Adresse erst beim Öffnen; Esc/Knopf schließen, iframe wird entfernt,
 *    der Fokus kehrt zum Link zurück. Modaler Dialog: der Rest der Seite ist solange inaktiv.
 */
(() => {
  const d = document, cfg = d.currentScript?.dataset || {};
  const ID = { youtube: /^[\w-]{11}$/, vimeo: /^\d{6,12}$/ };
  const NAME = { youtube: [cfg.yt || 'YouTube', cfg.ytCompany || ''], vimeo: [cfg.vimeo || 'Vimeo', cfg.vimeoCompany || ''] };
  const player = (p, id) => p === 'youtube'
    ? 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0&modestbranding=1&playsinline=1'
    : 'https://player.vimeo.com/video/' + id + '?autoplay=1&dnt=1';
  // Einwilligung: Einwilligungs-Verwaltung zuerst, sonst die Freigabe des Video-Blocks (gleiche Schlüssel wie embed.js)
  const KEY = p => 'cms-embed-' + p;
  const stored = p => { try { return localStorage.getItem(KEY(p)) === '1' || Object.keys(localStorage).some(k => (k.endsWith('-embed-' + p) || k === 'mycms-consent-' + p) && localStorage.getItem(k) === '1'); } catch { return false; } };
  const allowed = p => window.cmsConsent?.embed?.(p) ?? stored(p);
  const remember = p => {
    if ((window.cmsConsent?.embed?.(p) ?? null) !== null) { window.cmsConsent.allowEmbed(p, true); return; }
    try { localStorage.setItem(KEY(p), '1'); } catch { /* privater Modus */ }
  };
  const fill = (s, p) => String(s || '').replaceAll('{provider}', NAME[p][0]).replaceAll('{company}', NAME[p][1]);
  const el = (tag, cls, txt) => { const n = d.createElement(tag); if (cls) n.className = cls; if (txt) n.textContent = txt; return n; };

  if (cfg.css && !d.querySelector('link[data-cms-video-lb-css]')) {
    const l = el('link'); l.rel = 'stylesheet'; l.href = cfg.css; l.dataset.cmsVideoLbCss = ''; d.head.append(l);
  }

  let dlg = null, stage = null, opener = null;
  function dialog() {
    if (dlg) return dlg;
    dlg = el('dialog', 'cms-lb cms-lb--video');
    const close = el('button', 'cms-lb__btn cms-lb__close');
    close.type = 'button';
    close.setAttribute('aria-label', cfg.close || 'Schließen');
    close.innerHTML = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6 6 18"/></svg>';
    close.addEventListener('click', () => dlg.close());
    stage = el('div', 'cms-lb__stage');
    dlg.append(close, stage);
    // Klick neben das Video schließt; beim Schließen Player entfernen (stoppt Ton und Verbindung), Fokus zurück zum Link
    dlg.addEventListener('click', e => { if (e.target === dlg || e.target === stage) dlg.close(); });
    dlg.addEventListener('close', () => { stage.replaceChildren(); dlg.removeAttribute('aria-label'); opener?.focus(); opener = null; });
    d.body.append(dlg);
    return dlg;
  }
  // Titel für Dialog und Player: sichtbarer Linktext (ohne Screenreader-Zusätze wie „öffnet in neuem Tab“)
  const title = a => { const c = a.cloneNode(true); c.querySelectorAll('.sr-only,.visually-hidden').forEach(n => n.remove()); return (c.textContent || '').trim().replace(/\s+/g, ' ') || cfg.video || 'Video'; };

  function frame(p, id, label) {
    const f = el('iframe');
    f.src = player(p, id);
    f.title = label;
    f.allow = 'autoplay; fullscreen; picture-in-picture; encrypted-media';
    f.allowFullscreen = true;
    f.referrerPolicy = 'strict-origin-when-cross-origin';
    const box = el('div', 'cms-lb__player');
    box.append(f);
    return box;
  }

  // Zwei-Klick-Hinweis (Einstellung „Ohne Einwilligung Zwei-Klick-Hinweis …“): erst nach „Video laden“ geht eine Anfrage hinaus
  function gate(a, p, id, label) {
    const box = el('div', 'cms-lb__player cms-lb__gate');
    if (a.dataset.videoPoster && a.dataset.videoPoster.startsWith('/')) {
      const img = el('img', 'cms-lb__poster'); img.src = a.dataset.videoPoster; img.alt = ''; img.decoding = 'async';
      box.append(img);
    }
    const info = el('div', 'cms-lb__info');
    const text = el('p', 'cms-lb__text', fill(cfg.tText, p) + ' ');
    if (cfg.privacy) { const pl = el('a', '', cfg.tPrivacy); pl.href = cfg.privacy; text.append(pl); }
    const row = el('p', 'cms-lb__row');
    const rem = el('label', 'cms-lb__remember'), box2 = el('input');
    box2.type = 'checkbox';
    rem.append(box2, ' ' + fill(cfg.tRemember, p));
    const ext = el('a', 'cms-lb__ext', fill(cfg.tExt, p));
    ext.href = a.href; ext.target = '_blank'; ext.rel = 'noopener';
    ext.append(el('span', 'sr-only', ' ' + (cfg.tNewtab || '')));
    row.append(rem, ext);
    const play = el('button', 'cms-lb__play');
    play.type = 'button';
    play.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg>';
    play.append(el('span', '', fill(cfg.tLoad, p)));
    play.addEventListener('click', () => {
      if (box2.checked) remember(p);
      const f = frame(p, id, label);
      box.replaceWith(f);
      dlg.querySelector('.cms-lb__close').focus();   // Fokus nicht ins fremde iframe – dort käme Esc nicht an
    });
    info.append(text, row, play);
    box.append(info);
    return box;
  }

  function open(a, p, id, withConsent) {
    dialog();
    opener = a;
    const label = title(a) + ' (' + NAME[p][0] + ')';
    dlg.setAttribute('aria-label', label);
    stage.replaceChildren(withConsent ? frame(p, id, label) : gate(a, p, id, label));
    dlg.showModal();
    (withConsent ? dlg.querySelector('.cms-lb__close') : dlg.querySelector('.cms-lb__play'))?.focus();
  }

  d.addEventListener('click', e => {
    const a = e.target.closest?.('a[data-video-lb]');
    if (!a || e.defaultPrevented || e.button || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    const p = a.dataset.videoLb, id = a.dataset.videoId || '';
    if (!ID[p] || !ID[p].test(id)) return;   // „off“ oder unbekannt: normaler Link
    const ok = allowed(p);
    if (!ok && cfg.hint !== '1') return;      // ohne Einwilligung: normaler Link wie bisher
    e.preventDefault();
    open(a, p, id, ok);
  });
})();
