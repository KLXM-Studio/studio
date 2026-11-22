<?php
/**
 * Netzwerk → Website-Vorlagen → eine Vorlage (Admin\BlueprintsController::show): Angaben und Vorschaubild, Schnelleinrichtung
 * (Schritte, Platzhalter), Demo-Einträge kuratieren, Versionen mit Änderungsprotokoll, neue Website, Updates ausrollen.
 * @var array $b  @var ?array $manifest  @var array<string, string> $sites  @var list<array> $affected  @var array $candidates  @var ?array $created
 */
use Core\Blueprint\QuickConfig;

$base = url('/admin/network/vorlagen/' . $b['name']);
$m = $manifest ?? [];
$counts = (array) ($m['counts'] ?? []);
$demoLabel = ['anonym' => __('wenige, anonymisiert'), 'none' => __('keine'), 'full' => __('alle, anonymisiert')];
$ph = array_values(array_map(fn($k, $p) => ['key' => $k] + (array) $p, array_keys((array) $b['placeholders']), (array) $b['placeholders']));
$ph[] = ['key' => '', 'label' => '', 'default' => '', 'step' => 'org'];
$pick = (array) $b['pick'];
?>
<header class="adm-head">
  <div><p class="adm-eyebrow"><?= \Core\Network\Area::crumb() ?> · <a href="<?= e(url('/admin/network/vorlagen')) ?>"><?= e(__('Website-Vorlagen')) ?></a></p>
    <h1><?= e($b['title']) ?></h1>
    <p class="adm-muted"><?= e($b['description'] !== '' ? $b['description'] : __('Vorlage aus „{site}“.', ['site' => $sites[$b['source']] ?? $b['source']])) ?></p></div>
  <div class="adm-head__actions"><a class="adm-btn adm-btn--primary" href="#neu"><?= icon('globe') ?> <?= e(__('Neue Website aus Vorlage')) ?></a></div>
</header>

<?php if ($created): ?>
<div class="adm-inline-box bp-created" role="status">
  <strong><?= e(__('Website „{site}“ angelegt', ['site' => $created['key']])) ?></strong>
  <?php if ($created['token'] !== ''): ?><p><?= e(__('Erstes Konto über /admin/setup mit dem Setup-Token:')) ?> <code><?= e($created['token']) ?></code></p><?php endif; ?>
  <details><summary class="adm-link"><?= e(__('Protokoll')) ?></summary><pre class="net-cli"><?= e(implode("\n", $created['output'])) ?></pre></details>
</div>
<?php endif; ?>

<ul class="net-strip" role="list">
  <li class="net-strip__item"><strong><?= e((string) ($b['latest'] ?? '–')) ?></strong> <span><?= e(__('Version')) ?></span></li>
  <li class="net-strip__item"><strong><?= (int) ($counts['pages'] ?? 0) ?></strong> <span><?= e(__('Seiten')) ?></span></li>
  <li class="net-strip__item"><strong><?= (int) ($counts['tables'] ?? 0) ?></strong> <span><?= e(__('Tabellen')) ?></span></li>
  <li class="net-strip__item"><strong><?= array_sum((array) ($m['demo_counts'] ?? [])) ?></strong> <span><?= e(__('Demo-Einträge')) ?></span></li>
  <li class="net-strip__item"><strong><?= count((array) ($m['network'] ?? [])) ?></strong> <span><?= e(__('Netzwerk-Tabellen')) ?></span></li>
  <li class="net-strip__item"><strong><?= count($affected) ?></strong> <span><?= e(__('Websites')) ?></span></li>
</ul>

<div class="bp-cols">
<section class="adm-card" id="neu" aria-labelledby="bp-site-h">
  <h2 id="bp-site-h"><?= icon('globe') ?> <?= e(__('Neue Website aus Vorlage')) ?></h2>
  <p class="adm-muted"><?= e(__('Kit {kit}, danach Schnelleinrichtung beim ersten Anmelden. Netzwerk-Tabellen werden per Einladung und Beitritt verbunden.', ['kit' => (string) ($m['kit'] ?? '–')])) ?></p>
  <form method="post" action="<?= e($base . '/site') ?>" class="bp-form" novalidate><?= csrf_field() ?>
    <div class="f f--half"><label for="ns-key"><?= e(__('Kurzname')) ?> <span class="req">*</span></label><input id="ns-key" name="key" required pattern="[a-z][a-z0-9\-]{1,31}" maxlength="32" autocomplete="off" placeholder="lv-hessen"></div>
    <div class="f f--half"><label for="ns-hosts"><?= e(__('Domains')) ?> <span class="req">*</span></label><input id="ns-hosts" name="hosts" required autocomplete="off" placeholder="hessen.example.org"></div>
    <div class="f f--half"><label for="ns-name"><?= e(__('Name der Organisation')) ?></label><input id="ns-name" name="org_name" maxlength="120"></div>
    <div class="f f--half"><label for="ns-short"><?= e(__('Kurzname der Organisation')) ?></label><input id="ns-short" name="short" maxlength="40"></div>
    <div class="f f--half"><label for="ns-region"><?= e(__('Region')) ?></label><input id="ns-region" name="region" list="ns-regions" maxlength="120"><datalist id="ns-regions"><?php foreach (array_keys(QuickConfig::REGIONS) as $rg): ?><option value="<?= e($rg) ?>"></option><?php endforeach; ?></datalist></div>
    <div class="f f--half"><label for="ns-color"><?= e(__('Farbe')) ?></label><input id="ns-color" name="color" type="color" value="#0f766e"></div>
    <label class="qc-check"><input type="checkbox" name="use_color" value="1"> <span><?= e(__('Farbe übernehmen (sonst Farbe der Vorlage)')) ?></span></label>
    <div class="f f--half"><label for="ns-version"><?= e(__('Version')) ?></label><select id="ns-version" name="version"><?php foreach ($b['versions'] as $v): ?><option value="<?= e($v['version']) ?>"><?= e($v['version']) ?></option><?php endforeach; ?></select></div>
    <button class="adm-btn adm-btn--primary" type="submit"<?= $b['latest'] ? '' : ' disabled' ?>><?= e(__('Website anlegen')) ?></button>
  </form>
</section>

<section class="adm-card" id="versionen" aria-labelledby="bp-ver-h">
  <h2 id="bp-ver-h"><?= icon('clock-counter-clockwise') ?> <?= e(__('Versionen')) ?></h2>
  <ol class="bp-versions" reversed>
    <?php foreach ($b['versions'] as $v): ?>
    <li><strong><?= e($v['version']) ?></strong> <span class="adm-muted"><?= e(\Core\Format::admin()->date((int) strtotime((string) ($v['date'] ?? 'now')))) ?><?= !empty($v['by']) ? ' · ' . e((string) $v['by']) : '' ?></span><br><?= e((string) ($v['note'] ?? '')) ?></li>
    <?php endforeach; ?>
  </ol>
  <form method="post" action="<?= e($base . '/version') ?>" class="bp-form" novalidate><?= csrf_field() ?>
    <div class="f f--half"><label for="bv-src"><?= e(__('Aus Website')) ?></label><select id="bv-src" name="source"><?php foreach ($sites as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $b['source'] ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="f f--half"><label for="bv-note"><?= e(__('Was hat sich geändert?')) ?> <span class="req">*</span></label><input id="bv-note" name="note" required maxlength="300"></div>
    <button class="adm-btn" type="submit"><?= e(__('Neue Version aus Website')) ?></button>
  </form>
</section>
</div>

<section class="adm-card" id="updates" aria-labelledby="bp-up-h">
  <h2 id="bp-up-h"><?= icon('arrows-clockwise') ?> <?= e(__('Websites aus dieser Vorlage')) ?></h2>
  <p class="adm-muted"><?= e(__('Neue Versionen gehen als Vorschlag an die Websites – wie Kit-Updates: nur Struktur (neue Tabellen, Felder, Seiten als Entwurf, Rollen), nie Inhalte. Übernommen wird in der Schnelleinrichtung der Website.')) ?></p>
  <?php if (!$affected): ?><p><?= e(__('Noch keine Website aus dieser Vorlage.')) ?></p><?php else: ?>
  <form method="post" action="<?= e($base . '/rollout') ?>" novalidate><?= csrf_field() ?>
    <table class="adm-table bp-table"><caption class="adm-sr"><?= e(__('Websites aus dieser Vorlage')) ?></caption>
      <thead><tr><th scope="col"><span class="adm-sr"><?= e(__('Auswahl')) ?></span></th><th scope="col"><?= e(__('Website')) ?></th><th scope="col"><?= e(__('Version')) ?></th><th scope="col"><?= e(__('Neu in {v}', ['v' => (string) $b['latest']])) ?></th></tr></thead>
      <tbody><?php foreach ($affected as $a): $sum = $a['diff'] ? \Core\Blueprint\Diff::summary($a['diff']) : []; $cur = $a['version'] === $b['latest']; ?>
        <tr><td><input type="checkbox" name="sites[]" value="<?= e($a['key']) ?>" id="bp-s-<?= e($a['key']) ?>"<?= $cur ? ' disabled' : '' ?>></td>
          <td><label for="bp-s-<?= e($a['key']) ?>"><?= e($sites[$a['key']] ?? $a['key']) ?></label></td>
          <td><?= e($a['version'] ?: '–') ?><?= $a['proposal'] ? ' · <span class="net-tag">' . e(__('Vorschlag {v} offen', ['v' => $a['proposal']])) . '</span>' : '' ?></td>
          <td><?= $cur ? e(__('aktuell')) : ($sum ? e(implode(' · ', $sum)) : e(__('keine Strukturänderungen'))) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
    <button class="adm-btn" type="submit"><?= e(__('Als Vorschlag ausrollen')) ?></button>
  </form>
  <?php endif; ?>
</section>

<section class="adm-card" id="angaben" aria-labelledby="bp-meta-h">
  <h2 id="bp-meta-h"><?= icon('gear-six') ?> <?= e(__('Angaben und Schnelleinrichtung')) ?></h2>
  <form method="post" action="<?= e($base . '/meta') ?>" enctype="multipart/form-data" class="bp-form" novalidate><?= csrf_field() ?>
    <div class="f f--half"><label for="bm-title"><?= e(__('Name')) ?></label><input id="bm-title" name="title" value="<?= e($b['title']) ?>" maxlength="120"></div>
    <div class="f f--half"><label for="bm-prev"><?= e(__('Vorschaubild')) ?></label><input id="bm-prev" name="preview" type="file" accept="image/png,image/jpeg,image/webp"></div>
    <div class="f"><label for="bm-desc"><?= e(__('Beschreibung')) ?></label><textarea id="bm-desc" name="description" rows="2" maxlength="500"><?= e($b['description']) ?></textarea></div>
    <div class="f f--half"><label for="bm-demo"><?= e(__('Demoinhalte')) ?></label><select id="bm-demo" name="demo"><?php foreach ($demoLabel as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $b['demo'] ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="f f--half"><label for="bm-per"><?= e(__('Demo-Einträge je Tabelle')) ?></label><input id="bm-per" name="per_table" type="number" min="1" max="20" value="<?= (int) $b['per_table'] ?>"></div>
    <fieldset class="qc-checks bp-steps"><legend><?= e(__('Schritte der Schnelleinrichtung')) ?></legend>
      <?php foreach (QuickConfig::STEPS as $s): ?><label class="qc-check"><input type="checkbox" name="steps[]" value="<?= e($s) ?>"<?= in_array($s, (array) $b['steps'], true) ? ' checked' : '' ?>> <span><?= e(QuickConfig::label($s)) ?></span></label><?php endforeach; ?>
    </fieldset>
    <fieldset class="bp-ph"><legend><?= e(__('Eigene Platzhalter')) ?></legend>
      <p class="f-help"><?= e(__('Zusätzlich zu Name, Anschrift, Region … – im Text als {{schluessel}}, gefüllt in der Schnelleinrichtung.')) ?></p>
      <?php foreach ($ph as $i => $p): ?>
      <div class="bp-ph__row">
        <div class="f"><label for="bp-k-<?= $i ?>"><?= e(__('Schlüssel')) ?></label><input id="bp-k-<?= $i ?>" name="ph[<?= $i ?>][key]" value="<?= e((string) $p['key']) ?>" pattern="[a-z][a-z0-9_]*(\.[a-z0-9_]+)+" placeholder="verband.name"></div>
        <div class="f"><label for="bp-l-<?= $i ?>"><?= e(__('Bezeichnung')) ?></label><input id="bp-l-<?= $i ?>" name="ph[<?= $i ?>][label]" value="<?= e((string) ($p['label'] ?? '')) ?>"></div>
        <div class="f"><label for="bp-d-<?= $i ?>"><?= e(__('Vorgabe')) ?></label><input id="bp-d-<?= $i ?>" name="ph[<?= $i ?>][default]" value="<?= e((string) ($p['default'] ?? '')) ?>"></div>
        <div class="f"><label for="bp-s-<?= $i ?>"><?= e(__('Schritt')) ?></label><select id="bp-s-<?= $i ?>" name="ph[<?= $i ?>][step]"><?php foreach (['org', 'region', 'legal'] as $s): ?><option value="<?= e($s) ?>"<?= ($p['step'] ?? 'org') === $s ? ' selected' : '' ?>><?= e(QuickConfig::label($s)) ?></option><?php endforeach; ?></select></div>
      </div>
      <?php endforeach; ?>
    </fieldset>
    <button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Speichern')) ?></button>
  </form>
</section>

<section class="adm-card" id="demo" aria-labelledby="bp-demo-h">
  <h2 id="bp-demo-h"><?= icon('sparkle') ?> <?= e(__('Demo-Einträge kuratieren')) ?></h2>
  <p class="adm-muted"><?= e(__('Welche Einträge der Quelle als Beispiel mitgehen – immer anonymisiert (Platzhalter-Personen, Termine relativ zu heute, Bilder nur frei mit Urheber). Ohne Auswahl: die ersten je Tabelle.')) ?></p>
  <?php if (!$candidates): ?><p><?= e(__('Die Quelle hat keine Inhaltstabellen.')) ?></p><?php else: ?>
  <form method="post" action="<?= e($base . '/demo') ?>" novalidate><?= csrf_field() ?>
    <div class="bp-demo">
    <?php foreach ($candidates as $h => $c): ?>
      <fieldset class="qc-checks"><legend><?= e($c['name']) ?> <span class="adm-muted"><?= e(\Core\Data\Kinds::label($c['kind'])) ?></span></legend>
        <?php foreach ($c['rows'] as $row): ?><label class="qc-check"><input type="checkbox" name="pick[<?= e($h) ?>][]" value="<?= (int) $row['id'] ?>"<?= in_array($row['id'], (array) ($pick[$h] ?? []), true) ? ' checked' : '' ?>> <span><?= e($row['title'] !== '' ? $row['title'] : '#' . $row['id']) ?><?= $row['status'] !== 'published' ? ' <span class="adm-muted">(' . e(__('Entwurf')) . ')</span>' : '' ?></span></label><?php endforeach; ?>
      </fieldset>
    <?php endforeach; ?>
    </div>
    <div class="adm-row"><button class="adm-btn" type="submit"><?= e(__('Auswahl speichern')) ?></button>
      <button class="adm-btn adm-btn--ghost" type="submit" name="auto" value="1"><?= e(__('Automatisch wählen')) ?></button></div>
  </form>
  <?php endif; ?>
</section>
