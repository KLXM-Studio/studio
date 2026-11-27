<?php
/**
 * Einrichtung › Datenmodell: alle Tabellen und Eingänge auf einen Blick – Art, Felder, Einträge, Detailseite, Verwendung;
 * Auswahllisten eingerückt unter der Tabelle, die sie nutzt. Einrichten je Tabelle über ihre Bereichsnavigation (Core\Data\AdminNav).
 * @var array $tables  @var array $presets
 */
use Core\Data\OptionSource;
use Core\Data\Placement;
use Core\Data\Purpose;
use Core\Data\Tables;

$content = array_values(array_filter($tables, fn($t) => !Tables::isInbox($t)));
$inboxes = array_values(array_filter($tables, fn($t) => Tables::isInbox($t)));
// Auswahllisten unter ihrer einzigen Haupttabelle, von mehreren genutzte als eigene Gruppe (wie in der Navigation)
$byH = array_column($content, null, 'handle');
$childOf = $shared = $users = [];
foreach ($content as $t) {
    $users[$t['handle']] = array_values(array_unique(array_map(fn($x) => $x[0]['handle'], OptionSource::usedBy($t))));
    $u = $users[$t['handle']];
    if (count($u) === 1 && isset($byH[$u[0]]) && $u[0] !== $t['handle']) $childOf[$u[0]][] = $t;
    elseif ($u) $shared[] = $t;
}
$skip = array_flip(array_merge(array_column(array_merge(...array_values($childOf ?: [[]])), 'handle'), array_column($shared, 'handle')));
$rows = [];
foreach ($content as $t) {
    if (isset($skip[$t['handle']])) continue;
    $rows[] = [$t, false];
    foreach ($childOf[$t['handle']] ?? [] as $c) $rows[] = [$c, true];
}
$nameOf = fn(string $h) => $byH[$h]['name'] ?? $h;
$row = function (array $t, bool $child) use ($users, $nameOf): string {
    $inbox = Tables::isInbox($t);
    $route = trim((string) ($t['settings']['route'] ?? ''), '/');
    if ($users[$t['handle']] ?? []) $used = __('Liste für {tables}', ['tables' => implode(', ', array_map($nameOf, $users[$t['handle']]))]);
    else {
        $pages = array_filter(Placement::usages($t), fn($u) => !$u['template']);
        $used = $pages ? (count($pages) === 1 ? __('1 Seite') : __('{n} Seiten', ['n' => count($pages)])) : '–';
    }
    $n = (int) $t['count'];
    ob_start(); ?>
    <tr class="<?= $child ? 'dm-row--child' : '' ?>">
      <td><a class="dm-name" href="<?= e(url('/admin/data/' . $t['handle'] . '/schema')) ?>"><span class="dt-icon dm-ico" aria-hidden="true"><?= icon($t['icon'] ?: 'table') ?></span><span><?= e($t['name']) ?><small><?= e($t['handle']) ?></small></span></a></td>
      <td><?= e($inbox ? __('Eingang') : Purpose::label(Purpose::of($t))) ?></td>
      <td class="dm-num"><?= count($t['fields']) ?></td>
      <td class="dm-num"><?= $n ?></td>
      <td><?= $route !== '' ? '<code>/' . e($route) . '/…</code>' : '<span class="adm-muted">–</span>' ?></td>
      <td><?= e($used) ?></td>
    </tr>
    <?php return (string) ob_get_clean();
};
$head = '<thead><tr><th scope="col">' . e(__('Tabelle')) . '</th><th scope="col">' . e(__('Art')) . '</th><th scope="col" class="dm-num">' . e(__('Felder')) . '</th><th scope="col" class="dm-num">' . e(__('Einträge')) . '</th><th scope="col">' . e(__('Detailseite')) . '</th><th scope="col">' . e(__('Verwendet')) . '</th></tr></thead>';
?>
<header class="adm-head dt-head">
  <h1><?= e(__('Datenmodell')) ?></h1>
  <div class="adm-row">
    <?php if (\Core\AI\Assist::available('text') && \Core\Features::on('data.schema')): ?><a class="adm-btn kia-btn" href="<?= e(url('/admin/ai/tabellen')) ?>"><span class="kia-spark" aria-hidden="true"><?= icon('sparkle') ?></span> <?= e(__('Tabelle generieren')) ?></a><?php endif; ?>
    <a class="adm-btn adm-btn--primary" href="<?= e(url('/admin/data/new')) ?>">+ <?= e(__('Neue Tabelle oder Formular')) ?></a>
  </div>
</header>
<p class="adm-muted dm-lead"><?= e(__('Hier richten Sie die Tabellen ein: Felder, Detailseiten, wo sie eingesetzt sind. Die Einträge selbst pflegt die Redaktion unter „Daten“.')) ?></p>

<details class="adm-card dt-howto"<?= $tables ? '' : ' open' ?>>
  <summary>So funktioniert’s – in drei Schritten</summary>
  <p class="adm-muted dt-howto__lead">Eigene Inhaltstypen wie Aktuelles, Team, Produkte oder Termine – ohne Programmieren. Ausgabe auf der Website mit dem Block <b>Datenliste</b>, Detailseiten entstehen automatisch.</p>
  <ol class="dt-steps">
    <li><span class="dt-step">1</span><strong>Tabelle = Bauplan</strong><p>Sie legen fest, <em>welche Angaben</em> ein Eintrag hat – z. B. bei „Aktuelles“: Titel, Datum, Bild, Text, Kategorie. Einmalig, durch die Administration.</p></li>
    <li><span class="dt-step">2</span><strong>Einträge = Inhalte</strong><p>Die Redaktion füllt den Bauplan beliebig oft aus: jeder Beitrag, jede Person, jedes Produkt ist ein Eintrag. Wie ein Formular – ganz ohne Gestaltung.</p></li>
    <li><span class="dt-step">3</span><strong>Ausgabe = Gestaltung</strong><p><b>Übersicht:</b> Block „Datenliste“ auf einer beliebigen Seite. <b>Detailseite:</b> eine Vorlage, die Sie wie jede Seite mit Blöcken gestalten – bei jedem Feld wählen Sie mit <b><?= icon('link', ['label' => 'Kette']) ?></b>, welches Feld des Eintrags dort erscheint (Bild → „Bild“, Überschrift → „Titel“ …). Die Vorlage gilt automatisch für alle Einträge.</p></li>
  </ol>
</details>

<?php if ($rows || $shared || $inboxes): ?>
<div class="adm-card dm-card">
  <div class="dm-scroll">
  <table class="adm-table dm-table">
    <?= $head ?>
    <tbody>
      <?php foreach ($rows as [$t, $child]) echo $row($t, $child); ?>
      <?php if ($shared): ?><tr class="dm-group"><th colspan="6" scope="rowgroup"><?= e(__('Auswahllisten')) ?> <span class="adm-muted">· <?= e(__('von mehreren Tabellen genutzt')) ?></span></th></tr>
      <?php foreach ($shared as $t) echo $row($t, true); endif; ?>
      <?php if ($inboxes): ?><tr class="dm-group"><th colspan="6" scope="rowgroup"><?= e(__('Eingänge')) ?> <span class="adm-muted">· <?= e(__('Formular-Anfragen, gelesen unter „Eingang“')) ?></span></th></tr>
      <?php foreach ($inboxes as $t) echo $row($t, false); endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<section class="adm-card dt-presets">
  <h2><?= $tables ? e(__('Weitere Tabelle aus Vorlage')) : e(__('Mit einer Vorlage starten')) ?></h2>
  <p class="adm-muted"><?= e(__('Vorlagen enthalten sinnvolle Felder – alles lässt sich danach anpassen.')) ?></p>
  <div class="dt-preset-grid">
    <?php foreach ($presets as $key => $p): ?>
    <a class="dt-preset" href="<?= e(url('/admin/data/new?preset=' . $key)) ?>"><span class="dt-icon" aria-hidden="true"><?= icon($p['icon']) ?></span><strong><?= e($p['name']) ?></strong>
      <small><?= e(implode(', ', array_column(array_slice($p['fields'], 0, 4), 'label'))) ?> …</small></a>
    <?php endforeach; ?>
    <?php if (\Core\AI\Assist::available('text')): ?><a class="dt-preset dt-preset--ai" href="<?= e(url('/admin/ai/tabellen')) ?>"><span class="dt-icon" aria-hidden="true"><?= icon('sparkle') ?></span><strong><?= e(__('Mit KI generieren')) ?></strong><small><?= e(__('Beschreiben, was Sie brauchen')) ?></small></a><?php endif; ?>
    <a class="dt-preset dt-preset--empty" href="<?= e(url('/admin/data/new')) ?>"><span class="dt-icon" aria-hidden="true"><?= icon('plus') ?></span><strong><?= e(__('Mit dem Assistenten')) ?></strong><small><?= e(__('Art wählen, Grundeinstellungen, einsetzen')) ?></small></a>
  </div>
</section>
