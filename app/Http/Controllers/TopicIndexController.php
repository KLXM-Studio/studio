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
 *  - /{Adresse einer Auswahlliste} (z. B. /thema): alle veröffentlichten Listen-Einträge in Tabellen-Reihenfolge als Karten mit Anzahl
 *    zugeordneter Einträge, Link zur Themenseite; leere nur für die Redaktion. Kit: templates/list-index.php, sonst app/Views/list-index.php.
 *  - /schlagwort: alle Schlagworte mit veröffentlichten Einträgen (alle Vokabulare) A–Z mit Anzahl. Kit: templates/tag-index.php.
 * Eine Seite mit derselben Adresse hat jeweils Vorrang (SiteController::page bzw. Pages::byPath).
 */
final class TopicIndexController
{
    public function listIndex(Request $r, array $t): Response
    {
        $counts = OptionSource::publishedCounts($t);
        $editor = app()->auth->check();
        $items = [];
        foreach (Exposure::publicRows($t, Entries::query($t, ['status' => 'published', 'limit' => 500] + (Lang::multi() ? ['lang' => Lang::current()] : []))) as $e) {
            $n = (int) ($counts[(string) ($e['slug'] ?? '')] ?? 0);
            if ($n === 0 && !$editor) continue;
            $href = Entries::href($t, $e);
            if ($href) $items[] = ['entry' => $e, 'href' => $href, 'count' => $n];
        }
        return $this->page($r, 'list-index', ['table' => $t, 'items' => $items], (string) $t['name'], (string) ($t['description'] ?? ''));
    }

    public function tagIndex(Request $r): Response
    {
        $site = new SiteController();
        if (Pages::byPath('schlagwort')) return $site->page($r, 'schlagwort');
        $tags = Tags::publicCounts();
        if (!$tags) return $site->page($r, 'schlagwort');   // keine Schlagworte: 404 der Website
        $groups = [];
        foreach ($tags as $tag => $n) {
            $first = mb_strtoupper(mb_substr(strtr((string) $tag, ['Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u']), 0, 1));
            $groups[preg_match('~^[A-Z]$~', $first) ? $first : '#'][] = ['tag' => (string) $tag, 'count' => $n, 'href' => Tags::url((string) $tag)];
        }
        ksort($groups);
        return $this->page($r, 'tag-index', ['groups' => $groups, 'max' => max($tags)], lt('Schlagworte'), lt('Alle Schlagworte von A bis Z'));
    }

    private function page(Request $r, string $view, array $vars, string $title, string $desc): Response
    {
        $app = app();
        $site = new SiteController();
        if ($app->settings->get('sys.maintenance') && !$app->auth->check()) return $site->home($r);
        $theme = $app->theme;
        $content = $theme->file('templates/' . $view . '.php') !== null ? $theme->render($view, $vars) : \Core\Theme::capture(ROOT . '/app/Views/' . $view . '.php', $vars);
        $pseudo = ['id' => 0, 'title' => $title, 'slug' => trim($r->path, '/'), 'is_home' => 0, 'meta_description' => $desc, 'noindex' => 0, 'status' => 'published',
            'lang' => Lang::current() === Lang::default() ? null : Lang::current(), 'type' => $view];
        $seo = Seo::forError(200);
        $seo['title'] = $title . (($s = (string) site_name()) !== '' ? ' | ' . $s : '');
        $seo['description'] = $desc;
        $seo['canonical'] = absolute_url('/' . trim($r->path, '/'));
        $seo['noindex'] = false;
        $html = $theme->render('layout', ['page' => $pseudo, 'content' => $content, 'seo' => $seo, 'editor' => null, 'toolbar' => null,
            'extraCss' => [$theme->hasAsset('css/data.css') ? $theme->asset('css/data.css') : asset('css/data.css'), $theme->coreCss('highlights.css')], 'extraJs' => []]);
        return $site->respond(\Core\Icons::siteSprite($html), $app->auth->check(), 200);
    }
}
