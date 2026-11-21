<?php
/**
 * Namen eines Abschnitts (AddressBook::PAGE ab $offset) – auch einzeln nachgeladen (?_teil=mehr&ab=…). Buchstaben-Trenner,
 * wenn sich der Anfangsbuchstabe gegenüber dem vorigen Namen (auch aus dem vorigen Abschnitt) ändert.
 * @var array $t  @var array $s  @var array $rows  @var int $offset  @var ?int $until  @var ?array $e  @var bool $select
 */
use Core\Data\AddressBook;

$end = min(count($rows), $offset + AddressBook::PAGE, isset($until) && $until > $offset ? $until : PHP_INT_MAX);
$sel = $e ? (int) $e['id'] : 0;
for ($i = $offset; $i < $end; $i++):
    $r = $rows[$i];
    $id = (int) $r['id'];
    [$pre, $bold] = AddressBook::nameParts($r);
    $full = trim($pre . ' ' . $bold);
    if ($i === 0 || $rows[$i - 1]['_letter'] !== $r['_letter']): ?>
<li class="ab-letter" data-ab-letter-head="<?= e($r['_letter']) ?>" aria-hidden="true"><?= e($r['_letter']) ?></li>
<?php endif;
    $m = !empty($r['foto']) ? \Core\Media::find((int) $r['foto']) : null;
    $online = AddressBook::online($t, $r);
    $sub = AddressBook::subtitle($t, $r);
?>
<?php $mid = 'ab-row-menu-' . $id; ?>
<li class="ab-item<?= $id === $sel ? ' is-sel' : '' ?>" data-id="<?= $id ?>" data-i="<?= $i ?>"<?= \Core\RowMenu::host($mid) ?>>
  <?php if ($select): ?><input type="checkbox" class="ab-check" data-rm-check form="ab-sel" name="ids[]" value="<?= $id ?>" aria-label="<?= e(__('{name} auswählen', ['name' => $full])) ?>"><?php endif; ?>
  <a class="ab-item__a" href="<?= e(AddressBook::link($t, $s, ['k' => (string) $id])) ?>"<?= $id === $sel ? ' aria-current="true"' : '' ?>>
    <?php if ($m && str_starts_with((string) $m['mime'], 'image/')): ?><img class="ab-ava" src="<?= e(\Core\Media::url($m, 96)) ?>" alt="" loading="lazy" decoding="async"><?php elseif (AddressBook::isOrg($r)): ?><span class="ab-ava ab-ava--org" aria-hidden="true"><?= icon('buildings') ?></span><?php else: ?><span class="ab-ava adm-ava adm-ava--ini adm-ava--c<?= AddressBook::color($r) ?>" aria-hidden="true"><?= e(AddressBook::initials($r)) ?></span><?php endif; ?>
    <span class="ab-item__t"><span class="ab-item__n"><?= $pre !== '' ? e($pre) . ' ' : '' ?><b><?= e($bold) ?></b></span><?php if ($sub !== ''): ?><span class="ab-item__s"><?= e($sub) ?></span><?php endif; ?></span>
    <?php if ($online): ?><span class="ab-item__pub" title="<?= e(__('Auf der Website')) ?>"><?= icon('globe') ?><span class="sr-only"><?= e(__('Auf der Website')) ?></span></span><?php endif; ?>
  </a>
  <?php // Zeilenmenü wie in allen Listen (Core\RowMenu): ⋯, Rechtsklick, Umschalt+F10 ?>
  <?= \Core\RowMenu::render($mid, $full, AddressBook::menu($t, $s, $r), ['class' => 'ab-item__menu']) ?>
</li>
<?php endfor; ?>
