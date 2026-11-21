<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/**
 * php bin/console kits:selftest – Vererbung von Kits (theme.php → 'extends'): Zusammenführen der Definition (Theme::mergeDefinition),
 * Standardwerte des geerbten Designs (design_defaults), Kette eines Kits ohne Eltern.
 */
final class KitSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails);
        $parent = [
            'label' => 'Eltern', 'container_class' => 'wrap',
            'blocks' => ['hero' => ['label' => 'Einstieg'], 'quote' => ['label' => 'Zitat']],
            'conditional_css' => ['css/a.css' => ['hero'], 'css/b.css' => ['quote']],
            'design' => ['groups' => [['id' => 'farben', 'tokens' => [['name' => 'accent', 'type' => 'color', 'default' => '#111111', 'dark' => '#EEEEEE']]]]],
        ];
        $child = [
            'label' => 'Kind', 'extends' => 'eltern',
            'blocks' => ['quote' => null, 'cta' => ['label' => 'Aufruf'], 'hero' => ['label' => 'Bühne']],
            'conditional_css' => ['css/b.css' => null, 'css/c.css' => ['cta']],
            'design_defaults' => ['accent' => ['default' => '#B45309', 'dark' => '#FBBF24', 'var' => '--boese']],
        ];
        $m = Theme::mergeDefinition($parent, $child);
        $eq('Kind ersetzt einfache Werte, erbt den Rest', [$m['label'], $m['container_class'], $m['extends']], ['Kind', 'wrap', 'eltern']);
        $eq('Blöcke je Typ: ersetzt, neu, mit null entfernt', [array_keys($m['blocks']), $m['blocks']['hero']['label']], [['hero', 'cta'], 'Bühne']);
        $eq('conditional_css je Datei zusammengeführt', array_keys($m['conditional_css']), ['css/a.css', 'css/c.css']);
        $tok = $m['design']['groups'][0]['tokens'][0];
        $eq('design_defaults setzt hell und dunkel, sonst nichts', [$tok['default'], $tok['dark'], $tok['var'] ?? null], ['#B45309', '#FBBF24', null]);
        $eq('design_defaults: Design-Datei immer ausgeben, Schlüssel entfernt', [!empty($m['design']['emit_defaults']), isset($m['design_defaults'])], [true, false]);
        $s = Theme::mergeDefinition($parent, ['design_defaults' => ['accent' => '#222222']]);
        $eq('design_defaults als einfacher Wert', $s['design']['groups'][0]['tokens'][0]['default'], '#222222');
        $eq('Kit ohne Eltern: unverändert', Theme::mergeDefinition([], $parent), $parent);
        // block_extend: geerbte Blöcke ergänzen statt ersetzen (zusätzliche Varianten, Felder)
        $base = ['blocks' => ['media_text' => ['label' => 'Text + Bild', 'variants' => ['right' => 'Rechts'], 'fields' => [['name' => 'text', 'type' => 'richtext'], ['name' => 'image', 'type' => 'media']]]]];
        $x = Theme::mergeDefinition($base, ['block_extend' => [
            'media_text' => ['variants' => ['morph' => 'Morphing'], 'variant_help' => ['morph' => 'Hilfe'], 'fields' => [['name' => 'image', 'type' => 'media', 'label' => 'Neu'], ['name' => 'extra', 'type' => 'text']]],
            'gibt_es_nicht' => ['label' => 'x'],
        ]]);
        $mt = $x['blocks']['media_text'];
        $eq('block_extend: Varianten ergänzt, Label bleibt', [array_keys($mt['variants']), $mt['label'], $mt['variant_help']['morph']], [['right', 'morph'], 'Text + Bild', 'Hilfe']);
        $eq('block_extend: Felder angehängt, gleicher Name ersetzt', [array_column($mt['fields'], 'name'), $mt['fields'][1]['label'] ?? null], [['text', 'image', 'extra'], 'Neu']);
        $eq('block_extend: unbekannter Typ ignoriert, Schlüssel entfernt', [isset($x['blocks']['gibt_es_nicht']), isset($x['block_extend'])], [false, false]);
        $any = (string) array_key_first(Kit::all());
        if ($any !== '' && Kit::parent($any) === '') $eq('Kette eines Kits ohne Eltern: nur das Kit', array_keys(Kit::chain($any)), [$any]);
        $eq('Kette eines unbekannten Kits: leer', Kit::chain('gibt-es-nicht'), []);
        // Stylesheets je Design-Option (Liste 'option_css', Design::optionCssRules): Reihenfolge der Liste, Werte, Schalter, {value}
        $rules = [
            'css/opt-a.css' => ['nav' => ['pill', 'dot']],
            'css/opt-b.css' => ['progress' => true],
            'css/opt-c.css' => ['x' => ['eins'], 'nav' => ['dot']],
            'css/opt-header-{value}.css' => 'header',
        ];
        $eq('option_css: Wert, Schalter an, Platzhalter', Design::optionCssRules($rules, ['nav' => 'pill', 'progress' => true, 'header' => 'side-bar']),
            ['css/opt-a.css', 'css/opt-b.css', 'css/opt-header-sidebar.css']);
        $eq('option_css: einer von mehreren Tokens genügt, Schalter aus', Design::optionCssRules($rules, ['nav' => 'dot', 'progress' => false]),
            ['css/opt-a.css', 'css/opt-c.css']);
        $eq('option_css: unbekannte Tokens laden nichts', Design::optionCssRules($rules, []), []);
        // Kit fluid: jede Datei der Liste gibt es als Quelle (sonst lädt still nichts)
        if (is_dir($fluid = Kit::dir('fluid'))) {
            $def = require $fluid . '/theme.php';
            $missing = [];
            foreach (array_keys((array) ($def['design']['option_css'] ?? [])) as $f) {
                if (!str_contains($f, '{value}') && !is_file($fluid . '/assets/' . $f)) $missing[] = $f;
            }
            $eq('fluid: Dateien der Liste option_css vorhanden', $missing, []);
        }
        // Theme::withKit: anderes Kit nur für die Dauer eines Aufrufs (Vorschau), danach alles wie vorher – auch bei Fehlern
        $other = '';
        foreach (array_keys(Kit::all()) as $k) if ($k !== app()->theme->name && Kit::definitionFile(Kit::dir($k)) !== null) { $other = (string) $k; break; }
        if ($other !== '') {
            $before = [app()->theme->name, Design::values(), app()->currentPage];
            $inside = Theme::withKit($other, fn(Theme $t) => [app()->theme->name, $t->name]);
            $eq('withKit: im Aufruf das andere Kit', $inside, [$other, $other]);
            $eq('withKit: danach Kit und Design-Werte wie vorher', [app()->theme->name, Design::values(), app()->currentPage], $before);
            $first = array_key_first(array_filter(Theme::withKit($other, fn() => Design::tokens()), fn($t) => ($t['type'] ?? '') === 'range'));
            if ($first !== null) {
                $probe = Theme::withKit($other, fn() => Design::get((string) $first), [(string) $first => -99999]);
                $eq('withKit: Werte nur für den Aufruf (begrenzt), danach kein Override', [is_numeric($probe), Design::values()], [true, $before[1]]);
            }
            try {
                Theme::withKit($other, function () { app()->currentPage = ['id' => -1]; throw new \RuntimeException('x'); });
            } catch (\RuntimeException) {
            }
            $eq('withKit: nach Fehler wiederhergestellt', [app()->theme->name, app()->currentPage], [$before[0], $before[2]]);
            $eq('withKit: Theme-Objekt geht ebenso', Theme::withKit(app()->theme, fn(Theme $t) => $t === app()->theme), true);
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
