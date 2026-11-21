<?php
/**
 * Adressbuch-Ansicht einer Kontakte-Tabelle (Core\Data\AddressBook, Controller AddressBookController): Kopf mit Umschalter
 * „Adressbuch | Liste“ (je Konto gemerkt), darunter die drei Spalten (_ab_app.php).
 * @var array $t  @var array $s  Zustand (siehe AddressBookController::state)
 */
$base = '/admin/data/' . $t['handle'];
?>
<header class="adm-head ab-head">
  <h1><span aria-hidden="true" class="dt-h1icon"><?= icon($t['icon'] ?: 'address-book') ?></span> <?= e($t['name']) ?><?php if (\Core\Data\Tables::isShared($t)): ?> <span class="dt-nav__shared"><?= e(__('geteilt')) ?></span><?php endif; ?></h1>
  <div class="adm-row">
    <nav class="fx-seg dt-seg ab-views" aria-label="<?= e(__('Ansicht')) ?>">
      <a href="<?= e(url($base) . '?view=book') ?>" aria-current="true"><?= icon('address-book') ?> <?= e(__('Adressbuch')) ?></a>
      <a href="<?= e(url($base) . '?view=list') ?>"><?= icon('list-bullets') ?> <?= e(__('Tabelle')) ?></a>
    </nav>
    <?php if (can('data.schema') && \Core\Data\Shared::canSchema($t)): ?><a class="adm-btn adm-btn--ghost ab-hide-s" href="<?= e(url($base . '/schema')) ?>"><?= icon('gear-six') ?> <?= e(__('Felder')) ?></a><?php endif; ?>
    <a class="adm-btn adm-btn--primary" href="<?= e(url($base . '/new')) ?>"><?= icon('plus') ?> <?= e($t['singular'] ?: __('Kontakt')) ?></a>
  </div>
</header>
<?= \Core\Theme::capture(__DIR__ . '/_ab_app.php', $s + ['s' => $s]) ?>
