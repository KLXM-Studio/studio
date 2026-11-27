<?php /** Daten: Übersicht der Tabellen. @var array $tables  @var array $inboxes  @var array $presets  @var array $user */
$inboxes ??= [];
$isAdmin = can('data.schema'); ?>
<header class="adm-head dt-head">
  <h1>Daten</h1>
  <?php if ($isAdmin): ?><div class="adm-row">
    <?php if (\Core\AI\Assist::available('text') && can('data.schema') && \Core\Features::on('data.schema')): ?><a class="adm-btn kia-btn" href="<?= e(url('/admin/ai/tabellen')) ?>"><span class="kia-spark" aria-hidden="true"><?= icon('sparkle') ?></span> <?= e(__('Tabelle generieren')) ?></a><?php endif; ?>
    <a class="adm-btn adm-btn--primary" href="<?= e(url('/admin/data/new')) ?>">+ <?= e(__('Neue Tabelle oder Formular')) ?></a></div><?php endif; ?>
</header>

<details class="adm-card dt-howto"<?= $tables ? '' : ' open' ?>>
  <summary>So funktioniert’s – in drei Schritten</summary>
  <p class="adm-muted dt-howto__lead">Eigene Inhaltstypen wie Aktuelles, Team, Produkte oder Termine – ohne Programmieren. Ausgabe auf der Website mit dem Block <b>Datenliste</b>, Detailseiten entstehen automatisch.</p>
  <ol class="dt-steps">
    <li><span class="dt-step">1</span><strong>Tabelle = Bauplan</strong><p>Sie legen fest, <em>welche Angaben</em> ein Eintrag hat – z. B. bei „Aktuelles“: Titel, Datum, Bild, Text, Kategorie. Einmalig, durch die Administration.</p></li>
    <li><span class="dt-step">2</span><strong>Einträge = Inhalte</strong><p>Die Redaktion füllt den Bauplan beliebig oft aus: jeder Beitrag, jede Person, jedes Produkt ist ein Eintrag. Wie ein Formular – ganz ohne Gestaltung.</p></li>
    <li><span class="dt-step">3</span><strong>Ausgabe = Gestaltung</strong><p><b>Übersicht:</b> Block „Datenliste“ auf einer beliebigen Seite. <b>Detailseite:</b> eine Vorlage, die Sie wie jede Seite mit Blöcken gestalten – bei jedem Feld wählen Sie mit <b><?= icon('link', ['label' => 'Kette']) ?></b>, welches Feld des Eintrags dort erscheint (Bild → „Bild“, Überschrift → „Titel“ …). Die Vorlage gilt automatisch für alle Einträge.</p></li>
  </ol>
</details>

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
    <?php if ($isAdmin): ?><p class="dt-card__meta"><?= e(\Core\Data\Purpose::label(\Core\Data\Purpose::of($t))) ?> · <?= count($t['fields']) ?> <?= e(__('Felder')) ?> · <?= $t['settings']['route'] !== '' ? 'Detailseiten unter <code>/' . e($t['settings']['route']) . '/…</code>' : e(__('ohne Detailseiten')) ?><?php if (\Core\Data\Feed::available($t)): ?> · <a class="dt-feed" href="<?= e(url(\Core\Data\Feed::path($t))) ?>" target="_blank" rel="noopener" title="<?= e(__('RSS-Feed dieser Tabelle (öffnet in neuem Tab)')) ?>"><?= icon('rss') ?> RSS</a><?php endif; ?></p><?php endif; ?>
    <?php if (!empty($listsOf[$t['handle']])): ?><p class="dt-card__lists"><span class="dt-card__listsl"><?= e(__('Listen')) ?>:</span> <?= implode(' ', array_map($chip, $listsOf[$t['handle']])) ?></p><?php endif; ?>
    <div class="dt-card__actions">
      <a class="adm-btn adm-btn--small" href="<?= e(url('/admin/data/' . $t['handle'] . '/new')) ?>">+ <?= e($t['singular']) ?></a>
      <?php if ($site !== ''): ?><a class="adm-btn adm-btn--small adm-btn--ghost" href="<?= e($site) ?>" target="_blank" rel="noopener"><?= icon('globe') ?> <?= e(__('Ansehen')) ?></a><?php endif; ?>
      <?php if ($isAdmin): ?><a class="adm-btn adm-btn--small adm-btn--ghost" href="<?= e(url('/admin/data/' . $t['handle'] . '/schema')) ?>"><?= e(__('Felder & Einstellungen')) ?></a><?php endif; ?>
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
      <?php if ($isAdmin): ?><a class="adm-btn adm-btn--small adm-btn--ghost" href="<?= e(url('/admin/data/' . $t['handle'] . '/schema')) ?>">Felder &amp; Einstellungen</a>
      <a class="adm-btn adm-btn--small adm-btn--ghost" href="<?= e(url('/admin/data/' . $t['handle'] . '/einsetzen')) ?>"><?= e(__('Einsetzen')) ?></a><?php endif; ?>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<section class="adm-card dt-presets">
  <h2><?= $tables ? 'Weitere Tabelle aus Vorlage' : 'Mit einer Vorlage starten' ?></h2>
  <p class="adm-muted">Vorlagen enthalten sinnvolle Felder – alles lässt sich danach anpassen.</p>
  <div class="dt-preset-grid">
    <?php foreach ($presets as $key => $p): ?>
    <a class="dt-preset" href="<?= e(url('/admin/data/new?preset=' . $key)) ?>"><span class="dt-icon" aria-hidden="true"><?= icon($p['icon']) ?></span><strong><?= e($p['name']) ?></strong>
      <small><?= e(implode(', ', array_column(array_slice($p['fields'], 0, 4), 'label'))) ?> …</small></a>
    <?php endforeach; ?>
    <?php if (\Core\AI\Assist::available('text') && can('data.schema')): ?><a class="dt-preset dt-preset--ai" href="<?= e(url('/admin/ai/tabellen')) ?>"><span class="dt-icon" aria-hidden="true"><?= icon('sparkle') ?></span><strong><?= e(__('Mit KI generieren')) ?></strong><small><?= e(__('Beschreiben, was Sie brauchen')) ?></small></a><?php endif; ?>
    <a class="dt-preset dt-preset--empty" href="<?= e(url('/admin/data/new')) ?>"><span class="dt-icon" aria-hidden="true"><?= icon('plus') ?></span><strong><?= e(__('Mit dem Assistenten')) ?></strong><small><?= e(__('Art wählen, Grundeinstellungen, einsetzen')) ?></small></a>
  </div>
</section>
<?php endif; ?>
