<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Mail;

use Core\Mailer;
use Core\Network\Network;
use Core\Network\SystemMail;
use Core\Sites;

/**
 * Wer bekommt technische Warnungen? Eine Regel für alle Systemmeldungen (Netzwerk und Einzelinstallation) – Integrität, Sicherheit,
 * Sicherung, Geplante Aufgaben, technische Health-Fehler, Updates/Lebensende, Mail-Zustellprobleme.
 *
 * Eingestellt unter Netzwerk → Systemmeldungen bzw. (Einzelinstallation) Grundeinstellungen → Systemmeldungen; gespeichert in der
 * Netzwerk-Datenbank (Tabelle network_mail, Schlüssel „alerts“; die Liste „Bestimmte Adressen“ bleibt „system_to“ aus
 * Core\Network\SystemMail). Empfänger-Modus:
 *  - auto (nichts gewählt): Agentur-Kontakt eingetragen → Agentur; sonst Liste eingetragen → Liste; sonst Agentur-Konten (Anmeldung
 *    über den Agentur-Zugang per OpenID Connect, users.idp) → Agentur; sonst wie bisher alle (Netzwerk-)Admins (Rückfall)
 *  - agency: Agentur-Kontakt, sonst die Agentur-Konten; die Admins der Websites (Kunden) bekommen nichts, außer „zusätzlich informieren“
 *  - list: die eingetragenen Adressen · admins: bisheriges Verhalten
 * Je Kategorie: wie oben | agency | list | admins | off, dazu zusätzliche Adressen. Fehlt das Ziel (Agentur ohne Kontakt und Konten,
 * leere Liste), gilt der Rückfall – eine Warnung geht nie ins Leere, solange es Admins gibt.
 *
 * Versand nur über send()/once() (Core\Mailer, Zweck notify, Impressum; Meldungen der ganzen Installation über das Netzwerk-Konto).
 * Eine Fehlerserie = eine Mail: once() merkt sich den Schlüssel bis clear() (bzw. bis zum Ablauf von ttl).
 */
final class Alerts
{
    public const KEY = 'alerts';
    /** Kategorien: Schlüssel → [Bezeichnung, Erklärung, Geltung: install = ganze Installation, site = eine Website] */
    public const CATEGORIES = [
        'integrity' => ['Integrität', 'Unerwartete Änderungen an Dateien (Installation) und Datenbank (Website) – je Fund einmal.', 'install'],
        'security' => ['Sicherheit', 'Auffällige Anmeldeversuche, gesperrte IP-Adressen, Alarme aus dem Sicherheitsprotokoll.', 'install'],
        'backup' => ['Sicherung', 'Fehlgeschlagene Sicherung außer Haus – einmal je Fehlerserie.', 'install'],
        'cron' => ['Geplante Aufgaben', 'Fehler und Zeitüberschreitungen geplanter Aufgaben – einmal je Fehlerserie.', 'install'],
        'health' => ['Technische Fehler', 'Fehler der Systemprüfung (health), z. B. fehlende Schlüssel oder Schreibrechte.', 'install'],
        'updates' => ['Updates und Lebensende', 'Neue Versionen, Sicherheitsupdates, auslaufende Unterstützung von PHP oder Paketen.', 'install'],
        'mail' => ['Mail-Zustellprobleme', 'Anfragen, die nicht per E-Mail zugestellt werden konnten (ohne Inhalte) – höchstens eine Mail je Stunde und Tabelle.', 'site'],
    ];
    public const MODES = ['auto', 'agency', 'list', 'admins'];
    public const ROUTES = ['default', 'agency', 'list', 'admins', 'off'];
    public const EXTRA_MAX = 5;
    /** Fehlermeldung des letzten send() (null = gesendet bzw. niemand) */
    public static ?string $error = null;

    /** Gespeicherte Einstellung (mit Standardwerten) @return array{mode: string, agency_email: string, agency_name: string, also_admins: bool, routes: array<string, string>, extra: array<string, list<string>>} */
    public static function config(): array
    {
        $raw = [];
        try {
            $v = SystemMail::store()?->fetchValue('SELECT v FROM network_mail WHERE k = ?', [self::KEY]);
            $raw = is_string($v) ? (array) json_decode($v, true) : [];
        } catch (\Throwable) {
        }
        return self::normalize($raw);
    }

    /** Werte bereinigen (Speichern, Lesen, Selbsttest) */
    public static function normalize(array $in): array
    {
        $mode = (string) ($in['mode'] ?? 'auto');
        $email = strtolower(trim((string) ($in['agency_email'] ?? '')));
        $routes = $extra = [];
        foreach (array_keys(self::CATEGORIES) as $c) {
            $r = (string) ($in['routes'][$c] ?? 'default');
            if ($r !== 'default' && in_array($r, self::ROUTES, true)) $routes[$c] = $r;
            $x = is_array($in['extra'][$c] ?? null) ? $in['extra'][$c] : SystemMail::parse((string) ($in['extra'][$c] ?? ''))[0];
            $x = array_values(array_unique(array_filter(array_map(fn($a) => strtolower(trim((string) $a)), $x), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL))));
            if ($x) $extra[$c] = array_slice($x, 0, self::EXTRA_MAX);
        }
        return [
            'mode' => in_array($mode, self::MODES, true) ? $mode : 'auto',
            'agency_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            'agency_name' => mb_substr(trim((string) ($in['agency_name'] ?? '')), 0, 80),
            'also_admins' => !empty($in['also_admins']),
            'routes' => $routes,
            'extra' => $extra,
        ];
    }

    /**
     * Speichern aus dem Formular (mode, agency_email, agency_name, also_admins, system_to, route[cat], extra[cat]).
     * Rückgabe: Fehlermeldung oder null.
     */
    public static function save(array $in): ?string
    {
        $email = trim((string) ($in['agency_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return __('Keine gültige E-Mail-Adresse: {list}', ['list' => $email]);
        foreach (array_keys(self::CATEGORIES) as $c) {
            [$ok, $bad] = SystemMail::parse((string) ($in['extra'][$c] ?? ''));
            if ($bad) return __('Keine gültige E-Mail-Adresse: {list}', ['list' => implode(', ', $bad)]);
            if (count($ok) > self::EXTRA_MAX) return __('Höchstens {n} Adressen.', ['n' => self::EXTRA_MAX]);
        }
        $cfg = self::normalize(['mode' => $in['mode'] ?? 'auto', 'agency_email' => $email, 'agency_name' => $in['agency_name'] ?? '',
            'also_admins' => !empty($in['also_admins']), 'routes' => (array) ($in['route'] ?? []), 'extra' => (array) ($in['extra'] ?? [])]);
        if ($cfg['mode'] === 'agency' && $cfg['agency_email'] === '' && !self::agencyAccounts('install')) {
            return __('„Agentur bevorzugt“ braucht einen Agentur-Kontakt oder Agentur-Konten (Agentur-Zugang per OpenID Connect).');
        }
        if (array_key_exists('system_to', $in) && ($err = SystemMail::save((string) $in['system_to']))) return $err;
        $db = SystemMail::store() ?? throw new \RuntimeException(__('Keine Netzwerk-Datenbank.'));
        $db->query('DELETE FROM network_mail WHERE k = ?', [self::KEY]);
        $db->insert('network_mail', ['k' => self::KEY, 'v' => json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        return null;
    }

    // ------------------------------------------------------------------ Regel

    /**
     * Die Regel ohne Datenbank (Selbsttest): wer bekommt eine Meldung der Kategorie?
     * $ctx: agency_accounts (Konten über den Agentur-Zugang), list (Bestimmte Adressen), admins (Rückfall: bisherige Empfänger)
     * @return array{to: list<string>, via: string, mode: string, fallback: bool}  via: agency | list | admins | off
     */
    public static function decide(array $cfg, string $cat, array $ctx): array
    {
        $cfg = self::normalize($cfg);
        $agency = $cfg['agency_email'] !== '' ? [$cfg['agency_email']] : self::clean($ctx['agency_accounts'] ?? []);
        $list = self::clean($ctx['list'] ?? []);
        $admins = self::clean($ctx['admins'] ?? []);
        $mode = self::mode($cfg, $ctx);
        $route = $cfg['routes'][$cat] ?? 'default';
        if ($route === 'off') return ['to' => [], 'via' => 'off', 'mode' => $mode, 'fallback' => false];
        $via = $route === 'default' ? $mode : $route;
        $fallback = false;
        if ($via === 'agency' && !$agency) { $via = $list ? 'list' : 'admins'; $fallback = true; }
        if ($via === 'list' && !$list) { $via = 'admins'; $fallback = true; }
        $to = match ($via) { 'agency' => $agency, 'list' => $list, default => $admins };
        if ($via === 'agency' && $cfg['also_admins']) $to = array_merge($to, $admins);
        $to = array_merge($to, $cfg['extra'][$cat] ?? []);
        return ['to' => self::clean($to), 'via' => $via, 'mode' => $mode, 'fallback' => $fallback];
    }

    /** Wirksamer Modus: gewählt bzw. automatisch (Agentur-Kontakt → Liste → Agentur-Konten → Admins) */
    public static function mode(array $cfg, array $ctx): string
    {
        $cfg = self::normalize($cfg);
        if ($cfg['mode'] !== 'auto') return $cfg['mode'];
        if ($cfg['agency_email'] !== '') return 'agency';
        if (self::clean($ctx['list'] ?? [])) return 'list';
        if (self::clean($ctx['agency_accounts'] ?? [])) return 'agency';
        return 'admins';
    }

    /**
     * Empfänger einer Meldung dieser Installation bzw. Website.
     * $o: scope (install | site; Standard aus der Kategorie), fallback (bisherige Empfänger statt der Admins, z. B. Benachrichtigungs-
     * adresse einer Tabelle) @return array{to: list<string>, via: string, mode: string, fallback: bool}
     */
    public static function resolve(string $cat, array $o = []): array
    {
        $scope = (string) ($o['scope'] ?? (self::CATEGORIES[$cat][2] ?? 'install'));
        return self::decide(self::config(), $cat, ['agency_accounts' => self::agencyAccounts($scope), 'list' => SystemMail::configured(),
            'admins' => isset($o['fallback']) ? (array) $o['fallback'] : self::admins($scope)]);
    }

    /** @return list<string> */
    public static function recipients(string $cat, array $o = []): array
    {
        return self::resolve($cat, $o)['to'];
    }

    /**
     * Rückfall (bisheriges Verhalten): Installation – mit mehreren Websites die aktiven Netzwerk-Konten, sonst alle Konten mit allen
     * Rechten; Website – deren Administration ohne Netzwerk- und Schatten-Konten (mit mehreren Websites; hat sie keine: Netzwerk-Konten).
     * @return list<string>
     */
    public static function admins(string $scope = 'install'): array
    {
        if (!Sites::multi()) return array_column(self::siteAdmins(), 'email');
        if ($scope === 'site') {
            $own = array_column(array_filter(self::siteAdmins(), fn($u) => $u['role'] !== 'network' && empty($u['network_uid'])), 'email');
            if ($own) return $own;
        }
        return SystemMail::networkAdmins() ?: array_column(array_filter(self::siteAdmins(), fn($u) => $u['role'] !== 'network'), 'email');
    }

    /**
     * Agentur-Konten: aktive Konten, die über einen Anbieter (OpenID Connect, users.idp) angemeldet sind und alle Rechte haben –
     * mit mehreren Websites die Netzwerk-Konten des Agentur-Zugangs (bei Website-Meldungen zusätzlich die der Website).
     * @return list<string>
     */
    public static function agencyAccounts(string $scope = 'install'): array
    {
        $out = [];
        if (Sites::multi()) {
            try {
                foreach (Network::accounts() as $a) if (!(int) $a['disabled'] && trim((string) ($a['idp'] ?? '')) !== '') $out[] = (string) $a['email'];
            } catch (\Throwable) {
            }
            if ($scope !== 'site') return self::clean($out);
        }
        foreach (self::siteAdmins() as $u) if ($u['idp'] !== '' && $u['role'] !== 'network') $out[] = $u['email'];
        return self::clean($out);
    }

    /** Aktive Konten mit allen Rechten dieser Website (und Netzwerk-Schatten) @return list<array{email: string, role: string, network_uid: mixed, idp: string}> */
    private static function siteAdmins(): array
    {
        try {
            $roles = array_keys(array_filter(\Core\Permissions::roles(), fn($r) => in_array('*', (array) $r['permissions'], true)));
            $roles[] = 'network';
            $rows = app()->db->fetchAll('SELECT email, role, network_uid, idp FROM users WHERE disabled = 0 AND role IN (' . implode(',', array_fill(0, count($roles), '?')) . ') ORDER BY email', $roles);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $u) {
            $out[] = ['email' => strtolower((string) $u['email']), 'role' => (string) $u['role'], 'network_uid' => $u['network_uid'], 'idp' => trim((string) ($u['idp'] ?? ''))];
        }
        // Einzelinstallation: nur echte Rollen mit allen Rechten (wie bisher) – keine Netzwerk-Rolle
        return Sites::multi() ? $out : array_values(array_filter($out, fn($u) => $u['role'] !== 'network'));
    }

    /** @param array<mixed> $list @return list<string> */
    private static function clean(array $list): array
    {
        $out = [];
        foreach ($list as $a) {
            $a = strtolower(trim((string) $a));
            if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) $out[$a] = $a;
        }
        return array_values($out);
    }

    // ------------------------------------------------------------------ Versand

    /**
     * Meldung senden – an die Empfänger der Kategorie, eine Mail für alle (Entdoppelung der Adressen), mit Hinweis, wo man die
     * Empfänger festlegt. $o wie resolve(). Rückgabe: Anzahl Empfänger (0 = niemand/Fehler).
     */
    public static function send(string $cat, string $subject, string $text, array $o = []): int
    {
        if (!isset(self::CATEGORIES[$cat])) throw new \InvalidArgumentException('Unbekannte Kategorie: ' . $cat);
        self::$error = null;
        $r = self::resolve($cat, $o);
        if (!$r['to']) return 0;
        $scope = (string) ($o['scope'] ?? self::CATEGORIES[$cat][2]);
        $net = Sites::multi() && ($scope === 'install' || $r['via'] !== 'admins');
        $foot = __('Systemmeldung „{cat}“. Wer diese Meldungen bekommt, legt die Administration fest: {where} → Systemmeldungen.',
            ['cat' => __(self::CATEGORIES[$cat][0]), 'where' => Sites::multi() ? __('Netzwerk') : __('Grundeinstellungen')]);
        $err = Mailer::send($subject, rtrim($text) . "\n\n—\n" . $foot, $r['to'], ['purpose' => 'notify', 'network' => $net]);
        self::$error = $err;
        return $err === null ? count($r['to']) : 0;
    }

    /**
     * Wie send(), aber je Schlüssel nur einmal, bis clear() ihn zurücksetzt (Fehlerserie) bzw. – mit $ttl in Sekunden – höchstens
     * einmal in dieser Zeit. Merkliste in der Netzwerk-Datenbank. Rückgabe wie send().
     */
    public static function once(string $cat, string $key, string $subject, string $text, array $o = [], int $ttl = 0): int
    {
        $k = self::marker($key);
        $db = SystemMail::store();
        $at = $db?->fetchValue('SELECT v FROM network_mail WHERE k = ?', [$k]);
        if ($at !== null && $at !== false && ($ttl <= 0 || time() - (int) $at < $ttl)) return 0;
        $n = self::send($cat, $subject, $text, $o);
        if ($db && $n > 0) {
            $db->query('DELETE FROM network_mail WHERE k = ?', [$k]);
            $db->insert('network_mail', ['k' => $k, 'v' => (string) time()]);
        }
        return $n;
    }

    /** Merker von once() zurücksetzen (Fehler behoben) */
    public static function clear(string $key): void
    {
        try {
            SystemMail::store()?->query('DELETE FROM network_mail WHERE k = ?', [self::marker($key)]);
        } catch (\Throwable) {
        }
    }

    /** Gleicher Schlüssel wie früher in Core\Network\SystemMail – vorhandene Merker gelten weiter */
    private static function marker(string $key): string
    {
        return 'notice:' . substr(hash('sha256', $key), 0, 32);
    }

    /**
     * Testnachricht einer Kategorie an ihre wirksamen Empfänger (wie im Ernstfall, ohne once()).
     * @return array{0: string, 1: string} Meldungstyp und Text
     */
    public static function test(string $cat): array
    {
        if (!isset(self::CATEGORIES[$cat])) return ['error', __('Unbekannte Kategorie.')];
        $o = $cat === 'mail' ? ['fallback' => self::mailFallback()] : [];
        $r = self::resolve($cat, $o);
        if (!$r['to']) return ['error', $r['via'] === 'off' ? __('Diese Kategorie ist ausgeschaltet – es geht keine Mail hinaus.') : __('Keine Empfänger.')];
        $label = __(self::CATEGORIES[$cat][0]);
        $n = self::send($cat, __('Testnachricht: Systemmeldung „{cat}“', ['cat' => $label]),
            __('Diese Testnachricht zeigt, wer Systemmeldungen der Kategorie „{cat}“ bekommt: {to}.', ['cat' => $label, 'to' => implode(', ', $r['to'])])
            . "\n\n" . __('Gesendet von {site}.', ['site' => site()->label()]), $o);
        if ($n === 0) return ['error', __('Nicht zugestellt.') . (self::$error !== null ? ' ' . self::$error : '')];
        return match (Mailer::$outcome) {
            'redirected' => ['success', __('Testnachricht an die Test-Umleitung gesendet (statt an {to}).', ['to' => implode(', ', $r['to'])])],
            'logged' => ['info', __('Testumgebung: nicht versendet, nur protokolliert (an {to}).', ['to' => implode(', ', $r['to'])])],
            'dumped' => ['success', __('Testnachricht an {to} als Datei abgelegt (lokaler Test, mail_dump).', ['to' => implode(', ', $r['to'])])],
            default => ['success', __('Testnachricht an {to} übergeben.', ['to' => implode(', ', $r['to'])])],
        };
    }

    // ------------------------------------------------------------------ Anzeige

    /** Empfänger-Art in Worten ($cat: Kategorie bzw. „site“ – Rückfall bei „Mail-Zustellprobleme“ ist die Redaktion, bei Website-Meldungen die Administration der Website) */
    public static function viaText(string $via, ?array $cfg = null, string $cat = ''): string
    {
        $cfg ??= self::config();
        return match (true) {
            $via === 'agency' => $cfg['agency_name'] !== '' ? __('Agentur ({name})', ['name' => $cfg['agency_name']]) : __('Agentur'),
            $via === 'list' => __('Bestimmte Adressen'),
            $via === 'off' => __('aus'),
            $cat === 'mail' => __('Redaktion (Benachrichtigungsadresse der Tabelle bzw. Empfänger für Benachrichtigungen)'),
            Sites::multi() && ($cat === 'site' || (self::CATEGORIES[$cat][2] ?? '') === 'site') => __('Administration der Website'),
            default => Sites::multi() ? __('Alle Netzwerk-Admins') : __('Alle Admins'),
        };
    }

    /** Rückfall der Kategorie „Mail-Zustellprobleme“ für die Anzeige: Empfänger für Benachrichtigungen dieser Website @return list<string> */
    public static function mailFallback(): array
    {
        return self::clean(explode(',', (string) app()->settings->get('sys.mail_to', '')));
    }

    /**
     * Wirksame Empfänger je Kategorie (Seite Systemmeldungen, E-Mail-Übersicht).
     * @return list<array{key: string, label: string, help: string, to: list<string>, via: string, via_text: string, fallback: bool, route: string}>
     */
    public static function overview(): array
    {
        $cfg = self::config();
        $out = [];
        foreach (self::CATEGORIES as $k => [$label, $help]) {
            $r = self::resolve($k, $k === 'mail' ? ['fallback' => self::mailFallback()] : []);
            $out[] = ['key' => $k, 'label' => __($label), 'help' => __($help), 'to' => $r['to'], 'via' => $r['via'], 'via_text' => self::viaText($r['via'], $cfg, $k),
                'fallback' => $r['fallback'], 'route' => $cfg['routes'][$k] ?? 'default'];
        }
        return $out;
    }

    /** Pfad der Einstellungsseite (Netzwerk-Bereich bzw. Grundeinstellungen) */
    public static function page(): string
    {
        return \Core\Network\Area::active() ? '/admin/network/meldungen' : '/admin/system/meldungen';
    }
}
