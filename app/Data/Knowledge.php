<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Glossary\Glossary;
use Core\Pages;
use Core\Search\Text;

/**
 * Wissensbereich / Handbuch (Tabellenart „knowledge“, Core\Data\Kinds::KNOWLEDGE): Kapitel als Einträge einer Datentabelle.
 *
 *  - Gliederung: Bereich (Auswahl, Reihenfolge der Optionen) → Kapitel → Unterkapitel (Feld oberkapitel, Verknüpfung auf die eigene
 *    Tabelle); Geschwister nach sort, dann Titel. Nummern automatisch („2.1.3“), je Eintrag überschreibbar (kapitelnummer).
 *  - Ansicht: frame() legt um den Inhalt der Detailseiten-Vorlage (SiteController::render, nur Besucher und Entwurfsansicht, nie
 *    beim Bearbeiten) Seitenleiste mit Suche und Kapiteln, „In diesem Kapitel“ (h2 des Inhalts), Schlagworte, Begriffe, verwandte
 *    Kapitel und Vor/Zurück. Layout-Modi (settings.knowledge.layout): classic, compact, focus (Schublade statt Seitenleiste),
 *    handbook (Seitenleiste nur Kapitel, Abschnitte rechts ab 1200 px), handbook_menu (Abschnitte immer als aufklappbares Menü).
 *    Stile resources/css/knowledge.css (Kit-Vertrag --kit-*, ein Kit ersetzt sie mit css/knowledge.css), Skript js/knowledge.js.
 *  - Übersicht/Inhaltsverzeichnis/Suche: Block „Datenliste“ (Darstellung „Kapitel“, Suche mit Trefferauszug, Glossar-Treffer,
 *    Mehrfach-Filter) – Core\Blocks\ListFilter wie überall.
 *  - Glossar: bleibt websiteweit (Core\Glossary, Tabelle glossar) – Wissenseinträge verweisen nur darauf (Feld begriffe).
 */
final class Knowledge
{
    public const LAYOUTS = ['classic' => 'Klassisch', 'compact' => 'Kompakt', 'focus' => 'Fokus', 'handbook' => 'Handbuch', 'handbook_menu' => 'Handbuch kompakt'];
    public const DEFAULTS = ['layout' => 'classic', 'numbers' => true, 'pager' => true, 'related' => true];
    /** Formularfelder für neue Bereiche/Schlagworte im Eintrag (Entries::formSchema, DataController::entrySave) */
    public const NEW_PREFIX = '_neu_';
    /** Höchstzahl der Kapitel im Baum (je Tabelle) */
    public const MAX = 2000;

    private static array $trees = [];
    /** Wurde in dieser Anfrage ein Kapitel gerahmt? (Stile auch im Modus „Bearbeiten“, Theme::conditionalCss) */
    public static bool $framed = false;
    /** Abschnitte des zuletzt gerahmten Kapitels (frame) – für JSON-LD hasPart (KnowledgeLd::entry) */
    public static array $sections = [];

    public static function is(?array $t): bool
    {
        return $t !== null && Kinds::isKnowledge($t);
    }

    public static function validateSettings(array $in): array
    {
        $bool = fn(string $k) => array_key_exists($k, $in) ? !empty($in[$k]) : self::DEFAULTS[$k];   // '0' gilt in PHP als leer
        $style = isset($in['style']) && isset(self::STYLES[(string) $in['style']]) ? ['style' => (string) $in['style']] : [];   // nur gesetzt (Bestand ohne)
        return ['layout' => isset(self::LAYOUTS[(string) ($in['layout'] ?? '')]) ? (string) $in['layout'] : self::DEFAULTS['layout'],
            'numbers' => $bool('numbers'), 'pager' => $bool('pager'), 'related' => $bool('related')] + $style;
    }

    /** @return array{layout: string, numbers: bool, pager: bool, related: bool} */
    public static function config(array $t): array
    {
        return self::validateSettings((array) ($t['settings']['knowledge'] ?? []));
    }

    public static function flush(): void
    {
        self::$trees = [];
    }

    // ================================================================= Vorlage, Detailseite, Übersicht

    /** Erlaubte Blöcke im Feld „Inhalt“ neuer Wissenstabellen: Standard (Inhalt, Medien) und die Datenliste */
    public static function contentBlocks(): array
    {
        return array_values(array_unique([...EntryBlocks::defaults(), ...array_intersect(EntryBlocks::DATA_OK, array_keys(EntryBlocks::choices()))]));
    }

    /** Vorlage „Wissen“ für Assistent und data:template knowledge (DataController::presets) */
    public static function preset(): array
    {
        return ['name' => 'Wissen', 'singular' => 'Kapitel', 'icon' => 'book-open', 'route' => 'wissen', 'sort' => ['sort', 'asc'],
            'kind' => Kinds::KNOWLEDGE, 'schema_type' => 'Article', 'fields' => Kinds::presetFields(Kinds::KNOWLEDGE),
            'title' => 'titel', 'image' => 'bild', 'desc' => 'anreisser', 'knowledge' => self::DEFAULTS];
    }

    /**
     * Grundschema auf den aktuellen Stand bringen (Version 2: Felder „Bild“ und „Verweis auf Seite“) – nur additiv: neue, leere Spalten,
     * vorhandene Einträge, Felder und Einstellungen bleiben unverändert. Gibt die (ggf. neue) Definition zurück.
     */
    public static function upgrade(array $t): array
    {
        if (!self::is($t) || (int) ($t['settings']['kind_version'] ?? 0) >= Kinds::version(Kinds::KNOWLEDGE) || isset($t['shared']) && !Shared::isOwner($t)) return $t;
        [$clean, $err] = Tables::validate(Tables::toInput($t), $t);
        if ($err) return $t;
        Tables::update($t, $clean);
        Tables::flush();
        return Tables::find($t['handle']) ?? $t;
    }

    /** Verweis-Kapitel (Feld verweis, z. B. page:12): Adresse der Seite, sonst null */
    public static function refUrl(array $t, array $e): ?string
    {
        $v = trim((string) ($e['verweis'] ?? ''));
        if ($v === '' || !Tables::field($t, 'verweis')) return null;
        $u = link_href($v);
        return $u !== '#' && $u !== '' ? $u : null;
    }

    /** Link eines Kapitels in Navigation, Listen und Vor/Zurück: Verweis-Kapitel führen auf ihre Seite */
    public static function href(array $t, array $e): ?string
    {
        return self::refUrl($t, $e) ?? Entries::href($t, $e);
    }

    /**
     * Mitgelieferte Detailseiten-Vorlagen (gleichberechtigt, beim Anlegen wählbar, später über Daten → Detailseiten-Vorlagen umschaltbar):
     *  - magazin:   Einstieg mit Bild und Kapitelnummer als Dachzeile über die volle Breite, Kapitelnavigation darunter, Inhaltsblöcke;
     *               Übersicht als Karten mit Bildern
     *  - sachlich:  Kapitelnavigation von Anfang an (kompakt), Kopf mit großer Nummer vor dem Titel, Anreißer, Inhaltsblöcke, Fuß mit
     *               „Zuletzt geprüft“; Übersicht als Inhaltsverzeichnis – für Satzungen, Ordnungen, Prüfungsordnungen
     *  - klassisch: bisherige Vorlage ohne Block „Kapitelnavigation“ (Rahmen nach den Einstellungen der Tabelle)
     */
    public const STYLES = ['magazin' => 'Magazin', 'sachlich' => 'Sachlich / Handbuch', 'klassisch' => 'Klassisch'];

    /** Gewählter Stil der Tabelle (settings.knowledge.style; '' = nicht gewählt, z. B. bestehende Tabellen) */
    public static function style(array $t): string
    {
        $s = (string) ($t['settings']['knowledge']['style'] ?? '');
        return isset(self::STYLES[$s]) ? $s : '';
    }

    /** Blöcke einer mitgelieferten Vorlage */
    public static function templateBlocks(array $t, string $style): array
    {
        $id = fn() => bin2hex(random_bytes(5));
        $h = $t['handle'];
        $content = ['id' => $id(), 'type' => 'entry_blocks', 'data' => ['table' => $h, 'field' => $h . '.inhalt']];
        $classicHead = ['id' => $id(), 'type' => 'data_fields', 'data' => ['table' => $h, 'layout' => 'head', 'fields' => [$h . '._title', $h . '.bereich', $h . '.bild']],
            'tunes' => ['section' => ['spaceBottom' => 'small']]];
        $lead = ['id' => $id(), 'type' => 'data_fields', 'data' => ['table' => $h, 'layout' => 'prose', 'fields' => [$h . '.anreisser']],
            'tunes' => ['section' => ['spaceTop' => 'none', 'spaceBottom' => 'small']]];
        $nav = fn(bool $compact) => ['id' => $id(), 'type' => self::NAV, 'data' => self::navData($t, $compact)];
        if ($style === 'sachlich') {
            return [$nav(true),
                ['id' => $id(), 'type' => 'data_fields', 'data' => ['table' => $h, 'layout' => 'head', 'fields' => [$h . '._title', $h . '.bereich'], 'kn_number' => true],
                    'tunes' => ['section' => ['spaceBottom' => 'small']]],
                $lead, $content];
        }
        if ($style === 'magazin') {
            $hb = \Core\Blocks\EntryHero::templateBlock($t);
            return $hb ? [$hb, $nav(false), $content] : [$nav(false), $classicHead, $lead, $content];   // Kit ohne passenden Einstieg: Kopf im Rahmen
        }
        return [$classicHead, $lead, $content];
    }

    /**
     * Detailseiten-Vorlage anlegen ($style: magazin (Standard für neue Wissensbereiche), sachlich, klassisch; true = magazin).
     * Die erste Vorlage der Tabelle wird Standard (Core\Data\DetailTemplates::create).
     */
    public static function makeTemplate(array $t, bool|string $style = 'magazin'): int
    {
        $style = is_bool($style) || !isset(self::STYLES[$style]) ? 'magazin' : $style;
        return DetailTemplates::create($t, $t['name'] . ' – ' . __(self::STYLES[$style]), self::templateBlocks($t, $style));
    }

    /** Daten der Datenliste für Übersicht/Inhaltsverzeichnis: Suche mit Auszug, Glossar, Filter Bereich + Schlagworte (mehrfach), Kapitel */
    public static function listBlock(array $t, ?string $style = null): array
    {
        $h = $t['handle'];
        $d = ['title' => '', 'table' => $h, 'fields' => [$h . '._title', $h . '.anreisser'], 'layout' => 'chapters', 'limit' => 0, 'link_detail' => true,
            'visitor_search' => true, 'visitor_filter' => $h . '.bereich', 'visitor_filter2' => $h . '.schlagworte', 'visitor_multi' => true,
            'search_excerpt' => true, 'search_glossary' => true, 'filter_min' => 0];
        // Stil „Magazin“: Kapitel als Karten mit Bild (Nummern bleiben); sonst Inhaltsverzeichnis
        if (($style ?? self::style($t)) === 'magazin') $d = ['fields' => [$h . '._title', $h . '.anreisser', $h . '.bild'], 'chapters_style' => 'cards', 'columns' => '3', 'ratio' => '4:3'] + $d;
        return $d;
    }

    /** Übersichtsseite /{route}: Datenliste „Kapitel“ und „Neu im Wissensbereich“ (zuletzt geändert) */
    public static function makeListPage(array $t): ?int
    {
        $route = (string) $t['settings']['route'];
        if ($route === '' || str_contains($route, '/') || Pages::byPath($route)) return null;
        $h = $t['handle'];
        $blocks = Pages::sanitizeBlocks([
            ['id' => bin2hex(random_bytes(5)), 'type' => 'data_list', 'data' => ['title' => $t['name']] + self::listBlock($t)],
            ['id' => bin2hex(random_bytes(5)), 'type' => 'data_list', 'data' => ['title' => lt('Neu im Wissensbereich'), 'table' => $h, 'fields' => [$h . '._title', $h . '.updated_at'],
                'layout' => 'compact', 'limit' => 5, 'sort_field' => $h . '.updated_at', 'sort_dir' => 'desc', 'link_detail' => true, 'hide_empty' => true]],
        ]);
        return Pages::create(['slug' => $route, 'title' => $t['name'], 'status' => 'published', 'menu' => 0], $blocks);
    }

    // ================================================================= Kapitelbaum

    /**
     * Kapitelbaum der veröffentlichten Einträge (bzw. $rows): Bereiche in der Reihenfolge der Optionen, darin Kapitel und Unterkapitel.
     * @return array{nodes: array<int, array>, order: list<int>, areas: list<array{key: string, label: string, number: string, ids: list<int>}>}
     */
    public static function tree(array $t, ?array $rows = null): array
    {
        $key = $t['handle'] . ':' . \Core\Lang::current();
        if ($rows === null && isset(self::$trees[$key])) return self::$trees[$key];
        $rows ??= Exposure::publicRows($t, Entries::query($t, ['status' => 'published', 'limit' => self::MAX, 'sort' => 'sort', 'dir' => 'asc']));
        $res = self::build($t, $rows);
        if (func_num_args() === 1) self::$trees[$key] = $res;
        return $res;
    }

    /** Baum aus Zeilen (ohne Datenbank – auch für den Selbsttest) */
    public static function build(array $t, array $rows): array
    {
        $bf = Tables::field($t, 'bereich');
        $opts = array_map('strval', array_keys((array) ($bf['options'] ?? [])));
        $nodes = [];
        foreach ($rows as $e) {
            $id = (int) $e['id'];
            $nodes[$id] = ['id' => $id, 'title' => Entries::title($t, $e), 'parent' => (int) ($e['oberkapitel'] ?? 0), 'area' => (string) ($e['bereich'] ?? ''),
                'own' => trim((string) ($e['kapitelnummer'] ?? '')), 'sort' => (int) ($e['sort'] ?? 0), 'href' => self::href($t, $e), 'ref' => self::refUrl($t, $e) !== null, 'children' => [],
                'number' => '', 'depth' => 0, 'entry' => $e];
        }
        // Oberkapitel nur, wenn es im Baum steht und kein Kreis entsteht – sonst erste Ebene
        foreach (array_keys($nodes) as $id) {
            $p = $nodes[$id]['parent'];
            if (!$p) continue;
            if (!isset($nodes[$p]) || $p === $id) { $nodes[$id]['parent'] = 0; continue; }
            $seen = [$id => true];
            while ($p && isset($nodes[$p])) {
                if (isset($seen[$p])) { $nodes[$id]['parent'] = 0; break; }
                $seen[$p] = true;
                $p = $nodes[$p]['parent'];
            }
        }
        $cmp = fn(int $a, int $b) => [$nodes[$a]['sort'], mb_strtolower($nodes[$a]['title'])] <=> [$nodes[$b]['sort'], mb_strtolower($nodes[$b]['title'])] ?: $a <=> $b;
        $tops = [];
        foreach ($nodes as $id => $n) {
            if ($n['parent']) $nodes[$n['parent']]['children'][] = $id;
            else $tops[] = $id;
        }
        foreach ($nodes as $id => $n) usort($nodes[$id]['children'], $cmp);
        // Bereiche: ohne Bereich zuerst (falls es solche gibt), dann die Optionen in ihrer Reihenfolge – nur Bereiche mit Kapiteln
        $byArea = [];
        foreach ($tops as $id) {
            $a = in_array($nodes[$id]['area'], $opts, true) ? $nodes[$id]['area'] : '';
            $byArea[$a][] = $id;
        }
        $areaKeys = array_values(array_filter(['', ...$opts], fn($a) => isset($byArea[$a])));
        $useAreas = $areaKeys !== [''] && $areaKeys !== [];
        $areas = [];
        $order = [];
        $walk = function (int $id, string $prefix, int $pos, int $depth) use (&$walk, &$nodes, &$order): void {
            $nodes[$id]['number'] = $nodes[$id]['own'] !== '' ? $nodes[$id]['own'] : ($prefix !== '' ? $prefix . '.' : '') . $pos;
            $nodes[$id]['depth'] = $depth;
            $order[] = $id;
            foreach ($nodes[$id]['children'] as $i => $c) $walk($c, $nodes[$id]['number'], $i + 1, $depth + 1);
        };
        foreach ($areaKeys as $ai => $a) {
            usort($byArea[$a], $cmp);
            $num = $useAreas ? (string) ($ai + 1) : '';
            foreach ($byArea[$a] as $i => $id) $walk($id, $num, $i + 1, 0);
            $areas[] = ['key' => $a, 'label' => $a === '' ? ($useAreas ? lt('Allgemein') : '') : ($bf ? Tables::optionLabel($bf, $a) : $a), 'number' => $num, 'ids' => $byArea[$a]];
        }
        return ['nodes' => $nodes, 'order' => $order, 'areas' => $areas];
    }

    /** Vorfahren eines Kapitels (oberstes zuerst) */
    public static function ancestors(array $tree, int $id): array
    {
        $out = [];
        $p = (int) ($tree['nodes'][$id]['parent'] ?? 0);
        while ($p && isset($tree['nodes'][$p]) && count($out) < 20) { array_unshift($out, $tree['nodes'][$p]); $p = (int) $tree['nodes'][$p]['parent']; }
        return $out;
    }

    /** [vorheriges, nächstes] Kapitel in Lesereihenfolge */
    public static function neighbours(array $tree, int $id): array
    {
        $i = array_search($id, $tree['order'], true);
        if ($i === false) return [null, null];
        $get = fn($k) => isset($tree['order'][$k]) ? $tree['nodes'][$tree['order'][$k]] : null;
        return [$get($i - 1), $get($i + 1)];
    }

    /** Übersichtsseite (Seite unter der Adresse der Tabelle, z. B. /wissen) */
    public static function overviewUrl(array $t): ?string
    {
        $route = (string) ($t['settings']['route'] ?? '');
        $p = $route !== '' ? Pages::byPath($route) : null;
        return $p && ($p['status'] === 'published' || app()->auth->check()) ? Pages::url($p) : null;
    }

    /** Übersicht gefiltert nach einem Schlagwort (Besucher-Filter der Datenliste, ?schlagworte=…) */
    public static function tagHref(array $t, string $key): ?string
    {
        $u = self::overviewUrl($t);
        return $u !== null ? $u . '?' . http_build_query(['schlagworte' => [$key]]) : null;
    }

    // ================================================================= Abschnitte (h2) und Nummer in der Überschrift

    /**
     * Zwischenüberschriften (h2) im Inhalt: fehlende IDs ergänzen (kn-…), Liste für „In diesem Kapitel“.
     * @return array{0: string, 1: list<array{id: string, label: string}>}
     */
    public static function sections(string $html): array
    {
        $list = [];
        $used = [];
        $html = (string) preg_replace_callback('~<h2(\s[^>]*)?>(.*?)</h2>~is', function ($m) use (&$list, &$used) {
            $attrs = (string) $m[1];
            // Zeilenumbrüche (<br>), geschützte Leerzeichen und Hervorhebungen → ein Leerzeichen; leere Überschriften zählen nicht
            $label = trim((string) preg_replace('~[\s\x{00A0}\x{200B}]+~u', ' ', html_entity_decode(strip_tags((string) preg_replace('~<br\s*/?>~i', ' ', $m[2])), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($label === '' || str_contains($attrs, 'data-kn-skip')) return $m[0];
            if (preg_match('~\sid="([^"]+)"~', $attrs, $im)) {
                $id = $im[1];
            } else {
                $base = 'kn-' . (Pages::slugify($label) ?: 'abschnitt');
                $id = $base;
                for ($i = 2; isset($used[$id]); $i++) $id = $base . '-' . $i;
                $attrs .= ' id="' . e($id) . '"';
            }
            $used[$id] = true;
            $list[] = ['id' => $id, 'label' => $label];
            return '<h2' . $attrs . '>' . $m[2] . '</h2>';
        }, $html);
        return [$html, $list];
    }

    /** Kapitelnummer vor den Titel der ersten Überschrift (h1) setzen */
    public static function numberHeading(string $html, string $number): string
    {
        if ($number === '') return $html;
        return (string) preg_replace('~(<h1\b[^>]*>)~i', '$1<span class="kn-num kn-num--h1">' . e($number) . '</span> ', $html, 1);
    }

    // ================================================================= Ansicht auf der Detailseite

    /** Ansicht anwenden? Nur Besucher/Entwurfsansicht auf der Detailseite eines Wissenseintrags – nie beim Bearbeiten */
    public static function applies(): bool
    {
        $ctx = app()->entry;
        return $ctx && self::is($ctx['table']) && !app()->editing && !app()->contentEdit && !empty($ctx['table']['settings']['detail_page_id']);
    }

    /** Blocktyp „Kapitelnavigation“ (app/Blocks/kn_nav.php) */
    public const NAV = 'kn_nav';
    /** Optionen des Blocks „Kapitelnavigation“ (Standard: Seitenleiste links mit allem) */
    public const NAV_DEFAULTS = ['position' => 'left', 'search' => true, 'sections' => true, 'tree' => true, 'crumbs' => true, 'compact' => false, 'footer' => true];

    /**
     * Blöcke der Detailseiten-Vorlage rendern und rahmen (SiteController::render).
     *  - Mit Block „Kapitelnavigation“ (kn_nav, oberste Ebene): Blöcke davor stehen über die volle Breite, die danach im Rahmen; der
     *    Block bestimmt Position, Suche, Abschnitte, Kapitelbaum, Brotkrumen, Fuß und Kompaktheit. Ausgeblendet = keine Navigation.
     *  - Ohne den Block (alle bestehenden Vorlagen): Rahmen wie bisher aus den Einstellungen der Tabelle (layout); beginnt die Vorlage mit
     *    einem Einstieg mit Quelle „Felder des Eintrags“ (Core\Blocks\EntryHero::leadIndex), steht er über die volle Breite davor.
     */
    public static function render(\Core\Theme $theme, array $blocks, array $t, array $e, bool $editing = false): string
    {
        $blocks = array_values($blocks);
        $n = self::navIndex($blocks);
        if ($n !== null) {
            $head = $n > 0 ? $theme->renderBlocks(array_slice($blocks, 0, $n)) : '';
            $rest = $theme->renderBlocks(array_slice($blocks, $n + 1));
            $opt = self::navOptions($blocks[$n]);
            return $opt === null ? $head . $rest : self::frame($rest, $t, $e, $editing, $head, $opt);
        }
        $i = \Core\Blocks\EntryHero::leadIndex($blocks);
        if ($i === null) return self::frame($theme->renderBlocks($blocks), $t, $e, $editing);
        $head = $theme->renderBlocks(array_slice($blocks, 0, $i + 1));
        return self::frame($theme->renderBlocks(array_slice($blocks, $i + 1)), $t, $e, $editing, $head);
    }

    /** Stelle des Blocks „Kapitelnavigation“ in der Vorlage (oberste Ebene, auch ausgeblendet) – null ohne */
    public static function navIndex(array $blocks): ?int
    {
        foreach (array_values($blocks) as $i => $b) if (is_array($b) && ($b['type'] ?? '') === self::NAV) return $i;
        return null;
    }

    /** Optionen des Blocks (gespeicherte Blockdaten) – null, wenn ausgeblendet */
    public static function navOptions(array $b): ?array
    {
        if (($b['tunes']['section']['visible'] ?? true) === false) return null;
        $d = (array) ($b['data'] ?? []);
        $o = self::NAV_DEFAULTS;
        $o['position'] = in_array($d['position'] ?? '', ['left', 'right', 'top'], true) ? (string) $d['position'] : 'left';
        foreach (['search', 'sections', 'tree', 'crumbs', 'compact', 'footer'] as $k) if (array_key_exists($k, $d)) $o[$k] = !empty($d[$k]);
        return $o;
    }

    /** Blockdaten „Kapitelnavigation“ für neue Vorlagen: Standard aus dem Layout der Tabelle ($compact erzwingt kompakt) */
    public static function navData(array $t, ?bool $compact = null): array
    {
        $layout = self::config($t)['layout'];
        return ['position' => $layout === 'focus' ? 'top' : 'left', 'search' => true, 'sections' => true, 'tree' => true, 'crumbs' => true,
            'compact' => $compact ?? ($layout === 'compact'), 'footer' => true];
    }

    /**
     * Rahmen um den Inhalt der Detailseite: Seitenleiste (Suche, Kapitel), Brotkrumen, „In diesem Kapitel“, Fuß mit Schlagworten,
     * Begriffen, verwandten Kapiteln und Vor/Zurück. $head: Einstieg über die volle Breite davor (Kapitelnummer steht dann in
     * seiner Dachzeile, nicht vor der H1; Klasse kn--headed). $opt: Optionen des Blocks „Kapitelnavigation“ (navOptions) statt des
     * Layouts der Tabelle – Position (links, rechts, oben als Menü), Suche, Abschnitte, Kapitelbaum, Brotkrumen, Fuß, kompakt.
     */
    public static function frame(string $content, array $t, array $e, bool $editing = false, string $head = '', ?array $opt = null): string
    {
        self::$framed = true;
        $cfg = self::config($t);
        $tree = self::tree($t);
        $id = (int) $e['id'];
        $node = $tree['nodes'][$id] ?? null;
        $num = $cfg['numbers'] && $node ? $node['number'] : '';
        [$content, $sections] = self::sections($content);
        self::$sections = $sections;
        // Nummer vor der H1 nur im Rahmen alter Art; der Block „Datensatz-Felder“ (Kopf) setzt sie selbst bzw. schaltet sie ab
        if ($head === '' && $opt === null && !str_contains($content, 'kn-num--h1') && !str_contains($content, 'data-kn-nonum')) $content = self::numberHeading($content, $num);
        $layout = $opt === null ? $cfg['layout'] : ($opt['compact'] ? 'compact' : 'classic');
        $o = $opt ?? self::NAV_DEFAULTS;
        $handbook = in_array($layout, ['handbook', 'handbook_menu'], true);
        $overview = self::overviewUrl($t);
        $wrap = app()->theme->def['container_class'] ?? 'wrap';
        $uid = 'kn-' . $id;
        $name = (string) $t['name'];

        // „In diesem Kapitel“: Handbuch = Menü unter der Überschrift (+ Spalte rechts ab 1200 px), Klassisch/Kompakt/Fokus = in der Seitenleiste
        $secList = function (string $cls) use ($sections): string {
            $h = '<ol class="kn-secs ' . $cls . '" role="list">';
            foreach ($sections as $s) $h .= '<li><a href="#' . e($s['id']) . '" data-kn-sec>' . e($s['label']) . '</a></li>';
            return $h . '</ol>';
        };
        $toc = '';
        if ($handbook && count($sections) > 1) {
            $toc = '<details class="kn-toc kn-toc--inline"><summary>' . e(lt('In diesem Kapitel')) . ' <span class="kn-toc__n">(' . count($sections) . ')</span></summary>'
                . '<nav aria-label="' . e(lt('In diesem Kapitel')) . '">' . $secList('kn-secs--inline') . '</nav></details>';
            $content = str_contains($content, '</h1>') ? (string) preg_replace('~</h1>~i', '</h1>' . self::protect($toc), $content, 1) : $toc . $content;
            $content = self::unprotect($content);
        }

        // Seitenleiste: Kopf mit Suche, Kapitel je Bereich (Unterkapitel nur auf dem Weg zum aufgerufenen Kapitel)
        $path = $node ? array_merge(array_column(self::ancestors($tree, $id), 'id'), [$id]) : [];
        $item = function (array $n) use (&$item, $tree, $id, $path, $cfg, $sections, $handbook, $secList, $o): string {
            $cur = $n['id'] === $id;
            $h = '<li class="kn-nav__item' . ($cur ? ' is-current' : '') . (in_array($n['id'], $path, true) ? ' is-open' : '') . '">';
            $label = ($cfg['numbers'] ? '<span class="kn-num">' . e($n['number']) . '</span> ' : '') . '<span class="kn-nav__t">' . e($n['title']) . '</span>';
            $h .= $n['href'] ? '<a class="kn-nav__link" href="' . e($n['href']) . '"' . ($cur ? ' aria-current="page"' : '') . '>' . $label . '</a>' : '<span class="kn-nav__link">' . $label . '</span>';
            if ($cur && !$handbook && $o['sections'] && count($sections) > 1) $h .= $secList('kn-secs--nav');
            if ($n['children'] && in_array($n['id'], $path, true)) {
                $h .= '<ul class="kn-nav__sub" role="list">';
                foreach ($n['children'] as $c) $h .= $item($tree['nodes'][$c]);
                $h .= '</ul>';
            }
            return $h . '</li>';
        };
        $nav = '';
        foreach ($tree['areas'] as $ai => $a) {
            $aid = $uid . '-a' . $ai;
            if ($a['label'] !== '') $nav .= '<p class="kn-area" id="' . e($aid) . '">' . ($cfg['numbers'] && $a['number'] !== '' ? '<span class="kn-num">' . e($a['number']) . '</span> ' : '') . e($a['label']) . '</p>';
            $nav .= '<ul class="kn-nav__list" role="list"' . ($a['label'] !== '' ? ' aria-labelledby="' . e($aid) . '"' : '') . '>';
            foreach ($a['ids'] as $c) $nav .= $item($tree['nodes'][$c]);
            $nav .= '</ul>';
        }
        // Modus „Bearbeiten“ (?edit=inhalt): gleiches Layout, Seitenleiste ohne Suche und inaktiv (inert) – der Zusammenhang bleibt sichtbar,
        // ein Klick führt nicht aus dem Editor
        $search = $overview === null || $editing || !$o['search'] ? '' : '<form class="kn-search" role="search" aria-label="' . e(lt('Wissensbereich')) . '" method="get" action="' . e($overview) . '">'
            . '<label class="kn-search__label" for="' . e($uid) . '-q">' . e(lt('Im Wissensbereich suchen')) . '</label>'
            . '<span class="kn-search__row"><input id="' . e($uid) . '-q" class="kn-search__input" type="search" name="q" maxlength="80" autocomplete="off" enterkeyhint="search">'
            . '<button class="kn-search__btn" type="submit">' . icon('magnifying-glass', ['class' => 'kn-ico']) . '<span class="kn-sr">' . e(lt('Suchen')) . '</span></button></span></form>';
        $top = $opt !== null && $o['position'] === 'top';
        // Ohne Kapitelbaum (Block „Kapitelnavigation“): nur die Abschnitte dieses Kapitels in der Seitenleiste
        $navHtml = $o['tree'] ? '<nav class="kn-nav" aria-label="' . e(lt('Kapitel')) . '">' . $nav . '</nav>'
            : ($o['sections'] && count($sections) > 1 ? '<nav class="kn-nav" aria-label="' . e(lt('In diesem Kapitel')) . '">' . $secList('kn-secs--nav') . '</nav>' : '');
        $side = '';
        if ($opt === null || $search !== '' || $navHtml !== '') {
            $side = '<div class="kn-side" id="' . e($uid) . '-side"' . ($editing ? ' inert' : ($top ? '' : ' data-kn-drawer')) . '>'
                . '<div class="kn-side__head"><p class="kn-side__title">' . ($overview ? '<a href="' . e($overview) . '">' . e($name) . '</a>' : e($name)) . '</p>'
                . ($top ? '' : '<button class="kn-close" type="button" data-kn-close hidden>' . icon('x', ['class' => 'kn-ico']) . '<span class="kn-sr">' . e(lt('Kapitelmenü schließen')) . '</span></button>') . '</div>'
                . $search
                . $navHtml
                . ($overview && $o['tree'] ? '<p class="kn-side__all"><a href="' . e($overview) . '">' . e(lt('Inhaltsverzeichnis')) . '</a></p>' : '')
                . '</div>';
            // Oben als Menü: aufklappbar über dem Text (ohne Skript, ohne Schublade)
            if ($top) $side = '<details class="kn-menu"' . ($editing ? ' inert' : '') . '><summary class="kn-menu__sum">' . icon('list', ['class' => 'kn-ico']) . '<span>' . e(lt('Kapitel')) . '</span></summary>' . $side . '</details>';
        }

        // Brotkrumen (sichtbar; JSON-LD über StructuredData::breadcrumb)
        $crumbs = '';
        if (($overview || $node) && $o['crumbs']) {
            $parts = $overview ? ['<a href="' . e($overview) . '">' . e($name) . '</a>'] : [];
            foreach ($node ? self::ancestors($tree, $id) : [] as $a) if ($a['href']) $parts[] = '<a href="' . e($a['href']) . '">' . e($a['title']) . '</a>';
            $parts[] = '<span aria-current="page">' . e(Entries::title($t, $e)) . '</span>';
            $crumbs = '<nav class="kn-crumbs" aria-label="' . e(lt('Brotkrumen')) . '"><ol role="list"><li>' . implode('</li><li>', $parts) . '</li></ol></nav>';
        }

        $foot = $o['footer'] ? self::footer($t, $e, $tree, $cfg) : '';
        if ($editing) {
            if ($foot !== '') $foot = '<div class="kn-foot-wrap" inert>' . $foot . '</div>';
            if ($crumbs !== '') $crumbs = '<div inert>' . $crumbs . '</div>';
        }
        $aside = $layout === 'handbook' && count($sections) > 1
            ? '<aside class="kn-aside" aria-labelledby="' . e($uid) . '-on"><div class="kn-aside__in"><p class="kn-aside__title" id="' . e($uid) . '-on">' . e(lt('In diesem Kapitel')) . '</p>'
                . '<nav aria-labelledby="' . e($uid) . '-on">' . $secList('kn-secs--aside') . '</nav></div></aside>'
            : '';
        $toggle = '<button class="kn-toggle" type="button" data-kn-toggle aria-expanded="false" aria-controls="' . e($uid) . '-side" hidden>'
            . icon('list', ['class' => 'kn-ico']) . '<span>' . e(lt('Kapitel')) . '</span></button>';
        // Block „Kapitelnavigation“: Position (kn--right, kn--top), ohne Seitenleiste (kn--noside); Knopf „Kapitel“ nur mit Schublade
        $mods = $opt === null ? '' : ' kn--block' . ($o['position'] === 'right' ? ' kn--right' : '') . ($top ? ' kn--top' : '') . ($side === '' ? ' kn--noside' : '');
        if ($top || $side === '') $toggle = '';
        return $head . '<div class="' . e($wrap) . ' kn kn--' . e($layout) . ($aside !== '' ? ' kn--aside' : '') . ($editing ? ' kn--editing' : '') . ($head !== '' ? ' kn--headed' : '') . $mods . '"' . ($editing ? '' : ' data-kn') . '>'
            . ($editing ? '' : ($side !== '' && !$top ? '<a class="kn-skip" href="#' . e($uid) . '-text">' . e(lt('Zum Kapiteltext springen')) . '</a>' : '') . $toggle)
            . $side
            . '<div class="kn-main" id="' . e($uid) . '-text" tabindex="-1">' . $crumbs . '<div class="kn-content">' . $content . '</div>' . $foot . '</div>'
            . $aside
            . '</div>';
    }

    /** HTML vor preg_replace schützen ($ und \ im Ersatztext) */
    private static function protect(string $s): string
    {
        return '%%KN' . base64_encode($s) . 'KN%%';
    }

    private static function unprotect(string $s): string
    {
        return (string) preg_replace_callback('~%%KN([A-Za-z0-9+/=]+)KN%%~', fn($m) => (string) base64_decode($m[1]), $s);
    }

    /** Fuß des Kapitels: geprüft/Autor, Schlagworte, Begriffe, verwandte Kapitel, Vor/Zurück */
    private static function footer(array $t, array $e, array $tree, array $cfg): string
    {
        $id = (int) $e['id'];
        $h = '';
        $meta = [];
        if (trim((string) ($e['autor'] ?? '')) !== '') $meta[] = e(lt('Von {name}', ['name' => trim((string) $e['autor'])]));
        if (preg_match('~^\d{4}-\d{2}-\d{2}~', (string) ($e['geprueft_am'] ?? ''))) $meta[] = e(lt('Zuletzt geprüft: {date}', ['date' => fmt()->date((string) $e['geprueft_am'])]));
        if ($meta) $h .= '<p class="kn-meta">' . implode('<span aria-hidden="true"> · </span>', $meta) . '</p>';
        // Schlagworte als Links zur gefilterten Übersicht
        $tf = Tables::field($t, 'schlagworte');
        $tags = $tf ? array_values(array_filter(array_map('strval', (array) ($e['schlagworte'] ?? [])), fn($k) => isset($tf['options'][$k]))) : [];
        if ($tags) {
            $h .= '<div class="kn-tags"><h2 class="kn-foot__h" data-kn-skip>' . e(lt('Schlagworte')) . '</h2><ul class="kn-chips" role="list">';
            foreach ($tags as $k) {
                $u = self::tagHref($t, $k);
                $l = e(Tables::optionLabel($tf, $k));
                $h .= '<li>' . ($u ? '<a class="kn-chip" href="' . e($u) . '">' . $l . '</a>' : '<span class="kn-chip">' . $l . '</span>') . '</li>';
            }
            $h .= '</ul></div>';
        }
        // Begriffe in diesem Kapitel (websiteweites Glossar)
        $terms = self::terms($t, $e);
        if ($terms) {
            $h .= '<div class="kn-terms"><h2 class="kn-foot__h" data-kn-skip>' . e(lt('Begriffe in diesem Kapitel')) . '</h2><dl class="kn-terms__list">';
            foreach ($terms as $x) $h .= '<div><dt>' . ($x['url'] ? '<a href="' . e($x['url']) . '">' . e($x['term']) . '</a>' : e($x['term'])) . '</dt><dd>' . e($x['short']) . '</dd></div>';
            $h .= '</dl></div>';
        }
        // Verwandte Kapitel: ausdrücklich verknüpfte, dann gemeinsame Schlagworte
        if ($cfg['related'] && ($rel = self::related($t, $e, $tree))) {
            $h .= '<div class="kn-related"><h2 class="kn-foot__h" data-kn-skip>' . e(lt('Verwandte Kapitel')) . '</h2><ul class="kn-related__list" role="list">';
            foreach ($rel as $n) $h .= '<li><a href="' . e((string) $n['href']) . '">' . ($cfg['numbers'] ? '<span class="kn-num">' . e($n['number']) . '</span> ' : '') . e($n['title']) . '</a></li>';
            $h .= '</ul></div>';
        }
        if ($cfg['pager']) {
            [$prev, $next] = self::neighbours($tree, $id);
            $link = function (?array $n, string $dir) use ($cfg): string {
                if (!$n || !$n['href']) return '<span class="kn-pager__slot"></span>';
                return '<a class="kn-pager__link kn-pager__link--' . $dir . '" href="' . e($n['href']) . '" rel="' . $dir . '"><span class="kn-pager__dir">'
                    . e($dir === 'prev' ? lt('Zurück') : lt('Weiter')) . '</span><span class="kn-pager__t">' . ($cfg['numbers'] ? '<span class="kn-num">' . e($n['number']) . '</span> ' : '') . e($n['title']) . '</span></a>';
            };
            if ($prev || $next) $h .= '<nav class="kn-pager" aria-label="' . e(lt('Kapitel blättern')) . '">' . $link($prev, 'prev') . $link($next, 'next') . '</nav>';
        }
        return $h !== '' ? '<footer class="kn-foot">' . $h . '</footer>' : '';
    }

    /** Verwandte Kapitel: Feld verwandt (Reihenfolge der Redaktion), dann meiste gemeinsame Schlagworte – höchstens $max */
    public static function related(array $t, array $e, array $tree, int $max = 5): array
    {
        $id = (int) $e['id'];
        $out = [];
        foreach ((array) ($e['verwandt'] ?? []) as $r) if (isset($tree['nodes'][(int) $r]) && (int) $r !== $id) $out[(int) $r] = $tree['nodes'][(int) $r];
        $mine = array_map('strval', (array) ($e['schlagworte'] ?? []));
        if ($mine && count($out) < $max) {
            $score = [];
            foreach ($tree['nodes'] as $nid => $n) {
                if ($nid === $id || isset($out[$nid])) continue;
                $c = count(array_intersect($mine, array_map('strval', (array) ($n['entry']['schlagworte'] ?? []))));
                if ($c) $score[$nid] = $c;
            }
            $pos = array_flip($tree['order']);
            uksort($score, fn($a, $b) => $score[$b] <=> $score[$a] ?: ($pos[$a] ?? 0) <=> ($pos[$b] ?? 0));
            foreach (array_keys($score) as $nid) { if (count($out) >= $max) break; $out[$nid] = $tree['nodes'][$nid]; }
        }
        return array_values(array_filter(array_slice($out, 0, $max, true), fn($n) => $n['href']));
    }

    /** Glossarbegriffe des Kapitels (Feld begriffe → websiteweites Glossar), nur veröffentlichte */
    public static function terms(array $t, array $e): array
    {
        $ids = array_map('intval', (array) ($e['begriffe'] ?? []));
        $f = Tables::field($t, 'begriffe');
        if (!$ids || !$f || ($f['target'] ?? '') !== Glossary::HANDLE || !Glossary::enabled()) return [];
        $out = [];
        foreach (Glossary::terms() as $x) if (in_array($x['id'], $ids, true)) $out[] = $x;
        return $out;
    }

    /**
     * „Erwähnt in“ für die Glossar-Detailseite: Wissenseinträge, die den Begriff verknüpfen (alle Wissenstabellen), dann Seiten und
     * Einträge, in denen er vorkommt (Glossary::occurrences). @return list<array{title: string, url: string}>
     */
    public static function mentions(int $termId, int $max = 12): array
    {
        $out = [];
        foreach (Tables::content() as $t) {
            if (!self::is($t) || (Tables::field($t, 'begriffe')['target'] ?? '') !== Glossary::HANDLE) continue;
            try {
                $rows = Entries::query($t, ['status' => 'published', 'limit' => 50, 'where' => [['begriffe', '=', (string) $termId]]]);
            } catch (\Throwable) {
                continue;
            }
            foreach (Exposure::publicRows($t, $rows) as $e) if ($u = Entries::href($t, $e)) $out[$u] = ['title' => Entries::title($t, $e), 'url' => $u];
        }
        foreach (Glossary::occurrences()[$termId] ?? [] as $o) {
            $u = (string) ($o['url'] ?? '');
            if ($u !== '' && !isset($out[$u])) $out[$u] = ['title' => (string) $o['title'], 'url' => $u];
        }
        return array_slice(array_values($out), 0, $max);
    }

    /** <link rel="prev|next"> der Kapitel-Reihenfolge für den <head> */
    public static function headLinks(array $t, array $e): string
    {
        if (!self::config($t)['pager']) return '';
        [$prev, $next] = self::neighbours(self::tree($t), (int) $e['id']);
        $h = '';
        foreach (['prev' => $prev, 'next' => $next] as $rel => $n) if ($n && $n['href']) $h .= '<link rel="' . $rel . '" href="' . e(abs_url((string) $n['href'])) . '">';
        return $h;
    }

    /** Vorfahren eines Eintrags für Brotkrumen (JSON-LD) [[Titel, Adresse], …] */
    public static function crumbs(array $t, array $e): array
    {
        $out = [];
        foreach (self::ancestors(self::tree($t), (int) $e['id']) as $a) if ($a['href']) $out[] = [$a['title'], abs_url((string) $a['href'])];
        return $out;
    }

    /**
     * Inhaltsverzeichnis (Datenliste, Darstellung „Kapitel“): Bereiche als Überschrift, Kapitel als verschachtelte Liste mit Nummer,
     * Titel und Anreißer der ersten Ebene. $hTag = Ebene der Bereichs-Überschriften.
     */
    public static function tocHtml(array $t, ?array $tree = null, string $hTag = 'h2'): string
    {
        $tree ??= self::tree($t);
        $cfg = self::config($t);
        $item = function (array $n) use (&$item, $tree, $cfg): string {
            $label = ($cfg['numbers'] ? '<span class="dl-knum">' . e($n['number']) . '</span> ' : '') . e($n['title']);
            $h = '<li class="kn-toc-item kn-toc-item--d' . min(3, (int) $n['depth']) . '">' . ($n['href'] ? '<a class="kn-toc-item__link" href="' . e($n['href']) . '">' . $label . '</a>' : $label);
            $teaser = $n['depth'] === 0 ? trim((string) ($n['entry']['anreisser'] ?? '')) : '';
            if ($teaser !== '') $h .= '<p class="kn-toc-item__text">' . e(mb_strimwidth($teaser, 0, 220, '…')) . '</p>';
            if ($n['children']) {
                $h .= '<ol class="kn-toc-list kn-toc-list--sub" role="list">';
                foreach ($n['children'] as $c) $h .= $item($tree['nodes'][$c]);
                $h .= '</ol>';
            }
            return $h . '</li>';
        };
        $out = '<div class="kn-toc-all">';
        foreach ($tree['areas'] as $a) {
            $out .= '<div class="kn-toc-area">';
            if ($a['label'] !== '') $out .= '<' . $hTag . ' class="kn-toc-area__h">' . ($cfg['numbers'] && $a['number'] !== '' ? '<span class="dl-knum">' . e($a['number']) . '</span> ' : '') . e($a['label']) . '</' . $hTag . '>';
            $out .= '<ol class="kn-toc-list" role="list">';
            foreach ($a['ids'] as $id) $out .= $item($tree['nodes'][$id]);
            $out .= '</ol></div>';
        }
        return $out . '</div>';
    }

    /** Bild eines Kapitels: Feld „Bild“, bei Verweis-Kapiteln ohne eigenes Bild das der Zielseite (Seitenkopf, sonst Vorschaubild) */
    public static function imageOf(array $t, array $e): int
    {
        if (Tables::field($t, 'bild') && (int) ($e['bild'] ?? 0) > 0) return (int) $e['bild'];
        if (preg_match('~^page:(\d+)~', (string) ($e['verweis'] ?? ''), $m) && ($p = Pages::find((int) $m[1]))) return (int) (\Core\Blocks\SubpageList::item($p)['image'] ?? 0);
        return 0;
    }

    /**
     * Startseite als Karten (Datenliste, Darstellung „Kapitel“ → „Karten“): je Bereich ein Raster der Kapitel der obersten Ebene mit Bild,
     * Nummer als Plakette, Titel, Anreißer und bis zu drei Unterkapiteln als Links („weitere“). $d: columns, ratio des Blocks.
     */
    public static function tocCards(array $t, ?array $tree = null, array $d = []): string
    {
        $tree ??= self::tree($t);
        $cfg = self::config($t);
        $cols = in_array((string) ($d['columns'] ?? ''), ['2', '3', '4'], true) ? (string) $d['columns'] : '3';
        $ratio = in_array((string) ($d['ratio'] ?? ''), ['16:9', '16:10', '4:3', '1:1', '3:4'], true) ? (string) $d['ratio'] : '16:10';
        $sizes = ['2' => '(min-width: 1080px) 520px, (min-width: 640px) 45vw, 90vw', '3' => '(min-width: 1080px) 360px, (min-width: 640px) 45vw, 90vw',
            '4' => '(min-width: 1080px) 270px, (min-width: 640px) 45vw, 90vw'][$cols];
        $out = '<div class="kn-cards-all">';
        foreach ($tree['areas'] as $a) {
            $out .= '<div class="kn-toc-area">';
            if ($a['label'] !== '') $out .= '<h2 class="kn-toc-area__h">' . ($cfg['numbers'] && $a['number'] !== '' ? '<span class="dl-knum">' . e($a['number']) . '</span> ' : '') . e($a['label']) . '</h2>';
            $out .= '<ul class="kn-cards kn-cards--c' . e($cols) . '" role="list">';
            foreach ($a['ids'] as $id) {
                $n = $tree['nodes'][$id];
                $img = self::imageOf($t, $n['entry']);
                $pic = $img ? img($img, $sizes, ['ratio' => $ratio, 'alt' => '']) : '';
                $teaser = trim((string) ($n['entry']['anreisser'] ?? ''));
                $out .= '<li class="kn-card' . ($pic !== '' ? ' kn-card--img' : '') . '">';
                if ($pic !== '') $out .= '<div class="kn-card__img kn-card__img--r' . e(str_replace(':', '-', $ratio)) . '">' . $pic . '</div>';
                $out .= '<div class="kn-card__body">';
                if ($cfg['numbers'] && $n['number'] !== '') $out .= '<span class="kn-card__num">' . e($n['number']) . '</span>';
                $out .= '<h3 class="kn-card__title">' . ($n['href'] ? '<a href="' . e((string) $n['href']) . '">' . e($n['title']) . '</a>' : e($n['title'])) . '</h3>';
                if ($teaser !== '') $out .= '<p class="kn-card__text">' . e(mb_strimwidth($teaser, 0, 220, '…')) . '</p>';
                $kids = array_values(array_filter(array_map(fn($c) => $tree['nodes'][$c], $n['children']), fn($c) => $c['href']));
                if ($kids) {
                    $out .= '<ul class="kn-card__sub" role="list" aria-label="' . e(lt('Unterkapitel von {title}', ['title' => $n['title']])) . '">';
                    foreach (array_slice($kids, 0, 3) as $c) $out .= '<li><a href="' . e((string) $c['href']) . '">' . ($cfg['numbers'] ? '<span class="dl-knum">' . e($c['number']) . '</span> ' : '') . e($c['title']) . '</a></li>';
                    if (count($kids) > 3 && $n['href']) $out .= '<li class="kn-card__more"><a href="' . e((string) $n['href']) . '">' . e(lt('{n} weitere', ['n' => count($kids) - 3])) . '<span class="dl-sr"> ' . e(lt('in {title}', ['title' => $n['title']])) . '</span></a></li>';
                    $out .= '</ul>';
                }
                $out .= '</div></li>';
            }
            $out .= '</ul></div>';
        }
        return $out . '</div>';
    }

    // ================================================================= Suche in der Übersicht

    /** Klartext eines Eintrags (Anreißer + Inhalt) für Trefferauszüge */
    public static function plain(array $t, array $e): string
    {
        $parts = [];
        foreach ($t['fields'] as $f) {
            if (!Exposure::alwaysFor($f, 'web')) continue;
            $v = $e[$f['name']] ?? null;
            if ($f['type'] === 'blocks') $parts[] = EntryBlocks::text($v);
            elseif (in_array($f['type'], ['textarea', 'richtext'], true) && is_string($v)) $parts[] = Text::plain($v);
        }
        return trim((string) preg_replace('~\s+~u', ' ', implode(' ', array_filter($parts))));
    }

    /** Trefferauszug mit <mark> (HTML-sicher) */
    public static function excerpt(array $t, array $e, string $q, int $len = 200): string
    {
        $words = Text::words($q);
        if (!$words) return '';
        return Text::excerpt(self::plain($t, $e), $words, $len);
    }

    /** Glossarbegriffe, deren Name, Varianten oder Kurz-Erklärung zur Suche passen (eigene Treffergruppe) */
    public static function glossaryHits(string $q, int $max = 6): array
    {
        $q = trim($q);
        if ($q === '' || !Glossary::enabled()) return [];
        $words = Text::words($q);
        if (!$words) return [];
        $out = [];
        foreach (Glossary::terms() as $x) {
            $hay = Text::fold(implode(' ', [$x['term'], ...$x['variants'], $x['short']]));
            $all = true;
            foreach ($words as $w) if (!preg_match('~(?<![\p{L}\p{N}])' . preg_quote($w, '~') . '~u', $hay)) { $all = false; break; }
            if ($all) $out[] = $x;
            if (count($out) >= $max) break;
        }
        return $out;
    }

    // ================================================================= Schlagworte

    /**
     * Neue Schlagworte (bzw. Bereiche) als Optionen ergänzen – wie „Neue Liste“ im Adressbuch (AddressBook::addList). Vorhandene
     * (gleicher Name, Groß/klein egal) werden wiederverwendet. Geteilte Tabellen: nur die Eigentümer-Website.
     * @return array{keys: list<string>, error?: string}
     */
    public static function addOptions(array $t, string $field, array $labels): array
    {
        $labels = array_values(array_unique(array_filter(array_map(fn($l) => mb_substr(trim((string) preg_replace('~[\r\n=,;]+~', ' ', (string) $l)), 0, 60), $labels), fn($l) => $l !== '')));
        $f = Tables::field($t, $field);
        if (!$labels || !$f || !in_array($f['type'], ['select', 'multiselect'], true)) return ['keys' => []];
        $have = [];
        foreach ((array) ($f['options'] ?? []) as $k => $l) $have[mb_strtolower((string) $l)] = (string) $k;
        $new = array_values(array_filter($labels, fn($l) => !isset($have[mb_strtolower($l)])));
        if ($new) {
            if (isset($t['shared']) && !Shared::isOwner($t)) return ['keys' => [], 'error' => __('Die Felder dieser geteilten Tabelle ändert nur die Eigentümer-Website.')];
            $in = Tables::toInput($t);
            foreach ($in['fields'] as &$x) if (($x['name'] ?? '') === $field) $x['options'] = rtrim((string) ($x['options'] ?? '')) . "\n" . implode("\n", $new);
            unset($x);
            [$clean, $errors] = Tables::validate($in, $t);
            if ($errors) return ['keys' => [], 'error' => implode(' ', array_map('strval', $errors))];
            Tables::update($t, $clean);
            $t = Tables::find($t['handle']) ?? $t;
            $have = [];
            foreach ((array) (Tables::field($t, $field)['options'] ?? []) as $k => $l) $have[mb_strtolower((string) $l)] = (string) $k;
        }
        return ['keys' => array_values(array_filter(array_map(fn($l) => $have[mb_strtolower($l)] ?? null, $labels)))];
    }

    /**
     * Eingabe des Eintrags-Formulars: neue Bereiche/Schlagworte (Felder _neu_bereich, _neu_schlagworte) als Optionen anlegen und wählen.
     * Gibt die Eingabe ohne die Hilfsfelder zurück; $error erhält einen Hinweis (z. B. geteilte Tabelle eines Mitglieds).
     */
    public static function applyNewOptions(array $t, array $in, ?string &$error = null): array
    {
        foreach (['bereich', 'schlagworte'] as $name) {
            $raw = trim((string) ($in[self::NEW_PREFIX . $name] ?? ''));
            unset($in[self::NEW_PREFIX . $name]);
            if ($raw === '' || !self::is($t)) continue;
            $labels = $name === 'bereich' ? [$raw] : preg_split('~\s*[,;]\s*~u', $raw);
            $res = self::addOptions($t, $name, (array) $labels);
            if (!empty($res['error'])) { $error = $res['error']; continue; }
            $t = Tables::find($t['handle']) ?? $t;
            if (!$res['keys']) continue;
            if ($name === 'bereich') $in[$name] = $res['keys'][0];
            else $in[$name] = array_values(array_unique([...array_values(array_filter(array_map('strval', (array) ($in[$name] ?? [])))), ...$res['keys']]));
        }
        return $in;
    }

    // ================================================================= Selbsttest

    /** Selbsttest ohne Datenbank: Baum, Nummern, Kreise, Abschnitte, Nachbarn, Verwandte, Einstellungen, Glossar bleibt websiteweit */
    public static function selftest(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Wissen – ');
        $t = ['handle' => 'wissen_test', 'name' => 'Wissen', 'singular' => 'Kapitel', 'settings' => ['kind' => 'knowledge', 'title_field' => 'titel', 'route' => '', 'detail_page_id' => null],
            'fields' => [['name' => 'titel', 'type' => 'text'], ['name' => 'bereich', 'type' => 'select', 'options' => ['grundlagen' => 'Grundlagen', 'praxis' => 'Praxis']],
                ['name' => 'oberkapitel', 'type' => 'relation', 'target' => 'wissen_test'], ['name' => 'kapitelnummer', 'type' => 'text'],
                ['name' => 'schlagworte', 'type' => 'multiselect', 'options' => ['a' => 'A', 'b' => 'B']], ['name' => 'verwandt', 'type' => 'relations', 'target' => 'wissen_test']]];
        $r = fn(int $id, string $title, string $area, int $parent = 0, int $sort = 0, string $own = '', array $tags = []) => ['id' => $id, 'titel' => $title, 'bereich' => $area,
            'oberkapitel' => $parent ?: null, 'sort' => $sort, 'kapitelnummer' => $own, 'schlagworte' => $tags, 'slug' => 'k' . $id, 'verwandt' => []];
        $rows = [$r(1, 'Einführung', 'grundlagen', 0, 1, '', ['a']), $r(2, 'Begriffe', 'grundlagen', 0, 2), $r(3, 'Unterkapitel', 'grundlagen', 2, 1, '', ['a', 'b']),
            $r(4, 'Tiefer', 'grundlagen', 3, 1), $r(5, 'Werkzeuge', 'praxis', 0, 1, 'P.1', ['b']), $r(6, 'Kreis A', 'praxis', 7, 2), $r(7, 'Kreis B', 'praxis', 6, 3)];
        $tree = self::build($t, $rows);
        $num = fn(int $id) => $tree['nodes'][$id]['number'] ?? '?';
        $eq('Nummern aus Bereich, Oberkapitel und Reihenfolge (3 Ebenen)', [$num(1), $num(2), $num(3), $num(4)], ['1.1', '1.2', '1.2.1', '1.2.1.1']);
        $eq('Eigene Kapitelnummer ersetzt die automatische', $num(5), 'P.1');
        $eq('Kreis im Oberkapitel → erste Ebene (kein Endlos-Baum)', count($tree['order']), 7);
        $eq('Lesereihenfolge Eltern vor Kindern', array_slice($tree['order'], 0, 5), [1, 2, 3, 4, 5]);
        $eq('Bereiche in Reihenfolge der Optionen', array_column($tree['areas'], 'key'), ['grundlagen', 'praxis']);
        $eq('Vorfahren', array_column(self::ancestors($tree, 4), 'id'), [2, 3]);
        [$p, $n] = self::neighbours($tree, 4);
        $eq('Vor/Zurück', [$p['id'] ?? null, $n['id'] ?? null], [3, 5]);
        $flat = self::build($t, [$r(1, 'B', ''), $r(2, 'A', ''), $r(3, 'C', '', 1)]);
        $eq('Ohne Bereiche: 1, 2, 1.1 (Titel A–Z bei gleicher Reihenfolge)', [$flat['nodes'][2]['number'], $flat['nodes'][1]['number'], $flat['nodes'][3]['number']], ['1', '2', '2.1']);
        [$html, $secs] = self::sections('<h1>T</h1><h2>Erster Schritt</h2><p>x</p><h2 id="eigen">Zweiter</h2><h2>Erster Schritt</h2><h2 data-kn-skip>Fuß</h2>');
        $eq('Abschnitte: IDs ergänzt, vorhandene bleiben, doppelte eindeutig', array_column($secs, 'id'), ['kn-erster-schritt', 'eigen', 'kn-erster-schritt-2']);
        $eq('Abschnitte: Markup mit IDs', substr_count($html, 'id="kn-erster-schritt'), 2);
        $eq('Nummer in der Überschrift', self::numberHeading('<h1 class="x">Titel</h1>', '2.1'), '<h1 class="x"><span class="kn-num kn-num--h1">2.1</span> Titel</h1>');
        $rel = self::related($t, $rows[2], self::build($t, array_map(fn($x) => $x + ['_' => 1], $rows)));
        $eq('Verwandte über gemeinsame Schlagworte (ohne Adresse: keine Links)', $rel, []);
        $treeH = self::build($t, $rows);
        foreach ($treeH['nodes'] as &$x) $x['href'] = '/w/' . $x['id'];
        unset($x);
        $eq('Verwandte: meiste gemeinsame Schlagworte zuerst', array_column(self::related($t, $rows[2], $treeH), 'id'), [1, 5]);
        $eq('Einstellungen: Standard und Bereinigung', [self::validateSettings([]), self::validateSettings(['layout' => 'quatsch', 'numbers' => '0'])['numbers'], self::validateSettings(['layout' => 'handbook'])['layout']],
            [self::DEFAULTS, false, 'handbook']);
        $eq('Schutz für preg_replace ($ und \\)', self::unprotect(self::protect('a $1 \\ b')), 'a $1 \\ b');
        // Glossar bleibt websiteweit: das Grundschema hat keine eigene Glossar-Tabelle, nur die Verknüpfung „begriffe“ aufs Core-Glossar
        $names = Kinds::systemNames(Kinds::KNOWLEDGE);
        $eq('Grundschema Wissen', array_values(array_intersect(['titel', 'bereich', 'oberkapitel', 'kapitelnummer', 'anreisser', 'inhalt', 'schlagworte', 'verwandt', 'begriffe', 'autor', 'geprueft_am'], $names)),
            ['titel', 'bereich', 'oberkapitel', 'kapitelnummer', 'anreisser', 'inhalt', 'schlagworte', 'verwandt', 'begriffe', 'autor', 'geprueft_am']);
        $err = [];
        $in = array_column(Kinds::enforceInput(Kinds::KNOWLEDGE, [], $err, 'wissen_x'), null, 'name');
        $eq('Oberkapitel/Verwandte → eigene Tabelle, Begriffe → nur das Website-Glossar', [$in['oberkapitel']['target'], $in['verwandt']['target'], in_array($in['begriffe']['target'], ['', Glossary::HANDLE], true)],
            ['wissen_x', 'wissen_x', true]);
        $eq('Keine Glossar-Kopplung je Bereich (kein Feld mit eigenem Glossar, keine zweite Glossar-Tabelle)', array_values(array_filter($names, fn($n) => str_contains($n, 'glossar'))), []);
        return ['ok' => $ok, 'fails' => $fails];
    }
}
