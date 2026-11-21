<?php
/**
 * Karte des gewählten Kontakts (rechte Spalte): Foto/Initialen, Name, Funktion/Organisation, Schnellknöpfe, gruppierte Angaben
 * mit Beschriftung und Merkmal Öffentlich/Intern (Exposure::reach), Listen als Etiketten (entfernen/hinzufügen), vCard,
 * Versionen, Bearbeiten.
 * @var array $t  @var array $s  @var ?array $e  @var array $lists  @var string $list  @var string $filter
 */
use Core\Data\AddressBook;
use Core\Data\Exposure;

$base = url('/admin/data/' . $t['handle']);
$scopeLabel = $list !== '' ? ($lists[$list] ?? '') : ($filter !== '' ? __('Kontakte') : __('Alle Kontakte'));
if (!$e): ?>
<div class="ab-card__none">
  <?= icon('address-book') ?>
  <p><?= e(__('Wählen Sie links einen Kontakt.')) ?></p>
</div>
<?php return; endif;
$id = (int) $e['id'];
[$pre, $bold] = AddressBook::nameParts($e);
$name = trim($pre . ' ' . $bold);
$online = AddressBook::online($t, $e);
$m = !empty($e['foto']) ? \Core\Media::find((int) $e['foto']) : null;
$q = AddressBook::quick($t, $e);
$tg = Exposure::targets($t);
$back = AddressBook::link($t, $s);
$mine = array_values(array_filter(AddressBook::listKeys($e), fn($k) => isset($lists[$k])));
$org = array_values(array_filter([AddressBook::isOrg($e) ? '' : trim((string) ($e['firma'] ?? '')), trim((string) ($e['abteilung'] ?? ''))]));
$badge = function (array $r) use ($tg): string {
    $on = $r['targets'] !== [];
    $txt = Exposure::reachText($r, $tg);
    return '<span class="ab-reach' . ($on ? ' is-public' : '') . '" title="' . e($on ? __('Erscheint öffentlich: {where}', ['where' => $txt]) : __('Nur intern – nicht auf der Website')) . '">'
        . icon($on ? 'globe' : 'lock') . '<span>' . e($txt) . '</span></span>';
};
?>
<div class="ab-card__bar">
  <a class="ab-back" href="<?= e(AddressBook::link($t, $s, ['k' => ''])) ?>" data-ab-pane="names"><?= icon('arrow-left') ?> <span><?= e($scopeLabel) ?></span></a>
  <span class="ab-chip<?= $online ? ' is-public' : '' ?>"><?= icon($online ? 'globe' : 'lock') ?> <?= e($online ? __('Auf der Website') : ($e['status'] !== 'published' ? __('Entwurf') : __('Nur intern'))) ?></span>
  <a class="adm-btn adm-btn--small adm-btn--primary" href="<?= e($base . '/' . $id) ?>"><?= icon('pencil-simple') ?> <?= e(__('Bearbeiten')) ?></a>
  <?php // Weitere Aktionen: Zeilenmenü wie in allen Listen (Core\RowMenu, AddressBook::menu) – ⋯, Rechtsklick auf die Karte, Umschalt+F10 ?>
  <?= \Core\RowMenu::render('ab-menu-' . $id, $name, AddressBook::menu($t, $s, $e), ['class' => 'ab-rowmenu']) ?>
</div>
<div class="ab-card__body"<?= \Core\RowMenu::host('ab-menu-' . $id) ?>>
  <div class="ab-hero">
    <?php if ($m && str_starts_with((string) $m['mime'], 'image/')): ?><img class="ab-ava ab-ava--l" src="<?= e(\Core\Media::url($m, 480)) ?>" alt="<?= e(__('Foto von {name}', ['name' => $name])) ?>"><?php elseif (AddressBook::isOrg($e)): ?><span class="ab-ava ab-ava--l ab-ava--org" aria-hidden="true"><?= icon('buildings') ?></span><?php else: ?><span class="ab-ava ab-ava--l adm-ava adm-ava--ini adm-ava--c<?= AddressBook::color($e) ?>" aria-hidden="true"><?= e(AddressBook::initials($e)) ?></span><?php endif; ?>
    <div>
      <h2 id="ab-card-name" tabindex="-1"><?= e($name) ?></h2>
      <?php if (trim((string) ($e['position'] ?? '')) !== ''): ?><p><?= e((string) $e['position']) ?></p><?php endif; ?>
      <?php if ($org): ?><p class="ab-hero__org"><?= e(implode(' · ', $org)) ?></p><?php endif; ?>
    </div>
  </div>
  <div class="ab-quick">
    <?php foreach ([['call', 'phone', __('Anrufen')], ['mail', 'envelope-simple', __('E-Mail')], ['map', 'map-pin', __('Karte')]] as [$k, $ic, $label]): ?>
    <?php if ($q[$k]): ?><a href="<?= e($q[$k]) ?>"<?= $k === 'map' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= icon($ic) ?><span><?= e($label) ?></span><?= $k === 'map' ? '<span class="sr-only"> ' . e(__('(OpenStreetMap, neuer Tab)')) . '</span>' : '' ?></a>
    <?php else: ?><span aria-disabled="true"><?= icon($ic) ?><span><?= e($label) ?></span></span><?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php foreach (AddressBook::groups($t, $e) as $g): ?>
  <section class="ab-grp">
    <h3><?= e($g['label']) ?></h3>
    <dl>
      <?php foreach ($g['rows'] as $row): ?>
      <div class="ab-row"><dt><?= e($row['label']) ?></dt><dd class="ab-row__v"><?= $row['html'] ?></dd><dd class="ab-row__r"><?= $badge($row['reach']) ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </section>
  <?php endforeach; ?>
  <?php if (\Core\Data\Tables::field($t, 'listen')): ?>
  <section class="ab-grp">
    <h3 id="ab-card-lists"><?= e(__('Listen')) ?></h3>
    <div class="ab-tags">
      <?php foreach ($mine as $k): ?>
      <form method="post" action="<?= e($base . '/listen') ?>" class="ab-tag" data-ab-tagform>
        <?= csrf_field() ?><input type="hidden" name="ids[]" value="<?= $id ?>"><input type="hidden" name="liste" value="<?= e($k) ?>"><input type="hidden" name="op" value="remove"><input type="hidden" name="zurueck" value="<?= e($back) ?>">
        <span><?= e($lists[$k]) ?></span><button type="submit" aria-label="<?= e(__('Aus Liste „{list}“ entfernen', ['list' => $lists[$k]])) ?>" title="<?= e(__('Aus Liste entfernen')) ?>"><?= icon('x') ?></button>
      </form>
      <?php endforeach; ?>
      <?php $free = array_diff_key($lists, array_flip($mine)); if ($free): ?>
      <form method="post" action="<?= e($base . '/listen') ?>" class="ab-addlist" data-ab-tagform>
        <?= csrf_field() ?><input type="hidden" name="ids[]" value="<?= $id ?>"><input type="hidden" name="op" value="add"><input type="hidden" name="zurueck" value="<?= e($back) ?>">
        <label class="sr-only" for="ab-addlist-<?= $id ?>"><?= e(__('Zu Liste hinzufügen')) ?></label>
        <select id="ab-addlist-<?= $id ?>" name="liste"><option value=""><?= e(__('Zu Liste hinzufügen …')) ?></option><?php foreach ($free as $k => $label): ?><option value="<?= e((string) $k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
        <button type="submit" class="adm-btn adm-btn--small"><?= e(__('Hinzufügen')) ?></button>
      </form>
      <?php endif; ?>
      <?php if (!$mine && !$free): ?><p class="ab-muted"><?= e(__('Noch keine Listen angelegt.')) ?></p><?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
  <p class="ab-foot"><?= e(__('Geändert am {date}', ['date' => \Core\Format::admin()->date(strtotime((string) ($e['updated_at'] ?: $e['created_at'])))])) ?>
    · <button type="button" class="ab-link"<?= \Core\Http\Controllers\Admin\VersionsController::attrs('/admin/api/data/' . $t['handle'] . '/' . $id . '/versions') ?>><?= e(__('Versionen')) ?></button></p>
</div>
