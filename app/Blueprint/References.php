<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

/**
 * Echte Verweise auf umbenannte Einträge nachziehen (Website-Vorlagen: neutrale Namen für Gebiete, Filter blueprint.areas) – nie Fließtext.
 *
 * Ein Verweis ist:
 *  - ein Link auf den Eintrag (<a href="entry:tabelle:id"> bzw. Adresse mit seinem Slug): Linktext und Slug in der Adresse,
 *  - ein Linkfeld in Blöcken (button_link, link …) auf den Eintrag: das zugehörige Beschriftungsfeld (button_label, link_label, label),
 *  - ein Wert, der genau dem Namen entspricht (Datenfeld, Auswahl, Überschrift nur mit dem Namen).
 * Derselbe Name als Wort in einem Satz („… im Bergland 2016“) bleibt stehen – den meldet die Prüfung der Texte (Export::review).
 *
 * $refs: Liste von ['handle', 'id', 'old', 'new', 'slug_old', 'slug_new', 'prefix' (Einzahl der Tabelle, optional)].
 */
final class References
{
    /** Struktur (Blöcke, Felder) oder Text: Verweise nachziehen */
    public static function apply(mixed $v, array $refs): mixed
    {
        if (!$refs) return $v;
        if (is_string($v)) return self::text($v, $refs);
        if (!is_array($v)) return $v;
        // Linkfeld auf einen Eintrag → zugehörige Beschriftung
        foreach ($v as $k => $x) {
            if (!is_string($k) || !is_string($x) || !preg_match('~(?:^|_)link$~', $k)) continue;
            $ref = self::target($x, $refs);
            if ($ref === null) continue;
            $base = substr($k, 0, -4);
            foreach ([$base . 'label', $k . '_label', $base === '' ? 'label' : ''] as $lk) {
                if ($lk !== '' && isset($v[$lk]) && is_string($v[$lk])) $v[$lk] = self::rename($v[$lk], $ref);
            }
            $v[$k] = self::reslug($x, $ref);
        }
        foreach ($v as $k => $x) $v[$k] = self::apply($x, $refs);
        return $v;
    }

    /** Text: Links auf Einträge (Linktext, Adresse) und Werte, die genau einem Namen entsprechen */
    public static function text(string $s, array $refs): string
    {
        $plain = trim(html_entity_decode($s, ENT_QUOTES, 'UTF-8'));
        if (strip_tags($s) === $s) foreach ($refs as $r) {
            if ($plain === (string) $r['old'] || ($r['prefix'] ?? '') !== '' && $plain === $r['prefix'] . ' ' . $r['old']) {
                return str_replace($plain, (string) $r['new'], $s);
            }
        }
        if (!str_contains($s, '<a')) return $s;
        return (string) preg_replace_callback('~<a\b([^>]*)>(.*?)</a>~su', function ($m) use ($refs) {
            // Rich-Text: stabiler Verweis data-link="entry:tabelle:id" (Core\Sanitizer), sonst die Adresse
            $dl = preg_match('~\bdata-link="([^"]*)"~', $m[1], $d) ? html_entity_decode($d[1], ENT_QUOTES, 'UTF-8') : '';
            $hr = preg_match('~\bhref="([^"]*)"~', $m[1], $h) ? html_entity_decode($h[1], ENT_QUOTES, 'UTF-8') : '';
            $ref = ($dl !== '' ? self::target($dl, $refs) : null) ?? ($hr !== '' ? self::target($hr, $refs) : null);
            if ($ref === null) return $m[0];
            $attrs = $hr !== '' ? str_replace($h[0], 'href="' . htmlspecialchars(self::reslug($hr, $ref), ENT_QUOTES) . '"', $m[1]) : $m[1];
            return '<a' . $attrs . '>' . self::rename($m[2], $ref) . '</a>';
        }, $s);
    }

    /** Zeigt eine Adresse auf einen der Einträge? (entry:tabelle:id oder …/slug) */
    private static function target(string $href, array $refs): ?array
    {
        foreach ($refs as $r) {
            if (preg_match('~^entry:' . preg_quote((string) $r['handle'], '~') . ':' . (int) $r['id'] . '(?!\d)~', $href)) return $r;
            if (($r['slug_old'] ?? '') !== '' && preg_match('~/' . preg_quote((string) $r['slug_old'], '~') . '(?:$|[/?#])~', $href)) return $r;
        }
        return null;
    }

    /** Name im Linktext bzw. in der Beschriftung ersetzen („Regionalgruppe Alt“ → neuer Name, nicht doppelt) */
    private static function rename(string $s, array $r): string
    {
        $prefix = (string) ($r['prefix'] ?? '');
        if ($prefix !== '' && str_contains($s, $prefix . ' ' . $r['old'])) return str_replace($prefix . ' ' . $r['old'], (string) $r['new'], $s);
        return str_replace((string) $r['old'], (string) $r['new'], $s);
    }

    private static function reslug(string $href, array $r): string
    {
        if (($r['slug_old'] ?? '') === '' || ($r['slug_new'] ?? '') === '' || str_starts_with($href, 'entry:')) return $href;
        return (string) preg_replace('~/' . preg_quote((string) $r['slug_old'], '~') . '(?=$|[/?#])~', '/' . $r['slug_new'], $href);
    }

    /** Selbsttest: Verweise ja, Fließtext nein */
    public static function selftest(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Verweise – ');
        $refs = [['handle' => 'gruppen', 'id' => 4, 'old' => 'Bergheide', 'new' => 'Gruppe Nord', 'slug_old' => 'bergheide', 'slug_new' => 'gruppe-nord', 'prefix' => 'Gruppe']];
        $eq('Fließtext bleibt', [self::text('Kalamitätsfläche mit Gras in der Bergheide 2016', $refs), self::text('Südliche Bergheide – Wanderung', $refs),
            self::text('<p>über die A45 durch die Bergheide</p>', $refs)],
            ['Kalamitätsfläche mit Gras in der Bergheide 2016', 'Südliche Bergheide – Wanderung', '<p>über die A45 durch die Bergheide</p>']);
        $eq('Link auf den Eintrag', [self::text('<p>Siehe <a href="/gruppen/bergheide" data-link="entry:gruppen:4">Gruppe Bergheide</a> und die Bergheide</p>', $refs),
            self::text('<a href="/gruppen/bergheide">Bergheide</a>', $refs)],
            ['<p>Siehe <a href="/gruppen/gruppe-nord" data-link="entry:gruppen:4">Gruppe Nord</a> und die Bergheide</p>', '<a href="/gruppen/gruppe-nord">Gruppe Nord</a>']);
        $eq('Wert genau der Name, Linkfeld mit Beschriftung', [self::apply(['titel' => 'Bergheide', 'button_link' => 'entry:gruppen:4', 'button_label' => 'Zur Gruppe Bergheide',
            'text' => 'Wir wandern in der Bergheide.'], $refs)],
            [['titel' => 'Gruppe Nord', 'button_link' => 'entry:gruppen:4', 'button_label' => 'Zur Gruppe Nord', 'text' => 'Wir wandern in der Bergheide.']]);
        $eq('Anderer Eintrag bleibt', self::text('<a href="entry:gruppen:40">Bergheide</a>', $refs), '<a href="entry:gruppen:40">Bergheide</a>');
        return ['ok' => $ok, 'fails' => $fails];
    }
}
