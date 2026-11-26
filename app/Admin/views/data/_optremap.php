<?php
/**
 * Rückfrage beim Speichern des Schemas (Core\Data\OptionRemap): entfernte Auswahl-Optionen, die Einträge noch haben – je Option
 * „neu zuordnen zu [Option]“ oder „leer lassen“. Steht im Formular des Tabellen-Designers bzw. der Seitenleiste „Felder bearbeiten“.
 * @var list<array> $askOpts
 */
if (empty($askOpts)) return;
?>
<div class="adm-flash adm-flash--error dt-optask" role="alert">
  <p><b><?= e(__('Diese Auswahl-Optionen werden noch benutzt.')) ?></b> <?= e(__('Bitte wählen Sie, was mit den Einträgen geschehen soll, und speichern Sie erneut.')) ?></p>
  <input type="hidden" name="opt_confirm" value="1">
  <?php foreach ($askOpts as $i => $o): $sid = 'dt-optask-' . $i; ?>
  <div class="dt-optask__row">
    <label for="<?= e($sid) ?>"><?= e($o['count'] === 1
      ? __('1 Eintrag hat „{option}“ (Feld {field}):', ['option' => $o['option'], 'field' => $o['label']])
      : __('{n} Einträge haben „{option}“ (Feld {field}):', ['n' => $o['count'], 'option' => $o['option'], 'field' => $o['label']])) ?></label>
    <select id="<?= e($sid) ?>" name="optmap[<?= e($o['id']) ?>][<?= e($o['key']) ?>]">
      <option value=""><?= e(__('Leer lassen')) ?></option>
      <?php foreach ($o['choices'] as $k => $lbl): ?>
      <option value="<?= e((string) $k) ?>"><?= e(__('Neu zuordnen zu „{option}“', ['option' => $lbl])) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endforeach; ?>
</div>
