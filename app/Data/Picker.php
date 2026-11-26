<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Media;

/**
 * Auswahlfenster für Einträge (resources/js/_picker.js) – ein Fenster für alle Verweise auf Einträge: Verknüpfungsfelder
 * („Zugehörige Termine“), Einträge im Slider und in „Highlights“ (Feldtyp 'entrypick').
 *
 *  - list(): Suche im Titel, Filter Status, Zeitraum (Kalender-Tabellen: kommende, vergangene, Monat) und je ein Auswahlfeld der
 *    Tabelle (z. B. Thema), sortiert wie die Tabelle (Kalender: kommende zuerst), 30 je Seite. Je Eintrag Titel, Zeile darunter
 *    (Datum bzw. Kategorie), Status und Vorschaubild.
 *  - preview(): kurze Vorschau (Bild, Datum, Anriss, Status, Links „Bearbeiten“ und „Ansehen“).
 * Rechte prüft der Aufrufer (DataController::picker): data.edit der Ziel-Tabelle.
 */
final class Picker
{
    public const PER_PAGE = 30;
    private const SCAN = 2000;

    /** Filter, die das Fenster für die Tabelle anbietet @return list<array{name: string, label: string, options: array<string, string>}> */
    public static function filters(array $t): array
    {
        $out = [];
        foreach ($t['fields'] as $f) {
            if (!in_array($f['type'], ['select', 'multiselect'], true) || !empty($f['relation'])) continue;
            $opts = \Core\Fields::options($f);
            if (count($opts) < 2 || count($opts) > 40) continue;
            // gleiche Beschriftung (z. B. altes Feld neben dem neuen): nur das erste
            if (in_array((string) $f['label'], array_column($out, 'label'), true)) continue;
            $out[] = ['name' => (string) $f['name'], 'label' => (string) $f['label'], 'options' => array_map('strval', $opts)];
            if (count($out) === 2) break;
        }
        return $out;
    }

    /**
     * $o: q, status (all|published|draft), when (all|upcoming|past|YYYY-MM, nur Kalender), filter ([feld => wert]), page, ids (nur diese)
     * @return array{items: list<array>, total: int, page: int, pages: int, calendar: bool, filters: list<array>, months: array<string, string>}
     */
    public static function list(array $t, array $o = []): array
    {
        $cal = Calendar::enabled($t);
        $q = mb_strtolower(trim((string) ($o['q'] ?? '')));
        $status = in_array($o['status'] ?? '', ['published', 'draft'], true) ? (string) $o['status'] : 'all';
        $when = (string) ($o['when'] ?? ($cal ? 'upcoming' : 'all'));
        $filters = self::filters($t);
        $want = [];
        foreach ($filters as $fl) {
            $v = (string) (($o['filter'] ?? [])[$fl['name']] ?? '');
            if ($v !== '' && isset($fl['options'][$v])) $want[$fl['name']] = $v;
        }
        $ids = isset($o['ids']) ? array_flip(array_map('intval', (array) $o['ids'])) : null;
        $today = new \DateTimeImmutable('today');
        $rows = [];
        $months = [];
        foreach (Entries::query($t, ['status' => 'all', 'limit' => self::SCAN, 'source' => 'all']) as $e) {
            if ($ids !== null && !isset($ids[(int) $e['id']])) continue;
            $span = $cal ? Calendar::span($t, $e) : null;
            if ($span) $months[$span['start']->format('Y-m')] = true;
            if ($ids === null) {
                if ($status !== 'all' && ($e['status'] ?? '') !== $status) continue;
                if ($q !== '' && !str_contains(mb_strtolower(Entries::title($t, $e)), $q)) continue;
                foreach ($want as $name => $v) {
                    $val = $e[$name] ?? null;
                    $vals = is_array($val) ? $val : (is_string($val) && str_starts_with($val, '[') ? (array) json_decode($val, true) : [$val]);
                    if (!in_array($v, array_map('strval', $vals), true)) continue 2;
                }
                if ($cal) {
                    $end = $span ? ($span['has_end'] ? $span['end'] : $span['start']->modify('+1 day')) : null;
                    $past = $end !== null && $end <= $today;
                    if ($when === 'upcoming' && $past) continue;
                    if ($when === 'past' && !$past) continue;
                    if (preg_match('~^\d{4}-\d{2}$~', $when) && (!$span || $span['start']->format('Y-m') !== $when)) continue;
                }
            }
            $rows[] = [$e, $span];
        }
        if ($cal) {
            // Kommende aufsteigend, vergangene absteigend (neueste zuerst), ohne Datum ans Ende
            usort($rows, function ($a, $b) use ($when) {
                $sa = $a[1]['start'] ?? null;
                $sb = $b[1]['start'] ?? null;
                if (!$sa || !$sb) return ($sa === null) <=> ($sb === null);
                return $when === 'past' ? $sb <=> $sa : $sa <=> $sb;
            });
        }
        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = $ids !== null ? 1 : max(1, min($pages, (int) ($o['page'] ?? 1)));
        $slice = $ids !== null ? $rows : array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $m = array_keys($months);
        rsort($m);
        $fmt = \Core\Format::admin();
        $monthLabels = [];
        foreach (array_slice($m, 0, 36) as $ym) $monthLabels[$ym] = $fmt->date($ym . '-01', 'month');
        return ['items' => array_map(fn($r) => self::item($t, $r[0], $r[1], $filters), $slice), 'total' => $total, 'page' => $page, 'pages' => $pages,
            'calendar' => $cal, 'filters' => $filters, 'months' => $monthLabels];
    }

    /** Ein Eintrag für die Liste */
    public static function item(array $t, array $e, ?array $span = null, ?array $filters = null): array
    {
        $span ??= Calendar::enabled($t) ? Calendar::span($t, $e) : null;
        $sub = [];
        if ($span) $sub[] = \Core\Format::admin()->date($span['start']->format('Y-m-d'));
        foreach ($filters ?? self::filters($t) as $fl) {
            $val = $e[$fl['name']] ?? null;
            $vals = is_array($val) ? $val : (is_string($val) && str_starts_with($val, '[') ? (array) json_decode($val, true) : [$val]);
            $labels = array_values(array_filter(array_map(fn($v) => $fl['options'][(string) $v] ?? '', $vals)));
            if ($labels) { $sub[] = implode(', ', $labels); break; }
        }
        $past = false;
        if ($span) {
            $end = $span['has_end'] ? $span['end'] : $span['start']->modify('+1 day');
            $past = $end <= new \DateTimeImmutable('today');
        }
        $st = (string) ($e['status'] ?? 'published');
        return ['id' => (int) $e['id'], 'title' => Entries::title($t, $e), 'sub' => implode(' · ', $sub), 'status' => $st,
            'status_label' => $st === 'published' ? __('Online') : __('Entwurf'), 'past' => $past, 'thumb' => self::thumb($t, $e)];
    }

    /** Vorschau eines Eintrags */
    public static function preview(array $t, array $e): array
    {
        $it = self::item($t, $e);
        $text = '';
        foreach ($t['fields'] as $f) {
            if (!in_array($f['type'], ['textarea', 'richtext', 'inline'], true)) continue;
            $plain = trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], ' ', (string) ($e[$f['name']] ?? ''))), ENT_QUOTES | ENT_HTML5)) ?? '');
            if ($plain !== '') { $text = mb_strimwidth($plain, 0, 320, '…'); break; }
        }
        $img = '';
        foreach ($t['fields'] as $f) {
            if ($f['type'] === 'media' && !empty($e[$f['name']]) && ($m = Media::find((int) $e[$f['name']])) && str_starts_with((string) $m['mime'], 'image/')) {
                $img = Media::url($m, 640);
                break;
            }
        }
        return $it + ['text' => $text, 'image' => $img, 'edit' => url('/admin/data/' . $t['handle'] . '/' . (int) $e['id']), 'view' => Entries::href($t, $e)];
    }

    private static function thumb(array $t, array $e): string
    {
        foreach ($t['fields'] as $f) {
            if ($f['type'] !== 'media' || empty($e[$f['name']])) continue;
            $m = Media::find((int) $e[$f['name']]);
            return $m && str_starts_with((string) $m['mime'], 'image/') ? Media::url($m, 160) : '';
        }
        return '';
    }
}
