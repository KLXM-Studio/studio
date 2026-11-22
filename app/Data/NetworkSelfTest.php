<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Sites;

/**
 * php bin/console shared:selftest [--sandbox --sites=bund,landa,landb] – Netzwerk für geteilte Tabellen (Core\Data\Network).
 *
 * Ohne --sandbox: Logik ohne Datenbank (Network::selftest, EventRef::selftest, Nähe). Mit --sandbox Ende-zu-Ende mit drei Websites
 * (Verband = Eigentümer, zwei Landesvereine), jeder Schritt als eigener Prozess mit --site=…: Weitergabe-Stufen (nach oben, nach
 * unten mit Abwahl je Eintrag und Kategorie, quer an ausgewählte Websites), Termine aus Abos (Herkunft, schreibgeschützt), Gebiet und
 * „In deiner Nähe“, Absage am Ursprung (Kalender-Datei), Kontakte des Verbands nur öffentlich (Verwaltung, vCard/CardDAV), eine
 * Person mit Funktionen je Website, Regionalgruppen nur im Landesverein, Freigabe je Feld vor Stufe, Löschen am Ursprung, Beitritt
 * mit Plan (existing/mine/both) und Rückweg, Grundschema-Upgrade im Mischstand, Rechte der Schalter.
 *
 * LEGT GETEILTE TABELLEN AN UND ÄNDERT DIE BETEILIGTEN WEBSITES – nur in einer Wegwerf-Kopie der Installation (Schalter --sandbox).
 * Erfundene Daten (Musterstadt, example.org). Am Ende werden die Tabellen wieder aufgelöst.
 */
final class NetworkSelfTest
{
    private static int $ok = 0;
    private static array $fail = [];

    /** Logik ohne Datenbank (Teil von shared:selftest ohne --sandbox) */
    public static function logic(): array
    {
        $res = Network::selftest();
        $x = EventRef::selftest();
        $res['ok'] += $x['ok'];
        array_push($res['fails'], ...$x['fails']);
        $eq = \Core\Testing\Check::eq($res['ok'], $res['fails'], 'Nähe – ');
        $eq('Entfernung Wesel–Köln (km, gerundet)', (int) round(Nearby::km(['lat' => 51.658, 'lng' => 6.617], ['lat' => 50.938, 'lng' => 6.960]) / 10), 8);
        $t = ['handle' => 'termine', 'fields' => [['name' => 'gruppe', 'type' => 'relation', 'target' => 'gruppen'], ['name' => 'standort', 'type' => 'geo']], 'settings' => []];
        $rows = [['id' => 1, 'gruppe' => null, 'standort' => '50.94, 6.96'], ['id' => 2, 'gruppe' => 7, 'standort' => ''], ['id' => 3, 'gruppe' => null, 'standort' => '48.14, 11.58']];
        $loc = ['point' => ['lat' => 51.658, 'lng' => 6.617], 'regions' => ['gruppen:7'], 'sites' => []];
        $eq('Zuständige zuerst, dann im Umkreis, übrige weg', array_column(Nearby::arrange($t, $rows, $loc, ['nearby_km' => 100]), 'id'), [2, 1]);
        $eq('Kleiner Umkreis: nur Zuständige', array_column(Nearby::arrange($t, $rows, $loc, ['nearby_km' => 20]), 'id'), [2]);
        $eq('Modus Website', array_column(Nearby::arrange($t, [['id' => 1, 'origin_site' => 'landa'], ['id' => 2, 'origin_site' => 'landb']], ['point' => null, 'regions' => [], 'sites' => ['landb']], ['nearby_mode' => 'site']), 'id'), [2]);
        $c = ['handle' => 'personen', 'fields' => [['name' => Roles::FIELD, 'type' => Roles::TYPE]], 'settings' => []];
        $eq('Funktion mit Verknüpfung aufs Gebiet', Nearby::inRegion($c, ['id' => 5, Roles::FIELD => [['funktion' => 'Ansprechperson', 'ref' => 'entry:gruppen:7']]], ['gruppen:7']), true);
        return $res;
    }

    public static function run(bool $sandbox, array $sites, ?string $step = null, ?string $state = null): int
    {
        if ($step !== null) return self::step($step, (string) $state);
        if (!$sandbox) {
            $r = self::logic();
            foreach ($r['fails'] as $f) echo "  FEHLER: $f\n";
            echo "  {$r['ok']} Prüfungen bestanden, " . count($r['fails']) . " fehlgeschlagen (Ende-zu-Ende: --sandbox --sites=verband,land1,land2 in einer Kopie)\n";
            return $r['fails'] ? 1 : 0;
        }
        $sites = array_values(array_filter($sites));
        if (count($sites) < 3 || array_diff($sites, array_keys(Sites::all()))) {
            fwrite(STDERR, "Es braucht drei Websites dieser Installation: --sites=verband,land1,land2\n");
            return 1;
        }
        [$bund, $la, $lb] = $sites;
        $file = (string) tempnam(sys_get_temp_dir(), 'netst');
        file_put_contents($file, json_encode(['sfx' => bin2hex(random_bytes(3)), 'sites' => $sites]));
        $call = function (string $site, string $step) use ($file): void {
            $out = [];
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/bin/console') . ' shared:selftest --sandbox --step=' . escapeshellarg($step)
                . ' --state=' . escapeshellarg($file) . ' --site=' . escapeshellarg($site) . ' 2>&1', $out, $code);
            $res = json_decode((string) end($out), true);
            if ($code !== 0 || !is_array($res)) { self::$fail[] = "$step ($site): Prozess fehlgeschlagen – " . implode(' | ', array_slice($out, -6)); return; }
            self::$ok += (int) $res['ok'];
            foreach ((array) $res['fails'] as $f) self::$fail[] = "$step ($site): $f";
        };
        foreach ([[$bund, 'setup'], [$la, 'landA'], [$lb, 'landB'], [$bund, 'bund'], [$la, 'landA2'], [$bund, 'bund2'], [$lb, 'join'], [$bund, 'cleanup'], [$lb, 'cleanupB'], [$bund, 'localPrep'], [$la, 'localMember'], [$bund, 'localBack']] as [$s, $st]) {
            $call($s, $st);
        }
        @unlink($file);
        foreach (self::$fail as $f) echo "  FEHLER: $f\n";
        echo '  ' . self::$ok . ' Prüfungen bestanden, ' . count(self::$fail) . " fehlgeschlagen\n";
        return self::$fail ? 1 : 0;
    }

    // ================================================================= Schritte (je Prozess eine Website)

    private static function step(string $step, string $file): int
    {
        $st = json_decode((string) file_get_contents($file), true) ?: [];
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails);
        try {
            $st = match ($step) {
                'setup' => self::setup($st, $eq), 'landA' => self::landA($st, $eq), 'landB' => self::landB($st, $eq), 'bund' => self::bund($st, $eq),
                'landA2' => self::landA2($st, $eq), 'bund2' => self::bund2($st, $eq), 'join' => self::join($st, $eq), 'cleanup' => self::cleanup($st, $eq), 'cleanupB' => self::cleanupB($st, $eq),
                'localPrep' => self::localPrep($st, $eq), 'localMember' => self::localMember($st, $eq), 'localBack' => self::localBack($st, $eq),
                default => throw new \InvalidArgumentException('Unbekannter Schritt'),
            };
        } catch (\Throwable $e) {
            $fails[] = 'Ausnahme: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        }
        file_put_contents($file, json_encode($st, JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => $ok, 'fails' => $fails], JSON_UNESCAPED_UNICODE) . "\n";
        return 0;
    }

    private static function keys(array $st): array
    {
        $s = (string) $st['sfx'];
        return ['g' => 'ntg_' . $s, 't' => 'ntt_' . $s, 'k' => 'ntk_' . $s, 'a' => 'nta_' . $s];
    }

    private static function table(string $key): array
    {
        Tables::flush();
        Shared::flush();
        return Tables::sharedTable($key) ?? throw new \RuntimeException("Tabelle $key fehlt");
    }

    private static function save(array $t, array $in, ?int $id = null): int
    {
        [$nid, $err] = Entries::save($t, $id, $in);
        if ($err) throw new \RuntimeException('Speichern (' . $t['handle'] . '): ' . json_encode($err, JSON_UNESCAPED_UNICODE));
        return (int) $nid;
    }

    /** Titel der Einträge, die diese Website zeigt (Quelle „site“ bzw. gegeben), sortiert */
    private static function titles(array $t, array $o = []): array
    {
        $out = array_map(fn($e) => Entries::title($t, $e), Entries::query($t, $o + ['status' => 'published', 'lang' => 'all', 'limit' => 500]));
        sort($out);
        return $out;
    }

    private static function make(string $key, array $in, array $members, array $invited = []): void
    {
        [$def, $errors] = Tables::validate($in + ['handle' => $key, '_shared_owner' => site()->key]);
        if ($errors) throw new \RuntimeException("Tabelle $key: " . json_encode($errors, JSON_UNESCAPED_UNICODE));
        Shared::create($def, $members);
        if ($invited) Shared::update($key, ['invited' => $invited]);
    }

    private static function setup(array $st, \Closure $eq): array
    {
        $k = self::keys($st);
        [, $la, $lb] = $st['sites'];
        self::make($k['g'], ['name' => 'Regionalgruppen', 'fields' => [['label' => 'Name', 'name' => 'name', 'type' => 'text', 'required' => 1],
            ['label' => 'Gebiet', 'name' => 'gebiet', 'type' => 'text'], ['label' => 'Website', 'name' => 'website', 'type' => 'text']]], [$la, $lb]);
        self::make($k['t'], ['name' => 'Termine', 'settings' => ['kind' => Kinds::CALENDAR], 'fields' => [
            ['label' => 'Kalender', 'name' => 'kategorie', 'type' => 'multiselect', 'options' => "workshop=Workshop\ntagung=Tagung\nintern=Intern"],
            ['label' => 'Regionalgruppe', 'name' => 'gruppe', 'type' => 'relation', 'target' => $k['g']]]], [$la, $lb]);
        self::make($k['k'], ['name' => 'Kontakte', 'settings' => ['kind' => Kinds::CONTACTS], 'fields' => [
            ['label' => 'Listen', 'name' => 'listen', 'type' => 'multiselect', 'options' => "vorstand=Vorstand\nbund=Bundesvorstand"]]], [$la], [$lb]);
        self::make($k['a'], ['name' => 'Altes Adressbuch', 'settings' => ['kind' => Kinds::CONTACTS], '_kind_version' => 1, 'fields' => [
            ['label' => 'Listen', 'name' => 'listen', 'type' => 'multiselect', 'options' => 'vorstand=Vorstand']]], [$la]);
        foreach (['g', 't', 'k'] as $x) Network::setFlags($k[$x], ['levels' => true, 'site_fields' => $x === 'k']);
        foreach (['t', 'k'] as $x) Shared::update($k[$x], ['auto' => ['enabled' => true, 'sites' => [], 'where' => []]]);   // Verband übernimmt Netzwerk-Einträge von selbst
        $t = self::table($k['t']);
        $kt = self::table($k['k']);
        $at = self::table($k['a']);
        $eq('Schalter: Stufen an, Spalte da', [Network::levelsOn($t), in_array(Network::COL, array_column(Shared::db($k['t'])->fetchAll("PRAGMA table_info({$t['table']})"), 'name'), true)], [true, true]);
        $eq('Altes Adressbuch hat Grundschema 1', Kinds::tableVersion($at), 1);
        $st['B1'] = self::save($t, ['titel' => 'Bundestagung', 'beginn' => '2027-03-06 10:00', 'kategorie' => ['tagung']]);
        $st['B2'] = self::save($t, ['titel' => 'Bundesvorstand intern', 'beginn' => '2027-03-07 10:00', 'kategorie' => ['intern']]);
        $st['K1'] = self::save($kt, ['vorname' => 'Bernd', 'nachname' => 'Bundesvorstand', 'oeffentlich' => 1, Network::COL => 'network',
            'telefon' => [['label' => 'office', 'value' => '0201 100', 'public' => true], ['label' => 'mobile', 'value' => '0170 999', 'public' => false]],
            'email' => [['label' => 'office', 'value' => 'vorstand@example.org', 'public' => true]]]);
        $eq('Neuer Kontakt: Standard „nur diese Website“, ausdrücklich Netzwerk', [Network::defaultLevel($kt), Network::level(Entries::find($kt, $st['K1']) ?? [])], ['site', 'network']);
        $st['A_b'] = self::save($at, ['vorname' => 'Berta', 'nachname' => 'Beispiel', 'position' => 'Kassenwart', 'listen' => ['vorstand']]);
        return $st;
    }

    private static function landA(array $st, \Closure $eq): array
    {
        $k = self::keys($st);
        [, , $lb] = $st['sites'];
        $t = self::table($k['t']);
        $g = self::table($k['g']);
        $kt = self::table($k['k']);
        $at = self::table($k['a']);
        // Rechte: Schalter nur die Eigentümer-Website
        try { Network::setFlags($k['t'], ['levels' => false]); $eq('Rechte: Mitglied kann Schalter nicht setzen', 'gesetzt', 'Fehler'); }
        catch (\InvalidArgumentException) { $eq('Rechte: Mitglied kann Schalter nicht setzen', 'Fehler', 'Fehler'); }
        $eq('Rechte: Mitglied verwaltet die Tabelle nicht', Shared::canAdmin($k['t']), false);
        $st['G1'] = self::save($g, ['name' => 'Niederrhein', 'gebiet' => '05170', 'website' => site()->key, Network::COL => 'site']);
        $st['T1'] = self::save($t, ['titel' => 'Offener Workshop', 'beginn' => '2027-04-10 18:00', 'kategorie' => ['workshop'], Network::COL => 'network']);
        $st['T2'] = self::save($t, ['titel' => 'Vorstandssitzung Land', 'beginn' => '2027-04-11 18:00', Network::COL => 'site']);
        $st['T3'] = self::save($t, ['titel' => 'Grenztreffen', 'beginn' => '2027-04-12 18:00', Network::COL => 'sites', Network::COL_SITES => [$lb]]);
        $st['T4'] = self::save($t, ['titel' => 'Gruppenabend Niederrhein', 'beginn' => '2027-04-13 19:00', 'gruppe' => $st['G1'], 'standort' => '51.6580, 6.6170']);
        $st['T5'] = self::save($t, ['titel' => 'Stammtisch Musterstadt', 'beginn' => '2027-04-14 19:00', 'standort' => '50.9380, 6.9600']);
        $eq('Termin ohne Angabe: Standard Netzwerk', Network::level(Entries::find($t, $st['T4']) ?? []), 'network');
        // Nach unten: Termine des Verbands von selbst, Kategorie „intern“ abgewählt
        $eq('Nach unten: Verbandstermine von selbst', array_values(array_intersect(self::titles($t), ['Bundestagung', 'Bundesvorstand intern'])), ['Bundestagung', 'Bundesvorstand intern']);
        Shared::saveLocal($k['t'], ['except' => [['kategorie', '=', 'intern']]]);
        $eq('Nach unten: Kategorie abwählbar (außer-Regel)', array_values(array_intersect(self::titles($t), ['Bundestagung', 'Bundesvorstand intern'])), ['Bundestagung']);
        // Kooperation: Termin aus einem Kalender-Abo (Herkunft am Eintrag)
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Muster//DE\r\nBEGIN:VEVENT\r\nUID:partner-1@example.org\r\nDTSTART:20270415T170000Z\r\nDTEND:20270415T190000Z\r\n"
            . "SUMMARY:Partnerseminar\r\nORGANIZER;CN=Musterverein Partner:mailto:info@example.org\r\nURL:https://example.org/seminar\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        \Core\Sources\Sync::$writing = true;
        try {
            $rep = IcsImport::import($t, $ics, ['source' => ['id' => 990001, 'name' => 'Partnerverband', 'options' => []], 'status' => 'published', 'missing' => 'keep', 'categories' => false]);
        } finally {
            \Core\Sources\Sync::$writing = false;
        }
        $eq('Abo: Termin übernommen', [$rep['ok'], $rep['created']], [true, 1]);
        $t = self::table($k['t']);
        $x = Entries::query($t, ['status' => 'all', 'lang' => 'all', 'source' => 'own', 'where' => [['titel', '=', 'Partnerseminar']]])[0] ?? [];
        $st['X1'] = (int) ($x['id'] ?? 0);
        $eq('Abo: Herkunft am Termin', Network::external($x), ['source' => 'Partnerverband', 'organizer' => 'Musterverein Partner', 'url' => 'https://example.org/seminar']);
        $eq('Abo: nicht bearbeitbar', isset(Entries::save($t, $st['X1'], ['titel' => 'Umbenannt'])[1]['_']), true);
        $eq('Abo: Herkunft auf der Website', str_contains(Network::originNote($t, $x), 'Quelle: Partnerverband'), true);
        // Kontakte
        $st['K2'] = self::save($kt, ['vorname' => 'Erika', 'nachname' => 'Musterfrau', 'oeffentlich' => 1, Network::COL => 'network',
            Roles::FIELD => [['funktion' => 'Vorsitz', 'liste' => 'vorstand']], 'email' => [['label' => 'work', 'value' => 'erika@example.org', 'public' => true]]]);
        $st['K3'] = self::save($kt, ['vorname' => 'Rita', 'nachname' => 'Regional', 'oeffentlich' => 1,
            Roles::FIELD => [['funktion' => 'Ansprechperson', 'ref' => 'entry:' . $k['g'] . ':' . $st['G1']]]]);
        $st['K4'] = self::save($kt, ['vorname' => 'Nina', 'nachname' => 'Nichtöffentlich', 'oeffentlich' => 0, Network::COL => 'network']);
        $eq('Kontakt ohne Angabe: nur diese Website', Network::level(Entries::find($kt, $st['K3']) ?? []), 'site');
        $k1 = Entries::find($kt, (int) $st['K1']) ?? [];
        $eq('Kontakt Verband: nur lesend', isset(Entries::save($kt, (int) $st['K1'], ['nachname' => 'X'])[1]['_']), true);
        $eq('Kontakt Verband: Verwaltung sieht nur freigegebene Nummern', array_column((array) ($k1['telefon'] ?? []), 'value'), ['0201 100']);
        $vc = VCards::export($kt, Exposure::forApps($kt, Entries::find($kt, (int) $st['K1']) ?? []));
        $eq('Kontakt Verband: vCard/CardDAV ohne private Nummer', [str_contains($vc, '0170'), str_contains($vc, '0201 100')], [false, true]);
        $eq('Kontakt Verband: Herkunft auf der Website', str_contains(Network::originNote($kt, $k1), 'von'), true);
        // Grundschema-Mischstand: nur der Eigentümer hebt
        $plan = KindUpgrade::plan($at);
        $eq('Upgrade: Mitglied hebt nicht', [$plan['ok'], str_contains(implode(' ', $plan['errors']), 'gehört')], [false, true]);
        $st['A_a'] = self::save($at, ['vorname' => 'Anton', 'nachname' => 'Anders', 'position' => 'Beisitz', 'listen' => ['vorstand']]);
        return $st;
    }

    private static function landB(array $st, \Closure $eq): array
    {
        $k = self::keys($st);
        $t = self::table($k['t']);
        $titles = self::titles($t);
        $eq('Quer: an diese Website gerichteter Termin erscheint', in_array('Grenztreffen', $titles, true), true);
        $eq('Quer: Netzwerk-Termin eines anderen Mitglieds nicht (Verband zeigt sie nicht untereinander)', in_array('Offener Workshop', $titles, true), false);
        $eq('Nicht gerichtete interne Termine nie', in_array('Vorstandssitzung Land', $titles, true), false);
        Shared::setPick($t, [(int) $st['B1']], 'hidden');
        $eq('Nach unten: je Eintrag abwählbar', array_values(array_intersect(self::titles($t), ['Bundestagung', 'Bundesvorstand intern'])), ['Bundesvorstand intern']);
        // Beitritt vorbereiten: eigenes, älteres Adressbuch (v1) mit einem Doppel des Verbandskontakts
        [$def, $err] = Tables::validate(['name' => 'Kontakte', 'handle' => $k['k'], 'settings' => ['kind' => Kinds::CONTACTS], '_kind_version' => 1,
            'fields' => [['label' => 'Listen', 'name' => 'listen', 'type' => 'multiselect', 'options' => 'vorstand=Vorstand']]]);
        if ($err) throw new \RuntimeException('Lokale Tabelle: ' . json_encode($err, JSON_UNESCAPED_UNICODE));
        $lt = Tables::find(Tables::create($def)) ?? throw new \RuntimeException('Lokale Tabelle fehlt');
        $st['L1'] = self::save($lt, ['vorname' => 'Bernd', 'nachname' => 'Bundesvorstand', 'email' => [['label' => 'office', 'value' => 'vorstand@example.org', 'public' => true]]]);
        $st['L2'] = self::save($lt, ['vorname' => 'Max', 'nachname' => 'Mustermann', 'position' => 'Kassenwart', 'listen' => ['vorstand'], 'oeffentlich' => 1]);
        return $st;
    }

    private static function bund(array $st, \Closure $eq): array
    {
        $k = self::keys($st);
        [, $la] = $st['sites'];
        $t = self::table($k['t']);
        $kt = self::table($k['k']);
        $titles = self::titles($t);
        $eq('Nach oben: Netzwerk-Termine erscheinen beim Verband', array_values(array_intersect($titles, ['Offener Workshop', 'Gruppenabend Niederrhein', 'Partnerseminar'])),
            ['Gruppenabend Niederrhein', 'Offener Workshop', 'Partnerseminar']);
        $eq('Nach oben: interne und gerichtete Termine nicht', array_values(array_intersect($titles, ['Vorstandssitzung Land', 'Grenztreffen'])), []);
        $x = Entries::find($t, (int) $st['X1']) ?? [];
        $eq('Abo-Termin beim Verband: Herkunft bleibt sichtbar', str_contains(Network::originNote($t, $x), 'Partnerverband'), true);
        $eq('Kontakte: Netzwerk + öffentlich sichtbar, nur-Website und nicht freigegebene nicht', self::titles($kt), ['Bernd Bundesvorstand', 'Erika Musterfrau']);
        // Eine Person, Funktionen je Website: Zusatzfeld „funktionen“ beim Verband
        [, $errs] = Network::saveSiteFields($kt, [['label' => 'Funktionen im Verband', 'name' => Roles::FIELD, 'type' => Roles::TYPE], ['label' => 'Bemerkung Verband', 'type' => 'text']]);
        $eq('Zusatzfelder angelegt', $errs, []);
        $kt = self::table($k['k']);
        $eq('Zusatzangaben am fremden Kontakt', Network::saveExtras($kt, (int) $st['K2'], [Roles::FIELD => [['funktion' => 'Beisitz', 'liste' => 'bund']], 'bemerkung_verband' => 'Delegierte']), []);
        $e = Entries::find($kt, (int) $st['K2']) ?? [];
        $eq('Funktion auf dieser Website', [array_column(Roles::items($e[Roles::FIELD] ?? []), 'funktion'), $e['bemerkung_verband'] ?? null], [['Beisitz'], 'Delegierte']);
        $eq('Keine Dublette', count(array_filter(Entries::query($kt, ['status' => 'all', 'lang' => 'all']), fn($r) => Entries::title($kt, $r) === 'Erika Musterfrau')), 1);
        // „Ansprechpartner in deinem Land“: nur Einträge der zuständigen Website
        $rows = Nearby::arrange($kt, Entries::query($kt, ['status' => 'published', 'lang' => 'all', 'source' => 'all']), ['point' => null, 'regions' => [], 'sites' => [$la]], ['nearby_mode' => 'site']);
        $eq('Zuständige Website: nur deren freigegebene Kontakte', array_map(fn($r) => Entries::title($kt, $r), $rows), ['Erika Musterfrau']);
        // Grundschema im Mischstand: Eigentümer hebt für alle
        $at = self::table($k['a']);
        $plan = KindUpgrade::plan($at);
        $eq('Upgrade: Probelauf zeigt Websites', [$plan['ok'], $plan['sites'] ?? null], [true, [site()->key => 1, $la => 1]]);
        KindUpgrade::apply($at, $plan);
        $at = self::table($k['a']);
        $fn = [];
        foreach (Shared::db($k['a'])->fetchAll("SELECT nachname, " . Roles::FIELD . " AS f FROM {$at['table']} ORDER BY nachname") as $r) $fn[$r['nachname']] = array_column(Roles::items($r['f']), 'funktion');
        $eq('Upgrade: Funktionen aller Websites gefüllt', [Kinds::tableVersion($at), $fn], [2, ['Anders' => ['Beisitz'], 'Beispiel' => ['Kassenwart']]]);
        return $st;
    }

    private static function landA2(array $st, \Closure $eq): array
    {
        $k = self::keys($st);
        $t = self::table($k['t']);
        $kt = self::table($k['k']);
        $e = Entries::find($kt, (int) $st['K2']) ?? [];
        $eq('Funktion am Ursprung unverändert', array_column(Roles::items($e[Roles::FIELD] ?? []), 'funktion'), ['Vorsitz']);
        $eq('Zusatzangaben des Verbands hier unsichtbar', array_key_exists('bemerkung_verband', $e), false);
        // In deiner Nähe: Gruppe zuerst, dann Umkreis
        $occ = Calendar::occurrences($t, new \DateTimeImmutable('2027-04-01'), new \DateTimeImmutable('2027-05-01'), []);
        $loc = ['point' => ['lat' => 51.658, 'lng' => 6.617], 'regions' => [$k['g'] . ':' . $st['G1']], 'sites' => []];
        $eq('Nähe: Termine der Regionalgruppe zuerst, dann im Umkreis', array_map(fn($o) => Entries::title($t, $o['entry']), Nearby::arrange($t, $occ, $loc, ['nearby_km' => 100])),
            ['Gruppenabend Niederrhein', 'Stammtisch Musterstadt']);
        $kc = Entries::query($kt, ['status' => 'published', 'lang' => 'all']);
        $eq('Nähe: Ansprechperson der Regionalgruppe (Funktion mit Verknüpfung)', array_map(fn($r) => Entries::title($kt, $r), Nearby::arrange($kt, $kc, $loc, [])), ['Rita Regional']);
        // Absage am Ursprung
        self::save($t, ['abgesagt' => 1], (int) $st['T1']);
        // Löschen am Ursprung
        Entries::delete($kt, (int) $st['K2']);
        return $st;
    }

    private static function bund2(array $st, \Closure $eq): array
    {
        $k = self::keys($st);
        $t = self::table($k['t']);
        $kt = self::table($k['k']);
        $occ = Calendar::occurrences($t, new \DateTimeImmutable('2027-04-01'), new \DateTimeImmutable('2027-05-01'), []);
        $c = array_values(array_filter($occ, fn($o) => (int) $o['entry']['id'] === (int) $st['T1']))[0] ?? null;
        $eq('Absage am Ursprung kommt beim Verband an', !empty($c['cancelled']), true);
        $ics = ICal::calendar($t, Entries::query($t, ['status' => 'published', 'lang' => 'all']));
        $eq('Absage auch in der Kalender-Datei (Abo/CalDAV)', (bool) preg_match('~SUMMARY:Offener Workshop.*?STATUS:CANCELLED|STATUS:CANCELLED.*?SUMMARY:Offener Workshop~s', $ics), true);
        $eq('Löschen am Ursprung: überall weg', [Entries::find($kt, (int) $st['K2']), (int) Shared::db($k['k'])->fetchValue('SELECT COUNT(*) FROM share_extra WHERE extra_entry = ?', [(int) $st['K2']])], [null, 0]);
        return $st;
    }

    private static function join(array $st, \Closure $eq): array
    {
        $k = self::keys($st);
        $plan = Network::joinPlan($k['k']);
        $eq('Beitritt: Probelauf findet das Doppel', [$plan['ok'], array_column($plan['dupes'], 'title')], [true, ['Bernd Bundesvorstand']]);
        $log = Network::join($k['k'], [(int) $st['L1'] => 'existing']);
        $eq('Beitritt: Protokoll', $log !== [], true);
        $kt = self::table($k['k']);
        $eq('Beitritt: eigener Kontakt übernommen, Doppel nicht', self::titles($kt, ['source' => 'all']), ['Bernd Bundesvorstand', 'Max Mustermann']);
        $max = Entries::query($kt, ['status' => 'all', 'lang' => 'all', 'source' => 'own'])[0] ?? [];
        $eq('Beitritt: übernommene Kontakte nur auf dieser Website, Funktionen ergänzt (v1 → v2)', [Network::level($max), array_column(Roles::items($max[Roles::FIELD] ?? []), 'funktion')], ['site', ['Kassenwart']]);
        $back = Shared::leave($k['k']);
        $eq('Rückweg: wieder eigene Tabelle', [$back !== [], Tables::find($k['k']) !== null && !Tables::isShared((array) Tables::find($k['k']))], [true, true]);
        return $st;
    }

    private static function cleanup(array $st, \Closure $eq): array
    {
        $k = self::keys($st);
        foreach (['t', 'k', 'a', 'g'] as $x) {
            $meta = Shared::meta($k[$x]);
            if (!$meta) continue;
            // Mitglieder austragen, dann auflösen (Sicherung bleibt unter storage/shared/_removed/)
            Shared::update($k[$x], ['members' => []]);
            Shared::unshare($k[$x], true);
            if ($lt = Tables::find($k[$x])) Tables::delete($lt);
        }
        $eq('Aufgeräumt', Shared::meta($k['t']), null);
        return $st;
    }

    /** Land B: nach dem Rückweg wieder eigene Tabelle – im Test entfernen */
    private static function cleanupB(array $st, \Closure $eq): array
    {
        $k = self::keys($st);
        if ($lt = Tables::find($k['k'])) Tables::delete($lt);
        $eq('Land B aufgeräumt', Tables::find($k['k']), null);
        return $st;
    }

    // ================================================================= Teilen mit Verknüpfungen auf lokale Tabellen (Fall oejv: Termine/Personen)

    /** Lokale Tabelle anlegen (Website, nicht geteilt) */
    private static function local(string $handle, array $in): array
    {
        [$def, $err] = Tables::validate($in + ['handle' => $handle]);
        if ($err) throw new \RuntimeException("Tabelle $handle: " . json_encode($err, JSON_UNESCAPED_UNICODE));
        return Tables::find(Tables::create($def)) ?? throw new \RuntimeException("Tabelle $handle fehlt");
    }

    private static function localPrep(array $st, \Closure $eq): array
    {
        $s = (string) $st['sfx'];
        [, $la] = $st['sites'];
        $rg = self::local('ntr_' . $s, ['name' => 'Regionalgruppen', 'settings' => ['route' => 'ntr-' . $s], 'fields' => [['label' => 'Name', 'name' => 'name', 'type' => 'text', 'required' => 1]]]);
        $ws = self::local('ntw_' . $s, ['name' => 'Workshops', 'fields' => [['label' => 'Titel', 'name' => 'titel', 'type' => 'text', 'required' => 1]]]);
        $cal = self::local('ntc_' . $s, ['name' => 'Termine', 'settings' => ['kind' => Kinds::CALENDAR], 'fields' => [
            ['label' => 'Regionalgruppe', 'name' => 'regionalgruppe', 'type' => 'relation', 'target' => $rg['handle']],
            ['label' => 'Workshop', 'name' => 'workshop', 'type' => 'relation', 'target' => $ws['handle']]]]);
        $ppl = self::local('ntp_' . $s, ['name' => 'Personen', 'settings' => ['kind' => Kinds::CONTACTS], 'fields' => [
            ['label' => 'Regionalgruppe', 'name' => 'regionalgruppe', 'type' => 'relation', 'target' => $rg['handle']]]]);
        $eq('Kalender: „Ort aus der Liste“ ohne Ziel', (string) (Tables::field($cal, 'ort_ref')['target'] ?? 'x'), '');
        $st['R1'] = self::save($rg, ['name' => 'Niederrhein']);
        $st['R2'] = self::save($rg, ['name' => 'Ruhr']);
        $st['W1'] = self::save($ws, ['titel' => 'Kartieren']);
        $st['C1'] = self::save($cal, ['titel' => 'Gruppenabend', 'beginn' => '2027-05-03 19:00', 'regionalgruppe' => $st['R1'], 'workshop' => $st['W1']]);
        $st['C2'] = self::save($cal, ['titel' => 'Stammtisch', 'beginn' => '2027-05-04 19:00', 'regionalgruppe' => $st['R2']]);
        $st['C3'] = self::save($cal, ['titel' => 'Ohne Gruppe', 'beginn' => '2027-05-05 19:00']);
        $st['P1'] = self::save($ppl, ['vorname' => 'Rita', 'nachname' => 'Regional', 'regionalgruppe' => $st['R1'], 'oeffentlich' => 1]);
        $titles = fn(array $t, array $o) => array_map(fn($e) => Entries::title($t, $e), Entries::query($t, $o + ['status' => 'all', 'lang' => 'all']));
        // Probelauf ohne Wahl: Vorschlag, keine leeren Namen
        $plan = Network::sharePlan($cal);
        $eq('Plan: Verknüpfung ohne Ziel „nicht eingerichtet“', array_column($plan['unset'], 'name'), ['ort_ref']);
        $eq('Plan: lokale Verknüpfungen mit Anzahl', array_map(fn($r) => $r['name'] . ':' . $r['filled'], $plan['local']), ['regionalgruppe:2', 'workshop:1']);
        $eq('Plan: Vorschlag --local-fields', str_contains((string) $plan['error'], '--local-fields=regionalgruppe,workshop'), true);
        try {
            Shared::shareLocal($cal['handle'], [$la]);
            $eq('Teilen ohne Wahl bricht ab', 'geteilt', 'Fehler');
        } catch (\InvalidArgumentException $e) {
            $eq('Teilen ohne Wahl bricht ab, Meldung ohne leere Namen', [str_contains($e->getMessage(), '„“'), str_contains($e->getMessage(), 'Regionalgruppe')], [false, true]);
        }
        // Teilen mit Zusatzfeldern
        $log = Shared::shareLocal($cal['handle'], [$la], false, ['local_fields' => ['regionalgruppe', 'workshop']]);
        $eq('Teilen: Protokoll nennt nicht eingerichtetes Feld und Zusatzfelder', [(bool) array_filter($log, fn($l) => str_contains($l, 'Ort aus der Liste')), (bool) array_filter($log, fn($l) => str_contains($l, 'Zusatzfeld'))], [true, true]);
        $t = self::table($cal['handle']);
        $eq('Geteilt: Zusatzfelder dieser Website', array_column((array) ($t['site_fields'] ?? []), 'name'), ['regionalgruppe', 'workshop']);
        $eq('Geteilt: Feld nicht mehr im gemeinsamen Schema', Tables::field($t, 'regionalgruppe'), null);
        $c1 = Entries::find($t, (int) $st['C1']) ?? [];
        $eq('Werte bleiben für die Eigentümer-Website', [$c1['regionalgruppe'] ?? null, $c1['workshop'] ?? null], [(int) $st['R1'], (int) $st['W1']]);
        $eq('Filter auf Zusatzfeld (Entries::query)', $titles($t, ['where' => [['regionalgruppe', '=', (string) $st['R1']]]]), ['Gruppenabend']);
        $eq('Filter „ist nicht“ auf Zusatzfeld', $titles($t, ['where' => [['regionalgruppe', '!=', (string) $st['R1']]], 'sort' => 'titel']), ['Ohne Gruppe', 'Stammtisch']);
        // Datenliste/Kalender auf der Detailseite der Regionalgruppe: „verknüpft mit dem aufgerufenen Eintrag“
        $prev = app()->entry;
        app()->entry = ['table' => $rg, 'entry' => Entries::find($rg, (int) $st['R2']) ?? []];
        [$w] = Calendar::blockWhere($t, ['filter_field' => $t['handle'] . '.regionalgruppe', 'filter_op' => 'current']);
        $eq('Detailseite der Regionalgruppe: Termine (current)', $titles($t, ['where' => $w, 'status' => 'published']), ['Stammtisch']);
        app()->entry = ['table' => $t, 'entry' => $c1];
        [$w] = Calendar::blockWhere($t, ['filter_field' => $t['handle'] . '.regionalgruppe', 'filter_op' => 'same']);
        $eq('„gleich wie beim aufgerufenen Eintrag“ (same)', $titles($t, ['where' => $w]), ['Gruppenabend']);
        app()->entry = $prev;
        $eq('In deiner Nähe über die Regionalgruppe (Zusatzfeld)', Nearby::inRegion($t, $c1, [$rg['handle'] . ':' . $st['R1']]), true);
        $eq('Zusatzangabe ändern', Network::saveExtras($t, (int) $st['C3'], ['regionalgruppe' => (string) $st['R2']]), []);
        $eq('Geänderte Zuordnung wirkt im Filter', $titles(self::table($cal['handle']), ['where' => [['regionalgruppe', '=', (string) $st['R2']]], 'sort' => 'titel']), ['Ohne Gruppe', 'Stammtisch']);
        // Personen mit Regionalgruppe
        Shared::shareLocal($ppl['handle'], [$la], false, ['local_fields' => ['regionalgruppe']]);
        $pt = self::table($ppl['handle']);
        $eq('Personen: Regionalgruppe bleibt (Zusatzfeld)', (Entries::find($pt, (int) $st['P1']) ?? [])['regionalgruppe'] ?? null, (int) $st['R1']);
        return $st;
    }

    private static function localMember(array $st, \Closure $eq): array
    {
        $t = self::table('ntc_' . $st['sfx']);
        $c1 = Entries::find($t, (int) $st['C1']) ?? [];
        $eq('Andere Website: Termin sichtbar, Zuordnung der Eigentümer-Website nicht', [Entries::title($t, $c1), array_key_exists('regionalgruppe', $c1), (array) ($t['site_fields'] ?? [])], ['Gruppenabend', false, []]);
        return $st;
    }

    private static function localBack(array $st, \Closure $eq): array
    {
        $s = (string) $st['sfx'];
        foreach (['ntc_' . $s, 'ntp_' . $s] as $h) {
            Shared::update($h, ['members' => []]);
            Shared::unshare($h, true);
        }
        Tables::flush();
        $t = Tables::find('ntc_' . $s) ?? throw new \RuntimeException('Termine nicht zurück');
        $c = Entries::find($t, (int) $st['C1']) ?? [];
        $eq('Rückweg: Felder wieder Spalten, Werte zurück', [!Tables::isShared($t), Tables::field($t, 'regionalgruppe')['target'] ?? null, $c['regionalgruppe'] ?? null, $c['workshop'] ?? null],
            [true, 'ntr_' . $s, (int) $st['R1'], (int) $st['W1']]);
        $eq('Rückweg: geänderte Zuordnung übernommen', (Entries::find($t, (int) $st['C3']) ?? [])['regionalgruppe'] ?? null, (int) $st['R2']);
        $pt = Tables::find('ntp_' . $s);
        $eq('Rückweg Personen', $pt ? ((Entries::find($pt, (int) $st['P1']) ?? [])['regionalgruppe'] ?? null) : null, (int) $st['R1']);
        foreach (['ntc_', 'ntp_', 'ntw_', 'ntr_'] as $p) if ($lt = Tables::find($p . $s)) Tables::delete($lt);
        return $st;
    }
}
