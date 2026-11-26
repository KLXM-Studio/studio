<?php
/**
 * „Passend dazu“ (Core\Data\Related): zum aufgerufenen Eintrag (Detailseite) passende Einträge mit gemeinsamen Schlagworten bzw.
 * gleichem Thema – auch aus anderen Tabellen. Markup wie Highlights/Datenliste (dl dl--cards …), damit jedes Kit die Karten
 * gestaltet; Zusatz-Stile css/highlights.css. Ohne Treffer bleibt der Abschnitt leer (im Editor ein Hinweis).
 * @var \Core\Block $b  @var array $d
 */
use Core\Data\Clamp;
use Core\Data\Entries;
use Core\Data\Related;
use Core\Data\Tables;

$wrap = app()->theme->def['container_class'] ?? 'wrap';
$ctx = app()->entry;
$items = $ctx ? Related::items($ctx['table'], $ctx['entry'], (array) ($d['sources'] ?? []), max(1, min(18, (int) ($d['limit'] ?? 6)))) : [];
if (!$items && !is_editing()) return;
$cols = in_array((string) ($d['columns'] ?? ''), ['2', '3', '4'], true) ? (string) $d['columns'] : '3';
$ratio = in_array((string) ($d['ratio'] ?? ''), ['16:10', '4:3', '1:1', '16:9'], true) ? (string) $d['ratio'] : '16:10';
$hTag = !empty($d['title']) ? 'h3' : 'h2';
$tones = [];
foreach ($items as $it) $tones[$it['table']['handle']] ??= 'dl-tone-' . (count($tones) % 5 + 1);
?>
<div class="<?= e($wrap) ?> dl dl--cards dl--highlights dl--related hl--cards">
  <?php if (!empty($d['eyebrow']) || !empty($d['title'])): ?>
  <header class="dl-head">
    <?php if (!empty($d['eyebrow'])): ?><p class="eyebrow eyebrow--accent"<?= $b->edit('eyebrow') ?>><?= emphasis((string) $d['eyebrow']) ?></p><?php endif; ?>
    <?php if (!empty($d['title'])): ?><h2 id="<?= e($b->titleId()) ?>" class="h2 h2--m dl-title"><span<?= $b->edit('title') ?>><?= emphasis((string) $d['title']) ?></span></h2><?php endif; ?>
  </header>
  <?php endif; ?>
  <?php if (!$items): ?>
  <p class="dl-empty"><small><?= e($ctx ? __('Zu diesem Eintrag gibt es noch nichts Passendes – gemeinsame Schlagworte oder dasselbe Thema verbinden Einträge.') : __('„Passend dazu“ zeigt auf Detailseiten Einträge mit gemeinsamen Schlagworten – bitte in der Detailseiten-Vorlage einsetzen.')) ?></small></p>
  <?php else: ?>
  <ul class="dl-items dl-cols-<?= e($cols) ?>" role="list">
    <?php foreach ($items as $it): $t = $it['table']; $e = $it['entry']; $title = Entries::title($t, $e);
      $img = Tables::imageField($t);
      $desc = (string) ($t['settings']['description_field'] ?? '');
      $meta = [];
      if (!empty($d['show_badge'])) $meta[] = '<span class="dl-chip hl-badge ' . e($tones[$t['handle']]) . '">' . e($it['badge']) . '</span>';
      if (!empty($d['show_date'])) {
          $when = \Core\Data\Calendar::enabled($t) ? Entries::html($t, $e, '_when') : Entries::html($t, $e, 'published_at');
          if ($when !== '') $meta[] = $when;
      } ?>
    <li class="dl-item hl-item" data-reveal="up">
      <?php if ($img !== ''): $pic = Entries::html($t, $e, $img, ['ratio' => $ratio, 'sizes' => '(min-width: 1080px) 400px, 100vw']); ?>
      <div class="dl-img ratio-<?= e(str_replace(':', '-', $ratio)) ?>"><?= $pic ?: '<span class="dl-ph" aria-hidden="true"></span>' ?></div>
      <?php endif; ?>
      <div class="dl-body">
        <?php if ($meta): ?><p class="dl-meta"><?= implode('<span aria-hidden="true"> · </span>', $meta) ?></p><?php endif; ?>
        <<?= $hTag ?> class="dl-name"><a href="<?= e($it['href']) ?>" class="dl-link"><?= e($title) ?></a></<?= $hTag ?>>
        <?php if (!empty($d['show_text']) && $desc !== '' && $desc !== ($t['settings']['title_field'] ?? '')): [$clamp, $html] = Clamp::text($t, $e, $desc, $d); if ($html !== ''): ?>
        <div class="dl-f dl-f--<?= e((string) (Tables::field($t, $desc)['type'] ?? 'text')) ?><?= $clamp ?>"><?= $html ?></div>
        <?php endif; endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
