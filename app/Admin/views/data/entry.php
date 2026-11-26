<?php
/**
 * Eintrag bearbeiten.
 * @var array $t  @var ?array $e  @var array $values  @var array $errors  @var ?string $occ (Serie: ursprünglicher Beginn des geöffneten Termins)
 */
use Core\Data\Entries;
use Core\Data\Series;
use Core\Fields;

$occ ??= null;
// Kalender mit Serien (Core\Data\Series): Serienkopf, Einzeltermin, geöffnetes Vorkommen
$isHead = $e && Series::isHead($t, $e);
$isOv = $e && Series::isOverride($t, $e);
$headE = $isOv ? Entries::find($t, (int) $e[Series::SERIES]) : null;
$occKey = $occ !== null ? \Core\Data\Calendar::ridKey($occ) : '';
$occWhen = $occ !== null ? \Core\Data\Calendar::dayLabel(Series::ridTime($occ) ?? new DateTimeImmutable()) . ', ' . substr($occ, 11) : '';
$rule = $isHead ? \Core\Data\Calendar::describe(\Core\Data\Calendar::config($t)['recurrence'] !== '' ? ($e[\Core\Data\Calendar::config($t)['recurrence']] ?? '') : '', substr(\Core\I18n::locale(), 0, 2) ?: 'de') : '';

$isNew = $e === null;
$base = '/admin/data/' . $t['handle'];
$url = $e ? Entries::url($t, $e) : null;
// Neue Einträge starten offline (Entwurf), wenn die Tabelle Entwurf/Online kennt – „Veröffentlichen“ stellt sie direkt online
$status = $values['status'] ?? ($e['status'] ?? ($t['settings']['workflow'] ? 'draft' : 'published'));
?>
<header class="adm-head dt-head">
  <div><p class="adm-eyebrow"><a href="<?= e(url($base)) ?>"><span aria-hidden="true"><?= icon($t['icon']) ?></span> <?= e($t['name']) ?></a></p>
    <h1><?= $isNew ? 'Neu: ' . e($t['singular']) : e(Entries::title($t, $e)) ?></h1>
    <?php if ($occ !== null): ?><p class="dt-series-note"><?= icon('repeat') ?> <?= e(__('Termin am {date} aus der Serie – {rule}', ['date' => $occWhen, 'rule' => $rule])) ?></p>
    <?php elseif ($isHead): ?><p class="dt-series-note"><?= icon('repeat') ?> <?= e(__('Serie: {rule}. Änderungen hier gelten für alle Termine; einzelne Termine öffnen Sie im Kalender.', ['rule' => $rule])) ?></p>
    <?php elseif ($isOv && $headE): ?><p class="dt-series-note"><?= icon('repeat') ?> <?= e(__('Einzeltermin der Serie')) ?> <a href="<?= e(url($base . '/' . (int) $headE['id'])) ?>">„<?= e(Entries::title($t, $headE)) ?>“</a> – <?= e(__('ersetzt den Termin am {date}.', ['date' => \Core\Data\Calendar::dayLabel(Series::ridTime((string) $e[Series::RID]) ?? new DateTimeImmutable()) . ', ' . substr((string) $e[Series::RID], 11)])) ?></p><?php endif; ?></div>
  <?php if (!$isNew): // Versionen des Eintrags (Core\Data\Revisions) ?><button type="button" class="adm-btn adm-btn--ghost"<?= \Core\Http\Controllers\Admin\VersionsController::attrs('/admin/api/data/' . $t['handle'] . '/' . (int) $e['id'] . '/versions') ?>><?= icon('clock-counter-clockwise') ?> <?= e(__('Versionen')) ?></button><?php endif; ?>
  <?php if ($url): ?><a class="adm-btn adm-btn--ghost" href="<?= e($url) ?>" target="_blank" rel="noopener">Ansehen ↗</a><?php endif; ?>
</header>

<form method="post" action="<?= e(url($isNew ? $base . '/new' : $base . '/' . $e['id']) . (($_GET['eingebettet'] ?? '') === '1' ? '?eingebettet=1' : '')) ?>" class="dt-entry" novalidate<?= \Core\Data\Calendar::enabled($t) ? ' data-rrule-preview="' . e(url($base . '/wiederholung')) . '"' . ($e ? ' data-entry-id="' . (int) $e['id'] . '"' : '') : '' ?><?php if ($cond = \Core\Data\Rules::client($t['fields'])): ?> data-conditions="<?= e(json_encode($cond, JSON_UNESCAPED_UNICODE)) ?>"<?php endif; ?>>
  <?= csrf_field() ?>
  <section class="adm-card dt-entry__main">
    <?= /* Sichtbarkeit: „Auf der Website: …“ bzw. „nicht auf der Website“ (Core\Data\Exposure) */ \Core\Theme::capture(__DIR__ . '/_visibility.php', ['t' => $t, 'values' => $values, 'status' => (string) $status]) ?>
    <div class="adm-fields"><?= Fields::renderForm(Entries::formSchema($t, $values), $values, $errors, 'f') ?></div>
    <?= /* Netzwerk: Zusatzfelder dieser Website (Core\Data\Network) */ \Core\Theme::capture(__DIR__ . '/_network.php', ['t' => $t, 'e' => $e, 'values' => $values, 'part' => 'extra', 'action' => null]) ?>
    <?php if (!$isNew): ?><?= /* Rückverknüpfungen: Einträge anderer Tabellen, die hierher verknüpfen – bearbeitbar (Core\Data\Backlinks) */ \Core\Theme::capture(__DIR__ . '/_backlinks.php', ['t' => $t, 'e' => $e]) ?><?php endif; ?>
  </section>
  <aside class="dt-entry__side">
    <section class="adm-card">
      <?php if ($t['settings']['workflow'] && !can('data.publish', $t['handle'])): ?>
      <p class="dt-note"><span class="dt-status dt-status--draft">Entwurf</span> <?= e(__('Ihre Rolle speichert Einträge als Entwurf – veröffentlicht wird von der Redaktion.')) ?></p>
      <?php elseif ($t['settings']['workflow']): ?>
      <fieldset class="dt-statuspick"><legend>Status</legend>
        <label><input type="radio" name="status" value="published"<?= $status === 'published' ? ' checked' : '' ?>> <span class="dt-status dt-status--published">Online</span></label>
        <label><input type="radio" name="status" value="draft"<?= $status === 'draft' ? ' checked' : '' ?>> <span class="dt-status dt-status--draft"><?= e(__('Offline')) ?></span></label>
        <?php if (\Core\Data\Tables::field($t, \Core\Data\Kinds::PUBLIC_FIELD)): // Kontakte: zwei Schalter – was sie jeweils bedeuten ?>
        <p class="f-help"><?= e(__('Status = Eintrag aktiv (Offline: ruht, auch für Verknüpfungen). Ob die Person öffentlich erscheint, regelt zusätzlich „Auf der Website zeigen“.')) ?></p>
        <?php endif; ?>
      </fieldset>
      <?= /* Zeitplan „Online ab“ / „Offline ab“ (Core\Schedule) – auch in der Seitenleiste der Website */ \Core\Theme::capture(__DIR__ . '/_schedule.php', ['values' => $values, 'errors' => $errors]) ?>
      <?php endif; ?>
      <?= \Core\Theme::capture(__DIR__ . '/_featured.php', ['t' => $t, 'values' => $values, 'errors' => $errors]) ?>
      <?php if (\Core\Lang::multi()): $elang = $isNew ? (\Core\Lang::valid($_GET['lang'] ?? '') ? $_GET['lang'] : \Core\Lang::default()) : \Core\Lang::norm($e['lang'] ?? null); ?>
      <?php if ($isNew): ?><input type="hidden" name="lang" value="<?= e($elang) ?>"><?php endif; ?>
      <div class="dt-langbox"><strong><?= e(__('Sprache')) ?>: <?= e(\Core\Lang::all()[$elang] ?? $elang) ?></strong>
        <?php if (!$isNew): $tr = Entries::translations($t, $e); foreach (\Core\Lang::all() as $code => $label): if ($code === $elang) continue; ?>
        <?php if (isset($tr[$code])): ?><a class="adm-btn adm-btn--small adm-btn--ghost" href="<?= e(url($base . '/' . $tr[$code]['id'])) ?>"><?= e($label) ?> ↗</a>
        <?php else: ?><button class="adm-btn adm-btn--small" form="tr-<?= e($code) ?>">+ <?= e($label) ?></button><?php endif; ?>
        <?php endforeach; endif; ?>
      </div>
      <?php endif; ?>
      <?php if (\Core\Data\Tables::isShared($t)): $shOwner = \Core\Data\Shared::isOwner($t); ?>
      <div class="f sh-suggest">
        <p class="sh-sharednote"><span class="dt-nav__shared"><?= e(__('geteilt')) ?></span> <?= e($shOwner ? __('Erscheint je nach deren Einstellung auch auf den übrigen Websites dieser Tabelle.') : __('Diese Website pflegt ihre eigenen Einträge; „{site}“ legt die Felder fest.', ['site' => \Core\Data\Shared::siteInfo($t['shared']['owner'], $t['shared']['key'])['name']])) ?></p>
        <?php if (!$shOwner):
          $sugg = !empty($values['_suggest'] ?? $values['suggest'] ?? false);
          $sp = $e ? (\Core\Data\Shared::picks($t, [$e['id']], $t['shared']['owner'])[$e['id']] ?? null) : null; ?>
        <input type="hidden" name="suggest" value="0">
        <label class="f-check"><input type="checkbox" name="suggest" value="1"<?= $sugg ? ' checked' : '' ?>> <span><?= e(__('Zur Übernahme vorschlagen ({owner})', ['owner' => term('shared_owner')])) ?></span></label>
        <p class="f-help"><?= e(match ($sp) {
            'visible' => __('Übernommen – erscheint auf der Website von „{site}“.', ['site' => \Core\Data\Shared::siteInfo($t['shared']['owner'], $t['shared']['key'])['name']]),
            'featured' => __('Übernommen und hervorgehoben.'),
            'rejected' => __('Vorschlag wurde abgelehnt.'),
            'hidden' => __('Dort ausgeblendet.'),
            default => $sugg ? __('Vorgeschlagen – noch nicht entschieden.') : __('Veröffentlichte Einträge mit Häkchen erscheinen dort unter „Vorschläge“.'),
        }) ?></p>
        <?php endif; ?>
      </div>
      <?= /* Netzwerk: Weitergabe-Stufe (Core\Data\Network) */ \Core\Theme::capture(__DIR__ . '/_network.php', ['t' => $t, 'e' => $e, 'values' => $values, 'part' => 'level', 'action' => null]) ?>
      <?php endif; ?>
      <div class="f"><label for="slug">Adresse</label>
        <div class="adm-prefix"><span>/<?= e($t['settings']['route'] ?: '…') ?>/</span><input id="slug" name="slug" value="<?= e($values['slug'] ?? '') ?>" placeholder="aus dem Titel"></div>
        <?php if ($t['settings']['route'] === ''): ?><p class="f-help">Diese Tabelle hat keine Detailseiten.</p><?php endif; ?></div>
      <?php if ($occ !== null): // Serie: Bereich der Änderung – mit JavaScript als Rückfrage beim Speichern (resources/js/_rrule.js) ?>
      <input type="hidden" name="vorkommen" value="<?= e($occKey) ?>">
      <fieldset class="dt-scope" data-series-scope data-when="<?= e($occWhen) ?>" data-title="<?= e(Entries::title($t, $e)) ?>" data-rule="<?= e($rule) ?>">
        <legend><?= e(__('Welche Termine ändern?')) ?></legend>
        <label class="dt-scope__opt"><input type="radio" name="scope" value="this" checked> <span><strong><?= e(__('Nur dieser Termin')) ?></strong>
          <small><?= e(__('Nur am {date}. Die übrigen Termine der Serie bleiben, wie sie sind.', ['date' => $occWhen])) ?></small></span></label>
        <label class="dt-scope__opt"><input type="radio" name="scope" value="following"> <span><strong><?= e(__('Dieser und alle folgenden Termine')) ?></strong>
          <small><?= e(__('Ab {date}. Frühere Termine bleiben unverändert.', ['date' => $occWhen])) ?></small></span></label>
        <label class="dt-scope__opt"><input type="radio" name="scope" value="all"> <span><strong><?= e(__('Alle Termine der Serie')) ?></strong>
          <small><?= e(__('Auch vergangene. Einzeln geänderte Termine bleiben, wie sie sind.')) ?></small></span></label>
      </fieldset>
      <?php endif; ?>
      <div class="dt-save">
        <button class="adm-btn adm-btn--primary adm-btn--block" type="submit"><?= e(__('Speichern')) ?></button>
        <?php if ($isNew && $t['settings']['workflow'] && can('data.publish', $t['handle'])): ?><button class="adm-btn adm-btn--block" type="submit" name="status" value="published"><?= icon('globe') ?> <?= e(__('Veröffentlichen')) ?></button><?php endif; ?>
        <div class="adm-row"><button class="adm-btn adm-btn--small adm-btn--ghost" name="then" value="new">Speichern &amp; neu</button><button class="adm-btn adm-btn--small adm-btn--ghost" name="then" value="list">Speichern &amp; zur Liste</button></div>
      </div>
      <?php if (!$isNew): ?>
      <dl class="md-info">
        <dt>Angelegt</dt><dd><?= e(\Core\Format::admin()->datetime(strtotime((string) $e['created_at']))) ?></dd>
        <dt>Geändert</dt><dd><?= e(\Core\Format::admin()->datetime(strtotime((string) $e['updated_at']))) ?></dd>
        <?php if ($e['published_at']): ?><dt>Veröffentlicht</dt><dd><?= e(\Core\Format::admin()->date(strtotime((string) $e['published_at']))) ?></dd><?php endif; ?>
        <dt>ID</dt><dd><?= (int) $e['id'] ?></dd>
      </dl>
      <?php endif; ?>
    </section>
    <?php if (!$isNew && \Core\Data\Jobs::is($t)): $jp = $e['status'] === 'published' ? \Core\Data\Jobs::problems($t, $e) : [__('Entwurf – erst veröffentlicht erscheint die Stelle bei Google.')]; ?>
    <section class="adm-card">
      <h2><?= e(__('Google for Jobs')) ?></h2>
      <?php if (!$jp): ?><p><?= icon('check-circle') ?> <?= e(__('Alle Pflichtangaben vorhanden – die Detailseite liefert ein gültiges JobPosting.')) ?></p>
      <?php else: ?><ul><?php foreach ($jp as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <p class="f-help"><?= e(__('Prüfen: Rich-Suchergebnis-Test von Google mit der Adresse der Stelle. „Gültig bis“ aktuell halten bzw. besetzte Stellen auf Entwurf stellen.')) ?></p>
    </section>
    <?php endif; ?>
    <?php if (!$isNew && ($blg = array_values(array_filter(\Core\Data\Backlinks::groups($t, (int) $e['id'], true), fn($g) => $g['total'] > 0)))): // Kurzfassung; bearbeitbar im Hauptbereich ?>
    <section class="adm-card dt-backlinks">
      <h2><?= e(__('Verknüpft mit')) ?></h2>
      <ul class="dt-bl-sum" role="list">
        <?php foreach ($blg as $g): ?>
        <li><a href="<?= e($g['enabled'] ? '#rueckverknuepfungen' : $g['list_url']) ?>"><span aria-hidden="true"><?= icon($g['table']['icon']) ?></span> <span><?= e($g['label']) ?></span></a> <small><?= e(\Core\Data\Backlinks::summary($g)) ?></small></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
    <?= /* KI-Assistent: Teaser, SEO, Übersetzen (Core\AI) */ \Core\Theme::capture(ROOT . '/app/Admin/views/ai/_entry.php', ['t' => $t, 'e' => $e]) ?>
    <?php if (!$isNew): // Aufgaben zu diesem Eintrag (Core\Tasks) – Dialog steht außerhalb des Formulars (unten) ?><?= \Core\Theme::capture(ROOT . '/app/Admin/views/tasks/_context.php', ['link' => 'entry:' . $t['handle'] . ':' . (int) $e['id'], 'back' => $base . '/' . (int) $e['id'], 'part' => 'panel']) ?><?php endif; ?>
    <?php if (!$isNew): ?>
    <button class="adm-btn adm-btn--small adm-btn--ghost" form="dup" type="submit" title="<?= e(__('Kopie als Entwurf anlegen – z. B. für den nächsten Termin')) ?>"><?= icon('copy') ?> <?= e(__('Duplizieren')) ?></button>
    <?php endif; ?>
    <?php if ($occ !== null): // einzelner Termin einer Serie: fällt aus / entfernen ?>
    <section class="adm-card dt-occ">
      <h2><?= e(__('Nur dieser Termin')) ?></h2>
      <p class="f-help"><?= e($occWhen) ?></p>
      <div class="adm-row">
        <button class="adm-btn adm-btn--small" form="occ-cancel" type="submit"><?= icon('prohibit') ?> <?= e(__('Fällt aus')) ?></button>
        <?php if (can('data.delete', $t['handle'])): ?><button class="adm-btn adm-btn--small adm-btn--ghost adm-btn--danger-text" form="occ-remove" type="submit"><?= e(__('Termin entfernen')) ?></button><?php endif; ?>
      </div>
      <p class="f-help"><?= e(__('„Fällt aus“ lässt den Termin sichtbar (als abgesagt), „Termin entfernen“ nimmt ihn aus der Serie (Ausnahme).')) ?></p>
    </section>
    <?php endif; ?>
    <?php if ($isHead && ($ovs = Series::overrides($t, (int) $e['id']))): // Einzeltermine der Serie ?>
    <section class="adm-card dt-backlinks">
      <h2><?= e(__('Geänderte Termine')) ?></h2>
      <ul><?php foreach ($ovs as $orid => $ov): ?><li><a href="<?= e(url($base . '/' . (int) $ov['id'])) ?>"><?= e(\Core\Data\Calendar::dayLabel(Series::ridTime((string) $orid) ?? new DateTimeImmutable())) ?></a>
        <?= !empty($ov[\Core\Data\Kinds::CANCELLED_FIELD]) ? '<small class="dt-cal__badge">' . e(__('fällt aus')) . '</small>' : '<small class="adm-muted">' . e(__('geändert')) . '</small>' ?></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>
    <?php if (!$isNew && can('data.delete', $t['handle'])): ?>
    <button class="adm-btn adm-btn--small adm-btn--ghost adm-btn--danger-text" form="del" type="submit"><?= e($isHead ? __('Ganze Serie löschen') : __('Eintrag löschen')) ?></button>
    <?php endif; ?>
  </aside>
</form>
<?php if (!$isNew && \Core\Lang::multi()): foreach (\Core\Lang::all() as $code => $label): ?>
<form id="tr-<?= e($code) ?>" method="post" action="<?= e(url($base . '/' . $e['id'] . '/translate')) ?>"><?= csrf_field() ?><input type="hidden" name="lang" value="<?= e($code) ?>"></form>
<?php endforeach; endif; ?>
<?php if (!$isNew): ?>
<?= \Core\Theme::capture(ROOT . '/app/Admin/views/tasks/_context.php', ['link' => 'entry:' . $t['handle'] . ':' . (int) $e['id'], 'back' => $base . '/' . (int) $e['id'], 'part' => 'dialog']) ?>
<?php if (!$isNew): ?><form id="dup" method="post" action="<?= e(url($base . '/' . $e['id'] . '/duplicate')) ?>"><?= csrf_field() ?></form><?php endif; ?>
<form id="del" method="post" action="<?= e(url($base . '/' . $e['id'] . '/delete')) ?>" data-reassign="<?= e(url('/admin/api/neuzuordnung/')) ?>" data-handle="<?= e($t['handle']) ?>" data-id="<?= (int) $e['id'] ?>" data-confirm="„<?= e(Entries::title($t, $e)) ?>“ endgültig löschen?<?= $isHead ? "\n" . e(__('Alle Termine der Serie und ihre geänderten Einzeltermine werden gelöscht.')) : '' ?>"><?= csrf_field() ?></form>
<?php if ($occ !== null): ?>
<form id="occ-cancel" method="post" action="<?= e(url($base . '/' . $e['id'] . '/serie')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="vorkommen" value="<?= e($occKey) ?>"></form>
<form id="occ-remove" method="post" action="<?= e(url($base . '/' . $e['id'] . '/serie')) ?>" data-confirm="<?= e(__('Termin am {date} aus der Serie entfernen?', ['date' => $occWhen])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="scope" value="this"><input type="hidden" name="vorkommen" value="<?= e($occKey) ?>"></form>
<?php endif; ?>
<?php endif; ?>
