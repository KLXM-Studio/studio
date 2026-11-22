<?php
/**
 * Schnelleinrichtung einer Website aus einer Vorlage (Admin\QuickConfigController, Core\Blueprint\QuickConfig): Schritte links,
 * aktueller Schritt rechts, darunter Demoinhalte, Vorschlag einer neuen Vorlagen-Version und Abschluss mit Checkliste.
 * Stile resources/css/blueprint.css, Vorschau der Akzentfarbe resources/js/_quickconfig.js.
 * @var array $bp  @var list<string> $steps  @var array $state  @var string $step  @var array $org  @var array $values  @var string $accent
 * @var bool $hasAccent  @var int $logo  @var string $region  @var list<array> $refs  @var array $roles  @var array $demo  @var array $mail
 * @var bool $netMail  @var list<array> $checklist  @var ?array $proposal  @var array $placeholders
 */
use Core\Blueprint\QuickConfig;

$done = (array) $state['done'];
$open = !empty($state['open']);
$act = fn(string $s) => url('/admin/schnelleinrichtung/' . $s);
$field = function (string $id, string $name, string $label, string $value = '', string $type = 'text', array $o = []): string {
    return '<div class="f' . (!empty($o['half']) ? ' f--half' : '') . '"><label for="' . e($id) . '">' . e($label) . (!empty($o['req']) ? ' <span class="req">*</span>' : '') . '</label>'
        . '<input id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '"' . (!empty($o['req']) ? ' required' : '')
        . (isset($o['auto']) ? ' autocomplete="' . e($o['auto']) . '"' : '') . (isset($o['list']) ? ' list="' . e($o['list']) . '"' : '') . (isset($o['ph']) ? ' placeholder="' . e($o['ph']) . '"' : '') . '>'
        . (isset($o['help']) ? '<p class="f-help">' . e($o['help']) . '</p>' : '') . '</div>';
};
$custom = array_filter($placeholders, fn($p) => is_array($p) && ($p['step'] ?? 'org') === 'org');
?>
<header class="adm-head">
  <div><p class="adm-eyebrow"><?= e(__('Website-Vorlage: {name}', ['name' => (string) ($bp['title'] ?? $bp['name'] ?? '')])) ?><?php if (!empty($bp['version'])): ?> · <?= e(__('Version {v}', ['v' => $bp['version']])) ?><?php endif; ?></p>
    <h1><?= e(__('Schnelleinrichtung')) ?></h1>
    <p class="adm-muted"><?= e($open ? __('Wenige Schritte bis zur eigenen Website – alles lässt sich später ändern. Bis zum Abschluss sehen Besucher die Wartungsseite.') : __('Die Schnelleinrichtung ist abgeschlossen. Sie können Schritte jederzeit erneut ausführen.')) ?></p></div>
  <div class="adm-head__actions"><a class="adm-btn" href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= icon('arrow-square-out') ?> <?= e(__('Website ansehen')) ?></a></div>
</header>

<div class="qc">
  <nav class="qc-steps adm-card" aria-label="<?= e(__('Schritte der Schnelleinrichtung')) ?>">
    <ol>
      <?php foreach ($steps as $i => $s): $isDone = !empty($done[$s]); ?>
      <li><a href="<?= e(url('/admin/schnelleinrichtung?schritt=' . $s)) ?>" class="qc-step<?= $s === $step ? ' is-current' : '' ?><?= $isDone ? ' is-done' : '' ?>"<?= $s === $step ? ' aria-current="step"' : '' ?>>
        <span class="qc-step__n" aria-hidden="true"><?= $isDone ? icon('check') : (string) ($i + 1) ?></span>
        <span class="qc-step__t"><?= e(QuickConfig::label($s)) ?><span class="adm-sr"><?= e($isDone ? ' – ' . __('erledigt') : ' – ' . __('offen')) ?></span></span>
      </a></li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <section class="qc-main adm-card" aria-labelledby="qc-h">
    <h2 id="qc-h"><?= icon(QuickConfig::icon($step)) ?> <?= e(QuickConfig::label($step)) ?></h2>
    <?php if ($step === 'org'): ?>
    <p class="adm-muted"><?= e(__('Diese Angaben erscheinen im Impressum, im Fußbereich, in den Kontaktdaten und als Absender der E-Mails.')) ?></p>
    <form method="post" action="<?= e($act('org')) ?>" class="qc-form" novalidate><?= csrf_field() ?>
      <div class="qc-grid">
        <?= $field('qc-name', 'name', __('Name der Organisation'), $org['name'], 'text', ['req' => true, 'auto' => 'organization']) ?>
        <?= $field('qc-short', 'short', __('Kurzname'), $org['short'], 'text', ['half' => true, 'help' => __('z. B. für App-Icon und Kopf')]) ?>
        <?= $field('qc-claim', 'claim', __('Claim'), $org['claim'], 'text', ['half' => true]) ?>
        <?= $field('qc-legal', 'legal_form', __('Rechtsform'), $org['legal_form'], 'text', ['half' => true, 'ph' => __('z. B. eingetragener Verein')]) ?>
        <?= $field('qc-reg', 'register', __('Registereintrag'), $org['register'], 'text', ['half' => true, 'ph' => __('z. B. Amtsgericht Musterstadt, VR 1234')]) ?>
        <?= $field('qc-street', 'street', __('Straße und Hausnummer'), $org['street'], 'text', ['auto' => 'street-address']) ?>
        <?= $field('qc-zip', 'zip', __('PLZ'), $org['zip'], 'text', ['half' => true, 'auto' => 'postal-code']) ?>
        <?= $field('qc-city', 'city', __('Ort'), $org['city'], 'text', ['half' => true, 'auto' => 'address-level2']) ?>
        <?= $field('qc-phone', 'phone', __('Telefon'), $org['phone'], 'tel', ['half' => true, 'auto' => 'tel']) ?>
        <?= $field('qc-email', 'email', __('E-Mail'), $org['email'], 'email', ['half' => true, 'auto' => 'email']) ?>
        <?php foreach ($custom as $k => $p): ?>
        <?= $field('qc-ph-' . preg_replace('~[^a-z0-9]~', '-', (string) $k), 'ph[' . $k . ']', (string) ($p['label'] ?? $k), (string) ($values[$k] ?? $p['default'] ?? '')) ?>
        <?php endforeach; ?>
      </div>
      <button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Speichern und weiter')) ?></button>
    </form>

    <?php elseif ($step === 'look'): ?>
    <p class="adm-muted"><?= e(__('Logo und Akzentfarbe. Die Farbe wird bei Bedarf so angepasst, dass Text darauf gut lesbar bleibt (mindestens 4,5 : 1).')) ?></p>
    <form method="post" action="<?= e($act('look')) ?>" enctype="multipart/form-data" class="qc-form" novalidate><?= csrf_field() ?>
      <div class="qc-grid">
        <div class="f"><label for="qc-logo"><?= e(__('Logo')) ?></label><input id="qc-logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" aria-describedby="qc-logo-h">
          <p class="f-help" id="qc-logo-h"><?= e($logo ? __('Ein Logo ist gesetzt – eine neue Datei ersetzt es.') : __('PNG, JPEG, WebP oder SVG. Ohne Logo zeigt die Website den Kurznamen.')) ?></p></div>
        <?php if ($hasAccent): ?>
        <div class="f f--half"><label for="qc-color"><?= e(__('Akzentfarbe')) ?></label>
          <input id="qc-color" name="color" type="color" value="<?= e($accent !== '' ? strtolower($accent) : '#0f766e') ?>" data-qc-color aria-describedby="qc-contrast"></div>
        <div class="f f--half qc-preview" data-qc-preview style="--qc-a: <?= e($accent !== '' ? $accent : '#0F766E') ?>">
          <span class="qc-preview__label"><?= e(__('Vorschau')) ?></span>
          <span class="qc-preview__btn"><?= e(__('Mitglied werden')) ?></span>
          <a class="qc-preview__link" href="#" tabindex="-1" aria-hidden="true"><?= e(__('Mehr erfahren')) ?></a>
          <p class="f-help" id="qc-contrast" data-qc-contrast aria-live="polite"></p>
        </div>
        <?php else: ?>
        <p class="adm-inline-box"><?= e(__('Dieses Kit hat keine einstellbare Akzentfarbe – Farben unter Design.')) ?></p>
        <?php endif; ?>
      </div>
      <button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Speichern und weiter')) ?></button>
    </form>
    <figure class="qc-frame"><iframe src="<?= e(url('/')) ?>" title="<?= e(__('Vorschau der Startseite')) ?>" loading="lazy"></iframe>
      <figcaption class="adm-muted"><?= e(__('Vorschau der Startseite (nach dem Speichern aktualisiert)')) ?></figcaption></figure>

    <?php elseif ($step === 'region'): ?>
    <p class="adm-muted"><?= e(__('Das Gebiet bestimmt den Kartenpunkt der Organisation, „In deiner Nähe“ und – mit der Erweiterung Gebietskarten – die zuständigen Gebiete.')) ?></p>
    <form method="post" action="<?= e($act('region')) ?>" class="qc-form" novalidate><?= csrf_field() ?>
      <?= $field('qc-region', 'region', __('Region bzw. Bundesland'), $region ?: (string) ($values['region.name'] ?? ''), 'text', ['req' => true, 'list' => 'qc-regions']) ?>
      <datalist id="qc-regions"><?php foreach (array_keys(QuickConfig::REGIONS) as $rg): ?><option value="<?= e($rg) ?>"></option><?php endforeach; ?></datalist>
      <button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Speichern und weiter')) ?></button>
    </form>

    <?php elseif ($step === 'network'): ?>
    <p class="adm-muted"><?= e(__('Die Vorlage nutzt geteilte Tabellen des Netzwerks. Beitreten heißt: Die Einträge kommen aus dem Netzwerk, statt kopiert zu werden – Beitritt mit Plan, eigene Einträge bleiben.')) ?></p>
    <?php if (!$refs): ?><p><?= e(__('Diese Vorlage hat keine Netzwerk-Tabellen.')) ?></p><?php else: ?>
    <form method="post" action="<?= e($act('network')) ?>" class="qc-form" novalidate><?= csrf_field() ?>
      <fieldset class="qc-checks"><legend><?= e(__('Tabellen')) ?></legend>
        <?php foreach ($refs as $ref): ?>
        <label class="qc-check"><input type="checkbox" name="join[]" value="<?= e($ref['key']) ?>"<?= $ref['joined'] ? ' checked disabled' : ($ref['exists'] ? ' checked' : ' disabled') ?>>
          <span><strong><?= e($ref['label']) ?></strong> <span class="adm-muted"><?= e(match (true) {
              $ref['joined'] => __('verbunden'), !$ref['exists'] => __('gibt es in dieser Installation nicht'),
              $ref['invited'] => __('eingeladen – Beitritt mit Plan „vorhandene behalten“'), default => __('wird beim Beitritt eingeladen (Eigentümer: {site})', ['site' => $ref['owner']]) }) ?></span></span></label>
        <?php endforeach; ?>
      </fieldset>
      <button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Beitreten und weiter')) ?></button>
    </form>
    <?php endif; ?>

    <?php elseif ($step === 'accounts'): ?>
    <p class="adm-muted"><?= e(__('Laden Sie die ersten Personen ein. Sie erhalten eine E-Mail mit Link (7 Tage gültig).')) ?></p>
    <form method="post" action="<?= e($act('accounts')) ?>" class="qc-form" novalidate><?= csrf_field() ?>
      <?php for ($i = 0; $i < 3; $i++): ?>
      <fieldset class="qc-invite"><legend class="adm-sr"><?= e(__('Person {n}', ['n' => $i + 1])) ?></legend>
        <?= $field('qc-inv-' . $i, 'invite[' . $i . '][email]', __('E-Mail-Adresse'), '', 'email', ['auto' => 'off']) ?>
        <div class="f"><label for="qc-inv-r-<?= $i ?>"><?= e(__('Rolle')) ?></label><select id="qc-inv-r-<?= $i ?>" name="invite[<?= $i ?>][role]">
          <?php foreach ($roles as $rk => $rn): ?><option value="<?= e((string) $rk) ?>"<?= $rk === 'editor' ? ' selected' : '' ?>><?= e((string) $rn) ?></option><?php endforeach; ?></select></div>
      </fieldset>
      <?php endfor; ?>
      <button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Einladen und weiter')) ?></button>
    </form>

    <?php elseif ($step === 'demo'): ?>
    <p class="adm-muted"><?= e(__('Die Vorlage bringt Beispiel-Einträge mit (Platzhalter-Personen, Termine ab heute). Sie helfen beim Ausprobieren und lassen sich jederzeit entfernen.')) ?></p>
    <?php if ($demo): ?><ul class="qc-demo"><?php foreach ($demo as $h => $n): ?><li><span class="qc-tag"><?= e(__('Demo')) ?></span> <?= e((\Core\Data\Tables::find((string) $h)['name'] ?? $h) . ': ' . $n) ?></li><?php endforeach; ?></ul><?php else: ?><p><?= e(__('Keine Demoinhalte (mehr) vorhanden.')) ?></p><?php endif; ?>
    <form method="post" action="<?= e($act('demo')) ?>" class="qc-form" novalidate><?= csrf_field() ?>
      <fieldset class="qc-checks"><legend><?= e(__('Demoinhalte')) ?></legend>
        <label class="qc-check"><input type="radio" name="demo" value="keep" checked> <span><?= e(__('Behalten')) ?></span></label>
        <label class="qc-check"><input type="radio" name="demo" value="remove"<?= $demo ? '' : ' disabled' ?>> <span><?= e(__('Entfernen')) ?></span></label>
      </fieldset>
      <button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Speichern und weiter')) ?></button>
    </form>

    <?php elseif ($step === 'mail'): ?>
    <p class="adm-muted"><?= e(__('Über welches Konto verschickt die Website E-Mails (Formulare, Einladungen, Benachrichtigungen)?')) ?></p>
    <form method="post" action="<?= e($act('mail')) ?>" class="qc-form" novalidate><?= csrf_field() ?>
      <fieldset class="qc-checks"><legend><?= e(__('Versand')) ?></legend>
        <label class="qc-check"><input type="radio" name="mode" value="network"<?= $mail['host'] === '' ? ' checked' : '' ?>> <span><?= e(__('Konto des Netzwerks')) ?> <span class="adm-muted"><?= e($netMail ? __('eingerichtet') : __('noch nicht eingerichtet')) ?></span></span></label>
        <label class="qc-check"><input type="radio" name="mode" value="smtp"<?= $mail['host'] !== '' ? ' checked' : '' ?>> <span><?= e(__('Eigenes SMTP-Konto')) ?></span></label>
      </fieldset>
      <div class="qc-grid">
        <?= $field('qc-host', 'host', __('SMTP-Server'), (string) $mail['host'], 'text', ['half' => true, 'auto' => 'off']) ?>
        <?= $field('qc-port', 'port', __('Port'), (string) ($mail['port'] ?: 587), 'number', ['half' => true]) ?>
        <?= $field('qc-user', 'user', __('Benutzername'), (string) $mail['user'], 'text', ['half' => true, 'auto' => 'off']) ?>
        <div class="f f--half"><label for="qc-pass"><?= e(__('Passwort')) ?></label><input id="qc-pass" name="pass" type="password" autocomplete="new-password" aria-describedby="qc-pass-h"><p class="f-help" id="qc-pass-h"><?= e(__('Leer lassen, um ein gespeichertes Passwort zu behalten.')) ?></p></div>
        <div class="f f--half"><label for="qc-enc"><?= e(__('Verschlüsselung')) ?></label><select id="qc-enc" name="encryption"><?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => __('keine')] as $ek => $el): ?><option value="<?= e($ek) ?>"<?= $mail['encryption'] === $ek ? ' selected' : '' ?>><?= e($el) ?></option><?php endforeach; ?></select></div>
        <?= $field('qc-from', 'from', __('Absender-Adresse'), (string) $mail['from'], 'email', ['half' => true]) ?>
      </div>
      <label class="qc-check"><input type="checkbox" name="test" value="1"> <span><?= e(__('Testmail an mich senden')) ?></span></label>
      <button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Speichern und weiter')) ?></button>
    </form>

    <?php elseif ($step === 'legal'): ?>
    <p class="adm-muted"><?= e(__('Impressum und Datenschutzerklärung der Vorlage werden mit Ihren Angaben gefüllt. Bitte danach prüfen – dafür entsteht eine Aufgabe.')) ?></p>
    <ul class="qc-legal"><?php foreach (['imprint' => __('Impressum'), 'privacy' => __('Datenschutzerklärung')] as $w => $wl): $lp = \Core\Legal::page($w); ?>
      <li><?= $lp ? '<a href="' . e(url('/admin/pages/' . $lp['id'])) . '">' . e($wl) . '</a>' : e($wl . ' – ' . __('keine Seite in der Vorlage')) ?></li><?php endforeach; ?></ul>
    <form method="post" action="<?= e($act('legal')) ?>" class="qc-form" novalidate><?= csrf_field() ?>
      <button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Füllen und Aufgabe anlegen')) ?></button>
    </form>
    <?php endif; ?>
  </section>

  <?php if ($proposal): ?>
  <section class="qc-side adm-card" aria-labelledby="qc-up">
    <h2 id="qc-up"><?= icon('arrows-clockwise') ?> <?= e(__('Neue Version der Vorlage: {v}', ['v' => $proposal['version']])) ?></h2>
    <p class="adm-muted"><?= e(__('Übernommen wird nur Neues an der Struktur – neue Tabellen und Felder, neue Seiten als Entwurf, Rollen. Inhalte bleiben unverändert.')) ?></p>
    <ul class="qc-diff"><?php foreach (\Core\Blueprint\Diff::summary((array) $proposal['diff']) as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
    <form method="post" action="<?= e($act('update')) ?>"><?= csrf_field() ?><button class="adm-btn" type="submit"><?= e(__('Vorschlag übernehmen')) ?></button></form>
  </section>
  <?php endif; ?>

  <section class="qc-side adm-card" id="abschluss" aria-labelledby="qc-fin">
    <h2 id="qc-fin"><?= icon('seal-check') ?> <?= e(__('Abschluss')) ?></h2>
    <?php if ($checklist): ?>
    <p class="adm-muted"><?= e(__('Noch offen – wird beim Abschließen zur Aufgabe:')) ?></p>
    <ul class="qc-open"><?php foreach ($checklist as $c): ?><li><?= e($c['title']) ?></li><?php endforeach; ?></ul>
    <?php else: ?><p><?= e(__('Alles erledigt.')) ?></p><?php endif; ?>
    <form method="post" action="<?= e($act('finish')) ?>" class="qc-form"><?= csrf_field() ?>
      <label class="qc-check"><input type="checkbox" name="live" value="1" checked> <span><?= e(__('Website danach für Besucher öffnen (Wartungsmodus aus)')) ?></span></label>
      <button class="adm-btn adm-btn--primary" type="submit"><?= e($open ? __('Schnelleinrichtung abschließen') : __('Erneut abschließen')) ?></button>
    </form>
  </section>
</div>
