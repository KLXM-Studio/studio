<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/**
 * Selbsttest „Weitere Links im Fußbereich“ (php bin/console footer:selftest --site=…, Core\FooterLinks):
 * Standard leer (neue Seiten ohne Schalter, Fußbereich byte-gleich), Liste mit Reihenfolge, Seiten mit „Im Fußbereich zeigen“
 * in Baumreihenfolge nach der Liste, doppelte Ziele einmal, Seiten aus dem Menü nicht doppelt, Entwürfe und leere Zeilen fallen
 * weg, aria-current, neuer Tab mit Hinweis, Escaping; mit Kit fluid bzw. native auch die Ausgabe aller Fuß-Varianten.
 * Vorübergehende Seiten in einer Transaktion (zurückgerollt), Einstellung nur im Speicher (Settings::override).
 */
final class FooterLinksSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $ck = function (bool $c, string $msg) use (&$ok, &$fails): void { if ($c) $ok++; else $fails[] = 'Fußbereich – ' . $msg; };
        $key = FooterLinks::SETTING;
        $settings = app()->settings;
        $hadValue = $settings->has($key);
        $prevValue = $settings->get($key);
        $prevPage = app()->currentPage;
        $kit = isset(Kit::chain(app()->theme->name)['fluid']);   // fluid und Kind-Kits (native …) geben die Links aus
        $render = function (string $variant): string {
            Design::override(['footer' => $variant] + Design::values());
            try {
                return app()->theme->partial('footer');
            } finally {
                Design::override(null);
            }
        };
        $pdo = app()->db->pdo;
        $pdo->beginTransaction();
        try {
            $settings->override([$key => null]);
            app()->currentPage = Pages::home();
            $base = FooterLinks::items();
            $baseHtml = $kit ? array_map($render, ['columns', 'simple', 'centered', 'statement']) : [];

            // 1. Standard: neue Seiten ohne Schalter ändern nichts
            $mk = fn(string $slug, string $title, array $x = []) => Pages::create($x + ['slug' => 'qa-fuss-' . $slug . '-' . bin2hex(random_bytes(2)), 'title' => $title,
                'lang' => Lang::default() === Lang::current() ? null : Lang::current(), 'status' => 'published', 'sort' => 9000]);
            $a = $mk('a', 'QA Newsletter', ['sort' => 9001]);
            $b = $mk('b', 'QA Presse', ['sort' => 9003, 'footer' => 1]);
            $c = $mk('c', 'QA Entwurf', ['status' => 'draft', 'footer' => 1]);
            $d = $mk('d', 'QA Downloads', ['sort' => 9002, 'footer' => 1, 'nav_title' => 'QA Dateien']);
            $col = array_column(app()->db->fetchAll('SELECT id, footer FROM pages WHERE id = ?', [$a]), 'footer');
            $ck(($col[0] ?? null) !== null && (int) $col[0] === 0, 'Spalte pages.footer vorhanden, Standard 0');
            $qa = fn(array $items) => array_values(array_filter($items, fn($l) => str_starts_with($l['label'], 'QA ')));
            $labels = fn(array $items) => array_column($qa($items), 'label');

            // 2. Seitenschalter: nur veröffentlichte, Baumreihenfolge (sort), Beschriftung im Menü vor Titel
            $ck($labels(FooterLinks::items()) === ['QA Dateien', 'QA Presse'], 'Seitenschalter: veröffentlichte in Baumreihenfolge, nav_title – ' . json_encode($labels(FooterLinks::items()), JSON_UNESCAPED_UNICODE));
            $ck(!in_array('QA Entwurf', $labels(FooterLinks::items()), true), 'Entwurf mit Schalter erscheint nicht');
            $ck($labels(FooterLinks::items([$b])) === ['QA Dateien'], 'Seite aus dem Menü ($skipIds) nicht doppelt');

            // 3. Liste: Reihenfolge der Liste, vor den Seiten; doppelte Ziele einmal; Entwurf/leer/fehlend fällt weg
            $settings->override([$key => [
                ['label' => 'QA News abonnieren', 'link' => 'page:' . $a],
                ['label' => 'QA Extern', 'link' => 'https://example.org/presse'],
                ['label' => '', 'link' => 'page:' . $a],
                ['label' => 'QA Ohne Ziel', 'link' => ''],
                ['label' => 'QA Entwurf Liste', 'link' => 'page:' . $c],
                ['label' => 'QA Gelöscht', 'link' => 'page:987654321'],
                ['label' => 'QA Downloads Liste', 'link' => 'page:' . $d],
                ['label' => 'QA Doppelt', 'link' => 'https://example.org/presse'],
                ['label' => 'QA <b>Fett</b>', 'link' => 'page:' . $a . '#anmelden'],
            ]]);
            $items = FooterLinks::items();
            $ck($labels($items) === ['QA News abonnieren', 'QA Extern', 'QA Downloads Liste', 'QA <b>Fett</b>', 'QA Presse'],
                'Liste zuerst in ihrer Reihenfolge, dann Seiten mit Schalter ohne Doppelte – ' . json_encode($labels($items), JSON_UNESCAPED_UNICODE));
            $byLabel = array_column($qa($items), null, 'label');
            $pa = Pages::find($a);
            $ck(($byLabel['QA News abonnieren']['href'] ?? '') === Pages::url($pa), 'page:ID → Adresse der Seite');
            $ck(($byLabel['QA <b>Fett</b>']['href'] ?? '') === Pages::url($pa) . '#anmelden', 'page:ID#anker → Adresse mit Anker');
            $ck(($byLabel['QA Extern']['external'] ?? false) === true && ($byLabel['QA News abonnieren']['external'] ?? true) === false, 'Externe Adresse erkannt');
            $ck($labels(FooterLinks::items([$a])) === ['QA Extern', 'QA Downloads Liste', 'QA <b>Fett</b>', 'QA Presse'], 'Listeneintrag auf eine Seite aus dem Menü fällt weg (Anker bleibt)');

            // 4. Aktuelle Seite, HTML: aria-current, neuer Tab mit Hinweis, Escaping
            app()->currentPage = $pa;
            $items = FooterLinks::items();
            $byLabel = array_column($qa($items), null, 'label');
            $ck(($byLabel['QA News abonnieren']['current'] ?? false) === true && ($byLabel['QA Presse']['current'] ?? true) === false, 'current nur für die aufgerufene Seite');
            $html = FooterLinks::html($qa($items));
            $ck(substr_count($html, 'aria-current="page"') === 2, 'aria-current an der aktuellen Seite (Link und Anker-Link)');
            $ck(str_contains($html, 'href="https://example.org/presse" target="_blank" rel="noopener">QA Extern<span class="sr-only">'), 'Extern: neuer Tab mit Hinweis für Screenreader');
            $ck(str_contains($html, 'QA &lt;b&gt;Fett&lt;/b&gt;') && !str_contains($html, '<b>'), 'Beschriftung escaped');
            $ck(FooterLinks::html([]) === '' && footer_nav_html([]) === '' && footer_nav_links() === FooterLinks::items(), 'Helfer footer_nav_links()/footer_nav_html()');

            // 5. Ausgabe im Kit (fluid/native): alle Fuß-Varianten, Reihenfolge unter „Seiten“
            if ($kit) {
                foreach (['columns', 'simple', 'centered', 'statement'] as $v) {
                    $out = $render($v);
                    $p1 = strpos($out, 'QA News abonnieren');
                    $p2 = strpos($out, 'QA Extern');
                    $p3 = strpos($out, 'QA Presse');
                    $ck($p1 !== false && $p2 !== false && $p3 !== false && $p1 < $p2 && $p2 < $p3, "Kit-Ausgabe „{$v}“: Links in Reihenfolge");
                    $menu = array_column(Pages::menu(false), 'label');
                    $last = $menu ? strrpos($out, '>' . e((string) end($menu)) . '</a>') : false;
                    $ck(!$menu || ($last !== false && $last < $p1), "Kit-Ausgabe „{$v}“: nach den Seiten des Menüs");
                }
                // Liste leer, Schalter aus → Fußbereich byte-gleich wie vorher
                $settings->override([$key => null]);
                app()->db->query('UPDATE pages SET footer = 0 WHERE id IN (?, ?, ?)', [$b, $c, $d]);
                app()->currentPage = Pages::home();
                $ck(array_map($render, ['columns', 'simple', 'centered', 'statement']) === $baseHtml, 'Leer: Fußbereich aller Varianten byte-gleich');
            }
            $settings->override([$key => null]);
            app()->db->query('UPDATE pages SET footer = 0 WHERE id IN (?, ?, ?)', [$b, $c, $d]);
            $ck($qa(FooterLinks::items()) === [] && count(FooterLinks::items()) === count($base), 'Leer: keine weiteren Links');
        } catch (\Throwable $e) {
            $fails[] = 'Fußbereich – Ausnahme: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $settings->override([$key => $hadValue ? $prevValue : null]);
            app()->currentPage = $prevPage;
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
