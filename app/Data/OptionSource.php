<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * Auswahl-Optionen aus einer Tabelle (Feld-Einstellung options_from = Kurzname einer Datentabelle, select und multiselect):
 * Die Redaktion pflegt Kategorien, Themen oder Listen als normale kleine Tabelle – anlegen, umbenennen, sortieren, mit Bild und Text.
 *  - Gespeichert bleibt am Eintrag der Kurzname (slug) des Options-Eintrags; Tables::hydrate füllt $f['options'] (slug => Titel, Reihenfolge
 *    der Tabelle) und $f['options_i18n'] (Titel der Übersetzungen). Kalender-Kategorien, ICS, Filter, Menü, Export, optionLabel bleiben gleich.
 *  - Umbenennen = Titel ändern (Kurzname bleibt). Ändert sich der Kurzname doch, schreibt renamed() die Werte um.
 *  - Löschen eines Options-Eintrags: Rückfrage wie bei Verknüpfungen (Core\Data\Reassign), sonst leert Entries::delete die Werte.
 *  - extract(): bestehendes Feld auslagern – legt die Tabelle aus den Optionen an, Werte bleiben gültig (CLI data:options-to-table,
 *    Knopf im Tabellen-Designer).
 * Nur Inhaltstabellen dieser Website (keine Eingänge, keine geteilten Tabellen).
 */
final class OptionSource
{
    /** @var array<string, array{0: array<string, string>, 1: array<string, array<string, string>>}> */
    private static array $memo = [];
    /** @var array<string, bool> */
    private static array $busy = [];

    /** @var ?array<string, list<string>> Quelle => Tabellen, die daraus beziehen (je Anfrage) */
    private static ?array $parents = null;

    public static function flush(): void
    {
        self::$memo = [];
        self::$parents = null;
    }

    /**
     * Haupttabellen einer Auswahlliste: Tabellen, deren Felder ihre Optionen aus $handle beziehen. Leer, wenn die Liste gesperrt ist
     * (settings.list_locked – nur über „Felder & Einstellungen“ pflegbar). @return list<string>
     */
    public static function parentsOf(string $handle): array
    {
        if (self::$parents === null) {
            self::$parents = [];
            try {
                foreach (Tables::content() as $t) {
                    foreach ($t['fields'] as $f) {
                        $src = (string) ($f['options_from'] ?? '');
                        if ($src !== '' && in_array($f['type'], ['select', 'multiselect'], true)) self::$parents[$src][$t['handle']] = $t['handle'];
                    }
                }
                foreach (array_keys(self::$parents) as $src) {
                    $st = Tables::find($src);
                    if (!$st || !empty($st['settings']['list_locked'])) unset(self::$parents[$src]);
                    else self::$parents[$src] = array_values(self::$parents[$src]);
                }
            } catch (\Throwable) {
                self::$parents = [];
            }
        }
        return self::$parents[$handle] ?? [];
    }

    /** Ist die Tabelle eine Auswahlliste (Quelle für mindestens ein Feld)? – auch gesperrte */
    public static function isList(array $t): bool
    {
        return self::usedBy($t) !== [];
    }

    /** Taugt die Tabelle als Quelle? */
    public static function valid(string $handle, string $self = ''): bool
    {
        if ($handle === '' || $handle === $self) return false;
        $t = Tables::findContent($handle);
        return $t !== null && !Tables::isShared($t);
    }

    /** Feld mit Optionen aus der Quelle füllen (Tables::hydrate) */
    public static function fill(array $f): array
    {
        $h = (string) ($f['options_from'] ?? '');
        if ($h === '' || !in_array($f['type'] ?? '', ['select', 'multiselect'], true)) return $f;
        [$opts, $i18n] = self::load($h);
        $f['options'] = $opts;
        $f['options_i18n'] = $i18n;
        return $f;
    }

    /** @return array{0: array<string, string>, 1: array<string, array<string, string>>} */
    public static function load(string $handle): array
    {
        if (isset(self::$memo[$handle])) return self::$memo[$handle];
        if (!empty(self::$busy[$handle])) return [[], []];   // Kreis (A aus B, B aus A)
        self::$busy[$handle] = true;
        try {
            $t = Tables::findContent($handle);
            if (!$t || Tables::isShared($t)) return self::$memo[$handle] = [[], []];
            $tf = (string) ($t['settings']['title_field'] ?? '');
            if ($tf === '' || !Tables::field($t, $tf)) return self::$memo[$handle] = [[], []];
            $sort = (string) ($t['settings']['sort_field'] ?? 'sort');
            $names = array_merge(Tables::SYSTEM, array_column($t['fields'], 'name'));
            if (!in_array($sort, $names, true)) $sort = 'sort';
            $dir = strtolower((string) ($t['settings']['sort_dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            $def = \Core\Lang::default();
            $opts = $i18n = [];
            foreach (Tables::db($t)->fetchAll("SELECT slug, {$tf} AS t, lang FROM {$t['table']} WHERE slug IS NOT NULL AND slug != '' ORDER BY {$sort} {$dir}, id") as $r) {
                $label = trim(html_entity_decode(strip_tags((string) $r['t']), ENT_QUOTES | ENT_HTML5));
                $lang = \Core\Lang::norm($r['lang'] ?? null);
                if ($lang === $def) $opts[(string) $r['slug']] ??= $label !== '' ? $label : (string) $r['slug'];
                elseif ($label !== '') $i18n[$lang][(string) $r['slug']] = $label;
            }
            return self::$memo[$handle] = [$opts, $i18n];
        } finally {
            unset(self::$busy[$handle]);
        }
    }

    /** Felder (select/multiselect) anderer Tabellen, die ihre Optionen aus $source beziehen @return list<array{0: array, 1: array}> */
    public static function usedBy(array $source): array
    {
        $out = [];
        foreach (Tables::content() as $t) {
            foreach ($t['fields'] as $f) {
                if (in_array($f['type'], ['select', 'multiselect'], true) && ($f['options_from'] ?? '') === $source['handle']) $out[] = [$t, $f];
            }
        }
        return $out;
    }

    /**
     * Themenseite: Einträge, die einen Listen-Eintrag ($e der Liste $source, Wert = Kurzname) verwenden – je Tabelle mit Detailseiten,
     * veröffentlicht und öffentlich; Termine nur kommende (früher Beginn zuerst, $past = auch vergangene danach), sonst neueste.
     * @return list<array{table: array, items: list<array{entry: array, href: string}>}>
     */
    public static function topic(array $source, array $e, int $perTable = 6, bool $past = false): array
    {
        $slug = (string) ($e['slug'] ?? '');
        if ($slug === '') return [];
        $byTable = [];
        foreach (self::usedBy($source) as [$t, $f]) {
            if (empty($t['settings']['detail_page_id']) || trim((string) ($t['settings']['route'] ?? ''), '/') === '') continue;
            $p = [];
            $w = self::cond($f, $slug, $p);
            $ids = array_map('intval', array_column(Tables::db($t)->fetchAll("SELECT id FROM {$t['table']} WHERE status = 'published' AND $w", $p), 'id'));
            if ($ids) $byTable[$t['handle']] = ['table' => $t, 'ids' => array_merge($byTable[$t['handle']]['ids'] ?? [], $ids)];
        }
        $out = [];
        $now = time();
        foreach ($byTable as ['table' => $t, 'ids' => $ids]) {
            $cal = Calendar::enabled($t);
            $items = [];
            foreach (Exposure::publicRows($t, Entries::query($t, ['status' => 'published', 'ids' => array_values(array_unique($ids)), 'limit' => 300] + (\Core\Lang::multi() ? ['lang' => \Core\Lang::current()] : []))) as $r) {
                $href = Entries::href($t, $r);
                if (!$href) continue;
                if ($cal) {
                    $span = Calendar::span($t, $r);
                    $end = $span ? ($span['has_end'] ? $span['end'] : $span['start']->setTime(23, 59))->getTimestamp() : 0;
                    $up = $end >= $now;
                    if (!$up && !$past) continue;
                    $start = $span ? $span['start']->getTimestamp() : 0;
                    $key = [$up ? 1 : 0, $up ? -$start : $start];   // kommende zuerst (früher Beginn vorn), danach vergangene (jüngste vorn)
                } else {
                    $key = [0, strtotime((string) ($r['published_at'] ?? '') ?: (string) ($r['created_at'] ?? '')) ?: 0];
                }
                $items[] = ['entry' => $r, 'href' => $href, '_ts' => $key];
            }
            usort($items, fn($a, $b) => $b['_ts'] <=> $a['_ts']);
            $items = array_map(fn($x) => ['entry' => $x['entry'], 'href' => $x['href']], array_slice($items, 0, $perTable));
            if ($items) $out[] = ['table' => $t, 'items' => $items];
        }
        return $out;
    }

    /** Ist die Tabelle Quelle für Optionen? */
    public static function isSource(array $t): bool
    {
        return self::usedBy($t) !== [];
    }

    /** Wie oft wird jeder Kurzname benutzt (alle nutzenden Felder zusammen)? @return array<string, int> */
    public static function useCounts(array $source): array
    {
        $n = [];
        foreach (self::usedBy($source) as [$t, $f]) {
            $p = [];
            $w = self::own($t, $p);
            $col = $f['name'];
            foreach (Tables::db($t)->fetchAll("SELECT {$col} AS v FROM {$t['table']} WHERE {$col} IS NOT NULL AND {$col} != ''" . $w, $p) as $r) {
                $vals = $f['type'] === 'select' ? [(string) $r['v']] : array_map('strval', (array) (json_decode((string) $r['v'], true) ?: []));
                foreach (array_unique($vals) as $v) if ($v !== '') $n[$v] = ($n[$v] ?? 0) + 1;
            }
        }
        return $n;
    }

    /** SQL-Bedingung „Wert enthält slug“ für ein Feld */
    public static function cond(array $f, string $slug, array &$params): string
    {
        if ($f['type'] === 'select') { $params[] = $slug; return "{$f['name']} = ?"; }
        $params[] = '%' . json_encode($slug, JSON_UNESCAPED_UNICODE) . '%';
        return "{$f['name']} LIKE ?";
    }

    /** Eigene Einträge (geteilte Tabellen: nur diese Website) */
    private static function own(array $t, array &$params): string
    {
        if (!Tables::isShared($t)) return '';
        $params[] = site()->key;
        return ' AND origin_site = ?';
    }

    /**
     * Werte umschreiben: $from → $to ('' = entfernen) in allen Feldern, die aus $source beziehen. @return int geänderte Einträge
     */
    public static function rewrite(array $source, string $from, string $to): int
    {
        $n = 0;
        foreach (self::usedBy($source) as [$t, $f]) {
            $db = Tables::db($t);
            $p = [];
            $w = self::cond($f, $from, $p) . self::own($t, $p);
            if ($f['type'] === 'select') {
                $n += $db->query("UPDATE {$t['table']} SET {$f['name']} = ? WHERE $w", [$to === '' ? null : $to, ...$p])->rowCount();
            } else {
                foreach ($db->fetchAll("SELECT id, {$f['name']} AS v FROM {$t['table']} WHERE $w", $p) as $row) {
                    $vals = array_map('strval', (array) (json_decode((string) $row['v'], true) ?: []));
                    if (!in_array($from, $vals, true)) continue;
                    $vals = array_values(array_unique(array_filter(array_map(fn($v) => $v === $from ? $to : $v, $vals), fn($v) => $v !== '')));
                    $db->query("UPDATE {$t['table']} SET {$f['name']} = ? WHERE id = ?", [json_encode($vals, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
                    $n++;
                }
            }
            if ($n) Entries::changed($t);
        }
        return $n;
    }

    /** Kurzname eines Options-Eintrags geändert (Entries::save): Werte nachziehen */
    public static function renamed(array $source, string $old, string $new): void
    {
        if ($old === '' || $old === $new) return;
        self::rewrite($source, $old, $new);
        self::flush();
    }

    /**
     * Optionen eines Feldes in eine neue Tabelle auslagern: Tabelle anlegen (Titel, Beschreibung, manuelle Sortierung), je Option ein
     * Eintrag (Kurzname = Schlüssel, Übersetzungen der Beschriftung als Übersetzungs-Einträge), Feld auf options_from umstellen.
     * @return array{table: array, created: int, renamed: int}
     */
    public static function extract(array $t, string $field, string $handle = '', string $name = ''): array
    {
        $f = Tables::field($t, $field);
        if (!$f || !in_array($f['type'], ['select', 'multiselect'], true)) throw new \RuntimeException(__('Nur Auswahl- und Mehrfachauswahl-Felder lassen sich auslagern.'));
        if (!empty($f['options_from'])) throw new \RuntimeException(__('Die Optionen kommen schon aus einer Tabelle.'));
        if (Tables::isShared($t) || Tables::isInbox($t)) throw new \RuntimeException(__('In geteilten Tabellen und Eingängen nicht möglich.'));
        $name = trim($name) !== '' ? trim($name) : (string) $f['label'];
        $handle = $handle !== '' ? $handle : Tables::normName($name);
        if (Tables::find($handle)) $handle .= '_' . substr(bin2hex(random_bytes(2)), 0, 3);
        $def = \Core\Http\Controllers\Admin\DataController::presetDef('');
        $def['handle'] = $handle;
        $def['name'] = $name;
        $def['singular'] = $name;
        $def['icon'] = 'tag';
        $def['settings']['workflow'] = false;
        $def['fields'][] = ['label' => 'Beschreibung', 'name' => 'beschreibung', 'type' => 'textarea'];
        [$clean, $err] = Tables::validate($def);
        if ($err) throw new \RuntimeException(implode(' ', array_map('strval', $err)));
        Tables::create($clean);
        Tables::flush();
        $nt = Tables::find($handle) ?? throw new \RuntimeException('Tabelle wurde nicht angelegt.');
        $tf = (string) $nt['settings']['title_field'];
        $created = $renamed = 0;
        $map = [];
        foreach ((array) $f['options'] as $key => $label) {
            [$id, $errs] = Entries::save($nt, null, [$tf => (string) $label, 'slug' => (string) $key, 'status' => 'published']);
            if (!$id) throw new \RuntimeException(implode(' ', array_map('strval', $errs)));
            $created++;
            $slug = (string) (Entries::find($nt, (int) $id)['slug'] ?? $key);
            if ($slug !== (string) $key) $map[(string) $key] = $slug;
            foreach ((array) ($f['options_i18n'] ?? []) as $lang => $labels) {
                if (isset($labels[$key]) && \Core\Lang::valid((string) $lang)) {
                    try { $tr = Entries::translate($nt, (int) $id, (string) $lang); Entries::save($nt, (int) $tr['id'], [$tf => (string) $labels[$key], 'status' => 'published']); } catch (\Throwable) {}
                }
            }
        }
        // Feld umstellen; Werte, deren Schlüssel kein gültiger Kurzname war, vorher umschreiben
        $in = Tables::toInput($t);
        foreach ($in['fields'] as &$x) {
            if (($x['id'] ?? '') === ($f['id'] ?? '-')) { $x['options_from'] = $handle; $x['options'] = ''; }
        }
        unset($x);
        [$ndef, $errs] = Tables::validate($in, $t);
        if ($errs) throw new \RuntimeException(implode(' ', array_map('strval', $errs)));
        Tables::update($t, $ndef);
        Tables::flush();
        self::flush();
        foreach ($map as $from => $to) $renamed += self::rewrite($nt, $from, $to);
        return ['table' => $nt, 'created' => $created, 'renamed' => $renamed];
    }
}
