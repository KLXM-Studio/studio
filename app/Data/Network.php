<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\PageCache;

/**
 * Netzwerk für geteilte Tabellen (Core\Data\Shared): Verband und Vereine führen Adressbücher und Kalender zusammen. Alles hängt an
 * Schaltern der geteilten Tabelle (share.json „network“, nur Eigentümer-Website bzw. Integrator) – ohne Schalter verhält sich jede
 * geteilte Tabelle wie bisher, Websites ohne geteilte Tabellen bemerken nichts.
 *
 *  - levels (Weitergabe-Stufe je Eintrag, Spalte _share): site = nur die eigene Website, sites = zusätzlich ausgewählte Websites
 *    (Spalte _share_sites, erscheinen dort von selbst, je Website abwählbar), network = alle Websites des Netzwerks nach deren
 *    Einstellungen (wie bisher: Eigentümer → Mitglieder von selbst, Mitglieder → Eigentümer per Übernahme/Regel). Leer = network
 *    (Einträge von vor dem Schalter bleiben sichtbar). Standard neuer Einträge: Adressbücher site, alles andere network.
 *    Mit Stufen sehen andere Websites fremde Einträge nur in der öffentlichen Fassung (Core\Data\Exposure::publicEntry) – auch in
 *    Verwaltung, API, CardDAV/CalDAV und vCard; Kontakte nur mit „Auf der Website zeigen“. Die Stufe erweitert nie die Freigabe je Feld.
 *  - site_fields (Zusatzfelder je Website): jede Website legt eigene Felder an (share.json „site_fields.{website}“), Werte je Website
 *    und Eintrag in share_extra (auch für fremde Einträge, z. B. „Funktionen Bund“ an einem Kontakt eines Landesvereins) – nur auf
 *    dieser Website sichtbar. Ein Zusatzfeld „funktionen“ (Typ Funktionen) ersetzt auf dieser Website die Funktionen des Eintrags.
 *
 * Ohne Schalter, aber nur wirksam, wenn eingestellt: „außer“-Regeln je Website (localConfig except, z. B. Kategorie „Intern“ des
 * Verbands abwählen) und Quelle „bestimmte Websites“ im Block. Termine aus Kalender-Abos (Core\Sources, ICS) tragen ihre Herkunft
 * am Eintrag (Spalte _external: Quelle, Veranstalter, Original-Link) – so bleibt sie auch beim Weitergeben sichtbar und der Termin
 * schreibgeschützt.
 */
final class Network
{
    public const LEVELS = ['site', 'sites', 'network'];
    public const COL = '_share';
    public const COL_SITES = '_share_sites';
    /** Herkunft eines Termins aus einem Kalender-Abo (JSON {source, organizer, url}) */
    public const EXTERNAL = '_external';
    public const FLAGS = ['levels' => false, 'site_fields' => false];
    /** Feldtypen für Zusatzfelder je Website (keine Medien/Verknüpfungen: deren Werte gehören einer Website bzw. dem Pool) */
    public const SITE_TYPES = ['text', 'textarea', 'richtext', 'url', 'email', 'tel', 'date', 'bool', 'number', 'select', 'multiselect', Roles::TYPE];
    /** Verknüpfungen als Zusatzfeld: nur auf eigene, nicht geteilte Tabellen dieser Website (z. B. Regionalgruppen beim Teilen der Termine) */
    public const SITE_REL = ['relation', 'relations'];

    // ================================================================= Schalter

    /** Schalter einer geteilten Tabelle (Schlüssel) */
    public static function flags(string $key): array
    {
        $m = Shared::meta($key);
        $f = (array) ($m['network'] ?? []);
        return ['levels' => !empty($f['levels']), 'site_fields' => !empty($f['site_fields'])];
    }

    public static function levelsOn(array $t): bool
    {
        return isset($t['shared']) && self::flags((string) $t['shared']['key'])['levels'];
    }

    public static function siteFieldsOn(array $t): bool
    {
        return isset($t['shared']) && self::flags((string) $t['shared']['key'])['site_fields'];
    }

    /**
     * Schalter setzen (Eigentümer-Website oder Integrator). $existing (nur beim Einschalten der Stufen): Stufe für vorhandene Einträge
     * ohne Angabe – null = unverändert (gelten als network). @return list<string> Protokoll
     */
    public static function setFlags(string $key, array $in, ?string $existing = null): array
    {
        $meta = Shared::meta($key) ?? throw new \InvalidArgumentException(__('Unbekannte geteilte Tabelle.'));
        // Verwaltung: Eigentümer-Website mit „Geteilte Daten verwalten“ bzw. Integrator; Kommandozeile: nur als Eigentümer-Website (--site=…)
        if (!Shared::canAdmin($key) && !(PHP_SAPI === 'cli' && site()->key === $meta['owner'])) throw new \InvalidArgumentException(__('Die Netzwerk-Schalter stellt die Website ein, der die Tabelle gehört.'));
        $cur = self::flags($key);
        $new = $cur;
        foreach (array_keys(self::FLAGS) as $k) if (array_key_exists($k, $in)) $new[$k] = !empty($in[$k]);
        $meta['network'] = $new;
        self::saveMeta($key, $meta);
        $log = [];
        if ($new['levels'] && ($t = Tables::sharedTable($key))) {
            Tables::sync($t);
            if ($existing !== null && in_array($existing, self::LEVELS, true) && $existing !== 'sites') {
                $n = Shared::db($key)->query("UPDATE {$t['table']} SET " . self::COL . ' = ? WHERE ' . self::COL . ' IS NULL', [$existing])->rowCount();
                $log[] = __('Weitergabe vorhandener Einträge: {level} ({n})', ['level' => self::levelLabel($existing), 'n' => $n]);
            }
        }
        if ($new['site_fields']) self::extraTable($key);
        foreach (['levels' => __('Weitergabe je Eintrag'), 'site_fields' => __('Zusatzfelder je Website')] as $k => $label) {
            if ($cur[$k] !== $new[$k]) $log[] = $label . ': ' . ($new[$k] ? __('an') : __('aus'));
        }
        Shared::clearCaches($key);
        return $log;
    }

    private static function saveMeta(string $key, array $meta): void
    {
        Shared::writeMeta($key, $meta);
    }

    // ================================================================= Weitergabe-Stufe

    public static function levelLabel(string $level): string
    {
        return match ($level) {
            'site' => __('Nur diese Website'),
            'sites' => __('Ausgewählte Websites'),
            default => __('Ganzes Netzwerk'),
        };
    }

    /** Standard neuer Einträge: Adressbücher nur die eigene Website, alles andere das Netzwerk */
    public static function defaultLevel(array $t): string
    {
        return Kinds::isContacts($t) ? 'site' : 'network';
    }

    /** Stufe eines Eintrags (leer bzw. unbekannt = network – Einträge von vor dem Schalter) */
    public static function level(array $e): string
    {
        $v = (string) ($e[self::COL] ?? '');
        return in_array($v, self::LEVELS, true) ? $v : 'network';
    }

    /** Websites einer Stufe „ausgewählte Websites“ */
    public static function shareSites(array $e): array
    {
        $v = $e[self::COL_SITES] ?? null;
        if (is_string($v)) $v = json_decode($v, true);
        return is_array($v) ? array_values(array_filter(array_map('strval', $v), fn($s) => preg_match('~^[a-z0-9_-]{1,40}$~', $s) === 1)) : [];
    }

    /**
     * Stufe und Websites aus der Eingabe ($in['_share'], $in['_share_sites']) bereinigen – nur Beteiligte (ohne die eigene Website);
     * ohne Angabe bleibt der Stand bzw. – neu – der Standard. @return array{0: string, 1: list<string>}
     */
    public static function cleanLevel(array $t, array $in, ?array $current): array
    {
        $level = array_key_exists(self::COL, $in) ? (string) $in[self::COL] : ($current ? self::level($current) : self::defaultLevel($t));
        if (!in_array($level, self::LEVELS, true)) $level = $current ? self::level($current) : self::defaultLevel($t);
        $raw = array_key_exists(self::COL_SITES, $in) ? $in[self::COL_SITES] : ($current[self::COL_SITES] ?? []);
        if (is_string($raw)) $raw = json_decode($raw, true) ?: preg_split('~[,\s]+~', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $allowed = array_values(array_diff(Shared::participants(Shared::meta((string) $t['shared']['key']) ?? ['owner' => '', 'members' => []]), [site()->key]));
        $sites = array_values(array_unique(array_intersect(array_map('strval', (array) $raw), $allowed)));
        if ($level !== 'sites') $sites = [];
        if ($level === 'sites' && !$sites) $level = 'site';
        return [$level, $sites];
    }

    /** Spaltenwerte für Entries::save */
    public static function row(array $t, array $in, ?array $current): array
    {
        [$level, $sites] = self::cleanLevel($t, $in, $current);
        return [self::COL => $level, self::COL_SITES => $sites ? json_encode($sites) : null];
    }

    /**
     * Freigabe durch die Stufe (SQL, Parameter in Textreihenfolge): eigene Einträge immer, fremde nur mit Stufe network bzw. sites an
     * diese Website, Kontakte zusätzlich nur mit „Auf der Website zeigen“.
     */
    public static function gateSql(array $t, array &$params): string
    {
        $site = site()->key;
        $params[] = $site;
        $params[] = '%' . json_encode($site) . '%';
        $foreign = '(COALESCE(' . self::COL . ", 'network') = 'network' OR (" . self::COL . " = 'sites' AND " . self::COL_SITES . ' LIKE ?))';
        if (Exposure::perEntry($t)) $foreign = '(' . $foreign . ' AND ' . Kinds::PUBLIC_FIELD . ' = 1)';
        return '(origin_site = ? OR ' . $foreign . ')';
    }

    /** An diese Website gerichtete Einträge (Stufe „ausgewählte Websites“) – erscheinen von selbst, ausgeblendete nicht */
    public static function targetedSql(array &$params): string
    {
        $site = site()->key;
        array_push($params, $site, '%' . json_encode($site) . '%', $site);
        return '(origin_site != ? AND ' . self::COL . " = 'sites' AND " . self::COL_SITES . " LIKE ? AND status = 'published'"
            . " AND id NOT IN (SELECT pick_entry FROM share_picks WHERE pick_site = ? AND pick_state IN ('hidden', 'rejected')))";
    }

    /**
     * „Außer“-Regeln dieser Website (localConfig except): fremde Einträge, die eine der Bedingungen erfüllen, nicht zeigen
     * (z. B. Kategorie „Intern“ des Verbands). $cond wie in Shared::sourceSql. '' = keine Regel.
     */
    public static function exceptSql(array $t, array &$params, callable $cond): string
    {
        $out = [];
        foreach (Shared::cleanWhere($t, (array) (Shared::localConfig((string) $t['shared']['key'])['except'] ?? [])) as $w) {
            $tmp = [];
            $cs = $cond([$w], $tmp);
            if (!$cs) continue;
            $params[] = site()->key;
            array_push($params, ...$tmp);
            $out[] = 'NOT (origin_site != ? AND ' . implode(' AND ', $cs) . ')';
        }
        return implode(' AND ', $out);
    }

    /** Darf diese Website den Eintrag nach seiner Stufe sehen? (ohne Stufen: immer) */
    public static function allows(array $t, array $e, ?string $site = null): bool
    {
        if (!self::levelsOn($t)) return true;
        $site ??= site()->key;
        if ((string) ($e['origin_site'] ?? $site) === $site) return true;
        if (Exposure::perEntry($t) && empty($e[Kinds::PUBLIC_FIELD])) return false;
        return match (self::level($e)) {
            'site' => false,
            'sites' => in_array($site, self::shareSites($e), true),
            default => true,
        };
    }

    /**
     * Fremder Eintrag für diese Website: nur die öffentliche Fassung (Freigabe je Feld und Angabe) – Herkunft, Auswahl und Stufe
     * bleiben. Ohne Stufen-Schalter unverändert.
     */
    public static function foreignView(array $t, array $e, string $target = 'web'): array
    {
        $keep = array_intersect_key($e, array_flip(['id', 'slug', 'status', 'lang', 'origin_site', 'suggest', '_pick', self::COL, self::COL_SITES, self::EXTERNAL,
            'created_at', 'updated_at', 'published_at', 'sort', 'translation_group', 'online_from', 'online_until']));
        return array_replace(Exposure::publicEntry($t, $e, $target), $keep) + ['_foreign' => true];
    }

    // ================================================================= Zusatzfelder je Website

    /** Zusatzfelder einer Website (Standard: diese) – nur mit Schalter */
    public static function siteFields(string $key, ?string $site = null): array
    {
        if (!self::flags($key)['site_fields']) return [];
        $all = (array) (Shared::meta($key)['site_fields'] ?? []);
        return array_values(array_filter((array) ($all[$site ?? site()->key] ?? []), 'is_array'));
    }

    /**
     * Zusatzfelder dieser Website speichern: [[label, name?, type, help?, options?], …]. Namen dürfen Grundfelder nicht belegen –
     * außer „funktionen“ (Typ Funktionen) als Funktionen auf dieser Website. @return array{0: list<array>, 1: array<string, string>}
     */
    public static function saveSiteFields(array $t, array $rows): array
    {
        $key = (string) $t['shared']['key'];
        $errors = [];
        $out = [];
        $base = array_merge(Tables::SYSTEM, Shared::COLUMNS, [self::COL, self::COL_SITES, self::EXTERNAL], array_column($t['fields'], 'name'));
        $seen = [];
        $prev = array_column(self::siteFields($key), null, 'name');
        foreach (array_values($rows) as $i => $f) {
            if (!is_array($f)) continue;
            $label = trim(strip_tags((string) ($f['label'] ?? '')));
            if ($label === '') continue;
            $name = Tables::normName((string) (($f['name'] ?? '') ?: $label));
            // Verknüpfungen auf lokale Tabellen dieser Website (beim Teilen übernommen bzw. ausdrücklich mit Ziel): Ziel und Herkunft bleiben
            $p = $prev[$name] ?? null;
            if ($p && in_array($p['type'], self::SITE_REL, true)) {
                $seen[$name] = true;
                $out[] = ['label' => mb_substr($label, 0, 80)] + $p;
                continue;
            }
            if (in_array($f['type'] ?? '', self::SITE_REL, true)) {
                $tt = Tables::findContent((string) ($f['target'] ?? ''));
                if (!$tt || Tables::isShared($tt) || isset($seen[$name]) || in_array($name, $base, true)) {
                    $errors["site_fields.$i"] = __('Zusatzfeld „{label}“: Verknüpfungen nur auf eigene, nicht geteilte Tabellen dieser Website.', ['label' => $label]);
                    continue;
                }
                $seen[$name] = true;
                $out[] = ['name' => $name, 'label' => mb_substr($label, 0, 80), 'type' => (string) $f['type'], 'target' => (string) $tt['handle'], 'required' => false,
                    'in_list' => false, 'searchable' => false, 'help' => '', 'width' => '', 'site_extra' => true];
                continue;
            }
            $type = in_array($f['type'] ?? '', self::SITE_TYPES, true) ? (string) $f['type'] : 'text';
            if ($name === '' || isset($seen[$name]) || (in_array($name, $base, true) && !($name === Roles::FIELD && $type === Roles::TYPE))) {
                $errors["site_fields.$i"] = __('Zusatzfeld „{label}“: Der Name „{name}“ ist schon vergeben.', ['label' => $label, 'name' => $name]);
                continue;
            }
            $seen[$name] = true;
            $def = ['name' => $name, 'label' => mb_substr($label, 0, 80), 'type' => $type, 'required' => false, 'in_list' => false, 'searchable' => false,
                'help' => mb_substr(trim(strip_tags((string) ($f['help'] ?? ''))), 0, 200), 'width' => '', 'site_extra' => true];
            if (in_array($type, ['select', 'multiselect'], true)) {
                $opts = is_array($f['options'] ?? null) ? $f['options'] : preg_split('~\R~', (string) ($f['options'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                $def['options'] = [];
                foreach ($opts as $k => $v) {
                    $v = trim((string) $v);
                    if ($v === '') continue;
                    $def['options'][is_string($k) ? $k : Tables::normName($v)] = $v;
                }
            }
            $out[] = $def;
        }
        if ($errors) return [$out, $errors];
        $meta = Shared::meta($key) ?? throw new \InvalidArgumentException(__('Unbekannte geteilte Tabelle.'));
        $meta['site_fields'] = (array) ($meta['site_fields'] ?? []);
        $meta['site_fields'][site()->key] = $out;
        self::saveMeta($key, $meta);
        self::extraTable($key);
        PageCache::clear();
        return [$out, []];
    }

    private static function extraTable(string $key): void
    {
        Shared::db($key)->pdo->exec('CREATE TABLE IF NOT EXISTS share_extra (extra_site VARCHAR(40) NOT NULL, extra_entry INT NOT NULL, extra_data TEXT,
            extra_at VARCHAR(25), PRIMARY KEY (extra_site, extra_entry))');
    }

    /** Werte der Zusatzfelder dieser Website in die Einträge (Entries::attach) – „funktionen“ ersetzt die Funktionen des Eintrags */
    public static function loadExtras(array $t, array $rows): array
    {
        $defs = (array) ($t['site_fields'] ?? []);
        if (!$defs || !$rows) return $rows;
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $data = [];
        try {
            foreach (Shared::db((string) $t['shared']['key'])->fetchAll('SELECT extra_entry, extra_data FROM share_extra WHERE extra_site = ? AND extra_entry IN ('
                . implode(',', $ids) . ')', [site()->key]) as $x) {
                $data[(int) $x['extra_entry']] = json_decode((string) $x['extra_data'], true) ?: [];
            }
        } catch (\Throwable) {
            return $rows;
        }
        foreach ($rows as &$r) {
            $v = $data[(int) $r['id']] ?? [];
            $site = [];
            foreach ($defs as $f) {
                $n = (string) $f['name'];
                $val = $v[$n] ?? null;
                $val = match ($f['type']) {
                    'multiselect' => array_values(array_map('strval', (array) ($val ?? []))),
                    'bool' => !empty($val),
                    Roles::TYPE => Roles::items($val),
                    'relation' => $val !== null && (int) $val > 0 ? (int) $val : null,
                    'relations' => array_values(array_filter(array_map('intval', (array) ($val ?? [])))),
                    default => $val,
                };
                $site[$n] = $val;
                if ($n === Roles::FIELD && isset($r[Roles::FIELD]) && Shared::isForeign($t, $r)) {
                    // Funktionen auf dieser Website: ersetzen die des Eintrags, wenn welche eingetragen sind
                    $r['_base_' . Roles::FIELD] = $r[Roles::FIELD];
                    if ($val) $r[Roles::FIELD] = $val;
                    continue;
                }
                if (!array_key_exists($n, $r)) $r[$n] = $val;
            }
            $r['_site_extra'] = $site;
        }
        unset($r);
        return Roles::has($t) ? array_map(fn($r) => isset($r['_base_' . Roles::FIELD]) ? Roles::refresh($t, $r) : $r, $rows) : $rows;
    }

    /**
     * Zusatzangaben dieser Website zu einem Eintrag speichern (auch fremde, sichtbare Einträge). @return array<string, string> Fehler
     */
    public static function saveExtras(array $t, int $id, array $in): array
    {
        $defs = self::siteFields((string) $t['shared']['key']);
        if (!$defs) return [];
        $db = Shared::db((string) $t['shared']['key']);
        $e = $db->fetch("SELECT * FROM {$t['table']} WHERE id = ?", [$id]);
        if (!$e) return ['_' => __('Eintrag nicht gefunden.')];
        if (!Shared::visibleAll($t, $e)) return ['_' => __('Dieser Eintrag ist für diese Website nicht freigegeben.')];
        $cur = json_decode((string) ($db->fetchValue('SELECT extra_data FROM share_extra WHERE extra_site = ? AND extra_entry = ?', [site()->key, $id]) ?? ''), true) ?: [];
        [$values, $errors] = \Core\Fields::sanitize(Entries::schema(['fields' => [...$defs, ...array_values(array_filter($t['fields'], fn($f) => $f['name'] === 'listen'))], 'settings' => []] + $t), array_intersect_key($in, array_flip(array_column($defs, 'name'))), $cur);
        if ($errors) return $errors;
        $values = array_intersect_key($values, array_flip(array_column($defs, 'name')));
        foreach ($defs as $f) {
            if (!array_key_exists($f['name'], $values)) continue;
            $v = $values[$f['name']];
            $values[$f['name']] = match ($f['type']) {
                Roles::TYPE => Roles::items($v),
                'relation' => $v !== null && (int) $v > 0 ? (int) $v : null,
                'relations' => array_values(array_filter(array_map('intval', (array) ($v ?? [])))),
                default => $v,
            };
        }
        $db->query('DELETE FROM share_extra WHERE extra_site = ? AND extra_entry = ?', [site()->key, $id]);
        $db->insert('share_extra', ['extra_site' => site()->key, 'extra_entry' => $id, 'extra_data' => json_encode(array_replace($cur, $values), JSON_UNESCAPED_UNICODE), 'extra_at' => now()]);
        PageCache::clear();
        return [];
    }

    /** Stand der Zusatzangaben dieser Website (für Änderungsmarken, z. B. CTag der Erweiterung dav); '' = keine */
    public static function extrasStamp(array $t): string
    {
        if (empty($t['site_fields'])) return '';
        try {
            $r = Shared::db((string) $t['shared']['key'])->fetch('SELECT MAX(extra_at) AS u, COUNT(*) AS n FROM share_extra WHERE extra_site = ?', [site()->key]);
        } catch (\Throwable) {
            return '';
        }
        return (string) ($r['u'] ?? '') . '/' . (string) ($r['n'] ?? 0);
    }

    // ================================================================= Teilen: Verknüpfungen auf lokale Tabellen

    /**
     * Plan für das Teilen einer lokalen Tabelle (Shared::shareLocal): Verknüpfungen ohne Ziel (z. B. „Ort aus der Liste“ ohne Tabelle
     * Orte) bleiben „nicht eingerichtet“; Verknüpfungen auf lokale Tabellen dieser Website, die lokal bleiben sollen (Regionalgruppen,
     * Workshops), werden mit $localFields zu Zusatzfeldern dieser Website (Werte nach share_extra, Rückweg beim Aufheben).
     * @return array{error: ?string, unset: list<array{name: string, label: string, filled: int}>, local: list<array{name: string, label: string, target: string, filled: int}>, convert: list<string>}
     */
    public static function sharePlan(array $local, array $localFields = []): array
    {
        $out = ['error' => null, 'unset' => [], 'local' => [], 'convert' => []];
        $localFields = array_values(array_filter(array_map('strval', $localFields)));
        foreach ($local['fields'] as $f) {
            if (!in_array($f['type'], ['relation', 'relations'], true)) continue;
            $target = (string) ($f['target'] ?? '');
            if ($target === $local['handle']) continue;
            $filled = self::filled($local, $f);
            $tt = $target !== '' ? Tables::findContent($target) : null;
            if (!$tt) { $out['unset'][] = ['name' => (string) $f['name'], 'label' => (string) $f['label'], 'filled' => $filled]; continue; }
            if (Tables::isShared($tt) && ($tt['shared']['owner'] ?? '') === site()->key) continue;   // geteilte Tabelle derselben Website: bleibt Verknüpfung
            $row = ['name' => (string) $f['name'], 'label' => (string) $f['label'], 'target' => $target, 'filled' => $filled];
            if (in_array($f['name'], $localFields, true) && !Tables::isShared($tt)) $out['convert'][] = (string) $f['name'];
            else $out['local'][] = $row;
        }
        if ($unknown = array_diff($localFields, $out['convert'])) {
            $out['error'] = __('Diese Felder sind keine Verknüpfungen auf lokale Tabellen: {list}', ['list' => implode(', ', $unknown)]);
        } elseif ($out['local']) {
            $names = array_map(fn($r) => '„' . $r['label'] . '“ → „' . $r['target'] . '“', $out['local']);
            $out['error'] = __('Verknüpfungen auf lokale Tabellen: {list}. Entweder die Zieltabellen zuerst teilen oder die Felder als Zusatzfelder dieser Website übernehmen (Werte bleiben erhalten): --local-fields={names}',
                ['list' => implode(', ', $names), 'names' => implode(',', array_column($out['local'], 'name'))]);
        }
        return $out;
    }

    /** Wie viele Einträge haben in diesem Verknüpfungsfeld einen Wert? */
    private static function filled(array $t, array $f): int
    {
        try {
            return $f['type'] === 'relations'
                ? (int) Tables::db($t)->fetchValue('SELECT COUNT(DISTINCT entry_id) FROM ' . Tables::pivot($t, (string) $f['name']))
                : (int) Tables::db($t)->fetchValue("SELECT COUNT(*) FROM {$t['table']} WHERE {$f['name']} IS NOT NULL AND {$f['name']} != 0");
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Bericht des Plans (Kommandozeile, Verwaltung) */
    public static function sharePlanReport(array $plan): array
    {
        $l = [];
        foreach ($plan['unset'] as $u) $l[] = __('Nicht eingerichtet (ohne Zieltabelle, bleibt leer): „{label}“', ['label' => $u['label']]) . ($u['filled'] ? ' – ' . __('{n} Werte ohne Ziel', ['n' => $u['filled']]) : '');
        foreach ($plan['convert'] as $n) $l[] = __('Wird Zusatzfeld dieser Website (Werte bleiben, andere Websites sehen es nicht): {name}', ['name' => $n]);
        foreach ($plan['local'] as $r) $l[] = __('Verknüpfung auf lokale Tabelle: „{label}“ → „{target}“ ({n} belegt) – Vorschlag: als Zusatzfeld übernehmen', ['label' => $r['label'], 'target' => $r['target'], 'n' => $r['filled']]);
        return $l;
    }

    /**
     * Beim Teilen bzw. Beitreten: umgewandelte Verknüpfungen als Zusatzfelder dieser Website anlegen und ihre Werte nach share_extra
     * schreiben ($map: lokale ID → ID in der geteilten Tabelle). Die ursprüngliche Felddefinition bleibt am Zusatzfeld (orig) für den Rückweg.
     * @return list<string> Protokoll
     */
    public static function convertLocalFields(string $key, array $local, array $names, array $map): array
    {
        if (!$names) return [];
        $meta = Shared::meta($key) ?? throw new \InvalidArgumentException(__('Unbekannte geteilte Tabelle.'));
        if (empty($meta['network']['site_fields'])) {
            if ($meta['owner'] !== site()->key) throw new \InvalidArgumentException(__('Zusatzfelder je Website sind für diese geteilte Tabelle nicht eingeschaltet – das stellt die Website ein, der sie gehört.'));
            $meta['network'] = ['levels' => !empty($meta['network']['levels']), 'site_fields' => true];
        }
        $defs = array_values(array_filter((array) ($meta['site_fields'][site()->key] ?? []), fn($d) => !in_array($d['name'] ?? '', $names, true)));
        $values = [];
        foreach ($local['fields'] as $f) {
            if (!in_array($f['name'], $names, true)) continue;
            $defs[] = ['name' => (string) $f['name'], 'label' => (string) $f['label'], 'type' => (string) $f['type'], 'target' => (string) $f['target'], 'required' => false,
                'in_list' => false, 'searchable' => false, 'help' => (string) ($f['help'] ?? ''), 'width' => '', 'site_extra' => true, 'orig' => $f];
            if ($f['type'] === 'relation') {
                foreach (app()->db->fetchAll("SELECT id, {$f['name']} AS v FROM {$local['table']} WHERE {$f['name']} IS NOT NULL AND {$f['name']} != 0") as $r) {
                    if (isset($map[(int) $r['id']])) $values[$map[(int) $r['id']]][$f['name']] = (int) $r['v'];
                }
            } else {
                foreach (app()->db->fetchAll('SELECT entry_id, target_id FROM ' . Tables::pivot($local, (string) $f['name']) . ' ORDER BY sort, target_id') as $p) {
                    if (isset($map[(int) $p['entry_id']])) $values[$map[(int) $p['entry_id']]][$f['name']][] = (int) $p['target_id'];
                }
            }
        }
        $meta['site_fields'] = (array) ($meta['site_fields'] ?? []);
        $meta['site_fields'][site()->key] = $defs;
        Shared::writeMeta($key, $meta);
        self::extraTable($key);
        $db = Shared::db($key);
        foreach ($values as $id => $v) {
            $cur = json_decode((string) ($db->fetchValue('SELECT extra_data FROM share_extra WHERE extra_site = ? AND extra_entry = ?', [site()->key, $id]) ?? ''), true) ?: [];
            $db->query('DELETE FROM share_extra WHERE extra_site = ? AND extra_entry = ?', [site()->key, $id]);
            $db->insert('share_extra', ['extra_site' => site()->key, 'extra_entry' => (int) $id, 'extra_data' => json_encode(array_replace($cur, $v), JSON_UNESCAPED_UNICODE), 'extra_at' => now()]);
        }
        return [__('Als Zusatzfelder dieser Website übernommen: {list} ({n} Einträge mit Werten).', ['list' => implode(', ', $names), 'n' => count($values)])];
    }

    /**
     * Rückweg (Shared::toLocal beim Aufheben bzw. Verlassen): Zusatzfelder, die beim Teilen aus Verknüpfungen entstanden sind, werden
     * wieder normale Felder; $rows bekommen ihre Werte, $pivots die mehrfachen. @return list<array> zusätzliche Felddefinitionen
     */
    public static function restoreLocalFields(array $t, array &$rows, array &$pivots): array
    {
        $defs = array_values(array_filter((array) ($t['site_fields'] ?? []), fn($d) => is_array($d['orig'] ?? null)));
        if (!$defs || !$rows) return array_map(fn($d) => $d['orig'], $defs);
        $have = array_column($t['fields'], 'name');
        $defs = array_values(array_filter($defs, fn($d) => !in_array($d['name'], $have, true)));
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $data = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Shared::db((string) $t['shared']['key'])->fetchAll('SELECT extra_entry, extra_data FROM share_extra WHERE extra_site = ? AND extra_entry IN (' . implode(',', $chunk) . ')', [site()->key]) as $x) {
                $data[(int) $x['extra_entry']] = json_decode((string) $x['extra_data'], true) ?: [];
            }
        }
        foreach ($rows as &$r) {
            foreach ($defs as $d) {
                $v = $data[(int) $r['id']][$d['name']] ?? null;
                if ($d['type'] === 'relation') $r[$d['name']] = $v !== null && (int) $v > 0 ? (int) $v : null;
                else foreach (array_values((array) $v) as $i => $tid) $pivots[$d['name']][] = ['entry_id' => (int) $r['id'], 'target_id' => (int) $tid, 'sort' => $i];
            }
        }
        unset($r);
        foreach ($defs as $d) if ($d['type'] === 'relations') $pivots[$d['name']] ??= [];
        return array_map(fn($d) => $d['orig'], $defs);
    }

    /**
     * Bedingung auf ein Zusatzfeld dieser Website (Entries::conditions): Werte in share_extra. Verknüpfungen per ID (bzw. Slug der
     * Zieltabelle), sonst Text/Auswahl. null = kein Zusatzfeld.
     */
    public static function extraCondition(array $t, string $name, string $op, mixed $v, array &$params): ?string
    {
        $def = null;
        foreach ((array) ($t['site_fields'] ?? []) as $d) if (($d['name'] ?? '') === $name) { $def = $d; break; }
        if (!$def || !preg_match('~^[a-z][a-z0-9_]{0,63}$~', $name)) return null;
        $path = '$.' . $name;
        $neg = $op === '!=';
        if (in_array($def['type'], ['relation', 'relations'], true)) {
            $tid = ctype_digit((string) $v) ? (int) $v : 0;
            if (!$tid && ($tt = Tables::findContent((string) ($def['target'] ?? '')))) $tid = (int) (Tables::db($tt)->fetchValue("SELECT id FROM {$tt['table']} WHERE slug = ?", [\Core\Pages::slugify((string) $v)]) ?: 0);
            $sub = $def['type'] === 'relation'
                ? "SELECT extra_entry FROM share_extra WHERE extra_site = ? AND CAST(json_extract(extra_data, ?) AS INTEGER) = ?"
                : "SELECT extra_entry FROM share_extra, json_each(share_extra.extra_data, ?) je WHERE extra_site = ? AND CAST(je.value AS INTEGER) = ?";
            array_push($params, ...($def['type'] === 'relation' ? [site()->key, $path, $tid] : [$path, site()->key, $tid]));
            return 'id ' . ($neg ? 'NOT ' : '') . "IN ($sub)";
        }
        if ($op === 'contains' || $def['type'] === 'multiselect' || $def['type'] === Roles::TYPE) {
            array_push($params, site()->key, $path, '%' . ($op === 'contains' ? (string) $v : json_encode((string) $v, JSON_UNESCAPED_UNICODE)) . '%');
            return 'id ' . ($neg ? 'NOT ' : '') . 'IN (SELECT extra_entry FROM share_extra WHERE extra_site = ? AND json_extract(extra_data, ?) LIKE ?)';
        }
        array_push($params, site()->key, $path, (string) $v);
        return 'id ' . ($neg ? 'NOT ' : '') . 'IN (SELECT extra_entry FROM share_extra WHERE extra_site = ? AND CAST(json_extract(extra_data, ?) AS TEXT) = ?)';
    }

    // ================================================================= Herkunft aus Kalender-Abos (Kooperationen)

    /** Spalte _external anlegen (beim ersten Abo-Abgleich in diesen Kalender) */
    public static function ensureExternal(array $t): void
    {
        $db = Tables::db($t);
        $cols = $db->driver === 'mysql' ? array_column($db->fetchAll("SHOW COLUMNS FROM {$t['table']}"), 'Field')
            : array_column($db->fetchAll("PRAGMA table_info({$t['table']})"), 'name');
        if (!in_array(self::EXTERNAL, $cols, true)) $db->pdo->exec("ALTER TABLE {$t['table']} ADD COLUMN " . self::EXTERNAL . ' TEXT NULL');
    }

    /** Herkunft eines Termins aus einem Abo: ['source' => Name, 'organizer' => Veranstalter, 'url' => Original] oder null */
    public static function external(array $e): ?array
    {
        $v = $e[self::EXTERNAL] ?? null;
        if (is_string($v)) $v = json_decode($v, true);
        if (!is_array($v) || trim((string) ($v['source'] ?? '')) === '') return null;
        $url = trim((string) ($v['url'] ?? ''));
        return ['source' => mb_substr(trim((string) $v['source']), 0, 120), 'organizer' => mb_substr(trim((string) ($v['organizer'] ?? '')), 0, 120),
            'url' => preg_match('~^https?://~i', $url) ? mb_substr($url, 0, 500) : ''];
    }

    /**
     * Herkunft für die Website (Blöcke Nächste Termine, Personen, Datensatz-Felder): Termine aus Abos mit Quelle, Veranstalter und
     * Original-Link; fremde Einträge (nur mit Weitergabe-Stufen) mit der Website, von der sie kommen. '' = nichts (eigene Einträge,
     * Tabellen ohne Netzwerk – Ausgabe bleibt unverändert).
     */
    public static function originNote(array $t, array $e): string
    {
        $parts = [];
        if ($x = self::external($e)) {
            $parts[] = e(lt('Quelle: {name}', ['name' => $x['source']]));
            if ($x['organizer'] !== '') $parts[] = e(lt('Veranstalter: {name}', ['name' => $x['organizer']]));
            if ($x['url'] !== '') $parts[] = '<a href="' . e($x['url']) . '" rel="noopener external">' . e(lt('Zum Original')) . '</a>';
        }
        if (self::levelsOn($t) && Shared::isForeign($t, $e)) {
            $info = Shared::siteInfo((string) $e['origin_site'], (string) $t['shared']['key']);
            if (!$x) $parts[] = e(lt('von {name}', ['name' => $info['name']]));
        }
        if (!$parts) return '';
        app()->theme->markUsed('@nearby');
        return '<p class="net-origin">' . implode('<span aria-hidden="true"> · </span>', $parts) . '</p>';
    }

    /** JSON für die Spalte (IcsImport mit Quelle) */
    public static function externalValue(string $source, string $organizer, string $url): string
    {
        return (string) json_encode(['source' => $source, 'organizer' => $organizer, 'url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ================================================================= Grundschema im Netzwerk (Mischstände)

    /**
     * Prüft vor dem Zusammenführen einer lokalen Tabelle mit der geteilten (Beitritt): gleiche Art, Version der lokalen höchstens die
     * der geteilten. @return array{error: ?string, fill: bool} fill = Funktionen der übernommenen Einträge füllen (lokal v1, geteilt v2)
     */
    public static function mergeCheck(array $local, array $shared): array
    {
        $lk = Kinds::of($local);
        $sk = Kinds::of($shared);
        if (!Kinds::isSpecial($lk) && !Kinds::isSpecial($sk)) return ['error' => null, 'fill' => false];
        if ($lk !== $sk) return ['error' => __('Die Tabellenart passt nicht: hier „{a}“, geteilt „{b}“ – bitte zuerst umwandeln (data:convert).', ['a' => Kinds::label($lk), 'b' => Kinds::label($sk)]), 'fill' => false];
        $lv = Kinds::tableVersion($local);
        $sv = Kinds::tableVersion($shared);
        if ($lv > $sv) {
            return ['error' => __('Die Tabelle dieser Website hat Grundschema {a}, die geteilte erst {b}. Die Website, der die geteilte Tabelle gehört, hebt sie zuerst: php bin/console data:kind-upgrade {key} --apply --site={owner}',
                ['a' => $lv, 'b' => $sv, 'key' => (string) $shared['handle'], 'owner' => (string) $shared['shared']['owner']]), 'fill' => false];
        }
        return ['error' => null, 'fill' => $lk === Kinds::CONTACTS && $lv < 2 && $sv >= 2];
    }

    /** Versionen im Netzwerk: geteilte Tabelle und je beteiligter Website gleichnamige lokale Tabellen (Bericht) */
    public static function versions(string $key): array
    {
        $t = Tables::sharedTable($key);
        $out = ['shared' => $t ? ['kind' => Kinds::of($t), 'version' => Kinds::tableVersion($t)] : null, 'sites' => []];
        foreach (Shared::participants(Shared::meta($key) ?? ['owner' => '', 'members' => []]) as $s) {
            if ($s === '') continue;
            $db = Shared::siteDb($s);
            $n = $db ? (int) $db->fetchValue("SELECT COUNT(*) FROM data_tables WHERE handle = ?", [$key]) : 0;
            $count = $t ? (int) Shared::db($key)->fetchValue("SELECT COUNT(*) FROM {$t['table']} WHERE origin_site = ?", [$s]) : 0;
            $out['sites'][$s] = ['entries' => $count, 'local_table' => $n > 0];
        }
        return $out;
    }

    // ================================================================= Beitritt mit Plan (Kontakte, Termine)

    /** Vergleichsschlüssel für Doppel: Kontakte Name + erste E-Mail, Termine Titel + Beginn (Tag), sonst Titel */
    public static function dupKey(array $t, array $e): string
    {
        $n = fn(string $s) => mb_strtolower(trim((string) preg_replace('~\s+~u', ' ', strip_tags($s))));
        if (Kinds::isContacts($t)) {
            $mail = '';
            foreach (\Core\Combo::items($e['email'] ?? null) as $i) { $mail = $n((string) ($i['value'] ?? '')); if ($mail !== '') break; }
            return $n((string) ($e['name'] ?? '')) . "\x1F" . $mail;
        }
        if (Kinds::isCalendar($t)) return $n((string) ($e['titel'] ?? '')) . "\x1F" . substr((string) ($e['beginn'] ?? ''), 0, 10);
        return $n(Entries::title($t, $e));
    }

    /**
     * Probelauf des Beitritts: eigene Einträge der lokalen Tabelle gleichen Kurznamens, Einträge der anderen, Doppel.
     * @return array{ok: bool, error: ?string, local: int, shared: int, dupes: list<array{id: int, title: string, matches: list<array{id: int, title: string, site: string}>}>}
     */
    public static function joinPlan(string $key): array
    {
        $meta = Shared::meta($key);
        $t = $meta ? Tables::sharedTable($key) : null;
        if (!$meta || !$t) return ['ok' => false, 'error' => __('Unbekannte geteilte Tabelle.'), 'local' => 0, 'shared' => 0, 'dupes' => []];
        $row = app()->db->fetch('SELECT id FROM data_tables WHERE handle = ?', [$key]);
        $local = $row ? Tables::find((int) $row['id']) : null;
        if ($local && ($c = self::mergeCheck($local, $t)) && $c['error']) return ['ok' => false, 'error' => $c['error'], 'local' => 0, 'shared' => 0, 'dupes' => []];
        $others = array_values(array_diff(Shared::participants($meta), [site()->key]));
        $theirs = $others ? Shared::db($key)->fetchAll("SELECT * FROM {$t['table']} WHERE status = 'published' AND origin_site IN (" . implode(',', array_fill(0, count($others), '?')) . ')', $others) : [];
        $index = [];
        foreach ($theirs as $x) {
            $x['email'] = $x['email'] ?? null;
            $index[self::dupKey($t, $x)][] = ['id' => (int) $x['id'], 'title' => Entries::title($t, $x), 'site' => (string) $x['origin_site']];
        }
        $dupes = [];
        $mine = $local ? app()->db->fetchAll("SELECT * FROM {$local['table']} ORDER BY id") : [];
        foreach ($mine as $m) {
            $k = self::dupKey($local, $m);
            if ($k !== "\x1F" && isset($index[$k])) $dupes[] = ['id' => (int) $m['id'], 'title' => Entries::title($local, $m), 'matches' => $index[$k]];
        }
        return ['ok' => true, 'error' => null, 'local' => count($mine), 'shared' => count($theirs), 'dupes' => $dupes];
    }

    /**
     * Beitreten mit Plan (nur auf Einladung): eigene Einträge zusammenführen – je Doppel existing (vorhandenen nutzen, eigenen nicht
     * übernehmen), mine (eigenen übernehmen, den anderen hier ausblenden) oder both. Rückweg: Shared::leave (eigene Einträge kommen
     * als lokale Tabelle zurück, die Sicherung der lokalen Tabelle bleibt als zz_unshared_…). @return list<string>
     */
    public static function join(string $key, array $choices = [], string $by = ''): array
    {
        $plan = self::joinPlan($key);
        if (!$plan['ok']) throw new \InvalidArgumentException((string) $plan['error']);
        if (!Shared::isInvited($key)) throw new \InvalidArgumentException(__('Diese Website ist nicht zum Beitreten eingeladen – die Website, der die Tabelle gehört, gibt sie frei.'));
        if (!app()->db->fetch('SELECT id FROM data_tables WHERE handle = ?', [$key])) {
            Shared::addMember($key);
            return [__('Diese Website ist jetzt beteiligt.')];
        }
        $skip = [];
        $hide = [];
        foreach ($plan['dupes'] as $d) {
            $c = in_array($choices[$d['id']] ?? '', ['existing', 'mine', 'both'], true) ? $choices[$d['id']] : 'existing';
            if ($c === 'existing') $skip[$d['id']] = (int) $d['matches'][0]['id'];
            elseif ($c === 'mine') foreach ($d['matches'] as $x) $hide[] = (int) $x['id'];
        }
        $map = [];
        $log = Shared::shareLocal($key, [], true, [], $map, $skip);
        if ($hide && ($t = Tables::sharedTable($key))) {
            $n = Shared::setPick($t, array_values(array_unique($hide)), 'hidden', $by);
            $log[] = __('{n} Einträge anderer Websites auf dieser Website ausgeblendet (eigener Eintrag gilt).', ['n' => $n]);
        }
        return $log;
    }

    // ================================================================= Selbsttest (ohne Datenbank)

    public static function selftest(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Netzwerk – ');
        $eq('Stufe leer = Netzwerk (Bestand)', [self::level([]), self::level([self::COL => 'site']), self::level([self::COL => 'quatsch'])], ['network', 'site', 'network']);
        $eq('Websites einer Stufe', self::shareSites([self::COL_SITES => '["landa","x y","landb"]']), ['landa', 'landb']);
        $eq('Standard: Adressbuch nur eigene Website, sonst Netzwerk', [self::defaultLevel(['settings' => ['kind' => 'contacts']]), self::defaultLevel(['settings' => ['kind' => 'calendar']])], ['site', 'network']);
        $eq('Ohne Schalter: alles erlaubt', self::allows(['settings' => []], ['origin_site' => 'x', self::COL => 'site']), true);
        $eq('Herkunft aus Abo', self::external([self::EXTERNAL => self::externalValue('Partner', 'Musterverein', 'javascript:alert(1)')]), ['source' => 'Partner', 'organizer' => 'Musterverein', 'url' => '']);
        $eq('Keine Herkunft ohne Quelle', self::external([self::EXTERNAL => '{"source":""}']), null);
        $c = self::mergeCheck(['settings' => ['kind' => 'contacts', 'kind_version' => 1]], ['handle' => 'k', 'settings' => ['kind' => 'contacts', 'kind_version' => 2], 'shared' => ['owner' => 'bund']]);
        $eq('Mischstand lokal v1 / geteilt v2: Funktionen füllen', $c, ['error' => null, 'fill' => true]);
        $c = self::mergeCheck(['settings' => ['kind' => 'contacts', 'kind_version' => 2]], ['handle' => 'k', 'settings' => ['kind' => 'contacts', 'kind_version' => 1], 'shared' => ['owner' => 'bund']]);
        $eq('Mischstand lokal v2 / geteilt v1: abgelehnt', [$c['error'] !== null, $c['fill']], [true, false]);
        $c = self::mergeCheck(['settings' => ['kind' => 'content']], ['handle' => 'k', 'settings' => ['kind' => 'contacts', 'kind_version' => 2], 'shared' => ['owner' => 'bund']]);
        $eq('Andere Art: abgelehnt', $c['error'] !== null, true);
        $eq('Doppel: Kontakt Name + E-Mail', self::dupKey(['settings' => ['kind' => 'contacts']], ['name' => ' Erika  Musterfrau', 'email' => '[{"label":"work","value":"ERIKA@example.org"}]']), "erika musterfrau\x1Ferika@example.org");
        return ['ok' => $ok, 'fails' => $fails];
    }
}
