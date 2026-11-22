<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Layout;
use Core\PageCache;
use Core\Pages;
use Core\Redirects\Redirects;

/**
 * Seitenzweig → Wissensbereich (php bin/console data:import-pages <pfad|id> [--table=…] [--apply] | --revert): die Unterseiten einer
 * Seite werden Kapitel einer Tabelle der Art „Wissen“ (Core\Data\Knowledge). Standard ist ein Probelauf mit Plan.
 *
 *  - Tabelle: vorhandene Wissenstabelle (--table) oder neu aus der Vorlage „Wissen“ mit der Adresse des Zweigs – so behalten die
 *    Kapitel der ersten Ebene ihre Adresse (/wissen/kapitel), tiefere bekommen eine 301-Weiterleitung.
 *  - Je Unterseite ein Eintrag: Titel (Menütitel, sonst Titel), Adresse (Slug, bei Gleichstand -2 …), Status, Oberkapitel, Reihenfolge wie
 *    im Seitenbaum, Anreißer = Meta-Beschreibung (sonst Text des Seitenkopfs), Inhalt = Blöcke der veröffentlichten Fassung (Spalten aufgelöst, nur Blöcke, die in
 *    Inhaltsblöcken erlaubt sind – der Plan nennt ausgelassene).
 *  - Die Seiten wandern offline unter eine versteckte Archivseite (Entwurf) – ihr Inhalt bleibt; Links page:ID in Seiten, Einstellungen
 *    und den neuen Kapiteln zeigen auf die Einträge. Die Startseite des Zweigs bekommt die Datenliste „Kapitel“ (Suche, Filter).
 *  - --revert dreht alles zurück (Einträge, Weiterleitungen, Links, Seitenbaum, Startseite; eine neu angelegte Tabelle wird gelöscht).
 *    Der Stand liegt in der Einstellung sys.knowledge_import (je Startseite).
 */
final class KnowledgeImport
{
    public const SET = 'sys.knowledge_import';

    /** Wurzelseite aus Pfad oder ID */
    public static function root(string $ref): ?array
    {
        $ref = trim($ref, '/');
        return ctype_digit($ref) ? Pages::find((int) $ref) : Pages::byPath($ref);
    }

    /**
     * Plan: Tabelle, Kapitel (Seite → Slug, Oberkapitel, Adresse alt/neu), ausgelassene Blocktypen, Hinweise.
     * @return array{ok: bool, errors: list<string>, root: ?array, table: ?array, handle: string, route: string, create: bool, items: list<array>, notes: list<string>}
     */
    public static function plan(array $root, string $handle = ''): array
    {
        $errors = [];
        $notes = [];
        $route = trim((string) ($root['path'] ?? $root['slug']), '/');
        $handle = Tables::normName($handle !== '' ? $handle : (string) $root['slug']);
        $t = Tables::find($handle);
        $create = $t === null;
        if ($t && !Knowledge::is($t)) $errors[] = __('Die Tabelle „{h}“ ist keine Wissenstabelle – bitte --table=… mit einer Tabelle der Art „Wissen“ oder einem neuen Kurznamen.', ['h' => $handle]);
        if ($t && (string) $t['settings']['route'] !== '' && $t['settings']['route'] !== $route) $notes[] = __('Die Tabelle hat die Adresse /{r} – die Kapitel erscheinen dort, alle alten Adressen bekommen eine Weiterleitung.', ['r' => $t['settings']['route']]);
        if ($create && ($other = Tables::byRoute($route))) $errors[] = __('Unter /{r} liegt schon die Tabelle „{n}“.', ['r' => $route, 'n' => $other['name']]);
        if (isset(self::state()[(string) $root['id']])) $errors[] = __('Dieser Zweig wurde schon übernommen – erst zurückdrehen (--revert).');
        $route = $t ? ((string) $t['settings']['route'] ?: $route) : $route;
        $allowed = array_flip(EntryBlocks::allowed(['blocks' => []]));
        $items = [];
        $slugs = $t ? array_map('strval', array_column(Tables::db($t)->fetchAll("SELECT slug FROM {$t['table']}"), 'slug')) : [];
        $walk = function (int $parentId, ?int $parentPage, int $depth) use (&$walk, &$items, &$slugs, $allowed, $route, &$notes): void {
            foreach (Pages::db()->fetchAll("SELECT * FROM pages WHERE parent_id = ? AND type = 'page' ORDER BY sort, id", [$parentId]) as $p) {
                $blocks = Layout::flatten(Pages::blocks($p, $p['content_published'] === null));
                $keep = [];
                $dropped = [];
                foreach ($blocks as $b) {
                    if (isset($allowed[(string) ($b['type'] ?? '')])) $keep[] = $b;
                    elseif (($b['type'] ?? '') !== 'layout') $dropped[] = (string) ($b['type'] ?? '?');
                }
                $slug = Pages::slugify((string) $p['slug']) ?: 'kapitel';
                $base = $slug;
                for ($i = 2; in_array($slug, $slugs, true); $i++) $slug = $base . '-' . $i;
                $slugs[] = $slug;
                $old = '/' . trim((string) ($p['path'] ?? $p['slug']), '/');
                $new = '/' . $route . '/' . $slug;
                $items[] = ['page' => (int) $p['id'], 'parent_page' => $parentPage, 'depth' => $depth, 'title' => (string) (($p['nav_title'] ?? '') ?: $p['title']),
                    'slug' => $slug, 'status' => $p['status'] === 'published' ? 'published' : 'draft', 'old' => $old, 'new' => $new,
                    // Anreißer: Meta-Beschreibung, sonst der Text des Seitenkopfs (der Kopf selbst entfällt – Titel steht in der Detailvorlage)
                    'teaser' => trim((string) ($p['meta_description'] ?? '')) ?: fmt()->excerpt((string) (\Core\Blocks\SubpageList::hero($p)['text'] ?? ''), 300), 'blocks' => $keep, 'dropped' => $dropped, 'menu' => (int) $p['menu'],
                    'sort' => (int) $p['sort'], 'parent_id' => (int) $p['parent_id'], 'published_at' => $p['published_at'], 'pslug' => (string) $p['slug']];
                if ($depth >= 6) { $notes[] = __('Sehr tiefe Verschachtelung bei „{t}“ – tiefer als sechs Ebenen wird nicht übernommen.', ['t' => $p['title']]); continue; }
                $walk((int) $p['id'], (int) $p['id'], $depth + 1);
            }
        };
        $walk((int) $root['id'], null, 0);
        if (!$items) $errors[] = __('Die Seite „{t}“ hat keine Unterseiten.', ['t' => $root['title']]);
        if (!Redirects::enabled() && array_filter($items, fn($i) => $i['old'] !== $i['new'])) $notes[] = __('Funktion „Weiterleitungen“ ist aus – geänderte Adressen bekommen keine 301 (bitte einschalten und erneut ausführen).');
        return ['ok' => !$errors, 'errors' => $errors, 'root' => $root, 'table' => $t, 'handle' => $handle, 'route' => $route, 'create' => $create, 'items' => $items, 'notes' => $notes];
    }

    /** Bericht des Plans (Zeilen) */
    public static function report(array $plan): array
    {
        $l = [];
        $l[] = __('Zweig „{t}“ (/{p}) → Tabelle „{h}“{c} unter /{r}/…', ['t' => $plan['root']['title'], 'p' => trim((string) ($plan['root']['path'] ?? ''), '/'),
            'h' => $plan['handle'], 'c' => $plan['create'] ? ' (' . __('neu, Vorlage „Wissen“') . ')' : '', 'r' => $plan['route']]);
        foreach ($plan['items'] as $i) {
            $l[] = str_repeat('  ', $i['depth'] + 1) . '+ ' . $i['title'] . ' [' . $i['status'] . '] ' . $i['old'] . ($i['old'] !== $i['new'] ? ' → ' . $i['new'] . ' (301)' : ' (' . __('gleiche Adresse') . ')')
                . ($i['dropped'] ? ' · ' . __('ausgelassen: {list}', ['list' => implode(', ', array_unique($i['dropped']))]) : '');
        }
        foreach ($plan['notes'] as $n) $l[] = '! ' . $n;
        foreach ($plan['errors'] as $e) $l[] = '✗ ' . $e;
        return $l;
    }

    /** Plan ausführen. @return list<string> */
    public static function apply(array $plan, ?int $userId = null): array
    {
        if (!$plan['ok']) return array_map(fn($e) => '✗ ' . $e, $plan['errors']);
        $log = [];
        $root = $plan['root'];
        $t = $plan['table'];
        if ($plan['create']) {
            $res = Templates::install('knowledge', ['handle' => $plan['handle'], 'name' => (string) $root['title'], 'slug' => $plan['route'], 'detail' => true], function (string $l) use (&$log) { $log[] = $l; });
            $t = $res['table'];
            if (!$t) return [...$log, '✗ ' . __('Tabelle konnte nicht angelegt werden.')];
        }
        $t = Tables::find($t['handle']);
        if (empty($t['settings']['detail_page_id'])) { \Core\Http\Controllers\Admin\DataController::makeTemplate($t); $t = Tables::find($t['handle']); }
        $st = ['table' => $t['handle'], 'created' => $plan['create'], 'entries' => [], 'pages' => [], 'redirects' => [], 'links' => [], 'archive' => null, 'root' => null, 'at' => now()];
        // 1. Einträge (Reihenfolge wie im Seitenbaum)
        $db = Tables::db($t);
        foreach ($plan['items'] as $n => $i) {
            $parent = $i['parent_page'] !== null ? ($st['entries'][(string) $i['parent_page']] ?? null) : null;
            [$id, $errors] = Entries::save($t, null, ['titel' => $i['title'], 'slug' => $i['slug'], 'status' => $i['status'], 'oberkapitel' => $parent,
                'anreisser' => $i['teaser'], 'inhalt' => $i['blocks']], __('Aus Seite #{id} übernommen', ['id' => $i['page']]));
            if ($errors || !$id) { $log[] = '✗ ' . $i['title'] . ': ' . implode(' ', array_map('strval', $errors)); continue; }
            $db->update($t['table'], array_filter(['sort' => ($n + 1) * 10, 'published_at' => $i['published_at'] ?: null]), 'id = :id', ['id' => (int) $id]);
            $st['entries'][(string) $i['page']] = (int) $id;
            $log[] = '✓ ' . $i['title'] . ' → ' . $i['new'];
        }
        // 2. Seiten offline unter eine versteckte Archivseite (frei werdende Adressen für die Kapitel)
        $archive = Pages::create(['slug' => '_archiv-' . Pages::slugify((string) $root['slug']), 'title' => $root['title'] . ' – ' . __('vor der Umstellung'), 'status' => 'draft',
            'menu' => 0, 'noindex' => 1, 'lang' => $root['lang'] ?? null]);
        $st['archive'] = $archive;
        foreach ($plan['items'] as $i) {
            if (!isset($st['entries'][(string) $i['page']])) continue;
            $st['pages'][(string) $i['page']] = ['parent_id' => $i['parent_id'], 'sort' => $i['sort'], 'status' => $i['status'], 'menu' => $i['menu']];
            Pages::db()->update('pages', array_filter(['status' => 'draft', 'menu' => 0, 'parent_id' => $i['depth'] === 0 ? $archive : null, 'updated_at' => now()], fn($v) => $v !== null),
                'id = :id', ['id' => $i['page']]);
        }
        self::movePaths();
        $log[] = '✓ ' . __('{n} Seiten offline unter „{t}“ (Entwurf, Inhalt bleibt)', ['n' => count($st['pages']), 't' => $root['title'] . ' – ' . __('vor der Umstellung')]);
        // 3. Links page:ID → entry:… (Seiten, Einstellungen, neue Kapitel)
        $map = [];
        foreach ($st['entries'] as $pid => $eid) $map['page:' . $pid] = 'entry:' . $t['handle'] . ':' . $eid;
        $st['links'] = self::relink($map, $t, $log);
        // 4. Weiterleitungen alter Adressen
        foreach ($plan['items'] as $i) {
            if (!isset($st['entries'][(string) $i['page']]) || $i['old'] === $i['new'] || !Redirects::enabled()) continue;
            $target = 'entry:' . $t['handle'] . ':' . $st['entries'][(string) $i['page']];
            if (Redirects::bySource($i['old'])) { $log[] = '= ' . __('Weiterleitung {s} gibt es schon', ['s' => $i['old']]); continue; }
            [$rid, $errors] = Redirects::save(['source' => $i['old'], 'target' => $target, 'code' => 301, 'note' => __('Wissensbereich „{t}“ übernommen', ['t' => $root['title']])], null, 'import');
            if ($errors) $log[] = '! ' . __('Weiterleitung {s}: {e}', ['s' => $i['old'], 'e' => implode(' ', array_map('strval', $errors))]);
            else { $st['redirects'][] = (int) $rid; $log[] = '✓ 301 ' . $i['old'] . ' → ' . $i['new']; }
        }
        // 5. Startseite des Zweigs: Datenliste „Kapitel“ (Suche, Filter, Inhaltsverzeichnis), falls noch keine
        $fresh = Pages::find((int) $root['id']);
        $hasList = str_contains((string) $fresh['content_draft'] . (string) $fresh['content_published'], '"table":"' . $t['handle'] . '"');
        if (!$hasList) {
            $st['root'] = ['draft' => $fresh['content_draft'], 'published' => $fresh['content_published']];
            $add = ['id' => bin2hex(random_bytes(5)), 'type' => 'data_list', 'data' => ['title' => ''] + Knowledge::listBlock($t)];
            $upd = [];
            foreach (['content_draft', 'content_published'] as $col) {
                if ($fresh[$col] === null) continue;
                $j = json_decode((string) $fresh[$col], true) ?: ['blocks' => []];
                $j['blocks'] = Pages::sanitizeBlocks([...(array) ($j['blocks'] ?? []), $add]);
                $upd[$col] = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            Pages::db()->update('pages', $upd + ['updated_at' => now()], 'id = :id', ['id' => (int) $root['id']]);
            Pages::addRevision((int) $root['id'], (string) ($upd['content_draft'] ?? $upd['content_published']), $userId, __('Wissensbereich: Datenliste „Kapitel“ ergänzt'));
            $log[] = '✓ ' . __('Startseite „{t}“: Datenliste „Kapitel“ mit Suche und Filtern ergänzt', ['t' => $root['title']]);
        }
        $all = self::state();
        $all[(string) $root['id']] = $st;
        app()->settings->set(self::SET, $all);
        Knowledge::flush();
        PageCache::clear();
        $log[] = '✓ ' . __('Fertig. Zurückdrehen: data:import-pages {p} --revert', ['p' => trim((string) ($root['path'] ?? $root['slug']), '/')]);
        return $log;
    }

    /**
     * Links ersetzen ($map: alt → neu) in Seiten (Entwurf/veröffentlicht), Einstellungen und den Kapiteln der Tabelle.
     * @return list<array{kind: string, key: string}> geänderte Stellen
     */
    private static function relink(array $map, array $t, array &$log): array
    {
        if (!$map) return [];
        $changed = [];
        // Verweis in Anführungszeichen (JSON-Wert oder href im Rich-Text), auch mit Sprungmarke/Parametern: "page:7", "page:7#teil", \"page:7\"
        $rx = '~(?<=")(' . implode('|', array_map(fn($k) => preg_quote($k, '~'), array_keys($map))) . ')(?=["#?\\\\])~';
        $swap = fn(string $s) => (string) preg_replace_callback($rx, fn($m) => $map[$m[1]], $s);
        $first = array_key_first($map);
        $like = '%' . explode(':', (string) $first)[0] . ':%';
        // Weiterleitungen mit Ziel page:ID bzw. entry:… (Ziel ist der Verweis selbst)
        foreach ($map as $from => $to) {
            foreach (Pages::db()->fetchAll('SELECT id FROM redirects WHERE target = ?', [$from]) as $r) {
                Pages::db()->update('redirects', ['target' => $to, 'updated_at' => now()], 'id = :id', ['id' => (int) $r['id']]);
                $changed[] = ['kind' => 'redirect', 'key' => (string) $r['id']];
            }
        }
        foreach (Pages::db()->fetchAll('SELECT id, title, content_draft, content_published FROM pages WHERE content_draft LIKE ? OR content_published LIKE ?', [$like, $like]) as $p) {
            $upd = [];
            foreach (['content_draft', 'content_published'] as $col) {
                if ($p[$col] === null || !preg_match($rx, (string) $p[$col])) continue;
                $upd[$col] = $swap((string) $p[$col]);
            }
            if (!$upd) continue;
            Pages::db()->update('pages', $upd, 'id = :id', ['id' => (int) $p['id']]);
            $changed[] = ['kind' => 'page', 'key' => (string) $p['id']];
            $log[] = '~ ' . __('Links in Seite „{t}“ umgestellt', ['t' => $p['title']]);
        }
        foreach (Pages::db()->fetchAll('SELECT skey, value_json FROM settings WHERE value_json LIKE ?', [$like]) as $row) {
            if (!preg_match($rx, (string) $row['value_json'])) continue;
            Pages::db()->update('settings', ['value_json' => $swap((string) $row['value_json'])], 'skey = :k', ['k' => $row['skey']]);
            $changed[] = ['kind' => 'setting', 'key' => (string) $row['skey']];
        }
        $db = Tables::db($t);
        foreach ($db->fetchAll("SELECT id, inhalt FROM {$t['table']} WHERE inhalt LIKE ?", [$like]) as $row) {
            if (!preg_match($rx, (string) $row['inhalt'])) continue;
            $db->update($t['table'], ['inhalt' => $swap((string) $row['inhalt'])], 'id = :id', ['id' => (int) $row['id']]);
            $changed[] = ['kind' => 'entry', 'key' => (string) $row['id']];
        }
        return $changed;
    }

    /**
     * Pfade neu berechnen ohne automatische Weiterleitungen der verschobenen Seiten (Pages::rebuildPaths legt sonst alte Adresse → page:ID
     * an – hier übernehmen die Kapitel die Adressen, die Weiterleitungen setzt apply() selbst).
     */
    private static function movePaths(): void
    {
        $max = 0;
        try { $max = (int) Pages::db()->fetchValue('SELECT COALESCE(MAX(id), 0) FROM redirects'); } catch (\Throwable) { }
        Pages::rebuildPaths();
        try { Pages::db()->query("DELETE FROM redirects WHERE id > ? AND origin = 'auto'", [$max]); } catch (\Throwable) { }
    }

    /** Stand aller Übernahmen [Wurzel-ID => …] */
    public static function state(): array
    {
        return (array) app()->settings->get(self::SET, []);
    }

    /** Übernahme zurückdrehen. @return list<string> */
    public static function revert(array $root): array
    {
        $all = self::state();
        $st = $all[(string) $root['id']] ?? null;
        if (!$st) return ['✗ ' . __('Für diesen Zweig gibt es keine Übernahme.')];
        $log = [];
        $t = Tables::find((string) $st['table']);
        // Links zurück (entry:… → page:…) – vor dem Löschen der Einträge
        if ($t) {
            $map = [];
            foreach ((array) $st['entries'] as $pid => $eid) $map['entry:' . $t['handle'] . ':' . $eid] = 'page:' . $pid;
            self::relink($map, $t, $log);
        }
        if ($st['redirects']) { Redirects::delete((array) $st['redirects']); $log[] = '✓ ' . __('{n} Weiterleitungen entfernt', ['n' => count($st['redirects'])]); }
        foreach ((array) $st['pages'] as $pid => $p) {
            Pages::db()->update('pages', ['parent_id' => $p['parent_id'] ?: null, 'sort' => (int) $p['sort'], 'status' => (string) $p['status'], 'menu' => (int) $p['menu'], 'updated_at' => now()], 'id = :id', ['id' => (int) $pid]);
        }
        if (!empty($st['archive'])) Pages::db()->query('DELETE FROM pages WHERE id = ? AND parent_id IS NULL', [(int) $st['archive']]);
        self::movePaths();
        $log[] = '✓ ' . __('{n} Seiten wieder an ihrem Platz', ['n' => count((array) $st['pages'])]);
        if (!empty($st['root'])) {
            Pages::db()->update('pages', ['content_draft' => $st['root']['draft'], 'content_published' => $st['root']['published'], 'updated_at' => now()], 'id = :id', ['id' => (int) $root['id']]);
            $log[] = '✓ ' . __('Startseite des Zweigs wie vorher');
        }
        if ($t) {
            if (!empty($st['created'])) {
                Tables::delete($t);
                $log[] = '✓ ' . __('Tabelle „{n}“ gelöscht (war neu angelegt)', ['n' => $t['name']]);
            } else {
                foreach ((array) $st['entries'] as $eid) Entries::delete($t, (int) $eid);
                $log[] = '✓ ' . __('{n} Einträge entfernt', ['n' => count((array) $st['entries'])]);
            }
        }
        unset($all[(string) $root['id']]);
        app()->settings->set(self::SET, $all);
        Tables::flush();
        Knowledge::flush();
        PageCache::clear();
        return $log;
    }
}
