<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * Löschen mit Neuzuordnung: Verweisen andere Einträge (Verknüpfungsfelder relation/relations) auf einen Eintrag, der gelöscht wird,
 * lassen sie sich vorher einem anderen Eintrag derselben Tabelle zuordnen – statt dass die Verknüpfung still verschwindet
 * (Entries::delete leert übrig gebliebene Verweise wie bisher).
 *  - refs(): je verweisendem Feld die Einträge (nur eigene, keine aus externen Quellen; Einträge, die mitgelöscht werden, nicht).
 *  - delete(): erst umhängen (Entries::save, Teilaktualisierung mit Versionsnotiz), dann löschen. $map: „tabelle.feld“ => Ziel-ID
 *    (0/fehlend = leer lassen); Ziel muss in derselben Tabelle existieren und darf nicht mitgelöscht werden.
 * Verwaltung (Einzel- und Mehrfachlöschen, resources/js/_reassign.js), CLI data:delete --reassign=ID, API/MCP reassign.
 */
final class Reassign
{
    private const LIMIT = 5000;

    /**
     * Verweise auf $ids. @return list<array{key: string, table: array, field: array, label: string, rows: list<int>, count: int}>
     */
    public static function refs(array $t, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $out = [];
        foreach (Tables::referencing($t) as [$s, $f]) {
            $rows = [];
            foreach ($ids as $id) {
                foreach (Entries::query($s, ['status' => 'all', 'lang' => 'all', 'where' => [[$f['name'], '=', (string) $id]], 'limit' => self::LIMIT, 'source' => 'all']) as $r) {
                    $rid = (int) $r['id'];
                    if (isset($rows[$rid]) || ($s['handle'] === $t['handle'] && in_array($rid, $ids, true)) || !Backlinks::rowEditable($s, $r)) continue;
                    $rows[$rid] = $rid;
                }
            }
            if (!$rows) continue;
            $out[] = ['key' => $s['handle'] . '.' . $f['name'], 'table' => $s, 'field' => $f, 'rows' => array_values($rows), 'count' => count($rows),
                'label' => count($rows) === 1
                    ? __('1 Eintrag in „{table}“ (Feld {field}) verweist darauf', ['table' => $s['name'], 'field' => $f['label']])
                    : __('{n} Einträge in „{table}“ (Feld {field}) verweisen darauf', ['n' => count($rows), 'table' => $s['name'], 'field' => $f['label']])];
        }
        return $out;
    }

    /** Ziel prüfen: Fehlertext oder null */
    public static function checkTarget(array $t, array $ids, int $target): ?string
    {
        if (in_array($target, array_map('intval', $ids), true)) return __('Bitte einen Eintrag wählen, der nicht mitgelöscht wird.');
        if (!Entries::find($t, $target)) return __('Der gewählte Eintrag existiert nicht.');
        return null;
    }

    /**
     * Umhängen und löschen. $map: „tabelle.feld“ => Ziel-ID (Schlüssel '*' = für alle Felder).
     * @return array{deleted: int, moved: int, errors: list<string>}
     */
    public static function delete(array $t, array $ids, array $map, bool $deleteNow = true): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $moved = 0;
        $errors = [];
        if (array_filter($map)) {
            foreach (self::refs($t, $ids) as $g) {
                $target = (int) ($map[$g['key']] ?? $map['*'] ?? 0);
                if ($target < 1) continue;
                if (($err = self::checkTarget($t, $ids, $target)) !== null) { $errors[] = $err; continue; }
                $s = $g['table'];
                $f = $g['field'];
                $note = __('Neu zugeordnet: „{old}“ gelöscht, jetzt „{new}“', ['old' => self::titles($t, $ids), 'new' => self::titles($t, [$target])]);
                foreach ($g['rows'] as $rid) {
                    $row = Entries::find($s, $rid);
                    if (!$row) continue;
                    if ($f['type'] === 'relation') {
                        $val = $target;
                    } else {
                        $cur = array_values(array_map('intval', (array) ($row[$f['name']] ?? [])));
                        $val = array_values(array_unique([...array_filter($cur, fn($x) => !in_array($x, $ids, true)), $target]));
                    }
                    [, $errs] = Entries::save($s, $rid, [$f['name'] => $val], $note);
                    if ($errs) $errors[] = Entries::title($s, $row) . ': ' . implode(' ', array_map('strval', $errs));
                    else $moved++;
                }
            }
        }
        // Fehler beim Umhängen: nicht löschen (sonst gingen die Verweise doch verloren)
        if ($errors || !$deleteNow) return ['deleted' => 0, 'moved' => $moved, 'errors' => $errors];
        foreach ($ids as $id) Entries::delete($t, $id);
        return ['deleted' => count($ids), 'moved' => $moved, 'errors' => []];
    }

    private static array $titleCache = [];

    private static function titles(array $t, array $ids): string
    {
        $out = [];
        foreach (array_slice($ids, 0, 3) as $id) {
            $k = $t['handle'] . ':' . $id;
            self::$titleCache[$k] ??= ($e = Entries::find($t, (int) $id)) ? Entries::title($t, $e) : '#' . $id;
            $out[] = self::$titleCache[$k];
        }
        return implode(', ', $out) . (count($ids) > 3 ? ' …' : '');
    }

    /** Für die Oberfläche (JSON) */
    public static function payload(array $t, array $ids): array
    {
        return array_map(fn($g) => ['key' => $g['key'], 'label' => $g['label'], 'count' => $g['count'], 'table' => $g['table']['name'], 'field' => (string) $g['field']['label']], self::refs($t, $ids));
    }
}
