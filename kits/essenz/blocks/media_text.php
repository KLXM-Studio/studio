<?php
/**
 * Text + Bild. auto: mehrere Blöcke hintereinander wechseln die Seite (Bild rechts, links, rechts …) · right · left.
 * Nebeneinander, sobald der Abschnitt breit genug ist (Container-Query), sonst Bild über dem Text.
 * @var \Core\Block $b  @var array $d
 */
$v = in_array($b->variant(), ['auto', 'right', 'left'], true) ? $b->variant() : 'auto';
if ($v === 'auto') {
    $n = 0;
    for ($p = $b->prev; $p && $p->type === 'media_text' && $p->variant() === 'auto'; $p = $p->prev) $n++;
    $v = $n % 2 ? 'left' : 'right';
}
$ratio = (string) ($d['ratio'] ?? '') ?: '4:3';
// Bild ausrichten: mitlaufen (Standard für neue Blöcke, Feld-Standard) · oben · mitte – ältere Blöcke ohne Wert bleiben „mitte“ (unverändert)
$valign = in_array($d['valign'] ?? '', ['follow', 'top', 'center'], true) ? $d['valign'] : 'center';
$capImg = !empty($d['image']) ? (int) $d['image'] : null;
$pic = \Core\Blocks\Caption::figure(essenz_image($capImg, '(min-width: 1080px) 560px, 100vw', $ratio, \Core\Blocks\Caption::text($d, $capImg) === '' ? 'mt__media' : ''), $b, $d, $capImg, '', 'mt__media');   // Bildunterschrift (Core\Blocks\Caption)
?>
<div class="wrap mt mt--<?= e($v) ?> mt--va-<?= e($valign) ?><?= $pic !== '' ? ' mt--has-media' : '' ?>">
  <div class="mt__text">
    <?= essenz_head($b, 'mt__head') ?>
    <?php if (trim(strip_tags((string) $d['text'])) !== '' || is_editing()): ?><div class="prose"<?= $b->edit('text', 'rich') ?>><?= rich((string) $d['text']) ?></div><?php endif; ?>
    <?= essenz_checks(essenz_lines($d['list'] ?? ''), 'mt__list') ?>
    <?= essenz_buttons($b, 'mt__actions') ?>
  </div>
  <?= $pic ?>
</div>
