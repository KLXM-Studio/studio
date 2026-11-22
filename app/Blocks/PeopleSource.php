<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blocks;

use Core\Data\Entries;
use Core\Data\Exposure;
use Core\Data\Roles;
use Core\Data\Tables;
use Core\Fields;

/**
 * Kern-Block „Personen“ (Renderer app/Blocks/people.php): welche Einträge der Personen-Tabelle erscheinen.
 *
 *  - source = manual (Standard, wie bisher): die Einträge der Liste „Personen“ (Verweis entry:{tabelle}:{id}), in deren Reihenfolge,
 *    mit Funktion, Foto und Text je Stelle.
 *  - source = filter: alle veröffentlichten Einträge der Tabelle, optional nur die, deren Feld filter_field einen der Werte aus
 *    filter_values hat (mehrere Werte: einer je Zeile oder durch Komma – „oder“). Auswahlfelder vergleichen den Kurznamen oder die
 *    Beschriftung der Option, Verknüpfungen ID, Slug oder Titel, Textfelder „enthält“. Sortierung: sort_field (auch die Reihenfolge
 *    von Hand der Tabelle, Feld „sort“) und sort_dir, sonst wie in der Tabelle eingestellt; limit = Höchstzahl (0 = alle).
 *    So wird z. B. der Vorstand nur an einer Stelle gepflegt (Funktion in der Tabelle) und erscheint überall von selbst.
 * Der Seiten-Cache leert sich beim Speichern eines Eintrags ohnehin (Entries::save → PageCache::clear).
 *
 * Funktionen je Person (Adressbuch ab Grundschema 2, Core\Data\Roles): Filter nach „Listen“ bzw. „Funktionen“ mit einer Liste →
 * je Person die Funktion in dieser Liste (in position, Kits zeigen sie ohne Änderung), sortiert nach ihrer „Reihenfolge“ (wenn der
 * Block nicht selbst sortiert); beendete Funktionen fallen heraus, mit former = below erscheinen sie unter „Ehemalige“. Filter
 * „Funktionen“ ohne Wert auf der Detailseite eines Eintrags einer anderen Tabelle (z. B. Arbeitskreis): Personen mit einer Funktion,
 * die mit ihm verknüpft ist – mit dieser Funktion. Sonst zeigt jede Person auf so einer Detailseite die passende verknüpfte Funktion.
 */
final class PeopleSource
{
    /**
     * Zeilen für die Ausgabe: ['i' => Index in der Liste (nur von Hand, sonst null), 'entry' => Eintrag, 'item' => Angaben je Stelle,
     * 'former' => Ehemalige (nur Quelle „gefiltert“ mit former = below; erst die aktuellen, dann die ehemaligen)]
     * @return list<array{i: ?int, entry: array, item: array, former: bool}>
     */
    public static function rows(array $t, array $d, bool $editing = false): array
    {
        // Website-Ausgabe: Einträge ohne „Nie öffentlich“-Felder (Core\Data\Exposure)
        if (($d['source'] ?? 'manual') === 'filter') {
            $ctx = self::context($t, $d);
            $withFormer = ($d['former'] ?? '') === 'below' && (!empty($ctx['lists']) || !empty($ctx['ref_filter']));
            $rows = array_map(fn(array $e) => Exposure::publicEntry($t, $e), self::filtered($t, $d, $withFormer));
            [$cur, $former] = Roles::arrange($t, $rows, $ctx, (string) ($d['sort_field'] ?? '') === '', $withFormer);
            $limit = max(0, min(200, (int) ($d['limit'] ?? 0)));
            if ($limit && Roles::has($t)) $cur = array_slice($cur, 0, $limit);
            return [...array_map(fn(array $e) => ['i' => null, 'entry' => $e, 'item' => [], 'former' => false], $cur),
                ...array_map(fn(array $e) => ['i' => null, 'entry' => $e, 'item' => [], 'former' => true], $former)];
        }
        $out = [];
        $ctx = Roles::has($t) ? Roles::context($t, '', []) : [];
        foreach ((array) ($d['items'] ?? []) as $i => $it) {
            if (!preg_match('~^entry:([a-z][a-z0-9_]*):(\d+)$~', (string) ($it['person'] ?? ''), $m) || $m[1] !== $t['handle']) continue;
            $e = Entries::find($t, (int) $m[2]);
            if (!$e || (($e['status'] ?? 'published') !== 'published' && !$editing) || !Exposure::visible($t, $e)) continue;   // Kontakte: nur mit Freigabe
            $e = Exposure::publicEntry($t, $e);
            // Detailseite eines verknüpften Eintrags: die Funktion mit dieser Verknüpfung (Funktion „an dieser Stelle“ geht vor)
            if ($ctx) $e = Roles::arrange($t, [$e], $ctx, false)[0][0] ?? $e;
            $out[] = ['i' => (int) $i, 'entry' => $e, 'item' => (array) $it, 'former' => false];
        }
        return $out;
    }

    /** Zusammenhang für die Funktionen (Core\Data\Roles::context): Listen aus dem Filter, verknüpfter Eintrag der Detailseite */
    public static function context(array $t, array $d): array
    {
        if (!Roles::has($t)) return [];
        $pfx = $t['handle'] . '.';
        $ff = (string) ($d['filter_field'] ?? '');
        $name = str_starts_with($ff, $pfx) ? substr($ff, strlen($pfx)) : '';
        $values = self::values((string) ($d['filter_values'] ?? ''));
        $ctx = Roles::context($t, $name, $values);
        if ($name === (string) Roles::field($t)['name'] && !$values && !empty($ctx['ref'])) $ctx['ref_filter'] = true;
        return $ctx;
    }

    /** Einträge bei source = filter (nur veröffentlichte); $former: auch Personen, deren Funktion in der Liste beendet ist */
    public static function filtered(array $t, array $d, bool $former = false): array
    {
        $pfx = $t['handle'] . '.';
        $o = ['status' => 'published'];
        $sf = (string) ($d['sort_field'] ?? '');
        if (str_starts_with($sf, $pfx)) $o['sort'] = substr($sf, strlen($pfx));
        if (in_array($d['sort_dir'] ?? '', ['asc', 'desc'], true)) $o['dir'] = (string) $d['sort_dir'];
        $limit = max(0, min(200, (int) ($d['limit'] ?? 0)));
        // Funktionen: Anzahl erst nach dem Ordnen (beendete fallen weg, Reihenfolge der Funktion) – rows() begrenzt
        if ($limit && !Roles::has($t)) $o['limit'] = $limit;

        $ff = (string) ($d['filter_field'] ?? '');
        $field = str_starts_with($ff, $pfx) ? Tables::field($t, substr($ff, strlen($pfx))) : null;
        $values = self::values((string) ($d['filter_values'] ?? ''));
        $rf = Roles::field($t);
        // Funktionen ohne Wert auf der Detailseite eines anderen Eintrags: Personen mit einer Funktion, die mit ihm verknüpft ist
        if ($field && $rf && $field['name'] === $rf['name'] && !$values) {
            $ref = self::context($t, $d)['ref'] ?? null;
            if ($ref === null) return [];
            $o['where'] = [[$rf['name'], '=', $ref]];
            return Entries::query($t, $o);
        }
        if ($field && $values) {
            // „oder“ über mehrere Werte: je Wert die passenden IDs, dann gemeinsam sortiert und begrenzt
            $ids = [];
            foreach ($values as $v) {
                [$op, $v] = self::condition($field, $v);
                foreach (Entries::query($t, ['status' => 'published', 'where' => [[$field['name'], $op, $v]]]) as $e) $ids[(int) $e['id']] = true;
                // Liste als Wert: auch Personen mit einer (beendeten) Funktion in dieser Liste – für „Ehemalige“
                if ($former && $rf && in_array($field['name'], ['listen', $rf['name']], true) && ($k = Roles::listKey($t, $v)) !== null) {
                    foreach (Entries::query($t, ['status' => 'published', 'where' => [[$rf['name'], '=', $k]]]) as $e) $ids[(int) $e['id']] = true;
                }
            }
            if (!$ids) return [];
            // 'ids' gilt sonst für alle Sprachen – die Teilabfragen haben die Sprache schon beachtet
            $o['ids'] = array_keys($ids);
        }
        return Entries::query($t, $o);
    }

    /** Werte aus dem Textfeld: je Zeile oder durch Komma/Semikolon getrennt, ohne Leerzeichen am Rand, ohne Doppelte */
    public static function values(string $raw): array
    {
        $out = [];
        foreach (preg_split('~[\r\n,;]+~', $raw) ?: [] as $v) {
            $v = trim($v);
            if ($v !== '' && !in_array($v, $out, true)) $out[] = $v;
        }
        return array_slice($out, 0, 30);
    }

    /**
     * Bedingung je Feldtyp: Auswahl (auch Beschriftung statt Kurzname), Verknüpfung, Ja/Nein, Zahl/Datum = gleich; Text = enthält.
     * @return array{0: string, 1: string}
     */
    public static function condition(array $field, string $v): array
    {
        $type = (string) ($field['type'] ?? 'text');
        if (in_array($type, ['select', 'multiselect'], true)) {
            $opts = Fields::options($field);
            if (!array_key_exists($v, $opts)) {
                foreach ($opts as $k => $label) {
                    if (mb_strtolower(trim((string) $label)) === mb_strtolower($v) || mb_strtolower((string) $k) === mb_strtolower($v)) { $v = (string) $k; break; }
                }
            }
            return ['=', $v];
        }
        if ($type === 'bool') return ['=', in_array(mb_strtolower($v), ['1', 'ja', 'yes', 'true', 'an'], true) ? '1' : '0'];
        if (in_array($type, ['relation', 'relations', 'number', 'date', 'datetime', 'email', 'tel', 'url'], true)) return ['=', $v];
        return ['contains', $v];
    }
}
