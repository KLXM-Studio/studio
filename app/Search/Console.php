<?php
declare(strict_types=1);

namespace Core\Search;

use Core\AI\Ai;
use Core\Lang;
use Core\Pages;
use Core\Sites;

/**
 * Kommandozeile der Suche und KI (bin/console):
 *   search:index [--all] [--full] [--no-vectors] [--kb]   Index abgleichen (Standard: nur Änderungen)
 *   search:index --help [--no-vectors]                    Hilfe-Index des Assistenten (Core\AI\HelpIndex)
 *   search:status [--all]                                 Stand des Index, Vektoren, KI-Anbieter
 *   search:query "<text>" [--lang=en]                     Testsuche (wie Besucher, ohne Zähler)
 *   ai:test [--cap=text|embed|vision]                     KI-Anbieter je Fähigkeit prüfen (Standard: alle konfigurierten)
 */
final class Console
{
    public static function run(string $cmd, array $args, string $script): int
    {
        if (in_array('--all', $args, true)) {
            $rest = array_values(array_filter($args, fn($a) => $a !== '--all'));
            $fail = 0;
            foreach (array_keys(Sites::all()) as $k) {
                echo "── $k\n";
                passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $cmd . ' ' . implode(' ', array_map('escapeshellarg', $rest)) . ' --site=' . escapeshellarg($k), $code);
                $fail = $fail ?: $code;
            }
            return $fail;
        }
        $pos = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
        $opt = fn(string $name) => in_array('--' . $name, $args, true);
        return match ($cmd) {
            'search:index' => $opt('help') ? self::help(!$opt('no-vectors')) : self::index($opt('full'), !$opt('no-vectors'), $opt('kb')),
            'search:status' => self::status(),
            'search:query' => self::query(implode(' ', $pos), $args),
            'search:selftest' => self::selftest(),
            'ai:test' => self::aiTest(array_values(array_filter(array_map(fn($a) => str_starts_with($a, '--cap=') ? substr($a, 6) : null, $args)))),
            default => 1,
        };
    }

    /**
     * Selbsttest: Ausschluss aus der Website-Suche – Seite (search_exclude), Abschnitt (Tune noSearch, auch Spalten eines
     * Layouts), noindex; in einer Transaktion, die zurückgerollt wird.
     */
    private static function selftest(): int
    {
        $fail = 0;
        $eq = function (string $name, mixed $got, mixed $want) use (&$fail): void {
            $ok = $got === $want;
            if (!$ok) $fail++;
            echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok ? '' : ' – erwartet ' . var_export($want, true) . ', erhalten ' . var_export($got, true)) . "\n";
        };
        $type = null;
        foreach (['richtext', 'text'] as $t) if (app()->theme->block($t)) { $type = $t; break; }
        if (!$type) { echo "  – Kit ohne Textblock, übersprungen\n"; return 0; }
        $para = fn(string $txt, array $tunes = []) => ['id' => substr(md5($txt), 0, 8), 'type' => $type, 'data' => ['text' => '<p>' . $txt . '</p>'], 'tunes' => ['section' => $tunes]];
        $pdo = app()->db->pdo;
        $pdo->beginTransaction();
        try {
            $lang = Lang::default();
            $mk = fn(string $slug, array $blocks, array $f = []) => Pages::create($f + ['slug' => $slug, 'title' => 'Suchtest ' . $slug, 'status' => 'published', 'lang' => null, 'sort' => 9999], $blocks);
            $a = $mk('suchtest-a', [$para('Sichtbarwort'), $para('Versteckwort', ['noSearch' => true])]);
            $b = $mk('suchtest-b', [$para('Ausgeschlossenwort')], ['search_exclude' => 1]);
            $c = $mk('suchtest-c', [$para('Noindexwort')], ['noindex' => 1]);
            $docs = Documents::build($lang);
            $text = (string) ($docs['p-' . $a]['text'] ?? '');
            $eq('Seite im Index', isset($docs['p-' . $a]), true);
            $eq('Abschnitt sichtbar → durchsucht', str_contains($text, 'Sichtbarwort'), true);
            $eq('Abschnitt „Nicht in der Website-Suche“ → nicht durchsucht', str_contains($text, 'Versteckwort'), false);
            $eq('Seite „Nicht in der Website-Suche“ → nicht im Index', isset($docs['p-' . $b]), false);
            $eq('noindex → nicht im Index', isset($docs['p-' . $c]), false);
            [, $lt] = Documents::blocksText([['type' => 'layout', 'data' => ['columns' => [['blocks' => [$para('Spaltenwort')]]]], 'tunes' => ['section' => ['noSearch' => true]]]]);
            $eq('Layout „Nicht in der Website-Suche“ → Spalten nicht durchsucht', str_contains(implode(' ', $lt), 'Spaltenwort'), false);
            $eq('Tune wird gespeichert (Theme::sanitizeTunes)', app()->theme->sanitizeTunes(['noSearch' => '1'], app()->theme->block($type))['noSearch'], true);
        } finally {
            $pdo->rollBack();
        }
        // Tabellen legt Doctrine über eine eigene Verbindung an (nicht in der Transaktion) – angelegt und danach wieder gelöscht
        $made = [];
        try {
            self::selftestEntryBlocks($eq, Lang::default(), $made);
        } catch (\Throwable $e) {
            $eq('Inhaltsblöcke – Ausnahme: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', false, true);
        } finally {
            \Core\Data\Tables::flush();
            foreach ($made as $h) if ($x = \Core\Data\Tables::find($h)) \Core\Data\Tables::delete($x);
            \Core\Data\Tables::flush();
            \Core\Data\Knowledge::flush();
        }
        echo $fail ? "\n{$fail} Fehler\n" : "\nAlles in Ordnung\n";
        return $fail ? 1 : 0;
    }

    /**
     * Selbsttest Inhaltsblöcke in Einträgen: Wissenstabelle (Vorlage „Wissen“ mit Detailseite) – Wort aus dem Rich-Text im Text,
     * Zwischenüberschrift in den Überschriften, Sprungmarke wie auf der Detailseite („In diesem Kapitel“), Reiter „Wissen“,
     * keine verschachtelte Datenliste; zweite Tabelle mit einem Inhaltsblöcke-Feld „Nie öffentlich“ → nicht im Index.
     */
    private static function selftestEntryBlocks(\Closure $eq, string $lang, array &$made): void
    {
        $theme = app()->theme;
        if (!$theme->block('richtext')) { echo "  – Kit ohne Block „richtext“: Inhaltsblöcke übersprungen\n"; return; }
        $h = 'suchtest_wissen_' . bin2hex(random_bytes(2));
        $made[] = $h;
        $res = \Core\Data\Templates::install('knowledge', ['handle' => $h, 'name' => 'Suchtest Wissen', 'slug' => str_replace('_', '-', $h), 'detail' => true], fn() => null);
        $t = $res['table'] ?? null;
        if (!$res['ok'] || !$t || empty($t['settings']['detail_page_id'])) { $eq('Wissenstabelle angelegt', false, true); return; }
        $rt = fn(string $html, string $title = '') => ['id' => substr(md5($html . $title), 0, 8), 'type' => 'richtext', 'data' => ['title' => $title, 'text' => $html], 'tunes' => []];
        $blocks = [$rt('<p>Einleitung mit Quittenkompott.</p><h2>Anreise nach Musterstadt</h2><p>Bahnhof Zinnoberheim.</p><h2>Musterstadt bei Nacht</h2><p>Führung.</p>')];
        if ($theme->block('data_list')) $blocks[] = ['id' => 'dl000001', 'type' => 'data_list', 'data' => ['table' => $h], 'tunes' => []];
        [$id, $err] = \Core\Data\Entries::save($t, null, ['titel' => 'Kapitel Suchtest', 'inhalt' => $blocks, 'status' => 'published']);
        $eq('Kapitel gespeichert', $err, []);
        \Core\Data\Knowledge::flush();
        $doc = Documents::build($lang)['e-' . $h . '-' . $id] ?? null;
        $eq('Kapitel im Index', $doc !== null, true);
        if (!$doc) return;
        $eq('Wort aus dem Rich-Text der Inhaltsblöcke im Text', str_contains($doc['text'], 'Quittenkompott'), true);
        $eq('Zwischenüberschrift in den Überschriften (hoch)', str_contains($doc['headings'], 'Anreise nach Musterstadt'), true);
        $eq('Reiter/Art „Wissen“', $doc['badge'], lt('Wissen'));
        $words = Text::words('Anreise Musterstadt');
        $sec = Documents::anchorFor($doc['anchors'], $words);
        $eq('Sprungmarke zur passenden Überschrift', $sec['id'] ?? null, 'kn-anreise-nach-musterstadt');
        $eq('Mehrere Überschriften passen: die erste', Documents::anchorFor($doc['anchors'], Text::words('Musterstadt'))['id'] ?? null, 'kn-anreise-nach-musterstadt');
        $eq('Keine Sprungmarke, wenn keine Überschrift passt', Documents::anchorFor($doc['anchors'], Text::words('Quittenkompott')), null);
        $eq('Verschachtelte Datenliste nicht indexiert (kein Rundlauf über den eigenen Eintrag)', substr_count($doc['text'] . ' ' . $doc['headings'], 'Kapitel Suchtest'), 0);
        // Sprungmarke = ID auf der Detailseite (wie „In diesem Kapitel“ sie vergibt)
        if (!app()->settings->get('sys.maintenance')) {
            $prev = [app()->entry, app()->request, app()->currentPage];
            $e = \Core\Data\Entries::find(\Core\Data\Tables::find($h) ?? $t, (int) $id) ?? [];
            $path = $t['settings']['route'] . '/' . ($e['slug'] ?? '');
            try {
                $page = (new \Core\Http\Controllers\SiteController())->page(new \Core\Http\Request('GET', '/' . $path, ['selbsttest' => '1'], [], [], []), $path);
                $eq('Detailseite hat dieselbe ID', [$page->status, str_contains($page->body, 'id="kn-anreise-nach-musterstadt"')], [200, true]);
            } finally {
                [app()->entry, app()->request, app()->currentPage] = $prev;
            }
        }
        // Inhaltsblöcke „Nie öffentlich“ in einer zweiten Tabelle
        $h2 = 'suchtest_priv_' . bin2hex(random_bytes(2));
        [$def, $errors] = \Core\Data\Tables::validate(['name' => 'Suchtest Beiträge', 'singular' => 'Beitrag', 'handle' => $h2, 'fields' => [
            ['label' => 'Titel', 'type' => 'text', 'required' => 1],
            ['label' => 'Inhalt', 'name' => 'inhalt', 'type' => 'blocks'],
            ['label' => 'Intern', 'name' => 'intern', 'type' => 'blocks', 'private' => 1],
        ], 'settings' => ['route' => str_replace('_', '-', $h2), 'workflow' => 0]]);
        $eq('Tabelle mit privatem Inhaltsblöcke-Feld angelegt', $errors, []);
        if ($errors) return;
        $made[] = $h2;
        \Core\Data\Tables::create($def);
        \Core\Data\Tables::flush();
        $t2 = \Core\Data\Tables::find($h2) ?? throw new \RuntimeException('Tabelle fehlt');
        $pid = Pages::create(['slug' => '_vorlage-' . $h2, 'title' => 'Suchtest Detail', 'type' => 'template', 'template_for' => $h2, 'status' => 'published', 'noindex' => 1],
            Pages::sanitizeBlocks([['id' => 'eb000001', 'type' => 'entry_blocks', 'data' => ['table' => $h2, 'field' => $h2 . '.inhalt']]]));
        \Core\Data\Tables::setDetailPage($t2, $pid);
        $t2['settings']['detail_page_id'] = $pid;
        $cfg = TableSearch::config($t2);
        $eq('Inhaltsblöcke: Standard „normal“, „Nie öffentlich“ aus', [$cfg['fields']['inhalt'] ?? null, $cfg['fields']['intern'] ?? null], ['normal', 'off']);
        [$id2] = \Core\Data\Entries::save($t2, null, ['titel' => 'Beitrag Suchtest', 'inhalt' => [$rt('<p>Öffentlich Ahornblatt.</p>')],
            'intern' => [$rt('<p>Geheim Distelfink.</p>')], 'status' => 'published']);
        $doc2 = Documents::build($lang)['e-' . $h2 . '-' . $id2] ?? null;
        $all = (string) json_encode($doc2, JSON_UNESCAPED_UNICODE);
        $eq('Öffentliches Inhaltsblöcke-Feld im Index, privates nicht', [str_contains($all, 'Ahornblatt'), str_contains($all, 'Distelfink')], [true, false]);
        $eq('Art ohne Wissen: Einzahl der Tabelle', $doc2['badge'] ?? null, lt((string) $t2['singular']));
    }

    /** Hilfe-Index des Assistenten (Core\AI\HelpIndex): Handbuch, Technik, Tutorials – Stichwörter + Vektoren */
    private static function help(bool $vectors): int
    {
        $r = \Core\AI\HelpIndex::rebuild($vectors, fn($m) => print($m . "\n"));
        echo "Hilfe-Index (" . site()->key . "): {$r['sections']} Abschnitte, {$r['vectors']} neue Vektoren.\n";
        if ($r['error']) { fwrite(STDERR, "KI-Anbieter: {$r['error']} (Stichwortsuche funktioniert weiter)\n"); return 2; }
        return 0;
    }

    private static function index(bool $full, bool $vectors, bool $kb): int
    {
        if ($kb) {
            $cfg = Ai::installationConfig();
            if (!$cfg['enabled'] || $cfg['embeddings'] === '') { fwrite(STDERR, "Keine KI mit Embeddings in config/config.local.php ('ai').\n"); return 1; }
            $n = (new SupportSemantic($cfg))->reindex(fn($m) => print($m . "\n"));
            echo "Wissensdatenbank: $n Abschnitte eingebettet ({$cfg['provider']}:{$cfg['embeddings']}).\n";
            return 0;
        }
        if (!Search::enabled()) { echo "Suche ist auf dieser Website abgeschaltet (features: search).\n"; return 0; }
        $s = Search::process(force: true, full: $full, vectors: $vectors, log: fn($m) => print($m . "\n"), wait: 300);
        if ($s === null) { fwrite(STDERR, "Abgleich läuft bereits (Sperre " . Search::dir() . "/.lock).\n"); return 1; }
        printf("Suchindex aktuell (%s): %d Dokumente, %d neu, %d geändert, %d entfernt%s – %.2f s\n", site()->key, $s['docs'], $s['added'], $s['updated'], $s['removed'],
            $vectors && Search::semanticActive() ? sprintf(', Vektoren: %d Dokumente/%d Abschnitte%s', $s['vectors'], $s['chunks'], $s['pending'] ? ", {$s['pending']} ausstehend" : '') : '', $s['seconds']);
        if ($s['vector_error']) { fwrite(STDERR, "KI-Anbieter: {$s['vector_error']} (Stichwortsuche funktioniert weiter)\n"); return 2; }
        return 0;
    }

    private static function status(): int
    {
        $st = Search::status();
        $cfg = Ai::config();
        echo "Website " . site()->key . ': Suche ' . ($st['enabled'] ? 'an' : 'aus') . ', semantisch ' . ($st['semantic'] ? 'an' : 'aus') . "\n";
        foreach ($st['languages'] as $l => $x) {
            printf("  %-5s %4d Dokumente, Stichwort-Index %4d%s\n", $l, $x['docs'], $x['keyword'], $st['semantic'] ? sprintf(', Vektoren %d', $x['vectors']) : '');
        }
        printf("  Stand: %s%s · Abschnitte: %d · Größe: %s · %s\n", $st['synced_at'] ?? 'nie', $st['dirty'] ? ' (Änderungen vorgemerkt)' : '',
            $st['chunks'], \Core\Media::humanSize((int) $st['bytes']), $st['dir']);
        if ($st['error']) echo "  Fehler: {$st['error']}\n";
        if ($st['vector_error']) echo "  KI-Fehler: {$st['vector_error']}\n";
        $e = Ai::capability('embed', $cfg);
        echo '  KI (Embeddings): ' . ($e['configured'] ? $e['provider'] . ' · ' . $e['endpoint'] . ' · ' . $e['model'] . ($e['external'] ? ' · EXTERN' : ' · lokal') . (Ai::enabled('embed') ? '' : ' · für diese Website aus') : 'nicht konfiguriert') . "\n";
        return 0;
    }

    private static function query(string $q, array $args): int
    {
        foreach ($args as $a) {
            if (str_starts_with($a, '--lang=') && \Core\Lang::valid(substr($a, 7))) app()->lang = substr($a, 7);
        }
        $r = Search::query($q, ['count_miss' => false]);
        printf("%d Treffer (%s%s)\n", $r['total'], $r['mode'], $r['fallback'] ? ', Rückfall auf Stichwortsuche' : '');
        foreach ($r['items'] as $i => $it) {
            printf("%2d. [%s|%s] %s\n    %s\n    %s\n", $i + 1, $it['badge'], $it['match'], strip_tags($it['title']), $it['url'], mb_strimwidth(strip_tags($it['snippet']), 0, 140, '…'));
        }
        return 0;
    }

    private static function aiTest(array $caps): int
    {
        $cfg = Ai::config();
        if (!$cfg['configured']) { fwrite(STDERR, "Kein KI-Anbieter konfiguriert (Grundeinstellungen → KI oder config 'ai').\n"); return 1; }
        $site = Ai::siteSettings();
        echo 'Website ' . site()->key . ': KI ' . ($site['enabled'] ? 'an' : 'aus') . ' · Funktion „ai“ ' . (\Core\Features::on('ai', false) ? 'an' : 'aus') . "\n";
        $fail = 0;
        foreach ($caps ?: array_keys(Ai::CAPS) as $cap) {
            if (!isset(Ai::CAPS[$cap])) { fwrite(STDERR, "Unbekannte Fähigkeit „{$cap}“ (text, embed, vision, transcribe).\n"); return 1; }
            $c = Ai::capability($cap, $cfg);
            if (!$c['configured'] && !$caps) continue;
            $t = Ai::test($cap, $cfg);
            printf("  %-6s %-8s %-28s %s%s%s%s\n", $cap, $t['provider'] ?: '–', $t['model'] ?: '–', $t['ok'] ? 'OK' : 'FEHLER', $t['ms'] !== null ? " · {$t['ms']} ms" : '',
                $t['dims'] ? " · {$t['dims']} Dimensionen" : ($t['text'] !== null ? ' · „' . $t['text'] . '“' : ''), $t['error'] ? ' · ' . $t['error'] : '');
            echo '         ' . $t['endpoint'] . ($t['external'] ? ' (extern – Inhalte verlassen den Server)' : ' (lokal)') . ($site['caps'][$cap] ? '' : ' · auf dieser Website ausgeschaltet') . "\n";
            $fail += $t['ok'] ? 0 : 1;
        }
        return $fail ? 1 : 0;
    }
}
