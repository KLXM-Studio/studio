<?php
/**
 * Highlights: hervorgehobene Einträge mehrerer Tabellen gemischt (Core\Data\Featured::items) – Kern-Block, vom Kit überschreibbar.
 *
 * Markup der Karten wie im Block „Datenliste“ (dl dl--cards, dl-items, dl-item, dl-img, dl-body, dl-meta, dl-name, dl-f), damit jedes
 * Kit sie über seine Datenlisten-Stile gestaltet (Raster mit auto-fill); dazu dl--highlights, Etikett hl-badge (dl-chip dl-tone-N je
 * Tabelle) und bei „Großer Teaser“ die erste Karte hl-lead über die ganze Breite. Zusatz-Stile css/highlights.css (Kit kann ersetzen).
 * Nur veröffentlichte, für Besucher sichtbare Einträge (Featured::items über Entries::query + Exposure::publicRows).
 * @var \Core\Block $b  @var array $d
 */
use Core\Data\Clamp;
use Core\Data\Entries;
use Core\Data\Featured;
use Core\Data\Tables;

$wrap = app()->theme->def['container_class'] ?? 'wrap';
$btn = app()->theme->def['button_class'] ?? 'btn btn--primary';
$sources = array_values(array_filter((array) ($d['sources'] ?? []), fn($s) => is_array($s) && trim((string) ($s['table'] ?? '')) !== ''));
// Aufgerufener Eintrag (Detailseite): nicht noch einmal als Highlight; Option „exclude_slider“: auch nicht die Einträge, die schon als
// Folien eines Einstiegs als Slider auf dieser Seite stehen (Core\Blocks\HeroSlider::pageEntries, unabhängig von der Reihenfolge)
$ctx = app()->entry;
$exclude = $ctx ? [$ctx['table']['handle'] => [(int) $ctx['entry']['id']]] : [];
// Ausgewählte Einträge (Feldtyp „entrypick“, Core\Data\EntryPick): zuerst, in gewählter Reihenfolge – dann die hervorgehobenen
$picked = \Core\Data\EntryPick::clean(['max' => 24], $d['picked'] ?? []);
$inSlider = $sources || $picked ? \Core\Blocks\HeroSlider::pageEntries() : [];
$q = ['sort' => (string) ($d['sort'] ?? 'date'), 'limit' => (int) ($d['limit'] ?? 6)];
$skip = $exclude;
if (!empty($d['exclude_slider'])) foreach ($inSlider as $h => $ids) $skip[$h] = [...($skip[$h] ?? []), ...$ids];
$items = \Core\Data\EntryPick::items($picked, $skip);
if ($items) {
    // Etikett wie bei der Tabelle unter „Tabellen“, sonst ihr Name; ausgewählte nicht doppelt als hervorgehobene
    $badgeOf = [];
    foreach ($sources as $s) $badgeOf[(string) $s['table']] ??= trim((string) ($s['badge'] ?? ''));
    foreach ($items as &$it) { $it['badge'] = ($badgeOf[$it['table']['handle']] ?? '') ?: $it['badge']; $skip[$it['table']['handle']][] = (int) $it['entry']['id']; }
    unset($it);
}
$items = [...$items, ...Featured::items($sources, ['sort' => $q['sort'], 'exclude' => $skip])];
if ($q['limit'] > 0) $items = array_slice($items, 0, $q['limit']);
// Hinweis im Editor: Überschneidung mit dem Slider dieser Seite (ausgelassen bzw. doppelt)
$overlap = 0;
if (is_editing() && $inSlider) {
    foreach (Featured::items($sources, $q + ['exclude' => $exclude, 'limit' => 0]) as $it) {
        if (in_array((int) $it['entry']['id'], $inSlider[$it['table']['handle']] ?? [], true)) $overlap++;
    }
}
if (!$items && !empty($d['hide_empty']) && !is_editing()) return;
$layout = ($d['layout'] ?? '') === 'teaser' ? 'teaser' : 'cards';
$cols = in_array((string) ($d['columns'] ?? ''), ['2', '3', '4'], true) ? (string) $d['columns'] : '3';
// Großer Teaser: volle Reihen – die kleinen Karten nehmen die Spaltenzahl aus der Restmenge (höchstens die eingestellte);
// ein Rest von 1 steht 50/50 neben dem Teaser (hl--rest-1, Teaser ohne volle Breite). Karten: auto-fill wie die Datenliste.
$rest = $layout === 'teaser' ? max(0, count($items) - 1) : 0;
$restCls = '';
if ($layout === 'teaser' && $rest > 0) {
    $restCls = ' hl--rest-' . min($rest, (int) $cols);
    $cols = (string) max(2, min($rest, (int) $cols));
}
// Hinweis im Editor: Lücke in der letzten Reihe der kleinen Karten
$gap = is_editing() && $layout === 'teaser' && $rest > (int) $cols && $rest % (int) $cols !== 0;
$ratio = in_array((string) ($d['ratio'] ?? ''), ['16:10', '4:3', '1:1', '16:9'], true) ? (string) $d['ratio'] : '16:10';
$hTag = !empty($d['title']) ? 'h3' : 'h2';
$titleClamp = Clamp::titleClass($d);
// Farbton des Etiketts je Tabelle (Reihenfolge der gewählten Tabellen) – dl-tone-1 … 5 wie die Etiketten im Verzeichnis
$tones = [];
foreach ($sources as $s) $tones[(string) $s['table']] ??= 'dl-tone-' . (count($tones) % 5 + 1);
foreach ($items as $it) $tones[$it['table']['handle']] ??= 'dl-tone-' . (count($tones) % 5 + 1);
?>
<div class="<?= e($wrap) ?> dl dl--cards dl--highlights hl--<?= e($layout) ?><?= e($restCls) ?>">
  <?php if (!empty($d['eyebrow']) || !empty($d['title']) || !empty($d['intro'])): ?>
  <header class="dl-head">
    <?php if (!empty($d['eyebrow'])): ?><p class="eyebrow eyebrow--accent"<?= $b->edit('eyebrow') ?>><?= emphasis((string) $d['eyebrow']) ?></p><?php endif; ?>
    <?php if (!empty($d['title'])): ?><h2 id="<?= e($b->titleId()) ?>" class="h2 h2--m dl-title"><span<?= $b->edit('title') ?>><?= emphasis((string) $d['title']) ?></span></h2><?php endif; ?>
    <?php if (!empty($d['intro'])): ?><p class="muted dl-intro"<?= $b->edit('intro') ?>><?= emphasis((string) $d['intro']) ?></p><?php endif; ?>
  </header>
  <?php endif; ?>
  <?php if ($gap): ?>
  <p class="dl-empty hl-note"><small><?= e(__('Für volle Reihen 1 + {a} bzw. 1 + {b} Einträge hervorheben (Anzahl gesamt oder je Tabelle anpassen).', ['a' => $cols, 'b' => (int) $cols - 1])) ?></small></p>
  <?php endif; ?>
  <?php if ($overlap): ?>
  <p class="dl-empty hl-note"><small><?= e(!empty($d['exclude_slider'])
      ? __('{n} hervorgehobene Einträge stehen schon im Slider dieser Seite und werden hier ausgelassen.', ['n' => $overlap])
      : __('{n} Einträge stehen auch im Slider dieser Seite. Mit „Einträge auslassen, die schon im Slider dieser Seite stehen“ erscheint jeder nur einmal.', ['n' => $overlap])) ?></small></p>
  <?php endif; ?>
  <?php if (!$items): ?>
  <p class="dl-empty"><?= e((string) ($d['empty_text'] ?? '') ?: lt('Zurzeit ist nichts hervorgehoben.')) ?><?php if (is_editing()): ?> <small><?= e(!Featured::tables()
      ? __('Schalten Sie zuerst in einer Tabelle „Einträge auf der Startseite hervorheben können“ ein (Tabelle → Auf der Website → Detailseiten).')
      : (!$sources && !$picked ? __('Bitte in der Seitenleiste Tabellen oder einzelne Einträge wählen.') : __('Nur veröffentlichte Einträge mit „Hervorheben“ erscheinen.'))) ?></small><?php endif; ?></p>
  <?php else: ?>
  <ul class="dl-items dl-cols-<?= e($cols) ?>" role="list">
    <?php foreach ($items as $i => $it): $t = $it['table']; $e = $it['entry']; $url = $it['href']; $title = Entries::title($t, $e);
      $lead = $layout === 'teaser' && $i === 0;
      $img = Tables::imageField($t);
      $desc = (string) ($t['settings']['description_field'] ?? '');
      $meta = [];
      if (!empty($d['show_badge'])) $meta[] = '<span class="dl-chip hl-badge ' . e($tones[$t['handle']] ?? 'dl-tone-1') . '">' . e($it['badge']) . '</span>';
      if (!empty($d['show_date'])) {
          $when = \Core\Data\Calendar::enabled($t) ? Entries::html($t, $e, '_when') : Entries::html($t, $e, 'published_at');
          if ($when !== '') $meta[] = $when;
      } ?>
    <li class="dl-item hl-item<?= $lead ? ' hl-lead' : '' ?>" data-reveal="up"><?= \Core\Data\EntryEdit::button($t, $e, $title) ?>
      <?php if ($img !== ''): $r = $lead ? '4:3' : $ratio; $pic = Entries::html($t, $e, $img, ['ratio' => $r, 'sizes' => $lead ? '(min-width: 1080px) 720px, 100vw' : '(min-width: 1080px) 400px, 100vw']); ?>
      <div class="dl-img<?= $lead ? ' hl-lead__img' : ' ratio-' . e(str_replace(':', '-', $r)) ?>"><?= $pic ?: '<span class="dl-ph" aria-hidden="true"></span>' ?></div>
      <?php endif; ?>
      <div class="dl-body">
        <?php if ($meta): ?><p class="dl-meta"><?= implode('<span aria-hidden="true"> · </span>', $meta) ?></p><?php endif; ?>
        <<?= $hTag ?> class="dl-name<?= $titleClamp ?>"><?= $url ? '<a href="' . e($url) . '" class="dl-link">' . e($title) . '</a>' : e($title) ?></<?= $hTag ?>>
        <?php if (!empty($d['show_text']) && $desc !== '' && $desc !== ($t['settings']['title_field'] ?? '')): [$clamp, $html] = Clamp::text($t, $e, $desc, $d); if ($html !== ''): ?>
        <div class="dl-f dl-f--<?= e((string) (Tables::field($t, $desc)['type'] ?? 'text')) ?><?= $clamp ?>"><?= $html ?></div>
        <?php endif; endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if (!empty($d['more_label']) && !empty($d['more_link'])): ?>
  <p class="dl-more"><a class="<?= e($btn) ?>" href="<?= e(link_href((string) $d['more_link'])) ?>"<?= $b->edit('more_label') ?>><?= e($d['more_label']) ?></a></p>
  <?php endif; ?>
</div>
