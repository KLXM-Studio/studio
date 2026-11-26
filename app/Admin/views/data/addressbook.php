<?php
/**
 * Adressbuch-Ansicht einer Kontakte-Tabelle (Core\Data\AddressBook, Controller AddressBookController): Titel, darunter die
 * Ansicht (_ab_app.php) mit eigener Leiste – Suche, „Adressbuch | Tabelle“, Felder, + Kontakt – über den drei Spalten.
 * @var array $t  @var array $s  Zustand (siehe AddressBookController::state)
 */
$base = '/admin/data/' . $t['handle'];
?>
<header class="adm-head ab-head">
  <h1><span aria-hidden="true" class="dt-h1icon"><?= icon($t['icon'] ?: 'address-book') ?></span> <?= e($t['name']) ?><?php if (\Core\Data\Tables::isShared($t)): ?> <span class="dt-nav__shared"><?= e(__('geteilt')) ?></span><?php endif; ?></h1>
</header>
<?= \Core\Theme::capture(__DIR__ . '/_ab_app.php', $s + ['s' => $s]) ?>
