<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use DateTimeImmutable;

/**
 * Feldtyp „Termin“ (event): Verweis auf einen Termin eines Kalenders – Wert „{id}“ (Termin bzw. ganze Serie) oder
 * „{id}@JJJJMMTTTHHMM“ (ein Vorkommen einer Serie, Schlüssel wie ?vorkommen=). Ziel ist eine Tabelle mit Kalender (target).
 * Eingabe als Auswahl (Termine und die nächsten Vorkommen von Serien, ein Jahr), Ausgabe Titel + Zeitpunkt mit Link auf die
 * Detailseite (bei Vorkommen ?vorkommen=…). Die Vorauswahl „Kontakt“/„Kontakte“ ist dagegen eine normale Verknüpfung auf ein
 * Adressbuch (Tables::validate → relation/relations mit target_kind = contacts).
 */
final class EventRef
{
    public const TYPE = 'event';
    public const PATTERN = '~^(\d{1,10})(?:@(\d{8}T\d{4}))?$~';

    /** @return array{0: int, 1: ?string}|null [ID, Vorkommen-Schlüssel] */
    public static function parse(mixed $v): ?array
    {
        return is_scalar($v) && preg_match(self::PATTERN, trim((string) $v), $m) ? [(int) $m[1], ($m[2] ?? '') !== '' ? $m[2] : null] : null;
    }

    /** Auswahl für die Eingabe: [wert => „Titel – Zeitpunkt“], $keep = gespeicherter Wert (bleibt wählbar, auch wenn vergangen) */
    public static function options(array $target, mixed $keep = null): array
    {
        $out = [];
        if (!Calendar::enabled($target)) return $out;
        $now = new DateTimeImmutable('today', Calendar::tz());
        try {
            $occ = Calendar::occurrences($target, $now, $now->modify('+1 year'), ['limit' => 300, 'status' => 'all']);
        } catch (\Throwable) {
            $occ = [];
        }
        $series = [];
        foreach ($occ as $o) {
            $x = self::extras($o);   // Zusatzangaben je Vorkommen: series, rid, override (Calendar::compute)
            $e = $o['entry'];
            $id = (int) (!empty($x['override']) && !empty($x['series']) ? $x['series'] : $e['id']);
            $title = Entries::title($target, $e);
            if (!empty($x['series']) && ($x['rid'] ?? '') !== '') {
                if (!isset($series[$id])) { $series[$id] = true; $out[(string) $id] = $title . ' – ' . __('alle Termine der Serie'); }
                $out[$id . '@' . Calendar::ridKey((string) $x['rid'])] = $title . ' – ' . Calendar::when($o);
            } else {
                $out[(string) $id] = $title . ' – ' . Calendar::when($o);
            }
        }
        if (($p = self::parse($keep)) && !isset($out[(string) $keep]) && ($e = Entries::find($target, $p[0]))) {
            $out[(string) $keep] = Entries::title($target, $e) . ($p[1] !== null && ($t = Series::ridTime((string) Series::ridFromKey($p[1]))) ? ' – ' . Calendar::dayLabel($t) : '');
        }
        return $out;
    }

    /** Zusatzangaben eines Vorkommens (series, rid, override – nicht im Rückgabetyp von Calendar::occurrences) */
    private static function extras(array $o): array
    {
        return array_intersect_key($o, array_flip(['series', 'rid', 'override']));
    }

    /** Ausgabe für die Website: Titel und Zeitpunkt, verlinkt (Detailseite, Vorkommen) */
    public static function html(array $field, mixed $v, array $o = []): string
    {
        $p = self::parse($v);
        $target = $p ? Tables::findContent((string) ($field['target'] ?? '')) : null;
        if (!$p || !$target || !($e = Entries::find($target, $p[0])) || ($e['status'] ?? '') !== 'published' || !Exposure::visible($target, $e)) return '';
        $e = Exposure::publicEntry($target, $e);
        $title = Entries::title($target, $e);
        $when = '';
        if ($p[1] !== null && ($rid = Series::ridFromKey($p[1])) && ($t = Series::ridTime($rid))) {
            $occ = Calendar::occurrences($target, $t, $t->modify('+1 day'), ['ids' => [(int) $e['id']], 'limit' => 5])[0] ?? null;
            $when = $occ ? Calendar::when($occ) : Calendar::dayLabel($t);
        } elseif ($span = Calendar::span($target, $e)) {
            $when = Calendar::when($span + ['recurring' => false]);
        }
        $href = !empty($o['link']) || !array_key_exists('link', $o) ? Entries::href($target, $e) : null;
        if ($href && $p[1] !== null && !str_contains($href, '://')) $href .= '?vorkommen=' . rawurlencode($p[1]);
        $txt = e($title) . ($when !== '' ? ' <span class="dt-when">' . e($when) . '</span>' : '');
        return $href && empty($o['plain']) ? '<a href="' . e($href) . '">' . $txt . '</a>' : $txt;
    }

    /** Selbsttest (ohne Datenbank) */
    public static function selftest(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Feldtyp Termin – ');
        $eq('Wert: Termin, Vorkommen, ungültig', [self::parse('12'), self::parse('12@20261205T1900'), self::parse('12@2026'), self::parse('x')], [[12, null], [12, '20261205T1900'], null, null]);
        return ['ok' => $ok, 'fails' => $fails];
    }
}
