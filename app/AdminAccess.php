<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/**
 * Welches Recht braucht ein Bereich der Verwaltung? – für Hilfen, Handbuch und Projekt-Hinweise: Verweise auf Bereiche, die die Rolle
 * nicht öffnen kann, werden nicht als Link gezeigt, sondern mit „(nur Administration)“ gekennzeichnet (markLinks()).
 * Die Zuordnung ist bewusst grob (Pfad-Präfix → Recht); im Zweifel bleibt der Link (die Seite selbst prüft weiter das Recht).
 */
final class AdminAccess
{
    /** Pfad-Präfix (ohne /admin) → Recht; längere Präfixe zuerst prüfen */
    public const AREAS = [
        '/design' => 'design.edit', '/settings' => 'settings.edit', '/system' => 'system.manage', '/users' => 'users.manage',
        '/roles' => 'users.manage', '/network' => '@network', '/api' => 'api.manage', '/blocks' => 'blocks.build',
        '/funktionen' => 'system.features', '/extensions' => 'system.features', '/quellen' => 'sources.manage', '/redirects' => 'redirects.manage',
        '/requests' => 'requests.read', '/eingereicht' => 'review.manage', '/aktionslog' => 'system.manage',
    ];

    /** Recht für einen Verwaltungspfad (/admin/…) – null = kein besonderes Recht bekannt */
    public static function permFor(string $path): ?string
    {
        $p = (string) parse_url($path, PHP_URL_PATH);
        $base = rtrim((string) parse_url(url('/admin'), PHP_URL_PATH), '/');
        if ($base === '' || !str_starts_with($p, $base)) return null;
        $rest = substr($p, strlen($base));
        $best = null;
        foreach (self::AREAS as $pre => $perm) {
            if (($rest === $pre || str_starts_with($rest, $pre . '/')) && ($best === null || strlen($pre) > strlen($best[0]))) $best = [$pre, $perm];
        }
        return $best[1] ?? null;
    }

    /** Darf die angemeldete Rolle den Pfad öffnen? (unbekannte Bereiche: ja) */
    public static function allowed(string $path): bool
    {
        $perm = self::permFor($path);
        if ($perm === '@network') return \Core\Network\Network::isNetworkUser();   // Netzwerk-Verwaltung: Konto, kein Rollenrecht
        return $perm === null || can($perm);
    }

    /** In HTML Links auf nicht erlaubte Verwaltungsbereiche durch Text mit „(nur Administration)“ ersetzen */
    public static function markLinks(string $html): string
    {
        return (string) preg_replace_callback('~<a\b([^>]*)\bhref="([^"]+)"([^>]*)>(.*?)</a>~su', function (array $m): string {
            $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);
            if (!str_contains($href, '/admin/') || self::allowed($href)) return $m[0];
            return '<span class="doc-locked">' . $m[4] . ' <small>(' . e(self::permFor($href) === '@network' ? __('nur Netzwerk-Administration') : __('nur Administration')) . ')</small></span>';
        }, $html);
    }
}
