<?php
/**
 * Mittlere Spalte des Adressbuchs: Abschnitt der Namen (AddressBook::PAGE ab $offset) mit Buchstaben-Trennern, Links
 * „Vorherige/Weitere anzeigen“ (mit JavaScript beim Scrollen nachgeladen) und Sprungleiste A–Z.
 * @var array $t  @var array $s  @var array $rows  @var int $offset  @var string $q
 */
use Core\Data\AddressBook;

$total = count($rows);
$end = min($total, $offset + AddressBook::PAGE);
$present = array_fill_keys(array_column($rows, '_letter'), true);
?>
<div class="ab-scroll" data-ab-scroll data-total="<?= $total ?>" data-page="<?= AddressBook::PAGE ?>">
  <?php if ($offset > 0): ?>
  <a class="ab-more ab-more--prev" href="<?= e(AddressBook::link($t, $s, ['ab' => (string) max(0, $offset - AddressBook::PAGE)])) ?>" data-ab-more="prev" data-from="<?= max(0, $offset - AddressBook::PAGE) ?>" data-to="<?= $offset ?>"><?= e(__('Vorherige anzeigen')) ?></a>
  <?php endif; ?>
  <?php if (!$rows): ?>
  <div class="ab-empty">
    <?= icon('address-book') ?>
    <p><?= $q !== '' ? e(__('Keine Treffer für „{q}“.', ['q' => $q])) : e(__('Hier sind noch keine Kontakte.')) ?></p>
  </div>
  <?php else: ?>
  <ul class="ab-rows" data-ab-rows aria-label="<?= e(__('Kontakte')) ?>">
    <?= \Core\Theme::capture(__DIR__ . '/_ab_rows.php', get_defined_vars()) ?>
  </ul>
  <?php endif; ?>
  <?php if ($end < $total): ?>
  <a class="ab-more" href="<?= e(AddressBook::link($t, $s, ['ab' => (string) $end])) ?>" data-ab-more="next" data-from="<?= $end ?>"><?= e(__('Weitere anzeigen ({n} übrig)', ['n' => $total - $end])) ?></a>
  <?php endif; ?>
</div>
<?php if ($total > 12): ?>
<nav class="ab-az" aria-label="<?= e(__('Zu Buchstabe springen')) ?>">
  <?php foreach (AddressBook::alphabet() as $l): ?>
  <?php if (isset($present[$l])): ?><a href="<?= e(AddressBook::link($t, $s, ['buchstabe' => $l])) ?>" data-ab-letter="<?= e($l) ?>" aria-label="<?= e(__('Buchstabe {l}', ['l' => $l])) ?>"><?= e($l) ?></a><?php else: ?><span aria-hidden="true"><?= e($l) ?></span><?php endif; ?>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
