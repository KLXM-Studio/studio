<?php
/**
 * Netzwerk → Website-Vorlagen (Admin\BlueprintsController::index, Core\Blueprint\Library): Liste mit Zeilenmenü (Core\RowMenu),
 * „Aus Website erstellen“ und „Website kopieren“. Stile resources/css/blueprint.css.
 * @var list<array> $list  @var array<string, string> $sites  @var ?array $created
 */
use Core\RowMenu;

$demoLabel = ['anonym' => __('wenige, anonymisiert'), 'none' => __('keine'), 'full' => __('alle, anonymisiert')];
?>
<header class="adm-head">
  <div><p class="adm-eyebrow"><?= \Core\Network\Area::crumb() ?></p><h1><?= e(__('Website-Vorlagen')) ?></h1>
    <p class="adm-muted"><?= e(__('Blaupausen für neue Websites: Kit, Design, Seitenbaum, Tabellen, Rollen und Beispiel-Inhalte – ohne Konten, Geheimnisse und personenbezogene Daten. Neue Websites aus einer Vorlage starten mit der Schnelleinrichtung.')) ?></p></div>
  <div class="adm-head__actions"><a class="adm-btn adm-btn--primary" href="#neu"><?= icon('plus') ?> <?= e(__('Aus Website erstellen')) ?></a></div>
</header>

<?php if ($created): ?>
<div class="adm-inline-box bp-created" role="status">
  <strong><?= e(__('Website „{site}“ angelegt', ['site' => $created['key']])) ?></strong>
  <?php if ($created['token'] !== ''): ?><p><?= e(__('Erstes Konto über /admin/setup mit dem Setup-Token:')) ?> <code><?= e($created['token']) ?></code></p><?php endif; ?>
  <details><summary class="adm-link"><?= e(__('Protokoll')) ?></summary><pre class="net-cli"><?= e(implode("\n", $created['output'])) ?></pre></details>
</div>
<?php endif; ?>

<section class="adm-card" aria-labelledby="bp-list-h">
  <h2 id="bp-list-h"><?= e(__('Vorlagen')) ?> <span class="adm-badge adm-badge--muted"><?= count($list) ?></span></h2>
  <?php if (!$list): ?>
  <p class="adm-muted"><?= e(__('Noch keine Vorlagen. Erstellen Sie die erste aus einer bestehenden Website – zum Beispiel einem Landesverein mit Beispiel-Inhalten.')) ?></p>
  <?php else: ?>
  <ul class="bp-list" role="list">
    <?php foreach ($list as $b): $u = url('/admin/network/vorlagen/' . $b['name']); $mid = 'bp-m-' . $b['name']; ?>
    <li class="bp-row"<?= RowMenu::host($mid) ?>>
      <?php if (\Core\Blueprint\Library::previewFile($b['name'])): ?><img class="bp-thumb" src="<?= e($u . '/vorschau') ?>" alt="" width="96" height="60" loading="lazy"><?php else: ?><span class="bp-thumb bp-thumb--empty" aria-hidden="true"><?= icon('copy') ?></span><?php endif; ?>
      <div class="bp-row__main">
        <h3><a href="<?= e($u) ?>"><?= e($b['title']) ?></a> <span class="net-key"><?= e($b['name']) ?></span></h3>
        <?php if ($b['description'] !== ''): ?><p class="adm-muted"><?= e($b['description']) ?></p><?php endif; ?>
        <p class="bp-meta"><span class="net-tag"><?= e(__('Version {v}', ['v' => (string) ($b['latest'] ?? '–')])) ?></span>
          <span class="net-tag"><?= e(__('Quelle: {site}', ['site' => $sites[$b['source']] ?? $b['source']])) ?></span>
          <span class="net-tag"><?= e(__('Demo: {mode}', ['mode' => $demoLabel[$b['demo']] ?? $b['demo']])) ?></span></p>
      </div>
      <?= RowMenu::render($mid, (string) $b['title'], [
          ['key' => 'open', 'label' => __('Öffnen'), 'href' => $u],
          ['key' => 'child', 'label' => __('Neue Website aus Vorlage'), 'href' => $u . '#neu', 'icon' => 'globe'],
          ['key' => 'versions', 'label' => __('Neue Version aus Website'), 'href' => $u . '#versionen'],
          ['key' => 'export', 'label' => __('Updates ausrollen'), 'href' => $u . '#updates', 'icon' => 'arrows-clockwise'],
          ['key' => 'delete', 'label' => __('Löschen'), 'post' => $u . '/delete', 'confirm' => __('Vorlage „{name}“ mit allen Versionen löschen?', ['name' => $b['title']])],
      ]) ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<section class="adm-card" id="neu" aria-labelledby="bp-new-h">
  <h2 id="bp-new-h"><?= icon('plus') ?> <?= e(__('Aus Website erstellen')) ?></h2>
  <p class="adm-muted"><?= e(__('Übernimmt die Struktur der Website. Grundeinstellungen nur nach Liste, nie Passwörter, Konten, Abonnenten, Anfragen, Aufgaben oder Statistik. Angaben der Organisation werden Platzhalter.')) ?></p>
  <form method="post" action="<?= e(url('/admin/network/vorlagen')) ?>" class="bp-form" novalidate><?= csrf_field() ?>
    <div class="f f--half"><label for="bp-source"><?= e(__('Quelle')) ?> <span class="req">*</span></label><select id="bp-source" name="source" required>
      <?php foreach ($sites as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l . ' (' . $k . ')') ?></option><?php endforeach; ?></select></div>
    <div class="f f--half"><label for="bp-name"><?= e(__('Kurzname')) ?> <span class="req">*</span></label><input id="bp-name" name="name" required pattern="[a-z][a-z0-9\-]{1,47}" maxlength="48" placeholder="landesverein" autocomplete="off" aria-describedby="bp-name-h"><p class="f-help" id="bp-name-h"><?= e(__('a–z, 0–9 und Bindestrich')) ?></p></div>
    <div class="f f--half"><label for="bp-title"><?= e(__('Name')) ?></label><input id="bp-title" name="title" maxlength="120" placeholder="<?= e(__('Landesverein')) ?>"></div>
    <div class="f f--half"><label for="bp-demo"><?= e(__('Demoinhalte')) ?></label><select id="bp-demo" name="demo">
      <?php foreach ($demoLabel as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === 'anonym' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="f"><label for="bp-desc"><?= e(__('Beschreibung')) ?></label><textarea id="bp-desc" name="description" rows="2" maxlength="500"></textarea></div>
    <div class="f f--half"><label for="bp-per"><?= e(__('Demo-Einträge je Tabelle')) ?></label><input id="bp-per" name="per_table" type="number" min="1" max="20" value="3"></div>
    <div class="f f--half"><label for="bp-note"><?= e(__('Änderungsprotokoll')) ?></label><input id="bp-note" name="note" maxlength="300" placeholder="<?= e(__('Erste Version')) ?>"></div>
    <button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Vorlage erstellen')) ?></button>
  </form>
</section>

<section class="adm-card" id="kopieren" aria-labelledby="bp-copy-h">
  <h2 id="bp-copy-h"><?= icon('copy') ?> <?= e(__('Website kopieren')) ?></h2>
  <p class="adm-muted"><?= e(__('Eine ganze Website als neue Website – für eine Testkopie, einen neuen Verein auf Basis eines bestehenden oder einen Archiv-Stand. Neue Schlüssel; nie kopiert werden Abonnenten, Anfragen, Formulareingänge, Verläufe, Statistik und Tokens. An geteilten Tabellen ist die Kopie zunächst nicht beteiligt.')) ?></p>
  <form method="post" action="<?= e(url('/admin/network/vorlagen/kopieren')) ?>" class="bp-form" novalidate><?= csrf_field() ?>
    <div class="f f--half"><label for="cp-src"><?= e(__('Quelle')) ?> <span class="req">*</span></label><select id="cp-src" name="source" required>
      <?php foreach ($sites as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l . ' (' . $k . ')') ?></option><?php endforeach; ?></select></div>
    <div class="f f--half"><label for="cp-key"><?= e(__('Kurzname der Kopie')) ?> <span class="req">*</span></label><input id="cp-key" name="key" required pattern="[a-z][a-z0-9\-]{1,31}" maxlength="32" autocomplete="off" placeholder="verein-test"></div>
    <div class="f"><label for="cp-hosts"><?= e(__('Domains (mit Komma getrennt)')) ?> <span class="req">*</span></label><input id="cp-hosts" name="hosts" required autocomplete="off" placeholder="test.example.org"></div>
    <div class="f f--half"><label for="cp-content"><?= e(__('Inhalt')) ?></label><select id="cp-content" name="content">
      <option value="full"><?= e(__('Vollständig (Seiten, Einträge, Medien, Einstellungen)')) ?></option><option value="structure"><?= e(__('Nur Struktur')) ?></option><option value="demo"><?= e(__('Struktur mit Beispiel-Einträgen')) ?></option></select></div>
    <div class="f f--half"><label for="cp-media"><?= e(__('Medien')) ?></label><select id="cp-media" name="media">
      <option value="copy"><?= e(__('Kopieren')) ?></option><option value="share"><?= e(__('Dieselben Dateien teilen')) ?></option><option value="none"><?= e(__('Weglassen')) ?></option></select></div>
    <div class="f f--half"><label for="cp-users"><?= e(__('Konten')) ?></label><select id="cp-users" name="users"><option value="none"><?= e(__('Keine')) ?></option><option value="copy"><?= e(__('Übernehmen (ohne Passkeys)')) ?></option></select></div>
    <div class="f f--half"><label for="cp-env"><?= e(__('Umgebung')) ?></label><select id="cp-env" name="environment"><option value=""><?= e(__('wie die Quelle')) ?></option><option value="staging"><?= e(__('Testumgebung (staging)')) ?></option></select></div>
    <fieldset class="qc-checks"><legend><?= e(__('Optionen')) ?></legend>
      <label class="qc-check"><input type="checkbox" name="anonymize" value="1"> <span><?= e(__('Personen und Kontakte anonymisieren')) ?></span></label>
      <label class="qc-check"><input type="checkbox" name="revisions" value="1"> <span><?= e(__('Versionen von Seiten und Einträgen behalten')) ?></span></label>
      <label class="qc-check"><input type="checkbox" name="quick_config" value="1"> <span><?= e(__('Danach Schnelleinrichtung (Wartungsmodus bis zum Abschluss)')) ?></span></label>
    </fieldset>
    <button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Website kopieren')) ?></button>
  </form>
  <details class="net-clihint"><summary class="adm-link"><?= e(__('Auf der Kommandozeile')) ?></summary>
    <pre class="net-cli"><code>php bin/console site:blueprint &lt;key&gt; --out=vorlage.zip [--demo=anonym|none|full]
php bin/console site:create &lt;key&gt; &lt;domain&gt; --blueprint=&lt;name|paket&gt; [--name=… --region=…]
php bin/console site:copy &lt;quelle&gt; &lt;neu&gt; &lt;domain&gt; [--content=full|structure|demo] [--media=copy|share|none]</code></pre></details>
</section>
