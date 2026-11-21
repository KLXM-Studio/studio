<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/**
 * php bin/console kits:selftest – Vererbung von Kits (theme.php → 'extends'): Zusammenführen der Definition (Theme::mergeDefinition),
 * Standardwerte des geerbten Designs (design_defaults), Kette eines Kits ohne Eltern, Theme::withKit (Vorschau mit anderem Kit),
 * eigene Stylesheets der Kits ('css'), Paketprüfung von Kind-Kits ohne templates/ (KitPackages::check).
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
        // Kind-Kits mit eigenem CSS ('css' => [...]) und Paketprüfung ohne templates/ – zwei Test-Kits in storage/kits, danach entfernt
        if (Kit::exists('fluid')) {
            $tmp = ROOT . '/storage/tmp/kits-selftest-' . bin2hex(random_bytes(4));
            $write = function (string $dir, array $files): void {
                foreach ($files as $f => $c) { @mkdir(dirname("$dir/$f"), 0775, true); file_put_contents("$dir/$f", $c); }
            };
            $theme = fn(string $label, string $parent, string $css) => "<?php\nreturn [\n    'extends' => '$parent',\n    'label' => '$label',\n    'version' => '0.0.1',\n    'css' => ['$css'],\n];\n";
            $write("$tmp/selftest-css-a", ['theme.php' => $theme('Test A', 'fluid', 'css/a.css'), 'public/css/a.css' => "body{--selftest-a:1}\n"]);
            $write("$tmp/selftest-css-b", ['theme.php' => $theme('Test B', 'selftest-css-a', 'css/b.css'), 'public/css/b.css' => "body{--selftest-b:1}\n",
                'templates/probe.php' => "<html><head><title>x</title></head><body></body></html>"]);
            $write("$tmp/selftest-solo", ['theme.php' => "<?php\nreturn [\n    'label' => 'Solo',\n    'version' => '0.0.1',\n];\n"]);
            try {
                $ca = KitPackages::check("$tmp/selftest-css-a");
                $cs = KitPackages::check("$tmp/selftest-solo");
                $eq('check: Kind-Kit ohne templates/ ohne Warnung, eigenständiges Kit mit', [preg_grep('~templates/~', $ca['warnings']), (bool) preg_grep('~templates/~', $cs['warnings']), $ca['meta']['extends'], $cs['meta']['extends']],
                    [[], true, 'fluid', '']);
                $eq('check: fehlendes Eltern-Kit als Warnung', (bool) preg_grep('~selftest-css-a~', KitPackages::check("$tmp/selftest-css-b")['warnings']), true);
                $ia = KitPackages::install("$tmp/selftest-css-a", true, 'kits:selftest');
                $ib = KitPackages::install("$tmp/selftest-css-b", true, 'kits:selftest');
                Kit::reset();
                $eq('Test-Kits installiert', [$ia['ok'], $ib['ok']], [true, true]);
                $b = new Theme('selftest-css-b');
                $urls = array_map(fn($u) => (string) preg_replace('~\?v=\w+$~', '', $u), $b->kitStylesheets());
                $eq("'css': Eltern zuerst, dann Kind, je aus dem eigenen Ordner", $urls, [Kit::url('selftest-css-a', 'css/a.css'), Kit::url('selftest-css-b', 'css/b.css')]);
                $eq("'css': nicht in der zusammengeführten Definition", array_key_exists('css', $b->def), false);
                $html = $b->render('probe');
                $eq("'css': ganze Seite ohne Liste extraCss → vor </head>", [str_contains($html, 'css/a.css'), strpos($html, 'css/b.css') < strpos($html, '</head>'), strpos($html, 'css/a.css') < strpos($html, 'css/b.css')], [true, true, true]);
                $eq("kit:check: vorhandene Stylesheets ohne Warnung", array_values(array_filter(KitCheck::run($b), fn($r) => str_contains($r['text'], "('css')"))), []);
                @unlink(Kit::publicDir('selftest-css-b') . '/css/b.css');
                $eq("'css': fehlende Datei fällt weg, kit:check meldet sie", [count((new Theme('selftest-css-b'))->kitStylesheets()), count(array_filter(KitCheck::run(new Theme('selftest-css-b')), fn($r) => str_contains($r['text'], "'css/b.css'")))], [1, 1]);
                $eq("'css': Kit ohne Angabe – keine Stylesheets (z. B. fluid)", (new Theme('fluid'))->kitStylesheets(), []);
            } finally {
                foreach (['selftest-css-b', 'selftest-css-a'] as $n) if (Kit::source($n) === 'upload') KitPackages::remove($n);
                foreach (['selftest-css-a', 'selftest-css-b', 'selftest-solo'] as $n) {
                    foreach (array_reverse(glob("$tmp/$n/{,*/,*/*/}*", GLOB_BRACE) ?: []) as $f) is_dir($f) ? @rmdir($f) : @unlink($f);
                    @rmdir("$tmp/$n");
                }
                @rmdir($tmp);
                Kit::reset();
            }
            $eq('Test-Kits entfernt', [Kit::exists('selftest-css-a'), Kit::exists('selftest-css-b')], [false, false]);
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
