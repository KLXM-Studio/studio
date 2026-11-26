<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

namespace Core\Blocks;

use Core\Block;
use Core\Media;

/**
 * Bildunterschrift für Blöcke mit einem Bild, das für sich steht (Text + Bild, Reiter, Scrollytelling, Interaktives Bild …) –
 * in allen Kits gleich: Feld „caption“ (eigener Text) und Schalter „caption_credit“ (sonst Bildnachweis aus der Mediathek).
 * Kits nehmen die Felder mit Caption::fields() in ihre Blockdefinition und setzen das Bild mit Caption::figure() bzw. die
 * Unterschrift mit Caption::html(). Stile: css/caption.css des Kerns (Klasse .blk-cap), ein Kit kann eigene mitbringen.
 */
final class Caption
{
    /** Felder für die Blockdefinition (in Repeatern je Eintrag); $extra z. B. ['variants' => [...]] */
    public static function fields(array $extra = []): array
    {
        return [
            ['name' => 'caption', 'label' => 'Bildunterschrift (optional)', 'type' => 'text', 'max' => 200, 'width' => 'half'] + $extra,
            ['name' => 'caption_credit', 'label' => 'Sonst Bildnachweis zeigen', 'type' => 'bool', 'default' => false, 'width' => 'half',
                'help' => 'Ohne eigene Unterschrift den Bildnachweis aus der Mediathek darunter zeigen.'] + $extra,
        ];
    }

    /** Text der Unterschrift: eigener Text, sonst (Schalter) Bildnachweis des Bildes */
    public static function text(array $d, ?int $media = null): string
    {
        $cap = trim((string) ($d['caption'] ?? ''));
        if ($cap === '' && !empty($d['caption_credit']) && $media && ($m = Media::find($media))) $cap = trim((string) ($m['credit'] ?? ''));
        return $cap;
    }

    /** <figcaption> oder '' – $base: Pfad im Block für die Bearbeitung auf der Seite, z. B. "items.2." */
    public static function html(Block $b, array $d, ?int $media = null, string $base = '', string $class = ''): string
    {
        $cap = self::text($d, $media);
        if ($cap === '') return '';
        $own = trim((string) ($d['caption'] ?? '')) !== '';
        return '<figcaption class="blk-cap' . ($class !== '' ? ' ' . e($class) : '') . '"' . ($own ? $b->edit($base . 'caption') : '') . '>' . e($cap) . '</figcaption>';
    }

    /**
     * Bild mit Unterschrift: ohne Unterschrift unverändert $img; sonst <figure class="blk-fig $class"> mit Bild und Unterschrift –
     * $class trägt die Layout-Klasse des Bildes (Rasterspalte), damit die Unterschrift mit dem Bild wandert.
     */
    public static function figure(string $img, Block $b, array $d, ?int $media = null, string $base = '', string $class = ''): string
    {
        if ($img === '') return '';
        $cap = self::html($b, $d, $media, $base);
        if ($cap === '') return $img;
        return '<figure class="blk-fig' . ($class !== '' ? ' ' . e($class) : '') . '">' . $img . $cap . '</figure>';
    }
}
