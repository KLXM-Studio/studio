<?php
/**
 * Mit einem Agentur-Hub verbinden (Core\InstanceEnroll): Einmal-Code erzeugen, den der Hub einmal einlöst – er richtet die
 * Anmeldung über den Anbieter und die Überwachung aller Websites ein. Nur Einzelinstallation bzw. Netzwerk-Website (in _oidc.php).
 */
$hcCode = app()->session->get('enroll_code');
app()->session->forget('enroll_code');   // nur einmal anzeigen
$hcCode = is_array($hcCode) && (int) ($hcCode['at'] ?? 0) > time() - 600 ? (string) $hcCode['code'] : null;
$hcPending = \Core\InstanceEnroll::pending();
$hcDone = \Core\InstanceEnroll::done();
?>
<div class="set-group us-hub" id="hub-verbinden">
  <h2 class="set-group__title"><?= e(__('Mit einem Agentur-Hub verbinden')) ?></h2>
  <p class="set-group__note"><?= e(__('Erzeugen Sie einen Einmal-Code und fügen Sie ihn im Hub unter „Instanz per Code verbinden“ ein. Der Hub richtet damit die Anmeldung über den Anbieter und die Überwachung aller Websites ein – ohne dass jemand Schlüssel abtippt. Der Code gilt 15 Minuten und nur einmal.')) ?></p>
  <?php if ($hcDone): ?><p class="adm-muted"><?= e(__('Zuletzt verbunden mit {issuer} am {date} ({n} Websites überwacht).', ['issuer' => (string) $hcDone['issuer'], 'date' => fmt()->datetime((int) $hcDone['at']), 'n' => (int) ($hcDone['sites'] ?? 0)])) ?></p><?php endif; ?>
  <?php if ($hcCode !== null): ?>
    <div class="f"><label for="hc-code"><?= e(__('Einmal-Code')) ?></label>
      <textarea id="hc-code" rows="3" readonly class="adm-mono" aria-describedby="hc-code-h"><?= e($hcCode) ?></textarea>
      <p class="f-help" id="hc-code-h"><?= e(__('Jetzt kopieren – der Code wird nur einmal angezeigt.')) ?></p></div>
  <?php elseif ($hcPending): ?>
    <p class="adm-muted"><?= e(__('Ein Code ist offen bis {time}.', ['time' => fmt()->time($hcPending['expires'])])) ?></p>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/admin/users/hub-code')) ?>" class="us-hub__actions">
    <?= csrf_field() ?>
    <button class="adm-btn adm-btn--small adm-btn--primary" type="submit"><?= e($hcPending ? __('Neuen Code erzeugen') : __('Einmal-Code erzeugen')) ?></button>
    <?php if ($hcPending): ?><button class="adm-btn adm-btn--small" type="submit" name="cancel" value="1"><?= e(__('Code verwerfen')) ?></button><?php endif; ?>
  </form>
</div>
