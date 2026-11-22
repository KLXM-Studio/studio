<?php
/**
 * Kopfleiste der Verwaltung: über dem Inhalt, rechts neben der Seitenleiste (die bis ganz nach oben reicht – kein Kopf über die ganze
 * Breite). Links der Umschalter Netzwerk ↔ Website (Netzwerk-Konten mit mehreren Websites, _areaswitch.php) bzw. der Name der Website,
 * in der Mitte die Suche direkt in der Leiste (Quelle wählen oder per Kürzel – resources/js/_quickbar.js, /admin/api/search?scope=…),
 * rechts Seitenbaum, Favoriten (Abschnitt #adm-fav, resources/js/_favorites.js) und das Konto-Menü mit Hilfe und Assistent.
 * „Website ansehen“ bleibt erster Punkt der Seitenleiste.
 * Farbe je Modus (resources/css/_topbar.css): Website wie die Seitenleiste, Netzwerk Petrol, „Ansicht als Rolle/Person“ Pflaume;
 * Testumgebung als Hinweis mit Streifen an der Unterkante. Telefon: volle Breite mit Menü-Knopf, Seitenleiste als Schublade.
 * @var array $user  @var array $favs  @var string $favHere  @var string $favTitle  @var string $favIcon  @var bool $netArea  @var bool $netMulti
 * @var ?array $tbImp  @var string $tbEnv  @var int $supportN  @var string $section  @var string $view
 */
$tbTree = !$netArea && can('pages.edit') ? array_slice(\Core\Pages::flat(), 0, 60) : [];
$tbIsFav = (bool) array_filter($favs, fn($f) => $f['url'] === $favHere);
// Quellen der Suche: [Kürzel, Name, Symbol] – Kürzel = Präfix im Feld, scope = Schlüssel für /admin/api/search
$tbScopes = [['', __('Alles'), 'magnifying-glass'], ['p:', __('Seiten'), 'file-text'], ['d:', __('Daten'), 'database'], ['m:', __('Medien'), 'image'],
    ['k:', __('Kontakte'), 'address-book'], ['t:', __('Termine'), 'calendar-blank'], ['f:', __('Favoriten'), 'star'], ['>', __('Befehle'), 'lightning']];
if ($netArea) $tbScopes = array_values(array_filter($tbScopes, fn($s) => in_array($s[0], ['', 'f:', '>'], true)));
$tbName = (string) ($user['name'] ?: $user['email']);
?>
<header class="adm-tb<?= $netArea ? ' is-net' : '' ?><?= $tbImp ? ' is-imp' : '' ?><?= $tbEnv !== '' ? ' is-env' : '' ?>" data-tb>
  <a class="adm-tb__icobtn adm-tb__menu" href="#adm-side" aria-label="<?= e(__('Menü öffnen')) ?>" data-drawer-open><?= icon('list') ?></a>
  <?php if ($netMulti): include __DIR__ . '/_areaswitch.php'; else: ?>
  <a class="adm-tb__site" href="<?= e(url('/admin')) ?>"><span class="adm-sw__eyebrow"><?= e(__('Website')) ?></span><span class="adm-sw__name"><?= e(site_name()) ?></span></a>
  <?php endif; ?>

  <div class="adm-tb__search" role="search" aria-label="<?= e(__('Verwaltung durchsuchen')) ?>" data-qb data-search-endpoint="<?= e(url('/admin/api/search')) ?>" data-fav-endpoint="<?= e(url('/admin/api/favorites')) ?>">
    <button type="button" class="adm-tb__scope" popovertarget="adm-qb-scope" aria-haspopup="menu" data-pop-btn><span class="adm-sr"><?= e(__('Suchen in')) ?> </span><span data-qb-scope-label><?= e(__('Alles')) ?></span><?= icon('caret-down') ?></button>
    <div class="adm-pop adm-pop--menu" id="adm-qb-scope" popover data-pop>
      <div role="menu" aria-label="<?= e(__('Suchen in')) ?>">
        <?php foreach ($tbScopes as [$sk, $sl, $si]): ?>
        <button type="button" role="menuitemradio" aria-checked="<?= $sk === '' ? 'true' : 'false' ?>" class="adm-pop__item" data-qb-scope="<?= e($sk) ?>"><?= icon($si) ?><span><?= e($sl) ?></span><?php if ($sk !== ''): ?><kbd><?= e($sk) ?></kbd><?php endif; ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <span class="adm-tb__field"><?= icon('magnifying-glass') ?>
      <input type="text" class="is-bare" role="combobox" aria-expanded="false" aria-controls="adm-qb-list" aria-autocomplete="list" autocomplete="off" spellcheck="false" aria-keyshortcuts="Meta+K Control+K"
        placeholder="<?= e($netArea ? __('Netzwerk durchsuchen oder Befehl …') : __('Suchen oder Befehl …')) ?>" aria-label="<?= e(__('Suchen')) ?>" data-qb-input>
      <kbd aria-hidden="true" data-kbd>⌘K</kbd></span>
    <div class="adm-qb" data-qb-panel hidden>
      <div class="adm-qb__list" id="adm-qb-list" role="listbox" aria-label="<?= e(__('Treffer')) ?>"></div>
      <footer class="adm-qb__foot" aria-hidden="true"><span><kbd>↑</kbd><kbd>↓</kbd> <?= e(__('wählen')) ?></span><span><kbd>↵</kbd> <?= e(__('öffnen')) ?></span><span><kbd>⌘↵</kbd> <?= e(__('neuer Tab')) ?></span><span><kbd>→</kbd> <?= e(__('Aktionen')) ?></span><span><kbd>⌘D</kbd> <?= e(__('Favorit')) ?></span><span><kbd>Bild↑↓</kbd> <?= e(__('Gruppe')) ?></span><span><kbd>p:</kbd> <kbd>&gt;</kbd> <kbd>f:</kbd> <?= e(__('Quelle')) ?></span></footer>
    </div>
  </div>

  <div class="adm-tb__tools">
    <?php if ($tbEnv !== ''): ?><span class="adm-tb__env" title="<?= e(__('Testumgebung – Änderungen hier gehen nicht auf die Live-Website. E-Mails werden umgeleitet, Suchmaschinen ausgesperrt.')) ?>"><?= icon('flask') ?><span><?= e(ucfirst($tbEnv)) ?></span><span class="adm-sr"> – <?= e(__('Testumgebung – Änderungen hier gehen nicht auf die Live-Website. E-Mails werden umgeleitet, Suchmaschinen ausgesperrt.')) ?></span></span><?php endif; ?>
    <?php if ($tbImp): // Ansicht als Rolle/Person (Core\Impersonation): Kopfleiste in Pflaume, Rückweg immer sichtbar ?>
    <div class="adm-tb__imp" role="status"><?= icon('user-circle') ?><span title="<?= e(__('Bis {time} · Konto, Benutzer und Grundeinstellungen sind gesperrt.', ['time' => \Core\Format::admin()->time((int) $tbImp['until'])])) ?>"><?= e($tbImp['mode'] === 'role' ? __('Ansicht als Rolle „{name}“', ['name' => $tbImp['label']]) : __('Sie arbeiten als {name}', ['name' => $tbImp['label']])) ?><small> · <?= e(__('bis {time}', ['time' => \Core\Format::admin()->time((int) $tbImp['until'])])) ?></small></span>
      <form method="post" action="<?= e(url('/admin/impersonate/stop')) ?>"><?= csrf_field() ?><button class="adm-tb__impbtn"><?= e(__('Zurück zu meinem Konto')) ?></button></form></div>
    <?php endif; ?>
    <button type="button" class="adm-tb__icobtn adm-tb__msearch" data-spotlight aria-label="<?= e(__('Suchen')) ?>"><?= icon('magnifying-glass') ?></button>
    <?php if ($tbTree): ?>
    <button type="button" class="adm-tb__icobtn" data-tb-desk popovertarget="adm-tb-tree" aria-haspopup="menu" data-pop-btn title="<?= e(__('Seitenbaum')) ?>" aria-label="<?= e(__('Seitenbaum')) ?>"><?= icon('tree-structure') ?></button>
    <div class="adm-pop adm-pop--end adm-pop--tree" id="adm-tb-tree" popover data-pop>
      <div class="adm-pop__head"><strong><?= e(__('Seitenbaum')) ?></strong><a href="<?= e(url('/admin/pages')) ?>"><?= e(__('Alle Seiten')) ?></a></div>
      <?php if (count($tbTree) > 8): ?><label class="adm-pop__filter"><?= icon('magnifying-glass') ?><input type="search" class="is-bare" placeholder="<?= e(__('Seite suchen …')) ?>" aria-label="<?= e(__('Seiten filtern')) ?>" autocomplete="off" data-pop-filter></label><?php endif; ?>
      <div role="menu" aria-label="<?= e(__('Seitenbaum')) ?>">
        <?php foreach ($tbTree as $pg): ?>
        <a role="menuitem" class="adm-pop__item adm-pop__item--d<?= min(4, (int) $pg['depth']) ?>" href="<?= e(url('/admin/pages/' . (int) $pg['id'])) ?>" data-filter-text="<?= e(mb_strtolower((string) $pg['title'])) ?>"><?= icon(!empty($pg['is_home']) ? 'house' : 'file-text') ?><span><?= e((string) $pg['title']) ?></span><?php if (($pg['status'] ?? '') !== 'published'): ?><small><?= e(__('Entwurf')) ?></small><?php endif; ?></a>
        <?php endforeach; ?>
      </div>
      <p class="adm-pop__none" data-pop-none hidden><?= e(__('Keine Seite gefunden.')) ?></p>
    </div>
    <?php endif; ?>
    <button type="button" class="adm-tb__icobtn<?= $tbIsFav ? ' is-on' : '' ?>" popovertarget="adm-tb-fav" data-pop-btn data-tb-fav-btn title="<?= e(__('Favoriten')) ?>" aria-label="<?= e(__('Favoriten')) ?>"><?= icon('star') ?></button>
    <div class="adm-pop adm-pop--end adm-pop--fav" id="adm-tb-fav" popover data-pop>
      <?php if ($favHere !== ''): ?>
      <button type="button" class="adm-pop__item adm-pop__item--act" data-tb-fav-here><?= icon('star') ?><span data-tb-fav-label data-on="<?= e(__('Gemerkt – entfernen')) ?>" data-off="<?= e(__('Diese Seite merken')) ?>"><?= e($tbIsFav ? __('Gemerkt – entfernen') : __('Diese Seite merken')) ?></span></button>
      <?php endif; ?>
      <?php // Favoriten: Liste, Bearbeiten (umbenennen, sortieren, entfernen) – resources/js/_favorites.js ?>
      <section class="adm-fav<?= $favs ? '' : ' is-empty' ?>" id="adm-fav" aria-labelledby="adm-fav-h" data-fav-endpoint="<?= e(url('/admin/api/favorites')) ?>"
        data-fav-here="<?= e($favHere) ?>" data-fav-title="<?= e($favTitle) ?>" data-fav-icon="<?= e($favIcon) ?>" data-fav-ico="<?= e((string) \Core\Icons::resolve($favIcon)) ?>" data-fav-user="<?= (int) $user['id'] ?>" data-fav-max="<?= \Core\Favorites::MAX ?>">
        <div class="adm-fav__head">
          <h2 class="adm-fav__h" id="adm-fav-h"><?= e(__('Favoriten')) ?></h2>
          <a class="adm-fav__edit" href="<?= e(url('/admin/account#favoriten')) ?>" data-fav-edit><?= e(__('Bearbeiten')) ?></a>
        </div>
        <div class="adm-fav__body" id="adm-fav-body">
          <nav aria-labelledby="adm-fav-h">
            <ul class="adm-fav__list" data-fav-list>
              <?php foreach ($favs as $f): $favSvg = \Core\Icons::render($f['icon'] ?: 'fav', ['class' => 'adm-fav__ico']); ?>
              <li data-url="<?= e($f['url']) ?>"><a href="<?= e(url($f['url'])) ?>"<?= $f['url'] === $favHere ? ' aria-current="page"' : '' ?>><?= $favSvg ?><span><?= e($f['title']) ?></span></a></li>
              <?php endforeach; ?>
            </ul>
          </nav>
          <p class="adm-fav__empty" data-fav-empty<?= $favs ? ' hidden' : '' ?>><?= e(__('Noch keine Favoriten. Mit dem Stern ☆ oben auf jeder Seite merken Sie sich Seiten, Tabellen, Einträge oder Einstellungen – sie erscheinen dann hier.')) ?></p>
        </div>
        <script type="application/json" id="adm-fav-data"><?= json_encode(array_map(fn($f) => $f + ['href' => url($f['url']), 'ico' => \Core\Icons::resolve($f['icon'] ?: 'fav')], $favs), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      </section>
    </div>
    <button type="button" class="adm-tb__me" popovertarget="adm-tb-me" aria-haspopup="menu" data-pop-btn><span class="adm-sr"><?= e(__('Konto und Hilfe: {name}', ['name' => $tbName])) ?><?= $supportN ? ' – ' . e(__('{n} ungelesen', ['n' => $supportN])) : '' ?></span><?= \Core\Avatar::html($user, 'adm-ava adm-ava--m') ?><?php if ($supportN): ?><span class="adm-tb__dot" aria-hidden="true"><?= (int) $supportN ?></span><?php endif; ?></button>
    <div class="adm-pop adm-pop--end" id="adm-tb-me" popover data-pop>
      <div class="adm-pop__me"><?= \Core\Avatar::html($user, 'adm-ava adm-ava--m') ?><span><strong><?= e($tbName) ?></strong><small><?= e(app()->auth->role()['name'] ?? $user['role']) ?> · <?= e($user['email']) ?></small></span></div>
      <?php $usCtx = 'pop'; include __DIR__ . '/account/_uiscale.php'; // Schriftgröße der Verwaltung (Core\UiScale) – außerhalb des Menüs (kein Menüpunkt) ?>
      <div role="menu" aria-label="<?= e(__('Konto und Hilfe')) ?>">
        <a role="menuitem" class="adm-pop__item" href="<?= e(url('/admin/account')) ?>"<?= $section === 'account' ? ' aria-current="page"' : '' ?>><?= icon('user-circle') ?><span><?= e(__('Konto')) ?></span></a>
        <a role="menuitem" class="adm-pop__item" href="<?= e(url('/admin/account#darstellung')) ?>"><?= icon('paint-brush') ?><span><?= e(__('Erscheinungsbild')) ?></span></a>
        <div role="separator" class="adm-pop__sep"></div>
        <a role="menuitem" class="adm-pop__item" href="<?= e(url('/admin/hilfe')) ?>"<?= $section === 'help' ? ' aria-current="page"' : '' ?>><?= icon('question') ?><span><?= e(__('Handbuch & Hilfe')) ?></span></a>
        <?php if (\Core\Support\Support::canRead()): // Support & Wissensdatenbank (Core\Support) ?>
        <a role="menuitem" class="adm-pop__item" href="<?= e(url('/admin/support')) ?>"<?= $section === 'support' && $view !== 'support/new' ? ' aria-current="page"' : '' ?>><?= icon('lifebuoy') ?><span><?= e(__('Support')) ?></span><?php if ($supportN): ?><small class="adm-pop__count"><?= (int) $supportN ?> <?= e(__('ungelesen')) ?></small><?php endif; ?></a>
        <?php endif; ?>
        <?php if (can('system.manage')): // Begrüßungsfenster erneut öffnen (Core\Welcome) ?>
        <a role="menuitem" class="adm-pop__item" href="<?= e(url('/admin') . '?' . \Core\Welcome::QUERY . '=1') ?>"><?= icon('list-checks') ?><span><?= e(__('Erste Schritte')) ?></span></a>
        <?php endif; ?>
        <?php if (\Core\Guide::exists()): // Hinweise zu diesem Projekt (Core\Guide) ?>
        <a role="menuitem" class="adm-pop__item" href="<?= e(url('/admin/hilfe')) ?>#projekt"><?= icon('lightbulb') ?><span><?= e(__('Projekt-Hinweise')) ?></span></a>
        <?php endif; ?>
        <?php if (\Core\AI\Assistant::available()): // Assistent-Chat (Core\AI\Assistant) – öffnet das Chat-Fenster, ohne JavaScript die Seite im KI-Bereich ?>
        <a role="menuitem" class="adm-pop__item" href="<?= e(url('/admin/ai/assistent')) ?>" data-assistant aria-keyshortcuts="Alt+Shift+K"><?= icon('chat-teardrop-dots') ?><span><?= e(__('Assistent fragen')) ?></span><kbd>⌥⇧K</kbd></a>
        <?php endif; ?>
        <?php if (\Core\Support\Support::canReport()): // Problem melden (Core\Support) – nimmt die aktuelle Seite als Kontext mit ?>
        <a role="menuitem" class="adm-pop__item" href="<?= e(url('/admin/support/neu')) ?>" data-support-report><?= icon('warning') ?><span><?= e(__('Problem melden')) ?></span></a>
        <?php endif; ?>
        <div role="separator" class="adm-pop__sep"></div>
        <form method="post" action="<?= e(url('/admin/logout')) ?>"><?= csrf_field() ?><button role="menuitem" type="submit" class="adm-pop__item"><?= icon('sign-out') ?><span><?= e(__('Abmelden')) ?></span></button></form>
      </div>
    </div>
  </div>
</header>
