<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
// Vorlage der Prüfungen: KLXM/scheduler Tests ExpanderTest, SeriesEditorTest, IcalRoundTripTest (MIT, © 2026 KLXM Crossmedia GmbH)
declare(strict_types=1);

namespace Core\Data;

use Core\Http\Controllers\Admin\DataController;
use DateTimeImmutable;
use Sabre\VObject\Reader;

/**
 * Selbsttest Kalender mit Serien – `php bin/console calendar:selftest`. Mit vorübergehenden Tabellen (danach gelöscht):
 *
 *  - Vorlagen „Orte“ und „Kalender“: Art, Grundschema, feste Kalender-Zuordnung, Ort aus der Liste, Zusatzfeld „Nur Website“.
 *  - Vorkommens-Index: nach Aufbau und nach jeder Änderung gleich der Berechnung (Status, Monate, Rückfall bei abgeschaltetem Index).
 *  - Serien: nur dieser (Einzeltermin), „fällt aus“, Ausfall (EXDATE), dieser und folgende (COUNT aufgeteilt, Einzeltermine ziehen um),
 *    alle (Verschiebung samt Ausnahmen und Einzelterminen), Löschen der ganzen Serie mit Einzelterminen.
 *  - Listen und Abfragen ohne Einzeltermine; ICS mit RRULE, EXDATE, RECURRENCE-ID, STATUS:CANCELLED, Ort aus der Liste – Rundreise
 *    über sabre/vobject ergibt dieselben Termine wie die Website; Felder „Nur Website“ nie in der ICS-Datei.
 *  - data:convert --kind=calendar: Probelauf ändert nichts, Umwandlung lässt die Termine gleich, Reihenfolge/Abschnitte/Beschriftungen,
 *    Zurückdrehen stellt Felder und Einstellung wieder her.
 */
final class CalendarSelfTest
{
    private const SECRET = 'Programm-Nur-Website-Musterstadt';

    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Kalender – ');
        self::units($eq);
        if (!Calendar::enabled(['settings' => ['kind' => Kinds::CALENDAR, 'calendar' => []], 'fields' => [['name' => 'beginn', 'type' => 'datetime']]])) {
            $eq('Funktion „Kalender“ an (sonst nur Einheiten geprüft)', \Core\Features::on('calendar', false), true);
            return ['ok' => $ok, 'fails' => $fails];
        }
        $made = [];
        $sfx = bin2hex(random_bytes(3));
        $load = fn(string $h): array => Tables::find($h) ?? throw new \RuntimeException("Tabelle „{$h}“ fehlt");
        $tz = Calendar::tz();
        $d = fn(string $s) => new DateTimeImmutable($s, $tz);
        try {
            // ---------------------------------------------------------- Vorlagen
            $ph = 'selftest_orte_' . $sfx;
            $def = DataController::presetDef('places');
            $def['handle'] = $ph;
            $def['name'] = 'Selbsttest Orte';
            $def['settings']['route'] = '';
            [$clean, $err] = Tables::validate($def);
            $eq('Vorlage Orte gültig', $err, []);
            Tables::create($clean);
            $made[] = $ph;
            $pt = $load($ph);
            [$pid] = Entries::save($pt, null, ['name' => 'Vereinsheim Musterstadt', 'adresse' => [['street' => 'Musterweg 1', 'zip' => '12345', 'city' => 'Musterstadt']],
                'standort' => '51.45, 6.62', 'status' => 'published']);

            $h = 'selftest_kal_' . $sfx;
            $def = DataController::presetDef('calendar');
            $def['handle'] = $h;
            $def['name'] = 'Selbsttest Kalender';
            $def['settings']['route'] = str_replace('_', '-', $h);
            foreach ($def['fields'] as &$f) if ($f['name'] === 'ort_ref') $f['target'] = $ph;
            unset($f);
            $def['fields'][] = ['label' => 'Programm', 'name' => 'programm', 'type' => 'richtext'];
            [$clean, $err] = Tables::validate($def);
            $eq('Vorlage Kalender gültig', $err, []);
            $eq('Art, Version, feste Zuordnung', [$clean['settings']['kind'], $clean['settings']['kind_version'] ?? null, $clean['settings']['calendar']['start'], $clean['settings']['calendar']['recurrence']],
                ['calendar', 1, 'beginn', 'wiederholung']);
            Tables::create($clean);
            $made[] = $h;
            $t = $load($h);
            $eq('Kalender eingeschaltet, Grundfelder gesperrt', [Calendar::enabled($t), !empty(Tables::field($t, 'wiederholung')['system']), empty(Tables::field($t, 'programm')['system'])], [true, true, true]);
            $eq('Zusatzfeld „Nur Website“', Exposure::isWebsiteOnly(Tables::field($t, 'programm')), true);
            $cols = array_column(Tables::db($t)->fetchAll("PRAGMA table_info({$t['table']})"), 'name');
            $eq('Spalten für Serien', [in_array(Series::SERIES, $cols, true), in_array(Series::RID, $cols, true)], [true, true]);

            // ---------------------------------------------------------- Einträge
            [$sid, $e1] = Entries::save($t, null, ['titel' => 'Übungsabend', 'beginn' => '2027-01-05 19:00', 'ende' => '2027-01-05 21:00', 'wiederholung' => 'FREQ=WEEKLY;BYDAY=TU;COUNT=10',
                'ort_ref' => $pid, 'programm' => '<p>' . self::SECRET . '</p>', 'beschreibung' => 'Für alle.', 'status' => 'published']);
            [$oid, $e2] = Entries::save($t, null, ['titel' => 'Sommerfest', 'beginn' => '2027-01-23 00:00', 'ende' => '2027-01-24 00:00', 'ganztaegig' => true, 'ort' => 'Festwiese', 'status' => 'published']);
            [$did, $e3] = Entries::save($t, null, ['titel' => 'Vorstand intern', 'beginn' => '2027-01-12 18:00', 'status' => 'draft']);
            $eq('Termine gespeichert', [$e1, $e2, $e3], [[], [], []]);
            $occ = fn(array $o = []) => Calendar::occurrences($t, $d('2027-01-01'), $d('2027-05-01'), $o + ['status' => 'published']);
            $starts = fn(array $list) => array_map(fn($o) => $o['start']->format('m-d H:i') . ($o['cancelled'] ? ' fällt aus' : ''), $list);
            $eq('Serie expandiert (10 Termine + Fest)', count($occ()), 11);
            $eq('Ort aus der Liste', Calendar::place($t, Entries::find($t, (int) $sid) ?? []), ['Vereinsheim Musterstadt, Musterweg 1, 12345 Musterstadt', '51.45, 6.62']);

            // ---------------------------------------------------------- Index
            Occurrences::rebuild($t);
            $same = function (string $what) use ($eq, $t, $d) {
                $map = fn($l) => array_map(fn($o) => [$o['entry']['id'], $o['start']->format('c'), $o['end']->format('c'), $o['all_day'], $o['key'], $o['cancelled'], $o['override']], $l);
                $bad = [];
                foreach (['published', 'all', 'draft'] as $st) {
                    foreach (['2027-01', '2027-02', '2027-03'] as $m) {
                        $a = Occurrences::query($t, $d($m . '-01'), $d($m . '-01')->modify('+1 month'), ['status' => $st], 1000);
                        $b = Calendar::compute($t, $d($m . '-01'), $d($m . '-01')->modify('+1 month'), ['status' => $st]);
                        if ($a === null || $map($a) !== $map($b)) $bad[] = "$st $m";
                    }
                }
                $eq('Index = Berechnung ' . $what, $bad, []);
            };
            $eq('Index aufgebaut und aktuell', Occurrences::fresh($t), true);
            $same('nach Aufbau');

            // ---------------------------------------------------------- nur dieser
            $head = Entries::find($t, (int) $sid) ?? [];
            $in = Series::valuesFor($t, $head, '2027-01-19 19:00');
            $eq('Vorbelegung eines Vorkommens', $in, ['beginn' => '2027-01-19 19:00', 'ende' => '2027-01-19 21:00']);
            [$ov1, $err] = Series::edit($t, $head, '2027-01-19 19:00', array_merge(self::values($t, $head), ['beginn' => '2027-01-19 20:00', 'ende' => '2027-01-19 22:00', 'titel' => 'Übungsabend (später)']), 'this');
            $eq('nur dieser: Einzeltermin angelegt', [$err, (int) (Entries::find($t, (int) $ov1)[Series::SERIES] ?? 0)], [[], (int) $sid]);
            $list = $occ();
            $jan19 = array_values(array_filter($list, fn($o) => $o['start']->format('m-d') === '01-19'));
            $eq('nur dieser: Vorkommen ersetzt', [count($jan19), $jan19[0]['start']->format('H:i') ?? '', $jan19[0]['override'] ?? null, $jan19[0]['key'] ?? ''], [1, '20:00', true, $sid . '-20270119T1900']);
            $ids = array_column(Entries::query($t, ['status' => 'all']), 'id');
            sort($ids);
            $eq('Einzeltermin nicht in Listen', [$ids, Entries::count($t, ['status' => 'all'])], [[(int) $sid, (int) $oid, (int) $did], 3]);
            $same('nach „nur dieser“');

            // ---------------------------------------------------------- fällt aus, Ausfall
            Series::cancel($t, Entries::find($t, (int) $sid) ?? [], '2027-01-26 19:00', true);
            Series::remove($t, Entries::find($t, (int) $sid) ?? [], '2027-02-02 19:00', 'this');
            $s = $starts($occ());
            $eq('fällt aus bleibt sichtbar, Ausfall (EXDATE) fehlt', [in_array('01-26 19:00 fällt aus', $s, true), in_array('02-02 19:00', $s, true)], [true, false]);
            $eq('EXDATE gespeichert', Calendar::recurrence($t, Entries::find($t, (int) $sid) ?? [])['exdates'], ['2027-02-02']);
            $same('nach Ausfällen');

            // ---------------------------------------------------------- dieser und folgende
            Series::edit($t, Entries::find($t, (int) $sid) ?? [], '2027-02-23 19:00', array_merge(self::values($t, Entries::find($t, (int) $sid) ?? []), ['beginn' => '2027-02-23 19:00', 'ende' => '2027-02-23 21:00', 'titel' => 'Übungsabend Ausnahme']), 'this');
            $headNow = Entries::find($t, (int) $sid) ?? [];
            [$nid, $err] = Series::edit($t, $headNow, '2027-02-16 19:00', array_merge(self::values($t, $headNow), Series::valuesFor($t, $headNow, '2027-02-16 19:00'), ['beginn' => '2027-02-16 18:00', 'ende' => '2027-02-16 20:00']), 'following');
            $eq('folgende: neuer Serienkopf', [$err, $nid !== null && (int) $nid !== (int) $sid], [[], true]);
            $old = Entries::find($t, (int) $sid) ?? [];
            $new = Entries::find($t, (int) $nid) ?? [];
            $eq('folgende: alte Serie endet davor, COUNT aufgeteilt', [str_contains(Calendar::recurrence($t, $old)['rrule'], 'UNTIL='), str_contains(Calendar::recurrence($t, $old)['rrule'], 'COUNT'),
                Calendar::recurrence($t, $new)['rrule']], [true, false, 'FREQ=WEEKLY;BYDAY=TU;COUNT=4']);
            $s = $starts($occ());
            $eq('folgende: Zeiten', [in_array('02-09 19:00', $s, true), in_array('02-16 18:00', $s, true), in_array('03-09 18:00', $s, true), in_array('03-16 18:00', $s, true)], [true, true, true, false]);
            $ovMoved = Series::overrides($t, (int) $nid);
            $eq('folgende: Einzeltermin zieht um (verschoben)', array_keys($ovMoved), ['2027-02-23 18:00']);
            $eq('folgende: gleich viele Termine (10 − 1 Ausfall + Fest)', count($occ()), 10);
            $same('nach „dieser und folgende“');

            // ---------------------------------------------------------- alle (Uhrzeit verschieben)
            $new = Entries::find($t, (int) $nid) ?? [];
            Series::edit($t, $new, '2027-03-02 18:00', array_merge(self::values($t, $new), ['beginn' => '2027-03-02 17:30', 'ende' => '2027-03-02 19:30']), 'all');
            $new = Entries::find($t, (int) $nid) ?? [];
            $eq('alle: Kopf verschoben, Einzeltermin mitverschoben', [$new['beginn'], array_keys(Series::overrides($t, (int) $nid))], ['2027-02-16 17:30', ['2027-02-23 17:30']]);
            $same('nach „alle“');

            // ---------------------------------------------------------- ICS
            $ics = ICal::calendar($t, Entries::query($t, ['status' => 'published', 'limit' => 100]), ['publish' => true]);
            $eq('ICS: RRULE, EXDATE, RECURRENCE-ID, abgesagt, Ort aus der Liste', [str_contains($ics, 'RRULE:'), str_contains($ics, 'EXDATE;TZID='), substr_count($ics, 'RECURRENCE-ID;TZID=') >= 2,
                str_contains($ics, 'STATUS:CANCELLED'), str_contains($ics, 'LOCATION:Vereinsheim Musterstadt'), str_contains($ics, 'GEO:51.45;6.62')], [true, true, true, true, true, true]);
            $eq('ICS: „Nur Website“ fehlt', str_contains(str_replace("\r\n ", '', $ics), self::SECRET), false);
            $eq('Website: „Nur Website“ da', str_contains((string) (Exposure::publicEntry($t, Entries::find($t, (int) $sid) ?? [])['programm'] ?? ''), self::SECRET), true);
            $vc = Reader::read($ics);
            $exp = $vc->expand(new \DateTime('2027-01-01', $tz), new \DateTime('2027-05-01', $tz));
            $fromIcs = [];
            foreach ($exp->select('VEVENT') as $ev) {
                $st = \DateTimeImmutable::createFromInterface($ev->DTSTART->getDateTime($tz))->setTimezone($tz);
                $fromIcs[] = $st->format('m-d H:i') . ((string) ($ev->STATUS ?? '') === 'CANCELLED' ? ' fällt aus' : '');
            }
            sort($fromIcs);
            $web = $starts($occ());
            sort($web);
            $eq('ICS-Rundreise = Website', $fromIcs, $web);

            // ---------------------------------------------------------- CalDAV (Erweiterung dav, falls geladen)
            if (class_exists(\Klxm\Dav\CalendarBackend::class)) {
                $cal = \Klxm\Dav\CalendarBackend::render($t, Entries::find($t, (int) $nid) ?? [], null);
                $eq('CalDAV: Serie mit Einzeltermin, ohne „Nur Website“', [str_contains($cal, 'RECURRENCE-ID'), str_contains(str_replace("\r\n ", '', $cal), self::SECRET)], [true, false]);
                // aus der App: Einzeltermin am 09.03. auf 20:00 verschoben → Einzeltermin im CMS; der vom 23.02. fehlt → wieder normal
                $vc = Reader::read($cal);
                foreach ($vc->select('VEVENT') as $ev) if (isset($ev->{'RECURRENCE-ID'})) $vc->remove($ev);
                $ex = $vc->add('VEVENT', ['UID' => (string) $vc->VEVENT->UID, 'SUMMARY' => 'Übungsabend App', 'DTSTART' => new \DateTime('2027-03-09 20:00', $tz), 'DTEND' => new \DateTime('2027-03-09 22:00', $tz)]);
                $ex->add('RECURRENCE-ID', new \DateTime('2027-03-09 17:30', $tz));
                $b = new \Klxm\Dav\CalendarBackend();
                $parse = new \ReflectionMethod($b, 'parse');
                $sync = new \ReflectionMethod($b, 'sync');
                [$in] = $parse->invoke($b, $t, Entries::find($t, (int) $nid), $vc->serialize());
                Entries::save($t, (int) $nid, $in);
                $sync->invoke($b, $t, (int) $nid);
                $ovs = Series::overrides($t, (int) $nid);
                $eq('CalDAV: in der App geänderter Termin wird Einzeltermin', [array_keys($ovs), $ovs['2027-03-09 17:30']['beginn'] ?? null], [['2027-03-09 17:30'], '2027-03-09 20:00']);
                $same('nach CalDAV');
            }

            // ---------------------------------------------------------- Rückfall ohne Index
            Occurrences::$disabled = true;
            $eq('Rückfall ohne Index', $starts($occ()), $starts(Calendar::compute($t, $d('2027-01-01'), $d('2027-05-01'), ['status' => 'published'])));
            Occurrences::$disabled = false;

            // ---------------------------------------------------------- Serie löschen
            Series::remove($t, Entries::find($t, (int) $nid) ?? [], '2027-03-02 17:30', 'all');
            $eq('alle löschen: Kopf und Einzeltermine weg', [Entries::find($t, (int) $nid), Series::overrides($t, (int) $nid)], [null, []]);
            $same('nach Löschen');

            // ---------------------------------------------------------- data:convert --kind=calendar
            self::convert($eq, $sfx, $made);
        } catch (\Throwable $x) {
            $fails[] = 'Kalender – Ausnahme: ' . $x->getMessage() . ' (' . basename($x->getFile()) . ':' . $x->getLine() . ')';
        } finally {
            Occurrences::$disabled = false;
            foreach (array_reverse($made) as $mh) {
                if ($x = Tables::find($mh)) Tables::delete($x);
                foreach (glob(site()->storage('convert') . '/' . $mh . '*') ?: [] as $file) @unlink($file);
            }
            Tables::flush();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }

    /** Formularwerte eines Eintrags (wie aus der Eingabemaske) */
    private static function values(array $t, array $e): array
    {
        $in = [];
        foreach ($t['fields'] as $f) if (array_key_exists($f['name'], $e)) $in[$f['name']] = $e[$f['name']];
        return $in + ['status' => $e['status'] ?? 'published'];
    }

    /** Inhaltstabelle mit Kalender-Einstellung → Kalender und zurück */
    private static function convert(\Closure $eq, string $sfx, array &$made): void
    {
        $h = 'selftest_kconv_' . $sfx;
        [$def, $err] = Tables::validate(['name' => 'Selbsttest Umwandlung', 'handle' => $h, 'fields' => [
            ['label' => 'Termin', 'name' => 'abschnitt_1', 'type' => 'section'],
            ['label' => 'Was', 'name' => 'was', 'type' => 'text', 'required' => 1],
            ['label' => 'Art', 'name' => 'kategorie', 'type' => 'select', 'options' => "a=Training\nb=Fest"],
            ['label' => 'Wann?', 'name' => 'abschnitt_2', 'type' => 'section'],
            ['label' => 'Von', 'name' => 'von', 'type' => 'datetime'],
            ['label' => 'Bis', 'name' => 'bis', 'type' => 'datetime'],
            ['label' => 'Ort', 'name' => 'ort', 'type' => 'text'],
            ['label' => 'Mehr', 'name' => 'abschnitt_3', 'type' => 'section'],
            ['label' => 'Text', 'name' => 'beschreibung', 'type' => 'richtext'],
        ], 'settings' => ['title_field' => 'was', 'route' => '', 'calendar' => ['enabled' => 1, 'start' => 'von', 'end' => 'bis', 'location' => 'ort', 'category' => 'kategorie', 'description' => 'beschreibung']]]);
        $eq('Umwandlung: Ausgangstabelle gültig', $err, []);
        Tables::create($def);
        $made[] = $h;
        $t = Tables::find($h) ?? throw new \RuntimeException('Tabelle fehlt');
        Entries::save($t, null, ['was' => 'Training', 'von' => '2027-02-01 18:00', 'bis' => '2027-02-01 19:30', 'kategorie' => 'a', 'ort' => 'Halle', 'beschreibung' => '<p>Mit <b>Ball</b></p>', 'status' => 'published']);
        Entries::save($t, null, ['was' => 'Fest', 'von' => '2027-02-10 15:00', 'kategorie' => 'b', 'status' => 'published']);
        $tz = Calendar::tz();
        $snap = fn(array $t) => array_map(fn($o) => [$o['start']->format('c'), $o['end']->format('c'), Entries::title($t, $o['entry']), Calendar::place($t, $o['entry'])[0]],
            Calendar::compute($t, new DateTimeImmutable('2027-01-01', $tz), new DateTimeImmutable('2027-04-01', $tz)));
        $before = $snap($t);
        $fieldsBefore = array_map(fn($f) => [$f['name'], $f['label'], !empty($f['required'])], Tables::allFields($t));
        $plan = Convert::plan($t, Kinds::CALENDAR);
        $eq('Probelauf: Zuordnung', [$plan['ok'], $plan['fields']['von']['to'] ?? null, $plan['fields']['kategorie']['action'] ?? null, $plan['fields']['was']['to'] ?? null, $plan['fields']['beschreibung']['action'] ?? null, $plan['fields']['beschreibung']['own_to'] ?? null],
            [true, 'beginn', 'cast', 'titel', 'copy', 'beschreibung_web']);
        $eq('Probelauf: Beschriftung aus dem Grundschema', [$plan['fields']['was']['label_to'] ?? null, $plan['fields']['von']['label_to'] ?? null], ['Titel', 'Beginn']);
        $eq('Probelauf ändert nichts', Kinds::of(Tables::find($h) ?? []), 'content');
        Convert::apply($t, $plan);
        $nt = Tables::find($h) ?? [];
        $eq('Umwandlung: Art Kalender', Kinds::of($nt), 'calendar');
        $eq('Umwandlung: Termine gleich', $snap($nt), $before);
        $all = Tables::allFields($nt);
        $eq('Umwandlung: beginnt mit Abschnitt „Termin“, Altfeld am Ende', [$all[0]['type'] ?? '', $all[0]['label'] ?? '', !empty(end($all)['legacy'])], ['section', 'Termin', true]);
        $eq('Umwandlung: leerer Abschnitt entfällt', in_array('Wann?', array_column($all, 'label'), true), false);
        $eq('Umwandlung: Kategorie als Mehrfachauswahl mit Werten', [Tables::field($nt, 'kategorie')['type'] ?? '', array_keys((array) (Tables::field($nt, 'kategorie')['options'] ?? [])),
            Entries::query($nt, ['sort' => 'beginn'])[0]['kategorie'] ?? null], ['multiselect', ['a', 'b'], ['a']]);
        $eq('Umwandlung: Beschreibung als Text, Rich-Text bleibt „Nur Website“', [Entries::query($nt, ['sort' => 'beginn'])[0]['beschreibung'] ?? null,
            Exposure::isWebsiteOnly(Tables::field($nt, 'beschreibung_web'))], ['Mit Ball', true]);
        Convert::revert($nt);
        $rt = Tables::find($h) ?? [];
        $eq('Zurück: Art, Felder, Reihenfolge, Beschriftungen, Pflicht', [Kinds::of($rt), array_values(array_filter(array_map(fn($f) => [$f['name'], $f['label'], !empty($f['required'])], Tables::allFields($rt)),
            fn($f) => in_array($f[0], array_column($fieldsBefore, 0), true)))], ['content', $fieldsBefore]);
        $eq('Zurück: Kalender-Einstellung und Termine', [Calendar::config($rt)['start'], $snap($rt)], ['von', $before]);
    }

    /** Ohne Datenbank */
    private static function units(\Closure $eq): void
    {
        $eq('Schlüssel ↔ ursprünglicher Beginn', [Series::ridFromKey('20270119T1900'), Calendar::ridKey('2027-01-19 19:00'), Series::ridFromKey('quatsch')], ['2027-01-19 19:00', '20270119T1900', null]);
        $eq('Wert verschieben (Format bleibt)', [Series::shiftValue('2027-01-05 19:00', new \DateInterval('P1DT1H')), Series::shiftValue('2027-01-05', new \DateInterval('P2D'))], ['2027-01-06 20:00', '2027-01-07']);
        $eq('Grundschema Kalender', array_values(array_intersect(['titel', 'beginn', 'ende', 'ganztaegig', 'wiederholung', 'kategorie', 'ort', 'ort_ref', 'standort', 'beschreibung', 'abgesagt'], Kinds::systemNames(Kinds::CALENDAR))),
            ['titel', 'beginn', 'ende', 'ganztaegig', 'wiederholung', 'kategorie', 'ort', 'ort_ref', 'standort', 'beschreibung', 'abgesagt']);
        $eq('Feste Zuordnung', Kinds::calendarSettings(['start' => 'x', 'feed' => false])['start'] . '/' . (Kinds::calendarSettings(['feed' => false])['feed'] ? '1' : '0'), 'beginn/0');
        $err = [];
        $in = Kinds::enforceInput(Kinds::CALENDAR, [['label' => 'Vita', 'type' => 'richtext'], ['label' => 'Alles', 'name' => 'alles', 'type' => 'text', 'exposure' => ['mode' => 'always']]], $err);
        $eq('Zusatzfeld ohne Angabe = „Nur Website“, ausdrückliche Angabe bleibt', [$in[0]['exposure']['ics'] ?? null, $in[1]['exposure'] ?? null], [0, ['mode' => 'always']]);
    }
}
