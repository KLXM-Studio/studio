<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * Rückverknüpfungen: Für jedes Verknüpfungsfeld (relation, relations) einer Tabelle S, das auf die Tabelle T zeigt, zeigt das
 * Formular eines T-Eintrags die Gegenrichtung („Termine dieses Workshops“) – ohne zweite Datenhaltung: gespeichert bleibt die
 * Verknüpfung am S-Eintrag.
 *  - Je Relationsfeld abschaltbar (Feld-Einstellung backlink = false) und mit eigener Beschriftung (backlink_label).
 *  - Kalender-Tabellen nach Beginn (kommende aufsteigend, vergangene absteigend und eingeklappt), sonst Sortierung der Tabelle.
 *  - Verknüpfen / Lösen setzt bzw. leert das Feld am S-Eintrag (Entries::save, Teilaktualisierung: Versionen, Regeln, Sperren
 *    gelten). Recht data.edit auf S; fremde Einträge geteilter Tabellen und Einträge aus externen Quellen nur lesend.
 * Ansicht: app/Admin/views/data/_backlinks.php (Hauptbereich) und Kurzfassung „Verknüpft mit“ in der Seitenleiste; Skript
 * resources/js/_backlinks.js; Endpunkt POST /admin/api/backlinks/{handle}/{id} (Admin\BacklinkController).
 */
final class Backlinks
{
    /** sichtbare Einträge je Gruppe, bevor „Alle … anzeigen“ aufklappt */
    public const VISIBLE = 5;
    private const LIMIT = 500;

    /** Rückverknüpfung für dieses Feld eingeschaltet? (Standard an) */
    public static function enabled(array $f): bool
    {
        return ($f['backlink'] ?? true) !== false;
    }

    /** Beschriftung der Gegenrichtung: eigene oder „{Tabelle} · {Feld}“ */
    public static function label(array $source, array $f): string
    {
        $own = trim((string) ($f['backlink_label'] ?? ''));
        return $own !== '' ? $own : (string) $source['name'] . ' · ' . (string) $f['label'];
    }

    /**
     * Gruppen für einen Eintrag der Tabelle $t.
     * @param bool $all auch abgeschaltete Felder (Kurzfassung in der Seitenleiste)
     * @return list<array{table: array, field: array, label: string, key: string, rows: list<array>, upcoming: list<array>, past: list<array>,
     *   calendar: bool, total: int, editable: bool, enabled: bool, list_url: string}>
     */
    public static function groups(array $t, int $id, bool $all = false): array
    {
        $out = [];
        foreach (Tables::referencing($t) as [$s, $f]) {
            $on = self::enabled($f);
            if (!$on && !$all) continue;
            $rows = Entries::query($s, ['status' => 'all', 'where' => [[$f['name'], '=', (string) $id]], 'limit' => self::LIMIT, 'source' => 'all']);
            $cal = Calendar::enabled($s);
            $up = $past = [];
            if ($cal) {
                $now = new \DateTimeImmutable('now');
                foreach ($rows as $r) {
                    $span = Calendar::span($s, $r);
                    $r['_ts'] = $span ? $span['start']->getTimestamp() : 0;
                    $r['_date'] = $span ? ($span['all_day'] ? fmt()->date($span['start']->format('Y-m-d')) : fmt()->datetime($span['start']->format('Y-m-d H:i:s'))) : '';
                    $end = $span ? ($span['has_end'] ? $span['end'] : $span['start']->setTime(0, 0)->modify('+1 day')) : null;
                    if ($end === null || $end > $now) $up[] = $r; else $past[] = $r;
                }
                usort($up, fn($a, $b) => $a['_ts'] <=> $b['_ts']);
                usort($past, fn($a, $b) => $b['_ts'] <=> $a['_ts']);
            } else {
                $up = $rows;
            }
            $editable = can('data.edit', $s['handle']) && !Tables::isInbox($s);
            $out[] = ['table' => $s, 'field' => $f, 'label' => self::label($s, $f), 'key' => $s['handle'] . '.' . $f['name'],
                'rows' => $rows, 'upcoming' => $up, 'past' => $past, 'calendar' => $cal, 'total' => count($rows), 'editable' => $editable, 'enabled' => $on,
                'list_url' => url('/admin/data/' . $s['handle']) . '?' . http_build_query(['f' => [$f['name'] => $id]] + ($cal ? ['zeit' => 'alle'] : []))];
        }
        return $out;
    }

    /** Darf diese Zeile hier gelöst werden? (eigener Eintrag, nicht aus externer Quelle) */
    public static function rowEditable(array $s, array $row): bool
    {
        return !Shared::isForeign($s, $row) && !\Core\Sources\Sources::isExternal($s, (int) $row['id']) && Network::external($row) === null;
    }

    /** Kurzfassung einer Gruppe: „3 kommende, 34 vergangene“ bzw. „12 Einträge“ */
    public static function summary(array $g): string
    {
        if ($g['calendar'] && $g['past']) {
            return __('{up} kommende, {past} vergangene', ['up' => count($g['upcoming']), 'past' => count($g['past'])]);
        }
        return $g['total'] === 1 ? __('1 Eintrag') : __('{n} Einträge', ['n' => $g['total']]);
    }

    /**
     * Verknüpfen bzw. lösen: setzt am Eintrag $rowId der Tabelle $s das Feld $field auf $targetId (relation) bzw. ergänzt/entfernt
     * $targetId (relations). @return ?string Fehlertext
     */
    public static function apply(array $s, string $field, int $rowId, int $targetId, bool $link): ?string
    {
        $f = Tables::field($s, $field);
        if (!$f || !in_array($f['type'], ['relation', 'relations'], true)) return __('Unbekanntes Verknüpfungsfeld.');
        $row = Entries::find($s, $rowId);
        if (!$row) return __('Eintrag nicht gefunden.');
        if (!self::rowEditable($s, $row)) return __('Dieser Eintrag lässt sich hier nicht ändern.');
        if ($f['type'] === 'relation') {
            $cur = (int) ($row[$field] ?? 0);
            if (!$link && $cur !== $targetId) return null;   // schon gelöst
            $val = $link ? $targetId : null;
        } else {
            $ids = array_values(array_map('intval', (array) ($row[$field] ?? [])));
            $ids = $link ? array_values(array_unique([...$ids, $targetId])) : array_values(array_filter($ids, fn($x) => $x !== $targetId));
            $val = $ids;
        }
        [, $errors] = Entries::save($s, $rowId, [$field => $val], $link ? __('Verknüpft (Rückverknüpfung)') : __('Verknüpfung gelöst (Rückverknüpfung)'));
        return $errors ? implode(' ', array_map('strval', $errors)) : null;
    }
}
