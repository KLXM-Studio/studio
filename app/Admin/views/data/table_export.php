<?php
/**
 * Exportieren als Tabelle (Admin\TableIoController::export): Format und Spalten; die Ansicht der Liste (Status, Suche, Filter, Zeitraum)
 * bzw. gewählte Zeilen kommen über die Adresse mit. @var array $t  @var array $cols  @var array $query
 */
$base = '/admin/data/' . $t['handle'];
$keep = array_intersect_key($query, array_flip(['status', 'q', 'f', 'zeit', 'ids']));
$hidden = function (array $a, string $prefix = '') use (&$hidden): string {
    $h = '';
    foreach ($a as $k => $v) {
        $n = $prefix === '' ? (string) $k : $prefix . '[' . $k . ']';
        $h .= is_array($v) ? $hidden($v, $n) : '<input type="hidden" name="' . e($n) . '" value="' . e((string) $v) . '">';
    }
    return $h;
};
?>
<header class="adm-head"><div><p class="adm-eyebrow"><?= e($t['name']) ?></p><h1><?= e(__('Exportieren')) ?></h1>
  <p class="adm-muted"><?= e($keep ? __('Exportiert wird die aktuelle Ansicht der Liste (Status, Suche, Filter bzw. gewählte Einträge).') : __('Exportiert werden alle Einträge dieser Tabelle.')) ?></p></div></header>
<form class="adm-card" method="get" action="<?= e(url($base . '/export')) ?>">
  <?= $hidden($keep) ?>
  <fieldset class="f"><legend><?= e(__('Format')) ?></legend>
    <label class="f-check"><input type="radio" name="format" value="csv" checked> <span><?= e(__('CSV für Excel (Semikolon, UTF-8)')) ?></span></label>
    <label class="f-check"><input type="radio" name="format" value="csv-comma"> <span><?= e(__('CSV mit Komma (z. B. Numbers, Google Tabellen)')) ?></span></label>
    <label class="f-check"><input type="radio" name="format" value="xlsx"> <span><?= e(__('Excel (.xlsx)')) ?></span></label>
  </fieldset>
  <fieldset class="f"><legend><?= e(__('Spalten')) ?></legend>
    <p class="f-help"><?= e(__('Die ID steht immer vorn – damit lassen sich Einträge später per Import aktualisieren. Ohne Auswahl: alle Spalten.')) ?></p>
    <div class="set-checks"><?php foreach ($cols as $ck => $cl): ?><label class="f-check"><input type="checkbox" name="cols[]" value="<?= e($ck) ?>" checked> <span><?= e($cl) ?></span></label><?php endforeach; ?></div>
  </fieldset>
  <p class="adm-row"><button class="adm-btn adm-btn--primary" type="submit"><?= icon('download-simple') ?> <?= e(__('Exportieren')) ?></button>
    <a class="adm-btn adm-btn--ghost" href="<?= e(url($base)) ?>"><?= e(__('Zurück zur Liste')) ?></a></p>
</form>
