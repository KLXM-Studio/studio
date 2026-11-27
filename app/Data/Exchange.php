<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * Austausch für Adressbücher und Kalender (Verwaltung → Importieren): Datei bzw. Adresse lesen, Probelauf, Bestätigen.
 *
 *  - Datei: nur .vcf/.vcard bzw. .ics/.ical, Inhalt beginnt mit BEGIN:VCARD bzw. BEGIN:VCALENDAR, höchstens maxBytes()
 *    (config 'import_max_mb', Standard 10 MB).
 *  - Adresse (nur Kalender, einmaliger Abruf): nur http(s) bzw. webcal(s) – Abruf über Core\Http\Fetch (SSRF-Schutz: nur öffentliche
 *    Adressen, Weiterleitungen einzeln geprüft, Größen- und Zeitlimit, Content-Type text/calendar).
 *  - Zwischen Vorschau und Bestätigen liegt der Inhalt in storage/sites/{website}/import (Token, an Konto und Tabelle gebunden,
 *    höchstens eine Stunde) – das Bestätigen liest exakt die geprüfte Datei.
 */
final class Exchange
{
    public const TTL = 3600;

    /** Format einer Tabelle: vcf (Adressbuch) | ics (Kalender) | null */
    public static function kind(array $t): ?string
    {
        if (Tables::isInbox($t)) return null;
        return Kinds::isContacts($t) ? 'vcf' : (Kinds::isCalendar($t) ? 'ics' : null);
    }

    public static function maxBytes(): int
    {
        return max(1, min(50, (int) app()->config->get('import_max_mb', 10))) * 1024 * 1024;
    }

    /** Inhalt prüfen: Kopfzeile des Formats */
    public static function looksLike(string $body, string $kind): bool
    {
        $head = ltrim(substr($body, 0, 512), "\xEF\xBB\xBF \t\r\n");
        return (bool) preg_match($kind === 'vcf' ? '~^BEGIN:VCARD~i' : '~^BEGIN:VCALENDAR~i', $head);
    }

    /**
     * Hochgeladene Datei ($_FILES-Eintrag) lesen.
     * @return array{0: ?string, 1: ?string} [Inhalt, Fehler]
     */
    public static function fromUpload(mixed $f, string $kind): array
    {
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, __('Bitte eine Datei wählen oder hierher ziehen.')];
        if (in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) ($f['size'] ?? 0) > self::maxBytes()) {
            return [null, __('Datei zu groß (höchstens {mb} MB).', ['mb' => (int) (self::maxBytes() / 1048576)])];
        }
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) return [null, __('Upload fehlgeschlagen – bitte erneut versuchen.')];
        $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
        $exts = $kind === 'vcf' ? ['vcf', 'vcard'] : ['ics', 'ical', 'ifb', 'icalendar'];
        if (!in_array($ext, $exts, true)) return [null, $kind === 'vcf' ? __('Bitte eine vCard-Datei (.vcf) wählen.') : __('Bitte eine Kalender-Datei (.ics) wählen.')];
        $body = (string) file_get_contents((string) $f['tmp_name'], false, null, 0, self::maxBytes() + 1);
        if (strlen($body) > self::maxBytes()) return [null, __('Datei zu groß (höchstens {mb} MB).', ['mb' => (int) (self::maxBytes() / 1048576)])];
        if (!self::looksLike($body, $kind)) return [null, $kind === 'vcf' ? __('Keine vCard-Datei (erwartet wird „BEGIN:VCARD“ am Anfang).') : __('Keine Kalender-Datei (erwartet wird „BEGIN:VCALENDAR“ am Anfang).')];
        return [$body, null];
    }

    /** Adresse eines Kalenders normalisieren: webcal(s):// → https://; nur http(s), keine Zugangsdaten. null = ungültig */
    public static function normUrl(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('~^webcals?://~i', $url)) $url = (string) preg_replace('~^webcals?://~i', 'https://', $url);
        if (!\Core\Sanitizer::validHttpUrl($url)) return null;
        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) return null;
        return $url;
    }

    /**
     * Kalender einmalig von einer Adresse holen (SSRF-Schutz über Core\Http\Fetch).
     * @return array{0: ?string, 1: ?string} [Inhalt, Fehler]
     */
    public static function fromUrl(string $url): array
    {
        $u = self::normUrl($url);
        if ($u === null) return [null, __('Bitte eine vollständige Adresse mit https:// (oder webcal://) angeben – ohne Zugangsdaten.')];
        try {
            $r = \Core\Http\Fetch::get($u, ['timeout' => 15, 'max' => self::maxBytes(), 'kind' => 'ics', 'headers' => ['Accept: text/calendar, text/plain;q=0.9, */*;q=0.5']]);
        } catch (\Core\Http\FetchException $e) {
            return [null, $e->getMessage()];
        }
        if (!self::looksLike($r['body'], 'ics')) return [null, __('Unter dieser Adresse liegt kein Kalender (erwartet wird „BEGIN:VCALENDAR“).')];
        return [$r['body'], null];
    }

    private static function dir(): string
    {
        $d = site()->storage() . '/import';
        if (!is_dir($d)) @mkdir($d, 0770, true);
        return $d;
    }

    /** Inhalt bis zum Bestätigen ablegen → Token */
    public static function stash(array $t, string $kind, string $body, string $label = ''): string
    {
        foreach (glob(self::dir() . '/*.json') ?: [] as $f) {
            if (filemtime($f) < time() - self::TTL) { @unlink($f); @unlink(substr($f, 0, -5) . '.dat'); }
        }
        $token = bin2hex(random_bytes(16));
        file_put_contents(self::dir() . "/$token.dat", $body, LOCK_EX);
        file_put_contents(self::dir() . "/$token.json", json_encode(['user' => (int) (app()->auth->user()['id'] ?? 0), 'table' => $t['handle'], 'kind' => $kind,
            'label' => mb_substr($label, 0, 200), 'at' => time()], JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $token;
    }

    /**
     * Ablage zu einem Token: Pfad ohne .dat/.json – aus dem Verzeichnis gelesen (nie aus der Eingabe zusammengesetzt), Vergleich
     * zeitkonstant. null = unbekannt.
     */
    private static function find(string $token): ?string
    {
        if (!preg_match('~^[a-f0-9]{32}$~', $token)) return null;
        foreach (glob(self::dir() . '/*.json') ?: [] as $f) {
            if (hash_equals(basename($f, '.json'), $token)) return substr($f, 0, -5);
        }
        return null;
    }

    /** Abgelegten Inhalt lesen (nur dasselbe Konto, dieselbe Tabelle, nicht abgelaufen) @return ?array{body: string, label: string} */
    public static function unstash(array $t, string $kind, string $token): ?array
    {
        $p = self::find($token);
        if ($p === null) return null;
        $meta = json_decode((string) @file_get_contents($p . '.json'), true);
        if (!is_array($meta) || (int) $meta['user'] !== (int) (app()->auth->user()['id'] ?? -1) || $meta['table'] !== $t['handle'] || $meta['kind'] !== $kind
            || (int) $meta['at'] < time() - self::TTL || !is_file($p . '.dat')) return null;
        return ['body' => (string) file_get_contents($p . '.dat'), 'label' => (string) ($meta['label'] ?? '')];
    }

    public static function drop(string $token): void
    {
        $p = self::find($token);
        if ($p === null) return;
        @unlink($p . '.dat');
        @unlink($p . '.json');
    }

    /** Probelauf bzw. Übernahme */
    public static function run(array $t, string $kind, string $body, array $o): array
    {
        return $kind === 'vcf' ? VCards::import($t, $body, $o) : IcsImport::import($t, $body, $o);
    }
}
