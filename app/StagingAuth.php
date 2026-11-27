<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/**
 * Zugangsschutz der Testumgebung (HTTP Basic, nur außerhalb von production – App::stagingGate).
 * Quelle: Konfiguration 'staging_auth' => ['user' => …, 'pass' => …] (hat Vorrang, z. B. für Deploy-Vorlagen) oder die Einstellung
 * der Website (Grundeinstellungen → Umgebung → Zugangsschutz). In der Einstellung steht nie das Passwort, nur ein Prüfwert:
 * HMAC-SHA256 mit dem app_key (nicht in der Datenbank) und zufälligem Salz – schnell genug für jede Anfrage, ohne app_key wertlos.
 */
final class StagingAuth
{
    public const KEY = 'sys.staging_auth';

    /** Zugang aus der Konfiguration? ['user' => …, 'pass' => …] oder null */
    public static function fromConfig(): ?array
    {
        $a = (array) app()->config->get('staging_auth', []);
        return !empty($a['user']) && !empty($a['pass']) ? ['user' => (string) $a['user'], 'pass' => (string) $a['pass']] : null;
    }

    /** Zugang aus der Einstellung der Website: ['user', 'salt', 'mac', 'by', 'at'] oder null */
    public static function stored(): ?array
    {
        try {
            $s = (array) app()->settings->get(self::KEY, []);
        } catch (\Throwable) {
            return null;
        }
        return !empty($s['user']) && !empty($s['mac']) && !empty($s['salt']) ? $s : null;
    }

    /** Ist ein Zugangsschutz eingerichtet (wirkt nur außerhalb von production)? */
    public static function configured(): bool
    {
        return self::fromConfig() !== null || self::stored() !== null;
    }

    /** Anmeldedaten prüfen */
    public static function check(string $user, string $pass): bool
    {
        if ($c = self::fromConfig()) return hash_equals($c['user'], $user) && hash_equals($c['pass'], $pass);
        $s = self::stored();
        return $s !== null && hash_equals((string) $s['user'], $user) && hash_equals((string) $s['mac'], self::mac($pass, (string) $s['salt']));
    }

    /** Zugang setzen (Passwort mindestens 8 Zeichen) – Fehlermeldung oder null */
    public static function set(string $user, string $pass, string $by = ''): ?string
    {
        $user = trim($user);
        if ($user === '' || mb_strlen($user) > 60 || str_contains($user, ':')) return __('Benutzername fehlt oder ist ungültig (ohne Doppelpunkt, höchstens 60 Zeichen).');
        if (mb_strlen($pass) < 8 || mb_strlen($pass) > 200) return __('Das Passwort braucht mindestens 8 Zeichen.');
        $salt = bin2hex(random_bytes(16));
        app()->settings->set(self::KEY, ['user' => $user, 'salt' => $salt, 'mac' => self::mac($pass, $salt), 'by' => $by, 'at' => date('c')]);
        return null;
    }

    /** Nur den Benutzernamen ändern geht nicht ohne Passwort (Prüfwert hängt nicht am Namen) – Schutz entfernen */
    public static function clear(): void
    {
        app()->settings->set(self::KEY, []);
    }

    private static function mac(string $pass, string $salt): string
    {
        return hash_hmac('sha256', $pass, 'staging-auth|' . $salt . '|' . (string) app()->config->get('app_key'));
    }
}
