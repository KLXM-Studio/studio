<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Http\Controllers\Admin\DataController;

/**
 * Selbsttest Schlagworte (php bin/console tags:selftest --site=…): freie Eingabe, Schreibweise, Dubletten, ähnliche, Umbenennen,
 * Zusammenführen, Entfernen (mit Versionsnotiz), geteiltes Vokabular, Filter/Zählen, Umstellung Mehrfachauswahl → Schlagworte.
 * Vorübergehende Tabellen, räumt alles ab.
 */
final class TagsSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $ck = function (bool $c, string $msg) use (&$ok, &$fails): void { if ($c) $ok++; else $fails[] = 'Schlagworte – ' . $msg; };
        $tables = [];
        $mk = function (string $name, array $extra) use (&$tables, $ck): ?array {
            $h = 'qa_tg_' . bin2hex(random_bytes(3));
            $pd = DataController::presetDef('');
            $pd['handle'] = $h;
            $pd['name'] = $name;
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
            $a = $mk('QA Wissen', [['name' => 'tags', 'label' => 'Schlagworte', 'type' => 'multiselect', 'free' => '1'],
                ['name' => 'format', 'label' => 'Format', 'type' => 'multiselect', 'options' => "video=Video\nvortrag=Vortrag"]]);
            if (!$a) return ['ok' => $ok, 'fails' => $fails];
            $fa = Tables::field($a, 'tags');
            $ck(Tags::isFree($fa) && ($fa['options'] ?? null) === [], 'Freies Feld ohne Liste gespeichert');
            $va = Tags::vocab($a['handle'], $fa);
            $b = $mk('QA News', [['name' => 'sw', 'label' => 'Schlagworte', 'type' => 'multiselect', 'free' => '1', 'vocab' => $va]]);
            if (!$b) return ['ok' => $ok, 'fails' => $fails];
            $ck(Tags::vocab($b['handle'], Tables::field($b, 'sw')) === $va, 'Geteiltes Vokabular gespeichert');

            [$e1] = Entries::save($a, null, ['titel' => 'A1', 'tags' => ['Rehwild', 'Wald'], 'status' => 'published']);
            [$e2] = Entries::save($a, null, ['titel' => 'A2', 'tags' => ['rehwild', ' Wald ,  Jagd  ', 'Wald'], 'status' => 'published']);
            [$e3] = Entries::save($b, null, ['titel' => 'B1', 'sw' => ['Rewild', 'Schalenwild'], 'status' => 'published']);
            $ck(Entries::find($a, (int) $e2)['tags'] === ['Rehwild', 'Wald', 'Jagd'], 'Schreibweise übernommen, Komma getrennt, ohne Dubletten');
            $vals = Tags::values($va);
            $ck(($vals['Rehwild'] ?? 0) === 2 && ($vals['Rewild'] ?? 0) === 1 && count($vals) === 5, 'Vokabular über beide Tabellen gezählt');
            $ck(in_array('Rehwild', Tags::similar($va, 'Rewild'), true) && in_array('Wald', Tags::similar($va, 'wald'), true), 'Ähnliche erkannt (Tippfehler, Groß/klein)');
            $ck((Tags::duplicates($va)['Rewild'] ?? '') === 'Rehwild', 'Mögliche Dublette markiert');
            $a = Tables::find($a['handle']);
            $ck(isset(Tables::field($a, 'tags')['options']['Schalenwild']), 'Möglichkeiten = vorhandene Schlagworte (auch der anderen Tabelle)');
            $fc = Entries::facets($a, ['tags'], ['status' => 'published']);
            $ck(($fc['counts']['tags']['Wald'] ?? 0) === 2 && count(Entries::query($a, ['status' => 'all', 'where' => [['tags', '=', 'Jagd']]])) === 1, 'Filtern und Zählen wie Mehrfachauswahl');

            $r1 = Tags::merge($va, ['Rewild'], 'Rehwild');
            $ck($r1['changed'] === 1 && Entries::find($b, (int) $e3)['sw'] === ['Rehwild', 'Schalenwild'], 'Zusammenführen über Tabellen hinweg');
            $ck(in_array(true, array_map(fn($x) => str_contains($x['note'], 'zusammengeführt'), Revisions::list($b, (int) $e3)), true), 'Versionsnotiz');
            $r2 = Tags::rename($va, 'Rehwild', 'Reh');
            $ck($r2['changed'] === 3 && Entries::find($a, (int) $e1)['tags'] === ['Reh', 'Wald'], 'Umbenennen in allen Einträgen');
            $r3 = Tags::remove($va, 'Wald');
            $ck($r3['changed'] === 2 && Entries::find($a, (int) $e2)['tags'] === ['Reh', 'Jagd'], 'Entfernen in allen Einträgen');

            // Mehrfachauswahl → Schlagworte (Werte = bisherige Beschriftungen)
            [$e4] = Entries::save($a, null, ['titel' => 'A4', 'format' => ['video', 'vortrag'], 'status' => 'published']);
            $in = Tables::toInput($a);
            foreach ($in['fields'] as &$x) if (($x['name'] ?? '') === 'format') { $x['free'] = '1'; }
            unset($x);
            [, $errs] = DataController::saveSchema($a, $in, false);
            $a = Tables::find($a['handle']);
            $ck($errs === [] && Tags::isFree(Tables::field($a, 'format')) && Entries::find($a, (int) $e4)['format'] === ['Video', 'Vortrag'], 'Umstellung: Werte werden zu Beschriftungen');
            [$again, $aerr] = Tables::validate(Tables::toInput($a), $a);
            $af = array_values(array_filter($again['fields'], fn($x) => $x['name'] === 'tags'))[0] ?? [];
            $ck($aerr === [] && !empty($af['free']) && ($af['options'] ?? null) === [], 'Erneut speichern: frei bleibt frei');
        } finally {
            foreach (array_reverse($tables) as $x) { if ($tt = Tables::find($x)) Tables::delete($tt); app()->db->query('DELETE FROM entry_revisions WHERE tbl = ?', [$x]); }
            Tables::flush();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
