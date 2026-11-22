<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Glossary\Glossary;
use Core\Pages;
use Core\StructuredData;

/**
 * Strukturierte Daten des Wissensbereichs (schema.org, von Core\StructuredData::graph() aufgerufen):
 *
 *  - Sammlung: {route}#collection (Collection, name = Tabelle, url = Übersicht). Auf der Übersicht: WebPage → CollectionPage mit
 *    mainEntity = Sammlung, hasPart = Kapitel in Lesereihenfolge (Verweis-Kapitel mit der Adresse der Zielseite) – die ItemList der
 *    Datenliste entfällt dort (StructuredData::itemList).
 *  - Kapitel (Article, @id = Adresse#main): isPartOf = Sammlung und Oberkapitel, position (oberste Ebene), alternateName = Kapitelnummer,
 *    keywords = Schlagworte, articleSection = Bereich, about = Glossarbegriffe (DefinedTerm mit derselben @id wie die Glossar-Detailseite,
 *    inDefinedTermSet = Glossar#set), author = Person aus „Autor/in“ (sonst Organisation), hasPart = Abschnitte (WebPageElement, #anker),
 *    geteilt: sourceOrganization/publisher = Herkunft. WebPage: lastReviewed = „Zuletzt geprüft“, reviewedBy = Organisation,
 *    relatedLink = verwandte Kapitel.
 *  - Zielseite eines Verweis-Kapitels: isPartOf auch auf die Sammlung.
 * Verweise auf Knoten anderer Seiten tragen @type, name und url (auflösbar ohne den Graphen der anderen Seite).
 */
final class KnowledgeLd
{
    public static function collectionId(array $t): ?string
    {
        $u = Knowledge::overviewUrl($t);
        return $u !== null ? abs_url($u) . '#collection' : null;
    }

    public static function collection(array $t, bool $parts = false): ?array
    {
        $id = self::collectionId($t);
        if ($id === null) return null;
        $n = ['@type' => 'Collection', '@id' => $id, 'name' => (string) $t['name'], 'url' => abs_url((string) Knowledge::overviewUrl($t))];
        if ($parts) {
            $tree = Knowledge::tree($t);
            $items = [];
            foreach ($tree['order'] as $cid) {
                $node = $tree['nodes'][$cid];
                if (!$node['href']) continue;
                $abs = abs_url((string) $node['href']);
                $items[] = $node['ref'] ? ['@type' => 'WebPage', '@id' => $abs . '#webpage', 'url' => $abs, 'name' => $node['title']]
                    : ['@type' => 'Article', '@id' => $abs . '#main', 'url' => $abs, 'name' => $node['title']];
            }
            if ($items) $n['hasPart'] = $items;
        }
        return $n;
    }

    /** Organisation der Website als Verweis ({@id}) bzw. null */
    private static function org(): ?array
    {
        $o = StructuredData::organization();
        return $o ? ['@id' => $o['@id']] : null;
    }

    /** Kapitel: Hauptknoten $main und WebPage $webpage ergänzen, Zusatzknoten an $graph anhängen */
    public static function entry(array $t, array $e, string $url, array &$main, array &$webpage, array &$graph): void
    {
        $tree = Knowledge::tree($t);
        $id = (int) $e['id'];
        $node = $tree['nodes'][$id] ?? null;
        $main['@type'] = 'Article';
        $coll = self::collection($t);
        if ($coll) $graph[] = $coll;
        $parts = $coll ? [['@id' => $coll['@id']]] : [];
        foreach ($node ? Knowledge::ancestors($tree, $id) : [] as $a) {
            if ($a['href']) { $abs = abs_url((string) $a['href']); $parts[] = ['@type' => 'Article', '@id' => $abs . '#main', 'url' => $abs, 'name' => $a['title']]; }
        }
        if ($parts) $main['isPartOf'] = count($parts) === 1 ? $parts[0] : $parts;
        if ($node) {
            if (Knowledge::config($t)['numbers']) $main['alternateName'] = $node['number'];
            if ($node['depth'] === 0) {
                $tops = array_values(array_filter($tree['order'], fn($x) => $tree['nodes'][$x]['depth'] === 0));
                $main['position'] = (int) array_search($id, $tops, true) + 1;
            }
        }
        $tf = Tables::field($t, 'schlagworte');
        $kw = $tf ? array_values(array_filter(array_map(fn($k) => Tables::optionLabel($tf, (string) $k), (array) ($e['schlagworte'] ?? [])))) : [];
        if ($kw) $main['keywords'] = implode(', ', $kw); else unset($main['keywords']);
        $bf = Tables::field($t, 'bereich');
        if ($bf && (string) ($e['bereich'] ?? '') !== '') $main['articleSection'] = Tables::optionLabel($bf, (string) $e['bereich']);
        // Glossarbegriffe: dieselbe @id wie auf der Glossar-Detailseite
        $terms = [];
        $setId = Glossary::setLdId();
        foreach (Knowledge::terms($t, $e) as $x) {
            if (!$x['url']) continue;
            $terms[] = array_filter(['@type' => 'DefinedTerm', '@id' => Glossary::termLdId($x['url']), 'name' => $x['term'], 'url' => abs_url($x['url']),
                'inDefinedTermSet' => $setId ? ['@type' => 'DefinedTermSet', '@id' => $setId, 'name' => lt('Glossar'), 'url' => substr($setId, 0, -4)] : null]);
        }
        if ($terms) $main['about'] = $terms;
        $org = self::org();
        $author = trim((string) ($e['autor'] ?? ''));
        $main['author'] = $author !== '' ? ['@type' => 'Person', 'name' => $author] : ($main['author'] ?? $org);
        if (!$main['author']) unset($main['author']);
        // Abschnitte (h2) aus der Ansicht (Knowledge::frame)
        $secs = [];
        foreach (Knowledge::$sections as $s) $secs[] = ['@type' => 'WebPageElement', '@id' => $url . '#' . $s['id'], 'name' => $s['label'], 'url' => $url . '#' . $s['id']];
        if ($secs) $main['hasPart'] = $secs;
        // Geteilte Einträge: Herkunft
        if (Tables::isShared($t) && Shared::isForeign($t, $e) && ($origin = (string) ($e['origin_site'] ?? '')) !== '') {
            $info = Shared::siteInfo($origin, $t['shared']['key'] ?? null);
            $src = array_filter(['@type' => 'Organization', 'name' => (string) ($info['name'] ?? ''), 'url' => (string) ($info['url'] ?? '')]);
            if (!empty($src['name'])) { $main['sourceOrganization'] = $src; $main['publisher'] = $src; }
        }
        // WebPage: geprüft, verwandte Kapitel
        if (preg_match('~^\d{4}-\d{2}-\d{2}~', (string) ($e['geprueft_am'] ?? ''))) {
            $webpage['lastReviewed'] = substr((string) $e['geprueft_am'], 0, 10);
            if ($org) $webpage['reviewedBy'] = $org;
        }
        $rel = array_values(array_filter(array_map(fn($n) => $n['href'] ? abs_url((string) $n['href']) : null, Knowledge::related($t, $e, $tree))));
        if ($rel) $webpage['relatedLink'] = $rel;
    }

    /** Seite: Übersicht eines Wissensbereichs → CollectionPage; Zielseite eines Verweis-Kapitels → isPartOf Sammlung */
    public static function page(array $page, array &$webpage, array &$graph): void
    {
        if (empty($page['id']) || ($page['type'] ?? 'page') !== 'page') return;
        $path = trim((string) ($page['path'] ?? ''), '/');
        foreach (Tables::content() as $t) {
            if (!Knowledge::is($t) || empty($t['settings']['detail_page_id'])) continue;
            if ($path !== '' && trim((string) $t['settings']['route'], '/') === $path && ($coll = self::collection($t, true))) {
                $webpage['@type'] = 'CollectionPage';
                $webpage['mainEntity'] = ['@id' => $coll['@id']];
                $graph[] = $coll;
                continue;
            }
            // Verweis-Kapitel auf diese Seite
            if (!Tables::field($t, 'verweis')) continue;
            try {
                $n = Entries::count($t, ['where' => [['verweis', '=', 'page:' . (int) $page['id']]]]);
            } catch (\Throwable) {
                $n = 0;
            }
            if ($n && ($coll = self::collection($t))) {
                $parts = isset($webpage['isPartOf']) ? (isset($webpage['isPartOf']['@id']) ? [$webpage['isPartOf']] : (array) $webpage['isPartOf']) : [];
                $parts[] = ['@id' => $coll['@id']];
                $webpage['isPartOf'] = $parts;
                $graph[] = $coll;
            }
        }
    }

    /** Liegt die aufgerufene Seite auf der Übersicht dieser Wissenstabelle? (ItemList dort entfallen – Sammlung trägt die Kapitel) */
    public static function onOverview(array $t): bool
    {
        $p = app()->currentPage;
        return Knowledge::is($t) && is_array($p) && !app()->entry && trim((string) ($p['path'] ?? ''), '/') === trim((string) ($t['settings']['route'] ?? ''), '/') && self::collectionId($t) !== null;
    }

    /**
     * Prüfung eines Graphen (Selbsttest): gültiges JSON, keine doppelten @id unter den Knoten, jeder reine Verweis ({@id}) zeigt auf
     * einen Knoten im Graphen. @return list<string> Fehler
     */
    public static function check(array $graph): array
    {
        $errors = [];
        if (json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === false) $errors[] = 'kein gültiges JSON';
        $defined = [];
        $refs = [];
        $walk = function (mixed $v, bool $top) use (&$walk, &$defined, &$refs, &$errors): void {
            if (!is_array($v)) return;
            if (isset($v['@id']) && is_string($v['@id'])) {
                if (count($v) === 1) $refs[] = $v['@id'];
                elseif ($top) {
                    if (isset($defined[$v['@id']])) $errors[] = 'doppelt: ' . $v['@id'];
                    $defined[$v['@id']] = true;
                } else {
                    $defined[$v['@id']] ??= true;
                }
            }
            foreach ($v as $k => $x) if ($k !== '@id') $walk($x, false);
        };
        foreach ((array) ($graph['@graph'] ?? []) as $n) $walk($n, true);
        foreach (array_unique($refs) as $r) if (!isset($defined[$r])) $errors[] = 'nicht auflösbar: ' . $r;
        return $errors;
    }
}
