<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/**
 * Weitere Links im Fußbereich (unter „Seiten“, neben Pages::menu(false)) – z. B. „News abonnieren“, „Presse“, „Downloads“.
 *
 * Zwei Quellen, in dieser Reihenfolge:
 *  1. Liste „Weitere Links im Fußbereich“ (Website → Darstellung, Einstellung footer_more_links, je Sprache): Beschriftung +
 *     Link aus der Linkauswahl (page:ID, entry:…, media:…, Adresse). Reihenfolge wie in der Liste (sortierbar).
 *  2. Seiten mit dem Schalter „Im Fußbereich zeigen“ (Seiteneigenschaften, Spalte pages.footer) in Baumreihenfolge – die
 *     Beschriftung ist die „Beschriftung im Menü“ bzw. der Titel, die Adresse wandert beim Verschieben mit.
 *
 * Nur veröffentlichte, für Besucher erreichbare Seiten (nicht geschützt, Core\PageAccess); page:ID in der Sprache der Seite.
 * Doppelte Ziele erscheinen einmal; Seiten, die schon im Menü des Fußbereichs stehen, fallen weg ($skipIds).
 * Leer → []: Kits geben dann genau das bisherige Markup aus. Helfer im Kit: footer_nav_links(array_column($pages, 'id')).
 */
final class FooterLinks
{
    /** Einstellung der Liste (Repeater mit label + link) */
    public const SETTING = 'footer_more_links';

    /** Felder für Website → Darstellung (im Kit: ...\Core\FooterLinks::settingsFields() in die Gruppe der Einstellungen) */
    public static function settingsFields(): array
    {
        $t = fn(string $s): string => $s;   // Texte übersetzt die Verwaltung über lang/en.php
        return [
            ['name' => self::SETTING, 'label' => $t('Weitere Links im Fußbereich'), 'type' => 'repeater', 'item_label' => $t('Link'), 'title_field' => 'label',
                'translate' => true, 'add_label' => $t('Link hinzufügen'),
                'help' => $t('Erscheinen im Fußbereich unter „Seiten“ – nach den Seiten des Hauptmenüs, z. B. „News abonnieren“ oder „Presse“. Reihenfolge mit den Pfeilen. Einzelne Seiten lassen sich auch in den Seiteneigenschaften mit „Im Fußbereich zeigen“ aufnehmen; die Liste steht dann zuerst.'),
                'fields' => [
                    ['name' => 'label', 'label' => $t('Beschriftung'), 'type' => 'text', 'required' => true, 'max' => 40, 'width' => 'half'],
                    ['name' => 'link', 'label' => $t('Link'), 'type' => 'link', 'required' => true, 'width' => 'half',
                        'help' => $t('Seite der Website auswählen oder eine Adresse eingeben.')],
                ]],
        ];
    }

    /**
     * Einträge für den Fußbereich.
     * @param list<int> $skipIds Seiten, die schon im Fußbereich stehen (z. B. array_column(Pages::menu(false), 'id'))
     * @return list<array{label: string, href: string, current: bool, external: bool, id: ?int}>
     */
    public static function items(array $skipIds = []): array
    {
        $out = [];
        $seen = [];   // Adressen (doppelte Ziele nur einmal)
        $menu = array_flip(array_filter($skipIds, 'is_int'));   // schon im Menü des Fußbereichs
        $ids = [];                                                // schon ausgegebene Seiten
        $cur = (int) (app()->currentPage['id'] ?? 0);
        $add = function (string $label, string $href, ?int $id) use (&$out, &$seen, $cur): void {
            if ($label === '' || $href === '' || $href === '#' || isset($seen[$href])) return;
            $seen[$href] = true;
            $out[] = ['label' => $label, 'href' => $href, 'current' => $id !== null && $id === $cur, 'external' => is_external($href), 'id' => $id];
        };
        // 1. Liste aus den Einstellungen
        foreach ((array) setting(self::SETTING, []) as $row) {
            if (!is_array($row)) continue;
            $label = trim((string) ($row['label'] ?? ''));
            $link = trim((string) ($row['link'] ?? ''));
            if ($label === '' || $link === '') continue;
            $id = null;
            if (preg_match('~^page:(\d+)(#[\w-]{1,80})?$~', $link, $m)) {
                $p = self::visible(Pages::find((int) $m[1]));
                if (!$p) continue;   // gelöscht, offline oder geschützt: Link fällt weg statt ins Leere zu zeigen
                $id = (int) $p['id'];
                $anchor = $m[2] ?? '';
                if ($anchor === '' && isset($menu[$id])) continue;   // steht schon unter „Seiten“
                $href = Pages::url($p) . $anchor;
                if ($anchor === '') $ids[$id] = true;
            } else {
                $href = link_href($link);
            }
            $add($label, $href, $id);
        }
        // 2. Seiten mit „Im Fußbereich zeigen“
        foreach (self::flaggedPages() as $p) {
            $id = (int) $p['id'];
            if (isset($ids[$id]) || isset($menu[$id])) continue;
            $ids[$id] = true;
            $add((string) (($p['nav_title'] ?? '') ?: $p['title']), Pages::url($p), $id);
        }
        return $out;
    }

    /** Veröffentlichte Seiten mit „Im Fußbereich zeigen“ in der aktuellen Sprache, in Baumreihenfolge */
    public static function flaggedPages(): array
    {
        $multi = Lang::multi();
        try {
            $rows = app()->db->fetchAll("SELECT * FROM pages WHERE footer = 1 AND type = 'page' AND status = 'published'"
                . ($multi ? ' AND ' . Lang::sql() : ''), $multi ? [Lang::current()] : []);
        } catch (\PDOException) {
            return [];   // Spalte fehlt noch (vor migrate) – Fußbereich wie bisher
        }
        $rows = array_values(array_filter($rows, fn(array $p) => app()->auth->check() || !PageAccess::restricted($p)));
        if (count($rows) < 2) return $rows;
        $pos = array_flip(array_map(fn(array $p) => (int) $p['id'], Pages::flat()));
        usort($rows, fn(array $a, array $b) => ($pos[(int) $a['id']] ?? PHP_INT_MAX) <=> ($pos[(int) $b['id']] ?? PHP_INT_MAX));
        return $rows;
    }

    /** Seite in der Sprache der Anfrage, wenn veröffentlicht und für Besucher erreichbar – sonst null */
    private static function visible(?array $p): ?array
    {
        if (!$p || ($p['type'] ?? 'page') !== 'page') return null;
        if (Lang::multi()) $p = Pages::translations($p)[Lang::current()] ?? $p;
        if ($p['status'] !== 'published') return null;
        if (!app()->auth->check() && PageAccess::restricted($p)) return null;
        return $p;
    }

    /**
     * Listeneinträge als <li>…</li> – gleiches Markup wie die Seiten-Links der Kits (aria-current, neuer Tab mit Hinweis).
     * $liClass/$aClass optional für Kits mit eigenen Klassen.
     */
    public static function html(array $items, string $liClass = '', string $aClass = ''): string
    {
        $h = '';
        foreach ($items as $l) {
            $h .= '<li' . ($liClass !== '' ? ' class="' . e($liClass) . '"' : '') . '><a href="' . e($l['href']) . '"'
                . ($aClass !== '' ? ' class="' . e($aClass) . '"' : '')
                . ($l['current'] ? ' aria-current="page"' : '')
                . ($l['external'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($l['label'])
                . ($l['external'] ? '<span class="sr-only"> ' . e(lt('(öffnet in neuem Tab)')) . '</span>' : '') . '</a></li>';
        }
        return $h;
    }
}
