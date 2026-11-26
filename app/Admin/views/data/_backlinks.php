<?php
/**
 * Rückverknüpfungen im Formular eines Eintrags (Core\Data\Backlinks): je Verknüpfungsfeld einer anderen Tabelle, das auf diese
 * Tabelle zeigt, die Einträge der Gegenrichtung – Kalender nach Beginn (kommende zuerst, vergangene eingeklappt), sonst nach
 * Sortierung der Tabelle; höchstens Backlinks::VISIBLE sichtbar, Rest aufklappbar. Mit Recht data.edit auf der anderen Tabelle:
 * „+ … verknüpfen“ (Auswahlfenster), „+ … neu“ (Formular im Dialog, Feld vorbelegt), „×“ löst (mit Rückfrage) –
 * resources/js/_backlinks.js. Steht im Formular, bedient aber nur eigene Endpunkte (keine Felder des Formulars).
 * @var array $t  @var array $e
 */
use Core\Data\Backlinks;
use Core\Data\Entries;

$groups = Backlinks::groups($t, (int) $e['id']);
if (!$groups) return;
$row = function (array $g, array $be): string {
    $s = $g['table'];
    $title = Entries::title($s, $be);
    $canOpen = can('data.edit', $s['handle']);
    $h = '<li class="dt-bl__row" data-bl-row="' . (int) $be['id'] . '">'
        . ($g['calendar'] && ($be['_date'] ?? '') !== '' ? '<span class="dt-bl__date">' . e((string) $be['_date']) . '</span>' : '')
        . '<span class="dt-bl__title">' . ($canOpen ? '<a href="' . e(url('/admin/data/' . $s['handle'] . '/' . (int) $be['id'])) . '">' . e($title) . '</a>' : e($title)) . '</span>'
        . (($be['status'] ?? '') === 'draft' ? '<span class="dt-status dt-status--draft">' . e(__('Offline')) . '</span>' : '');
    if ($g['editable'] && Backlinks::rowEditable($s, $be)) {
        $h .= '<button type="button" class="cms-iconbtn dt-bl__x" data-bl-unlink aria-label="' . e(__('Verknüpfung lösen: {title}', ['title' => $title])) . '" title="' . e(__('Verknüpfung lösen')) . '">' . icon('x') . '</button>';
    }
    return $h . '</li>';
};
$list = function (array $g, array $rows) use ($row): string {
    if (!$rows) return '';
    $h = '<ul class="dt-bl__list" role="list">';
    foreach (array_slice($rows, 0, Backlinks::VISIBLE) as $be) $h .= $row($g, $be);
    $h .= '</ul>';
    if (count($rows) > Backlinks::VISIBLE) {
        $h .= '<details class="dt-bl__more"><summary>' . e(__('Alle {n} anzeigen', ['n' => count($rows)])) . '</summary><ul class="dt-bl__list" role="list">';
        foreach (array_slice($rows, Backlinks::VISIBLE) as $be) $h .= $row($g, $be);
        $h .= '</ul></details>';
    }
    return $h;
};
?>
<section class="adm-card dt-bl" id="rueckverknuepfungen" aria-labelledby="dt-bl-h" data-backlinks data-target="<?= (int) $e['id'] ?>"
  data-api="<?= e(url('/admin/api/backlinks/')) ?>" data-picker="<?= e(url('/admin/api/picker/')) ?>" data-new="<?= e(url('/admin/data/')) ?>">
  <h2 id="dt-bl-h"><?= icon('link') ?> <?= e(__('Verknüpft mit diesem Eintrag')) ?></h2>
  <?php foreach ($groups as $g): $s = $g['table']; ?>
  <div class="dt-bl__g" data-bl-group data-table="<?= e($s['handle']) ?>" data-field="<?= e($g['field']['name']) ?>" data-tname="<?= e($s['name']) ?>" data-singular="<?= e($s['singular'] ?? $s['name']) ?>"<?= $g['field']['type'] === 'relations' ? ' data-multi' : '' ?>>
    <div class="dt-bl__head">
      <h3 class="dt-bl__h"><span aria-hidden="true"><?= icon($s['icon']) ?></span> <?= e($g['label']) ?></h3>
      <span class="dt-bl__sum"><?= e(Backlinks::summary($g)) ?></span>
      <?php if ($g['editable']): ?>
      <span class="dt-bl__acts">
        <button type="button" class="adm-btn adm-btn--small" data-bl-link aria-haspopup="dialog">+ <?= e(__('{singular} verknüpfen …', ['singular' => $s['singular'] ?? $s['name']])) ?></button>
        <a class="adm-btn adm-btn--small adm-btn--ghost" data-bl-new href="<?= e(url('/admin/data/' . $s['handle'] . '/new') . '?' . http_build_query(['mit' => [$g['field']['name'] => (int) $e['id']]])) ?>">+ <?= e(__('{singular} neu', ['singular' => $s['singular'] ?? $s['name']])) ?></a>
      </span>
      <?php endif; ?>
    </div>
    <?php if (!$g['total']): ?>
    <p class="dt-bl__empty f-help"><?= e(__('Noch nichts verknüpft.')) ?></p>
    <?php else: ?>
    <?= $list($g, $g['upcoming']) ?>
    <?php if ($g['calendar'] && !$g['upcoming']): ?><p class="dt-bl__empty f-help"><?= e(__('Keine kommenden Termine.')) ?></p><?php endif; ?>
    <?php if ($g['past']): ?>
    <details class="dt-bl__past"><summary><?= e(count($g['past']) === 1 ? __('1 vergangener') : __('{n} vergangene', ['n' => count($g['past'])])) ?></summary><?= $list($g, $g['past']) ?></details>
    <?php endif; ?>
    <?php if ($g['total'] > Backlinks::VISIBLE && can('data.edit', $s['handle'])): ?><p class="dt-bl__all"><a href="<?= e($g['list_url']) ?>"><?= e(__('In der Liste „{name}“ öffnen', ['name' => $s['name']])) ?></a></p><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
