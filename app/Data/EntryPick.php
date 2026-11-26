<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * Feldtyp „entrypick“: einzelne Einträge aus einer oder mehreren Tabellen in fester Reihenfolge – z. B. „Ausgewählte Einträge“ im
 * Block „Highlights“ und im Einstieg als Slider. Wert: Liste „tabelle:id“ (JSON im Block). Auswahl über das Auswahlfenster
 * (resources/js/_picker.js, Core\Data\Picker) mit Tabellenwahl; Chips lassen sich ziehen (Alt+Pfeil per Tastatur).
 *
 * Feldoptionen: 'tables' => 'featured' (Tabellen mit „Hervorheben“) | Liste von Handles | fehlt (alle Inhaltstabellen), 'max' (Standard 12).
 * Ausgabe: items() liefert nur veröffentlichte, für Besucher sichtbare Einträge – im Format von Featured::items.
 */
final class EntryPick
{
    public const TYPE = 'entrypick';
    private const REF = '~^([a-z][a-z0-9_]{0,40}):(\d{1,10})$~';

    /** Wert bereinigen @return list<string> */
    public static function clean(array $f, mixed $raw): array
    {
        $max = max(1, min(48, (int) ($f['max'] ?? 12)));
        $vals = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? (array) (json_decode($raw, true) ?? explode(',', $raw)) : []);
        $out = [];
        foreach ($vals as $v) {
            if (is_string($v) && preg_match(self::REF, trim($v)) && !in_array(trim($v), $out, true)) $out[] = trim($v);
        }
        return array_slice($out, 0, $max);
    }

    /** Tabellen, aus denen das Feld wählt (nur mit Recht data.edit) @return list<array> */
    public static function tables(array $f): array
    {
        $want = $f['tables'] ?? null;
        $out = [];
        foreach (Tables::content() as $t) {
            if (Tables::isInbox($t) || !can('data.edit', $t['handle'])) continue;
            if ($want === 'featured' && !Featured::enabled($t)) continue;
            if (is_array($want) && !in_array($t['handle'], $want, true)) continue;
            $out[] = $t;
        }
        return $out;
    }

    /** Feld rendern (Core\Fields::renderField) */
    public static function render(array $f, mixed $value, string $id, string $inputName, string $label, string $help, string $errHtml, string $describedBy, string $width): string
    {
        $refs = self::clean($f, $value);
        $tables = array_map(fn($t) => ['handle' => $t['handle'], 'name' => (string) $t['name'], 'singular' => (string) $t['singular'],
            'url' => url('/admin/api/picker/' . $t['handle']), 'create' => url('/admin/data/' . $t['handle'] . '/quick')], self::tables($f));
        $h = '<fieldset class="f f--entrypick' . $width . ($errHtml !== '' ? ' f--error' : '') . '" id="' . e($id) . '" data-entrypick data-name="' . e($inputName) . '"'
            . ' data-max="' . max(1, min(48, (int) ($f['max'] ?? 12))) . '" data-tables="' . e((string) json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"'
            . ($describedBy ? ' aria-describedby="' . e($describedBy) . '"' : '') . '><legend>' . $label . '</legend>'
            . '<input type="hidden" name="' . e($inputName) . '[]" value="">'
            . '<ul class="pgf__chips relp__chips" data-ep-chips aria-label="' . e(__('Ausgewählt')) . '">';
        foreach (self::labels($refs) as $ref => $it) {
            $h .= '<li class="pgf__chip relp__chip' . ($it === null ? ' is-missing' : '') . '" data-value="' . e($ref) . '" draggable="true">'
                . '<button type="button" class="relp__grip" data-ep-move aria-label="' . e(__('„{label}“ verschieben (Alt+Pfeil hoch/runter)', ['label' => $it['title'] ?? $ref])) . '" title="' . e(__('Ziehen oder Alt+Pfeil hoch/runter')) . '">⋮⋮</button>'
                . (!empty($it['thumb']) ? '<img class="relp__thumb" src="' . e($it['thumb']) . '" alt="" loading="lazy">' : '')
                . '<span class="pgf__txt"><span class="pgf__label">' . e($it['title'] ?? __('Eintrag nicht gefunden')) . '</span>'
                . '<span class="pgf__trail">' . e($it === null ? $ref : implode(' · ', array_filter([$it['table_name'], $it['sub'], $it['status'] === 'draft' ? __('Entwurf') : '']))) . '</span></span>'
                . '<button type="button" class="pgf__x" data-ep-remove aria-label="' . e(__('„{label}“ entfernen', ['label' => $it['title'] ?? $ref])) . '" title="' . e(__('Entfernen')) . '">×</button>'
                . '<input type="hidden" name="' . e($inputName) . '[]" value="' . e($ref) . '"></li>';
        }
        $h .= '</ul><p class="pgf__empty f-help" data-ep-empty' . ($refs ? ' hidden' : '') . '>' . e(__('Keine Einträge ausgewählt.')) . '</p>'
            . '<p class="pgf__actions">' . ($tables ? '<button type="button" class="adm-btn adm-btn--small" data-ep-pick aria-haspopup="dialog">+ ' . e(__('Einträge wählen …')) . '</button>' : '<span class="f-help">' . e(__('Keine passende Tabelle.')) . '</span>')
            . ' <span class="pgf__count f-help" data-ep-count aria-live="polite">' . ($refs ? e(__('{n} ausgewählt', ['n' => count($refs)])) : '') . '</span></p>'
            . $help . $errHtml . '</fieldset>';
        return $h;
    }

    /** Beschriftungen für die Chips (Verwaltung, auch Entwürfe) @return array<string, ?array> */
    public static function labels(array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            [$h, $id] = explode(':', $ref) + ['', '0'];
            $t = Tables::findContent($h);
            $e = $t ? Entries::find($t, (int) $id) : null;
            $out[$ref] = $t && $e ? Picker::item($t, $e) + ['table_name' => (string) $t['name']] : null;
        }
        return $out;
    }

    /**
     * Einträge für die Website: nur veröffentlicht und für Besucher sichtbar, Reihenfolge wie gewählt; $exclude wie Featured::items
     * @return list<array{table: array, entry: array, badge: string, href: ?string, date: string, order: ?int}>
     */
    public static function items(array $refs, array $exclude = []): array
    {
        $items = [];
        foreach (self::clean(['max' => 48], $refs) as $ref) {
            [$h, $id] = explode(':', $ref);
            $t = Tables::findContent($h);
            if (!$t || in_array((int) $id, array_map('intval', (array) ($exclude[$t['handle']] ?? [])), true)) continue;
            $rows = Exposure::publicRows($t, Entries::query($t, ['status' => 'published', 'ids' => [(int) $id], 'limit' => 1]));
            if (!$rows) continue;
            $e = $rows[0];
            $items[] = ['table' => $t, 'entry' => $e, 'badge' => (string) $t['name'], 'href' => Entries::href($t, $e),
                'date' => (string) (($e['published_at'] ?? '') ?: ($e['created_at'] ?? '')), 'order' => null];
        }
        return $items;
    }
}
