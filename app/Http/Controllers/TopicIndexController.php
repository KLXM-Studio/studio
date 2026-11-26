<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Http\Controllers;

use Core\Data\Entries;
use Core\Data\Exposure;
use Core\Data\OptionSource;
use Core\Data\Tags;
use Core\Http\Request;
use Core\Http\Response;
use Core\Lang;
use Core\Pages;
use Core\Seo;

/**
 * Übersichten ohne Einrichtung:
 *  - /{Adresse einer Auswahlliste} (z. B. /thema): Block list_index – Listen-Einträge als Karten mit Anzahl zugeordneter Einträge.
 *  - /schlagwort: Block tag_index – alle Schlagworte mit veröffentlichten Einträgen A–Z.
 * Gerendert als Abschnitte wie jede Seite (Kopf und Abstände des Kits); Kits überschreiben blocks/list_index.php, tag_index.php, tag_page.php.
 * Eine Seite mit derselben Adresse hat jeweils Vorrang (SiteController::page bzw. Pages::byPath).
 */
final class TopicIndexController
{
    public function listIndex(Request $r, array $t): Response
    {
        return $this->page($r, [['id' => 'auto-list', 'type' => 'list_index', 'data' => ['table' => $t['handle']]]], (string) $t['name'], (string) ($t['description'] ?? ''),
            ['noindex' => \Core\Data\TopicIndexing::listIndexReason($t) !== null]);   // Seite zeigt die Liste schon → nur eine Adresse für Suchmaschinen
    }

    public function tagIndex(Request $r): Response
    {
        $site = new SiteController();
        if (Pages::byPath('schlagwort') || !Tags::publicCounts()) return $site->page($r, 'schlagwort');   // Seite gleichen Pfads bzw. keine Schlagworte
        return $this->page($r, [['id' => 'auto-tags', 'type' => 'tag_index', 'data' => []]], lt('Schlagworte'), lt('Alle Schlagworte von A bis Z'));
    }

    /**
     * Seite aus Blöcken rendern wie jede Seite (Abschnitte, Abstände, Hintergründe des Kits): $blocks = interne Blöcke list_index,
     * tag_index, tag_page. $o: noindex, canonical.
     */
    public function page(Request $r, array $blocks, string $title, string $desc, array $o = []): Response
    {
        $app = app();
        $site = new SiteController();
        if ($app->settings->get('sys.maintenance') && !$app->auth->check()) return $site->home($r);
        $theme = $app->theme;
        $pseudo = ['id' => 0, 'title' => $title, 'slug' => trim($r->path, '/'), 'is_home' => 0, 'meta_description' => $desc, 'noindex' => empty($o['noindex']) ? 0 : 1,
            'status' => 'published', 'lang' => Lang::current() === Lang::default() ? null : Lang::current(), 'type' => 'auto'];
        $app->currentPage = $pseudo;
        $content = $theme->renderBlocks($blocks);
        $seo = Seo::forError(200);
        $seo['title'] = $title . (($s = (string) site_name()) !== '' ? ' | ' . $s : '');
        $seo['description'] = $desc;
        $seo['canonical'] = (string) ($o['canonical'] ?? absolute_url('/' . trim($r->path, '/')));
        $seo['noindex'] = !empty($o['noindex']);
        $html = $theme->render('layout', ['page' => $pseudo, 'content' => $content, 'seo' => $seo, 'editor' => null, 'toolbar' => null,
            'extraCss' => [$theme->hasAsset('css/data.css') ? $theme->asset('css/data.css') : asset('css/data.css'), $theme->coreCss('highlights.css')], 'extraJs' => []]);
        return $site->respond(\Core\Icons::siteSprite($html), $app->auth->check(), 200);
    }
}
