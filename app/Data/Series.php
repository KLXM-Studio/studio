<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
// Teile übernommen aus KLXM/scheduler (MIT, © 2026 KLXM Crossmedia GmbH): Recurrence/SeriesEditor (move, remove, split), EditScope
declare(strict_types=1);

namespace Core\Data;

use DateTimeImmutable;
use RRule\RRule;

/**
 * Serientermine in Kalendern der Art calendar (Core\Data\Kinds) nach RFC 5545:
 *
 *  - Serie = Eintrag mit Wiederholung (Feld wiederholung: RRULE + Zeile „EXDATE:“).
 *  - Abweichender Einzeltermin (RECURRENCE-ID) = eigener Eintrag derselben Tabelle mit _series (ID des Serienkopfs) und _recurrence_id
 *    (ursprünglicher Beginn des ersetzten Vorkommens in Ortszeit, „JJJJ-MM-TT HH:MM“) – mit eigenen Feldern, Revisionen, Bild,
 *    Übersetzung. In Listen, Suche und Feeds erscheint er nicht als eigener Eintrag (Entries::where), nur als Vorkommen der Serie.
 *  - Ausfall: EXDATE (Termin entfällt ganz) oder Einzeltermin mit „Fällt aus“ (bleibt sichtbar, als abgesagt gekennzeichnet).
 *  - Bearbeiten eines Vorkommens mit Bereich (scope): this = nur dieser (Einzeltermin anlegen/ändern), following = dieser und alle
 *    folgenden (Serie endet davor, neuer Serienkopf ab hier, spätere Einzeltermine und Ausnahmen ziehen um), all = alle (Serie um den
 *    Abstand des bearbeiteten Vorkommens verschieben, Schlüssel der Einzeltermine und Ausnahmen mitverschieben).
 *
 * Gespeichert wird immer über Entries::save/delete (Rechte, Revisionen, Ereignisse, Vorkommens-Index wie sonst).
 */
final class Series
{
    public const SERIES = '_series';
    public const RID = '_recurrence_id';
    public const UID = '_uid';
    public const SCOPES = ['this', 'following', 'all'];

    /** Ist der Eintrag ein Serienkopf (Kalender mit Serien, mit Wiederholung, kein Einzeltermin)? */
    public static function isHead(array $t, array $e): bool
    {
        return Kinds::isCalendar($t) && (int) ($e[self::SERIES] ?? 0) === 0 && Calendar::recurrence($t, $e)['rrule'] !== '';
    }

    /** Ist der Eintrag ein abweichender Einzeltermin einer Serie? */
    public static function isOverride(array $t, array $e): bool
    {
        return Kinds::isCalendar($t) && (int) ($e[self::SERIES] ?? 0) > 0;
    }

    /** Einzeltermine einer Serie: [ursprünglicher Beginn => Eintrag] */
    public static function overrides(array $t, int $head): array
    {
        if (!Kinds::isCalendar($t)) return [];
        $ids = array_map(fn($r) => (int) $r['id'], Tables::db($t)->fetchAll("SELECT id FROM {$t['table']} WHERE " . self::SERIES . ' = ?', [$head]));
        $out = [];
        foreach ($ids ? Entries::query($t, ['ids' => $ids, 'status' => 'all', 'lang' => 'all', 'source' => 'all', 'internal' => true]) : [] as $e) {
            $out[(string) $e[self::RID]] = $e;
        }
        ksort($out);
        return $out;
    }

    /** Vorkommens-Schlüssel „JJJJMMTTTHHMM“ (Adresse ?vorkommen=…) → ursprünglicher Beginn „JJJJ-MM-TT HH:MM“ */
    public static function ridFromKey(string $key): ?string
    {
        return preg_match('~^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})$~', $key, $m) ? "$m[1]-$m[2]-$m[3] $m[4]:$m[5]" : null;
    }

    /** Ursprünglicher Beginn als Zeitpunkt */
    public static function ridTime(string $rid): ?DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d H:i', $rid, Calendar::tz()) ?: null;
    }

    /** Gehört der Beginn zur Serie (Regel ohne Ausnahmen)? */
    public static function hasOccurrence(array $t, array $head, string $rid): bool
    {
        $at = self::ridTime($rid);
        $span = Calendar::span($t, $head);
        if (!$at || !$span) return false;
        try {
            $rule = new RRule(Calendar::recurrence($t, $head)['rrule'], \DateTime::createFromImmutable($span['start']));
            return $rule->occursAt(\DateTime::createFromImmutable($at));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Beginn/Ende eines Vorkommens als Feldwerte (Format wie im Serienkopf, Ende mit gleicher Dauer) – Vorbelegung des Formulars.
     * @return array<string, string>
     */
    public static function valuesFor(array $t, array $head, string $rid): array
    {
        $c = Calendar::config($t);
        $at = self::ridTime($rid);
        $s = Calendar::parse($head[$c['start']] ?? null);
        if (!$at || !$s) return [];
        $shift = $s[0]->diff($at);
        $out = [$c['start'] => self::shiftValue((string) $head[$c['start']], $shift)];
        if ($c['end'] !== '' && trim((string) ($head[$c['end']] ?? '')) !== '') $out[$c['end']] = self::shiftValue((string) $head[$c['end']], $shift);
        return $out;
    }

    /** Datums-/Zeitwert („JJJJ-MM-TT“ bzw. „JJJJ-MM-TT HH:MM“) um einen Abstand verschieben, Format bleibt */
    public static function shiftValue(string $v, \DateInterval $shift): string
    {
        $p = Calendar::parse($v);
        if (!$p) return $v;
        $d = $p[0]->add($shift);
        return $p[1] ? $d->format('Y-m-d') : $d->format('Y-m-d H:i');
    }

    /** Werte eines Eintrags als Eingabe für Entries::save (alle Datenfelder) */
    private static function input(array $t, array $e): array
    {
        $in = [];
        foreach ($t['fields'] as $f) if (array_key_exists($f['name'], $e)) $in[$f['name']] = $e[$f['name']];
        $in['status'] = $e['status'] ?? 'published';
        if (!empty($e['lang'])) $in['lang'] = $e['lang'];
        if (array_key_exists(Exposure::STATE, $e)) $in[Exposure::STATE] = $e[Exposure::STATE];
        return $in;
    }

    /** Regel zerlegen/zusammensetzen: „FREQ=WEEKLY;COUNT=5“ ↔ ['FREQ' => 'WEEKLY', 'COUNT' => '5'] */
    private static function parts(string $rrule): array
    {
        $out = [];
        foreach (array_filter(explode(';', $rrule)) as $kv) if (str_contains($kv, '=')) { [$k, $v] = explode('=', $kv, 2); $out[strtoupper($k)] = $v; }
        return $out;
    }

    private static function join(array $parts, array $exdates): string
    {
        return implode(';', array_map(fn($k, $v) => "$k=$v", array_keys($parts), $parts)) . ($exdates ? "\nEXDATE:" . implode(',', $exdates) : '');
    }

    // ================================================================= Bearbeiten

    /**
     * Ein Vorkommen bearbeiten. $in = Formularwerte (Beginn/Ende des Vorkommens, übrige Felder), $rid = ursprünglicher Beginn.
     * @return array{0: ?int, 1: array} [ID des Eintrags, der das Vorkommen jetzt trägt (Einzeltermin, neuer Kopf oder Kopf), Fehler]
     */
    public static function edit(array $t, array $head, string $rid, array $in, string $scope): array
    {
        if (!self::isHead($t, $head)) return Entries::save($t, (int) $head['id'], $in);
        $c = Calendar::config($t);
        $cut = self::ridTime($rid);
        if (!$cut) return [null, ['_' => __('Unbekannter Termin der Serie.')]];
        $first = Calendar::span($t, $head)['start'] ?? null;
        if ($scope === 'following' && $first && $cut <= $first) $scope = 'all';
        if ($scope === 'this') {
            $ov = self::overrides($t, (int) $head['id'])[$rid] ?? null;
            $in[$c['recurrence']] = '';
            $in[self::SERIES] = (int) $head['id'];
            $in[self::RID] = $rid;
            if (!$ov) $in['slug'] = ($head['slug'] ?? 'termin') . '-' . $cut->format('Ymd');
            return Entries::save($t, $ov ? (int) $ov['id'] : null, $in, __('Einzeltermin am {date}', ['date' => fmt()->date($cut->getTimestamp())]));
        }
        if ($scope === 'all') {
            // Abstand des bearbeiteten Vorkommens → ganze Serie; Ausnahmen und Einzeltermine wandern mit
            $new = Calendar::parse($in[$c['start']] ?? null);
            $shift = $new ? $cut->diff($new[0]) : null;
            if ($shift && $cut != $new[0]) {
                $in[$c['start']] = self::shiftValue((string) $head[$c['start']], $shift);
                $dur = ($e2 = Calendar::parse($in[$c['end']] ?? null)) ? $new[0]->diff($e2[0]) : null;
                if ($c['end'] !== '' && $dur) $in[$c['end']] = self::shiftValue((string) $in[$c['start']], $dur);
                $in[$c['recurrence']] = self::shiftExdates((string) ($in[$c['recurrence']] ?? $head[$c['recurrence']]), $shift);
                foreach (self::overrides($t, (int) $head['id']) as $orid => $ov) {
                    $ot = self::ridTime($orid);
                    if ($ot) Entries::save($t, (int) $ov['id'], [self::RID => $ot->add($shift)->format('Y-m-d H:i')]);
                }
            } else {
                $in[$c['start']] = $head[$c['start']];
                if ($c['end'] !== '' && ($e2 = Calendar::parse($in[$c['end']] ?? null)) && $new) {
                    $in[$c['end']] = self::shiftValue((string) $head[$c['start']], $new[0]->diff($e2[0]));
                }
            }
            return Entries::save($t, (int) $head['id'], $in);
        }
        // following: Serie endet vor dem Vorkommen, neuer Serienkopf ab hier
        [$newId, $errors] = self::split($t, $head, $rid, $in);
        return [$newId, $errors];
    }

    /**
     * Serie vor $rid beenden und ab dort als neuen Serienkopf mit den Werten $in fortsetzen (COUNT wird aufgeteilt, Ausnahmen und
     * Einzeltermine ab dem Schnitt ziehen um – verschoben um den Abstand des neuen Beginns).
     * @return array{0: ?int, 1: array}
     */
    public static function split(array $t, array $head, string $rid, ?array $in = null): array
    {
        $c = Calendar::config($t);
        $cut = self::ridTime($rid);
        $span = Calendar::span($t, $head);
        if (!$cut || !$span) return [null, ['_' => __('Unbekannter Termin der Serie.')]];
        $rec = Calendar::recurrence($t, $head);
        $parts = self::parts($rec['rrule']);
        $tail = $parts;
        if (isset($parts['COUNT'])) {
            try {
                $before = count((new RRule($rec['rrule'], \DateTime::createFromImmutable($span['start'])))
                    ->getOccurrencesBetween(\DateTime::createFromImmutable($span['start']), \DateTime::createFromImmutable($cut->modify('-1 second'))));
            } catch (\Throwable) {
                $before = 0;
            }
            $tail['COUNT'] = (string) max(1, (int) $parts['COUNT'] - $before);
            unset($parts['COUNT']);
        }
        $parts['UNTIL'] = $span['all_day'] ? $cut->modify('-1 day')->format('Ymd') : $cut->modify('-1 second')->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $exBefore = array_values(array_filter($rec['exdates'], fn($d) => $d < $cut->format('Y-m-d')));
        $exAfter = array_values(array_filter($rec['exdates'], fn($d) => $d >= $cut->format('Y-m-d')));
        // neuer Kopf: Werte aus $in (Formular) bzw. Kopie des Kopfs mit Beginn/Ende des Vorkommens
        $new = $in ?? (self::input($t, $head) + self::valuesFor($t, $head, $rid));
        if ($in !== null && !isset($in[$c['start']])) $new += self::valuesFor($t, $head, $rid);
        $newStart = Calendar::parse($new[$c['start']] ?? null);
        $shift = $newStart ? $cut->diff($newStart[0]) : null;
        $moved = $shift && $newStart[0] != $cut;
        // Regel des neuen Kopfs: aus dem Formular, sonst die alte; Ausnahmen ab dem Schnitt (verschoben)
        $formRule = Calendar::parseValue((string) ($new[$c['recurrence']] ?? ''));
        // unveränderte Regel: Rest der Anzahl (COUNT) übernehmen; geänderte Regel gilt, wie sie im Formular steht
        $sameRule = $formRule['rrule'] === '' || Calendar::normalize($formRule['rrule'])[0] === $rec['rrule'];
        $tailParts = $sameRule ? $tail : self::parts((string) Calendar::normalize($formRule['rrule'])[0]);
        $tailEx = $moved ? array_map(fn($d) => (new DateTimeImmutable($d . ' 12:00', Calendar::tz()))->add($shift)->format('Y-m-d'), $exAfter) : $exAfter;
        $formEx = array_filter($formRule['exdates'], fn($d) => $d >= $cut->format('Y-m-d'));
        $allEx = array_values(array_unique(array_merge($tailEx, $formEx)));
        sort($allEx);
        $new[$c['recurrence']] = self::join($tailParts, $allEx);
        unset($new[self::SERIES], $new[self::RID], $new[self::UID]);
        $new['slug'] = '';
        [$newId, $errors] = Entries::save($t, null, $new, __('Serie ab {date}', ['date' => fmt()->date($cut->getTimestamp())]));
        if (!$newId) return [null, $errors];
        // alter Kopf endet vor dem Schnitt
        [, $err2] = Entries::save($t, (int) $head['id'], [$c['recurrence'] => self::join($parts, $exBefore)], __('Serie endet vor {date}', ['date' => fmt()->date($cut->getTimestamp())]));
        if ($err2) return [$newId, $err2];
        // Einzeltermine ab dem Schnitt ziehen um
        foreach (self::overrides($t, (int) $head['id']) as $orid => $ov) {
            if ($orid < $rid) continue;
            $ot = self::ridTime($orid);
            Entries::save($t, (int) $ov['id'], [self::SERIES => $newId, self::RID => $moved && $ot ? $ot->add($shift)->format('Y-m-d H:i') : $orid]);
        }
        return [$newId, []];
    }

    /** Ausnahmen (EXDATE) einer gespeicherten Wiederholung um einen Abstand verschieben */
    private static function shiftExdates(string $value, \DateInterval $shift): string
    {
        $p = Calendar::parseValue($value);
        if (!$p['exdates']) return $value;
        $ex = array_map(fn($d) => (new DateTimeImmutable($d . ' 12:00', Calendar::tz()))->add($shift)->format('Y-m-d'), $p['exdates']);
        return self::join(self::parts($p['rrule']), $ex);
    }

    // ================================================================= Löschen, Ausfall

    /**
     * Vorkommen entfernen: this = Ausnahme (EXDATE, ein vorhandener Einzeltermin wird gelöscht), following = Serie endet davor
     * (Einzeltermine ab dort weg), all = ganze Serie löschen.
     */
    public static function remove(array $t, array $head, string $rid, string $scope): void
    {
        $c = Calendar::config($t);
        $cut = self::ridTime($rid);
        $first = Calendar::span($t, $head)['start'] ?? null;
        if (!self::isHead($t, $head) || $scope === 'all' || !$cut || ($scope === 'following' && $first && $cut <= $first)) {
            Entries::delete($t, (int) $head['id']);
            return;
        }
        $ovs = self::overrides($t, (int) $head['id']);
        if ($scope === 'this') {
            if (isset($ovs[$rid])) Entries::delete($t, (int) $ovs[$rid]['id']);
            $rec = Calendar::recurrence($t, $head);
            $ex = array_values(array_unique([...$rec['exdates'], $cut->format('Y-m-d')]));
            sort($ex);
            Entries::save($t, (int) $head['id'], [$c['recurrence'] => self::join(self::parts($rec['rrule']), $ex)], __('Termin am {date} entfernt', ['date' => fmt()->date($cut->getTimestamp())]));
            return;
        }
        foreach ($ovs as $orid => $ov) if ($orid >= $rid) Entries::delete($t, (int) $ov['id']);
        $span = Calendar::span($t, $head);
        $rec = Calendar::recurrence($t, $head);
        $parts = self::parts($rec['rrule']);
        unset($parts['COUNT']);
        $parts['UNTIL'] = !empty($span['all_day']) ? $cut->modify('-1 day')->format('Ymd') : $cut->modify('-1 second')->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        Entries::save($t, (int) $head['id'], [$c['recurrence'] => self::join($parts, array_values(array_filter($rec['exdates'], fn($d) => $d < $cut->format('Y-m-d'))))],
            __('Serie endet vor {date}', ['date' => fmt()->date($cut->getTimestamp())]));
    }

    /**
     * „Fällt aus“ für ein Vorkommen an-/abschalten: Einzeltermin mit abgesagt = 1 (bleibt sichtbar). Abschalten bei einem Einzeltermin,
     * der sonst dem Kopf gleicht, löscht ihn wieder.
     * @return array{0: ?int, 1: array}
     */
    public static function cancel(array $t, array $head, string $rid, bool $on): array
    {
        $ovs = self::overrides($t, (int) $head['id']);
        if (isset($ovs[$rid])) return Entries::save($t, (int) $ovs[$rid]['id'], [Kinds::CANCELLED_FIELD => $on]);
        if (!$on) return [null, []];
        $in = self::input($t, $head) + [];
        $in = array_merge($in, self::valuesFor($t, $head, $rid), [Kinds::CANCELLED_FIELD => true]);
        return self::edit($t, $head, $rid, $in, 'this');
    }

    // ================================================================= Vorschau (Wiederholungs-Editor)

    /**
     * Nächste Termine einer Regel ab heute (bzw. ab Beginn) für den Wiederholungs-Editor: Klartext und Liste mit Ausfällen
     * (EXDATE: skipped) sowie – bei gespeicherten Serien – abweichenden Einzelterminen (changed, mit neuer Zeit) und „fällt aus“ (cancelled).
     * @return array{summary: string, items: list<array{date: string, state: string, note: string}>}
     */
    public static function preview(array $t, ?array $head, string $start, bool $allDay, string $value, int $n = 6): array
    {
        $p = Calendar::parseValue($value);
        $out = ['summary' => $p['rrule'] !== '' ? Calendar::describe($value, \Core\I18n::locale() !== '' ? substr(\Core\I18n::locale(), 0, 2) : 'de') : '', 'items' => []];
        $s = Calendar::parse($start);
        if (!$s || $p['rrule'] === '') return $out;
        $begin = $allDay || $s[1] ? $s[0]->setTime(0, 0) : $s[0];
        try {
            $rule = new RRule(Calendar::normalize($p['rrule'])[0] ?: $p['rrule'], \DateTime::createFromImmutable($begin));
        } catch (\Throwable) {
            return $out;
        }
        $from = max($begin, new DateTimeImmutable('today', Calendar::tz()));
        $ovs = $head && Kinds::isCalendar($t) ? self::overrides($t, (int) $head['id']) : [];
        $ex = array_flip($p['exdates']);
        $f = \Core\Format::admin();
        $i = 0;
        foreach ($rule->getOccurrencesAfter(\DateTime::createFromImmutable($from), true, $n + count($ex)) as $d) {
            if ($i >= $n) break;
            $d = DateTimeImmutable::createFromInterface($d)->setTimezone(Calendar::tz());
            $label = Calendar::dayLabel($d) . ($allDay || $s[1] ? '' : ', ' . $d->format('H:i'));
            $ov = $ovs[$d->format('Y-m-d H:i')] ?? null;
            $state = isset($ex[$d->format('Y-m-d')]) ? 'skipped' : ($ov ? (!empty($ov[Kinds::CANCELLED_FIELD]) ? 'cancelled' : 'changed') : '');
            $note = '';
            if ($state === 'changed' && ($os = Calendar::span($t, $ov))) $note = $os['all_day'] ? $f->date($os['start']->getTimestamp()) : $f->datetime($os['start']->getTimestamp());
            $out['items'][] = ['date' => $label, 'state' => $state, 'note' => $note];
            $i++;
        }
        return $out;
    }
}
