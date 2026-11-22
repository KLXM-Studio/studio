<?php
/**
 * Auswahl von Blocktypen, gruppiert wie in der Blockauswahl (Core\BlockGuard::catalog) – für „Rolle bearbeiten“ (Erlaubte Blöcke)
 * und „Blöcke der Website“ (Rahmen). Ein Kästchen je Typ, je Gruppe ein Kästchen „alle“ (resources/js/_blockchoice.js).
 * @var array $catalog  @var list<string> $checked  @var string $name  Feldname (z. B. blocks[])
 * @var string $mode  'role' (nur Typen im Rahmen der Website wählbar) | 'frame' (per Konfiguration gesperrte nicht änderbar)
 */
$mode ??= 'role';
?>
<div class="bc" data-blockchoice>
  <?php foreach ($catalog as $group => $types): $gid = 'bc-' . substr(md5($name . $group), 0, 8); ?>
  <fieldset class="set-checks bc__group" aria-labelledby="<?= e($gid) ?>">
    <legend id="<?= e($gid) ?>" class="bc__legend"><span><?= e(__($group)) ?></span>
      <label class="bc__all"><input type="checkbox" data-blockchoice-all> <span><?= e(__('alle')) ?></span></label></legend>
    <?php foreach ($types as $type => $t):
      $off = $mode === 'role' ? !$t['frame'] : $t['config'];
      $hint = $mode === 'role' ? __('für diese Website abgeschaltet') : __('per Konfiguration festgelegt'); ?>
    <label class="f-check<?= $off ? ' is-off' : '' ?>"><input type="checkbox" name="<?= e($name) ?>" value="<?= e($type) ?>"<?= in_array($type, $checked, true) ? ' checked' : '' ?><?= $off ? ' disabled' : '' ?>>
      <span><?= e(__($t['label'])) ?><?php if ($off): ?> <small class="adm-muted">(<?= e($hint) ?>)</small><?php endif; ?></span></label>
    <?php endforeach; ?>
  </fieldset>
  <?php endforeach; ?>
</div>
