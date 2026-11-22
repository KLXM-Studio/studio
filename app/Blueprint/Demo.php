<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

use Core\Data\Entries;
use Core\Data\Tables;

/**
 * Demoinhalte einer aus einer Vorlage angelegten Website (Einstellung sys.blueprint_demo): Einträge je Tabelle und Bilder, die nur
 * zu den Demo-Einträgen gehören. Gekennzeichnet in der Übersicht („Demo“) und entfernbar – Knopf in der Schnelleinrichtung bzw.
 * php bin/console site:demo-remove. Seiten und Vorlagen bleiben (Struktur); bearbeitete Demo-Einträge bleiben ebenfalls, wenn
 * $keepEdited gilt.
 */
final class Demo
{
    /** @return array{entries: array<string, list<int>>, media: list<int>} */
    public static function state(): array
    {
        $s = (array) app()->settings->get(Apply::DEMO_KEY, []);
        return ['entries' => array_map(fn($ids) => array_values(array_map('intval', (array) $ids)), (array) ($s['entries'] ?? [])), 'media' => array_values(array_map('intval', (array) ($s['media'] ?? [])))];
    }

    /** Wie viele Demo-Einträge gibt es noch (je Tabelle)? @return array<string, int> */
    public static function counts(): array
    {
        $out = [];
        foreach (self::state()['entries'] as $handle => $ids) {
            $t = Tables::find((string) $handle);
            if (!$t || !$ids) continue;
            $n = (int) Tables::db($t)->fetchValue('SELECT COUNT(*) FROM ' . $t['table'] . ' WHERE id IN (' . implode(',', $ids) . ')');
            if ($n) $out[(string) $handle] = $n;
        }
        return $out;
    }

    public static function has(): bool
    {
        return (bool) self::counts();
    }

    /** Ist dieser Eintrag ein Demo-Eintrag? (Kennzeichnung in Listen) */
    public static function isDemo(string $handle, int $id): bool
    {
        return in_array($id, self::state()['entries'][$handle] ?? [], true);
    }

    /** Demoinhalte entfernen. @return int Zahl entfernter Einträge und Bilder */
    public static function remove(): int
    {
        $s = self::state();
        $n = 0;
        foreach ($s['entries'] as $handle => $ids) {
            $t = Tables::find((string) $handle);
            if (!$t || Tables::isShared($t)) continue;
            foreach ($ids as $id) {
                if (!Entries::find($t, $id)) continue;
                Entries::delete($t, $id);
                $n++;
            }
        }
        foreach ($s['media'] as $mid) {
            if (!\Core\Media::find($mid) || \Core\Media::usages($mid)) continue;
            \Core\Media::delete($mid);
            $n++;
        }
        app()->settings->set(Apply::DEMO_KEY, ['entries' => [], 'media' => [], 'removed_at' => date('c')]);
        \Core\PageCache::clear();
        return $n;
    }
}
