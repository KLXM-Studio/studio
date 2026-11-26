<?php
/**
 * Unterschiede (Format Core\Review\Diff) – feldweise nebeneinander, bei Seiten blockweise. Gemeinsam für die Prüf-Ebene
 * „Eingereicht“ (review/show.php) und die Entwürfe (drafts/show.php).
 * @var array $diff  @var string $newLabel  Beschriftung des neuen Stands  @var ?string $oldLabel  @var ?string $removedLabel
 */
use Core\Review\Diff;

$oldLabel ??= __('Vorher');
$removedLabel ??= __('Vorher (wird entfernt)');
$blockOps = ['added' => __('neu'), 'removed' => __('entfernt'), 'changed' => __('geändert'), 'moved' => __('verschoben')];

/** Formatierter Text (Rich-Text): so dargestellt, wie er auf der Website erscheint – Unterschiede wortweise aufklappbar */
$isHtml = fn(mixed $v): bool => is_string($v) && preg_match('~<(p|ul|ol|h[2-6]|strong|em|b|i|a|blockquote|br)\b~i', $v) === 1;
$pair = function (mixed $before, mixed $after) use ($newLabel, $oldLabel, $removedLabel, $isHtml, &$pair): string {
    if ($isHtml($before) || $isHtml($after)) {
        $col = fn(mixed $v, string $label, string $cls) => '<div class="rv-diff__col rv-diff__col--' . $cls . '"><span class="rv-diff__lbl">' . e($label) . '</span>'
            . (trim(strip_tags((string) $v)) !== '' ? '<div class="rv-diff__val rv-diff__val--rich prose">' . rich((string) $v) . '</div>' : '<div class="rv-diff__val rv-diff__val--empty">' . e(__('(leer)')) . '</div>') . '</div>';
        [$a, $b] = Diff::words(Diff::text($before), Diff::text($after));
        $cols = trim(strip_tags((string) $before)) === '' ? $col($after, $newLabel, 'new') : $col($before, $oldLabel, 'old') . $col($after, $newLabel, 'new');
        return '<div class="rv-diff__pair' . (trim(strip_tags((string) $before)) === '' ? ' rv-diff__pair--one' : '') . '">' . $cols . '</div>'
            . ($a !== '' && $b !== '' ? '<details class="rv-diff__words"><summary>' . e(__('Unterschiede wortweise')) . '</summary><div class="rv-diff__pair"><div class="rv-diff__col rv-diff__col--old"><div class="rv-diff__val">' . $a . '</div></div><div class="rv-diff__col rv-diff__col--new"><div class="rv-diff__val">' . $b . '</div></div></div></details>' : '');
    }
    [$a, $b] = Diff::words(Diff::text($before), Diff::text($after));
    $cell = fn(string $h, string $label, string $cls) => '<div class="rv-diff__col rv-diff__col--' . $cls . '"><span class="rv-diff__lbl">' . e($label) . '</span>'
        . ($h !== '' ? '<div class="rv-diff__val">' . $h . '</div>' : '<div class="rv-diff__val rv-diff__val--empty">' . e(__('(leer)')) . '</div>') . '</div>';
    // Nur eine Seite vorhanden (neu bzw. entfernt): eine Spalte statt „(leer)“ daneben
    if ($a === '' && $b !== '') return '<div class="rv-diff__pair rv-diff__pair--one">' . $cell($b, $newLabel, 'new') . '</div>';
    if ($b === '' && $a !== '') return '<div class="rv-diff__pair rv-diff__pair--one">' . $cell($a, $removedLabel, 'old') . '</div>';
    return '<div class="rv-diff__pair">' . $cell($a, $oldLabel, 'old') . $cell($b, $newLabel, 'new') . '</div>';
};
?>
<?php
// Verknüpfungen (Relation, Relationen): Titel statt IDs; Auswahlfelder: Beschriftung statt Schlüssel
$table ??= null;
$readable = function (string $path, mixed $v) use ($table): mixed {
    $f = $table ? \Core\Data\Tables::field($table, $path) : null;
    if (!$f || $v === null || $v === '' || $v === []) return $v;
    $vals = is_array($v) ? $v : (is_string($v) && str_starts_with(trim($v), '[') ? (json_decode($v, true) ?? [$v]) : [$v]);
    if (in_array($f['type'] ?? '', ['relation', 'relations'], true) && ($tt = \Core\Data\Tables::findContent((string) ($f['target'] ?? '')))) {
        return implode(', ', array_map(fn($id) => ($e = \Core\Data\Entries::find($tt, (int) $id)) ? \Core\Data\Entries::title($tt, $e) : '#' . $id, $vals));
    }
    if (in_array($f['type'] ?? '', ['select', 'multiselect'], true)) return implode(', ', array_map(fn($k) => \Core\Data\Tables::optionLabel($f, (string) $k), $vals));
    return $v;
};
?>
<?php foreach ($diff as $d): ?>
  <?php if ($d['type'] === 'blocks'): ?>
  <div class="rv-diff rv-diff--blocks">
    <h3 class="rv-diff__field"><?= e($d['label']) ?> <small class="adm-muted"><?= e(count($d['items']) === 1 ? __('1 Block') : __('{n} Blöcke', ['n' => count($d['items'])])) ?></small></h3>
    <ol class="rv-blocks">
    <?php foreach ($d['items'] as $it): ?>
      <li class="rv-block rv-block--<?= e($it['op']) ?>">
        <p class="rv-block__head"><span class="rv-op rv-op--<?= e($it['op']) ?>"><?= e($blockOps[$it['op']] ?? $it['op']) ?></span>
          <strong><?= e($it['label']) ?></strong>
          <span class="adm-muted"><?= e(match ($it['op']) {
              'added' => __('an Position {n}', ['n' => ($it['to'] ?? 0) + 1]),
              'removed' => __('bisher Position {n}', ['n' => ($it['from'] ?? 0) + 1]),
              default => !empty($it['moved']) ? __('Position {a} → {b}', ['a' => ($it['from'] ?? 0) + 1, 'b' => ($it['to'] ?? 0) + 1]) : __('Position {n}', ['n' => ($it['to'] ?? 0) + 1]),
          }) ?></span></p>
        <?php if ($it['op'] !== 'moved' && $it['fields']): ?>
        <dl class="rv-block__fields">
          <?php foreach ($it['fields'] as $fd): ?>
          <dt><?= e($fd['label']) ?></dt><dd><?= $pair($fd['before'], $fd['after']) ?></dd>
          <?php endforeach; ?>
        </dl>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ol>
  </div>
  <?php else: ?>
  <div class="rv-diff">
    <h3 class="rv-diff__field"><?= e($d['label']) ?></h3>
    <?= $pair($readable((string) $d['path'], $d['before']), $readable((string) $d['path'], $d['after'])) ?>
  </div>
  <?php endif; ?>
<?php endforeach; ?>
