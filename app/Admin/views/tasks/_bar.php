<?php
/**
 * Übersicht → „Was ist zu tun?“: „Aufgabe anlegen“ (Dialog, Core\Tasks) und „Alle Aufgaben“. Nur, was die Rolle darf.
 * @var array $user  @var string $back
 */
use Core\Tasks\Tasks;

$tkCan = Tasks::canCreate($user);
$tkB = Tasks::badge($user);
?>
<p class="tk-sum<?= $tkB['warn'] ? ' tk-sum--warn' : '' ?>" role="status">
  <?php if ($tkB['n']): ?><b><?= e(__('{n} offen', ['n' => $tkB['n']])) ?></b><?= $tkB['overdue'] ? ', <b>' . e(__('{n} überfällig', ['n' => $tkB['overdue']])) . '</b>' : '' ?><?= $tkB['others'] ? ' · ' . e(__('dazu {n} im Team', ['n' => $tkB['others']])) : '' ?>
  <?php elseif ($tkB['others']): ?><?= e(__('Nichts für Sie offen')) ?> – <a href="<?= e(url('/admin/aufgaben')) ?>"><?= e(__('{n} offene im Team', ['n' => $tkB['others']])) ?></a>
  <?php else: ?><?= e(__('Keine offenen Aufgaben.')) ?><?php endif; ?>
</p>
<div class="tk-bar">
  <?php if ($tkCan): ?>
  <a class="adm-btn adm-btn--small" href="<?= e(url('/admin/aufgaben/neu?back=' . rawurlencode($back))) ?>" data-task-open="tk-dash-dlg" aria-haspopup="dialog"><?= icon('plus') ?> <?= e(__('Aufgabe anlegen')) ?></a>
  <?php endif; ?>
  <a class="adm-btn adm-btn--small adm-btn--ghost" href="<?= e(url('/admin/aufgaben')) ?>"><?= icon('list-checks') ?> <?= e(__('Alle Aufgaben')) ?></a>
</div>
<?php if ($tkCan): ?>
<?= \Core\Theme::capture(__DIR__ . '/_dialog.php', ['id' => 'tk-dash-dlg', 'back' => $back, 'form' => Tasks::formDefaults($user)]) ?>
<?php endif; ?>
