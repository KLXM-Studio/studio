<?php
/**
 * Grundeinstellungen → Integrität (Core\Integrity, IntegrityController): letzte Prüfung von Dateien und Datenbank mit Funden,
 * Jetzt prüfen, Als bekannt übernehmen, E-Mail-Hinweis.
 * Netzwerk (Core\Network\Area): im Netzwerk-Bereich nur „Dateien der Installation“ ($net), auf der Website nur die Datenbank.
 * @var ?array $files  @var bool $canFiles  @var ?array $db  @var bool $mail  @var bool $net  @var bool $showDb  @var list<string> $systemTo
 */
$net ??= false;
$showDb ??= true;
$systemTo ??= [];
$f = \Core\Format::admin();
// $scope: 'site'|'network' – Kennzeichen „Gilt für“ (nur mit mehreren Websites)
$section = function (string $id, string $title, string $help, ?array $res, string $accept, string $scope = '') use ($f): void {
    $err = $res ? count(array_filter($res['findings'], fn($x) => $x['level'] === 'error')) : 0;
    $warn = $res ? count(array_filter($res['findings'], fn($x) => $x['level'] === 'warning')) : 0;
    ?>
<section class="adm-card" aria-labelledby="ig-<?= e($id) ?>">
  <h2 id="ig-<?= e($id) ?>"><?= icon($err ? 'warning' : 'shield-check') ?> <?= e($title) ?>
    <?php if ($res): ?><span class="adm-badge<?= $err ? ' adm-badge--warn' : ($warn ? ' adm-badge--muted' : '') ?>"><?= e($err ? __('{n} Fehler', ['n' => $err]) : ($warn ? __('{n} Hinweise', ['n' => $warn]) : __('nichts Unerwartetes'))) ?></span><?php endif; ?></h2>
  <p class="adm-muted"><?php if ($scope !== '' && \Core\Sites::multi()): ?><span class="set-scope<?= $scope === 'network' ? ' set-scope--net' : '' ?>"><?= e($scope === 'network' ? __('Gilt für: alle Websites') : __('Gilt für: diese Website')) ?></span> <?php endif; ?><?= e($help) ?></p>
  <?php if (!$res): ?>
  <p><?= e(__('Noch nicht geprüft.')) ?></p>
  <?php else: ?>
  <p class="adm-muted"><?= e(__('Letzte Prüfung: {time}', ['time' => $f->datetime((int) $res['time'])])) ?> · <?= e($res['baseline'] ? __('Bestätigter Stand vom {time}', ['time' => $f->datetime((int) $res['baseline'])]) : __('kein bestätigter Stand')) ?></p>
  <?php if ($res['findings']): ?>
  <ul class="ig-list" role="list">
    <?php foreach (array_slice($res['findings'], 0, 200) as $x): ?>
    <li class="ig-item ig-item--<?= e($x['level']) ?>"><span class="adm-badge<?= $x['level'] === 'error' ? ' adm-badge--warn' : ' adm-badge--muted' ?>"><?= e(['error' => __('Fehler'), 'warning' => __('Hinweis')][$x['level']] ?? __('Info')) ?></span> <?= e($x['text']) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php if (count($res['findings']) > 200): ?><p class="adm-muted"><?= e(__('… und {n} weitere (Kommandozeile: integrity:check).', ['n' => count($res['findings']) - 200])) ?></p><?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/admin/system/integritaet/accept/' . $accept)) ?>" class="adm-row"
    data-confirm="<?= e(__('Den aktuellen Stand als bekannt übernehmen? Nur, wenn alle Funde gewollt sind (Update, neue Erweiterung, neues Konto).')) ?>"><?= csrf_field() ?>
    <button class="adm-btn adm-btn--small" type="submit"><?= e(__('Als bekannt übernehmen')) ?></button>
  </form>
</section>
<?php
};
?>
<header class="adm-head">
  <div><p class="adm-eyebrow"><?= \Core\Network\Area::crumb() ?></p><h1><?= e($net ? __('Integrität der Dateien') : __('Integrität')) ?></h1>
    <p class="adm-muted"><?= e($net ? __('Überwacht die Dateien der ganzen Installation: Code, .htaccess und Konfiguration, ausführbare Dateien in Medien- und Datenordnern. Die Datenbank prüft jede Website in ihren Grundeinstellungen. Die Prüfung läuft stündlich (Geplante Aufgaben).')
      : (\Core\Network\Area::active() ? __('Überwacht die Datenbank dieser Website: Trigger, neue Administrator-Konten, API-Schlüssel und Skript-Code in Inhalten. Die Prüfung läuft stündlich (Geplante Aufgaben).')
      : __('Überwacht, was Angreifer typischerweise verändern: Code, .htaccess und Konfiguration, ausführbare Dateien in Medien- und Datenordnern, Trigger in der Datenbank, neue Administrator-Konten und Skript-Code in Inhalten. Die Prüfung läuft stündlich (Geplante Aufgaben).'))) ?></p></div>
  <form method="post" action="<?= e(url('/admin/system/integritaet/check')) ?>"><?= csrf_field() ?><?php if (\Core\Network\Area::active()): ?><input type="hidden" name="scope" value="<?= $net ? 'files' : 'db' ?>"><?php endif; ?><button class="adm-btn adm-btn--primary" type="submit"><?= icon('shield-check') ?> <?= e(__('Jetzt prüfen')) ?></button></form>
</header>

<div class="adm-tabs-form--side us-shell" data-secnav>
<?php $current = 'integritaet'; include __DIR__ . '/_sidenav.php'; ?>
  <div class="us-main">
<?php
if ($canFiles) $section('files', __('Dateien der Installation'), __('Prüfsummen von Code, Autoloader, Konfiguration und jeder .htaccess/.user.ini – dazu ausführbare Dateien in public/ und storage/ sowie PHP-Code in neuen Mediendateien. Nach einem Update oder Deploy den Stand als bekannt übernehmen.'), $files, 'files', 'network');
if ($showDb) $section('db', __('Datenbank dieser Website'), __('Trigger und Views, neue Tabellen, neue Konten mit allen Rechten, neue API-Schlüssel und Skript-Code in Seiten oder Einstellungen.'), $db, 'db', 'site');
?>
<section class="adm-card" aria-labelledby="ig-mail">
  <h2 id="ig-mail"><?= icon('envelope-simple') ?> <?= e(__('Hinweis per E-Mail')) ?></h2>
  <?php if ($net): // Netzwerk: neue Fehler in den Dateien gehen an die Systemmeldungen (Core\Mail\Alerts, Kategorie „Integrität“) ?>
  <p><?= e(__('Neue Fehler meldet die Prüfung an: {to}', ['to' => $systemTo ? implode(', ', $systemTo) : __('– niemand (bitte unter „Systemmeldungen“ festlegen)')])) ?></p>
  <p><a class="adm-btn adm-btn--small" href="<?= e(url('/admin/network/meldungen')) ?>"><?= e(__('Systemmeldungen einstellen')) ?></a></p>
  <?php else: ?>
  <form method="post" action="<?= e(url('/admin/system/integritaet/settings')) ?>" class="adm-row"><?= csrf_field() ?>
    <label class="f-check"><input type="checkbox" name="mail" value="1"<?= $mail ? ' checked' : '' ?>><span><?= e(__('Neue Fehler per E-Mail melden (je Fund einmal)')) ?></span></label>
    <button class="adm-btn adm-btn--small" type="submit"><?= e(__('Speichern')) ?></button>
  </form>
  <p class="adm-muted"><?= e(__('Empfänger (Systemmeldungen, Kategorie „Integrität“): {to}', ['to' => implode(', ', \Core\Integrity::recipients('db')) ?: __('– niemand')])) ?>
    <?php if (!\Core\Network\Area::active() && \Core\Permissions::installationAdmin()): ?> · <a href="<?= e(url('/admin/system/meldungen')) ?>"><?= e(__('Systemmeldungen einstellen')) ?></a><?php endif; ?></p>
  <?php endif; ?>
</section>
  </div>
</div>
