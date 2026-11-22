<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
// Vorlage der Prüfungen: KLXM/contacts VCardTest, KLXM/scheduler IcalRoundTripTest (MIT, © 2026 KLXM Crossmedia GmbH)
declare(strict_types=1);

namespace Core\Data;

use Core\Http\Controllers\Admin\DataController;
use DateTimeImmutable;

/**
 * Selbsttest Austausch – `php bin/console exchange:selftest`. Mit vorübergehenden Tabellen (danach gelöscht), erfundenen Daten:
 *
 *  - vCard: Probelauf ändert nichts; Import mit Mehrfachfeldern, Beschriftungen, Gruppen-Karten und CATEGORIES → Listen, Foto →
 *    Mediathek, unbekannte Eigenschaften → _vcard_extra; erneuter Import = unverändert (UID); Änderung = aktualisiert, Freigabe einer
 *    unveränderten Angabe bleibt; Export → Import → Export identisch inkl. Zusatzangaben; eigene UID des CMS wird erkannt.
 *  - ICS: Serie mit EXDATE und geänderten/abgesagten Einzelterminen, ganztägig, Zeitzonen (New York, UTC, schwebend), nicht
 *    unterstützte Regel; erneuter Import = unverändert; geänderte Datei: Einzeltermin entfernt/neu; Export → Import ergibt dieselben
 *    Termine; Abo (Quelle) mit Herkunft, fehlender Termin ausgeblendet, wieder da, gelöscht; Format „ics“ für Externe Quellen.
 *  - Sicherheit: SSRF (nur http(s)/webcal, keine internen Adressen), Skripte in importierten Feldern bleiben Text, _vcard_extra nie öffentlich.
 *  - Alt-Tabellen ohne Tabellenart: keine neuen Spalten, UID wie bisher.
 */
final class ExchangeSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Austausch – ');
        $made = [];
        $media = [];
        $sfx = bin2hex(random_bytes(3));
        try {
            self::security($eq);
            self::legacy($eq, $sfx, $made);
            self::vcard($eq, $sfx, $made, $media);
            if (\Core\Features::on('calendar', false)) self::ics($eq, $sfx, $made);
            else $eq('Funktion „Kalender“ an (sonst ICS nicht geprüft)', true, true);
        } catch (\Throwable $x) {
            $fails[] = 'Austausch – Ausnahme: ' . $x->getMessage() . ' (' . basename($x->getFile()) . ':' . $x->getLine() . ')';
        } finally {
            foreach ($made as $h) if ($t = Tables::find($h)) Tables::delete($t);
            foreach ($media as $id) try { \Core\Media::delete($id, true); } catch (\Throwable) {}
            try {
                foreach (app()->db->fetchAll("SELECT id FROM ext_sources WHERE name LIKE 'Selbsttest Austausch%'") as $r) {
                    foreach (['ext_source_items', 'ext_source_log', 'ext_source_media'] as $x) app()->db->query("DELETE FROM $x WHERE source_id = ?", [(int) $r['id']]);
                    app()->db->query('DELETE FROM ext_sources WHERE id = ?', [(int) $r['id']]);
                }
            } catch (\Throwable) {
            }
            Tables::flush();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }

    // ================================================================== Sicherheit

    private static function security(\Closure $eq): void
    {
        $eq('Adressen: webcal → https, nur http(s), keine Zugangsdaten', [Exchange::normUrl('webcal://example.org/a.ics'), Exchange::normUrl('webcals://example.org/a.ics'),
            Exchange::normUrl('file:///etc/passwd'), Exchange::normUrl('ftp://example.org/a.ics'), Exchange::normUrl('https://nutzer:pw@example.org/a.ics'),
            Exchange::normUrl('javascript:alert(1)'), Exchange::normUrl('gopher://127.0.0.1:25/')],
            ['https://example.org/a.ics', 'https://example.org/a.ics', null, null, null, null, null]);
        $blocked = [];
        foreach (['http://127.0.0.1/kalender.ics', 'http://localhost:8099/kalender.ics', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/a.ics',
            'http://[::1]/a.ics', 'http://0x7f000001/a.ics', 'http://192.168.1.1:22/', 'webcal://127.0.0.1/a.ics'] as $u) {
            [$body, $err] = Exchange::fromUrl($u);
            if ($body === null && $err !== null) $blocked[] = $u;
        }
        $eq('SSRF: interne Adressen gesperrt (einmaliger Abruf)', count($blocked), 8);
        [, $errs] = \Core\Sources\Sources::normalize(null, ['name' => 'x', 'format' => 'ics', 'url' => 'webcal://example.org/kal.ics']);
        [$v] = \Core\Sources\Sources::normalize(null, ['name' => 'x', 'format' => 'ics', 'url' => 'webcal://example.org/kal.ics']);
        $eq('Quelle: webcal wird https, ICS ohne Adresse erlaubt (Upload)', [$v['url'], isset($errs['url']), isset(\Core\Sources\Sources::normalize(null, ['name' => 'x', 'format' => 'ics', 'url' => ''])[1]['url'])],
            ['https://example.org/kal.ics', false, false]);
        $eq('Quelle: file:// abgelehnt', isset(\Core\Sources\Sources::normalize(null, ['name' => 'x', 'format' => 'ics', 'url' => 'file:///etc/passwd'])[1]['url']), true);
        $eq('Fetch kennt Art ics (text/calendar)', [\Core\Http\Fetch::typeAllowed('text/calendar; charset=utf-8', 'ics'), \Core\Http\Fetch::typeAllowed('text/html', 'ics')], [true, false]);
        $eq('Dateien: Kopfzeile geprüft', [Exchange::looksLike("\xEF\xBB\xBFBEGIN:VCARD\r\n", 'vcf'), Exchange::looksLike('<html>', 'vcf'), Exchange::looksLike("BEGIN:VCALENDAR\r\n", 'ics'), Exchange::looksLike("BEGIN:VCARD\r\n", 'ics')],
            [true, false, true, false]);
    }

    // ================================================================== Alt-Tabellen

    private static function legacy(\Closure $eq, string $sfx, array &$made): void
    {
        $h = 'selftest_altkontakte_' . $sfx;
        [$clean, $err] = Tables::validate(['name' => 'Selbsttest Alt-Kontakte', 'singular' => 'Kontakt', 'handle' => $h, 'icon' => 'users', 'description' => '',
            'fields' => [['label' => 'Nachname', 'type' => 'text', 'required' => 1, 'in_list' => 1], ['label' => 'Vorname', 'type' => 'text'], ['label' => 'Firma', 'type' => 'text'],
                ['label' => 'E-Mail', 'type' => 'email'], ['label' => 'Telefon', 'type' => 'tel'], ['label' => 'Beginn', 'type' => 'datetime'], ['label' => 'Wiederholung', 'type' => 'recurrence']],
            'settings' => ['route' => '', 'title_field' => 'nachname', 'calendar' => ['enabled' => true, 'start' => 'beginn', 'recurrence' => 'wiederholung']]]);
        $eq('Alt-Tabelle gültig', $err, []);
        Tables::create($clean);
        $made[] = $h;
        $t = Tables::find($h) ?? throw new \RuntimeException('Alt-Tabelle fehlt');
        $cols = array_column(Tables::db($t)->fetchAll("PRAGMA table_info({$t['table']})"), 'name');
        $eq('Alt-Tabelle: keine Spalten _uid, _vcard_extra, _series', array_values(array_intersect([VCards::UID, VCards::EXTRA, Series::SERIES], $cols)), []);
        $eq('Alt-Tabelle: Austausch-Import nicht angeboten', Exchange::kind($t), null);
        $eq('Alt-Tabelle: UID wie bisher (auch mit _uid im Eintrag)', Calendar::uid($t, ['id' => 7, VCards::UID => 'fremd@example.org']) === $h . '-7@' . (parse_url(site_url(), PHP_URL_HOST) ?: 'localhost'), true);
        $e = ['id' => 7, 'nachname' => 'Musterfrau', VCards::EXTRA => "NICKNAME:x\r\n"];
        $eq('Freigabe: _vcard_extra nie öffentlich', array_key_exists(VCards::EXTRA, Exposure::publicEntry($t, $e)), false);
        $eq('vCard-/ICS-Import verweigert Alt-Tabellen', [VCards::import($t, "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:x\r\nEND:VCARD\r\n", ['dry' => true])['ok'],
            IcsImport::import($t, "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", ['dry' => true])['ok']], [false, false]);
    }

    // ================================================================== vCard

    /** Foto je Lauf neu (Farbe zufällig), innerhalb eines Laufs gleich – sonst gälte es als schon übernommen */
    private static function png(): string
    {
        static $png = null;
        if ($png !== null) return $png;
        $im = imagecreatetruecolor(24, 24);
        imagefill($im, 0, 0, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        ob_start();
        imagepng($im);
        return $png = (string) ob_get_clean();
    }

    private static function vcardFile(string $phone = '+49 2841 555-10'): string
    {
        $photo = base64_encode(self::png());
        $erika = implode("\r\n", ['BEGIN:VCARD', 'VERSION:3.0', 'PRODID:-//Example Corp.//Adressbuch 1.0//DE', 'UID:erika-musterfrau-0001',
            'N:Musterfrau;Erika;;Dr.;', 'FN:Dr. Erika Musterfrau', 'ORG:Musterverein e. V.;Vorstand', 'TITLE:Vorsitz',
            'TEL;TYPE=CELL:0170 5550188', 'item1.TEL:' . $phone, 'item1.X-ABLabel:Geschäftsstelle', 'EMAIL;TYPE=INTERNET,WORK:erika@example.org',
            'ADR;TYPE=HOME:;;Musterweg 1;Musterstadt;;12345;Deutschland', 'URL:https://example.org/erika', 'URL:javascript:alert(1)',
            'BDAY:1970-04-01', 'NOTE:Bitte vormittags anrufen.\nDanke!', 'CATEGORIES:Vorstand,Presse', 'PHOTO;ENCODING=b;TYPE=PNG:' . $photo,
            'NICKNAME:Eri', 'X-SOCIALPROFILE;TYPE=mastodon:https://social.example.org/@erika', 'item2.X-ABDATE:2001-06-15', 'item2.X-ABLabel:_$!<Anniversary>!$_',
            'X-MYCMS-MITGLIEDSNUMMER:4711', 'X-MUSTER-FELD;SPRACHE=de:Wert\, mit Komma', 'END:VCARD']);
        $max = implode("\r\n", ['BEGIN:VCARD', 'VERSION:4.0', 'UID:urn:uuid:5a1f0c3e-0000-4000-8000-00000000abcd', 'FN:<script>alert(1)</script> Max',
            'N:<b>Mustermann</b>;Max;;;', 'EMAIL;TYPE=home:max@example.org', 'END:VCARD']);
        $org = implode("\r\n", ['BEGIN:VCARD', 'VERSION:3.0', 'UID:musterdruck-gmbh', 'FN:Musterdruck GmbH', 'N:;;;;', 'ORG:Musterdruck GmbH', 'X-ABShowAs:COMPANY',
            'TEL;TYPE=WORK,VOICE:+49 2841 555-30', 'END:VCARD']);
        $group = implode("\r\n", ['BEGIN:VCARD', 'VERSION:3.0', 'UID:gruppe-jugend', 'FN:Jugend', 'N:Jugend;;;;', 'X-ADDRESSBOOKSERVER-KIND:group',
            'X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:urn:uuid:5a1f0c3e-0000-4000-8000-00000000abcd', 'END:VCARD']);
        return $erika . "\r\n" . $max . "\r\n" . $org . "\r\n" . $group . "\r\n";
    }

    /** vCard-Zeilen ohne PRODID/REV, entfaltet, sortiert – PHOTO nur als „vorhanden“ */
    private static function lines(string $vcf): array
    {
        $out = [];
        foreach (explode("\r\n", str_replace("\r\n ", '', $vcf)) as $l) {
            if ($l === '' || preg_match('~^(PRODID|REV)[:;]~', $l)) continue;
            $out[] = str_starts_with($l, 'PHOTO') ? 'PHOTO' : $l;
        }
        sort($out);
        return $out;
    }

    private static function contactsTable(string $h, string $name, array &$made): array
    {
        $def = DataController::presetDef('contacts');
        $def['handle'] = $h;
        $def['name'] = $name;
        $def['settings']['route'] = '';
        foreach ($def['fields'] as &$f) if (($f['name'] ?? '') === 'listen') $f['options'] = 'Vorstand';
        unset($f);
        $def['fields'][] = ['label' => 'Mitgliedsnummer', 'name' => 'mitgliedsnummer', 'type' => 'text'];
        [$clean, $err] = Tables::validate($def);
        if ($err) throw new \RuntimeException('Adressbuch ungültig: ' . json_encode($err, JSON_UNESCAPED_UNICODE));
        Tables::create($clean);
        $made[] = $h;
        return Tables::find($h) ?? throw new \RuntimeException('Tabelle fehlt');
    }

    private static function vcard(\Closure $eq, string $sfx, array &$made, array &$media): void
    {
        $h = 'selftest_vcf_' . $sfx;
        $t = self::contactsTable($h, 'Selbsttest vCard', $made);
        $cols = array_column(Tables::db($t)->fetchAll("PRAGMA table_info({$t['table']})"), 'name');
        $eq('Adressbuch: Spalten _uid und _vcard_extra', [in_array(VCards::UID, $cols, true), in_array(VCards::EXTRA, $cols, true)], [true, true]);
        $file = self::vcardFile();

        $dry = VCards::import($t, $file, ['dry' => true, 'lists' => true]);
        $eq('Probelauf: 3 Kontakte neu, 1 Gruppe, neue Listen', [$dry['ok'], $dry['total'], $dry['created'], $dry['groups'], $dry['new_lists'], $dry['photos']],
            [true, 3, 3, 1, ['Presse', 'Jugend'], 1]);
        $eq('Probelauf ändert nichts', [Entries::count($t, ['status' => 'all']), array_keys(AddressBook::lists(Tables::find($h) ?? $t))], [0, ['vorstand']]);
        $noSchema = VCards::import($t, $file, ['dry' => true, 'lists' => false]);
        $eq('ohne Recht auf Felder: fehlende Listen nur gemeldet', [$noSchema['new_lists'], $noSchema['missing_lists']], [[], ['Presse', 'Jugend']]);

        $rep = VCards::import($t, $file, ['lists' => true]);
        $eq('Import: 3 neu, keine Fehler', [$rep['ok'], $rep['created'], $rep['failed'], $rep['photos']], [true, 3, 0, 1]);
        $t = Tables::find($h) ?? $t;
        $eq('Listen angelegt', array_values(AddressBook::lists($t)), ['Vorstand', 'Presse', 'Jugend']);
        $byUid = [];
        foreach (Entries::query($t, ['status' => 'all', 'internal' => true]) as $e) $byUid[(string) $e[VCards::UID]] = $e;
        $erika = $byUid['erika-musterfrau-0001'] ?? throw new \RuntimeException('Erika fehlt');
        $max = $byUid['urn:uuid:5a1f0c3e-0000-4000-8000-00000000abcd'] ?? throw new \RuntimeException('Max fehlt');
        $org = $byUid['musterdruck-gmbh'] ?? throw new \RuntimeException('Organisation fehlt');
        if (!empty($erika['foto'])) $media[] = (int) $erika['foto'];
        $eq('Name, Organisation, Funktion', [$erika['praefix'], $erika['vorname'], $erika['nachname'], $erika['firma'], $erika['abteilung'], $erika['position'], $erika['name']],
            ['Dr.', 'Erika', 'Musterfrau', 'Musterverein e. V.', 'Vorstand', 'Vorsitz', 'Dr. Erika Musterfrau']);
        $eq('Mehrfachfelder mit Beschriftung, alle intern', [array_map(fn($i) => [$i['label'], $i['value'], \Core\Combo::publicScope('phones', $i)], $erika['telefon']),
            array_map(fn($i) => [$i['label'], $i['value']], $erika['email']), array_map(fn($i) => [$i['label'], $i['city']], $erika['adresse'])],
            [[['mobile', '0170 5550188', ''], ['office', '+49 2841 555-10', '']], [['work', 'erika@example.org']], [['home', 'Musterstadt']]]);
        $eq('Webadressen nur http(s) (javascript: verworfen)', array_column($erika['web'], 'value'), ['https://example.org/erika']);
        $eq('Geburtstag, Notiz, eigenes Feld', [$erika['geburtstag'], $erika['notiz'], $erika['mitgliedsnummer']], ['1970-04-01', "Bitte vormittags anrufen.\nDanke!", '4711']);
        $eq('Listen aus CATEGORIES und Gruppen-Karte', [$erika['listen'], $max['listen']], [['vorstand', 'presse'], ['jugend']]);
        $eq('Foto in der Mediathek', (int) $erika['foto'] > 0 && \Core\Media::find((int) $erika['foto']) !== null, true);
        $eq('Organisation erkannt', [(bool) $org['ist_firma'], $org['name']], [true, 'Musterdruck GmbH']);
        $eq('Neue Kontakte nicht auf der Website', [(bool) $erika['oeffentlich'], (bool) $max['oeffentlich']], [false, false]);
        $extra = (string) $erika[VCards::EXTRA];
        $eq('Zusatzangaben: unbekannte Eigenschaften', [str_contains($extra, 'NICKNAME:Eri'), str_contains($extra, 'X-SOCIALPROFILE;TYPE=mastodon:'), str_contains($extra, 'ITEM2.X-ABDATE'),
            str_contains($extra, 'ITEM2.X-ABLABEL'), str_contains($extra, 'X-MUSTER-FELD;SPRACHE=de:Wert\, mit Komma')], [true, true, true, true, true]);
        $eq('Zusatzangaben ohne abgebildete Eigenschaften', [str_contains($extra, 'TEL'), str_contains($extra, 'ITEM1'), str_contains($extra, 'X-MYCMS'), str_contains($extra, 'PHOTO'), str_contains($extra, 'FN:')],
            [false, false, false, false, false]);
        $eq('XSS: HTML aus der Datei entfernt, Ausgabe escaped', [$max['name'], $max['nachname'], str_contains(e(Entries::title($t, $max)), '<script')],
            ['alert(1) Max', 'Mustermann', false]);
        $pub = Exposure::publicEntry($t, ['oeffentlich' => true, 'status' => 'published'] + $erika);
        $eq('Zusatzangaben nie öffentlich (Website, Visitenkarte)', [array_key_exists(VCards::EXTRA, $pub), str_contains(\Core\VCard::person($t, ['oeffentlich' => true, 'status' => 'published'] + $erika), 'NICKNAME')],
            [false, false]);

        $again = VCards::import($t, $file, ['lists' => true]);
        $eq('Erneuter Import: unverändert, keine Dubletten', [$again['created'], $again['updated'], $again['unchanged'], Entries::count($t, ['status' => 'all'])], [0, 0, 3, 3]);
        // eine Angabe freigeben, dann die andere Nummer ändern: Freigabe der unveränderten Angabe bleibt, die neue ist intern
        $tel = $erika['telefon'];
        $tel[0]['public'] = '1';
        Entries::save($t, (int) $erika['id'], ['telefon' => $tel]);
        $chg = VCards::import($t, self::vcardFile('+49 2841 555-99'), ['lists' => true]);
        $e2 = Entries::find($t, (int) $erika['id']) ?? [];
        $eq('Geänderte Datei: aktualisiert', [$chg['updated'], $chg['unchanged'], array_column($chg['rows'], 'detail')[0] ?? ''], [1, 2, 'Telefon']);
        $eq('Freigabe: unveränderte bleibt, neue Angabe intern', array_map(fn($i) => [$i['value'], \Core\Combo::publicScope('phones', $i)], $e2['telefon']),
            [['0170 5550188', 'full'], ['+49 2841 555-99', '']]);

        // Export → Import → Export identisch (inkl. Zusatzangaben und eigener Felder)
        $x1 = VCards::export($t, $e2);
        foreach (explode("\r\n", rtrim($extra, "\r\n")) as $l) if ($l !== '' && !str_contains(str_replace("\r\n ", '', $x1), str_replace("\r\n ", '', $l))) $eq('Export enthält Zusatzzeile ' . $l, false, true);
        $eq('Export: UID, eigenes Feld, Beschriftung, kein javascript:', [str_contains($x1, 'UID:erika-musterfrau-0001'), str_contains($x1, 'X-MYCMS-MITGLIEDSNUMMER:4711'),
            str_contains($x1, 'X-ABLABEL:Geschäftsstelle'), str_contains($x1, 'javascript')], [true, true, true, false]);
        $eq('Export: Gruppen der Zusatzangaben und Angaben getrennt', [substr_count($x1, 'ITEM2.'), substr_count($x1, 'item3.') + substr_count($x1, 'ITEM3.') > 0], [2, true]);
        $h2 = 'selftest_vcf2_' . $sfx;
        $t2 = self::contactsTable($h2, 'Selbsttest vCard 2', $made);
        $r2 = VCards::import($t2, $x1, ['lists' => true]);
        $e3 = Entries::query($t2, ['status' => 'all', 'internal' => true])[0] ?? [];
        if (!empty($e3['foto']) && (int) $e3['foto'] !== (int) $e2['foto']) $media[] = (int) $e3['foto'];
        $x2 = VCards::export(Tables::find($h2) ?? $t2, $e3);
        $eq('Rundreise: Import der Exportdatei', [$r2['created'], $r2['failed']], [1, 0]);
        $eq('Rundreise: Export → Import → Export identisch inkl. Zusatzangaben', self::lines($x2), self::lines($x1));
        $eq('Rundreise: Zusatzangaben bytegleich', (string) $e3[VCards::EXTRA], (string) $e2[VCards::EXTRA]);

        // Kontakt aus dem CMS (ohne UID): Export trägt die UID des CMS, erneuter Import erkennt ihn
        [$own] = Entries::save($t, null, ['vorname' => 'Lena', 'nachname' => 'Beispiel', 'status' => 'published']);
        $ownCard = VCards::export($t, Entries::find($t, (int) $own) ?? []);
        $r3 = VCards::import($t, $ownCard, ['lists' => true]);
        $eq('Eigene UID des CMS erkannt (keine Dublette)', [$r3['created'], $r3['unchanged'], (string) (Entries::find($t, (int) $own)[VCards::UID] ?? '')], [0, 1, '']);
        $exp = VCards::exportMany($t, array_map(fn($e) => (int) $e['id'], Entries::query($t, ['status' => 'all'])));
        $eq('Export gesamt', substr_count($exp, 'BEGIN:VCARD'), 4);
        $eq('Fehlerhafte Dateien', [VCards::import($t, 'kein vcard')['error'] !== null, VCards::import($t, "BEGIN:VCARD\r\nVERSION:3.0\r\nX-ADDRESSBOOKSERVER-KIND:group\r\nFN:Nur Gruppe\r\nEND:VCARD\r\n")['error'] !== null],
            [true, true]);
    }

    // ================================================================== ICS

    private static function icsFile(bool $changed = false): string
    {
        $l = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Example Corp.//Kalender 1.0//DE', 'X-WR-CALNAME:Musterverein',
            'BEGIN:VTIMEZONE', 'TZID:Europe/Berlin', 'BEGIN:STANDARD', 'DTSTART:19701025T030000', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
            'BEGIN:DAYLIGHT', 'DTSTART:19700329T020000', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT', 'END:VTIMEZONE',
            'BEGIN:VEVENT', 'UID:training-serie@example.org', 'DTSTAMP:20261101T100000Z', 'DTSTART;TZID=Europe/Berlin:20270104T190000', 'DTEND;TZID=Europe/Berlin:20270104T210000',
            'SUMMARY:' . ($changed ? 'Training (neu)' : 'Training'), 'RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=8', 'EXDATE;TZID=Europe/Berlin:20270118T190000',
            'LOCATION:Sporthalle Musterstadt', 'GEO:51.45;6.62', 'CATEGORIES:Training', 'DESCRIPTION:Bitte Hallenschuhe mitbringen.', 'END:VEVENT'];
        if (!$changed) array_push($l, 'BEGIN:VEVENT', 'UID:training-serie@example.org', 'RECURRENCE-ID;TZID=Europe/Berlin:20270125T190000', 'DTSTART;TZID=Europe/Berlin:20270125T200000',
            'DTEND;TZID=Europe/Berlin:20270125T220000', 'SUMMARY:Training (später)', 'END:VEVENT');
        else array_push($l, 'BEGIN:VEVENT', 'UID:training-serie@example.org', 'RECURRENCE-ID;TZID=Europe/Berlin:20270208T190000', 'DTSTART;TZID=Europe/Berlin:20270208T180000',
            'DTEND;TZID=Europe/Berlin:20270208T200000', 'SUMMARY:Training (früher)', 'END:VEVENT');
        array_push($l, 'BEGIN:VEVENT', 'UID:training-serie@example.org', 'RECURRENCE-ID;TZID=Europe/Berlin:20270201T190000', 'DTSTART;TZID=Europe/Berlin:20270201T190000',
            'DTEND;TZID=Europe/Berlin:20270201T210000', 'SUMMARY:Training', 'STATUS:CANCELLED', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:sommerfest@example.org', 'DTSTART;VALUE=DATE:20270320', 'DTEND;VALUE=DATE:20270322', 'SUMMARY:Sommerfest', 'CATEGORIES:Fest', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:telefonkonferenz@example.org', 'DTSTART;TZID=America/New_York:20270210T120000', 'DTEND;TZID=America/New_York:20270210T130000', 'SUMMARY:Telefonkonferenz New York', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:utc@example.org', 'DTSTART:20270215T080000Z', 'DURATION:PT90M', 'SUMMARY:Termin in UTC', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:schwebend@example.org', 'DTSTART:20270216T100000', 'SUMMARY:Schwebende Zeit', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:xss@example.org', 'DTSTART:20270217T100000', 'SUMMARY:<img src=x onerror=alert(1)>', 'LOCATION:<script>alert(1)</script>', 'END:VEVENT');
        // nicht unterstützte Regel – in der geänderten Datei unverändert dabei
        array_push($l, 'BEGIN:VEVENT', 'UID:stuendlich@example.org', 'DTSTART:20270218T100000', 'SUMMARY:Stündlich', 'RRULE:FREQ=HOURLY;COUNT=3', 'END:VEVENT');
        $l[] = 'END:VCALENDAR';
        return implode("\r\n", $l) . "\r\n";
    }

    private static function calendarTable(string $h, string $name, array &$made): array
    {
        $def = DataController::presetDef('calendar');
        $def['handle'] = $h;
        $def['name'] = $name;
        $def['settings']['route'] = '';
        [$clean, $err] = Tables::validate($def);
        if ($err) throw new \RuntimeException('Kalender ungültig: ' . json_encode($err, JSON_UNESCAPED_UNICODE));
        Tables::create($clean);
        $made[] = $h;
        return Tables::find($h) ?? throw new \RuntimeException('Tabelle fehlt');
    }

    /** Vorkommen Januar–April 2027 als „MM-TT HH:MM Titel [fällt aus]“ */
    private static function occ(array $t): array
    {
        $tz = Calendar::tz();
        return array_map(fn($o) => $o['start']->format('m-d H:i') . ' ' . Entries::title($t, $o['entry']) . (!empty($o['cancelled']) ? ' [fällt aus]' : ''),
            Calendar::occurrences($t, new DateTimeImmutable('2027-01-01', $tz), new DateTimeImmutable('2027-05-01', $tz), ['status' => 'all']));
    }

    private static function ics(\Closure $eq, string $sfx, array &$made): void
    {
        $h = 'selftest_ics_' . $sfx;
        $t = self::calendarTable($h, 'Selbsttest ICS', $made);
        $file = self::icsFile();
        $dry = IcsImport::import($t, $file, ['dry' => true, 'categories' => true]);
        $eq('Probelauf: 7 Termine neu, 2 Einzeltermine, Kategorien', [$dry['ok'], $dry['total'], $dry['created'], $dry['overrides'], $dry['new_categories']], [true, 7, 7, 2, ['Training', 'Fest']]);
        $eq('Probelauf ändert nichts', Entries::count($t, ['status' => 'all']), 0);
        $warn = array_merge(...array_column($dry['rows'], 'warnings'));
        $eq('Hinweise: nicht unterstützte Regel', (bool) array_filter($warn, fn($w) => str_contains($w, 'FREQ=HOURLY')), true);

        $rep = IcsImport::import($t, $file, ['categories' => true, 'status' => 'published']);
        $eq('Import: 7 neu, keine Fehler', [$rep['created'], $rep['failed']], [7, 0]);
        $t = Tables::find($h) ?? $t;
        $occ = self::occ($t);
        $eq('Serie: EXDATE fehlt, Einzeltermin verschoben, Ausfall sichtbar', array_values(array_filter($occ, fn($s) => str_contains($s, 'Training'))), [
            '01-04 19:00 Training', '01-11 19:00 Training', '01-25 20:00 Training (später)', '02-01 19:00 Training [fällt aus]', '02-08 19:00 Training', '02-15 19:00 Training', '02-22 19:00 Training']);
        $eq('Zeitzonen: New York → Ortszeit, UTC, schwebend', array_values(array_filter($occ, fn($s) => preg_match('~(New York|UTC|Schwebende)~', $s))),
            ['02-10 18:00 Telefonkonferenz New York', '02-15 09:00 Termin in UTC', '02-16 10:00 Schwebende Zeit']);
        $heads = [];
        foreach (Entries::query($t, ['status' => 'all']) as $e) $heads[(string) $e[Series::UID]] = $e;
        $fest = $heads['sommerfest@example.org'] ?? [];
        $eq('Ganztägig über zwei Tage', [$fest['beginn'] ?? '', $fest['ende'] ?? '', (bool) ($fest['ganztaegig'] ?? false)], ['2027-03-20 00:00', '2027-03-21 00:00', true]);
        $utc = $heads['utc@example.org'] ?? [];
        $eq('Dauer (DURATION) → Ende', $utc['ende'] ?? '', '2027-02-15 10:30');
        $ser = $heads['training-serie@example.org'] ?? [];
        $kf = Tables::field($t, 'kategorie');
        $eq('Ort, Kartenpunkt, Kategorie, Beschreibung', [$ser['ort'] ?? '', $ser['standort'] ?? '', array_map(fn($k) => Tables::optionLabel($kf, (string) $k), (array) ($ser['kategorie'] ?? [])), $ser['beschreibung'] ?? ''],
            ['Sporthalle Musterstadt', '51.45, 6.62', ['Training'], 'Bitte Hallenschuhe mitbringen.']);
        $eq('Einzeltermine nicht in Listen', Entries::count($t, ['status' => 'all']), 7);
        $x = $heads['xss@example.org'] ?? [];
        $eq('XSS: HTML aus Titel und Ort entfernt', [$x['titel'] ?? '', $x['ort'] ?? '', str_contains(e(Entries::title($t, $x)), '<img')], ['(ohne Titel)', 'alert(1)', false]);

        $again = IcsImport::import($t, $file, ['categories' => true]);
        $eq('Erneuter Import: unverändert, keine Dubletten', [$again['created'], $again['updated'], $again['unchanged'], $again['overrides'], Entries::count($t, ['status' => 'all'])], [0, 0, 7, 0, 7]);
        $chg = IcsImport::import($t, self::icsFile(true), ['categories' => true]);
        $eq('Geänderte Datei: Serie aktualisiert', [$chg['created'], $chg['updated'], $chg['failed']], [0, 1, 0]);
        $eq('Einzeltermin entfernt (wieder normal), neuer Einzeltermin', array_values(array_filter(self::occ(Tables::find($h) ?? $t), fn($s) => str_contains($s, 'Training'))), [
            '01-04 19:00 Training (neu)', '01-11 19:00 Training (neu)', '01-25 19:00 Training (neu)', '02-01 19:00 Training [fällt aus]', '02-08 18:00 Training (früher)', '02-15 19:00 Training (neu)', '02-22 19:00 Training (neu)']);

        // Export → Import in einen zweiten Kalender: dieselben Termine
        $t = Tables::find($h) ?? $t;
        $out = ICal::calendar($t, Entries::query($t, ['status' => 'all', 'internal' => true]), ['internal' => true]);
        $h2 = 'selftest_ics2_' . $sfx;
        $t2 = self::calendarTable($h2, 'Selbsttest ICS 2', $made);
        $r2 = IcsImport::import($t2, $out, ['categories' => true, 'status' => 'published']);
        $t2 = Tables::find($h2) ?? $t2;
        $eq('Rundreise: Export → Import', [$r2['created'], $r2['failed']], [7, 0]);
        $eq('Rundreise: dieselben Termine', self::occ($t2), self::occ($t));
        $own = IcsImport::import($t, $out, ['categories' => true]);
        $eq('Eigene UIDs erkannt (Export → Import in denselben Kalender)', [$own['created'], $own['unchanged'], array_values(array_filter(array_map(fn($r) => $r['action'] === 'updated' ? $r['name'] . ': ' . $r['detail'] : '', $own['rows'])))], [0, 7, []]);

        // Externe Quellen: Format ics als flache Einträge
        $flat = \Core\Sources\Parser::parse($file, 'ics');
        $eq('Quelle (flach): je VEVENT ein Eintrag', [count($flat['items']), $flat['items'][0]['uid'], $flat['items'][0]['start'], $flat['items'][0]['rrule'], $flat['items'][1]['recurrence_id']],
            [9, 'training-serie@example.org', '2027-01-04 19:00', 'FREQ=WEEKLY;BYDAY=MO;COUNT=8', '2027-01-25 19:00']);

        // Abo: Herkunft je Termin, fehlende ausblenden, wieder einblenden, löschen
        $h3 = 'selftest_ics3_' . $sfx;
        $t3 = self::calendarTable($h3, 'Selbsttest ICS Abo', $made);
        [$sid, $serr] = \Core\Sources\Sources::save(null, ['name' => 'Selbsttest Austausch ' . $sfx, 'format' => 'ics', 'url' => '', 'table_handle' => $h3,
            'options' => ['schedule' => 'manual', 'missing' => 'hide']]);
        $eq('Quelle angelegt', [$serr, $sid > 0], [[], true]);
        $src = \Core\Sources\Sources::find((int) $sid) ?? [];
        \Core\Sources\Sync::$writing = true;
        try {
            $a = IcsImport::import($t3, $file, ['source' => $src, 'categories' => true, 'status' => 'published', 'missing' => 'hide']);
            $items = (int) app()->db->fetchValue('SELECT COUNT(*) FROM ext_source_items WHERE source_id = ?', [(int) $sid]);
            $eq('Abo: angelegt mit Herkunft', [$a['created'], $items], [7, 7]);
            $fest = fn() => Entries::find($t3, (int) app()->db->fetchValue("SELECT entry_id FROM ext_source_items WHERE source_id = ? AND ext_id = 'sommerfest@example.org'", [(int) $sid])) ?? [];
            $eq('Abo: Termine schreibgeschützt (aus Quelle)', \Core\Sources\Sources::isExternal($t3, (int) $fest()['id']), true);
            $without = preg_replace('~BEGIN:VEVENT\r\nUID:sommerfest@example\.org.*?END:VEVENT\r\n~s', '', $file);
            $b = IcsImport::import($t3, (string) $without, ['source' => $src, 'categories' => true, 'missing' => 'hide']);
            $eq('Abo: fehlender Termin ausgeblendet', [$b['hidden'], $fest()['status']], [1, 'draft']);
            $c = IcsImport::import($t3, $file, ['source' => $src, 'categories' => true, 'missing' => 'hide']);
            $eq('Abo: wieder da → wieder veröffentlicht', [$c['created'], $fest()['status']], [0, 'published']);
            $d = IcsImport::import($t3, (string) $without, ['source' => $src, 'categories' => true, 'missing' => 'delete']);
            $eq('Abo: Löschung übernommen', [$d['deleted'], $fest(), Entries::count($t3, ['status' => 'all'])], [1, [], 6]);
            $none = IcsImport::import($t3, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n", ['source' => $src, 'missing' => 'delete']);
            $eq('Abo: leere Antwort löscht nichts', [$none['deleted'], Entries::count($t3, ['status' => 'all'])], [0, 6]);
        } finally {
            \Core\Sources\Sync::$writing = false;
        }
        $eq('Datei-Import überspringt Termine aus einem Abo', IcsImport::import($t3, $file, ['categories' => true])['skipped'] >= 1, true);
    }
}
