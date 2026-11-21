<?php
/**
 * Systemmeldungen (Core\Mail\Alerts, AlertsController): wer technische Warnungen bekommt. Ein Formular – 1 Empfänger (Modus,
 * Agentur-Kontakt, Bestimmte Adressen, Admins zusätzlich), 2 Kategorie × Empfänger mit wirksamen Adressen und Testversand
 * (eigene Formulare, die gespeicherte Einstellung gilt). Netzwerk-Bereich und Grundeinstellungen nutzen dieselbe Ansicht.
 * Stile: resources/css/mailoverview.css (.mo-table, .al-*).
 * @var array $cfg  @var bool $net
 */
use Core\Mail\Alerts;

$multi = \Core\Sites::multi();
$list = \Core\Network\SystemMail::configured();
$agencyAcc = Alerts::agencyAccounts('install');
$admins = Alerts::admins('install');
$rows = Alerts::overview();
$ctx = ['agency_accounts' => $agencyAcc, 'list' => $list];
$autoMode = Alerts::mode(['mode' => 'auto'] + $cfg, $ctx);
$adminsLabel = $multi ? __('Alle Netzwerk-Admins') : __('Alle Admins');
$modes = [
    'auto' => [__('Automatisch'), __('Agentur, sobald ein Agentur-Kontakt eingetragen ist oder Agentur-Konten bestehen; sonst die Adressen unten; sonst {admins}. Zurzeit: {now}.', ['admins' => $adminsLabel, 'now' => Alerts::viaText($autoMode, $cfg)])],
    'agency' => [__('Agentur bevorzugt'), __('Warnungen gehen an den Agentur-Kontakt bzw. die Agentur-Konten – die Admins der Websites werden nicht behelligt.')],
    'list' => [__('Bestimmte Adressen'), __('Warnungen gehen an die eingetragenen Adressen.')],
    'admins' => [$adminsLabel, $multi ? __('Bisheriges Verhalten: alle aktiven Netzwerk-Konten; Hinweise zur Datenbank einer Website an deren Administration.') : __('Bisheriges Verhalten: alle Konten mit allen Rechten.')],
];
$routeLabels = ['default' => __('wie oben'), 'agency' => __('Agentur'), 'list' => __('Bestimmte Adressen'), 'admins' => $adminsLabel, 'off' => __('aus')];
?>
<header class="adm-head">
  <div><p class="adm-eyebrow"><?= \Core\Network\Area::crumb() ?></p><h1><?= e(__('Systemmeldungen')) ?></h1>
    <p class="adm-muted"><?= e(__('Wer technische Warnungen bekommt: Integrität, Sicherheit, Sicherung, Geplante Aufgaben, technische Fehler, Updates und Zustellprobleme. Gibt es eine Agentur, sollte sie die Meldungen bekommen – die Redaktion und die Admins der Kunden bleiben unbehelligt.')) ?></p></div>
</header>

<div class="adm-tabs-form--side us-shell" data-secnav>
<?php $current = 'meldungen'; include __DIR__ . '/_sidenav.php'; ?>
  <div class="us-main">
<form method="post" action="<?= e(url('/admin/system/meldungen')) ?>" novalidate>
  <?= csrf_field() ?>
  <section class="adm-card" aria-labelledby="al-who">
    <h2 id="al-who"><?= icon('bell-ringing') ?> <?= e(__('Empfänger')) ?></h2>
    <fieldset class="al-modes">
      <legend class="adm-sr"><?= e(__('Empfänger-Modus')) ?></legend>
      <?php foreach ($modes as $k => [$label, $help]): ?>
      <label class="set-row us-radio"><input type="radio" name="mode" value="<?= e($k) ?>"<?= $cfg['mode'] === $k ? ' checked' : '' ?> aria-describedby="al-m-<?= e($k) ?>">
        <span class="set-row__main"><span class="set-row__label"><?= e($label) ?></span><span class="set-row__sub" id="al-m-<?= e($k) ?>"><?= e($help) ?></span></span></label>
      <?php endforeach; ?>
    </fieldset>

    <h3 class="al-sub"><?= e(__('Agentur – Kontakt für technische Meldungen')) ?></h3>
    <div class="al-grid">
      <div class="f"><label for="al-ag-mail"><?= e(__('E-Mail der Agentur')) ?></label>
        <input id="al-ag-mail" name="agency_email" type="email" autocomplete="off" value="<?= e($cfg['agency_email']) ?>" list="al-ag-suggest" placeholder="technik@agentur.example" aria-describedby="al-ag-help">
        <?php if ($agencyAcc): ?><datalist id="al-ag-suggest"><?php foreach ($agencyAcc as $a): ?><option value="<?= e($a) ?>"><?php endforeach; ?></datalist><?php endif; ?>
      </div>
      <div class="f"><label for="al-ag-name"><?= e(__('Name (optional)')) ?></label>
        <input id="al-ag-name" name="agency_name" type="text" maxlength="80" value="<?= e($cfg['agency_name']) ?>" placeholder="<?= e(__('z. B. Agentur Musterstadt')) ?>"></div>
    </div>
    <p class="f-help" id="al-ag-help"><?= $agencyAcc
        ? e(__('Vorschlag aus dem Agentur-Zugang (OpenID Connect): {list}. Leer: Meldungen gehen an diese Agentur-Konten.', ['list' => implode(', ', $agencyAcc)]))
        : e(__('Ohne Agentur-Zugang (OpenID Connect) gibt es keine Agentur-Konten – tragen Sie hier die Adresse der Agentur ein, damit „Agentur bevorzugt“ greift.')) ?></p>
    <label class="f-check"><input type="checkbox" name="also_admins" value="1"<?= $cfg['also_admins'] ? ' checked' : '' ?>> <span><?= e(__('{admins} zusätzlich informieren (auch bei „Agentur bevorzugt“)', ['admins' => $adminsLabel])) ?></span></label>

    <h3 class="al-sub"><?= e(__('Bestimmte Adressen')) ?></h3>
    <div class="f">
      <label for="al-list"><?= e(__('Adressen')) ?></label>
      <input id="al-list" name="system_to" type="text" autocomplete="off" value="<?= e(implode(', ', $list)) ?>" placeholder="technik@example.org" aria-describedby="al-list-help">
      <p class="f-help" id="al-list-help"><?= e(__('Mehrere Adressen mit Komma trennen, höchstens {n}. Gilt beim Modus „Bestimmte Adressen“ und für Kategorien, die dorthin umgeleitet sind.', ['n' => \Core\Network\SystemMail::MAX])) ?>
        <?= $admins ? ' ' . e(__('{admins}: {list}', ['admins' => $adminsLabel, 'list' => implode(', ', $admins)])) : '' ?></p>
    </div>
  </section>

  <section class="adm-card" aria-labelledby="al-cats">
    <h2 id="al-cats"><?= icon('list-checks') ?> <?= e(__('Kategorien')) ?></h2>
    <p class="adm-muted"><?= e(__('Je Kategorie abwählen oder umleiten, dazu bei Bedarf weitere Adressen. „Wirksam“ zeigt die gespeicherte Einstellung; „Test“ schickt eine Testnachricht an genau diese Empfänger.')) ?></p>
    <div class="mo-wrap">
    <table class="adm-table mo-table al-table">
      <thead><tr><th scope="col"><?= e(__('Kategorie')) ?></th><th scope="col"><?= e(__('Empfänger')) ?></th><th scope="col"><?= e(__('Zusätzlich an')) ?></th><th scope="col"><?= e(__('Wirksam')) ?></th><th scope="col"><span class="adm-sr"><?= e(__('Test')) ?></span></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $k = $row['key']; ?>
        <tr id="al-<?= e($k) ?>">
          <th scope="row"><strong><?= e($row['label']) ?></strong><span class="mo-sub"><?= e($row['help']) ?></span></th>
          <td data-label="<?= e(__('Empfänger')) ?>"><label class="adm-sr" for="al-r-<?= e($k) ?>"><?= e(__('Empfänger für {cat}', ['cat' => $row['label']])) ?></label>
            <select id="al-r-<?= e($k) ?>" name="route[<?= e($k) ?>]">
              <?php foreach ($routeLabels as $rk => $rl): ?><option value="<?= e($rk) ?>"<?= $row['route'] === $rk ? ' selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?>
            </select></td>
          <td data-label="<?= e(__('Zusätzlich an')) ?>"><label class="adm-sr" for="al-x-<?= e($k) ?>"><?= e(__('Zusätzliche Adressen für {cat}', ['cat' => $row['label']])) ?></label>
            <input id="al-x-<?= e($k) ?>" name="extra[<?= e($k) ?>]" type="text" autocomplete="off" value="<?= e(implode(', ', $cfg['extra'][$k] ?? [])) ?>" placeholder="<?= e(__('optional')) ?>"></td>
          <td data-label="<?= e(__('Wirksam')) ?>">
            <span class="mo-addr"><?= e($row['to'] ? implode(', ', $row['to']) : ($row['via'] === 'off' ? __('– aus') : __('– niemand'))) ?></span>
            <?php if ($row['via'] !== 'off'): ?><span class="mo-sub"><?= e($row['via_text']) ?><?= $row['fallback'] ? ' · ' . e(__('Rückfall – Ziel nicht eingerichtet')) : '' ?></span><?php endif; ?></td>
          <td class="al-test"><button class="adm-btn adm-btn--small" form="al-t-<?= e($k) ?>"<?= $row['to'] ? '' : ' disabled' ?>
            aria-label="<?= e(__('Testnachricht für {cat}', ['cat' => $row['label']])) ?>"><?= e(__('Test')) ?></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="f-help"><?= e(__('Mails gehen mit Impressum{net} hinaus; je Fehlerserie eine Mail. Ist nichts eingerichtet, bleibt das bisherige Verhalten ({admins}) – für „Agentur bevorzugt“ die Agentur-Adresse eintragen.', ['net' => $multi ? ' ' . __('und dem Netzwerk-Konto') : '', 'admins' => $adminsLabel])) ?></p>
  </section>
  <div class="adm-form-actions"><button class="adm-btn adm-btn--primary"><?= e(__('Speichern')) ?></button></div>
</form>
<?php // Testversand je Kategorie: eigene Formulare (Knöpfe in der Tabelle über form=…), damit die Eingabetaste im Formular oben speichert ?>
<?php foreach ($rows as $row): ?><form method="post" action="<?= e(url('/admin/system/meldungen/test/' . $row['key'])) ?>" id="al-t-<?= e($row['key']) ?>" hidden><?= csrf_field() ?></form><?php endforeach; ?>
  </div>
</div>
