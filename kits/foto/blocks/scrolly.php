<?php
/**
 * Scrollytelling: Bildspalte bleibt stehen (position: sticky), Textabschnitte ziehen vorbei; js/scrolly.js blendet das
 * passende Bild ein (IntersectionObserver). Container-Query: Unter ~46 rem Blockbreite steht jedes Bild direkt über
 * seinem Text (die stehende Spalte entfällt). Ohne JavaScript bleibt das erste Bild stehen – alle Bilder stehen zusätzlich
 * am Text. Bei „Bewegung reduzieren“ wechseln die Bilder ohne Überblendung.
 *  - Bild je Abschnitt optional: Ohne eigenes Bild bleibt das vorige stehen (data-img am Abschnitt = Index des Abschnitts,
 *    dessen Bild gilt; leer = noch kein Bild). Auf schmalen Flächen steht ein Bild nur bei dem Abschnitt, der es bringt.
 *  - Bildformat „auto“: jedes Bild in seinem eigenen Seitenverhältnis (kein Zuschnitt, Hochformat bleibt ganz).
 *  - Text: Rich-Text (Feld war früher textarea, 'from_text'); alter Klartext erscheint unverändert wie bisher.
 *    Zwischenüberschriften im Text liegen immer unter der Ebene des Abschnittstitels (h2 → h3, unter h3 → h4).
 * @var \Core\Block $b  @var array $d
 */
$ratio = (string) ($d['ratio'] ?? '') ?: '4:5';
$auto = $ratio === 'auto';
$items = array_values(array_filter((array) ($d['items'] ?? []), fn($i) => trim((string) ($i['title'] ?? '')) !== '' || is_editing()));
$tag = foto_htag($d);
$stage = $show = [];
$cur = null;
$long = false;
foreach ($items as $i => $it) {
    $pic = !empty($it['image']) ? img((int) $it['image'], '(min-width: 1080px) 560px, 50vw', $auto ? [] : ['ratio' => $ratio]) : '';
    if ($pic !== '') { $stage[$i] = $pic; $cur = $i; }
    $show[$i] = $cur;
    if (!is_plain_text((string) ($it['text'] ?? ''))) $long = true;
}
?>
<div class="wrap">
  <?= foto_head($b) ?>
  <?php if ($items): ?>
  <div class="scrolly scrolly--<?= $b->variant() === 'right' ? 'right' : 'left' ?><?= $long ? ' scrolly--long' : '' ?>" data-scrolly>
    <div class="scrolly__stage">
      <div class="scrolly__frame <?= $auto ? 'scrolly__frame--auto' : 'r-' . e(str_replace(':', '-', $ratio)) ?>">
        <?php foreach ($stage as $i => $pic): ?>
        <div class="scrolly__img<?= $i === 0 ? ' is-active' : '' ?>" data-step-img="<?= $i ?>"><?= $pic ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <ol class="scrolly__steps" role="list">
      <?php foreach ($items as $i => $it): $text = (string) ($it['text'] ?? ''); ?>
      <li class="scrolly__step<?= $i === 0 ? ' is-active' : '' ?>" data-step="<?= $i ?>" data-img="<?= $show[$i] ?? '' ?>">
        <?php if (isset($stage[$i])): ?><?= $auto ? '<div class="scrolly__inline scrolly__inline--auto">' . img((int) $it['image'], '100vw') . '</div>' : foto_image((int) $it['image'], '100vw', $ratio, 'scrolly__inline') ?><?php endif; ?>
        <span class="scrolly__num" aria-hidden="true"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
        <?php if (trim((string) ($it['eyebrow'] ?? '')) !== ''): ?><p class="eyebrow"<?= $b->edit("items.$i.eyebrow") ?>><?= foto_title((string) $it['eyebrow']) ?></p><?php endif; ?>
        <<?= $tag ?> class="scrolly__title"<?= $b->edit("items.$i.title") ?>><?= foto_title((string) ($it['title'] ?? '')) ?></<?= $tag ?>>
        <?php if (trim($text) === ''): ?>
        <?php elseif (is_plain_text($text)): ?><p class="scrolly__text"<?= $b->edit("items.$i.text") ?>><?= nl2br(foto_title(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')), false) ?></p>
        <?php else: ?><div class="scrolly__text scrolly__rich prose"<?= $b->edit("items.$i.text", 'rich') ?>><?= rich_below(rich($text), (int) $tag[1]) ?></div>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
  <?php elseif (is_editing()): ?><p class="empty-hint">Noch keine Abschnitte – in der Seitenleiste hinzufügen.</p><?php endif; ?>
</div>
