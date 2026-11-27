<?php /** Daten: Übersicht der Tabellen. @var array $tables  @var array $inboxes  @var array $user */
$inboxes ??= [];
$isAdmin = can('data.schema'); ?>
<header class="adm-head dt-head">
  <h1>Daten</h1>
  <?php if ($isAdmin): ?><div class="adm-row">
    <?php // Ein Einstieg: der Assistent fragt Art, Vorlage (auch „Mit KI beschreiben“) und Grundeinstellungen ?>
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
  // Auswahllisten (Core\Data\OptionSource) sind normale Tabellen, aber keine eigenen Karten: als Chips an jeder Tabelle, die sie nutzt,
  // und gesammelt im Abschnitt „Auswahllisten“ mit „für …“. Karten zeigen zuerst, was die Redaktion braucht (Einträge, zuletzt geändert)
  $byH = array_column($tables, null, 'handle');
  $listsOf = $usersOf = $sharedLists = [];
  foreach ($tables as $t) {
      $users = array_values(array_unique(array_filter(array_map(fn($x) => $x[0]['handle'], \Core\Data\OptionSource::usedBy($t)), fn($h) => $h !== $t['handle'])));
      if (!$users) continue;
      $usersOf[$t['handle']] = $users;
      $sharedLists[] = $t;
      foreach ($users as $u) $listsOf[$u][] = $t;
  }
  $isList = $usersOf;
  $last = function (array $t): string {
      $e = \Core\Data\Entries::query($t, ['status' => 'all', 'sort' => 'updated_at', 'dir' => 'desc', 'limit' => 1])[0] ?? null;
      return $e && !empty($e['updated_at']) ? __('zuletzt geändert {when}', ['when' => fmt()->relative((string) $e['updated_at'])]) : '';
  };
  // „Ansehen“: eigene Übersicht (Wissen, Glossar), sonst die Seite mit der Liste – Unterseiten vor der Startseite; die Detail-Adresse
  // allein (/nachricht) hat keine Übersicht (404). Ohne veröffentlichte Seite kein Knopf.
  $viewUrl = function (array $t): string {
      if (\Core\Data\Knowledge::is($t) && $t['settings']['route'] !== '') return url('/' . trim((string) $t['settings']['route'], '/'));
      if (($g = \Core\Glossary\Glossary::table()) && $g['handle'] === $t['handle'] && ($o = \Core\Glossary\Glossary::overviewUrl())) return $o;
      $pages = array_values(array_filter(\Core\Data\Placement::usages($t), fn($u) => !$u['template'] && $u['status'] === 'published' && $u['url']));
      usort($pages, fn($a, $b) => ($a['url'] === '/') <=> ($b['url'] === '/'));
      return (string) ($pages[0]['url'] ?? '');
  };
  $chip = fn(array $l) => '<a class="dt-listchip" href="' . e(url('/admin/data/' . $l['handle'])) . '">' . icon($l['icon'] ?: 'tag') . ' ' . e($l['name']) . ' <span class="dt-listchip__n">' . (int) $l['count'] . '</span></a>';
?>
<div class="dt-grid">
  <?php foreach ($tables as $t): if (isset($isList[$t['handle']])) continue; $site = $viewUrl($t); ?>
  <article class="dt-card dt-card--c">
    <?php $base = url('/admin/data/' . $t['handle']); $n = (int) $t['count']; $lc = $last($t); ?>
    <a class="dt-card__main" href="<?= e($base) ?>">
      <span class="dt-icon" aria-hidden="true"><?= icon($t['icon']) ?></span>
      <span class="dt-card__head"><strong><?= e($t['name']) ?></strong><small><b><?= $n ?></b> <?= e($n === 1 ? __('Eintrag') : __('Einträge')) ?><?= $lc !== '' ? ' · ' . e($lc) : '' ?></small></span>
    </a>
    <?php if (!empty($listsOf[$t['handle']])): ?><p class="dt-card__lists"><span class="dt-card__listsl"><?= e(__('Listen')) ?>:</span> <?= implode(' ', array_map($chip, $listsOf[$t['handle']])) ?></p><?php endif; ?>
    <div class="dt-card__actions">
      <a class="adm-btn adm-btn--small adm-btn--primary" href="<?= e($base) ?>"><?= icon('list-bullets') ?> <?= e(__('Einträge')) ?></a>
      <a class="adm-btn adm-btn--small" href="<?= e($base . '/new') ?>">+ <?= e($t['singular']) ?></a>
    </div>
    <?php // Weitere Wege als Textlinks in einer Fußzeile; Technik (Art, Felder, Adresse) als Tooltip an „Einrichten“
    $feed = \Core\Data\Feed::available($t);
    $tech = \Core\Data\Purpose::label(\Core\Data\Purpose::of($t)) . ' · ' . count($t['fields']) . ' ' . __('Felder') . ($t['settings']['route'] !== '' ? ' · /' . $t['settings']['route'] . '/…' : '');
    if ($site !== '' || $feed || $isAdmin): ?>
    <p class="dt-card__foot">
      <?php if ($site !== ''): ?><a href="<?= e($site) ?>" target="_blank" rel="noopener" title="<?= e(__('Seite mit dieser Liste auf der Website öffnen (neuer Tab)')) ?>"><?= icon('arrow-square-out') ?> <?= e(__('Website')) ?></a><?php endif; ?>
      <?php if ($feed): ?><a href="<?= e(url(\Core\Data\Feed::path($t))) ?>" target="_blank" rel="noopener" title="<?= e(__('RSS-Feed dieser Tabelle (öffnet in neuem Tab)')) ?>"><?= icon('rss') ?> RSS</a><?php endif; ?>
      <?php if ($isAdmin): ?><a class="dt-card__setup" href="<?= e($base . '/schema') ?>" title="<?= e($tech) ?>"><?= icon('gear-six') ?> <?= e(__('Einrichten')) ?></a><?php endif; ?>
    </p>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
</div>
<?php if ($sharedLists): ?>
<section class="dt-shared-lists"><h2 class="dt-section-title"><?= e(__('Auswahllisten')) ?></h2>
  <ul class="dt-listrows"><?php foreach ($sharedLists as $l): ?><li><?= $chip($l) ?> <span class="adm-muted"><?= e(__('für {tables}', ['tables' => implode(', ', array_map(fn($h) => $byH[$h]['name'] ?? $h, $usersOf[$l['handle']]))])) ?></span></li><?php endforeach; ?></ul></section>
<?php endif; ?>
<?php endif; ?>

<?php if ($inboxes): ?>
<h2 class="dt-section-title"><?= e(__('Eingänge (verschlüsselte Anfragen)')) ?></h2>
<div class="dt-grid">
  <?php foreach ($inboxes as $t): ?>
  <article class="dt-card dt-card--c">
    <a class="dt-card__main" href="<?= e(url('/admin/requests?table=' . $t['handle'])) ?>">
      <span class="dt-icon" aria-hidden="true"><?= icon($t['icon']) ?></span>
      <span><strong><?= e($t['name']) ?></strong><small><?= e(__('{n} Anfragen · {new} neu', ['n' => (int) $t['count'], 'new' => (int) $t['new']])) ?></small></span>
    </a>
    <p class="dt-card__meta"><?= icon(\Core\Data\Purpose::of($t) === 'mail' ? 'paper-plane-tilt' : 'lock') ?> <?= e(\Core\Data\Purpose::of($t) === 'mail' ? __('Nur per E-Mail · nichts gespeichert') : __('Ende-zu-Ende verschlüsselt · nur über das Formular')) ?></p>
    <div class="dt-card__actions">
      <?php if (can('requests.read', $t['handle'])): ?><a class="adm-btn adm-btn--small adm-btn--primary" href="<?= e(url('/admin/requests?table=' . $t['handle'])) ?>"><?= icon('tray') ?> <?= e(__('Im Eingang lesen')) ?></a><?php endif; ?>
    </div>
    <?php if ($isAdmin): ?><p class="dt-card__foot"><a class="dt-card__setup" href="<?= e(url('/admin/data/' . $t['handle'] . '/schema')) ?>" title="<?= e(__('Felder & Einstellungen')) ?>"><?= icon('gear-six') ?> <?= e(__('Einrichten')) ?></a></p><?php endif; ?>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
