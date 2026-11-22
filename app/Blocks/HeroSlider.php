<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blocks;

use Core\Block;
use Core\Data\Entries;
use Core\Data\Featured;
use Core\Media;
use Core\Theme;

/**
 * Einstieg als Slider (Block „hero“, Schalter „Als Slider zeigen“, Standard aus): mehrere Folien mit dem Einstieg des Kits.
 *
 * Der Kern legt die Folien um den Hero des Kits, statt jeden Kit-Renderer anzupassen: Theme::renderBlock rendert den Block je Folie
 * mit anderen Inhalten (Bild/Video, Dachzeile, Titel, Text, Buttons) – Variante, Design und Abschnitts-Optionen bleiben – und setzt
 * die Folien in eine Hülle mit Steuerung. Alle Varianten der Kits gelten weiter.
 *
 *  - Folien: 1. der Inhalt des Blocks (abschaltbar), 2. eigene Folien (Wiederholgruppe mit den Inhaltsfeldern des Kit-Heros),
 *    3. Folien aus Einträgen: hervorgehobene Einträge mehrerer Tabellen (Core\Data\Featured::items – wie Block „Highlights“) mit der
 *    Zuordnung „Felder des Eintrags“ wie beim Detail-Hero (Core\Blocks\EntryHero::defaults/value), Button zur Detailseite.
 *  - Genau eine H1: die erste Folie; die übrigen setzen ihre Überschrift als h2 (Klassen bleiben, Aussehen gleich).
 *  - Ohne Skript steht die erste Folie still, die übrigen sind verborgen (hidden). Bilder der weiteren Folien laden verzögert.
 *  - Skript public/assets/js/hero-slider.js und Stile css/hero-slider.css nur auf Seiten mit Slider (Merkmal '@heroslider'); ein Kit
 *    ersetzt die Stile mit eigener css/hero-slider.css. Pause/Play über den gemeinsamen Schalter (Hero::playToggle, video-toggle.css).
 *  - Barrierefreiheit (WAI-ARIA Carousel): role="region" + aria-roledescription „Karussell“, Folien role="group" + „Folie“ mit
 *    „x von n“, Steuerung vor den Folien (Pause zuerst), Live-Region nur nach Bedienung, Pause bei Maus und Fokus, kein Auto-Wechsel
 *    und harte Schnitte bei „Bewegung reduzieren“, Tastatur (Pfeile, Pos1/Ende), Wischen auf Touch.
 *  - Hintergrundvideos in Folien: der Video-Schalter der Folie entfällt (Hero::$inSlider); EIN Knopf in der Leiste hält Auto-Wechsel
 *    und das Video der aktuellen Folie zusammen an („Bewegung anhalten/fortsetzen“). Das Skript pausiert Videos inaktiver Folien und
 *    spielt das der aktiven nur, wenn nicht angehalten, ohne „Bewegung reduzieren“ und ohne „Daten sparen“ (hero.mjs lässt sie aus).
 *  - Bearbeiten-Modus und Inline-Bearbeitung auf Detailseiten: nur der Inhalt des Blocks (wie bisher) mit Hinweis im Editor.
 */
final class HeroSlider
{
    /** Inhaltsfelder des Kit-Heros, die eine eigene Folie überschreiben kann (nur die, die das Kit hat) */
    public const CONTENT = ['image', 'video', 'eyebrow', 'title', 'text', 'button_label', 'button_link', 'button2_label', 'button2_link'];
    /** Inhalte des Blocks, die nur zur ersten Folie gehören (Bilderfolge, Aufzählung, Laufzeile) */
    private const LEAD_ONLY = ['gallery', 'points', 'marquee'];
    public const TRANSITIONS = ['morph' => 'Morphing – Bilder weich ineinander, Titel wird aufgedeckt', 'words' => 'Morphing – Titel Wort für Wort',
        'up' => 'Aufdecken von unten', 'side' => 'Aufdecken seitlich', 'fade' => 'Überblenden'];
    /** Varianten, über deren Bild die Steuerung liegt (sonst als Leiste darunter) */
    private const OVERLAY = ['fullbleed', 'cover', 'image', 'panel', 'mosaic'];
    private const MAX = 12;

    /** Kommt der Block in Frage? (Hero eines Kits mit Titel – praxis u. a. mit eigenem Einstieg ohne Titel nicht) */
    public static function supports(?array $def): bool
    {
        if (!is_array($def) || ($def['type'] ?? '') !== 'hero' || !empty($def['custom'])) return false;
        return in_array('title', array_column((array) ($def['fields'] ?? []), 'name'), true);
    }

    /** Felder „Slider“ (aufklappbarer Abschnitt am Ende) an den Hero des Kits hängen – idempotent */
    public static function decorate(array $def): array
    {
        if (!self::supports($def) || in_array('slider', array_column((array) $def['fields'], 'name'), true)) return $def;
        $def['fields'] = [...$def['fields'], ...self::fields($def)];
        return $def;
    }

    /** Die Felder des Sliders; Felder einer eigenen Folie = die Inhaltsfelder des Kit-Heros (ohne Bedingungen) */
    public static function fields(array $def): array
    {
        $on = ['slider' => ['1']];
        $slide = [];
        foreach ((array) $def['fields'] as $f) {
            if (!in_array($f['name'] ?? '', self::CONTENT, true)) continue;
            unset($f['show_if'], $f['variants'], $f['default']);
            if (($f['name'] ?? '') !== 'title') unset($f['required']);
            $slide[] = $f;
        }
        return [
            ['type' => 'heading', 'label' => 'Slider', 'collapse' => true, 'summary' => 'Mehrere Folien, Übergang, Steuerung'],
            ['name' => 'slider', 'label' => 'Als Slider zeigen (mehrere Folien)', 'type' => 'bool', 'default' => false,
                'help' => 'Der Einstieg wechselt zwischen mehreren Folien – mit derselben Variante und Gestaltung. Ohne JavaScript steht die erste Folie still. Bei „Bewegung reduzieren“ wechselt nichts von selbst.'],
            ['name' => 'slides_lead', 'label' => 'Erste Folie', 'type' => 'select', 'required' => true, 'default' => 'block', 'width' => 'half', 'show_if' => $on,
                'options' => ['block' => 'Inhalt dieses Einstiegs (oben)', 'none' => 'Nur die Folien unten']],
            ['name' => 'slides_mix', 'label' => 'Reihenfolge', 'type' => 'select', 'required' => true, 'default' => 'own', 'width' => 'half', 'show_if' => $on,
                'options' => ['own' => 'Eigene Folien, dann Einträge', 'entries' => 'Einträge, dann eigene Folien']],
            ['name' => 'slides', 'label' => 'Eigene Folien', 'type' => 'repeater', 'item_label' => 'Folie', 'title_field' => 'title', 'max_items' => 8,
                'show_if' => $on, 'fields' => $slide],
            ['name' => 'slides_sources', 'label' => 'Folien aus Einträgen (hervorgehoben)', 'type' => 'repeater', 'item_label' => 'Tabelle', 'title_field' => 'table', 'max_items' => 6,
                'show_if' => $on, 'help' => 'Einträge mit „Auf der Startseite hervorheben“ – Bild, Titel, Dachzeile und Text wie beim Einstieg mit Quelle „Felder des Eintrags“, Button zur Detailseite.',
                'fields' => [
                    ['name' => 'table', 'label' => 'Tabelle', 'type' => 'datatable', 'required' => true, 'width' => 'half'],
                    ['name' => 'limit', 'label' => 'Höchstens', 'type' => 'select', 'required' => true, 'default' => '3', 'width' => 'half',
                        'options' => ['1' => '1', '2' => '2', '3' => '3', '4' => '4', '6' => '6']],
                    ['name' => 'badge', 'label' => 'Bereich als Dachzeile (optional)', 'type' => 'text', 'max' => 32, 'width' => 'half',
                        'help' => 'Leer = automatisch (Kategorie, Termin oder Kapitel des Eintrags).'],
                ]],
            ['name' => 'slides_sort', 'label' => 'Einträge sortieren', 'type' => 'select', 'required' => true, 'default' => 'date', 'width' => 'half', 'show_if' => $on,
                'options' => ['date' => 'Neueste zuerst', 'order' => 'Reihenfolge (Zahl am Eintrag)']],
            ['name' => 'slides_button', 'label' => 'Button der Eintrags-Folien', 'type' => 'text', 'max' => 32, 'width' => 'half', 'show_if' => $on, 'placeholder' => 'Mehr erfahren'],
            ['name' => 'slides_transition', 'label' => 'Übergang', 'type' => 'select', 'required' => true, 'default' => 'morph', 'width' => 'half', 'show_if' => $on,
                'options' => self::TRANSITIONS],
            ['name' => 'slides_kenburns', 'label' => 'Bilder zoomen langsam (Kenburns)', 'type' => 'bool', 'default' => false, 'width' => 'half', 'show_if' => $on,
                'help' => 'Zoomt zum Fokuspunkt des Bildes (Mediathek). Nie bei „Bewegung reduzieren“.'],
            ['name' => 'slides_interval', 'label' => 'Automatisch weiter nach', 'type' => 'select', 'required' => true, 'default' => '7', 'width' => 'half', 'show_if' => $on,
                'options' => ['0' => 'nie (nur per Klick)', '5' => '5 Sekunden', '7' => '7 Sekunden', '9' => '9 Sekunden', '12' => '12 Sekunden'],
                'help' => 'Hält bei Maus und Tastatur-Fokus an; mit Pause-Knopf.'],
            ['name' => 'slides_nav', 'label' => 'Folien-Auswahl', 'type' => 'select', 'required' => true, 'default' => 'dots', 'width' => 'half', 'show_if' => $on,
                'options' => ['dots' => 'Punkte', 'numbers' => 'Nummern', 'none' => 'keine']],
            ['name' => 'slides_arrows', 'label' => 'Pfeile „zurück“ und „weiter“', 'type' => 'bool', 'default' => true, 'width' => 'half', 'show_if' => $on],
            ['name' => 'slides_toggle', 'label' => 'Pause-Knopf', 'type' => 'select', 'required' => true, 'default' => 'icon', 'width' => 'half', 'show_if' => $on,
                'options' => Hero::TOGGLE_MODES],
        ];
    }

    /** Ist der Slider für diesen Block an? (nur eigener Abschnitt, nicht in Spalten) */
    public static function active(Block $b): bool
    {
        return $b->type === 'hero' && !empty($b->data['slider']) && $b->parent === null && self::supports($b->def);
    }

    /**
     * Daten der Folien in Reihenfolge – jede ein vollständiger Datensatz des Blocks (Variante, Design …) mit anderen Inhalten;
     * 'key' = „tabelle:id“ bei Folien aus Einträgen (sonst null).
     * @return list<array{data: array, label: string, key: ?string}>
     */
    public static function slides(Block $b): array
    {
        $d = $b->data;
        $base = $d;
        $base['slider'] = false;
        unset($base['_bind'], $base['slides'], $base['slides_sources']);
        $names = array_column((array) $b->def['fields'], 'name');
        $content = array_values(array_intersect(self::CONTENT, $names));
        $clean = $base;
        foreach ([...$content, ...self::LEAD_ONLY] as $k) unset($clean[$k]);
        $clean = array_replace(array_fill_keys(array_intersect([...$content, ...self::LEAD_ONLY], $names), ''), $clean);
        if (array_key_exists('image', $clean)) $clean['image'] = null;
        if (array_key_exists('video', $clean)) $clean['video'] = null;

        $own = [];
        foreach ((array) ($d['slides'] ?? []) as $s) {
            if (!is_array($s) || trim((string) ($s['title'] ?? '')) === '') continue;
            $x = $clean;
            foreach ($content as $k) if (array_key_exists($k, $s)) $x[$k] = $s[$k];
            $x['image'] = !empty($s['image']) ? (int) $s['image'] : null;
            $own[] = ['data' => $x, 'key' => null];
        }
        $entries = [];
        $ctx = app()->entry;
        $button = trim((string) ($d['slides_button'] ?? '')) ?: lt('Mehr erfahren');
        // Bereich als Dachzeile nur, wenn ausdrücklich eingetragen (sonst die Dachzeile aus dem Eintrag)
        $badges = [];
        foreach ((array) ($d['slides_sources'] ?? []) as $src) {
            if (is_array($src) && ($st = \Core\Data\Tables::findContent((string) ($src['table'] ?? '')))) $badges[$st['handle']] ??= trim((string) ($src['badge'] ?? ''));
        }
        foreach (Featured::items((array) ($d['slides_sources'] ?? []), ['sort' => (string) ($d['slides_sort'] ?? 'date'),
            'exclude' => $ctx ? [$ctx['table']['handle'] => (int) $ctx['entry']['id']] : []]) as $it) {
            $entries[] = ['data' => self::entryData($clean, $b->def, $it, $button, $badges[$it['table']['handle']] ?? ''),
                'key' => $it['table']['handle'] . ':' . (int) $it['entry']['id']];
        }
        $list = ($d['slides_mix'] ?? 'own') === 'entries' ? [...$entries, ...$own] : [...$own, ...$entries];
        if (($d['slides_lead'] ?? 'block') !== 'none') array_unshift($list, ['data' => $base, 'key' => null]);
        $list = array_slice($list, 0, self::MAX);
        $n = count($list);
        return array_map(fn(array $x, int $i) => $x + ['label' => lt('{n} von {total}', ['n' => $i + 1, 'total' => $n])], $list, array_keys($list));
    }

    /** @var array<string, array<string, list<int>>> Einträge in Slidern je Seite (pageEntries, einmal je Anfrage) */
    private static array $pageCache = [];

    /**
     * Einträge, die als Folien in einem Slider dieser Seite stehen: [handle => [ids]] – für den Block „Highlights“ (Option „Einträge
     * auslassen, die schon im Slider dieser Seite stehen“). Liest die Blöcke der aufgerufenen Seite (beim Bearbeiten den Entwurf),
     * unabhängig von der Reihenfolge der Blöcke. Nur Slider mit mindestens zwei Folien (sonst gibt es keinen).
     */
    public static function pageEntries(): array
    {
        $page = app()->currentPage;
        if (!$page || empty($page['id'])) return [];
        $ck = (int) $page['id'] . (app()->editing ? ':d' : ':p') . (app()->entry ? ':' . app()->entry['table']['handle'] . (int) app()->entry['entry']['id'] : '');
        if (isset(self::$pageCache[$ck])) return self::$pageCache[$ck];
        $out = [];
        $theme = app()->theme;
        foreach (\Core\Pages::blocks($page, app()->editing) as $raw) {
            if (!is_array($raw) || ($raw['type'] ?? '') !== 'hero' || empty($raw['data']['slider'])) continue;
            if (($raw['tunes']['section']['visible'] ?? true) === false) continue;
            $blk = $theme->makeBlock($raw);
            if (!$blk || !self::supports($blk->def)) continue;
            $slides = self::slides($blk);
            if (count($slides) < 2) continue;
            foreach ($slides as $s) {
                if ($s['key'] === null) continue;
                [$h, $id] = explode(':', $s['key'], 2);
                $out[$h][] = (int) $id;
            }
        }
        return self::$pageCache[$ck] = $out;
    }

    /** Nur für Selbsttests: Zwischenspeicher leeren */
    public static function reset(): void
    {
        self::$pageCache = [];
    }

    /** Folie aus einem hervorgehobenen Eintrag: Zuordnung wie Einstieg „Felder des Eintrags“, Button zur Detailseite */
    private static function entryData(array $clean, array $def, array $it, string $button, string $badge): array
    {
        $t = $it['table'];
        $e = $it['entry'];
        $types = array_column((array) $def['fields'], 'type', 'name');
        $x = $clean;
        foreach (EntryHero::defaults($t) as $target => $src) {
            if (!array_key_exists($target, $types)) continue;
            if ($target === 'eyebrow' && $badge !== '') continue;
            $x[$target] = $src === '' ? ($types[$target] === 'media' ? null : '') : EntryHero::value($t, $e, $src, (string) $types[$target]);
        }
        if (trim((string) ($x['title'] ?? '')) === '') $x['title'] = Entries::title($t, $e);
        // Text als Anreißer: höchstens ~200 Zeichen ohne Formatierung – lange Beschreibungen machen sonst alle Folien hoch
        if (is_string($x['text'] ?? null) && $x['text'] !== '') $x['text'] = \Core\Format::for()->excerpt(trim(html_entity_decode(strip_tags($x['text']), ENT_QUOTES | ENT_HTML5)), 200, false);
        if (array_key_exists('eyebrow', $types) && $badge !== '') $x['eyebrow'] = $badge;
        $img = (int) ($x['image'] ?? 0);
        $x['image'] = $img && Media::find($img) ? $img : null;
        if (!$x['image'] && in_array((string) ($x['variant'] ?? ''), EntryHero::IMAGE_VARIANTS, true) && isset($def['variants']['compact'])) $x['variant'] = 'compact';
        if (isset($types['button_link'])) {
            $x['button_link'] = (string) ($it['href'] ?? '');
            $x['button_label'] = $x['button_link'] !== '' ? $button : '';
        }
        return $x;
    }

    /**
     * Ausgabe (Theme::renderBlock): Hülle mit Steuerung und einer Folie je Datensatz – null, wenn es nur eine Folie gibt (dann
     * rendert Theme den Block wie bisher). Im Bearbeiten-Modus der Block wie bisher mit Hinweis auf die Folien.
     */
    public static function render(Theme $theme, Block $b): ?string
    {
        $slides = self::slides($b);
        if (count($slides) < 2) return null;
        if (app()->editing || app()->entryEdit) {
            if (!app()->editing) return null;
            $first = $slides[0]['data'];
            return $theme->renderBlock(new Block($b->id, $b->type, ['slider' => false] + $first, $b->tunes, $b->def, $b->prev))
                . '<p class="hs-editnote">' . e(__('Slider mit {n} Folien – die Vorschau zeigt die erste. Übergänge und Steuerung sehen Besucher.', ['n' => count($slides)])) . '</p>';
        }
        $theme->markUsed('@heroslider');
        $d = $b->data;
        $id = $b->domId() . '-hs';
        $n = count($slides);
        $transition = array_key_exists((string) ($d['slides_transition'] ?? ''), self::TRANSITIONS) ? (string) $d['slides_transition'] : 'morph';
        $interval = in_array((string) ($d['slides_interval'] ?? '7'), ['0', '5', '7', '9', '12'], true) ? (int) $d['slides_interval'] : 7;
        $navMode = in_array($d['slides_nav'] ?? 'dots', ['dots', 'numbers', 'none'], true) ? (string) ($d['slides_nav'] ?? 'dots') : 'dots';
        $overlay = in_array($b->variant(), self::OVERLAY, true);
        $html = '';
        $video = false;
        foreach ($slides as $i => $s) {
            $data = $s['data'];
            if ($i > 0) Media::$lazyAll = true;
            try {
                // Weitere Folien: eigene ID (Sprungmarke, Überschrift), kein Einblend-Effekt, Bild verzögert
                $tunes = $i === 0 ? $b->tunes : array_replace($b->tunes, ['anchor' => '', 'reveal' => '']);
                $blk = new Block($i === 0 ? $b->id : $b->id . '-s' . ($i + 1), $b->type, $data, $tunes, $b->def, $i === 0 ? $b->prev : $b);
                $blk->first = $i === 0 && $b->first;
                // Ein Knopf für alle Bewegung: der Video-Schalter der Folie (Hero::videoToggle) entfällt, die Leiste übernimmt ihn
                Hero::$inSlider = true;
                $section = $theme->renderBlock($blk);
            } finally {
                Media::$lazyAll = false;
                Hero::$inSlider = false;
            }
            $video = $video || str_contains($section, 'data-hero-video');
            if ($i > 0) $section = self::demote($section);
            $html .= '<div class="hs__slide' . ($i === 0 ? ' is-active' : '') . '" id="' . e($id . '-' . ($i + 1)) . '" role="group" aria-roledescription="' . e(lt('Folie')) . '"'
                . ' aria-label="' . e($s['label']) . '" data-hs-title="' . e($blk->titleId()) . '"' . ($i > 0 ? ' hidden' : '') . '>' . $section . '</div>';
        }
        // Steuerung vor den Folien (Reihenfolge beim Tabben: Pause, zurück, Auswahl, weiter), sichtbar erst mit Skript
        // Ein Knopf für Auto-Wechsel und Hintergrundvideo der aktuellen Folie (mit Video: „Bewegung anhalten/fortsetzen“)
        $ctrl = $interval > 0 || $video ? Hero::playToggle('hs__btn hs__toggle', (string) ($d['slides_toggle'] ?? 'icon'),
            $video ? lt('Bewegung anhalten') : lt('Automatischen Wechsel anhalten'), $video ? lt('Bewegung fortsetzen') : lt('Automatischen Wechsel starten'), 'data-hs-toggle') : '';
        $arrow = fn(string $dir, string $label) => '<button type="button" class="hs__btn hs__arrow hs__arrow--' . $dir . '" data-hs-' . $dir . ' aria-controls="' . e($id . '-track') . '">'
            . icon($dir === 'prev' ? 'arrow-left' : 'arrow-right', ['class' => 'hs__ico']) . '<span class="hs__sr">' . e($label) . '</span></button>';
        $arrows = !empty($d['slides_arrows']);
        if ($arrows) $ctrl .= $arrow('prev', lt('Vorherige Folie'));
        if ($navMode !== 'none') {
            $ctrl .= '<div class="hs__dots hs__dots--' . e($navMode) . '" role="group" aria-label="' . e(lt('Folie wählen')) . '">';
            foreach ($slides as $i => $s) {
                $title = strip_emphasis(strip_tags((string) ($s['data']['title'] ?? '')));
                $ctrl .= '<button type="button" class="hs__dot" data-hs-go="' . $i . '" aria-controls="' . e($id . '-track') . '"' . ($i === 0 ? ' aria-current="true"' : '')
                    . '><span class="hs__num" aria-hidden="true">' . ($navMode === 'numbers' ? sprintf('%02d', $i + 1) : '') . '</span>'
                    . '<span class="hs__sr">' . e(lt('Folie {n}: {title}', ['n' => $i + 1, 'title' => $title])) . '</span></button>';
            }
            $ctrl .= '</div>';
        }
        if ($arrows) $ctrl .= $arrow('next', lt('Nächste Folie'));
        $cls = 'hs hs--' . $transition . ($transition === 'words' ? ' hs--morph' : '') . (!empty($d['slides_kenburns']) ? ' hs--kb' : '') . ($overlay ? ' hs--overlay' : ' hs--below');
        return '<div class="' . e($cls) . '" id="' . e($id) . '" role="region" aria-roledescription="' . e(lt('Karussell')) . '" aria-label="' . e(lt('Wechselnde Folien')) . '"'
            . ' data-hero-slider data-interval="' . ($interval * 1000) . '">'
            . ($ctrl !== '' ? '<div class="hs__ctrl" hidden>' . $ctrl . '</div>' : '')
            . '<div class="hs__track" id="' . e($id . '-track') . '" aria-live="off">' . $html . '</div></div>';
    }

    /** Überschrift(en) erster Ordnung einer weiteren Folie als h2 – Klassen bleiben (Aussehen gleich), genau eine H1 je Seite */
    public static function demote(string $html): string
    {
        return (string) preg_replace(['~<h1(?=[\s>])~i', '~</h1>~i'], ['<h2', '</h2>'], $html);
    }
}
