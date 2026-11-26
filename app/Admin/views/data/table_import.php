<?php
/**
 * Importieren aus einer Tabelle (Admin\TableIoController): 1. Datei, 2. Zuordnung + Probelauf, 3. Ergebnis + Bestätigen.
 * @var array $t  @var string $token  @var ?array $rows  @var array $map  @var array $targets  @var ?array $result  @var array $opts
 */
$base = '/admin/data/' . $t['handle'];
$head = $rows[0] ?? [];
$sample = array_slice((array) $rows, 1, 3);
$matchOpts = ['_id' => __('ID'), 'slug' => __('Adresse (Slug)')] + array_diff_key($targets, ['' => 1, '_id' => 1, 'slug' => 1, 'status' => 1]);
?>
<header class="adm-head"><div><p class="adm-eyebrow"><?= e($t['name']) ?></p><h1><?= e(__('Importieren')) ?></h1>
  <p class="adm-muted"><?= e(__('CSV- oder Excel-Datei mit einer Kopfzeile. Erst ein Probelauf zeigt, was passiert – gespeichert wird nach Ihrer Bestätigung. Neue Einträge starten offline.')) ?></p></div></header>
<?php if ($rows === null): ?>
<form class="adm-card" method="post" enctype="multipart/form-data" action="<?= e(url($base . '/tabelle-import')) ?>"><?= csrf_field() ?>
  <div class="f"><label for="ti-file"><?= e(__('Datei (.csv oder .xlsx, höchstens 10 MB)')) ?></label>
    <input id="ti-file" type="file" name="datei" accept=".csv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div>
  <p class="f-help"><?= e(__('Tipp: Erst exportieren, in Excel bearbeiten, dann importieren – die Spalte „ID“ ordnet die Zeilen den vorhandenen Einträgen zu.')) ?></p>
  <p class="adm-row"><button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Weiter')) ?></button> <a class="adm-btn adm-btn--ghost" href="<?= e(url($base)) ?>"><?= e(__('Abbrechen')) ?></a></p>
</form>
<?php else: ?>
<form class="adm-card ti" method="post" action="<?= e(url($base . '/tabelle-import/lauf')) ?>"><?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <h2 class="adm-h2"><?= e(__('Spalten zuordnen')) ?> <small class="adm-muted"><?= e(__('{n} Zeilen', ['n' => count($rows) - 1])) ?></small></h2>
  <div class="ti__wrap"><table class="dt-table ti__table">
    <thead><tr><th scope="col"><?= e(__('Spalte in der Datei')) ?></th><th scope="col"><?= e(__('Feld')) ?></th><th scope="col"><?= e(__('Beispiele')) ?></th></tr></thead>
    <tbody><?php foreach ($head as $ci => $h): ?>
      <tr><th scope="row"><?= e((string) $h) ?></th>
        <td><select name="map[<?= (int) $ci ?>]" aria-label="<?= e(__('Feld für „{col}“', ['col' => (string) $h])) ?>"><?php foreach ($targets as $tk => $tl): ?><option value="<?= e($tk) ?>"<?= ($map[$ci] ?? '') === (string) $tk ? ' selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?></select></td>
        <td class="adm-muted"><?= e(implode(' · ', array_filter(array_map(fn($s) => mb_strimwidth((string) ($s[$ci] ?? ''), 0, 40, '…'), $sample), fn($x) => $x !== ''))) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <div class="adm-row ti__opts">
    <label class="f"><span><?= e(__('Vorgehen')) ?></span><select name="mode">
      <?php foreach (['both' => __('Vorhandene aktualisieren, übrige neu anlegen'), 'update' => __('Nur vorhandene aktualisieren'), 'new' => __('Alle neu anlegen')] as $mk => $ml): ?><option value="<?= e($mk) ?>"<?= ($opts['mode'] ?? 'both') === $mk ? ' selected' : '' ?>><?= e($ml) ?></option><?php endforeach; ?></select></label>
    <label class="f"><span><?= e(__('Vorhandene erkennen über')) ?></span><select name="match">
      <?php foreach ($matchOpts as $mk => $ml): ?><option value="<?= e((string) $mk) ?>"<?= ($opts['match'] ?? '_id') === (string) $mk ? ' selected' : '' ?>><?= e($ml) ?></option><?php endforeach; ?></select></label>
  </div>
  <?php if ($result): ?>
  <section class="ti__result" aria-live="polite">
    <h2 class="adm-h2"><?= e(__('Probelauf')) ?></h2>
    <p><b><?= e(__('{new} neu, {up} aktualisiert, {skip} übersprungen', ['new' => $result['new'], 'up' => $result['updated'], 'skip' => $result['skipped']])) ?></b><?php if ($result['errors']): ?> · <span class="adm-err"><?= e(__('{n} Zeilen mit Fehlern – sie werden nicht importiert', ['n' => count(array_unique(array_column($result['errors'], 'row')))])) ?></span><?php endif; ?></p>
    <?php foreach ($result['notes'] as $nt): ?><p class="f-help"><?= e($nt) ?></p><?php endforeach; ?>
    <?php if ($result['errors']): ?><ul class="ti__errors"><?php foreach (array_slice($result['errors'], 0, 30) as $er): ?><li><?= e(__('Zeile {n}', ['n' => $er['row']])) ?>: <?= e($er['msg']) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($result['preview']): ?><details><summary><?= e(__('Vorschau der ersten Zeilen')) ?></summary><ul><?php foreach ($result['preview'] as $pv): ?><li><?= e(__('Zeile {n}', ['n' => $pv['row']])) ?>: <?= e(match ($pv['action']) { 'new' => __('neu'), 'update' => __('aktualisiert'), default => __('übersprungen') }) ?> – <?= e((string) $pv['title']) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
  </section>
  <?php endif; ?>
  <p class="adm-row">
    <button class="adm-btn<?= $result ? '' : ' adm-btn--primary' ?>" type="submit" name="step" value="probe"><?= e($result ? __('Probelauf wiederholen') : __('Probelauf')) ?></button>
    <?php if ($result && ($result['new'] + $result['updated']) > 0): ?><button class="adm-btn adm-btn--primary" type="submit" name="step" value="run" data-confirm="<?= e(__('Jetzt {n} Einträge importieren? Vorhandene werden vorher gesichert.', ['n' => $result['new'] + $result['updated']])) ?>"><?= e(__('Importieren')) ?></button><?php endif; ?>
    <a class="adm-btn adm-btn--ghost" href="<?= e(url($base)) ?>"><?= e(__('Abbrechen')) ?></a></p>
</form>
<?php endif; ?>
