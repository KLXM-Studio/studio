<?php
/**
 * Seite je Schlagwort (Core\Http\Controllers\TagPageController): Einträge mit diesem Schlagwort, je Tabelle eine Karten-Reihe – Markup
 * wie Datenliste/Highlights (dl dl--cards …), damit jedes Kit sie gestaltet. Ein Kit ersetzt die Ansicht mit templates/tag.php.
 * @var string $tag  @var list<array{table: array, items: list<array{entry: array, href: string}>}> $groups
 */
use Core\Data\Entries;
use Core\Data\Tables;

$wrap = app()->theme->def['container_class'] ?? 'wrap';
?>
<section class="section tagpage">
  <div class="<?= e($wrap) ?> dl dl--cards dl--highlights hl--cards dl--tagpage">
    <header class="dl-head">
      <p class="eyebrow eyebrow--accent"><?= e(lt('Schlagwort')) ?></p>
      <h1 class="h2 dl-title"><?= e($tag) ?></h1>
    </header>
    <?php foreach ($groups as $g): $t = $g['table']; $img = Tables::imageField($t); $cal = \Core\Data\Calendar::enabled($t); ?>
    <h2 class="h3 tagpage__h"><?= e($t['name']) ?> <small class="muted">(<?= count($g['items']) ?>)</small></h2>
    <ul class="dl-items dl-cols-3" role="list">
      <?php foreach ($g['items'] as $it): $e = $it['entry']; $title = Entries::title($t, $e);
        $when = $cal ? Entries::html($t, $e, '_when') : Entries::html($t, $e, 'published_at'); ?>
      <li class="dl-item hl-item">
        <?php if ($img !== ''): $pic = Entries::html($t, $e, $img, ['ratio' => '16:10', 'sizes' => '(min-width: 1080px) 400px, 100vw']); ?>
        <div class="dl-img ratio-16-10"><?= $pic ?: '<span class="dl-ph" aria-hidden="true"></span>' ?></div>
        <?php endif; ?>
        <div class="dl-body">
          <?php if ($when !== ''): ?><p class="dl-meta"><?= $when ?></p><?php endif; ?>
          <h3 class="dl-name"><a href="<?= e($it['href']) ?>" class="dl-link"><?= e($title) ?></a></h3>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endforeach; ?>
  </div>
</section>
