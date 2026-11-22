<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Http\Controllers\Admin\DataController;

/**
 * Selbsttest „Auf der Startseite hervorheben“ und Block „Highlights“ (php bin/console highlights:selftest --site=…): Schalter je Tabelle
 * (Standard aus, Bestand byte-gleich), Spalten, Speichern mit Reihenfolge und „bis“-Datum, nur gerade hervorgehobene und veröffentlichte
 * Einträge, Mischung aus mehreren Tabellen mit Anzahl je Tabelle, Sortierung nach Datum bzw. Reihenfolge, Ausgabe als Karten bzw. großer
 * Teaser mit Etikett, Netzwerk-Ansicht behält die Spalten. Vorübergehende Tabellen, räumt alles wieder ab.
 */
final class FeaturedSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $ck = function (bool $c, string $msg) use (&$ok, &$fails): void { if ($c) $ok++; else $fails[] = 'Highlights – ' . $msg; };
        $theme = app()->theme;
        $prevEditing = app()->editing;
        $prevEntry = app()->entry;
        $tables = [];
        $mk = function (string $name, bool $featured) use (&$tables, $ck): ?array {
            $h = 'qa_hl_' . bin2hex(random_bytes(3));
            $pd = DataController::presetDef('blog');
            $pd['handle'] = $h;
            $pd['name'] = $name;
            $pd['settings']['route'] = str_replace('_', '-', $h);
            $pd['settings']['detail_page_id'] = '987654321';   // Detailseite nur für die Adressen (Entries::url) – keine echte Vorlage nötig
            if ($featured) $pd['settings']['featured'] = '1';
            [$clean, $err] = Tables::validate($pd);
            $ck($err === [], "Tabelle „{$name}“ gültig: " . json_encode($err, JSON_UNESCAPED_UNICODE));
            if ($err) return null;
            Tables::create($clean);
            Tables::flush();
            $tables[] = $h;
            return Tables::find($h);
        };
        try {
            app()->editing = false;
            app()->entry = null;
            // 1. Standard aus: Einstellung fehlt ganz (Bestand byte-gleich), keine Spalten, nichts hervorgehoben
            $plain = $mk('QA Ohne', false);
            if (!$plain) return ['ok' => $ok, 'fails' => $fails];
            $ck(!array_key_exists('featured', $plain['settings']) && !Featured::enabled($plain), 'Standard: Schalter aus, Einstellung nicht gespeichert');
            $ck(!in_array(Featured::COL, Tables::db($plain)->columns($plain['table']), true), 'Ohne Schalter keine Spalten');
            [$again] = Tables::validate(Tables::toInput($plain), $plain);
            $ck(!array_key_exists('featured', $again['settings']), 'Erneut speichern ohne Schalter: unverändert');
            [$pid] = Entries::save($plain, null, ['titel' => 'Nicht hervorhebbar', Featured::COL => '1', 'status' => 'published']);
            $ck(Featured::items([['table' => $plain['handle']]]) === [], 'Tabelle ohne Schalter liefert keine Highlights');

            // 2. Schalter an: Spalten, Einstellung bleibt beim erneuten Speichern
            $a = $mk('QA Neuigkeiten', true);
            $b = $mk('QA Projekte', true);
            if (!$a || !$b) return ['ok' => $ok, 'fails' => $fails];
            $ck(Featured::enabled($a) && ($a['settings']['featured'] ?? null) === true, 'Schalter an gespeichert');
            $ck(count(array_intersect(Featured::COLUMNS, Tables::db($a)->columns($a['table']))) === 3, 'Spalten angelegt (Tables::sync)');
            $in = Tables::toInput($a);
            unset($in['settings']['featured']);
            [$kept] = Tables::validate($in, $a);
            $ck(($kept['settings']['featured'] ?? null) === true, 'Ohne Angabe im Formular bleibt der Schalter an');
            [$off] = Tables::validate(['settings' => ['featured' => '0'] + Tables::toInput($a)['settings']] + Tables::toInput($a), $a);
            $ck(($off['settings']['featured'] ?? null) === false, 'Ausschalten wird gespeichert');
            $noRoute = Tables::toInput($a);
            $noRoute['settings']['route'] = '';
            [$nr] = Tables::validate($noRoute, $a);
            $ck(!Featured::enabled($nr), 'Ohne Detailseiten nicht hervorhebbar');

            // 3. Speichern: Reihenfolge, „bis“, Fehler
            $today = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $future = date('Y-m-d', strtotime('+30 days'));
            $save = function (array $t, array $v): int { [$id, $e] = Entries::save($t, null, $v + ['status' => 'published']); return $e ? 0 : (int) $id; };
            $a1 = $save($a, ['titel' => 'Musterfest', 'teaser' => 'Ein Tag für alle.', Featured::COL => '1']);
            $a2 = $save($a, ['titel' => 'Abgelaufen', Featured::COL => '1', Featured::UNTIL => $yesterday]);
            $a3 = $save($a, ['titel' => 'Endet heute', Featured::COL => '1', Featured::UNTIL => $today, Featured::ORDER => '2']);
            $a4 = $save($a, ['titel' => 'Nicht hervorgehoben', Featured::COL => '']);
            $a5 = $save($a, ['titel' => 'Entwurf', Featured::COL => '1', 'status' => 'draft']);
            $ck($a1 && $a2 && $a3 && $a4 && $a5, 'Einträge gespeichert');
            $e3 = Entries::find($a, $a3);
            $ck((int) $e3[Featured::COL] === 1 && (int) $e3[Featured::ORDER] === 2 && $e3[Featured::UNTIL] === $today, 'Reihenfolge und „bis“ gespeichert');
            [, $err] = Entries::save($a, $a1, [Featured::COL => '1', Featured::UNTIL => 'morgen']);
            $ck(isset($err[Featured::UNTIL]), 'Ungültiges Datum wird abgelehnt');
            [, $err] = Entries::save($a, $a1, [Featured::COL => '1', Featured::ORDER => 'zwei']);
            $ck(isset($err[Featured::ORDER]), 'Ungültige Reihenfolge wird abgelehnt');
            Entries::save($a, $a1, ['titel' => 'Musterfest im Park']);
            $ck(Featured::active((array) Entries::find($a, $a1)), 'Speichern ohne Angaben lässt die Hervorhebung stehen');
            $ck(Featured::active(['_featured' => 1, '_featured_until' => $today], $today) && !Featured::active(['_featured' => 1, '_featured_until' => $yesterday], $today), '„bis“ gilt einschließlich');
            $ck(Featured::label(['_featured' => 1, '_featured_until' => $future]) !== '' && Featured::label(['_featured' => 0]) === '', 'Kurzbeschreibung für die Verwaltung');

            // 4. Nur gerade hervorgehobene, veröffentlichte Einträge
            $ids = fn(array $items) => array_map(fn($i) => (int) $i['entry']['id'], $items);
            $only = $ids(Featured::items([['table' => $a['handle'], 'limit' => 12]]));
            sort($only);
            $ck($only === [$a1, $a3], 'Nur hervorgehoben, nicht abgelaufen, nicht Entwurf: ' . json_encode($only));
            $ck(count(Entries::query($a, ['featured' => 'date'])) === 2 && count(Entries::query($a)) === 4, 'Abfrage-Option featured, ohne sie alle');

            // 5. Mischung aus zwei Tabellen, Anzahl je Tabelle, Sortierung
            $db = Tables::db($b);
            $b1 = $save($b, ['titel' => 'Projekt eins', Featured::COL => '1', Featured::ORDER => '1']);
            $b2 = $save($b, ['titel' => 'Projekt zwei', Featured::COL => '1']);
            $b3 = $save($b, ['titel' => 'Projekt drei', Featured::COL => '1', Featured::ORDER => '3']);
            // feste Veröffentlichungszeiten für eine eindeutige Datumsfolge
            $stamp = ['a' => [$a1 => '2026-01-05 10:00:00', $a3 => '2026-01-03 10:00:00'], 'b' => [$b1 => '2026-01-01 10:00:00', $b2 => '2026-01-06 10:00:00', $b3 => '2026-01-04 10:00:00']];
            foreach ($stamp['a'] as $id => $ts) Tables::db($a)->update($a['table'], ['published_at' => $ts], 'id = :id', ['id' => $id]);
            foreach ($stamp['b'] as $id => $ts) $db->update($b['table'], ['published_at' => $ts], 'id = :id', ['id' => $id]);
            $src = [['table' => $a['handle'], 'limit' => '6'], ['table' => $b['handle'], 'limit' => '6', 'badge' => 'Unsere Projekte']];
            $byDate = Featured::items($src, ['sort' => 'date']);
            $ck($ids($byDate) === [$b2, $a1, $b3, $a3, $b1], 'Neueste zuerst über beide Tabellen: ' . json_encode($ids($byDate)));
            $byOrder = Featured::items($src, ['sort' => 'order']);
            $ck($ids($byOrder) === [$b1, $a3, $b3, $b2, $a1], 'Reihenfolge, ohne Zahl ans Ende (dann neueste): ' . json_encode($ids($byOrder)));
            $ck($byDate[0]['badge'] === 'Unsere Projekte' && $byDate[1]['badge'] === 'QA Neuigkeiten', 'Etikett: eigene Beschriftung bzw. Name der Tabelle');
            $lim = Featured::items([['table' => $a['handle'], 'limit' => '1'], ['table' => $b['handle'], 'limit' => '2']], ['sort' => 'date']);
            $ck(count(array_filter($lim, fn($i) => $i['table']['handle'] === $a['handle'])) === 1 && count($lim) === 3, 'Anzahl je Tabelle');
            $ck(count(Featured::items($src, ['limit' => 2])) === 2, 'Anzahl gesamt');
            $ck(count(Featured::items([...$src, ['table' => $a['handle']]])) === 5, 'Tabelle doppelt gewählt zählt einmal');
            $exKeys = array_map(fn($i) => $i['table']['handle'] . ':' . $i['entry']['id'], Featured::items($src, ['exclude' => [$a['handle'] => $a1]]));
            $ck(!in_array($a['handle'] . ':' . $a1, $exKeys, true) && count($exKeys) === 4, 'Aufgerufener Eintrag wird ausgelassen');
            $ck(isset(Featured::tables()[$a['handle']]) && !isset(Featured::tables()[$plain['handle']]), 'Auswahl: nur Tabellen mit Schalter');

            // 6. Ausgabe: Karten wie die Datenliste, Etikett, großer Teaser
            $blk = fn(array $data) => $theme->makeBlock(['id' => 'qahl' . bin2hex(random_bytes(3)), 'type' => 'highlights', 'data' => $data]);
            $def = $theme->block('highlights');
            $ck($def !== null, 'Block „Highlights“ im Kit');
            if ($def) {
                $html = $theme->renderBlock($blk(['sources' => $src, 'title' => 'Aktuell', 'limit' => 4]));
                $ck(str_contains($html, 'dl dl--cards dl--highlights') && substr_count($html, 'class="dl-item hl-item') === 4, 'Karten wie Datenliste (4 Stück)');
                $ck(str_contains($html, 'hl-badge') && str_contains($html, 'Unsere Projekte') && str_contains($html, '<h3 class="dl-name'), 'Etikett und Titel h3 unter der Überschrift');
                $ck(str_contains($html, 'href="' . e((string) Entries::href($b, (array) Entries::find($b, $b2))) . '"'), 'Link zur Detailseite');
                $ck(str_contains($html, 'Ein Tag für alle.'), 'Kurzbeschreibung aus dem Beschreibungsfeld');
                $tea = $theme->renderBlock($blk(['sources' => $src, 'layout' => 'teaser', 'title' => '']));
                $ck(substr_count($tea, 'hl-item hl-lead"') === 1 && str_contains($tea, 'hl--teaser') && str_contains($tea, '<h2 class="dl-name'), 'Großer Teaser: erster groß, Titel h2 ohne Überschrift');
                $ck($theme->renderBlock($blk(['sources' => [['table' => $plain['handle']]]])) === '', 'Nichts hervorgehoben: Abschnitt entfällt');
                $ck(str_contains($theme->renderBlock($blk(['sources' => [['table' => $plain['handle']]], 'hide_empty' => false])), 'dl-empty'), 'Nichts hervorgehoben: Hinweistext, wenn gewünscht');
                $css = implode(' ', $theme->conditionalCss(['highlights']));
                $ck(str_contains($css, 'highlights.css') && str_contains($css, 'data.css'), 'Stylesheets: Datenliste + highlights.css');
                $ck(!str_contains(implode(' ', $theme->conditionalCss(['richtext'])), 'highlights.css'), 'Ohne Block kein highlights.css');
            }

            // 7. Netzwerk: fremde Einträge behalten die Spalten (Network::foreignView)
            $fv = Network::foreignView($a, (array) Entries::find($a, $a3));
            $ck((int) ($fv[Featured::COL] ?? 0) === 1 && ($fv[Featured::UNTIL] ?? null) === $today, 'Netzwerk-Ansicht behält Hervorhebung');
        } finally {
            app()->editing = $prevEditing;
            app()->entry = $prevEntry;
            foreach ($tables as $x) if ($tt = Tables::find($x)) Tables::delete($tt);
            Tables::flush();
            Featured::reset();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
