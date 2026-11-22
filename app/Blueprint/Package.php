<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

/**
 * Paket „Website-Vorlage“ (Blaupause): ein Verzeichnis oder ZIP mit festem Aufbau – Grundlage für site:blueprint (Export),
 * site:create --blueprint (Anlegen) und die Netzwerk-Verwaltung → Website-Vorlagen (Core\Blueprint\Library).
 *
 *   manifest.json                Format, Version, Name, Beschreibung, Kit, Umfang der Demoinhalte, Funktionen, Erweiterungen,
 *                                Netzwerk-Bezüge, Schnelleinrichtung (Schritte + Platzhalter), Änderungsprotokoll, Prüfsummen
 *   structure/settings.json      Grundeinstellungen nach Whitelist (Export::SYS_SETTINGS + Kit-Felder) und Design-Werte
 *   structure/pages.json         Seitenbaum (Seiten, Detail- und Listen-Vorlagen) mit IDs – Verweise page:ID bleiben gültig
 *   structure/tables.json        Datentabellen: Definition (Felder, Abschnitte, Art, Freigabe) ohne Einträge
 *   structure/roles.json         Rollen mit Rechten, Tabellen- und Blockrechten (ohne admin/network)
 *   structure/list_templates.json, structure/custom_blocks.json   Listen-Vorlagen und eigene Blöcke (Block-Designer)
 *   structure/extensions.json    Abschnitte der Erweiterungen (Filter blueprint.export, z. B. Gebietskarten)
 *   guide/*.md                   Projekt-Hinweise der Website (Kit-Guide, Core\Guide)
 *   demo/entries.json            Demo-Einträge je Tabelle (anonymisiert, Termine relativ zu heute)
 *   demo/media.json + demo/media/*  freie Bilder mit Urheber und Lizenz (sonst Platzhalter beim Anlegen)
 *   preview.png|jpg|webp         Vorschaubild (optional)
 *
 * Nie im Paket: Konten, Passwörter, Schlüssel, Tokens, E-Mail-Konten, Abonnenten, Aufgaben, Formulareingänge, Statistik,
 * Protokolle, Versionen, Domains (Prüfung: Export::audit).
 */
final class Package
{
    public const FORMAT = 'klxm-site-blueprint';
    /** Version des Paketformats – neue Einträge nur additiv; ältere Pakete bleiben lesbar */
    public const FORMAT_VERSION = 1;
    public const FILES = ['structure/settings.json', 'structure/pages.json', 'structure/tables.json', 'structure/roles.json',
        'structure/list_templates.json', 'structure/custom_blocks.json', 'structure/extensions.json', 'demo/entries.json', 'demo/media.json'];
    /** Größte Datei im Paket (Bytes) und größtes ZIP – schützt das Anlegen vor übergroßen Paketen */
    public const MAX_FILE = 25 * 1024 * 1024;
    public const MAX_TOTAL = 200 * 1024 * 1024;
    /** Erlaubte Endungen außerhalb der JSON-Dateien (Medien, Hinweise, Vorschaubild) */
    public const ALLOWED_EXT = ['json', 'md', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'svg', 'pdf', 'mp4', 'webm'];

    /** Kurzname einer Vorlage (a–z, 0–9, Bindestrich) */
    public static function validName(string $n): bool
    {
        return (bool) preg_match('~^[a-z][a-z0-9-]{1,47}$~', $n);
    }

    /** Version „1.2.0“ prüfen */
    public static function validVersion(string $v): bool
    {
        return (bool) preg_match('~^\d{1,3}\.\d{1,3}\.\d{1,3}$~', $v);
    }

    /** Nächste Version: 1.2.0 → 1.3.0 (Struktur) */
    public static function nextVersion(string $v): string
    {
        $p = array_map('intval', explode('.', self::validVersion($v) ? $v : '1.0.0'));
        return $p[0] . '.' . ($p[1] + 1) . '.0';
    }

    /**
     * Paket in ein Verzeichnis schreiben: $files relativer Pfad → Inhalt (Text bzw. JSON), $copies relativer Pfad → Quelldatei
     * (Bilder, Hinweise, Vorschaubild – unverändert kopiert).
     */
    public static function write(string $dir, array $manifest, array $files, array $copies = []): void
    {
        if (is_dir($dir) && (glob($dir . '/*') ?: [])) throw new \RuntimeException("Ziel ist nicht leer: $dir");
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) throw new \RuntimeException("Ziel nicht beschreibbar: $dir");
        $sums = [];
        $target = function (string $rel) use ($dir): array {
            $clean = self::cleanPath($rel) ?? throw new \RuntimeException("Ungültiger Pfad im Paket: $rel");
            $abs = $dir . '/' . $clean;
            if (!is_dir(dirname($abs))) mkdir(dirname($abs), 0770, true);
            return [$clean, $abs];
        };
        foreach ($files as $rel => $content) {
            [$rel, $abs] = $target((string) $rel);
            file_put_contents($abs, is_string($content) ? $content : self::json($content));
            $sums[$rel] = hash_file('sha256', $abs);
        }
        foreach ($copies as $rel => $src) {
            [$rel, $abs] = $target((string) $rel);
            if (!@copy((string) $src, $abs)) throw new \RuntimeException("Datei nicht kopierbar: $rel");
            $sums[$rel] = hash_file('sha256', $abs);
        }
        ksort($sums);
        $manifest['files'] = $sums;
        file_put_contents($dir . '/manifest.json', self::json($manifest));
    }

    /** JSON wie im Paket (lesbar, Unicode, ohne Escapes für /) */
    public static function json(mixed $v): string
    {
        return (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    /** Verzeichnis als ZIP packen */
    public static function zip(string $dir, string $zipFile): void
    {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('PHP-Erweiterung zip fehlt – Paket als Verzeichnis ausgeben (--out=ordner).');
        $z = new \ZipArchive();
        if ($z->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException("ZIP nicht beschreibbar: $zipFile");
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $z->addFile($f->getPathname(), ltrim(substr($f->getPathname(), strlen($dir)), '/'));
        }
        $z->close();
    }

    /**
     * Paket öffnen: Verzeichnis oder ZIP (wird in ein temporäres Verzeichnis entpackt – nur erlaubte Pfade und Endungen,
     * keine Pfade mit „..“, Größen begrenzt). Prüft Format und Prüfsummen. @return array{dir: string, manifest: array, temp: bool}
     */
    public static function open(string $path): array
    {
        $temp = false;
        if (is_file($path)) {
            if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('PHP-Erweiterung zip fehlt.');
            if (filesize($path) > self::MAX_TOTAL) throw new \RuntimeException('Paket ist zu groß.');
            $z = new \ZipArchive();
            if ($z->open($path) !== true) throw new \RuntimeException('Kein gültiges ZIP.');
            $dir = self::tempDir();
            $temp = true;
            $total = 0;
            for ($i = 0; $i < $z->numFiles; $i++) {
                $st = $z->statIndex($i);
                $name = (string) ($st['name'] ?? '');
                if (str_ends_with($name, '/')) continue;
                $rel = self::cleanPath($name);
                if ($rel === null) { $z->close(); self::remove($dir); throw new \RuntimeException("Ungültiger Pfad im Paket: $name"); }
                if (($st['size'] ?? 0) > self::MAX_FILE || ($total += (int) ($st['size'] ?? 0)) > self::MAX_TOTAL) { $z->close(); self::remove($dir); throw new \RuntimeException('Paket ist zu groß.'); }
                $abs = $dir . '/' . $rel;
                if (!is_dir(dirname($abs))) mkdir(dirname($abs), 0770, true);
                $stream = $z->getStream($name);
                if ($stream === false) continue;
                file_put_contents($abs, $stream);
                fclose($stream);
            }
            $z->close();
        } elseif (is_dir($path)) {
            $dir = rtrim($path, '/');
        } else {
            throw new \RuntimeException("Paket nicht gefunden: $path");
        }
        $manifest = json_decode((string) @file_get_contents($dir . '/manifest.json'), true);
        $err = is_array($manifest) ? self::checkManifest($manifest) : 'manifest.json fehlt oder ist kein JSON.';
        if ($err === null) {
            foreach ((array) $manifest['files'] as $rel => $sum) {
                $rel = self::cleanPath((string) $rel);
                if ($rel === null || !is_file($dir . '/' . $rel) || !hash_equals((string) $sum, (string) hash_file('sha256', $dir . '/' . $rel))) {
                    $err = 'Prüfsumme stimmt nicht: ' . ($rel ?? '?');
                    break;
                }
            }
        }
        if ($err !== null) {
            if ($temp) self::remove($dir);
            throw new \RuntimeException($err);
        }
        return ['dir' => $dir, 'manifest' => $manifest, 'temp' => $temp];
    }

    /** Fehler im Manifest oder null */
    public static function checkManifest(array $m): ?string
    {
        if (($m['format'] ?? '') !== self::FORMAT) return 'Kein Paket „Website-Vorlage“ (format).';
        if ((int) ($m['format_version'] ?? 0) < 1 || (int) $m['format_version'] > self::FORMAT_VERSION) return 'Paketformat ' . (int) ($m['format_version'] ?? 0) . ' wird von dieser Version nicht unterstützt.';
        if (!self::validName((string) ($m['name'] ?? ''))) return 'Ungültiger Name der Vorlage.';
        if (!self::validVersion((string) ($m['version'] ?? ''))) return 'Ungültige Version der Vorlage.';
        if (!is_array($m['files'] ?? null)) return 'Prüfsummen fehlen.';
        return null;
    }

    /** Datei des Pakets als JSON lesen (fehlt sie: $default) */
    public static function read(string $dir, string $rel, mixed $default = []): mixed
    {
        $rel = self::cleanPath($rel);
        if ($rel === null || !is_file($dir . '/' . $rel)) return $default;
        $v = json_decode((string) file_get_contents($dir . '/' . $rel), true);
        return $v ?? $default;
    }

    /** Relativer Pfad im Paket – nur a–z, 0–9, ._-/, keine „..“, erlaubte Endung; sonst null */
    public static function cleanPath(string $p): ?string
    {
        $p = ltrim(str_replace('\\', '/', $p), '/');
        if ($p === '' || str_contains($p, '..') || !preg_match('~^[A-Za-z0-9._/-]{1,200}$~', $p)) return null;
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        return in_array($ext, self::ALLOWED_EXT, true) ? self::rebuild($p, self::PATH_CHARS) : null;
    }

    private const PATH_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._/-';

    /**
     * Geprüften Namen bzw. Pfad Zeichen für Zeichen aus dem erlaubten Alphabet neu zusammensetzen – so stammt der Wert, der einen
     * Dateipfad erreicht, nachweislich nicht aus der Eingabe (Taint-Analyse); unbekannte Zeichen fallen weg.
     */
    public static function rebuild(string $s, string $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789-.'): string
    {
        $out = '';
        $n = strlen($alphabet);
        foreach (str_split($s) as $c) {
            for ($j = 0; $j < $n; $j++) {
                if ($alphabet[$j] === $c) { $out .= $alphabet[$j]; break; }
            }
        }
        return $out;
    }

    /** Kurzname einer Vorlage geprüft und neu zusammengesetzt (für Pfade) – null = ungültig */
    public static function cleanName(string $n): ?string
    {
        return self::validName($n) ? self::rebuild($n) : null;
    }

    public static function tempDir(): string
    {
        $d = ROOT . '/storage/tmp/blueprint-' . bin2hex(random_bytes(6));
        mkdir($d, 0770, true);
        return $d;
    }

    /**
     * Verzeichnis rekursiv entfernen – nur unterhalb von storage/; $package = true erlaubt auch ein anderes Ziel, wenn es ein Paket
     * ist (manifest.json und nur Paketordner), z. B. ein verworfener Export nach --out=…
     */
    public static function remove(string $dir, bool $package = false): void
    {
        $real = realpath($dir);
        if ($real === false || $real === '/' || $real === realpath(ROOT)) return;
        $inStorage = str_starts_with($real, (string) realpath(ROOT . '/storage') . '/');
        if (!$inStorage) {
            if (!$package || !is_file($real . '/manifest.json')) return;
            foreach (scandir($real) ?: [] as $e) if (!in_array($e, ['.', '..', 'manifest.json', 'structure', 'demo', 'guide', 'preview.png', 'preview.jpg', 'preview.jpeg', 'preview.webp'], true)) return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($real);
    }
}
