<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * Filter der Eintragsliste in der Verwaltung, je Tabelle einstellbar (Felder & Einstellungen → „Filter in der Eintragsliste“,
 * settings.list_filters = Liste von Feldnamen). Je Feldtyp:
 *  - Auswahl, Mehrfachauswahl, Verknüpfung (ein/mehrere), Ja/Nein: Auswahlliste („Alle“ + Werte bzw. verknüpfte Einträge)
 *  - Datum, Datum + Uhrzeit (auch Beginn eines Kalenders): Bereich „von … bis“ (beide optional, „bis“ einschließlich)
 * Adresse: ?f[feld]=wert bzw. ?f[feld][von]=JJJJ-MM-TT&f[feld][bis]=… – Bedingungen für Entries::query über 'where'.
 */
final class ListFilters
{
    public const TYPES = ['select', 'multiselect', 'relation', 'relations', 'bool', 'date', 'datetime'];
    public const MAX = 6;

    /** Felder, die sich als Filter eignen [name => Beschriftung] */
    public static function candidates(array $t): array
    {
        $out = [];
        foreach ($t['fields'] as $f) {
            if (in_array($f['type'], self::TYPES, true) && empty($f['legacy'])) $out[(string) $f['name']] = (string) $f['label'];
        }
        return $out;
    }

    /** Einstellung bereinigen: nur geeignete Felder, höchstens MAX @return list<string> */
    public static function validate(mixed $in, array $fields): array
    {
        $ok = [];
        foreach ($fields as $f) if (in_array($f['type'] ?? '', self::TYPES, true)) $ok[] = (string) $f['name'];
        return array_slice(array_values(array_unique(array_intersect(array_map('strval', (array) $in), $ok))), 0, self::MAX);
    }

    /** Eingestellte Filter mit Feld und Optionen @return list<array{name: string, label: string, kind: string, options: array<string, string>}> */
    public static function active(array $t): array
    {
        $out = [];
        foreach ((array) ($t['settings']['list_filters'] ?? []) as $name) {
            $f = Tables::field($t, (string) $name);
            if (!$f || !in_array($f['type'], self::TYPES, true)) continue;
            $kind = in_array($f['type'], ['date', 'datetime'], true) ? 'range' : 'pick';
            $opts = [];
            if ($kind === 'pick') {
                $opts = match ($f['type']) {
                    'bool' => ['1' => __('Ja'), '0' => __('Nein')],
                    'relation', 'relations' => ($target = Tables::findContent((string) ($f['target'] ?? ''))) ? array_map('strval', Entries::titles($target)) : [],
                    default => array_map('strval', \Core\Fields::options($f)),
                };
            }
            $out[] = ['name' => (string) $f['name'], 'label' => (string) $f['label'], 'kind' => $kind, 'options' => array_map('strval', $opts)];
        }
        return $out;
    }

    /** Auswahl aus der Adresse (?f[…]) – nur gültige Werte @return array<string, string|array{von: string, bis: string}> */
    public static function selection(array $t, array $query): array
    {
        $in = is_array($query['f'] ?? null) ? $query['f'] : [];
        $sel = [];
        foreach (self::active($t) as $fl) {
            $v = $in[$fl['name']] ?? null;
            if ($fl['kind'] === 'range') {
                $von = is_array($v) && preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($v['von'] ?? '')) ? (string) $v['von'] : '';
                $bis = is_array($v) && preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($v['bis'] ?? '')) ? (string) $v['bis'] : '';
                if ($von !== '' || $bis !== '') $sel[$fl['name']] = ['von' => $von, 'bis' => $bis];
            } elseif (is_string($v) && $v !== '' && isset($fl['options'][$v])) {
                $sel[$fl['name']] = $v;
            }
        }
        // Verknüpfungsfelder auch ohne eingerichteten Filter (?f[feld]=ID, z. B. „In der Liste öffnen“ der Rückverknüpfung)
        foreach ($t['fields'] as $f) {
            $v = $in[$f['name']] ?? null;
            if (!isset($sel[$f['name']]) && in_array($f['type'], ['relation', 'relations'], true) && is_string($v) && ctype_digit($v) && (int) $v > 0) $sel[(string) $f['name']] = $v;
        }
        return $sel;
    }

    /**
     * Gewählte Filter, die nicht als Listenfilter eingerichtet sind – für einen Hinweis über der Liste
     * @return list<array{name: string, label: string, value: string}>
     */
    public static function extra(array $t, array $sel): array
    {
        $active = array_column(self::active($t), 'name');
        $out = [];
        foreach ($sel as $name => $v) {
            if (in_array($name, $active, true) || is_array($v) || !($f = Tables::field($t, (string) $name))) continue;
            $target = Tables::findContent((string) ($f['target'] ?? ''));
            $e = $target ? Entries::find($target, (int) $v) : null;
            $out[] = ['name' => (string) $name, 'label' => (string) $f['label'], 'value' => $e && $target ? Entries::title($target, $e) : (string) $v];
        }
        return $out;
    }

    /** Bedingungen für Entries::query ('where') @return list<array{0: string, 1: string, 2: mixed}> */
    public static function where(array $sel): array
    {
        $w = [];
        foreach ($sel as $name => $v) {
            if (is_array($v)) {
                if ($v['von'] !== '') $w[] = [$name, '>=', $v['von']];
                if ($v['bis'] !== '') $w[] = [$name, '<=', $v['bis'] . ' 23:59:59'];   // „bis“ einschließlich (auch mit Uhrzeit)
            } else {
                $w[] = [$name, '=', $v];
            }
        }
        return $w;
    }
}
