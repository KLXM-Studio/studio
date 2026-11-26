<?php
/** Upload-Link (Admin\UploadLinkController) @var array $link  @var string $token  @var ?array $done  @var string $error */
?>
<section class="adm-card upl">
  <h1><?= e(__('Datei hochladen')) ?></h1>
  <?php if ($link['label'] !== ''): ?><p class="adm-muted"><?= e($link['label']) ?></p><?php endif; ?>
  <?php if ($done): ?>
  <p class="adm-flash adm-flash--success" role="status"><?= e(__('Hochgeladen: {name}. Sie können zurück in den Chat – der Assistent bindet die Datei jetzt ein.', ['name' => $done['display'] ?? ''])) ?></p>
  <?php else: ?>
  <?php if ($error !== ''): ?><p class="adm-flash adm-flash--error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" action="<?= e(url('/admin/hochladen/' . $token)) ?>"><?= csrf_field() ?>
    <div class="f"><label for="upl-file"><?= e($link['kind'] === 'image' ? __('Bild wählen oder hierher ziehen') : __('Datei wählen oder hierher ziehen')) ?></label>
      <input id="upl-file" type="file" name="datei" required<?= $link['kind'] === 'image' ? ' accept="image/*"' : '' ?>></div>
    <p class="f-help"><?= e(__('Der Link gilt 30 Minuten für eine Datei. Die Datei landet in der Mediathek dieser Website.')) ?></p>
    <p><button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Hochladen')) ?></button></p>
  </form>
  <?php endif; ?>
</section>
