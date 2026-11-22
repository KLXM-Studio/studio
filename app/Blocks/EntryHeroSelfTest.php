<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blocks;

use Core\Data\Entries;
use Core\Data\Knowledge;
use Core\Data\Tables;
use Core\Http\Controllers\Admin\DataController;
use Core\Pages;

/**
 * Selbsttest Einstieg mit Quelle „Felder des Eintrags“ (php bin/console hero:selftest --site=…): Felder am Hero des Kits,
 * Werte aus dem Eintrag (Bild, Titel, Dachzeile, Text, Button), fehlendes Bild → schlichte Variante, fehlende/gelöschte Felder,
 * genau eine H1, Bestand unverändert, Vorlage „Kopf wie Seiten“, Wissensbereich mit Kopf über der Seitenleiste. Vorübergehende
 * Tabellen und Seiten, räumt alles wieder ab.
 */
final class EntryHeroSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $ck = function (bool $c, string $msg) use (&$ok, &$fails): void { if ($c) $ok++; else $fails[] = 'Einstieg aus Eintrag – ' . $msg; };
        $theme = app()->theme;
        $def = $theme->block('hero');
        if (!EntryHero::supports($def)) {
            // Kit ohne passenden Einstieg (z. B. eigener Kopf ohne Titel/Bild): nichts ergänzt, nichts zu prüfen
            $ck(!$def || !in_array('source', array_column((array) $def['fields'], 'name'), true), 'Kit ohne Titel/Bild im Einstieg bekommt keine Quelle');
            return ['ok' => $ok, 'fails' => $fails];
        }
        $prevEntry = app()->entry;
        $prevEditing = app()->editing;
        $prevEntryEdit = app()->entryEdit;
        $tables = [];
        $pages = [];
        $h = 'qa_eh_' . bin2hex(random_bytes(3));
        try {
            // 1. Definition: Quelle + Zuordnung vorn, eigene Inhalte nur bei „Eigene Inhalte“
            $names = array_column($def['fields'], null, 'name');
            $ck(($def['fields'][0]['name'] ?? '') === 'source' && !empty($names['source']['entry_only']) && isset($names['src_image'], $names['src_title'], $names['src_eyebrow'], $names['src_text'], $names['src_link']),
                'Felder „Quelle“ und Zuordnung am Hero');
            $si = $names['title']['show_if'] ?? null;
            $ck(is_array($si) && (array_is_list($si) ? array_reduce($si, fn($c, $a) => $c && (($a['source'] ?? null) === ['']), true) : ($si['source'] ?? null) === ['']), 'Titel nur bei „Eigene Inhalte“ sichtbar');
            $ck(count(array_filter($def['fields'], fn($f) => ($f['name'] ?? '') === 'source')) === 1 && EntryHero::decorate($def) === $def, 'Zweimal dekorieren ändert nichts');
            // 2. Tabelle (Vorlage „Neuigkeiten“) mit Bild-, Kategorie-, Beschreibungs- und Link-Feld
            $pd = DataController::presetDef('blog');
            $pd['handle'] = $h;
            $pd['name'] = 'QA Kopf';
            $pd['settings']['route'] = str_replace('_', '-', $h);
            $pd['fields'][] = ['name' => 'mehr', 'label' => 'Mehr', 'type' => 'url'];
            [$clean, $err] = Tables::validate($pd);
            $ck($err === [], 'Vorlage gültig: ' . json_encode($err, JSON_UNESCAPED_UNICODE));
            if ($err) return ['ok' => $ok, 'fails' => $fails];
            Tables::create($clean);
            Tables::flush();
            $tables[] = $h;
            $t = Tables::find($h);
            $kat = (string) array_key_first((array) (Tables::field($t, 'kategorie')['options'] ?? []));
            $img = (int) (app()->db->fetchValue("SELECT id FROM media WHERE mime LIKE 'image/%' ORDER BY id LIMIT 1") ?: 0);
            $save = function (array $v) use ($t): int { [$id, $e] = Entries::save($t, null, $v + ['status' => 'published']); return $e ? 0 : (int) $id; };
            $full = $save(['titel' => 'Musterfest im Park', 'kategorie' => $kat, 'teaser' => 'Ein Tag für alle in Musterstadt.', 'bild' => $img ?: null, 'mehr' => 'https://example.org/fest']);
            $bare = $save(['titel' => 'Ohne Bild und Text']);
            $ck($full > 0 && $bare > 0, 'Einträge gespeichert');
            $ef = Entries::find($t, $full);
            $eb = Entries::find($t, $bare);

            // 3. Standard-Zuordnung
            $dm = EntryHero::defaults($t);
            $ck($dm === ['image' => 'bild', 'title' => '_title', 'eyebrow' => 'kategorie', 'text' => 'teaser'], 'Standard: Bildfeld, Titel, Kategorie, Beschreibung');

            $hero = fn(array $data, array $tunes = []) => ['id' => 'qaeh' . bin2hex(random_bytes(3)), 'type' => 'hero', 'data' => $data, 'tunes' => ['section' => $tunes]];
            $variants = array_keys((array) ($def['variants'] ?? []));
            $imgVariant = (string) (array_values(array_intersect(EntryHero::IMAGE_VARIANTS, $variants))[0] ?? ($variants[0] ?? ''));
            $base = ['source' => EntryHero::SOURCE, 'title' => 'Platzhalter', 'text' => 'Eigener Text', 'eyebrow' => 'Eigene Dachzeile', 'variant' => $imgVariant];

            // 4. Quelle Eintrag: Werte, Bindung, Variante
            app()->editing = false;
            app()->entryEdit = false;
            app()->entry = ['table' => $t, 'entry' => $ef];
            EntryHero::reset();
            $b = $theme->makeBlock($hero($base));
            $kl = (string) (Tables::field($t, 'kategorie')['options'][$kat] ?? '');
            $ck($b && $b->data['title'] === 'Musterfest im Park' && $b->data['text'] === 'Ein Tag für alle in Musterstadt.' && $b->data['eyebrow'] === $kl,
                'Titel, Text und Dachzeile aus dem Eintrag');
            $ck($b && (!$img || (int) $b->data['image'] === $img) && $b->data['variant'] === $imgVariant, 'Bild aus dem Bildfeld, Variante bleibt');
            $ck($b && ($b->data['_bind']['title'] ?? '') === '_title' && ($b->data['_bind']['text'] ?? '') === 'teaser' && EntryHero::$titled, 'Übernommene Felder gebunden, H1 gemerkt');
            // Fokuspunkt: das Bild läuft durch die normalen Bildfunktionen (Media::pictureOf → Fokus-Klasse)
            if ($img && ($m = \Core\Media::find($img))) {
                $fc = \Core\Media::focusClass(['focus_x' => 20, 'focus_y' => 70] + $m);
                $ck($fc === 'fx20 fy70', 'Fokuspunkt: Klassen aus der Mediathek (Media::pictureOf, wie bei jedem Bild)');
            }
            // Button aus Link-Feld
            $b2 = $theme->makeBlock($hero($base + ['src_link' => $h . '.mehr', 'src_link_label' => 'Zum Fest']));
            $ck($b2 && $b2->data['button_link'] === 'https://example.org/fest' && $b2->data['button_label'] === 'Zum Fest', 'Button aus Link-Feld');
            // Auswahl „nichts“ und gelöschtes Feld
            $b3 = $theme->makeBlock($hero($base + ['src_eyebrow' => $h . '._none', 'src_text' => $h . '.gibt_es_nicht']));
            $ck($b3 && $b3->data['eyebrow'] === '' && $b3->data['text'] === '' && !isset($b3->data['_bind']['text']), 'Dachzeile „nichts“, gelöschtes Feld = leer');

            // 5. Eintrag ohne Bild, Text, Kategorie, Link
            app()->entry = ['table' => $t, 'entry' => $eb];
            $b4 = $theme->makeBlock($hero($base + ['src_link' => $h . '.mehr']));
            $ck($b4 && $b4->data['image'] === null && $b4->data['text'] === '' && $b4->data['eyebrow'] === '' && $b4->data['button_label'] === '' && $b4->data['title'] === 'Ohne Bild und Text',
                'Fehlende Felder: leer, kein Button, Titel bleibt');
            $ck($b4 && (isset($def['variants']['compact']) && $imgVariant !== '' ? $b4->data['variant'] === 'compact' : true), 'Ohne Bild: schlichte Variante „compact“');
            $html = $b4 ? $theme->renderBlock($b4) : '';
            $ck(substr_count($html, '<h1') === 1 && str_contains($html, 'Ohne Bild und Text') && !str_contains($html, 'Platzhalter') && !str_contains($html, '{{'), 'Ohne Bild: genau eine H1, nichts Kaputtes');
            if ($img) {
                app()->entry = ['table' => $t, 'entry' => $ef];
                $html = $theme->renderBlock($theme->makeBlock($hero($base)));
                $ck(substr_count($html, '<h1') === 1 && str_contains($html, 'Musterfest im Park') && str_contains($html, '<img'), 'Mit Bild: H1 und Bild im Kit-Renderer');
            }

            // 6. Bestand: eigene Inhalte, gleiche Daten wie ohne die neue Funktion
            app()->entry = ['table' => $t, 'entry' => $ef];
            $own = ['title' => 'Eigener Titel', 'text' => 'Eigener Text', 'variant' => $variants[0] ?? ''];
            $b5 = $theme->makeBlock($hero($own));
            $ck($b5 && $b5->data['title'] === 'Eigener Titel' && $b5->data['text'] === 'Eigener Text' && empty($b5->data['_bind']), 'Eigene Inhalte: unverändert (auch auf Detailseiten)');
            app()->entry = null;
            $b6 = $theme->makeBlock($hero($base));
            $ck($b6 && $b6->data['title'] === 'Platzhalter' && $b6->data['text'] === 'Eigener Text', 'Ohne Eintrag (normale Seite): eigene Inhalte');

            // 7. Vorlage „Kopf wie Seiten“: Hero zuerst, kein zweiter Titel; Datensatz-Felder im Kopf-Layout danach als h2
            $tid = DataController::makeTemplate($t, true);
            $pages[] = $tid;
            $tpl = Pages::find($tid);
            $blocks = Pages::blocks($tpl, false);
            $ck(($blocks[0]['type'] ?? '') === 'hero' && ($blocks[0]['data']['source'] ?? '') === EntryHero::SOURCE && !str_contains(json_encode($blocks), '"layout":"head"'), 'Vorlage „Kopf wie Seiten“: Einstieg mit Quelle Eintrag');
            app()->entry = ['table' => $t, 'entry' => $ef];
            EntryHero::reset();
            $page = $theme->renderBlocks([...$blocks, ['id' => 'qaehdf', 'type' => 'data_fields', 'data' => ['table' => $h, 'layout' => 'head', 'fields' => [$h . '._title']]]]);
            $ck(substr_count($page, '<h1') === 1 && str_contains($page, '<h2 class="h2 df-title"'), 'Genau eine H1 (Datensatz-Felder danach als h2)');
            EntryHero::reset();
            $old = $theme->renderBlocks([['id' => 'qaehdf2', 'type' => 'data_fields', 'data' => ['table' => $h, 'layout' => 'head', 'fields' => [$h . '._title']]]]);
            $ck(str_contains($old, '<h1 class="h2 df-title"'), 'Bestehende Vorlage ohne Einstieg: Titel bleibt H1');
            // unsichtbarer Einstieg setzt keine H1
            EntryHero::reset();
            $theme->makeBlock($hero($base, ['visible' => false]));
            $ck(!EntryHero::$titled, 'Ausgeblendeter Einstieg zählt nicht als H1');

            // 8. Editor: übernommene Felder gekennzeichnet statt direkt bearbeitbar
            app()->editing = true;
            $be = $theme->makeBlock($hero($base));
            $ck($be && str_contains($be->edit('title'), 'data-bound="_title"') && !EntryHero::$titled, 'Editor: Titel „Aus dem Eintrag“');
            app()->editing = false;

            // 9. Wissensbereich: Kopf über die volle Breite, Seitenleiste darunter, Kapitelnummer als Dachzeile
            $kh = $h . 'kn';
            $kd = DataController::presetDef('knowledge');
            $kd['handle'] = $kh;
            $kd['name'] = 'QA Wissen Kopf';
            $kd['settings']['route'] = str_replace('_', '-', $kh);
            [$kc, $kerr] = Tables::validate($kd);
            $ck($kerr === [], 'Wissen: Vorlage gültig');
            if (!$kerr) {
                Tables::create($kc);
                Tables::flush();
                $tables[] = $kh;
                $kt = Tables::find($kh);
                $pages[] = Knowledge::makeTemplate($kt, true);
                $kt = Tables::find($kh);
                [$kid] = Entries::save($kt, null, ['titel' => 'Erstes Kapitel', 'anreisser' => 'Worum es geht.', 'status' => 'published',
                    'inhalt' => [['id' => 'qaehrt', 'type' => 'richtext', 'data' => ['text' => '<h2>Teil A</h2><p>x</p><h2>Teil B</h2><p>y</p>']]]]);
                Knowledge::flush();
                $ke = Entries::find($kt, (int) $kid);
                app()->entry = ['table' => $kt, 'entry' => $ke];
                $kb = Pages::blocks(Pages::find((int) $kt['settings']['detail_page_id']), false);
                $ck(EntryHero::leadIndex($kb) === 0, 'Wissen: Einstieg ist der erste Block');
                $out = Knowledge::render($theme, $kb, $kt, $ke);
                $hp = strpos($out, '<h1');
                $kp = strpos($out, 'kn--headed');
                $num = (string) (Knowledge::tree($kt)['nodes'][(int) $kid]['number'] ?? '');
                $ck($hp !== false && $kp !== false && $hp < $kp && strpos($out, 'kn-side') > $kp, 'Wissen: Kopf vor dem Rahmen, Seitenleiste darunter');
                $ck(!str_contains($out, 'kn-num--h1') && $num !== '' && str_contains($out, lt('Kapitel {n}', ['n' => $num])) && substr_count($out, '<h1') === 1, 'Wissen: Kapitelnummer als Dachzeile, nicht in der H1');
                $ck(str_contains($out, 'kn-crumbs') && str_contains($out, 'Worum es geht.'), 'Wissen: Brotkrumen unter dem Kopf, Anreißer im Kopf');
                $kt2 = $kt;
                $kt2['settings']['knowledge'] = ['numbers' => false] + Knowledge::DEFAULTS;
                app()->entry = ['table' => $kt2, 'entry' => $ke];
                $out2 = Knowledge::render($theme, $kb, $kt2, $ke);
                $ck(!str_contains($out2, lt('Kapitel {n}', ['n' => $num])) && !str_contains($out2, 'kn-num--badge'), 'Wissen: ohne Nummern keine Dachzeile, keine Plakette');
                app()->entry = ['table' => $kt, 'entry' => $ke];
                $kb2 = $kb;
                $kb2[0]['data']['src_eyebrow'] = $kh . '.bereich';
                $out3 = Knowledge::render($theme, $kb2, $kt, $ke);
                $ck(str_contains($out3, 'kn-num kn-num--badge') && str_contains($out3, '</span>' . $num . '</span>') && !str_contains($out3, lt('Kapitel {n}', ['n' => $num])) && substr_count($out3, '<h1') === 1,
                    'Wissen: Dachzeile mit Feld belegt → Nummer als Plakette in der H1');
                $ck(!str_contains($out, 'kn-num--badge'), 'Wissen: Nummer in der Dachzeile → keine Plakette');
                // Ohne Einstieg zuerst: Rahmen wie bisher (Nummer vor der H1)
                EntryHero::reset();
                $plain = Knowledge::render($theme, [['id' => 'qaehdf3', 'type' => 'data_fields', 'data' => ['table' => $kh, 'layout' => 'head', 'fields' => [$kh . '._title']]]], $kt, $ke);
                $ck(str_contains($plain, 'kn-num--h1') && !str_contains($plain, 'kn--headed'), 'Wissen ohne Einstieg: unverändert');
                // Einstieg neben einem anderen Block (Reihe) oder ausgeblendet davor → kein Kopf über voller Breite bzw. der nächste zählt
                $ck(EntryHero::leadIndex([$kb[0], ['id' => 'x', 'type' => 'richtext', 'data' => [], 'tunes' => ['section' => ['row' => 'auto']]]]) === null, 'Wissen: Einstieg in einer Reihe bleibt im Rahmen');
                $ck(EntryHero::leadIndex([['id' => 'y', 'type' => 'richtext', 'data' => [], 'tunes' => ['section' => ['visible' => false]]], $kb[0]]) === 1, 'Wissen: ausgeblendeter Block davor wird übersprungen');
            }
        } finally {
            app()->entry = $prevEntry;
            app()->editing = $prevEditing;
            app()->entryEdit = $prevEntryEdit;
            EntryHero::reset();
            foreach ($tables as $x) if ($tt = Tables::find($x)) Tables::delete($tt);
            foreach (array_reverse($pages) as $pid) Pages::db()->query('DELETE FROM pages WHERE id = ?', [$pid]);
            Pages::rebuildPaths();
            Tables::flush();
            Knowledge::flush();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
