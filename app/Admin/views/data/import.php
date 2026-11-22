<?php
/**
 * Importieren in ein Adressbuch (vCard) bzw. einen Kalender (ICS) – Controller ExchangeController, Logik Core\Data\Exchange.
 * Schritt 1: Datei ziehen/wählen (Kalender auch Adresse einmalig abrufen oder abonnieren). Schritt 2: Vorschau (Probelauf) mit
 * Bestätigen. Ohne JavaScript ein normales Formular; resources/js/_exchange.js sendet die gezogene Datei sofort ab.
 * @var array $t  @var string $kind vcf|ics  @var ?array $report  @var string $token  @var string $label  @var ?string $error  @var string $url
 * @var array $abos  @var bool $canSchema  @var bool $canPublish
 */
use Core\Data\Exchange;

$base = '/admin/data/' . $t['handle'];
$vcf = $kind === 'vcf';
$mb = (int) (Exchange::maxBytes() / 1048576);
$back = url($base) . ($vcf ? '' : '?view=calendar');
$actions = ['created' => [__('neu'), 'plus'], 'updated' => [__('geändert'), 'pencil-simple'], 'unchanged' => [__('unverändert'), 'check'],
    'skipped' => [__('übersprungen'), 'minus'], 'failed' => [__('Fehler'), 'warning']];
$fmt = \Core\Format::admin();
?>
<header class="adm-head dt-head">
  <h1><span aria-hidden="true" class="dt-h1icon"><?= icon($t['icon'] ?: ($vcf ? 'address-book' : 'calendar-dots')) ?></span> <?= e($t['name']) ?> <span class="dt-h1sub">· <?= e(__('Importieren')) ?></span></h1>
  <div class="adm-row">
    <a class="adm-btn adm-btn--ghost" href="<?= e($back) ?>"><?= icon('arrow-left') ?> <?= e($vcf ? __('Zum Adressbuch') : __('Zum Kalender')) ?></a>
  </div>
</header>

<?php if ($error): ?><p class="adm-flash adm-flash--error" role="alert"><?= icon('warning') ?> <?= e($error) ?></p><?php endif; ?>

<?php if (!$report): ?>
<p class="adm-muted imp-lead"><?= e($vcf
    ? __('Kontakte aus Apple Kontakte, Google, Outlook oder einem anderen Adressbuch übernehmen. Zuerst sehen Sie eine Vorschau – erst „Übernehmen“ ändert das Adressbuch. Vorhandene Kontakte mit derselben UID werden aktualisiert statt doppelt angelegt.')
    : __('Termine aus Apple Kalender, Google, Outlook oder einem anderen Kalender übernehmen – mit Serien, Ausnahmen, geänderten Einzelterminen und Zeitzonen. Zuerst sehen Sie eine Vorschau. Termine mit derselben UID werden aktualisiert statt doppelt angelegt.')) ?></p>

<section class="adm-card imp-card" aria-labelledby="imp-h-file">
  <h2 id="imp-h-file"><?= e(__('Datei')) ?></h2>
  <form method="post" action="<?= e(url($base . '/import')) ?>" enctype="multipart/form-data" data-imp-form>
    <?= csrf_field() ?><input type="hidden" name="step" value="preview">
    <label class="imp-drop" for="imp-file" data-imp-drop>
      <span class="imp-drop__ico" aria-hidden="true"><?= icon($vcf ? 'address-book' : 'calendar-plus') ?></span>
      <span class="imp-drop__t"><?= e($vcf ? __('vCard-Datei hierher ziehen') : __('ICS-Datei hierher ziehen')) ?></span>
      <span class="imp-drop__s"><?= e(__('oder klicken, um eine Datei zu wählen')) ?> · <?= e($vcf ? '.vcf' : '.ics') ?> · <?= e(__('höchstens {mb} MB', ['mb' => $mb])) ?></span>
      <span class="imp-drop__name" data-imp-name aria-live="polite"></span>
    </label>
    <input class="imp-file" id="imp-file" type="file" name="datei" accept="<?= e($vcf ? '.vcf,.vcard,text/vcard,text/x-vcard' : '.ics,.ical,text/calendar') ?>" data-imp-file>
    <div class="adm-row imp-actions">
      <button class="adm-btn adm-btn--primary" data-imp-submit><?= icon('eye') ?> <?= e(__('Vorschau anzeigen')) ?></button>
      <span class="adm-muted"><?= e(__('Noch wird nichts geändert.')) ?></span>
    </div>
  </form>
</section>

<?php if (!$vcf): ?>
<section class="adm-card imp-card" aria-labelledby="imp-h-url">
  <h2 id="imp-h-url"><?= e(__('Von einer Adresse')) ?></h2>
  <form method="post" action="<?= e(url($base . '/import')) ?>">
    <?= csrf_field() ?><input type="hidden" name="step" value="preview">
    <div class="f"><label for="imp-url"><?= e(__('Adresse des Kalenders')) ?></label>
      <input id="imp-url" name="url" type="url" inputmode="url" value="<?= e($url) ?>" placeholder="https://example.org/kalender.ics" maxlength="2000" required aria-describedby="imp-url-h">
      <p class="f-help" id="imp-url-h"><?= e(__('https:// oder webcal:// – einmal abgerufen, vom Server. Adressen im eigenen Netz (localhost, private IP-Adressen) sind gesperrt.')) ?></p></div>
    <div class="adm-row imp-actions">
      <button class="adm-btn"><?= icon('download-simple') ?> <?= e(__('Einmal abrufen – Vorschau')) ?></button>
    </div>
  </form>
  <h3><?= e(__('Regelmäßig abrufen (Abo)')) ?></h3>
  <?php if (\Core\Sources\Sources::canManage()): ?>
  <form method="post" action="<?= e(url($base . '/import/abo')) ?>" class="imp-abo">
    <?= csrf_field() ?>
    <div class="adm-fields">
      <div class="f"><label for="imp-abo-url"><?= e(__('Adresse')) ?></label><input id="imp-abo-url" name="url" type="url" inputmode="url" placeholder="webcal://example.org/ferien.ics" maxlength="2000" required></div>
      <div class="f f--half"><label for="imp-abo-name"><?= e(__('Name')) ?></label><input id="imp-abo-name" name="name" maxlength="120" placeholder="<?= e(__('z. B. Ferien NRW')) ?>"></div>
      <div class="f f--half"><label for="imp-abo-s"><?= e(__('Abruf')) ?></label><select id="imp-abo-s" name="schedule"><option value="daily"><?= e(__('täglich')) ?></option><option value="hourly"><?= e(__('stündlich')) ?></option></select></div>
      <div class="f f--half"><label for="imp-abo-m"><?= e(__('Fehlt ein Termin im Abo')) ?></label><select id="imp-abo-m" name="missing"><?php foreach (\Core\Sources\Sources::missingModes() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="f f--half"><label for="imp-abo-st"><?= e(__('Neue Termine')) ?></label><select id="imp-abo-st" name="status"><option value="draft"><?= e(__('als Entwurf')) ?></option><option value="published"><?= e(__('veröffentlichen')) ?></option></select></div>
    </div>
    <div class="adm-row imp-actions"><button class="adm-btn"><?= icon('arrows-clockwise') ?> <?= e(__('Abo anlegen')) ?></button>
      <span class="adm-muted"><?= e(__('Wird als Externe Quelle angelegt und per Cron abgeglichen; Termine aus dem Abo sind im CMS schreibgeschützt.')) ?></span></div>
  </form>
  <?php if ($abos): ?>
  <ul class="imp-abos">
    <?php foreach ($abos as $a): ?><li><a href="<?= e(url('/admin/quellen/' . $a['id'])) ?>"><?= icon('rss') ?> <?= e($a['name']) ?></a> <span class="adm-muted">· <?= e($a['last_run_at'] ? __('zuletzt {when}', ['when' => $fmt->datetime(strtotime((string) $a['last_run_at']))]) : __('noch nie abgerufen')) ?></span></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php else: ?>
  <p class="adm-muted"><?= e(__('Für regelmäßige Abos muss die Funktion „Externe Quellen“ eingeschaltet sein und Ihre Rolle Externe Quellen verwalten dürfen.')) ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php else: $r = $report; ?>
<section class="adm-card imp-card" aria-labelledby="imp-h-prev">
  <h2 id="imp-h-prev"><?= e(__('Vorschau')) ?><?php if ($label !== ''): ?> <span class="adm-muted imp-src">· <?= e($label) ?></span><?php endif; ?></h2>
  <p class="adm-muted"><?= e($vcf ? __('{n} Kontakte in der Datei', ['n' => $r['total']]) . ($r['groups'] ? ' · ' . ($r['groups'] === 1 ? __('1 Gruppe') : __('{n} Gruppen', ['n' => $r['groups']])) : '') : __('{n} Termine bzw. Serien in der Datei', ['n' => $r['total']])) ?></p>
  <ul class="imp-sum" role="list">
    <?php foreach ($actions as $k => [$lab, $ico]): if (!$r[$k] && in_array($k, ['skipped', 'failed'], true)) continue; ?>
    <li class="imp-sum__i imp-sum__i--<?= e($k) ?>"><span class="imp-sum__n"><?= (int) $r[$k] ?></span><span class="imp-sum__l"><?= icon($ico) ?> <?= e($lab) ?></span></li>
    <?php endforeach; ?>
    <?php if (!$vcf && $r['overrides']): ?><li class="imp-sum__i"><span class="imp-sum__n"><?= (int) $r['overrides'] ?></span><span class="imp-sum__l"><?= icon('repeat') ?> <?= e(__('Einzeltermine')) ?></span></li><?php endif; ?>
    <?php if ($vcf && $r['photos']): ?><li class="imp-sum__i"><span class="imp-sum__n"><?= (int) $r['photos'] ?></span><span class="imp-sum__l"><?= icon('image') ?> <?= e(__('Fotos')) ?></span></li><?php endif; ?>
  </ul>
  <?php $newOpts = $vcf ? $r['new_lists'] : $r['new_categories']; $missOpts = $vcf ? $r['missing_lists'] : $r['missing_categories']; ?>
  <form method="post" action="<?= e(url($base . '/import')) ?>" class="imp-confirm">
    <?= csrf_field() ?><input type="hidden" name="step" value="apply"><input type="hidden" name="token" value="<?= e($token) ?>">
    <?php if ($newOpts): ?>
    <label class="f-check"><input type="checkbox" name="anlegen" value="1" checked> <span><?= e($vcf ? __('Fehlende Listen anlegen: {list}', ['list' => implode(', ', $newOpts)]) : __('Fehlende Kategorien anlegen: {list}', ['list' => implode(', ', $newOpts)])) ?></span></label>
    <?php endif; ?>
    <?php if ($missOpts): ?><p class="adm-flash adm-flash--warning"><?= icon('warning') ?> <?= e(($vcf ? __('Diese Listen fehlen und werden nicht zugeordnet (neue Listen legt an, wer Felder ändern darf):') : __('Diese Kategorien fehlen und werden nicht zugeordnet (neue Kategorien legt an, wer Felder ändern darf):')) . ' ' . implode(', ', $missOpts)) ?></p><?php endif; ?>
    <fieldset class="imp-status">
      <legend><?= e($vcf ? __('Neue Kontakte') : __('Neue Termine')) ?></legend>
      <?php $def = $vcf && $canPublish ? 'published' : 'draft'; ?>
      <label class="f-check"><input type="radio" name="status" value="published"<?= $def === 'published' ? ' checked' : '' ?><?= $canPublish ? '' : ' disabled' ?>> <span><?= e($vcf ? __('veröffentlichen (stehen trotzdem erst mit „Auf der Website zeigen“ auf der Website)') : __('veröffentlichen')) ?></span></label>
      <label class="f-check"><input type="radio" name="status" value="draft"<?= $def === 'draft' ? ' checked' : '' ?>> <span><?= e(__('als Entwurf')) ?></span></label>
    </fieldset>
    <?php if ($vcf && $r['photos']): ?><label class="f-check"><input type="checkbox" name="fotos" value="0"> <span><?= e(__('Fotos nicht übernehmen')) ?></span></label><?php endif; ?>
    <div class="imp-rows" role="region" aria-labelledby="imp-h-rows" tabindex="0">
      <h3 id="imp-h-rows" class="sr-only"><?= e(__('Einträge der Datei')) ?></h3>
      <table class="adm-table imp-table">
        <thead><tr><th scope="col"><?= e(__('Aktion')) ?></th><th scope="col"><?= e($vcf ? __('Name') : __('Termin')) ?></th><th scope="col"><?= e(__('Details')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($r['rows'] as $row): [$lab, $ico] = $actions[$row['action']] ?? [$row['action'], 'circle']; ?>
          <tr class="imp-row imp-row--<?= e($row['action']) ?>">
            <td><span class="imp-badge imp-badge--<?= e($row['action']) ?>"><?= icon($ico) ?> <?= e($lab) ?></span></td>
            <td><b><?= e($row['name']) ?></b><?php if (!$vcf && $row['when'] !== ''): ?><br><span class="adm-muted"><?= e($row['when']) ?><?= !empty($row['series']) ? ' · ' . e(__('Serie')) : '' ?><?= !empty($row['overrides']) ? ' · ' . e(__('{n} geänderte Einzeltermine', ['n' => $row['overrides']])) : '' ?></span><?php endif; ?></td>
            <td><?= e($row['detail']) ?><?php foreach ($row['warnings'] as $w): ?><br><span class="imp-warn"><?= icon('warning') ?> <?= e($w) ?></span><?php endforeach; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($r['total'] > count($r['rows'])): ?><p class="adm-muted"><?= e(__('Gezeigt werden die ersten {n}.', ['n' => count($r['rows'])])) ?></p><?php endif; ?>
    </div>
    <?php foreach ($r['warnings'] as $w): ?><p class="adm-flash adm-flash--warning"><?= icon('warning') ?> <?= e($w) ?></p><?php endforeach; ?>
    <div class="adm-row imp-actions">
      <button class="adm-btn adm-btn--primary"<?= $r['created'] + $r['updated'] === 0 ? ' disabled' : '' ?>><?= icon('check') ?> <?= e(__('Übernehmen')) ?></button>
      <a class="adm-btn adm-btn--ghost" href="<?= e(url($base . '/import')) ?>"><?= e(__('Andere Datei')) ?></a>
      <?php if ($r['created'] + $r['updated'] === 0): ?><span class="adm-muted"><?= e(__('Nichts zu übernehmen – alles ist schon auf dem Stand der Datei.')) ?></span><?php endif; ?>
    </div>
  </form>
</section>
<?php endif; ?>
