<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

use Core\Testing\Check;

/**
 * Selbsttest „Interaktives Bild“ (Core\Hotspots) – ohne Datenbank, Bilder als Datensätze im Zwischenspeicher von Core\Media.
 *  - data(): Teil von data:selftest – Datenformat v1 (Formen, Grenzen, Kennungen, Rich-Text ohne Bilder, unsichere Links, ältere
 *    Angaben), Formularzeilen hin und zurück, Feldtyp der Datentabellen und Vorlage „Interaktive Bilder“.
 *  - render(): Teil von blocks:selftest – Ausgabe (Popover, Tab-Reihenfolge = Nummern, Liste, SVG-Flächen, CSP: keine style-Attribute
 *    und Inline-Skripte), Glossar-Begriff, Block mit Quelle „hier“ und Rückfall ohne Bild, Kern-CSS.
 */
final class HotspotsSelfTest
{
    private const IMG = 990301;

    public static function data(): array
    {
        $ok = 0;
        $fails = [];
        $eq = Check::eq($ok, $fails, 'Interaktives Bild – ');
        $n = Hotspots::normalize(json_encode([
            ['x' => 10, 'y' => 20, 'title' => '<b>A</b>', 'text' => '<p>Hallo <img src="x.png"> <a href="javascript:alert(1)">x</a> <a href="https://example.org">y</a></p>', 'link' => 'javascript:alert(1)'],
            ['shape' => 'poly', 'points' => '10,10 30,10 20,30', 'id' => 'baum'],
            ['shape' => 'rect', 'x' => 90, 'y' => 95, 'w' => 30, 'h' => 30, 'link' => 'page:3'],
            ['shape' => 'circle', 'x' => 150, 'y' => -5, 'r' => 0, 'id' => 'baum'],
            ['shape' => 'poly', 'points' => [[1, 1]], 'x' => 5, 'y' => 6],
            ['title' => 'ohne Lage'],
            ['shape' => 'hexagon', 'x' => 1, 'y' => 2, 'term' => 'Bio Top!', 'icon' => 'star'],
            ['x' => 3, 'y' => 4, 'content' => '<p>Alt</p>'],
        ]));
        $eq('Version und Anzahl (ohne Lage fällt weg)', [$n['v'], count($n['items'])], [1, 7]);
        $it = $n['items'];
        $eq('Titel als Klartext, Rich-Text ohne Bild und ohne javascript:-Link', [$it[0]['title'], str_contains($it[0]['text'], '<img'), str_contains($it[0]['text'], 'javascript'),
            str_contains($it[0]['text'], 'href="https://example.org"'), $it[0]['link']], ['A', false, false, true, '']);
        $eq('Fläche: Punkte als Liste', $it[1]['points'], [[10.0, 10.0], [30.0, 10.0], [20.0, 30.0]]);
        $eq('Rechteck bleibt im Bild', [$it[2]['w'], $it[2]['h'], $it[2]['link']], [10.0, 5.0, 'page:3']);
        $eq('Kreis: Lage begrenzt, Mindestradius, doppelte Kennung umbenannt', [$it[3]['x'], $it[3]['y'], $it[3]['r'], $it[3]['id']], [100.0, 0.0, 0.5, 'baum-2']);
        $eq('Fläche mit zu wenigen Punkten wird Punkt', [$it[4]['shape'], $it[4]['x']], ['point', 5.0]);
        $eq('Unbekannte Form → Punkt, ungültiger Begriff leer, Symbol bleibt', [$it[5]['shape'], $it[5]['term'], $it[5]['icon']], ['point', '', 'star']);
        $eq('Ältere Angabe content → text', $it[6]['text'], '<p>Alt</p>');
        $eq('Mittelpunkte (Rechteck, Dreieck)', [Hotspots::anchor($it[2]), Hotspots::anchor($it[1])], [[95.0, 97.5], [20.0, 16.67]]);
        $eq('Leere/ungültige Eingaben', [Hotspots::normalize(''), Hotspots::normalize('{kaputt'), Hotspots::normalize(null)], [Hotspots::empty(), Hotspots::empty(), Hotspots::empty()]);
        $many = Hotspots::normalize(array_fill(0, Hotspots::MAX + 5, ['x' => 1, 'y' => 1]));
        $eq('Höchstzahl der Markierungen', count($many['items']), Hotspots::MAX);
        $eq('Idempotent', Hotspots::normalize($n), $n);

        // Formular: Zeilen ↔ Format (Punkte als Text, neue Zeile ohne Lage mit Inhalt in die Mitte)
        $rows = Hotspots::toForm($n);
        $eq('Formular: Fläche als Text mit Mittelpunkt', [$rows[1]['points'], $rows[1]['x'], $rows[1]['y']], ['10,10 30,10 20,30', 20.0, 16.67]);
        [$v, $err] = Fields::sanitize([['name' => 'hs', 'type' => 'hotspots', 'required' => true]], ['hs' => [
            ['title' => 'Neu', 'shape' => 'point', 'x' => '', 'y' => ''],
            ['title' => '', 'shape' => 'point', 'x' => '', 'y' => ''],
            ['title' => 'F', 'shape' => 'poly', 'points' => '1,1 5,1 3,4', 'x' => '3', 'y' => '2', 'id' => 'f1'],
        ]]);
        $eq('Formular: Zeilen → Format', [count($v['hs']['items']), $v['hs']['items'][0]['x'], $v['hs']['items'][1]['points'], $v['hs']['items'][1]['id'], $err], [2, 50.0, [[1.0, 1.0], [5.0, 1.0], [3.0, 4.0]], 'f1', []]);
        $eq('Formular: Format (API) wird angenommen', Fields::sanitize([['name' => 'hs', 'type' => 'hotspots']], ['hs' => $n])[0]['hs'], $n);
        $eq('Formular: Pflichtfeld ohne Markierung', isset(Fields::sanitize([['name' => 'hs', 'type' => 'hotspots', 'required' => true, 'label' => 'M']], ['hs' => []])[1]['hs']), true);
        $html = Fields::renderField(['name' => 'hs', 'type' => 'hotspots', 'image_field' => 'bild', 'label' => 'M'], $n, [], 'f');
        $eq('Formular: Editor mit Bildfeld und Zeilen', [str_contains($html, 'data-hotspots='), str_contains($html, '&quot;image&quot;:&quot;f[bild]&quot;'), substr_count($html, 'class="rep-item"'),
            str_contains($html, 'name="f[hs][1][points]" value="10,10 30,10 20,30"')], [true, true, 8, true]);   // 7 Zeilen + Vorlage für neue Zeilen
        $eq('Leerwert', Fields::emptyValue(['type' => 'hotspots']), Hotspots::empty());

        // Datentabellen: Feldtyp, Bildfeld, Vorlage
        $eq('Feldtyp „hotspots“ (Spalte text)', \Core\Data\Tables::TYPES['hotspots'][1] ?? null, 'text');
        $t = ['fields' => [['name' => 'titel', 'type' => 'text'], ['name' => 'a', 'type' => 'media'], ['name' => 'b', 'type' => 'media'], ['name' => 'm', 'type' => 'hotspots'], ['name' => 'c', 'type' => 'media']]];
        $eq('Bildfeld: letztes davor, ausdrücklich, Rückfall', [Hotspots::imageField($t, $t['fields'][3]), Hotspots::imageField($t, ['name' => 'm', 'image_field' => 'c']),
            Hotspots::imageField(['fields' => [['name' => 'm', 'type' => 'hotspots'], ['name' => 'z', 'type' => 'media']]], ['name' => 'm'])], ['b', 'c', 'z']);
        $p = \Core\Http\Controllers\Admin\DataController::presets()['hotspots'] ?? null;
        $def = \Core\Http\Controllers\Admin\DataController::presetDef('hotspots');
        $eq('Vorlage „Interaktive Bilder“', [$p['name'] ?? null, array_column($def['fields'], 'type', 'name')['markierungen'] ?? null, $def['settings']['image_field']],
            ['Interaktive Bilder', 'hotspots', 'bild']);
        [, $errs] = \Core\Data\Tables::validate(array_replace($def, ['handle' => 'qa_hotspots_vorlage']));
        $eq('Vorlage gültig', $errs, []);
        return ['ok' => $ok, 'fails' => $fails];
    }

    public static function render(): array
    {
        $ok = 0;
        $fails = [];
        $eq = Check::eq($ok, $fails, 'Interaktives Bild – ');
        $cache = new \ReflectionProperty(Media::class, 'cache');
        $prev = $cache->getValue();
        $cache->setValue(null, [self::IMG => ['id' => self::IMG, 'mime' => 'image/jpeg', 'file' => 'hs.jpg', 'width' => 1600, 'height' => 1000, 'variants_json' => null,
            'alt' => 'Garten <mit> Haus', 'decorative' => 0, 'focus_x' => 50, 'focus_y' => 50]] + (array) $prev);
        Hotspots::reset();
        try {
            $data = ['v' => 1, 'items' => [
                ['x' => 22.5, 'y' => 55, 'title' => 'Haus "A"', 'text' => '<p>Text <b>fett</b></p>', 'link' => 'https://example.org/haus', 'link_label' => 'Zum Haus'],
                ['shape' => 'circle', 'x' => 50, 'y' => 50, 'r' => 10, 'title' => 'Teich'],
                ['shape' => 'poly', 'points' => [[10, 10], [30, 10], [20, 40]], 'icon' => 'star'],
            ]];
            $h = Hotspots::render(self::IMG, $data, ['label' => 'Rundgang', 'id' => 'qa']);
            $eq('Gruppe mit Namen, Alt-Text aus der Mediathek (escaped)', [str_contains($h, 'role="group" aria-label="' . e(lt('Interaktives Bild: {name}', ['name' => 'Rundgang'])) . '"'),
                str_contains($h, 'alt="Garten &lt;mit&gt; Haus"')], [true, true]);
            preg_match_all('~<button type="button" class="cms-hs__pin[^"]*"[^>]*data-n="(\d+)"[^>]*popovertarget="([^"]+)"~', $h, $m);
            $eq('Knöpfe in Reihenfolge der Nummern mit Popover-Ziel', [$m[1], $m[2]], [['1', '2', '3'], ['qa-1', 'qa-2', 'qa-3']]);
            $eq('Popover mit Titel, Schließen, aria-expanded', [substr_count($h, ' popover role="dialog"'), substr_count($h, 'popovertargetaction="hide"'), substr_count($h, 'aria-expanded="false"'),
                str_contains($h, 'id="qa-1-t">Haus &quot;A&quot;</p>')], [3, 3, 3, true]);
            $eq('Lage als Klassen, genau in data-x/-y', [str_contains($h, 'cms-hs__pin x23 y55'), str_contains($h, 'data-x="22.5" data-y="55"')], [true, true]);
            $eq('CSP: keine style-Attribute, keine Inline-Skripte, Skript einmal', [str_contains($h, 'style='), preg_match('~<script(?![^>]*\bsrc=)~', $h), substr_count($h, 'js/hotspots.js')], [false, 0, 1]);
            $eq('Flächen als SVG im Pixelraster (Kreis: Radius in % der Breite)', [str_contains($h, 'viewBox="0 0 1600 1000"'), str_contains($h, '<circle class="cms-hs__area" data-n="2" cx="800" cy="500" r="160"/>'),
                str_contains($h, 'points="160,100 480,100 320,400"')], [true, true, true]);
            $eq('Liste unter dem Bild mit Nummer, Titel, Text, Link', [substr_count($h, 'class="cms-hs__li"'), str_contains($h, '<a href="https://example.org/haus"'), str_contains($h, '>Zum Haus <span')],
                [3, true, true]);
            $eq('Symbol statt Nummer, Name für Screenreader', [str_contains($h, 'cms-hs__pin--icon'), str_contains($h, '<span class="sr-only">' . e(lt('Markierung {n}', ['n' => 3])) . '</span>')], [true, true]);
            $eq('Zweites Bild: kein zweites Skript', str_contains(Hotspots::render(self::IMG, $data, []), 'js/hotspots.js'), false);
            $eq('Ohne Bild bzw. mit Nicht-Bild keine Ausgabe', [Hotspots::render(null, $data), Hotspots::render(999999991, $data)], ['', '']);
            $eq('Liste „immer“', str_contains(Hotspots::render(self::IMG, $data, ['list' => 'always']), 'cms-hs--list-always'), true);

            // Block über das Kit (fluid zuerst; jedes Kit erbt den Kern-Block)
            foreach (['fluid', 'native', 'praxis'] as $kit) {
                if (!is_dir(ROOT . '/kits/' . $kit)) continue;
                $th = new Theme($kit);
                $def = $th->block('hotspots');
                $eq("Block im Kit $kit vorhanden, Feld „Markierungen“ am Bildfeld", [$def['label'] ?? null, array_column($def['fields'] ?? [], 'image_field', 'name')['hotspots'] ?? null], ['Interaktives Bild', 'image']);
                Hotspots::reset();
                $html = $th->renderBlock($th->makeBlock(['id' => 'qh', 'type' => 'hotspots', 'data' => ['title' => 'Plan', 'source' => 'here', 'image' => self::IMG, 'hotspots' => $data]]));
                $eq("Block ($kit): Kopf, Bild, drei Markierungen", [str_contains($html, '>Plan<'), substr_count($html, 'class="cms-hs__pin'), str_contains($html, 'id="b-qh-hs"')], [true, 3, true]);
                $none = $th->renderBlock($th->makeBlock(['id' => 'qh2', 'type' => 'hotspots', 'data' => ['source' => 'table', 'entry' => '']]));
                $eq("Block ($kit): ohne Eintrag für Besucher nichts", str_contains($none, 'cms-hs'), false);
                $css = $th->conditionalCss(['hotspots']);
                $eq("Block ($kit): Kern-CSS bzw. css/hotspots.css des Kits", (bool) array_filter($css, fn($u) => str_contains($u, 'css/hotspots.css')), true);
            }
            // Kern-CSS: Positionsklassen 0–100 und reduzierte Bewegung
            $src = (string) file_get_contents(ROOT . '/resources/css/hotspots.css');
            $eq('CSS: Klassen x0–x100/y0–y100, reduced-motion, Kit-Farben', [str_contains($src, '.cms-hs__pin.x100{left:100%}'), str_contains($src, '.cms-hs__pin.y0{top:0%}'),
                str_contains($src, 'prefers-reduced-motion:reduce'), str_contains($src, 'var(--kit-accent')], [true, true, true, true]);
        } finally {
            $cache->setValue(null, $prev);
            Hotspots::reset();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
