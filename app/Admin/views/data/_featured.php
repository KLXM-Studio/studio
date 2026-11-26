<?php
/**
 * „Auf der Startseite hervorheben“ (Core\Data\Featured) – Schalter mit Reihenfolge und „bis“ (erst nach dem Einschalten sichtbar).
 * Im Eintragsformular der Verwaltung und in der Seitenleiste „Eintrag bearbeiten“ auf der Website.
 * @var array $t  @var array $values  @var array $errors
 */
if (\Core\Data\Featured::enabled($t) && can('data.publish', $t['handle'])):
        $fOn = in_array($values[\Core\Data\Featured::COL] ?? 0, [1, '1', true, 'on'], true); $fOrder = (string) ($values[\Core\Data\Featured::ORDER] ?? ''); $fUntil = (string) ($values[\Core\Data\Featured::UNTIL] ?? '');
        $fErr = fn(string $k) => isset($errors[$k]) ? '<p class="f-error">' . e($errors[$k]) . '</p>' : ''; ?>
      <div class="dt-featured">
        <input type="hidden" name="_featured_form" value="1">
        <div class="f f--bool"><label class="f-check f-check--switch"><input type="checkbox" name="<?= \Core\Data\Featured::COL ?>" value="1" role="switch"<?= $fOn ? ' checked' : '' ?> data-featured-toggle> <span><?= icon('star') ?> <?= e(__('Auf der Startseite hervorheben')) ?></span></label>
          <p class="f-help"><?= e(__('Wirkt nur dort, wo ein Element hervorgehobene Einträge zeigt – z. B. ein Einstieg mit Slider oder der Block „Highlights“.')) ?>
          <?php $fUsed = \Core\Data\Featured::usedOn($t); ?><?= $fUsed ? e(' ' . __('Auf dieser Website: {where}', ['where' => implode('; ', array_map(fn($pg, $bl) => $pg . ' (' . implode(', ', $bl) . ')', array_keys($fUsed), $fUsed))])) : e(' ' . __('Derzeit nutzt kein Element dieser Website hervorgehobene Einträge.')) ?><?php if (($fl = \Core\Data\Featured::label($values)) !== '' && !\Core\Data\Featured::active($values)): ?> <strong><?= e($fl) ?></strong><?php endif; ?></p></div>
        <div class="dt-featured__more" data-featured-more<?= $fOn || isset($errors[\Core\Data\Featured::UNTIL]) || isset($errors[\Core\Data\Featured::ORDER]) ? '' : ' hidden' ?>>
        <div class="f<?= isset($errors[\Core\Data\Featured::ORDER]) ? ' f--error' : '' ?>"><label for="f-featured-order"><?= e(__('Reihenfolge (optional)')) ?></label>
          <input type="number" id="f-featured-order" name="<?= \Core\Data\Featured::ORDER ?>" value="<?= e($fOrder) ?>" step="1" aria-describedby="f-featured-order-h"><?= $fErr(\Core\Data\Featured::ORDER) ?>
          <p class="f-help" id="f-featured-order-h"><?= e(__('Kleinere Zahl = weiter vorne. Leer = nach den nummerierten.')) ?></p></div>
        <div class="f<?= isset($errors[\Core\Data\Featured::UNTIL]) ? ' f--error' : '' ?>"><label for="f-featured-until"><?= e(__('Hervorheben bis (optional)')) ?></label>
          <input type="date" id="f-featured-until" name="<?= \Core\Data\Featured::UNTIL ?>" value="<?= e($fUntil) ?>" aria-describedby="f-featured-until-h"><?= $fErr(\Core\Data\Featured::UNTIL) ?>
          <p class="f-help" id="f-featured-until-h"><?= e(__('Einschließlich. Danach erscheint der Eintrag nicht mehr hervorgehoben, bleibt aber online. Leer = unbegrenzt.')) ?></p></div>
        </div>
      </div>
      <?php endif; ?>
