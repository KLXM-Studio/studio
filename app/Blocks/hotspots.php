<?php
/**
 * Interaktives Bild (Kern-Block, Core\Hotspots) – vom Kit überschreibbar (kits/{name}/blocks/hotspots.php), Aussehen über
 * css/hotspots.css des Kerns und die Variablen --hs-* (aus den --kit-*-Farben) oder eine eigene css/hotspots.css des Kits.
 *  - Quelle „here“: Bild und Markierungen aus dem Block (Feldtyp „hotspots“, Marker-Editor in der Seitenleiste).
 *  - Quelle „table“: Eintrag einer Tabelle mit Feld „Markierungen“ (Vorlage „Interaktive Bilder“, Verweis entry:{tabelle}:{id}) –
 *    Bild aus dem zugehörigen Bildfeld. Geteilte Tabellen liefern Bild und Markierungen aus dem Netzwerk.
 * @var \Core\Block $b  @var array $d
 */
use Core\Data\Entries;
use Core\Data\Exposure;
use Core\Data\Tables;
use Core\Hotspots;
use Core\MediaBlocks;

$wrap = app()->theme->def['container_class'] ?? 'wrap';
$media = null;
$data = null;
$label = trim((string) ($d['title'] ?? ''));
$hint = '';
if (($d['source'] ?? 'here') === 'table') {
    $ref = (string) ($d['entry'] ?? '');
    $t = preg_match('~^entry:([a-z][a-z0-9_]*):(\d+)$~', $ref, $m) ? Tables::find($m[1]) : null;
    $f = $t ? Hotspots::tableField($t) : null;
    $e = $t && $f ? Entries::find($t, (int) $m[2]) : null;
    if ($e && ((($e['status'] ?? 'published') === 'published') || is_editing()) && Exposure::visible($t, $e)) {
        $img = Hotspots::imageField($t, $f);
        $media = $img ? (int) ($e[$img] ?? 0) : null;
        $data = $e[$f['name']] ?? null;
        if ($label === '') $label = Entries::title($t, $e);
    } elseif (is_editing()) {
        $hint = $ref === '' ? __('Noch kein Eintrag gewählt – in der Seitenleiste unter „Eintrag“ einen Eintrag der Tabelle „Interaktive Bilder“ wählen.')
            : ($t && !$f ? __('Diese Tabelle hat kein Feld „Markierungen“.') : __('Der gewählte Eintrag ist nicht (mehr) veröffentlicht oder wurde gelöscht.'));
    }
} else {
    $media = (int) ($d['image'] ?? 0) ?: null;
    $data = $d['hotspots'] ?? null;
}
$html = $media ? Hotspots::render($media, $data, ['label' => $label, 'id' => $b->domId() . '-hs', 'list' => (string) ($d['list'] ?? 'auto'), 'path' => 'image',
    'sizes' => ($d['width'] ?? '') === 'text' ? '(min-width: 800px) 760px, 100vw' : '(min-width: 1280px) 1200px, 100vw']) : '';
if ($html === '' && is_editing() && $hint === '') $hint = __('Noch kein Bild – in der Seitenleiste ein Bild wählen und Markierungen setzen.');
if ($html === '' && $hint === '') return;
?>
<div class="<?= e($wrap) ?>">
  <?= MediaBlocks::head($b) ?>
  <?php if ($html !== ''): ?>
  <div class="cms-hs-wrap<?= ($d['width'] ?? '') === 'text' ? ' cms-hs-wrap--text' : '' ?>"><?= $html ?></div>
  <?php else: ?>
  <p class="cms-empty-hint"><?= e($hint) ?></p>
  <?php endif; ?>
</div>
