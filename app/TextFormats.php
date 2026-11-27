<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/**
 * Textformate des Rich-Texts aus dem Kit – EINE Quelle für die Formatierungsleiste (resources/js/_rte.js, über RichText::clientScript)
 * und die Ausgabe (Core\Sanitizer: erlaubte Klassen je Element). Editor und Sanitizer können so nicht auseinanderlaufen; Klassen
 * eines anderen Kits fallen bei der Ausgabe weg, der Text bleibt.
 *
 * theme.php → 'text' (optional, je Gruppe; Kind-Kits erben je Gruppe per array_replace, false entfernt ein geerbtes Format):
 *   'text' => [
 *     'paragraphs' => ['t-lead' => 'Hervorgehoben', 't-quote' => ['label' => 'Großes Zitat', 'hint' => 'Eingerückt, kursiv']],
 *     'headings'   => ['h-display' => 'Groß (Blickfang)'],          // für jede Überschrift (H2–H4), die Ebene bleibt
 *     'colors'     => ['c-accent' => 'Akzentfarbe', 'c-secondary' => 'Zweite Markenfarbe', 'c-muted' => false],
 *     'lists'      => ['check' => ['label' => 'Häkchen-Liste', 'list' => 'ul'], 'steps' => ['label' => 'Schritte', 'list' => 'ol']],
 *     'links'      => ['btn btn--primary' => 'Button', 'btn btn--secondary' => 'Zweiter Button'],
 *   ]
 * Ohne Angabe gelten die Formate des Kerns (BASE, wie bisher: t-*, c-*, Häkchen-Liste). Nur Klassen, die das Kit-CSS gestaltet.
 */
final class TextFormats
{
    public const GROUPS = ['paragraphs', 'headings', 'colors', 'lists', 'links'];

    /** Klassenname(n): einzelne Klasse; bei Links auch mehrere durch Leerzeichen („btn btn--primary“) */
    private const CLS = '~^[a-z][a-z0-9_-]{0,40}(?: [a-z][a-z0-9_-]{0,40}){0,3}$~';

    private static ?array $memo = null;
    /** Für den Selbsttest: andere Kit-Angabe 'text' statt der des aktiven Kits (null = Kit) */
    private static ?array $override = null;
    private static string $memoKey = '';

    /** Formate des Kerns (Stand vor den Kit-Formaten – bestehende Inhalte bleiben gültig) */
    public static function base(): array
    {
        return [
            'paragraphs' => [
                't-lead' => ['label' => 'Hervorgehoben', 'short' => 'Hervorgeh.', 'hint' => 'Größerer Text, keine Überschrift'],
                't-small' => ['label' => 'Klein', 'hint' => 'Kleingedrucktes, Anmerkungen'],
                't-note' => ['label' => 'Hinweis-Box', 'short' => 'Hinweis', 'hint' => 'Abgesetzter Kasten'],
                't-tip' => ['label' => 'Tipp-Box', 'short' => 'Tipp', 'hint' => 'Grüner Kasten für Tipps'],
                't-warn' => ['label' => 'Achtung-Box', 'short' => 'Achtung', 'hint' => 'Kasten für Warnungen'],
                't-kicker' => ['label' => 'Dachzeile', 'hint' => 'Kleine Zeile über einer Überschrift'],
                't-center' => ['label' => 'Zentriert', 'hint' => 'Mittig gesetzter Absatz'],
            ],
            'headings' => [],
            'colors' => array_combine(array_map(fn($c) => 'c-' . $c, Sanitizer::COLORS), array_map(fn($c) => ['label' => RichText::labels()[$c] ?? $c], Sanitizer::COLORS)),
            'lists' => ['check' => ['label' => 'Häkchen-Liste', 'list' => 'ul']],
            'links' => [],
        ];
    }

    /**
     * Aktive Formate des Kits: [gruppe => [klasse => ['label', 'short', 'hint', 'list']]] – normalisiert, ungültige Einträge entfallen.
     * @return array<string, array<string, array{label: string, short: string, hint: string, list: string}>>
     */
    public static function active(?array $def = null): array
    {
        $def ??= self::$override ?? (array) (app()->theme->def['text'] ?? []);
        $key = md5(serialize($def));
        if (self::$memo !== null && self::$memoKey === $key) return self::$memo;
        self::$memoKey = $key;
        return self::$memo = self::merge(self::base(), $def);
    }

    /** Formate zusammenführen (Kern bzw. Eltern-Kit + Kit), je Gruppe; false entfernt */
    public static function merge(array $base, array $over): array
    {
        $out = [];
        foreach (self::GROUPS as $g) {
            $items = array_replace((array) ($base[$g] ?? []), (array) ($over[$g] ?? []));
            $norm = [];
            foreach ($items as $cls => $v) {
                $cls = trim((string) $cls);
                if ($v === false || $v === null || !preg_match(self::CLS, $cls)) continue;
                if ($g !== 'links' && str_contains($cls, ' ')) continue;   // nur Links dürfen mehrere Klassen tragen
                $v = is_array($v) ? $v : ['label' => (string) $v];
                $label = trim((string) ($v['label'] ?? $cls)) ?: $cls;
                $list = in_array($v['list'] ?? '', ['ul', 'ol', 'both'], true) ? (string) $v['list'] : 'ul';
                $norm[$cls] = ['label' => $label, 'short' => trim((string) ($v['short'] ?? '')) ?: $label, 'hint' => trim((string) ($v['hint'] ?? '')), 'list' => $list];
            }
            $out[$g] = $norm;
        }
        return $out;
    }

    /**
     * Erlaubte Klassen je Element für den Sanitizer (Links: ganze Klassenkombination).
     * @return list<string>
     */
    public static function allowed(string $tag): array
    {
        $f = self::active();
        return match ($tag) {
            'p' => array_keys($f['paragraphs']),
            'h2', 'h3', 'h4' => array_keys($f['headings']),
            'span' => array_keys($f['colors']),
            'ul' => array_keys(array_filter($f['lists'], fn($l) => $l['list'] !== 'ol')),
            'ol' => array_keys(array_filter($f['lists'], fn($l) => $l['list'] !== 'ul')),
            'a' => array_keys($f['links']),
            default => [],
        };
    }

    /** Konfiguration für die Formatierungsleiste: je Gruppe [[klasse, Name, Kurzname, Erläuterung(, Liste)], …] – Texte übersetzt */
    public static function editorConfig(): array
    {
        $out = [];
        foreach (self::active() as $g => $items) {
            $out[$g] = [];
            foreach ($items as $cls => $v) {
                $row = [$cls, __($v['label']), __($v['short']), $v['hint'] !== '' ? __($v['hint']) : ''];
                if ($g === 'lists') $row[] = $v['list'];
                $out[$g][] = $row;
            }
        }
        return $out;
    }

    /** Für Tests: Zwischenspeicher leeren */
    public static function flush(): void
    {
        self::$memo = null;
        self::$memoKey = '';
    }

    /** Für den Selbsttest: Angabe 'text' eines Kits vorgeben (null = wieder das aktive Kit) */
    public static function useDefinition(?array $text): void
    {
        self::$override = $text;
        self::flush();
    }
}
