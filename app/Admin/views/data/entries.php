<?php
/**
 * Einträge einer Tabelle (Listenansicht wie im Finder).
 * @var array $t  @var array $rows  @var int $total  @var string $status  @var string $q  @var string $sort  @var string $dir
 * @var int $page  @var int $pages  @var array $counts  @var array $user
 */
use Core\Data\Entries;
use Core\Data\Tables;

$cols = array_values(array_filter($t['fields'], fn($f) => !empty($f['in_list']) && $f['name'] !== $t['settings']['title_field']));
// Adressbuch (Exposure::perEntry): „Auf der Website zeigen“ als eigene Spalte „Website“ mit Merkmal statt Ja/Nein
$perEntry = \Core\Data\Exposure::perEntry($t);
if ($perEntry) $cols = array_values(array_filter($cols, fn($f) => $f['name'] !== \Core\Data\Kinds::PUBLIC_FIELD));
$cols = array_slice($cols, 0, 4);
// Sichtbarkeit je Eintrag bzw. Angabe (Core\Data\Exposure::reach) – Symbol + Wort, nicht nur Farbe
$visTag = function (array $e) use ($t): string {
    $on = \Core\Data\Exposure::reach($t, null, $e)['targets'] !== [];
    return '<span class="dt-vis' . ($on ? ' is-public' : '') . '">' . icon($on ? 'globe' : 'lock') . ' ' . e($on ? __('Öffentlich') : __('Intern')) . '</span>';
};
// Zeilenmenü (Core\RowMenu): ⋯, Rechtsklick und Umschalt+F10 – dieselben Aktionen wie in allen Listen; Rechte prüft forEntry()
$rowMenu = function (array $e) use ($t, &$extOrigin, &$extTbl): string {
    return \Core\RowMenu::render('dt-menu-' . (int) $e['id'], Entries::title($t, $e), \Core\RowMenu::forEntry($t, $e, ['row' => $extTbl['row'], 'readonly' => isset($extOrigin[$e['id']])]));
};
// Lange Texte in Spalten: zwei Zeilen, voller Text im Tooltip
$clamp = function (string $html): string {
    $plain = trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return $plain === '' ? '' : '<span class="dt-clamp" title="' . e($plain) . '">' . $html . '</span>';
};
// Spalten nach Wichtigkeit: schmale Liste blendet zuerst „Geändert“, dann die hinteren Felder aus (admin.css, Container-Abfrage)
$prio = fn(int $i) => min(5, $i + 2);
$multiCell = function (array $c, array $e) use ($t): string {
    $items = \Core\Combo::items($e[$c['name']] ?? null);
    $h = '';
    foreach (array_slice($items, 0, 3) as $i) {
        $r = \Core\Data\Exposure::reach($t, $c, $e, $i);
        $on = $r['targets'] !== [];
        $h .= '<li' . ($on ? ' class="is-public"' : '') . ' title="' . e(\Core\Data\Exposure::reachText($r, [])) . '">' . icon($on ? 'globe' : 'lock')
            . '<span class="adm-sr">' . e(\Core\Data\Exposure::reachText($r, [])) . ': </span><span title="' . e(\Core\Combo::itemText($c['type'], $i)) . '">' . e(\Core\Combo::itemText($c['type'], $i)) . '</span></li>';
    }
    if (count($items) > 3) $h .= '<li class="adm-muted">+' . (count($items) - 3) . '</li>';
    return $h !== '' ? '<ul class="dt-items">' . $h . '</ul>' : '';
};
$listImg = $t['settings']['list_image'] ?? 'small';
$img = $listImg === 'none' ? '' : Tables::imageField($t);
// Kleines Vorschaubild für Bild-/Dateifelder in Spalten
$thumb = function (?int $id, string $cls = '') use ($listImg): string {
    if ($listImg === 'none' || !$id || !($m = \Core\Media::find($id))) return '';
    return str_starts_with((string) $m['mime'], 'image/')
        ? '<img src="' . e(\Core\Media::url($m, 480)) . '" alt="" class="dt-thumb' . ($listImg === 'large' ? ' dt-thumb--l' : '') . $cls . '" loading="lazy">'
        : '<span class="dt-file">' . e(\Core\Media::displayName($m)) . '</span>';
};
$base = '/admin/data/' . $t['handle'];
$multi = \Core\Lang::multi();
$qs = fn(array $o) => url($base) . '?' . http_build_query(array_filter(array_merge(['lang' => $multi && $lang !== \Core\Lang::default() ? $lang : '', 'status' => $status === 'all' ? '' : $status, 'q' => $q, 'sort' => $sort, 'dir' => $dir], $o), fn($v) => $v !== '' && $v !== null));
$sortLink = function (string $key, string $label) use ($sort, $dir, $qs) {
    $active = $sort === $key;
    return '<a href="' . e($qs(['sort' => $key, 'dir' => $active && $dir === 'asc' ? 'desc' : 'asc', 'seite' => null])) . '" class="' . ($active ? 'is-sorted' . ($dir === 'desc' ? ' is-desc' : '') : '') . '">' . e($label) . '</a>';
};
$manual = $sort === 'sort' && $q === '' && $status === 'all';
// Geteilte Tabellen (Mitglieder): Entscheidung der Eigentümer-Website über Vorschläge
$ownerPicks = \Core\Data\Tables::isShared($t) && !\Core\Data\Shared::isOwner($t) ? \Core\Data\Shared::picks($t, array_column($rows, 'id'), $t['shared']['owner']) : [];
// Externe Quellen (Core\Sources): Einträge aus einem Feed/einer API – nur lesbar, Badge mit Name der Quelle
$extOrigin = \Core\Sources\Sources::originMap($t, array_column($rows, 'id'));
// Status je Zeile: Online | Offline (war schon online) | Entwurf (nie veröffentlicht); mit data.publish ein Knopf (admin.js → Einträge)
$canPub = $t['settings']['workflow'] && can('data.publish', $t['handle']) && !Tables::isInbox($t);
// Zeitplan (Core\Schedule): Uhr mit „Online ab …“ / „Offline ab …“ neben dem Status
$statusCell = function (array $e) use (&$statusBtn): string {
    $plan = \Core\Schedule::label($e);
    return $statusBtn($e) . ($plan !== '' ? ' <span class="dt-planhint" role="img" aria-label="' . e(__('Zeitplan') . ': ' . $plan) . '" title="' . e($plan) . '">' . icon('clock') . '</span>' : '');
};
$statusBtn = function (array $e) use ($t, $canPub, $extOrigin): string {
    $st = $e['status'] === 'published' ? 'online' : (!empty($e['published_at']) ? 'offline' : 'draft');
    $label = ['online' => __('Online'), 'offline' => __('Offline'), 'draft' => __('Entwurf')][$st];
    $cls = 'dt-status dt-status--' . ['online' => 'published', 'offline' => 'offline', 'draft' => 'draft'][$st];
    // Nur eigene Einträge: nicht aus externen Quellen (nur lesbar), bei geteilten Tabellen nicht von anderen Websites
    if (!$canPub || isset($extOrigin[$e['id']]) || (Tables::isShared($t) && \Core\Data\Shared::isForeign($t, $e))) {
        return '<span class="' . $cls . '">' . e($label) . '</span>';
    }
    $title = Entries::title($t, $e);
    $act = $st === 'online' ? __('Offline nehmen') : ($st === 'offline' ? __('Online stellen') : __('Veröffentlichen'));
    return '<button type="button" class="pt-stbtn ' . $cls . '" data-status-toggle data-state="' . $st . '" data-title="' . e($title) . '"'
        . ' aria-label="' . e(__('„{title}“: {status} – {action}', ['title' => $title, 'status' => $label, 'action' => $act])) . '" title="' . e($act) . '">' . e($label) . '</button>';
};
?>
<?php // Ansicht (Liste/Kalender), Felder und „Detailseite gestalten“ stehen in der Daten-Navigation (data/_nav.php)
$extTbl = \Core\Extensions::tableActions($t);   // Erweiterungen: Knöpfe im Kopf, Aktionen je Zeile (Core\Slots) ?>
<header class="adm-head dt-head">
  <h1><span aria-hidden="true" class="dt-h1icon"><?= icon($t['icon']) ?></span> <?= e($t['name']) ?><?php if ($shared = \Core\Data\Tables::isShared($t)): ?> <span class="dt-nav__shared"><?= e(__('geteilt')) ?></span><?php endif; ?></h1>
  <div class="adm-row">
    <?php if (\Core\Data\AddressBook::enabled($t)): // Kontakte: Umschalter „Adressbuch | Tabelle“ (je Konto gemerkt, Core\Data\AddressBook) ?>
    <nav class="fx-seg dt-seg ab-views" aria-label="<?= e(__('Ansicht')) ?>">
      <a href="<?= e(url($base) . '?view=book') ?>"><?= icon('address-book') ?> <?= e(__('Adressbuch')) ?></a>
      <a href="<?= e(url($base) . '?view=list') ?>" aria-current="true"><?= icon('list-bullets') ?> <?= e(__('Tabelle')) ?></a>
    </nav>
    <?php endif; ?>
    <?php if ($tblPages = \Core\AdminPages::forTable($t['handle'])): // Einstellungsseiten dieser Tabelle (Core\AdminPages, 'table' => …), z. B. Glossar ?>
    <span class="dt-head__pages"><?php foreach ($tblPages as $tp): ?><a class="adm-btn adm-btn--ghost" href="<?= e(url($tp['href'])) ?>"><?= \Core\Icons::render($tp['icon'], ['fallback' => 'gear-six']) ?> <?= e($tp['label']) ?></a><?php endforeach; ?></span>
    <?php endif; ?>
    <?php if ($extTbl['actions']): // Erweiterungen (Extension::tableActions, Core\Slots): Knöpfe im Kopf ?>
    <span class="dt-head__pages"><?php foreach ($extTbl['actions'] as $xa): ?><a class="adm-btn adm-btn--ghost" href="<?= e(url($xa['href'])) ?>" data-slot="<?= e($xa['extension']) ?>"><?= $xa['icon'] !== '' ? \Core\Icons::render($xa['icon']) . ' ' : '' ?><?= e($xa['label']) ?></a><?php endforeach; ?></span>
    <?php endif; ?>
    <?php if (\Core\Data\Calendar::enabled($t)): // Kalender als ICS-Datei (Serien mit RRULE/EXDATE/RECURRENCE-ID) ?><a class="adm-btn adm-btn--ghost" href="<?= e(url($base . '/kalender.ics')) ?>" download><?= icon('download-simple') ?> <?= e(__('ICS exportieren')) ?></a><?php endif; ?>
    <a class="adm-btn adm-btn--primary" href="<?= e(url($base . '/new') . ($multi ? '?lang=' . $lang : '')) ?>">+ <?= e($t['singular']) ?></a>
  </div>
</header>

<div class="dt-list" data-entries="<?= e(url($base . '/bulk')) ?>"<?= \Core\RowMenu::selectAttrs() ?><?= ($pl = \Core\Http\Controllers\Admin\PresenceController::listAttrs('entry', $t['handle'] . ':')) ? ' data-presence-list' . $pl : '' ?>>
  <div class="dt-bar">
    <?php if ($multi): ?><nav class="fx-seg dt-seg" aria-label="Sprache"><?php foreach (\Core\Lang::all() as $code => $label): ?><a href="<?= e(url($base) . '?lang=' . $code) ?>"<?= $code === $lang ? ' aria-current="true"' : '' ?>><?= e($label) ?></a><?php endforeach; ?></nav><?php endif; ?>
    <nav class="fx-seg dt-seg" aria-label="Status">
      <?php foreach (['all' => 'Alle', 'published' => 'Online', 'draft' => 'Entwurf'] as $k => $l): if ($k !== 'all' && !$t['settings']['workflow']) continue; ?>
      <a href="<?= e($qs(['status' => $k === 'all' ? '' : $k, 'seite' => null])) ?>"<?= $status === $k ? ' aria-current="true"' : '' ?>><?= $l ?> <small data-count="<?= $k ?>"><?= $counts[$k] ?></small></a>
      <?php endforeach; ?>
    </nav>
    <form class="fx-search dt-search" method="get" action="<?= e(url($base)) ?>" role="search">
      <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M7 2a5 5 0 1 0 3 9l3.3 3.3 1-1L11 10A5 5 0 0 0 7 2zm0 1.5a3.5 3.5 0 1 1 0 7 3.5 3.5 0 0 1 0-7z"/></svg>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Suchen" aria-label="Einträge durchsuchen">
      <?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    </form>
    <?php // Mehrfachauswahl (Core\RowMenu::bar): dieselben Aktionen und Symbole wie im Zeilenmenü, für alle gewählten
    $bulkItems = [];
    if ($t['settings']['workflow'] && can('data.publish', $t['handle'])) {
        $bulkItems[] = ['key' => 'publish', 'label' => __('Online stellen'), 'attrs' => ['data-bulk-action' => 'publish']];
        $bulkItems[] = ['key' => 'publish', 'icon' => 'eye-slash', 'label' => __('Offline nehmen'), 'attrs' => ['data-bulk-action' => 'draft']];
    }
    if (\Core\Data\AddressBook::enabled($t) && can('data.edit', $t['handle'])) $bulkItems[] = ['key' => 'export', 'label' => __('vCard exportieren'), 'attrs' => ['data-bulk-export' => url($base . '/vcard')]];
    elseif (\Core\Data\Calendar::enabled($t) && can('data.edit', $t['handle'])) $bulkItems[] = ['key' => 'export', 'label' => __('ICS exportieren'), 'attrs' => ['data-bulk-export' => url($base . '/kalender.ics')]];
    if (can('data.delete', $t['handle'])) $bulkItems[] = ['key' => 'delete', 'label' => __('Löschen'), 'attrs' => ['data-bulk-action' => 'delete']];
    echo \Core\RowMenu::bar($bulkItems, ['class' => 'dt-bulk']); ?>
  </div>

  <?php if (!empty($cal)): ?>
  <?= \Core\Theme::capture(__DIR__ . '/_calendar.php', ['t' => $t, 'cal' => $cal, 'base' => $base, 'lang' => $lang, 'multi' => $multi, 'status' => $status, 'q' => $q]) ?>
  <?php elseif (!$rows): ?>
  <div class="dt-empty">
    <span class="dt-icon" aria-hidden="true"><?= icon($t['icon']) ?></span>
    <p><?= $q !== '' ? 'Keine Treffer für „' . e($q) . '“.' : 'Noch keine Einträge.' ?></p>
    <a class="adm-btn adm-btn--primary" href="<?= e(url($base . '/new')) ?>">+ <?= e($t['singular']) ?> anlegen</a>
  </div>
  <?php else: ?>
  <table class="dt-table<?= $manual ? ' is-sortable' : '' ?>">
    <thead><tr>
      <th class="dt-c-check"><input type="checkbox" data-checkall data-rm-checkall aria-label="<?= e(__('Alle auswählen')) ?>"></th>
      <?php if ($manual): ?><th class="dt-c-grip"><span class="sr-only">Reihenfolge</span></th><?php endif; ?>
      <th scope="col"><?= $sortLink($t['settings']['title_field'] ?: 'id', Tables::field($t, $t['settings']['title_field'])['label'] ?? 'Titel') ?></th>
      <?php if ($perEntry): ?><th scope="col" data-prio="1"><?= $sortLink(\Core\Data\Kinds::PUBLIC_FIELD, __('Website')) ?></th><?php endif; ?>
      <?php foreach ($cols as $ci => $c): ?><th scope="col" data-prio="<?= $prio($ci) ?>"><?= $sortLink($c['name'], $c['label']) ?></th><?php endforeach; ?>
      <?php if ($t['settings']['workflow']): ?><th scope="col"><?= $sortLink('status', 'Status') ?></th><?php endif; ?>
      <th scope="col" data-prio="5"><?= $sortLink('updated_at', 'Geändert') ?></th>
    </tr></thead>
    <tbody data-rows>
    <?php foreach ($rows as $e): $url = Entries::url($t, $e); ?>
      <tr data-id="<?= (int) $e['id'] ?>"<?= \Core\RowMenu::host('dt-menu-' . (int) $e['id']) ?><?= $manual ? ' draggable="true"' : '' ?>>
        <td class="dt-c-check"><input type="checkbox" data-check data-rm-check value="<?= (int) $e['id'] ?>" aria-label="<?= e(Entries::title($t, $e)) ?> auswählen"></td>
        <?php if ($manual): ?><td class="dt-c-grip" aria-hidden="true">⋮⋮</td><?php endif; ?>
        <td class="dt-c-title"><div class="dt-c-title__in" data-presence-slot>
          <?php if ($img && !empty($e[$img]) && ($m = \Core\Media::find((int) $e[$img]))): ?><img src="<?= e(\Core\Media::url($m, 480)) ?>" alt="" class="dt-thumb<?= $listImg === 'large' ? ' dt-thumb--l' : '' ?>" loading="lazy"><?php elseif ($listImg !== 'none'): ?><span class="dt-thumb dt-thumb--ph<?= $listImg === 'large' ? ' dt-thumb--l' : '' ?>" aria-hidden="true"><?= icon($t['icon']) ?></span><?php endif; ?>
          <a href="<?= e(url($base . '/' . $e['id'])) ?>"><?= e(Entries::title($t, $e)) ?></a>
          <?php if (!empty($shared) && !empty($e['suggest'])): $sp = $ownerPicks[$e['id']] ?? null; ?><small class="sh-pick sh-pick--<?= e($sp ?? 'suggested') ?>"><?= e(match ($sp) { 'visible' => __('übernommen'), 'featured' => __('hervorgehoben'), 'rejected' => __('abgelehnt'), 'hidden' => __('ausgeblendet'), default => __('vorgeschlagen') }) ?></small><?php endif; ?>
          <?php if (isset($extOrigin[$e['id']])): ?><small class="src-badge" title="<?= e(__('Aus der externen Quelle „{name}“ – nur lesbar', ['name' => $extOrigin[$e['id']]['name']])) ?>"><?= icon('plugs-connected') ?> <?= e(__('aus Quelle')) ?></small><?php endif; ?>
          <?php if ($url): ?><a class="dt-view" href="<?= e($url) ?>" target="_blank" rel="noopener" aria-label="Auf der Website ansehen">↗</a><?php endif; ?>
          <?= $rowMenu($e) ?>
        </div></td>
        <?php if ($perEntry): ?><td class="dt-c-vis" data-prio="1" data-label="<?= e(__('Website')) ?>"><?= $visTag($e) ?></td><?php endif; ?>
        <?php foreach ($cols as $ci => $c): ?><td data-prio="<?= $prio($ci) ?>" data-label="<?= e($c['label']) ?>"><?= \Core\Combo::isMulti($c['type']) ? $multiCell($c, $e) : ($c['type'] === 'group' ? e(Entries::groupSummary($c, $e[$c['name']] ?? [])) : (in_array($c['type'], ['media', 'file'], true) ?$thumb(isset($e[$c['name']]) && $e[$c['name']] !== '' ? (int) $e[$c['name']] : null) : $clamp(strip_tags(Entries::html($t, $e, $c['name'], ['link' => false]), '<br>')))) ?></td><?php endforeach; ?>
        <?php if ($t['settings']['workflow']): ?><td class="dt-c-status" data-label="Status"><?= $statusCell($e) ?></td><?php endif; ?>
        <td class="adm-muted dt-c-date" data-prio="5" data-label="Geändert"><?= e(\Core\Format::admin()->date(strtotime((string) ($e['updated_at'] ?: $e['created_at'])))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <footer class="dt-foot">
    <span><?= $total ?> <?= $total === 1 ? 'Eintrag' : 'Einträge' ?><?= $manual ? ' · Reihenfolge per Ziehen ändern' : '' ?></span>
    <span data-entries-msg aria-live="polite"></span>
    <?= \Core\AdminUi::pager($page, $pages, fn(int $p) => $qs(['seite' => $p])) ?>
  </footer>
  <?php endif; ?>
</div>
