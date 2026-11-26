<?php
/**
 * Adressbuch: Listen (links) · Namen (Mitte) · Karte (rechts). Schmale Bildschirme zeigen eine Spalte (is-pane-src|names|card)
 * mit Zurück-Links. resources/js/_addressbook.js ersetzt beim Wechsel nur Teile (?_teil=…) und schreibt die Adresse.
 * @var array $t  @var array $s  @var array $lists  @var string $list  @var string $filter  @var string $q  @var array $rows
 * @var ?array $e  @var bool $select  @var string $pane  @var array $counts  @var array $books
 */
use Core\Data\AddressBook;

$base = url('/admin/data/' . $t['handle']);
$L = fn(array $o = []) => AddressBook::link($t, $s, $o);
$cur = fn(bool $on) => $on ? ' aria-current="page"' : '';
$scope = $list !== '' ? $lists[$list] : ($filter !== '' ? '' : __('Alle Kontakte'));
$filterLabels = ['offline' => __('Nicht auf der Website'), 'nofoto' => __('Ohne Foto'), 'nomail' => __('Ohne E-Mail')];
$filterIcons = ['offline' => 'lock', 'nofoto' => 'user', 'nomail' => 'envelope-simple'];
if ($filter !== '') $scope = $filterLabels[$filter];
$canSchema = can('data.schema') && \Core\Data\Shared::canSchema($t);
$back = AddressBook::link($t, $s);
$subs = \Core\Data\Subscriptions::kind($t) === 'contacts';
?>
<div class="ab is-pane-<?= e($pane) ?><?= $select ? ' is-select' : '' ?><?= !empty($edit) || !empty($new) ? ' is-editing' : '' ?>"<?php if (can('data.import', $t['handle'])): ?> data-imp-target="<?= e($base . '/import') ?>" data-imp-label="<?= e(__('vCard-Datei loslassen zum Importieren')) ?>"<?php endif; ?> data-ab data-ab-base="<?= e($base) ?>" data-ab-assign="<?= e($base . '/listen') ?>"<?= \Core\RowMenu::selectAttrs() ?>
  data-ab-t-none="<?= e(__('Bitte zuerst Kontakte auswählen.')) ?>">
  <nav class="ab-src" aria-label="<?= e(__('Adressbücher und Listen')) ?>">
    <ul>
      <li><a href="<?= e($L(['liste' => '', 'filter' => '', 'ab' => '', 'ansicht' => ''])) ?>" data-ab-nav<?= $cur($list === '' && $filter === '') ?>><?= icon('users') ?><span><?= e(__('Alle Kontakte')) ?></span><small><?= (int) $counts['all'] ?></small></a></li>
    </ul>
    <?php if (count($books) > 1): ?>
    <h2><?= e(__('Adressbücher')) ?></h2>
    <ul>
      <?php foreach ($books as $b): ?>
      <li><a href="<?= e(url('/admin/data/' . $b['handle'])) ?>"<?= $b['handle'] === $t['handle'] ? ' aria-current="true"' : '' ?>><?= icon('address-book') ?><span><?= e($b['name']) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <h2 id="ab-lists-h"><?= e(__('Listen')) ?></h2>
    <?php if ($lists): ?>
    <ul aria-labelledby="ab-lists-h" data-ab-lists>
      <?php foreach ($lists as $k => $label): ?>
      <li><a href="<?= e($L(['liste' => $k, 'filter' => '', 'ab' => '', 'ansicht' => ''])) ?>" data-ab-nav data-ab-drop="<?= e((string) $k) ?>" data-ab-label="<?= e($label) ?>"<?= $cur($list === (string) $k) ?>><?= icon('list-bullets') ?><span><?= e($label) ?></span><small data-ab-count="<?= e((string) $k) ?>"><?= (int) ($counts['lists'][$k] ?? 0) ?></small></a></li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="ab-src__hint"><?= e(__('Noch keine Listen – Listen gruppieren Kontakte wie „Vorstand“ oder „Presse“.')) ?></p>
    <?php endif; ?>
    <h2 id="ab-filter-h"><?= e(__('Filter')) ?></h2>
    <ul aria-labelledby="ab-filter-h">
      <?php foreach ($filterLabels as $k => $label): ?>
      <li><a href="<?= e($L(['filter' => $k, 'liste' => '', 'ab' => '', 'ansicht' => ''])) ?>" data-ab-nav<?= $cur($filter === $k) ?>><?= icon($filterIcons[$k]) ?><span><?= e($label) ?></span><small data-ab-count="~<?= e($k) ?>"><?= (int) $counts[$k] ?></small></a></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($canSchema && \Core\Data\Tables::field($t, 'listen')): ?>
    <details class="ab-newlist">
      <summary><?= icon('plus') ?> <?= e(__('Neue Liste')) ?></summary>
      <form method="post" action="<?= e($base . '/listen') ?>">
        <?= csrf_field() ?><input type="hidden" name="zurueck" value="<?= e($back) ?>">
        <label class="sr-only" for="ab-newlist"><?= e(__('Name der neuen Liste')) ?></label>
        <input id="ab-newlist" name="neu" maxlength="80" required placeholder="<?= e(__('z. B. Presse')) ?>">
        <button type="submit" class="adm-btn adm-btn--small adm-btn--primary"><?= e(__('Anlegen')) ?></button>
      </form>
    </details>
    <?php endif; ?>
  </nav>

  <section class="ab-list" aria-labelledby="ab-list-h">
    <div class="ab-list__top">
      <div class="ab-mobhead">
        <a class="ab-back" href="<?= e($L(['ansicht' => 'listen', 'k' => ''])) ?>" data-ab-pane="src"><?= icon('arrow-left') ?> <?= e(__('Listen')) ?></a>
      </div>
      <h2 id="ab-list-h" class="ab-list__h" data-ab-scope><?= e($scope) ?></h2>
      <form class="ab-search" method="get" action="<?= e($base) ?>" role="search" data-ab-search>
        <?= icon('magnifying-glass') ?>
        <?php if ($list !== ''): ?><input type="hidden" name="liste" value="<?= e($list) ?>"><?php endif; ?>
        <?php if ($filter !== ''): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
        <?php if ($select): ?><input type="hidden" name="auswahl" value="1"><?php endif; ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Name, Funktion, Telefon, E-Mail')) ?>" aria-label="<?= e(__('Kontakte durchsuchen: Name, Funktion, Telefon, E-Mail')) ?>" autocomplete="off" data-ab-q>
        <button type="submit" class="sr-only"><?= e(__('Suchen')) ?></button>
      </form>
      <div class="ab-meta">
        <span aria-live="polite" data-ab-total><b><?= e(count($rows) === 1 ? __('1 Kontakt') : __('{n} Kontakte', ['n' => count($rows)])) ?></b> · <?= e(__('nach Nachname')) ?></span>
        <span class="ab-meta__tools">
          <?php if (can('data.import', $t['handle'])): ?><a class="ab-tool" href="<?= e($base . '/import') ?>"><?= icon('upload-simple') ?> <?= e(__('vCard importieren')) ?></a><?php endif; ?>
          <a class="ab-tool<?= $select ? ' is-on' : '' ?>" href="<?= e($L(['auswahl' => $select ? '' : '1'])) ?>" data-ab-select<?= $select ? ' aria-current="true"' : '' ?>><?= icon('list-checks') ?> <?= e(__('Auswählen')) ?></a>
          <?php if (can('data.export', $t['handle']) || ($subs && can('data.edit', $t['handle']))): ?>
          <details class="ab-menu">
            <summary class="ab-tool"><?= icon('export') ?> <?= e(__('Exportieren')) ?></summary>
            <div class="ab-menu__pop">
              <?php if (can('data.export', $t['handle'])): ?>
              <a href="<?= e($base . '/vcard?' . http_build_query(array_filter(['liste' => $list, 'filter' => $filter, 'q' => $q]))) ?>" download><?= e(__('Diese Kontakte als vCard – alle Angaben')) ?></a>
              <a href="<?= e($base . '/vcard?' . http_build_query(array_filter(['liste' => $list, 'filter' => $filter, 'q' => $q, 'umfang' => 'oeffentlich']))) ?>" download><?= e(__('Diese Kontakte als vCard – nur öffentliche Angaben')) ?></a>
              <?php if ($list !== '' || $filter !== '' || $q !== ''): ?><a href="<?= e($base . '/vcard') ?>" download><?= e(__('Ganzes Adressbuch als vCard')) ?></a><?php endif; ?>
              <?php endif; ?>
              <?php if ($subs && can('data.edit', $t['handle'])): ?><a href="<?= e($base . '/abonnieren') ?>"><?= icon('rss') ?> <?= e(__('In Apps abonnieren …')) ?></a><?php endif; ?>
            </div>
          </details>
          <?php endif; ?>
        </span>
      </div>
    </div>
    <div class="ab-names" data-ab-names>
      <?= \Core\Theme::capture(__DIR__ . '/_ab_names.php', get_defined_vars()) ?>
    </div>
    <form id="ab-sel" class="ab-selbar" method="post" action="<?= e($base . '/listen') ?>" data-ab-selbar<?= $select ? '' : ' hidden' ?>>
      <?= csrf_field() ?><input type="hidden" name="zurueck" value="<?= e($back) ?>">
      <?php // Mehrfachauswahl wie in allen Listen (Core\RowMenu::bar): Zähler, Aktionen mit denselben Symbolen; Rechtsklick auf eine gewählte Zeile bietet sie an
      $selItems = [];
      if ($lists) {
          $selItems[] = ['key' => 'move', 'icon' => 'list-bullets', 'label' => __('Zu Liste hinzufügen'), 'submit' => true, 'attrs' => ['name' => 'op', 'value' => 'add']];
          $selItems[] = ['key' => 'move', 'icon' => 'x', 'label' => __('Aus Liste entfernen'), 'submit' => true, 'attrs' => ['name' => 'op', 'value' => 'remove']];
      }
      if (can('data.export', $t['handle'])) $selItems[] = ['key' => 'export', 'label' => __('vCard exportieren'), 'attrs' => ['formaction' => $base . '/vcard']];
      $listSel = '';
      if ($lists) {
          $listSel = '<label class="sr-only" for="ab-sel-list">' . e(__('Liste')) . '</label><select id="ab-sel-list" name="liste">';
          foreach ($lists as $k => $label) $listSel .= '<option value="' . e((string) $k) . '"' . ($list === (string) $k ? ' selected' : '') . '>' . e($label) . '</option>';
          $listSel .= '</select>';
      }
      echo \Core\RowMenu::bar($selItems, ['always' => true, 'class' => 'ab-selbar__bar', 'before' => $listSel]); ?>
    </form>
  </section>

  <article class="ab-card" data-ab-card aria-label="<?= e(__('Kontakt')) ?>">
    <?= \Core\Theme::capture(__DIR__ . (!empty($edit) || !empty($new) ? '/_ab_edit.php' : '/_ab_card.php'), get_defined_vars()) ?>
  </article>
</div>
