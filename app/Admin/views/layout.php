<?php
/** Admin-Layout. @var string $content  @var ?array $user  @var array $flash  @var string $view */
$dataOk = $user && (can('data.schema') || array_filter(\Core\Data\Tables::content(), fn($t) => can('data.edit', $t['handle'])));
// Anfragen = Eingangs-Tabellen, die der Benutzer lesen darf (Recht requests.read, optional je Tabelle)
$inboxes = $user && can('requests.read') ? \Core\Data\Inbox::readable() : [];
// Netzwerk-Administration (Core\Network): „Netzwerk“ auf der Netzwerk-Website, „Website wechseln“ auf allen anderen
$netUser = $user && \Core\Network\Network::isNetworkUser($user);
// Netzwerk-Bereich (Core\Network\Area, mehrere Websites): eigene Seitenleiste, Kopfleiste in Petrol; der Weg dorthin ist der
// Umschalter Netzwerk ↔ Website links in der Kopfleiste (_areaswitch.php). Einzelinstallation: „Netzwerk“ bleibt Menüpunkt wie bisher.
$netMulti = $netUser && \Core\Network\Area::active();
$netArea = $user && \Core\Network\Area::here();
// Kopfleiste (_topbar.php): Testumgebung und „Ansicht als Rolle/Person“ (Core\Impersonation) stehen dort statt in Streifen
$tbEnv = environment() !== 'production' ? environment() : '';
$tbImp = $user ? (app()->auth->user()['_imp'] ?? null) : null;
$nav = array_values(array_filter([
    ['/admin/network', __('Netzwerk'), 'network', $netUser && !$netMulti && \Core\Network\Network::isNetworkSite()],
    ['/admin', __('Übersicht'), 'dashboard', true],
    ['/admin/pages', __('Seiten'), 'pages', $user && can('pages.edit')],
    // Entwürfe (Core\Review\Drafts): offene Seiten- und Eintrags-Entwürfe prüfen, veröffentlichen, verwerfen
    ['/admin/entwuerfe', __('Entwürfe'), 'drafts', $user && \Core\Review\Drafts::canView()],
    // Website-Angaben (/admin/settings) stehen unter „Einrichtung › Website“ (Core\AdminPages::groups)
    ['/admin/media', __('Medien'), 'media', $user && can('media.upload')],
    ['/admin/data', __('Daten'), 'data', (bool) $dataOk],
    // Kontakte (Core\Data\AddressBook): sobald es eine Kontakte-Tabelle gibt, die das Konto pflegen darf – öffnet das Adressbuch
    ['/admin/kontakte', __('Kontakte'), 'contacts', $user && \Core\Data\AddressBook::tables()],
    // Glossar (Core\Glossary) ist eine Einstellungsseite (Core\AdminPages, kind settings): Sammelseite „Einstellungen“ und an der Tabelle „glossar“
    ['/admin/requests', __('Eingang'), 'requests', (bool) $inboxes || ($user && can('requests.read') && can('data.schema') && \Core\Data\Inbox::available())],
    // Support & Wissensdatenbank: im Konto-Menü der Kopfleiste (views/_topbar.php)
    // Chat zwischen Benutzern (Core\Chat, optional) – öffnet mit JavaScript die Schublade (resources/js/userchat.js)
    ['/admin/chat', __('Chat'), 'chat', $user && \Core\Chat\Chat::canUse()],
    // Mitteilungen (Core\Push, Funktion „push“): verfassen, Verlauf, Kanäle, Statistik
    ['/admin/mitteilungen', __('Mitteilungen'), 'push', $user && \Core\Push\Push::on() && can('push.view')],
    // KI-Bereich (Core\AI, Marke config 'ai_brand' – Standard „KLXM AI“)
    ['/admin/ai', \Core\AI\Assist::brand(), 'ai', $user && \Core\AI\Assist::navVisible()],
    // Prüf-Ebene „Eingereicht“ (Core\Review) – eigener Punkt nur, wenn der KI-Bereich nicht sichtbar ist
    ['/admin/ai/eingereicht', __('Eingereicht'), 'review', $user && \Core\Review\Queue::canReview() && !\Core\AI\Assist::navVisible()],
    // Seiten von Funktionen und Erweiterungen (Core\AdminPages): hier nur Inhalte und Werkzeuge – Einstellungen und Statistiken
    // stehen gesammelt unter „Einstellungen“ bzw. „Statistiken“ (Administration)
    ...($user ? \Core\AdminPages::nav('main') : []),
], fn($n) => $n[3]));
// Abschnitt „Administration“: nur aufklappbare Gruppen „Einstellungen“ und „Werkzeuge“ (Core\AdminPages::groups) – leere entfallen,
// eine Gruppe mit nur einem Punkt erscheint als einfacher Link (z. B. Redaktion, die nur das Glossar einstellen darf)
$adminGroups = $user ? \Core\AdminPages::groups() : [];
// Erweiterungen (Filter admin.nav): Seitenleiste vereinfachen/umsortieren – nur Darstellung, Rechte prüft jede Seite selbst
if ($user && \Core\Extensions::filters('admin.nav')) {
    $nf = \Core\Extensions::filter('admin.nav', ['main' => $nav, 'groups' => $adminGroups], ['user' => $user, 'view' => $view]);
    if (is_array($nf)) {
        $nav = array_values(array_filter((array) ($nf['main'] ?? $nav), fn($n) => is_array($n) && isset($n[0], $n[1], $n[2]) && ($n[3] ?? true)));
        $adminGroups = array_values((array) ($nf['groups'] ?? $adminGroups));
    }
}
if ($netArea) { $nav = []; $adminGroups = []; }   // Netzwerk-Bereich: nur die Seitenleiste des Netzwerks (unten)
$adminNav = array_merge([], ...array_column($adminGroups, 'items'));
$section = explode('/', $view)[0];
// Seite einer Sammelseite (Einstellungen/Statistiken, Core\AdminPages)? Dann ist deren Menüpunkt der aktuelle
$reqPath = $user && app()->request ? (string) app()->request->path : '';
$hubKind = $reqPath !== '' ? (\Core\AdminPages::match($reqPath)['kind'] ?? '') : '';
$hubKind = in_array($hubKind, ['settings', 'stats'], true) ? $hubKind : '';
// Unterseite einer Sammelseite (z. B. Einstellungen von KLXM Check): im Menü eingerückt unter der Sammelseite, über dem Inhalt ein Rückweg –
// sonst wäre die Seite eine Sackgasse
$hubPage = $hubKind !== '' ? \Core\AdminPages::match($reqPath) : null;
if ($hubPage && in_array(rtrim($hubPage['href'], '/'), [\Core\AdminPages::HUB, \Core\AdminPages::STATS], true)) $hubPage = null;
// Aktueller Menüpunkt: Sammelseite > Adresse (mit $href, Administration) > Bereich der Ansicht
// Genauester Menüpunkt zur Adresse (z. B. /admin/system/domain vor /admin/system) – der übergeordnete ist dann nicht aktuell
$bestHref = '';
foreach ($adminNav as $n) if ($reqPath !== '' && ($reqPath === $n[0] || str_starts_with($reqPath, $n[0] . '/')) && strlen($n[0]) > strlen($bestHref)) $bestHref = $n[0];
$isCur = function (string $key, string $href = '') use ($hubKind, $reqPath, $section, $bestHref, $view): bool {
    if ($hubKind !== '') return ($key === 'prefs' && $hubKind === 'settings') || ($key === 'stats' && $hubKind === 'stats');
    if ($href !== '' && $bestHref !== $href && str_starts_with($bestHref, $href . '/')) return false;
    if ($href !== '' && ($reqPath === $href || str_starts_with($reqPath, $href . '/'))) return true;
    if (in_array($key, ['prefs', 'stats'], true)) return false;   // beide Sammelseiten liegen in views/prefs – nur über die Adresse
    // Adressbuch-Ansicht: „Kontakte“ statt „Daten“ ist der aktuelle Menüpunkt
    if ($key === 'contacts' || ($key === 'data' && $view === 'data/addressbook')) return $view === 'data/addressbook' && $key === 'contacts';
    return $section === $key || ($key === 'users' && $section === 'role');
};
$newReq = 0;
foreach ($inboxes as $ib) $newReq += \Core\Data\Inbox::count($ib, 'neu');
// Warnungen an Unterpunkten der Einrichtung (Punkt am Unterpunkt, die Gruppe zeigt ihn mit): Schlüssel → Grund
$navWarn = [];
if ($user && can('system.manage')) {
    if (environment() !== 'production') $navWarn['system'] = __('Testumgebung (Staging) aktiv');
    elseif ($inboxes && !\Core\FormCrypto::ready() && \Core\Data\Inbox::anyNeedsKey()) $navWarn['system'] = __('Kein Schlüssel für verschlüsselte Anfragen');
}
[$chatN, $chatAt] = $user ? \Core\Chat\Chat::navCount() : [0, 0];   // Chat: ungelesen + Erwähnungen (live: resources/js/userchat.js)
$supportN = $user ? \Core\Support\Support::navCount() : 0;   // ungelesene Antworten bzw. (Team) neue Meldungen
$reviewN = $user && \Core\Review\Queue::canReview() ? \Core\Review\Queue::pendingCount() : 0;   // offene Einreichungen (API, MCP, KI)
$draftsN = $user && in_array('drafts', array_column($nav, 2), true) ? \Core\Review\Drafts::count() : 0;   // offene Entwürfe (Seiten, Einträge)
// Favoriten (Core\Favorites): Liste + Vorschlag für den Stern dieser Seite (Adresse, Titel, Symbol) – resources/js/_favorites.js
$favs = $user ? \Core\Favorites::all((int) $user['id']) : [];
$favHere = $favTitle = $favIcon = '';
if ($user && ($req = app()->request)) {
    $favQ = $req->query;
    $favT = isset($t) && is_array($t) ? $t : (isset($table) && is_array($table) ? $table : null);
    // Kalender-Tabellen merken die Ansicht in der Sitzung – im Favoriten steht sie ausdrücklich
    if ($view === 'data/entries' && $favT && \Core\Data\Calendar::enabled($favT)) $favQ['view'] = empty($cal) ? 'list' : 'calendar';
    $favHere = (string) \Core\Favorites::normalize($req->path . ($favQ ? '?' . http_build_query($favQ) : ''));
    $favTitle = match (true) {
        $view === 'dashboard' => __('Übersicht'),
        $view === 'help/technical' => __('Technische Dokumentation'),
        $section === 'help' => __('Handbuch'),
        $favT !== null && $view === 'data/entries' => $favT['name'] . (empty($cal) ? '' : ' · ' . __('Kalender')),
        $favT !== null && $view === 'data/entry' => $favT['name'] . ' · ' . ($title ?? ''),
        $favT !== null && $view === 'data/schema' => $favT['name'] . ' · ' . __('Felder & Einstellungen'),
        default => (string) ($title ?? ''),
    };
    // Symbol des Favoriten: Symbol der Tabelle (Symbolname, Core\Icons) bzw. Menü-Schlüssel des Bereichs
    $favIcon = $favT !== null && trim((string) ($favT['icon'] ?? '')) !== '' ? (string) $favT['icon']
        : (['role' => 'users', 'account' => 'account', 'help' => 'help'][$section] ?? $section);
}
?><!doctype html>
<html lang="<?= e(\Core\I18n::locale()) ?>" class="adm-ui" data-icons="<?= e(\Core\Icons::sprite()) ?>"<?= ($icoTopics = \Core\Icons::enabledTopics()) ? ' data-icons-topics="' . e(implode(' ', $icoTopics)) . '"' /* Symbolbereiche (Grundeinstellungen) */ : '' ?><?= in_array($user['appearance'] ?? '', ['light', 'dark'], true) ? ' data-theme="' . e($user['appearance']) . '"' : '' ?><?= !$user && \Core\AuthScreen::forceDark() ? ' data-theme="dark"' /* Anmeldung nachts immer dunkel */ : '' ?><?= $user ? \Core\Accent::attrs($user) /* persönliche Akzentfarbe (Konto) */ : '' ?><?= $user && environment() !== 'production' ? ' data-env="' . e(environment()) . '"' /* Testumgebung: oranger Punkt an „Grundeinstellungen“ */ : '' ?><?= \Core\DatetimePicker::adminAttr() /* Datums-/Zeitauswahl (Grundeinstellungen) */ ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? __('Verwaltung')) ?> · <?= e(site_name()) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<?php foreach ($css ?? [] as $c): ?><link rel="stylesheet" href="<?= e(asset($c)) ?>">
<?php endforeach; ?>
<?= $user ? '' : \Core\AuthScreen::head() /* Bildschirme vor der Anmeldung: Farbhimmel nach Tageszeit + Markenfarbe (resources/css/auth.css) */ ?>
<link rel="icon" href="<?= e(base_path()) ?>/favicon.ico?v=<?= e(\Core\AppIcons::version()) ?>" sizes="48x48">
<script type="application/json" id="cms-i18n"><?= json_encode(\Core\I18n::dictionary(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?= $user ? \Core\AI\Assist::clientScript() /* KI-Assistent (resources/js/_ai.js) – leer ohne Recht/KI */ : '' ?>
<?= $user ? \Core\AI\Assistant::clientScript() /* Assistent-Chat der Redaktion (resources/js/_assistant.js → assistant.mjs) – leer ohne Recht/KI */ : '' ?>
<?= $user ? \Core\RichText::clientScript() /* Formatierungsleiste: Stile und Farben des Kits (resources/js/_rte.js) */ : '' ?>
<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
<?= $user ? \Core\Chat\Chat::head() /* Chat zwischen Benutzern (resources/js/userchat.js, css/userchat.css) – leer ohne Recht; nach admin.js (Symbol-Index) */ : '' ?>
<?php if ($user): // Diagramme (Core\Charts): Lader immer – nachgeladene Karten bringen keine eigenen Skripte mit; die Engine lädt erst bei Bedarf ?><script type="module" src="<?= e(asset('js/charts.mjs')) ?>"></script><?php \Core\Charts::loaderPrinted(); endif; ?>
<?php if ($user && \Core\Http\Controllers\Admin\PresenceController::on()): // Präsenz der Redaktion (eigenständiges Modul) ?><script type="module" src="<?= e(asset('js/presence.mjs')) ?>"></script><?php endif; ?>
<?= $user ? \Core\Extensions::adminHead((string) ($view ?? '')) /* CSS/JS aktiver Erweiterungen für diese Ansicht (Extension::adminAssets) – nach admin.js */ : '' ?>
</head>
<?php $drill = $user && !empty($drill) ? $drill : ''; ?>
<body class="adm<?= $user ? '' : ' adm--bare ' . \Core\AuthScreen::bodyClass() ?><?= $user ? ' adm--tb' : '' ?><?= $netArea ? ' adm--netarea' : '' ?><?= $drill ? ' is-drill' : '' ?>" data-max-vars="<?= (int) ini_get('max_input_vars') ?>"<?= $user && \Core\Http\Controllers\Admin\PresenceController::on() ? ' data-online-endpoint="' . e(url('/admin/api/online')) . '"' : '' ?>>
<?= $user ? '' : \Core\AuthScreen::sky() ?>
<?php if (environment() === 'production' && ($mr = \Core\Mailer::testRedirect())): // Test-Umleitung aktiv – nicht vergessen ?><div class="adm-env adm-env--mail" role="region" aria-label="<?= e(__('E-Mail-Test')) ?>"><?= e(__('E-MAIL-TEST')) ?> · <?= e(__('Alle E-Mails gehen an {to} statt an die eigentlichen Empfänger.', ['to' => implode(', ', $mr)])) ?> <?php if (can('system.manage')): ?><a href="<?= e(url('/admin/system#mail')) ?>"><?= e(__('Ausschalten')) ?></a><?php endif; ?></div><?php endif; ?>
<?php if (environment() !== 'production' && !$user): ?><div class="adm-env adm-env--<?= e(environment()) ?>" role="region" aria-label="<?= e(__('Testumgebung')) ?>"><?= e(strtoupper(environment())) ?> · <?= e(__('Testumgebung – Änderungen hier gehen nicht auf die Live-Website. E-Mails werden umgeleitet, Suchmaschinen ausgesperrt.')) ?></div><?php endif; ?>
<?php if ($user):
  // Titel des Bereichs (Präsenz „wer arbeitet wo“)
  $areaTitle = (string) ($drillTitle ?? '');
  if ($areaTitle === '') foreach ($adminNav as [$h, $l, $k]) if ($isCur($k, $h)) { $areaTitle = (string) $l; break; }
  if ($areaTitle === '') foreach ($nav as [, $l, $k]) if ($isCur($k)) { $areaTitle = (string) $l; break; }
  if ($areaTitle === '' && $netArea) $areaTitle = (string) ($title ?? __('Netzwerk'));
  if ($areaTitle === '') $areaTitle = match ($section) { 'help' => __('Handbuch & Hilfe'), 'account' => __('Konto'), 'role' => __('Benutzer & Rollen'), default => (string) ($title ?? __('Verwaltung')) };
  // „online“ melden (Core\Presence::ONLINE): Ort = Titel des Bereichs, Pfad der Anfrage
  if ($user) \Core\Http\Controllers\Admin\PresenceController::seen($areaTitle, (string) (app()->request?->path ?? '/admin'));
?>
<?php include __DIR__ . '/_topbar.php'; // Kopfleiste über dem Inhalt: Umschalter, Suche, Favoriten, Konto (Netzwerk-Konten: Umschalter Netzwerk ↔ Website) ?>
<aside class="adm-side" id="adm-side" aria-label="<?= e(__('Menü')) ?>">
  <a class="adm-side__close" href="#main" aria-label="<?= e(__('Menü schließen')) ?>" data-drawer-close><?= icon('x') ?></a>
  <div class="adm-brand-row">
  <a class="adm-brand" href="<?= e(url($netArea ? '/admin/network' : '/admin')) ?>" data-search-endpoint="<?= e(url('/admin/api/search')) ?>"><?php [$b1, $b2] = admin_brand(); ?><span><?= e($b1) ?><i>.</i></span><small><?= e(trim($b2 . ' · ' . __('Verwaltung'), ' ·')) ?></small></a>
  </div>
  <?php if ($drill): // Bereichsnavigation (z. B. Daten) ersetzt die Hauptnavigation – resources/js/_drill.js; ohne JavaScript führt „Hauptmenü“ zur Übersicht ?>
  <div class="adm-drill" data-drill-area="<?= e(['review' => 'ai', 'assistant' => 'ai'][$section] ?? $section) ?>" data-drill-back="<?= e(__('Zurück zu {name}', ['name' => $drillTitle ?? ''])) ?>" data-drill-title="<?= e($drillTitle ?? '') ?>">
    <a class="adm-drill__back" href="<?= e(url('/admin')) ?>"><span aria-hidden="true">‹</span> <?= e(__('Hauptmenü')) ?></a>
    <p class="adm-drill__title" aria-hidden="true"><?= e($drillTitle ?? '') ?></p>
    <?= $drill ?>
  </div>
  <?php endif; ?>
  <?php if ($netArea): $netCur = \Core\Network\Area::current($reqPath); // Seitenleiste des Netzwerk-Bereichs (Core\Network\Area::nav) ?>
  <nav id="adm-mainnav" class="adm-netnav" aria-label="<?= e(__('Netzwerk')) ?>">
    <?php foreach (\Core\Network\Area::nav() as $gi => $g): if (!$g['items']) continue; ?>
    <?php if ($g['label'] !== ''): ?><p class="adm-side__label" id="adm-netgrp-<?= (int) $gi ?>"><?= e($g['label']) ?></p><?php endif; ?>
    <ul<?= $g['label'] !== '' ? ' aria-labelledby="adm-netgrp-' . (int) $gi . '"' : '' ?>>
      <?php foreach ($g['items'] as [$nk, $nh, $nl, $ni, $nw]): $cur = $reqPath === '/admin/network' ? $nk === '' : $nk === $netCur; ?>
      <li><a href="<?= e(url($nh)) ?>"<?= $cur ? ' aria-current="page"' : '' ?><?= $nw !== '' ? ' title="' . e($nw) . '"' : '' ?>><?= icon($ni, ['class' => 'adm-nav__ico']) ?><span><?= e($nl) ?></span><?php if ($nw !== ''): ?><span class="adm-navwarn" aria-hidden="true"></span><span class="sr-only"> – <?= e($nw) ?></span><?php endif; ?></a></li>
      <?php endforeach; ?>
    </ul>
    <?php endforeach; ?>
  </nav>
  <?php else: ?>
  <nav id="adm-mainnav" aria-label="<?= e(__('Verwaltung')) ?>">
    <ul>
      <?php // Website ansehen: erster Menüpunkt, öffnet einen neuen Tab (Pfeil rechts) ?>
      <li class="adm-nav-site"><a href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= icon('globe', ['class' => 'adm-nav__ico']) ?><span><?= e(__('Website ansehen')) ?></span><?= icon('arrow-square-out', ['class' => 'adm-nav-site__ext']) ?><span class="adm-sr"> <?= e(__('(öffnet in neuem Tab)')) ?></span></a></li>
      <?php foreach ($nav as [$href, $label, $key]): $navSvg = \Core\Icons::nav($key, 'adm-nav__ico'); // Symbol aus dem Sprite; ohne (KLXM AI) → CSS-Maske über data-ico ?>
      <li><a href="<?= e(url($href)) ?>"<?= $navSvg ? ' data-nav="' . e($key) . '"' : ' data-ico="' . e($key) . '"' ?><?= $isCur($key) ? ' aria-current="page"' : '' ?>><?= $navSvg ?><span><?= e($label) ?></span><?php if ($key === 'requests' && $newReq): ?> <span class="adm-count"><?= $newReq ?></span><?php endif; ?><?php if ($key === 'chat' && $href === '/admin/chat'): ?> <span class="adm-count uc-count<?= $chatAt ? ' uc-count--at' : '' ?>" data-chat-badge<?= $chatN ? '' : ' hidden' ?>><?= $chatAt ? '@ ' : '' ?><?= $chatN ?><span class="sr-only"> <?= e(__('ungelesen')) ?></span></span><?php endif; ?><?php if ($key === 'support' && $supportN): ?> <span class="adm-count"><?= $supportN ?><span class="sr-only"> <?= e(__('ungelesen')) ?></span></span><?php endif; ?><?php if ($key === 'drafts'): ?> <span class="adm-count" title="<?= e(__('Offene Entwürfe')) ?>" data-drafts-badge<?= $draftsN ? '' : ' hidden' ?>><span data-n><?= $draftsN ?></span><span class="sr-only"> <?= e(__('offene Entwürfe')) ?></span></span><?php endif; ?><?php if (in_array($key, ['ai', 'review'], true) && $reviewN): ?> <span class="adm-count" title="<?= e(__('Eingereicht: zur Freigabe')) ?>"><?= $reviewN ?><span class="sr-only"> <?= e(__('zur Freigabe eingereicht')) ?></span></span><?php endif; ?></a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($adminGroups): // Administration: aufklappbare Gruppen – Zustand je Browser (resources/js/_navgroups.js), offen auf ihren Seiten ?>
    <p class="adm-side__label" id="adm-admin-h"><?= e(__('Einrichtung')) ?></p>
    <ul class="adm-navgrps" aria-labelledby="adm-admin-h">
      <?php foreach ($adminGroups as $g): $gOpen = (bool) array_filter($g['items'], fn($n) => $isCur($n[2], $n[0])); ?>
      <?php if (count($g['items']) === 1): [$href, $label, $key] = $g['items'][0]; $navSvg = \Core\Icons::nav($key, 'adm-nav__ico'); ?>
      <li><a href="<?= e(url($href)) ?>"<?= $navSvg ? ' data-nav="' . e($key) . '"' : ' data-ico="' . e($key) . '"' ?><?= $gOpen ? ' aria-current="page"' : '' ?>><?= $navSvg ?><span><?= e($label) ?></span></a></li>
      <?php else: $gId = 'adm-grp-' . $g['key']; ?>
      <?php $gWarn = array_values(array_filter(array_map(fn($n) => $navWarn[$n[2]] ?? null, $g['items']))); ?>
      <li class="adm-navgrp<?= $gOpen ? ' is-open is-current' : '' ?><?= $gWarn ? ' has-warn' : '' ?>" data-navgroup="<?= e($g['key']) ?>">
        <button type="button" class="adm-navgrp__btn" aria-expanded="<?= $gOpen ? 'true' : 'false' ?>" aria-controls="<?= e($gId) ?>"<?= $gWarn ? ' title="' . e(implode(' · ', $gWarn)) . '"' : '' ?>><?= \Core\Icons::nav($g['icon'], 'adm-nav__ico') ?><span><?= e($g['label']) ?></span><?php if ($gWarn): ?><span class="adm-navwarn" aria-hidden="true"></span><span class="sr-only"> – <?= e(implode(' · ', $gWarn)) ?></span><?php endif; ?><span class="adm-navgrp__chev" aria-hidden="true"></span></button>
        <ul class="adm-navgrp__list" id="<?= e($gId) ?>"<?= $gOpen ? '' : ' hidden' ?>>
          <?php foreach ($g['items'] as [$href, $label, $key]): ?>
          <?php $sub = $hubPage && (($key === 'prefs' && $hubKind === 'settings') || ($key === 'stats' && $hubKind === 'stats')); ?>
          <li><a href="<?= e(url($href)) ?>" data-nav="<?= e($key) ?>"<?= $sub ? ' class="is-parent"' : ($isCur($key, $href) ? ' aria-current="page"' : '') ?><?= isset($navWarn[$key]) ? ' data-warn title="' . e($navWarn[$key]) . '"' : '' ?>><span><?= e($label) ?></span><?php if (isset($navWarn[$key])): ?><span class="adm-navwarn" aria-hidden="true"></span><span class="sr-only"> – <?= e($navWarn[$key]) ?></span><?php endif; ?></a>
            <?php if ($sub): ?><ul class="adm-navgrp__sub"><li><a href="<?= e(url($hubPage['href'])) ?>" aria-current="page"><span><?= e($hubPage['label']) ?></span></a></li></ul><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      </li>
      <?php endif; ?>
      <?php endforeach; ?>
    </ul>
    <noscript><style>.adm-side .adm-navgrp__list[hidden]{display:grid}.adm-navgrp__chev{display:none}</style></noscript>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
</aside>
<?php endif; ?>
<main class="adm-main" id="main">
  <?php if ($hubPage): // Rückweg von einer Einstellungs-/Statistikseite zur Sammelseite (und zur Datentabelle, falls sie dazugehört) ?>
  <nav class="adm-backbar" aria-label="<?= e(__('Zurück')) ?>">
    <a href="<?= e(url($hubKind === 'stats' ? \Core\AdminPages::STATS : \Core\AdminPages::HUB)) ?>"><span aria-hidden="true">←</span> <?= e($hubKind === 'stats' ? __('Statistiken') : __('Einstellungen der Funktionen')) ?></a>
    <?php if (!empty($hubPage['table']) && ($tbl = \Core\Data\Tables::find((string) $hubPage['table']))): ?><a href="<?= e(url('/admin/data/' . $tbl['handle'])) ?>"><?= e(__('Zur Tabelle „{name}“', ['name' => (string) $tbl['name']])) ?></a><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php foreach ($flash as [$type, $msg]): ?>
  <div class="adm-flash adm-flash--<?= e($type) ?>" data-flash role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($msg) ?></div>
  <?php endforeach; ?>
  <?php // Hinweise zu diesem Projekt für diesen Bereich (Core\Guide, Front Matter „bereich:“) – kleiner Link über dem Inhalt
  $guideT = isset($t) && is_array($t) ? $t : (isset($table) && is_array($table) ? $table : null);
  if ($user && $section !== 'help' && !\Core\Guide::$shownInline && ($guideHere = \Core\Guide::forArea($section, $guideT['handle'] ?? null))): ?>
  <p class="adm-guidehint"><?= icon('lightbulb') ?><span><?= e(count($guideHere) > 1 ? __('Hinweise zum Projekt:') : __('Hinweis zum Projekt:')) ?>
    <?php foreach ($guideHere as $gi => $gn): ?><?= $gi ? ' · ' : '' ?><a href="<?= e(\Core\Guide::url($gn)) ?>"><?= e($gn['title']) ?></a><?php endforeach; ?></span></p>
  <?php endif; ?>
  <?= $content ?>
  <?php // Begrüßungsfenster „Willkommen“ + nächste Schritte (Core\Welcome): einmal automatisch, sonst über ?erste-schritte
  if ($user) { [$wShow, $wAuto] = \Core\Welcome::state($user, $_GET); if ($wShow) { if ($wAuto) \Core\Welcome::markSeen((int) $user['id']); $auto = $wAuto; include __DIR__ . '/_welcome-modal.php'; } } ?>
</main>
<?php if ($user): ?>
<input type="hidden" id="adm-csrf" value="<?= e(\Core\Csrf::token()) ?>">
<div class="adm-sr" id="adm-live" role="status" aria-live="polite"></div>
<datalist id="cms-links" data-endpoint="<?= e(url('/admin/api/links')) ?>"></datalist>
<?php // Push-Benachrichtigungen (Core\Push): Abo dieses Geräts einmal am Tag auffrischen (resources/js/_push.js) – nur mit angemeldetem Gerät
if (\Core\Push\Push::enabled() && app()->db->fetchValue('SELECT 1 FROM push_subscriptions WHERE user_id = ? LIMIT 1', [(int) $user['id']])): ?>
<span hidden id="cms-push-cfg" data-key="<?= e(\Core\Push\Keys::publicB64()) ?>" data-sw="<?= e(url(\Core\Push\Push::SW_PATH)) ?>" data-scope="<?= e(url(\Core\Push\Push::SW_SCOPE)) ?>" data-subscribe="<?= e(url('/admin/api/push/subscribe')) ?>"></span>
<?php endif; ?>
<?php endif; ?>
</body>
</html>
