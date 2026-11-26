<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Http\Controllers\Admin\DataController;

/**
 * Selbsttest „Kategorien als Tabelle“ (php bin/console reassign:selftest --site=…): Löschen mit Neuzuordnung (relation, relations,
 * Versionsnotiz, ungültiges Ziel), Auswahl-Optionen beim Entfernen neu zuordnen (select, multiselect, leer lassen), Filter und Zählen
 * nach Verknüpfungen (Entries::facets, where 'in'), Menüpunkte aus einer Verknüpfung. Vorübergehende Tabellen, räumt alles ab.
 */
final class ReassignSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $ck = function (bool $c, string $msg) use (&$ok, &$fails): void { if ($c) $ok++; else $fails[] = 'Neuzuordnung – ' . $msg; };
        $tables = [];
        $mk = function (string $name, array $extra = []) use (&$tables, $ck): ?array {
            $h = 'qa_ra_' . bin2hex(random_bytes(3));
            $pd = DataController::presetDef('blog');
            $pd['handle'] = $h;
            $pd['name'] = $name;
            $pd['settings']['route'] = str_replace('_', '-', $h);
            $pd['fields'] = array_merge($pd['fields'], $extra);
            [$clean, $err] = Tables::validate($pd);
            $ck($err === [], "Tabelle „{$name}“ gültig: " . json_encode($err, JSON_UNESCAPED_UNICODE));
            if ($err) return null;
            Tables::create($clean);
            Tables::flush();
            $tables[] = $h;
            return Tables::find($h);
        };
        try {
            $th = $mk('QA Themen');
            if (!$th) return ['ok' => $ok, 'fails' => $fails];
            $wi = $mk('QA Wissen', [
                ['name' => 'thema', 'label' => 'Thema', 'type' => 'relation', 'target' => $th['handle']],
                ['name' => 'auch', 'label' => 'Auch', 'type' => 'relations', 'target' => $th['handle']],
                ['name' => 'art', 'label' => 'Art', 'type' => 'select', 'options' => "a=Alpha\nb=Beta\nc=Gamma"],
                ['name' => 'tags', 'label' => 'Schlagworte', 'type' => 'multiselect', 'options' => "x=Iks\ny=Ypsilon\nz=Zett"],
                ['name' => 'kat', 'label' => 'Kategorie', 'type' => 'select', 'options' => "wald=Wald\nwild=Wild\nx_y=X Y"],
                ['name' => 'kats', 'label' => 'Kategorien', 'type' => 'multiselect', 'options' => "eins=Eins\nzwei=Zwei"],
            ]);
            if (!$wi) return ['ok' => $ok, 'fails' => $fails];
            [$wald] = Entries::save($th, null, ['titel' => 'Wald', 'status' => 'published']);
            [$wild] = Entries::save($th, null, ['titel' => 'Wild', 'status' => 'published']);
            [$jagd] = Entries::save($th, null, ['titel' => 'Jagd', 'status' => 'published']);
            [$k1] = Entries::save($wi, null, ['titel' => 'Kapitel 1', 'thema' => $wald, 'auch' => [$wald, $jagd], 'art' => 'a', 'tags' => ['x', 'y'], 'status' => 'published']);
            [$k2] = Entries::save($wi, null, ['titel' => 'Kapitel 2', 'thema' => $wald, 'art' => 'b', 'tags' => ['x'], 'status' => 'published']);
            [$k3] = Entries::save($wi, null, ['titel' => 'Kapitel 3', 'thema' => $wild, 'auch' => [$wild], 'art' => 'a', 'status' => 'published']);

            // Zählen und Filtern nach Verknüpfungen
            $fc = Entries::facets($wi, ['thema', 'auch'], ['status' => 'published']);
            $ck(($fc['counts']['thema'][(string) $wald] ?? 0) === 2 && ($fc['counts']['auch'][(string) $jagd] ?? 0) === 1 && ($fc['counts']['auch'][(string) $wild] ?? 0) === 1, 'Zählen je verknüpftem Eintrag (relation, relations)');
            $ck(count(Entries::query($wi, ['status' => 'all', 'where' => [['auch', 'in', [(string) $jagd, (string) $wild]]]])) === 2, 'Filter „eine von“ bei Mehrfach-Verknüpfung');
            $ck(array_values(Entries::relationOptions(Tables::field($wi, 'thema'))) !== [] && isset(Entries::relationOptions(Tables::field($wi, 'thema'))[(string) $wild]), 'Optionen aus der Zieltabelle');
            $ck(isset(MenuEntries::sourceFields($wi)['thema']) && isset(MenuEntries::sourceFields($wi)['auch']), 'Menüpunkte: Verknüpfungen als Quelle');

            // Löschen mit Neuzuordnung
            $refs = Reassign::refs($th, [(int) $wald]);
            $byKey = array_column($refs, 'count', 'key');
            $ck(($byKey[$wi['handle'] . '.thema'] ?? 0) === 2 && ($byKey[$wi['handle'] . '.auch'] ?? 0) === 1, 'Verweise je Feld gezählt');
            $ck(Reassign::checkTarget($th, [(int) $wald], (int) $wald) !== null && Reassign::checkTarget($th, [(int) $wald], 999999) !== null, 'Ziel: nicht mitgelöscht, muss existieren');
            $res = Reassign::delete($th, [(int) $wald], [$wi['handle'] . '.thema' => (int) $wild]);
            $e1 = Entries::find($wi, (int) $k1);
            $ck($res['deleted'] === 1 && $res['moved'] === 2 && !Entries::find($th, (int) $wald), 'Gelöscht, 2 Einträge umgehängt');
            $ck((int) $e1['thema'] === (int) $wild && (int) Entries::find($wi, (int) $k2)['thema'] === (int) $wild, 'relation: auf neues Ziel umgehängt');
            $ck(array_map('intval', (array) $e1['auch']) === [(int) $jagd], '„Leer lassen“ für das andere Feld: Verweis entfernt, Rest bleibt');
            $ck(in_array(true, array_map(fn($r) => str_contains($r['note'], '„Wald“'), Revisions::list($wi, (int) $k2)), true), 'Versionsnotiz am umgehängten Eintrag');
            $res2 = Reassign::delete($th, [(int) $jagd], ['*' => (int) $wild]);
            $ck($res2['moved'] === 1 && array_map('intval', (array) Entries::find($wi, (int) $k1)['auch']) === [(int) $wild], 'relations: Ziel statt gelöschtem Eintrag (für alle Felder „*“)');
            $ck(array_map('intval', (array) Entries::find($wi, (int) $k3)['auch']) === [(int) $wild], 'Schon verknüpft: kein Doppel');
            $ck(Reassign::delete($th, [(int) $wild], ['*' => (int) $wild])['errors'] !== [] && Entries::find($th, (int) $wild), 'Ungültiges Ziel: nichts gelöscht');

            // Auswahl-Optionen entfernen
            $in = Tables::toInput($wi);
            foreach ($in['fields'] as &$f) {
                if (($f['name'] ?? '') === 'art') $f['options'] = "b=Beta\nc=Gamma";
                if (($f['name'] ?? '') === 'tags') $f['options'] = "y=Ypsilon\nz=Zett";
            }
            unset($f);
            [, $errs, , $ask] = DataController::saveSchema($wi, $in, false);
            $keys = array_map(fn($x) => $x['key'] . ':' . $x['count'], $ask);
            sort($keys);
            $ck(isset($errs['_opts']) && $keys === ['a:2', 'x:2'], 'Rückfrage nennt benutzte, entfernte Optionen mit Anzahl');
            $artId = $tagsId = '';
            foreach ($wi['fields'] as $f) { if ($f['name'] === 'art') $artId = $f['id']; if ($f['name'] === 'tags') $tagsId = $f['id']; }
            [, $errs2] = DataController::saveSchema($wi, $in, false, [$artId => ['a' => 'c'], $tagsId => ['x' => '']]);
            $wi2 = Tables::find($wi['handle']);
            $ck($errs2 === [] && !isset($wi2['fields'][array_search('art', array_column($wi2['fields'], 'name'), true)]['options']['a']), 'Gespeichert nach Antwort');
            $ck(Entries::find($wi2, (int) $k1)['art'] === 'c' && Entries::find($wi2, (int) $k3)['art'] === 'c' && Entries::find($wi2, (int) $k2)['art'] === 'b', 'select: neu zugeordnet');
            $ck(array_values((array) Entries::find($wi2, (int) $k1)['tags']) === ['y'] && array_values((array) Entries::find($wi2, (int) $k2)['tags']) === [], 'multiselect: leer gelassen');

            // Optionen aus einer Tabelle (Core\Data\OptionSource)
            $wi = Tables::find($wi['handle']);
            [$o1] = Entries::save($wi, null, ['titel' => 'O1', 'kat' => 'x_y', 'status' => 'published']);
            [$o2] = Entries::save($wi, null, ['titel' => 'O2', 'kat' => 'wald', 'status' => 'published']);
            $ex = OptionSource::extract($wi, 'kat', 'qa_ra_k' . bin2hex(random_bytes(2)), 'QA Kategorien');
            $tables[] = $ex['table']['handle'];
            $kt = $ex['table'];
            $wi = Tables::find($wi['handle']);
            $kf = Tables::field($wi, 'kat');
            $ck($ex['created'] === 3 && ($kf['options_from'] ?? '') === $kt['handle'], 'Auslagern: Tabelle mit 3 Einträgen, Feld bezieht daraus');
            $ck(array_values($kf['options']) === ['Wald', 'Wild', 'X Y'], 'Optionen aus der Tabelle in deren Reihenfolge');
            $xy = (string) Entries::find($wi, (int) $o1)['kat'];
            $ck($xy !== '' && isset($kf['options'][$xy]) && Tables::optionLabel($kf, $xy) === 'X Y', 'Wert bleibt gültig (ggf. auf Kurznamen umgeschrieben)');
            $stored = json_decode((string) app()->db->fetchValue('SELECT fields_json FROM data_tables WHERE handle = ?', [$wi['handle']]), true);
            $sk = array_values(array_filter($stored, fn($x) => ($x['name'] ?? '') === 'kat'))[0] ?? [];
            $ck(($sk['options'] ?? []) === [] && ($sk['options_from'] ?? '') === $kt['handle'], 'Gespeichert wird nur die Quelle');
            [$again, $aerr] = Tables::validate(Tables::toInput($wi), $wi);
            $ak = array_values(array_filter($again['fields'], fn($x) => $x['name'] === 'kat'))[0] ?? [];
            $ck($aerr === [] && ($ak['options_from'] ?? '') === $kt['handle'], 'Erneut speichern: Quelle bleibt');
            [$neu] = Entries::save($kt, null, ['titel' => 'Neu dazu', 'status' => 'published']);
            $ck(in_array('Neu dazu', Tables::field(Tables::find($wi['handle']), 'kat')['options'], true), 'Neuer Eintrag = neue Option');
            $wid = (int) app()->db->fetchValue("SELECT id FROM {$kt['table']} WHERE slug = 'wald'");
            Entries::save($kt, $wid, ['titel' => 'Wald & Forst']);
            $wi = Tables::find($wi['handle']);
            $ck(Tables::optionLabel(Tables::field($wi, 'kat'), 'wald') === 'Wald & Forst' && Entries::find($wi, (int) $o2)['kat'] === 'wald', 'Umbenennen = Titel ändern, Wert bleibt');
            Entries::save($kt, $wid, ['slug' => 'forst']);
            $ck(Entries::find(Tables::find($wi['handle']), (int) $o2)['kat'] === 'forst', 'Kurzname geändert: Werte nachgezogen');
            $refs = Reassign::refs($kt, [$wid]);
            $ck(count($refs) === 1 && $refs[0]['count'] === 1, 'Löschen: Verweise über das Auswahlfeld erkannt');
            $r3 = Reassign::delete($kt, [$wid], ['*' => (int) $neu]);
            $ck($r3['moved'] === 1 && Entries::find(Tables::find($wi['handle']), (int) $o2)['kat'] === (string) Entries::find($kt, (int) $neu)['slug'], 'Löschen mit Neuzuordnung (Auswahl)');
            Entries::delete($kt, (int) $neu);
            $ck((string) (Entries::find(Tables::find($wi['handle']), (int) $o2)['kat'] ?? '') === '', 'Löschen ohne Zuordnung leert den Wert');
            $ck(OptionSource::extract(Tables::find($wi['handle']), 'kats', '', '')['created'] === 2, 'Mehrfachauswahl auslagern');
            $tables[] = Tables::field(Tables::find($wi['handle']), 'kats')['options_from'];
        } finally {
            foreach (array_reverse($tables) as $x) if ($tt = Tables::find($x)) Tables::delete($tt);
            foreach ($tables as $x) app()->db->query('DELETE FROM entry_revisions WHERE tbl = ?', [$x]);
            Tables::flush();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
