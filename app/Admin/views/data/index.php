<?php /** Daten: Übersicht der Tabellen für die Redaktion – Einrichten liegt unter „Einrichtung › Datenmodell“ (data/model.php). @var array $tables  @var array $inboxes  @var array $user */
$inboxes ??= [];
$isAdmin = can('data.schema'); ?>
<header class="adm-head dt-head">
  <h1><?= e(__('Daten')) ?></h1>
  <?php if ($isAdmin): ?><div class="adm-row"><a class="adm-btn adm-btn--ghost" href="<?= e(url(\Core\Data\AdminNav::MODEL_PATH)) ?>"><?= icon('tree-structure') ?> <?= e(__('Datenmodell')) ?></a></div><?php endif; ?>
</header>
<?php if (!$tables && !$inboxes): ?>
<div class="adm-card dt-empty"><p><?= e(__('Noch keine Tabellen. Tabellen wie Aktuelles, Team oder Termine legt die Administration unter „Einrichtung › Datenmodell“ an.')) ?></p>
  <?php if ($isAdmin): ?><p><a class="adm-btn adm-btn--primary" href="<?= e(url('/admin/data/new')) ?>">+ <?= e(__('Neue Tabelle oder Formular')) ?></a></p><?php endif; ?></div>
<?php endif; ?>

<?php if ($tables):
  // Auswahllisten (Core\Data\OptionSource) nicht als eigene Karten: als Chips unter der Tabelle, die sie nutzt; von mehreren genutzte
  // Listen gesammelt darunter. Karten zeigen zuerst, was die Redaktion braucht (Einträge, zuletzt geändert); Technik nur mit data.schema
  $byH = array_column($tables, null, 'handle');
  $listsOf = $sharedLists = [];
  foreach ($tables as $t) {
      $users = array_values(array_unique(array_map(fn($x) => $x[0]['handle'], \Core\Data\OptionSource::usedBy($t))));
      if (count($users) === 1 && isset($byH[$users[0]]) && $users[0] !== $t['handle']) $listsOf[$users[0]][] = $t;
      elseif ($users && \Core\Data\OptionSource::isList($t)) $sharedLists[] = $t;
  }
  $isList = array_flip(array_merge(array_column(array_merge(...array_values($listsOf ?: [[]])), 'handle'), array_column($sharedLists, 'handle')));
  $last = function (array $t): string {
      $e = \Core\Data\Entries::query($t, ['status' => 'all', 'sort' => 'updated_at', 'dir' => 'desc', 'limit' => 1])[0] ?? null;
      return $e && !empty($e['updated_at']) ? __('zuletzt geändert {when}', ['when' => fmt()->relative((string) $e['updated_at'])]) : '';
  };
  $chip = fn(array $l) => '<a class="dt-listchip" href="' . e(url('/admin/data/' . $l['handle'])) . '">' . icon($l['icon'] ?: 'tag') . ' ' . e($l['name']) . ' <span class="dt-listchip__n">' . (int) $l['count'] . '</span></a>';
?>
<div class="dt-grid">
  <?php foreach ($tables as $t): if (isset($isList[$t['handle']])) continue; $site = $t['settings']['route'] !== '' ? url('/' . trim((string) $t['settings']['route'], '/')) : ''; ?>
  <article class="dt-card">
    <a class="dt-card__main" href="<?= e(url('/admin/data/' . $t['handle'])) ?>">
      <span class="dt-icon" aria-hidden="true"><?= icon($t['icon']) ?></span>
      <span><strong><?= e($t['name']) ?></strong><small><?= e((int) $t['count'] === 1 ? __('1 Eintrag') : __('{n} Einträge', ['n' => (int) $t['count']])) ?><?php $lc = $last($t); ?><?= $lc !== '' ? ' · ' . e($lc) : '' ?></small></span>
    </a>
    <?php if (!empty($listsOf[$t['handle']])): ?><p class="dt-card__lists"><span class="dt-card__listsl"><?= e(__('Listen')) ?>:</span> <?= implode(' ', array_map($chip, $listsOf[$t['handle']])) ?></p><?php endif; ?>
    <div class="dt-card__actions">
      <a class="adm-btn adm-btn--small" href="<?= e(url('/admin/data/' . $t['handle'] . '/new')) ?>">+ <?= e($t['singular']) ?></a>
      <?php if ($site !== ''): ?><a class="adm-btn adm-btn--small adm-btn--ghost" href="<?= e($site) ?>" target="_blank" rel="noopener"><?= icon('globe') ?> <?= e(__('Ansehen')) ?></a><?php endif; ?>
      <?php if (\Core\Data\Feed::available($t)): ?><a class="adm-btn adm-btn--small adm-btn--ghost" href="<?= e(url(\Core\Data\Feed::path($t))) ?>" target="_blank" rel="noopener" title="<?= e(__('RSS-Feed dieser Tabelle (öffnet in neuem Tab)')) ?>"><?= icon('rss') ?> RSS</a><?php endif; ?>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php if ($sharedLists): ?>
<section class="dt-shared-lists"><h2 class="dt-section-title"><?= e(__('Auswahllisten')) ?></h2><p class="adm-muted"><?= e(__('Von mehreren Tabellen genutzt.')) ?></p><p><?= implode(' ', array_map($chip, $sharedLists)) ?></p></section>
<?php endif; ?>
<?php endif; ?>

<?php if ($inboxes): ?>
<h2 class="dt-section-title"><?= e(__('Eingänge (verschlüsselte Anfragen)')) ?></h2>
<div class="dt-grid">
  <?php foreach ($inboxes as $t): ?>
  <article class="dt-card">
    <a class="dt-card__main" href="<?= e(url('/admin/requests?table=' . $t['handle'])) ?>">
      <span class="dt-icon" aria-hidden="true"><?= icon($t['icon']) ?></span>
      <span><strong><?= e($t['name']) ?></strong><small><?= e(__('{n} Anfragen · {new} neu', ['n' => (int) $t['count'], 'new' => (int) $t['new']])) ?></small></span>
    </a>
    <p class="dt-card__meta"><?= icon(\Core\Data\Purpose::of($t) === 'mail' ? 'paper-plane-tilt' : 'lock') ?> <?= e(\Core\Data\Purpose::of($t) === 'mail' ? __('Nur per E-Mail · nichts gespeichert') : __('Ende-zu-Ende verschlüsselt · nur über das Formular')) ?></p>
    <div class="dt-card__actions">
      <?php if (can('requests.read', $t['handle'])): ?><a class="adm-btn adm-btn--small" href="<?= e(url('/admin/requests?table=' . $t['handle'])) ?>"><?= e(__('Im Eingang lesen')) ?></a><?php endif; ?>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
