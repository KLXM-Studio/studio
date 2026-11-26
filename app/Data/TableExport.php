<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * Einträge einer Datentabelle exportieren (CSV oder XLSX, Core\Data\Tabular) – Recht data.export.
 * Spalten wählbar; Werte lesbar: Auswahlfelder als Beschriftung, Verknüpfungen als Titel (mehrere mit „; “), Datum wie in der
 * Verwaltung, Rich-Text als Klartext, Ja/Nein, Bilder/Dateien als Adresse. Interne Felder („nie öffentlich“) nur für die Administration.
 * Die erste Spalte ist immer „ID“ (für einen späteren Import „vorhandene aktualisieren“).
 */
final class TableExport
{
    public const SYSTEM = ['slug' => 'Adresse (Slug)', 'status' => 'Status', 'published_at' => 'Veröffentlicht am', 'created_at' => 'Angelegt', 'updated_at' => 'Geändert'];
    private const SKIP = ['section', 'note', 'blocks', 'repeater', 'group', 'secret', 'hotspots', 'mediatext', 'collection'];

    /** Exportierbare Spalten [name => Beschriftung] (ohne ID) */
    public static function columns(array $t): array
    {
        $admin = can('*') || in_array('*', (array) (app()->auth->role()['permissions'] ?? []), true);
        $out = [];
        foreach ($t['fields'] as $f) {
            if (in_array($f['type'], self::SKIP, true) || Tables::isLayout($f) || !empty($f['legacy'])) continue;
            if (!$admin && Exposure::isPrivate($f)) continue;   // interne Felder nur für die Administration
            $out[(string) $f['name']] = (string) $f['label'];
        }
        foreach (self::SYSTEM as $k => $l) $out[$k] = __($l);
        return $out;
    }

    /**
     * Datei erzeugen. $cols: Spaltennamen (leer = alle), $format: csv | csv-comma | xlsx
     * @return array{body: string, name: string, type: string, rows: int}
     */
    public static function build(array $t, array $rows, array $cols, string $format): array
    {
        $avail = self::columns($t);
        $cols = array_values(array_filter($cols ?: array_keys($avail), fn($c) => isset($avail[$c])));
        $out = [array_merge([__('ID')], array_map(fn($c) => $avail[$c], $cols))];
        $relCache = [];
        foreach ($rows as $e) {
            $line = [(string) (int) $e['id']];
            foreach ($cols as $c) $line[] = self::value($t, $e, $c, $relCache);
            $out[] = $line;
        }
        $base = preg_replace('~[^a-z0-9_-]+~i', '-', (string) $t['handle']) . '-' . date('Y-m-d');
        return $format === 'xlsx'
            ? ['body' => Tabular::xlsx($out, (string) $t['name']), 'name' => $base . '.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'rows' => count($rows)]
            : ['body' => Tabular::csv($out, $format === 'csv-comma' ? ',' : ';'), 'name' => $base . '.csv', 'type' => 'text/csv; charset=utf-8', 'rows' => count($rows)];
    }

    /** Ein Wert lesbar */
    private static function value(array $t, array $e, string $c, array &$relCache): string
    {
        if (isset(self::SYSTEM[$c])) {
            $v = (string) ($e[$c] ?? '');
            if ($c === 'status') return $v === 'published' ? __('Online') : __('Offline');
            return in_array($c, ['published_at', 'created_at', 'updated_at'], true) && $v !== '' ? fmt()->datetime($v) : $v;
        }
        $f = Tables::field($t, $c);
        if (!$f) return '';
        $v = $e[$c] ?? null;
        if ($v === null || $v === '' || $v === []) return '';
        $titles = function (array $ids) use ($f, &$relCache): string {
            $target = Tables::findContent((string) ($f['target'] ?? ''));
            if (!$target) return implode('; ', $ids);
            $relCache[$target['handle']] ??= Entries::titles($target);
            return implode('; ', array_map(fn($id) => (string) ($relCache[$target['handle']][(int) $id] ?? $id), $ids));
        };
        return match ($f['type']) {
            'select' => Tables::optionLabel($f, (string) $v),
            'multiselect' => implode('; ', array_map(fn($k) => Tables::optionLabel($f, (string) $k), is_array($v) ? $v : (array) json_decode((string) $v, true))),
            'relation' => $titles([(int) $v]),
            'relations' => $titles(array_map('intval', (array) $v)),
            'bool' => $v ? __('Ja') : __('Nein'),
            'date' => fmt()->date((string) $v),
            'datetime' => fmt()->datetime((string) $v),
            'richtext', 'inline' => trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>'], ["\n", "\n", "\n"], (string) $v)), ENT_QUOTES | ENT_HTML5)) ?? ''),
            'media', 'file' => ($m = \Core\Media::find((int) $v)) ? absolute_url(\Core\Media::url($m)) : '',
            default => is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE),
        };
    }
}
