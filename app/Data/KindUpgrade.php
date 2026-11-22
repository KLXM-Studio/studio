<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\PageCache;

/**
 * Grundschema einer Tabellenart auf eine neuere Version heben (php bin/console data:kind-upgrade, Core\Data\Kinds) – nur additiv:
 * neue Grundfelder kommen dazu, nichts wird entfernt oder umbenannt. Standard ist der Probelauf (Bericht, nichts geändert).
 *
 * Kontakte v1 → v2 (Funktionen je Person, Core\Data\Roles): Feld „funktionen“ im Abschnitt „Funktionen & Listen“; je Eintrag aus
 * position und listen je Liste eine Funktion – position landet bei der ersten, ohne Listen eine Funktion ohne Liste. Einträge mit
 * schon gefüllten Funktionen (z. B. nach einem Rückweg) behalten sie. position und listen bleiben unverändert (sie sind danach
 * Hauptfunktion bzw. Listen der Funktionen).
 *
 * Ablauf mit --apply: Sicherung (VACUUM INTO), Revision je Eintrag, Schema (Tables::validate mit _kind_version), Werte direkt.
 * Zustand in storage/sites/{website}/convert/{tabelle}.kind-v{n}.json. Rückweg (--revert): Version zurück, neue Grundfelder als
 * ausgeblendete Altfelder (Spalte und Werte bleiben, ein erneutes Upgrade nimmt sie wieder auf), Feldliste wie vorher.
 * Geteilte Tabellen (Netzwerk): nur die Eigentümer-Website hebt – für alle Beteiligten auf einmal (ein Schema, Werte in share.sqlite);
 * der Probelauf zeigt die Einträge je Website, Sicherung = Kopie von share.sqlite, Werte aller Websites werden gefüllt (Funktionen
 * aus Funktion und Listen der jeweiligen Website). Lokale Tabellen gleicher Art treten nur bei gleicher oder älterer Version bei
 * (Core\Data\Network::mergeCheck – ältere werden beim Beitritt gefüllt).
 */
final class KindUpgrade
{
    /**
     * Probelauf: was das Upgrade auf $to (Standard: aktuelle Version) täte.
     * @return array{ok: bool, errors: list<string>, kind?: string, from?: int, to?: int, added?: list<string>, entries?: int, keep?: int, sample?: list<array>}
     */
    public static function plan(array $t, ?int $to = null): array
    {
        $kind = Kinds::of($t);
        $errors = [];
        if (!Kinds::isSpecial($kind)) $errors[] = __('Die Tabelle hat keine Art mit Grundschema (Adressbuch, Kalender, Orte).');
        if (Tables::isShared($t) && !Shared::isOwner($t)) $errors[] = __('Eine geteilte Tabelle hebt nur die Website, der sie gehört ({site}) – für alle Beteiligten.', ['site' => (string) $t['shared']['owner']]);
        $from = Kinds::tableVersion($t);
        $to ??= Kinds::version($kind);
        if (!$errors && $from >= $to) $errors[] = __('Die Tabelle hat schon Version {v} des Grundschemas.', ['v' => $from]);
        if (!$errors && $to > Kinds::version($kind)) $errors[] = __('Version {v} gibt es nicht (aktuell: {cur}).', ['v' => $to, 'cur' => Kinds::version($kind)]);
        if ($errors) return ['ok' => false, 'errors' => $errors];
        $have = array_column(Tables::allFields($t), null, 'name');
        $added = [];
        $conflict = [];
        foreach (Kinds::schema($kind, $to) as $def) {
            if ((int) $def['since'] <= $from) continue;
            $n = (string) $def['name'];
            if (isset($have[$n]) && ($have[$n]['type'] ?? '') !== $def['type']) $conflict[] = $n;
            $added[] = $n;
        }
        if ($conflict) return ['ok' => false, 'errors' => [__('Eigene Felder belegen Namen des neuen Grundschemas: {names} – bitte im Tabellen-Designer umbenennen.', ['names' => implode(', ', $conflict)])]];
        $rows = self::rows($t);
        $sample = [];
        $keep = 0;
        foreach ($rows as $e) {
            $have = Roles::items($e[Roles::FIELD] ?? null);
            if ($have) { $keep++; continue; }
            if (count($sample) < 5) $sample[] = ['title' => Entries::title($t, $e), 'roles' => self::rolesFor($t, $e)];
        }
        $sites = [];
        if (Tables::isShared($t)) foreach ($rows as $e) $sites[(string) ($e['origin_site'] ?? '')] = ($sites[(string) ($e['origin_site'] ?? '')] ?? 0) + 1;
        ksort($sites);
        return ['ok' => true, 'errors' => [], 'kind' => $kind, 'from' => $from, 'to' => $to, 'added' => $added, 'entries' => count($rows), 'keep' => $keep, 'sample' => $sample, 'sites' => $sites];
    }

    /** Alle Einträge (alle Sprachen, Entwürfe) roh aus der Datenbank – die Spalte funktionen auch, wenn sie (noch) kein Feld ist */
    private static function rows(array $t): array
    {
        // Geteilt: alle Einträge aller Websites roh aus share.sqlite (ohne Quelle, ohne öffentliche Fassung fremder Einträge)
        if (Tables::isShared($t)) {
            return array_map(fn($r) => ['id' => (int) $r['id'], 'listen' => json_decode((string) ($r['listen'] ?? ''), true) ?: [], Roles::FIELD => $r[Roles::FIELD] ?? null] + $r,
                Tables::db($t)->fetchAll("SELECT * FROM {$t['table']} ORDER BY id"));
        }
        $cols = Tables::db($t)->driver === 'mysql' ? array_column(Tables::db($t)->fetchAll("SHOW COLUMNS FROM {$t['table']}"), 'Field')
            : array_column(Tables::db($t)->fetchAll("PRAGMA table_info({$t['table']})"), 'name');
        $rows = Entries::query($t, ['status' => 'all', 'lang' => 'all', 'source' => 'all', 'internal' => true]);
        if (in_array(Roles::FIELD, $cols, true) && !Tables::field($t, Roles::FIELD)) {
            $raw = array_column(Tables::db($t)->fetchAll('SELECT id, ' . Roles::FIELD . " AS v FROM {$t['table']}"), 'v', 'id');
            foreach ($rows as &$r) $r[Roles::FIELD] = $raw[$r['id']] ?? null;
            unset($r);
        }
        return $rows;
    }

    /**
     * Funktionen aus position und listen (Kontakte v1): je Liste eine Funktion, position bei der ersten; ohne Listen eine Funktion
     * ohne Liste (nur mit position). Listen in der Reihenfolge der Optionen.
     * @return list<array<string, string>>
     */
    public static function rolesFor(array $t, array $e): array
    {
        $pos = trim((string) ($e['position'] ?? ''));
        $lists = array_values(array_map('strval', (array) ($e['listen'] ?? [])));
        $lf = Tables::field($t, 'listen');
        if ($lf) {
            $order = array_map('strval', array_keys((array) ($lf['options'] ?? [])));
            $lists = array_values(array_filter($order, fn($k) => in_array($k, $lists, true)));
        }
        if (!$lists) return $pos !== '' ? [['funktion' => $pos]] : [];
        $out = [];
        foreach ($lists as $i => $k) $out[] = array_filter(['funktion' => $i === 0 ? $pos : '', 'liste' => $k], fn($v) => $v !== '');
        return $out;
    }

    /** Bericht des Probelaufs */
    public static function report(array $plan, array $t): array
    {
        if (!$plan['ok']) return array_map(fn($e) => '! ' . $e, $plan['errors']);
        $l = ["Tabelle „{$t['name']}“ ({$t['handle']}): " . Kinds::label($plan['kind']) . " · Grundschema {$plan['from']} → {$plan['to']} · {$plan['entries']} Einträge", ''];
        $l[] = 'Neue Grundfelder: ' . implode(', ', $plan['added']);
        if (!empty($plan['sites'])) $l[] = 'Geteilte Tabelle – betroffene Websites: ' . implode(', ', array_map(fn($s, $n) => "$s ($n)", array_keys($plan['sites']), $plan['sites']));
        if ($plan['kind'] === Kinds::CONTACTS && in_array(Roles::FIELD, $plan['added'], true)) {
            $l[] = 'Funktionen: je Liste eine Funktion, „Funktion“ (position) bei der ersten; ohne Listen eine Funktion ohne Liste.';
            if ($plan['keep']) $l[] = "  {$plan['keep']} Einträge haben schon Funktionen – bleiben unverändert.";
            $lf = Tables::field($t, 'listen');
            foreach ($plan['sample'] as $s) {
                $txt = array_map(fn($r) => trim(($r['funktion'] ?? '') . (isset($r['liste']) ? ' [' . ($lf ? Tables::optionLabel($lf, $r['liste']) : $r['liste']) . ']' : '')), $s['roles']);
                $l[] = '  ' . $s['title'] . ': ' . ($txt ? implode(' · ', $txt) : '(keine)');
            }
        }
        return $l;
    }

    private static function stateFile(array $t, int $to): string
    {
        return site()->storage('convert') . '/' . $t['handle'] . '.kind-v' . $to . '.json';
    }

    /** Upgrade ausführen (nach plan()). @return list<string> Protokoll */
    public static function apply(array $t, array $plan): array
    {
        if (!$plan['ok']) throw new \RuntimeException(implode(' ', $plan['errors']));
        $log = [];
        $in = Tables::toInput($t);
        $before = $in['fields'];
        $in['fields'] = self::place($in['fields'], $plan);
        $in['_kind_version'] = $plan['to'];
        [$def, $errors] = Tables::validate($in, $t);
        if ($errors) throw new \RuntimeException('Schema: ' . implode(' ', array_map('strval', $errors)));
        $sdb = Tables::db($t);
        if ($sdb->driver !== 'mysql') {
            $dir = site()->storage('convert');
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $bak = $dir . '/' . $t['handle'] . '-kind-v' . $plan['to'] . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.sqlite';
            $sdb->pdo->exec('VACUUM INTO ' . $sdb->pdo->quote($bak));
            $log[] = '✓ Sicherung: ' . $bak;
        }
        $rows = self::rows($t);
        if (!Tables::isShared($t)) {
            foreach ($rows as $e) Revisions::record($t, $e, __('Vor dem Upgrade auf Grundschema {v}', ['v' => $plan['to']]));
            $log[] = '✓ Revision je Eintrag (' . count($rows) . ')';
        }
        Tables::update($t, $def);
        Tables::flush();
        $nt = (Tables::isShared($t) ? Tables::sharedTable((string) $t['shared']['key']) : Tables::find((string) $t['handle'])) ?? throw new \RuntimeException('Tabelle fehlt nach dem Upgrade.');
        $log[] = '✓ Schema: Version ' . $plan['to'] . ', neue Grundfelder ' . implode(', ', $plan['added']);
        $n = $plan['kind'] === Kinds::CONTACTS ? self::fill($nt, $rows) : 0;
        if ($plan['kind'] === Kinds::CONTACTS) $log[] = "✓ Funktionen aus Funktion und Listen ({$n} Einträge)";
        file_put_contents(self::stateFile($t, (int) $plan['to']), json_encode(['plan' => $plan, 'fields_before' => $before, 'from' => $plan['from'], 'at' => now()],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        PageCache::clear();
        if (Tables::isShared($t)) Shared::clearCaches((string) $t['shared']['key']);
        $log[] = '✓ Fertig. Suchindex neu aufbauen: php bin/console search:index --site=' . site()->key;
        return $log;
    }

    /**
     * Funktionen füllen, wo sie fehlen (Upgrade, data:convert --kind=contacts): direkt in der Spalte, position/listen bleiben.
     * @param list<array>|null $rows Einträge (Standard: alle)
     */
    public static function fill(array $t, ?array $rows = null): int
    {
        if (!Roles::has($t)) return 0;
        $col = (string) Roles::field($t)['name'];
        $db = Tables::db($t);
        $n = 0;
        foreach ($rows ?? self::rows($t) as $e) {
            if (Roles::items($e[$col] ?? null)) continue;
            $roles = self::rolesFor($t, $e);
            if (!$roles) continue;
            $db->update($t['table'], [$col => json_encode($roles, JSON_UNESCAPED_UNICODE)], 'id = :id', ['id' => (int) $e['id']]);
            $n++;
        }
        return $n;
    }

    /** Neue Grundfelder in die Feldliste: Kontakte v2 „Funktionen“ als Abschnitt vor den Listen (bzw. nach „Funktion“) */
    private static function place(array $fields, array $plan): array
    {
        $names = array_column($fields, 'name');
        $have = array_flip($names);
        foreach (Kinds::schema($plan['kind'], $plan['to']) as $def) {
            $n = (string) $def['name'];
            if ((int) $def['since'] <= $plan['from']) continue;
            unset($def['since']);
            if (isset($have[$n])) {                                     // Altfeld eines Rückwegs: wieder sichtbar, an seinen Platz
                $i = array_search($n, array_column($fields, 'name'), true);
                $def = array_diff_key($fields[(int) $i], ['legacy' => 1, 'system' => 1]);
                array_splice($fields, (int) $i, 1);
            }
            $at = count($fields);
            if ($n === Roles::FIELD) {
                // wie die Vorlage (Kinds::presetFields v2): Abschnitt „Funktionen & Listen“ vor „Kontakt“, Listen mit hinein
                $ins = isset($have['abschnitt_funktionen']) ? [$def] : [['label' => 'Funktionen & Listen', 'name' => 'abschnitt_funktionen', 'type' => 'section'], $def];
                $li = array_search('listen', array_column($fields, 'name'), true);
                if ($li !== false) { $ins[] = $fields[$li]; array_splice($fields, (int) $li, 1); }
                $names = array_column($fields, 'name');
                $ki = array_search('abschnitt_kontakt', $names, true);
                $pi = array_search('position', $names, true);
                $at = $ki !== false ? (int) $ki : ($li !== false ? min((int) $li, count($fields)) : ($pi !== false ? (int) $pi + 1 : count($fields)));
                array_splice($fields, $at, 0, $ins);
                continue;
            }
            array_splice($fields, $at, 0, [$def]);
        }
        return $fields;
    }

    /** Rückweg: Version zurück, neue Grundfelder ausgeblendet (Werte bleiben), Feldliste wie vorher. @return list<string> */
    public static function revert(array $t): array
    {
        $to = Kinds::tableVersion($t);
        $file = self::stateFile($t, $to);
        if (!is_file($file)) throw new \RuntimeException(__('Kein Upgrade zum Zurückdrehen gefunden.'));
        $st = json_decode((string) file_get_contents($file), true) ?: [];
        $from = (int) ($st['from'] ?? 1);
        $in = Tables::toInput($t);
        $cur = array_column($in['fields'], null, 'name');
        $added = (array) ($st['plan']['added'] ?? []);
        $out = [];
        foreach ((array) ($st['fields_before'] ?? []) as $f) {
            $n = (string) ($f['name'] ?? '');
            if ($n !== '' && !isset($cur[$n]) && !in_array($f['type'] ?? '', Tables::LAYOUT, true)) continue;   // seither gelöscht
            $out[] = $n !== '' && isset($cur[$n]) ? array_merge($cur[$n], array_intersect_key($f, array_flip(['label', 'labels', 'help', 'in_list', 'required']))) : $f;
        }
        $seen = array_flip(array_column($out, 'name'));
        foreach ($in['fields'] as $f) {
            $n = (string) ($f['name'] ?? '');
            if (isset($seen[$n]) || ($n === 'abschnitt_funktionen')) continue;
            if (in_array($n, $added, true)) { $f['legacy'] = 1; $f['in_list'] = 0; unset($f['system']); }
            $out[] = $f;                                                   // seither ergänzte eigene Felder bleiben
        }
        $in['fields'] = $out;
        $in['_kind_version'] = $from;
        [$def, $errors] = Tables::validate($in, $t);
        if ($errors) throw new \RuntimeException('Schema: ' . implode(' ', array_map('strval', $errors)));
        Tables::update($t, $def);
        @rename($file, $file . '.reverted-' . date('Ymd-His'));
        PageCache::clear();
        Tables::flush();
        return ['✓ Schema zurück auf Version ' . $from . ': ' . implode(', ', $added) . ' ausgeblendet (Werte bleiben, erneutes Upgrade übernimmt sie)'];
    }
}
