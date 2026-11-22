<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/**
 * Rollenrechte an Blöcken – EINE Prüfung für alle Schreibwege (Seiten-Editor, Bearbeiten auf der Website, REST-API, MCP, KI,
 * Freigaben, Wiederherstellen, Inhaltsblöcke in Einträgen). Vergleicht den alten mit dem neuen Stand:
 *
 *  - Erlaubte Blöcke (roles.blocks_json, null = alle): neue Blöcke nur von erlaubten Typen innerhalb des Website-Rahmens
 *    (Features::allowsBlock, Grundeinstellungen bzw. config blocks.allow/deny).
 *  - Geschützte Blöcke: gesperrt (Eigenschaft lock an der Wurzel des Block-JSON: {by: 'design', note}) oder von einem Typ, den die
 *    Rolle nicht nutzen darf. Sie bleiben sichtbar und lassen sich verschieben (auch zwischen Spalten eines Layouts), aber weder
 *    ändern noch löschen. Ausgenommen: volle Gestaltung (design.blocks) und Rollen mit allen Rechten.
 *    Ein gesperrtes Layout sperrt seine Spalten mit: Kinder nicht ändern, löschen oder hinausschieben, nichts hinzufügen.
 *  - Gestaltung (Stufen): ohne design.blocks.basic bleiben Variante und Abschnitts-Optionen (DESIGN_TUNES) unverändert bzw. bei
 *    neuen Blöcken auf dem Standard. Sperren setzen/entfernen nur mit design.blocks – sonst bleibt die Sperre wie vorher (still).
 *
 * Aufruf: Pages::saveDraft (alle Seiten-Schreibwege), CmsService::pageCreate (Seite mit Blöcken per API/MCP), Entries::save
 * (Feldtyp „Inhaltsblöcke“). Handelnde Rollen: die angemeldete Person (auch „Als Rolle ansehen“) und – falls abweichend – das
 * Konto hinter $userId (Token-Besitzer, Einreichende einer KI-Änderung). Ohne Person (Kommandozeile, Zeitplan, Abgleich): keine Prüfung.
 * Abgelehnt wird mit Core\BlockDenied (HTTP 403) – nichts wird gespeichert.
 */
final class BlockGuard
{
    /** Abschnitts-Optionen, die als Gestaltung gelten – Anker, Sichtbarkeit, Navigation, Suche und Glossar sind Inhalt */
    public const DESIGN_TUNES = ['background', 'spaceTop', 'spaceBottom', 'divider', 'height', 'bgImage', 'overlay', 'align', 'reveal'];
    public const NOTE_MAX = 120;

    /** Stufe der Gestaltung: full (design.blocks oder alle Rechte) | basic (design.blocks.basic) | none */
    public static function level(?array $role): string
    {
        if (!$role) return 'none';
        if (in_array('*', (array) ($role['permissions'] ?? []), true) || Permissions::allows($role, 'design.blocks')) return 'full';
        return Permissions::allows($role, 'design.blocks.basic') ? 'basic' : 'none';
    }

    /** Sperre bereinigen (Pages::sanitizeBlocks): null oder {by: 'design', note} */
    public static function cleanLock(mixed $l): ?array
    {
        if ($l === true) $l = [];
        if (!is_array($l)) return null;
        return ['by' => 'design', 'note' => mb_substr(trim(strip_tags((string) ($l['note'] ?? ''))), 0, self::NOTE_MAX)];
    }

    /** Warum darf die Rolle den (bestehenden) Block nicht ändern? 'lock' | 'type' | null */
    public static function protection(?array $role, array $b): ?string
    {
        if (self::level($role) === 'full') return null;
        if (!empty($b['lock'])) return 'lock';
        $list = $role['blocks'] ?? null;
        return is_array($list) && !in_array((string) ($b['type'] ?? ''), $list, true) ? 'type' : null;
    }

    /** Hinweis für Redaktion (Editor, Fehlermeldung) */
    public static function note(?array $b): string
    {
        $n = trim((string) ($b['lock']['note'] ?? ''));
        return $n !== '' ? $n : __('Dieser Block ist von der Agentur gestaltet');
    }

    /** Darf die angemeldete Person den Blocktyp einfügen? Ohne Anmeldung: nur der Rahmen der Website (KI-Kataloge, Auswahl) */
    public static function mayInsert(string $type): bool
    {
        $me = isset(app()->auth) ? app()->auth->user() : null;
        return $me ? Permissions::allowsBlock(app()->auth->role(), $type) : Features::allowsBlock($type);
    }

    /**
     * Blocktypen des Kits für Auswahllisten (Rolle bearbeiten, Blöcke der Website), gruppiert wie in der Blockauswahl:
     * [Gruppe => [typ => ['label', 'icon', 'frame' => im Rahmen der Website, 'config' => per Konfiguration gesperrt]]]
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (app()->theme->blocks() as $type => $b) {
            $type = (string) $type;
            $out[(string) ($b['group'] ?? 'Inhalt')][$type] = ['label' => (string) ($b['label'] ?? $type), 'icon' => (string) ($b['icon'] ?? ''),
                'frame' => Features::allowsBlock($type), 'config' => Features::configBlockLock($type) !== null];
        }
        foreach ($out as &$g) uasort($g, fn($a, $b) => strcasecmp($a['label'], $b['label']));
        unset($g);
        return $out;
    }

    /** Rolle eines Kontos (null = gibt es nicht) */
    public static function roleOfUser(int $userId): ?array
    {
        $key = app()->db->fetchValue('SELECT role FROM users WHERE id = ?', [$userId]);
        return $key === null ? null : Permissions::role((string) $key);
    }

    /**
     * Handelnde Rollen: angemeldete Person (wirksame Rolle) und das Konto $userId, wenn es eine andere Person ist.
     * Unbekanntes Konto → Rolle ohne Rechte (alles geschützt). Leer = niemand handelt (keine Prüfung).
     * @return list<array>
     */
    public static function actingRoles(?int $userId): array
    {
        $out = [];
        $me = isset(app()->auth) ? app()->auth->user() : null;
        if ($me) $out[(int) $me['id']] = app()->auth->role();
        if ($userId && !array_key_exists($userId, $out)) $out[$userId] = self::roleOfUser($userId);
        return array_values(array_map(fn($r) => $r ?? ['key' => '', 'name' => '', 'permissions' => [], 'tables' => [], 'blocks' => [], 'builtin' => false], $out));
    }

    /** Prüfung für alle handelnden Rollen ($old: gespeicherter Stand, roh); Rückgabe: $new mit Sperren wie erlaubt */
    public static function forSave(array $old, array $new, ?int $userId): array
    {
        $roles = self::actingRoles($userId);
        if (!$roles) return $new;
        $keep = Pages::$rejected;   // sanitizeBlocks setzt die Liste zurück – der Aufrufer liest sie evtl. noch
        $old = $old ? Pages::sanitizeBlocks($old) : [];
        Pages::$rejected = $keep;
        foreach ($roles as $role) $new = self::check($old, $new, $role);
        return $new;
    }

    /**
     * Kern der Prüfung (bereinigte Blöcke, $role = handelnde Rolle). Wirft BlockDenied mit allen Gründen.
     * @return array $new – ohne volle Gestaltung mit den Sperren des alten Stands
     */
    public static function check(array $old, array $new, ?array $role): array
    {
        $level = self::level($role);
        $all = $role && in_array('*', (array) ($role['permissions'] ?? []), true);
        $was = self::flat($old);
        if ($level !== 'full') {
            $new = self::mapBlocks($new, function (array $b) use ($was) {
                $prev = $was[(string) ($b['id'] ?? '')] ?? null;
                if ($prev && ($prev['b']['type'] ?? '') === ($b['type'] ?? '') && !empty($prev['b']['lock'])) $b['lock'] = $prev['b']['lock'];
                else unset($b['lock']);
                return $b;
            });
        }
        $now = self::flat($new);
        $label = fn(array $b) => (string) (app()->theme->block((string) ($b['type'] ?? ''))['label'] ?? ($b['type'] ?? '?'));
        $problems = [];
        foreach ($was as $id => $x) {
            $why = self::protection($role, $x['b']);
            // Gesperrtes Layout: seine Spalten sind mitgesperrt – Kinder nicht ändern, nicht löschen, nicht hinausschieben
            $lay = $x['parent'] !== null && self::protection($role, $was[$x['parent']]['b'] ?? []) === 'lock' ? $was[$x['parent']]['b'] : null;
            if ($why === null && $lay) {
                $why = 'lock';
                if (isset($now[$id]) && $now[$id]['parent'] !== $x['parent']) $problems[] = __('„{label}“ liegt in einem gesperrten Layout – verschieben nur innerhalb des Layouts', ['label' => $label($x['b'])]);
            }
            if ($why === null) continue;
            $msg = $why === 'lock' ? __('„{label}“ ist gesperrt ({note})', ['label' => $label($x['b']), 'note' => self::note($lay && empty($x['b']['lock']) ? $lay : $x['b'])])
                : __('„{label}“ darf Ihre Rolle nicht bearbeiten', ['label' => $label($x['b'])]);
            if (!isset($now[$id])) $problems[] = $msg . ' – ' . __('löschen nicht möglich');
            elseif (self::content($now[$id]['b']) !== self::content($x['b'])) $problems[] = $msg . ' – ' . __('ändern nicht möglich, nur verschieben');
        }
        foreach ($now as $id => $x) {
            $b = $x['b'];
            $prev = $was[$id] ?? null;
            if ($prev && ($prev['b']['type'] ?? '') !== ($b['type'] ?? '')) $prev = null;   // andere Art unter alter ID = neuer Block
            // In die Spalten eines gesperrten Layouts einfügen bzw. hineinschieben: nur volle Gestaltung
            $into = $x['parent'] !== null ? ($was[$x['parent']]['b'] ?? null) : null;
            if ($into && (!$prev || $prev['parent'] !== $x['parent']) && self::protection($role, $into) === 'lock') {
                $problems[] = __('„{label}“: Die Spalten von „{layout}“ sind gesperrt ({note})', ['label' => $label($b), 'layout' => $label($into), 'note' => self::note($into)]);
                continue;
            }
            if (!$prev) {
                if (!$all && !Permissions::allowsBlock($role, (string) $b['type'])) {
                    $problems[] = __('„{label}“ darf Ihre Rolle nicht einfügen', ['label' => $label($b)]);
                    continue;
                }
                if ($level === 'none' && self::design($b, $x['nested']) !== self::design(self::fresh($b, $x['nested']), $x['nested'])) {
                    $problems[] = __('„{label}“: Gestaltung (Variante, Hintergrund, Abstände) darf Ihre Rolle nicht festlegen', ['label' => $label($b)]);
                }
                continue;
            }
            if ($level === 'none' && self::protection($role, $prev['b']) === null
                && self::design($b, $x['nested']) !== self::design($prev['b'], $prev['nested'])) {
                $problems[] = __('„{label}“: Gestaltung (Variante, Hintergrund, Abstände) darf Ihre Rolle nicht ändern', ['label' => $label($b)]);
            }
        }
        if ($problems) throw new BlockDenied(array_values(array_unique($problems)));
        return $new;
    }

    /** Alle Blöcke einschließlich der Kinder von Layouts: [id => ['b' => Block, 'nested' => bool, 'parent' => ID des Layouts|null]] */
    private static function flat(array $blocks, bool $nested = false, array &$out = [], ?string $parent = null): array
    {
        foreach ($blocks as $b) {
            if (!is_array($b) || ($b['id'] ?? '') === '') continue;
            $out[(string) $b['id']] = ['b' => $b, 'nested' => $nested, 'parent' => $parent];
            if (($b['type'] ?? '') === Layout::TYPE) {
                foreach ((array) ($b['data']['columns'] ?? []) as $c) self::flat((array) ($c['blocks'] ?? []), true, $out, (string) $b['id']);
            }
        }
        return $out;
    }

    /** $fn auf jeden Block anwenden (auch in Spalten eines Layouts) */
    private static function mapBlocks(array $blocks, callable $fn): array
    {
        foreach ($blocks as $i => $b) {
            if (!is_array($b)) continue;
            $b = $fn($b);
            if (($b['type'] ?? '') === Layout::TYPE && is_array($b['data']['columns'] ?? null)) {
                foreach ($b['data']['columns'] as $ci => $c) {
                    if (is_array($c['blocks'] ?? null)) $b['data']['columns'][$ci]['blocks'] = self::mapBlocks($c['blocks'], $fn);
                }
            }
            $blocks[$i] = $b;
        }
        return $blocks;
    }

    /** Inhalt eines Blocks ohne die Kinder (Layout) – Grundlage für „unverändert“ */
    private static function content(array $b): string
    {
        $data = (array) ($b['data'] ?? []);
        if (($b['type'] ?? '') === Layout::TYPE) unset($data['columns']);
        return (string) json_encode([(string) ($b['type'] ?? ''), $data, (array) ($b['tunes']['section'] ?? []), $b['lock'] ?? null]);
    }

    /** Gestaltungs-Werte: Variante + Abschnitts-Optionen (in einer Spalte nur die eigene Fläche) */
    private static function design(array $b, bool $nested): string
    {
        $t = (array) ($b['tunes']['section'] ?? []);
        $keys = $nested ? ['background'] : self::DESIGN_TUNES;
        return (string) json_encode([(string) ($b['data']['variant'] ?? ''), array_map(fn($k) => $t[$k] ?? null, $keys)]);
    }

    /** Derselbe Block mit Standard-Gestaltung (wie ein neu eingefügter) */
    private static function fresh(array $b, bool $nested): array
    {
        $theme = app()->theme;
        $def = $theme->block((string) $b['type']) ?? [];
        if (!empty($def['variants'])) {
            $allowed = $nested && is_array($nv = $theme->nestableVariants((string) $b['type'])) ? array_flip($nv) : $def['variants'];
            $b['data']['variant'] = (string) array_key_first($allowed);
        }
        $b['tunes']['section'] = $nested ? Layout::childTunes([], $theme) : $theme->sanitizeTunes([], $def);
        return $b;
    }
}
