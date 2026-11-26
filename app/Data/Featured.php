<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * „Auf der Startseite hervorheben“ – optionales Grundfeld jeder Tabelle mit Detailseiten (Tabelle → Auf der Website → Detailseiten,
 * Schalter settings.featured, Standard aus). Je Eintrag: hervorheben ja/nein, optional Reihenfolge (kleinere Zahl zuerst) und „bis“-Datum
 * (einschließlich; danach fällt der Eintrag von selbst heraus, ohne Cron).
 *
 *  - Spalten (additiv, bleiben wie alle Grundfelder, wenn einmal da): _featured (0/1), _featured_order (Zahl oder NULL), _featured_until
 *    (JJJJ-MM-TT oder NULL). Angelegt von Tables::sync (Schalter an oder Spalte vorhanden) bzw. ensure() beim ersten Speichern.
 *  - Abfrage: Entries::query($t, ['featured' => 'date'|'order']) – nur aktuell hervorgehobene, sortiert nach Veröffentlichung bzw.
 *    Reihenfolge. Sonst gelten alle Regeln der Website: veröffentlicht, Sprache, Zeitplan, geschützte Bereiche (Core\PageAccess),
 *    Freigaben (Core\Data\Exposure) und bei geteilten Tabellen im Netzwerk nur, was diese Website sieht (Quelle, Auswahl, ausgeblendet).
 *  - items(): mischt hervorgehobene Einträge mehrerer Tabellen (Block „Highlights“, Folien des Einstiegs als Slider – Core\Blocks\HeroSlider).
 */
final class Featured
{
    public const COL = '_featured';
    public const ORDER = '_featured_order';
    public const UNTIL = '_featured_until';
    public const COLUMNS = [self::COL, self::ORDER, self::UNTIL];
    /** Sortierungen der Mischung: neueste zuerst bzw. Reihenfolge (ohne Zahl ans Ende, dann neueste) */
    public const SORTS = ['date', 'order'];

    /** @var array<string,bool> Tabellen mit geprüften Spalten (je Anfrage) */
    private static array $ready = [];

    /** Ist das Hervorheben für diese Tabelle eingeschaltet? (nur mit Detailseiten, nie Eingangs-Tabellen) */
    public static function enabled(array $t): bool
    {
        return !empty($t['settings']['featured']) && trim((string) ($t['settings']['route'] ?? '')) !== '' && !Tables::isInbox($t);
    }

    /** Spalten anlegen (additiv, idempotent je Anfrage) */
    public static function ensure(array $t): void
    {
        if (Tables::isInbox($t) || isset(self::$ready[$t['table']])) return;
        Tables::db($t)->ensureColumns($t['table'], [self::COL => 'INT NOT NULL DEFAULT 0', self::ORDER => 'INT NULL', self::UNTIL => 'VARCHAR(10) NULL']);
        self::$ready[$t['table']] = true;
    }

    /** Hat die Tabelle die Spalten schon? (Tables::sync behält sie) */
    public static function hasColumns(array $columns): bool
    {
        return in_array(self::COL, $columns, true);
    }

    /**
     * Formularwerte → Spalten: ['_featured' => 0|1, '_featured_order' => ?int, '_featured_until' => ?string] und Fehler je Feld.
     * „bis“ als JJJJ-MM-TT (auch TT.MM.JJJJ); leer = unbegrenzt.
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function input(array $in): array
    {
        $errors = [];
        $on = in_array($in[self::COL] ?? null, [true, 1, '1', 'on', 'true'], true);
        $order = trim((string) ($in[self::ORDER] ?? ''));
        $until = trim((string) ($in[self::UNTIL] ?? ''));
        $date = null;
        if ($until !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $until) ?: \DateTimeImmutable::createFromFormat('!d.m.Y', $until);
            if ($d) $date = $d->format('Y-m-d');
            else $errors[self::UNTIL] = __('Bitte ein Datum angeben.');
        }
        if ($order !== '' && !preg_match('~^-?\d{1,6}$~', $order)) $errors[self::ORDER] = __('Bitte eine ganze Zahl angeben.');
        return [[self::COL => $on ? 1 : 0, self::ORDER => $order !== '' && !isset($errors[self::ORDER]) ? (int) $order : null, self::UNTIL => $date], $errors];
    }

    /** Ist der Eintrag gerade hervorgehoben? (Schalter an, „bis“ nicht überschritten) */
    public static function active(array $e, ?string $today = null): bool
    {
        if ((int) ($e[self::COL] ?? 0) !== 1) return false;
        $until = (string) ($e[self::UNTIL] ?? '');
        return $until === '' || $until >= ($today ?? date('Y-m-d'));
    }

    /** SQL-Bedingung „gerade hervorgehoben“ (Entries::where, Option 'featured') */
    public static function sql(array &$params): string
    {
        $params[] = date('Y-m-d');
        return '(' . self::COL . ' = 1 AND (' . self::UNTIL . ' IS NULL OR ' . self::UNTIL . " = '' OR " . self::UNTIL . ' >= ?))';
    }

    /** ORDER BY für die Option 'featured' (Entries::queryAll): Reihenfolge (ohne Zahl ans Ende) bzw. neueste zuerst */
    public static function orderSql(string $sort): string
    {
        $date = 'COALESCE(published_at, created_at) DESC, id DESC';
        return $sort === 'order' ? 'CASE WHEN ' . self::ORDER . ' IS NULL THEN 1 ELSE 0 END, ' . self::ORDER . ' ASC, ' . $date : $date;
    }

    /** Kurzbeschreibung für die Eintragsliste der Verwaltung („Hervorgehoben bis 31.12.2026“) – '' ohne Hervorhebung */
    public static function label(array $e): string
    {
        if ((int) ($e[self::COL] ?? 0) !== 1) return '';
        if (!self::active($e)) return __('Hervorhebung abgelaufen');
        $until = (string) ($e[self::UNTIL] ?? '');
        return $until !== '' ? __('Hervorgehoben bis {date}', ['date' => fmt()->date($until)]) : __('Hervorgehoben');
    }

    /**
     * Hervorgehobene Einträge aus mehreren Tabellen gemischt.
     * $sources: [['table' => handle, 'limit' => n, 'badge' => 'Beschriftung' (leer = Name der Tabelle)], …] – jede Tabelle höchstens einmal.
     * $o: sort ('date' | 'order'), limit (gesamt, 0 = ohne), exclude (['handle' => id oder [ids]] – z. B. der aufgerufene Eintrag bzw.
     *     Einträge, die schon im Slider der Seite stehen; die Anzahl je Tabelle gilt nach dem Auslassen)
     * @return list<array{table: array, entry: array, badge: string, href: ?string, date: string, order: ?int}>
     */
    public static function items(array $sources, array $o = []): array
    {
        $sort = in_array($o['sort'] ?? '', self::SORTS, true) ? (string) $o['sort'] : 'date';
        $items = [];
        $seen = [];
        foreach ($sources as $s) {
            if (!is_array($s)) continue;
            $t = Tables::findContent((string) ($s['table'] ?? ''));
            if (!$t || isset($seen[$t['handle']]) || !self::enabled($t)) continue;
            $seen[$t['handle']] = true;
            $limit = max(1, min(12, (int) ($s['limit'] ?? 3) ?: 3));
            $q = ['status' => 'published', 'featured' => $sort, 'limit' => $limit];
            if (isset($o['exclude'][$t['handle']])) $q['exclude_ids'] = array_map('intval', (array) $o['exclude'][$t['handle']]);
            try {
                $rows = Exposure::publicRows($t, Entries::query($t, $q));
            } catch (\Throwable) {
                continue;   // Spalten fehlen noch (Schalter an, noch nie gespeichert): nichts hervorgehoben
            }
            $badge = trim((string) ($s['badge'] ?? '')) ?: (string) $t['name'];
            foreach ($rows as $e) {
                $items[] = ['table' => $t, 'entry' => $e, 'badge' => $badge, 'href' => Entries::href($t, $e),
                    'date' => (string) (($e['published_at'] ?? '') ?: ($e['created_at'] ?? '')), 'order' => isset($e[self::ORDER]) ? (int) $e[self::ORDER] : null];
            }
        }
        usort($items, function (array $a, array $b) use ($sort): int {
            if ($sort === 'order') {
                $c = ($a['order'] === null) <=> ($b['order'] === null);
                if ($c !== 0) return $c;
                if ($a['order'] !== null && ($c = $a['order'] <=> $b['order']) !== 0) return $c;
            }
            return strcmp($b['date'], $a['date']);
        });
        $limit = max(0, (int) ($o['limit'] ?? 0));
        return $limit ? array_slice($items, 0, $limit) : $items;
    }

    /**
     * Bedienung an einer Karte bzw. Folie im Bearbeiten-Modus (Highlights, Slider): „Weiter vorne/hinten“ (nur bei Sortierung nach
     * Reihenfolge) und „Nicht mehr hervorheben“ – resources/js/editor.js, POST /admin/api/featured. '' ohne Bearbeiten oder ohne Recht.
     */
    public static function editControls(array $t, array $e, bool $orderable): string
    {
        if (!is_editing() || !can('data.publish', $t['handle'])) return '';
        $key = $t['handle'] . ':' . (int) $e['id'];
        $title = Entries::title($t, $e);
        return '<span class="cms-feat" data-feat-key="' . e($key) . '" contenteditable="false">'
            . ($orderable ? '<button type="button" class="cms-feat__b" data-feat-act="up" aria-label="' . e(__('„{title}“ weiter nach vorne', ['title' => $title])) . '" title="' . e(__('Weiter nach vorne')) . '">←</button>'
                . '<button type="button" class="cms-feat__b" data-feat-act="down" aria-label="' . e(__('„{title}“ weiter nach hinten', ['title' => $title])) . '" title="' . e(__('Weiter nach hinten')) . '">→</button>' : '')
            . '<button type="button" class="cms-feat__b cms-feat__off" data-feat-act="off" aria-label="' . e(__('„{title}“ nicht mehr hervorheben', ['title' => $title])) . '" title="' . e(__('Nicht mehr auf der Startseite hervorheben')) . '">☆ ' . e(__('Entfernen')) . '</button></span>';
    }

    /** Hinweis im Bearbeiten-Modus: woher die Einträge kommen und wie man weitere hinzufügt */
    public static function editHint(array $sources, string $sort): string
    {
        if (!is_editing()) return '';
        $names = [];
        foreach ($sources as $s) if (is_array($s) && ($t = Tables::findContent((string) ($s['table'] ?? ''))) && self::enabled($t)) $names[] = (string) $t['name'];
        if (!$names) return '';
        return '<p class="cms-feat-hint">' . e(__('Hervorgehobene Einträge aus {tables} – weitere am Eintrag mit ★ „Auf der Startseite hervorheben“ hinzufügen.', ['tables' => implode(', ', array_unique($names))]))
            . ($sort !== 'order' ? ' ' . e(__('Reihenfolge ändern: in der Seitenleiste die Sortierung auf „Reihenfolge“ stellen.')) : '') . '</p>';
    }

    /**
     * Wo wertet diese Website hervorgehobene Einträge der Tabelle aus? Seiten mit Block „Highlights“ bzw. Einstieg als Slider, deren
     * Quellen die Tabelle nennen (veröffentlichte Fassung, auch in Spalten) – für den Hilfetext am Schalter. [Seitentitel => [Blocktitel]]
     * @return array<string, list<string>>
     */
    public static function usedOn(array $t): array
    {
        $out = [];
        $theme = app()->theme;
        foreach (\Core\Pages::all() as $p) {
            if (!str_contains((string) ($p['content_published'] ?? ''), '"' . $t['handle'] . '"')) continue;
            $walk = function (array $blocks) use (&$walk, &$out, $p, $t, $theme): void {
                foreach ($blocks as $b) {
                    if (!is_array($b)) continue;
                    $d = (array) ($b['data'] ?? []);
                    $type = (string) ($b['type'] ?? '');
                    $src = $type === 'highlights' ? (array) ($d['sources'] ?? []) : ($type === 'hero' && !empty($d['slider']) ? (array) ($d['slides_sources'] ?? []) : []);
                    if (in_array($t['handle'], array_map(fn($x) => (string) (is_array($x) ? ($x['table'] ?? '') : ''), $src), true)) {
                        $label = (string) ($theme->block($type)['label'] ?? $type);
                        $out[(string) $p['title']][] = $type === 'hero' ? __('Einstieg') : $label;
                    }
                    foreach ((array) ($d['columns'] ?? []) as $col) if (is_array($col)) $walk((array) ($col['blocks'] ?? $col));
                }
            };
            $walk(\Core\Pages::blocks($p, false));
        }
        return array_map(fn($l) => array_values(array_unique($l)), $out);
    }

    /** Tabellen, deren Einträge sich hervorheben lassen (Auswahl im Block, Hinweise) – [handle => Name] */
    public static function tables(): array
    {
        $out = [];
        foreach (Tables::content() as $t) if (self::enabled($t)) $out[$t['handle']] = (string) $t['name'];
        return $out;
    }

    /** Nur für Selbsttests: „Spalten geprüft“ zurücksetzen */
    public static function reset(): void
    {
        self::$ready = [];
    }
}
