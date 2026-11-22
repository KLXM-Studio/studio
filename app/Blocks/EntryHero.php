<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blocks;

use Core\Data\Calendar;
use Core\Data\Entries;
use Core\Data\Exposure;
use Core\Data\Knowledge;
use Core\Data\Tables;
use Core\Media;

/**
 * Einstieg (Block „hero“) mit Quelle „Felder des Eintrags“ – Detailseiten bekommen Köpfe wie reguläre Seiten.
 *
 * Core\Theme fügt jedem Hero-Block eines Kits (mit Feldern title + image) die Felder unten hinzu (decorate). Auf einer
 * Detailseiten-Vorlage wählt die Redaktion „Quelle: Felder des Eintrags“; beim Rendern legt Theme::makeBlock (apply) Bild,
 * Titel (H1), Dachzeile, Text und optional den Button aus dem aufgerufenen Eintrag über die Blockdaten. Jeder Kit-Renderer
 * (blocks/hero.php) bekommt also normale Blockdaten – alle Varianten, Überlagerungen und Design-Einstellungen gelten weiter.
 *
 *  - Felder (Typ datafield mit 'entry' => true, Wert „tabelle.feld“): leer = Standard der Tabelle (defaults()), „_none“ = nichts.
 *    Standard: Bildfeld der Tabelle, Titel des Eintrags, Dachzeile = Kapitelnummer (Wissen, „Kapitel 2.1“, nur mit
 *    eingeschalteten Nummern) bzw. Termin (Kalender) bzw. Auswahlfeld kategorie/bereich/rubrik, Text = Beschreibungsfeld der Tabelle.
 *  - Ohne Bild (leer, gelöscht, „Nie öffentlich“): bildbetonte Varianten werden zur schlichten Variante „compact“ (falls vorhanden).
 *  - Leere Felder bleiben leer (Kits blenden leere Dachzeile/Text aus), ein leerer Titel wird zum automatischen Titel des Eintrags.
 *  - Übernommene Felder stehen in data._bind: im Vorlagen-Editor „Aus dem Eintrag“, auf der Detailseite inline bearbeitbar
 *    (Core\Data\EntryEdit::blockAttr) – wie von Hand gebundene Felder.
 *  - Ohne Eintrag (normale Seite, Vorlage ohne Einträge) oder mit Quelle „Eigene Inhalte“ (Standard, alle bestehenden Blöcke):
 *    Daten unverändert – darum keine DefaultChanges-Umstellung.
 *  - Wissensbereich: steht ein solcher Hero als erster Block, legt Core\Data\Knowledge::render() ihn über die volle Breite
 *    vor den Rahmen mit Seitenleiste (leadIndex()); die Kapitelnummer steht dann in der Dachzeile statt vor der H1.
 *  - Genau eine H1: $titled merkt sich, dass der Hero die Überschrift des Eintrags gesetzt hat – der Block „Datensatz-Felder“
 *    im Kopf-Layout setzt den Titel dann als h2 (ältere Vorlagen mit beidem).
 */
final class EntryHero
{
    public const SOURCE = 'entry';
    /** Blockfeld → Auswahlfeld der Quelle */
    public const MAP = ['image' => 'src_image', 'title' => 'src_title', 'eyebrow' => 'src_eyebrow', 'text' => 'src_text'];
    /** Varianten, die ohne Bild nicht tragen → schlichte Variante „compact“ */
    public const IMAGE_VARIANTS = ['fullbleed', 'split', 'centered', 'cards', 'collage', 'cover', 'mosaic', 'product', 'panel', 'image', 'stack'];
    /** Bevorzugte Varianten für neue Vorlagen („Kopf wie Seiten“) */
    public const PREFERRED = ['fullbleed', 'cover', 'split', 'center'];
    /** Kandidaten für die Dachzeile (Auswahlfelder) */
    private const EYEBROW_NAMES = ['kategorie', 'kategorien', 'bereich', 'rubrik', 'thema', 'themen', 'typ', 'art'];

    /** Hat der Einstieg dieser Anfrage die H1 aus dem Eintrag gesetzt? (Block „Datensatz-Felder“ → Titel als h2) */
    public static bool $titled = false;

    /** Kommt der Block für die Quelle in Frage? (Hero eines Kits mit Titel und Bild – praxis u. a. nicht) */
    public static function supports(?array $def): bool
    {
        if (!is_array($def) || ($def['type'] ?? '') !== 'hero' || !empty($def['custom'])) return false;
        $names = array_column((array) ($def['fields'] ?? []), 'name');
        return in_array('title', $names, true) && in_array('image', $names, true);
    }

    /** Felder „Quelle“ und Zuordnung vorn einfügen; eigene Inhalte (Bild, Titel, Dachzeile, Text) nur bei „Eigene Inhalte“ zeigen */
    public static function decorate(array $def): array
    {
        if (!self::supports($def) || in_array('source', array_column((array) $def['fields'], 'name'), true)) return $def;
        $own = ['source' => ['']];
        $fields = array_map(function (array $f) use ($own): array {
            if (!isset(self::MAP[$f['name'] ?? ''])) return $f;
            $c = $f['show_if'] ?? null;
            if (!is_array($c) || !$c) $f['show_if'] = $own;
            elseif (array_is_list($c)) $f['show_if'] = array_map(fn($alt) => (array) $alt + $own, $c);
            else $f['show_if'] = $c + $own;
            return $f;
        }, (array) $def['fields']);
        $def['fields'] = [...self::fields(), ...$fields];
        return $def;
    }

    /** Die zusätzlichen Felder (Feld „source“ nur auf Detailseiten-Vorlagen sichtbar: 'entry_only', Core\Fields) */
    public static function fields(): array
    {
        $entry = ['source' => [self::SOURCE]];
        return [
            ['name' => 'source', 'label' => 'Quelle', 'type' => 'select', 'default' => '', 'entry_only' => true,
                'options' => ['' => 'Eigene Inhalte', self::SOURCE => 'Felder des Eintrags'],
                'help' => '„Felder des Eintrags“: Bild, Titel (H1), Dachzeile und Text kommen auf jeder Detailseite aus dem aufgerufenen Eintrag – mit allen Varianten des Einstiegs. Ohne Bild erscheint die schlichte Variante.'],
            ['name' => 'src_image', 'label' => 'Bild aus', 'type' => 'datafield', 'entry' => true, 'types' => ['media'], 'width' => 'half',
                'special' => ['_none'], 'empty_label' => '– Bildfeld der Tabelle –', 'show_if' => $entry],
            ['name' => 'src_title', 'label' => 'Titel (H1) aus', 'type' => 'datafield', 'entry' => true, 'types' => ['_title', 'text'], 'width' => 'half',
                'empty_label' => '– Titel des Eintrags –', 'show_if' => $entry],
            ['name' => 'src_eyebrow', 'label' => 'Dachzeile aus', 'type' => 'datafield', 'entry' => true, 'types' => ['text', 'select', 'multiselect', 'date', 'datetime'], 'width' => 'half',
                'special' => ['_none', '_chapter', '_when'], 'empty_label' => '– automatisch –', 'show_if' => $entry,
                'help' => 'Automatisch: Kapitelnummer (Wissen), Termin (Kalender) oder Kategorie/Bereich – sonst keine.'],
            ['name' => 'src_text', 'label' => 'Text aus', 'type' => 'datafield', 'entry' => true, 'types' => ['text', 'textarea', 'richtext'], 'width' => 'half',
                'special' => ['_none'], 'empty_label' => '– Beschreibung der Tabelle –', 'show_if' => $entry],
            ['name' => 'src_link', 'label' => 'Button-Link aus (optional)', 'type' => 'datafield', 'entry' => true, 'types' => ['url', 'link'], 'width' => 'half',
                'empty_label' => '– Buttons des Blocks –', 'show_if' => $entry,
                'help' => 'Ist das Feld im Eintrag leer, entfällt der Button.'],
            ['name' => 'src_link_label', 'label' => 'Beschriftung des Buttons', 'type' => 'text', 'max' => 32, 'width' => 'half',
                'placeholder' => 'Mehr erfahren', 'show_if' => ['source' => [self::SOURCE], 'src_link' => ['*']]],
        ];
    }

    /** Standard-Zuordnung einer Tabelle: [blockfeld => quelle] ('' = nichts) */
    public static function defaults(array $t): array
    {
        $field = fn(string $n) => $n !== '' ? Tables::field($t, $n) : null;
        $img = Tables::imageField($t);
        $desc = (string) ($t['settings']['description_field'] ?? '');
        $eyebrow = '';
        if (Knowledge::is($t)) {
            $eyebrow = '_chapter';
        } elseif (Calendar::enabled($t)) {
            $eyebrow = '_when';
        } else {
            foreach (self::EYEBROW_NAMES as $n) {
                if (in_array($field($n)['type'] ?? '', ['select', 'multiselect'], true)) { $eyebrow = $n; break; }
            }
        }
        return [
            'image' => $img !== '' && ($field($img)['type'] ?? '') === 'media' ? $img : '',
            'title' => '_title',
            'eyebrow' => $eyebrow,
            'text' => in_array($field($desc)['type'] ?? '', ['text', 'textarea', 'richtext'], true) ? $desc : '',
        ];
    }

    /** Gewählte Zuordnung aus den Blockdaten (Standard für leere Felder; gelöschte Felder = nichts) */
    public static function mapping(array $t, array $data): array
    {
        $def = self::defaults($t);
        $out = [];
        foreach (self::MAP as $target => $key) {
            $v = (string) ($data[$key] ?? '');
            $out[$target] = $v === '' ? $def[$target] : self::source($t, $v);
        }
        return $out;
    }

    /** „tabelle.feld“ → Quelle in dieser Tabelle ('' = nichts/unbekannt) */
    public static function source(array $t, string $v): string
    {
        $p = $t['handle'] . '.';
        $n = str_starts_with($v, $p) ? substr($v, strlen($p)) : (str_contains($v, '.') ? '' : $v);
        if ($n === '' || $n === '_none') return '';
        if (in_array($n, ['_title', '_chapter', '_when', '_url', 'published_at'], true)) return $n;
        return Tables::field($t, $n) ? $n : '';
    }

    /**
     * Blockdaten mit den Werten des aufgerufenen Eintrags füllen (Theme::makeBlock, nach Feldbindungen und Variante).
     * Ohne Quelle „entry“ oder ohne Eintrag: unverändert.
     */
    public static function apply(array $data, array $def, bool $visible = true): array
    {
        $ctx = app()->entry;
        if (($data['source'] ?? '') !== self::SOURCE || !$ctx || !self::supports($def)) return $data;
        $t = $ctx['table'];
        $e = Exposure::publicEntry($t, $ctx['entry']);
        $types = array_column((array) $def['fields'], 'type', 'name');
        $bind = is_array($data['_bind'] ?? null) ? $data['_bind'] : [];
        foreach (self::mapping($t, $data) as $target => $src) {
            unset($bind[$target]);
            $type = (string) ($types[$target] ?? 'text');
            $data[$target] = $src === '' ? ($type === 'media' ? null : '') : self::value($t, $e, $src, $type);
            if ($src !== '') $bind[$target] = $src;   // Editor: „Aus dem Eintrag“, Detailseite: inline bearbeitbar (EntryEdit::blockAttr)
        }
        if (trim((string) $data['title']) === '') {
            $data['title'] = Entries::title($t, $e);
            $bind['title'] = '_title';
        }
        // Bild nur, wenn es die Datei noch gibt
        $img = (int) ($data['image'] ?? 0);
        if ($img && !Media::find($img)) $img = 0;
        $data['image'] = $img ?: null;
        // Ohne Bild (und ohne Video): schlichte Variante statt leerer Bildfläche
        $variant = (string) ($data['variant'] ?? '');
        if (!$img && empty($data['video']) && in_array($variant, self::IMAGE_VARIANTS, true) && isset($def['variants']['compact'])) $data['variant'] = 'compact';
        // Button aus einem Link-Feld: leer im Eintrag = kein Button
        $link = self::source($t, (string) ($data['src_link'] ?? ''));
        if ($link !== '' && isset($types['button_link'])) {
            $href = trim((string) Entries::bindValue($t, $e, $link, 'link'));
            $data['button_link'] = $href;
            $data['button_label'] = $href === '' ? '' : (trim((string) ($data['src_link_label'] ?? '')) ?: lt('Mehr erfahren'));
        }
        $data['_bind'] = $bind;
        if ($visible && !app()->editing) self::$titled = true;
        return $data;
    }

    /** Wert einer Quelle passend zum Blockfeld (Sonderquellen _chapter, _when als Klartext) */
    public static function value(array $t, array $e, string $src, string $type): mixed
    {
        if ($src === '_chapter') {
            if (!Knowledge::is($t) || !Knowledge::config($t)['numbers']) return '';
            $n = (string) (Knowledge::tree($t)['nodes'][(int) $e['id']]['number'] ?? '');
            return $n !== '' ? lt('Kapitel {n}', ['n' => $n]) : '';
        }
        if ($src === '_when') return Entries::text($t, $e, '_when');
        $v = Entries::bindValue($t, $e, $src, $type);
        return $type === 'media' ? ((int) $v ?: null) : (is_scalar($v) ? trim((string) $v) : '');
    }

    /**
     * Index des Einstiegs mit Quelle „Eintrag“, wenn er der erste sichtbare Block ist (und kein Block daneben steht) – sonst null.
     * @param list<array> $blocks gespeicherte Blöcke der Vorlage
     */
    public static function leadIndex(array $blocks): ?int
    {
        foreach (array_values($blocks) as $i => $b) {
            if (($b['tunes']['section']['visible'] ?? true) === false) continue;
            if (($b['type'] ?? '') !== 'hero' || (($b['data']['source'] ?? '') !== self::SOURCE)) return null;
            if (!self::supports(app()->theme->block('hero'))) return null;
            $next = $blocks[$i + 1] ?? null;
            if (is_array($next) && (string) ($next['tunes']['section']['row'] ?? '') !== '') return null;   // Reihe „nebeneinander“
            return $i;
        }
        return null;
    }

    /**
     * Block für neue Detailseiten-Vorlagen („Kopf wie Seiten“): bevorzugte Variante des Kits, Verlauf als Überlagerung, mittlere Höhe.
     * null, wenn das Kit keinen passenden Einstieg hat.
     */
    public static function templateBlock(array $t): ?array
    {
        $def = app()->theme->block('hero');
        if (!self::supports($def)) return null;
        $variants = array_keys((array) ($def['variants'] ?? []));
        $variant = '';
        foreach (self::PREFERRED as $v) if (in_array($v, $variants, true)) { $variant = $v; break; }
        $data = ['source' => self::SOURCE, 'title' => $t['singular'] ?: $t['name'], 'variant' => $variant !== '' ? $variant : (string) ($variants[0] ?? '')];
        $names = array_column((array) $def['fields'], null, 'name');
        if (isset($names['overlay']['options']['gradient'])) $data['overlay'] = 'gradient';
        if (isset($names['bleed_height']['options']['medium'])) $data['bleed_height'] = 'medium';
        return ['id' => bin2hex(random_bytes(5)), 'type' => 'hero', 'data' => $data];
    }

    /** Nur für Selbsttests: Merker zurücksetzen */
    public static function reset(): void
    {
        self::$titled = false;
    }
}
