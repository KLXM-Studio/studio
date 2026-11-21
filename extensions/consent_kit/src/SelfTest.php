<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

namespace Klxm\Consent;

/**
 * php bin/console consent:selftest – ohne Datenbankänderung: Merkmal „nur Einbettung mit Platzhalter“ der Vorlagen, Gründe
 * am fertigen Dienst, Entscheidung „Hinweis ja/nein“ je Dienst-Konstellation, Platzhalter-HTML ohne fremde Adressen vor der
 * Einwilligung (nur im inerten <template> bzw. als Link in <noscript>).
 */
final class SelfTest
{
    private int $fail = 0;
    private int $ok = 0;

    private const EMBED_ONLY = ['youtube', 'vimeo', 'google_maps', 'openstreetmap', 'spotify', 'soundcloud', 'instagram', 'facebook', 'x_twitter', 'tiktok', 'podigee', 'microsoft_forms'];
    private const NEEDS_BANNER = ['google_analytics', 'matomo', 'google_tag_manager', 'meta_pixel', 'google_ads', 'hotjar', 'google_recaptcha', 'google_fonts', 'calendly', 'paypal', 'google_analytics_gtm'];

    public static function run(): int
    {
        $t = new self();
        $t->presets();
        $t->compiled();
        $t->banner();
        $t->markup();
        echo "\nconsent:selftest: {$t->ok} ok, {$t->fail} Fehler\n";
        return $t->fail ? 1 : 0;
    }

    private function check(bool $cond, string $label): void
    {
        if ($cond) {
            $this->ok++;
            echo "  ok     $label\n";
        } else {
            $this->fail++;
            echo "  FEHLER $label\n";
        }
    }

    private function presets(): void
    {
        echo "Vorlagen (Presets::embedOnly)\n";
        foreach (self::EMBED_ONLY as $k) {
            $p = Presets::get($k);
            $this->check($p !== null && Presets::embedOnly($p), "$k gilt als „nur Einbettung mit Platzhalter“");
        }
        foreach (self::NEEDS_BANNER as $k) {
            $p = Presets::get($k);
            $this->check($p !== null && !Presets::embedOnly($p), "$k braucht den Hinweis");
        }
        $this->check(Presets::embedOnly(['group' => 'functional', 'embed_hosts' => ['example.org'], 'embed_only' => true]), 'ausdrückliches embed_only in der Vorlage gilt');
        $this->check(!Presets::embedOnly(['group' => 'media', 'embed_hosts' => ['example.org'], 'embed_only' => false]), 'embed_only: false überschreibt die Ableitung');
    }

    /** Dienst aus der Vorlage mit ausgefüllten Angaben fertig auflösen */
    private static function compile(string $key, array $over = []): ?array
    {
        $s = Presets::toService($key);
        if (!$s) return null;
        foreach ($s['param_defs'] as $d) $s['params'][(string) $d['key']] = (string) (($d['placeholder'] ?? '') ?: 'test123');
        $s = $over + $s + ['id' => 0];
        return Compiler::compile($s, 'main', 'de');
    }

    private function compiled(): void
    {
        echo "Fertige Dienste (Compiler::embedOnlyIssues)\n";
        foreach (self::EMBED_ONLY as $k) {
            $c = self::compile($k);
            $this->check($c !== null && $c['_eo'] === [], "$k: kein Grund für den Hinweis" . ($c && $c['_eo'] ? ' (' . implode('; ', $c['_eo']) . ')' : ''));
        }
        // Auch mit gesetztem Merkmal: Statistik/Marketing erzwingt den Hinweis
        foreach (['google_analytics', 'matomo', 'meta_pixel'] as $k) {
            $c = self::compile($k, ['embed_only' => true, 'embed_hosts' => ['example.org']]);
            $this->check($c !== null && isset($c['_eo']['group']), "$k mit Merkmal: trotzdem Hinweis (Gruppe)");
        }
        $c = self::compile('youtube', ['html_body' => '<script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>']);
        $this->check($c !== null && isset($c['_eo']['code']), 'YouTube mit fremdem Code (googletagmanager.com): Hinweis');
        $c = self::compile('instagram');
        $this->check($c !== null && $c['_eo'] === [] && $c['x'], 'Instagram: embed.js vom Anbieter der Einbettung zählt nicht als fremder Code');
        $c = self::compile('youtube', ['gcm' => ['ad_storage']]);
        $this->check($c !== null && isset($c['_eo']['gcm']), 'YouTube mit Consent Mode: Hinweis');
        $c = self::compile('youtube', ['js_default' => 'window.x = 1;']);
        $this->check($c !== null && isset($c['_eo']['default']), 'YouTube mit Code vor der Einwilligung: Hinweis');
        $c = self::compile('youtube', ['embed_only' => false]);
        $this->check($c !== null && isset($c['_eo']['flag']), 'eigener Dienst ohne Merkmal: Hinweis');
        $c = self::compile('youtube', ['embed_hosts' => []]);
        $this->check($c !== null && isset($c['_eo']['embed']), 'ohne Domains eingebetteter Inhalte: Hinweis');
    }

    private function banner(): void
    {
        echo "Hinweis beim Seitenaufruf je Konstellation (Consent::bannerOff)\n";
        $svc = fn(string $grp, array $eo = []) => ['grp' => $grp, 'name' => $grp, '_eo' => $eo];
        $on = ['only_needed' => true];
        $off = ['only_needed' => false];
        $only = ['services' => ['youtube' => $svc('media')]];
        $mixed = ['services' => ['youtube' => $svc('media'), 'matomo' => $svc('statistics', ['group' => 'x'])]];
        $many = ['services' => ['youtube' => $svc('media'), 'google_maps' => $svc('media'), 'instagram' => $svc('media')]];
        $nothing = ['services' => ['own' => $svc('necessary', ['flag' => 'x'])]];
        $this->check(Consent::bannerOff($only, $on), 'nur YouTube, Einstellung an → kein Hinweis');
        $this->check(Consent::bannerOff($many, $on), 'YouTube + Karte + Instagram (alle Platzhalter) → kein Hinweis');
        $this->check(!Consent::bannerOff($mixed, $on), 'YouTube + Matomo → Hinweis');
        $this->check(Consent::bannerRequired($mixed) === ['matomo' => 'statistics'], 'der auslösende Dienst wird genannt');
        $this->check(!Consent::bannerOff($only, $off), 'nur YouTube, Einstellung aus → Hinweis wie bisher');
        $this->check(!Consent::bannerOff($nothing, $on), 'nur notwendige Dienste → nichts zu entscheiden');
        $this->check(!Consent::bannerOff(['services' => ['x' => ['grp' => 'media', 'name' => 'x']]], $on), 'Dienst ohne Prüfergebnis → Hinweis (sichere Seite)');
        $this->check(Consent::bannerOff($only, Repository::SETTING_DEFAULTS), 'Standard der Einstellung: an (nur YouTube → kein Hinweis)');
    }

    private function markup(): void
    {
        echo "Platzhalter-HTML vor der Einwilligung (Consent::embedMarkup)\n";
        $html = Consent::embedMarkup('youtube', 'YouTube', '<iframe src="https://www.youtube-nocookie.com/embed/abcdefghijk?rel=0" title="Film"></iframe>', ['title' => 'Film']);
        $outside = (string) preg_replace(['~<template>.*?</template>~s', '~<noscript>.*?</noscript>~s'], '', $html);
        $this->check(!preg_match('~https?://|//[a-z0-9.-]+\.[a-z]{2,}~i', $outside), 'außerhalb von <template>/<noscript> keine fremde Adresse');
        $this->check(!str_contains($html, 'ytimg') && !preg_match('~<img\b~i', $html), 'kein Vorschaubild (ytimg) im Platzhalter');
        $this->check((bool) preg_match('~<noscript><p[^>]*>.*<a href="https://www\.youtube\.com/watch\?v=abcdefghijk" rel="noopener" target="_blank">~s', $html), 'ohne JavaScript: Link zur Video-Seite bei YouTube (neuer Tab)');
        $tpl = (string) preg_replace('~^.*?<template>(.*)</template>.*$~s', '$1', $html);
        $this->check(str_contains($tpl, 'youtube-nocookie.com/embed/'), 'Inhalt steht unverändert im <template> (lädt erst nach Klick)');
        $map = Consent::embedMarkup('google_maps', 'Google Maps', '<iframe src="https://www.google.com/maps/embed?pb=1&amp;x=2"></iframe>');
        $this->check(str_contains($map, 'href="https://www.google.com/maps/embed?pb=1&amp;x=2"'), 'Karte: Link aus der iframe-Adresse (korrekt maskiert)');
        $bad = Consent::embedMarkup('x', 'X', '<iframe src="javascript:alert(1)"></iframe>', ['link' => 'javascript:alert(1)']);
        $this->check(!str_contains($bad, '<noscript>'), 'unsichere Adresse: kein Link');
    }
}
