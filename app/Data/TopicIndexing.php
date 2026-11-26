<?php
declare(strict_types=1);

namespace Core\Data;

use Core\Pages;

/**
 * Kein doppelter Inhalt durch automatische Seiten (Themenseiten /{liste}/{thema}, Übersicht /{liste}, Seiten je Schlagwort):
 * Zeigt eine veröffentlichte, indexierbare Seite dieselben Einträge schon (Block „Datenliste“ mit Filter auf das Thema bzw.
 * Schlagwort, bei der Übersicht: Datenliste der Liste selbst), bleibt die automatische Seite erreichbar, aber noindex und aus der
 * Sitemap – die Seite der Redaktion ist dann die eine Adresse für Suchmaschinen. Ebenso noindex: Themen- und Schlagwortseiten
 * mit weniger als zwei Einträgen (ohne eigene Beschreibung), Schlagworte mit dem Namen eines Themas (die Themenseite gilt) und
 * Schlagworte mit genau denselben Einträgen wie ein anderes (Canonical auf das meistgenutzte bzw. alphabetisch erste).
 * Filteransichten (?bereich=…) haben ohnehin den Canonical der Seite ohne Parameter (Core\Seo::forPage).
 */
final class TopicIndexing
{
    /** @var array<string, mixed> */
    private static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * Datenlisten auf veröffentlichten, indexierbaren Seiten (ohne Detailvorlagen): [table, field, op, value, page] je Block.
     * @return list<array{table: string, field: string, op: string, value: string, page: array}>
     */
    public static function lists(): array
    {
        if (isset(self::$memo['lists'])) return self::$memo['lists'];
        $out = [];
        $walk = function (array $blocks, array $p) use (&$walk, &$out): void {
            foreach ($blocks as $b) {
                if (!is_array($b)) continue;
                if (($b['type'] ?? '') === 'data_list' && is_array($b['data'] ?? null)) {
                    $d = $b['data'];
                    $h = (string) ($d['table'] ?? '');
                    $ff = (string) ($d['filter_field'] ?? '');
                    $out[] = ['table' => $h, 'field' => str_starts_with($ff, $h . '.') ? substr($ff, strlen($h) + 1) : '',
                        'op' => (string) ($d['filter_op'] ?? '='), 'value' => trim((string) ($d['filter_value'] ?? '')), 'page' => $p];
                }
                foreach ((array) ($b['data']['columns'] ?? []) as $col) if (is_array($col['blocks'] ?? null)) $walk($col['blocks'], $p);
                if (is_array($b['data']['blocks'] ?? null)) $walk($b['data']['blocks'], $p);
            }
        };
        foreach (Pages::published() as $p) {
            if (!empty($p['noindex']) || !empty($p['template_for']) || \Core\PageAccess::restricted($p)) continue;
            $walk(Pages::blocks($p), $p);
        }
        return self::$memo['lists'] = $out;
    }

    /** Seite, die die Einträge mit $value im Feld $field der Tabelle $handle schon zeigt (Filter =/enthält auf genau diesen Wert) */
    private static function pageShowing(string $handle, string $field, string $value, bool $fold = false): ?array
    {
        $want = $fold ? Tags::fold($value) : $value;
        foreach (self::lists() as $l) {
            if ($l['table'] !== $handle || $l['field'] !== $field || !in_array($l['op'], ['=', 'contains'], true) || $l['value'] === '') continue;
            if (($fold ? Tags::fold($l['value']) : $l['value']) === $want) return $l['page'];
        }
        return null;
    }

    /**
     * Themenseite (Detailseite eines Listen-Eintrags): null = indexieren, sonst Grund für noindex.
     * $list = Listen-Tabelle, $entry = Thema
     */
    public static function topicReason(array $list, array $entry): ?string
    {
        if (!OptionSource::isList($list)) return null;
        $slug = (string) ($entry['slug'] ?? '');
        if ($slug === '') return null;
        foreach (OptionSource::usedBy($list) as [$t, $f]) {
            if ($p = self::pageShowing((string) $t['handle'], (string) $f['name'], $slug)) return 'page:' . $p['id'];
        }
        $n = self::$memo['counts:' . $list['handle']] ??= OptionSource::publishedCounts($list);
        $desc = (string) ($list['settings']['description_field'] ?? '');
        if (($n[$slug] ?? 0) < 2 && ($desc === '' || trim(strip_tags((string) ($entry[$desc] ?? ''))) === '')) return 'thin';
        return null;
    }

    /** Übersicht /{liste}: noindex, wenn eine Seite die Liste schon als Datenliste zeigt */
    public static function listIndexReason(array $list): ?string
    {
        foreach (self::lists() as $l) if ($l['table'] === $list['handle'] && $l['field'] === '') return 'page:' . $l['page']['id'];
        return null;
    }

    /**
     * Seite eines Schlagworts: ['noindex' => bool, 'canonical' => ?string Schlagwort, auf das verwiesen wird, 'reason' => ?string]
     * @return array{noindex: bool, canonical: ?string, reason: ?string}
     */
    public static function tag(string $tag): array
    {
        $no = fn(string $why) => ['noindex' => true, 'canonical' => null, 'reason' => $why];
        $counts = Tags::publicCounts();
        $label = Tags::resolve($tag);
        if ($label === null) return $no('missing');
        if (($counts[$label] ?? 0) < 2) return $no('thin');
        $k = Tags::fold($label);
        if (isset(self::topicNames()[$k])) return $no('topic');
        foreach (Tags::members() as $fields) {
            foreach ($fields as $m) if (self::pageShowing((string) $m['handle'], (string) $m['field'], $label, true)) return $no('page');
        }
        // dieselben Einträge wie ein anderes Schlagwort (Synonyme, gleich oft genutzt) → Canonical auf das alphabetisch erste
        $sets = self::tagSets();
        $mine = $sets[$k] ?? '';
        foreach (array_keys($counts) as $other) {   // alphabetisch (publicCounts)
            $other = (string) $other;
            $ok = Tags::fold($other);
            if ($ok === $k) break;   // selbst das erste mit diesem Inhalt
            if (($sets[$ok] ?? null) === $mine && !isset(self::topicNames()[$ok])) return ['noindex' => false, 'canonical' => $other, 'reason' => 'same'];
        }
        return ['noindex' => false, 'canonical' => null, 'reason' => null];
    }

    /** Für die Sitemap: Schlagwortseite indexierbar und ihre eigene kanonische Adresse? */
    public static function tagIndexable(string $tag): bool
    {
        $s = self::tag($tag);
        return !$s['noindex'] && $s['canonical'] === null;
    }

    /** Vergleichsformen der Titel und Kurznamen aller Themen mit Themenseite */
    private static function topicNames(): array
    {
        if (isset(self::$memo['topics'])) return self::$memo['topics'];
        $out = [];
        foreach (Tables::content() as $t) {
            if (trim((string) ($t['settings']['route'] ?? ''), '/') === '' || empty($t['settings']['detail_page_id']) || !OptionSource::isList($t)) continue;
            foreach (Entries::query($t, ['status' => 'published', 'limit' => 2000]) as $e) {
                foreach ([Entries::title($t, $e), (string) ($e['slug'] ?? '')] as $s) if (($f = Tags::fold($s)) !== '') $out[$f] = true;
            }
        }
        return self::$memo['topics'] = $out;
    }

    /**
     * Einträge je Schlagwort als Fingerabdruck (Vergleichsform => sortierte Liste tabelle:id), in der Reihenfolge der
     * Häufigkeit – gleiche Fingerabdrücke = gleicher Inhalt der Schlagwortseite.
     * @return array<string, string>
     */
    private static function tagSets(): array
    {
        if (isset(self::$memo['sets'])) return self::$memo['sets'];
        $ids = [];
        foreach (Tags::members() as $fields) {
            foreach ($fields as $m) {
                $t = Tables::findContent($m['handle']);
                if (!$t || empty($t['settings']['detail_page_id']) || trim((string) ($t['settings']['route'] ?? ''), '/') === '') continue;
                try {
                    $rows = \Core\Data\Tables::db($t)->fetchAll("SELECT id, {$m['field']} AS v FROM {$m['table']} WHERE status = 'published' AND {$m['field']} IS NOT NULL AND {$m['field']} != '[]'");
                } catch (\Throwable) {
                    continue;
                }
                foreach ($rows as $r) {
                    foreach ((array) (json_decode((string) $r['v'], true) ?: []) as $tag) {
                        if (($f = Tags::fold((string) $tag)) !== '') $ids[$f][] = $m['handle'] . ':' . $r['id'];
                    }
                }
            }
        }
        $out = [];
        foreach ($ids as $f => $l) {
            $l = array_unique($l);
            sort($l);
            $out[$f] = implode(',', $l);
        }
        return self::$memo['sets'] = $out;
    }
}
