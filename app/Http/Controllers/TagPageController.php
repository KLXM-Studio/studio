<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Http\Controllers;

use Core\Data\Tags;
use Core\Http\Request;
use Core\Http\Response;
use Core\Lang;
use Core\Pages;
use Core\Seo;

/**
 * Seite je Schlagwort (Core\Data\Tags): /schlagwort/{schlagwort} – alle veröffentlichten Einträge mit diesem Schlagwort aus allen Tabellen
 * mit freien Schlagworten (über Vokabulare hinweg), je Tabelle gruppiert, ohne Einrichtung. Eine Seite mit derselben Adresse hat Vorrang.
 * Ansicht: Kit templates/tag.php, sonst app/Views/tag.php (Karten wie Datenliste/Highlights – jedes Kit gestaltet sie).
 */
final class TagPageController
{
    public function show(Request $r, string $tag): Response
    {
        $site = new SiteController();
        $path = trim($r->path, '/');
        if (Pages::byPath($path) || !Tags::vocabularies()) return $site->page($r, $path);
        $app = app();
        if ($app->settings->get('sys.maintenance') && !$app->auth->check()) return $site->home($r);
        $tag = Tags::norm(rawurldecode($tag));
        $groups = $tag !== '' ? Tags::entries($tag) : [];
        if (!$groups) return $site->page($r, $path);   // unbekanntes Schlagwort: 404 der Website
        $label = $groups[0]['tag'];
        $theme = $app->theme;
        $vars = ['tag' => $label, 'groups' => $groups];
        $content = $theme->file('templates/tag.php') !== null ? $theme->render('tag', $vars) : \Core\Theme::capture(ROOT . '/app/Views/tag.php', $vars);
        $title = lt('Schlagwort: {tag}', ['tag' => $label]);
        $pseudo = ['id' => 0, 'title' => $title, 'slug' => 'schlagwort', 'is_home' => 0, 'meta_description' => '', 'noindex' => 0, 'status' => 'published',
            'lang' => Lang::current() === Lang::default() ? null : Lang::current(), 'type' => 'tag'];
        $seo = Seo::forError(200);
        $seo['title'] = $title . (($s = (string) site_name()) !== '' ? ' | ' . $s : '');
        $seo['description'] = lt('Alles zum Thema „{tag}“', ['tag' => $label]);
        $seo['canonical'] = absolute_url(Tags::url($label));
        if (array_sum(array_map(fn($g) => count($g['items']), $groups)) < 2) $seo['noindex'] = true;   // einzelne Treffer nicht indexieren
        $html = $theme->render('layout', ['page' => $pseudo, 'content' => $content, 'seo' => $seo, 'editor' => null, 'toolbar' => null,
            'extraCss' => [$theme->hasAsset('css/data.css') ? $theme->asset('css/data.css') : asset('css/data.css'), $theme->coreCss('highlights.css')], 'extraJs' => []]);
        return $site->respond(\Core\Icons::siteSprite($html), $app->auth->check(), 200);
    }
}
