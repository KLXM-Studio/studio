<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\PageCache;
use Core\Pages;

/**
 * Mehrere Detailseiten-Vorlagen je Tabelle (für alle Tabellen mit Detailseiten: Neuigkeiten, Termine, Wissen …).
 *
 *  - Vorlagen sind Seiten mit type = template und template_for = Kurzname der Tabelle. Die Standard-Vorlage steht wie bisher in
 *    settings.detail_page_id (bei geteilten Tabellen lokal je Website) – Tabellen mit einer Vorlage verhalten sich unverändert.
 *  - Zuordnung (Website-Einstellung data.detail_templates.{tabelle}, also je Website – auch für geteilte Tabellen):
 *      ['rules' => [['entry' => 12, 'page' => 40], ['field' => 'bereich', 'value' => 'satzung', 'page' => 41]]]
 *    Reihenfolge der Auswahl (resolve): Eintrag selbst → nächster Vorfahr über ein Feld „Oberkapitel“ (Verknüpfung auf die eigene
 *    Tabelle, vererbt an Unterkapitel) → Feldwert (Auswahl/Mehrfachauswahl) → Standard. Regeln auf gelöschte Vorlagen zählen nicht.
 *  - Redaktion: ?vorlage={id} an einer Detailseite zeigt bzw. bearbeitet (?edit=vorlage) eine bestimmte Vorlage (nur angemeldet mit pages.edit).
 *  - Verwaltung: Daten → Tabelle → Detailseiten-Vorlagen (/admin/data/{tabelle}/vorlagen-detail, Recht data.schema).
 */
final class DetailTemplates
{
    /**
     * Detailseiten gestalten (Vorlage im Editor, mehrere Vorlagen verwalten): mit „Tabellen und Felder ändern“ oder als Redaktion mit
     * „Seiten bearbeiten“ und dem Recht an den Einträgen dieser Tabelle – die Vorlage ist eine Seite, die Inhalte kommen aus den Einträgen.
     */
    public static function canDesign(array $t): bool
    {
        return can('data.schema') || (can('pages.edit') && can('data.edit', (string) $t['handle']));
    }

    public const SETTING = 'data.detail_templates.';
    /** Höchstzahl an Regeln je Tabelle */
    public const MAX_RULES = 200;

    /** Alle Vorlagen der Tabelle (Standard zuerst, dann nach Anlage) */
    public static function all(array $t): array
    {
        $rows = Pages::db()->fetchAll("SELECT id, title, slug, status, updated_at FROM pages WHERE type = 'template' AND template_for = ? ORDER BY id", [$t['handle']]);
        $def = self::defaultId($t);
        usort($rows, fn($a, $b) => ((int) $b['id'] === $def) <=> ((int) $a['id'] === $def) ?: (int) $a['id'] <=> (int) $b['id']);
        return $rows;
    }

    /** Standard-Vorlage (settings.detail_page_id), 0 = keine */
    public static function defaultId(array $t): int
    {
        return (int) ($t['settings']['detail_page_id'] ?? 0);
    }

    /** Gehört die Seite als Vorlage zu dieser Tabelle? */
    public static function belongs(array $t, int $pageId): ?array
    {
        $p = $pageId > 0 ? Pages::find($pageId) : null;
        return $p && ($p['type'] ?? '') === 'template' && ($p['template_for'] ?? '') === $t['handle'] ? $p : null;
    }

    /** @return list<array{entry?: int, field?: string, value?: string, page: int}> */
    public static function rules(array $t): array
    {
        $cfg = app()->settings->get(self::SETTING . $t['handle'], []);
        return array_values(array_filter((array) ($cfg['rules'] ?? []), 'is_array'));
    }

    /** Regeln prüfen und speichern (unbekannte Felder, Vorlagen anderer Tabellen und Doppel fallen weg) */
    public static function saveRules(array $t, array $rules): array
    {
        $ids = array_map('intval', array_column(self::all($t), 'id'));
        $out = [];
        $seen = [];
        foreach ($rules as $r) {
            if (!is_array($r)) continue;
            $page = (int) ($r['page'] ?? 0);
            if (!in_array($page, $ids, true)) continue;
            if (!empty($r['entry']) && ctype_digit((string) $r['entry'])) {
                $k = 'e' . (int) $r['entry'];
                $rule = ['entry' => (int) $r['entry'], 'page' => $page];
            } elseif (($f = (string) ($r['field'] ?? '')) !== '' && in_array(Tables::field($t, $f)['type'] ?? '', ['select', 'multiselect'], true) && (string) ($r['value'] ?? '') !== '') {
                $k = 'f' . $f . '=' . $r['value'];
                $rule = ['field' => $f, 'value' => (string) $r['value'], 'page' => $page];
            } else {
                continue;
            }
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $rule;
            if (count($out) >= self::MAX_RULES) break;
        }
        app()->settings->set(self::SETTING . $t['handle'], ['rules' => $out]);
        PageCache::clear();
        return $out;
    }

    /** Feld „Oberkapitel“: Verknüpfung (einfach) auf die eigene Tabelle – '' ohne */
    public static function parentField(array $t): string
    {
        if (Knowledge::is($t) && Tables::field($t, 'oberkapitel')) return 'oberkapitel';
        foreach ($t['fields'] as $f) if (($f['type'] ?? '') === 'relation' && ($f['target'] ?? '') === $t['handle']) return (string) $f['name'];
        return '';
    }

    /** Vorlage für einen Eintrag (Seiten-ID) – 0 ohne Vorlage */
    public static function resolveId(array $t, array $e): int
    {
        $rules = self::rules($t);
        $def = self::defaultId($t);
        if (!$rules) return $def;
        $ids = array_map('intval', array_column(self::all($t), 'id'));
        $ok = fn(int $p) => in_array($p, $ids, true);
        $byEntry = [];
        foreach ($rules as $r) if (isset($r['entry']) && $ok((int) $r['page'])) $byEntry[(int) $r['entry']] = (int) $r['page'];
        // Eintrag selbst, dann Vorfahren über „Oberkapitel“ (höchstens 20 Ebenen, ohne Kreise)
        $id = (int) ($e['id'] ?? 0);
        $pf = self::parentField($t);
        $cur = $e;
        $seen = [];
        for ($i = 0; $id && $i < 20 && !isset($seen[$id]); $i++) {
            if (isset($byEntry[$id])) return $byEntry[$id];
            $seen[$id] = true;
            if ($pf === '') break;
            $pid = (int) ($cur[$pf] ?? 0);
            if (!$pid || !($cur = Entries::find($t, $pid))) break;
            $id = $pid;
        }
        // Feldwert (Auswahl, Mehrfachauswahl)
        foreach ($rules as $r) {
            if (!isset($r['field']) || !$ok((int) $r['page'])) continue;
            $v = $e[$r['field']] ?? null;
            $vals = is_array($v) ? $v : (is_string($v) && str_starts_with($v, '[') ? (array) json_decode($v, true) : [(string) $v]);
            if (in_array((string) $r['value'], array_map('strval', $vals), true)) return (int) $r['page'];
        }
        return $def;
    }

    /** Vorlagen-Seite für einen Eintrag (Standard als Rückfall) */
    public static function resolve(array $t, array $e): ?array
    {
        $id = self::resolveId($t, $e);
        $p = $id ? self::belongs($t, $id) : null;
        if (!$p && $id !== self::defaultId($t)) $p = self::belongs($t, self::defaultId($t));
        return $p ?? (self::defaultId($t) ? Pages::find(self::defaultId($t)) : null);
    }

    /** Neue Vorlage anlegen (Standard, wenn die Tabelle noch keine hat) – Seiten-ID */
    public static function create(array $t, string $name, array $blocks): int
    {
        $base = '_vorlage-' . $t['handle'];
        $slug = $base;
        for ($n = 2; Pages::db()->fetchValue('SELECT COUNT(*) FROM pages WHERE slug = ?', [$slug]); $n++) $slug = $base . '-' . $n;
        $id = Pages::create(['slug' => $slug, 'title' => $name, 'type' => 'template', 'template_for' => $t['handle'], 'status' => 'published', 'noindex' => 0],
            Pages::sanitizeBlocks($blocks));
        if (!self::defaultId($t) || !Pages::find(self::defaultId($t))) Tables::setDetailPage($t, $id);
        PageCache::clear();
        return $id;
    }

    /** Kopie einer Vorlage (Blöcke des Arbeitsstands, neue Block-IDs) */
    public static function duplicate(array $t, int $pageId, string $name): ?int
    {
        $p = self::belongs($t, $pageId);
        if (!$p) return null;
        $blocks = Pages::blocks($p, true);
        foreach ($blocks as $i => $b) if (is_array($b)) $blocks[$i]['id'] = bin2hex(random_bytes(5));   // neue Block-IDs
        return self::create($t, $name, $blocks);
    }

    public static function setDefault(array $t, int $pageId): bool
    {
        if (!self::belongs($t, $pageId)) return false;
        Tables::setDetailPage($t, $pageId);
        PageCache::clear();
        return true;
    }

    public static function rename(array $t, int $pageId, string $name): bool
    {
        $name = trim(mb_substr($name, 0, 120));
        if ($name === '' || !self::belongs($t, $pageId)) return false;
        Pages::db()->update('pages', ['title' => $name, 'updated_at' => now()], 'id = :id', ['id' => $pageId]);
        return true;
    }

    /** Löschen – nicht die Standard-Vorlage; Regeln auf sie fallen weg */
    public static function delete(array $t, int $pageId): bool
    {
        if ($pageId === self::defaultId($t) || !self::belongs($t, $pageId)) return false;
        Pages::db()->query("DELETE FROM pages WHERE id = ? AND type = 'template'", [$pageId]);
        self::saveRules($t, array_filter(self::rules($t), fn($r) => (int) $r['page'] !== $pageId));
        Pages::rebuildPaths();
        PageCache::clear();
        return true;
    }

    /** Wie viele Einträge nutzen welche Vorlage? [seiten-id => anzahl] (für die Übersicht, höchstens 2000 Einträge) */
    public static function usage(array $t): array
    {
        $out = [];
        foreach (Entries::query($t, ['status' => 'all', 'limit' => 2000, 'source' => 'site']) as $e) {
            $id = self::resolveId($t, $e);
            $out[$id] = ($out[$id] ?? 0) + 1;
        }
        return $out;
    }

    /** Erster Eintrag, der die Vorlage nutzt (für „Gestalten“), sonst der erste überhaupt */
    public static function sampleEntry(array $t, int $pageId): ?array
    {
        $first = null;
        foreach (Entries::query($t, ['status' => 'all', 'limit' => 500, 'source' => 'site']) as $e) {
            $first ??= $e;
            if (self::resolveId($t, $e) === $pageId) return $e;
        }
        return $first;
    }
}
