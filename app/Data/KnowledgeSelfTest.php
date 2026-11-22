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
                [['id' => 'qa3', 'type' => 'richtext', 'data' => ['text' => '<p>Vor der Liste</p>']], ['id' => 'qa4', 'type' => 'data_list', 'data' => ['table' => $h]]]);
            $pt = Pages::create(['slug' => 'tief', 'title' => 'Tief', 'status' => 'published', 'parent_id' => $pl, 'sort' => 1],
                [['id' => 'qa5', 'type' => 'richtext', 'data' => ['text' => '<p>Tief</p>']]]);
            $px = Pages::create(['slug' => 'extra', 'title' => 'Extra', 'status' => 'published', 'parent_id' => $r2, 'sort' => 2],
                array_values(array_filter([['id' => 'qa6', 'type' => 'hero', 'data' => ['title' => 'Extra', 'text' => 'Kopftext', 'image' => $mid ?: null]],
                    $extra ? ['id' => 'qa7', 'type' => $extra, 'data' => []] : null])));
            array_push($pages, $r2, $pl, $pt, $px);
            $h2 = $h . 'ref';
            $plain = KnowledgeImport::plan(Pages::find($r2), $h2);
            $ck($plain['ok'] && $plain['suggest'] === ['liste'] && in_array('data_list', $plain['items'][0]['dropped'], true) && !in_array('data_list', $plain['allowed'], true)
                && !in_array('hero', $plain['allowed'], true), 'Plan: data_list nicht übertragbar, Vorschlag --keep=liste, Daten-Blöcke und Seitenkopf nie erlaubt');
            $ck($extra === null || ($plain['unlock'] === [$extra] && in_array($extra, $plain['allowed'], true)), 'Plan: wählbare Blöcke der Seiten werden im Feld „Inhalt“ freigeschaltet');
            $bad = KnowledgeImport::plan(Pages::find($r2), $h2, ['keep' => ['gibtsnicht'], 'blocks' => ['data_list']]);
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
            foreach ([$h . 'imp', $h] as $x) if ($tt = Tables::find($x)) Tables::delete($tt);
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
