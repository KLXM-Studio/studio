<?php
/**
 * Funktionen & Erweiterungen → Blöcke der Website (FeaturesController::blocks): Rahmen für alle Rollen (Core\BlockGuard,
 * Features::allowsBlock). Gespeichert als sys.blocks_off; per Konfiguration gesperrte Typen sind nicht änderbar.
 * @var array $catalog  @var list<string> $on  @var bool $canManage  @var array $usage  Verwendungen je Typ
 */
?>
<header class="adm-head">
  <div><p class="adm-eyebrow"><a href="<?= e(url('/admin/funktionen')) ?>"><?= e(__('Funktionen & Erweiterungen')) ?></a></p>
    <h1><?= e(__('Blöcke der Website')) ?></h1>
    <p class="adm-muted"><?= e(__('Welche Blockarten die Redaktion neu einfügen darf. Das gilt für alle Rollen; in „Benutzer & Rollen“ lässt sich die Auswahl je Rolle weiter einschränken, nie erweitern.')) ?></p></div>
</header>
<div class="ft-hint" role="note">
  <?= icon('info', ['class' => 'ft-hint__ico']) ?>
  <div>
    <p class="ft-hint__h"><?= e(__('Bestehende Blöcke bleiben, wie sie sind.')) ?></p>
    <p><?= e(__('Abgeschaltete Blockarten verschwinden nur aus der Blockauswahl, aus API, MCP und KI. Blöcke, die schon auf Seiten stehen, bleiben sichtbar und bearbeitbar.')) ?></p>
  </div>
</div>
<?php if (!$canManage): ?>
<p class="adm-flash ft-readonly" role="status"><?= icon('lock') ?> <?= e(__('Freischaltung durch die Agentur: Diese Übersicht zeigt, was auf Ihrer Website eingeschaltet ist. Änderungen nimmt die Netzwerk-Administration vor.')) ?></p>
<?php endif; ?>
<form method="post" action="<?= e(url('/admin/funktionen/bloecke')) ?>" class="set-page" novalidate>
  <?= csrf_field() ?>
  <fieldset class="set-group"<?= $canManage ? '' : ' disabled' ?>>
    <legend class="set-group__title"><?= e(__('Blockarten')) ?></legend>
    <?php $checked = $on; $name = 'blocks[]'; $mode = 'frame'; include __DIR__ . '/_block_choice.php'; ?>
    <p class="set-group__note"><?= e(__('Festgelegt per Konfiguration (config/sites/…: blocks.allow bzw. blocks.deny) sind die Arten mit Hinweis – sie lassen sich hier nicht ändern.')) ?>
      <?php $used = array_sum(array_map('intval', $usage)); if ($used): ?><?= e(__('Auf den Seiten stehen zurzeit {n} Blöcke.', ['n' => $used])) ?><?php endif; ?></p>
  </fieldset>
  <?php if ($canManage): ?><div class="adm-savebar"><button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Speichern')) ?></button> <a class="adm-btn" href="<?= e(url('/admin/funktionen')) ?>"><?= e(__('Abbrechen')) ?></a></div><?php endif; ?>
</form>
