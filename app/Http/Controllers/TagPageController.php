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
 * Gerendert über den internen Block tag_page wie jede Seite (Kopf und Abstände des Kits); Kit-Override blocks/tag_page.php.
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
        $one = array_sum(array_map(fn($g) => count($g['items']), $groups)) < 2;   // einzelne Treffer nicht indexieren
        $dup = \Core\Data\TopicIndexing::tag($label);   // kein doppelter Inhalt: Thema gleichen Namens, Seite mit Liste, Synonym
        return (new TopicIndexController())->page($r, [['id' => 'auto-tag', 'type' => 'tag_page', 'data' => ['tag' => $label]]], lt('Schlagwort: {tag}', ['tag' => $label]),
            lt('Alles zum Thema „{tag}“', ['tag' => $label]), ['noindex' => $one || $dup['noindex'], 'canonical' => absolute_url(Tags::url($dup['canonical'] ?? $label))]);
    }
}
