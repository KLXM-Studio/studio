<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Http\Request;
use Core\Pages;

/**
 * Selbsttest Funktionen je Person (Core\Data\Roles) und Grundschema-Upgrade (Core\Data\KindUpgrade) – Teil von `php bin/console
 * data:selftest`. Stichtag fest (Roles::$today). Ohne Datenbank: Aktualität, Auswahl je Zusammenhang, Zugehörigkeit, Speichern
 * (Listen ableiten, Hauptfunktion, App ändert TITLE), Ende in einer Liste. Mit vorübergehenden Tabellen:
 *
 *  - Adressbuch Version 1 mit Funktion + Listen, Probelauf (ändert nichts), Upgrade (je Liste eine Funktion, Funktion bei der ersten,
 *    Version 2, Abschnitt), Rückweg (Version 1, Feld ausgeblendet, Werte bleiben), erneutes Upgrade (Werte bleiben).
 *  - Funktionen je Zusammenhang: Personen-Block mit Filter nach Liste (Funktion dieser Liste, Reihenfolge, beendete fehlen,
 *    „Ehemalige“), Detailseite eines verknüpften Eintrags (verknüpfte Funktion, Filter „Funktionen“ ohne Wert), Datenliste,
 *    Visitenkarte TITLE = Hauptfunktion, Adressbuch (Zähler/Liste ohne beendete, „Aus Liste entfernen“ beendet die Funktion).
 * Alles wird danach gelöscht.
 */
final class RolesSelfTest
{
    private const TODAY = '2026-06-15';

    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Funktionen – ');
        $prevToday = Roles::$today;
        Roles::$today = self::TODAY;
        $prevReq = app()->request;
        $prevEntry = app()->entry;
        $made = [];
        $pages = [];
        $load = fn(string $hh): array => Tables::find($hh) ?? throw new \RuntimeException("Tabelle „{$hh}“ fehlt");
        try {
            self::units($eq);
            // ---------------------------------------------------------- Upgrade v1 → v2
            $h = 'selftest_fn_' . bin2hex(random_bytes(3));
            $def = \Core\Http\Controllers\Admin\DataController::presetDef('contacts');
            $def['handle'] = $h;
            $def['name'] = 'Selbsttest Funktionen';
            $def['settings']['route'] = str_replace('_', '-', $h);
            $def['fields'] = array_values(array_filter(Kinds::presetFields(Kinds::CONTACTS, 1), fn($f) => true));
            foreach ($def['fields'] as &$f) if (($f['name'] ?? '') === 'listen') $f['options'] = "Vorstand\nBeirat\nPresse";
            unset($f);
            $def['_kind_version'] = 1;
            [$clean, $err] = Tables::validate($def);
            $eq('v1-Tabelle gültig, Version 1, ohne Funktionen', [$err, $clean['settings']['kind_version'] ?? null, in_array(Roles::FIELD, array_column($clean['fields'], 'name'), true)], [[], 1, false]);
            Tables::create($clean);
            $made[] = $h;
            $t = $load($h);
            [$rt] = Tables::validate(Tables::toInput($t), $t);
            $eq('Designer hebt v1 nicht von selbst', [$rt['settings']['kind_version'] ?? null, in_array(Roles::FIELD, array_column($rt['fields'], 'name'), true)], [1, false]);
            $mk = fn(array $in) => (int) (Entries::save($t, null, $in + ['oeffentlich' => true, 'status' => 'published'])[0] ?? 0);
            $a = $mk(['vorname' => 'Erika', 'nachname' => 'Musterfrau', 'position' => 'Vorsitz', 'listen' => ['presse', 'vorstand']]);
            $b = $mk(['vorname' => 'Max', 'nachname' => 'Mustermann', 'position' => 'Ansprechperson', 'listen' => []]);
            $c = $mk(['vorname' => 'Lena', 'nachname' => 'Beispiel', 'position' => '', 'listen' => ['beirat']]);
            $plan = KindUpgrade::plan($t);
            $eq('Probelauf: v1 → v2, Feld funktionen, nichts geändert', [$plan['ok'], $plan['from'] ?? null, $plan['to'] ?? null, $plan['added'] ?? null, Kinds::tableVersion($load($h))],
                [true, 1, 2, [Roles::FIELD], 1]);
            $eq('Probelauf: je Liste eine Funktion (Reihenfolge der Listen), Funktion bei der ersten',
                KindUpgrade::rolesFor($t, Entries::find($t, $a) ?? []), [['funktion' => 'Vorsitz', 'liste' => 'vorstand'], ['liste' => 'presse']]);
            KindUpgrade::apply($t, $plan);
            $t = $load($h);
            $names = array_column(Tables::allFields($t), 'name');
            $eq('Upgrade: Version 2, Abschnitt „Funktionen & Listen“ vor „Kontakt“', [Kinds::tableVersion($t), array_slice($names, (int) array_search('abschnitt_funktionen', $names, true), 4)],
                [2, ['abschnitt_funktionen', Roles::FIELD, 'listen', 'abschnitt_kontakt']]);
            $ea = Entries::find($t, $a) ?? [];
            $eq('Upgrade: Werte', [Roles::items($ea[Roles::FIELD])[0]['funktion'], Roles::items($ea[Roles::FIELD])[1]['liste'], Roles::items((Entries::find($t, $b) ?? [])[Roles::FIELD])[0]['funktion'],
                Roles::items((Entries::find($t, $c) ?? [])[Roles::FIELD])[0]['liste'], $ea['position'], $ea['listen']], ['Vorsitz', 'presse', 'Ansprechperson', 'beirat', 'Vorsitz', ['presse', 'vorstand']]);
            $eq('Zweites Upgrade nicht nötig', KindUpgrade::plan($t)['ok'], false);
            KindUpgrade::revert($t);
            $t = $load($h);
            $eq('Rückweg: Version 1, Feld ausgeblendet, Werte bleiben', [Kinds::tableVersion($t), !empty(Tables::field($t, Roles::FIELD)['legacy']), Roles::has($t),
                (string) Tables::db($t)->fetchValue("SELECT " . Roles::FIELD . " FROM {$t['table']} WHERE id = ?", [$a]) !== ''], [1, true, false, true]);
            $plan = KindUpgrade::plan($t);
            $eq('Erneutes Upgrade: vorhandene Funktionen bleiben', [$plan['ok'], $plan['keep'] ?? null], [true, 3]);
            KindUpgrade::apply($t, $plan);
            $t = $load($h);
            $eq('Erneutes Upgrade: Version 2, sichtbar, Funktion erhalten', [Kinds::tableVersion($t), Roles::has($t), Roles::mainText($t, Entries::find($t, $a) ?? [])], [2, true, 'Vorsitz']);

            // ---------------------------------------------------------- Funktionen je Zusammenhang
            $ak = 'selftest_ak_' . bin2hex(random_bytes(3));
            [$ac, $aerr] = Tables::validate(['handle' => $ak, 'name' => 'Selbsttest Arbeitskreise', 'singular' => 'Arbeitskreis',
                'fields' => [['label' => 'Name', 'name' => 'name', 'type' => 'text', 'required' => 1]], 'settings' => ['route' => str_replace('_', '-', $ak), 'title_field' => 'name']]);
            $eq('Arbeitskreise gültig', $aerr, []);
            Tables::create($ac);
            $made[] = $ak;
            $akt = $load($ak);
            [$jugend] = Entries::save($akt, null, ['name' => 'AK Jugend', 'status' => 'published']);
            $ref = "entry:$ak:$jugend";
            // Erika: Vorsitz Vorstand (2.), Ansprechperson AK Jugend; Max: Kassenwart Vorstand beendet, Beisitz Beirat; Lena: stellv. Vorsitz (1.)
            [, $e1] = Entries::save($t, $a, [Roles::FIELD => [['funktion' => 'Vorsitz', 'liste' => 'vorstand', 'reihenfolge' => '2'], ['funktion' => 'Ansprechperson', 'ref' => $ref, 'seit' => '2025-01-01']]]);
            [, $e2] = Entries::save($t, $b, [Roles::FIELD => [['funktion' => 'Kassenwart', 'liste' => 'vorstand', 'seit' => '2020-01-01', 'bis' => '2026-03-31'], ['funktion' => 'Beisitz', 'liste' => 'beirat']]]);
            [, $e3] = Entries::save($t, $c, [Roles::FIELD => [['funktion' => 'Stellv. Vorsitz', 'liste' => 'vorstand', 'reihenfolge' => '1']]]);
            $eq('Speichern ohne Fehler', [$e1, $e2, $e3], [[], [], []]);
            $ra = Entries::find($t, $a) ?? [];
            $rb = Entries::find($t, $b) ?? [];
            $eq('Listen aus Funktionen: entfernte Funktion nimmt ihre Liste mit', $ra['listen'], ['vorstand']);
            $eq('Beendete Funktion: Liste fällt weg, Hauptfunktion = erste aktuelle', [$rb['listen'], $rb['position']], [['beirat'], 'Beisitz']);
            $err = Entries::save($t, $a, [Roles::FIELD => [['funktion' => 'X', 'ref' => 'https://example.org']]])[1];
            $eq('Verknüpfung nur auf Einträge', $err !== [], true);
            $err = Entries::save($t, $a, [Roles::FIELD => [['funktion' => 'X', 'seit' => '2026-05-01', 'bis' => '2026-01-01']]])[1];
            $eq('„bis“ vor „seit“ abgelehnt', $err !== [], true);

            $pid = Pages::create(['slug' => '_vorlage-' . $h, 'title' => 'Selbsttest – Kontakt', 'type' => 'template', 'template_for' => $h, 'status' => 'published', 'noindex' => 1],
                Pages::sanitizeBlocks([['id' => bin2hex(random_bytes(5)), 'type' => 'data_fields', 'data' => ['table' => $h, 'layout' => 'portrait', 'fields' => []]]]));
            $pages[] = $pid;
            Tables::setDetailPage($t, $pid);
            $t = $load($h);
            app()->request = new Request('GET', '/' . $t['settings']['route'], [], [], [], []);
            app()->entry = null;
            $th = app()->theme;
            $render = function (array $blk) use ($th): string {
                $s = Pages::sanitizeBlocks([$blk]);
                return $s ? (string) $th->renderInner($th->makeBlock($s[0])) : '';
            };
            $people = fn(array $d) => \Core\Blocks\PeopleSource::rows($t, $d + ['table' => $h, 'source' => 'filter']);
            $pos = fn(array $rows) => array_map(fn($r) => Entries::title($t, $r['entry']) . ': ' . ($r['entry']['position'] ?? '') . (!empty($r['former']) ? ' (ehemals)' : ''), $rows);
            $eq('Personen-Block, Filter Liste Vorstand: Funktion dieser Liste, Reihenfolge, beendete fehlen',
                $pos($people(['filter_field' => "$h.listen", 'filter_values' => 'Vorstand'])), ['Lena Beispiel: Stellv. Vorsitz', 'Erika Musterfrau: Vorsitz']);
            $eq('… mit „Ehemalige“ darunter', $pos($people(['filter_field' => "$h.listen", 'filter_values' => 'vorstand', 'former' => 'below'])),
                ['Lena Beispiel: Stellv. Vorsitz', 'Erika Musterfrau: Vorsitz', 'Max Mustermann: Kassenwart (ehemals)']);
            $eq('… Filter auf „Funktionen“ mit Liste', $pos($people(['filter_field' => "$h." . Roles::FIELD, 'filter_values' => 'Vorstand'])), ['Lena Beispiel: Stellv. Vorsitz', 'Erika Musterfrau: Vorsitz']);
            $eq('Ohne Filter: Hauptfunktion', in_array('Max Mustermann: Beisitz', $pos($people([])), true), true);
            if (!empty($th->block('people')['core'])) {
                $html = $render(['id' => 'qafnpeople', 'type' => 'people', 'data' => ['table' => $h, 'source' => 'filter', 'filter_field' => "$h.listen", 'filter_values' => 'Vorstand', 'former' => 'below']]);
                $eq('Personen-Block (HTML): Abschnitt „Ehemalige“, Funktion je Liste', [str_contains($html, 'cms-people__former'), str_contains($html, 'Stellv. Vorsitz'), substr_count($html, 'class="cms-person"')], [true, true, 3]);
            }
            // Detailseite des Arbeitskreises
            app()->entry = ['table' => $akt, 'entry' => Entries::find($akt, (int) $jugend) ?? []];
            $eq('Detailseite AK: Filter „Funktionen“ ohne Wert = verknüpfte Personen mit dieser Funktion', $pos($people(['filter_field' => "$h." . Roles::FIELD, 'filter_values' => ''])), ['Erika Musterfrau: Ansprechperson']);
            $man = \Core\Blocks\PeopleSource::rows($t, ['table' => $h, 'items' => [['person' => "entry:$h:$a"]]]);
            $eq('Detailseite AK: von Hand gewählte Person zeigt die verknüpfte Funktion', $man[0]['entry']['position'] ?? null, 'Ansprechperson');
            $dl = Roles::listRows($t, ['filter_field' => "$h." . Roles::FIELD, 'filter_op' => 'current'], Exposure::publicRows($t, Entries::query($t, ['where' => [[Roles::FIELD, '=', $ref]]])));
            $eq('Datenliste „verknüpft mit dem aufgerufenen Eintrag“', array_map(fn($e) => $e['position'], $dl), ['Ansprechperson']);
            $list = $render(['id' => 'qafnlist', 'type' => 'data_list', 'data' => ['table' => $h, 'layout' => 'table', 'fields' => ["$h._title", "$h.position"], 'filter_field' => "$h." . Roles::FIELD, 'filter_op' => 'current']]);
            $eq('Datenliste (HTML) auf der AK-Seite: nur Erika mit Ansprechperson', [str_contains($list, 'Ansprechperson'), str_contains($list, 'Mustermann'), str_contains($list, 'Beispiel')], [true, false, false]);
            app()->entry = null;
            $list = $render(['id' => 'qafnlist2', 'type' => 'data_list', 'data' => ['table' => $h, 'layout' => 'table', 'fields' => ["$h._title", "$h.position"], 'filter_field' => "$h.listen", 'filter_value' => 'vorstand']]);
            $eq('Datenliste (HTML) Filter Vorstand: Funktionen der Liste, beendete fehlen', [str_contains($list, 'Stellv. Vorsitz'), str_contains($list, 'Kassenwart'), str_contains($list, 'Mustermann')], [true, false, false]);
            // Liste noch nicht neu gespeichert (Funktion seither beendet): schon die Abfrage lässt ihn weg – Anzahl und Blättern stimmen
            Tables::db($t)->update($t['table'], ['listen' => json_encode(['beirat', 'vorstand'])], 'id = :id', ['id' => $b]);
            $lq = ['filter_field' => "$h.listen", 'filter_op' => '=', 'filter_value' => 'vorstand'];
            $eq('Datenliste: beendete Funktion schon in der Abfrage weg (Anzahl, Blättern)', [Entries::count($t, ['where' => [['listen', '=', 'vorstand']]]),
                Entries::count($t, Roles::listQuery($t, $lq, ['where' => [['listen', '=', 'vorstand']]])), Roles::listQuery($t, $lq, [])['exclude_ids'] ?? []], [3, 2, [$b]]);
            $vc = (new \Core\Http\Controllers\VCardController())->person(new Request('GET', "/vcard/$h/{$ra['slug']}.vcf", [], [], [], []), $h, (string) $ra['slug']);
            $eq('Visitenkarte: TITLE = Hauptfunktion', [$vc->status, (bool) preg_match('~\r\nTITLE:Vorsitz\r\n~', $vc->body)], [200, true]);
            $vb = \Core\VCard::person($t, $rb);
            $eq('Visitenkarte: beendete Funktion nicht TITLE', [str_contains($vb, 'TITLE:Beisitz'), str_contains($vb, 'Kassenwart')], [true, false]);
            app()->request = $prevReq;
            // Adressbuch der Verwaltung
            $cnt = AddressBook::counts($t);
            $eq('Adressbuch: Zähler ohne beendete', $cnt['lists'], ['vorstand' => 2, 'beirat' => 1, 'presse' => 0]);
            $eq('Adressbuch: Liste Vorstand ohne Max', array_map(fn($r) => (int) $r['id'], AddressBook::index($t, 'vorstand')), [$c, $a]);
            $groups = Roles::groups($t, $rb);
            $eq('Karte: Gremium, ehemalige Funktionen', array_column($groups, 'label'), ['Beirat', 'Ehemalige Funktionen']);
            AddressBook::assign($t, [$a], 'vorstand', false);
            $ra = Entries::find($t, $a) ?? [];
            $va = array_values(array_filter(Roles::items($ra[Roles::FIELD]), fn($i) => $i['liste'] === 'vorstand'));
            $eq('„Aus Liste entfernen“ beendet die Funktion (gestern), Liste weg, Hauptfunktion nächste', [$va[0]['bis'] ?? null, in_array('vorstand', $ra['listen'], true), $ra['position']],
                ['2026-06-14', false, 'Ansprechperson']);
            [, $ae] = Entries::save($t, $a, ['position' => 'Sprecherin']);
            $eq('Nur position geändert (App, API): landet in der Hauptfunktion', [$ae, Roles::main($t, Entries::find($t, $a) ?? [])['funktion'] ?? null], [[], 'Sprecherin']);
        } catch (\Throwable $x) {
            $fails[] = 'Funktionen – Ausnahme: ' . $x->getMessage() . ' (' . basename($x->getFile()) . ':' . $x->getLine() . ')';
        } finally {
            Roles::$today = $prevToday;
            app()->request = $prevReq;
            app()->entry = $prevEntry;
            foreach ($pages as $p) app()->db->query("DELETE FROM pages WHERE id = ? AND type = 'template'", [(int) $p]);   // sonst mit der Tabelle
            foreach ($made as $mh) {
                if ($x = Tables::find($mh)) Tables::delete($x);
                foreach (glob(site()->storage('convert') . '/' . $mh . '*') ?: [] as $file) @unlink($file);
            }
            Tables::flush();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }

    /** Ohne Datenbank (Tabelle nur im Speicher) */
    private static function units(\Closure $eq): void
    {
        $t = ['handle' => 'fn_mem', 'settings' => ['kind' => 'contacts', 'kind_version' => 2], 'fields' => [
            ['name' => 'position', 'label' => 'Funktion', 'type' => 'text'], ['name' => Roles::FIELD, 'label' => 'Funktionen', 'type' => Roles::TYPE],
            ['name' => 'listen', 'label' => 'Listen', 'type' => 'multiselect', 'options' => ['vorstand' => 'Vorstand', 'beirat' => 'Beirat']]]];
        $i = fn(array $x) => Roles::items([$x])[0];
        $eq('aktuell: offen, seit/bis heute, künftig, beendet', [Roles::isCurrent($i(['funktion' => 'a'])), Roles::isCurrent($i(['funktion' => 'a', 'seit' => self::TODAY, 'bis' => self::TODAY])),
            Roles::isCurrent($i(['funktion' => 'a', 'seit' => '2026-07-01'])), Roles::isCurrent($i(['funktion' => 'a', 'bis' => '2026-06-14'])), Roles::isFormer($i(['funktion' => 'a', 'bis' => '2026-06-14']))],
            [true, true, false, false, true]);
        $eq('leere Zeilen fallen weg, Datum gekürzt', Roles::items([['funktion' => ' '], ['liste' => 'beirat', 'seit' => '2026-01-01T00:00']]), [['funktion' => '', 'liste' => 'beirat', 'ref' => '', 'seit' => '2026-01-01', 'bis' => '', 'reihenfolge' => null]]);
        $e = ['listen' => ['vorstand'], Roles::FIELD => [['funktion' => 'Kassenwart', 'liste' => 'vorstand', 'bis' => '2026-01-01'], ['funktion' => 'Beisitz', 'liste' => 'beirat'],
            ['funktion' => 'Ansprechperson', 'ref' => 'entry:ak:3'], ['funktion' => 'Sprecher', 'liste' => 'vorstand', 'seit' => '2027-01-01']]];
        $eq('Zugehörigkeit: beendet, aktuell, von Hand, künftig, keine', [Roles::membership($t, $e, 'vorstand'), Roles::membership($t, $e, 'beirat'), Roles::membership($t, ['listen' => ['beirat']], 'beirat'),
            Roles::membership($t, [Roles::FIELD => [['funktion' => 'x', 'liste' => 'beirat', 'seit' => '2030-01-01']]], 'beirat'), Roles::membership($t, $e, 'presse')], ['former', 'current', 'manual', 'future', '']);
        $pick = fn(array $ctx, bool $former = false) => Roles::pick($t, $e, $ctx, $former)['funktion'] ?? null;
        $eq('Auswahl: Liste, Verknüpfung, Text, sonst Hauptfunktion, beendete nur mit „Ehemalige“',
            [$pick(['lists' => ['beirat']]), $pick(['ref' => 'entry:ak:3']), $pick(['text' => 'sprech']), $pick([]), $pick(['lists' => ['vorstand']]), $pick(['lists' => ['vorstand']], true)],
            ['Beisitz', 'Ansprechperson', 'Ansprechperson', 'Beisitz', 'Beisitz', 'Kassenwart']);
        $eq('Text ohne Funktion = Name der Liste', Roles::text($t, $i(['liste' => 'beirat'])), 'Beirat');
        [$v] = Roles::apply($t, ['position' => 'alt', 'listen' => ['vorstand'], Roles::FIELD => [['funktion' => 'Vorsitz', 'liste' => 'vorstand']]],
            ['position' => 'alt', 'listen' => ['vorstand'], Roles::FIELD => [['funktion' => 'Beisitz', 'liste' => 'beirat']]]);
        $eq('Speichern: Liste der entfernten Funktion fällt weg, neue kommt dazu, position = Hauptfunktion', [$v['listen'], $v['position']], [['beirat'], 'Beisitz']);
        [$v] = Roles::apply($t, ['position' => 'Beisitz', 'listen' => ['vorstand'], Roles::FIELD => [['funktion' => 'Beisitz', 'liste' => 'beirat']]],
            ['position' => 'Beisitz', 'listen' => ['vorstand'], Roles::FIELD => [['funktion' => 'Beisitz', 'liste' => 'beirat']]]);
        $eq('Speichern: von Hand zugeordnete Liste bleibt', $v['listen'], ['vorstand', 'beirat']);
        [$v] = Roles::apply($t, ['position' => 'Beisitz', 'listen' => [], Roles::FIELD => [['funktion' => 'Beisitz']]], ['position' => 'Beirätin', 'listen' => [], Roles::FIELD => [['funktion' => 'Beisitz']]]);
        $eq('Speichern: nur position geändert → Text der Hauptfunktion', [$v[Roles::FIELD][0]['funktion'], $v['position']], ['Beirätin', 'Beirätin']);
        [$v] = Roles::apply($t, null, ['position' => 'Pressewart', 'listen' => [], Roles::FIELD => []]);
        $eq('Neu ohne Funktionen, mit position (App): eine Funktion', [$v[Roles::FIELD], $v['position']], [[['funktion' => 'Pressewart']], 'Pressewart']);
        [$v] = Roles::apply($t, ['position' => 'Beisitz', 'listen' => ['beirat'], Roles::FIELD => [['funktion' => 'Beisitz', 'liste' => 'beirat']]], ['position' => 'Beisitz', 'listen' => ['beirat'], Roles::FIELD => []]);
        $eq('Alle Funktionen entfernt: position leer, Liste der Funktion weg', [$v['position'], $v['listen']], ['', []]);
        $eq('Ende in einer Liste: gestern, heute begonnene fallen weg', Roles::endIn(Roles::items([['funktion' => 'a', 'liste' => 'beirat'], ['funktion' => 'b', 'liste' => 'beirat', 'seit' => self::TODAY], ['funktion' => 'c', 'liste' => 'vorstand']]), 'beirat'),
            Roles::items([['funktion' => 'a', 'liste' => 'beirat', 'bis' => '2026-06-14'], ['funktion' => 'c', 'liste' => 'vorstand']]));
        $html = Roles::html($t, [['funktion' => 'Vorsitz <b>', 'liste' => 'vorstand', 'seit' => '2024-03-01'], ['funktion' => 'Alt', 'bis' => '2020-01-01']]);
        $eq('Ausgabe: escaped, Gremium, seit, ohne beendete', [str_contains($html, 'Vorsitz &lt;b&gt;'), str_contains($html, 'Vorstand'), str_contains($html, '03/2024'), str_contains($html, 'Alt')], [true, true, true, false]);
        $eq('Kurzfassung', Roles::summary([['funktion' => 'Vorsitz'], ['funktion' => 'Alt', 'bis' => '2020-01-01'], ['funktion' => 'Beisitz']]), 'Vorsitz · Beisitz');
        $sch = Roles::schema(['name' => Roles::FIELD, 'type' => Roles::TYPE], $t);
        $eq('Eingabe: Wiederholer mit Liste, Verknüpfung, seit/bis, Reihenfolge, Vorschläge', [$sch['type'], array_column($sch['fields'], 'name'), in_array('Vorsitz', $sch['fields'][0]['suggest'], true)],
            ['repeater', ['funktion', 'liste', 'ref', 'seit', 'bis', 'reihenfolge'], true]);
    }
}
