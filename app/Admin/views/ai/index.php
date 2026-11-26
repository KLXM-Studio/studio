<?php
/** Bereich „KLXM AI“ – Übersicht: Kacheln je aktiver Funktion (interne KI, MCP, REST-API, Freigaben, Chats, Suche); mit interner KI dazu Zahlen, Status und Schnellzugriffe. */
use Core\AI\Ai;
use Core\AI\Assist;
use Core\AI\Center;

$brand = Assist::brand();
$site = Ai::siteSettings();
$cfg = Ai::config();
$on = ['text' => Assist::available('text'), 'vision' => Assist::available('vision')];
$any = $on['text'] || $on['vision'];
$q = Assist::quota();
$labels = ['text' => __('Texte: schreiben, übersetzen, SEO'), 'vision' => __('Bilder: Alt-Texte'), 'embed' => __('Suche: semantisch')];
?>
<header class="adm-head">
  <div><p class="adm-eyebrow"><?= e(__('KI-Assistent')) ?></p><h1 class="kia-brand"><?= Assist::logo() ?> <?= e($brand) ?></h1>
    <p class="adm-muted"><?= e(__('Übersicht der aktiven Funktionen rund um KI und Schnittstellen – die KI macht Vorschläge, Sie prüfen und entscheiden.')) ?></p></div>
</header>

<?php
// Übersicht aller aktiven Funktionen rund um KI und Schnittstellen – eine Kachel je Funktion; sind keine internen KI-Funktionen an,
// weist genau eine Kachel darauf hin (MCP, API und Freigaben laufen unabhängig davon)
$mcpOn = \Core\Features::on('mcp');
$apiOn = \Core\Features::on('api');
$tokens = ($mcpOn || $apiOn) ? \Core\Api\Tokens::all() : [];
$lastUse = $tokens ? max(array_map(fn($t) => (string) ($t['last_used_at'] ?? ''), $tokens)) : '';
$mcpClients = array_values(array_unique(array_filter(array_map(fn($t) => strtok((string) ($t['mcp_client'] ?? ''), ' ') ?: '', $tokens))));   // nur der Name, ohne Version
$canTok = can('api.manage');
$review = \Core\Review\Queue::canReview();
$pending = $review ? \Core\Review\Queue::pendingCount() : 0;
$visitor = $any && \Core\Features::on('chat.visitor');
$assistant = $any && \Core\Features::on('chat.assistant');
$searchAns = $any && (string) setting('sys.search_answer', 'off') !== 'off';
$semantic = Ai::enabled('embed');
$tile = function (string $icon, string $title, string $status, string $text, string $href = '', string $mod = 'on'): string {
    $inner = '<span class="kia-tile__ico" aria-hidden="true">' . icon($icon) . '</span><span class="kia-tile__body"><strong>' . e($title) . '</strong>'
        . '<span class="kia-tile__st kia-tile__st--' . e($mod) . '">' . e($status) . '</span><small>' . e($text) . '</small></span>';
    return '<li class="kia-tile kia-tile--' . e($mod) . '">' . ($href !== '' ? '<a href="' . e(url($href)) . '">' . $inner . '</a>' : '<div>' . $inner . '</div>') . '</li>';
};
?>
<section class="kia-over" aria-labelledby="kia-over-h">
  <h2 id="kia-over-h" class="adm-sr"><?= e(__('Aktive Funktionen')) ?></h2>
  <ul class="kia-tiles" role="list">
    <?php if ($any): ?>
    <?= $tile('sparkle', __('Interne KI'), __('aktiv'), implode(' · ', array_map(fn($c) => $labels[$c], array_values(array_filter(['text', 'vision', 'embed'], fn($c) => Ai::enabled($c)))))
        . ($q['cap'] > 0 ? ' · ' . __('{left} von {cap} Aufrufen heute übrig', ['left' => (int) $q['left'], 'cap' => (int) $q['cap']]) : ''), can('system.manage') ? '/admin/system#ki' : '') ?>
    <?php else: ?>
    <?= $tile('sparkle', __('Interne KI-Funktionen'), __('noch nicht aktiviert'), $cfg['configured'] && !$site['enabled']
        ? __('Der Anbieter ist eingerichtet ({provider}), für diese Website aber ausgeschaltet. Schreiben, Übersetzen, Alt-Texte und Besucher-Chat brauchen ihn.', ['provider' => ucfirst((string) ($cfg['provider'] ?? ''))])
        : __('Schreiben, Übersetzen, Alt-Texte, semantische Suche und Besucher-Chat brauchen einen KI-Anbieter (eigener Ollama-Server oder EU-Anbieter). Externe Assistenten über MCP funktionieren auch ohne.'),
        can('system.manage') ? '/admin/system#ki' : '', 'off') ?>
    <?php endif; ?>
    <?php if ($mcpOn): ?>
    <?= $tile('code', __('MCP-Server'), __('aktiv'), ($tokens ? (count($tokens) === 1 ? __('1 Zugangs-Token') : __('{n} Zugangs-Tokens', ['n' => count($tokens)])) : __('noch kein Zugangs-Token'))
        . ($mcpClients ? ' · ' . __('verbunden: {list}', ['list' => implode(', ', array_slice($mcpClients, 0, 3))]) : '')
        . ($lastUse !== '' ? ' · ' . __('zuletzt genutzt {when}', ['when' => fmt()->relative($lastUse)]) : ''), $canTok ? '/admin/api-tokens' : '') ?>
    <?php endif; ?>
    <?php if ($apiOn): ?>
    <?= $tile('globe', __('REST-API'), __('aktiv'), __('Schnittstelle für Automatisierungen unter /api/v1 – gleiche Zugangs-Tokens wie MCP.'), $canTok ? '/admin/api-tokens' : '') ?>
    <?php endif; ?>
    <?php if ($review && ($mcpOn || $apiOn || $any)): ?>
    <?= $tile('tray', __('Eingereichte Änderungen'), $pending ? __('{n} offen', ['n' => $pending]) : __('nichts offen'), __('Vorschläge von KI-Assistenten und Schnittstellen zur Freigabe.'), '/admin/ai/eingereicht', $pending ? 'hot' : 'on') ?>
    <?php endif; ?>
    <?php if ($assistant): ?><?= $tile('chat-circle-text', __('KI-Assistent der Redaktion'), __('aktiv'), __('Hilfe und Aktionen mit Bestätigung direkt in der Verwaltung.')) ?><?php endif; ?>
    <?php if ($visitor): ?><?= $tile('chats', __('KI-Chat für Besucher'), __('aktiv'), __('Antwortet nur aus den Inhalten der Website.'), can('system.manage') ? '/admin/system#ki' : '') ?><?php endif; ?>
    <?php if ($semantic || $searchAns): ?><?= $tile('magnifying-glass', __('KI in der Website-Suche'), __('aktiv'), implode(' · ', array_filter([$semantic ? __('semantische Suche') : '', $searchAns ? __('KI-Antwort über den Treffern') : '']))) ?><?php endif; ?>
  </ul>
</section>

<?php if ($any):
  $gaps = Assist::available('text') && \Core\Lang::multi() ? Center::translationGaps() : [];
  $open = 0; foreach ($gaps as $g) $open += count($g['pages']) + count($g['entries']) + count($g['media']) + $g['settings'];
  $noalt = can('media.upload') ? count(Center::missingAlt()) : 0;
  $seo = 0; if (can('pages.manage')) foreach (\Core\AI\SeoCheck::overview()['pages'] as $r) if ($r['missing']) $seo++;
?>
<div class="adm-stats kia-stats">
  <?php if ($q['cap'] > 0): ?>
  <div class="adm-stat"><strong><?= (int) $q['left'] ?></strong><span><?= e(__('KI-Aufrufe heute übrig (von {cap})', ['cap' => $q['cap']])) ?></span>
    <span class="kia-bar" role="progressbar" aria-valuenow="<?= (int) $q['used'] ?>" aria-valuemin="0" aria-valuemax="<?= (int) $q['cap'] ?>" aria-label="<?= e(__('Tageslimit genutzt')) ?>"><progress max="<?= (int) $q['cap'] ?>" value="<?= min((int) $q['used'], (int) $q['cap']) ?>"></progress></span></div>
  <?php else: ?><div class="adm-stat"><strong><?= (int) $q['used'] ?></strong><span><?= e(__('KI-Aufrufe heute (ohne Limit)')) ?></span></div><?php endif; ?>
  <?php if (\Core\Review\Queue::canReview()): ?><a class="adm-stat<?= \Core\Review\Queue::pendingCount() ? ' adm-stat--hot' : '' ?>" href="<?= e(url('/admin/ai/eingereicht')) ?>"><strong><?= \Core\Review\Queue::pendingCount() ?></strong><span><?= e(__('Änderungen zur Freigabe')) ?></span></a><?php endif; ?>
  <?php if ($gaps): ?><a class="adm-stat" href="<?= e(url('/admin/ai/uebersetzen')) ?>"><strong><?= $open ?></strong><span><?= e(__('fehlende Übersetzungen')) ?></span></a><?php endif; ?>
  <?php if (can('pages.manage')): ?><a class="adm-stat" href="<?= e(url('/admin/ai/seo')) ?>"><strong><?= $seo ?></strong><span><?= e(__('Seiten ohne Beschreibung')) ?></span></a><?php endif; ?>
  <?php if (can('media.upload')): ?><a class="adm-stat" href="<?= e(url('/admin/ai/alt-texte')) ?>"><strong><?= $noalt ?></strong><span><?= e(__('Bilder ohne Alt-Text')) ?></span></a><?php endif; ?>
</div>

<div class="adm-grid2">
  <section class="adm-card" aria-labelledby="kia-st-h">
    <h2 id="kia-st-h"><?= e(__('Status')) ?></h2>
    <ul class="kia-status">
      <?php foreach (['text', 'vision', 'embed'] as $c): $cap = Ai::capability($c); $act = Ai::enabled($c); ?>
      <li class="kia-status__row"><span class="kia-chk kia-chk--<?= $act ? 'ok' : 'info' ?>"><span class="kia-chk__i" aria-hidden="true"><?= $act ? '✓' : '–' ?></span><span><strong><?= e($labels[$c]) ?></strong>
        <small class="adm-muted"><?= $act ? e(__('aktiv')) . ' · ' . e($cap['model']) . ' · ' . e($cap['external'] ? __('externer Anbieter') : __('bleibt auf dem Server')) : e(__('aus')) ?></small></span></span></li>
      <?php endforeach; ?>
    </ul>
    <p class="adm-muted kia-small"><?= e(__('Gezählt werden nur Aufrufe und Dauer – Inhalte werden nicht gespeichert.')) ?></p>
  </section>
  <section class="adm-card" aria-labelledby="kia-go-h">
    <h2 id="kia-go-h"><?= e(__('Schnellzugriff')) ?></h2>
    <ul class="kia-quick">
      <?php if ($on['text']): ?><li><a href="<?= e(url('/admin/ai/texte')) ?>"><span aria-hidden="true">✎</span><span><strong><?= e(__('Text schreiben')) ?></strong><small><?= e(__('Freier Auftrag mit Seite oder Eintrag als Zusammenhang, Ergebnis als Entwurf einfügen')) ?></small></span></a></li><?php endif; ?>
      <?php if ($gaps): ?><li><a href="<?= e(url('/admin/ai/uebersetzen')) ?>"><span aria-hidden="true">⇄</span><span><strong><?= e(__('Übersetzen')) ?></strong><small><?= e(__('Fehlende Übersetzungen je Sprache prüfen und nacharbeiten')) ?></small></span></a></li><?php endif; ?>
      <?php if (can('pages.manage')): ?><li><a href="<?= e(url('/admin/ai/seo')) ?>"><span aria-hidden="true">⌕</span><span><strong><?= e(__('SEO-Übersicht')) ?></strong><small><?= e(__('Beschreibungen für Suchmaschinen ergänzen')) ?></small></span></a></li><?php endif; ?>
      <?php if (can('media.upload')): ?><li><a href="<?= e(url('/admin/ai/alt-texte')) ?>"><span aria-hidden="true">▣</span><span><strong><?= e(__('Alt-Texte')) ?></strong><small><?= e(__('Bilder ohne Beschreibung nacheinander prüfen')) ?></small></span></a></li><?php endif; ?>
    </ul>
  </section>
</div>
<section class="adm-card" aria-labelledby="kia-where-h">
  <h2 id="kia-where-h"><?= e(__('Wo hilft der Assistent noch?')) ?></h2>
  <ul class="kia-where">
    <li><?= e(__('„✦ KI“ in jeder Formatierungsleiste und an Textfeldern: verbessern, kürzen, erweitern, einfacher, korrigieren, Ton ändern, freier Auftrag.')) ?></li>
    <li><?= e(__('Seiteneinstellungen und Einträge: SEO-Check, SEO-Vorschläge, Teaser, „Aus Deutsch übersetzen“.')) ?></li>
    <li><?= e(__('Mediathek: Alt-Text beim Hochladen und in der Info-Spalte vorschlagen.')) ?></li>
    <li><?= e(__('Support und Tabellen-Designer: Antwortvorschläge, Wissensartikel überarbeiten, Felder vorschlagen.')) ?></li>
  </ul>
  <p class="kia-rule"><?= e(__('Die KI erfindet keine Fakten: Fehlende Angaben erscheinen als [bitte ergänzen: …]. Bitte jeden Vorschlag prüfen.')) ?></p>
</section>
<?php endif;
