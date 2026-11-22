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
 *    im Seitenbaum, Anreißer = Meta-Beschreibung (sonst Text des Seitenkopfs), Bild = Bild des Seitenkopfs, Inhalt = Blöcke der
 *    veröffentlichten Fassung (Spalten aufgelöst). Erlaubte Blöcke: Feld „Inhalt“ der vorhandenen Tabelle bzw. --blocks/Standard; Blöcke
 *    der Seiten, die in Kapiteln wählbar sind (EntryBlocks::choices), schaltet der Import dort frei. Daten-Blöcke (data_list …) gehen nie.
 *  - --keep=ID|Slug|Pfad,…: diese Seiten bleiben Seiten und werden Kapitel vom Typ „Verweis“ (Feld verweis = page:ID) – der Plan schlägt
 *    Seiten mit nicht übertragbaren Blöcken dafür vor.
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

    /** Blocktypen, die nie in Kapitel übernommen werden: Seitenkopf (Titel/Bild/Anreißer kommen aus Feldern), Spalten (aufgelöst) */
    private const SKIP = ['hero', 'layout'];

    /**
     * Plan: Tabelle, erlaubte Blöcke des Feldes „Inhalt“ (vorhanden, --blocks bzw. Standard; Blöcke der Seiten, die in Kapiteln wählbar
     * sind, werden freigeschaltet), Kapitel (Seite → Slug, Oberkapitel, Adresse alt/neu), Verweis-Kapitel (--keep), nicht übertragbare
     * Blöcke mit Vorschlag für --keep, Hinweise. $o: keep (Liste ID|Slug|Pfad), blocks (Liste Blocktypen, nur beim Anlegen).
     * @return array{ok: bool, errors: list<string>, root: ?array, table: ?array, handle: string, route: string, create: bool, items: list<array>,
     *     notes: list<string>, allowed: list<string>, unlock: list<string>, suggest: list<string>}
     */
    public static function plan(array $root, string $handle = '', array $o = []): array
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
        // Erlaubte Blöcke: Feld „Inhalt“ der vorhandenen Tabelle, beim Anlegen --blocks bzw. der Standard (Inhalt und Medien)
        $choices = array_keys(EntryBlocks::choices());   // ohne Daten-Blöcke (data_list, Kalender …) – nie in Kapiteln
        $want = array_values(array_filter(array_map('trim', (array) ($o['blocks'] ?? [])), fn($x) => $x !== ''));
        if ($want && !$create) $notes[] = __('--blocks gilt nur beim Anlegen – die Tabelle „{h}“ behält ihre Auswahl (Blöcke der Seiten werden ergänzt).', ['h' => $handle]);
        foreach (array_diff($want, $choices) as $bad) $errors[] = __('--blocks: „{b}“ ist in Kapiteln nicht wählbar.', ['b' => $bad]);
        $base = $t && ($f = Tables::field($t, 'inhalt')) ? EntryBlocks::allowed($f) : ($want && $create ? array_values(array_intersect($choices, $want)) : Knowledge::contentBlocks());
        // Verweis-Kapitel: Seiten, die Seiten bleiben (--keep=ID|Slug|Pfad)
        $keepRefs = array_values(array_filter(array_map(fn($x) => trim((string) $x, " /"), (array) ($o['keep'] ?? [])), fn($x) => $x !== ''));
        $isKept = fn(array $p) => in_array((string) $p['id'], $keepRefs, true) || in_array((string) $p['slug'], $keepRefs, true) || in_array(trim((string) ($p['path'] ?? ''), '/'), $keepRefs, true);
        $items = [];
        $unlock = [];
        $suggest = [];
        $seenKeep = [];
        $slugs = $t ? array_map('strval', array_column(Tables::db($t)->fetchAll("SELECT slug FROM {$t['table']}"), 'slug')) : [];
        $walk = function (int $parentId, ?int $parentPage, int $depth) use (&$walk, &$items, &$slugs, &$unlock, &$suggest, &$seenKeep, $choices, $base, $isKept, $route, &$notes): void {
            foreach (Pages::db()->fetchAll("SELECT * FROM pages WHERE parent_id = ? AND type = 'page' ORDER BY sort, id", [$parentId]) as $p) {
                $keep = $isKept($p);
                if ($keep) $seenKeep[] = $p;
                $blocks = Layout::flatten(Pages::blocks($p, $p['content_published'] === null));
                $take = [];
                $foreign = [];   // nicht übertragbar (Daten-Blöcke, unbekannte)
                foreach ($blocks as $b) {
                    $type = (string) ($b['type'] ?? '');
                    if (in_array($type, self::SKIP, true)) continue;
                    if (in_array($type, $choices, true)) {
                        $take[] = $b;
                        if (!$keep && !in_array($type, $base, true) && !in_array($type, $unlock, true)) $unlock[] = $type;
                    } else {
                        $foreign[] = $type ?: '?';
                    }
                }
                if ($foreign && !$keep) $suggest[] = (string) $p['slug'];
                $hero = \Core\Blocks\SubpageList::hero($p);
                $slug = Pages::slugify((string) $p['slug']) ?: 'kapitel';
                $baseSlug = $slug;
                for ($i = 2; in_array($slug, $slugs, true); $i++) $slug = $baseSlug . '-' . $i;
                $slugs[] = $slug;
                $old = '/' . trim((string) ($p['path'] ?? $p['slug']), '/');
                $new = '/' . $route . '/' . $slug;
                $items[] = ['page' => (int) $p['id'], 'parent_page' => $parentPage, 'depth' => $depth, 'title' => (string) (($p['nav_title'] ?? '') ?: $p['title']),
                    'slug' => $slug, 'status' => $p['status'] === 'published' ? 'published' : 'draft', 'old' => $old, 'new' => $new, 'keep' => $keep,
                    // Anreißer: Meta-Beschreibung, sonst der Text des Seitenkopfs; Bild des Seitenkopfs → Bild des Eintrags (Kopf selbst entfällt)
                    'teaser' => trim((string) ($p['meta_description'] ?? '')) ?: fmt()->excerpt((string) ($hero['text'] ?? ''), 300),
                    'image' => (int) ($hero['image'] ?? 0), 'blocks' => $keep ? [] : $take, 'dropped' => array_values(array_unique($foreign)), 'menu' => (int) $p['menu'],
                    'sort' => (int) $p['sort'], 'parent_id' => (int) $p['parent_id'], 'published_at' => $p['published_at'], 'pslug' => (string) $p['slug']];
                if ($depth >= 6) { $notes[] = __('Sehr tiefe Verschachtelung bei „{t}“ – tiefer als sechs Ebenen wird nicht übernommen.', ['t' => $p['title']]); continue; }
                $walk((int) $p['id'], (int) $p['id'], $depth + 1);
            }
        };
        $walk((int) $root['id'], null, 0);
        if (!$items) $errors[] = __('Die Seite „{t}“ hat keine Unterseiten.', ['t' => $root['title']]);
        foreach ($keepRefs as $k) {
            if (!array_filter($seenKeep, fn($p) => in_array($k, [(string) $p['id'], (string) $p['slug'], trim((string) ($p['path'] ?? ''), '/')], true))) {
                $errors[] = __('--keep: „{k}“ ist keine Unterseite dieses Zweigs.', ['k' => $k]);
            }
        }
        if ($unlock) $notes[] = __('Feld „Inhalt“: zusätzlich erlaubt (kommen in den Seiten vor): {list}', ['list' => implode(', ', $unlock)]);
        if ($suggest) $notes[] = __('Vorschlag: Seiten mit Blöcken, die in Kapiteln nicht gehen, als Verweis behalten – --keep={list}', ['list' => implode(',', $suggest)]);
        if (!Redirects::enabled() && array_filter($items, fn($i) => !$i['keep'] && $i['old'] !== $i['new'])) $notes[] = __('Funktion „Weiterleitungen“ ist aus – geänderte Adressen bekommen keine 301 (bitte einschalten und erneut ausführen).');
        return ['ok' => !$errors, 'errors' => $errors, 'root' => $root, 'table' => $t, 'handle' => $handle, 'route' => $route, 'create' => $create, 'items' => $items, 'notes' => $notes,
            'allowed' => array_values(array_unique([...$base, ...$unlock])), 'unlock' => $unlock, 'suggest' => $suggest];
    }

    /** Bericht des Plans (Zeilen) */
    public static function report(array $plan): array
    {
        $l = [];
        $l[] = __('Zweig „{t}“ (/{p}) → Tabelle „{h}“{c} unter /{r}/…', ['t' => $plan['root']['title'], 'p' => trim((string) ($plan['root']['path'] ?? ''), '/'),
            'h' => $plan['handle'], 'c' => $plan['create'] ? ' (' . __('neu, Vorlage „Wissen“') . ')' : '', 'r' => $plan['route']]);
        foreach ($plan['items'] as $i) {
            $extra = [];
            if ($i['keep']) $extra[] = __('Verweis – die Seite bleibt');
            if ($i['image']) $extra[] = __('Bild aus dem Seitenkopf');
            if ($i['dropped']) $extra[] = ($i['keep'] ? __('Seite behält: {list}', ['list' => implode(', ', $i['dropped'])]) : __('nicht übertragbar: {list}', ['list' => implode(', ', $i['dropped'])]));
            $where = $i['keep'] ? $i['old'] . ' (' . __('bleibt Seite, Kapitel-Adresse leitet dorthin') . ')'
                : $i['old'] . ($i['old'] !== $i['new'] ? ' → ' . $i['new'] . ' (301)' : ' (' . __('gleiche Adresse') . ')');
            $l[] = str_repeat('  ', $i['depth'] + 1) . ($i['keep'] ? '↗ ' : '+ ') . $i['title'] . ' [' . $i['status'] . '] ' . $where . ($extra ? ' · ' . implode(' · ', $extra) : '');
        }
        $l[] = __('Feld „Inhalt“ erlaubt: {list}', ['list' => implode(', ', $plan['allowed'])]);
        foreach ($plan['notes'] as $n) $l[] = '! ' . $n;
        foreach ($plan['errors'] as $e) $l[] = '✗ ' . $e;
        return $l;
    }

    /** Erlaubte Blöcke des Feldes „Inhalt“ setzen. Gibt die bisherige Auswahl zurück (für --revert), null = unverändert */
    private static function setAllowed(array $t, array $allowed): ?array
    {
        $f = Tables::field($t, 'inhalt');
        if (!$f) return null;
        $before = array_values((array) ($f['blocks'] ?? []));
        if (EntryBlocks::allowed($f) === EntryBlocks::allowed(['blocks' => $allowed])) return null;
        $in = Tables::toInput($t);
        foreach ($in['fields'] as &$x) if (($x['name'] ?? '') === 'inhalt') $x['blocks'] = $allowed;
        unset($x);
        [$clean, $err] = Tables::validate($in, $t);
        if ($err) return null;
        Tables::update($t, $clean);
        Tables::flush();
        return $before;
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
        // Vorhandene Tabelle: Grundschema auf Version 2 (Bild, Verweis) – nur additiv, Einträge bleiben unverändert
        $t = Knowledge::upgrade(Tables::find($t['handle']));
        if (empty($t['settings']['detail_page_id'])) { \Core\Http\Controllers\Admin\DataController::makeTemplate($t); $t = Tables::find($t['handle']); }
        $st = ['table' => $t['handle'], 'created' => $plan['create'], 'entries' => [], 'kept' => [], 'pages' => [], 'redirects' => [], 'links' => [], 'archive' => null, 'root' => null,
            'blocks_before' => null, 'at' => now()];
        // 0. Erlaubte Blöcke des Feldes „Inhalt“ (freigeschaltete Blöcke der Seiten, --blocks)
        $before = self::setAllowed($t, $plan['allowed']);
        if ($before !== null) {
            $st['blocks_before'] = $before;
            $t = Tables::find($t['handle']);
            $log[] = '✓ ' . __('Feld „Inhalt“ erlaubt: {list}', ['list' => implode(', ', $plan['allowed'])]);
        }
        $hasImage = (bool) Tables::field($t, 'bild');
        // 1. Einträge (Reihenfolge wie im Seitenbaum); Verweis-Kapitel ohne Inhalt, mit Verweis auf die Seite
        $db = Tables::db($t);
        foreach ($plan['items'] as $n => $i) {
            $parent = $i['parent_page'] !== null ? ($st['entries'][(string) $i['parent_page']] ?? null) : null;
            $values = ['titel' => $i['title'], 'slug' => $i['slug'], 'status' => $i['status'], 'oberkapitel' => $parent, 'anreisser' => $i['teaser'], 'inhalt' => $i['blocks']];
            if ($hasImage && $i['image']) $values['bild'] = $i['image'];
            if ($i['keep']) $values['verweis'] = 'page:' . $i['page'];
            [$id, $errors] = Entries::save($t, null, $values, __('Aus Seite #{id} übernommen', ['id' => $i['page']]));
            if ($errors || !$id) { $log[] = '✗ ' . $i['title'] . ': ' . implode(' ', array_map('strval', $errors)); continue; }
            $db->update($t['table'], array_filter(['sort' => ($n + 1) * 10, 'published_at' => $i['published_at'] ?: null]), 'id = :id', ['id' => (int) $id]);
            $st['entries'][(string) $i['page']] = (int) $id;
            if ($i['keep']) $st['kept'][] = $i['page'];
            $log[] = '✓ ' . $i['title'] . ' → ' . ($i['keep'] ? __('Verweis auf {p}', ['p' => $i['old']]) : $i['new']);
        }
        // 2. Übernommene Seiten offline unter eine versteckte Archivseite (frei werdende Adressen); Verweis-Seiten bleiben, wo sie sind
        $archive = Pages::create(['slug' => '_archiv-' . Pages::slugify((string) $root['slug']), 'title' => $root['title'] . ' – ' . __('vor der Umstellung'), 'status' => 'draft',
            'menu' => 0, 'noindex' => 1, 'lang' => $root['lang'] ?? null]);
        $st['archive'] = $archive;
        $kept = array_map('intval', $st['kept']);
        foreach ($plan['items'] as $i) {
            if ($i['keep'] || !isset($st['entries'][(string) $i['page']])) continue;
            $st['pages'][(string) $i['page']] = ['parent_id' => $i['parent_id'], 'sort' => $i['sort'], 'status' => $i['status'], 'menu' => $i['menu']];
            $toArchive = $i['depth'] === 0 || in_array((int) $i['parent_page'], $kept, true);
            Pages::db()->update('pages', array_filter(['status' => 'draft', 'menu' => 0, 'parent_id' => $toArchive ? $archive : null, 'updated_at' => now()], fn($v) => $v !== null),
                'id = :id', ['id' => $i['page']]);
        }
        self::movePaths();
        $log[] = '✓ ' . __('{n} Seiten offline unter „{t}“ (Entwurf, Inhalt bleibt)', ['n' => count($st['pages']), 't' => $root['title'] . ' – ' . __('vor der Umstellung')]);
        // 3. Links page:ID → entry:… (Seiten, Einstellungen, neue Kapitel) – nicht für Verweis-Seiten, die bleiben erreichbar
        $map = [];
        foreach ($st['entries'] as $pid => $eid) if (!in_array((int) $pid, $kept, true)) $map['page:' . $pid] = 'entry:' . $t['handle'] . ':' . $eid;
        $st['links'] = self::relink($map, $t, $log);
        // 4. Weiterleitungen alter Adressen
        foreach ($plan['items'] as $i) {
            if ($i['keep'] || !isset($st['entries'][(string) $i['page']]) || $i['old'] === $i['new'] || !Redirects::enabled()) continue;
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
                if (is_array($st['blocks_before'] ?? null) && self::setAllowed($t, $st['blocks_before']) !== null) $log[] = '✓ ' . __('Feld „Inhalt“: erlaubte Blöcke wie vorher');
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
