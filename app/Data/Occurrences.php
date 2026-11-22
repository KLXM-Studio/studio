<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
// Idee und Aufbau nach KLXM/scheduler (OccurrenceIndexer, OccurrenceQuery; MIT, © 2026 KLXM Crossmedia GmbH)
declare(strict_types=1);

namespace Core\Data;

use Core\Database;
use DateTimeImmutable;

/**
 * Vorkommens-Index für Kalender-Tabellen (Art calendar und Inhaltstabellen mit „Als Kalender nutzen“): jedes Vorkommen eines
 * Termins im Zeitfenster (WINDOW_PAST Monate zurück bis HORIZON Monate voraus) als Zeile in data_occurrences – in der Datenbank der
 * Tabelle (Website bzw. share.sqlite geteilter Tabellen, eine Pflege für alle Websites des Verbunds).
 *
 * Calendar::occurrences() fragt den Index, wenn er für die Tabelle passt (gleiche Kalender-Einstellung, gleicher Datenstand, Zeitraum
 * im Fenster), sonst rechnet es wie bisher (Rückfall – kein Bruch). Gefiltert (Status, Sprache, Felder, Suche, Quelle geteilter Tabellen,
 * Freigabe) wird über die Abfrage der Einträge (Entries::filterSql) als Unterabfrage, sortiert und gekürzt in SQL – geladen werden nur die
 * Einträge der gefundenen Vorkommen.
 *
 * Pflege: nach jedem Speichern/Löschen (Entries::save/delete → changed()), nach Schema-Änderungen (Tables::update → rebuild), täglich
 * per Cron (calendar:reindex --all, rollierendes Fenster) und von Hand. Status- oder Sprachwechsel brauchen keine Pflege (Filter über
 * die Einträge). Zeilen ohne Status/Sprache: der Index hält alle Vorkommen aller Einträge; ersetzte Vorkommen (abweichender
 * Einzeltermin, Core\Data\Series) zeigen auf den Einzeltermin.
 */
final class Occurrences
{
    public const TABLE = 'data_occurrences';
    public const META = 'data_occurrences_meta';
    /** Monate voraus bzw. zurück */
    public const HORIZON = 24;
    public const WINDOW_PAST = 12;
    /** Höchstzahl Vorkommen je Eintrag im Fenster (täglich über 36 Monate passt) */
    public const MAX_PER_ENTRY = 1500;
    /** Ändert sich die Berechnung, wird jeder Index neu aufgebaut */
    private const VERSION = 1;

    /** @var array<int, bool> Datenbanken mit angelegten Tabellen (spl_object_id) */
    private static array $ready = [];
    /** Schalter für Selbsttests: Index nicht benutzen */
    public static bool $disabled = false;

    private static function ensure(Database $db): void
    {
        $k = spl_object_id($db);
        if (isset(self::$ready[$k])) return;
        \Core\Db\Table::named(self::TABLE)->id()
            ->column('tbl', 'string', ['length' => 80, 'null' => false])
            ->column('entry_id', 'int', ['null' => false])
            ->column('series_id', 'int')
            ->column('start_ts', 'bigint', ['null' => false])
            ->column('end_ts', 'bigint', ['null' => false])
            ->column('all_day', 'int', ['default' => 0])
            ->column('has_end', 'int', ['default' => 0])
            ->column('recurring', 'int', ['default' => 0])
            ->column('cancelled', 'int', ['default' => 0])
            ->column('occ_key', 'string', ['length' => 40])
            ->column('rid', 'string', ['length' => 20])
            ->index(['tbl', 'start_ts'])
            ->index(['tbl', 'entry_id'])
            ->ensure($db);
        \Core\Db\Table::named(self::META)
            ->column('tbl', 'string', ['length' => 80, 'null' => false])
            ->column('sig', 'string', ['length' => 40])
            ->column('from_ts', 'bigint')
            ->column('to_ts', 'bigint')
            ->column('row_count', 'int')
            ->column('max_updated', 'string', ['length' => 25])
            ->column('built_at', 'string', ['length' => 25])
            ->primary(['tbl'])
            ->ensure($db);
        self::$ready[$k] = true;
    }

    /** Fenster [von, bis) des Index ab heute */
    public static function window(): array
    {
        $today = new DateTimeImmutable('today', Calendar::tz());
        return [$today->modify('first day of this month')->modify('-' . self::WINDOW_PAST . ' months'), $today->modify('first day of this month')->modify('+' . (self::HORIZON + 1) . ' months')];
    }

    /** Fingerabdruck der Berechnung: Kalender-Einstellung, Art, Felder der Zuordnung, Zeitzone */
    public static function signature(array $t): string
    {
        $c = Calendar::config($t);
        $types = [];
        foreach (['start', 'end', 'all_day', 'recurrence'] as $k) $types[$k] = $c[$k] !== '' ? (Tables::field($t, (string) $c[$k])['type'] ?? '') : '';
        return sha1(json_encode([self::VERSION, Kinds::of($t), array_intersect_key($c, array_flip(['start', 'end', 'all_day', 'recurrence', 'duration'])), $types,
            Calendar::tz()->getName()]) ?: '');
    }

    /** Datenstand der Tabelle: [Anzahl, letzte Änderung] */
    private static function stats(array $t): array
    {
        $r = Tables::db($t)->fetch("SELECT COUNT(*) AS n, MAX(updated_at) AS m FROM {$t['table']}") ?? [];
        return [(int) ($r['n'] ?? 0), (string) ($r['m'] ?? '')];
    }

    private static function meta(array $t): ?array
    {
        $db = Tables::db($t);
        self::ensure($db);
        return $db->fetch('SELECT * FROM ' . self::META . ' WHERE tbl = ?', [$t['table']]);
    }

    /** Ist der Index für die Tabelle aufgebaut und aktuell? (ohne Zeitraum) */
    public static function fresh(array $t): bool
    {
        try {
            $m = self::meta($t);
            if (!$m || $m['sig'] !== self::signature($t)) return false;  // nosemgrep: klxm-timing-safe-compare – Prüfsumme des Schemas, kein Geheimnis
            [$n, $max] = self::stats($t);
            return (int) $m['row_count'] === $n && (string) $m['max_updated'] === $max;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Index einer Tabelle neu aufbauen. @return int Anzahl Vorkommen */
    public static function rebuild(array $t): int
    {
        $db = Tables::db($t);
        self::ensure($db);
        if (!Calendar::enabled($t)) {
            self::drop($t);
            return 0;
        }
        [$from, $to] = self::window();
        $occ = Calendar::compute($t, $from, $to, ['status' => 'all', 'lang' => 'all', 'source' => 'all', 'internal' => true, 'all' => true]);
        [$n, $max] = self::stats($t);
        $db->transaction(function () use ($db, $t, $occ, $from, $to, $n, $max) {
            $db->query('DELETE FROM ' . self::TABLE . ' WHERE tbl = ?', [$t['table']]);
            self::insert($db, $t, $occ);
            $db->query('DELETE FROM ' . self::META . ' WHERE tbl = ?', [$t['table']]);
            $db->insert(self::META, ['tbl' => $t['table'], 'sig' => self::signature($t), 'from_ts' => $from->getTimestamp(), 'to_ts' => $to->getTimestamp(),
                'row_count' => $n, 'max_updated' => $max, 'built_at' => now()]);
        });
        return count($occ);
    }

    private static function insert(Database $db, array $t, array $occ): void
    {
        foreach ($occ as $o) {
            $db->insert(self::TABLE, ['tbl' => $t['table'], 'entry_id' => (int) $o['entry']['id'], 'series_id' => $o['series'], 'start_ts' => $o['start']->getTimestamp(),
                'end_ts' => $o['end']->getTimestamp(), 'all_day' => $o['all_day'] ? 1 : 0, 'has_end' => $o['has_end'] ? 1 : 0, 'recurring' => $o['recurring'] ? 1 : 0,
                'cancelled' => $o['cancelled'] ? 1 : 0, 'occ_key' => $o['key'], 'rid' => $o['rid']]);
        }
    }

    /** Index-Zeilen einer Tabelle entfernen (Kalender aus, Tabelle gelöscht) */
    public static function drop(array $t): void
    {
        try {
            $db = Tables::db($t);
            self::ensure($db);
            $db->query('DELETE FROM ' . self::TABLE . ' WHERE tbl = ?', [$t['table']]);
            $db->query('DELETE FROM ' . self::META . ' WHERE tbl = ?', [$t['table']]);
        } catch (\Throwable) {
        }
    }

    /**
     * Nach dem Speichern/Löschen eines Eintrags: Vorkommen der ganzen Serie (Kopf + Einzeltermine) neu berechnen. Ohne aufgebauten
     * Index nichts (der baut sich per Cron bzw. calendar:reindex auf); passt der Index danach nicht (andere Änderungen dazwischen), wird
     * er beim nächsten Abruf übergangen, bis er neu aufgebaut ist.
     */
    public static function changed(array $t, int $id): void
    {
        try {
            if (self::$disabled || !Calendar::enabled($t)) return;
            $m = self::meta($t);
            if (!$m) return;
            if ($m['sig'] !== self::signature($t)) { self::rebuild($t); return; }  // nosemgrep: klxm-timing-safe-compare – Prüfsumme des Schemas, kein Geheimnis
            if ($id <= 0) return;                            // nur Schema geprüft (Tables::update)
            $db = Tables::db($t);
            $row = $db->fetch("SELECT * FROM {$t['table']} WHERE id = ?", [$id]);
            $head = $row && Kinds::isCalendar($t) && (int) ($row[Series::SERIES] ?? 0) > 0 ? (int) $row[Series::SERIES] : $id;
            // gelöschter Einzeltermin: Serie aus dem Index (das Vorkommen der Serie gilt wieder)
            if (!$row) $head = (int) ($db->fetchValue('SELECT series_id FROM ' . self::TABLE . ' WHERE tbl = ? AND entry_id = ? AND series_id IS NOT NULL', [$t['table'], $id]) ?: $id);
            $ids = [$head];
            if (Kinds::isCalendar($t)) foreach ($db->fetchAll("SELECT id FROM {$t['table']} WHERE " . Series::SERIES . ' = ?', [$head]) as $r) $ids[] = (int) $r['id'];
            if ($id !== $head) $ids[] = $id;
            $from = new DateTimeImmutable('@' . (int) $m['from_ts']);
            $to = new DateTimeImmutable('@' . (int) $m['to_ts']);
            $occ = Calendar::compute($t, $from, $to, ['status' => 'all', 'lang' => 'all', 'source' => 'all', 'internal' => true, 'all' => true, 'ids' => array_values(array_unique($ids))]);
            [$n, $max] = self::stats($t);
            $db->transaction(function () use ($db, $t, $ids, $head, $occ, $n, $max) {
                $in = implode(',', array_map('intval', array_unique($ids)));
                $db->query('DELETE FROM ' . self::TABLE . " WHERE tbl = ? AND (entry_id IN ($in) OR series_id = ?)", [$t['table'], $head]);
                self::insert($db, $t, $occ);
                $db->update(self::META, ['row_count' => $n, 'max_updated' => $max], 'tbl = :tbl', ['tbl' => $t['table']]);
            });
        } catch (\Throwable $e) {
            error_log('[calendar] Index ' . $t['handle'] . ': ' . $e->getMessage());
        }
    }

    /**
     * Vorkommen aus dem Index (wie Calendar::occurrences) oder null, wenn der Index nicht passt (dann rechnet der Aufrufer).
     * @return ?list<array>
     */
    public static function query(array $t, DateTimeImmutable $from, DateTimeImmutable $to, array $opts, int $limit): ?array
    {
        if (self::$disabled || \Core\PageAccess::audience($t) !== 'all') return null;
        try {
            $m = self::meta($t);
            if (!$m || $from->getTimestamp() < (int) $m['from_ts'] || $to->getTimestamp() > (int) $m['to_ts'] || !self::fresh($t)) return null;
            $q = ['status' => $opts['status'] ?? 'published', 'where' => (array) ($opts['where'] ?? []), 'overrides' => true];
            foreach (['lang', 'ids', 'q', 'source'] as $k) if (!empty($opts[$k])) $q[$k] = $opts[$k];
            [$sql, $params] = Entries::filterSql($t, $q);
            $db = Tables::db($t);
            $rows = $db->fetchAll('SELECT * FROM ' . self::TABLE . ' WHERE tbl = ? AND start_ts < ? AND end_ts > ? AND entry_id IN (' . $sql . ')'
                . ' ORDER BY start_ts, all_day DESC, entry_id LIMIT ' . max(1, $limit), [$t['table'], $to->getTimestamp(), $from->getTimestamp(), ...$params]);
            if (!$rows) return [];
            $ids = array_values(array_unique(array_map(fn($r) => (int) $r['entry_id'], $rows)));
            $entries = [];
            foreach (Entries::query($t, ['ids' => $ids, 'status' => 'all', 'lang' => 'all', 'source' => 'all', 'internal' => true, 'overrides' => true]) as $e) $entries[$e['id']] = $e;
            $tz = Calendar::tz();
            $out = [];
            foreach ($rows as $r) {
                $e = $entries[(int) $r['entry_id']] ?? null;
                if (!$e) continue;
                $out[] = ['entry' => $e, 'start' => (new DateTimeImmutable('@' . $r['start_ts']))->setTimezone($tz), 'end' => (new DateTimeImmutable('@' . $r['end_ts']))->setTimezone($tz),
                    'all_day' => (bool) $r['all_day'], 'has_end' => (bool) $r['has_end'], 'recurring' => (bool) $r['recurring'], 'key' => (string) $r['occ_key'],
                    'cancelled' => (bool) $r['cancelled'], 'series' => $r['series_id'] !== null ? (int) $r['series_id'] : null, 'rid' => (string) $r['rid'],
                    'override' => $r['series_id'] !== null && (int) $r['series_id'] !== (int) $r['entry_id']];
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[calendar] Index ' . $t['handle'] . ' übergangen: ' . $e->getMessage());
            return null;
        }
    }

    /** Alle Kalender-Tabellen der Website (geteilte nur auf der Eigentümer-Website) neu aufbauen. @return array<string, int> */
    public static function rebuildAll(): array
    {
        $out = [];
        foreach (Calendar::tables() as $t) {
            if (Tables::isShared($t) && !Shared::isOwner($t)) continue;
            $out[$t['handle']] = self::rebuild($t);
        }
        return $out;
    }

    /** Zustand für Verwaltung/Kommandozeile: [handle => [rows, fresh, built_at]] */
    public static function status(): array
    {
        $out = [];
        foreach (Calendar::tables() as $t) {
            $m = self::meta($t);
            $out[$t['handle']] = ['rows' => $m ? (int) Tables::db($t)->fetchValue('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE tbl = ?', [$t['table']]) : 0,
                'fresh' => self::fresh($t), 'built_at' => $m['built_at'] ?? null];
        }
        return $out;
    }
}
