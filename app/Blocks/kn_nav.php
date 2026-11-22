<?php
/**
 * Kapitelnavigation (Kern-Block, nur Detailseiten-Vorlagen von Wissensbereichen). Auf der Website rendert ihn nie dieser Renderer:
 * Core\Data\Knowledge::render teilt die Vorlage an diesem Block – Blöcke davor über die volle Breite, danach im Rahmen mit
 * Seitenleiste bzw. Menü nach den Optionen des Blocks. Hier nur die Vorschau im Editor (Platzhalter mit den gewählten Optionen).
 * @var \Core\Block $b  @var array $d
 */
if (!is_editing()) return;
$wrap = app()->theme->def['container_class'] ?? 'wrap';
$ctx = app()->entry;
$isKn = $ctx && \Core\Data\Knowledge::is($ctx['table']);
$pos = ['left' => __('Seitenleiste links'), 'right' => __('Seitenleiste rechts'), 'top' => __('Oben als aufklappbares Menü')][(string) ($d['position'] ?? 'left')] ?? __('Seitenleiste links');
$parts = [];
foreach (['tree' => __('Kapitelbaum'), 'sections' => __('Abschnitte'), 'search' => __('Suche'), 'crumbs' => __('Brotkrumen'), 'footer' => __('Fuß')] as $k => $l) {
    if (!array_key_exists($k, $d) || !empty($d[$k])) $parts[] = $l;
}
?>
<div class="<?= e($wrap) ?>">
  <p class="dl-empty"><?= icon('list') ?> <b><?= e(__('Kapitelnavigation')) ?>:</b> <?= e($pos) ?><?= !empty($d['compact']) ? ' · ' . e(__('kompakt')) : '' ?> – <?= e($parts ? implode(', ', $parts) : __('nur der Text')) ?>.
    <?= e($isKn ? __('Blöcke darüber stehen über die volle Breite, Blöcke darunter neben bzw. unter der Navigation (Ansicht: „Ansehen“).') : __('Wirkt nur in Detailseiten-Vorlagen eines Wissensbereichs.')) ?></p>
</div>
