<?php
declare(strict_types=1);

namespace Core\Search;

use Core\Data\Calendar;
use Core\Data\Entries;
use Core\Data\EntryBlocks;
use Core\Data\Exposure;
use Core\Data\Shared;
use Core\Data\Tables;
use Core\Lang;
use Core\Media;
use Core\Pages;

/**
 * Quellen des Suchindex einer Website in einer Sprache:
 *   Seiten      veröffentlicht, nicht „noindex“, Typ page; Titel, Beschreibung, sichtbare Blocktexte (Felder text,
 *               textarea, richtext, inline, repeater; Überschriften gewichtet)
 *   Einträge    Tabellen mit Detailseite (URL-Basis + Vorlage), veröffentlicht, wie auf der Website eingestellt
 *               (geteilte Tabellen: eigene + gewählte fremde, mit Herkunft „_origin“)
 *   Dokumente   PDFs der Mediathek (Titel, Alt-Text, Dateiname) – nur wenn eingeschaltet
 *
 * Dokument: id, type (page|entry|file), table, badge, title, headings (hoch), keywords (Synonyme), text (normal), extra (niedrig),
 *           summary, image (Medien-ID), facets (JSON), url, date, date_label, origin,
 *           anchors (JSON [{id, label}] – Abschnitte mit Sprungmarke; Search::query hängt #id an, wenn eine Überschrift passt)
 * Einträge folgen den Such-Einstellungen der Tabelle (Core\Search\TableSearch). Felder „Inhaltsblöcke“ (blocks) liefern ihren
 * Text über dieselbe Extraktion wie Seiten (blocksText); Datenlisten darin werden nicht rekursiv indexiert (SKIP_BLOCKS).
 */
final class Documents
{
    /** Blöcke, deren Inhalt dynamisch ist oder anderswo indexiert wird */
    private const SKIP_BLOCKS = ['data_list', 'data_fields', 'data_form', 'calendar', 'upcoming', 'event_actions', 'map', 'gallery', 'slideshow', 'stack_cards'];
    private const HEADING = '~^(title|titel|heading|headline|ueberschrift|title_strong|title_light|subtitle|untertitel|q|frage|question)$~';
    private const TEXT_TYPES = ['text', 'textarea', 'richtext', 'inline'];

    /** Dateien, die auf aufgenommenen Seiten bzw. in aufgenommenen Einträgen verwendet werden (beim Aufbau gesammelt) – nur sie sind als Dokument suchbar */
    private static array $used = [];

    /** @return array<string, array> id => Dokument */
    public static function build(string $lang): array
    {
        self::$used = [];
        $prev = app()->lang;
        $notes = \Core\EditorNotes::$show;
        \Core\EditorNotes::$show = false;   // Redaktionsnotizen [# … #] nie in den Index (auch beim Neuaufbau aus der Verwaltung)
        app()->lang = $lang === Lang::default() ? null : $lang;
        try {
            $docs = [];
            foreach (self::pages($lang) as $d) $docs[$d['id']] = $d;
            foreach (self::entries($lang) as $d) $docs[$d['id']] = $d;
            if (Search::settings()['media']) {
                foreach (self::files() as $d) $docs[$d['id']] = $d;
            }
            // Erweiterungen (Extension::filter 'search.document'): Dokument ändern oder mit false aus der Suche nehmen
            if (\Core\Extensions::filters('search.document')) {
                foreach ($docs as $k => $d) {
                    $ctx = ['type' => (string) ($d['type'] ?? ''), 'lang' => $lang];
                    if (preg_match('~^p-(\d+)$~', (string) $k, $m)) $ctx['page_id'] = (int) $m[1];
                    elseif (preg_match('~^e-(.+)-(\d+)$~', (string) $k, $m)) $ctx += ['table' => $m[1], 'entry_id' => (int) $m[2]];
                    $out = \Core\Extensions::filter('search.document', $d, $ctx);
                    if (!is_array($out)) unset($docs[$k]);
                    else $docs[$k] = ['id' => $d['id']] + $out;
                }
            }
            $syn = Search::synonyms($lang);
            foreach ($docs as &$d) {
                $d['keywords'] = $syn ? self::expand($d, $syn) : '';
                $d['hash'] = md5(json_encode($d, JSON_UNESCAPED_UNICODE));
            }
            return $docs;
        } finally {
            app()->lang = $prev;
            \Core\EditorNotes::$show = $notes;
        }
    }

    /** Dokument mit allen Feldern als Text – Redaktionsnotizen [# … #] und *Betonung*-Sternchen entfernt (Titel, Überschriften, Text, Auszug …) */
    private static function doc(array $d): array
    {
        // *Betonung*-Sternchen nie in Titel, Treffertext oder Auszug (strip_emphasis; Adresse/Bild bleiben unverändert)
        return array_map(fn($v) => strip_emphasis(\Core\EditorNotes::strip((string) $v)), $d + ['type' => 'page', 'table' => '', 'badge' => '', 'title' => '', 'headings' => '', 'keywords' => '',
            'text' => '', 'extra' => '', 'summary' => '', 'image' => '', 'facets' => '', 'url' => '', 'date' => '', 'date_label' => '', 'origin' => '', 'anchors' => '']);
    }

    // ------------------------------------------------------------------ Seiten

    private static function pages(string $lang): \Generator
    {
        $rows = app()->db->fetchAll("SELECT * FROM pages WHERE status = 'published' AND type = 'page' AND noindex = 0 AND search_exclude = 0 AND " . Lang::sql(), [$lang]);
        foreach ($rows as $p) {
            if (\Core\PageAccess::restricted($p)) continue;   // geschützter Bereich (Erweiterung): nie im Suchindex
            $rb = Pages::readBlocks($p);   // Erweiterungen (z. B. gemeinsame Bausteine) aufgelöst
            foreach (Media::idsInBlocks($rb) as $mid) self::$used[$mid] = true;
            [$head, $text] = self::blocksText($rb, $p);
            // Sprungmarken der Abschnitte (Tune „Anker“) – dieselbe Liste wie die Linkauswahl (Pages::anchors)
            $anchors = [];
            foreach (Pages::anchors($p) as $aid => $label) $anchors[] = ['id' => (string) $aid, 'label' => Text::plain((string) $label)];
            yield self::doc([
                'id' => 'p-' . $p['id'], 'type' => 'page', 'badge' => lt('Seite'),
                'title' => $p['title'], 'headings' => implode(' · ', array_unique($head)),
                'text' => trim(trim((string) $p['meta_description']) . ' ' . implode(' ', $text)),
                'url' => Pages::url($p), 'image' => $p['og_image'] ? (string) (int) $p['og_image'] : '',
                'anchors' => self::anchorsJson($anchors),
            ]);
        }
    }

    /** @return array{0: list<string>, 1: list<string>} [Überschriften, Text] sichtbarer Blöcke */
    public static function blocksText(array $blocks, ?array $page = null): array
    {
        $theme = app()->theme;
        $head = $text = [];
        // Abschnitts-Option „Nicht in der Website-Suche“ (Tune noSearch): Abschnitt samt Spalten überspringen
        $blocks = array_filter($blocks, fn($b) => is_array($b) && empty($b['tunes']['section']['noSearch']));
        foreach (\Core\Layout::flatten($blocks) as $b) {   // auch Blöcke in Spalten eines Layouts
            $type = (string) ($b['type'] ?? '');
            if (in_array($type, self::SKIP_BLOCKS, true) || !(($b['tunes']['section']['visible'] ?? true))) continue;
            $def = $theme->block($type);
            if (!$def || !is_array($b['data'] ?? null)) continue;
            self::fieldsText((array) ($def['fields'] ?? []), $b['data'], $head, $text);
            // Blöcke mit zentralen Angaben (Kontakt, Sprechzeiten …): sichtbaren Text der Ausgabe übernehmen
            if (!empty($def['central']) && $page) {
                $rendered = self::rendered($b, $page);
                if ($rendered !== '') $text[] = $rendered;
            }
        }
        return [$head, $text];
    }

    /** Sichtbarer Text eines gerenderten Blocks (ohne Skripte, Styles, SVG, versteckte Hilfstexte) */
    private static function rendered(array $b, array $page): string
    {
        $app = app();
        $prev = [$app->currentPage, $app->editing];
        $app->currentPage = $page;
        $app->editing = false;
        try {
            $block = $app->theme->makeBlock($b);
            $html = $block ? $app->theme->renderBlock($block) : '';
        } catch (\Throwable $e) {
            error_log('[search] block ' . ($b['type'] ?? '?') . ': ' . $e->getMessage());
            $html = '';
        } finally {
            [$app->currentPage, $app->editing] = $prev;
        }
        $html = (string) preg_replace('~<(script|style|svg|template|noscript)\b.*?</\1>~is', ' ', $html);
        $html = (string) preg_replace('~<[^>]+\b(hidden|aria-hidden="true")[^>]*>.*?</[^>]+>~is', ' ', $html);
        return mb_substr(Text::plain($html), 0, 3000);
    }

    private static function fieldsText(array $fields, array $data, array &$head, array &$text, int $depth = 0): void
    {
        foreach ($fields as $f) {
            $name = (string) ($f['name'] ?? '');
            $v = $data[$name] ?? null;
            if ($name === '' || $v === null || $v === '' || $v === []) continue;
            $type = (string) ($f['type'] ?? 'text');
            if ($type === 'repeater' && is_array($v) && $depth < 3) {
                foreach ($v as $item) {
                    if (is_array($item)) self::fieldsText((array) ($f['fields'] ?? []), $item, $head, $text, $depth + 1);
                }
                continue;
            }
            if ($type === \Core\Hotspots::TYPE) {
                // Interaktives Bild: Titel und Text der Markierungen (Reihenfolge der Liste unter dem Bild)
                foreach (\Core\Hotspots::normalize($v)['items'] as $h) {
                    $x = trim(trim((string) ($h['title'] ?? '')) . ' ' . Text::plain((string) ($h['text'] ?? '')));
                    if ($x !== '' && !str_contains($x, '{{')) $text[] = $x;
                }
                continue;
            }
            if ($type === 'file' && is_numeric($v)) {
                // Video/Audio im Block: veröffentlichtes Transkript mitsuchen (Core\MediaTracks)
                if (($tr = self::transcript((int) $v)) !== '') $text[] = $tr;
                continue;
            }
            if (!in_array($type, self::TEXT_TYPES, true) || !is_string($v) || (str_ends_with($name, 'label') && empty($f['search'])) || str_contains($v, '{{')) continue;   // 'search' => true: sichtbare Bezeichnung trotz Name *label indexieren
            if (in_array($type, ['richtext', 'inline'], true)) {
                if (preg_match_all('~<h[1-6][^>]*>(.*?)</h[1-6]>~is', $v, $m)) {
                    foreach ($m[1] as $h) if (($h = Text::plain($h)) !== '') $head[] = $h;
                }
                $v = Text::plain($v);
            } else {
                $v = trim((string) preg_replace('~\s+~u', ' ', $v));
            }
            if ($v === '') continue;
            if (preg_match(self::HEADING, $name) && mb_strlen($v) <= 160) $head[] = $v; else $text[] = $v;
        }
    }

    // ------------------------------------------------------------------ Einträge

    /** Tabellen, deren Einträge gefunden werden: Suche der Tabelle an (TableSearch), mit Detailseite, nicht auf dieser Website ausgenommen */
    public static function tables(): array
    {
        $exclude = Search::settings()['exclude'];
        return array_values(array_filter(Tables::content(), fn($t) => ($t['settings']['route'] ?? '') !== '' && !empty($t['settings']['detail_page_id'])
            && TableSearch::config($t)['enabled'] && !in_array($t['handle'], $exclude, true) && !\Core\PageAccess::tableRestricted($t)));
    }

    private static function entries(string $lang): \Generator
    {
        $detail = [];   // Detailseiten-Vorlage je Tabelle (Kontext für zentrale Blöcke und Sprungmarken; false = keine)
        foreach (self::tables() as $t) {
            $cfg = TableSearch::config($t);
            try {
                $rows = Entries::query($t, ['status' => 'published', 'lang' => $lang, 'source' => 'site', 'limit' => 5000]);
                // Nur künftige Termine: nächstes Vorkommen je Eintrag (Wiederholungen berücksichtigt)
                $next = $cfg['future'] ? self::nextDates($t, $cfg, $rows, $lang) : null;
            } catch (\Throwable $e) {
                error_log('[search] ' . $t['handle'] . ': ' . $e->getMessage());
                continue;
            }
            $shared = Tables::isShared($t);
            $badge = TableSearch::label($t, $cfg);
            foreach ($rows as $e) {
                if ($next !== null && !isset($next[$e['id']])) continue;
                if ($cfg['exclude'] && self::matches($t, $e, $cfg['exclude'])) continue;   // Ausschluss prüft den vollen Eintrag
                if (!Exposure::visible($t, $e)) continue;           // Kontakte ohne „Auf der Website zeigen“: nicht im Index
                if (\Core\Data\Knowledge::is($t) && \Core\Data\Knowledge::refUrl($t, $e) !== null) continue;   // Wissen: Verweis-Kapitel – die Seite selbst steht im Index
                $e = Exposure::publicEntry($t, $e);              // „Nie öffentlich“: weder Text, Auszug, Facetten noch Bild
                $url = Entries::href($t, $e);
                if (!$url) continue;
                foreach (Media::idsInEntry($t, $e) as $mid) self::$used[$mid] = true;
                $bucket = ['high' => [], 'normal' => [], 'low' => []];
                $anchors = [];
                foreach ($t['fields'] as $f) {
                    $w = $cfg['fields'][$f['name']] ?? 'off';
                    if ($w === 'off' || $f['name'] === $cfg['title']) continue;
                    if (($f['type'] ?? '') === EntryBlocks::TYPE) {
                        // Inhaltsblöcke: wie Seiten – Überschriften hoch, Text mit dem Gewicht des Feldes, Sprungmarken der Abschnitte
                        $items = EntryBlocks::items($e[$f['name']] ?? null);
                        $page = $detail[$t['handle']] ??= self::detailPage($t);
                        [$h, $tx] = self::blocksText($items, $page ?: null);
                        foreach ($h as $x) $bucket['high'][] = $x;
                        if ($tx) $bucket[$w][] = implode(' ', $tx);
                        if ($h) array_push($anchors, ...self::entryAnchors($t, $e, $items, $page ?: null));
                        continue;
                    }
                    $v = self::fieldText($t, $e, $f);
                    if ($v !== '') $bucket[$w][] = $v;
                }
                [$date, $label] = isset($next[$e['id']]) ? $next[$e['id']] : self::entryDate($t, $e, $cfg);
                $facets = [];
                foreach ($cfg['facets'] as $fn) {
                    if ($vals = self::facetValues($t, $e, Tables::field($t, $fn) ?? [])) $facets[$fn] = $vals;
                }
                $title = $cfg['title'] !== '' ? trim(strip_tags((string) ($e[$cfg['title']] ?? ''))) : '';
                yield self::doc([
                    'id' => 'e-' . $t['handle'] . '-' . $e['id'], 'type' => 'entry', 'table' => $t['handle'], 'badge' => $badge,
                    'title' => $title !== '' ? $title : Entries::title($t, $e),
                    'headings' => implode(' · ', array_unique($bucket['high'])), 'text' => implode(' ', $bucket['normal']), 'extra' => implode(' ', $bucket['low']),
                    'summary' => $cfg['summary'] !== '' ? Text::plain(Entries::html($t, $e, $cfg['summary'])) : '',
                    'image' => $cfg['image'] !== '' && !empty($e[$cfg['image']]) ? (string) (int) $e[$cfg['image']] : '',
                    'facets' => $facets ? json_encode($facets, JSON_UNESCAPED_UNICODE) : '',
                    'url' => $url, 'date' => $date, 'date_label' => $label,
                    'origin' => $shared && Shared::isForeign($t, $e) ? Entries::html($t, $e, '_origin') : '',
                    'anchors' => self::anchorsJson($anchors),
                ]);
            }
        }
    }

    /** Klartext eines Feldes für die Suche (Auswahl als Beschriftung, Verknüpfungen als Titel, Gruppen ohne IBAN/E-Mail/Telefon) */
    private static function fieldText(array $t, array $e, array $f): string
    {
        $n = (string) ($f['name'] ?? '');
        if ($n === '') return '';
        if (($f['type'] ?? '') === 'group') {
            $out = [];
            foreach (Entries::groupRows($f, $e[$n] ?? null) as $row) {
                foreach ((array) ($f['fields'] ?? []) as $sf) {
                    if (in_array($sf['type'] ?? 'text', ['iban', 'email', 'tel'], true)) continue;
                    $c = Entries::groupCell($sf, $row[$sf['name']] ?? null);
                    if ($c !== '' && $c !== '✓') $out[] = $c;
                }
            }
            return implode(' ', $out);
        }
        if (\Core\Combo::is((string) ($f['type'] ?? ''))) return \Core\Combo::text($f, $e[$n] ?? null);
        if (($f['type'] ?? '') === \Core\Data\EntryBlocks::TYPE) return \Core\Data\EntryBlocks::text($e[$n] ?? null);
        if (($f['type'] ?? '') === 'mediatext') return trim((string) preg_replace('~\s+~u', ' ', implode(' ', array_map(fn($s) => $s['title'] . ' ' . Text::plain($s['text']), \Core\Fields::mediaTextSections($e[$n] ?? null)))));
        $v = in_array($f['type'], ['relation', 'relations', 'richtext'], true) ? Text::plain(Entries::html($t, $e, $n, ['link' => false])) : Entries::text($t, $e, $n);
        if ($f['type'] === 'file' && is_numeric($e[$n] ?? null)) $v .= ' ' . self::transcript((int) $e[$n]);
        return trim((string) preg_replace('~\s+~u', ' ', $v));
    }

    /** Detailseiten-Vorlage einer Tabelle (false = keine) */
    private static function detailPage(array $t): array|false
    {
        $id = (int) ($t['settings']['detail_page_id'] ?? 0);
        return $id ? (Pages::find($id) ?: false) : false;
    }

    /**
     * Sprungmarken der Inhaltsblöcke eines Eintrags – so, wie die Detailseite sie vergibt: Blöcke mit den IDs der Einbindung
     * (EntryBlocks::forContext: e{eintrag}-{block}) im Kontext des Eintrags ausgeben; Wissensbereich: IDs der Abschnitte wie
     * „In diesem Kapitel“ (Knowledge::sections, h2), sonst vorhandene IDs von Überschriften (z. B. Inhaltsverzeichnis eines Kits).
     * Datenlisten und andere dynamische Blöcke (SKIP_BLOCKS) werden dabei nicht ausgegeben.
     * @return list<array{id: string, label: string}>
     */
    private static function entryAnchors(array $t, array $e, array $items, ?array $page): array
    {
        $items = array_values(array_filter($items, fn($b) => !in_array((string) ($b['type'] ?? ''), self::SKIP_BLOCKS, true)
            && empty($b['tunes']['section']['noSearch'])));
        if (!$items || !$page) return [];
        $blocks = array_map(fn($b) => ['id' => 'e' . $e['id'] . '-' . ($b['id'] ?? '')] + $b, $items);
        $app = app();
        $prev = [$app->currentPage, $app->editing];
        $app->currentPage = $page;
        $app->editing = false;
        try {
            $html = EntryBlocks::render($blocks, $t, $e);
        } catch (\Throwable $ex) {
            error_log('[search] anchors ' . $t['handle'] . '-' . $e['id'] . ': ' . $ex->getMessage());
            return [];
        } finally {
            [$app->currentPage, $app->editing] = $prev;
        }
        $out = [];
        if (\Core\Data\Knowledge::is($t)) {
            [$html, $secs] = \Core\Data\Knowledge::sections($html);
            foreach ($secs as $sec) $out[$sec['id']] = ['id' => $sec['id'], 'label' => $sec['label']];
        }
        if (preg_match_all('~<h([1-6])\b([^>]*\sid="([^"]+)"[^>]*)>(.*?)</h\1>~is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $aid = html_entity_decode($x[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = Text::plain($x[4]);
                if ($label !== '' && !isset($out[$aid])) $out[$aid] = ['id' => $aid, 'label' => $label];
            }
        }
        return array_values($out);
    }

    /** Sprungmarken als JSON (höchstens 80, Beschriftung gekürzt) – leer ohne */
    private static function anchorsJson(array $anchors): string
    {
        $list = [];
        foreach ($anchors as $a) {
            $aid = (string) ($a['id'] ?? '');
            $label = mb_substr(trim((string) ($a['label'] ?? '')), 0, 160);
            if ($aid === '' || $label === '' || !preg_match('~^[A-Za-z][A-Za-z0-9_\-:.]*$~', $aid)) continue;
            $list[$aid] ??= ['id' => $aid, 'label' => $label];
            if (count($list) >= 80) break;
        }
        return $list ? (string) json_encode(array_values($list), JSON_UNESCAPED_UNICODE) : '';
    }

    /**
     * Passende Sprungmarke für einen Treffer: erste Überschrift, in der alle Suchwörter (gefaltet, als Wortanfang) vorkommen.
     * @param list<string> $words Text::words() der Anfrage
     * @return ?array{id: string, label: string}
     */
    public static function anchorFor(string $json, array $words): ?array
    {
        if ($json === '' || !$words) return null;
        foreach ((array) json_decode($json, true) as $a) {
            if (!is_array($a) || !isset($a['id'], $a['label'])) continue;
            $hay = ' ' . preg_replace('~[^\p{L}\p{N}]+~u', ' ', Text::fold((string) $a['label']));
            $all = true;
            foreach ($words as $w) if (!str_contains($hay, ' ' . $w)) { $all = false; break; }
            if ($all) return ['id' => (string) $a['id'], 'label' => (string) $a['label']];
        }
        return null;
    }

    /** Veröffentlichtes Transkript eines Videos/Audios in der Sprache des Index (ohne Rückfall auf andere Sprachen) */
    private static function transcript(int $mediaId): string
    {
        $m = $mediaId ? Media::find($mediaId) : null;
        if (!$m || !\Core\MediaTracks::enabled() || !\Core\MediaTracks::supports($m)) return '';
        return mb_substr((string) preg_replace('~\s+~u', ' ', \Core\MediaTracks::transcriptText($m, Lang::current(), false)), 0, 20000);
    }

    /** Werte eines Filterfeldes (Auswahl-Beschriftungen bzw. Titel verknüpfter Einträge) */
    private static function facetValues(array $t, array $e, array $f): array
    {
        $raw = $e[$f['name'] ?? ''] ?? null;
        if ($raw === null || $raw === '' || $raw === []) return [];
        if (in_array($f['type'] ?? '', ['relation', 'relations'], true)) {
            $target = Tables::findContent($f['target'] ?? '');
            if (!$target) return [];
            return array_values(array_map(fn($r) => Entries::title($target, $r), Entries::query($target, ['ids' => (array) $raw, 'source' => 'all', 'status' => 'all'])));
        }
        return array_values(array_map(fn($v) => Tables::optionLabel($f, (string) $v), (array) $raw));
    }

    /** Ausschluss-Bedingung: Feld hat diesen Wert (Auswahl: Schlüssel oder Beschriftung; Mehrfach: enthält) */
    private static function matches(array $t, array $e, array $x): bool
    {
        $raw = $e[$x['field']] ?? null;
        $vals = is_array($raw) ? array_map('strval', $raw) : [(string) $raw];
        $f = Tables::field($t, $x['field']);
        if ($f && in_array($f['type'], ['select', 'multiselect'], true)) {
            foreach ($vals as $v) $vals[] = Tables::optionLabel($f, $v);
        }
        if ($f && $f['type'] === 'bool') $vals = [$raw ? '1' : '0', $raw ? 'ja' : 'nein'];
        return in_array(mb_strtolower($x['value']), array_map('mb_strtolower', $vals), true);
    }

    /** Nächstes Datum je Eintrag ab heute: [id => [iso, anzeige]] (Kalender: Vorkommen bis 1 Jahr; sonst Datumsfeld ≥ heute) */
    private static function nextDates(array $t, array $cfg, array $rows, string $lang): array
    {
        $out = [];
        if (Calendar::enabled($t)) {
            foreach (Calendar::upcoming($t, 1000, 366, ['lang' => $lang, 'source' => 'site']) as $o) {
                $id = (int) $o['entry']['id'];
                if (!isset($out[$id])) $out[$id] = [$o['start']->format('Y-m-d H:i'), Calendar::when(array_replace($o, ['recurring' => false]), 'short')];
            }
            return $out;
        }
        $today = date('Y-m-d');
        foreach ($rows as $e) {
            [$d, $l] = self::entryDate($t, $e, $cfg);
            if ($d !== '' && substr($d, 0, 10) >= $today) $out[(int) $e['id']] = [$d, $l];
        }
        return $out;
    }

    /** Datum für die Anzeige: gewähltes Datumsfeld, sonst Termin (Kalender), sonst erstes Datumsfeld */
    private static function entryDate(array $t, array $e, array $cfg = []): array
    {
        if (($cfg['date'] ?? '') === '' && Calendar::enabled($t) && ($span = Calendar::span($t, $e))) {
            return [$span['start']->format('Y-m-d H:i'), Calendar::when($span + ['recurring' => false], 'short')];
        }
        foreach ($t['fields'] as $f) {
            if (($cfg['date'] ?? '') !== '' && $f['name'] !== $cfg['date']) continue;
            if (in_array($f['type'], ['date', 'datetime'], true) && !empty($e[$f['name']]) && ($ts = strtotime((string) $e[$f['name']]))) {
                return [date('Y-m-d H:i', $ts), date_local($ts, 'short') . ($f['type'] === 'datetime' ? ', ' . lt('{time} Uhr', ['time' => fmt()->time($ts)]) : '')];
            }
        }
        return ['', ''];   // Veröffentlichungsdatum allein ist kein Inhaltsdatum (z. B. Schlagwörter)
    }

    // ------------------------------------------------------------------ Dokumente (PDF)

    /**
     * PDFs, die auf einer aufgenommenen Seite oder in einem aufgenommenen Eintrag verwendet werden (also veröffentlicht, nicht
     * „noindex“, nicht geschützt) – Titel, Alt-Text, Dateiname, Schlagwörter und Inhalt (pdftotext, Media::pdfText).
     * PDFs, die nur in Entwürfen, auf geschützten Seiten oder gar nicht verwendet werden, sind nie suchbar.
     */
    private static function files(): \Generator
    {
        foreach (Media::all(['kind' => 'pdf']) as $m) {
            if (!isset(self::$used[(int) $m['id']])) continue;
            if (\Core\MediaPools::mediaProtected($m)) continue;   // geschützter Pool: nie im Suchindex
            $url = Media::viewerUrl($m) ?? Media::url($m);
            yield self::doc([
                'id' => 'm-' . $m['id'], 'type' => 'file', 'badge' => lt('Dokument'),
                'title' => Media::displayName($m), 'headings' => trim(Media::alt($m) . ' ' . str_replace(',', ' ', (string) ($m['tags'] ?? ''))),
                'text' => trim((string) $m['original_name'] . ' ' . mb_substr(Media::pdfText(Media::find((int) $m['id']) ?? $m), 0, 60000)),
                'url' => $url, 'date' => substr((string) ($m['created_at'] ?? ''), 0, 10),
            ]);
        }
    }

    // ------------------------------------------------------------------ Synonyme

    /** Begriffe der Synonym-Gruppen, die im Dokument vorkommen, um die übrigen Begriffe der Gruppe ergänzen */
    private static function expand(array $d, array $groups): string
    {
        $hay = ' ' . Text::fold($d['title'] . ' ' . $d['headings'] . ' ' . $d['text'] . ' ' . $d['extra']) . ' ';
        $hay = (string) preg_replace('~[^\p{L}\p{N}]+~u', ' ', $hay);
        $add = [];
        foreach ($groups as $g) {
            $hit = false;
            foreach ($g as $term) {
                if (str_contains($hay, ' ' . Text::fold($term) . ' ')) { $hit = true; break; }
            }
            if ($hit) foreach ($g as $term) $add[$term] = true;
        }
        return implode(' ', array_keys($add));
    }

    /** Selbsttest (notes:selftest): Redaktionsnotizen landen nicht im Suchindex */
    public static function notesSelftest(array $res): array
    {
        $fields = [['name' => 'title', 'type' => 'text'], ['name' => 'text', 'type' => 'richtext'],
            ['name' => 'items', 'type' => 'repeater', 'fields' => [['name' => 'q', 'type' => 'text'], ['name' => 'a', 'type' => 'textarea']]]];
        $data = ['title' => 'Seminare [# Platzhalter #]', 'text' => '<h3>Termine [# prüfen #]</h3><p>Ab März.[# bitte ergänzen:\nOrt #]</p>',
            'items' => [['q' => 'Frage', 'a' => "Antwort [# geheim #]"]]];
        $head = $text = [];
        self::fieldsText($fields, $data, $head, $text);
        $d = self::doc(['id' => 'p-1', 'title' => 'Kurs [# Titel prüfen #]', 'headings' => implode(' · ', $head), 'text' => implode(' ', $text), 'summary' => 'x [# y #]']);
        $all = implode(' | ', $d);
        $check = function (string $name, bool $ok) use (&$res) { if ($ok) $res['ok']++; else $res['fail'][] = $name; };
        $check('Suchindex ohne Notizen: ' . $all, !str_contains($all, '[#') && !str_contains($all, 'geheim') && !str_contains($all, 'Platzhalter'));
        $check('Suchindex behält Text', str_contains($all, 'Seminare') && str_contains($all, 'Termine') && str_contains($all, 'Ab März.') && str_contains($all, 'Antwort'));
        $check('Text::plain ohne Notizen', Text::plain('<p>A [# b #]</p><p>C</p>') === 'A C');
        return $res;
    }
}
