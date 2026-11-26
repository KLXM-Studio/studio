<?php
/**
 * Übersicht einer Auswahlliste (/thema …, Core\Http\Controllers\TopicIndexController): Kopf wie der Kopf einer Detailseite (df-head), darunter die
 * Listen-Einträge als Karten mit Anzahl zugeordneter Einträge (Markup wie Datenliste/Highlights). Abschnitts-Hülle und Abstände vom Kit
 * (Theme::renderBlock); ein Kit ersetzt den Block mit blocks/list_index.php.
 * @var \Core\Block $b  @var array $d
 */
use Core\Data\Clamp;
use Core\Data\Entries;
use Core\Data\Exposure;
use Core\Data\OptionSource;
use Core\Data\Tables;

$t = Tables::findContent((string) ($d['table'] ?? ''));
if (!$t) return;
$wrap = app()->theme->def['container_class'] ?? 'wrap';
$counts = OptionSource::publishedCounts($t);
$editor = app()->auth->check();
$items = [];
foreach (Exposure::publicRows($t, Entries::query($t, ['status' => 'published', 'limit' => 500] + (\Core\Lang::multi() ? ['lang' => \Core\Lang::current()] : []))) as $e) {
    $n = (int) ($counts[(string) ($e['slug'] ?? '')] ?? 0);
    if ($n === 0 && !$editor) continue;
    if ($href = Entries::href($t, $e)) $items[] = ['entry' => $e, 'href' => $href, 'count' => $n];
}
$img = Tables::imageField($t);
$desc = (string) ($t['settings']['description_field'] ?? '');
$title = (string) $t['name'];
$eyebrow = '';
$intro = trim((string) ($t['description'] ?? ''));
?>
<div class="<?= e($wrap) ?> df df--head">
  <header class="df-head">
    <?php if ($eyebrow !== ''): ?><p class="df-meta"><?= e($eyebrow) ?></p><?php endif; ?>
    <h1 class="h2 df-title"><?= e($title) ?><span class="dot">.</span></h1>
    <?php if ($intro !== ''): ?><p class="muted dl-intro"><?= e($intro) ?></p><?php endif; ?>
  </header>
</div>
<div class="<?= e($wrap) ?> dl dl--cards dl--highlights hl--cards dl--listindex">
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
