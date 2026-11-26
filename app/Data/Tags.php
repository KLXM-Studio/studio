<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * Schlagworte: Mehrfachauswahl mit freier Eingabe (Feld-Einstellung free = true). Keine vorher zu pflegende Liste – die Möglichkeiten
 * sind die vorhandenen Werte des Vokabulars. Gespeichert wie eine Mehrfachauswahl (JSON-Liste), Schlüssel = Schlagwort selbst; deshalb
 * funktionieren Filter-Schaltflächen, „Einträge im Menü“, Listen-Filter, Suche, Export, ICS CATEGORIES und API ohne Sonderweg.
 *  - Vokabular: je Feld eigenes ({tabelle}.{feld}) oder geteilt (Feld-Einstellung vocab = Schlüssel eines anderen Feldes) – Vorschläge
 *    und Verwaltung gelten dann für alle beteiligten Felder der Website.
 *  - canon(): Leerzeichen bereinigen, vorhandene Schreibweise übernehmen („rehwild“ → „Rehwild“), Dubletten entfernen.
 *  - similar(): ähnliche Schlagworte (Groß/klein, Umlaute/ae, Tippabstand 1–2) – „Meinten Sie …?“ und „mögliche Dublette“.
 *  - rename()/merge()/remove(): in allen Einträgen aller beteiligten Felder auf einmal, mit Versionsnotiz (Entries::save).
 * Tables::hydrate füllt options aus values() – ohne Rekursion: Felder werden aus data_tables gelesen, nicht über Tables::find.
 * Verwaltung: /admin/data/{tabelle}/schlagworte; Eingabe: resources/js/_tags.js.
 */
final class Tags
{
    public const MAX = 60;

    /** @var array<string, array<string, int>> Vokabular => [Schlagwort => Anzahl] (je Anfrage) */
    private static array $memo = [];
    /** @var ?array<string, list<array{handle: string, table: string, field: string, shared: bool}>> */
    private static ?array $members = null;

    public static function flush(): void
    {
        self::$memo = [];
        self::$members = null;
    }

    public static function isFree(array $f): bool
    {
        return ($f['type'] ?? '') === 'multiselect' && !empty($f['free']);
    }

    /** Schlüssel des Vokabulars eines Feldes */
    public static function vocab(string $handle, array $f): string
    {
        $v = (string) ($f['vocab'] ?? '');
        return $v !== '' ? $v : $handle . '.' . $f['name'];
    }

    public static function norm(string $tag): string
    {
        $tag = trim((string) preg_replace('~\s+~u', ' ', strip_tags(str_replace([',', ';', "\n", "\r"], ' ', $tag))));
        return mb_substr($tag, 0, self::MAX);
    }

    /** Vergleichsform: klein, Umlaute als ae/oe/ue, ß als ss, ohne Satzzeichen */
    public static function fold(string $tag): string
    {
        $s = mb_strtolower(self::norm($tag));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a']);
        return (string) preg_replace('~[^a-z0-9]+~', '', $s);
    }

    /** Beteiligte Felder je Vokabular (aus data_tables, ohne Tables::find) */
    private static function members(): array
    {
        if (self::$members !== null) return self::$members;
        self::$members = [];
        try {
            foreach (app()->db->fetchAll('SELECT handle, fields_json, settings_json FROM data_tables') as $r) {
                $set = json_decode((string) $r['settings_json'], true) ?: [];
                if (($set['kind'] ?? 'content') === 'inbox') continue;
                foreach (json_decode((string) $r['fields_json'], true) ?: [] as $f) {
                    if (!is_array($f) || !self::isFree($f)) continue;
                    self::$members[self::vocab((string) $r['handle'], $f)][] = ['handle' => (string) $r['handle'], 'table' => 'data_' . $r['handle'], 'field' => (string) $f['name'], 'shared' => false];
                }
            }
        } catch (\Throwable) {
        }
        return self::$members;
    }

    /** Felder eines Vokabulars @return list<array{handle: string, table: string, field: string}> */
    public static function fieldsOf(string $vocab): array
    {
        return self::members()[$vocab] ?? [];
    }

    /** Alle Vokabulare (für „Vokabular teilen mit …“) @return array<string, list<array>> */
    public static function vocabularies(): array
    {
        return self::members();
    }

    /** Schlagworte mit Anzahl (alphabetisch) @return array<string, int> */
    public static function values(string $vocab): array
    {
        if (isset(self::$memo[$vocab])) return self::$memo[$vocab];
        $n = [];
        foreach (self::fieldsOf($vocab) as $m) {
            try {
                foreach (app()->db->fetchAll("SELECT {$m['field']} AS v FROM {$m['table']} WHERE {$m['field']} IS NOT NULL AND {$m['field']} != '' AND {$m['field']} != '[]'") as $r) {
                    foreach (array_unique(array_map('strval', (array) (json_decode((string) $r['v'], true) ?: []))) as $tag) {
                        if ($tag !== '') $n[$tag] = ($n[$tag] ?? 0) + 1;
                    }
                }
            } catch (\Throwable) {
            }
        }
        uksort($n, fn($a, $b) => strnatcasecmp($a, $b));
        return self::$memo[$vocab] = $n;
    }

    /** Feld mit Optionen = vorhandene Schlagworte füllen (Tables::hydrate) */
    public static function fill(string $handle, array $f): array
    {
        if (!self::isFree($f)) return $f;
        $keys = array_keys(self::values(self::vocab($handle, $f)));
        $f['options'] = $keys ? array_combine($keys, $keys) : [];
        return $f;
    }

    /** Eingabe bereinigen: normalisieren, vorhandene Schreibweise, ohne Dubletten @return list<string> */
    public static function canon(string $vocab, array $raw): array
    {
        $have = [];
        foreach (array_keys(self::values($vocab)) as $t) $have[mb_strtolower($t)] ??= $t;
        $out = [];
        foreach ($raw as $r) {
            foreach (preg_split('~\s*[,;\n]\s*~u', (string) $r) ?: [] as $part) {
                $t = self::norm($part);
                if ($t === '') continue;
                $t = $have[mb_strtolower($t)] ?? $t;
                $out[mb_strtolower($t)] ??= $t;
            }
        }
        return array_values($out);
    }

    /** Ähnliche vorhandene Schlagworte (ohne exakt gleiche) @return list<string> */
    public static function similar(string $vocab, string $tag, int $limit = 3): array
    {
        $f = self::fold($tag);
        if ($f === '') return [];
        $max = mb_strlen($f) <= 4 ? 1 : 2;
        $hits = [];
        foreach (array_keys(self::values($vocab)) as $t) {
            if ($t === $tag) continue;
            $g = self::fold($t);
            $d = $g === $f ? 0 : levenshtein($f, $g);
            // auch Wortanfang („Reh“ ↔ „Rehwild“ nicht – zu viele Treffer); nur Schreibvarianten
            if ($d <= $max) $hits[$t] = $d;
        }
        asort($hits);
        return array_slice(array_keys($hits), 0, $limit);
    }

    /** Mögliche Dubletten: selten benutzte Schlagworte (1×) mit ähnlichem, häufigerem Partner @return array<string, string> Dublette => Partner */
    public static function duplicates(string $vocab): array
    {
        $vals = self::values($vocab);
        $out = [];
        foreach ($vals as $t => $n) {
            if ($n > 1) continue;
            foreach (self::similar($vocab, $t, 5) as $p) {
                if (($vals[$p] ?? 0) >= $n && $p !== $t && !isset($out[$p])) { $out[$t] = $p; break; }
            }
        }
        return $out;
    }

    /**
     * Schlagworte ersetzen: $from (Liste) → $to ('' = entfernen) in allen Feldern des Vokabulars, mit Versionsnotiz.
     * @return array{changed: int, errors: list<string>}
     */
    public static function replace(string $vocab, array $from, string $to, string $note): array
    {
        $to = self::norm($to);
        $fromL = array_map('mb_strtolower', array_map([self::class, 'norm'], $from));
        $changed = 0;
        $errors = [];
        foreach (self::fieldsOf($vocab) as $m) {
            $t = Tables::findContent($m['handle']);
            if (!$t) continue;
            $p = [];
            $cond = [];
            foreach ($from as $x) { $cond[] = "{$m['field']} LIKE ?"; $p[] = '%' . trim((string) json_encode(self::norm((string) $x), JSON_UNESCAPED_UNICODE), '"') . '%'; }
            if (!$cond) continue;
            $own = Tables::isShared($t) ? ' AND origin_site = ?' : '';
            if ($own) $p[] = site()->key;
            foreach (Tables::db($t)->fetchAll("SELECT id, {$m['field']} AS v FROM {$t['table']} WHERE (" . implode(' OR ', $cond) . ')' . $own, $p) as $row) {
                $vals = array_map('strval', (array) (json_decode((string) $row['v'], true) ?: []));
                $hit = false;
                $new = [];
                foreach ($vals as $v) {
                    if (in_array(mb_strtolower($v), $fromL, true)) { $hit = true; if ($to !== '') $new[mb_strtolower($to)] = $to; continue; }
                    $new[mb_strtolower($v)] ??= $v;
                }
                if (!$hit) continue;
                [, $errs] = Entries::save($t, (int) $row['id'], [$m['field'] => array_values($new)], $note);
                if ($errs) $errors[] = '#' . $row['id'] . ': ' . implode(' ', array_map('strval', $errs));
                else $changed++;
            }
        }
        self::flush();
        Tables::flush();
        return ['changed' => $changed, 'errors' => $errors];
    }

    public static function rename(string $vocab, string $from, string $to): array
    {
        return self::replace($vocab, [$from], $to, __('Schlagwort umbenannt: „{from}“ → „{to}“', ['from' => $from, 'to' => self::norm($to)]));
    }

    public static function merge(string $vocab, array $from, string $into): array
    {
        return self::replace($vocab, array_values(array_filter($from, fn($x) => $x !== $into)), $into,
            __('Schlagworte zusammengeführt: {from} → „{to}“', ['from' => '„' . implode('“, „', $from) . '“', 'to' => $into]));
    }

    public static function remove(string $vocab, string $tag): array
    {
        return self::replace($vocab, [$tag], '', __('Schlagwort entfernt: „{tag}“', ['tag' => $tag]));
    }

    /**
     * Bestehende Mehrfachauswahl auf freie Schlagworte umstellen: gespeicherte Schlüssel → Beschriftungen (Werte = bisherige Labels).
     * $t = Tabelle vor der Umstellung (mit options). @return int geänderte Einträge
     */
    public static function keysToLabels(array $t, array $oldField): int
    {
        $opts = (array) ($oldField['options'] ?? []);
        if (!$opts) return 0;
        $db = Tables::db($t);
        $col = $oldField['name'];
        $n = 0;
        foreach ($db->fetchAll("SELECT id, {$col} AS v FROM {$t['table']} WHERE {$col} IS NOT NULL AND {$col} != '' AND {$col} != '[]'") as $row) {
            $vals = array_map('strval', (array) (json_decode((string) $row['v'], true) ?: []));
            $new = [];
            foreach ($vals as $v) { $l = self::norm((string) ($opts[$v] ?? $v)); if ($l !== '') $new[mb_strtolower($l)] ??= $l; }
            $json = json_encode(array_values($new), JSON_UNESCAPED_UNICODE);
            if ($json !== $row['v']) { $db->query("UPDATE {$t['table']} SET {$col} = ? WHERE id = ?", [$json, (int) $row['id']]); $n++; }
        }
        self::flush();
        return $n;
    }
}
