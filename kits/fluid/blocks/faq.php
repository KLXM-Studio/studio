<?php
/**
 * Fragen & Antworten. split: Kopf neben der Liste (Sidebar-Muster) · stacked: untereinander, zentriert (beide aufklappbar,
 * details/summary – ohne JavaScript) · open: alle Antworten sichtbar, Frage als Zwischenüberschrift · cards: je Frage eine Karte,
 * zwei Spalten (offen). FAQPage-Daten für Suchmaschinen in jeder Variante (theme.php → jsonld). @var \Core\Block $b  @var array $d
 */
$v = in_array($b->variant(), ['split', 'stacked', 'open', 'cards'], true) ? $b->variant() : 'split';
$fold = in_array($v, ['split', 'stacked'], true);
$hx = trim((string) ($d['title'] ?? '')) !== '' ? 'h3' : 'h2';
?>
<div class="wrap faq faq--<?= e($v) ?>">
  <?= fluid_head($b, $v === 'split' ? 'faq__head' : 'sec-head--center') ?>
  <div class="faq__list">
    <?php foreach ((array) $d['items'] as $i => $it): ?>
    <?php if ($fold): ?>
    <details class="faq__item" name="<?= e($b->domId()) ?>-faq">
      <summary class="faq__q"><span class="faq__qt"<?= $b->edit("items.$i.q") ?>><?= fluid_title((string) $it['q']) ?></span><span class="faq__icon" aria-hidden="true"></span></summary>
      <div class="faq__a prose"<?= $b->edit("items.$i.a", 'rich') ?>><?= rich($it['a']) ?></div>
    </details>
    <?php else: ?>
    <div class="faq__item<?= $v === 'cards' ? ' card' : '' ?>">
      <<?= $hx ?> class="faq__q"<?= $b->edit("items.$i.q") ?>><?= fluid_title((string) $it['q']) ?></<?= $hx ?>>
      <div class="faq__a prose"<?= $b->edit("items.$i.a", 'rich') ?>><?= rich($it['a']) ?></div>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$d['items'] && is_editing()): ?><p class="empty-hint">Noch keine Fragen – in der Seitenleiste hinzufügen.</p><?php endif; ?>
  </div>
</div>
