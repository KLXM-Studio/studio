<?php
/**
 * „Zum Thema“ (Core\Data\OptionSource::topic): auf der Detailseite eines Listen-Eintrags (Thema, Kategorie) die zugeordneten Einträge
 * aller Tabellen, je Bereich eine Karten-Reihe – Markup wie Highlights/Datenliste, jedes Kit gestaltet es. Ohne Treffer leer
 * (im Editor ein Hinweis).
 * @var \Core\Block $b  @var array $d
 */
use Core\Data\Entries;
use Core\Data\OptionSource;
use Core\Data\Tables;

$wrap = app()->theme->def['container_class'] ?? 'wrap';
$ctx = app()->entry;
$groups = $ctx && OptionSource::isList($ctx['table']) ? OptionSource::topic($ctx['table'], $ctx['entry'], max(1, min(24, (int) ($d['limit'] ?? 6))), !empty($d['past'])) : [];
if (!$groups && !is_editing()) return;
$cols = in_array((string) ($d['columns'] ?? ''), ['2', '3', '4'], true) ? (string) $d['columns'] : '3';
?>
<div class="<?= e($wrap) ?> dl dl--cards dl--highlights hl--cards dl--topic">
  <?php if (!empty($d['title'])): ?><header class="dl-head"><h2 id="<?= e($b->titleId()) ?>" class="h2 h2--m dl-title"><span<?= $b->edit('title') ?>><?= emphasis((string) $d['title']) ?></span></h2></header><?php endif; ?>
  <?php if (!$groups): ?>
  <p class="dl-empty"><small><?= e($ctx && OptionSource::isList($ctx['table']) ? __('Diesem Eintrag ist noch nichts zugeordnet.') : __('„Zum Thema“ zeigt auf der Detailseite eines Themas bzw. einer Kategorie die zugeordneten Einträge – bitte in die Detailseiten-Vorlage einer Auswahlliste einsetzen.')) ?></small></p>
  <?php endif; ?>
  <?php foreach ($groups as $g): $t = $g['table']; $img = Tables::imageField($t); $cal = \Core\Data\Calendar::enabled($t); ?>
  <h3 class="h3 tagpage__h"><?= e($t['name']) ?></h3>
  <ul class="dl-items dl-cols-<?= e($cols) ?>" role="list">
    <?php foreach ($g['items'] as $it): $e = $it['entry']; $title = Entries::title($t, $e);
      $when = !empty($d['show_date']) ? ($cal ? Entries::html($t, $e, '_when') : Entries::html($t, $e, 'published_at')) : ''; ?>
    <li class="dl-item hl-item" data-reveal="up">
      <?php if ($img !== ''): $pic = Entries::html($t, $e, $img, ['ratio' => '16:10', 'sizes' => '(min-width: 1080px) 400px, 100vw']); ?>
      <div class="dl-img ratio-16-10"><?= $pic ?: '<span class="dl-ph" aria-hidden="true"></span>' ?></div>
      <?php endif; ?>
      <div class="dl-body">
        <?php if ($when !== ''): ?><p class="dl-meta"><?= $when ?></p><?php endif; ?>
        <h4 class="dl-name"><a href="<?= e($it['href']) ?>" class="dl-link"><?= e($title) ?></a></h4>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endforeach; ?>
</div>
