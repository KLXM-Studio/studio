<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Network;

/**
 * Ablage der Systemmeldungen (Netzwerk-Datenbank, Tabelle network_mail) und die Liste „Bestimmte Adressen“ (Schlüssel system_to).
 * Wer welche Meldung bekommt und der Versand: Core\Mail\Alerts (eine Regel für alle technischen Warnungen).
 */
final class SystemMail
{
    private const TABLE = 'network_mail';
    public const KEY = 'system_to';
    public const MAX = 10;

    /** Netzwerk-Datenbank mit der Tabelle network_mail (null, wenn sie nicht erreichbar ist) */
    public static function store(): ?\Core\Database
    {
        try {
            $db = Network::db();
            $db->pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (k VARCHAR(40) NOT NULL PRIMARY KEY, v TEXT)');
            return $db;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Eingetragene Adressen (leer = nicht eingestellt) @return list<string> */
    public static function configured(): array
    {
        $raw = self::store()?->fetchValue('SELECT v FROM ' . self::TABLE . ' WHERE k = ?', [self::KEY]);
        return self::parse((string) ($raw ?? ''))[0];
    }

    /** Adressen aus Freitext (Komma, Semikolon, Zeilen) – [gültige, ungültige] @return array{0: list<string>, 1: list<string>} */
    public static function parse(string $raw): array
    {
        $ok = $bad = [];
        foreach (preg_split('~[\s,;]+~', strtolower(trim($raw))) ?: [] as $a) {
            if ($a === '') continue;
            if (filter_var($a, FILTER_VALIDATE_EMAIL)) $ok[$a] = $a; else $bad[] = $a;
        }
        return [array_values($ok), $bad];
    }

    /** Speichern; Rückgabe: Fehlermeldung oder null */
    public static function save(string $raw): ?string
    {
        [$ok, $bad] = self::parse($raw);
        if ($bad) return __('Keine gültige E-Mail-Adresse: {list}', ['list' => implode(', ', $bad)]);
        if (count($ok) > self::MAX) return __('Höchstens {n} Adressen.', ['n' => self::MAX]);
        $db = self::store() ?? throw new \RuntimeException(__('Keine Netzwerk-Datenbank.'));
        $db->query('DELETE FROM ' . self::TABLE . ' WHERE k = ?', [self::KEY]);
        if ($ok) $db->insert(self::TABLE, ['k' => self::KEY, 'v' => implode(', ', $ok)]);
        return null;
    }

    /** Aktive Netzwerk-Konten (Rückfall der Systemmeldungen) @return list<string> */
    public static function networkAdmins(): array
    {
        try {
            return array_values(array_map(fn($a) => strtolower((string) $a['email']), array_filter(Network::accounts(), fn($a) => !(int) $a['disabled'])));
        } catch (\Throwable) {
            return [];
        }
    }
}
