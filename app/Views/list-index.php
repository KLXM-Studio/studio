<?php
/**
 * Übersicht einer Auswahlliste (/thema …, Core\Http\Controllers\TopicIndexController): Listen-Einträge als Karten mit Anzahl zugeordneter
 * Einträge – Markup wie Datenliste/Highlights, jedes Kit gestaltet es. Ein Kit ersetzt die Ansicht mit templates/list-index.php.
 * @var array $table  @var list<array{entry: array, href: string, count: int}> $items
 */
use Core\Data\Clamp;
use Core\Data\Entries;
use Core\Data\Tables;

$wrap = app()->theme->def['container_class'] ?? 'wrap';
$t = $table;
$img = Tables::imageField($t);
$desc = (string) ($t['settings']['description_field'] ?? '');
?>
<section class="section listindex">
  <div class="<?= e($wrap) ?> dl dl--cards dl--highlights hl--cards dl--listindex">
    <header class="dl-head">
      <h1 class="h2 dl-title"><?= e($t['name']) ?></h1>
      <?php if (trim((string) ($t['description'] ?? '')) !== ''): ?><p class="muted dl-intro"><?= e((string) $t['description']) ?></p><?php endif; ?>
    </header>
    <?php if (!$items): ?><p class="dl-empty"><?= e(lt('Noch keine Einträge.')) ?></p><?php else: ?>
    <ul class="dl-items dl-cols-3" role="list">
      <?php foreach ($items as $it): $e = $it['entry']; ?>
      <li class="dl-item hl-item" data-reveal="up">
        <?php if ($img !== ''): $pic = Entries::html($t, $e, $img, ['ratio' => '16:10', 'sizes' => '(min-width: 1080px) 400px, 100vw']); ?>
        <div class="dl-img ratio-16-10"><?= $pic ?: '<span class="dl-ph" aria-hidden="true"></span>' ?></div>
        <?php endif; ?>
        <div class="dl-body">
          <p class="dl-meta"><?= e($it['count'] === 1 ? lt('1 Eintrag') : lt('{n} Einträge', ['n' => $it['count']])) ?></p>
          <h2 class="dl-name"><a href="<?= e($it['href']) ?>" class="dl-link"><?= e(Entries::title($t, $e)) ?></a></h2>
          <?php if ($desc !== '' && $desc !== ($t['settings']['title_field'] ?? '')): [$clamp, $html] = Clamp::text($t, $e, $desc, ['text_lines' => '3']); if ($html !== ''): ?>
          <div class="dl-f<?= $clamp ?>"><?= $html ?></div>
          <?php endif; endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</section>
