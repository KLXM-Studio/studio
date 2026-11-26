<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Http\Controllers\Admin\DataController;

/**
 * Selbsttest Rückverknüpfungen (php bin/console backlinks:selftest --site=…): Gruppen je Verknüpfungsfeld (relation, relations),
 * Standard an, abschaltbar und mit eigener Beschriftung (bleibt beim erneuten Speichern), Verknüpfen/Lösen am anderen Eintrag,
 * Filter ?f[feld]=ID auch ohne eingerichteten Listenfilter. Vorübergehende Tabellen, räumt alles wieder ab.
 */
final class BacklinksSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $ck = function (bool $c, string $msg) use (&$ok, &$fails): void { if ($c) $ok++; else $fails[] = 'Rückverknüpfung – ' . $msg; };
        $tables = [];
        $mk = function (string $name, array $extra = []) use (&$tables, $ck): ?array {
            $h = 'qa_bl_' . bin2hex(random_bytes(3));
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
            $a = $mk('QA Workshops');
            if (!$a) return ['ok' => $ok, 'fails' => $fails];
            $b = $mk('QA Termine', [
                ['name' => 'bezug', 'label' => 'Workshop', 'type' => 'relation', 'target' => $a['handle']],
                ['name' => 'mehr', 'label' => 'Weitere', 'type' => 'relations', 'target' => $a['handle'], 'backlink' => '0', 'backlink_label' => 'Auch bei'],
            ]);
            if (!$b) return ['ok' => $ok, 'fails' => $fails];
            $fb = Tables::field($b, 'bezug');
            $fm = Tables::field($b, 'mehr');
            $ck(Backlinks::enabled($fb) && !array_key_exists('backlink', $fb), 'Standard an, nichts gespeichert');
            $ck(!Backlinks::enabled($fm) && ($fm['backlink_label'] ?? '') === 'Auch bei', 'Abgeschaltet mit eigener Beschriftung gespeichert');
            [$again] = Tables::validate(Tables::toInput($b), $b);
            $am = null;
            foreach ($again['fields'] as $x) if ($x['name'] === 'mehr') $am = $x;
            $ck($am && ($am['backlink'] ?? null) === false && ($am['backlink_label'] ?? '') === 'Auch bei', 'Erneut speichern: Einstellung bleibt');

            [$w] = Entries::save($a, null, ['titel' => 'Hochsitzbau', 'status' => 'published']);
            [$t1] = Entries::save($b, null, ['titel' => 'Termin 1', 'bezug' => $w, 'status' => 'published']);
            [$t2] = Entries::save($b, null, ['titel' => 'Termin 2', 'bezug' => $w, 'status' => 'draft']);
            [$t3] = Entries::save($b, null, ['titel' => 'Termin 3', 'mehr' => [$w], 'status' => 'published']);
            $g = Backlinks::groups($a, (int) $w);
            $ck(count($g) === 1 && $g[0]['total'] === 2 && $g[0]['label'] === 'QA Termine · Workshop', 'Gruppe: nur eingeschaltete Felder, 2 Einträge, Beschriftung');
            $all = Backlinks::groups($a, (int) $w, true);
            $ck(count($all) === 2 && $all[1]['label'] === 'Auch bei' && $all[1]['total'] === 1 && !$all[1]['enabled'], 'Kurzfassung: auch abgeschaltete Felder (relations)');

            $ck(Backlinks::apply($b, 'bezug', (int) $t1, (int) $w, false) === null && Entries::find($b, (int) $t1)['bezug'] === null, 'Lösen leert das Feld');
            $ck(Entries::find($b, (int) $t1)['titel'] === 'Termin 1', 'Lösen lässt andere Felder unverändert');
            $ck(Backlinks::apply($b, 'bezug', (int) $t1, (int) $w, true) === null && (int) Entries::find($b, (int) $t1)['bezug'] === (int) $w, 'Verknüpfen setzt das Feld');
            $ck(Backlinks::apply($b, 'mehr', (int) $t1, (int) $w, true) === null && in_array((int) $w, Entries::find($b, (int) $t1)['mehr'], true), 'Verknüpfen ergänzt Mehrfach-Verknüpfung');
            $ck(Backlinks::apply($b, 'mehr', (int) $t3, (int) $w, false) === null && Entries::find($b, (int) $t3)['mehr'] === [], 'Lösen entfernt aus Mehrfach-Verknüpfung');
            $ck(Backlinks::apply($b, 'titel', (int) $t1, (int) $w, true) !== null, 'Kein Verknüpfungsfeld → Fehler');
            $ck(Entries::find($b, (int) $t2)['status'] === 'draft', 'Status bleibt (Entwurf)');

            $sel = ListFilters::selection($b, ['f' => ['bezug' => (string) $w]]);
            $ck(($sel['bezug'] ?? null) === (string) $w && count(Entries::query($b, ['status' => 'all', 'where' => ListFilters::where($sel), 'source' => 'all'])) === 2, 'Filter ?f[feld]=ID ohne eingerichteten Listenfilter');
            $ck(($x = ListFilters::extra($b, $sel)) && $x[0]['value'] === 'Hochsitzbau', 'Hinweis „Gefiltert“ nennt den Titel');
            $ck(ListFilters::selection($b, ['f' => ['bezug' => 'abc']]) === [], 'Ungültiger Wert wird ignoriert');
        } finally {
            foreach (array_reverse($tables) as $x) if ($tt = Tables::find($x)) Tables::delete($tt);
            Tables::flush();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
