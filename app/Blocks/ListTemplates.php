<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blocks;

use Core\Data\Calendar;
use Core\Data\Tables;
use Core\Extensions;
use Core\Fields;
use Core\PageCache;
use Core\Pages;

/**
 * Listen-Vorlagen (Stufe 1): gespeicherte Darstellungen für Listenblöcke wie die Datenliste (data_list).
 *
 * Eine Vorlage ist ein benannter Satz der Darstellungs-Optionen eines Blocks (Darstellung, Felder, Spalten, Bildformat, Sortierung,
 * Besucher-Filter …) für eine Tabelle. Der Block behält, was zum Inhalt gehört (BLOCK_KEYS: Überschrift, Tabelle, Anzahl, Filter
 * „Nur Einträge, bei denen …“, Button, Text ohne Einträge). Beim Rendern legt Core\Theme::makeBlock die Werte der gewählten Vorlage
 * über die Daten des Blocks – jeder Kit-Renderer (blocks/data_list.php) bekommt also ganz normale Blockdaten und muss nichts wissen.
 * Ohne Vorlage (Feld „template“ leer, auch alle bestehenden Blöcke) bleibt alles wie bisher; fehlt eine gewählte Vorlage, gelten
 * die eigenen Werte des Blocks.
 *
 * Quellen:
 *   - Tabelle list_templates der Website (Verwaltung: Daten → Tabelle → Listen-Vorlagen, „Als Vorlage speichern“ im Editor);
 *     Vorschläge je Tabelle legt seed() beim ersten Öffnen an (Karten, Kompakte Liste, Liste mit Bild, Verzeichnis A–Z, Tabelle, Team).
 *   - Kit: theme.php → 'list_templates' => ['team' => ['name' => …, 'options' => […], 'tables' => ['team'], 'requires' => ['media'],
 *     'block' => 'data_list', 'default' => true]] – Schlüssel im Block „kit:team“, nur lesbar.
 *   - Erweiterungen: Filter „list_templates“ (gleiches Format, Kontext table + block) – Schlüssel „ext:{name}“.
 * Optionen stehen ohne Tabellen-Präfix („title“ statt „news.title“); apply() ergänzt es für Felder der Typen datafield/datafields.
 *
 * Blöcke nehmen teil mit 'list_template' => true in ihrer Definition (optional ['keep' => [weitere Blockfelder]]); decorate()
 * fügt das Feld „Vorlage“ ein und blendet die Darstellungsfelder aus, solange eine Vorlage gewählt ist (Werte bleiben gespeichert).
 * Recht zum Anlegen, Ändern und Festlegen des Standards: design.blocks („Blöcke gestalten“).
 */
final class ListTemplates
{
    /** Grundformen (Stufe 2: „custom“ = frei gestaltet) – Vorschaubild je Grundform */
    public const BASES = ['cards', 'list', 'compact', 'table', 'directory', 'custom'];

    /** Felder, die immer beim Block bleiben: Inhalt und Auswahl der Einträge */
    public const BLOCK_KEYS = ['eyebrow', 'title', 'intro', 'table', 'limit', 'template', 'filter_field', 'filter_op', 'filter_value', 'exclude_current',
        'paginate', 'source', 'featured_first', 'more_label', 'more_link', 'empty_text', 'hide_empty', 'skip'];

    /** Recht für Anlegen, Ändern, Löschen und Standard */
    public const PERMISSION = 'design.blocks';

    /** Merker: Vorschläge je Tabelle schon angelegt (auch wenn danach gelöscht) – [block|tabelle => true] */
    public const SEEDED = 'sys.list_templates_seeded';

    /** @var array<string, array<string, array>> je „block|tabelle“ */
    private static array $memo = [];

    /** Nur Selbsttest: Kit-Vorlagen statt theme.php → list_templates */
    private static ?array $kitOverride = null;

    // ------------------------------------------------------------------ Definition des Blocks

    public static function supports(?array $def): bool
    {
        return is_array($def) && !empty($def['list_template']);
    }

    /** Namen der Felder, die eine Vorlage festlegt (alles außer BLOCK_KEYS und 'keep') */
    public static function keys(array $def): array
    {
        $keep = array_merge(self::BLOCK_KEYS, is_array($def['list_template'] ?? null) ? (array) ($def['list_template']['keep'] ?? []) : []);
        $out = [];
        foreach ((array) ($def['fields'] ?? []) as $f) {
            $n = $f['name'] ?? null;
            if (!is_string($n) || ($f['type'] ?? '') === 'heading' || str_starts_with($n, '_') || in_array($n, $keep, true)) continue;
            $out[] = $n;
        }
        return $out;
    }

    /** Felder der Vorlage (für das Formular „Vorlage bearbeiten“): Überschriften bleiben, wenn ihr Abschnitt Vorlagen-Felder enthält */
    public static function formFields(array $def): array
    {
        $keys = self::keys($def);
        $out = [];
        $head = null;
        foreach ((array) ($def['fields'] ?? []) as $f) {
            if (($f['type'] ?? '') === 'heading') { $head = $f; continue; }
            if (!in_array($f['name'] ?? '', $keys, true)) continue;
            if ($head !== null) { $out[] = ['collapse' => false] + $head; $head = null; }
            $out[] = $f;
        }
        foreach ($out as $i => $f) {
            if (($f['type'] ?? '') === 'heading') unset($out[$i]['collapse']);   // Formular der Vorlage zeigt alles auf einen Blick
            // Bedingung „ohne Vorlage“ (decorate) gilt hier nicht – das Formular IST die Vorlage
            $c = $f['show_if'] ?? null;
            if (is_array($c)) {
                $c = array_is_list($c) ? array_map(fn($alt) => array_diff_key((array) $alt, ['template' => 1]), $c) : array_diff_key($c, ['template' => 1]);
                if (!array_filter(array_is_list($c) ? $c : [$c])) unset($out[$i]['show_if']); else $out[$i]['show_if'] = $c;
            }
        }
        return array_values($out);
    }

    /**
     * Feld „Vorlage“ einfügen (nach „Anzahl“ bzw. „Tabelle“) und Darstellungsfelder nur ohne Vorlage zeigen ('show_if' template = leer).
     * Abschnitte, die nur aus Vorlagen-Feldern bestehen, fallen mit einer gewählten Vorlage ganz weg.
     */
    public static function decorate(array $def, string $type): array
    {
        $fields = (array) ($def['fields'] ?? []);
        if (in_array('template', array_column($fields, 'name'), true)) return $def;
        $keys = self::keys($def);
        $empty = ['template' => ['']];
        $add = function (array $f) use ($empty): array {
            $c = $f['show_if'] ?? null;
            if (!is_array($c) || !$c) $f['show_if'] = $empty;
            elseif (array_is_list($c)) $f['show_if'] = array_map(fn($alt) => (array) $alt + $empty, $c);
            else $f['show_if'] = $c + $empty;
            return $f;
        };
        // Abschnitte: Kopf + Felder bis zum nächsten Kopf
        $sections = [];
        $cur = ['head' => null, 'fields' => []];
        foreach ($fields as $f) {
            if (($f['type'] ?? '') === 'heading') { $sections[] = $cur; $cur = ['head' => $f, 'fields' => []]; continue; }
            $cur['fields'][] = $f;
        }
        $sections[] = $cur;
        $out = [];
        foreach ($sections as $s) {
            $named = array_values(array_filter($s['fields'], fn($f) => isset($f['name'])));
            $onlyTpl = $named && !array_filter($named, fn($f) => !in_array($f['name'], $keys, true));
            if ($s['head'] !== null) $out[] = $onlyTpl && !empty($s['head']['collapse']) ? $add($s['head']) : $s['head'];
            foreach ($s['fields'] as $f) {
                $out[] = !$onlyTpl && in_array($f['name'] ?? '', $keys, true) ? $add($f) : $f;
                // Feld „Vorlage“ direkt nach der Anzahl (sonst nach der Tabelle)
                if (($f['name'] ?? '') === 'limit' || (($f['name'] ?? '') === 'table' && !in_array('limit', array_column($fields, 'name'), true))) {
                    $out[] = self::field($type);
                }
            }
        }
        if (!in_array('template', array_column($out, 'name'), true)) $out[] = self::field($type);
        $def['fields'] = $out;
        return $def;
    }

    /** Das Feld „Vorlage“ in der Seitenleiste (Feldtyp listtemplate, Core\Fields) */
    public static function field(string $type): array
    {
        return ['name' => 'template', 'label' => 'Vorlage', 'type' => 'listtemplate', 'block' => $type, 'default' => '',
            'help' => 'Eine Vorlage legt Darstellung, Felder, Spalten und Sortierung fest – Änderungen an der Vorlage gelten überall, wo sie gewählt ist.'];
    }

    // ------------------------------------------------------------------ Lesen

    /**
     * Vorlagen für eine Tabelle: gespeicherte der Website (Standard zuerst), dann die des Kits und der Erweiterungen.
     * $seed: Vorschläge beim ersten Aufruf anlegen (nur in der Verwaltung, nicht beim Rendern).
     * @return array<string, array{key: string, id: ?int, name: string, base: string, options: array, table: string, block: string, default: bool, source: string, locked: bool, updated_at: ?string}>
     */
    public static function forTable(array $t, string $type = 'data_list', bool $seed = false): array
    {
        if ($seed) self::seed($t, $type);
        $mk = $type . '|' . $t['handle'];
        if (isset(self::$memo[$mk])) return self::$memo[$mk];
        $out = [];
        try {
            $rows = app()->db->fetchAll('SELECT * FROM list_templates WHERE table_handle = ? AND block = ? ORDER BY is_default DESC, id', [$t['handle'], $type]);
        } catch (\Throwable) {
            $rows = [];   // Tabelle noch nicht angelegt (vor migrate)
        }
        foreach ($rows as $r) {
            $out[(string) $r['tkey']] = ['key' => (string) $r['tkey'], 'id' => (int) $r['id'], 'name' => (string) $r['name'], 'base' => (string) $r['base'],
                'options' => (array) (json_decode((string) $r['options_json'], true) ?: []), 'table' => (string) $r['table_handle'], 'block' => (string) $r['block'],
                'default' => (bool) $r['is_default'], 'source' => 'db', 'locked' => (bool) $r['locked'], 'updated_at' => $r['updated_at'] ?? null];
        }
        foreach (self::code($t, $type) as $k => $c) $out[$k] = $c;
        return self::$memo[$mk] = $out;
    }

    /** Vorlagen aus Kit (theme.php → list_templates) und Erweiterungen (Filter „list_templates“) für diese Tabelle */
    public static function code(array $t, string $type = 'data_list'): array
    {
        $out = [];
        $lists = ['kit' => self::$kitOverride ?? (array) (app()->theme->def['list_templates'] ?? [])];
        $ext = Extensions::filter('list_templates', [], ['table' => $t, 'block' => $type]);
        $lists['ext'] = is_array($ext) ? $ext : [];
        foreach ($lists as $src => $list) {
            foreach ($list as $k => $c) {
                if (!is_array($c) || !preg_match('~^[a-z0-9][a-z0-9_-]{0,47}$~', (string) $k)) continue;
                if (($c['block'] ?? 'data_list') !== $type) continue;
                if (isset($c['tables']) && !in_array($t['handle'], (array) $c['tables'], true)) continue;
                // 'requires' => ['media', 'select']: nur für Tabellen mit Feldern dieser Typen (deklarativ statt Rückruf)
                $types = array_column((array) ($t['fields'] ?? []), 'type');
                if (array_diff(array_map('strval', (array) ($c['requires'] ?? [])), $types)) continue;
                $opts = (array) ($c['options'] ?? []);
                $key = $src . ':' . $k;
                $out[$key] = ['key' => $key, 'id' => null, 'name' => (string) ($c['name'] ?? $k), 'base' => self::baseOf($opts),
                    'options' => $opts, 'table' => $t['handle'], 'block' => $type, 'default' => !empty($c['default']), 'source' => $src, 'locked' => true, 'updated_at' => null];
            }
        }
        return $out;
    }

    public static function find(array $t, string $key, string $type = 'data_list'): ?array
    {
        return $key === '' ? null : (self::forTable($t, $type)[$key] ?? null);
    }

    /** Standard der Tabelle: gespeicherter Standard, sonst der eines Kits/einer Erweiterung, sonst null (= eigene Einstellungen) */
    public static function defaultKey(array $t, string $type = 'data_list'): ?string
    {
        $code = null;
        foreach (self::forTable($t, $type) as $k => $tpl) {
            if (!$tpl['default']) continue;
            if ($tpl['source'] === 'db') return $k;
            $code ??= $k;
        }
        return $code;
    }

    /** Grundform aus den Optionen (Darstellung der Datenliste; unbekannte Darstellungen eines Kits zählen als Karten) */
    public static function baseOf(array $options): string
    {
        $l = (string) ($options['layout'] ?? 'cards');
        return in_array($l, self::BASES, true) ? $l : 'cards';
    }

    // ------------------------------------------------------------------ Anwenden (Rendern)

    /** Blockdaten mit der gewählten Vorlage (Core\Theme::makeBlock); ohne Tabelle/Vorlage unverändert */
    public static function resolve(array $data, array $def, string $type): array
    {
        $key = (string) ($data['template'] ?? '');
        if ($key === '') return $data;
        $t = Tables::findContent((string) ($data['table'] ?? ''));
        $tpl = $t ? self::find($t, $key, $type) : null;
        return $tpl && $t ? self::apply($data, $tpl, $def, $t) : $data;
    }

    /** Werte der Vorlage über die Blockdaten legen; fehlende Optionen = Standard des Feldes (eine Vorlage bestimmt die Darstellung ganz) */
    public static function apply(array $data, array $tpl, array $def, array $t): array
    {
        $byName = array_column(array_filter((array) ($def['fields'] ?? []), fn($f) => isset($f['name'])), null, 'name');
        $defaults = Fields::defaults(array_values($byName));
        foreach (self::keys($def) as $k) {
            $v = array_key_exists($k, $tpl['options']) ? $tpl['options'][$k] : ($defaults[$k] ?? null);
            $data[$k] = self::prefix($v, $byName[$k] ?? [], $t['handle']);
        }
        return $data;
    }

    /** Feldwerte „feld“ → „tabelle.feld“ (datafield, datafields) */
    private static function prefix(mixed $v, array $f, string $handle): mixed
    {
        $p = $handle . '.';
        return match ($f['type'] ?? '') {
            'datafield' => is_string($v) && $v !== '' && !str_contains($v, '.') ? $p . $v : $v,
            'datafields' => array_values(array_map(fn($x) => is_string($x) && !str_contains($x, '.') ? $p . $x : $x, (array) $v)),
            default => $v,
        };
    }

    /** Feldwerte „tabelle.feld“ → „feld“ (nur Felder dieser Tabelle; fremde fallen weg) */
    private static function unprefix(mixed $v, array $f, string $handle): mixed
    {
        $p = $handle . '.';
        return match ($f['type'] ?? '') {
            'datafield' => is_string($v) && str_starts_with($v, $p) ? substr($v, strlen($p)) : '',
            'datafields' => array_values(array_filter(array_map(fn($x) => is_string($x) && str_starts_with($x, $p) ? substr($x, strlen($p)) : '', (array) $v), fn($x) => $x !== '')),
            default => $v,
        };
    }

    /**
     * Optionen aus Blockdaten bzw. Formular (Werte mit Tabellen-Präfix, wie im Block): nur Vorlagen-Felder, geprüft nach dem Feldschema
     * des Blocks (Core\Fields::sanitize), gespeichert ohne Präfix.
     */
    public static function optionsFrom(array $data, array $def, array $t): array
    {
        $keys = self::keys($def);
        $fields = array_values(array_filter((array) ($def['fields'] ?? []), fn($f) => in_array($f['name'] ?? '', $keys, true)));
        [$clean] = Fields::sanitize($fields, array_intersect_key($data, array_flip($keys)));
        $byName = array_column($fields, null, 'name');
        $out = [];
        foreach ($keys as $k) if (array_key_exists($k, $clean)) $out[$k] = self::unprefix($clean[$k], $byName[$k] ?? [], $t['handle']);
        return $out;
    }

    // ------------------------------------------------------------------ Schreiben

    /** Neue Vorlage; Rückgabe: Schlüssel */
    public static function create(array $t, string $type, string $name, array $options, ?int $user = null, bool $default = false): string
    {
        $name = self::cleanName($name);
        $key = self::uniqueKey($t, $type, $name);
        app()->db->insert('list_templates', ['table_handle' => $t['handle'], 'block' => $type, 'tkey' => $key, 'name' => $name,
            'base' => self::baseOf($options), 'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'locked' => 0, 'is_default' => 0, 'shared' => 0, 'created_by' => $user, 'created_at' => now(), 'updated_at' => now()]);
        if ($default) self::setDefault($t, $type, $key);
        self::changed();
        return $key;
    }

    /** Name und Optionen ändern (Schlüssel bleibt – Blöcke behalten die Verbindung) */
    public static function update(array $t, string $type, string $key, string $name, array $options): bool
    {
        $n = app()->db->update('list_templates', ['name' => self::cleanName($name), 'base' => self::baseOf($options),
            'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()],
            'table_handle = ? AND block = ? AND tkey = ?', [$t['handle'], $type, $key]);
        self::changed();
        return $n > 0;
    }

    /** Standard der Tabelle setzen (null = keiner); nur gespeicherte Vorlagen */
    public static function setDefault(array $t, string $type, ?string $key): void
    {
        app()->db->query('UPDATE list_templates SET is_default = 0 WHERE table_handle = ? AND block = ?', [$t['handle'], $type]);
        if ($key !== null) app()->db->query('UPDATE list_templates SET is_default = 1 WHERE table_handle = ? AND block = ? AND tkey = ?', [$t['handle'], $type, $key]);
        self::$memo = [];
    }

    /** Kopie unter neuem Namen */
    public static function duplicate(array $t, string $type, string $key, ?int $user = null): ?string
    {
        $tpl = self::find($t, $key, $type);
        return $tpl ? self::create($t, $type, __('{name} (Kopie)', ['name' => $tpl['name']]), $tpl['options'], $user) : null;
    }

    /** Löschen – nur, wenn kein Block sie verwendet (sonst sähen diese Blöcke plötzlich anders aus) */
    public static function delete(array $t, string $type, string $key): bool
    {
        if (!empty(self::usage($t, $type)[$key])) return false;
        app()->db->query('DELETE FROM list_templates WHERE table_handle = ? AND block = ? AND tkey = ?', [$t['handle'], $type, $key]);
        self::changed();
        return true;
    }

    private static function changed(): void
    {
        self::$memo = [];
        PageCache::clear();   // Vorlage wirkt auf allen Seiten, die sie verwenden
    }

    private static function cleanName(string $name): string
    {
        $name = trim(preg_replace('~\s+~u', ' ', strip_tags($name)) ?? '');
        return mb_substr($name !== '' ? $name : __('Vorlage'), 0, 80);
    }

    private static function uniqueKey(array $t, string $type, string $name): string
    {
        $base = trim(substr((string) preg_replace('~[^a-z0-9]+~', '-', strtolower(strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss']))), 0, 40), '-');
        if ($base === '') $base = 'vorlage';
        $key = $base;
        for ($i = 2; app()->db->fetchValue('SELECT COUNT(*) FROM list_templates WHERE table_handle = ? AND block = ? AND tkey = ?', [$t['handle'], $type, $key]); $i++) $key = $base . '-' . $i;
        return $key;
    }

    // ------------------------------------------------------------------ Verwendung

    /**
     * Seiten, deren Blöcke eine Vorlage dieser Tabelle verwenden (veröffentlicht oder Entwurf, auch in Layout-Spalten):
     * [schlüssel => [seiten-id => titel]]
     */
    public static function usage(array $t, string $type = 'data_list'): array
    {
        $out = [];
        $like = '%"template":"%';
        try {
            $rows = Pages::db()->fetchAll('SELECT id, title, content_published, content_draft FROM pages WHERE content_published LIKE ? OR content_draft LIKE ?', [$like, $like]);
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $p) {
            foreach (\Core\Layout::flatten(array_merge(Pages::blocks($p, false), Pages::blocks($p, true))) as $b) {
                if (($b['type'] ?? '') !== $type || (string) ($b['data']['table'] ?? '') !== $t['handle']) continue;
                $k = (string) ($b['data']['template'] ?? '');
                if ($k !== '') $out[$k][(int) $p['id']] = (string) $p['title'];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ Vorschläge

    /**
     * Vorschläge für eine Tabelle (je nach Feldern): Name + Optionen. Karten und Kompakte Liste immer; Liste mit Bild, wenn ein Bildfeld
     * da ist; Verzeichnis A–Z mit Filter, wenn ein Auswahlfeld da ist; Tabelle; Team mit Foto, wenn Bild und Funktion/Position.
     * @return list<array{name: string, options: array}>
     */
    public static function suggestions(array $t): array
    {
        $img = Tables::imageField($t);
        $select = '';
        $role = '';
        foreach ((array) $t['fields'] as $f) {
            if ($select === '' && in_array($f['type'] ?? '', ['select', 'multiselect'], true)) $select = (string) $f['name'];
            if ($role === '' && preg_match('~funktion|position|rolle|role|job|aufgabe~i', (string) ($f['name'] ?? '') . ' ' . (string) ($f['label'] ?? ''))) $role = (string) $f['name'];
        }
        $out = [['name' => __('Neueste als Karten'), 'options' => ['layout' => 'cards', 'columns' => '3', 'ratio' => '16:10', 'link_detail' => true]]];
        $out[] = ['name' => __('Kompakte Liste'), 'options' => ['layout' => 'compact', 'link_detail' => true]];
        if ($img !== '') $out[] = ['name' => __('Liste mit Bild'), 'options' => ['layout' => 'list', 'ratio' => '4:3', 'link_detail' => true]];
        if ($select !== '') {
            $out[] = ['name' => __('Verzeichnis A–Z mit Filter'), 'options' => ['layout' => 'directory', 'columns' => '3', 'az_index' => true,
                'visitor_search' => true, 'visitor_filter' => $select, 'link_detail' => true]];
        }
        $out[] = ['name' => __('Tabelle sortierbar'), 'options' => ['layout' => 'table', 'table_sort' => true, 'link_detail' => true]];
        if ($img !== '' && $role !== '') {
            $out[] = ['name' => __('Team mit Foto'), 'options' => ['layout' => 'cards', 'columns' => '4', 'ratio' => '3:4', 'image_fit' => 'cover',
                'fields' => array_values(array_filter(['_title', $img, $role])), 'link_detail' => ($t['settings']['route'] ?? '') !== '']];
        }
        if (Calendar::enabled($t)) {
            $start = (string) (Calendar::config($t)['start'] ?? '');
            if ($start !== '') $out[] = ['name' => __('Termine der Reihe nach'), 'options' => ['layout' => 'compact', 'sort_field' => $start, 'sort_dir' => 'asc', 'link_detail' => true]];
        }
        return $out;
    }

    /** Vorschläge einmal je Tabelle anlegen (lazy; gelöschte kommen nicht wieder). Nur Inhalts-Tabellen der Datenliste. */
    public static function seed(array $t, string $type = 'data_list'): int
    {
        if ($type !== 'data_list' || \Core\Data\Placement::blockType($t) !== 'data_list') return 0;
        $mk = $type . '|' . $t['handle'];
        try {
            $done = (array) (app()->settings->get(self::SEEDED) ?: []);
            if (!empty($done[$mk])) return 0;
            if (app()->db->fetchValue('SELECT COUNT(*) FROM list_templates WHERE table_handle = ? AND block = ?', [$t['handle'], $type])) {
                $done[$mk] = true;
                app()->settings->set(self::SEEDED, $done);
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }
        $def = app()->theme->block($type);
        if (!$def) return 0;
        $n = 0;
        foreach (self::suggestions($t) as $s) {
            // Über das Feldschema prüfen (mit Präfix wie im Block), damit nur gültige Werte gespeichert werden
            $pre = [];
            $byName = array_column(array_filter((array) $def['fields'], fn($f) => isset($f['name'])), null, 'name');
            foreach ($s['options'] as $k => $v) $pre[$k] = self::prefix($v, $byName[$k] ?? [], $t['handle']);
            self::create($t, $type, $s['name'], array_intersect_key(self::optionsFrom($pre, $def, $t), $s['options']));
            $n++;
        }
        $done[$mk] = true;
        app()->settings->set(self::SEEDED, $done);
        return $n;
    }

    // ------------------------------------------------------------------ Darstellung (Verwaltung, Seitenleiste)

    /** Vorschaubild der Grundform als SVG (nur Formen, Farben aus currentColor/Klassen; aria-hidden) */
    public static function preview(string $base): string
    {
        $r = fn(float $x, float $y, float $w, float $h, string $c = 'b', int $rx = 2) => '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="' . $rx . '" class="ltpl-svg__' . $c . '"/>';
        $s = '';
        switch ($base) {
            case 'list':
                foreach ([4, 22, 40] as $y) $s .= $r(4, $y, 18, 14, 'i') . $r(26, $y + 2, 34, 4, 't') . $r(26, $y + 9, 26, 3);
                break;
            case 'compact':
                foreach ([6, 17, 28, 39, 50] as $y) $s .= $r(4, $y, 4, 4, 'i', 1) . $r(12, $y, 48, 4, 't', 1);
                break;
            case 'table':
                $s .= $r(4, 6, 56, 7, 'i', 1);
                foreach ([17, 27, 37, 47] as $y) $s .= $r(4, $y, 18, 4, 't', 1) . $r(26, $y, 16, 4, 'b', 1) . $r(46, $y, 14, 4, 'b', 1);
                break;
            case 'directory':
                $s .= $r(4, 4, 56, 6, 'b', 3);
                foreach ([4, 23, 42] as $x) $s .= $r($x, 14, 18, 18, 'i') . $r($x, 35, 14, 3, 't') . $r($x, 41, 10, 4, 'c', 2);
                foreach ([4, 23, 42] as $x) $s .= $r($x, 49, 18, 10, 'i');
                break;
            case 'custom':
                $s .= $r(4, 4, 56, 22, 'i') . $r(4, 30, 26, 4, 't') . $r(4, 38, 56, 3) . $r(4, 44, 40, 3) . $r(4, 52, 18, 6, 'c', 3);
                break;
            default:   // cards
                foreach ([4, 23, 42] as $x) $s .= $r($x, 6, 18, 14, 'i') . $r($x, 24, 16, 4, 't') . $r($x, 31, 18, 3) . $r($x, 36, 12, 3);
                foreach ([4, 23, 42] as $x) $s .= $r($x, 44, 18, 14, 'i');
        }
        return '<svg class="ltpl-svg" viewBox="0 0 64 62" aria-hidden="true" focusable="false">' . $s . '</svg>';
    }

    /** Beschriftung der Grundform */
    public static function baseLabel(string $base): string
    {
        return match ($base) {
            'list' => __('Liste mit Bild'), 'compact' => __('Kompakte Liste'), 'table' => __('Tabelle'), 'directory' => __('Verzeichnis'),
            'custom' => __('Frei gestaltet'), default => __('Karten mit Bild'),
        };
    }

    /**
     * Feld „Vorlage“ in der Seitenleiste: Kacheln mit Vorschaubild je Vorlage (alle Tabellen, das Skript zeigt die der gewählten Tabelle –
     * resources/js/_listtpl.js), „Eigene Einstellungen“ (leer) zuerst. Ohne JavaScript: Optionsfelder mit Beschriftung.
     * Mit Recht design.blocks: „Als Vorlage speichern“ (aus den aktuellen Einstellungen des Blocks) und Link zur Verwaltung.
     */
    public static function renderInput(array $f, string $inputName, string $value, string $label, string $help, ?string $table = null): string
    {
        $type = (string) ($f['block'] ?? 'data_list');
        $id = 'ltpl-' . substr(md5($inputName), 0, 8);
        $manage = can(self::PERMISSION);
        $tile = function (string $key, string $name, string $base, string $tbl, bool $isDefault, string $opts = '') use ($inputName, $value, $id): string {
            $rid = $id . '-' . substr(md5($tbl . '|' . $key), 0, 8);
            return '<label class="ltpl-tile" for="' . e($rid) . '"' . ($tbl !== '' ? ' data-table="' . e($tbl) . '"' : '') . ($isDefault ? ' data-default' : '') . '>'
                . '<input type="radio" id="' . e($rid) . '" name="' . $inputName . '" value="' . e($key) . '"' . ($key === $value && ($tbl === '' || $key !== '') ? ' checked' : '')
                . ($opts !== '' ? ' data-options="' . e($opts) . '"' : '') . '>'
                . ($key === '' ? '<span class="ltpl-tile__own" aria-hidden="true">' . icon('sliders-horizontal') . '</span>' : self::preview($base))
                . '<span class="ltpl-tile__name">' . e($name) . '</span>'
                . ($isDefault ? '<span class="ltpl-tile__std">' . e(__('Standard')) . '</span>' : '') . '</label>';
        };
        $h = '<fieldset class="f f--ltpl" data-ltpl data-block="' . e($type) . '"' . ($table === null || $table === '' ? ' data-new' : '')
            . ($manage ? ' data-save="' . e(url('/admin/api/list-templates')) . '"' : '') . '><legend>' . $label . '</legend>'
            . '<div class="ltpl-grid">' . $tile('', __('Eigene Einstellungen'), '', '', false);
        $def = app()->theme->block($type) ?? [];
        foreach (Tables::content() as $t) {
            if (\Core\Data\Placement::blockType($t) !== 'data_list' && $type === 'data_list') continue;
            $list = self::forTable($t, $type, $table === $t['handle']);   // Vorschläge der gewählten Tabelle beim ersten Öffnen
            $std = self::defaultKey($t, $type);
            foreach ($list as $k => $tpl) {
                $opts = (string) json_encode(self::apply([], $tpl, $def, $t), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $h .= $tile($k, $tpl['name'], $tpl['base'], $t['handle'], $k === $std, $opts);
            }
        }
        $h .= '</div>';
        // Gewählte Vorlage gibt es nicht (mehr): Hinweis, eigene Werte gelten
        if ($value !== '' && $table !== null && ($t = Tables::findContent($table)) && !self::find($t, $value, $type)) {
            $h .= '<p class="f-help f-warn">' . e(__('Die gewählte Vorlage gibt es nicht mehr – es gelten die eigenen Einstellungen dieses Blocks.')) . '</p>';
        }
        $h .= '<div class="ltpl-actions">'
            . '<button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-ltpl-detach hidden>' . icon('sliders-horizontal') . ' ' . e(__('Anpassen (Vorlage lösen)')) . '</button>';
        if ($manage) {
            $h .= '<details class="ltpl-save" data-ltpl-savebox><summary class="adm-btn adm-btn--small adm-btn--ghost">' . icon('floppy-disk') . ' ' . e(__('Als Vorlage speichern …')) . '</summary>'
                . '<div class="ltpl-save__row"><label class="adm-sr" for="' . $id . '-name">' . e(__('Name der Vorlage')) . '</label>'
                . '<input type="text" id="' . $id . '-name" maxlength="80" placeholder="' . e(__('Name, z. B. Neueste drei')) . '" data-ltpl-name>'
                . '<button type="button" class="adm-btn adm-btn--small" data-ltpl-save>' . e(__('Speichern')) . '</button></div>'
                . '<p class="f-help">' . e(__('Speichert die Darstellung dieses Blocks (Felder, Spalten, Bild, Sortierung, Besucher-Filter) als Vorlage der gewählten Tabelle und wählt sie hier aus.')) . '</p></details>'
                . '<a class="ltpl-manage" href="' . e(url('/admin/data')) . '" data-ltpl-manage target="_blank" rel="noopener">' . e(__('Vorlagen verwalten')) . ' ↗</a>';
        }
        return $h . '</div>' . $help . '</fieldset>';
    }

    // ------------------------------------------------------------------ Selbsttest

    /**
     * Selbsttest (php bin/console listtemplates:selftest): Anwenden, Übernehmen aus Block, Standard, Rechte, unveränderte Blöcke ohne
     * Vorlage, Kit-Vorlagen. Arbeitet in einer Transaktion und nimmt alles zurück.
     * @return array{ok: int, fails: list<string>}
     */
    public static function selfTest(): array
    {
        $ok = 0; $fails = [];
        $eq = function (string $label, mixed $got, mixed $want) use (&$ok, &$fails): void {
            if ($got === $want) $ok++; else $fails[] = $label . ': erwartet ' . json_encode($want, JSON_UNESCAPED_UNICODE) . ', erhalten ' . json_encode($got, JSON_UNESCAPED_UNICODE);
        };
        $theme = app()->theme;
        $def = $theme->block('data_list');
        if (!$def) return ['ok' => 0, 'fails' => ['Kit ohne Block data_list']];
        $eq('data_list nimmt teil', self::supports($def), true);
        $names = array_column($def['fields'], 'name');
        $eq('Feld „Vorlage“ eingefügt', in_array('template', $names, true), true);
        $eq('Feld „Vorlage“ nach „Anzahl“', $names[array_search('limit', $names, true) + 1] ?? null, 'template');
        $keys = self::keys($def);
        foreach (['layout', 'fields', 'columns', 'ratio', 'visitor_filter', 'sort_field', 'link_detail'] as $k) $eq("Vorlagen-Feld $k", in_array($k, $keys, true), true);
        foreach (self::BLOCK_KEYS as $k) $eq("Blockfeld $k bleibt beim Block", in_array($k, $keys, true), false);
        $head = array_values(array_filter($def['fields'], fn($f) => ($f['type'] ?? '') === 'heading' && ($f['label'] ?? '') === 'Darstellung'))[0] ?? [];
        $eq('Abschnitt „Darstellung“ nur ohne Vorlage sichtbar', $head['show_if']['template'] ?? null, ['']);
        $sort = array_values(array_filter($def['fields'], fn($f) => ($f['name'] ?? '') === 'sort_field'))[0] ?? [];
        $eq('Sortierung (gemischter Abschnitt) nur ohne Vorlage', $sort['show_if']['template'] ?? null, ['']);
        $flt = array_values(array_filter($def['fields'], fn($f) => ($f['name'] ?? '') === 'filter_field'))[0] ?? [];
        $eq('Filter „Nur Einträge“ immer sichtbar', isset($flt['show_if']), false);
        $hl = array_values(array_filter($def['fields'], fn($f) => ($f['name'] ?? '') === 'highlight_field'))[0] ?? [];
        $eq('Bestehende Bedingung bleibt (Tabelle)', $hl['show_if']['layout'] ?? null, ['table']);

        $db = app()->db;
        $db->query('BEGIN');
        $memoSettings = app()->settings->get(self::SEEDED);
        try {
            $handle = 'lt_selftest_' . bin2hex(random_bytes(3));
            $t = ['handle' => $handle, 'name' => 'Selbsttest', 'fields' => [['name' => 'titel', 'type' => 'text', 'label' => 'Titel'],
                ['name' => 'bild', 'type' => 'media', 'label' => 'Bild'], ['name' => 'kategorie', 'type' => 'select', 'label' => 'Kategorie', 'options' => ['a' => 'A']],
                ['name' => 'funktion', 'type' => 'text', 'label' => 'Funktion']], 'settings' => ['title_field' => 'titel', 'route' => 'x', 'purpose' => 'content']];
            // Übernehmen aus einem Block: nur Vorlagen-Felder, ohne Präfix, fremde Tabellen-Felder fallen weg
            $block = ['title' => 'Aktuelles', 'table' => $handle, 'limit' => 3, 'layout' => 'directory', 'columns' => '4', 'ratio' => '1:1',
                'fields' => [$handle . '.titel', $handle . '.bild', 'andere.feld'], 'visitor_filter' => $handle . '.kategorie', 'filter_field' => $handle . '.kategorie', 'az_index' => true];
            $opts = self::optionsFrom($block, $def, $t);
            $eq('Übernehmen: Darstellung', $opts['layout'] ?? null, 'directory');
            $eq('Übernehmen: Felder ohne Präfix', $opts['fields'] ?? null, ['titel', 'bild']);
            $eq('Übernehmen: Filterfeld ohne Präfix', $opts['visitor_filter'] ?? null, 'kategorie');
            $eq('Übernehmen: Blockfelder nicht in der Vorlage', array_intersect_key($opts, array_flip(['title', 'table', 'limit', 'filter_field'])), []);
            $key = self::create($t, 'data_list', 'Verzeichnis groß', $opts);
            $eq('Schlüssel aus dem Namen', $key, 'verzeichnis-gross');
            $eq('Zweiter Schlüssel eindeutig', self::create($t, 'data_list', 'Verzeichnis groß', $opts), 'verzeichnis-gross-2');
            // Anwenden: Vorlage überschreibt Darstellung, Block behält Inhalt
            $tpl = self::find($t, $key);
            $base = ['title' => 'Neu', 'table' => $handle, 'limit' => 6, 'layout' => 'cards', 'columns' => '2', 'filter_field' => $handle . '.kategorie', 'filter_value' => 'a', 'template' => $key];
            $res = $tpl ? self::apply($base, $tpl, $def, $t) : [];
            $eq('Anwenden: Darstellung aus der Vorlage', $res['layout'] ?? null, 'directory');
            $eq('Anwenden: Spalten aus der Vorlage', $res['columns'] ?? null, '4');
            $eq('Anwenden: Felder mit Präfix', $res['fields'] ?? null, [$handle . '.titel', $handle . '.bild']);
            $eq('Anwenden: Filter mit Präfix', $res['visitor_filter'] ?? null, $handle . '.kategorie');
            $eq('Anwenden: Überschrift bleibt', $res['title'] ?? null, 'Neu');
            $eq('Anwenden: Anzahl bleibt', $res['limit'] ?? null, 6);
            $eq('Anwenden: Filter „Nur Einträge“ bleibt', [$res['filter_field'] ?? null, $res['filter_value'] ?? null], [$handle . '.kategorie', 'a']);
            $eq('Anwenden: fehlende Option = Standard', $res['table_sort'] ?? null, false);
            // Ohne Vorlage: Daten unverändert (bestehende Blöcke)
            $plain = ['title' => 'X', 'table' => $handle, 'layout' => 'list', 'columns' => '2', 'template' => ''];
            $eq('Ohne Vorlage unverändert', self::resolve($plain, $def, 'data_list'), $plain);
            $eq('Unbekannte Vorlage: eigene Werte', self::apply($plain, ['options' => []] + ($tpl ?? []), $def, $t)['layout'] ?? null, 'cards');
            $gone = ['template' => 'gibt-es-nicht'] + $plain;
            $eq('Gelöschte Vorlage: eigene Werte', self::resolve($gone, $def, 'data_list'), $gone);
            // Standard je Tabelle
            $eq('Kein Standard am Anfang', self::defaultKey($t), null);
            self::setDefault($t, 'data_list', $key);
            $eq('Standard gesetzt', self::defaultKey($t), $key);
            self::setDefault($t, 'data_list', 'verzeichnis-gross-2');
            $eq('Nur ein Standard je Tabelle', (int) $db->fetchValue('SELECT COUNT(*) FROM list_templates WHERE table_handle = ? AND is_default = 1', [$handle]), 1);
            self::setDefault($t, 'data_list', null);
            $eq('Standard entfernt', self::defaultKey($t), null);
            // Ändern: Schlüssel bleibt
            self::update($t, 'data_list', $key, 'Verzeichnis', ['layout' => 'table'] + $opts);
            $eq('Ändern: neuer Name, gleicher Schlüssel', self::find($t, $key)['name'] ?? null, 'Verzeichnis');
            $eq('Ändern: Grundform folgt der Darstellung', self::find($t, $key)['base'] ?? null, 'table');
            // Vorschläge (Felder der Tabelle entscheiden)
            $sug = array_column(self::suggestions($t), 'name');
            $eq('Vorschlag Verzeichnis (Auswahlfeld vorhanden)', in_array(__('Verzeichnis A–Z mit Filter'), $sug, true), true);
            $eq('Vorschlag Team (Bild + Funktion)', in_array(__('Team mit Foto'), $sug, true), true);
            $t2 = ['fields' => [['name' => 'titel', 'type' => 'text', 'label' => 'Titel']]] + $t;
            $eq('Ohne Bild/Auswahl: nur Karten, Kompakt, Tabelle', count(self::suggestions($t2)), 3);
            // Kit-Vorlagen (theme.php → list_templates)
            self::$kitOverride = ['gross' => ['name' => 'Groß', 'options' => ['layout' => 'cards', 'columns' => '2'], 'default' => true],
                'nur_andere' => ['name' => 'Andere', 'tables' => ['gibt_es_nicht'], 'options' => []],
                'mit_datum' => ['name' => 'Mit Datum', 'requires' => ['date'], 'options' => []],
                'Ungültig!' => ['name' => 'x', 'options' => []]];
            self::$memo = [];
            $list = self::forTable($t);
            $eq('Kit-Vorlage mit Schlüssel kit:…', isset($list['kit:gross']), true);
            $eq('Kit-Vorlage nur für ihre Tabellen', isset($list['kit:nur_andere']), false);
            $eq('Kit-Vorlage nur mit verlangten Feldtypen', isset($list['kit:mit_datum']), false);
            $eq('Kit-Vorlage mit ungültigem Schlüssel fehlt', count(array_filter(array_keys($list), fn($k) => str_starts_with($k, 'kit:'))), 1);
            $eq('Kit-Vorlage nur lesbar', $list['kit:gross']['locked'] ?? null, true);
            $eq('Kit-Standard, wenn die Website keinen hat', self::defaultKey($t), 'kit:gross');
            self::setDefault($t, 'data_list', $key);
            $eq('Standard der Website vor Kit-Standard', self::defaultKey($t), $key);
            self::$kitOverride = null;
            self::$memo = [];
            // Löschen gesperrt, solange ein Block die Vorlage verwendet (Seite im Entwurf)
            $pid = $db->insert('pages', ['slug' => $handle, 'title' => 'Selbsttest', 'status' => 'draft', 'is_home' => 0, 'sort' => 0, 'noindex' => 1,
                'content_draft' => json_encode(['blocks' => [['id' => 'x1', 'type' => 'data_list', 'data' => ['table' => $handle, 'template' => $key]]]]), 'content_published' => '']);
            $eq('Verwendung gezählt', array_keys(self::usage($t)[$key] ?? []), [$pid]);
            $eq('Löschen bei Verwendung abgelehnt', self::delete($t, 'data_list', $key), false);
            $eq('Unbenutzte Vorlage löschbar', self::delete($t, 'data_list', 'verzeichnis-gross-2'), true);
            // Sanitize: Feldtyp listtemplate
            [$clean] = Fields::sanitize([self::field('data_list')], ['template' => 'kit:gross']);
            $eq('Feldwert kit:… erlaubt', $clean['template'], 'kit:gross');
            [$clean] = Fields::sanitize([self::field('data_list')], ['template' => '<script>']);
            $eq('Ungültiger Feldwert verworfen', $clean['template'], '');
        } catch (\Throwable $e) {
            $fails[] = 'Ausnahme: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        } finally {
            $db->query('ROLLBACK');
            self::$kitOverride = null;
            self::$memo = [];
        }
        // Rechte: Recht im Katalog, Administration hat es, Redaktion (Standardrolle) nicht
        $eq('Recht design.blocks im Katalog', in_array(self::PERMISSION, \Core\Permissions::all(), true), true);
        $eq('Administration darf Vorlagen verwalten', \Core\Permissions::allows(['permissions' => ['*'], 'tables' => null], self::PERMISSION), true);
        $eq('Redaktion darf es nicht von selbst', \Core\Permissions::allows(['permissions' => \Core\Permissions::defaults()['editor']['permissions'], 'tables' => null], self::PERMISSION), false);
        $eq('Mit dem Recht erlaubt', \Core\Permissions::allows(['permissions' => [self::PERMISSION], 'tables' => null], self::PERMISSION), true);
        unset($memoSettings);
        return ['ok' => $ok, 'fails' => $fails];
    }
}
