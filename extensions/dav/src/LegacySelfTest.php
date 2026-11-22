<?php
declare(strict_types=1);

namespace Klxm\Dav;

/**
 * Teil von dav:selftest: Alt-Tabellen ohne Tabellenart (Inhaltstabellen mit „Als Adressbuch nutzen“ bzw. „Als Kalender nutzen“ und
 * freier Zuordnung) verhalten sich bitgleich wie vor dem Austausch (vCard-/ICS-Import, _vcard_extra). Die Fälle bilden eine typische
 * Alt-Einrichtung nach: Adressbuch „kontakte“ mit Nachname/Vorname/Organisation, Kalender „termine“ mit Serie, ganztägigem Termin
 * und Rohdaten aus Apps (Erinnerung, Teilnehmer, geänderter Einzeltermin). Erwartet werden die Ausgaben, die dav 1.7.0 lieferte
 * (LegacySelfTest.golden.json, ohne PRODID/REV/DTSTAMP) – jede Abweichung ist ein Fehler.
 *
 * php bin/console dav:selftest --legacy-dump schreibt die aktuellen Ausgaben (nur beim bewussten Neufassen der Erwartung).
 */
final class LegacySelfTest
{
    public const GOLDEN = __DIR__ . '/LegacySelfTest.golden.json';

    /** Erfundene Alt-Tabelle „Kontakte“ (Inhaltstabelle, keine Art) */
    public static function contactsTable(): array
    {
        $f = fn(string $name, string $type, array $x = []) => ['name' => $name, 'label' => ucfirst($name), 'type' => $type] + $x;
        return ['id' => 0, 'handle' => 'kontakte', 'table' => 'data_kontakte', 'name' => 'Kontakte', 'singular' => 'Kontakt', 'description' => '',
            'settings' => ['title_field' => 'nachname', 'workflow' => false, 'route' => '', 'kind' => 'content'],
            'fields' => [$f('nachname', 'text'), $f('vorname', 'text'), $f('firma', 'text'), $f('position', 'text'), $f('email', 'email'),
                $f('telefon', 'tel'), $f('mobil', 'tel'), $f('fax', 'tel'), $f('strasse', 'text'), $f('plz', 'text'), $f('ort', 'text'),
                $f('web', 'url'), $f('notiz', 'richtext'), $f('fachgebiet', 'text'), $f('sprechzeiten', 'textarea'), $f('aktiv', 'bool'),
                $f('bereich', 'select', ['options' => ['praxis' => 'Praxis', 'labor' => 'Labor']])]];
    }

    /** Einstellungen wie bei einer Alt-Einrichtung: Nachname/Vorname/Organisation zugeordnet, veröffentlichen, Löschen = Entwurf */
    public static function contactsSettings(): array
    {
        return ['addressbook' => true, 'caldav' => false, 'status' => 'published', 'on_delete' => 'draft', 'published_only' => false, 'org' => true,
            'map' => ['n_family' => 'nachname', 'n_given' => 'vorname', 'org' => 'firma', 'title' => 'position', 'adr_street' => 'strasse',
                'adr_zip' => 'plz', 'adr_city' => 'ort', 'url' => 'web', 'note' => 'notiz', 'categories' => 'bereich']] + Dav::TABLE_DEFAULTS;
    }

    /** Erfundene Alt-Tabelle „Termine“ (Inhaltstabelle mit „Als Kalender nutzen“, keine Art) */
    public static function eventsTable(): array
    {
        $f = fn(string $name, string $type, array $x = []) => ['name' => $name, 'label' => ucfirst($name), 'type' => $type] + $x;
        return ['id' => 0, 'handle' => 'termine', 'table' => 'data_termine', 'name' => 'Termine', 'singular' => 'Termin', 'description' => '',
            'settings' => ['title_field' => 'titel', 'workflow' => false, 'route' => '', 'kind' => 'content',
                'calendar' => ['enabled' => true, 'start' => 'beginn', 'end' => 'ende', 'all_day' => 'ganztags', 'recurrence' => 'wiederholung',
                    'location' => 'ort', 'description' => 'beschreibung', 'category' => 'art', 'duration' => 60, 'feed' => true]],
            'fields' => [$f('titel', 'text'), $f('beginn', 'datetime'), $f('ende', 'datetime'), $f('ganztags', 'bool'), $f('wiederholung', 'recurrence'),
                $f('ort', 'text'), $f('beschreibung', 'richtext'), $f('art', 'select', ['options' => ['sprechstunde' => 'Sprechstunde', 'urlaub' => 'Urlaub']])]];
    }

    /** Alle Fälle → Ausgaben (normalisiert) */
    public static function capture(): array
    {
        $out = [];
        $t = self::contactsTable();
        $s = self::contactsSettings();
        $e = ['id' => 11, 'status' => 'published', 'updated_at' => '2026-03-01 09:00:00', 'created_at' => '2026-01-01 09:00:00', 'slug' => 'musterfrau',
            'nachname' => 'Musterfrau', 'vorname' => 'Erika', 'firma' => 'Musterpraxis Musterstadt', 'position' => 'Ärztin', 'email' => 'erika@example.org',
            'telefon' => '+49 2841 555-10', 'mobil' => '0170 5550188', 'fax' => '+49 2841 555-11', 'strasse' => 'Musterweg 1', 'plz' => '12345',
            'ort' => 'Musterstadt', 'web' => 'https://example.org', 'notiz' => '<p>Sprechzeiten &amp; Termine</p><p>Mo–Fr<br>8–12 Uhr</p>',
            'fachgebiet' => 'Innere Medizin', 'sprechzeiten' => "Mo 8–12\nDi 14–18", 'aktiv' => true, 'bereich' => 'praxis'];
        $o = ['uid' => 'kontakt-11@example.org', 'uri' => 'kontakt-11.vcf', 'raw' => null];
        $out['card.fresh'] = self::norm(CardBackend::render($t, $e, $o, $s));
        $raw = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:kontakt-11@example.org\r\nFN:Dr. Erika Musterfrau\r\nN:Musterfrau;Erika;;Dr.;\r\nNICKNAME:Eri\r\n"
            . "X-SOCIALPROFILE;TYPE=twitter:https://example.org/erika\r\nitem1.X-ABDATE:2020-05-01\r\nitem1.X-ABLABEL:" . '_$!<Anniversary>!$_' . "\r\nTEL;TYPE=CELL:0170 5550188\r\nEND:VCARD\r\n";
        $out['card.raw'] = self::norm(CardBackend::render($t, $e, ['raw' => $raw] + $o, $s));
        $app = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:kontakt-11@example.org\r\nFN:Erika Beispiel\r\nN:Beispiel;Erika;;;\r\nORG:Musterpraxis Musterstadt\r\n"
            . "TITLE:Ärztin\r\nEMAIL;TYPE=INTERNET,WORK:erika@example.org\r\nTEL;TYPE=WORK,VOICE:+49 2841 555-20\r\nTEL;TYPE=CELL,VOICE:0171 5550199\r\n"
            . "ADR;TYPE=WORK:;;Neuer Weg 2;Musterstadt;;12345;Deutschland\r\nURL:https://example.org/neu\r\nNOTE:Neue Notiz\\nzweite Zeile\r\nCATEGORIES:Labor\r\n"
            . "X-MYCMS-FACHGEBIET:Kardiologie\r\nX-MYCMS-AKTIV:0\r\nNICKNAME:Eri\r\nEND:VCARD\r\n";
        [$in, $uid] = CardBackend::parse($t, $e, $app, $s);
        ksort($in);
        $out['card.parse'] = ['in' => $in, 'uid' => $uid];
        [$in] = CardBackend::parse($t, null, "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Max Mustermann\r\nTEL:+49 2841 555-30\r\nEND:VCARD\r\n", $s);
        ksort($in);
        $out['card.parse.new'] = $in;

        $ct = self::eventsTable();
        $ev = ['id' => 21, 'status' => 'published', 'updated_at' => '2026-03-01 09:00:00', 'created_at' => '2026-01-01 09:00:00', 'slug' => 'sprechstunde',
            'titel' => 'Offene Sprechstunde', 'beginn' => '2026-03-02 08:00', 'ende' => '2026-03-02 10:00', 'ganztags' => false,
            'wiederholung' => "FREQ=WEEKLY;BYDAY=MO;COUNT=10\nEXDATE:2026-03-16", 'ort' => 'Praxis, Musterweg 1', 'beschreibung' => '<p>Ohne Termin</p>', 'art' => 'sprechstunde'];
        $eo = ['uid' => 'termin-21@example.org', 'uri' => 'termin-21.ics', 'raw' => null];
        $out['event.series'] = self::norm(CalendarBackend::render($ct, $ev, $eo));
        $allDay = ['id' => 22, 'titel' => 'Praxis geschlossen', 'beginn' => '2026-07-20 00:00', 'ende' => '2026-07-31 00:00', 'ganztags' => true,
            'wiederholung' => '', 'ort' => '', 'beschreibung' => '', 'art' => 'urlaub', 'slug' => 'urlaub'] + $ev;
        $out['event.allday'] = self::norm(CalendarBackend::render($ct, $allDay, ['uid' => 'termin-22@example.org'] + $eo));
        $rawIcs = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//App//EN\r\nBEGIN:VEVENT\r\nUID:termin-21@example.org\r\nDTSTART;TZID=Europe/Berlin:20260302T080000\r\n"
            . "DTEND;TZID=Europe/Berlin:20260302T100000\r\nSUMMARY:Alt\r\nRRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=10\r\nATTENDEE;CN=Max:mailto:max@example.org\r\n"
            . "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nDESCRIPTION:Erinnerung\r\nEND:VALARM\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:termin-21@example.org\r\n"
            . "RECURRENCE-ID;TZID=Europe/Berlin:20260309T080000\r\nDTSTART;TZID=Europe/Berlin:20260309T090000\r\nDTEND;TZID=Europe/Berlin:20260309T110000\r\n"
            . "SUMMARY:Sprechstunde später\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $out['event.raw'] = self::norm(CalendarBackend::render($ct, $ev, ['raw' => $rawIcs] + $eo));
        $be = new CalendarBackend();
        $parse = new \ReflectionMethod(CalendarBackend::class, 'parse');
        [$in, $uid] = $parse->invoke($be, $ct, $ev, str_replace(['SUMMARY:Alt', 'COUNT=10'], ['SUMMARY:Sprechstunde neu', 'COUNT=12'], $rawIcs));
        ksort($in);
        $out['event.parse'] = ['in' => $in, 'uid' => $uid];
        [$in] = $parse->invoke($be, $ct, null, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:neu-1\r\nDTSTART;VALUE=DATE:20260801\r\nDTEND;VALUE=DATE:20260803\r\n"
            . "SUMMARY:Betriebsausflug\r\nLOCATION:Musterstadt\r\nCATEGORIES:Urlaub\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        ksort($in);
        $out['event.parse.new'] = $in;
        return $out;
    }

    /** vCard/iCalendar → Zeilen ohne Angaben, die sich mit Version und Uhrzeit ändern (PRODID, REV, DTSTAMP), entfaltet */
    public static function norm(string $s): array
    {
        $s = str_replace("\r\n ", '', $s);
        $lines = array_values(array_filter(explode("\r\n", $s), fn($l) => $l !== '' && !preg_match('~^(PRODID|REV|DTSTAMP)[:;]~', $l)));
        return $lines;
    }

    /** Vergleich mit der Erwartung; $dump: Erwartung neu schreiben */
    public static function run(\Closure $eq, bool $dump = false): void
    {
        $now = self::capture();
        if ($dump) {
            file_put_contents(self::GOLDEN, json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            echo "  Erwartung geschrieben: " . basename(self::GOLDEN) . "\n";
            return;
        }
        $want = json_decode((string) @file_get_contents(self::GOLDEN), true);
        if (!is_array($want)) {
            $eq('Erwartung vorhanden', false, true);
            return;
        }
        foreach ($want as $k => $w) $eq('unverändert: ' . $k, json_decode((string) json_encode($now[$k] ?? null), true), $w);
    }
}
