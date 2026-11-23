<?php
/**
 * Datenliste: Einträge einer Datentabelle ausgeben (Kern-Block, vom Theme überschreibbar).
 *
 * Darstellung „Verzeichnis“ (directory): Karten mit eingepasstem Bild auf einer Kachel (Logos), Auswahl-/Mehrfachauswahl-
 * Felder als Etiketten (dl-chip dl-tone-1…5 nach Reihenfolge der Option). Besucher-Filter (alle Darstellungen): bis zu zwei
 * Auswahlfelder als Filter-Schaltflächen (?{feldname}={option}), Suchfeld (?q=) und A–Z-Sprungleiste – serverseitig per GET,
 * ohne JavaScript; Aufrufe mit Parametern umgehen den Seiten-Cache. Ohne diese Optionen ist die Ausgabe unverändert.
 * @var \Core\Block $b  @var array $d
 */
use Core\Data\Clamp;
use Core\Data\Entries;
use Core\Data\Tables;

$t = Tables::findContent((string) ($d['table'] ?? ''));
$wrap = app()->theme->def['container_class'] ?? 'wrap';
$btn = app()->theme->def['button_class'] ?? 'btn btn--primary';
if (!$t) {
    if (is_editing()) echo '<div class="' . e($wrap) . '"><p class="dl-empty">Bitte in der Seitenleiste eine Tabelle wählen.</p></div>';
    return;
}
// In den Inhaltsblöcken eines Eintrags (Core\Data\EntryBlocks): höchstens eine Ebene (keine Liste in den Einträgen einer Liste, auch
// nicht über andere Tabellen), dieser Eintrag selbst nicht in der Liste, „aufgerufener Eintrag“ = dieser Eintrag, Links auf seine Seite
$host = \Core\Data\EntryBlocks::current();
$listGuard = \Core\Data\EntryBlocks::enterList();
if (!$listGuard) {
    if (is_editing()) echo '<div class="' . e($wrap) . '"><p class="dl-empty">' . e(__('Datenliste in einer Datenliste: wird nicht ausgegeben (höchstens eine Ebene).')) . '</p></div>';
    return;
}
// Gewählte Felder dieser Tabelle (ohne Präfix); Standard: Titel, Bild, Beschreibung
$fields = [];
foreach ((array) ($d['fields'] ?? []) as $v) {
    if (str_starts_with((string) $v, $t['handle'] . '.')) $fields[] = substr((string) $v, strlen($t['handle']) + 1);
}
$fields = \Core\Data\Exposure::publicNames($t, $fields);   // „Nie öffentlich“ nie in der Liste
if (!$fields) {
    $fields = array_values(array_filter(['_title', Tables::imageField($t), $t['settings']['description_field']]));
}
$imageField = null;
foreach ($fields as $f) {
    if ((\Core\Data\Network::anyField($t, $f)['type'] ?? '') === 'media') { $imageField = $f; break; }
}
$other = array_values(array_filter($fields, fn($f) => $f !== $imageField && $f !== '_title'));
$showTitle = in_array('_title', $fields, true);

$limit = max(0, (int) ($d['limit'] ?? 0));
$page = !empty($d['paginate']) && $limit ? max(1, (int) (app()->request?->query['seite'] ?? 1)) : 1;
$o = ['status' => 'published'];
if ($limit) { $o['limit'] = $limit; $o['offset'] = ($page - 1) * $limit; }
$pfx = $t['handle'] . '.';
$ctx = app()->entry;
$op = (string) ($d['filter_op'] ?? '=');
if (str_starts_with((string) ($d['filter_field'] ?? ''), $pfx)) {
    $ff = substr($d['filter_field'], strlen($pfx));
    if ($op === 'current' || $op === 'same') {
        // Kontext-Filter auf Detailseiten: verknüpft mit / gleich wie der aufgerufene Eintrag
        $val = !$ctx ? null : ($op === 'current' ? $ctx['entry']['id'] : ($ctx['entry'][$ff] ?? null));
        if (is_array($val)) $val = $val[0] ?? null;
        // Funktionen (Core\Data\Roles): „verknüpft mit dem aufgerufenen Eintrag“ = Funktion mit Verknüpfung entry:{tabelle}:{id}
        if ($op === 'current' && $ctx && (\Core\Data\Network::anyField($t, $ff)['type'] ?? '') === \Core\Data\Roles::TYPE) $val = 'entry:' . $ctx['table']['handle'] . ':' . (int) $ctx['entry']['id'];
        $o['where'] = [[$ff, '=', $val === null || $val === '' ? '-1' : (string) $val]];
    } elseif ($op === 'empty' || $op === 'notempty') {
        $o['where'] = [[$ff, $op, '']];   // ist leer / ist nicht leer (ohne Wert)
    } elseif (trim((string) ($d['filter_value'] ?? '')) !== '') {
        $o['where'] = [[$ff, $op, trim((string) $d['filter_value'])]];
    }
}
if (!empty($d['exclude_current']) && $ctx && $ctx['table']['handle'] === $t['handle']) $o['exclude'] = $ctx['entry']['id'];
if ($host && $host['table']['handle'] === $t['handle']) $o['exclude'] = $host['entry']['id'];   // Liste im Eintrag: nie sich selbst
if (str_starts_with((string) ($d['sort_field'] ?? ''), $pfx)) $o['sort'] = substr($d['sort_field'], strlen($pfx));
if (!empty($d['sort_dir'])) $o['dir'] = $d['sort_dir'];
// Geteilte Tabellen: Quelle (leer = wie für die Website eingestellt), hervorgehobene Einträge zuerst
$o += \Core\Data\Shared::blockQuery($d);   // Quelle, auch „bestimmte Websites“ (Core\Data\Network)
if (!empty($d['featured_first'])) $o['featured_first'] = true;
// Besucher-Filter: Schaltflächen je Auswahlfeld, Suche, A–Z (Verzeichnis, Mitglieder, Partner …)
$query = (array) (app()->request?->query ?? []);
$layout = in_array($d['layout'] ?? '', ['cards', 'list', 'compact', 'table', 'directory', 'chapters'], true) ? $d['layout'] : 'cards';
// Wissensbereich (Tabellenart knowledge, Core\Data\Knowledge): Kapitelnummern vor dem Titel, Darstellung „Kapitel“ = Inhaltsverzeichnis
$know = \Core\Data\Knowledge::is($t);
$kcfg = $know ? \Core\Data\Knowledge::config($t) : null;
$ktree = $know ? \Core\Data\Knowledge::tree($t) : null;
if ($layout === 'chapters' && !$know) $layout = 'list';
// Mehrere Optionen je Filter (Kontrollkästchen), Trefferauszug, Glossar-Treffer: Stile css/list-filter.css ('@kfilter', auch nach dem Nachladen)
$multi = !empty($d['visitor_multi']);
if ($multi || !empty($d['search_excerpt']) || !empty($d['search_glossary']) || $know) app()->theme->markUsed('@kfilter');
// A–Z: nur ohne Blättern (Sprungmarken auf dieser Seite) und nicht als Tabelle; sortiert nach dem Titel
$az = !empty($d['az_index']) && empty($d['paginate']) && $layout !== 'table';
$vcands = [];   // eingestellte Filterfelder [name => Feld]
foreach (['visitor_filter', 'visitor_filter2'] as $k) {
    if (!str_starts_with((string) ($d[$k] ?? ''), $pfx)) continue;
    $vn = substr($d[$k], strlen($pfx));
    $vfd = \Core\Data\Network::anyField($t, $vn);
    if (!$vfd || !in_array($vfd['type'], ['select', 'multiselect'], true) || isset($vcands[$vn]) || in_array($vn, ['q', 'seite'], true)) continue;
    $vcands[$vn] = $vfd;
}
// Erweiterungen (Extension::filter 'data_list.query'): Abfrage ändern, z. B. zusätzliche Bedingung
$extend = function (array $q) use ($t, $d): array {
    $q2 = \Core\Extensions::filter('data_list.query', $q, ['table' => $t, 'block' => $d]);
    return is_array($q2) ? $q2 : $q;
};
// Gemeinsame Regel (Core\Blocks\ListFilter): Filter, Suche und A–Z erst ab genug Einträgen; nur Optionen mit Treffern, ab zwei
// Schaltflächen – gezählt mit derselben Abfrage wie die Liste (ohne Besucher-Auswahl), in einer Abfrage (Entries::facets)
$vfilters = [];   // [name => ['field' => …, 'options' => [key => label], 'sel' => ?string]]
$vsearch = false;
if ($vcands || !empty($d['visitor_search']) || $az) {
    $facets = Entries::facets($t, array_keys($vcands), $extend($o));
    $enough = \Core\Blocks\ListFilter::enough($d, $facets['total']);
    $vsearch = $enough && !empty($d['visitor_search']);
    $az = $az && $enough;
    // „Im Filter nicht anbieten“: Optionen nach Beschriftung oder Schlüssel ausblenden (Einträge bleiben in der Liste)
    $vhide = array_filter(array_map(fn($x) => mb_strtolower(trim($x)), explode(',', (string) ($d['visitor_filter_hide'] ?? ''))), fn($x) => $x !== '');
    foreach ($enough ? $vcands : [] as $vn => $vfd) {
        $vopts = (array) ($vfd['options'] ?? []);
        if ($vhide) $vopts = array_filter($vopts, fn($label, $key) => !in_array(mb_strtolower((string) $key), $vhide, true) && !in_array(mb_strtolower(trim((string) $label)), $vhide, true), ARRAY_FILTER_USE_BOTH);
        // Mehrfach: ?feld[]=a&feld[]=b – innerhalb eines Feldes „eine davon“, über Felder hinweg „alle Bedingungen“
        $want = \Core\Blocks\ListFilter::selection($query[$vn] ?? null, $vopts, $multi);
        $buttons = \Core\Blocks\ListFilter::buttons($vopts, $facets['counts'][$vn] ?? [], $d, $want);
        if (!$buttons) continue;   // ausgeblendete Leiste: Auswahl aus der Adresse wird ignoriert
        $chosen = array_values(array_filter($want, fn($k) => isset($buttons[$k])));
        $sel = $multi ? $chosen : ($chosen[0] ?? null);
        $vfilters[$vn] = ['field' => $vfd, 'options' => $buttons, 'sel' => $sel, 'counts' => $facets['counts'][$vn] ?? []];
        if ($chosen) $o['where'] = array_merge((array) ($o['where'] ?? []), [$multi ? [$vn, 'in', $chosen] : [$vn, '=', $chosen[0]]]);
    }
}
$vq = $vsearch && is_string($query['q'] ?? null) ? trim(mb_substr($query['q'], 0, 80)) : '';
if ($vq !== '') { $o['q'] = $vq; $o['q_public'] = true; }   // Besucher-Suche: nie in „Nie öffentlich“-Feldern
$filtering = $vq !== '' || array_filter(array_column($vfilters, 'sel'), fn($v) => $v !== null && $v !== []);
if ($az && ($tf = (string) ($t['settings']['title_field'] ?? '')) !== '') { $o['sort'] = $tf; $o['dir'] = 'asc'; }
$o = \Core\Data\Roles::listQuery($t, $d, $extend($o));   // Adressbuch: beendete Funktionen schon in der Abfrage (Blättern, Anzahl)
// „In deiner Nähe“ (Core\Data\Nearby): mit Eingabe des Besuchers zuerst das zuständige Gebiet, dann im Umkreis – ohne Blättern
$nearLoc = empty($d['paginate']) ? \Core\Data\Nearby::forBlock($d) : null;
$near = \Core\Data\Nearby::found($nearLoc);
if ($near) unset($o['limit'], $o['offset']);
$rows = \Core\Data\Exposure::publicRows($t, Entries::query($t, $o));   // Stellenangebote: abgelaufene fehlen schon hier (Core\Data\Jobs, Entries::where)
// Adressbuch mit Funktionen (Core\Data\Roles): Filter nach Liste → deren Funktion, Detailseite eines verknüpften Eintrags → die
// verknüpfte Funktion, sonst die Hauptfunktion (in position); beendete Funktionen der gefilterten Liste fallen heraus
$rows = \Core\Data\Roles::listRows($t, $d, $rows, empty($o['sort']) && !$az);
if ($near) {
    $rows = \Core\Data\Nearby::arrange($t, $rows, $nearLoc, $d);
    if ($limit) $rows = array_slice($rows, 0, $limit);
    $filtering = true;
}
// Wissen ohne eigene Sortierung: Lesereihenfolge des Kapitelbaums (Bereich, Oberkapitel, Reihenfolge)
if ($know && empty($d['sort_field']) && !$az) {
    $kpos = array_flip($ktree['order']);
    usort($rows, fn($x, $y) => ($kpos[(int) $x['id']] ?? PHP_INT_MAX) <=> ($kpos[(int) $y['id']] ?? PHP_INT_MAX));
}
// Kapitelnummer: „automatisch“ nur in der Darstellung „Kapitel“ bzw. auf der Übersichtsseite des Wissensbereichs – sonst
// (Startseite „Im Fokus“) wäre die Zahl ohne Zusammenhang
$knumMode = in_array($d['chapter_numbers'] ?? 'auto', ['auto', 'on', 'off'], true) ? ($d['chapter_numbers'] ?? 'auto') : 'auto';
$knumOn = $knumMode === 'on' || ($knumMode === 'auto' && $know && ($layout === 'chapters' || \Core\Data\Knowledge::onOverview($t)));
$knum = fn(array $e) => $knumOn && $kcfg && $kcfg['numbers'] && ($n = (string) ($ktree['nodes'][(int) $e['id']]['number'] ?? '')) !== '' ? '<span class="dl-knum">' . e($n) . '</span> ' : '';
// Suche: Trefferauszug je Eintrag, passende Glossarbegriffe als eigene Gruppe (websiteweites Glossar)
$excerpt = fn(array $e) => $vq !== '' && !empty($d['search_excerpt']) ? \Core\Data\Knowledge::excerpt($t, $e, $vq) : '';
$glossHits = $vq !== '' && !empty($d['search_glossary']) ? \Core\Data\Knowledge::glossaryHits($vq) : [];
// Darstellung „Kapitel“: ohne Suche/Filter das Inhaltsverzeichnis, sonst die Treffer als Liste
$tocMode = $layout === 'chapters' && !$filtering;
// „Kapitel als Karten“ mit Suche/Filter: flache Karten statt Textliste
$flatCards = $layout === 'chapters' && $filtering && ($d['chapters_style'] ?? 'list') === 'cards' && $ktree;
if ($layout === 'chapters' && $filtering) $layout = 'list';
// „Abschnitt ausblenden, wenn nichts da ist“: für Besucher keine Ausgabe – die Hülle (partials/section) entfällt dann
if (!$rows && !empty($d['hide_empty']) && !is_editing() && !$filtering) return;
$jobs = \Core\Data\Jobs::is($t);
if (!empty($d['link_detail'])) \Core\StructuredData::itemList($t, $rows, strip_emphasis((string) ($d['title'] ?? '')));   // schema.org ItemList
$pages = !empty($d['paginate']) && $limit ? (int) ceil(Entries::count($t, $o) / $limit) : 1;
$link = fn(array $e) => !empty($d['link_detail']) ? ($know ? \Core\Data\Knowledge::href($t, $e) : Entries::href($t, $e)) : null;   // Wissen: Verweis-Kapitel → Seite
$hTag = !empty($d['title']) ? 'h3' : 'h2';
// Bilder einpassen (Logos auf einer Kachel) statt füllen: Standard im Verzeichnis
$fit = (string) ($d['image_fit'] ?? '');
$contain = $fit === 'contain' || ($fit === '' && $layout === 'directory');
// Etiketten (Verzeichnis): Farbton nach Position der Option (dl-tone-1 … 5)
$tone = fn(array $f, string $k) => 'dl-tone-' . ((int) array_search($k, array_keys((array) ($f['options'] ?? [])), true) % 5 + 1);
$chips = function (array $e, string $f) use ($t, $tone): string {
    $fd = \Core\Data\Network::anyField($t, $f);
    $vals = array_values(array_filter((array) ($e[$f] ?? []), fn($v) => $v !== '' && $v !== null));
    if (!$fd || !$vals) return '';
    $h = '';
    foreach ($vals as $k) $h .= '<span class="dl-chip ' . $tone($fd, (string) $k) . '">' . e(Tables::optionLabel($fd, (string) $k)) . '</span> ';
    return '<span class="dl-chips">' . trim($h) . '</span>';
};
// Adresse mit den aktiven Besucher-Filtern (Schaltflächen, Blättern); $set überschreibt, null entfernt
$keep = array_filter(array_map(fn($v) => $v['sel'], $vfilters), fn($v) => $v !== null && $v !== []) + ($vq !== '' ? ['q' => $vq] : []);
$here = ($host ? Entries::url($host['table'], $host['entry']) : null) ?? (app()->currentPage ? \Core\Pages::url(app()->currentPage) : url('/'));
$qs = function (array $set) use ($keep, $here, $b): string {
    $p = array_filter($set + $keep, fn($v) => $v !== null && $v !== '');
    return $here . ($p ? '?' . http_build_query($p) : '') . '#' . $b->domId();
};
// Gruppen für A–Z (Umlaute zum Grundbuchstaben, Ziffern/Sonstiges unter „#“)
$letterOf = function (string $s): string {
    $c = strtr(mb_strtoupper(mb_substr(trim($s), 0, 1)), ['Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'À' => 'A', 'Á' => 'A', 'É' => 'E', 'È' => 'E']);
    return preg_match('~^[A-Z]$~', $c) ? $c : '#';
};
$groups = ['' => $rows];
if ($az && $rows) {
    $groups = [];
    foreach ($rows as $e) $groups[$letterOf(Entries::title($t, $e))][] = $e;
    uksort($groups, fn($a, $b2) => $a === '#' ? 1 : ($b2 === '#' ? -1 : strcmp($a, $b2)));
    // Regel wie bei Filtern (Core\Blocks\ListFilter): Sprungleiste erst ab zwei Buchstaben – sonst einfache Liste
    if (!\Core\Blocks\ListFilter::enoughButtons(count($groups), $d)) { $az = false; $groups = ['' => $rows]; }
}
$azTag = $hTag;   // Buchstaben-Überschriften der A–Z-Gruppen; Namen der Einträge eine Stufe darunter
if ($az) $hTag = !empty($d['title']) ? 'h4' : 'h3';
$titleClamp = Clamp::titleClass($d);   // Textlänge/Titel kürzen (Karten und Listen; Texte über Clamp::text)
// Spaltenköpfe der Tabelle: Titel-Feld, Sonderfelder „_when“ (Termin) und „published_at“ wie im Block „Datensatz-Felder“
$colLabel = fn(string $f) => match (true) {
    $f === '_title' => ($tf = \Core\Data\Network::anyField($t, $t['settings']['title_field'])) ? Tables::label($tf) : lt('Titel'),
    ($ff = \Core\Data\Network::anyField($t, $f)) !== null => Tables::label($ff),
    $f === '_when' => lt('Termin'),
    $f === 'published_at' => lt('Datum'),
    default => $f,
};
?>
<?php // Filtern, Suche, Blättern ohne Seitenwechsel (Core\Live::region – Links/Formulare mit data-live-nav, ohne JS normale Links)
$reload = !$host && ($pages > 1 || $vfilters || $vsearch) ? \Core\Live::region($b) : '';   // im Eintrag: normale Links (Block steht nicht auf der Seite) ?>
<div class="<?= e($wrap) ?> dl dl--<?= e($layout) ?>"<?= $reload ?>>
  <?= !empty($d['nearby']) && empty($d['paginate']) ? \Core\Data\Nearby::form($d, $nearLoc, count($rows)) : '' ?>
  <?php if (!empty($d['eyebrow']) || !empty($d['title']) || !empty($d['intro'])): ?>
  <header class="dl-head">
    <?php if (!empty($d['eyebrow'])): ?><p class="eyebrow eyebrow--accent"<?= $b->edit('eyebrow') ?>><?= emphasis((string) $d['eyebrow']) ?></p><?php endif; ?>
    <?php if (!empty($d['title'])): ?><h2 id="<?= e($b->titleId()) ?>" class="h2 h2--m dl-title"><span<?= $b->edit('title') ?>><?= emphasis((string) $d['title']) ?></span></h2><?php endif; ?>
    <?php if (!empty($d['intro'])): ?><p class="muted dl-intro"<?= $b->edit('intro') ?>><?= emphasis((string) $d['intro']) ?></p><?php endif; ?>
  </header>
  <?php endif; ?>

  <?php if (($vfilters || $vsearch) && $multi): $qid = $b->domId() . '-q'; // Mehrfach-Filter: ein GET-Formular mit Suche und Kontrollkästchen ?>
  <form class="dl-tools dl-tools--multi" role="search" method="get" data-live-nav action="<?= e($here . '#' . $b->domId()) ?>" aria-label="<?= e(lt('Suchen und filtern')) ?>">
    <?php if ($vsearch): ?>
    <div class="dl-search">
      <label class="dl-search__label" for="<?= e($qid) ?>"><?= e(lt('In der Liste suchen')) ?></label>
      <span class="dl-search__row"><input class="dl-search__input" id="<?= e($qid) ?>" type="search" name="q" value="<?= e($vq) ?>" maxlength="80" autocomplete="off" enterkeyhint="search" data-live-input><button class="dl-search__btn" type="submit"><?= e(lt('Suchen')) ?></button></span>
    </div>
    <?php endif; ?>
    <?php foreach ($vfilters as $vn => $vf): $vl = Tables::label($vf['field']); ?>
    <fieldset class="dl-mfilter">
      <legend class="dl-filter__label"><?= e($vl) ?></legend>
      <ul class="dl-mfilter__list" role="list">
        <?php foreach ($vf['options'] as $k => $l): $cid = $b->domId() . '-f-' . $vn . '-' . preg_replace('~[^a-z0-9_-]~i', '', (string) $k); $on = in_array((string) $k, (array) $vf['sel'], true); $n = (int) ($vf['counts'][(string) $k] ?? 0); ?>
        <li><input class="dl-mcheck" type="checkbox" id="<?= e($cid) ?>" name="<?= e($vn) ?>[]" value="<?= e((string) $k) ?>"<?= $on ? ' checked' : '' ?> data-live-change><label class="dl-fchip dl-fchip--check <?= e($tone($vf['field'], (string) $k)) ?>" for="<?= e($cid) ?>"><?= e(Tables::optionLabel($vf['field'], (string) $k)) ?><?php if ($n): ?> <span class="dl-fchip__n"><span class="dl-sr">(</span><?= $n ?><span class="dl-sr"> <?= e(lt('Einträge')) ?>)</span></span><?php endif; ?></label></li>
        <?php endforeach; ?>
      </ul>
    </fieldset>
    <?php endforeach; ?>
    <?php if ($vfilters): ?><p class="dl-mfilter__apply"><button class="dl-search__btn" type="submit"><?= e(lt('Filter anwenden')) ?></button></p><?php endif; ?>
    <?php if ($filtering): $total = $limit ? Entries::count($t, $o) : count($rows); ?>
    <p class="dl-count" role="status"><span data-live-count><?= e($total === 1 ? lt('1 Treffer') : lt('{n} Treffer', ['n' => $total])) ?></span> · <a href="<?= e($here . '#' . $b->domId()) ?>" data-live-nav><?= e(lt('Filter zurücksetzen')) ?></a></p>
    <?php endif; ?>
  </form>
  <?php elseif ($vfilters || $vsearch): $qid = $b->domId() . '-q'; ?>
  <div class="dl-tools">
    <?php if ($vsearch): ?>
    <form class="dl-search" role="search" method="get" data-live-nav action="<?= e($here . '#' . $b->domId()) ?>">
      <label class="dl-search__label" for="<?= e($qid) ?>"><?= e(lt('In der Liste suchen')) ?></label>
      <span class="dl-search__row"><input class="dl-search__input" id="<?= e($qid) ?>" type="search" name="q" value="<?= e($vq) ?>" maxlength="80" autocomplete="off" enterkeyhint="search" data-live-input><?php foreach ($vfilters as $vn => $vf): if ($vf['sel'] === null) continue; ?><input type="hidden" name="<?= e($vn) ?>" value="<?= e($vf['sel']) ?>"><?php endforeach; ?><button class="dl-search__btn" type="submit"><?= e(lt('Suchen')) ?></button></span>
    </form>
    <?php endif; ?>
    <?php foreach ($vfilters as $vn => $vf): $vl = Tables::label($vf['field']); ?>
    <nav class="dl-filter" aria-label="<?= e(lt('Filtern nach {label}', ['label' => $vl])) ?>">
      <span class="dl-filter__label" aria-hidden="true"><?= e($vl) ?></span>
      <ul class="dl-filter__list" role="list">
        <li><a class="dl-fchip" href="<?= e($qs([$vn => null])) ?>" data-live-nav rel="nofollow"<?= $vf['sel'] === null ? ' aria-current="true"' : '' ?>><?= e(lt('Alle')) ?></a></li>
        <?php foreach ($vf['options'] as $k => $l): ?><li><a class="dl-fchip <?= e($tone($vf['field'], (string) $k)) ?>" href="<?= e($qs([$vn => (string) $k])) ?>" data-live-nav rel="nofollow"<?= $vf['sel'] === (string) $k ? ' aria-current="true"' : '' ?>><?= e(Tables::optionLabel($vf['field'], (string) $k)) ?></a></li><?php endforeach; ?>
      </ul>
    </nav>
    <?php endforeach; ?>
    <?php if ($filtering): $total = $limit ? Entries::count($t, $o) : count($rows); ?>
    <p class="dl-count" role="status"><span data-live-count><?= e($total === 1 ? lt('1 Treffer') : lt('{n} Treffer', ['n' => $total])) ?></span> · <a href="<?= e($here . '#' . $b->domId()) ?>" data-live-nav><?= e(lt('Filter zurücksetzen')) ?></a></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($glossHits): $gid = $b->domId() . '-gl'; ?>
  <section class="dl-gloss" aria-labelledby="<?= e($gid) ?>">
    <h2 class="dl-gloss__h" id="<?= e($gid) ?>"><?= e(lt('Im Glossar')) ?></h2>
    <dl class="dl-gloss__list"><?php foreach ($glossHits as $g): ?><div><dt><?= $g['url'] ? '<a href="' . e($g['url']) . '">' . e($g['term']) . '</a>' : e($g['term']) ?></dt><dd><?= \Core\Search\Text::mark($g['short'], \Core\Search\Text::words($vq)) ?></dd></div><?php endforeach; ?></dl>
  </section>
  <?php endif; ?>
  <?php if ($tocMode && $rows): ?>
  <?= ($d['chapters_style'] ?? 'list') === 'cards' ? \Core\Data\Knowledge::tocCards($t, $ktree, $d) : \Core\Data\Knowledge::tocHtml($t, $ktree, 'h2') ?>
  <?php elseif ($flatCards && $rows): ?>
  <?= \Core\Data\Knowledge::flatCards($t, $ktree, $rows, $d, $vq !== '' && !empty($d['search_excerpt']) ? $excerpt : null) ?>
  <?php elseif (!$rows && $filtering): ?>
  <p class="dl-empty"><?= e(lt('Keine Einträge für diese Auswahl.')) ?> <a href="<?= e($here . '#' . $b->domId()) ?>"><?= e(lt('Alle anzeigen')) ?></a></p>
  <?php elseif (!$rows): ?>
  <p class="dl-empty"><?= e($d['empty_text'] ?: lt('Zurzeit gibt es hier keine Einträge.')) ?><?= is_editing() ? ' <small>(nur veröffentlichte Einträge erscheinen)</small>' : '' ?></p>
  <?php elseif ($layout === 'table'):
    // Zahlenfelder rechtsbündig (dl-num), Zeilen mit gesetztem Ja/Nein-Feld „highlight_field“ hervorgehoben (dl-row--hl, z. B. Summen)
    $num = fn(string $f) => (\Core\Data\Network::anyField($t, $f)['type'] ?? '') === 'number' ? ' class="dl-num"' : '';
    $hl = str_starts_with((string) ($d['highlight_field'] ?? ''), $pfx) ? substr($d['highlight_field'], strlen($pfx)) : null;
    // Merkmale für Kits (Theme::markUsed): '@table' bei jeder Tabelle; „Spalten sortierbar“ → '@tablesort' lädt Kern-Skript
    // js/table-sort.js und css/table-sort.css (Komponente von NativeCSS: Schaltflächen im Kopf, aria-sort, Ansage; dazu Schatten
    // an der Kante) – nicht beim Bearbeiten. Zahlenspalten: Schaltfläche rechtsbündig (data-sort-align="end")
    $sortable = !empty($d['table_sort']) && !is_editing();
    app()->theme->markUsed('@table', $sortable ? '@tablesort' : ''); ?>
  <div class="dl-tablewrap<?= $sortable ? ' dl-tablewrap--sort" role="region" tabindex="0" aria-label="' . e(trim(lt('Tabelle') . ': ' . strip_emphasis(trim((string) ($d['title'] ?? ''))), ': ')) : '' ?>"><table class="dl-table"<?= $sortable ? ' data-ncss-sort data-label-sorted="' . e(lt('Sortiert nach {column}, {direction}')) . '" data-label-ascending="' . e(lt('aufsteigend')) . '" data-label-descending="' . e(lt('absteigend')) . '"' : '' ?>>
    <thead><tr><?php foreach ($fields as $f): if ($f === $imageField) continue; ?><th scope="col"<?= $num($f) ?><?= $sortable && $num($f) !== '' ? ' data-sort-align="end"' : '' ?>><?= e($colLabel($f)) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $e): $url = $link($e); ?>
      <tr<?= $hl !== null && !empty($e[$hl]) ? ' class="dl-row--hl"' : '' ?>><?php foreach ($fields as $f): if ($f === $imageField) continue; ?>
        <td<?= $num($f) ?>><?php if ($f === '_title'): ?><?= $url ? '<a href="' . e($url) . '">' . e(Entries::title($t, $e)) . '</a>' : e(Entries::title($t, $e)) ?><?= \Core\Data\EntryEdit::button($t, $e) ?><?php else: ?><?= Entries::html($t, $e, $f) ?><?php endif; ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
  <?php if ($az && count($rows) > 1): $azLetters = array_merge(range('A', 'Z'), isset($groups['#']) ? ['#'] : []); ?>
  <nav class="dl-az" aria-label="<?= e(lt('Nach Anfangsbuchstaben springen')) ?>"><ul class="dl-az__list" role="list">
    <?php foreach ($azLetters as $L): ?><li><?php if (isset($groups[$L])): ?><a href="#<?= e($b->domId() . '-az-' . ($L === '#' ? 'num' : strtolower($L))) ?>"><?= e($L) ?></a><?php else: ?><span aria-hidden="true"><?= e($L) ?></span><?php endif; ?></li><?php endforeach; ?>
  </ul></nav>
  <?php endif; ?>
  <?php foreach ($groups as $L => $grows): $chipField = fn(string $f) => $layout === 'directory' && in_array(\Core\Data\Network::anyField($t, $f)['type'] ?? '', ['select', 'multiselect'], true); ?>
  <?php if ($L !== ''): ?><<?= $azTag ?> class="dl-az__head" id="<?= e($b->domId() . '-az-' . ($L === '#' ? 'num' : strtolower($L))) ?>"><?= e($L) ?></<?= $azTag ?>><?php endif; ?>
  <ul class="dl-items<?= in_array($layout, ['cards', 'directory'], true) ? ' dl-cols-' . e((string) ($d['columns'] ?: 3)) : '' ?>" role="list">
    <?php foreach ($grows as $e): $url = $link($e); $title = Entries::title($t, $e); ?>
    <li class="dl-item<?= ($e['_pick'] ?? null) === 'featured' ? ' dl-item--featured' : '' ?>" data-reveal="up"><?= \Core\Data\EntryEdit::button($t, $e, $title) ?>
      <?php if ($imageField && $layout !== 'compact'): $pic = Entries::html($t, $e, $imageField, ['ratio' => $d['ratio'] ?? '16:10', 'sizes' => in_array($layout, ['cards', 'directory'], true) ? '(min-width: 1080px) 400px, 100vw' : '(min-width: 1080px) 320px, 40vw']); ?>
      <div class="dl-img ratio-<?= e(str_replace(':', '-', (string) ($d['ratio'] ?? '16:10'))) ?><?= $contain ? ' dl-img--contain' : '' ?>"><?= $pic ?: '<span class="dl-ph" aria-hidden="true"></span>' ?></div>
      <?php endif; ?>
      <div class="dl-body">
        <?php $meta = $jobs && ($js = \Core\Data\Jobs::summary($t, $e)) !== '' ? [e($js)] : []; foreach ($other as $f) { if ($chipField($f)) continue; if (in_array(\Core\Data\Network::anyField($t, $f)['type'] ?? 'date', ['date', 'datetime', 'select', 'time'], true) || $f === 'published_at') { $h = Entries::html($t, $e, $f); if ($h !== '') $meta[] = $h; } } ?>
        <?php if ($meta): ?><p class="dl-meta"><?= implode('<span aria-hidden="true"> · </span>', $meta) ?></p><?php endif; ?>
        <?php if ($showTitle): ?><<?= $hTag ?> class="dl-name<?= $titleClamp ?>"><?= $knum($e) ?><?= $url ? '<a href="' . e($url) . '" class="dl-link">' . e($title) . '</a>' : e($title) ?></<?= $hTag ?>><?php endif; ?>
        <?php if (($ex = $excerpt($e)) !== ''): ?><p class="dl-excerpt"><?= $ex ?></p><?php endif; ?>
        <?php foreach ($other as $f): $type = \Core\Data\Network::anyField($t, $f)['type'] ?? 'date'; if (in_array($type, ['date', 'datetime', 'select', 'time'], true) || $chipField($f) || ($ex !== '' && in_array($type, ['text', 'textarea', 'richtext'], true))) continue; [$clamp, $html] = Clamp::text($t, $e, $f, $d); if ($html === '') continue; ?>
        <div class="dl-f dl-f--<?= e($type) ?><?= $clamp ?>"><?= $html ?></div>
        <?php endforeach; ?>
        <?php foreach ($other as $f): if (!$chipField($f) || ($ch = $chips($e, $f)) === '') continue; ?>
        <div class="dl-f dl-f--chips"><?= $ch ?></div>
        <?php endforeach; ?>
        <?php if ($url && !$showTitle): ?><a href="<?= e($url) ?>" class="dl-link dl-more-link"><?= e(lt('Mehr')) ?><span class="sr-only"> <?= e(lt('zu {title}', ['title' => $title])) ?></span></a><?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($pages > 1): $base = $here; ?>
  <nav class="dl-pager" aria-label="<?= e(lt('Seiten')) ?>">
    <?php if ($page > 1): ?><a href="<?= e($keep ? $qs(['seite' => $page > 2 ? $page - 1 : null]) : $base . ($page > 2 ? '?seite=' . ($page - 1) : '')) ?>" data-live-nav rel="prev">← <?= e(lt('Neuere')) ?></a><?php endif; ?>
    <span><?= e(lt('Seite {page} von {pages}', ['page' => $page, 'pages' => $pages])) ?></span>
    <?php if ($page < $pages): ?><a href="<?= e($keep ? $qs(['seite' => $page + 1]) : $base . '?seite=' . ($page + 1)) ?>" data-live-nav rel="next"><?= e(lt('Ältere')) ?> →</a><?php endif; ?>
  </nav>
  <?php endif; ?>

  <?php if (!empty($d['more_label']) && !empty($d['more_link'])): ?>
  <p class="dl-more"><a class="<?= e($btn) ?>" href="<?= e(link_href((string) $d['more_link'])) ?>"<?= $b->edit('more_label') ?>><?= e($d['more_label']) ?></a></p>
  <?php endif; ?>
<?= \Core\Data\EntryEdit::newButton($t) ?></div>
<?= $reload !== '' ? \Core\Live::assets() : '' /* live.js nur, wenn die Liste nachladen kann */ ?>
