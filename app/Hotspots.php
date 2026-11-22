<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

use Core\Glossary\Glossary;

/**
 * Interaktives Bild: Markierungen (Hotspots) auf einem Bild der Mediathek – gemeinsame Grundlage für den Kern-Block
 * „Interaktives Bild“ (app/Blocks/hotspots.php), den Feldtyp „hotspots“ (Blöcke und Datentabellen, Vorlage „Interaktive Bilder“)
 * und Editoren von Erweiterungen (Extension::hotspotEditor, z. B. ein Grafik-Editor).
 *
 * Datenformat, Version 1 (gespeichert als JSON; Koordinaten in Prozent der Bildbreite bzw. -höhe, 0–100, zwei Nachkommastellen):
 *
 *   {"v": 1, "items": [
 *     {"id": "h1", "shape": "point",  "x": 12.5, "y": 40,                 …Inhalt},
 *     {"id": "h2", "shape": "circle", "x": 50, "y": 50, "r": 8,            …},   r = Radius in % der Bildbreite
 *     {"id": "h3", "shape": "rect",   "x": 10, "y": 60, "w": 20, "h": 15,  …},   x/y = linke obere Ecke
 *     {"id": "h4", "shape": "poly",   "points": [[10, 10], [30, 12], [20, 30]], …}   3–64 Punkte
 *   ]}
 *   Inhalt je Markierung: "title" (Klartext), "text" (Rich-Text, Core\Sanitizer::block – keine Bilder), "link" (Link wie im
 *   Feldtyp „link“: page:12, entry:tabelle:5, https://…), "link_label", "term" (Kurzname eines Glossar-Begriffs → dessen
 *   Kurz-Erklärung), "icon" (Symbol aus Core\Icons statt der Nummer, leer = Nummer).
 *
 * Die Reihenfolge der Liste ist die Nummer (1, 2, 3 …), die Tab-Reihenfolge und die Reihenfolge der Liste unter dem Bild.
 * Flächen (circle/rect/poly) erscheinen als SVG über dem Bild; ihr Knopf mit der Nummer sitzt im Mittelpunkt (anchor()).
 * normalize() nimmt das Format, eine bloße Liste, Formularzeilen (points als „x,y x,y …“) und JSON an – Unbekanntes fällt weg.
 */
final class Hotspots
{
    public const VERSION = 1;
    public const TYPE = 'hotspots';
    public const SHAPES = ['point', 'circle', 'rect', 'poly'];
    /** Höchstzahl der Markierungen je Bild und der Punkte einer Fläche */
    public const MAX = 60;
    public const MAX_POINTS = 64;
    /** Länge des Textes (ohne Auszeichnung) */
    public const TEXT_MAX = 1200;

    private static int $n = 0;
    private static bool $script = false;

    // ================================================================= Datenformat

    /** Leeres Format */
    public static function empty(): array
    {
        return ['v' => self::VERSION, 'items' => []];
    }

    /**
     * Beliebige Eingabe → Format Version 1 (bereinigt). JSON-Text, {v, items}, Liste von Markierungen oder Formularzeilen.
     * Ältere Angaben (Marker-Editor des REDAXO-AddOns knowledgebase: content statt text) werden übernommen.
     * @return array{v: int, items: list<array<string, mixed>>}
     */
    public static function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? [] : json_decode($raw, true);
        }
        if (!is_array($raw)) return self::empty();
        $list = isset($raw['items']) && is_array($raw['items']) ? $raw['items'] : (array_is_list($raw) ? $raw : array_values($raw));
        $items = [];
        $ids = [];
        foreach ($list as $it) {
            if (!is_array($it) || count($items) >= self::MAX) continue;
            $h = self::item($it);
            if ($h === null) continue;
            // Kennung je Bild eindeutig (Grafik-Editor ordnet darüber zu); fehlt sie, entsteht sie aus der Position
            $id = $h['id'];
            for ($k = 2; $id === '' || isset($ids[$id]); $k++) $id = ($h['id'] !== '' ? $h['id'] : 'h' . (count($items) + 1)) . ($id === '' ? '' : '-' . $k);
            $h['id'] = $id;
            $ids[$id] = true;
            $items[] = $h;
        }
        return ['v' => self::VERSION, 'items' => $items];
    }

    /** Eine Markierung bereinigen – null, wenn sie keine gültige Lage hat */
    private static function item(array $it): ?array
    {
        $shape = in_array($it['shape'] ?? 'point', self::SHAPES, true) ? (string) ($it['shape'] ?? 'point') : 'point';
        $num = fn(mixed $v, float $def = 0.0): float => is_numeric($v) ? round(max(0.0, min(100.0, (float) $v)), 2) : $def;
        $h = ['id' => preg_match('~^[A-Za-z0-9_-]{1,40}$~', (string) ($it['id'] ?? '')) ? (string) $it['id'] : '', 'shape' => $shape];
        if ($shape === 'poly') {
            $pts = self::points($it['points'] ?? []);
            if (count($pts) < 3) {
                // Zu wenige Punkte: als Punkt an der angegebenen Lage behalten (nichts geht beim Umschalten verloren)
                if (!is_numeric($it['x'] ?? null) || !is_numeric($it['y'] ?? null)) return null;
                $h['shape'] = 'point';
            } else {
                $h['points'] = $pts;
            }
        }
        if ($h['shape'] !== 'poly') {
            if (!is_numeric($it['x'] ?? null) || !is_numeric($it['y'] ?? null)) return null;
            $h['x'] = $num($it['x']);
            $h['y'] = $num($it['y']);
        }
        if ($h['shape'] === 'circle') $h['r'] = max(0.5, $num($it['r'] ?? 5, 5.0));
        if ($h['shape'] === 'rect') {
            $h['w'] = max(0.5, min(100 - $h['x'], $num($it['w'] ?? 10, 10.0)));
            $h['h'] = max(0.5, min(100 - $h['y'], $num($it['h'] ?? 10, 10.0)));
        }
        $text = Sanitizer::block((string) ($it['text'] ?? $it['content'] ?? ''));
        if (trim(strip_tags($text)) === '') $text = '';
        $link = trim((string) ($it['link'] ?? ''));
        if ($link !== '' && !Links::isRef($link) && Sanitizer::safeHref($link) === null) $link = '';
        $icon = trim((string) ($it['icon'] ?? ''));
        return $h + [
            'title' => mb_substr(trim(html_entity_decode(strip_tags((string) ($it['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 160),
            'text' => $text,
            'link' => $link,
            'link_label' => mb_substr(trim(strip_tags((string) ($it['link_label'] ?? ''))), 0, 60),
            'term' => preg_match('~^[a-z0-9][a-z0-9-]{0,120}$~', (string) ($it['term'] ?? '')) ? (string) $it['term'] : '',
            'icon' => $icon !== '' && preg_match('~^[a-z0-9-]{1,40}$~', $icon) ? $icon : '',
        ];
    }

    /**
     * Punkte einer Fläche: [[x, y], …], [{x, y}, …] oder Text „x,y x,y …“ (auch mit Semikolon/Zeilenumbruch getrennt)
     * @return list<array{0: float, 1: float}>
     */
    public static function points(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = array_map(fn($p) => explode(',', $p), preg_split('~[\s;]+~', trim($raw)) ?: []);
        }
        $out = [];
        foreach ((array) $raw as $p) {
            $x = is_array($p) ? ($p['x'] ?? $p[0] ?? null) : null;
            $y = is_array($p) ? ($p['y'] ?? $p[1] ?? null) : null;
            if (!is_numeric($x) || !is_numeric($y)) continue;
            $out[] = [round(max(0.0, min(100.0, (float) $x)), 2), round(max(0.0, min(100.0, (float) $y)), 2)];
            if (count($out) >= self::MAX_POINTS) break;
        }
        return $out;
    }

    /** Punkte als Text für das Formular („12.5,40 20,30 …“) */
    public static function pointsText(array $pts): string
    {
        return implode(' ', array_map(fn($p) => self::fmt((float) $p[0]) . ',' . self::fmt((float) $p[1]), $pts));
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** Mittelpunkt einer Markierung (dort sitzt der Knopf mit der Nummer) – [x, y] in % */
    public static function anchor(array $h): array
    {
        return match ($h['shape'] ?? 'point') {
            'rect' => [round($h['x'] + $h['w'] / 2, 2), round($h['y'] + $h['h'] / 2, 2)],
            'poly' => self::centroid($h['points']),
            default => [(float) $h['x'], (float) $h['y']],
        };
    }

    /** Schwerpunkt eines Vielecks (bei entarteten Flächen der Mittelwert der Punkte) */
    private static function centroid(array $pts): array
    {
        $a = $cx = $cy = 0.0;
        $n = count($pts);
        for ($i = 0; $i < $n; $i++) {
            [$x0, $y0] = $pts[$i];
            [$x1, $y1] = $pts[($i + 1) % $n];
            $f = $x0 * $y1 - $x1 * $y0;
            $a += $f;
            $cx += ($x0 + $x1) * $f;
            $cy += ($y0 + $y1) * $f;
        }
        if (abs($a) < 1e-6) return [round(array_sum(array_column($pts, 0)) / max(1, $n), 2), round(array_sum(array_column($pts, 1)) / max(1, $n), 2)];
        return [round($cx / (3 * $a), 2), round($cy / (3 * $a), 2)];
    }

    // ================================================================= Formular (Feldtyp „hotspots“)

    /** Unterfelder einer Zeile im Formular (Wiederholgruppe unter dem Marker-Editor) */
    public static function subFields(): array
    {
        $shapes = ['point' => __('Punkt (Nummer)'), 'circle' => __('Kreis'), 'rect' => __('Rechteck'), 'poly' => __('Fläche (Vieleck)')];
        $geo = fn(array $s) => ['shape' => $s];
        $f = [
            ['name' => 'title', 'label' => __('Titel'), 'type' => 'text', 'max' => 160, 'width' => 'half'],
            ['name' => 'shape', 'label' => __('Form'), 'type' => 'select', 'required' => true, 'default' => 'point', 'width' => 'half', 'options' => $shapes],
            ['name' => 'x', 'label' => __('Waagerecht (%)'), 'type' => 'number', 'step' => '0.1', 'width' => 'half', 'show_if' => $geo(['point', 'circle', 'rect'])],
            ['name' => 'y', 'label' => __('Senkrecht (%)'), 'type' => 'number', 'step' => '0.1', 'width' => 'half', 'show_if' => $geo(['point', 'circle', 'rect'])],
            ['name' => 'r', 'label' => __('Radius (% der Breite)'), 'type' => 'number', 'step' => '0.1', 'width' => 'half', 'show_if' => $geo(['circle'])],
            ['name' => 'w', 'label' => __('Breite (%)'), 'type' => 'number', 'step' => '0.1', 'width' => 'half', 'show_if' => $geo(['rect'])],
            ['name' => 'h', 'label' => __('Höhe (%)'), 'type' => 'number', 'step' => '0.1', 'width' => 'half', 'show_if' => $geo(['rect'])],
            ['name' => 'points', 'label' => __('Eckpunkte (x,y in %, durch Leerzeichen getrennt)'), 'type' => 'text', 'show_if' => $geo(['poly']),
                'placeholder' => '10,10 30,12 20,30', 'help' => __('Im Bild die Eckpunkte ziehen; Umschalt + Klick ins Bild fügt einen Punkt an.')],
            ['name' => 'text', 'label' => __('Text'), 'type' => 'richtext', 'max' => self::TEXT_MAX],
            ['name' => 'link', 'label' => __('Link (optional)'), 'type' => 'link', 'width' => 'half'],
            ['name' => 'link_label', 'label' => __('Beschriftung des Links'), 'type' => 'text', 'max' => 60, 'width' => 'half', 'placeholder' => __('Mehr erfahren')],
        ];
        $terms = self::termOptions();
        if ($terms) {
            $f[] = ['name' => 'term', 'label' => __('Glossar-Begriff (optional)'), 'type' => 'select', 'width' => 'half', 'options' => ['' => __('– keiner –')] + $terms,
                'help' => __('Zeigt die Kurz-Erklärung des Begriffs (auch ohne eigenen Text) und verlinkt ins Glossar.')];
        }
        $f[] = ['name' => 'icon', 'label' => __('Symbol statt Nummer (optional)'), 'type' => 'icon', 'width' => 'half'];
        $f[] = ['name' => 'id', 'label' => 'ID', 'type' => 'hidden'];
        return $f;
    }

    /** Glossar-Begriffe für die Auswahl (Kurzname → Begriff) – leer ohne Funktion „glossary“ */
    public static function termOptions(): array
    {
        if (!Glossary::enabled() || !Glossary::table()) return [];
        $o = [];
        foreach (Glossary::terms(true) as $t) $o[(string) $t['key']] = (string) $t['term'];
        return $o;
    }

    /** Formularzeilen (Fields::sanitize der Unterfelder) → Format */
    public static function fromForm(mixed $raw): array
    {
        if (is_string($raw) || (is_array($raw) && isset($raw['v']))) return self::normalize($raw);
        $rows = [];
        foreach (is_array($raw) ? array_values($raw) : [] as $row) {
            if (!is_array($row)) continue;
            [$v] = Fields::sanitize(array_values(array_filter(self::subFields(), fn($f) => $f['type'] !== 'hidden')), $row);
            $v['id'] = (string) ($row['id'] ?? '');
            $v['points'] = (string) ($row['points'] ?? '');
            // Neue Zeile ohne Lage (Knopf „Markierung hinzufügen“), aber mit Inhalt: in die Bildmitte statt verwerfen
            if (($v['x'] === null || $v['y'] === null) && ($v['title'] !== '' || trim(strip_tags((string) $v['text'])) !== '' || (($v['term'] ?? '') !== ''))) {
                $v['x'] ??= 50;
                $v['y'] ??= 50;
            }
            $rows[] = $v;
        }
        return self::normalize($rows);
    }

    /** Format → Zeilen für das Formular (points als Text) */
    public static function toForm(mixed $data): array
    {
        $rows = [];
        foreach (self::normalize($data)['items'] as $h) {
            if ($h['shape'] === 'poly') {
                $h['points'] = self::pointsText($h['points']);
                [$h['x'], $h['y']] = self::anchor(['shape' => 'poly', 'points' => self::points($h['points'])]);
            }
            $rows[] = $h;
        }
        return $rows;
    }

    /**
     * Eingabe: Marker-Editor (resources/js/_hotspots.js) über einer Wiederholgruppe mit einer Zeile je Markierung. Das Bild kommt
     * aus dem Bildfeld 'image_field' desselben Formulars ({prefix}[feld]); Knöpfe der Editoren von Erweiterungen (hotspotEditor).
     */
    public static function renderInput(array $f, mixed $value, array $errors, string $prefix, string $path, string $label): string
    {
        $img = (string) ($f['image_field'] ?? '');
        $rep = ['name' => $f['name'], 'label' => $f['label'] ?? __('Markierungen'), 'type' => 'repeater', 'item_label' => __('Markierung'),
            'title_field' => 'title', 'max_items' => self::MAX, 'fields' => self::subFields(), 'add_label' => __('Markierung hinzufügen'),
            'help' => $f['help'] ?? ''];
        $cfg = ['image' => $img !== '' ? $prefix . '[' . $img . ']' : '', 'editors' => Extensions::hotspotEditors(),
            'term' => (bool) self::termOptions(), 'max' => self::MAX];
        $count = count(self::normalize($value)['items']);
        return '<div class="hs-field" data-hotspots="' . e((string) json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '">'
            . '<div class="hs-ed" data-hs-ed hidden>'
            . '<p class="hs-ed__hint" id="' . e('hs-' . md5($prefix . $f['name'])) . '-h">' . e(__('Ins Bild klicken setzt eine Markierung. Ziehen verschiebt sie; mit der Tastatur: Markierung wählen, Pfeiltasten (mit Umschalt in 5er-Schritten), Entf löscht, Eingabe öffnet die Angaben.')) . '</p>'
            . '<div class="hs-ed__bar" data-hs-bar></div>'
            . '<div class="hs-ed__stage" data-hs-stage><p class="hs-ed__empty">' . e(__('Zuerst ein Bild wählen.')) . '</p></div>'
            . '<p class="hs-ed__live sr-only" aria-live="polite" data-hs-live></p></div>'
            . Fields::renderField($rep, self::toForm($value), $errors, $prefix, $path)
            . '<p class="f-help hs-field__count">' . e(__('{n} Markierungen', ['n' => $count])) . '</p></div>';
    }

    // ================================================================= Ausgabe auf der Website

    /**
     * Interaktives Bild: Bild (auch SVG/Grafik) mit nummerierten Knöpfen; jeder öffnet ein Hinweisfenster (Popover-API, ohne
     * Inline-Skripte und -Stile – Lage über Klassen x0–x100/y0–y100, genau per CSSOM in js/hotspots.js). Flächen als SVG darüber.
     * Darunter die Liste aller Markierungen (Nummer, Titel, Text): auf schmalen Flächen sichtbar, sonst für Screenreader
     * ($o['list'] = 'always' immer sichtbar). $o: label (Name der Gruppe), id (Präfix der IDs), sizes, list, eager.
     */
    public static function render(?int $mediaId, mixed $data, array $o = []): string
    {
        $m = $mediaId ? Media::find($mediaId) : null;
        if (!$m || !str_starts_with((string) $m['mime'], 'image/')) return '';
        $items = self::normalize($data)['items'];
        $id = (string) ($o['id'] ?? '') !== '' ? preg_replace('~[^A-Za-z0-9_-]~', '', (string) $o['id']) : 'hs' . (++self::$n);
        $w = max(1, (int) ($m['width'] ?? 0));
        $hgt = max(1, (int) ($m['height'] ?? 0));
        if ((int) ($m['width'] ?? 0) <= 0 || (int) ($m['height'] ?? 0) <= 0) [$w, $hgt] = [1000, 1000];
        $terms = [];
        if (array_filter(array_column($items, 'term')) && Glossary::enabled()) {
            foreach (Glossary::terms(app()->auth->check()) as $t) $terms[(string) $t['key']] = $t;
        }
        $label = trim((string) ($o['label'] ?? ''));
        $alt = Media::alt($m);
        $img = Media::picture((int) $m['id'], (string) ($o['sizes'] ?? '(min-width: 1280px) 1200px, 100vw'), ['class' => 'cms-hs__img', 'fit' => false]
            + (isset($o['eager']) ? ['eager' => (bool) $o['eager']] : []) + (isset($o['path']) ? ['path' => (string) $o['path']] : []));

        $pins = $areas = $list = '';
        foreach ($items as $i => $h) {
            $n = $i + 1;
            $c = self::content($h, $terms);
            [$ax, $ay] = self::anchor($h);
            $pid = $id . '-' . $n;
            $name = $c['title'] !== '' ? lt('{n}: {title}', ['n' => $n, 'title' => $c['title']]) : lt('Markierung {n}', ['n' => $n]);
            $pinInner = $h['icon'] !== '' ? icon($h['icon']) . '<span class="sr-only">' . e($name) . '</span>' : '<span aria-hidden="true">' . $n . '</span><span class="sr-only">' . e($name) . '</span>';
            $pins .= '<button type="button" class="cms-hs__pin x' . (int) round($ax) . ' y' . (int) round($ay) . ($h['shape'] !== 'point' ? ' cms-hs__pin--area' : '') . ($h['icon'] !== '' ? ' cms-hs__pin--icon' : '') . '"'
                . ' data-x="' . e(self::fmt($ax)) . '" data-y="' . e(self::fmt($ay)) . '" data-n="' . $n . '"'
                . ' popovertarget="' . e($pid) . '" aria-controls="' . e($pid) . '" aria-expanded="false">' . $pinInner . '</button>'
                . '<div class="cms-hs__pop" id="' . e($pid) . '" popover role="dialog" aria-labelledby="' . e($pid) . '-t">'
                . '<div class="cms-hs__pophead"><span class="cms-hs__num" aria-hidden="true">' . $n . '</span>'
                . '<p class="cms-hs__ptitle" id="' . e($pid) . '-t">' . e($c['title'] !== '' ? $c['title'] : lt('Markierung {n}', ['n' => $n])) . '</p>'
                . '<button type="button" class="cms-hs__x" popovertarget="' . e($pid) . '" popovertargetaction="hide" aria-label="' . e(lt('Schließen')) . '"><span aria-hidden="true">×</span></button></div>'
                . $c['body'] . '</div>';
            if ($h['shape'] !== 'point') $areas .= self::svgShape($h, $n, $w, $hgt);
            $list .= '<li class="cms-hs__li" data-n="' . $n . '"><span class="cms-hs__num" aria-hidden="true">' . $n . '</span><div class="cms-hs__lb">'
                . '<p class="cms-hs__lt">' . ($c['title'] !== '' ? e($c['title']) : e(lt('Markierung {n}', ['n' => $n]))) . '</p>' . $c['body'] . '</div></li>';
        }
        $svg = $areas !== '' ? '<svg class="cms-hs__areas" viewBox="0 0 ' . $w . ' ' . $hgt . '" preserveAspectRatio="none" aria-hidden="true" focusable="false">' . $areas . '</svg>' : '';
        $listMode = ($o['list'] ?? 'auto') === 'always' ? 'always' : 'auto';
        $aria = $label !== '' ? $label : ($alt !== '' ? $alt : lt('Interaktives Bild'));
        $html = '<figure class="cms-hs cms-hs--list-' . $listMode . '" id="' . e($id) . '" aria-label="' . e(lt('Interaktives Bild: {name}', ['name' => strip_emphasis($aria)])) . '" data-cms-hs>'
            . '<div class="cms-hs__stage">' . $img . $svg . $pins . '</div>'
            . ($items ? '<figcaption class="cms-hs__cap"><span class="cms-hs__hint">' . e(lt('{n} Markierungen – zum Öffnen anklicken oder mit der Tab-Taste wählen.', ['n' => count($items)])) . '</span></figcaption>'
                . '<ol class="cms-hs__list" aria-label="' . e(lt('Markierungen im Bild')) . '">' . $list . '</ol>' : '')
            . '</figure>';
        return $html . self::script();
    }

    /** Titel und Inhalt eines Hinweisfensters (Text, Glossar, Link) */
    private static function content(array $h, array $terms): array
    {
        $t = $h['term'] !== '' ? ($terms[$h['term']] ?? null) : null;
        $title = $h['title'] !== '' ? $h['title'] : (string) ($t['term'] ?? '');
        $body = '';
        if ($h['text'] !== '') $body .= '<div class="cms-hs__text">' . $h['text'] . '</div>';
        if ($t) {
            // Kurz-Erklärung: ohne eigenen Text als Inhalt, sonst darunter mit dem Begriff davor
            if ($t['short'] !== '') {
                $dfn = $h['text'] !== '' || $title !== $t['term'] ? '<dfn class="cms-hs__dfn">' . e((string) $t['term']) . '</dfn>: ' : '';
                $body .= '<p class="cms-hs__gl">' . $dfn . e((string) $t['short']) . '</p>';
            }
            if (!empty($t['url']) && ($t['more'] ?? true)) {
                $body .= '<p class="cms-hs__more"><a href="' . e((string) $t['url']) . '">' . e(lt('Mehr im Glossar')) . ' <span aria-hidden="true">→</span></a></p>';
            }
        }
        if ($h['link'] !== '' && ($href = link_href($h['link'])) !== '#') {
            $body .= '<p class="cms-hs__more"><a href="' . e($href) . '"' . ext_attrs($href) . '>' . e($h['link_label'] !== '' ? $h['link_label'] : lt('Mehr erfahren')) . ' <span aria-hidden="true">→</span></a></p>';
        }
        return ['title' => $title, 'body' => $body];
    }

    /** Fläche als SVG-Form (Koordinaten im Pixelraster des Bildes, viewBox = Bildgröße) */
    private static function svgShape(array $h, int $n, int $w, int $hgt): string
    {
        $px = fn(float $v) => self::fmt($v * $w / 100);
        $py = fn(float $v) => self::fmt($v * $hgt / 100);
        $cls = ' class="cms-hs__area" data-n="' . $n . '"';
        return match ($h['shape']) {
            'circle' => '<circle' . $cls . ' cx="' . $px($h['x']) . '" cy="' . $py($h['y']) . '" r="' . $px($h['r']) . '"/>',
            'rect' => '<rect' . $cls . ' x="' . $px($h['x']) . '" y="' . $py($h['y']) . '" width="' . $px($h['w']) . '" height="' . $py($h['h']) . '" rx="' . self::fmt(min($w, $hgt) / 200) . '"/>',
            'poly' => '<polygon' . $cls . ' points="' . implode(' ', array_map(fn($p) => $px($p[0]) . ',' . $py($p[1]), $h['points'])) . '"/>',
            default => '',
        };
    }

    /** Skript der Website (Lage, Hinweisfenster am Knopf, Klick auf Flächen) – einmal je Seite, nicht im Bearbeiten-Modus */
    public static function script(): string
    {
        if (self::$script || app()->editing) return '';
        self::$script = true;
        return '<script src="' . e(asset('js/hotspots.js')) . '" defer></script>';
    }

    /** Für Selbsttests: Zähler und Skript zurücksetzen */
    public static function reset(): void
    {
        self::$n = 0;
        self::$script = false;
    }

    // ================================================================= Datentabellen

    /** Bildfeld zu einem Markierungsfeld der Tabelle: 'image_field' des Feldes, sonst das letzte Bildfeld davor, sonst das erste */
    public static function imageField(array $table, array $f): ?string
    {
        $want = (string) ($f['image_field'] ?? '');
        $media = array_values(array_filter($table['fields'], fn($x) => ($x['type'] ?? '') === 'media'));
        if ($want !== '' && in_array($want, array_column($media, 'name'), true)) return $want;
        $before = null;
        foreach ($table['fields'] as $x) {
            if ($x['name'] === $f['name']) break;
            if (($x['type'] ?? '') === 'media') $before = $x['name'];
        }
        return $before ?? ($media[0]['name'] ?? null);
    }

    /** Erstes Markierungsfeld einer Tabelle (Block „Interaktives Bild“ → Quelle Datentabelle) */
    public static function tableField(array $table): ?array
    {
        foreach ($table['fields'] as $f) if (($f['type'] ?? '') === self::TYPE) return $f;
        return null;
    }
}
