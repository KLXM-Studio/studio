<?php
/**
 * PROTOTYP Variante B (?ui=b): Kopfleiste über dem Inhalt – rechts neben der Seitenleiste, die bis ganz nach oben reicht (kein
 * durchgehender Kopf über die ganze Breite). Links Umschalter Netzwerk ↔ Website, Mitte Suche direkt in der Leiste (Quellen-Auswahl,
 * Kürzel, Favoriten/zuletzt geöffnet ohne Eingabe – resources/js/_quickbar.js), rechts Seitenbaum, Favoriten, Website ansehen,
 * Benutzer-Menü. Farbe je Modus: Website Navy, Netzwerk Petrol (bzw. Graphit), Ansicht als Rolle/Person Pflaume; Testumgebung als
 * Hinweis in der Leiste statt Streifen unten.
 * @var array $user  @var array $favs  @var string $favHere  @var bool $netArea  @var bool $netMulti  @var ?array $tbImp  @var string $tbEnv
 */
$tbFavs = array_slice($favs, 0, 12);
$tbTree = !$netArea && can('pages.edit') ? array_slice(\Core\Pages::flat(), 0, 40) : [];
$tbIsFav = (bool) array_filter($favs, fn($f) => $f['url'] === $favHere);
$tbScopes = [['', __('Alles'), 'magnifying-glass', ''], ['p:', __('Seiten'), 'file-text', 'p:'], ['d:', __('Daten'), 'database', 'd:'], ['m:', __('Medien'), 'image', 'm:'],
    ['k:', __('Kontakte'), 'address-book', 'k:'], ['t:', __('Termine'), 'calendar-blank', 't:'], ['f:', __('Favoriten'), 'star', 'f:'], ['>', __('Befehle'), 'lightning', '>']];
?>
<header class="adm-tb<?= $netArea ? ' is-net' : '' ?><?= $tbImp ? ' is-imp' : '' ?><?= $tbEnv !== '' ? ' is-env' : '' ?>" data-tb>
  <a class="adm-tb__icobtn adm-tb__menu" href="#adm-side" aria-label="<?= e(__('Menü öffnen')) ?>" data-drawer-open><?= icon('list') ?></a>
  <?php if ($netMulti): $swMode = 'top'; include __DIR__ . '/_areaswitch.php'; else: ?>
  <a class="adm-tb__site" href="<?= e(url('/admin')) ?>"><span class="adm-sw__eyebrow"><?= e(__('Website')) ?></span><span class="adm-sw__name"><?= e(site_name()) ?></span></a>
  <?php endif; ?>

  <div class="adm-tb__search" data-qb data-search-endpoint="<?= e(url('/admin/api/search')) ?>" data-fav-endpoint="<?= e(url('/admin/api/favorites')) ?>">
    <button type="button" class="adm-tb__scope" popovertarget="adm-qb-scope" aria-haspopup="menu" data-pop-btn data-qb-scope-btn><span data-qb-scope-label><?= e(__('Alles')) ?></span><?= icon('caret-down') ?></button>
    <div class="adm-pop adm-pop--menu" id="adm-qb-scope" popover data-pop>
      <div role="menu" aria-label="<?= e(__('Suchen in')) ?>">
        <?php foreach ($tbScopes as [$sk, $sl, $si, $sp]): ?>
        <button type="button" role="menuitemradio" aria-checked="<?= $sk === '' ? 'true' : 'false' ?>" class="adm-pop__item" data-qb-scope="<?= e($sk) ?>"><?= icon($si) ?><span><?= e($sl) ?></span><?php if ($sp !== ''): ?><kbd><?= e($sp) ?></kbd><?php endif; ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <span class="adm-tb__field"><?= icon('magnifying-glass') ?>
      <input type="text" class="is-bare" role="combobox" aria-expanded="false" aria-controls="adm-qb-list" aria-autocomplete="list" autocomplete="off" spellcheck="false"
        placeholder="<?= e($netArea ? __('Netzwerk durchsuchen oder Befehl …') : __('Suchen oder Befehl …')) ?>" aria-label="<?= e(__('Suchen')) ?>" data-qb-input>
      <kbd aria-hidden="true" data-kbd>⌘K</kbd></span>
    <div class="adm-qb" data-qb-panel hidden>
      <div class="adm-qb__list" id="adm-qb-list" role="listbox" aria-label="<?= e(__('Treffer')) ?>"></div>
      <footer class="adm-qb__foot"><span><kbd>↑</kbd><kbd>↓</kbd> <?= e(__('wählen')) ?></span><span><kbd>↵</kbd> <?= e(__('öffnen')) ?></span><span><kbd>⌘↵</kbd> <?= e(__('neuer Tab')) ?></span><span><kbd>→</kbd> <?= e(__('Aktionen')) ?></span><span><kbd>⌘D</kbd> <?= e(__('Favorit')) ?></span><span><kbd>p:</kbd> <kbd>&gt;</kbd> <kbd>f:</kbd> <?= e(__('Quelle')) ?></span></footer>
    </div>
  </div>

  <div class="adm-tb__tools">
    <?php if ($tbEnv !== ''): ?><span class="adm-tb__env" title="<?= e(__('Testumgebung – Änderungen hier gehen nicht auf die Live-Website. E-Mails werden umgeleitet, Suchmaschinen ausgesperrt.')) ?>"><?= icon('flask') ?><span><?= e(ucfirst($tbEnv)) ?></span></span><?php endif; ?>
    <?php if ($tbTree): ?>
    <button type="button" class="adm-tb__icobtn" data-tb-desk popovertarget="adm-tb-tree" aria-haspopup="menu" data-pop-btn title="<?= e(__('Seitenbaum')) ?>" aria-label="<?= e(__('Seitenbaum')) ?>"><?= icon('tree-structure') ?></button>
    <div class="adm-pop adm-pop--end adm-pop--tree" id="adm-tb-tree" popover data-pop>
      <div class="adm-pop__head"><strong><?= e(__('Seitenbaum')) ?></strong><a href="<?= e(url('/admin/pages')) ?>"><?= e(__('Alle Seiten')) ?></a></div>
      <label class="adm-pop__filter"><?= icon('magnifying-glass') ?><input type="search" class="is-bare" placeholder="<?= e(__('Seite suchen …')) ?>" aria-label="<?= e(__('Seiten filtern')) ?>" autocomplete="off" data-pop-filter></label>
      <div role="menu" aria-label="<?= e(__('Seitenbaum')) ?>">
        <?php foreach ($tbTree as $pg): ?>
        <a role="menuitem" class="adm-pop__item adm-pop__item--d<?= min(4, (int) $pg['depth']) ?>" href="<?= e(url('/admin/pages/' . (int) $pg['id'])) ?>" data-filter-text="<?= e(mb_strtolower((string) $pg['title'])) ?>"><?= icon(!empty($pg['is_home']) ? 'house' : 'file-text') ?><span><?= e((string) $pg['title']) ?></span><?php if (($pg['status'] ?? '') !== 'published'): ?><small><?= e(__('offline')) ?></small><?php endif; ?></a>
        <?php endforeach; ?>
      </div>
      <p class="adm-pop__none" data-pop-none hidden><?= e(__('Keine Seite gefunden.')) ?></p>
    </div>
    <?php endif; ?>
    <button type="button" data-tb-desk class="adm-tb__icobtn<?= $tbIsFav ? ' is-on' : '' ?>" popovertarget="adm-tb-fav" aria-haspopup="menu" data-pop-btn title="<?= e(__('Favoriten')) ?>" aria-label="<?= e(__('Favoriten')) ?>"><?= icon('star') ?></button>
    <div class="adm-pop adm-pop--end" id="adm-tb-fav" popover data-pop>
      <div class="adm-pop__head"><strong><?= e(__('Favoriten')) ?></strong><a href="<?= e(url('/admin/account#favoriten')) ?>"><?= e(__('Bearbeiten')) ?></a></div>
      <div role="menu" aria-label="<?= e(__('Favoriten')) ?>">
        <?php if ($favHere !== ''): ?>
        <button type="button" role="menuitem" class="adm-pop__item adm-pop__item--act" data-tb-fav-here><?= icon('star') ?><span><?= e($tbIsFav ? __('Gemerkt – entfernen') : __('Diese Seite merken')) ?></span><kbd>⌘D</kbd></button>
        <?php endif; ?>
        <?php foreach ($tbFavs as $f): ?>
        <a role="menuitem" class="adm-pop__item" href="<?= e(url($f['url'])) ?>"<?= $f['url'] === $favHere ? ' aria-current="page"' : '' ?>><?= \Core\Icons::render($f['icon'] ?: 'fav') ?><span><?= e($f['title']) ?></span></a>
        <?php endforeach; ?>
      </div>
      <?php if (!$tbFavs): ?><p class="adm-pop__none"><?= e(__('Noch keine Favoriten. Mit dem Stern merken Sie sich die aktuelle Seite.')) ?></p><?php endif; ?>
    </div>
    <a class="adm-tb__icobtn" data-tb-desk data-tb-mid href="<?= e(url('/')) ?>" target="_blank" rel="noopener" title="<?= e(__('Website ansehen')) ?>" aria-label="<?= e(__('Website ansehen')) ?> <?= e(__('(öffnet in neuem Tab)')) ?>"><?= icon('arrow-square-out') ?></a>
    <button type="button" class="adm-tb__icobtn adm-tb__msearch" data-spotlight aria-label="<?= e(__('Suchen')) ?>"><?= icon('magnifying-glass') ?></button>
    <button type="button" class="adm-tb__me" popovertarget="adm-tb-me" aria-haspopup="menu" data-pop-btn aria-label="<?= e(__('Konto: {name}', ['name' => (string) ($user['name'] ?: $user['email'])])) ?>"><?= \Core\Avatar::html($user, 'adm-ava adm-ava--m') ?></button>
    <div class="adm-pop adm-pop--end" id="adm-tb-me" popover data-pop>
      <div class="adm-pop__me"><?= \Core\Avatar::html($user, 'adm-ava adm-ava--m') ?><span><strong><?= e($user['name'] ?: $user['email']) ?></strong><small><?= e(app()->auth->role()['name'] ?? $user['role']) ?> · <?= e($user['email']) ?></small></span></div>
      <div role="menu" aria-label="<?= e(__('Konto')) ?>">
        <a role="menuitem" class="adm-pop__item" href="<?= e(url('/admin/account')) ?>"><?= icon('user-circle') ?><span><?= e(__('Konto')) ?></span></a>
        <a role="menuitem" class="adm-pop__item" href="<?= e(url('/admin/account#darstellung')) ?>"><?= icon('paint-brush') ?><span><?= e(__('Erscheinungsbild')) ?></span></a>
        <a role="menuitem" class="adm-pop__item" href="<?= e(url('/admin/hilfe')) ?>"><?= icon('question') ?><span><?= e(__('Handbuch & Hilfe')) ?></span></a>
        <?php if (\Core\Support\Support::canRead()): ?><a role="menuitem" class="adm-pop__item" href="<?= e(url('/admin/support')) ?>"><?= icon('lifebuoy') ?><span><?= e(__('Support')) ?></span></a><?php endif; ?>
        <form method="post" action="<?= e(url('/admin/logout')) ?>"><?= csrf_field() ?><button role="menuitem" type="submit" class="adm-pop__item"><?= icon('sign-out') ?><span><?= e(__('Abmelden')) ?></span></button></form>
      </div>
    </div>
  </div>
  <?php if ($tbImp): ?>
  <div class="adm-tb__imp" role="status"><?= icon('user-circle') ?><span><?= e($tbImp['mode'] === 'role' ? __('Ansicht als Rolle „{name}“', ['name' => $tbImp['label']]) : __('Sie arbeiten als {name}', ['name' => $tbImp['label']])) ?></span>
    <form method="post" action="<?= e(url('/admin/impersonate/stop')) ?>"><?= csrf_field() ?><button class="adm-tb__impbtn"><?= e(__('Zurück zu meinem Konto')) ?></button></form></div>
  <?php endif; ?>
</header>
