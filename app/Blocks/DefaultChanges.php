<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blocks;

use Core\Data\Tables;
use Core\PageCache;

/**
 * Geänderte Standardwerte von Blockfeldern: Bestehende Blöcke behalten ihr Aussehen.
 *
 * Beim Rendern füllt Core\Theme::makeBlock fehlende Feldwerte mit dem Standard aus der Felddefinition. Ändert sich ein Standard
 * (oder kommt ein Feld mit neuem Verhalten dazu), sähen gespeicherte Blöcke ohne Wert plötzlich anders aus. Darum schreibt diese
 * Umstellung einmal je Website in alle gespeicherten Blöcke ohne Wert ausdrücklich den bisherigen Zustand – danach gilt der neue
 * Standard nur für neue Blöcke.
 *
 * Je Änderung (CHANGES, Schlüssel = Kennung) läuft die Umstellung genau einmal (Merker in sys.block_defaults); sie ist zudem
 * idempotent (vorhandene Werte bleiben, auch null). Läuft bei `php bin/console migrate` und beim Start (App::boot), falls noch offen.
 *
 * Orte: Seiten (Entwurf + veröffentlichte Fassung, auch Vorlagen und Detailseiten-Vorlagen), Seitenversionen (revisions),
 * Einträge aller Inhalts-Tabellen (Feldtyp „Inhaltsblöcke“ u. a., auch geteilte Tabellen) und deren Versionen (entry_revisions) –
 * in jeder Tiefe (Layout-Spalten, Inhaltsblöcke eines Eintrags). Erweiterungen mit eigenen Block-Tabellen nutzen patchData()
 * bzw. patchJson() in einer eigenen Migration (Extension::migration).
 */
final class DefaultChanges
{
    /** Kennung => [Blocktyp => [Feld => bisheriger Zustand]] */
    public const CHANGES = [
        // 1.0.0: Text + Bild „Bild ausrichten“ (Standard „Mitlaufen“), Bildergalerie „Einzelnes Bild groß zeigen“ (Standard an)
        '2026-11-media-text-gallery' => [
            'media_text' => ['valign' => 'center'],
            'gallery' => ['single_large' => false],
        ],
    ];
    public const SETTING = 'sys.block_defaults';

    /** Alle Regeln der noch offenen (oder übergebenen) Änderungen zusammengefasst: [typ => [feld => wert]] */
    public static function rules(?array $ids = null): array
    {
        $out = [];
        foreach (self::CHANGES as $id => $types) {
            if ($ids !== null && !in_array($id, $ids, true)) continue;
            foreach ($types as $type => $fields) $out[$type] = ($out[$type] ?? []) + $fields;
        }
        return $out;
    }

    /** Offene Änderungen dieser Website */
    public static function pending(): array
    {
        $done = (array) (app()->settings->get(self::SETTING) ?: []);
        return array_values(array_diff(array_keys(self::CHANGES), $done));
    }

    /**
     * Daten EINES Blocks (Typ bekannt, z. B. Tabelle einer Erweiterung mit Spalten type + data_json): fehlende Felder ergänzen.
     * Arrays und Objekte (json_decode ohne assoc) werden unterstützt.
     */
    public static function patchData(string $type, mixed $data, int &$n, ?array $rules = null): mixed
    {
        $rules ??= self::rules();
        if (!isset($rules[$type])) return $data;
        if (is_array($data) && $data === []) $data = new \stdClass();   // leere Daten: Objekt, wie im Editor
        foreach ($rules[$type] as $field => $value) {
            if (is_array($data) && !array_key_exists($field, $data)) { $data[$field] = $value; $n++; }
            elseif ($data instanceof \stdClass && !property_exists($data, $field)) { $data->$field = $value; $n++; }
        }
        return $data;
    }

    /** Beliebig verschachtelte Inhalte durchgehen: jedes Gebilde mit „type“ (Regel vorhanden) und „data“ ist ein Block */
    public static function patch(mixed $v, int &$n, ?array $rules = null): mixed
    {
        $rules ??= self::rules();
        if ($v instanceof \stdClass) {
            if (isset($v->type) && is_string($v->type) && isset($rules[$v->type]) && property_exists($v, 'data') && (is_array($v->data) || $v->data instanceof \stdClass)) {
                $v->data = self::patchData($v->type, $v->data, $n, $rules);
            }
            foreach (get_object_vars($v) as $k => $x) {
                if (is_array($x) || $x instanceof \stdClass) $v->$k = self::patch($x, $n, $rules);
            }
            return $v;
        }
        if (is_array($v)) {
            if (isset($v['type']) && is_string($v['type']) && isset($rules[$v['type']]) && array_key_exists('data', $v) && is_array($v['data'])) {
                $v['data'] = self::patchData($v['type'], $v['data'], $n, $rules);
            }
            foreach ($v as $k => $x) {
                if (is_array($x) || $x instanceof \stdClass) $v[$k] = self::patch($x, $n, $rules);
            }
        }
        return $v;
    }

    /**
     * Gespeichertes JSON umstellen; null = unverändert. Objekte bleiben Objekte ({} bleibt {}), Schreibweise wie Pages::saveDraft.
     */
    public static function patchJson(?string $json, ?array $rules = null): ?string
    {
        if ($json === null || $json === '' || !str_contains($json, '"type"')) return null;
        $rules ??= self::rules();
        $hit = false;
        foreach (array_keys($rules) as $type) {
            if (str_contains($json, '"' . $type . '"')) { $hit = true; break; }
        }
        if (!$hit) return null;
        $d = json_decode($json);
        if (!is_array($d) && !$d instanceof \stdClass) return null;
        $n = 0;
        $d = self::patch($d, $n, $rules);
        return $n ? (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    /**
     * Offene Änderungen auf der aktuellen Website ausführen und als erledigt merken. @return list<string> Protokollzeilen
     */
    public static function run(bool $dry = false): array
    {
        $ids = self::pending();
        if (!$ids) return [];
        $rules = self::rules($ids);
        $db = app()->db;
        $log = [];
        $count = ['pages' => 0, 'revisions' => 0, 'entries' => 0, 'entry_revisions' => 0];
        foreach ($db->fetchAll('SELECT id, content_draft, content_published FROM pages') as $p) {
            $upd = [];
            $pub = self::patchJson($p['content_published'], $rules);
            if ($pub !== null) $upd['content_published'] = $pub;
            // Gleiche Fassungen bleiben gleich (sonst gälte die Seite als „mit unveröffentlichten Änderungen“)
            if ($p['content_draft'] !== null && $p['content_draft'] === $p['content_published']) {
                if ($pub !== null) $upd['content_draft'] = $pub;
            } elseif (($draft = self::patchJson($p['content_draft'], $rules)) !== null) {
                $upd['content_draft'] = $draft;
            }
            if (!$upd) continue;
            $count['pages']++;
            if (!$dry) $db->update('pages', $upd, 'id = :id', ['id' => (int) $p['id']]);   // updated_at bleibt
        }
        foreach ($db->fetchAll('SELECT id, blocks_json FROM revisions') as $r) {
            if (($j = self::patchJson($r['blocks_json'], $rules)) === null) continue;
            $count['revisions']++;
            if (!$dry) $db->update('revisions', ['blocks_json' => $j], 'id = :id', ['id' => (int) $r['id']]);
        }
        try {
            foreach ($db->fetchAll('SELECT id, data_json FROM entry_revisions') as $r) {
                if (($j = self::patchJson($r['data_json'], $rules)) === null) continue;
                $count['entry_revisions']++;
                if (!$dry) $db->update('entry_revisions', ['data_json' => $j], 'id = :id', ['id' => (int) $r['id']]);
            }
        } catch (\Throwable) {
            // Tabelle fehlt (sehr alte Datenbank) – nichts zu tun
        }
        foreach (Tables::all() as $t) {
            if (($t['settings']['kind'] ?? 'content') === 'inbox') continue;   // Eingänge: verschlüsselt, Angaben von Besuchern
            if (!preg_match('~^[a-z0-9_]+$~', (string) ($t['handle'] ?? ''))) continue;
            try {
                $tdb = Tables::db($t);
                foreach ($tdb->fetchAll("SELECT * FROM data_{$t['handle']}") as $e) {
                    $upd = [];
                    foreach ($e as $col => $v) {
                        if (!is_string($v) || in_array($col, Tables::SYSTEM, true) || ($v[0] ?? '') !== '[' && ($v[0] ?? '') !== '{') continue;
                        if (($j = self::patchJson($v, $rules)) !== null) $upd[$col] = $j;
                    }
                    if (!$upd) continue;
                    $count['entries']++;
                    if (!$dry) $tdb->update("data_{$t['handle']}", $upd, 'id = :id', ['id' => (int) $e['id']]);
                }
            } catch (\Throwable $ex) {
                $log[] = "Tabelle {$t['handle']}: " . $ex->getMessage();
            }
        }
        if (!$dry) {
            $done = (array) (app()->settings->get(self::SETTING) ?: []);
            app()->settings->set(self::SETTING, array_values(array_unique(array_merge($done, $ids))));
            if (array_sum($count)) PageCache::clear();
        }
        if (array_sum($count)) {
            $log[] = sprintf('Blöcke: bisheriges Aussehen festgehalten (%s) – %d Seite(n), %d Seitenversion(en), %d Eintrag/Einträge, %d Eintragsversion(en)',
                implode(', ', $ids), $count['pages'], $count['revisions'], $count['entries'], $count['entry_revisions']);
        }
        return $log;
    }
}
