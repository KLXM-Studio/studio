<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Http\Controllers\Admin\DataController;
use Core\Pages;

/**
 * Selbsttest Wissensbereich mit Datenbank (php bin/console knowledge:selftest): vorübergehende Wissenstabelle aus der Vorlage,
 * Schlagworte/Bereiche anlegen, Kapitelbaum, Ansicht in allen Layout-Modi (Landmarken, aria-current, Sprunglink, Vor/Zurück,
 * Schlagwort-Links), Datenliste mit Mehrfach-Filter, Trefferauszug und Inhaltsverzeichnis, Glossar bleibt websiteweit, Umstieg eines
 * Seitenzweigs (data:import-pages) mit Zurückdrehen. Räumt alles wieder ab.
 */
final class KnowledgeSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $ck = function (bool $c, string $msg) use (&$ok, &$fails): void { if ($c) $ok++; else $fails[] = 'Wissen (DB) – ' . $msg; };
        $h = 'qa_kn_' . bin2hex(random_bytes(3));
        $route = str_replace('_', '-', $h);
        $tablesBefore = array_column(Tables::content(), 'handle');
        $prevReq = app()->request;
        $prevEntry = app()->entry;
        $pages = [];
        $tables = [];
        try {
            // 1. Tabelle aus der Vorlage „Wissen“
            $def = DataController::presetDef('knowledge');
            $def['handle'] = $h;
            $def['name'] = 'QA Wissen';
            $def['settings']['route'] = $route;
            [$clean, $err] = Tables::validate($def);
            $ck($err === [], 'Vorlage gültig: ' . json_encode($err, JSON_UNESCAPED_UNICODE));
            if ($err) return ['ok' => $ok, 'fails' => $fails];
            Tables::create($clean);
            Tables::flush();
            $t = Tables::find($h);
            DataController::makeTemplate($t);
            $t = Tables::find($h);
            if ($lp = Knowledge::makeListPage($t)) $pages[] = $lp;   // Übersicht /{route}: Suche in der Seitenleiste, Schlagwort-Links
            $ck(count($pages) === 1, 'Übersichtsseite mit Datenliste „Kapitel“');
            $ck(Kinds::isKnowledge($t) && ($t['settings']['kind_version'] ?? 0) === Kinds::version(Kinds::KNOWLEDGE) && Knowledge::config($t) === Knowledge::DEFAULTS
                && ($t['settings']['image_field'] ?? '') === 'bild' && Tables::field($t, 'verweis'), 'Art „Wissen“, aktuelle Version, Standard-Einstellungen, Bild- und Verweisfeld');
            // Grundschema nur additiv: Version 1 ⊂ Version 2 (Bild, Verweis neu)
            $v1 = Kinds::systemNames(Kinds::KNOWLEDGE, 1);
            $v2 = Kinds::systemNames(Kinds::KNOWLEDGE, 2);
            $ck(array_values(array_intersect($v2, $v1)) === $v1 && array_values(array_diff($v2, $v1)) === ['bild', 'verweis'], 'Grundschema v2 ergänzt nur Bild und Verweis');
            $ck((Tables::field($t, 'oberkapitel')['target'] ?? '') === $h && (Tables::field($t, 'verwandt')['target'] ?? '') === $h, 'Oberkapitel/Verwandte verweisen auf die eigene Tabelle');
            $gt = (string) (Tables::field($t, 'begriffe')['target'] ?? '');
            $ck($gt === '' || $gt === \Core\Glossary\Glossary::HANDLE, 'Glossarbegriffe nur aufs Website-Glossar');
            $tpl = Pages::find((int) $t['settings']['detail_page_id']);
            $ck($tpl && str_contains((string) $tpl['content_published'], '"entry_blocks"'), 'Detailvorlage mit Inhaltsblöcken');
            // 2. Bereiche und Schlagworte anlegen (wie im Formular)
            $in = Knowledge::applyNewOptions($t, ['titel' => 'x', Knowledge::NEW_PREFIX . 'bereich' => 'Grundlagen', Knowledge::NEW_PREFIX . 'schlagworte' => 'Wald, Wild; Wald']);
            $t = Tables::find($h);
            $ck(($in['bereich'] ?? '') !== '' && count((array) ($in['schlagworte'] ?? [])) === 2 && !isset($in[Knowledge::NEW_PREFIX . 'bereich']), 'Neue Bereiche/Schlagworte angelegt und gewählt');
            $again = Knowledge::addOptions($t, 'schlagworte', ['wald', 'Recht']);
            $t = Tables::find($h);
            $ck(count((array) Tables::field($t, 'schlagworte')['options']) === 3 && count($again['keys']) === 2, 'Vorhandene Schlagworte wiederverwendet (Groß/klein egal)');
            Knowledge::addOptions($t, 'bereich', ['Praxis']);
            $t = Tables::find($h);
            [$wald, $wild, $recht] = array_map('strval', array_keys((array) Tables::field($t, 'schlagworte')['options']));
            [$gr, $pr] = array_map('strval', array_keys((array) Tables::field($t, 'bereich')['options']));
            // 3. Kapitel (3 Ebenen)
            $blocks = fn(string $s) => [['id' => bin2hex(random_bytes(5)), 'type' => 'richtext', 'data' => ['text' => "<h2>Erster Teil</h2><p>{$s} Hegeringzählung im Revier.</p><h2>Zweiter Teil</h2><p>Mehr.</p>"]]];
            $save = function (array $v) use ($t): int { [$id, $e] = Entries::save($t, null, $v + ['status' => 'published']); return $e ? 0 : (int) $id; };
            $a = $save(['titel' => 'Anfang', 'bereich' => $gr, 'schlagworte' => [$wald], 'inhalt' => $blocks('Einleitung')]);
            $b = $save(['titel' => 'Bestand', 'bereich' => $gr, 'oberkapitel' => $a, 'schlagworte' => [$wald, $wild], 'inhalt' => $blocks('Zählung')]);
            $c = $save(['titel' => 'Cnacht', 'bereich' => $gr, 'oberkapitel' => $b, 'schlagworte' => [$wild], 'inhalt' => $blocks('Nacht')]);
            $d = $save(['titel' => 'Draußen', 'bereich' => $pr, 'schlagworte' => [$recht], 'inhalt' => $blocks('Praxis')]);
            $ck($a && $b && $c && $d, 'Kapitel gespeichert');
            Knowledge::flush();
            $tree = Knowledge::tree($t);
            $ck(array_map(fn($id) => $tree['nodes'][$id]['number'], [$a, $b, $c, $d]) === ['1.1', '1.1.1', '1.1.1.1', '2.1'], 'Nummern 1.1 / 1.1.1 / 1.1.1.1 / 2.1');
            // 4. Ansicht in allen Layout-Modi
            $eb = Entries::find($t, $b);
            app()->entry = ['table' => $t, 'entry' => $eb];
            foreach (array_keys(Knowledge::LAYOUTS) as $mode) {
                $t2 = $t;
                $t2['settings']['knowledge'] = ['layout' => $mode] + Knowledge::DEFAULTS;
                Knowledge::flush();
                $html = Knowledge::frame('<h1 class="h2">Bestand</h1><h2>Erster Teil</h2><p>x</p><h2>Zweiter Teil</h2>', $t2, $eb);
                $ck(str_contains($html, 'kn--' . $mode) && str_contains($html, 'aria-current="page"') && str_contains($html, 'class="kn-skip"')
                    && str_contains($html, '<nav class="kn-nav" aria-label=') && str_contains($html, 'role="search"'), "Ansicht {$mode}: Landmarken, aria-current, Sprunglink, Suche");
                $ck(str_contains($html, 'kn-num--h1">1.1.1</span>') && str_contains($html, 'rel="prev"') && str_contains($html, 'rel="next"'), "Ansicht {$mode}: Nummer in der Überschrift, Vor/Zurück");
                $ck(($mode === 'handbook') === str_contains($html, 'class="kn-aside"'), "Ansicht {$mode}: Spalte „In diesem Kapitel“ nur bei Handbuch");
                $ck(in_array($mode, ['handbook', 'handbook_menu'], true) === str_contains($html, 'kn-toc--inline'), "Ansicht {$mode}: aufklappbares „In diesem Kapitel“ nur in den Handbuch-Modi");
                $ck(in_array($mode, ['handbook', 'handbook_menu'], true) !== str_contains($html, 'kn-secs--nav'), "Ansicht {$mode}: Abschnitte in der Seitenleiste nur ohne Handbuch");
                $ck(str_contains($html, '?schlagworte%5B0%5D=' . rawurlencode($wild)) || str_contains($html, 'schlagworte%5B0%5D=' . $wild), "Ansicht {$mode}: Schlagworte verlinken die gefilterte Übersicht");
            }
            Knowledge::flush();
            $rel = Knowledge::related($t, Entries::find($t, $a), Knowledge::tree($t));
            $ck(array_column($rel, 'id') === [$b], 'Verwandte Kapitel über gemeinsame Schlagworte');
            // Modus „Bearbeiten“: dasselbe Layout (Kapitelbaum, Nummer, Brotkrumen), Seitenleiste inaktiv und ohne Suche, kein Skript-Anker
            $ed = Knowledge::frame('<h1 class="h2">Bestand</h1>' . EntryBlocks::SLOT, $t, Entries::find($t, $b), true);
            $ck(str_contains($ed, 'kn--editing') && str_contains($ed, 'class="kn-side" id="') && str_contains($ed, '" inert>') && !str_contains($ed, 'role="search"')
                && !str_contains($ed, 'data-kn>') && !str_contains($ed, 'data-kn-toggle') && str_contains($ed, 'kn-num--h1">1.1.1<') && str_contains($ed, EntryBlocks::SLOT) && Knowledge::$framed,
                'Bearbeiten: Kapitel-Layout bleibt, Seitenleiste inaktiv ohne Suche, Platz für den Editor');
            // 4b. Einträge im Menü (Core\Data\MenuEntries) unter der Übersichtsseite: zwei Ebenen mit Nummern, oberste Ebene, aus
            $setMenu = function (array $me) use (&$t, $h): void {
                $in = Tables::toInput($t);
                $in['settings']['menu_entries'] = $me;
                [$clean, $err] = Tables::validate($in, $t);
                if (!$err) Tables::update($t, $clean);
                Tables::flush();
                Knowledge::flush();
                $t = Tables::find($h);
            };
            $lpRow = $pages ? Pages::find($pages[0]) : null;
            if ($lpRow) {
                Pages::db()->update('pages', ['menu' => 1], 'id = :id', ['id' => (int) $lpRow['id']]);
                $prevPage = app()->currentPage;
                app()->currentPage = null;
                $find = function () use ($lpRow): ?array {
                    foreach (Pages::menu() as $m) if ($m['href'] === Pages::url($lpRow)) return $m;
                    return null;
                };
                $ck(($find()['children'] ?? null) === [], 'Menü: ohne Einstellung keine Einträge (Bestand unverändert)');
                $setMenu(['mode' => 'two', 'numbers' => true]);
                app()->entry = ['table' => $t, 'entry' => Entries::find($t, $b)];
                $m = $find();
                $ck(array_column($m['children'] ?? [], 'label') === ['1.1 Anfang', '2.1 Draußen'] && array_column($m['children'][0]['children'] ?? [], 'label') === ['1.1.1 Bestand'],
                    'Menü: zwei Ebenen in Kapitelreihenfolge mit Nummern: ' . json_encode(array_column($m['children'] ?? [], 'label'), JSON_UNESCAPED_UNICODE));
                $ck(!empty($m['active']) && !empty($m['children'][0]['active']) && !empty($m['children'][0]['children'][0]['active']) && empty($m['children'][1]['active']),
                    'Menü: auf der Detailseite sind Übersicht und Kapitelkette aktiv');
                $setMenu(['mode' => 'top', 'limit' => 1]);
                $m = $find();
                $ck(array_column($m['children'] ?? [], 'label') === ['Anfang'] && ($m['children'][0]['children'] ?? null) === [], 'Menü: oberste Ebene, Höchstzahl, ohne Nummern');
                $setMenu(['mode' => '']);
                app()->entry = null;
                app()->currentPage = $prevPage;
                Pages::db()->update('pages', ['menu' => 0], 'id = :id', ['id' => (int) $lpRow['id']]);
            }
            // 4c. JSON-LD: Kapitel (Article in der Sammlung, Kette, Nummer, Bereich, Abschnitte, geprüft), Übersicht (CollectionPage ohne ItemList)
            if ($lpRow) {
                $tpl = Pages::find((int) $t['settings']['detail_page_id']);
                Entries::save($t, $b, ['geprueft_am' => '2026-10-01', 'autor' => 'Erika Musterfrau']);
                Knowledge::flush();
                $eB = Entries::find($t, $b);
                Knowledge::$sections = [['id' => 'kn-erster-teil', 'label' => 'Erster Teil']];
                \Core\StructuredData::reset();
                $g = \Core\StructuredData::graph($tpl, $t, $eB, []);
                $byType = [];
                foreach ($g['@graph'] as $n) $byType[(string) ($n['@type'] ?? '')][] = $n;
                $art = $byType['Article'][0] ?? [];
                $collId = KnowledgeLd::collectionId($t);
                $parts = isset($art['isPartOf']['@id']) ? [$art['isPartOf']] : (array) ($art['isPartOf'] ?? []);
                $ck(KnowledgeLd::check($g) === [] && isset($byType['Collection'][0]) && count($byType['Article'] ?? []) === 1, 'JSON-LD Kapitel: gültig, @id-Bezüge auflösbar, keine Dopplungen: ' . implode('; ', KnowledgeLd::check($g)));
                $ck(($parts[0]['@id'] ?? '') === $collId && str_ends_with((string) ($parts[1]['url'] ?? ''), '/' . (string) $t['settings']['route'] . '/' . Entries::find($t, $a)['slug'])
                    && ($art['alternateName'] ?? '') === '1.1.1' && !isset($art['position']), 'JSON-LD Kapitel: isPartOf Sammlung + Oberkapitel, alternateName = Nummer');
                $ck(($art['articleSection'] ?? '') === 'Grundlagen' && str_contains((string) ($art['keywords'] ?? ''), 'Wild') && ($art['author']['name'] ?? '') === 'Erika Musterfrau'
                    && ($art['hasPart'][0]['@type'] ?? '') === 'WebPageElement' && str_ends_with((string) ($art['hasPart'][0]['url'] ?? ''), '#kn-erster-teil'), 'JSON-LD Kapitel: Bereich, Schlagworte, Autor, Abschnitte');
                // Glossarbegriffe: about mit derselben @id wie die Glossar-Detailseite (falls es ein Glossar mit Begriffen gibt)
                $gt = \Core\Glossary\Glossary::enabled() && (Tables::field($t, 'begriffe')['target'] ?? '') === \Core\Glossary\Glossary::HANDLE ? (\Core\Glossary\Glossary::terms()[0] ?? null) : null;
                if ($gt && $gt['url']) {
                    Entries::save($t, $b, ['begriffe' => [$gt['id']]]);
                    $gG = \Core\StructuredData::graph($tpl, $t, Entries::find($t, $b), []);
                    $artG = array_values(array_filter($gG['@graph'], fn($n) => ($n['@type'] ?? '') === 'Article'))[0] ?? [];
                    $ck(($artG['about'][0]['@id'] ?? '') === \Core\Glossary\Glossary::termLdId($gt['url']) && ($artG['about'][0]['@type'] ?? '') === 'DefinedTerm'
                        && ($artG['about'][0]['inDefinedTermSet']['@id'] ?? null) === \Core\Glossary\Glossary::setLdId(), 'JSON-LD Kapitel: Glossarbegriffe mit der @id der Glossar-Detailseite');
                    Entries::save($t, $b, ['begriffe' => []]);
                }
                $wp = $byType['WebPage'][0] ?? [];
                $ck(($wp['lastReviewed'] ?? '') === '2026-10-01' && isset($wp['reviewedBy']), 'JSON-LD WebPage: lastReviewed, reviewedBy');
                $gA = \Core\StructuredData::graph($tpl, $t, Entries::find($t, $a), []);
                $artA = array_values(array_filter($gA['@graph'], fn($n) => ($n['@type'] ?? '') === 'Article'))[0] ?? [];
                $ck(($artA['position'] ?? 0) === 1 && ($artA['isPartOf']['@id'] ?? '') === $collId, 'JSON-LD Kapitel der obersten Ebene: position');
                // Übersicht: CollectionPage, Sammlung mit Kapiteln in Lesereihenfolge, keine ItemList der Datenliste
                $prevPage2 = app()->currentPage;
                app()->currentPage = $lpRow;
                app()->entry = null;
                \Core\StructuredData::reset();
                app()->theme->renderBlocks(Pages::blocks($lpRow));
                $go = \Core\StructuredData::graph($lpRow, null, null, []);
                $types = array_column($go['@graph'], '@type');
                $coll = array_values(array_filter($go['@graph'], fn($n) => ($n['@type'] ?? '') === 'Collection'))[0] ?? [];
                $order = array_map(fn($id) => abs_url((string) Knowledge::tree($t)['nodes'][$id]['href']) . '#main', Knowledge::tree($t)['order']);
                $ck(in_array('CollectionPage', $types, true) && !in_array('ItemList', $types, true) && array_column($coll['hasPart'] ?? [], '@id') === $order && KnowledgeLd::check($go) === [],
                    'JSON-LD Übersicht: CollectionPage, hasPart in Lesereihenfolge, keine ItemList, auflösbar');
                app()->currentPage = $prevPage2;
                Knowledge::$sections = [];
            }
            $mu = MenuEntries::selftest();
            $ok += $mu['ok'];
            array_push($fails, ...$mu['fails']);
            // 5. Datenliste: Inhaltsverzeichnis, Mehrfach-Filter („eine davon“ je Feld, Felder zusammen), Trefferauszug
            $th = app()->theme;
            if ($th->block('data_list') && !$th->files('blocks/data_list.php')) {
                $render = function (array $query) use ($th, $t): string {
                    app()->request = new \Core\Http\Request('GET', '/qa', $query, [], [], []);
                    $blk = Pages::sanitizeBlocks([['id' => 'qakn', 'type' => 'data_list', 'data' => ['title' => 'QA'] + Knowledge::listBlock($t)]]);
                    return $blk ? (string) $th->renderInner($th->makeBlock($blk[0])) : '';
                };
                $toc = $render([]);
                $ck(str_contains($toc, 'kn-toc-all') && str_contains($toc, '1.1.1.1') && str_contains($toc, 'type="checkbox"') && str_contains($toc, 'data-live-change'), 'Übersicht: Inhaltsverzeichnis mit Nummern, Filter als Kontrollkästchen');
                $one = $render(['schlagworte' => [$wild, $recht]]);
                $ck(str_contains($one, 'Bestand') && str_contains($one, 'Cnacht') && str_contains($one, 'Draußen') && !str_contains($one, '>Anfang<') && str_contains($one, '3 Treffer'), 'Mehrfach-Filter: eine der Optionen je Feld');
                $two = $render(['schlagworte' => [$wild, $recht], 'bereich' => [$gr]]);
                $ck(str_contains($two, 'Bestand') && !str_contains($two, 'Draußen') && str_contains($two, '2 Treffer') && substr_count($two, ' checked') === 3, 'Mehrfach-Filter: Felder zusammen, Auswahl bleibt angekreuzt');
                $q = $render(['q' => 'Nacht']);
                $ck(str_contains($q, 'dl-excerpt') && str_contains($q, '<mark>Nacht</mark>') && str_contains($q, '1 Treffer'), 'Suche im Wissensbereich mit Trefferauszug');
                $ck(!str_contains($render(['schlagworte' => 'quatsch']), 'checked'), 'Unbekannte Auswahl aus der Adresse wird ignoriert');
                // Kapitel als Karten: oberste Ebene mit Bild/Nummer/Anreißer, höchstens drei Unterkapitel + „weitere“, Verweis mit Bild der Zielseite
                $mid0 = (int) (Pages::db()->fetchValue("SELECT id FROM media WHERE deleted_at IS NULL AND mime LIKE 'image/%' ORDER BY id LIMIT 1") ?: 0);
                $kp = Pages::create(['slug' => 'qa-kn-ziel-' . bin2hex(random_bytes(2)), 'title' => 'Zielseite', 'status' => 'published', 'og_image' => $mid0 ?: null]);
                $pages[] = $kp;
                $extraIds = [];
                foreach (['K1', 'K2', 'K3', 'K4'] as $k) $extraIds[] = $save(['titel' => $k, 'bereich' => $pr, 'oberkapitel' => $d, 'anreisser' => 'Kurz ' . $k]);
                $extraIds[] = $save(['titel' => 'Zum Ziel', 'bereich' => $pr, 'verweis' => 'page:' . $kp]);
                Knowledge::flush();
                $cardsHtml = (function () use ($th, $t): string {
                    app()->request = new \Core\Http\Request('GET', '/qa', [], [], [], []);
                    $blk = Pages::sanitizeBlocks([['id' => 'qakc', 'type' => 'data_list', 'data' => ['title' => 'QA', 'chapters_style' => 'cards', 'columns' => '3', 'ratio' => '4:3'] + Knowledge::listBlock($t)]]);
                    return $blk ? (string) $th->renderInner($th->makeBlock($blk[0])) : '';
                })();
                $ck(preg_match_all('~<li class="kn-card( kn-card--img)?( kn-card--link)?"~', $cardsHtml) === 3 && str_contains($cardsHtml, 'kn-card__num">2.1<') && str_contains($cardsHtml, 'kn-cards--c3') && str_contains($cardsHtml, 'class="kn-card__link"')
                    && substr_count($cardsHtml, 'class="kn-card__sub"') === 2, 'Karten: drei Kapitel der obersten Ebene, Nummer als Plakette, Unterkapitel als Links');
                $ck(str_contains($cardsHtml, ' K3</a>') && !str_contains($cardsHtml, ' K4</a>') && str_contains($cardsHtml, 'kn-card__more'), 'Karten: höchstens drei Unterkapitel, dann „weitere“');
                $ck(!$mid0 || Knowledge::imageOf($t, Entries::find($t, end($extraIds))) === $mid0, 'Karten: Verweis-Kapitel ohne Bild nimmt das Bild der Zielseite');
                // Datenliste: Bedingung „ist leer“ / „ist nicht leer“ (ohne Wert)
                $ck(Entries::count($t, ['where' => [['verweis', 'notempty', '']]]) === 1 && Entries::count($t, ['where' => [['oberkapitel', 'empty', '']]]) === 3
                    && Entries::count($t, ['where' => [['schlagworte', 'empty', '']]]) === 5, 'Bedingung „ist leer“/„ist nicht leer“ (Text, Verknüpfung, Mehrfachauswahl)');
                foreach ($extraIds as $x) if ($x) Entries::delete($t, $x);
                Knowledge::flush();
            }
            // 6. Glossar bleibt websiteweit: keine weitere Glossar-Tabelle entstanden
            $ck(array_values(array_filter(array_diff(array_column(Tables::content(), 'handle'), $tablesBefore), fn($x) => $x !== $h && str_contains($x, 'glossar'))) === [], 'Keine Glossar-Tabelle je Wissensbereich');
            // 6b. Tabelle der Version 1 (ohne Bild/Verweis) wird additiv aktualisiert – Einträge bleiben unverändert
            $old = $t;
            $old['fields'] = array_values(array_filter($t['fields'], fn($f) => !in_array($f['name'] ?? '', ['bild', 'verweis'], true)));
            $old['settings']['kind_version'] = 1;
            $old['settings']['image_field'] = '';
            Tables::update($t, $old);
            $t1 = Tables::find($h);
            $before = Entries::find($t1, $a);
            $t2 = Knowledge::upgrade($t1);
            $after = Entries::find($t2, $a);
            $ck(($t1['settings']['kind_version'] ?? 0) === 1 && ($t2['settings']['kind_version'] ?? 0) === 2 && Tables::field($t2, 'bild') && Tables::field($t2, 'verweis')
                && ($t2['settings']['image_field'] ?? '') === '' && array_diff_key($after, ['bild' => 1, 'verweis' => 1, 'updated_at' => 1]) == array_diff_key($before, ['updated_at' => 1])
                && empty($after['bild']) && empty($after['verweis']), 'Version 1 → 2: neue Felder leer, Einträge und Einstellungen unverändert');
            $t = $t2;
            // 6c. Datenliste in Einträgen: wählbar, für Wissen vorbelegt; höchstens eine Ebene (Zyklus, Kette über andere Tabelle),
            // eigener Eintrag ausgeschlossen, „aufgerufener Eintrag“ = Eintrag der Blöcke – auch wenn eine andere Detailseite offen ist
            $ck(isset(EntryBlocks::choices()['data_list']) && !in_array('data_list', EntryBlocks::defaults(), true) && in_array('data_list', Knowledge::contentBlocks(), true)
                && in_array('data_list', (array) (Tables::field($t, 'inhalt')['blocks'] ?? []), true), 'Datenliste in Einträgen wählbar, in neuen Wissenstabellen vorbelegt');
            $th = app()->theme;
            if ($th->block('data_list') && !$th->files('blocks/data_list.php')) {
                $hb = $h . 'b';
                [$defB, $errB] = Tables::validate(['name' => 'QA B', 'singular' => 'QA B', 'handle' => $hb, 'fields' => [
                    ['label' => 'Name', 'name' => 'name', 'type' => 'text', 'required' => 1],
                    ['label' => 'Ref', 'name' => 'ref', 'type' => 'relation', 'target' => $h],
                    ['label' => 'Inhalt', 'name' => 'inhalt', 'type' => 'blocks', 'blocks' => ['richtext', 'data_list']]]]);
                $ck($errB === [], 'Datenliste per Feldeinstellung in anderen Tabellen wählbar');
                Tables::create($defB);
                Tables::flush();
                $tb = Tables::find($hb);
                $tables[] = $hb;
                $dl = fn(string $table, array $x = []) => ['id' => bin2hex(random_bytes(4)), 'type' => 'data_list', 'data' => ['table' => $table, 'layout' => 'list', 'limit' => 0,
                    'fields' => [$table . '._title', $table . '.inhalt'], 'link_detail' => false] + $x];
                Entries::save($tb, null, ['name' => 'B-eins', 'ref' => $a, 'inhalt' => [$dl($h)], 'status' => 'published']);
                Entries::save($tb, null, ['name' => 'B-zwei', 'ref' => $b, 'inhalt' => [], 'status' => 'published']);
                // Eintrag „Anfang“: Liste der eigenen Tabelle (mit Inhalt = Zyklus) und Liste von B, gefiltert auf „aufgerufener Eintrag“ (Kette B → Wissen)
                Entries::save($t, $a, ['inhalt' => [$dl($h), $dl($hb, ['filter_field' => $hb . '.ref', 'filter_op' => 'current'])]]);
                $other = Entries::find($t, $d);
                app()->entry = ['table' => $t, 'entry' => $other];   // es ist gerade eine andere Detailseite offen
                $t0 = microtime(true);
                $html = Entries::html($t, Entries::find($t, $a), 'inhalt');
                $ms = (microtime(true) - $t0) * 1000;
                $names = fn(string $x) => array_map(fn($m) => (string) preg_replace('~^[0-9.]+\s+~u', '', trim(strip_tags($m))), preg_match_all('~<h[23] class="dl-name[^"]*">(.*?)</h[23]>~s', $x, $mm) ? $mm[1] : []);
                $listed = $names($html);
                $ck(substr_count($html, ' dl dl--') === 2, 'Zyklus und Kette: nur die zwei Listen des Eintrags, keine Liste in den gelisteten Einträgen (' . substr_count($html, ' dl dl--') . ')');
                $ck(!in_array('Anfang', $listed, true) && in_array('Bestand', $listed, true), 'Liste der eigenen Tabelle ohne den Eintrag selbst: ' . implode('|', $listed));
                $ck(in_array('B-eins', $listed, true) && !in_array('B-zwei', $listed, true), 'Filter „verknüpft mit dem aufgerufenen Eintrag“ bezieht sich auf den Eintrag der Blöcke');
                $ck((int) (app()->entry['entry']['id'] ?? 0) === $d, 'Kontext danach wie vorher');
                $ck($ms < 3000, 'Leistung: Eintrag mit zwei Listen in ' . (int) $ms . ' ms');
                $g = EntryBlocks::enterList();
                $ck($g !== null && EntryBlocks::enterList() === null, 'Wächter frei nach der Ausgabe, zweite Liste darin gesperrt');
                unset($g);
                $ck(EntryBlocks::enterList() !== null, 'Wächter gibt beim Verlassen frei');
                // Detailseite des Eintrags: Inhaltsblöcke über entry_blocks – gleiche Regeln
                app()->entry = ['table' => $t, 'entry' => Entries::find($t, $a)];
                $page = $th->renderBlocks([['id' => 'qaeb', 'type' => 'entry_blocks', 'data' => ['table' => $h, 'field' => $h . '.inhalt']]]);
                $ck(substr_count($page, ' dl dl--') === 2 && in_array('B-eins', $names($page), true) && !in_array('Anfang', $names($page), true), 'Auf der Detailseite dieselben Regeln');
                Entries::save($t, $a, ['inhalt' => $blocks('Einleitung')]);
            }
            // 7. Seitenzweig umstellen und zurückdrehen
            $slug = 'qa-knroot-' . bin2hex(random_bytes(2));
            $root = Pages::create(['slug' => $slug, 'title' => 'QA Zweig', 'status' => 'published']);
            $p1 = Pages::create(['slug' => 'erstes', 'title' => 'Erstes', 'status' => 'published', 'parent_id' => $root, 'sort' => 1, 'meta_description' => 'Anriss eins'],
                [['id' => 'qa1', 'type' => 'richtext', 'data' => ['text' => '<h2>Teil</h2><p>Text eins</p>']]]);
            $p2 = Pages::create(['slug' => 'zweites', 'title' => 'Zweites', 'status' => 'published', 'parent_id' => $p1, 'sort' => 1],
                [['id' => 'qa2', 'type' => 'richtext', 'data' => ['text' => '<p>Text zwei</p><p><a href="page:' . 0 . '">x</a></p>']]]);
            array_push($pages, $root, $p1, $p2);
            $rootRow = Pages::find($root);
            $plan = KnowledgeImport::plan($rootRow, $h . 'imp');
            $ck($plan['ok'] && count($plan['items']) === 2 && $plan['items'][1]['old'] === '/' . $slug . '/erstes/zweites' && $plan['items'][1]['new'] === '/' . $slug . '/zweites', 'Plan: 2 Kapitel, tiefe Adresse → Weiterleitung');
            $log = KnowledgeImport::apply($plan);
            $ti = Tables::find($h . 'imp');
            $rows = $ti ? Entries::query($ti, ['status' => 'all', 'limit' => 10]) : [];
            $byTitle = array_column($rows, null, 'titel');
            $ck(count($rows) === 2 && (int) ($byTitle['Zweites']['oberkapitel'] ?? 0) === (int) ($byTitle['Erstes']['id'] ?? -1) && ($byTitle['Erstes']['anreisser'] ?? '') === 'Anriss eins', 'Übernahme: Einträge mit Oberkapitel und Anreißer – ' . implode(' | ', $log));
            $moved = Pages::find($p1);
            $ck($moved && $moved['status'] === 'draft' && (int) $moved['parent_id'] !== $root, 'Übernahme: Seiten offline im Archiv');
            $ck(!\Core\Redirects\Redirects::enabled() || \Core\Redirects\Redirects::bySource('/' . $slug . '/erstes/zweites') !== null, 'Übernahme: 301 für die tiefe Adresse');
            $ck(str_contains((string) Pages::find($root)['content_published'], '"table":"' . $h . 'imp"'), 'Übernahme: Datenliste auf der Startseite des Zweigs');
            KnowledgeImport::revert(Pages::find($root));
            Tables::flush();
            $back = Pages::find($p1);
            $ck($back && $back['status'] === 'published' && (int) $back['parent_id'] === $root && !Tables::find($h . 'imp') && !str_contains((string) Pages::find($root)['content_published'], 'data_list')
                && (!\Core\Redirects\Redirects::enabled() || \Core\Redirects\Redirects::bySource('/' . $slug . '/erstes/zweites') === null), 'Zurückdrehen: Seiten, Startseite, Tabelle, Weiterleitung wie vorher');

            // 8. Erlaubte Blöcke, Verweis-Kapitel (--keep), Bild aus dem Seitenkopf, --blocks
            $choices = array_keys(EntryBlocks::choices());
            $extra = array_values(array_diff($choices, EntryBlocks::defaults(), ['hero']))[0] ?? null;   // wählbar, aber nicht im Standard (z. B. Gruppe „Interaktiv“)
            $mid = (int) (Pages::db()->fetchValue('SELECT id FROM media WHERE deleted_at IS NULL ORDER BY id LIMIT 1') ?: 0);
            $slug2 = 'qa-knref-' . bin2hex(random_bytes(2));
            $r2 = Pages::create(['slug' => $slug2, 'title' => 'QA Verweise', 'status' => 'published']);
            $pl = Pages::create(['slug' => 'liste', 'title' => 'Liste', 'status' => 'published', 'parent_id' => $r2, 'sort' => 1],
                [['id' => 'qa3', 'type' => 'richtext', 'data' => ['text' => '<p>Vor der Liste</p>']], ['id' => 'qa4', 'type' => 'data_list', 'data' => ['table' => $h]], ['id' => 'qa8', 'type' => 'data_form', 'data' => ['table' => $h]]]);
            $pt = Pages::create(['slug' => 'tief', 'title' => 'Tief', 'status' => 'published', 'parent_id' => $pl, 'sort' => 1],
                [['id' => 'qa5', 'type' => 'richtext', 'data' => ['text' => '<p>Tief</p>']]]);
            $px = Pages::create(['slug' => 'extra', 'title' => 'Extra', 'status' => 'published', 'parent_id' => $r2, 'sort' => 2],
                array_values(array_filter([['id' => 'qa6', 'type' => 'hero', 'data' => ['title' => 'Extra', 'text' => 'Kopftext', 'image' => $mid ?: null]],
                    $extra ? ['id' => 'qa7', 'type' => $extra, 'data' => []] : null])));
            array_push($pages, $r2, $pl, $pt, $px);
            $h2 = $h . 'ref';
            $plain = KnowledgeImport::plan(Pages::find($r2), $h2);
            $ck($plain['ok'] && $plain['suggest'] === ['liste'] && $plain['items'][0]['dropped'] === ['data_form'] && in_array('data_list', $plain['allowed'], true)
                && !in_array('data_form', $plain['allowed'], true) && !in_array('hero', $plain['allowed'], true), 'Plan: Datenliste wird übernommen, data_form nicht übertragbar (Vorschlag --keep=liste), Seitenkopf nie');
            $ck($extra === null || ($plain['unlock'] === [$extra] && in_array($extra, $plain['allowed'], true)), 'Plan: wählbare Blöcke der Seiten werden im Feld „Inhalt“ freigeschaltet');
            $bad = KnowledgeImport::plan(Pages::find($r2), $h2, ['keep' => ['gibtsnicht'], 'blocks' => ['data_form']]);
            $ck(!$bad['ok'] && count($bad['errors']) === 2, 'Plan: unbekanntes --keep und nicht wählbares --blocks werden abgewiesen');
            $plan2 = KnowledgeImport::plan(Pages::find($r2), $h2, ['keep' => ['liste', (string) $pt], 'blocks' => ['richtext']]);
            $ck($plan2['ok'] && $plan2['suggest'] === [] && $plan2['allowed'] === array_values(array_filter(['richtext', $extra])), 'Plan: --keep übernimmt den Vorschlag, --blocks legt die Auswahl beim Anlegen fest');
            KnowledgeImport::apply($plan2);
            $tr = Tables::find($h2);
            $rr = $tr ? array_column(Entries::query($tr, ['status' => 'all', 'limit' => 10]), null, 'titel') : [];
            $ck(($rr['Liste']['verweis'] ?? '') === 'page:' . $pl && ($rr['Tief']['verweis'] ?? '') === 'page:' . $pt && (int) ($rr['Tief']['oberkapitel'] ?? 0) === (int) ($rr['Liste']['id'] ?? -1)
                && EntryBlocks::items($rr['Liste']['inhalt'] ?? null) === [], 'Verweis-Kapitel: Feld „Verweis auf Seite“, Oberkapitel, kein Inhalt');
            $keptPage = Pages::find($pl);
            $ck($keptPage && $keptPage['status'] === 'published' && (int) $keptPage['parent_id'] === $r2 && (int) Pages::find($pt)['parent_id'] === $pl, 'Verweis-Kapitel: Seiten bleiben, wo sie sind');
            Knowledge::flush();
            $tree2 = $tr ? Knowledge::tree($tr) : ['nodes' => []];
            $nodeT = $tree2['nodes'][(int) ($rr['Tief']['id'] ?? 0)] ?? null;
            $ck($nodeT && $nodeT['ref'] && $nodeT['href'] === Pages::url(Pages::find($pt)) && $nodeT['number'] === '1.1', 'Verweis-Kapitel: im Kapitelbaum mit Nummer, Link auf die Seite');
            $ck(isset($rr['Extra']) && ($extra === null || in_array($extra, array_column(EntryBlocks::items($rr['Extra']['inhalt']), 'type'), true))
                && !in_array('hero', array_column(EntryBlocks::items($rr['Extra']['inhalt'] ?? null), 'type'), true) && ($rr['Extra']['anreisser'] ?? '') === 'Kopftext'
                && (!$mid || (int) ($rr['Extra']['bild'] ?? 0) === $mid), 'Seitenkopf: Bild → Feld „Bild“, Text → Anreißer, freigeschalteter Block übernommen');
            // JSON-LD der Zielseite eines Verweises: isPartOf auch die Sammlung
            $gT = \Core\StructuredData::graph(Pages::find($pt), null, null, []);
            $wpT = array_values(array_filter($gT['@graph'], fn($n) => ($n['@type'] ?? '') === 'WebPage'))[0] ?? [];
            $ck($tr && in_array(KnowledgeLd::collectionId($tr), array_column((array) ($wpT['isPartOf'] ?? []), '@id'), true) && KnowledgeLd::check($gT) === [], 'JSON-LD Zielseite eines Verweises: isPartOf Sammlung');
            // Kapitel-Adresse eines Verweises (/zweig/tief) → 301 auf die Seite (/zweig/liste/tief)
            $res = (new \Core\Http\Controllers\SiteController())->page(new \Core\Http\Request('GET', '/' . $slug2 . '/tief', [], [], [], []), $slug2 . '/tief');
            $ck($res->status === 301 && str_ends_with((string) ($res->headers['Location'] ?? ''), '/' . $slug2 . '/liste/tief'), 'Verweis-Kapitel: Adresse leitet per 301 auf die Seite');
            KnowledgeImport::revert(Pages::find($r2));
            Tables::flush();
            $ck(!Tables::find($h2) && Pages::find($px)['status'] === 'published' && (int) Pages::find($px)['parent_id'] === $r2, 'Zurückdrehen mit Verweisen');
        } catch (\Throwable $e) {
            $ck(false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        } finally {
            app()->request = $prevReq;
            app()->entry = $prevEntry;
            foreach ([...$tables, $h . 'imp', $h . 'ref', $h] as $x) if ($tt = Tables::find($x)) Tables::delete($tt);
            foreach (array_reverse($pages) as $pid) Pages::db()->query('DELETE FROM pages WHERE id = ?', [$pid]);
            $st = KnowledgeImport::state();
            foreach ($pages as $pid) unset($st[(string) $pid]);
            app()->settings->set(KnowledgeImport::SET, $st);
            Pages::rebuildPaths();
            Tables::flush();
            Knowledge::flush();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
