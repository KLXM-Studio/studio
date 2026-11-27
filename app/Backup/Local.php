<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Backup;

/**
 * Sicherungen auf diesem Server (storage/backups) verwalten: auflisten, einzeln löschen, Aufräumen.
 *
 * Zwei Arten:
 *  - Website-Sicherungen (site:backup, pool:backup, shared:backup, Gesamtsicherung – Muster Offsite::PATTERN): rotieren selbst nach
 *    config 'backup_keep' (Standard 14) und werden hier nur angezeigt.
 *  - Sicherungen vor Änderungen („…-vor-{anlass}-{JJJJMMTT-HHMM}…“, z. B. vom Deploy: code-vor-…, site-vor-…-{datenbank}.sqlite,
 *    vendor-vor-…, ext-{name}-vor-…, composer.lock.vor-…): je Art (Präfix + Datenbank) bleiben die neuesten keep() Stück,
 *    ältere löscht prune() – nach jedem Deploy (php bin/console backup:prune) oder per Knopf in der Verwaltung.
 * Anzahl installationsweit in storage/backups/.keep.json (Verwaltung: System → Sicherung), Standard 10.
 */
final class Local
{
    public const KEEP_DEFAULT = 10;
    private const KEEP_FILE = '.keep.json';
    /** „{art}-vor-{anlass}-{zeit}{rest}“ bzw. „composer.lock.vor-{anlass}“ */
    private const BEFORE = '~^(?<base>.+?)-vor-.+?-(?<ts>\d{8}-\d{4})(?<rest>.*)$~';
    private const COMPOSER = '~^(?<base>composer\.(?:json|lock))\.vor-.+$~';

    public static function dir(): string
    {
        return ROOT . '/storage/backups';
    }

    /** Wie viele Sicherungen je Art behalten (vor Änderungen) */
    public static function keep(): int
    {
        $j = json_decode((string) @file_get_contents(self::dir() . '/' . self::KEEP_FILE), true);
        return max(1, min(200, (int) ($j['keep'] ?? self::KEEP_DEFAULT)));
    }

    public static function setKeep(int $n): void
    {
        if (!is_dir(self::dir())) @mkdir(self::dir(), 0750, true);
        file_put_contents(self::dir() . '/' . self::KEEP_FILE, json_encode(['keep' => max(1, min(200, $n))]));
    }

    /** Art einer Datei: ['kind' => 'before'|'site'|'other', 'group' => Schlüssel der Gruppe] */
    public static function classify(string $name): array
    {
        $plain = str_ends_with($name, Vault::EXT) ? substr($name, 0, -strlen(Vault::EXT)) : $name;   // verschlüsselte Kopie
        if (preg_match(Offsite::PATTERN, $plain, $m)) return ['kind' => 'site', 'group' => 'site:' . $m['prefix']];
        if (preg_match(self::COMPOSER, $name, $m)) return ['kind' => 'before', 'group' => $m['base']];
        if (preg_match(self::BEFORE, $name, $m)) {
            // Datenbank-Sicherungen je Datenbank getrennt (…-storage_sites_klxm_database_site.sqlite)
            $rest = preg_replace('~\.(tar\.gz|tgz|gz|sqlite|sql|zip|json)$~', '', ltrim((string) $m['rest'], '-'));
            return ['kind' => 'before', 'group' => $m['base'] . ($rest !== '' ? ':' . $rest : '')];
        }
        return ['kind' => 'other', 'group' => 'other'];
    }

    /**
     * Alle Dateien: [name, size, mtime, kind, group], neueste zuerst.
     * @return list<array{name: string, size: int, mtime: int, kind: string, group: string}>
     */
    public static function files(): array
    {
        $out = [];
        foreach (scandir(self::dir()) ?: [] as $f) {
            if ($f[0] === '.' || !is_file(self::dir() . '/' . $f)) continue;
            $c = self::classify($f);
            $out[] = ['name' => $f, 'size' => (int) filesize(self::dir() . '/' . $f), 'mtime' => (int) filemtime(self::dir() . '/' . $f)] + $c;
        }
        usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime'] ?: strcmp($b['name'], $a['name']));
        return $out;
    }

    /**
     * Zusammenfassung je Art für die Verwaltung: [kind => ['count', 'size', 'newest', 'oldest']]
     * @return array<string, array{count: int, size: int, newest: int, oldest: int}>
     */
    public static function summary(): array
    {
        $out = [];
        foreach (self::files() as $f) {
            $s = &$out[$f['kind']];
            $s ??= ['count' => 0, 'size' => 0, 'newest' => 0, 'oldest' => PHP_INT_MAX];
            $s['count']++;
            $s['size'] += $f['size'];
            $s['newest'] = max($s['newest'], $f['mtime']);
            $s['oldest'] = min($s['oldest'], $f['mtime']);
            unset($s);
        }
        return $out;
    }

    /**
     * Sicherungen vor Änderungen aufräumen: je Gruppe die neuesten $keep behalten. $dry: nur zurückgeben, was gelöscht würde.
     * @return array{files: list<string>, bytes: int}
     */
    public static function prune(?int $keep = null, bool $dry = false): array
    {
        $keep = max(1, $keep ?? self::keep());
        $seen = [];
        $del = [];
        $bytes = 0;
        foreach (self::files() as $f) {   // neueste zuerst
            if ($f['kind'] !== 'before') continue;
            $seen[$f['group']] = ($seen[$f['group']] ?? 0) + 1;
            if ($seen[$f['group']] <= $keep) continue;
            $del[] = $f['name'];
            $bytes += $f['size'];
            if (!$dry) @unlink(self::dir() . '/' . $f['name']);
        }
        return ['files' => $del, 'bytes' => $bytes];
    }

    /** Eine Datei löschen (nur Name, nur in storage/backups) – false, wenn es sie nicht gibt */
    public static function delete(string $name): bool
    {
        if ($name === '' || $name[0] === '.' || basename($name) !== $name || str_contains($name, '/') || str_contains($name, '\\')) return false;
        $path = self::dir() . '/' . $name;
        return is_file($path) && @unlink($path);
    }
}
