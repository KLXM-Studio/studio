<?php
/**
 * Umschalter Netzwerk ↔ Website (Core\Network\Area) – gleiches Element in Netzwerk- und Website-Ansicht, nur für Netzwerk-Konten
 * in Installationen mit mehreren Websites, links in der Kopfleiste (_topbar.php). Knopf mit Titel („Netzwerk“ bzw. Name der Website)
 * öffnet ein Popover (Top Layer, darf über die Leiste hinausragen; Telefon: Blatt von unten): oben „Netzwerk-Verwaltung“, darunter alle Websites mit Namen,
 * Domain und Schlüssel, ab mehr als 8 Websites mit Filter. Tastatur: Pfeile, Pos1/Ende, Enter, Escape (resources/js/_areaswitch.js).
 * Ohne JavaScript öffnet das Popover nativ (popovertarget), Website wechseln per Einmal-Anmeldung (POST /admin/network/open).
 * @var bool $netArea
 */
$swSites = \Core\Network\Area::switcher();
$swCur = null;
foreach ($swSites as $s) if ($s['current']) $swCur = $s;
$swOnNet = \Core\Network\Network::isNetworkSite();
$swId = 'adm-sw-pop';
$swFilter = count($swSites) > 8;
?>
<div class="adm-sw<?= $netArea ? ' is-net' : '' ?>" data-sw>
  <button type="button" class="adm-sw__btn" popovertarget="<?= e($swId) ?>" aria-haspopup="menu" aria-controls="<?= e($swId) ?>" data-pop-btn>
    <?php if ($netArea): ?>
    <span class="adm-sw__tile adm-sw__tile--net" aria-hidden="true"><?= icon('network') ?></span>
    <span class="adm-sw__txt"><span class="adm-sw__eyebrow"><?= e(__('Netzwerk-Verwaltung')) ?></span><span class="adm-sw__name"><?= e(__('Netzwerk')) ?></span></span>
    <?php else: ?>
    <img class="adm-sw__tile" src="<?= e($swCur['icon'] ?? '') ?>" width="28" height="28" alt="">
    <span class="adm-sw__txt"><span class="adm-sw__eyebrow"><?= e(__('Website')) ?></span><span class="adm-sw__name"><?= e($swCur['name'] ?? site()->label()) ?></span></span>
    <?php endif; ?>
    <span class="adm-sw__chev" aria-hidden="true"><?= icon('caret-down') ?></span>
    <span class="adm-sr"> – <?= e(__('Bereich oder Website wechseln')) ?></span>
  </button>
  <div class="adm-pop adm-sw__pop" id="<?= e($swId) ?>" popover data-pop>
    <div class="adm-pop__head"><strong><?= e(__('Bereich wechseln')) ?></strong>
      <button type="button" class="adm-pop__x" popovertarget="<?= e($swId) ?>" popovertargetaction="hide" aria-label="<?= e(__('Schließen')) ?>"><?= icon('x') ?></button></div>
    <?php if ($swFilter): ?>
    <label class="adm-pop__filter"><?= icon('magnifying-glass') ?><input type="search" class="is-bare" placeholder="<?= e(__('Website suchen …')) ?>" aria-label="<?= e(__('Websites filtern')) ?>" autocomplete="off" data-pop-filter></label>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/admin/network/open')) ?>">
      <?= csrf_field() ?>
      <div role="menu" aria-label="<?= e(__('Bereich wechseln')) ?>" class="adm-sw__menu">
        <?php // Netzwerk-Verwaltung: eigener Eintrag oben ?>
        <?php if ($swOnNet): ?>
        <a role="menuitem" class="adm-sw__item adm-sw__item--net" data-filter-text="<?= e(mb_strtolower(__('Netzwerk-Verwaltung') . ' netzwerk network')) ?>" href="<?= e(url('/admin/network')) ?>"<?= $netArea ? ' aria-current="page"' : '' ?>>
        <?php else: ?>
        <button role="menuitem" type="submit" name="site" value="<?= e(\Core\Network\Network::siteKey()) ?>" class="adm-sw__item adm-sw__item--net" data-filter-text="<?= e(mb_strtolower(__('Netzwerk-Verwaltung') . ' netzwerk network')) ?>">
        <?php endif; ?>
          <span class="adm-sw__ico adm-sw__ico--net" aria-hidden="true"><?= icon('network') ?></span>
          <span class="adm-sw__t"><span class="adm-sw__n"><?= e(__('Netzwerk-Verwaltung')) ?></span><small><?= e(__('{n} Websites · Einstellungen der ganzen Installation', ['n' => count($swSites)])) ?></small></span>
          <?php if ($netArea): ?><span class="adm-sw__cur"><?= icon('check') ?><span class="adm-sr"><?= e(__('geöffnet')) ?></span></span><?php endif; ?>
        <?= $swOnNet ? '</a>' : '</button>' ?>
        <div role="group" aria-labelledby="<?= e($swId) ?>-h" class="adm-sw__group">
          <p class="adm-sw__h" id="<?= e($swId) ?>-h" role="presentation"><?= e(__('Websites')) ?></p>
          <?php foreach ($swSites as $s): $here = $s['current'] && !$netArea; ?>
          <?php if ($s['current']): // diese Website: einfacher Link (keine Einmal-Anmeldung nötig) ?>
          <a role="menuitem" class="adm-sw__item" href="<?= e(url('/admin')) ?>" data-filter-text="<?= e(mb_strtolower($s['name'] . ' ' . $s['host'] . ' ' . $s['key'])) ?>"<?= $here ? ' aria-current="page"' : '' ?>>
          <?php else: ?>
          <button role="menuitem" type="submit" name="site" value="<?= e($s['key']) ?>" class="adm-sw__item" data-filter-text="<?= e(mb_strtolower($s['name'] . ' ' . $s['host'] . ' ' . $s['key'])) ?>">
          <?php endif; ?>
            <img class="adm-sw__ico" src="<?= e($s['icon']) ?>" width="28" height="28" alt="" loading="lazy" decoding="async">
            <span class="adm-sw__t"><span class="adm-sw__n"><?= e($s['name']) ?></span><small><?= e($s['host']) ?><?php if ($s['host'] !== $s['key'] && !str_starts_with($s['host'], $s['key'] . '.')): ?> · <code><?= e($s['key']) ?></code><?php endif; ?></small></span>
            <?php if ($here): ?><span class="adm-sw__cur"><?= icon('check') ?><span class="adm-sr"><?= e(__('geöffnet')) ?></span></span><?php elseif ($s['network']): ?><span class="adm-sw__tag"><?= e(__('Netzwerk-Website')) ?></span><?php endif; ?>
          <?= $s['current'] ? '</a>' : '</button>' ?>
          <?php endforeach; ?>
        </div>
      </div>
      <p class="adm-pop__none" data-pop-none hidden><?= e(__('Keine Website gefunden.')) ?></p>
    </form>
  </div>
</div>
