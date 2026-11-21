<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

use Core\Data\Entries;
use Core\Data\Kinds;
use Core\Data\Tables;

/**
 * Zeilenmenü der Verwaltung – EINE Bedienung für alle Listen (Datenlisten, Kalender, Adressbuch, Seitenbaum, Medien, Entwürfe):
 * Knopf „⋯“ je Zeile/Karte/Knoten, dasselbe Menü per Rechtsklick und per Tastatur (Umschalt+F10 / Menütaste), auf dem Telefon
 * als Blatt von unten. Gleiche Reihenfolge und Symbole überall (ORDER/ICONS; JavaScript: resources/js/_rowmenu.js).
 *
 * Ohne JavaScript: Popover (popovertarget) – jeder Punkt ist ein Link oder ein eigenes Formular (POST mit CSRF).
 *
 *   echo RowMenu::render('pg-12', 'Über uns', [
 *       ['key' => 'open', 'label' => __('Bearbeiten'), 'href' => url('/admin/pages/12')],
 *       ['key' => 'duplicate', 'label' => __('Duplizieren'), 'post' => url('/admin/pages/12/duplicate')],
 *       ['key' => 'delete', 'label' => __('Löschen'), 'post' => url('/admin/pages/12/delete'), 'confirm' => __('„{title}“ endgültig löschen?', ['title' => 'Über uns'])],
 *   ]);
 *   <tr data-rowmenu="pg-12"> … </tr>          Rechtsklick/Umschalt+F10 in der Zeile öffnet dasselbe Menü (RowMenu::host())
 *
 * Punkt: key (ORDER), label, icon (sonst ICONS[key]), danger, title, und genau eine Art:
 *   href (+ target, download)  ·  post (+ fields, confirm)  ·  attrs (Knopf mit eigenen Attributen, z. B. Versionen, formaction)
 *   copy => Adresse (Link kopieren; ohne JavaScript ein Link auf die Adresse).
 * Erweiterungen: Slot tableActions['row'] (Datenlisten) bzw. Filter „rowmenu.actions“ ([label, href, icon] je Art/Ziel).
 */
final class RowMenu
{
    /** Feste Reihenfolge aller Zeilenmenüs (unbekannte Schlüssel = Erweiterungen, stehen vor „Löschen“) */
    public const ORDER = ['open', 'settings', 'view', 'preview', 'child', 'duplicate', 'move', 'menu', 'publish', 'versions', 'copy', 'export', 'task', 'ext', 'delete'];

    /** Symbol je Aktion (Icon-Satz, Core\Icons) */
    public const ICONS = [
        'open' => 'pencil-simple', 'settings' => 'gear-six', 'view' => 'arrow-square-out', 'preview' => 'eye', 'child' => 'file-plus',
        'duplicate' => 'copy', 'move' => 'arrows-down-up', 'menu' => 'list', 'publish' => 'globe', 'unpublish' => 'eye-slash',
        'versions' => 'clock-counter-clockwise', 'copy' => 'link', 'export' => 'export', 'task' => 'list-checks', 'ext' => 'puzzle-piece',
        'delete' => 'trash',
    ];

    /** Punkte nach ORDER sortieren (stabil); unbekannte Schlüssel wie „ext“ */
    public static function sort(array $items): array
    {
        $rank = array_flip(self::ORDER);
        $i = 0;
        $items = array_map(function ($it) use (&$i, $rank) { $it['_n'] = $i++; $it['_r'] = $rank[$it['key'] ?? 'ext'] ?? $rank['ext']; return $it; }, array_values(array_filter($items)));
        usort($items, fn($a, $b) => [$a['_r'], $a['_n']] <=> [$b['_r'], $b['_n']]);
        return array_map(function ($it) { unset($it['_n'], $it['_r']); return $it; }, $items);
    }

    /** Attribut für Zeile/Karte/Knoten: Rechtsklick und Umschalt+F10 öffnen das Menü $id */
    public static function host(string $id): string
    {
        return ' data-rowmenu="' . e($id) . '"';
    }

    /** Knopf „⋯“ + Menü */
    public static function render(string $id, string $title, array $items, array $o = []): string
    {
        if (!$items) return '';
        return self::button($id, $title, $o) . self::pop($id, $title, $items);
    }

    public static function button(string $id, string $title, array $o = []): string
    {
        return '<button type="button" class="rm-btn' . (!empty($o['class']) ? ' ' . e((string) $o['class']) : '') . '" popovertarget="' . e($id) . '" aria-haspopup="menu"'
            . ' aria-label="' . e(__('Weitere Aktionen: {title}', ['title' => $title])) . '" title="' . e(__('Weitere Aktionen')) . '">' . icon('dots-three') . '</button>';
    }

    /** Nur das Menü (z. B. Termin im Kalender: Rechtsklick/Umschalt+F10 am Termin, ohne eigenen Knopf) */
    public static function pop(string $id, string $title, array $items): string
    {
        $h = '';
        $prevDanger = null;
        foreach (self::sort($items) as $it) {
            $danger = !empty($it['danger']) || ($it['key'] ?? '') === 'delete';
            if ($danger && $prevDanger === false) $h .= '<hr role="separator">';
            $prevDanger = $danger;
            $h .= self::item($it, $danger);
        }
        if ($h === '') return '';
        return '<div class="rm-pop" id="' . e($id) . '" popover role="menu" aria-label="' . e($title) . '">'
            . '<p class="rm-pop__title" aria-hidden="true">' . e($title) . '</p>' . $h . '</div>';
    }

    private static function item(array $it, bool $danger): string
    {
        $key = (string) ($it['key'] ?? 'ext');
        $cls = 'rm-item' . ($danger ? ' is-danger' : '');
        $ico = icon((string) (($it['icon'] ?? '') !== '' ? $it['icon'] : (self::ICONS[$key] ?? 'circle')));
        $label = $ico . '<span>' . e((string) $it['label']) . '</span>' . (!empty($it['keys']) ? '<kbd aria-hidden="true">' . e((string) $it['keys']) . '</kbd>' : '');
        $title = !empty($it['title']) ? ' title="' . e((string) $it['title']) . '"' : '';
        $data = ' data-rm-key="' . e($key) . '"';
        if (isset($it['copy'])) {
            return '<a class="' . $cls . '" role="menuitem" href="' . e((string) $it['copy']) . '" data-rm-copy="' . e((string) $it['copy']) . '"' . $data . $title . '>' . $label . '</a>';
        }
        if (isset($it['href'])) {
            return '<a class="' . $cls . '" role="menuitem" href="' . e((string) $it['href']) . '"' . $data . $title
                . (!empty($it['target']) ? ' target="' . e((string) $it['target']) . '" rel="noopener"' : '') . (!empty($it['download']) ? ' download' : '')
                . (isset($it['attrs']) ? self::attrs($it['attrs']) : '') . '>' . $label . '</a>';
        }
        if (isset($it['post'])) {
            $fields = '';
            foreach ((array) ($it['fields'] ?? []) as $k => $v) {
                foreach ((array) $v as $vv) $fields .= '<input type="hidden" name="' . e((string) $k) . (is_array($v) ? '[]' : '') . '" value="' . e((string) $vv) . '">';
            }
            return '<form method="post" action="' . e((string) $it['post']) . '" role="none" class="rm-form"' . (!empty($it['confirm']) ? ' data-confirm="' . e((string) $it['confirm']) . '"' : '') . '>'
                . csrf_field() . $fields . '<button type="submit" class="' . $cls . '" role="menuitem"' . $data . $title . '>' . $label . '</button></form>';
        }
        return '<button type="' . (isset($it['attrs']['formaction']) ? 'submit' : 'button') . '" class="' . $cls . '" role="menuitem"' . $data . $title . self::attrs($it['attrs'] ?? []) . '>' . $label . '</button>';
    }

    /** Attribute: Array (escaped) oder fertige Zeichenkette mit führendem Leerzeichen (z. B. VersionsController::attrs()) */
    private static function attrs(array|string $a): string
    {
        if (is_string($a)) return $a;
        $h = '';
        foreach ($a as $k => $v) if ($v !== null && $v !== false) $h .= ' ' . e((string) $k) . ($v === true ? '' : '="' . e((string) $v) . '"');
        return $h;
    }

    /** Aktionen von Erweiterungen (Filter „rowmenu.actions“: Liste [label, href, icon]; nur Pfade dieser Installation) */
    public static function extActions(string $kind, array $ctx): array
    {
        $list = Extensions::filter('rowmenu.actions', [], ['kind' => $kind] + $ctx);
        return array_map(fn($a) => ['key' => 'ext', 'label' => $a['label'], 'href' => url($a['href']), 'icon' => $a['icon'] ?: 'puzzle-piece'], Slots::actions(is_array($list) ? $list : []));
    }

    /**
     * Standardmenü eines Eintrags einer Datentabelle (Datenliste, Kalender, Adressbuch) – jede Aktion nur mit dem passenden Recht.
     * $o: open (Adresse statt Bearbeiten-Seite, z. B. Vorkommen einer Serie), back (Rücksprung für Aufgaben), move (Punkt „Zu Liste …“),
     *     row (Zeilenaktionen von Erweiterungen aus Extensions::tableActions($t)['row']), foreign/readonly (nur ansehen)
     */
    public static function forEntry(array $t, array $e, array $o = []): array
    {
        $h = (string) $t['handle'];
        $id = (int) $e['id'];
        $base = '/admin/data/' . $h;
        $title = Entries::title($t, $e);
        $ro = !empty($o['readonly']) || (Tables::isShared($t) && Data\Shared::isForeign($t, $e));
        $view = Entries::url($t, $e);
        $items = [['key' => 'open', 'label' => $ro ? __('Öffnen') : __('Bearbeiten'), 'href' => (string) ($o['open'] ?? url($base . '/' . $id))]];
        if ($view && $e['status'] === 'published') $items[] = ['key' => 'view', 'label' => __('Auf der Website ansehen'), 'href' => $view, 'target' => '_blank'];
        if (!$ro && can('data.edit', $h)) {
            $items[] = ['key' => 'duplicate', 'label' => __('Duplizieren'), 'post' => url($base . '/' . $id . '/duplicate'), 'title' => __('Kopie als Entwurf anlegen')];
            if (!empty($o['move'])) $items[] = ['key' => 'move'] + $o['move'];
        }
        if (!$ro && $t['settings']['workflow'] && can('data.publish', $h)) {
            $on = $e['status'] === 'published';
            $items[] = ['key' => 'publish', 'icon' => $on ? self::ICONS['unpublish'] : '', 'label' => $on ? __('Offline nehmen') : (!empty($e['published_at']) ? __('Online stellen') : __('Veröffentlichen')),
                'post' => url($base . '/bulk'), 'fields' => ['action' => $on ? 'draft' : 'publish', 'ids' => [$id]],
                'confirm' => $on ? __('„{title}“ offline nehmen? Besucher sehen den Eintrag dann nicht mehr; der Inhalt bleibt erhalten.', ['title' => $title]) : null];
        }
        if (!$ro) $items[] = ['key' => 'versions', 'label' => __('Versionen'), 'href' => url($base . '/' . $id), 'attrs' => Http\Controllers\Admin\VersionsController::attrs('/admin/api/data/' . $h . '/' . $id . '/versions')];
        $items[] = ['key' => 'copy', 'label' => __('Link kopieren'), 'copy' => $view && $e['status'] === 'published' ? $view : admin_url_abs($base . '/' . $id),
            'title' => $view && $e['status'] === 'published' ? __('Adresse auf der Website') : __('Adresse in der Verwaltung')];
        if (Data\AddressBook::enabled($t) && can('data.edit', $h)) {
            $items[] = ['key' => 'export', 'label' => __('vCard exportieren'), 'href' => url($base . '/vcard') . '?ids[]=' . $id, 'download' => true];
        } elseif (Data\Calendar::enabled($t) && can('data.edit', $h)) {
            $items[] = ['key' => 'export', 'label' => __('ICS exportieren'), 'href' => url($base . '/kalender.ics') . '?ids[]=' . $id, 'download' => true];
        }
        if (($u = app()->auth->user()) && Tasks\Tasks::canCreate($u)) {
            $items[] = ['key' => 'task', 'label' => __('Aufgabe anlegen'), 'href' => url('/admin/aufgaben/neu') . '?' . http_build_query(['link' => 'entry:' . $h . ':' . $id, 'back' => (string) ($o['back'] ?? $base)])];
        }
        if (!$ro && !empty($o['row'])) foreach (Extensions::rowActions($o['row'], $id) as $xa) $items[] = ['key' => 'ext', 'label' => $xa['label'], 'href' => url($xa['href']), 'icon' => $xa['icon'] ?: 'puzzle-piece'];
        array_push($items, ...self::extActions('entry', ['table' => $h, 'id' => $id]));
        if (!$ro && can('data.delete', $h)) {
            $items[] = ['key' => 'delete', 'label' => __('Löschen'), 'post' => url($base . '/' . $id . '/delete'), 'confirm' => __('„{title}“ endgültig löschen?', ['title' => $title])];
        }
        return $items;
    }

    /**
     * Aktionsleiste der Mehrfachauswahl (über der Liste): dieselben Aktionen und Symbole wie im Zeilenmenü, für alle gewählten.
     * $items: [key, label, attrs (z. B. data-bulk-action), danger?]; resources/js/_rowmenu.js zeigt sie ab einer Auswahl und
     * bietet sie im Kontextmenü einer gewählten Zeile an (mehr als eine gewählt).
     */
    public static function bar(array $items, array $o = []): string
    {
        $h = '';
        foreach (self::sort($items) as $it) {
            $danger = !empty($it['danger']) || ($it['key'] ?? '') === 'delete';
            $h .= '<button type="' . (isset($it['attrs']['formaction']) || !empty($it['submit']) ? 'submit' : 'button') . '" class="adm-btn adm-btn--small adm-btn--ghost' . ($danger ? ' adm-btn--danger-text' : '') . '" data-rm-bulk data-rm-key="' . e((string) $it['key']) . '"'
                . self::attrs($it['attrs'] ?? []) . '>' . icon((string) (($it['icon'] ?? '') !== '' ? $it['icon'] : (self::ICONS[$it['key']] ?? 'circle'))) . ' <span>' . e((string) $it['label']) . '</span></button>';
        }
        return '<div class="rm-bar' . (!empty($o['class']) ? ' ' . e((string) $o['class']) : '') . '" data-rm-bar role="toolbar" aria-label="' . e(__('Aktionen für die Auswahl')) . '"' . (empty($o['always']) ? ' hidden' : ' data-rm-always') . '>'
            . '<strong class="rm-bar__n" data-rm-selcount aria-live="polite"></strong>' . ($o['before'] ?? '') . $h . ($o['after'] ?? '') . '</div>';
    }

    /** Attribute des Auswahlbereichs (Häkchen [data-rm-check], „Alle“ [data-rm-checkall], Leiste RowMenu::bar) */
    public static function selectAttrs(): string
    {
        return ' data-rm-select data-rm-t1="' . e(__('1 ausgewählt')) . '" data-rm-tn="' . e(__('{n} ausgewählt')) . '"';
    }

    public static function selftest(): array
    {
        $ok = $fail = 0; $errors = [];
        $eq = function (string $name, mixed $got, mixed $want) use (&$ok, &$fail, &$errors) { if ($got === $want) $ok++; else { $fail++; $errors[] = $name . ': ' . var_export($got, true); } };
        $sorted = array_column(self::sort([['key' => 'delete', 'label' => 'D'], ['key' => 'zzz', 'label' => 'X'], ['key' => 'copy', 'label' => 'C'], ['key' => 'open', 'label' => 'O'], ['key' => 'duplicate', 'label' => 'U']]), 'key');
        $eq('Reihenfolge: Öffnen … Löschen, Erweiterungen vor Löschen', $sorted, ['open', 'duplicate', 'copy', 'zzz', 'delete']);
        $h = self::pop('m1', 'T', [['key' => 'delete', 'label' => 'Weg', 'post' => '/x/delete', 'confirm' => 'Sicher?'], ['key' => 'open', 'label' => 'Auf', 'href' => '/x']]);
        $eq('Menü: role=menu, Punkte role=menuitem', substr_count($h, 'role="menuitem"') === 2 && str_contains($h, 'role="menu"'), true);
        $eq('Löschen: eigenes Formular mit CSRF und Rückfrage', str_contains($h, 'name="_csrf"') && str_contains($h, 'data-confirm="Sicher?"'), true);
        $eq('Trennlinie vor Löschen', str_contains($h, '<hr role="separator">'), true);
        $eq('Ohne Punkte kein Menü', self::render('m2', 'T', []), '');
        $eq('Knopf öffnet das Popover', str_contains(self::button('m3', 'T'), 'popovertarget="m3"'), true);
        $eq('Felder als Liste', str_contains(self::pop('m4', 'T', [['key' => 'publish', 'label' => 'P', 'post' => '/b', 'fields' => ['ids' => [5]]]]), 'name="ids[]" value="5"'), true);
        return ['ok' => $ok, 'fail' => $fail, 'errors' => $errors];
    }
}
