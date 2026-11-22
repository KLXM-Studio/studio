<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Search;

use Core\Extensions;

/**
 * Eigene Dokumente für die Website-Suche (Erweiterungen: Extension::searchProvider, Funktionen des Cores: register) – für Inhalte,
 * die weder Seite noch Eintrag einer Datentabelle sind (z. B. Gebiete einer Karte, Ausgaben eines Archivs, Grafiken).
 *
 *   $x->searchProvider('gebiete', [
 *       'label'     => 'Gebiet',                                   // Reiter/Art im Treffer (Text oder fn(string $lang): string)
 *       'documents' => fn(string $lang): iterable => [              // nur öffentliche Inhalte dieser Sprache
 *           ['id' => '12', 'url' => '/gebiete/nord', 'title' => 'Nord', 'headings' => ['Musterstadt'], 'text' => '…',
 *            'summary' => '…', 'date' => '2026-05-01', 'date_label' => '1. Mai', 'image' => 34, 'facets' => ['Bezirk' => ['Nord']],
 *            'anchors' => [['id' => 'kontakt', 'label' => 'Kontakt']], 'lang' => 'de', 'public' => true],
 *       ],
 *       'feature'   => 'gebietskarten',                            // optional: nur, wenn die Funktion auf der Website an ist
 *   ]);
 *
 * Regeln: id (je Anbieter eindeutig), url (Pfad dieser Website „/…“ oder http(s)) und title sind Pflicht; Text-Angaben dürfen HTML
 * enthalten – im Index steht nur Klartext. 'public' => false oder eine andere Sprache ('lang') → nicht im Index. Ein Fehler des
 * Anbieters kostet nur seine Dokumente. Dokument-ID im Index: x-{anbieter}-{id}, type „ext“, table „ext-{anbieter}“ (Reiter-Filter
 * ?typ=ext-{anbieter}). Der Filter search.document gilt auch für diese Dokumente ($ctx type ext, provider, id).
 * Aktualisierung: Search::invalidate() nach Änderungen – der nächste Abgleich holt die Dokumente neu.
 */
final class Providers
{
    /** @var array<string, array> Anbieter des Cores (und der Selbsttests) */
    private static array $core = [];

    /** Anbieter des Cores anmelden (Erweiterungen: Extension::searchProvider) */
    public static function register(string $key, array $def): void
    {
        if (!self::validKey($key) || !is_callable($def['documents'] ?? null)) {
            error_log('[search] Anbieter „' . $key . '“: ungültiger Schlüssel oder documents fehlt');
            return;
        }
        self::$core[$key] = $def + ['owner' => 'core'];
    }

    public static function unregister(string $key): void
    {
        unset(self::$core[$key]);
    }

    public static function validKey(string $key): bool
    {
        return (bool) preg_match('~^[a-z][a-z0-9_]{0,39}$~', $key);
    }

    /** @return array<string, array> Schlüssel → Definition (+ owner); bei doppeltem Schlüssel gilt der erste */
    public static function all(): array
    {
        $out = self::$core;
        foreach (Extensions::searchProviders() as $k => $def) {
            if (isset($out[$k])) { error_log('[search] Anbieter „' . $k . '“ doppelt (' . ($def['owner'] ?? '?') . ') – übersprungen'); continue; }
            $out[$k] = $def;
        }
        return $out;
    }

    /** Art im Treffer (Reiter) eines Anbieters in der Sprache des Index */
    public static function label(array $def, string $key, string $lang): string
    {
        $l = $def['label'] ?? $key;
        if (is_callable($l)) {
            try {
                $l = $l($lang);
            } catch (\Throwable) {
                $l = $key;
            }
        }
        $l = trim(strip_tags((string) $l));
        return mb_substr($l !== '' ? lt($l) : $key, 0, 40);
    }

    /**
     * Dokumente aller Anbieter für eine Sprache – bereinigt, nur öffentliche (Documents::build).
     * @return \Generator<array>
     */
    public static function documents(string $lang): \Generator
    {
        foreach (self::all() as $key => $def) {
            if (!empty($def['feature']) && !\Core\Features::on((string) $def['feature'], false)) continue;
            $badge = self::label($def, $key, $lang);
            try {
                $n = 0;
                foreach (($def['documents'])($lang) ?? [] as $d) {
                    if (!is_array($d) || ++$n > 20000) continue;
                    $doc = self::normalize($key, $badge, $d, $lang);
                    if ($doc !== null) yield $doc;
                }
            } catch (\Throwable $e) {
                error_log('[search] Anbieter ' . $key . ' (' . ($def['owner'] ?? '?') . '): ' . $e->getMessage());
            }
        }
    }

    /** Ein Dokument eines Anbieters prüfen und in die Form des Index bringen – null = nicht aufnehmen */
    public static function normalize(string $key, string $badge, array $d, string $lang): ?array
    {
        if (array_key_exists('public', $d) && $d['public'] !== true) return null;   // nur ausdrücklich bzw. standardmäßig öffentliche
        if (isset($d['lang']) && $d['lang'] !== '' && (string) $d['lang'] !== $lang) return null;
        $id = trim((string) ($d['id'] ?? ''));
        $url = trim((string) ($d['url'] ?? ''));
        $title = self::plain($d['title'] ?? '', 300);
        if (!preg_match('~^[A-Za-z0-9_.:\-]{1,80}$~', $id) || $title === '' || !self::validUrl($url)) return null;
        $list = fn($v, int $max) => self::plain(is_array($v) ? implode(' · ', array_map(fn($x) => is_scalar($x) ? (string) $x : '', $v)) : $v, $max);
        $date = trim((string) ($d['date'] ?? ''));
        if ($date !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$~', $date)) $date = '';
        $facets = [];
        foreach ((array) ($d['facets'] ?? []) as $fn => $vals) {
            $vals = array_values(array_filter(array_map(fn($v) => is_scalar($v) ? mb_substr(trim((string) $v), 0, 120) : '', (array) $vals), fn($v) => $v !== ''));
            if ($vals && is_string($fn) && $fn !== '') $facets[mb_substr($fn, 0, 60)] = array_slice($vals, 0, 50);
        }
        $anchors = [];
        foreach ((array) ($d['anchors'] ?? []) as $a) {
            if (is_array($a) && isset($a['id'], $a['label'])) $anchors[] = ['id' => (string) $a['id'], 'label' => self::plain($a['label'], 160)];
        }
        return [
            'id' => 'x-' . $key . '-' . $id, 'type' => 'ext', 'table' => 'ext-' . $key, 'badge' => $badge, 'title' => $title,
            'headings' => $list($d['headings'] ?? '', 2000), 'text' => $list($d['text'] ?? '', 60000), 'extra' => $list($d['extra'] ?? '', 20000),
            'summary' => self::plain($d['summary'] ?? '', 600), 'image' => is_numeric($d['image'] ?? null) && (int) $d['image'] > 0 ? (string) (int) $d['image'] : '',
            'facets' => $facets ? (string) json_encode($facets, JSON_UNESCAPED_UNICODE) : '', 'url' => $url, 'date' => $date,
            'date_label' => self::plain($d['date_label'] ?? '', 80), 'origin' => self::plain($d['origin'] ?? '', 120), 'anchors' => $anchors,
        ];
    }

    /** Adresse eines Dokuments: Pfad dieser Website (/…, nicht //) oder http(s) */
    public static function validUrl(string $url): bool
    {
        if ($url === '' || preg_match('~[\s<>"]~', $url)) return false;
        return (str_starts_with($url, '/') && !str_starts_with($url, '//')) || (bool) preg_match('~^https?://[^/\s]+~i', $url);
    }

    private static function plain(mixed $v, int $max): string
    {
        if (!is_scalar($v)) return '';
        $s = (string) $v;
        $s = str_contains($s, '<') || str_contains($s, '&')
            ? Text::plain((string) preg_replace('~<(script|style|template|noscript)\b.*?</\1>~is', ' ', $s))   // Inhalt von Skripten nie als Text
            : trim((string) preg_replace('~\s+~u', ' ', $s));
        return mb_substr($s, 0, $max);
    }
}
