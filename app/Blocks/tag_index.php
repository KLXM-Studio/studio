<?php
/**
 * Alle Schlagworte (/schlagwort, TopicIndexController): Kopf wie eine Detailseite (df-head), darunter A–Z mit Anzahl, Schriftgröße nach
 * Häufigkeit. Abschnitts-Hülle vom Kit; ein Kit ersetzt den Block mit blocks/tag_index.php.
 * @var \Core\Block $b  @var array $d
 */
use Core\Data\Tags;

$wrap = app()->theme->def['container_class'] ?? 'wrap';
$tags = Tags::publicCounts();
$groups = [];
foreach ($tags as $tag => $n) {
    $first = mb_strtoupper(mb_substr(strtr((string) $tag, ['Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u']), 0, 1));
    $groups[preg_match('~^[A-Z]$~', $first) ? $first : '#'][] = ['tag' => (string) $tag, 'count' => $n, 'href' => Tags::url((string) $tag)];
}
ksort($groups);
$max = $tags ? max($tags) : 1;
$size = fn(int $n) => 'tagidx__s' . (int) min(4, max(1, (int) ceil(4 * $n / max(1, $max))));
$title = lt('Schlagworte');
$eyebrow = '';
$intro = '';
?>
<div class="<?= e($wrap) ?> df df--head">
  <header class="df-head">
    <?php if ($eyebrow !== ''): ?><p class="df-meta"><?= e($eyebrow) ?></p><?php endif; ?>
    <h1 class="h2 df-title"><?= e($title) ?><span class="dot">.</span></h1>
    <?php if ($intro !== ''): ?><p class="muted dl-intro"><?= e($intro) ?></p><?php endif; ?>
  </header>
</div>
<div class="<?= e($wrap) ?> tagidx">
  <nav class="tagidx__az" aria-label="<?= e(lt('Anfangsbuchstaben')) ?>"><?php foreach (array_keys($groups) as $l): ?><a href="#tag-<?= e($l === '#' ? 'x' : $l) ?>"><?= e($l) ?></a> <?php endforeach; ?></nav>
  <?php foreach ($groups as $l => $list): ?>
  <h2 class="h3 tagpage__h" id="tag-<?= e($l === '#' ? 'x' : $l) ?>"><?= e($l) ?></h2>
  <ul class="tagidx__list" role="list">
    <?php foreach ($list as $x): ?><li><a class="dl-chip opt-link <?= e($size($x['count'])) ?>" href="<?= e($x['href']) ?>"><?= e($x['tag']) ?> <small>(<?= (int) $x['count'] ?>)</small></a></li><?php endforeach; ?>
  </ul>
  <?php endforeach; ?>
</div>
