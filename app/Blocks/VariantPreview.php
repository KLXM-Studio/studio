<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blocks;

/**
 * Schemabilder für Block-Varianten: Die Auswahl „Variante“ in der Seitenleiste erscheint als Kacheln mit kleiner Strichzeichnung
 * (Text, Bild, Karten, Raster …) statt als Liste – über die Kacheln von Core\Fields ('tiles', resources/js/_tiles.js).
 *
 * Zuordnung je Variante: theme.php des Kits → Block 'variant_previews' => ['variante' => 'schema'], sonst nach dem Schlüssel der
 * Variante (MAP, z. B. 'split' → Text links, Bild rechts). Hat eine Variante kein Schema, bleibt es für den ganzen Block bei der
 * Liste – lieber keine Bilder als falsche. Die Bilder sind schematisch (gleich für alle Kits), die Beschriftung kommt vom Kit.
 */
final class VariantPreview
{
    /** Schlüssel der Variante → Schema (bekannte Namen der mitgelieferten Kits) */
    public const MAP = [
        'split' => 'split', 'right' => 'split', 'auto' => 'split', 'overlap' => 'split',
        'left' => 'split_rev',
        'center' => 'center', 'centered' => 'center', 'stack' => 'center', 'stacked' => 'stacked',
        'fullbleed' => 'cover', 'cover' => 'cover', 'image' => 'cover', 'bleed' => 'cover', 'photo' => 'cover', 'video' => 'cover',
        'aurora' => 'cover', 'silk' => 'cover', 'color' => 'cover', 'cinema' => 'wide',
        'cards' => 'cards', 'overlay' => 'cards', 'glass' => 'cards', 'product' => 'cards', 'service' => 'cards', 'horizontal' => 'cards',
        'type' => 'type', 'statement' => 'type', 'big' => 'type', 'voice' => 'type', 'issue' => 'type',
        'compact' => 'header', 'minimal' => 'header', 'plain' => 'header', 'standard' => 'header', 'text' => 'header', 'article' => 'header',
        'scale' => 'aside', 'side' => 'aside', 'margin' => 'aside',
        'collage' => 'collage', 'mosaic' => 'collage', 'bento' => 'collage', 'masonry' => 'collage', 'justified' => 'collage',
        'compare' => 'compare',
        'grid' => 'grid', 'icons' => 'grid', 'numbered' => 'grid', 'figures' => 'grid',
        'dials' => 'row', 'row' => 'row', 'rings' => 'row', 'numbers' => 'row',
        'list' => 'list', 'rows' => 'list', 'stream' => 'list', 'dates' => 'list', 'agenda' => 'list', 'brief' => 'list',
        'timeline' => 'timeline', 'steps' => 'timeline', 'process' => 'timeline',
        'columns' => 'columns',
        'band' => 'band', 'box' => 'box', 'panel' => 'box', 'newsletter' => 'band', 'form' => 'box', 'search' => 'box',
        'wide' => 'wide', '16-9' => 'wide', '21-9' => 'wide', '4-3' => 'wide', 'single' => 'wide', 'slideshow' => 'wide',
        'strip' => 'strip', 'reel' => 'strip', 'marquee' => 'strip',
        'table' => 'table', 'accordion' => 'accordion', 'top' => 'tabs', 'stage' => 'tabs',
    ];

    /** Schemata: Bausteine auf 64 × 40 – t = Textzeilen, i = Bild, b = Button, c = Karte, l = Linie */
    private const SHAPES = [
        'split' => [['t', 4, 9, 26, 3], ['b', 4, 27], ['i', 36, 5, 24, 30]],
        'split_rev' => [['i', 4, 5, 24, 30], ['t', 34, 9, 26, 3], ['b', 34, 27]],
        'center' => [['t', 14, 3, 36, 2, 'c'], ['b', 26, 16], ['i', 8, 24, 48, 13]],
        'stacked' => [['t', 4, 4, 56, 2], ['t', 4, 15, 56, 2], ['t', 4, 26, 56, 2]],
        'cover' => [['i', 0, 0, 64, 40], ['t', 8, 13, 30, 2, 'on'], ['b', 8, 25, 'on']],
        'cards' => [['i', 0, 0, 64, 40], ['c', 36, 6, 22, 14], ['c', 40, 22, 20, 13], ['t', 6, 14, 22, 2, 'on']],
        'type' => [['T', 6, 8, 52], ['T', 6, 20, 40], ['t', 6, 32, 30, 1]],
        'header' => [['t', 6, 10, 40, 1, 'h'], ['t', 6, 20, 52, 2]],
        'aside' => [['T', 4, 8, 34], ['t', 4, 22, 34, 2], ['l', 44, 6, 34], ['t', 47, 8, 14, 4]],
        'collage' => [['i', 4, 4, 22, 18], ['i', 28, 4, 14, 32], ['i', 44, 4, 16, 14], ['i', 4, 24, 22, 12], ['c', 44, 20, 16, 16]],
        'compare' => [['i', 4, 4, 28, 32], ['i2', 32, 4, 28, 32], ['l', 32, 2, 36], ['k', 32, 20]],
        'grid' => [['c', 4, 6, 17, 13], ['c', 23, 6, 18, 13], ['c', 43, 6, 17, 13], ['c', 4, 21, 17, 13], ['c', 23, 21, 18, 13], ['c', 43, 21, 17, 13]],
        'row' => [['o', 12, 20], ['o', 32, 20], ['o', 52, 20]],
        'list' => [['i', 4, 5, 9, 8], ['t', 16, 6, 40, 1], ['i', 4, 16, 9, 8], ['t', 16, 17, 40, 1], ['i', 4, 27, 9, 8], ['t', 16, 28, 40, 1]],
        'timeline' => [['l', 10, 4, 32], ['o', 10, 9, 2.5], ['t', 16, 8, 40, 1], ['o', 10, 20, 2.5], ['t', 16, 19, 40, 1], ['o', 10, 31, 2.5], ['t', 16, 30, 40, 1]],
        'columns' => [['t', 4, 8, 17, 5], ['t', 24, 8, 17, 5], ['t', 44, 8, 16, 5]],
        'band' => [['c', 0, 8, 64, 24], ['t', 8, 15, 30, 2, 'on'], ['b', 44, 17, 'on']],
        'box' => [['c', 10, 6, 44, 28], ['t', 16, 12, 32, 2, 'c'], ['b', 26, 24]],
        'wide' => [['i', 4, 6, 56, 28]],
        'strip' => [['i', 0, 12, 18, 16], ['i', 21, 12, 22, 16], ['i', 46, 12, 18, 16]],
        'table' => [['l', 4, 9, 0, 56], ['t', 4, 12, 56, 1], ['l', 4, 18, 0, 56], ['t', 4, 21, 56, 1], ['l', 4, 27, 0, 56], ['t', 4, 30, 56, 1]],
        'accordion' => [['c', 4, 4, 56, 9], ['c', 4, 15, 56, 9], ['c', 4, 26, 56, 9]],
        'tabs' => [['c', 4, 4, 16, 6], ['c', 22, 4, 16, 6], ['c', 40, 4, 16, 6], ['t', 4, 16, 56, 3]],
    ];

    /** Schemata zu den Varianten eines Blocks – oder null, wenn nicht jede Variante eines hat @return ?array<string, string> */
    public static function map(array $def, array $variants): ?array
    {
        $own = (array) ($def['variant_previews'] ?? []);
        $out = [];
        foreach (array_keys($variants) as $k) {
            $s = (string) ($own[$k] ?? self::MAP[(string) $k] ?? '');
            if ($s === '' || !isset(self::SHAPES[$s])) return null;
            $out[(string) $k] = $s;
        }
        return count(array_unique($out)) > 1 ? $out : null;   // alle gleich: Bilder helfen nicht
    }

    /** SVG eines Schemas (dekorativ, aria-hidden) */
    public static function svg(string $shape): string
    {
        $h = '<svg class="f-tile__svg" viewBox="0 0 64 40" aria-hidden="true" focusable="false">';
        foreach (self::SHAPES[$shape] ?? [] as $p) {
            $on = in_array('on', $p, true);
            switch ($p[0]) {
                case 'i': case 'i2':
                    $h .= sprintf('<rect class="vp-%s" x="%s" y="%s" width="%s" height="%s" rx="2"/>', $p[0] === 'i2' ? 'img2' : 'img', $p[1], $p[2], $p[3], $p[4]);
                    break;
                case 'c':
                    $h .= sprintf('<rect class="vp-card" x="%s" y="%s" width="%s" height="%s" rx="2"/>', $p[1], $p[2], $p[3], $p[4]);
                    break;
                case 'b':
                    $h .= sprintf('<rect class="vp-btn%s" x="%s" y="%s" width="12" height="5" rx="2.5"/>', $on ? ' vp-on' : '', $p[1], $p[2]);
                    break;
                case 'o':
                    $h .= sprintf('<circle class="vp-dot" cx="%s" cy="%s" r="%s"/>', $p[1], $p[2], $p[3] ?? 7);
                    break;
                case 'k':
                    $h .= sprintf('<circle class="vp-knob" cx="%s" cy="%s" r="3.5"/>', $p[1], $p[2]);
                    break;
                case 'l':
                    // ['l', x, y, Länge senkrecht] bzw. ['l', x, y, 0, Länge waagerecht]
                    $h .= isset($p[4]) ? sprintf('<line class="vp-line" x1="%s" y1="%s" x2="%s" y2="%s"/>', $p[1], $p[2], $p[1] + $p[4], $p[2])
                        : sprintf('<line class="vp-line" x1="%s" y1="%s" x2="%s" y2="%s"/>', $p[1], $p[2], $p[1], $p[2] + $p[3]);
                    break;
                case 'T':
                    $h .= sprintf('<rect class="vp-head" x="%s" y="%s" width="%s" height="7" rx="1.5"/>', $p[1], $p[2], $p[3]);
                    break;
                case 't':
                    [, $x, $y, $w, $n] = $p;
                    $center = in_array('c', array_slice($p, 5), true);
                    $head = in_array('h', array_slice($p, 5), true);
                    // erste Zeile kräftiger (Überschrift), danach Textzeilen
                    $h .= sprintf('<rect class="vp-head%s" x="%s" y="%s" width="%s" height="%s" rx="1.2"/>', $on ? ' vp-on' : '', $center ? $x + $w * .1 : $x, $y, $center ? $w * .8 : $w * .85, $head ? 5 : 3.5);
                    for ($i = 1; $i <= $n; $i++) {
                        $lw = $i === $n ? $w * .6 : $w;
                        $h .= sprintf('<rect class="vp-text%s" x="%s" y="%s" width="%s" height="1.8" rx=".9"/>', $on ? ' vp-on' : '', $center ? $x + ($w - $lw) / 2 : $x, $y + 3 + $i * 3.2 + ($head ? 2 : 0), $lw);
                    }
                    break;
            }
        }
        return $h . '</svg>';
    }
}
