<?php
/**
 * Fußbereich „NativeCSS“ (.ncss-footer): Name und Claim, Kontakt, Seiten, Social Media im Raster (so viele Spalten, wie Platz
 * ist – .grid von fluid), darunter Copyright, Rechtliches (mit footer_links(), z. B. „Datenschutz-Einstellungen“) und Sprachen.
 * Handlungsaufruf im Fußbereich (fluid_footer_cta, Website → Darstellung): Button unter Claim und Text.
 * Dichte (Design → Seite → „Dichte des Fußbereichs“, wie fluid): „Kompakt“ gibt die Adresse einzeilig aus; CSS in _native.css.
 */
$address = fluid_address_lines();
$social = fluid_social();
$pages = \Core\Pages::menu(false);
$more = footer_nav_links(array_column($pages, 'id'));   // weitere Links unter „Seiten“ (Core\FooterLinks, wie fluid); leer = wie bisher
$legal = array_merge(fluid_legal_links(), footer_links());   // footer_links(): z. B. „Datenschutz-Einstellungen“ (Erweiterung consent_kit)
$langs = language_links();
$text = trim((string) setting('footer_text'));
$tagline = trim((string) setting('tagline'));
$home = url(\Core\Lang::prefix(\Core\Lang::current()) . '/');
?>
<footer class="ncss-footer nv-footer">
  <div class="ncss-footer-inner grid nv-footer__grid">
    <div class="nv-footer__about">
      <?= app()->theme->partial('brand', ['href' => $home, 'class' => 'nv-brand nv-brand--footer']) ?>
      <?php if ($tagline !== ''): ?><p class="nv-footer__tagline"><?= fluid_title($tagline) ?></p><?php endif; ?>
      <?php if ($text !== ''): ?><p class="nv-footer__text"><?= nl2br(fluid_title($text), false) ?></p><?php endif; ?>
      <?php if ($ftrCta = fluid_footer_cta()): // Handlungsaufruf im Fußbereich (Website → Darstellung, wie fluid) ?><p class="nv-footer__action"><a class="btn btn--primary" <?= fluid_link_attrs($ftrCta['link']) ?>><?= e($ftrCta['label']) ?><?= fluid_ext_note(fluid_link($ftrCta['link'])) ?></a></p><?php endif; ?>
    </div>
    <?php if ($address || filled(fluid_phone()) || fluid_email() !== ''): ?>
    <div class="nv-footer__col">
      <h2 class="nv-footer__h"><?= e(lt('Kontakt')) ?></h2>
      <?php if ($address && design('footer_density') === 'compact'): // „Kompakt“: Adresse einzeilig, Trenner per CSS ?><address class="nv-footer__address nv-footer__address--line"><?= implode(' ', array_map(fn($l) => '<span>' . e($l) . '</span>', $address)) ?></address>
      <?php elseif ($address): ?><address class="nv-footer__address"><?= implode('<br>', array_map('e', $address)) ?></address><?php endif; ?>
      <?= fluid_quick_contact('nv-footer__list') ?>
    </div>
    <?php endif; ?>
    <?php if ($pages || $more): ?>
    <nav class="nv-footer__col" aria-labelledby="nv-footer-pages">
      <h2 class="nv-footer__h" id="nv-footer-pages"><?= e(lt('Seiten')) ?></h2>
      <ul class="nv-footer__list" role="list"><?php foreach ($pages as $m): ?><li><a href="<?= e($m['href']) ?>"<?= fluid_is_current($m) ? ' aria-current="page"' : '' ?>><?= e($m['label']) ?></a></li><?php endforeach; ?><?= footer_nav_html($more) ?></ul>
    </nav>
    <?php endif; ?>
    <?php if ($social): ?>
    <div class="nv-footer__col">
      <h2 class="nv-footer__h"><?= e(lt('Folgen Sie uns')) ?></h2>
      <ul class="nv-footer__list" role="list"><?php foreach ($social as $s): ?><li><a href="<?= e($s['url']) ?>" target="_blank" rel="noopener me"><?= e($s['label']) ?><span class="sr-only"> <?= e(lt('(öffnet in neuem Tab)')) ?></span></a></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
  </div>
  <div class="ncss-footer-bottom nv-footer__bottom">
    <p>© <?= e(date('Y')) ?> <?= e(fluid_name()) ?></p>
    <?php if ($legal): ?><nav aria-label="<?= e(lt('Rechtliches')) ?>"><ul class="cluster nv-footer__legal" role="list"><?php foreach ($legal as $l): ?><li><a href="<?= e($l['href']) ?>"><?= e($l['label']) ?></a></li><?php endforeach; ?></ul></nav><?php endif; ?>
    <?php if ($langs): ?><?= app()->theme->partial('langswitch', ['langs' => $langs]) ?><?php endif; ?>
  </div>
</footer>
