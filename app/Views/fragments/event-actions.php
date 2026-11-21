<?php
/**
 * Kern-Fragment „event-actions“ (überschreibbar: kits/{kit}/fragments/event-actions.php): Termin-Aktionen einer Kalender-Tabelle
 * (Core\Data\EventActions) – „In den Kalender“ (.ics), „Abonnieren“ (webcal), optional Google Kalender / Outlook.com als reine Links.
 * Vergangen: Hinweis statt Knopf (Serie: nächster Termin), abgesagt: „fällt aus“. Stile: css/event-actions.css (Variablen --eva-*).
 * Klassen: .eva (+ $class) · .eva-when · .eva-list · .eva-act (--ics, --sub, --ext) · .eva-state (--past, --cancelled) · .eva-next
 * @var array $table  @var array $entry  @var array $options  subscribe (bool), external ('' | none | google | outlook | both),
 *      when (auto | always | never: Datum des Vorkommens zeigen – auto: nur bei Serien), class
 */
use Core\Data\EventActions;

$o = ($options ?? []) + ['subscribe' => true, 'external' => '', 'when' => 'auto', 'class' => ''];
$a = EventActions::forEntry($table, $entry, ['subscribe' => (bool) $o['subscribe'],
    'services' => EventActions::services(in_array($o['external'], EventActions::CHOICES, true) ? (string) $o['external'] : '')]);
if (!$a) return;
if ($a['until']) \Core\PageCache::until($a['until']);   // zwischengespeicherte Seite nur bis zum Ende bzw. bis zum nächsten Termin
$open = $a['state'] === 'open';
if ($open && !$a['ics'] && !$a['webcal'] && !$a['links']) {
    if (is_editing()) echo '<p class="dl-empty">' . e(__('Termin-Aktionen: Ohne öffentlichen Kalender-Feed der Tabelle gibt es keine .ics-Datei. Einschalten unter Daten → Tabelle → Felder & Einstellungen → Kalender → „Öffentlicher Feed“.')) . '</p>';
    return;
}
app()->theme->markUsed('event_actions');   // Stile css/event-actions.css (Theme::conditionalCss) – auch aus „Datensatz-Felder“
$showWhen = $a['when'] !== '' && ($o['when'] === 'always' || ($o['when'] === 'auto' && $a['series']));
$hint = ['google' => lt('öffnet Google'), 'outlook' => lt('öffnet Microsoft')];
?>
<div class="eva eva--<?= e($a['state']) ?><?= $o['class'] !== '' ? ' ' . e((string) $o['class']) : '' ?>">
  <?php if ($a['skipped'] !== null): ?><p class="eva-state eva-state--cancelled" role="note"><?= icon('prohibit') ?><span><?= e(lt('Der Termin am {date} fällt aus.', ['date' => $a['skipped']])) ?></span></p><?php endif; ?>
  <?php if ($showWhen && $open): ?><p class="eva-when"><?= e($a['keyed'] ? lt('Termin') : lt('Nächster Termin')) ?>: <strong><?= e($a['when']) ?></strong></p><?php endif; ?>
  <?php if ($a['state'] === 'past'): ?>
  <p class="eva-state eva-state--past" role="note"><?= icon('clock-counter-clockwise') ?><span><?= e($showWhen ? lt('Der Termin am {date} ist vorbei.', ['date' => $a['when']]) : lt('Dieser Termin ist vorbei.')) ?></span></p>
  <?php elseif ($a['state'] === 'cancelled'): ?>
  <p class="eva-state eva-state--cancelled" role="note"><?= icon('prohibit') ?><span><?= e($showWhen ? lt('Der Termin am {date} fällt aus.', ['date' => $a['when']]) : lt('Dieser Termin fällt aus.')) ?></span></p>
  <?php endif; ?>
  <?php if ($a['next']): ?>
  <p class="eva-next"><?= e(lt('Nächster Termin')) ?>: <?= $a['next']['url'] ? '<a href="' . e($a['next']['url']) . '">' . e($a['next']['when']) . '</a>' : '<strong>' . e($a['next']['when']) . '</strong>' ?></p>
  <?php endif; ?>
  <?php if (($open && ($a['ics'] || $a['links'])) || $a['webcal']): ?>
  <ul class="eva-list" role="list">
    <?php if ($open && $a['ics']): ?><li><a class="eva-act eva-act--ics" href="<?= e($a['ics']) ?>" type="text/calendar" download><?= icon('calendar-plus') ?><span><?= e(lt('In den Kalender')) ?></span></a></li><?php endif; ?>
    <?php if ($a['webcal']): ?><li><a class="eva-act eva-act--sub" href="<?= e($a['webcal']) ?>"><?= icon('rss') ?><span><?= e(lt('Abonnieren')) ?></span></a></li><?php endif; ?>
    <?php if ($open): foreach ($a['links'] as $l): ?><li><a class="eva-act eva-act--ext eva-act--<?= e($l['service']) ?>" href="<?= e($l['url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('arrow-square-out') ?><span><?= e($l['service'] === 'google' ? lt('Google Kalender') : 'Outlook.com') ?> <small class="eva-hint">(<?= e($hint[$l['service']]) ?>)</small></span></a></li><?php endforeach; endif; ?>
  </ul>
  <?php endif; ?>
</div>
