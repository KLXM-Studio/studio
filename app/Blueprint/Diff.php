<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

use Core\Data\Tables;

/**
 * Struktur einer Website-Vorlage vergleichen und neue Versionen als Vorschlag ausrollen – wie Kit-Updates: nie Inhalte, nur
 * Struktur und nur hinzufügend (neue Tabellen, neue Felder, neue Seiten als Entwurf, neue Rollen, Listen-Vorlagen, eigene Blöcke).
 *
 *   signature($dir)         Fingerabdruck der Struktur eines Pakets (auch beim Anlegen in sys.blueprint.structure gemerkt)
 *   siteSignature()         derselbe Fingerabdruck der aktuellen Website (Selbsttest: Rundlauf Export → Anlegen → Vergleich)
 *   compare($alt, $neu)     was ist neu bzw. entfallen
 *   propose()/applyProposal()  Vorschlag in die Website legen (Einstellung sys.blueprint_update) bzw. übernehmen
 */
final class Diff
{
    public const PROPOSAL_KEY = 'sys.blueprint_update';
    public const PARTS = ['pages', 'tables', 'fields', 'roles', 'lists', 'blocks'];

    private static function pageKey(array $p): string
    {
        $where = ($p['type'] ?? 'page') === 'template' ? 'tpl:' . ($p['template_for'] ?? '') . ':' . ($p['slug'] ?? '') : (!empty($p['is_home']) ? '/' : '/' . trim((string) ($p['path'] ?: $p['slug']), '/'));
        return ($p['lang'] ?: '-') . ':' . $where;
    }

    /** Fingerabdruck aus Seiten, Tabellen, Rollen, Listen-Vorlagen und eigenen Blöcken */
    public static function build(array $pages, array $tables, array $roles, array $lists, array $blocks): array
    {
        $sig = ['pages' => [], 'tables' => [], 'roles' => [], 'lists' => [], 'blocks' => []];
        foreach ($pages as $p) $sig['pages'][self::pageKey($p)] = ['type' => (string) ($p['type'] ?? 'page'), 'menu' => (int) ($p['menu'] ?? 0), 'title' => (string) ($p['title'] ?? '')];
        foreach ($tables as $t) {
            $fields = [];
            foreach ((array) ($t['fields'] ?? []) as $f) if (isset($f['name'])) $fields[(string) $f['name']] = (string) ($f['type'] ?? 'text');
            $sig['tables'][(string) $t['handle']] = ['kind' => \Core\Data\Kinds::normalize((string) ($t['settings']['kind'] ?? 'content')), 'fields' => $fields];
        }
        foreach ($roles as $r) {
            $perms = json_decode((string) ($r['permissions_json'] ?? '[]'), true);
            $perms = is_array($perms) ? array_values(array_map('strval', $perms)) : [];
            sort($perms);
            $sig['roles'][(string) $r['rkey']] = $perms;
        }
        foreach ($lists as $l) $sig['lists'][] = $l['table_handle'] . ':' . $l['block'] . ':' . $l['tkey'];
        foreach ($blocks as $b) $sig['blocks'][] = (string) $b['bkey'];
        ksort($sig['pages']);
        ksort($sig['tables']);
        ksort($sig['roles']);
        sort($sig['lists']);
        sort($sig['blocks']);
        return $sig;
    }

    /** Fingerabdruck eines (geöffneten) Pakets */
    public static function signature(string $dir): array
    {
        return self::build((array) Package::read($dir, 'structure/pages.json'), (array) Package::read($dir, 'structure/tables.json'),
            (array) Package::read($dir, 'structure/roles.json'), (array) Package::read($dir, 'structure/list_templates.json'), (array) Package::read($dir, 'structure/custom_blocks.json'));
    }

    /** Fingerabdruck der aktuellen Website (eigene Tabellen, ohne admin/network) */
    public static function siteSignature(): array
    {
        $db = app()->db;
        $tables = [];
        foreach ($db->fetchAll('SELECT handle, fields_json, settings_json FROM data_tables') as $r) {
            $tables[] = ['handle' => $r['handle'], 'fields' => (array) json_decode((string) $r['fields_json'], true), 'settings' => (array) json_decode((string) $r['settings_json'], true)];
        }
        try {
            $blocks = $db->fetchAll('SELECT bkey FROM custom_blocks');
        } catch (\Throwable) {
            $blocks = [];
        }
        return self::build($db->fetchAll('SELECT * FROM pages'), $tables, $db->fetchAll("SELECT rkey, permissions_json FROM roles WHERE rkey NOT IN ('admin', 'network')"),
            $db->fetchAll('SELECT table_handle, block, tkey FROM list_templates'), $blocks);
    }

    /**
     * Unterschiede: added/removed je Teil (pages, tables, fields „tabelle.feld“, roles, lists, blocks), changed (Feldtyp, Rechte einer Rolle).
     * @return array{added: array<string, list<string>>, removed: array<string, list<string>>, changed: list<string>}
     */
    public static function compare(array $a, array $b): array
    {
        $out = ['added' => array_fill_keys(self::PARTS, []), 'removed' => array_fill_keys(self::PARTS, []), 'changed' => []];
        foreach (['pages', 'tables', 'roles'] as $part) {
            $out['added'][$part] = array_values(array_map('strval', array_keys(array_diff_key((array) ($b[$part] ?? []), (array) ($a[$part] ?? [])))));
            $out['removed'][$part] = array_values(array_map('strval', array_keys(array_diff_key((array) ($a[$part] ?? []), (array) ($b[$part] ?? [])))));
        }
        foreach (['lists', 'blocks'] as $part) {
            $out['added'][$part] = array_values(array_diff((array) ($b[$part] ?? []), (array) ($a[$part] ?? [])));
            $out['removed'][$part] = array_values(array_diff((array) ($a[$part] ?? []), (array) ($b[$part] ?? [])));
        }
        foreach ((array) ($b['tables'] ?? []) as $h => $t) {
            if (!isset($a['tables'][$h])) continue;
            $old = (array) $a['tables'][$h]['fields'];
            foreach ((array) $t['fields'] as $n => $type) {
                if (!isset($old[$n])) $out['added']['fields'][] = $h . '.' . $n;
                elseif ($old[$n] !== $type) $out['changed'][] = $h . '.' . $n . ': ' . $old[$n] . ' → ' . $type;
            }
            foreach (array_keys(array_diff_key($old, (array) $t['fields'])) as $n) $out['removed']['fields'][] = $h . '.' . $n;
        }
        foreach ((array) ($b['roles'] ?? []) as $k => $perms) if (isset($a['roles'][$k]) && $a['roles'][$k] !== $perms) $out['changed'][] = 'Rolle ' . $k;
        return $out;
    }

    /** Gibt es Unterschiede? */
    public static function any(array $d): bool
    {
        foreach (['added', 'removed'] as $k) foreach ((array) $d[$k] as $list) if ($list) return true;
        return (bool) $d['changed'];
    }

    /** Kurzfassung für Listen und Protokoll @return list<string> */
    public static function summary(array $d): array
    {
        $labels = ['pages' => __('Seiten'), 'tables' => __('Tabellen'), 'fields' => __('Felder'), 'roles' => __('Rollen'), 'lists' => __('Listen-Vorlagen'), 'blocks' => __('eigene Blöcke')];
        $out = [];
        foreach (self::PARTS as $p) {
            if ($d['added'][$p]) $out[] = '+ ' . $labels[$p] . ': ' . implode(', ', array_slice($d['added'][$p], 0, 8)) . (count($d['added'][$p]) > 8 ? ' …' : '');
            if ($d['removed'][$p]) $out[] = '− ' . $labels[$p] . ': ' . implode(', ', array_slice($d['removed'][$p], 0, 8)) . (count($d['removed'][$p]) > 8 ? ' …' : '');
        }
        foreach (array_slice($d['changed'], 0, 8) as $c) $out[] = '~ ' . $c;
        return $out;
    }

    /** Vorschlag in die aktuelle Website legen (vom Netzwerk ausgerollt) */
    public static function propose(string $name, string $version, string $package, array $diff): void
    {
        app()->settings->set(self::PROPOSAL_KEY, ['name' => $name, 'version' => $version, 'package' => $package, 'diff' => $diff, 'created_at' => date('c')]);
    }

    public static function proposal(): ?array
    {
        $p = app()->settings->get(self::PROPOSAL_KEY);
        return is_array($p) && !empty($p['version']) ? $p : null;
    }

    /**
     * Vorschlag übernehmen – nur Hinzufügendes, was die Website noch nicht hat: neue Tabellen, neue Felder bestehender Tabellen,
     * neue Seiten (als Entwurf, nicht im Menü), Rollen, Listen-Vorlagen, eigene Blöcke. Entfallenes und Geändertes bleibt (Hinweis).
     * @return list<string>
     */
    public static function applyProposal(): array
    {
        $p = self::proposal() ?? throw new \RuntimeException(__('Kein Vorschlag vorhanden.'));
        $pkg = Package::open((string) $p['package']);
        try {
            $dir = $pkg['dir'];
            $db = app()->db;
            $have = self::siteSignature();
            $lines = [];
            foreach ((array) Package::read($dir, 'structure/tables.json') as $t) {
                $h = (string) ($t['handle'] ?? '');
                if ($h === '' || !preg_match('~^[a-z][a-z0-9_]{0,62}$~', $h)) continue;
                if (!isset($have['tables'][$h])) {
                    if (Tables::find($h)) continue;   // geteilt bzw. gleichnamig
                    $newId = $db->insert('data_tables', ['handle' => $h, 'name' => (string) $t['name'], 'singular' => $t['singular'] ?? null, 'icon' => $t['icon'] ?? null,
                        'description' => (string) ($t['description'] ?? ''), 'fields_json' => json_encode((array) $t['fields'], JSON_UNESCAPED_UNICODE),
                        'settings_json' => json_encode(['detail_page_id' => null] + (array) $t['settings'], JSON_UNESCAPED_UNICODE), 'sort' => (int) ($t['sort'] ?? 0), 'created_at' => now(), 'updated_at' => now()]);
                    Tables::flush();
                    if ($nt = Tables::find($newId)) Tables::sync($nt);
                    $lines[] = __('Tabelle angelegt: {name}', ['name' => $t['name']]);
                    continue;
                }
                $cur = Tables::find($h);
                if (!$cur || Tables::isShared($cur)) continue;
                $names = array_column($cur['fields'], 'name');
                $new = array_values(array_filter((array) $t['fields'], fn($f) => isset($f['name']) && !in_array($f['name'], $names, true)));
                if (!$new) continue;
                $row = $db->fetch('SELECT fields_json FROM data_tables WHERE id = ?', [(int) $cur['id']]);
                $fields = array_merge((array) json_decode((string) ($row['fields_json'] ?? '[]'), true), $new);
                $db->update('data_tables', ['fields_json' => json_encode($fields, JSON_UNESCAPED_UNICODE), 'updated_at' => now()], 'id = :id', ['id' => (int) $cur['id']]);
                Tables::flush();
                if ($nt = Tables::find($h)) Tables::sync($nt);
                $lines[] = __('Neue Felder in {table}: {list}', ['table' => $cur['name'], 'list' => implode(', ', array_column($new, 'name'))]);
            }
            $cols = $db->columns('pages');
            foreach ((array) Package::read($dir, 'structure/pages.json') as $pg) {
                if (!is_array($pg) || isset($have['pages'][self::pageKey($pg)])) continue;
                unset($pg['id'], $pg['parent_id'], $pg['translation_group']);
                foreach (['content_draft', 'content_published'] as $c) if (is_array($pg[$c] ?? null)) $pg[$c] = json_encode($pg[$c], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $pg['content_draft'] = $pg['content_draft'] ?? $pg['content_published'] ?? null;
                $pg['content_published'] = null;
                $pg = ['status' => 'draft', 'menu' => 0, 'is_home' => 0, 'path' => $pg['slug'] ?? null, 'updated_at' => now(), 'published_at' => null] + $pg;
                $pg['status'] = 'draft';
                $pg['menu'] = 0;
                $pg['is_home'] = 0;
                $db->insert('pages', array_intersect_key($pg, array_flip($cols)));
                $lines[] = __('Seite als Entwurf angelegt: {title}', ['title' => $pg['title'] ?? '']);
            }
            foreach ((array) Package::read($dir, 'structure/roles.json') as $r) {
                if (!is_array($r) || isset($have['roles'][$r['rkey'] ?? '']) || in_array($r['rkey'] ?? '', ['admin', 'network'], true)) continue;
                if ($db->fetchValue('SELECT COUNT(*) FROM roles WHERE rkey = ?', [$r['rkey']])) continue;
                $db->insert('roles', array_intersect_key($r, array_flip($db->columns('roles'))));
                $lines[] = __('Rolle angelegt: {name}', ['name' => $r['name'] ?? $r['rkey']]);
            }
            foreach ((array) Package::read($dir, 'structure/list_templates.json') as $l) {
                if (!is_array($l) || in_array($l['table_handle'] . ':' . $l['block'] . ':' . $l['tkey'], $have['lists'], true)) continue;
                unset($l['id']);
                $db->insert('list_templates', array_intersect_key($l, array_flip($db->columns('list_templates'))));
                $lines[] = __('Listen-Vorlage angelegt: {name}', ['name' => $l['name'] ?? $l['tkey']]);
            }
            foreach ((array) Package::read($dir, 'structure/custom_blocks.json') as $b) {
                if (!is_array($b) || in_array($b['bkey'] ?? '', $have['blocks'], true)) continue;
                unset($b['id']);
                try {
                    $db->insert('custom_blocks', array_intersect_key($b, array_flip($db->columns('custom_blocks'))));
                    $lines[] = __('Eigener Block angelegt: {name}', ['name' => $b['label'] ?? $b['bkey']]);
                } catch (\Throwable) {
                }
            }
            $bp = (array) app()->settings->get(Apply::KEY, []);
            $bp['version'] = (string) $p['version'];
            $bp['structure'] = self::signature($dir);
            $bp['updated_at'] = date('c');
            app()->settings->set(Apply::KEY, $bp);
            app()->settings->set(self::PROPOSAL_KEY, null);
            \Core\PageCache::clear();
            Tables::flush();
            return $lines ?: [__('Nichts Neues zu übernehmen – die Website hat die Struktur schon.')];
        } finally {
            if ($pkg['temp']) Package::remove($pkg['dir']);
        }
    }
}
