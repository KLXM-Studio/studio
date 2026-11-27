<?php
/**
 * Bereichsnavigation „Daten“: Tabellen (mit Anzahl Einträge), unter der aktuellen Tabelle ihre Ansichten und Einstellungen.
 * Das Layout zeigt sie links anstelle der Hauptnavigation (breite Bildschirme), sonst oben in der Seite (resources/js/_drill.js).
 * @var string $cur  index|list|calendar|entry|new-entry|schema|new|place|subscribe|import|templates  @var ?array $active  aktuelle Tabelle
 */
use Core\Data\Entries;
use Core\Data\Tables;

$schema = can('data.schema');
$tables = array_filter(Tables::content(), fn($t) => $schema || can('data.edit', $t['handle']));
// Auswahllisten (Core\Data\OptionSource) sind normale Tabellen: alle gleichrangig, Listen gesammelt im Abschnitt „Auswahllisten“ mit
// „für News, Termine“ (nutzende Tabellen) – keine Verschachtelung, damit eine Liste mehreren Tabellen gehören kann. An der geöffneten
// Tabelle zeigt „Verknüpft“ ihre Listen, die Listen selbst tragen dann einen Punkt.
$names = array_column(Tables::content(), 'name', 'handle');
$usersOf = [];
foreach ($tables as $t) {
    $u = array_values(array_unique(array_filter(array_map(fn($x) => $x[0]['handle'], \Core\Data\OptionSource::usedBy($t)), fn($h) => $h !== $t['handle'])));
    if ($u) $usersOf[$t['handle']] = $u;
}
$mainT = array_values(array_filter($tables, fn($t) => !isset($usersOf[$t['handle']])));
$listT = array_values(array_filter($tables, fn($t) => isset($usersOf[$t['handle']])));
$tables = $mainT;
foreach ($listT as $i => $l) $tables[] = $l + ['_navlist' => true, '_navgroup' => $i === 0];
// Listen der geöffneten Tabelle (für „Verknüpft“ und den Punkt an den Listen)
$linked = $active ? array_keys(array_filter($usersOf, fn($u) => in_array($active['handle'], $u, true))) : [];
// Detailseite gestalten: Tabellen-Recht oder Seiten bearbeiten + Einträge dieser Tabelle (Core\Data\DetailTemplates::canDesign)
$ac = fn(bool $on, string $v = 'page') => $on ? ' aria-current="' . $v . '"' : '';
?>
<nav class="dt-nav" data-drill-panel aria-label="<?= e(__('Daten')) ?>">
  <ul class="dt-nav__list">
    <li><a class="dt-nav__item" href="<?= e(url('/admin/data')) ?>"<?= $ac($cur === 'index') ?>><span class="dt-nav__ico" aria-hidden="true"><?= icon('squares-four') ?></span><span class="dt-nav__name"><?= e(__('Übersicht')) ?></span></a></li>
    <?php foreach ($tables as $t):
      $base = '/admin/data/' . $t['handle'];
      $edit = can('data.edit', $t['handle']);
      $on = $active && $active['handle'] === $t['handle'];
      $subs = $setup = [];   // Ansichten zum Pflegen | Einrichten (abgesetzt unter „Einrichten“)
      $shared = \Core\Data\Tables::isShared($t);
      if ($on) {
          // Kontakte (Core\Data\AddressBook): Adressbuch-Ansicht neben der Tabelle
          if ($edit && \Core\Data\AddressBook::enabled($t)) $subs[] = [url($base) . '?view=book', __('Adressbuch'), false];
          if ($edit) $subs[] = [url($base) . '?view=list', $shared ? __('Eigene') : (\Core\Data\AddressBook::enabled($t) ? __('Tabelle') : __('Liste')), $cur === 'list'];
          if ($edit && \Core\Data\Calendar::enabled($t)) $subs[] = [url($base) . '?view=calendar', __('Kalender'), $cur === 'calendar'];
          if ($shared && $edit) {
              // Geteilte Tabelle: fremde Einträge, Vorschläge (nur Eigentümer), Anzeige auf dieser Website
              $isOwner = \Core\Data\Shared::isOwner($t);
              if (!$isOwner) $subs[] = [url($base . '/shared') . '?src=owner', term('shared_from_owner'), $cur === 'src-owner'];
              if ($isOwner || $t['shared']['members_see_members']) $subs[] = [url($base . '/shared') . '?src=members', term('shared_from_members'), $cur === 'src-members'];
              if ($isOwner) {
                  $pending = Entries::count($t, ['status' => 'published', 'lang' => 'all', 'source' => 'members', 'suggested' => 'pending']);
                  $subs[] = [url($base . '/shared') . '?src=suggestions', __('Vorschläge') . ($pending ? ' (' . $pending . ')' : ''), $cur === 'src-suggestions'];
              }
              $subs[] = [url($base . '/display'), __('Anzeige auf dieser Website'), $cur === 'display'];
          }
          // KI-Vorschläge für Schlagworte/Kategorien im Bestand (Core\AI\Classify)
          if ($edit && \Core\AI\Classify::enabled($t)) $subs[] = [url($base . '/ki-vorschlaege'), __('KI: Schlagworte vorschlagen'), $cur === 'classify'];
          // Schlagworte (Core\Data\Tags): Verwaltung, sobald die Tabelle ein Feld mit freien Schlagworten hat
          if ($edit && array_filter($t['fields'], [\Core\Data\Tags::class, 'isFree'])) $subs[] = [url($base . '/schlagworte'), __('Schlagworte'), $cur === 'tags'];
          // Abonnieren per App: Kalender- und Kontakte-Tabellen (Core\Data\Subscriptions)
          if (\Core\Data\Exchange::kind($t) !== null && can('data.import', $t['handle'])) $subs[] = [url($base . '/import'), \Core\Data\Exchange::kind($t) === 'ics' ? __('ICS importieren') : __('vCard importieren'), $cur === 'import'];
          if ($edit && \Core\Data\Subscriptions::kind($t) !== null) $subs[] = [url($base . '/abonnieren'), __('Abonnieren'), $cur === 'subscribe'];
          // ---- Einrichten
          if ($schema && \Core\Data\Shared::canSchema($t)) $setup[] = [url($base . '/schema'), __('Felder & Einstellungen'), $cur === 'schema'];
          // Einsetzen: Block auf einer Seite, Seite anlegen, Verwendungen (Core\Data\Placement) – nicht für interne Listen
          if ($schema && \Core\Data\Placement::blockType($t) !== null) $setup[] = [url($base . '/einsetzen'), __('Einsetzen'), $cur === 'place'];
          // Listen-Vorlagen (Core\Blocks\ListTemplates): gespeicherte Darstellungen der Datenliste – Recht „Blöcke gestalten“
          if (can(\Core\Blocks\ListTemplates::PERMISSION) && \Core\Data\Placement::blockType($t) === 'data_list' && app()->theme->block('data_list')) $setup[] = [url($base . '/vorlagen'), __('Listen-Vorlagen'), $cur === 'templates'];
          // Einstellungsseiten von Funktionen/Erweiterungen zu dieser Tabelle (Core\AdminPages, 'table' => …), z. B. Glossar
          foreach (\Core\AdminPages::forTable($t['handle']) as $tp) $setup[] = [url($tp['href']), $tp['label'], $cur === 'page:' . $tp['id']];
      }
      // Detailseiten: auch für die Redaktion mit „Seiten bearbeiten“ + Einträgen dieser Tabelle (Core\Data\DetailTemplates::canDesign)
      $tpl = $on && $t['settings']['route'] !== '' && \Core\Data\DetailTemplates::canDesign($t);
      $links = $on ? $linked : [];
      // Nur ein Unterpunkt (z. B. „Liste“) und nichts einzurichten: kein Untermenü, die Tabelle selbst ist die Seite
      $single = count($subs) < 2 && !$setup && !$tpl && !$links;
      $isList = !empty($t['_navlist']);
    ?>
    <?php if (!empty($t['_navgroup'])): ?><li class="dt-nav__group" role="presentation"><?= e(__('Auswahllisten')) ?></li><?php endif; ?>
    <li class="<?= trim(($on ? 'is-open' : '') . ($isList ? ' dt-nav__list-t' : '') . (in_array($t['handle'], $linked, true) ? ' is-linked' : '')) ?>">
      <a class="dt-nav__item<?= $on ? ' is-active' : '' ?>" href="<?= e(url($edit ? $base : $base . '/schema')) ?>"<?= $ac($on, $single && in_array($cur, ['list', 'calendar', 'schema'], true) ? 'page' : 'true') ?>>
        <span class="dt-nav__ico" aria-hidden="true"><?= icon($t['icon']) ?></span><span class="dt-nav__name"><?= e($t['name']) ?><?php if ($shared): ?> <span class="dt-nav__shared" title="<?= e(__('Geteilt mit anderen Websites dieser Installation')) ?>"><?= e(__('geteilt')) ?></span><?php endif; ?></span>
        <?php if ($isList): // Auswahlliste: wer sie nutzt ?><span class="dt-nav__for"><?= e(__('für {tables}', ['tables' => implode(', ', array_map(fn($h) => $names[$h] ?? $h, $usersOf[$t['handle']]))])) ?></span><?php endif; ?>
        <?php if (in_array($t['handle'], $linked, true)): ?><span class="dt-nav__dot" title="<?= e(__('Verknüpft mit {table}', ['table' => $active['name']])) ?>"><span class="sr-only"><?= e(__('Verknüpft mit {table}', ['table' => $active['name']])) ?></span></span><?php endif; ?>
        <small class="dt-nav__count"><?php $n = Entries::count($t, ['status' => 'all', 'source' => 'own']); ?><span aria-hidden="true"><?= $n ?></span><span class="sr-only"><?= e($n === 1 ? __('1 Eintrag') : __('{n} Einträge', ['n' => $n])) ?></span></small>
      </a>
      <?php if (!$single): ?>
      <ul class="dt-nav__sub" aria-label="<?= e($t['name']) ?>">
        <?php foreach ($subs as [$href, $label, $is]): ?>
        <li><a href="<?= e($href) ?>"<?= $ac($is) ?>><?= e($label) ?></a></li>
        <?php endforeach; ?>
        <?php if ($setup || $tpl): ?>
        <li class="dt-nav__subhead" role="presentation"><?= e(__('Einrichten')) ?></li>
        <?php foreach (array_slice($setup, 0, 2) as [$href, $label, $is]): // Felder & Einstellungen, Einsetzen ?>
        <li><a href="<?= e($href) ?>"<?= $ac($is) ?>><?= e($label) ?></a></li>
        <?php endforeach; ?>
        <?php if ($tpl): ?>
        <li><form method="post" action="<?= e(url($base . '/template')) ?>"><?= csrf_field() ?><button type="submit"><?= e(__('Detailseite gestalten')) ?></button></form></li>
        <li><a href="<?= e(url($base . '/detailvorlagen')) ?>"<?= $ac($cur === 'detail-templates') ?>><?= e(__('Detailseiten-Vorlagen')) ?></a></li>
        <?php endif; ?>
        <?php foreach (array_slice($setup, 2) as [$href, $label, $is]): ?>
        <li><a href="<?= e($href) ?>"<?= $ac($is) ?>><?= e($label) ?></a></li>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($links): // Auswahllisten dieser Tabelle ?>
        <li class="dt-nav__links"><span><?= icon('link-simple') ?> <?= e(__('Verknüpft')) ?>:</span> <?= implode(', ', array_map(fn($h) => '<a href="' . e(url('/admin/data/' . $h)) . '">' . e($names[$h] ?? $h) . '</a>', $links)) ?></li>
        <?php endif; ?>
      </ul>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if (\Core\Sources\Sources::navVisible()): /* Externe Quellen (Core\Sources): Feeds, APIs, OpenImmo → Tabellen */ ?>
  <ul class="dt-nav__list dt-nav__list--extra">
    <li><a class="dt-nav__item" href="<?= e(url('/admin/quellen')) ?>"<?= $ac($cur === 'sources') ?>><span class="dt-nav__ico" aria-hidden="true"><?= icon('plugs-connected') ?></span><span class="dt-nav__name"><?= e(__('Externe Quellen')) ?></span></a></li>
  </ul>
  <?php endif; ?>
  <?php if ($schema): ?>
  <a class="dt-nav__new" href="<?= e(url('/admin/data/new')) ?>"<?= $ac($cur === 'new') ?>><?= icon('plus') ?> <?= e(__('Neue Tabelle')) ?></a>
  <?php endif; ?>
</nav>
