<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/**
 * php bin/console assets:selftest – Core\AssetSync in einem Wegwerf-Ordner: Kern mit gebauten Assets und eine
 * Projekt-Installation mit eigenem public/ (verknüpfte Erweiterung/Kit, veraltete Kopie). Prüft Erkennen per Prüfsumme,
 * Verknüpfen, Abgleich (--copy) mit Entfernen alter Dateien, Schutz von fonts/installed, Composer-Paket mit public/,
 * normale Installation ohne Verknüpfung und die Sicherheitsgrenzen (Ziel, Verknüpfungsziel, Server-Code, Verknüpfungen).
 */
final class AssetSyncSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails);
        $tmp = sys_get_temp_dir() . '/klxm-assets-selftest-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        $tmp = (string) realpath($tmp);
        try {
            self::cases($eq, $tmp);
        } catch (\Throwable $e) {
            $fails[] = 'Abbruch: ' . $e->getMessage();
        } finally {
            self::rm($tmp);
        }
        return ['ok' => $ok, 'fails' => $fails];
    }

    private static function cases(\Closure $eq, string $tmp): void
    {
        $core = "$tmp/core";
        $root = "$tmp/projekt";
        $w = function (string $path, string $body): void { @mkdir(dirname($path), 0775, true); file_put_contents($path, $body); };
        // Kern: gebaute Assets
        $w("$core/public/assets/css/admin.css", 'a{}');
        $w("$core/public/assets/fonts/lato.woff2", 'LATO');
        $w("$core/public/assets/fonts/installed/kern-eigen.woff2", 'KERN');
        $w("$core/public/assets/ext/demo/js/demo.js", 'neu();');
        $w("$core/public/assets/ext/demo/css/demo.css", '.d{}');
        $w("$core/public/assets/ext/demo/evil.php", '<?php echo 1;');
        $w("$core/public/assets/ext/demo/.versteckt", 'x');
        $w("$core/extensions/demo/extension.php", '<?php return [];');
        $w("$core/kits/k1/theme.php", '<?php return [];');
        $w("$core/public/assets/kits/k1/css/site.css", 'body{}');
        // Projekt: eigenes public/, Erweiterung und Kit per Verknüpfung, veraltete Kopie vom Kern
        foreach (['ext', 'kits'] as $d) @mkdir("$root/public/assets/$d", 0775, true);
        @mkdir("$root/extensions", 0775, true);
        @mkdir("$root/kits", 0775, true);
        symlink("$core/extensions/demo", "$root/extensions/demo");
        symlink("$core/kits/k1", "$root/kits/k1");
        $w("$root/public/assets/ext/demo/js/demo.js", 'alt();');
        $w("$root/public/assets/ext/demo/css/demo.css", '.d{}');
        $w("$root/public/assets/ext/demo/js/weg.js", 'weg();');
        $w("$root/public/assets/fonts/lato.woff2", 'LATO-ALT');
        $w("$root/public/assets/fonts/installed/projekt-eigen.woff2", 'PROJEKT');
        // Composer-artiges Paket mit fertigem Ordner public/
        $w("$root/extensions/paket/public/js/p.js", 'p();');
        $w("$root/extensions/paket/public/js/p.phtml", 'nein');

        $avail = ['demo' => ['dir' => "$root/extensions/demo"], 'paket' => ['dir' => "$root/extensions/paket"]];
        $ext = AssetSync::extensionPlan($avail, $root, $core);
        $byName = [];
        foreach ($ext as $e) $byName[$e['name']] = $e;
        $eq('Plan: mitgelieferte Erweiterung verknüpfen, Paket kopieren', [$byName['demo']['mode'] ?? '', $byName['paket']['mode'] ?? ''], [AssetSync::LINK, AssetSync::COPY]);
        $eq('Plan: Quelle der mitgelieferten Erweiterung = gebauter Kern-Ordner', $byName['demo']['src'] ?? '', "$core/public/assets/ext/demo");
        $s = AssetSync::status($byName['demo']);
        $eq('Veraltete Kopie erkannt (1 geändert, 1 alt)', [$s['state'], $s['changed'], $s['removed']], ['stale', 1, 1]);

        // Abgleich als Kopie: nur geänderte Dateien, alte entfernt, Server-Code und versteckte Dateien nie
        $same = "$root/public/assets/ext/demo/css/demo.css";
        touch($same, 1000000000);
        $r = AssetSync::apply($byName['demo'], true, $root, $core);
        $eq('Kopie: geändert', $r['changed'], true);
        $eq('Kopie: neue Fassung, alte Datei weg', [(string) @file_get_contents("$root/public/assets/ext/demo/js/demo.js"), file_exists("$root/public/assets/ext/demo/js/weg.js")], ['neu();', false]);
        clearstatcache();
        $eq('Kopie: unveränderte Datei nicht neu geschrieben', filemtime($same), 1000000000);
        $eq('Kopie: kein PHP, keine versteckte Datei', [file_exists("$root/public/assets/ext/demo/evil.php"), file_exists("$root/public/assets/ext/demo/.versteckt")], [false, false]);
        $eq('Kopie: danach aktuell', AssetSync::status($byName['demo'])['state'], 'ok');
        $eq('Kopie: zweiter Lauf ändert nichts', AssetSync::apply($byName['demo'], true, $root, $core)['changed'], false);

        // Verknüpfen ersetzt die Kopie
        file_put_contents("$root/public/assets/ext/demo/js/demo.js", 'wieder-alt();');
        $r = AssetSync::apply($byName['demo'], false, $root, $core);
        $eq('Verknüpfung statt Kopie', [$r['changed'], is_link("$root/public/assets/ext/demo"), (string) realpath("$root/public/assets/ext/demo")], [true, true, "$core/public/assets/ext/demo"]);
        $eq('Verknüpfung: Status aktuell, Kern unverändert', [AssetSync::status($byName['demo'])['state'], (string) file_get_contents("$core/public/assets/ext/demo/js/demo.js")], ['ok', 'neu();']);
        $eq('Verknüpfung: zweiter Lauf ändert nichts', AssetSync::apply($byName['demo'], false, $root, $core)['changed'], false);

        // Paket mit public/: Kopie, Dateityp-Sperre
        AssetSync::apply($byName['paket'], false, $root, $core);
        $eq('Paket: kopiert (keine Verknüpfung), .phtml gesperrt', [is_link("$root/public/assets/ext/paket"), is_file("$root/public/assets/ext/paket/js/p.js"), file_exists("$root/public/assets/ext/paket/js/p.phtml")], [false, true, false]);

        // Kits
        $kits = AssetSync::kitPlan(['k1' => "$root/kits/k1", 'eigen' => "$root/kits/eigen"], $root, $core);
        $eq('Kit-Plan: nur Kern-Kits', array_column($kits, 'name'), ['k1']);
        $eq('Kit: fehlt → missing', AssetSync::status($kits[0])['state'], 'missing');
        AssetSync::apply($kits[0], false, $root, $core);
        $eq('Kit: verknüpft', [is_link("$root/public/assets/kits/k1"), AssetSync::status($kits[0])['state']], [true, 'ok']);

        // Kern-Assets: css verknüpfen, fonts kopieren ohne fonts/installed anzufassen
        $cp = [];
        foreach (AssetSync::corePlan($root, $core) as $e) $cp[$e['name']] = $e;
        $eq('Kern-Plan: css verknüpfen, fonts kopieren', [$cp['css']['mode'] ?? '', $cp['fonts']['mode'] ?? ''], [AssetSync::LINK, AssetSync::COPY]);
        $eq('Kern: veraltete Schrift erkannt', AssetSync::status($cp['fonts'])['state'], 'stale');
        AssetSync::apply($cp['fonts'], false, $root, $core);
        $eq('Kern: Schrift aktualisiert, installierte Schriften bleiben, Kern-eigene nicht kopiert', [
            (string) file_get_contents("$root/public/assets/fonts/lato.woff2"),
            is_file("$root/public/assets/fonts/installed/projekt-eigen.woff2"),
            file_exists("$root/public/assets/fonts/installed/kern-eigen.woff2"),
        ], ['LATO', true, false]);
        AssetSync::apply($cp['css'], false, $root, $core);
        $eq('Kern: css verknüpft', is_link("$root/public/assets/css"), true);

        // Normale Installation (Wurzel = Kern): nichts zu verknüpfen
        $eq('Ohne verknüpften Kern: keine Kern- und Kit-Einträge', [AssetSync::corePlan($core, $core), AssetSync::kitPlan(['k1' => "$core/kits/k1"], $core, $core)], [[], []]);
        $eq('Ohne verknüpften Kern: mitgelieferte Erweiterung nicht verknüpft', AssetSync::extensionPlan(['demo' => ['dir' => "$core/extensions/demo"]], $core, $core), []);

        // Sicherheit
        $refused = function (array $e, bool $copy = false) use ($root, $core): bool {
            try { AssetSync::apply($e, $copy, $root, $core); return false; } catch (\RuntimeException) { return true; }
        };
        $outside = "$tmp/draussen";
        $w("$outside/keep.txt", 'bleibt');
        $eq('Sicherheit: Ziel außerhalb von public/assets abgelehnt', $refused(['name' => 'x', 'src' => "$core/public/assets/css", 'base' => $tmp, 'dst' => "$tmp/x", 'mode' => AssetSync::COPY, 'keep' => []]), true);
        $eq('Sicherheit: Name mit Pfad abgelehnt', $refused(['name' => '../x', 'src' => "$core/public/assets/css", 'base' => "$root/public/assets/ext", 'dst' => "$root/public/assets/ext/../x", 'mode' => AssetSync::COPY, 'keep' => []]), true);
        $eq('Sicherheit: Verknüpfung nur in den Kern', $refused(['name' => 'y', 'src' => $outside, 'base' => "$root/public/assets/ext", 'dst' => "$root/public/assets/ext/y", 'mode' => AssetSync::LINK, 'keep' => []]), true);
        // Bereichsordner als Verknüpfung (zeigte sonst in den Kern) abgelehnt
        rename("$root/public/assets/kits", "$root/public/assets/kits-alt");
        symlink("$core/public/assets/kits", "$root/public/assets/kits");
        $eq('Sicherheit: verknüpfter Bereichsordner abgelehnt', $refused($kits[0], true), true);
        unlink("$root/public/assets/kits");
        rename("$root/public/assets/kits-alt", "$root/public/assets/kits");
        // Ziel ist eine Verknüpfung woandershin: nur die Verknüpfung wird ersetzt, ihr Ziel bleibt
        self::rm("$root/public/assets/ext/demo");
        symlink($outside, "$root/public/assets/ext/demo");
        $eq('Fremde Verknüpfung erkannt', AssetSync::status($byName['demo'])['state'], 'stale');
        AssetSync::apply($byName['demo'], true, $root, $core);
        $eq('Fremde Verknüpfung ersetzt, ihr Ziel unberührt', [is_link("$root/public/assets/ext/demo"), is_file("$root/public/assets/ext/demo/js/demo.js"), is_file("$outside/keep.txt")], [false, true, true]);
        // Verknüpfung in der Quelle wird nicht mitkopiert
        symlink($outside, "$core/public/assets/ext/demo/link");
        AssetSync::apply($byName['demo'], true, $root, $core);
        $eq('Verknüpfung in der Quelle nicht kopiert', file_exists("$root/public/assets/ext/demo/link"), false);
        // Verknüpfung im Ziel (z. B. js → draußen): wird entfernt, nie verfolgt
        symlink($outside, "$root/public/assets/ext/demo/fremd");
        AssetSync::apply($byName['demo'], true, $root, $core);
        $eq('Verknüpfung im Ziel entfernt, ihr Ziel unberührt', [is_link("$root/public/assets/ext/demo/fremd"), is_file("$outside/keep.txt")], [false, true]);
    }

    private static function rm(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) { @unlink($dir); return; }
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            ($f->isDir() && !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
