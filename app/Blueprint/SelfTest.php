<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

use Core\Data\Kinds;
use Core\Data\Tables;
use Core\Sites;

/**
 * php bin/console blueprint:selftest [--sandbox] [--keep]
 *
 * Ohne --sandbox: Logik ohne Website-Daten – Platzhalter (Ersetzen, Füllen, HTML), Anonymisieren (E-Mail, Telefon, IBAN, Notizen,
 * Kontakte, Organisationen, private Felder, relative Termine), Paket (Pfade, Prüfsummen, Manipulation), Whitelist der
 * Einstellungen, Strukturvergleich, Kopie (Geheimnisse und Datenschutz-Tabellen).
 *
 * Mit --sandbox Rundlauf mit zwei Wegwerf-Websites (bpst-…): Quelle mit Testdaten (Kontakt mit Personendaten, Konto, SMTP-Passwort,
 * Text mit E-Mail/Telefon/IBAN, Kalender) → Export (anonym) → keine Geheimnisse und Personendaten im Paket → neue Website aus dem
 * Paket → gleiche Struktur, Demo-Einträge mit Platzhalter-Personen, Termine in der Zukunft, Wartungsmodus → Schnelleinrichtung →
 * Health → Demoinhalte entfernen; dazu site:copy (full) mit neuen Geheimnissen und leeren Datenschutz-Tabellen. Am Ende werden
 * beide Websites wieder entfernt (--keep lässt sie stehen). Nur in einer Kopie der Installation ausführen.
 */
final class SelfTest
{
    /** Erfundene Testdaten – dürfen nie im Paket landen */
    private const SECRET = 'BpSelftest-Geheim-7731!';
    private const PERSON = ['Hiltrud', 'Geheimrath'];
    private const MAIL = 'hiltrud.geheimrath@verein-intern.example.net';
    private const PHONE = '0211 7654321';
    private const IBAN = 'DE89 3704 0044 0532 0130 00';

    public static function run(bool $sandbox, bool $keep = false): int
    {
        $step = null;
        foreach ($_SERVER['argv'] ?? [] as $a) if (str_starts_with((string) $a, '--step=')) $step = substr((string) $a, 7);
        if ($step !== null) return self::step($step);
        $r = self::logic();
        foreach ($r['fails'] as $f) echo "  FEHLER: $f\n";
        echo "  {$r['ok']} Prüfungen bestanden, " . count($r['fails']) . " fehlgeschlagen" . ($sandbox ? '' : ' (Rundlauf mit Testdaten: --sandbox in einer Kopie)') . "\n";
        if (!$sandbox || $r['fails']) return $r['fails'] ? 1 : 0;
        return self::sandbox($keep);
    }

    /** @return array{ok: int, fails: list<string>} */
    public static function logic(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Vorlagen – ');

        // Platzhalter
        $src = ['org.name' => 'Turnverein Musterhausen 1890 e. V.', 'org.short' => 'TVM', 'org.city' => 'Musterhausen', 'org.zip' => '99999', 'org.domain' => 'tv-musterhausen.example'];
        $t = Placeholders::tokenize('Willkommen beim Turnverein Musterhausen 1890 e. V. (TVM) in 99999 Musterhausen – www.tv-musterhausen.example', $src);
        $eq('Platzhalter: Angaben der Quelle ersetzt', $t, 'Willkommen beim {{org.name}} ({{org.short}}) in {{org.zip}} {{org.city}} – {{org.domain}}');
        $eq('Platzhalter: Wortgrenze (TVMX bleibt)', Placeholders::tokenize('TVMX', $src), 'TVMX');
        $eq('Platzhalter füllen (Text)', Placeholders::fill('{{org.name}} in {{org.city}}', ['org.name' => 'A & B', 'org.city' => 'Köln']), 'A & B in Köln');
        $eq('Platzhalter füllen (HTML escaped)', Placeholders::fill('<p>{{org.name}}</p>', ['org.name' => 'A & B <x>']), '<p>A &amp; B &lt;x&gt;</p>');
        $eq('Platzhalter ohne Wert bleibt', Placeholders::fill('{{org.phone}}', ['org.phone' => '']), '{{org.phone}}');
        $eq('Platzhalter finden', Placeholders::found('{{org.name}} {{x.y}} {{org.name}}'), ['org.name', 'x.y']);
        $eq('Platzhalter in JSON bleibt gültig', json_decode(Placeholders::fillJson('{"a":"{{org.name}}"}', ['org.name' => 'Ein "Verein"']), true), ['a' => 'Ein "Verein"']);

        // Anonymisieren
        $a = new Anonymizer([], (int) strtotime('2026-01-01'));
        $eq('Text: E-Mail', $a->text('Schreib an ' . self::MAIL . '!'), 'Schreib an ' . Anonymizer::EMAIL . '!');
        $eq('Text: Beispiel-Adressen bleiben', $a->text('info@example.org, x@demo.test'), 'info@example.org, x@demo.test');
        $eq('Text: Telefon', $a->text('Tel. ' . self::PHONE . ' oder +49 211 12345678'), 'Tel. +49 30 1234567 oder +49 30 1234567');
        $eq('Text: Jahreszahl und Uhrzeit bleiben', $a->text('Seit 1890, Beginn 18:30, Raum 0815'), 'Seit 1890, Beginn 18:30, Raum 0815');
        $eq('Text: IBAN', $a->text('IBAN ' . self::IBAN), 'IBAN DE00 0000 0000 0000 0000 00');
        $eq('Text: Redaktionsnotizen fallen weg', $a->text('Text [# intern: Frau X fragen #] Ende'), 'Text Ende');
        $rel = $a->relDate('2026-01-15 18:30');
        $eq('Datum relativ', $rel, ['@rel' => 14, 't' => '18:30', 'f' => ' ']);
        $eq('Datum zurück (heute + Tage + Versatz)', Anonymizer::absDate($rel, (int) strtotime('2026-03-01'), 7), '2026-03-22 18:30');
        $contacts = ['handle' => 'personen', 'settings' => ['kind' => Kinds::CONTACTS], 'fields' => [
            ['name' => 'name', 'type' => 'text'], ['name' => 'vorname', 'type' => 'text'], ['name' => 'nachname', 'type' => 'text'],
            ['name' => 'email', 'type' => 'emails'], ['name' => 'telefon', 'type' => 'phones'], ['name' => 'adresse', 'type' => 'addresses'],
            ['name' => 'geburtstag', 'type' => 'date', 'private' => 1], ['name' => 'notiz', 'type' => 'textarea', 'private' => 1], ['name' => 'foto', 'type' => 'media']]];
        $row = ['id' => 4, 'slug' => 'hiltrud-geheimrath', 'name' => implode(' ', self::PERSON), 'vorname' => self::PERSON[0], 'nachname' => self::PERSON[1],
            'email' => json_encode([['label' => 'work', 'value' => self::MAIL, 'public' => '1']]), 'telefon' => json_encode([['label' => 'mobile', 'value' => self::PHONE, 'public' => '0']]),
            'adresse' => json_encode([['label' => 'home', 'street' => 'Geheimweg 7', 'zip' => '40210', 'city' => 'Düsseldorf', 'public' => 'city']]),
            'geburtstag' => '1970-05-04', 'notiz' => 'Schulden', 'foto' => 12];
        $an = $a->entry($contacts, $row, 1);
        $flat = json_encode($an, JSON_UNESCAPED_UNICODE);
        $eq('Kontakt: Platzhalter-Person', [$an['vorname'], $an['nachname'], $an['name']], ['Max', 'Mustermann', 'Max Mustermann']);
        $eq('Kontakt: keine echten Angaben mehr', [str_contains($flat, 'Geheim'), str_contains($flat, '7654321'), str_contains($flat, 'Düsseldorf'), str_contains($flat, 'verein-intern')], [false, false, false, false]);
        $eq('Kontakt: private Felder leer', [$an['geburtstag'], $an['notiz']], [null, null]);
        $eq('Kontakt: Freigaben bleiben', json_decode((string) $an['adresse'], true)[0]['public'], 'city');
        $eq('Kontakt: Bild-ID bleibt bis zum Anlegen', $an['foto'], 12);
        $org = ['handle' => 'vereine', 'settings' => ['schema_type' => 'Organization', 'title_field' => 'titel'], 'fields' => [['name' => 'titel', 'type' => 'text'], ['name' => 'text', 'type' => 'richtext']]];
        $ao = $a->entry($org, ['id' => 1, 'slug' => 'tv', 'titel' => 'Turnverein Echtstadt', 'text' => '<p>Echte Beschreibung</p>'], 0);
        $eq('Organisation: Beispiel-Name und -Text', [$ao['titel'], str_contains((string) $ao['text'], 'Echte'), $ao['slug']], ['Beispiel-Organisation 1', false, 'beispiel-organisation-1']);
        $eq('Medien umschreiben (unbekannt → Platzhalter)', Anonymizer::remapMedia(['image' => 5, 'items' => [['media' => '7']], 'count' => 5], [5 => 50, 0 => 99]), ['image' => 50, 'items' => [['media' => '99']], 'count' => 5]);
        $eq('Medien-IDs finden', Anonymizer::mediaIds(['image' => 5, 'items' => [['poster' => 8]], 'count' => 3]), [5, 8]);

        // Paket
        $eq('Pfad: kein ..', Package::cleanPath('../x.json'), null);
        $eq('Pfad: keine PHP-Datei', Package::cleanPath('structure/x.php'), null);
        $eq('Pfad: erlaubt', Package::cleanPath('/demo/media/3.jpg'), 'demo/media/3.jpg');
        $eq('Version weiterzählen', Package::nextVersion('1.4.2'), '1.5.0');
        $dir = Package::tempDir() . '/pkg';
        try {
            Package::write($dir, ['format' => Package::FORMAT, 'format_version' => 1, 'name' => 'selftest', 'version' => '1.0.0', 'kit' => 'basis'], ['structure/pages.json' => [['id' => 1]]]);
            $p = Package::open($dir);
            $eq('Paket: öffnen mit Prüfsumme', [$p['manifest']['name'], Package::read($p['dir'], 'structure/pages.json')], ['selftest', [['id' => 1]]]);
            if (class_exists(\ZipArchive::class)) {
                Package::zip($dir, dirname($dir) . '/pkg.zip');
                $z = Package::open(dirname($dir) . '/pkg.zip');
                $eq('Paket: ZIP', $z['manifest']['version'], '1.0.0');
                if ($z['temp']) Package::remove($z['dir']);
            }
            file_put_contents($dir . '/structure/pages.json', '[{"id":2}]');
            $err = '';
            try { Package::open($dir); } catch (\RuntimeException $e) { $err = $e->getMessage(); }
            $eq('Paket: Manipulation erkannt', str_starts_with($err, 'Prüfsumme'), true);
        } finally {
            Package::remove(dirname($dir));
        }

        // Whitelist, Vergleich
        $eq('Einstellung: Mail-Passwort nie', Apply::allowedSetting('sys.mail_pass'), false);
        $eq('Einstellung: Formular-Schlüssel nie', Apply::allowedSetting('sys.form_public_key'), false);
        $eq('Einstellung: Erweiterung nie', Apply::allowedSetting('ext.newsletter.tables'), false);
        $eq('Einstellung: Symbolstil ja', Apply::allowedSetting('sys.symbol_style'), true);
        $eq('Einstellung: Design ja', Apply::allowedSetting('design.fluid'), true);
        $eq('Einstellung: Detailseiten-Vorlagen ja', Apply::allowedSetting('data.detail_templates.termine'), true);
        $eq('Whitelist ohne Geheimnisse', array_values(array_filter(Export::SYS_SETTINGS, fn($k) => preg_match('~mail|form|ai_|token|key|pass|site_url|maintenance~', $k))), []);
        $s1 = Diff::build([['type' => 'page', 'slug' => 'a', 'path' => 'a', 'lang' => null]], [['handle' => 't', 'fields' => [['name' => 'x', 'type' => 'text']], 'settings' => []]], [], [], []);
        $s2 = Diff::build([['type' => 'page', 'slug' => 'a', 'path' => 'a', 'lang' => null], ['type' => 'page', 'slug' => 'b', 'path' => 'b', 'lang' => null]],
            [['handle' => 't', 'fields' => [['name' => 'x', 'type' => 'text'], ['name' => 'y', 'type' => 'date']], 'settings' => []]], [['rkey' => 'presse', 'permissions_json' => '["pages.edit"]']], [], []);
        $d = Diff::compare($s1, $s2);
        $eq('Vergleich: neue Seite, Feld, Rolle', [$d['added']['pages'], $d['added']['fields'], $d['added']['roles'], Diff::any(Diff::compare($s2, $s2))], [['-:/b'], ['t.y'], ['presse'], false]);
        // Prüfung der Texte (--check): Rechtsverweise und technische Felder sind keine Fundstellen, Zahlwörter schon, Auszug als Klartext
        $eq('Zahl zur Region: Rechtsverweise zählen nicht', [Export::regionNumber('Art. 6 Abs. 1 lit. a DSGVO in {{region.name}}'), Export::regionNumber('nach § 25 Abs. 1 TTDSG und §§ 12, 13 BGB')], [null, null]);
        $eq('Zahl zur Region: Ziffern und Zahlwörter', [Export::regionNumber('42 Gruppen'), Export::regionNumber('in elf Regionen in ganz'), Export::regionNumber('Dutzende Gruppen'),
            Export::regionNumber('zweier Kreise'), Export::regionNumber('Wir achten auf Natur, seit 1990')], ['42', 'elf', 'Dutzende', 'zweier', null]);
        $eq('Technische Felder und Werte', [Export::technicalKey('video_url'), Export::technicalKey('button_link'), Export::technicalKey('text'), Export::technicalValue('3uwI9hSyX6g'),
            Export::technicalValue('https://example.org/x'), Export::technicalValue('NRW-weit')], [true, true, false, true, true, false]);
        [, $rv] = Export::review([['id' => 3, 'title' => 'T', 'content_published' => ['blocks' => [['type' => 'video', 'data' => ['video_url' => 'https://youtu.be/AB12cd34', 'caption' => 'Video aus {{region.short}}', 'id' => 'x1y2z3']],
            ['type' => 'richtext', 'data' => ['text' => '<p>Rechtsgrundlage in {{region.short}}: Art. 6 Abs. 1 lit. a DSGVO</p>']]]]]],
            ['t' => ['rows' => [['id' => 1, 'inhalt' => json_encode([['type' => 'richtext', 'data' => ['text' => '<p>In zwölf Gruppen in ganz {{region.short}}</p>']]])]]]], [], [['handle' => 't', 'name' => 'T']], ['region.short' => 'NRW']);
        $eq('Prüfung: keine Fehlalarme auf der Seite, Zahlwort im Eintrag mit Klartext-Auszug', [count(array_filter($rv, fn($r) => $r['where'] === 'page:3')),
            array_values(array_map(fn($r) => $r['sample'], array_filter($rv, fn($r) => $r['where'] === 'entry:t:1')))], [0, ['In zwölf Gruppen in ganz {{region.short}}']]);
        // Ortsnamen der Quell-Region (Filter blueprint.areas): mehrere in einem Absatz → ein Fund, einer → Ortsname; Felder mit Flächen nicht
        $areas = ['places' => ['Musterhausen', 'Beispielburg', 'Testheide', 'Kreis Probe'], 'fields' => ['gruppen.gebiet']];
        [, $rv] = Export::review([['id' => 4, 'title' => 'Gruppen', 'content_published' => ['blocks' => [['type' => 'richtext', 'data' => ['text' => '<p>Treffen in Musterhausen, Beispielburg und der Testheide.</p><p>Sonst nichts.</p>']]]]],
            ['id' => 5, 'title' => 'Ort', 'content_published' => ['blocks' => [['type' => 'richtext', 'data' => ['text' => '<p>Seminar in Beispielburg.</p>']]]]]],
            ['gruppen' => ['rows' => [['id' => 1, 'gebiet' => "Musterhausen\nBeispielburg"]]]], [], [['handle' => 'gruppen', 'name' => 'Gruppen']], ['region.name' => 'Musterland'], $areas);
        $by = fn(string $w) => array_values(array_map(fn($r) => $r['reason'], array_filter($rv, fn($r) => $r['where'] === $w)));
        $eq('Ortsnamen: Absatz mit mehreren → ein Fund, einzelner Ort, Feld mit Flächen nicht', [count($by('page:4')), str_starts_with((string) ($by('page:4')[0] ?? ''), 'Absatz ist auf Musterland bezogen'),
            str_starts_with((string) ($by('page:5')[0] ?? ''), 'Absatz ist auf Musterland bezogen („Beispielburg“)'), $by('entry:gruppen:1')], [1, true, true, []]);
        [, $rv0] = Export::review([['id' => 4, 'title' => 'Gruppen', 'content_published' => ['blocks' => [['type' => 'richtext', 'data' => ['text' => '<p>Treffen in Musterhausen und Beispielburg.</p>']]]]]], [], [], [], ['region.name' => 'Musterland']);
        $eq('Ortsnamen: ohne Erweiterung wie bisher', $rv0, []);
        $eq('Platzhalter lesbar im Auszug', Placeholders::readable('In {{region.short}} leben 526 Menschen beim {{org.short}}'), 'In [Region] leben 526 Menschen beim [Kurzname]');
        [, $rv3] = Export::review([], ['gruppen' => ['rows' => [['id' => 7, 'titel' => 'Gruppe Nord', 'text' => '<p>Wandern in der Testheide und in {{region.name}}</p>']]]], [], [['handle' => 'gruppen', 'name' => 'Gruppen', 'settings' => ['title_field' => 'titel']]],
            ['region.name' => 'Musterland'], $areas);
        $eq('Titel der Stelle = Titel des Eintrags, Auszug mit Platzhalter', [$rv3[0]['title'] ?? '', $rv3[0]['sample'] ?? ''], ['Gruppe Nord', 'Wandern in der Testheide und in {{region.name}}']);
        // Neutrale Namen nur an echten Verweisen – Landschaftsname im Fließtext bleibt und wird gemeldet
        $rs = References::selftest();
        $ok += $rs['ok'];
        array_push($fails, ...$rs['fails']);
        [, $rv2] = Export::review([['id' => 6, 'title' => 'Wald', 'content_published' => ['blocks' => [['type' => 'richtext', 'data' => ['text' => '<p>Kalamitätsfläche mit Gras in der Testheide 2016</p><p>Die Metropole Musterhausen (Beispielburg) wächst.</p>']]]]]],
            [], [], [], ['region.name' => 'Musterland'], $areas);
        $eq('Landschaft im Fließtext gemeldet (je Absatz ein Fund)', array_map(fn($r) => strstr((string) $r['reason'], ' – ', true), $rv2),
            ['Absatz ist auf Musterland bezogen („Testheide“)', 'Absatz ist auf Musterland bezogen („Musterhausen“, „Beispielburg“)']);
        // Lizenz aus dem Urheberhinweis
        $eq('Lizenz aus Urheberhinweis', [\Core\Media::licenseFromCredit('Image by Erika Musterfrau from Pixabay'), \Core\Media::licenseFromCredit('Foto: Max Mustermann / pexels.com'),
            \Core\Media::licenseFromCredit('Photo by X on Unsplash'), \Core\Media::licenseFromCredit('Foto: privat'), Export::license(['license' => 'Eigenes Foto', 'credit' => 'Pixabay'])],
            ['Pixabay-Lizenz', 'Pexels-Lizenz', 'Unsplash-Lizenz', '', 'Eigenes Foto']);
        $eq('Bild mit Pixabay im Urheberhinweis geht mit', Export::mediaReason(null) === 'missing' && preg_match(Export::FREE_LICENSE, Export::license(['license' => '', 'credit' => 'Image by A from Pixabay'])) === 1, true);
        // Fehlendes Video → Platzhalterbild (Einstieg nicht schwarz); Impressum älterer Pakete → Platzhalter
        $eq('Video fehlt: Feld leer, Platzhalterbild', Anonymizer::remapMedia(['video' => 100, 'image' => null, 'title' => 'x'], [0 => 99]), ['video' => null, 'image' => 99, 'title' => 'x']);
        $eq('Video vorhanden bleibt', Anonymizer::remapMedia(['video' => 100, 'image' => null], [100 => 7, 0 => 99]), ['video' => 7, 'image' => null]);
        $eq('Impressum älterer Pakete', Placeholders::legacyImprint(['t' => '<p>Vertreten durch: [# Vorstand bzw. Geschäftsführung ergänzen #]</p>']), ['t' => '<p>Vertreten durch: {{org.representatives}}</p>']);
        $eq('Impressum der Vorlage mit Vertretung und Verantwortlichem', [str_contains(Export::IMPRINT, '{{org.representatives}}'), str_contains(Export::IMPRINT, '{{org.responsible}}'), str_contains(Export::IMPRINT, '[#')], [true, true, false]);
        // Platzhalterbild: Fläche in der Farbe, ohne Grau, helle Schrift überall lesbar
        if (function_exists('imagecreatefrompng')) {
            $tmp = Package::tempDir();
            $f = Apply::renderPlaceholder($tmp . '/p.png', '#193E20');
            $im = $f ? imagecreatefrompng($f) : false;
            $px = [];
            if ($im) foreach ([[5, 5], [800, 500], [1590, 990], [30, 980]] as [$x, $y]) { $c = imagecolorat($im, $x, $y); $px[] = sprintf('#%02X%02X%02X', ($c >> 16) & 255, ($c >> 8) & 255, $c & 255); }
            Package::remove($tmp);
            $eq('Platzhalterbild: farbig, weiße Schrift ≥ 4,5 an jeder Stelle', [count($px), count(array_filter($px, fn($p) => Palette::hsl($p)[1] > 0.2 && \Core\Contrast::ratio('#FFFFFF', $p) >= 4.5))], [4, 4]);
        }
        $pal = Palette::selftest();
        $ok += $pal['ok'];
        array_push($fails, ...$pal['fails']);
        $c = Copy::selftest();
        $ok += $c['ok'];
        array_push($fails, ...$c['fails']);
        return ['ok' => $ok, 'fails' => $fails];
    }

    // ================================================================= Rundlauf mit Testdaten (--sandbox)

    private static function sandbox(bool $keep): int
    {
        $id = substr(bin2hex(random_bytes(3)), 0, 5);
        $src = 'bpst-src-' . $id;
        $dst = 'bpst-dst-' . $id;
        $cpy = 'bpst-cpy-' . $id;
        $pkg = ROOT . '/storage/tmp/bpst-' . $id;
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Rundlauf – ', true);
        $run = function (array $args) use (&$out): int { return Network::console($args, $out); };
        $out = '';
        try {
            Sites::create($src, [$src . '.localhost'], 'fluid', ['seed' => 'empty', 'environment' => 'staging']);
            $eq('Quelle anlegen und Testdaten einspielen', $run(['blueprint:selftest', '--step=seed', '--site=' . $src]), 0);
            if ($fails) echo $out;
            $eq('Export (anonym)', $run(['site:blueprint', $src, '--out=' . $pkg, '--demo=anonym', '--name=selftest-vorlage', '--per-table=2']), 0);
            if ($fails) echo $out;
            $raw = '';
            $it = is_dir($pkg) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pkg, \FilesystemIterator::SKIP_DOTS)) : [];
            foreach ($it as $f) if ($f->isFile() && preg_match('~\.(json|md)$~', $f->getFilename())) $raw .= self::plain((string) file_get_contents($f->getPathname()));
            $eq('Paket: kein Passwort, kein Name, keine E-Mail/Telefon/IBAN der Testdaten', [str_contains($raw, self::SECRET), str_contains($raw, self::PERSON[1]),
                str_contains($raw, self::MAIL), str_contains($raw, '7654321'), str_contains($raw, '0532 0130'), str_contains($raw, 'konto.selftest@')], [false, false, false, false, false, false]);
            $eq('Paket: keine Domain der Quelle, Platzhalter statt Name', [str_contains($raw, 'bpst-verein.example.com'), str_contains($raw, '{{org.name}}')], [false, true]);
            $eq('Paket: Region der Quelle als Platzhalter (Langname, Abkürzung)', [str_contains($raw, '{{region.name}}'), str_contains($raw, '{{region.short}}'), str_contains($raw, 'Gruppen in NRW')], [true, true, false]);
            $eq('Paket: Personen aus den Daten auch im Fließtext ersetzt', [str_contains($raw, 'Wahrnamig'), str_contains($raw, 'Klarname')], [false, false]);
            $man = (array) json_decode((string) @file_get_contents($pkg . '/manifest.json'), true);
            $eq('Paket: Stellen zum Anpassen (Wortteil, Zahlen zur Region)', [count(array_filter((array) ($man['review'] ?? []), fn($r) => str_contains((string) $r['reason'], 'Wortteil'))) > 0,
                count(array_filter((array) ($man['review'] ?? []), fn($r) => str_contains((string) $r['reason'], 'Zahlen'))) > 0], [true, true]);
            $regRev = array_values(array_filter((array) ($man['review'] ?? []), fn($r) => ($r['title'] ?? '') === 'Regionen' && str_contains((string) $r['reason'], 'Zahlen')));
            $eq('Paket: Zahlwort gefunden, Rechtsverweis nicht, Auszug Klartext', [count($regRev), str_contains((string) ($regRev[0]['sample'] ?? ''), 'elf'), str_contains((string) ($regRev[0]['sample'] ?? ''), '{"')], [1, true, false]);
            $eq('Paket: Bild mit Pixabay im Urheberhinweis geht mit (Lizenz erkannt, im Bericht)', [str_contains($raw, 'Pixabay-Lizenz'), count(glob($pkg . '/demo/media/*') ?: []) >= 1,
                str_contains(implode("\n", (array) ($man['report'] ?? [])) . $out, 'Urheberhinweis erkannt')], [true, true, true]);
            $eq('Paket: Impressum als Vorlage (keine Person der Quelle)', [str_contains($raw, '{{org.representatives}}'), str_contains($raw, '{{org.responsible}}')], [true, true]);
            $eq('Probelauf --check schreibt kein Paket', $run(['site:blueprint', $src, '--out=' . $pkg . '-check', '--check']) === 0 && !is_dir($pkg . '-check') && str_contains($out, 'Probelauf'), true);
            $eq('Neue Website aus der Vorlage', $run(['site:create', $dst, $dst . '.localhost', '--blueprint=' . $pkg, '--name=Neuer Musterverein', '--short=NMV', '--region=06', '--color=#B00020', '--palette=calm']), 0);
            if ($fails) echo $out;
            $eq('Ziel: Struktur, Demo, Wartung, Platzhalter', $run(['blueprint:selftest', '--step=check', '--pkg=' . $pkg, '--site=' . $dst]), 0);
            echo $out;
            $eq('Ziel: Farbe ändern ohne Palette (gewählte Palette bleibt)', $run(['site:quick-config', '--step=look', '--color=#2F6B3A', '--site=' . $dst]) === 0 && str_contains($out, 'Ruhig'), true);
            $eq('Ziel: Schnelleinrichtung abschließen', $run(['site:quick-config', '--step=org', '--street=Beispielweg 1', '--zip=12345', '--city=Musterstadt', '--phone=+49 30 1234567', '--email=info@example.org',
                '--representatives=Erika Beispiel (Vorsitzende)', '--responsible=Max Beispiel, Beispielweg 1, 12345 Musterstadt', '--finish', '--site=' . $dst]), 0);
            $eq('Ziel: Palette, Diagrammfarben, Platzhalterbild, Impressum', $run(['blueprint:selftest', '--step=final', '--site=' . $dst]), 0);
            if ($fails) echo $out;
            $eq('Ziel: health', $run(['health', '--site=' . $dst]), 0);
            if ($fails) echo $out;
            $eq('Ziel: data:selftest', $run(['data:selftest', '--site=' . $dst]), 0);
            if ($fails) echo $out;
            $eq('Ziel: Demoinhalte entfernen', $run(['site:demo-remove', '--site=' . $dst]) === 0 && str_contains($out, 'entfernt'), true);
            // Neue Version: Quelle bekommt ein Feld und eine Seite → Vorschlag an das Ziel, nur Struktur übernehmen
            $eq('Quelle: neue Struktur', $run(['blueprint:selftest', '--step=grow', '--site=' . $src]), 0);
            $eq('Export Version 1.1.0', $run(['site:blueprint', $src, '--out=' . $pkg . '-v2', '--demo=none', '--name=selftest-vorlage', '--version=1.1.0']), 0);
            $eq('Ziel: Vorschlag übernehmen (Feld, Seite als Entwurf, Inhalte bleiben)', $run(['blueprint:selftest', '--step=update', '--pkg=' . $pkg . '-v2', '--site=' . $dst]), 0);
            if ($fails) echo $out;
            // Kopie (site:copy, voller Inhalt)
            $eq('Kopie (full)', $run(['site:copy', $src, $cpy, $cpy . '.localhost', '--content=full', '--media=copy']), 0);
            if ($fails) echo $out;
            $eq('Kopie: Struktur, Geheimnisse, Datenschutz', $run(['blueprint:selftest', '--step=copycheck', '--from=' . $src, '--site=' . $cpy]), 0);
            echo $out;
            $eq('Kopie: health', $run(['health', '--site=' . $cpy]), 0);
            $eq('Kopie: data:selftest', $run(['data:selftest', '--site=' . $cpy]), 0);
            $eq('Kopie: links:selftest', $run(['links:selftest', '--site=' . $cpy]), 0);
            if ($fails) echo $out;
        } catch (\Throwable $e) {
            $fails[] = 'Ausnahme: ' . $e->getMessage();
        } finally {
            if (!$keep) {
                foreach ([$src, $dst, $cpy] as $k) self::dropSite($k);
                Package::remove($pkg);
                Package::remove($pkg . '-v2');
            } else {
                echo "  Websites bleiben stehen (--keep): $src, $dst, $cpy · Paket: $pkg\n";
            }
        }
        foreach ($fails as $f) echo "  FEHLER: $f\n";
        echo "  Rundlauf: $ok Prüfungen bestanden, " . count($fails) . " fehlgeschlagen\n";
        return $fails ? 1 : 0;
    }

    /** Text eines JSON-Pakets ohne Escapes (für die Suche nach Testdaten) */
    private static function plain(string $raw): string
    {
        return (string) preg_replace_callback('~\\\\u([0-9a-fA-F]{4})~', fn($m) => mb_chr((int) hexdec($m[1])), str_replace('\/', '/', $raw));
    }

    /** Wegwerf-Website entfernen (Konfiguration, Daten, Medien) – nur Websites des Selbsttests (bpst-…) */
    public static function dropSite(string $key): void
    {
        if (!preg_match('~^bpst-(src|dst|cpy)-[0-9a-f]{5}$~', $key)) return;
        @unlink(Sites::dir() . '/' . $key . '.php');
        @unlink(Sites::dir() . '/' . $key . '.php.bak');
        Package::remove(ROOT . '/storage/sites/' . $key);
        $pub = realpath(ROOT . '/public/sites/' . $key);
        if ($pub !== false && str_starts_with($pub, (string) realpath(ROOT . '/public/sites') . '/')) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pub, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            @rmdir($pub);
        }
        Sites::flush();
    }

    /** Schritte im Kontext einer Website (eigener Prozess) */
    private static function step(string $step): int
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, '', true);
        $opt = function (string $n): string { foreach ($_SERVER['argv'] ?? [] as $a) if (str_starts_with((string) $a, "--$n=")) return substr((string) $a, strlen($n) + 3); return ''; };
        if (!str_starts_with(site()->key, 'bpst-')) { fwrite(STDERR, "Nur für Websites des Selbsttests.\n"); return 1; }
        switch ($step) {
            case 'seed':
                $app = app();
                // Angaben der Organisation (werden Platzhalter), Geheimnisse und ein Konto
                foreach (['org_name' => 'Bpst Verein Echtstadt e. V.', 'short_name' => 'BVE', 'street' => 'Echtweg 9', 'zip' => '47441', 'city' => 'Echtstadt',
                    'phone' => self::PHONE, 'email' => 'vorstand@bpst-verein.example.com'] as $k => $v) $app->settings->set($k, $v);
                \Core\Mail\Accounts::saveStandard(['transport' => 'smtp', 'host' => 'smtp.bpst-verein.example.com', 'port' => 587, 'user' => 'konto.selftest@bpst-verein.example.com',
                    'pass' => self::SECRET, 'encryption' => 'tls', 'verify_peer' => true, 'from' => 'konto.selftest@bpst-verein.example.com', 'from_name' => 'Bpst']);
                $app->db->insert('users', ['email' => 'konto.selftest@bpst-verein.example.com', 'name' => 'Konrad Kontoinhaber', 'password_hash' => password_hash(self::SECRET, PASSWORD_DEFAULT), 'role' => 'admin', 'created_at' => now()]);
                $home = (int) $app->db->fetchValue('SELECT id FROM pages WHERE is_home = 1');
                $blocks = [['id' => 'bpst000001', 'type' => 'richtext', 'data' => ['text' => '<p>Willkommen beim Bpst Verein Echtstadt e. V.! Fragen an ' . self::MAIL . ' oder ' . self::PHONE . '. Spenden: ' . self::IBAN . ' [# intern: ' . self::PERSON[1] . ' #]</p>'], 'tunes' => []],
                    ['id' => 'bpst000002', 'type' => 'richtext', 'data' => ['title' => 'Gruppen in NRW', 'text' => '<p>In Nordrhein-Westfalen gibt es 42 Gruppen. BVE ist NRW-weit aktiv, auch in nordrhein-westfälischen Schulen. Ansprechpartnerin ist Waltraud Wahrnamig.</p>'], 'tunes' => []]];
                $app->settings->set('org.region', 'Nordrhein-Westfalen');
                // Bild mit Urheberhinweis „… from Pixabay“ ohne Lizenzfeld (geht mit), Einstieg mit fehlendem Video (wird Platzhalterbild)
                $tmp = Package::tempDir();
                $img = Apply::renderPlaceholder($tmp . '/bpst-bild.png', '#0F766E');
                [$pm] = $img ? \Core\Media::import($img, 'bpst-bild.png', 'Beispielbild', ['credit' => 'Image by Erika Musterfrau from Pixabay', 'require_alt' => false]) : [null];
                Package::remove($tmp);
                $blocks[] = ['id' => 'bpst000004', 'type' => 'hero', 'data' => ['variant' => 'fullbleed', 'title' => 'Willkommen', 'image' => null, 'video' => 99999, 'overlay' => 'gradient'], 'tunes' => []];
                if ($pm) $blocks[] = ['id' => 'bpst000005', 'type' => 'media_text', 'data' => ['title' => 'Bild', 'text' => '<p>Text</p>', 'image' => (int) $pm['id']], 'tunes' => []];
                \Core\Pages::create(['slug' => 'regionen-test', 'title' => 'Regionen', 'status' => 'published'], \Core\Pages::sanitizeBlocks([['id' => 'bpst000006', 'type' => 'richtext',
                    'data' => ['text' => '<p>Wir sind in elf Regionen in ganz NRW aktiv. Rechtsgrundlage: Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TTDSG.</p>'], 'tunes' => ['section' => []]]]));
                $imp = \Core\Pages::create(['slug' => 'impressum-test', 'title' => 'Impressum', 'status' => 'published'], \Core\Pages::sanitizeBlocks([['id' => 'bpst000003', 'type' => 'richtext',
                    'data' => ['text' => '<p>Verantwortlich: ' . implode(' ', self::PERSON) . ', Echtweg 9</p>'], 'tunes' => ['section' => []]]]));
                $app->settings->set('imprint_page', $imp);
                $app->db->update('pages', ['content_published' => json_encode(['blocks' => $blocks]), 'content_draft' => json_encode(['blocks' => $blocks]), 'status' => 'published'], 'id = :id', ['id' => $home]);
                $log = fn(string $m) => fwrite(STDERR, $m . "\n");
                $t = \Core\Kit\Demo::table(['handle' => 'personen', 'name' => 'Personen', 'settings' => ['kind' => Kinds::CONTACTS], 'fields' => Kinds::presetFields(Kinds::CONTACTS)], $log);
                $c = \Core\Kit\Demo::table(['handle' => 'termine', 'name' => 'Termine', 'settings' => ['kind' => Kinds::CALENDAR], 'fields' => Kinds::presetFields(Kinds::CALENDAR)], $log);
                if (!$t || !$c) return 1;
                foreach ([[self::PERSON[0], self::PERSON[1]], ['Waltraud', 'Wahrnamig'], ['Kunibert', 'Klarname']] as $i => [$v, $n]) {
                    Tables::db($t)->insert($t['table'], ['slug' => strtolower($v . '-' . $n), 'status' => 'published', 'sort' => $i, 'name' => "$v $n", 'vorname' => $v, 'nachname' => $n,
                        'email' => json_encode([['label' => 'work', 'value' => strtolower("$v.$n@verein-intern.example.net"), 'public' => '1']]),
                        'telefon' => json_encode([['label' => 'mobile', 'value' => self::PHONE, 'public' => '0']]), 'notiz' => 'privat: ' . $n, 'created_at' => now(), 'updated_at' => now()]);
                }
                foreach (['2025-03-01 19:00', '2025-04-12 10:00', '2025-06-20 18:00'] as $i => $d) {
                    Tables::db($c)->insert($c['table'], ['slug' => 'termin-' . $i, 'status' => 'published', 'sort' => $i, 'titel' => 'Treffen ' . ($i + 1), 'beginn' => $d, 'ort' => 'Echtstadt, Echtweg 9', 'created_at' => now(), 'updated_at' => now()]);
                }
                \Core\Data\Occurrences::rebuildAll();
                echo "Testdaten eingespielt\n";
                return 0;
            case 'check':
                $pkg = Package::open($opt('pkg'));
                $sig = Diff::signature($pkg['dir']);
                $site = Diff::siteSignature();
                if ($pkg['temp']) Package::remove($pkg['dir']);
                $eq('Struktur gleich (Seiten, Tabellen, Rollen, Vorlagen)', Diff::any(Diff::compare($sig, $site)) ? Diff::summary(Diff::compare($sig, $site)) : [], []);
                $t = Tables::find('personen');
                $names = $t ? array_column(Tables::db($t)->fetchAll('SELECT name FROM ' . $t['table'] . ' ORDER BY id'), 'name') : [];
                $eq('Demo-Personen sind Platzhalter', $names, ['Erika Musterfrau', 'Max Mustermann']);
                $c = Tables::find('termine');
                $first = $c ? (string) Tables::db($c)->fetchValue('SELECT MIN(beginn) FROM ' . $c['table']) : '';
                $eq('Termine liegen in der Zukunft', $first > date('Y-m-d'), true);
                $eq('Konten: keine', (int) app()->db->fetchValue('SELECT COUNT(*) FROM users'), 0);
                $eq('Mail-Konto leer', [(string) app()->settings->get('sys.mail_pass', ''), (string) app()->settings->get('sys.mail_host', '')], ['', '']);
                $eq('Wartungsmodus bis zur Schnelleinrichtung', (bool) app()->settings->get('sys.maintenance'), true);
                $eq('Name der neuen Website eingesetzt', (string) app()->settings->get('org_name'), 'Neuer Musterverein');
                $home = (string) app()->db->fetchValue('SELECT content_published FROM pages WHERE is_home = 1');
                $eq('Startseite mit neuem Namen', str_contains($home, 'Neuer Musterverein'), true);
                $eq('Akzentfarbe gesetzt und lesbar', \Core\Contrast::ratio((string) (\Core\Design::values()['accent'] ?? '#000000'), '#FFFFFF') >= 4.5, true);
                $dv = \Core\Design::values();
                $eq('Palette: dunkle Abschnitte und Flächen aus der Farbe, weiße Schrift ≥ 7, Fläche mit Text ≥ 4,5', [isset($dv['dark_section']) ? strtoupper((string) $dv['dark_section']) !== '#0E1022' : true,
                    !isset($dv['dark_section']) || \Core\Contrast::ratio('#FFFFFF', (string) $dv['dark_section']) >= 7, !isset($dv['surface']) || \Core\Contrast::ratio((string) $dv['surface'], (string) ($dv['text'] ?? '#2B2F3A')) >= 4.5], [true, true, true]);
                $st = QuickConfig::state();
                $eq('Werte aus site:create erledigen Erscheinungsbild und Gebiet', [!empty($st['done']['look']), !empty($st['done']['region']), (string) app()->settings->get('org.region')], [true, true, 'Hessen']);
                $tasks = array_column(app()->db->fetchAll('SELECT title FROM tasks'), 'title');
                $eq('Aufgabe „Text an Ihre Organisation anpassen“ mit neuer Region', count(array_filter($tasks, fn($t) => str_starts_with((string) $t, 'Text an Ihre Organisation anpassen: '))) >= 1, true);
                $home = (string) app()->db->fetchValue('SELECT content_published FROM pages WHERE is_home = 1');
                $eq('Region im Text ersetzt (Langname und Abkürzung)', [str_contains($home, 'In Hessen gibt es'), str_contains($home, 'Gruppen in Hessen')], [true, true]);
                $eq('Demo gekennzeichnet', array_sum(Demo::counts()) >= 4, true);
                break;
            case 'final':
                $vals = QuickConfig::values();
                $dv = \Core\Design::values();
                $want = Palette::forKit('#2F6B3A', 'calm', $dv)['values'];
                $eq('Palette aus site:create bleibt (Ruhig)', [(string) ($vals['look.palette'] ?? ''), isset($dv['dark_section']) ? strtoupper((string) $dv['dark_section']) === strtoupper((string) $want['dark_section']) : true], ['calm', true]);
                if (isset(\Core\Design::tokens()['chart_1'])) $eq('Diagrammfarben aus der Palette', [strtoupper((string) $dv['chart_1']), strtoupper((string) ($dv['chart_2'] ?? '')) !== strtoupper(\Core\Charts::PALETTE[1])],
                    [strtoupper((string) $dv['accent']), true]);
                $ph = Apply::placeholderId();
                $pm = $ph ? \Core\Media::find($ph) : null;
                $im = $pm && function_exists('imagecreatefrompng') ? @imagecreatefrompng(\Core\Media::path($pm)) : false;
                $c = $im ? imagecolorat($im, 800, 500) : 0;
                $hex = sprintf('#%02X%02X%02X', ($c >> 16) & 255, ($c >> 8) & 255, $c & 255);
                $eq('Platzhalterbild in der Farbe der Website (nicht grau, weiße Schrift lesbar)', [$ph > 0, Palette::hsl($hex)[1] > 0.1 && abs(Palette::hsl($hex)[0] - Palette::hsl((string) ($dv['dark_section'] ?? '#2F6B3A'))[0]) < 20, \Core\Contrast::ratio('#FFFFFF', $hex) >= 4.5], [true, true, true]);
                $home = (array) json_decode((string) app()->db->fetchValue('SELECT content_published FROM pages WHERE is_home = 1'), true);
                $hero = array_values(array_filter((array) ($home['blocks'] ?? []), fn($b) => ($b['id'] ?? '') === 'bpst000004'))[0]['data'] ?? [];
                $eq('Einstieg mit fehlendem Video zeigt das Platzhalterbild', [array_key_exists('video', $hero) ? $hero['video'] : 'x', (int) ($hero['image'] ?? 0)], [null, $ph]);
                $free = app()->db->fetchValue("SELECT license FROM media WHERE credit LIKE '%Pixabay%' AND deleted_at IS NULL");
                $eq('Bild mit Pixabay-Hinweis mitgenommen, Lizenz eingetragen', (string) $free, 'Pixabay-Lizenz');
                $imp = (string) app()->db->fetchValue('SELECT content_published FROM pages WHERE id = :i', ['i' => (int) app()->settings->get('imprint_page')]);
                $eq('Impressum: Vertreten durch und Verantwortlicher eingesetzt', [str_contains($imp, 'Erika Beispiel (Vorsitzende)'), str_contains($imp, 'Max Beispiel, Beispielweg 1'), str_contains($imp, '{{org.representatives}}') || str_contains($imp, '{{org.responsible}}')], [true, true, false]);
                break;
            case 'grow':
                $t = Tables::find('personen');
                $in = Tables::toInput($t);
                $in['fields'][] = ['label' => 'Ehrenamt seit', 'name' => 'ehrenamt_seit', 'type' => 'date'];
                [$def, $err] = Tables::validate($in, $t);
                if ($err) { fwrite(STDERR, implode(' ', $err) . "\n"); return 1; }
                Tables::update($t, $def);
                \Core\Pages::create(['slug' => 'mitmachen', 'title' => 'Mitmachen', 'status' => 'published', 'menu' => 1], []);
                return 0;
            case 'update':
                $pkg = Package::open($opt('pkg'));
                $bp = (array) app()->settings->get(Apply::KEY, []);
                $diff = Diff::compare((array) ($bp['structure'] ?? []), Diff::signature($pkg['dir']));
                $eq('Vorschlag: neues Feld und neue Seite', [$diff['added']['fields'], $diff['added']['pages']], [['personen.ehrenamt_seit'], ['-:/mitmachen']]);
                $home = (string) app()->db->fetchValue('SELECT content_published FROM pages WHERE is_home = 1');
                Diff::propose('selftest-vorlage', '1.1.0', $diff);
                Diff::applyProposal($opt('pkg'));
                \Core\Data\Tables::flush();
                $eq('Feld angelegt', \Core\Data\Tables::field((array) \Core\Data\Tables::find('personen'), 'ehrenamt_seit') !== null, true);
                $eq('Seite als Entwurf, nicht im Menü', app()->db->fetch("SELECT status, menu FROM pages WHERE slug = 'mitmachen'"), ['status' => 'draft', 'menu' => 0]);
                $eq('Inhalte unverändert', (string) app()->db->fetchValue('SELECT content_published FROM pages WHERE is_home = 1'), $home);
                $eq('Version gemerkt, Vorschlag erledigt', [(string) app()->settings->get(Apply::KEY)['version'], Diff::proposal()], ['1.1.0', null]);
                if ($pkg['temp']) Package::remove($pkg['dir']);
                break;
            case 'copycheck':
                $from = $opt('from');
                $srcDb = \Core\Network\Stats::db($from);
                $eq('Kopie: Struktur-Prüfsumme gleich der Quelle', Copy::checksum(app()->db), Copy::checksum($srcDb));
                $eq('Kopie: Einträge vollständig', (int) app()->db->fetchValue('SELECT COUNT(*) FROM data_personen'), (int) $srcDb->fetchValue('SELECT COUNT(*) FROM data_personen'));
                $eq('Kopie: app_key neu', app()->config->get('app_key') !== \Core\Network\Network::config($from)->get('app_key'), true);
                $eq('Kopie: Mail-Passwort geleert', (string) app()->settings->get('sys.mail_pass', ''), '');
                $eq('Kopie: keine Konten (ohne --users=copy)', (int) app()->db->fetchValue('SELECT COUNT(*) FROM users'), 0);
                foreach (Copy::PRIVATE_TABLES as $pt) {
                    try { $n = (int) app()->db->fetchValue("SELECT COUNT(*) FROM $pt"); } catch (\Throwable) { continue; }
                    if ($n) $eq("Kopie: $pt leer", $n, 0);
                }
                $eq('Kopie: Datenschutz-Tabellen leer', true, true);
                break;
            default:
                fwrite(STDERR, "Unbekannter Schritt\n");
                return 1;
        }
        foreach ($fails as $f) fwrite(STDERR, "  ✗ $f\n");
        return $fails ? 1 : 0;
    }
}
