<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blocks;

use Core\Data\Entries;
use Core\Data\Featured;
use Core\Data\Tables;
use Core\Http\Controllers\Admin\DataController;

/**
 * Selbsttest Einstieg als Slider (php bin/console slider:selftest --site=…): Felder am Hero des Kits (Standard aus), Bestand
 * unverändert, Markup (eine H1, übrige h2, Rollen, „x von n“, ohne Skript nur die erste Folie, eindeutige IDs, verzögerte Bilder),
 * Folien aus hervorgehobenen Einträgen (Zuordnung wie Detail-Hero, Button zur Detailseite), Bearbeiten-Modus, Stylesheet und Skript
 * nur bei Bedarf. Vorübergehende Tabelle, räumt alles wieder ab.
 */
final class HeroSliderSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $ck = function (bool $c, string $msg) use (&$ok, &$fails): void { if ($c) $ok++; else $fails[] = 'Slider – ' . $msg; };
        $theme = app()->theme;
        $def = $theme->block('hero');
        if (!HeroSlider::supports($def)) {
            $ck(!$def || !in_array('slider', array_column((array) $def['fields'], 'name'), true), 'Kit ohne Titel im Einstieg bekommt keinen Slider');
            return ['ok' => $ok, 'fails' => $fails];
        }
        $prevEditing = app()->editing;
        $prevEntry = app()->entry;
        $tables = [];
        try {
            app()->editing = false;
            app()->entry = null;
            // 1. Felder: Abschnitt „Slider“ am Ende, Standard aus, Folienfelder = Inhaltsfelder des Kits, zweimal dekorieren ändert nichts
            $names = array_column($def['fields'], null, 'name');
            $ck(isset($names['slider'], $names['slides'], $names['slides_sources'], $names['slides_transition'], $names['slides_interval']) && $names['slider']['default'] === false, 'Felder am Hero, Standard aus');
            $sub = array_column((array) $names['slides']['fields'], 'name');
            $ck(in_array('title', $sub, true) && !array_diff($sub, HeroSlider::CONTENT) && !array_filter((array) $names['slides']['fields'], fn($f) => isset($f['show_if'])), 'Folienfelder = Inhaltsfelder des Kits ohne Bedingungen');
            $ck(HeroSlider::decorate($def) === $def, 'Zweimal dekorieren ändert nichts');

            $img = (int) (app()->db->fetchValue("SELECT id FROM media WHERE mime LIKE 'image/%' AND deleted_at IS NULL ORDER BY id LIMIT 1") ?: 0);
            $img2 = (int) (app()->db->fetchValue("SELECT id FROM media WHERE mime LIKE 'image/%' AND deleted_at IS NULL AND id > ? ORDER BY id LIMIT 1", [$img]) ?: $img);
            $variants = array_keys((array) ($def['variants'] ?? []));
            $variant = in_array('fullbleed', $variants, true) ? 'fullbleed' : (string) ($variants[0] ?? '');
            $base = ['title' => 'Willkommen in Musterstadt', 'eyebrow' => 'Start', 'text' => 'Eigener Text', 'variant' => $variant] + (isset($names['image']) ? ['image' => $img ?: null] : []);
            $mk = fn(array $data, string $id = '') => $theme->makeBlock(['id' => $id ?: 'qahs' . bin2hex(random_bytes(3)), 'type' => 'hero', 'data' => $data]);

            // 2. Bestand: ohne Schalter bzw. mit Schalter, aber ohne weitere Folien – Ausgabe wie bisher
            $plain = $theme->renderBlock($mk($base, 'qahsplain'));
            $ck($theme->renderBlock($mk($base + ['slider' => false], 'qahsplain')) === $plain, 'Schalter aus: Ausgabe unverändert');
            $ck($theme->renderBlock($mk($base + ['slider' => true], 'qahsplain')) === $plain, 'Schalter an ohne Folien: Ausgabe unverändert');
            $ck($theme->renderBlock($mk($base + ['slides' => [['title' => 'Zweite Folie']]], 'qahsplain')) === $plain, 'Folien ohne Schalter: Ausgabe unverändert');
            $ck(!str_contains($plain, 'data-hero-slider') && !in_array('@heroslider', $theme->marks(), true), 'Ohne Slider kein Merkmal');
            Hero::resetOnce();
            $vt = Hero::videoToggle('x');
            $ck(str_contains($vt, 'class="x hx-toggle hx-vt hx-vt--icon is-paused" data-hero-video-toggle hidden title="') && str_contains($vt, 'hero.mjs'), 'Video-Schalter unverändert (gemeinsames Muster)');

            // 3. Markup mit zwei eigenen Folien
            $data = $base + ['slider' => true, 'slides' => [
                ['title' => 'Zweite Folie', 'text' => 'Text zwei', 'image' => $img2 ?: null, 'button_label' => 'Los', 'button_link' => '/kontakt'],
                ['title' => 'Dritte Folie'],
                ['title' => ''],   // ohne Titel: übergangen
            ]];
            $html = $theme->renderBlock($mk($data, 'qahsmark'));
            $ck(substr_count($html, '<h1') === 1 && substr_count($html, '<h2') >= 2, 'Genau eine H1 (erste Folie), übrige h2');
            $ck(str_contains($html, 'role="region" aria-roledescription="' . lt('Karussell') . '"') && substr_count($html, 'aria-roledescription="' . lt('Folie') . '"') === 3, 'Rollen: Karussell + 3 Folien');
            $ck(str_contains($html, 'aria-label="' . e(lt('{n} von {total}', ['n' => 1, 'total' => 3])) . '"') && str_contains($html, 'aria-label="' . e(lt('{n} von {total}', ['n' => 3, 'total' => 3])) . '"'), 'Folien mit „x von n“');
            preg_match_all('~<div class="hs__slide[^"]*"[^>]*>~', $html, $m);
            $ck(count($m[0]) === 3 && !str_contains($m[0][0], ' hidden') && str_contains($m[0][1], ' hidden') && str_contains($m[0][2], ' hidden'), 'Ohne Skript: erste Folie sichtbar, übrige hidden');
            $ck(str_contains($html, '<div class="hs__ctrl" hidden>') && str_contains($html, 'aria-live="off"') && str_contains($html, 'data-interval="7000"'), 'Steuerung erst mit Skript, Live-Region aus, Dauer');
            $ck(strpos($html, 'hs__ctrl') < strpos($html, 'hs__track') && strpos($html, 'data-hs-toggle') < strpos($html, 'data-hs-prev'), 'Pause-Knopf zuerst, Steuerung vor den Folien');
            preg_match_all('~\sid="([^"]+)"~', $html, $ids);
            $ck(count($ids[1]) === count(array_unique($ids[1])), 'IDs eindeutig');
            $ck(str_contains($html, 'Zweite Folie') && str_contains($html, 'Text zwei') && substr_count($html, 'Eigener Text') === 1, 'Folie 2 mit eigenem Inhalt, Text der ersten nicht wiederholt');
            $later = substr($html, (int) strpos($html, 'id="b-qahsmark-hs-2"'));
            $ck(!str_contains($later, 'fetchpriority="high"'), 'Bilder weiterer Folien laden verzögert');
            $ck(in_array('@heroslider', $theme->marks(), true) && str_contains(implode(' ', $theme->conditionalCss(['hero'])), 'hero-slider.css')
                && str_contains(implode(' ', $theme->conditionalJs(['hero'])), 'hero-slider.js'), 'Stylesheet und Skript nur mit Slider');
            $ck(str_contains($html, 'data-hs-go="2"') && str_contains($html, 'aria-current="true"'), 'Punkte für jede Folie, aktuelle markiert');
            $noAuto = $theme->renderBlock($mk(['slides_interval' => '0', 'slides_nav' => 'numbers', 'slides_arrows' => false] + $data));
            $ck(!str_contains($noAuto, 'data-hs-toggle') && !str_contains($noAuto, 'data-hs-prev') && str_contains($noAuto, '>02<'), 'Ohne Auto-Wechsel kein Pause-Knopf; Nummern; ohne Pfeile');
            $ck(HeroSlider::demote('<h1 id="a" class="h1">T</h1><h10>') === '<h2 id="a" class="h1">T</h2><h10>', 'Herabstufen nur von h1');

            // 4. Bearbeiten-Modus: erste Folie wie bisher + Hinweis
            app()->editing = true;
            $ed = $theme->renderBlock($mk($data, 'qahsmark'));
            app()->editing = false;
            $ck(!str_contains($ed, 'data-hero-slider') && str_contains($ed, 'hs-editnote') && str_contains($ed, 'Willkommen in Musterstadt'), 'Bearbeiten: erste Folie mit Hinweis');

            // 5. Folien aus hervorgehobenen Einträgen
            $h = 'qa_hs_' . bin2hex(random_bytes(3));
            $pd = DataController::presetDef('blog');
            $pd['handle'] = $h;
            $pd['name'] = 'QA Folien';
            $pd['settings']['route'] = str_replace('_', '-', $h);
            $pd['settings']['detail_page_id'] = '987654321';
            $pd['settings']['featured'] = '1';
            [$clean, $err] = Tables::validate($pd);
            $ck($err === [], 'Tabelle gültig');
            if ($err) return ['ok' => $ok, 'fails' => $fails];
            Tables::create($clean);
            Tables::flush();
            $tables[] = $h;
            $t = Tables::find($h);
            $kat = (string) array_key_first((array) (Tables::field($t, 'kategorie')['options'] ?? []));
            $katLabel = $kat !== '' ? Tables::optionLabel(Tables::field($t, 'kategorie'), $kat) : '';
            [$e1] = Entries::save($t, null, ['titel' => 'Sommerfest', 'kategorie' => $kat, 'teaser' => 'Alle sind eingeladen.', 'bild' => $img ?: null, Featured::COL => '1', 'status' => 'published']);
            [$e2] = Entries::save($t, null, ['titel' => 'Nicht hervorgehoben', Featured::COL => '', 'status' => 'published']);
            $ent = Entries::find($t, (int) $e1);
            $href = (string) Entries::href($t, (array) $ent);
            $es = $theme->renderBlock($mk($base + ['slider' => true, 'slides_sources' => [['table' => $h, 'limit' => '3']], 'slides_button' => 'Ansehen']));
            $ck(str_contains($es, 'Sommerfest') && !str_contains($es, 'Nicht hervorgehoben') && substr_count($es, 'class="hs__slide') === 2, 'Folie aus hervorgehobenem Eintrag');
            $ck(str_contains($es, 'Alle sind eingeladen.') && ($katLabel === '' || str_contains($es, e($katLabel))), 'Text und Dachzeile wie beim Detail-Hero (Beschreibung, Kategorie)');
            $ck($href !== '' && str_contains($es, e($href)) && str_contains($es, 'Ansehen'), 'Button zur Detailseite');
            $badge = $theme->renderBlock($mk($base + ['slider' => true, 'slides_sources' => [['table' => $h, 'badge' => 'Aus dem Verein']]]));
            $ck(str_contains($badge, 'Aus dem Verein'), 'Bereich als Dachzeile, wenn eingetragen');
            $only = $theme->renderBlock($mk($base + ['slider' => true, 'slides_lead' => 'none', 'slides' => [['title' => 'Eigene']], 'slides_sources' => [['table' => $h]], 'slides_mix' => 'entries']));
            $ck(preg_match('~<h1[^>]*>.*?Sommerfest~s', $only) === 1 && !str_contains($only, 'Willkommen in Musterstadt') && substr_count($only, '<h1') === 1, 'Nur Folien: erste Eintrags-Folie trägt die H1');
            app()->entry = ['table' => $t, 'entry' => (array) $ent];
            $self = $theme->renderBlock($mk($base + ['slider' => true, 'slides_sources' => [['table' => $h]]]));
            app()->entry = null;
            $ck(!str_contains($self, 'data-hero-slider'), 'Detailseite: der aufgerufene Eintrag wird keine Folie');
            unset($e2);
        } finally {
            app()->editing = $prevEditing;
            app()->entry = $prevEntry;
            foreach ($tables as $x) if ($tt = Tables::find($x)) Tables::delete($tt);
            Tables::flush();
            Featured::reset();
            Hero::resetOnce();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
