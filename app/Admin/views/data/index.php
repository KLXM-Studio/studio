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
  $pagesOf = function (array $t): array {
      $pages = array_values(array_filter(\Core\Data\Placement::usages($t), fn($u) => !$u['template'] && $u['status'] === 'published' && $u['url']));
      usort($pages, fn($a, $b) => ($a['url'] === '/') <=> ($b['url'] === '/'));
      return $pages;
  };
  $viewUrl = function (array $t): string {
      if (\Core\Data\Knowledge::is($t) && $t['settings']['route'] !== '') return url('/' . trim((string) $t['settings']['route'], '/'));
      if (($g = \Core\Glossary\Glossary::table()) && $g['handle'] === $t['handle'] && ($o = \Core\Glossary\Glossary::overviewUrl())) return $o;
      $pages = array_values(array_filter(\Core\Data\Placement::usages($t), fn($u) => !$u['template'] && $u['status'] === 'published' && $u['url']));
      usort($pages, fn($a, $b) => ($a['url'] === '/') <=> ($b['url'] === '/'));
      return (string) ($pages[0]['url'] ?? '');
  };
  $chip = fn(array $l) => '<a class="dt-listchip" href="' . e(url('/admin/data/' . $l['handle'])) . '">' . icon($l['icon'] ?: 'tag') . ' ' . e($l['name']) . ' <span class="dt-listchip__n">' . (int) $l['count'] . '</span></a>';
?>
<div class="dt-grid dt-grid--w">
  <?php foreach ($tables as $t): if (isset($isList[$t['handle']])) continue;
    $site = $viewUrl($t); $base = url('/admin/data/' . $t['handle']); $n = (int) $t['count'];
    $latest = \Core\Data\Entries::query($t, ['status' => 'all', 'sort' => 'updated_at', 'dir' => 'desc', 'limit' => 1])[0] ?? null;
    $wf = (bool) ($t['settings']['workflow'] ?? true);
    $drafts = $wf && $n ? \Core\Data\Entries::count($t, ['status' => 'draft', 'source' => 'own']) : 0;
    $pages = $pagesOf($t); $lists = $listsOf[$t['handle']] ?? [];
    $feed = \Core\Data\Feed::available($t); ?>
  <article class="dt-card dt-card--w">
    <div class="dt-card__body">
      <a class="dt-card__main" href="<?= e($base) ?>">
        <span class="dt-icon" aria-hidden="true"><?= icon($t['icon']) ?></span>
        <span class="dt-card__head"><strong><?= e($t['name']) ?></strong>
          <span class="dt-card__st<?= $n ? '' : ' is-empty' ?>"><?= e($n === 1 ? __('1 Eintrag') : __('{n} Einträge', ['n' => $n])) ?></span></span>
      </a>
      <?php // Details: Online/Entwurf, zuletzt bearbeiteter Eintrag; Technik nur für Admins ?>
      <ul class="dt-card__facts">
        <?php if ($wf && $n): ?><li><?= e(__('{on} online', ['on' => $n - $drafts])) ?><?= $drafts ? ' · <span class="dt-card__draft">' . e(__('{n} Entwurf', ['n' => $drafts])) . '</span>' : '' ?></li><?php endif; ?>
        <?php if ($latest): ?><li><?= e(__('Zuletzt bearbeitet')) ?>: <a href="<?= e($base . '/' . (int) $latest['id']) ?>"><?= e(\Core\Data\Entries::title($t, $latest)) ?></a><?php if (!empty($latest['updated_at'])): ?> <span class="adm-muted">· <?= e(fmt()->relative((string) $latest['updated_at'])) ?></span><?php endif; ?></li><?php endif; ?>
        <?php if ($isAdmin): ?><li class="dt-card__tech"><?= e(\Core\Data\Purpose::label(\Core\Data\Purpose::of($t))) ?> · <?= count($t['fields']) ?> <?= e(__('Felder')) ?><?= $t['settings']['route'] !== '' ? ' · <code>/' . e($t['settings']['route']) . '/…</code>' : '' ?></li><?php endif; ?>
      </ul>
      <?php // Alle Knöpfe gleich groß und gleich gefärbt, eine Reihe ?>
      <div class="dt-card__bar">
        <a class="adm-btn adm-btn--small" href="<?= e($base) ?>"><?= e(__('Einträge')) ?></a>
        <a class="adm-btn adm-btn--small" href="<?= e($base . '/new') ?>">+ <?= e($t['singular']) ?></a>
        <?php if ($site !== ''): ?><a class="adm-btn adm-btn--small" href="<?= e($site) ?>" target="_blank" rel="noopener" title="<?= e(__('Seite mit dieser Liste auf der Website öffnen (neuer Tab)')) ?>"><?= e(__('Website')) ?></a><?php endif; ?>
        <?php if ($feed): ?><a class="adm-btn adm-btn--small" href="<?= e(url(\Core\Data\Feed::path($t))) ?>" target="_blank" rel="noopener" title="<?= e(__('RSS-Feed dieser Tabelle (öffnet in neuem Tab)')) ?>">RSS</a><?php endif; ?>
        <?php if ($isAdmin): ?><a class="adm-btn adm-btn--small" href="<?= e($base . '/schema') ?>"><?= e(__('Einrichten')) ?></a><?php endif; ?>
        <?php // Schmal: die Seitenspalte als Popover (nativ, ohne Skript) ?>
        <button type="button" class="adm-btn adm-btn--small dt-card__sidebtn" popovertarget="dt-side-<?= e($t['handle']) ?>"><?= e($lists ? __('Listen & Seiten') : __('Seiten')) ?> ▾</button>
      </div>
    </div>
    <?php // Seitenspalte: Auswahllisten der Tabelle und Seiten, auf denen sie steht ?>
    <aside class="dt-card__side" id="dt-side-<?= e($t['handle']) ?>" popover aria-label="<?= e(__('Listen und Seiten von {table}', ['table' => $t['name']])) ?>"><div class="dt-card__sidein" tabindex="0">
      <?php if ($lists): ?>
      <h3><?= e(__('Listen')) ?></h3>
      <ul><?php foreach ($lists as $l): ?><li><a href="<?= e(url('/admin/data/' . $l['handle'])) ?>"><?= icon($l['icon'] ?: 'tag') ?><span><?= e($l['name']) ?></span><small><?= (int) $l['count'] ?></small></a></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <h3><?= e(__('Auf der Website')) ?></h3>
      <?php if ($pages): ?>
      <ul><?php foreach (array_slice($pages, 0, 3) as $pg): ?><li><a href="<?= e((string) $pg['url']) ?>" target="_blank" rel="noopener"><?= icon('file-text') ?><span><?= e($pg['title']) ?></span></a></li><?php endforeach; ?>
        <?php if (count($pages) > 3): ?><li class="adm-muted"><?= e(__('und {n} weitere', ['n' => count($pages) - 3])) ?></li><?php endif; ?></ul>
      <?php else: ?>
      <p class="adm-muted"><?= e(__('Noch auf keiner Seite.')) ?><?php if ($isAdmin && \Core\Data\Placement::blockType($t) !== null): ?> <a href="<?= e($base . '/einsetzen') ?>"><?= e(__('Einsetzen')) ?></a><?php endif; ?></p>
      <?php endif; ?>
    </div></aside>
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
      <?php if (can('requests.read', $t['handle'])): ?><a class="adm-btn adm-btn--small" href="<?= e(url('/admin/requests?table=' . $t['handle'])) ?>"><?= e(__('Im Eingang lesen')) ?></a><?php endif; ?>
    </div>
    <?php if ($isAdmin): ?><p class="dt-card__foot"><a class="dt-card__setup" href="<?= e(url('/admin/data/' . $t['handle'] . '/schema')) ?>" title="<?= e(__('Felder & Einstellungen')) ?>"><?= icon('gear-six') ?> <?= e(__('Einrichten')) ?></a></p><?php endif; ?>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
