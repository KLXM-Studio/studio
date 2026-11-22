<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

use Core\Contrast;
use Core\Design;

/**
 * Farbpaletten aus einer Akzentfarbe (Schnelleinrichtung → Erscheinungsbild, site:create --color): nicht nur „accent“, sondern
 * alle abhängigen Farben einer Vorlage – kräftiger Akzent, Schrift auf Akzent, Hervorhebung, zweite Markenfarbe, dunkle Abschnitte,
 * getönte Flächen, Linien – hell und dunkel. Kit-unabhängig über die Design-Tokens des Kits: gesetzt wird nur, was das Kit kennt
 * (fluid, native und andere Kits mit diesen Token-Namen); Hintergrund, Überschriften und Fließtext bleiben.
 *
 * Danach prüft fix() jede gesetzte Farbe gegen die Kontrast-Vorgabe ihres Tokens ('contrast' => ['with', 'min', 'dark_with'] in
 * theme.php/design.php, sonst 4,5 für Text bzw. 3 für Flächen) und verschiebt sie notfalls Richtung Schwarz bzw. Weiß.
 * Die Website der Quelle wird nie verändert – Paletten wirken nur auf die neue Website.
 */
final class Palette
{
    /** Varianten: Schlüssel → [Bezeichnung, Beschreibung] */
    public const VARIANTS = [
        'balanced' => ['Ausgewogen', 'Dunkle Abschnitte in einem tiefen Ton der Farbe, zarte Flächen, zweite Farbe benachbart.'],
        'vivid' => ['Kräftig', 'Dunkle Abschnitte deutlich farbig, Flächen stärker getönt, zweite Farbe als Gegenton.'],
        'calm' => ['Ruhig', 'Neutrale dunkle Abschnitte und Flächen, Farbe nur für Akzente.'],
        'contrast' => ['Hoher Kontrast', 'Sehr dunkle Abschnitte, kräftiger Akzent, gut lesbar auch bei Sonnenlicht.'],
    ];
    /** Tokens, die eine Palette setzt (sofern das Kit sie hat) */
    public const TOKENS = ['accent', 'accent_strong', 'on_accent', 'highlight', 'secondary', 'on_secondary', 'surface', 'line', 'dark_section'];

    public static function label(string $variant): string
    {
        return __(self::VARIANTS[$variant][0] ?? $variant);
    }

    public static function description(string $variant): string
    {
        return __(self::VARIANTS[$variant][1] ?? '');
    }

    /** #RRGGBB → [h 0–360, s 0–1, l 0–1] */
    public static function hsl(string $hex): array
    {
        [$r, $g, $b] = Contrast::rgb($hex);   // Anteile 0–1
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) return [0.0, 0.0, $l];
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) { $r => ($g - $b) / $d + ($g < $b ? 6 : 0), $g => ($b - $r) / $d + 2, default => ($r - $g) / $d + 4 };
        return [$h * 60, $s, $l];
    }

    /** [h, s, l] → #RRGGBB */
    public static function hex(float $h, float $s, float $l): string
    {
        $h = fmod(fmod($h, 360) + 360, 360) / 360;
        $s = max(0.0, min(1.0, $s));
        $l = max(0.0, min(1.0, $l));
        if ($s == 0.0) { $v = (int) round($l * 255); return sprintf('#%02X%02X%02X', $v, $v, $v); }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $f = function (float $t) use ($p, $q): float {
            if ($t < 0) $t += 1;
            if ($t > 1) $t -= 1;
            return match (true) { $t < 1 / 6 => $p + ($q - $p) * 6 * $t, $t < 1 / 2 => $q, $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6, default => $p };
        };
        return sprintf('#%02X%02X%02X', (int) round($f($h + 1 / 3) * 255), (int) round($f($h) * 255), (int) round($f($h - 1 / 3) * 255));
    }

    /**
     * Farben einer Variante aus der Akzentfarbe (ohne Kit-Bezug): Schlüssel wie die Tokens, dunkle Werte mit „@dark“.
     * $bg/$bgDark: Hintergrund hell bzw. dunkel des Kits (Bezug für Tönungen).
     */
    public static function derive(string $accent, string $variant, string $bg = '#FFFFFF', string $bgDark = '#0B0C14'): array
    {
        $a = Design::readable($accent, $bg, 4.5);
        [$h, $s, $l] = self::hsl($a);
        // Stufen je Variante: Anteil Schwarz in dunklen Abschnitten, Tönung der Flächen/Linien/Hervorhebung, Lage der zweiten Farbe
        [$darkMix, $surf, $line, $hi, $secShift, $secSat] = match ($variant) {
            'vivid' => [55, 10, 24, 30, 150, 1.0],
            'calm' => [90, 3, 10, 14, 0, 0.35],
            'contrast' => [88, 4, 20, 22, 30, 0.9],
            default => [78, 6, 16, 20, 32, 0.85],
        };
        $out = [];
        $out['accent'] = $variant === 'contrast' ? Design::readable($a, $bg, 7) : $a;
        $out['accent_strong'] = Contrast::mix('#000000', $out['accent'], 22);
        $out['on_accent'] = Contrast::ratio('#FFFFFF', $out['accent']) >= 4.5 ? '#FFFFFF' : '#111111';
        $out['highlight'] = Contrast::mix($a, '#FFFFFF', $hi);
        $sec = $variant === 'calm' ? self::hex($h, $s * $secSat, min(0.42, $l)) : self::hex($h + $secShift, $s * $secSat, min(0.40, max(0.28, $l)));
        $out['secondary'] = Design::readable($sec, $bg, 3);
        $out['on_secondary'] = Contrast::ratio('#FFFFFF', $out['secondary']) >= 4.5 ? '#FFFFFF' : '#111111';
        $out['surface'] = Contrast::mix($variant === 'calm' ? self::hex($h, 0.12, 0.5) : $a, '#FFFFFF', $surf);
        $out['line'] = Contrast::mix($variant === 'calm' ? self::hex($h, 0.12, 0.5) : $a, '#FFFFFF', $line);
        $out['dark_section'] = Contrast::mix('#000000', $variant === 'calm' ? self::hex($h, 0.25, 0.3) : $a, $darkMix);
        // Dunkles Farbschema: Akzent aufgehellt, Flächen und Abschnitte als Tönung des dunklen Hintergrunds
        $out['accent@dark'] = Design::readable(Contrast::mix('#FFFFFF', $a, 45), $bgDark, 4.5);
        $out['accent_strong@dark'] = Contrast::mix('#FFFFFF', $a, 62);
        $out['on_accent@dark'] = Contrast::ratio('#FFFFFF', $out['accent@dark']) >= 4.5 ? '#FFFFFF' : '#111111';
        $out['highlight@dark'] = Contrast::mix($a, $bgDark, (int) round($hi * 1.2));
        $out['secondary@dark'] = Design::readable(Contrast::mix('#FFFFFF', $out['secondary'], 45), $bgDark, 3);
        $out['on_secondary@dark'] = Contrast::ratio('#FFFFFF', $out['secondary@dark']) >= 4.5 ? '#FFFFFF' : '#111111';
        $out['surface@dark'] = Contrast::mix($a, $bgDark, $surf + 4);
        $out['line@dark'] = Contrast::mix($a, $bgDark, $line + 6);
        $out['dark_section@dark'] = Contrast::mix($variant === 'calm' ? self::hex($h, 0.2, 0.4) : $a, $bgDark, $variant === 'vivid' ? 30 : 16);
        return $out;
    }

    /**
     * Palette auf die Design-Werte des aktiven Kits abbilden: nur vorhandene Tokens, dann Kontrastprüfung je Token.
     * @param array $current bisherige Werte (Design::values()) @return array{values: array, set: list<string>, fixed: list<string>}
     */
    public static function forKit(string $accent, string $variant, ?array $current = null): array
    {
        $tokens = Design::tokens();
        $current ??= Design::values();
        $bg = Design::color($current['background'] ?? '') ?? '#FFFFFF';
        $bgDark = Design::color($current['background@dark'] ?? '') ?? '#0B0C14';
        $derived = self::derive($accent, $variant, $bg, $bgDark);
        $vals = $current;
        $set = [];
        foreach ($derived as $k => $v) {
            $name = str_ends_with($k, '@dark') ? substr($k, 0, -5) : $k;
            if (!isset($tokens[$name]) || ($tokens[$name]['type'] ?? 'color') !== 'color') continue;
            if (str_ends_with($k, '@dark') && !isset($tokens[$name]['dark'])) continue;
            $vals[$k] = $v;
            $set[] = $k;
        }
        [$vals, $fixed] = self::fix($vals, $tokens, $set);
        return ['values' => $vals, 'set' => $set, 'fixed' => $fixed];
    }

    /**
     * Kontrast je gesetztem Token sicherstellen: Vorgabe des Tokens (contrast.with/min, dunkel: dark_with bzw. with@dark), ohne Vorgabe
     * nichts. Verschiebt den Token-Wert Richtung Schwarz bzw. Weiß (Design::readable). @return array{0: array, 1: list<string>}
     */
    public static function fix(array $vals, array $tokens, array $only): array
    {
        $fixed = [];
        $color = fn(string $ref, bool $dark) => Design::color($ref) ?? Design::color((string) ($dark ? ($vals[$ref . '@dark'] ?? $vals[$ref] ?? '') : ($vals[$ref] ?? ''))) ?? null;
        foreach ($only as $k) {
            $dark = str_ends_with($k, '@dark');
            $name = $dark ? substr($k, 0, -5) : $k;
            $c = (array) ($tokens[$name]['contrast'] ?? []);
            if (!isset($c['with'])) continue;
            $ref = $color((string) ($dark && isset($c['dark_with']) ? $c['dark_with'] : $c['with']), $dark);
            $v = Design::color((string) ($vals[$k] ?? ''));
            if ($ref === null || $v === null) continue;
            $min = (float) ($c['min'] ?? 4.5);
            $new = Design::readable($v, $ref, $min);
            if ($new !== $v) { $vals[$k] = $new; $fixed[] = $k; }
        }
        return [$vals, $fixed];
    }

    /** Alle Varianten für eine Farbe (Auswahl und Live-Vorschau der Schnelleinrichtung) @return list<array{key: string, label: string, description: string, colors: array<string, string>}> */
    public static function options(string $accent): array
    {
        $out = [];
        foreach (array_keys(self::VARIANTS) as $v) {
            $p = self::forKit($accent, $v);
            $colors = [];
            foreach (array_merge(self::TOKENS, array_map(fn($t) => $t . '@dark', self::TOKENS), ['background', 'ink', 'text', 'background@dark', 'ink@dark', 'text@dark']) as $k) {
                if (isset($p['values'][$k]) && is_string($p['values'][$k])) $colors[$k] = $p['values'][$k];
            }
            $out[] = ['key' => $v, 'label' => self::label($v), 'description' => self::description($v), 'colors' => $colors];
        }
        return $out;
    }

    /**
     * Palette speichern (Design-Werte des aktiven Kits dieser Website) – nur in der neuen Website, nie in der Quelle.
     * @return array{color: string, adjusted: bool, set: list<string>, fixed: list<string>}
     */
    public static function apply(string $accent, string $variant = 'balanced'): array
    {
        if (!isset(self::VARIANTS[$variant])) $variant = 'balanced';
        $p = self::forKit($accent, $variant);
        if (!$p['set']) return ['color' => $accent, 'adjusted' => false, 'set' => [], 'fixed' => []];
        app()->settings->set('design.' . app()->theme->name, Design::normalize($p['values']));
        Design::override(null);
        \Core\PageCache::clear();
        $final = (string) ($p['values']['accent'] ?? $accent);
        return ['color' => $final, 'adjusted' => strtoupper($final) !== strtoupper($accent), 'set' => $p['set'], 'fixed' => $p['fixed']];
    }

    /** Selbsttest: jede Variante erfüllt die Kontraste (Text 4,5, UI 3) – ohne Kit, mit fluid-ähnlichen Vorgaben */
    public static function selftest(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Palette – ');
        $eq('HSL hin und zurück', self::hex(...self::hsl('#B84506')), '#B84506');
        $tokens = ['accent' => ['type' => 'color', 'dark' => '#A5B4FC', 'contrast' => ['with' => 'background', 'min' => 4.5]],
            'on_accent' => ['type' => 'color', 'dark' => '#14123A', 'contrast' => ['with' => 'accent', 'min' => 4.5]],
            'secondary' => ['type' => 'color', 'dark' => '#5EEAD4', 'contrast' => ['with' => 'background', 'min' => 3]],
            'surface' => ['type' => 'color', 'dark' => '#141621', 'contrast' => ['with' => 'text', 'min' => 4.5]],
            'dark_section' => ['type' => 'color', 'dark' => '#1A1D33', 'contrast' => ['with' => '#FFFFFF', 'min' => 7, 'dark_with' => '#FFFFFF']]];
        foreach (['#FFEB3B', '#0F766E', '#B00020', '#7DD3FC', '#222222'] as $c) {
            foreach (array_keys(self::VARIANTS) as $v) {
                $vals = ['background' => '#FFFFFF', 'background@dark' => '#0B0C14', 'text' => '#2B2F3A', 'text@dark' => '#D2D5DE'] + self::derive($c, $v);
                [$vals] = self::fix($vals, $tokens, array_keys($vals));
                $r = [round(Contrast::ratio($vals['accent'], '#FFFFFF'), 1) >= 4.5, Contrast::ratio($vals['on_accent'], $vals['accent']) >= 4.5,
                    Contrast::ratio($vals['secondary'], '#FFFFFF') >= 3, Contrast::ratio('#FFFFFF', $vals['dark_section']) >= 7,
                    Contrast::ratio($vals['surface'], '#2B2F3A') >= 4.5, Contrast::ratio($vals['accent@dark'], '#0B0C14') >= 4.5,
                    Contrast::ratio('#FFFFFF', $vals['dark_section@dark']) >= 7];
                $eq("$c $v: Akzent, Schrift auf Akzent, zweite Farbe, dunkle Abschnitte, Fläche, dunkel", $r, [true, true, true, true, true, true, true]);
            }
        }
        $eq('Varianten unterscheiden sich', count(array_unique(array_map(fn($v) => self::derive('#0F766E', $v)['dark_section'], array_keys(self::VARIANTS)))), 4);
        return ['ok' => $ok, 'fails' => $fails];
    }
}
