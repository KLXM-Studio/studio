<?php
/**
 * Schieber „Schriftgröße der Verwaltung“ (Core\UiScale) – im Konto-Menü der Kopfleiste (_topbar.php) und unter
 * Konto → Darstellung (account.php). Live-Vorschau und Speichern per fetch: resources/js/_uiscale.js; ohne JavaScript
 * speichert der Knopf das Formular. Stile: resources/css/_uiscale.css.
 * @var array $user  @var string $usCtx  'pop' (Kopfleiste) | 'page' (Konto)
 */
use Core\UiScale;

$usCtx ??= 'page';
$usFs = UiScale::get($user);
$usId = 'us-' . $usCtx;
$usText = fn(int $s) => $s . ' %';
?>
<form class="us-ctl us-ctl--<?= e($usCtx) ?>" method="post" action="<?= e(url('/admin/account/fontsize')) ?>" data-uiscale
  data-steps="<?= e(implode(',', UiScale::STEPS)) ?>" data-default="<?= UiScale::DEFAULT ?>" data-saved-text="<?= e(__('Gespeichert')) ?>" data-error-text="<?= e(__('Nicht gespeichert – bitte erneut versuchen.')) ?>">
  <?= csrf_field() ?>
  <div class="us-ctl__head">
    <label class="us-ctl__label" for="<?= e($usId) ?>"><?= e(__('Schriftgröße der Verwaltung')) ?></label>
    <output class="us-ctl__val" for="<?= e($usId) ?>" data-uiscale-out aria-hidden="true"><?= e($usText($usFs)) ?></output>
  </div>
  <div class="us-ctl__row">
    <span class="us-ctl__a us-ctl__a--s" aria-hidden="true">A</span>
    <input type="range" id="<?= e($usId) ?>" name="step" min="0" max="<?= count(UiScale::STEPS) - 1 ?>" step="1" value="<?= UiScale::index($usFs) ?>"
      aria-valuetext="<?= e($usText($usFs)) ?>" aria-describedby="<?= e($usId) ?>-h" data-uiscale-range>
    <span class="us-ctl__a us-ctl__a--l" aria-hidden="true">A</span>
  </div>
  <div class="us-ctl__foot">
    <p class="us-ctl__help" id="<?= e($usId) ?>-h"><?= e(__('Text und Bedienelemente der Verwaltung – nur für Sie, wirkt sofort.')) ?> <span data-uiscale-status role="status"></span></p>
    <button class="adm-btn adm-btn--small adm-btn--ghost" type="submit" name="step" value="<?= UiScale::index(UiScale::DEFAULT) ?>" data-uiscale-reset<?= $usFs === UiScale::DEFAULT ? ' disabled' : '' ?>><?= e(__('Zurücksetzen')) ?></button>
    <button class="adm-btn adm-btn--small" type="submit" data-uiscale-save><?= e(__('Speichern')) ?></button>
  </div>
</form>
