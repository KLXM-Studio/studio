<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * Bereichsnavigation der Tabellen (views/data/_nav.php) in zwei Bereichen:
 *  - „Daten“ (Hauptmenü, Redaktion): Einträge pflegen – Liste, Kalender, Adressbuch, Schlagworte, Import, Abonnieren …
 *  - „Datenmodell“ (Einrichtung, Recht data.schema): Tabellen einrichten – Felder & Einstellungen, Detailseiten, Einsetzen,
 *    Listen-Vorlagen, Einstellungsseiten von Funktionen zur Tabelle, neue Tabelle, externe Quellen.
 * Welcher Bereich gilt, ergibt sich aus der aktuellen Ansicht ($cur). Controller übernehmen drill/drillTitle/navSection mit vars().
 */
final class AdminNav
{
    public const MODEL_PATH = '/admin/datenmodell';
    /** Ansichten des Datenmodells; dazu 'page:…' (Einstellungsseiten zur Tabelle, z. B. Glossar) für alle mit data.schema – ohne das Recht
     *  bleiben sie bei den Daten, damit die Redaktion sie weiter erreicht */
    public const MODEL = ['model', 'schema', 'new', 'place', 'templates', 'detail-templates', 'sources'];

    public static function isModel(string $cur): bool
    {
        return in_array($cur, self::MODEL, true) || (str_starts_with($cur, 'page:') && can('data.schema'));
    }

    public static function title(string $cur): string
    {
        return self::isModel($cur) ? __('Datenmodell') : __('Daten');
    }

    /**
     * Variablen für die Ansicht: Bereichsnavigation, Titel und Bereich des Menüs (Datenmodell → „Einrichtung › Datenmodell“ aktuell).
     * @return array{drill: string, drillTitle: string, navSection?: string}
     */
    public static function vars(string $cur, ?array $active): array
    {
        $out = ['drill' => \Core\Theme::capture(ROOT . '/app/Admin/views/data/_nav.php', ['cur' => $cur, 'active' => $active]), 'drillTitle' => self::title($cur)];
        if (self::isModel($cur)) $out['navSection'] = 'datamodel';
        return $out;
    }
}
