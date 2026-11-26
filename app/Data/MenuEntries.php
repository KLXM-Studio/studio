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
 *  - source: Werte eines Feldes statt der Einträge – Auswahl/Mehrfachauswahl oder Verknüpfung (Einträge der Zieltabelle, z. B. „Themen“);
 *    link = 'detail': Punkte einer Verknüpfung führen zur Detailseite des verknüpften Eintrags statt zur gefilterten Übersicht.
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
        $src = (string) ($in['source'] ?? '');
        return ['mode' => isset(self::MODES[$mode]) ? $mode : '', 'limit' => max(1, min(50, (int) ($in['limit'] ?? self::DEFAULTS['limit']) ?: self::DEFAULTS['limit'])),
            'numbers' => !empty($in['numbers'])] + (preg_match('~^[a-z][a-z0-9_]{0,40}$~', $src) ? ['source' => $src] : [])   // source nur, wenn gesetzt (Bestand unverändert)
            + (($in['link'] ?? '') === 'detail' ? ['link' => 'detail'] : []);   // Verknüpfung: Menüpunkt führt zur Detailseite des verknüpften Eintrags
    }

    /**
     * Felder, deren Werte als Menüpunkte taugen: Auswahl, Mehrfachauswahl (z. B. Bereich, Kategorie, Schlagworte) und Verknüpfungen
     * (relation, relations) auf eine andere Tabelle – z. B. eine Tabelle „Themen“, die die Redaktion selbst pflegt.
     */
    public static function sourceFields(array $t): array
    {
        $out = [];
        foreach ((array) ($t['fields'] ?? []) as $f) {
            $rel = in_array($f['type'] ?? '', ['relation', 'relations'], true) && Tables::findContent((string) ($f['target'] ?? '')) !== null;
            if ($rel || (in_array($f['type'] ?? '', ['select', 'multiselect'], true) && !empty($f['options']))) $out[(string) $f['name']] = (string) ($f['label'] ?? $f['name']);
        }
        return $out;
    }

    /** Verknüpfungsfelder unter sourceFields(), deren Zieltabelle Detailseiten hat (Auswahl „zur Detailseite“) */
    public static function detailCapable(array $t): array
    {
        $out = [];
        foreach ((array) ($t['fields'] ?? []) as $f) {
            // Verknüpfung bzw. Auswahl aus einer Liste (OptionSource) – Ziel mit eigenen Detailseiten (Themenseiten)
            $isRel = in_array($f['type'] ?? '', ['relation', 'relations'], true);
            if (!$isRel && !(in_array($f['type'] ?? '', ['select', 'multiselect'], true) && !empty($f['options_from']))) continue;
            $tt = Tables::findContent((string) ($isRel ? ($f['target'] ?? '') : $f['options_from']));
            if ($tt && !empty($tt['settings']['detail_page_id']) && trim((string) ($tt['settings']['route'] ?? ''), '/') !== '') $out[] = (string) $f['name'];
        }
        return $out;
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
        if (($cfg['source'] ?? '') !== '' && isset(self::sourceFields($t)[$cfg['source']])) return self::valueItems($t, $cfg);
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

    /**
     * Menüpunkte aus den Werten eines Feldes: je Option, die in veröffentlichten Einträgen vorkommt (Reihenfolge wie im Feld), ein Link
     * auf die Übersichtsseite mit vorbelegtem Filter – derselbe Parameter wie die Filter-Schaltflächen der Datenliste (?feld=wert).
     * „Zwei Ebenen“: darunter die Einträge mit diesem Wert. Aktiv: Übersicht mit diesem Filter bzw. Detailseite eines Eintrags mit dem Wert.
     */
    private static function valueItems(array $t, array $cfg): array
    {
        $field = $cfg['source'];
        $fd = Tables::field($t, $field);
        $page = self::overviewPage($t);
        if (!$fd || !$page) return [];
        $base = Pages::url($page);
        $anchor = self::listAnchor($page, $t);
        $o = ['status' => 'published'];
        $counts = (array) (Entries::facets($t, [$field], $o)['counts'][$field] ?? []);
        // Verknüpfung: Einträge der Zieltabelle (veröffentlicht, ihre Sortierung) statt fester Optionen; wahlweise Link zur Detailseite
        $rel = in_array($fd['type'], ['relation', 'relations'], true);
        $options = $rel ? Entries::relationOptions($fd) : (array) $fd['options'];
        $target = $rel ? Tables::findContent((string) $fd['target']) : null;
        $toDetail = $target && ($cfg['link'] ?? '') === 'detail' && in_array($field, self::detailCapable($t), true);
        // Auswahl aus einer Liste mit Detailseiten: Menüpunkt führt auf die Themenseite des Listen-Eintrags
        $listDetail = !$rel && !empty($fd['options_from']) && ($cfg['link'] ?? '') === 'detail' && in_array($field, self::detailCapable($t), true);
        $ctxL = $listDetail ? app()->entry : null;
        $curList = $ctxL && ($ctxL['table']['handle'] ?? '') === (string) $fd['options_from'] ? (string) ($ctxL['entry']['slug'] ?? '') : '';
        $ctxT = app()->entry;
        $curTarget = $toDetail && $ctxT && ($ctxT['table']['handle'] ?? '') === $target['handle'] ? (string) (int) $ctxT['entry']['id'] : '';
        $req = app()->request;
        $onOverview = $req && rtrim((string) $req->path, '/') === rtrim((string) parse_url($base, PHP_URL_PATH), '/');
        $sel = $onOverview ? (array) ($req->query[$field] ?? []) : [];
        $ctx = app()->entry;
        $entryVals = $ctx && ($ctx['table']['handle'] ?? '') === $t['handle'] ? array_map('strval', (array) (is_array($ctx['entry'][$field] ?? null) ? $ctx['entry'][$field] : (json_decode((string) ($ctx['entry'][$field] ?? ''), true) ?? [$ctx['entry'][$field] ?? '']))) : [];
        if ($rel && $entryVals) $entryVals = array_map(fn($v) => (string) (int) (is_array($v) ? ($v['id'] ?? 0) : $v), $entryVals);
        $curId = $entryVals ? (int) $ctx['entry']['id'] : 0;
        $out = [];
        foreach ($options as $key => $label) {
            $key = (string) $key;
            if ((int) ($counts[$key] ?? 0) < 1) continue;
            if (count($out) >= $cfg['limit']) break;
            $kids = [];
            if ($cfg['mode'] === 'two') {
                foreach (Exposure::publicRows($t, Entries::query($t, $o + ['where' => [[$field, '=', $key]], 'limit' => $cfg['limit']])) as $e) {
                    if (!($href = Entries::href($t, $e))) continue;
                    $kids[] = ['id' => 'e-' . (int) $e['id'], 'label' => Entries::title($t, $e), 'href' => $href, 'active' => (int) $e['id'] === $curId, 'children' => [], 'draft' => false];
                }
            }
            $href = $base . '?' . http_build_query([$field => $key]) . $anchor;
            if ($toDetail && ($te = Entries::find($target, (int) $key)) && ($dh = Entries::href($target, $te))) $href = $dh;
            if ($listDetail && ($lh = Entries::listHref((string) $fd['options_from'], $key))) $href = $lh;
            $out[] = ['id' => 'v-' . $field . '-' . $key, 'label' => $rel ? (string) $label : Tables::optionLabel($fd, $key), 'href' => $href,
                'active' => in_array($key, array_map('strval', $sel), true) || in_array($key, $entryVals, true) || $key === $curTarget || ($curList !== '' && $key === $curList), 'children' => $kids, 'draft' => false];
        }
        return $out;
    }

    /**
     * Anker der Datenliste dieser Tabelle auf der Übersichtsseite (bevorzugt die mit Besucherfiltern/Suche) („#b-…“ bzw. eigener Anker des Abschnitts) – der Menüpunkt
     * springt so zur gefilterten Liste statt zum Einstieg; den festen Kopf berücksichtigt scroll-padding-top des Kits. '' ohne Liste.
     */
    private static function listAnchor(array $page, array $t): string
    {
        try {
            $first = null;
            foreach (Pages::blocks($page, false) as $raw) {
                if (!is_array($raw) || ($raw['type'] ?? '') !== 'data_list' || (string) ($raw['data']['table'] ?? '') !== $t['handle']) continue;
                $first ??= $raw;
                $dd = (array) ($raw['data'] ?? []);
                // Liste mit Besucherfiltern bzw. Suche bevorzugen – dort wirkt ?feld=wert sichtbar
                if (!empty($dd['visitor_filter']) || !empty($dd['visitor_filter2']) || !empty($dd['visitor_search'])) { $first = $raw; break; }
            }
            if ($first) {
                $a = trim((string) ($first['tunes']['section']['anchor'] ?? ''));
                $id = $a !== '' ? $a : 'b-' . (string) ($first['id'] ?? '');
                return $id !== 'b-' ? '#' . rawurlencode($id) : '';
            }
        } catch (\Throwable) {
        }
        return '';
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
        $eq('Link zur Detailseite nur, wenn gesetzt', [isset(self::validate(['mode' => 'top'])['link']), self::validate(['mode' => 'top', 'link' => 'detail'])['link'] ?? ''], [false, 'detail']);
        return ['ok' => $ok, 'fails' => $fails];
    }
}
