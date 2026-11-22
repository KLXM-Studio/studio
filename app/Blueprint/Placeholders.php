<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

/**
 * Platzhalter einer Website-Vorlage: {{org.name}}, {{org.city}} … in Seiten, Einträgen und Einstellungen.
 *
 * Export (Core\Blueprint\Export) ersetzt die Angaben der Quell-Website (Name, Kurzname, Claim, Anschrift, Telefon, E-Mail, Domain)
 * überall durch Platzhalter. Beim Anlegen und in der Schnelleinrichtung (Core\Blueprint\QuickConfig) werden sie mit den Angaben der
 * neuen Website gefüllt; offene Platzhalter zeigt die Schnelleinrichtung als Aufgabe. Eigene Platzhalter je Vorlage:
 * manifest.json → quick_config.placeholders ({"verband.name": {"label": "…", "default": "…", "step": "org"}}).
 */
final class Placeholders
{
    /** Feste Platzhalter: Schlüssel → [Bezeichnung, Schritt der Schnelleinrichtung, Einstellung des Kits] */
    public const KEYS = [
        'org.name' => ['Name der Organisation', 'org', 'org_name'],
        'org.short' => ['Kurzname', 'org', 'short_name'],
        'org.claim' => ['Claim', 'org', 'tagline'],
        'org.legal_form' => ['Rechtsform', 'org', 'legal_form'],
        'org.register' => ['Registereintrag', 'org', 'register'],
        'org.street' => ['Straße und Hausnummer', 'org', 'street'],
        'org.zip' => ['PLZ', 'org', 'zip'],
        'org.city' => ['Ort', 'org', 'city'],
        'org.phone' => ['Telefon', 'org', 'phone'],
        'org.email' => ['E-Mail', 'org', 'email'],
        'org.domain' => ['Domain', 'org', ''],
        'region.name' => ['Region', 'region', 'region'],
        'region.short' => ['Region (Abkürzung)', 'region', ''],
    ];

    public const PATTERN = '~\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)\s*\}\}~';

    public static function token(string $key): string
    {
        return '{{' . $key . '}}';
    }

    /** Bezeichnung (übersetzt) eines Platzhalters, eigene aus dem Manifest gehen vor */
    public static function label(string $key, array $custom = []): string
    {
        if (isset($custom[$key]['label'])) return (string) $custom[$key]['label'];
        return isset(self::KEYS[$key]) ? __(self::KEYS[$key][0]) : $key;
    }

    /**
     * Werte der Quell-Website, die beim Export zu Platzhaltern werden (nur nicht leere, mindestens 3 Zeichen; PLZ nur zusammen mit
     * dem Ort). @return array<string, string> Platzhalter → Wert
     */
    public static function sourceValues(callable $setting, string $host, string $region = ''): array
    {
        // Region der Quelle (Bundesland, Schlüssel oder Abkürzung): Langname und Abkürzung ab drei Zeichen („NRW“) werden Platzhalter
        $r = QuickConfig::region($region !== '' ? $region : trim((string) $setting('org.region')));
        $reg = [];
        if (mb_strlen($r['name']) >= 3) $reg['region.name'] = $r['name'];
        if (mb_strlen($r['short']) >= 3 && $r['short'] !== $r['name']) $reg['region.short'] = $r['short'];
        $v = [];
        $name = trim((string) ($setting('org_name') ?: $setting('site_title')));
        foreach (['org.name' => $name, 'org.short' => trim((string) $setting('short_name')), 'org.claim' => trim((string) $setting('tagline')),
            'org.street' => trim((string) $setting('street')), 'org.city' => trim((string) $setting('city')), 'org.phone' => trim((string) $setting('phone')),
            'org.email' => strtolower(trim((string) $setting('email'))), 'org.zip' => trim((string) $setting('zip'))] as $k => $x) {
            if (mb_strlen($x) >= 3) $v[$k] = $x;
        }
        $h = preg_replace('~^www\.~', '', strtolower(preg_replace('~:\d+$~', '', $host) ?? ''));
        if ($h !== '' && str_contains((string) $h, '.') && !preg_match('~\.(localhost|test|local)$~', (string) $h)) $v['org.domain'] = (string) $h;
        return $v + $reg;
    }

    /**
     * Werte der Quelle in einem Text durch Platzhalter ersetzen (längste zuerst, Wortgrenzen; PLZ nur vor dem Ort).
     * @param array<string, string> $src
     */
    public static function tokenize(string $s, array $src): string
    {
        if ($s === '' || !$src) return $s;
        if (isset($src['org.zip'], $src['org.city'])) {
            $s = (string) preg_replace('~\b' . preg_quote($src['org.zip'], '~') . '(\s+)' . preg_quote($src['org.city'], '~') . '\b~u', '{{org.zip}}$1{{org.city}}', $s);
        }
        $pairs = $src;
        unset($pairs['org.zip']);
        uasort($pairs, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($pairs as $key => $val) {
            $q = preg_quote($val, '~');
            // Domain auch mit www. und als Teil von E-Mail-Adressen (info@domain → Platzhalter-Adresse bleibt Aufgabe der Schnelleinrichtung)
            $re = $key === 'org.domain' ? '~(?<![\w.-])(?:www\.)?' . $q . '\b~iu' : '~(?<![\w@.-])' . $q . '(?![\w-])~u';
            $s = (string) preg_replace($re, self::token($key), $s);
            if (str_contains($val, '&')) $s = str_replace(htmlspecialchars($val, ENT_QUOTES), self::token($key), $s);
        }
        return $s;
    }

    /**
     * Platzhalter füllen: bekannte Werte einsetzen, unbekannte stehen lassen. In HTML (Text mit Tags) werden Werte escaped.
     * @param array<string, string> $values
     */
    public static function fill(string $s, array $values): string
    {
        if (!str_contains($s, '{{')) return $s;
        $html = $s !== strip_tags($s);
        return (string) preg_replace_callback(self::PATTERN, function ($m) use ($values, $html) {
            if (!array_key_exists($m[1], $values) || trim((string) $values[$m[1]]) === '') return $m[0];
            $v = (string) $values[$m[1]];
            return $html ? htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $v;
        }, $s);
    }

    /** Werte in einer beliebigen Struktur füllen (Strings rekursiv) */
    public static function fillDeep(mixed $v, array $values): mixed
    {
        if (is_string($v)) return self::fill($v, $values);
        if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = self::fillDeep($x, $values); }
        return $v;
    }

    /** Platzhalter, die in einem Text vorkommen @return list<string> */
    public static function found(string $s): array
    {
        return preg_match_all(self::PATTERN, $s, $m) ? array_values(array_unique($m[1])) : [];
    }

    /**
     * Platzhalter der aktuellen Website füllen: Seiten (Titel, Beschreibung, Entwurf und veröffentlicht), Einstellungen,
     * Einträge der eigenen Inhaltstabellen. Liefert die noch offenen Platzhalter je Ort. @return array{filled: int, open: array<string, list<string>>}
     */
    public static function applySite(array $values): array
    {
        $db = app()->db;
        $filled = 0;
        $open = [];
        $note = function (string $where, string $s) use (&$open): void {
            foreach (self::found($s) as $k) $open[$k][] = $where;
        };
        foreach ($db->fetchAll('SELECT id, title, nav_title, meta_title, meta_description, content_draft, content_published FROM pages') as $p) {
            $upd = [];
            foreach (['title', 'nav_title', 'meta_title', 'meta_description', 'content_draft', 'content_published'] as $c) {
                $old = (string) ($p[$c] ?? '');
                if (!str_contains($old, '{{')) continue;
                $new = str_starts_with($c, 'content_') ? self::fillJson($old, $values) : self::fill($old, $values);
                if ($new !== $old) $upd[$c] = $new;
                $note('page:' . $p['id'], $new);
            }
            if ($upd) { $db->update('pages', $upd, 'id = :id', ['id' => (int) $p['id']]); $filled++; }
        }
        foreach ($db->fetchAll("SELECT skey, value_json FROM settings WHERE value_json LIKE '%{{%'") as $row) {
            $old = (string) $row['value_json'];
            $new = self::fillJson($old, $values);
            if ($new !== $old) { $db->update('settings', ['value_json' => $new], 'skey = :k', ['k' => $row['skey']]); $filled++; }
            $note('setting:' . $row['skey'], $new);
        }
        foreach ($db->fetchAll('SELECT handle, fields_json FROM data_tables') as $t) {
            $tbl = 'data_' . $t['handle'];
            try {
                $cols = $db->columns($tbl);
            } catch (\Throwable) {
                continue;
            }
            if (!$cols || in_array('payload', $cols, true)) continue;   // Eingang: verschlüsselt, keine Platzhalter
            $text = array_values(array_diff($cols, ['id', 'sort', 'status', 'created_at', 'updated_at', 'published_at']));
            foreach ($db->fetchAll('SELECT * FROM ' . $tbl) as $e) {
                $upd = [];
                foreach ($text as $c) {
                    $old = $e[$c] ?? null;
                    if (!is_string($old) || !str_contains($old, '{{')) continue;
                    $new = str_starts_with(ltrim($old), '[') || str_starts_with(ltrim($old), '{') ? self::fillJson($old, $values) : self::fill($old, $values);
                    if ($new !== $old) $upd[$c] = $new;
                    $note('entry:' . $t['handle'] . ':' . $e['id'], $new);
                }
                if ($upd) { $db->update($tbl, $upd, 'id = :id', ['id' => (int) $e['id']]); $filled++; }
            }
        }
        app()->settings->forget();
        \Core\PageCache::clear();
        foreach ($open as $k => $w) $open[$k] = array_values(array_unique($w));
        return ['filled' => $filled, 'open' => $open];
    }

    /** JSON-Text füllen (Werte als Strings, Struktur bleibt gültig) */
    public static function fillJson(string $json, array $values): string
    {
        $v = json_decode($json, true);
        if ($v === null && $json !== 'null') return self::fill($json, $values);
        return (string) json_encode(self::fillDeep($v, $values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
