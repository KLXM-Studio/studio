<?php
/**
 * Termin-Aktionen (Kern-Block, vom Kit überschreibbar) – Detailseite eines Termins (Kalender-Tabelle): „In den Kalender“ (.ics),
 * „Abonnieren“ (webcal), optional Google Kalender / Outlook.com. Ausgabe über das Fragment event-actions (Core\Data\EventActions),
 * Stile: css/event-actions.css (Variablen --eva-*).
 * @var \Core\Block $b  @var array $d
 */
use Core\Data\Calendar;
use Core\Data\Entries;
use Core\Data\Tables;

$wrap = app()->theme->def['container_class'] ?? 'wrap';
$ctx = app()->entry;
$t = ($h = (string) ($d['table'] ?? '')) !== '' ? Tables::findContent($h) : ($ctx['table'] ?? null);
$e = $t && $ctx && $ctx['table']['handle'] === $t['handle'] ? $ctx['entry'] : null;
if (is_editing() && !$e) {
    // Editor ohne Eintrag (Vorlage direkt geöffnet): erster Termin der Tabelle bzw. der ersten Kalender-Tabelle als Vorschau
    foreach ($t ? [$t] : Calendar::tables() as $ct) {
        if (!Calendar::enabled($ct)) continue;
        $t = $ct;
        $e = Entries::query($ct, ['status' => 'all', 'limit' => 1])[0] ?? null;
        break;
    }
}
if (!$t || !$e || !Calendar::enabled($t)) {
    if (is_editing()) echo '<div class="' . e($wrap) . '"><p class="dl-empty">' . e(__('Dieser Block gehört auf die Detailseite einer Kalender-Tabelle. Die Vorschau erscheint, sobald es einen Termin gibt.')) . '</p></div>';
    return;
}
$html = fragment('event-actions', ['table' => $t, 'entry' => $e, 'options' => ['subscribe' => ($d['subscribe'] ?? true) !== false,
    'external' => (string) ($d['external'] ?? ''), 'when' => (string) ($d['show_when'] ?? 'auto')]]);
if (trim($html) === '') return;
?>
<div class="<?= e($wrap) ?> eva-block">
  <?php if (!empty($d['title'])): ?><h2 id="<?= e($b->titleId()) ?>" class="h2 h2--m eva-title"><span<?= $b->edit('title') ?>><?= emphasis((string) $d['title']) ?></span></h2><?php endif; ?>
  <?= $html ?>
</div>
