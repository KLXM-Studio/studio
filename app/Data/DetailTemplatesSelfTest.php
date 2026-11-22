<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Blocks\EntryHero;
use Core\Http\Controllers\Admin\DataController;
use Core\Pages;

/**
 * Selbsttest mehrere Detailseiten-Vorlagen und konfigurierbarer Wissensbereich (php bin/console detailtemplates:selftest --site=…):
 * Vorlagen anlegen/kopieren/umbenennen/löschen, Standard, Zuordnung je Feldwert, Eintrag und Oberkapitel (vererbt), Block
 * „Kapitelnavigation“ (Position, Suche, Abschnitte, Kapitelbaum, Brotkrumen, Fuß, kompakt, ausgeblendet), mitgelieferte Vorlagen
 * Magazin und Sachlich, Kapitelnummer im Einstieg (Dachzeile, Plakette, keine, Format) und im Kopf (an/aus), Bestand ohne Block
 * unverändert. Vorübergehende Tabellen und Seiten, räumt alles wieder ab.
 */
final class DetailTemplatesSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $ck = function (bool $c, string $msg) use (&$ok, &$fails): void { if ($c) $ok++; else $fails[] = 'Detailvorlagen – ' . $msg; };
        $theme = app()->theme;
        $prevEntry = app()->entry;
        $prevEditing = app()->editing;
        $tables = [];
        $pages = [];
        $h = 'qa_dt_' . bin2hex(random_bytes(3));
        $kh = $h . 'kn';
        $mk = function (string $preset, string $handle) use (&$tables): ?array {
            $d = DataController::presetDef($preset);
            $d['handle'] = $handle;
            $d['name'] = 'QA ' . $preset;
            $d['settings']['route'] = str_replace('_', '-', $handle);
            [$c, $err] = Tables::validate($d);
            if ($err) return null;
            Tables::create($c);
            Tables::flush();
            $tables[] = $handle;
            return Tables::find($handle);
        };
        try {
            app()->editing = false;
            // 1. Mehrere Vorlagen (Neuigkeiten)
            $t = $mk('blog', $h);
            $ck($t !== null, 'Tabelle angelegt');
            if (!$t) return ['ok' => $ok, 'fails' => $fails];
            $a = DataController::makeTemplate($t);
            $t = Tables::find($h);
            $b = DetailTemplates::create($t, 'Zweite', []);
            $c = (int) DetailTemplates::duplicate($t, $a, 'Kopie');
            $t = Tables::find($h);
            $ck(DetailTemplates::defaultId($t) === $a && count(DetailTemplates::all($t)) === 3 && (int) DetailTemplates::all($t)[0]['id'] === $a, 'Drei Vorlagen, die erste ist Standard');
            $ca = array_column(Pages::blocks(Pages::find($a), true), 'id');
            $cc = array_column(Pages::blocks(Pages::find($c), true), 'id');
            $ck(count($ca) === count($cc) && !array_intersect($ca, $cc), 'Kopie mit neuen Block-IDs');
            $ck(DetailTemplates::rename($t, $b, 'Sonderseite') && Pages::find($b)['title'] === 'Sonderseite', 'Umbenennen');
            $ck(!DetailTemplates::delete($t, $a), 'Standard-Vorlage nicht löschbar');
            $kat = (string) array_key_first((array) (Tables::field($t, 'kategorie')['options'] ?? []));
            [$e1] = Entries::save($t, null, ['titel' => 'Eins', 'kategorie' => $kat, 'status' => 'published']);
            [$e2] = Entries::save($t, null, ['titel' => 'Zwei', 'status' => 'published']);
            [$e3] = Entries::save($t, null, ['titel' => 'Drei', 'kategorie' => $kat, 'status' => 'published']);
            $E = fn($id) => Entries::find($t, (int) $id);
            $ck(DetailTemplates::resolveId($t, $E($e1)) === $a, 'Ohne Regeln: Standard');
            DetailTemplates::saveRules($t, [['field' => 'kategorie', 'value' => $kat, 'page' => $b], ['entry' => (string) $e3, 'page' => $c],
                ['field' => 'gibt_es_nicht', 'value' => 'x', 'page' => $b], ['entry' => 5, 'page' => 999999]]);
            $ck(count(DetailTemplates::rules($t)) === 2, 'Ungültige Regeln fallen weg');
            $ck(DetailTemplates::resolveId($t, $E($e1)) === $b && DetailTemplates::resolveId($t, $E($e2)) === $a && DetailTemplates::resolveId($t, $E($e3)) === $c,
                'Eintrag vor Feldwert vor Standard');
            $ck((int) (DetailTemplates::resolve($t, $E($e3))['id'] ?? 0) === $c && (DetailTemplates::usage($t)[$b] ?? 0) === 1, 'Vorlagen-Seite und Nutzung');
            $ck(DetailTemplates::setDefault($t, $b) && DetailTemplates::defaultId(Tables::find($h)) === $b, 'Standard umschalten');
            $t = Tables::find($h);
            DetailTemplates::setDefault($t, $a);
            $t = Tables::find($h);
            $ck(DetailTemplates::delete($t, $c) && !Pages::find($c) && count(DetailTemplates::rules($t)) === 1, 'Löschen entfernt auch die Regeln auf die Vorlage');

            // 2. Wissen: Magazin (Standard), Oberkapitel vererbt, Kapitelnavigation
            $kt = $mk('knowledge', $kh);
            $ck($kt !== null, 'Wissen angelegt');
            if (!$kt) return ['ok' => $ok, 'fails' => $fails];
            $mag = DataController::makeTemplate($kt);
            if ($lp = Knowledge::makeListPage(Tables::find($kh))) $pages[] = $lp;   // Übersicht: Ziel der Suche
            $kt = Tables::find($kh);
            $mb = Pages::blocks(Pages::find($mag), true);
            $types = array_column($mb, 'type');
            $hasHero = EntryHero::supports($theme->block('hero'));
            $ck($hasHero ? $types === ['hero', Knowledge::NAV, 'entry_blocks'] : $types[0] === Knowledge::NAV, 'Standard für neue Wissensbereiche: Magazin (Einstieg, Kapitelnavigation, Inhalt)');
            $sach = Knowledge::makeTemplate($kt, 'sachlich');
            $kt = Tables::find($kh);
            $sb = Pages::blocks(Pages::find($sach), true);
            $ck(array_column($sb, 'type') === [Knowledge::NAV, 'data_fields', 'data_fields', 'entry_blocks'] && !empty($sb[0]['data']['compact']), 'Sachlich: Navigation zuerst, kompakt, Kopf mit Nummer');
            $ck(DetailTemplates::defaultId($kt) === $mag, 'Zweite mitgelieferte Vorlage ändert den Standard nicht');
            $blocks = fn(string $s) => [['id' => bin2hex(random_bytes(5)), 'type' => 'richtext', 'data' => ['text' => "<h2>Teil A</h2><p>{$s}</p><h2>Teil B</h2><p>x</p>"]]];
            [$k1] = Entries::save($kt, null, ['titel' => 'Satzung', 'anreisser' => 'Worum es geht.', 'inhalt' => $blocks('a'), 'status' => 'published']);
            [$k2] = Entries::save($kt, null, ['titel' => 'Paragraf eins', 'oberkapitel' => $k1, 'inhalt' => $blocks('b'), 'status' => 'published']);
            [$k3] = Entries::save($kt, null, ['titel' => 'Anderes', 'inhalt' => $blocks('c'), 'status' => 'published']);
            Knowledge::flush();
            DetailTemplates::saveRules($kt, [['entry' => $k1, 'page' => $sach]]);
            $K = fn($id) => Entries::find($kt, (int) $id);
            $ck(DetailTemplates::resolveId($kt, $K($k2)) === $sach && DetailTemplates::resolveId($kt, $K($k3)) === $mag, 'Oberkapitel vererbt die Vorlage an Unterkapitel');
            $ck(Knowledge::listBlock($kt, 'magazin')['chapters_style'] === 'cards' && !isset(Knowledge::listBlock($kt, 'sachlich')['chapters_style']), 'Übersicht: Karten (Magazin) bzw. Inhaltsverzeichnis (Sachlich)');

            $render = function (array $bl, int $id) use ($theme, $kt, $K): string {
                app()->entry = ['table' => $kt, 'entry' => $K($id)];
                EntryHero::reset();
                return Knowledge::render($theme, $bl, $kt, $K($id));
            };
            $num = (string) (Knowledge::tree($kt)['nodes'][(int) $k3]['number'] ?? '');
            $out = $render($mb, $k3);
            if ($hasHero) {
                $ck(strpos($out, '<h1') < strpos($out, 'kn--block') && str_contains($out, 'kn--headed') && str_contains($out, lt('Kapitel {n}', ['n' => $num])) && !str_contains($out, 'kn-num--badge'),
                    'Magazin: Einstieg über der Navigation, Nummer als Dachzeile');
            }
            $ck(str_contains($out, 'role="search"') && str_contains($out, 'kn-crumbs') && str_contains($out, 'kn-nav__list') && str_contains($out, 'data-kn-drawer') && substr_count($out, '<h1') === 1,
                'Kapitelnavigation: Suche, Brotkrumen, Kapitelbaum, Schublade, eine H1');
            $nav = fn(array $o) => array_map(fn($b) => $b['type'] === Knowledge::NAV ? array_replace_recursive($b, ['data' => $o]) : $b, $mb);
            $r = $render($nav(['position' => 'right']), $k3);
            $ck(str_contains($r, 'kn--right'), 'Position rechts');
            $r = $render($nav(['position' => 'top']), $k3);
            $ck(str_contains($r, 'kn--top') && str_contains($r, '<details class="kn-menu"') && !str_contains($r, 'data-kn-drawer') && !str_contains($r, 'data-kn-toggle'), 'Position oben als Menü (ohne Schublade)');
            $r = $render($nav(['search' => false, 'crumbs' => false, 'footer' => false]), $k3);
            $ck(!str_contains($r, 'role="search"') && !str_contains($r, 'kn-crumbs') && !str_contains($r, 'class="kn-foot'), 'Suche, Brotkrumen, Fuß abschaltbar');
            $r = $render($nav(['tree' => false]), $k3);
            $ck(!str_contains($r, 'kn-nav__list') && str_contains($r, 'kn-secs--nav'), 'Ohne Kapitelbaum: nur die Abschnitte');
            $r = $render($nav(['tree' => false, 'sections' => false, 'search' => false]), $k3);
            $ck(str_contains($r, 'kn--noside') && !str_contains($r, 'kn-side'), 'Alles aus: keine Seitenleiste');
            $r = $render($nav(['compact' => true]), $k3);
            $ck(str_contains($r, 'kn--compact'), 'Kompakt');
            $hid = array_map(fn($b) => $b['type'] === Knowledge::NAV ? array_replace_recursive($b, ['tunes' => ['section' => ['visible' => false]]]) : $b, $mb);
            $r = $render($hid, $k3);
            $ck(!str_contains($r, ' kn kn--') && str_contains($r, 'Teil A'), 'Block ausgeblendet: keine Navigation, Inhalt bleibt');

            // 3. Sachlich: große Nummer vor dem Titel, abschaltbar
            $n1 = (string) (Knowledge::tree($kt)['nodes'][(int) $k1]['number'] ?? '');
            $r = $render($sb, $k1);
            $ck(str_contains($r, '<span class="kn-num kn-num--h1">' . $n1 . '</span>') && strpos($r, 'kn-side') < strpos($r, '<h1') && substr_count($r, 'kn-num--h1') === 1,
                'Sachlich: Navigation von Anfang an, große Nummer einmal vor dem Titel');
            $sb2 = $sb;
            $sb2[1]['data']['kn_number'] = false;
            $r = $render($sb2, $k1);
            $ck(!str_contains($r, 'kn-num--h1') && str_contains($r, 'data-kn-nonum'), 'Kopf: Nummer abschaltbar');
            // Bestand: Vorlage ohne Block → Rahmen wie bisher, Nummer vor der H1 (genau einmal)
            $old = Knowledge::templateBlocks($kt, 'klassisch');
            $r = $render($old, $k1);
            $ck(str_contains($r, 'kn--classic') && !str_contains($r, 'kn--block') && substr_count($r, 'kn-num--h1') === 1, 'Ohne Kapitelnavigation: Rahmen wie bisher');
            $old[0]['data']['kn_number'] = false;
            $r = $render($old, $k1);
            $ck(!str_contains($r, 'kn-num--h1'), 'Ohne Kapitelnavigation: Nummer im Kopf abschaltbar (Rahmen setzt keine)');

            // 4. Einstieg: Kapitelnummer als Plakette, keine, eigenes Format
            if ($hasHero) {
                $hero = fn(array $o) => array_map(fn($b) => $b['type'] === 'hero' ? array_replace($b, ['data' => $o + $b['data']]) : $b, $mb);
                $r = $render($hero(['kn_number' => 'badge']), $k3);
                $ck(str_contains($r, 'kn-num--badge') && !str_contains($r, lt('Kapitel {n}', ['n' => $num])), 'Einstieg: Nummer als Plakette statt Dachzeile');
                $r = $render($hero(['kn_number' => 'none']), $k3);
                $ck(!str_contains($r, 'kn-num--badge') && !str_contains($r, lt('Kapitel {n}', ['n' => $num])), 'Einstieg: keine Nummer');
                $r = $render($hero(['kn_format' => 'Abschnitt {nummer}']), $k3);
                $ck(str_contains($r, 'Abschnitt ' . $num), 'Einstieg: Format der Dachzeile');
                $r = $render($hero(['src_eyebrow' => $kh . '.bereich']), $k3);
                $ck(str_contains($r, 'kn-num--badge'), 'Einstieg: Dachzeile mit Feld → Plakette');
            }
        } finally {
            app()->entry = $prevEntry;
            app()->editing = $prevEditing;
            EntryHero::reset();
            foreach ($tables as $x) if ($tt = Tables::find($x)) Tables::delete($tt);
            foreach ($pages as $pid) Pages::db()->query('DELETE FROM pages WHERE id = ?', [$pid]);
            Pages::rebuildPaths();
            Tables::flush();
            Knowledge::flush();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
