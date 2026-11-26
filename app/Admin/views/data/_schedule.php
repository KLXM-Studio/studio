<?php
/**
 * Zeitplan eines Eintrags (Core\Schedule): „Online ab“ hält den Eintrag bis dahin offline, „Offline ab“ nimmt ihn automatisch heraus.
 * Genutzt in der Verwaltung (data/entry.php) und in der Seitenleiste „Eintrag bearbeiten“ der Website (app/Views/entry-panel.php).
 * Nur mit Veröffentlichungsrecht einbinden; gespeichert über DataController::save bzw. EntryEditController::save.
 * @var array $values  @var array $errors
 */
?>
<?php $sFrom = \Core\Schedule::input(\Core\Schedule::norm($values['online_from'] ?? '') ?? ''); $sUntil = \Core\Schedule::input(\Core\Schedule::norm($values['online_until'] ?? '') ?? '');
        $sOpen = $sFrom !== '' || $sUntil !== '' || isset($errors['online_from']) || isset($errors['online_until']); ?>
      <details class="dt-plan"<?= $sOpen ? ' open' : '' ?>>
        <summary><?= icon('clock') ?> <?= e(__('Zeitplan')) ?><?php if ($sFrom !== '' || $sUntil !== ''): ?> <span class="dt-plan__sum"><?= e(\Core\Schedule::label(['online_from' => \Core\Schedule::norm($sFrom), 'online_until' => \Core\Schedule::norm($sUntil)])) ?></span><?php endif; ?></summary>
        <div class="f<?= isset($errors['online_from']) ? ' f--error' : '' ?>"><label for="online_from"><?= e(__('Online ab')) ?></label>
          <input type="datetime-local" id="online_from" name="online_from" value="<?= e($sFrom) ?>" aria-describedby="online_from-h"><?php if (isset($errors['online_from'])): ?><p class="f-error"><?= e($errors['online_from']) ?></p><?php endif; ?>
          <p class="f-help" id="online_from-h"><?= e(__('Bis dahin bleibt der Eintrag offline – auch wenn „Online“ gewählt ist. Leer = sofort.')) ?></p></div>
        <div class="f<?= isset($errors['online_until']) ? ' f--error' : '' ?>"><label for="online_until"><?= e(__('Offline ab')) ?></label>
          <input type="datetime-local" id="online_until" name="online_until" value="<?= e($sUntil) ?>" aria-describedby="online_until-h"><?php if (isset($errors['online_until'])): ?><p class="f-error"><?= e($errors['online_until']) ?></p><?php endif; ?>
          <p class="f-help" id="online_until-h"><?= e(__('Danach ist der Eintrag nicht mehr aufrufbar und erscheint nicht mehr in Listen, Suche und Sitemap. Leer = unbegrenzt.')) ?></p></div>
      </details>
