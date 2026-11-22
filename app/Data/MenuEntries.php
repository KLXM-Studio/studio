<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Pages;

/**
 * Einträge im Menü (Tabellen-Einstellung settings.menu_entries): Einträge einer Tabelle mit Adresse erscheinen als Unterpunkte ihrer
 * Übersichtsseite (Seite unter /{route}) – in Kopf- und Mobilmenü, im Fuß (Kits zeigen dort meist nur Ebene 1) und überall, wo Kits
 * Pages::menu() lesen. Standard aus; ohne Einstellung bleibt das Menü unverändert.
 *
 *  - mode: '' (keine) | 'top' (oberste Ebene) | 'two' (zwei Ebenen). Wissen (Core\Data\Knowledge): Kapitelbaum und -reihenfolge,
 *    Unterkapitel als zweite Ebene, Nummer optional (numbers); andere Tabellen: Sortierung der Tabelle, nur eine Ebene.
 *  - limit: höchstens so viele Punkte je Ebene (1–50, Standard 12).
 *  - Verweis-Kapitel (Feld verweis), deren Zielseite selbst im Menü steht, erscheinen nicht doppelt.
 *  - Auf der Detailseite eines Eintrags sind Übersichtsseite und Eintrag „aktiv“.
 */
final class MenuEntries
{
    public const MODES = ['' => 'Keine', 'top' => 'Oberste Ebene', 'two' => 'Zwei Ebenen'];
    public const DEFAULTS = ['mode' => '', 'limit' => 12, 'numbers' => false];

    /** Tabellen mit Einstellung je Adresse (je Anfrage) */
    private static ?array $byRoute = null;

    public static function validate(array $in): array
    {
        $mode = (string) ($in['mode'] ?? '');
        return ['mode' => isset(self::MODES[$mode]) ? $mode : '', 'limit' => max(1, min(50, (int) ($in['limit'] ?? self::DEFAULTS['limit']) ?: self::DEFAULTS['limit'])),
            'numbers' => !empty($in['numbers'])];
    }

    public static function config(array $t): array
    {
        return self::validate((array) ($t['settings']['menu_entries'] ?? []));
    }

    public static function flush(): void
    {
        self::$byRoute = null;
    }

    /** Tabelle mit eingeschalteten Menüpunkten für eine Übersichtsseite (Pfad = Adresse der Tabelle), sonst null */
    public static function tableFor(array $page): ?array
    {
        if (self::$byRoute === null) {
            self::$byRoute = [];
            try {
                foreach (Tables::content() as $t) {
                    $r = trim((string) ($t['settings']['route'] ?? ''), '/');
                    if ($r !== '' && !empty($t['settings']['detail_page_id']) && self::config($t)['mode'] !== '') self::$byRoute[$r] = $t;
                }
            } catch (\Throwable) {
            }
        }
        if (!self::$byRoute || ($page['type'] ?? 'page') !== 'page') return null;
        return self::$byRoute[trim((string) ($page['path'] ?? $page['slug'] ?? ''), '/')] ?? null;
    }

    /** Übersichtsseite einer Tabelle mit Menüpunkten (für „aktiv“ auf Detailseiten), sonst null */
    public static function overviewPage(array $t): ?array
    {
        if (self::config($t)['mode'] === '' || ($r = (string) ($t['settings']['route'] ?? '')) === '') return null;
        return Pages::byPath($r);
    }

    /**
     * Menüpunkte unter der Übersichtsseite $page (leer ohne Einstellung). $menuPageIds: IDs der Seiten im Menü (Verweise nicht doppelt).
     * @return list<array{id: string, label: string, href: string, active: bool, children: list<array>, draft: bool}>
     */
    public static function itemsFor(array $page, array $menuPageIds = []): array
    {
        $t = self::tableFor($page);
        return $t ? self::items($t, $menuPageIds) : [];
    }

    /** Menüpunkte einer Tabelle nach ihrer Einstellung */
    public static function items(array $t, array $menuPageIds = []): array
    {
        $cfg = self::config($t);
        if ($cfg['mode'] === '') return [];
        $ctx = app()->entry;
        $curId = $ctx && ($ctx['table']['handle'] ?? '') === $t['handle'] ? (int) $ctx['entry']['id'] : 0;
        $skip = function (array $e) use ($t, $menuPageIds): bool {
            // Verweis-Kapitel auf eine Seite, die selbst im Menü steht: nicht doppelt
            return Knowledge::is($t) && preg_match('~^page:(\d+)~', (string) ($e['verweis'] ?? ''), $m) === 1 && in_array((int) $m[1], $menuPageIds, true);
        };
        if (Knowledge::is($t)) {
            $tree = Knowledge::tree($t);
            $active = $curId ? array_merge(array_column(Knowledge::ancestors($tree, $curId), 'id'), [$curId]) : [];
            $kcfg = Knowledge::config($t);
            $node = function (array $n, int $depth) use (&$node, $tree, $cfg, $active, $skip, $kcfg): ?array {
                if (!$n['href'] || $skip($n['entry'])) return null;
                $kids = [];
                if ($cfg['mode'] === 'two' && $depth === 0) {
                    foreach ($n['children'] as $c) {
                        if (count($kids) >= $cfg['limit']) break;
                        if ($x = $node($tree['nodes'][$c], 1)) $kids[] = $x;
                    }
                }
                return ['id' => 'e-' . $n['id'], 'label' => ($cfg['numbers'] && $kcfg['numbers'] ? $n['number'] . ' ' : '') . $n['title'], 'href' => (string) $n['href'],
                    'active' => in_array($n['id'], $active, true), 'children' => $kids, 'draft' => false];
            };
            $out = [];
            foreach ($tree['order'] as $id) {
                if ($tree['nodes'][$id]['depth'] !== 0) continue;
                if (count($out) >= $cfg['limit']) break;
                if ($x = $node($tree['nodes'][$id], 0)) $out[] = $x;
            }
            return $out;
        }
        $out = [];
        foreach (Exposure::publicRows($t, Entries::query($t, ['status' => 'published', 'limit' => $cfg['limit']])) as $e) {
            $href = Entries::href($t, $e);
            if (!$href) continue;
            $out[] = ['id' => 'e-' . (int) $e['id'], 'label' => Entries::title($t, $e), 'href' => $href, 'active' => (int) $e['id'] === $curId, 'children' => [], 'draft' => false];
        }
        return $out;
    }

    /** Selbsttest ohne Datenbank: Einstellungen */
    public static function selftest(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Menüpunkte – ');
        $eq('Standard aus', self::validate([]), self::DEFAULTS);
        $eq('Bereinigung', self::validate(['mode' => 'quatsch', 'limit' => 999, 'numbers' => '1']), ['mode' => '', 'limit' => 50, 'numbers' => true]);
        $eq('Zwei Ebenen', self::validate(['mode' => 'two', 'limit' => '5'])['mode'] . self::validate(['mode' => 'two', 'limit' => '5'])['limit'], 'two5');
        return ['ok' => $ok, 'fails' => $fails];
    }
}
