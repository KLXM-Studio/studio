<?php
/**
 * KI-Vorschläge für Schlagworte und Kategorien im Bestand (Core\AI\Classify): bis zu 25 Einträge (standardmäßig solche mit mindestens einem leeren Feld),
 * „Vorschläge holen“ fragt die KI je Eintrag (resources/js/_ai_ui.js), angehakte Vorschläge übernimmt das Formular gesammelt.
 * @var array $t  @var list<array> $fields  @var list<array> $rows  @var bool $all
 */
use Core\Data\Entries;
use Core\Data\Tables;

$base = '/admin/data/' . $t['handle'];
$show = function (array $e) use ($t, $fields): string {
    $out = [];
    foreach ($fields as $f) {
        $v = $e[$f['name']] ?? null;
        $vals = is_array($v) ? $v : ((string) $v !== '' ? [(string) $v] : []);
        if ($vals) $out[] = $f['label'] . ': ' . implode(', ', array_map(fn($k) => Tables::optionLabel(Tables::field($t, $f['name']) ?? [], (string) $k), $vals));
    }
    return implode(' · ', $out);
};
?>
<header class="adm-head dt-head">
  <div><p class="adm-eyebrow"><a href="<?= e(url($base)) ?>"><span aria-hidden="true"><?= icon($t['icon']) ?></span> <?= e($t['name']) ?></a></p>
    <h1><?= e(__('Schlagworte & Kategorien vorschlagen')) ?></h1>
    <p class="adm-muted"><?= e(__('Die KI schlägt für jeden Eintrag passende Werte vor – vorhandene bevorzugt, neue Schlagworte sind markiert. Nichts wird automatisch gesetzt: anhaken, was passt, dann übernehmen.')) ?></p></div>
</header>
<form method="post" action="<?= e(url($base . '/ki-vorschlaege')) ?>" class="adm-card kia-cls-review" data-kia-cls-review data-table="<?= e($t['handle']) ?>">
  <?= csrf_field() ?>
  <?php if ($all): ?><input type="hidden" name="alle" value="1"><?php endif; ?>
  <p class="adm-row">
    <button type="button" class="adm-btn adm-btn--primary kia-btn" data-kia-cls-go><span class="kia-spark" aria-hidden="true">✦</span> <?= e(__('Vorschläge holen')) ?></button>
    <span class="adm-muted" data-kia-cls-status aria-live="polite"><?= e(count($rows) === 1 ? __('1 Eintrag') : __('{n} Einträge', ['n' => count($rows)])) ?> · <?= e(__('Felder: {fields}', ['fields' => implode(', ', array_column($fields, 'label'))])) ?></span>
    <a class="adm-btn adm-btn--small adm-btn--ghost" href="<?= e(url($base . '/ki-vorschlaege') . ($all ? '' : '?alle=1')) ?>"><?= e($all ? __('Nur Einträge mit leeren Feldern') : __('Alle Einträge')) ?></a>
  </p>
  <?php if (!$rows): ?><p class="adm-muted"><?= e(__('Bei allen Einträgen sind Schlagworte und Kategorien schon ausgefüllt.')) ?></p><?php else: ?>
  <table class="adm-table kia-cls-table">
    <thead><tr><th scope="col"><?= e(__('Eintrag')) ?></th><th scope="col"><?= e(__('Vorschläge')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $e): ?>
      <tr data-kia-cls-row data-id="<?= (int) $e['id'] ?>">
        <td><a href="<?= e(url($base . '/' . (int) $e['id'])) ?>"><b><?= e(Entries::title($t, $e)) ?></b></a><?php if (($cur = $show($e)) !== ''): ?><br><span class="adm-muted kia-small"><?= e($cur) ?></span><?php endif; ?></td>
        <td data-kia-cls-out><span class="adm-muted">–</span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="adm-row"><button type="submit" class="adm-btn adm-btn--primary" data-kia-cls-take disabled><?= e(__('Ausgewählte übernehmen')) ?></button></p>
  <?php endif; ?>
</form>
