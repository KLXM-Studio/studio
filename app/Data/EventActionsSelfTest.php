<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Http\Controllers\Admin\DataController;
use DateTimeImmutable;

/**
 * Selbsttest Termin-Aktionen (Core\Data\EventActions) – Teil von `php bin/console calendar:selftest`. Mit einer vorübergehenden
 * Kalender-Tabelle (danach gelöscht) und festem „jetzt“:
 *
 *  - einzelner Termin: .ics-Adresse, webcal, Fremd-Links nur auf Wunsch (Standard aus), Google/Outlook korrekt kodiert, Zeiten in UTC
 *  - ganztägig: Datum statt Uhrzeit (Ende exklusiv), Outlook allday=true
 *  - vergangen und abgesagt: kein Knopf, keine Fremd-Links; ohne Feed keine .ics und kein Abonnieren
 *  - Serie: nächster stattfindender Termin (abgesagter davor als Hinweis), Vorkommen aus ?vorkommen=, verschobener Einzeltermin,
 *    vergangenes Vorkommen mit nächstem Termin, unbekanntes Vorkommen, beendete Serie; .ics eines Vorkommens ohne RRULE mit eigener UID
 *  - Freigabe: Felder „Nur Website“ weder in Fremd-Links noch in der .ics; Fragment ohne Inline-Stile/-Skripte
 */
final class EventActionsSelfTest
{
    private const SECRET = 'Nur-Website-Notiz-Musterstadt';

    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Termin-Aktionen – ');
        if (!\Core\Features::on('calendar', false) || !\Core\Features::on('data', false)) {
            $eq('Funktionen „Kalender“ und „Daten“ an', false, true);
            return ['ok' => $ok, 'fails' => $fails];
        }
        $tz = Calendar::tz();
        $d = fn(string $s) => new DateTimeImmutable($s, $tz);
        $now = $d('2027-01-01 12:00');
        $h = 'selftest_eva_' . bin2hex(random_bytes(3));
        $prevLinks = app()->settings->get('sys.calendar_links');
        try {
            $def = DataController::presetDef('calendar');
            $def['handle'] = $h;
            $def['name'] = 'Selbsttest Termin-Aktionen';
            $def['settings']['route'] = str_replace('_', '-', $h);
            foreach ($def['fields'] as &$f) if ($f['name'] === 'ort_ref') $f['target'] = '';
            unset($f);
            $def['fields'][] = ['label' => 'Interne Notiz', 'name' => 'notiz', 'type' => 'text'];   // Zusatzfeld → „Nur Website“
            [$clean, $err] = Tables::validate($def);
            $eq('Vorlage gültig', $err, []);
            Tables::create($clean);
            $t = Tables::find($h) ?? throw new \RuntimeException('Tabelle fehlt');
            $t['settings']['detail_page_id'] = 1;   // Detailseiten-Adressen (nur im Speicher)
            $save = function (array $in) use (&$t): array {
                [$id] = Entries::save($t, null, $in + ['status' => 'published']);
                return Entries::find($t, (int) $id) ?? [];
            };

            // ------------------------------------------------ Fremd-Links: Standard aus
            app()->settings->override(['sys.calendar_links' => null]);
            $eq('Grundeinstellung leer → keine Fremd-Links', EventActions::services(''), []);
            app()->settings->override(['sys.calendar_links' => ['outlook', 'fremd']]);
            $eq('Grundeinstellung Outlook (Unbekanntes fällt weg)', EventActions::services(''), ['outlook']);
            $eq('Block „keine“ schlägt die Grundeinstellung', EventActions::services('none'), []);
            $eq('Block „beide“', EventActions::services('both'), ['google', 'outlook']);
            app()->settings->override(['sys.calendar_links' => null]);

            // ------------------------------------------------ einzelner Termin
            $e = $save(['titel' => 'Sommerfest & Grillen', 'beginn' => '2027-02-10 18:00', 'ende' => '2027-02-10 20:00',
                'ort' => 'Haus A, Musterweg 1, 12345 Musterstadt', 'beschreibung' => "Musik; Eintritt frei.\nZweite Zeile", 'notiz' => self::SECRET]);
            $a = EventActions::forEntry($t, $e, ['now' => $now, 'key' => '']);
            $eq('einzeln: offen, .ics, webcal, ohne Fremd-Links', [$a['state'], $a['ics'], str_starts_with((string) $a['webcal'], 'webcal://'), $a['links'], $a['series']],
                ['open', url('/kalender/' . $h . '/' . $e['slug'] . '.ics'), true, [], false]);
            $eq('einzeln: Seiten-Cache bis Terminende', $a['until'], $d('2027-02-10 20:00')->getTimestamp());
            $a = EventActions::forEntry($t, $e, ['now' => $now, 'key' => '', 'services' => ['google', 'outlook']]);
            [$g, $ol] = [$a['links'][0]['url'] ?? '', $a['links'][1]['url'] ?? ''];
            parse_str((string) parse_url($g, PHP_URL_QUERY), $gq);
            parse_str((string) parse_url($ol, PHP_URL_QUERY), $oq);
            $eq('Google: Adresse', str_starts_with($g, 'https://calendar.google.com/calendar/render?action=TEMPLATE&'), true);
            $eq('Google: Titel, Zeit in UTC, Ort', [$gq['text'] ?? '', $gq['dates'] ?? '', $gq['location'] ?? ''],
                ['Sommerfest & Grillen', '20270210T170000Z/20270210T190000Z', 'Haus A, Musterweg 1, 12345 Musterstadt']);
            $eq('Google: Beschreibung als Text mit Detailseite', [str_starts_with((string) ($gq['details'] ?? ''), "Musik; Eintritt frei.\nZweite Zeile"),
                str_contains((string) ($gq['details'] ?? ''), '/' . $def['settings']['route'] . '/' . $e['slug'])], [true, true]);
            $eq('Kodierung: %20 und %26 statt + und &', [str_contains($g, '+'), str_contains($g, 'Sommerfest%20%26%20Grillen')], [false, true]);
            $eq('Outlook: Adresse, Zeiten in UTC, nicht ganztägig', [str_starts_with($ol, 'https://outlook.live.com/calendar/0/deeplink/compose?'), $oq['startdt'] ?? '', $oq['enddt'] ?? '', $oq['allday'] ?? '', $oq['subject'] ?? ''],
                [true, '2027-02-10T17:00:00Z', '2027-02-10T19:00:00Z', 'false', 'Sommerfest & Grillen']);
            $eq('„Nur Website“ nie in Fremd-Links', [str_contains(urldecode($g . $ol), self::SECRET)], [false]);
            $ics = ICal::calendar($t, [$e], ['publish' => true]);
            $eq('„Nur Website“ nie in der .ics', str_contains($ics, self::SECRET), false);
            // Fragment: Links, rel, Hinweis, CSP (keine Inline-Stile/-Skripte)
            app()->settings->override(['sys.calendar_links' => ['google']]);
            $html = fragment('event-actions', ['table' => $t, 'entry' => $e, 'options' => []]);
            app()->settings->override(['sys.calendar_links' => null]);
            $eq('Fragment: .ics-Knopf, Google mit rel und Hinweis', [str_contains($html, 'eva-act--ics'), str_contains($html, 'rel="noopener noreferrer"'), str_contains($html, lt('öffnet Google'))],
                [true, true, true]);
            $eq('Fragment: CSP-konform', [str_contains($html, 'style='), str_contains($html, '<script'), (bool) preg_match('~\son[a-z]+=~', $html)], [false, false, false]);
            $eq('Fragment: Stile gemeldet', in_array('event_actions', app()->theme->marks(), true), true);
            $html = fragment('event-actions', ['table' => $t, 'entry' => $e, 'options' => []]);
            $eq('Fragment: ohne Einstellung keine Fremd-Links', [str_contains($html, 'google.com'), str_contains($html, 'outlook')], [false, false]);

            // ------------------------------------------------ ganztägig
            $e = $save(['titel' => 'Aktionstag', 'beginn' => '2027-03-14 00:00', 'ganztaegig' => 1]);
            $a = EventActions::forEntry($t, $e, ['now' => $now, 'key' => '', 'services' => ['google', 'outlook']]);
            parse_str((string) parse_url($a['links'][0]['url'] ?? '', PHP_URL_QUERY), $gq);
            parse_str((string) parse_url($a['links'][1]['url'] ?? '', PHP_URL_QUERY), $oq);
            $eq('ganztägig: Google Datum, Ende exklusiv', $gq['dates'] ?? '', '20270314/20270315');
            $eq('ganztägig: Outlook', [$oq['startdt'] ?? '', $oq['enddt'] ?? '', $oq['allday'] ?? ''], ['2027-03-14', '2027-03-15', 'true']);

            // ------------------------------------------------ vergangen, abgesagt, ohne Feed
            $e = $save(['titel' => 'Versammlung', 'beginn' => '2026-06-01 19:00']);
            $a = EventActions::forEntry($t, $e, ['now' => $now, 'key' => '', 'services' => ['google']]);
            $eq('vergangen: Hinweis statt Knopf', [$a['state'], $a['ics'], $a['links'], $a['next']], ['past', null, [], null]);
            $e = $save(['titel' => 'Konzert', 'beginn' => '2027-04-01 20:00', 'abgesagt' => 1]);
            $a = EventActions::forEntry($t, $e, ['now' => $now, 'key' => '', 'services' => ['google']]);
            $eq('abgesagt: „fällt aus“ statt Knopf', [$a['state'], $a['ics'], $a['links']], ['cancelled', null, []]);
            $nofeed = $t;
            $nofeed['settings']['calendar']['feed'] = false;
            $a = EventActions::forEntry($nofeed, $save(['titel' => 'Ohne Feed', 'beginn' => '2027-05-01 10:00']), ['now' => $now, 'key' => '']);
            $eq('ohne Feed: keine .ics, kein Abonnieren', [$a['state'], $a['ics'], $a['webcal']], ['open', null, null]);

            // ------------------------------------------------ Serie
            $head = $save(['titel' => 'Übungsabend', 'beginn' => '2026-12-01 19:00', 'ende' => '2026-12-01 21:00', 'wiederholung' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=10',
                'ort' => 'Turnhalle', 'notiz' => self::SECRET]);
            Series::cancel($t, $head, '2027-01-05 19:00', true);
            $head = Entries::find($t, (int) $head['id']) ?? [];
            Series::edit($t, $head, '2027-01-12 19:00', ['titel' => 'Übungsabend spät', 'beginn' => '2027-01-12 20:00', 'ende' => '2027-01-12 22:00', 'status' => 'published'], 'this');
            $head = Entries::find($t, (int) $head['id']) ?? [];
            $a = EventActions::forEntry($t, $head, ['now' => $now, 'key' => '']);
            $eq('Serie: nächster stattfindender Termin (verschobener Einzeltermin)', [$a['state'], $a['series'], $a['keyed'], $a['key'], $a['when']],
                ['open', true, false, '20270112T1900', Calendar::when(['start' => $d('2027-01-12 20:00'), 'end' => $d('2027-01-12 22:00'), 'all_day' => false, 'has_end' => true])]);
            $eq('Serie: abgesagter Termin davor als Hinweis', $a['skipped'], Calendar::when(['start' => $d('2027-01-05 19:00'), 'end' => $d('2027-01-05 21:00'), 'all_day' => false, 'has_end' => true]));
            $eq('Serie: .ics des Vorkommens', $a['ics'], url('/kalender/' . $h . '/' . $head['slug'] . '.ics') . '?vorkommen=20270112T1900');
            $a = EventActions::forEntry($t, $head, ['now' => $now, 'key' => '20270119T1900', 'services' => ['google']]);
            parse_str((string) parse_url($a['links'][0]['url'] ?? '', PHP_URL_QUERY), $gq);
            $eq('Serie: Vorkommen aus der Adresse', [$a['state'], $a['keyed'], $gq['dates'] ?? '', str_contains((string) ($gq['details'] ?? ''), '?vorkommen=20270119T1900')],
                ['open', true, '20270119T180000Z/20270119T200000Z', true]);
            $eq('Serie: „Nur Website“ nie in Fremd-Links', str_contains(urldecode($a['links'][0]['url'] ?? ''), self::SECRET), false);
            $a = EventActions::forEntry($t, $head, ['now' => $now, 'key' => '20261208T1900']);
            $eq('Serie: vergangenes Vorkommen → nächster Termin', [$a['state'], $a['ics'], str_ends_with((string) ($a['next']['url'] ?? ''), '?vorkommen=20270112T1900')], ['past', null, true]);
            $a = EventActions::forEntry($t, $head, ['now' => $now, 'key' => '20270105T1900']);
            $eq('Serie: abgesagtes Vorkommen → „fällt aus“, nächster Termin', [$a['state'], $a['ics'], str_ends_with((string) ($a['next']['url'] ?? ''), '?vorkommen=20270112T1900')], ['cancelled', null, true]);
            $a = EventActions::forEntry($t, $head, ['now' => $now, 'key' => '20270120T1900']);
            $eq('Serie: unbekanntes Vorkommen → nächster Termin', [$a['state'], $a['keyed'], $a['key']], ['open', false, '20270112T1900']);
            $a = EventActions::forEntry($t, $head, ['now' => $d('2028-01-01 12:00'), 'key' => '']);
            $eq('Serie beendet: vorbei, kein nächster Termin', [$a['state'], $a['next'], $a['ics']], ['past', null, null]);
            // .ics eines Vorkommens: ohne RRULE, eigene UID, Zeit des Vorkommens; Einzeltermin mit seinen Werten
            [$one, $uid] = EventActions::icsOccurrence($t, $head, '20270119T1900') ?? [[], ''];
            $ics = ICal::calendar($t, [$one], ['publish' => true, 'single' => true, 'uid' => $uid]);
            $eq('Vorkommen-.ics: Zeit, keine Regel, UID je Vorkommen', [str_contains($ics, 'DTSTART;TZID=' . $tz->getName() . ':20270119T190000'), str_contains($ics, 'RRULE:FREQ=WEEKLY'),
                str_contains($ics, 'UID:' . $h . '-' . $head['id'] . '-20270119T1900@'), substr_count($ics, 'BEGIN:VEVENT'), str_contains($ics, self::SECRET)], [true, false, true, 1, false]);
            [$one, $uid] = EventActions::icsOccurrence($t, $head, '20270112T1900') ?? [[], ''];
            $ics = ICal::calendar($t, [$one], ['publish' => true, 'single' => true, 'uid' => $uid]);
            $eq('Vorkommen-.ics: verschobener Einzeltermin', [str_contains($ics, ':20270112T200000'), str_contains($ics, 'SUMMARY:Übungsabend spät'), str_contains($ics, 'RECURRENCE-ID')], [true, true, false]);
            $eq('Vorkommen-.ics: unbekannt bzw. Ausnahme → nichts', [EventActions::icsOccurrence($t, $head, '20270120T1900'), EventActions::icsOccurrence($t, $head, 'kaputt')], [null, null]);
        } catch (\Throwable $x) {
            $fails[] = 'Termin-Aktionen – Ausnahme: ' . $x->getMessage() . ' (' . basename($x->getFile()) . ':' . $x->getLine() . ')';
        } finally {
            app()->settings->override(['sys.calendar_links' => $prevLinks]);
            if ($x = Tables::find($h)) Tables::delete($x);
            Tables::flush();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
