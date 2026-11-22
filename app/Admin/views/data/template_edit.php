<?php
/**
 * Listen-Vorlage anlegen/bearbeiten (Core\Blocks\ListTemplates): Name, Standard und die Darstellungsfelder des Blocks „Datenliste“
 * (gleiches Formular wie in der Seitenleiste, Core\Fields). Vorlagen aus Kit/Erweiterung: nur ansehen, duplizieren.
 * @var array $t  @var array $def  @var ?array $tpl  @var array $errors  @var string $name  @var array $values  @var array $usage  @var bool $isDefault
 */
use Core\Blocks\ListTemplates;
use Core\Fields;

$base = '/admin/data/' . $t['handle'] . '/vorlagen';
$ro = $tpl !== null && $tpl['source'] !== 'db';
?>
<header class="adm-head dt-head">
  <div><p class="adm-eyebrow"><a href="<?= e(url($base)) ?>"><?= e(__('Listen-Vorlagen')) ?></a> · <?= e($t['name']) ?></p>
    <h1><?= e($tpl ? $tpl['name'] : __('Neue Vorlage')) ?></h1>
    <?php if ($ro): ?><p class="adm-muted"><?= e($tpl['source'] === 'kit' ? __('Diese Vorlage bringt das Kit mit – sie lässt sich hier nicht ändern. Mit „Duplizieren“ entsteht eine eigene Kopie.') : __('Diese Vorlage bringt eine Erweiterung mit – sie lässt sich hier nicht ändern. Mit „Duplizieren“ entsteht eine eigene Kopie.')) ?></p><?php endif; ?>
  </div>
  <?php if ($ro): ?>
  <form method="post" action="<?= e(url($base . '/aktion')) ?>"><?= csrf_field() ?><input type="hidden" name="key" value="<?= e($tpl['key']) ?>"><input type="hidden" name="do" value="duplicate">
    <button type="submit" class="adm-btn adm-btn--primary"><?= icon('copy') ?> <?= e(__('Duplizieren')) ?></button></form>
  <?php endif; ?>
</header>

<form method="post" action="<?= e(url($base)) ?>" class="ltpl-edit">
  <?= csrf_field() ?>
  <input type="hidden" name="key" value="<?= e($tpl['key'] ?? '') ?>">
  <input type="hidden" value="<?= e($t['handle']) ?>" data-datatable>
  <div class="ltpl-edit__main adm-card">
    <fieldset class="ltpl-edit__fs"<?= $ro ? ' disabled' : '' ?>>
      <legend class="adm-sr"><?= e(__('Vorlage')) ?></legend>
      <div class="f<?= isset($errors['name']) ? ' f--error' : '' ?>">
        <label for="ltpl-name"><?= e(__('Name')) ?> <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="ltpl-name" name="name" value="<?= e($name) ?>" maxlength="80" required placeholder="<?= e(__('z. B. Neueste als Karten')) ?>"<?= isset($errors['name']) ? ' aria-invalid="true" aria-describedby="ltpl-name-e"' : '' ?>>
        <?php if (isset($errors['name'])): ?><p class="f-error" id="ltpl-name-e"><?= e($errors['name']) ?></p><?php endif; ?>
      </div>
      <div class="f f--bool"><input type="hidden" name="default" value="0">
        <label class="f-check"><input type="checkbox" role="switch" name="default" value="1"<?= $isDefault ? ' checked' : '' ?>> <span><?= e(__('Standard für neue Datenlisten dieser Tabelle')) ?></span></label>
        <p class="f-help"><?= e(__('Neue Listen dieser Tabelle beginnen mit dieser Vorlage; bestehende Blöcke bleiben, wie sie sind.')) ?></p></div>
      <?= Fields::renderForm(ListTemplates::formFields($def), $values, [], 'f') ?>
    </fieldset>
    <?php if (!$ro): ?>
    <div class="adm-form-actions">
      <button type="submit" class="adm-btn adm-btn--primary"><?= e(__('Speichern')) ?></button>
      <a class="adm-btn adm-btn--ghost" href="<?= e(url($base)) ?>"><?= e(__('Abbrechen')) ?></a>
    </div>
    <?php endif; ?>
  </div>
  <aside class="ltpl-edit__side adm-card" aria-label="<?= e(__('Vorschau und Verwendung')) ?>">
    <div class="ltpl-edit__prev"><?= ListTemplates::preview($tpl['base'] ?? ListTemplates::baseOf($values)) ?></div>
    <p class="adm-muted"><?= e(__('Grundform: {base}', ['base' => ListTemplates::baseLabel($tpl['base'] ?? ListTemplates::baseOf($values))])) ?></p>
    <h2 class="ltpl-edit__h"><?= e(__('Verwendet auf')) ?></h2>
    <?php if ($usage): ?>
    <ul class="ltpl-edit__pages">
      <?php foreach ($usage as $pid => $title): ?><li><a href="<?= e(url('/admin/pages/' . (int) $pid)) ?>"><?= e($title) ?></a></li><?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="adm-muted"><?= e(__('Noch auf keiner Seite. Im Editor: Datenliste → „Vorlage“.')) ?></p>
    <?php endif; ?>
    <p class="adm-muted"><?= e(__('Die Redaktion sieht im Block nur Tabelle, Vorlage, Anzahl und „Nur Einträge, bei denen …“. Mit „Anpassen“ löst sie einen einzelnen Block von der Vorlage.')) ?></p>
  </aside>
</form>
