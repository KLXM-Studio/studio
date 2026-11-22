<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/**
 * Video-Links in der Lightbox: Links auf YouTube- und Vimeo-Videos (Buttons der Blöcke, Rich-Text, Kit-Vorlagen) öffnen nach
 * Einwilligung den Player in der Lightbox des Kerns statt der Seite des Anbieters.
 *
 *  - Website-Einstellung (Website → Darstellung, Kern ergänzt die Gruppe des Kits): video_lightbox (Standard aus) und
 *    video_lightbox_hint (Standard aus: ohne Einwilligung Zwei-Klick-Hinweis in der Lightbox statt normalem Link).
 *  - Aus: Ausgabe unverändert – kein Attribut, kein Skript, keine Zusatzfelder im Editor.
 *  - An: inject() hängt an jeden erkannten Video-Link data-video-lb="{anbieter}" + data-video-id="{id}" (vor dem Seiten-Cache)
 *    und bindet js/video-lightbox.js ein – nur, wenn die Seite solche Links hat. Das Skript prüft die Einwilligung beim Klick
 *    (window.cmsConsent.embed(anbieter) der Erweiterung consent_kit; ohne Dienst die Freigabe „künftig direkt laden“ des
 *    Video-Blocks, resources/js/embed.js). Ohne Einwilligung bleibt der Link ein normaler Link (bzw. Zwei-Klick-Hinweis).
 *  - Abschalten je Link: data-video-lb="off" am <a> (Rich-Text: Linkauswahl „Ohne Lightbox öffnen“, Sanitizer-Vertrag);
 *    je Button-Feld: Zusatzfeld „{feld}_nolb“ (blockFields, nur bei eingeschalteter Einstellung, sichtbar bei Video-Adressen),
 *    ausgewertet beim Rendern des Blocks (optOut).
 *  Erkannt werden nur Seiten-Links: youtube.com/watch?v=ID, youtube.com/shorts/ID, youtu.be/ID, vimeo.com/ID.
 */
final class VideoLinks
{
    public const SETTING = 'video_lightbox';
    public const HINT = 'video_lightbox_hint';
    /** Endung des Zusatzfelds an Link-Feldern der Blöcke: „Ohne Lightbox öffnen“ */
    public const SUFFIX = '_nolb';

    /** Für Tests: Einstellung erzwingen (null = gespeicherter Wert) */
    public static ?bool $force = null;
    public static ?bool $forceHint = null;

    public static function on(): bool
    {
        if (self::$force !== null) return self::$force;
        try {
            return (bool) app()->settings->get(self::SETTING, false);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function hint(): bool
    {
        if (self::$forceHint !== null) return self::$forceHint;
        try {
            return (bool) app()->settings->get(self::HINT, false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Video eines Links: nur Seiten-Adressen der Anbieter (keine Player- oder Kanal-Links, keine reinen IDs).
     * @return array{provider: string, id: string}|null
     */
    public static function parse(string $href): ?array
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
        if (!preg_match('~^https?://~i', $href)) return null;
        $u = parse_url($href);
        if (!is_array($u) || empty($u['host'])) return null;
        $host = strtolower((string) $u['host']);
        $path = (string) ($u['path'] ?? '');
        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            if ($path === '/watch') {
                parse_str((string) ($u['query'] ?? ''), $q);
                $id = is_string($q['v'] ?? null) ? $q['v'] : '';
                return preg_match('~^[\w-]{11}$~', $id) ? ['provider' => 'youtube', 'id' => $id] : null;
            }
            return preg_match('~^/shorts/([\w-]{11})/?$~', $path, $m) ? ['provider' => 'youtube', 'id' => $m[1]] : null;
        }
        if ($host === 'youtu.be') {
            return preg_match('~^/([\w-]{11})/?$~', $path, $m) ? ['provider' => 'youtube', 'id' => $m[1]] : null;
        }
        if ($host === 'vimeo.com' || $host === 'www.vimeo.com') {
            return preg_match('~^/(\d{6,12})/?$~', $path, $m) ? ['provider' => 'vimeo', 'id' => $m[1]] : null;
        }
        return null;
    }

    /** Felder der Website-Einstellungen (Gruppe „Darstellung“) */
    public static function settingsFields(): array
    {
        return [
            ['type' => 'heading', 'label' => __('Video-Links')],
            ['name' => self::SETTING, 'label' => __('Video-Links in Lightbox öffnen'), 'type' => 'bool', 'default' => false, 'translate' => false,
                'help' => __('Links und Buttons auf YouTube- oder Vimeo-Videos öffnen den Player in einer Lightbox auf der Website – nur, wenn Besucher in den Dienst eingewilligt haben (Cookie-Einwilligung bzw. „Videos künftig direkt laden“). Ohne Einwilligung bleibt es ein normaler Link. Einzelne Buttons und Links lassen sich ausnehmen.')],
            ['name' => self::HINT, 'label' => __('Ohne Einwilligung Zwei-Klick-Hinweis in der Lightbox zeigen'), 'type' => 'bool', 'default' => false, 'translate' => false,
                'show_if' => [self::SETTING => ['1']],
                'help' => __('Statt den Link normal zu öffnen, erklärt die Lightbox zuerst, dass der Anbieter Daten erhält; das Video lädt erst nach Klick. Vorschaubild nur, wenn es lokal zwischengespeichert ist.')],
        ];
    }

    /** Website-Einstellungen: Felder an die Gruppe „Darstellung“ des Kits hängen (sonst eigene Gruppe) */
    public static function extendGroups(array $groups): array
    {
        $add = self::settingsFields();
        foreach ($groups as $g) {
            foreach ((array) ($g['fields'] ?? []) as $f) if (($f['name'] ?? '') === self::SETTING) return $groups;   // Kit bringt sie selbst mit
        }
        foreach ($groups as $i => $g) {
            if (($g['id'] ?? '') === 'darstellung') {
                $groups[$i]['fields'] = [...(array) ($g['fields'] ?? []), ...$add];
                return $groups;
            }
        }
        $groups[] = ['id' => 'darstellung', 'label' => __('Darstellung'), 'fields' => $add];
        return $groups;
    }

    /**
     * Block-Felder: hinter jedem Link-Feld (auch in Wiederholgruppen) das Zusatzfeld „Ohne Lightbox öffnen“ – nur bei
     * eingeschalteter Einstellung und nur sichtbar, wenn das Link-Feld eine Video-Adresse enthält (show_if '~video', admin.js).
     */
    public static function blockFields(array $fields): array
    {
        $out = [];
        $names = array_column($fields, 'name');
        foreach ($fields as $f) {
            if (($f['type'] ?? '') === 'repeater' && is_array($f['fields'] ?? null)) $f['fields'] = self::blockFields($f['fields']);
            $out[] = $f;
            $n = (string) ($f['name'] ?? '');
            if (($f['type'] ?? '') === 'link' && $n !== '' && !in_array($n . self::SUFFIX, $names, true)) {
                $out[] = ['name' => $n . self::SUFFIX, 'label' => __('Video ohne Lightbox öffnen (normaler Link)'), 'type' => 'bool', 'default' => false,
                    'translate' => false, 'show_if' => [$n => ['~video']]];
            }
        }
        return $out;
    }

    /**
     * Block-Ausgabe: Links auf Videos, deren Link-Feld „Ohne Lightbox öffnen“ trägt, mit data-video-lb="off" kennzeichnen.
     * Zuerst über die genaue Adresse; steht sie so nicht im HTML (Kit-Helfer schreibt sie um), über Anbieter + ID.
     */
    public static function optOut(string $html, array $data): string
    {
        if (!self::on() || !str_contains($html, '<a')) return $html;
        $off = self::optedOut($data);
        if (!$off) return $html;
        preg_match_all('~<a\s[^>]*?\bhref="([^"]+)"~i', $html, $m);
        $present = array_flip(array_map(fn($h) => html_entity_decode($h, ENT_QUOTES | ENT_HTML5), $m[1]));
        $byId = [];
        foreach ($off as $href => $id) if (!isset($present[$href])) $byId[$id] = true;   // Adresse umgeschrieben → über die ID
        return preg_replace_callback('~<a\s(?![^>]*\bdata-video-lb=)[^>]*?\bhref="([^"]+)"[^>]*>~i', function (array $x) use ($off, $byId): string {
            $href = html_entity_decode($x[1], ENT_QUOTES | ENT_HTML5);
            $v = self::parse($href);
            $hit = isset($off[$href]) || ($v && isset($byId[$v['provider'] . ':' . $v['id']]));
            return $hit ? substr($x[0], 0, -1) . ' data-video-lb="off">' : $x[0];
        }, $html) ?? $html;
    }

    /** @return array<string, string> Adresse → „anbieter:id“ der ausgenommenen Video-Links eines Blocks (auch in Wiederholgruppen) */
    public static function optedOut(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) { $out += self::optedOut($v); continue; }
            if (!is_string($k) || !str_ends_with($k, self::SUFFIX) || !filter_var($v, FILTER_VALIDATE_BOOL)) continue;
            $link = $data[substr($k, 0, -strlen(self::SUFFIX))] ?? null;
            if (is_string($link) && ($p = self::parse($link))) $out[trim($link)] = $p['provider'] . ':' . $p['id'];
        }
        return $out;
    }

    /**
     * Erkannte Video-Links kennzeichnen. Ausgenommen: Links mit data-video-lb (z. B. „off“), der Link „Auf … ansehen“ der
     * Zwei-Klick-Lösung (.vembed__ext) und alles in <script>, <template>, <noscript>, <textarea> und <consent-embed>.
     */
    public static function enhance(string $html): string
    {
        if (!str_contains($html, 'youtu') && !str_contains($html, 'vimeo.com')) return $html;
        $parts = preg_split('~(<(script|template|noscript|textarea|consent-embed)\b.*?</\2\s*>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return $html;
        $hint = self::hint();
        $out = '';
        for ($i = 0, $n = count($parts); $i < $n; $i++) {
            if ($i % 3 === 2) continue;                        // Name des ausgenommenen Elements (zweite Klammer)
            if ($i % 3 === 1) { $out .= $parts[$i]; continue; } // ausgenommener Bereich unverändert
            $out .= preg_replace_callback('~<a\s(?![^>]*\bdata-video-lb=)[^>]*?\bhref="([^"]+)"[^>]*>~i', function (array $x) use ($hint): string {
                if (preg_match('~\bclass="[^"]*\bvembed__ext\b~', $x[0])) return $x[0];
                $v = self::parse($x[1]);
                if (!$v) return $x[0];
                $attrs = ' data-video-lb="' . $v['provider'] . '" data-video-id="' . e($v['id']) . '"';
                if ($hint && ($poster = self::poster($v))) $attrs .= ' data-video-poster="' . e($poster) . '"';
                return substr($x[0], 0, -1) . $attrs . '>';
            }, $parts[$i]) ?? $parts[$i];
        }
        return $out;
    }

    /** Lokal zwischengespeichertes Vorschaubild (Core\Embeds, Server-Proxy) – null, wenn keins vorliegt */
    private static function poster(array $v): ?string
    {
        try {
            return Embeds::meta($v)['poster_small'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Seite ausliefern: Links kennzeichnen und Skript einbinden – bei ausgeschalteter Einstellung unverändert */
    public static function inject(string $html): string
    {
        if (!self::on()) return $html;
        $html = self::enhance($html);
        if (!preg_match('~data-video-lb="(?:youtube|vimeo)"~', $html) || str_contains($html, 'data-cms-video-lb')) return $html;
        $p = Embeds::PROVIDERS;
        $tag = '<script src="' . e(asset('js/video-lightbox.js')) . '" defer data-cms-video-lb'
            . ' data-css="' . e(app()->theme->coreStylesheet('video-lightbox.css')) . '"'
            . (self::hint() ? ' data-hint="1"' : '')
            . ' data-close="' . e(lt('Schließen')) . '" data-video="' . e(lt('Video')) . '"'
            . ' data-yt="' . e($p['youtube']['label']) . '" data-yt-company="' . e($p['youtube']['company']) . '"'
            . ' data-vimeo="' . e($p['vimeo']['label']) . '" data-vimeo-company="' . e($p['vimeo']['company']) . '"'
            . (self::hint() ? ' data-t-text="' . e(lt('Beim Abspielen lädt {provider} ({company}) das Video. Dabei werden Daten wie Ihre IP-Adresse an {provider} übertragen.')) . '"'
                . ' data-t-privacy="' . e(lt('Datenschutz')) . '" data-privacy="' . e(privacy_url()) . '"'
                . ' data-t-load="' . e(lt('{provider}-Video laden')) . '" data-t-remember="' . e(lt('{provider}-Videos künftig direkt laden')) . '"'
                . ' data-t-ext="' . e(lt('Auf {provider} ansehen')) . '" data-t-newtab="' . e(lt('(öffnet in neuem Tab)')) . '"' : '')
            . '></script>';
        $pos = strripos($html, '</body>');
        return $pos === false ? $html . $tag : substr($html, 0, $pos) . $tag . substr($html, $pos);
    }
}
