<?php
/** Fehler in der Verwaltung (Admin\ErrorController) @var int $code  @var string $heading  @var string $text */
?>
<header class="adm-head"><h1><?= e($heading) ?></h1></header>
<section class="adm-card adm-errorpage" role="alert">
  <p><?= e($text) ?></p>
  <p class="adm-muted"><?= e(__('Fehlercode {code}', ['code' => $code])) ?></p>
  <p class="adm-row"><a class="adm-btn adm-btn--primary" href="<?= e(url('/admin')) ?>"><?= e(__('Zur Übersicht')) ?></a>
    <button type="button" class="adm-btn adm-btn--ghost" data-history-back><?= e(__('Zurück')) ?></button></p>
</section>
