<?php
/**
 * Alle Schlagworte (/schlagwort, Core\Http\Controllers\TopicIndexController): A–Z mit Anzahl, Schriftgröße nach Häufigkeit (Wolke).
 * Ein Kit ersetzt die Ansicht mit templates/tag-index.php.
 * @var array<string, list<array{tag: string, count: int, href: string}>> $groups  @var int $max
 */
$wrap = app()->theme->def['container_class'] ?? 'wrap';
$size = fn(int $n) => 'tagidx__s' . (int) min(4, max(1, (int) ceil(4 * $n / max(1, $max))));
?>
<section class="section tagidx">
  <div class="<?= e($wrap) ?>">
    <header class="dl-head"><h1 class="h2 dl-title"><?= e(lt('Schlagworte')) ?></h1></header>
    <nav class="tagidx__az" aria-label="<?= e(lt('Anfangsbuchstaben')) ?>"><?php foreach (array_keys($groups) as $l): ?><a href="#tag-<?= e($l === '#' ? 'x' : $l) ?>"><?= e($l) ?></a> <?php endforeach; ?></nav>
    <?php foreach ($groups as $l => $list): ?>
    <h2 class="h3 tagpage__h" id="tag-<?= e($l === '#' ? 'x' : $l) ?>"><?= e($l) ?></h2>
    <ul class="tagidx__list" role="list">
      <?php foreach ($list as $x): ?><li><a class="dl-chip opt-link <?= e($size($x['count'])) ?>" href="<?= e($x['href']) ?>"><?= e($x['tag']) ?> <small>(<?= (int) $x['count'] ?>)</small></a></li><?php endforeach; ?>
    </ul>
    <?php endforeach; ?>
  </div>
</section>
