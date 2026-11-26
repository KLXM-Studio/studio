<?php
/**
 * Seite eines Schlagworts (/schlagwort/{x}, TagPageController): Kopf wie eine Detailseite (df-head), darunter je Tabelle eine Karten-Reihe
 * (Markup wie Datenliste/Highlights). Abschnitts-Hülle vom Kit; ein Kit ersetzt den Block mit blocks/tag_page.php.
 * @var \Core\Block $b  @var array $d
 */
use Core\Data\Entries;
use Core\Data\Tables;
use Core\Data\Tags;

$wrap = app()->theme->def['container_class'] ?? 'wrap';
$groups = Tags::entries((string) ($d['tag'] ?? ''));
if (!$groups) return;
$title = (string) $groups[0]['tag'];
$eyebrow = lt('Schlagwort');
$intro = '';
?>
<div class="<?= e($wrap) ?> df df--head">
  <header class="df-head">
    <?php if ($eyebrow !== ''): ?><p class="df-meta"><?= e($eyebrow) ?></p><?php endif; ?>
    <h1 class="h2 df-title"><?= e($title) ?><span class="dot">.</span></h1>
    <?php if ($intro !== ''): ?><p class="muted dl-intro"><?= e($intro) ?></p><?php endif; ?>
  </header>
</div>
<div class="<?= e($wrap) ?> dl dl--cards dl--highlights hl--cards dl--tagpage">
  <?php foreach ($groups as $g): $t = $g['table']; $img = Tables::imageField($t); $cal = \Core\Data\Calendar::enabled($t); ?>
  <h2 class="h3 tagpage__h"><?= e($t['name']) ?> <small class="muted">(<?= count($g['items']) ?>)</small></h2>
  <ul class="dl-items dl-cols-3" role="list">
    <?php foreach ($g['items'] as $it): $e = $it['entry']; $when = $cal ? Entries::html($t, $e, '_when') : Entries::html($t, $e, 'published_at'); ?>
    <li class="dl-item hl-item">
      <?php if ($img !== ''): $pic = Entries::html($t, $e, $img, ['ratio' => '16:10', 'sizes' => '(min-width: 1080px) 400px, 100vw']); ?>
      <div class="dl-img ratio-16-10"><?= $pic ?: '<span class="dl-ph" aria-hidden="true"></span>' ?></div>
      <?php endif; ?>
      <div class="dl-body">
        <?php if ($when !== ''): ?><p class="dl-meta"><?= $when ?></p><?php endif; ?>
        <h3 class="dl-name"><a href="<?= e($it['href']) ?>" class="dl-link"><?= e(Entries::title($t, $e)) ?></a></h3>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endforeach; ?>
</div>
