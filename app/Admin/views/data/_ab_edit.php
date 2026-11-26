<?php
/**
 * Kontakt bearbeiten bzw. neu anlegen in der rechten Spalte des Adressbuchs (statt Wechsel ins klassische Formular).
 * Speichert über DataController::entrySave mit then=ab und springt zurück zur Karte – Liste, Filter und Suche bleiben.
 * Versionen, Übersetzen, Duplizieren, Zeitplan und Löschen bietet weiter das vollständige Formular („Alle Optionen“).
 * @var array $t  @var array $s  @var ?array $e  @var bool $edit  @var bool $new  @var string $list  @var array $lists  @var string $filter
 */
use Core\Data\AddressBook;
use Core\Data\Entries;
use Core\Fields;

$base = '/admin/data/' . $t['handle'];
$isNew = !empty($new) || !$e;
$values = $isNew ? [] : $e;
// Neuer Kontakt aus einer Liste heraus: gleich in diese Liste
if ($isNew && $list !== '' && isset($lists[$list])) $values['listen'] = [$list];
$status = (string) ($values['status'] ?? 'published');
$cancel = $isNew ? AddressBook::link($t, $s, ['k' => '', 'neu' => '']) : AddressBook::link($t, $s);
$name = $isNew ? '' : Entries::title($t, $e);
?>
<div class="ab-card__bar">
  <a class="ab-back" href="<?= e($cancel) ?>" data-ab-pane="<?= $isNew ? 'names' : 'card' ?>"><?= icon('arrow-left') ?> <span><?= e(__('Abbrechen')) ?></span></a>
  <?php if (!$isNew): ?><a class="adm-btn adm-btn--small adm-btn--ghost" href="<?= e(url($base . '/' . (int) $e['id'])) ?>"><?= icon('sliders-horizontal') ?> <?= e(__('Alle Optionen')) ?></a><?php endif; ?>
</div>
<div class="ab-card__body ab-edit">
  <h2 class="ab-edit__title"><?= $isNew ? e(__('Neu: {name}', ['name' => $t['singular'] ?: __('Kontakt')])) : e($name) ?></h2>
  <form method="post" action="<?= e(url($isNew ? $base . '/new' : $base . '/' . (int) $e['id'])) ?>" class="dt-entry ab-edit__form" novalidate<?php if ($cond = \Core\Data\Rules::client($t['fields'])): ?> data-conditions="<?= e(json_encode($cond, JSON_UNESCAPED_UNICODE)) ?>"<?php endif; ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="then" value="ab">
    <input type="hidden" name="ab" value="<?= e(AddressBook::link($t, $s, ['bearbeiten' => '', 'neu' => ''])) ?>">
    <?= \Core\Theme::capture(__DIR__ . '/_visibility.php', ['t' => $t, 'values' => $values, 'status' => $status]) ?>
    <div class="adm-fields"><?= Fields::renderForm(Entries::formSchema($t, $values), $values, [], 'f') ?></div>
    <?= \Core\Theme::capture(__DIR__ . '/_network.php', ['t' => $t, 'e' => $isNew ? null : $e, 'values' => $values, 'part' => 'extra', 'action' => null]) ?>
    <?php if ($t['settings']['workflow'] && can('data.publish', $t['handle'])): ?>
    <fieldset class="dt-statuspick"><legend><?= e(__('Status')) ?></legend>
      <label><input type="radio" name="status" value="published"<?= $status === 'published' ? ' checked' : '' ?>> <span class="dt-status dt-status--published"><?= e(__('Online')) ?></span></label>
      <label><input type="radio" name="status" value="draft"<?= $status === 'draft' ? ' checked' : '' ?>> <span class="dt-status dt-status--draft"><?= e(__('Offline')) ?></span></label>
    </fieldset>
    <?php else: ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <div class="adm-row ab-edit__actions">
      <button class="adm-btn adm-btn--primary" type="submit"><?= icon('check') ?> <?= e(__('Speichern')) ?></button>
      <a class="adm-btn adm-btn--ghost" href="<?= e($cancel) ?>"><?= e(__('Abbrechen')) ?></a>
    </div>
  </form>
</div>
