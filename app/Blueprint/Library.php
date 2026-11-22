<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

use Core\Sites;

/**
 * Website-Vorlagen der Installation (Netzwerk-Verwaltung → Website-Vorlagen, Recht network.blueprints): storage/blueprints/{name}/
 *
 *   meta.json            Name, Bezeichnung, Beschreibung, Quell-Website, Umfang der Demoinhalte, kuratierte Demo-Einträge,
 *                        Platzhalter und Schritte der Schnelleinrichtung, Versionen mit Änderungsprotokoll
 *   preview.(png|jpg|webp)  Vorschaubild
 *   {version}/           Paket je Version (Core\Blueprint\Package, Verzeichnisform)
 *
 * Export und Anlegen laufen als eigener Prozess der Kommandozeile (site:blueprint, site:create --blueprint) – so gelten für die
 * Quell- bzw. Ziel-Website ihre eigene Datenbank, ihr Kit und ihre Erweiterungen.
 */
final class Library
{
    /** Ordner der Bibliothek */
    public static function dir(): string
    {
        return ROOT . '/storage/blueprints';
    }

    /** Ordner einer Vorlage – nur mit Namen aus known() bzw. Package::cleanName(), nie direkt aus einer Eingabe */
    private static function path(string $safeName): string
    {
        return self::dir() . '/' . $safeName;
    }

    /** Vorlage mit allen Versionen löschen */
    public static function delete(string $name): void
    {
        if (($known = self::known($name)) !== null) Package::remove(self::path($known));
    }

    /** Alle Vorlagen (meta.json) nach Bezeichnung @return list<array> */
    public static function all(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*/meta.json') ?: [] as $f) {
            $m = json_decode((string) file_get_contents($f), true);
            if (is_array($m) && Package::validName((string) ($m['name'] ?? ''))) $out[] = self::norm($m);
        }
        usort($out, fn($a, $b) => strcasecmp($a['title'], $b['title']));
        return $out;
    }

    /**
     * Vorhandener Ordner zu einem Namen (Eingabe) – der Name kommt aus dem Verzeichnis, nicht aus der Eingabe; null = gibt es nicht.
     * Damit erreichen Eingaben nie direkt einen Dateipfad.
     */
    public static function known(string $name): ?string
    {
        if (!Package::validName($name)) return null;
        foreach (glob(self::dir() . '/*', GLOB_ONLYDIR) ?: [] as $d) if (basename($d) === $name) return basename($d);
        return null;
    }

    /** Vorhandene Version einer bekannten Vorlage (Name aus known()) – Ordnername aus dem Verzeichnis */
    private static function knownVersion(string $knownName, string $version): ?string
    {
        if (!Package::validVersion($version)) return null;
        foreach (glob(self::path($knownName) . '/*', GLOB_ONLYDIR) ?: [] as $d) if (basename($d) === $version) return basename($d);
        return null;
    }

    public static function get(string $name): ?array
    {
        $known = self::known($name);
        if ($known === null || !is_file(self::path($known) . '/meta.json')) return null;
        $m = json_decode((string) file_get_contents(self::path($known) . '/meta.json'), true);
        return is_array($m) ? self::norm(['name' => $known] + $m) : null;
    }

    private static function norm(array $m): array
    {
        $m += ['title' => $m['name'], 'description' => '', 'source' => '', 'demo' => 'anonym', 'per_table' => Export::DEMO_PER_TABLE, 'pick' => [],
            'placeholders' => [], 'steps' => QuickConfig::STEPS, 'versions' => [], 'preview' => '', 'created_at' => '', 'updated_at' => ''];
        $m['versions'] = array_values(array_filter((array) $m['versions'], fn($v) => is_array($v) && Package::validVersion((string) ($v['version'] ?? ''))));
        usort($m['versions'], fn($a, $b) => version_compare((string) $b['version'], (string) $a['version']));
        $m['latest'] = $m['versions'][0]['version'] ?? null;
        return $m;
    }

    public static function save(array $m): void
    {
        $dir = self::path(Package::cleanName((string) $m['name']) ?? throw new \InvalidArgumentException('name'));
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        unset($m['latest']);
        $m['updated_at'] = date('c');
        file_put_contents($dir . '/meta.json', Package::json($m), LOCK_EX);
    }

    /** Verzeichnis eines Pakets (neueste Version ohne Angabe) */
    public static function packageDir(string $name, ?string $version = null): ?string
    {
        $known = self::known($name);
        $m = $known !== null ? self::get($known) : null;
        $version ??= $m['latest'] ?? null;
        if ($known === null || !$m || !$version || !Package::validVersion($version)) return null;
        $v = self::knownVersion($known, (string) $version);
        $d = $v !== null ? self::path($known) . '/' . $v : '';
        return $d !== '' && is_file($d . '/manifest.json') ? $d : null;
    }

    /** Vorlage oder Paketpfad auflösen (site:create --blueprint=name|pfad) */
    public static function resolve(string $ref): ?string
    {
        if (Package::validName($ref) && ($d = self::packageDir($ref))) return $d;
        if (preg_match('~^([a-z][a-z0-9-]{1,47})@(\d+\.\d+\.\d+)$~', $ref, $x) && ($d = self::packageDir($x[1], $x[2]))) return $d;
        return file_exists($ref) ? $ref : null;
    }

    /**
     * Neue Vorlage bzw. neue Version aus einer Website: Export als eigener Prozess in storage/blueprints/{name}/{version}.
     * $in: name, title, description, source, demo, per_table, note, pick, placeholders, steps. @return array{version: string, report: list<string>}
     */
    public static function createVersion(array $in, string $by = ''): array
    {
        $name = Package::cleanName((string) $in['name']) ?? throw new \InvalidArgumentException(__('Kurzname der Vorlage: a–z, 0–9 und Bindestrich, 2–48 Zeichen.'));
        $source = (string) ($in['source'] ?? '');
        if (!isset(Sites::all()[$source])) throw new \InvalidArgumentException(__('Bitte eine Website als Quelle wählen.'));
        $m = self::get($name) ?? ['name' => $name, 'created_at' => date('c'), 'versions' => []];
        foreach (['title', 'description', 'demo', 'per_table', 'pick', 'placeholders', 'steps'] as $k) if (array_key_exists($k, $in)) $m[$k] = $in[$k];
        $m['source'] = $source;
        $m = self::norm($m);
        $version = (string) ($in['version'] ?? '') ?: ($m['latest'] ? Package::nextVersion((string) $m['latest']) : '1.0.0');
        if (!Package::validVersion($version)) throw new \InvalidArgumentException(__('Version: z. B. 1.0.0'));
        $version = Package::rebuild($version, '0123456789.');
        if (is_dir(self::path($name) . '/' . $version)) throw new \InvalidArgumentException(__('Version {v} gibt es schon.', ['v' => $version]));
        if (!is_dir(self::path($name))) mkdir(self::path($name), 0770, true);
        $changelog = array_map(fn($v) => ['version' => $v['version'], 'date' => $v['date'] ?? '', 'note' => $v['note'] ?? ''], (array) $m['versions']);
        $note = trim((string) ($in['note'] ?? '')) ?: ($changelog ? __('Neue Version aus der Website') : __('Erste Version'));
        array_unshift($changelog, ['version' => $version, 'date' => date('Y-m-d'), 'note' => $note]);
        $opts = ['name' => $name, 'title' => $m['title'], 'description' => $m['description'], 'version' => $version, 'note' => $note, 'per_table' => (int) $m['per_table'],
            'pick' => (array) $m['pick'] ?: null, 'placeholders' => (array) $m['placeholders'], 'steps' => (array) $m['steps'], 'changelog' => $changelog];
        if (!array_filter((array) $m['pick'])) unset($opts['pick']);
        $prev = self::previewFile($name);
        if ($prev) $opts['preview'] = $prev;
        $optFile = Package::tempDir() . '/options.json';
        file_put_contents($optFile, Package::json($opts));
        $code = Network::console(['site:blueprint', $source, '--out=' . self::path($name) . '/' . $version, '--demo=' . $m['demo'], '--options=' . $optFile], $out);
        Package::remove(dirname($optFile));
        if ($code !== 0) {
            Package::remove(self::path($name) . '/' . $version);
            throw new \RuntimeException(trim((string) $out) ?: __('Export fehlgeschlagen.'));
        }
        $m['versions'][] = ['version' => $version, 'date' => date('Y-m-d'), 'note' => $note, 'demo' => $m['demo'], 'by' => $by, 'at' => date('c')];
        self::save($m);
        return ['version' => $version, 'report' => array_values(array_filter(array_map('trim', explode("\n", (string) $out))))];
    }

    /** Bildformate des Vorschaubilds */
    public const PREVIEW_MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /**
     * Vorschaubild der Vorlage (Pfad): Datei der Mediathek der Netzwerk-Website (meta.json → preview_media), Pfad aus dem Medienordner
     * und dem geprüften Dateinamen. Nur im Kontext der Netzwerk-Website (Verwaltung, Bibliothek).
     */
    public static function previewFile(string $name): ?string
    {
        $b = self::get($name);
        $m = $b && (int) ($b['preview_media'] ?? 0) > 0 ? \Core\Media::find((int) $b['preview_media']) : null;
        if (!$m || !empty($m['deleted_at']) || !empty($m['pool_ref']) || !isset(self::PREVIEW_MIMES[(string) $m['mime']])) return null;
        $file = Package::cleanPath((string) $m['file']);
        $path = $file !== null ? site()->mediaDir() . '/' . $file : '';
        return $path !== '' && is_file($path) ? $path : null;
    }

    /**
     * Vorschaubild aus einem Upload: über den geprüften Upload der Mediathek (Core\Media::upload, Website des Netzwerks), die Vorlage
     * merkt sich nur die Medien-ID. Nur PNG/JPEG/WebP bis 4 MB. Fehlertext oder null.
     */
    public static function setPreview(string $name, array $file): ?string
    {
        $b = self::get($name);
        if (!$b) return __('Bild fehlt oder ist größer als 4 MB.');
        if ((int) ($file['size'] ?? 0) > 4 * 1024 * 1024) return __('Bild fehlt oder ist größer als 4 MB.');
        [$m, $err] = \Core\Media::upload($file, __('Vorschaubild der Website-Vorlage {name}', ['name' => (string) $b['title']]), ['require_alt' => false, 'tags' => 'website-vorlage']);
        if (!$m) return (string) $err;
        if (!isset(self::PREVIEW_MIMES[(string) $m['mime']])) {
            \Core\Media::delete((int) $m['id'], true);
            return __('Bitte ein PNG-, JPEG- oder WebP-Bild wählen.');
        }
        $b['preview_media'] = (int) $m['id'];
        self::save($b);
        return null;
    }

    /** Manifest einer Version */
    public static function manifest(string $name, ?string $version = null): ?array
    {
        $d = self::packageDir($name, $version);
        $m = $d ? json_decode((string) file_get_contents($d . '/manifest.json'), true) : null;
        return is_array($m) ? $m : null;
    }

    /** Demo-Einträge der Quelle zur Auswahl (kuratieren): Tabelle → [{id, title, status}] aus der Quell-Website */
    public static function candidates(string $source, int $limit = 40): array
    {
        $out = [];
        try {
            $db = \Core\Network\Stats::db($source);
            foreach ($db->fetchAll("SELECT handle, name, fields_json, settings_json FROM data_tables ORDER BY sort, name") as $t) {
                $s = (array) json_decode((string) $t['settings_json'], true);
                if (($s['kind'] ?? '') === 'inbox') continue;
                $title = (string) ($s['title_field'] ?? '');
                $fields = array_column((array) json_decode((string) $t['fields_json'], true), 'name');
                if ($title === '' || !in_array($title, $fields, true)) $title = (string) ($fields[0] ?? 'id');
                $col = preg_match('~^[a-z][a-z0-9_]*$~', $title) ? $title : 'id';
                try {
                    $rows = $db->fetchAll("SELECT id, status, $col AS t FROM data_{$t['handle']} ORDER BY sort, id LIMIT $limit");
                } catch (\Throwable) {
                    continue;
                }
                $out[(string) $t['handle']] = ['name' => (string) $t['name'], 'kind' => (string) ($s['kind'] ?? 'content'),
                    'rows' => array_map(fn($r) => ['id' => (int) $r['id'], 'title' => mb_strimwidth(strip_tags((string) $r['t']), 0, 80, '…'), 'status' => (string) $r['status']], $rows)];
            }
        } catch (\Throwable) {
        }
        return $out;
    }

    /**
     * Websites, die aus dieser Vorlage stammen (Einstellung sys.blueprint der jeweiligen Website), mit Version und Vorschlag.
     * @return list<array{key: string, version: string, proposal: ?string, diff: ?array}>
     */
    public static function affected(string $name): array
    {
        $out = [];
        $latest = self::packageDir($name);
        $newSig = $latest ? Diff::signature($latest) : null;
        foreach (array_keys(Sites::all()) as $key) {
            try {
                $db = \Core\Network\Stats::db((string) $key);
                $bp = json_decode((string) $db->fetchValue('SELECT value_json FROM settings WHERE skey = ?', [Apply::KEY]), true);
                if (!is_array($bp) || ($bp['name'] ?? '') !== $name) continue;
                $prop = json_decode((string) $db->fetchValue('SELECT value_json FROM settings WHERE skey = ?', [Diff::PROPOSAL_KEY]), true);
                $diff = $newSig && is_array($bp['structure'] ?? null) ? Diff::compare((array) $bp['structure'], $newSig) : null;
                $out[] = ['key' => (string) $key, 'version' => (string) ($bp['version'] ?? ''), 'proposal' => is_array($prop) ? (string) ($prop['version'] ?? '') : null, 'diff' => $diff];
            } catch (\Throwable) {
            }
        }
        return $out;
    }

    /** Neue Version als Vorschlag in eine Website legen (Einstellung sys.blueprint_update, eigener Prozess je Website) */
    public static function propose(string $name, string $site): string
    {
        $code = Network::console(['blueprint:propose', $name, '--site=' . $site], $out);
        if ($code !== 0) throw new \RuntimeException(trim((string) $out) ?: __('Vorschlag fehlgeschlagen.'));
        return trim((string) $out);
    }

    /**
     * Neue Website aus einer Vorlage (gleicher Weg wie site:create --blueprint) als eigener Prozess.
     * @return array{ok: bool, output: string}
     */
    public static function createSite(string $ref, string $key, string $hosts, array $params): array
    {
        $file = Package::tempDir() . '/params.json';
        file_put_contents($file, Package::json($params));
        $code = Network::console(['site:create', $key, $hosts, '--blueprint=' . $ref, '--params=' . $file], $out);
        Package::remove(dirname($file));
        return ['ok' => $code === 0, 'output' => (string) $out];
    }
}
