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
 * php bin/console alerts:selftest [--site=…] – Systemmeldungen (Core\Mail\Alerts): Rangfolge der Regel, „Agentur bevorzugt“,
 * Rückfall, Kategorien (aus, umleiten, zusätzlich), Entdoppelung; dann echt gegen die Datenbank – Einzelinstallation bzw. Netzwerk,
 * Agentur-Konten über den Agentur-Zugang, keine Mail an Kunden-Admins im Agentur-Modus, eine Mail je Fehlerserie (once/clear).
 * Alles in Transaktionen (Website- und Netzwerk-Datenbank), Mails als Ablage in einem Spielordner.
 */
final class AlertsSelfTest
{
    public static function run(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails);
        $adm = ['kunde@example.org', 'chefin@example.org'];
        $ctx = fn(array $agency = [], array $list = []) => ['agency_accounts' => $agency, 'list' => $list, 'admins' => $adm];

        // ---------------------------------------------------------- Regel (ohne Datenbank)
        $d = Alerts::decide([], 'integrity', $ctx());
        $eq('Nichts eingerichtet: Rückfall auf die Admins (bisheriges Verhalten)', [$d['via'], $d['to']], ['admins', $adm]);
        $d = Alerts::decide(['agency_email' => 'Technik@Agentur.example'], 'integrity', $ctx());
        $eq('Agentur-Kontakt eingetragen: automatisch Agentur', [$d['mode'], $d['to']], ['agency', ['technik@agentur.example']]);
        $eq('Agentur bevorzugt: keine Mail an Kunden-Admins', array_intersect($d['to'], $adm), []);
        $d = Alerts::decide([], 'backup', $ctx(['hub@agentur.example']));
        $eq('Agentur-Zugang (Konten) eingerichtet: automatisch Agentur', [$d['via'], $d['to']], ['agency', ['hub@agentur.example']]);
        $d = Alerts::decide([], 'cron', $ctx(['hub@agentur.example'], ['liste@example.org']));
        $eq('Rangfolge: eingetragene Liste vor Agentur-Konten', $d['to'], ['liste@example.org']);
        $d = Alerts::decide(['agency_email' => 'technik@agentur.example'], 'cron', $ctx(['hub@agentur.example'], ['liste@example.org']));
        $eq('Rangfolge: Agentur-Kontakt vor Liste', $d['to'], ['technik@agentur.example']);
        $d = Alerts::decide(['mode' => 'agency'], 'cron', $ctx());
        $eq('Agentur gewählt, aber nichts eingerichtet: Rückfall statt ins Leere', [$d['via'], $d['fallback'], $d['to']], ['admins', true, $adm]);
        $d = Alerts::decide(['mode' => 'list'], 'cron', $ctx());
        $eq('Liste gewählt, aber leer: Rückfall', [$d['via'], $d['fallback']], ['admins', true]);
        $d = Alerts::decide(['mode' => 'admins', 'agency_email' => 'technik@agentur.example'], 'cron', $ctx());
        $eq('Alle Admins ausdrücklich gewählt', $d['to'], $adm);
        $d = Alerts::decide(['agency_email' => 'technik@agentur.example', 'also_admins' => true], 'cron', $ctx());
        $eq('Admins zusätzlich informieren', $d['to'], ['technik@agentur.example', ...$adm]);
        $d = Alerts::decide(['agency_email' => 'technik@agentur.example', 'routes' => ['security' => 'off']], 'security', $ctx());
        $eq('Kategorie abgewählt: niemand', [$d['via'], $d['to']], ['off', []]);
        $d = Alerts::decide(['agency_email' => 'technik@agentur.example', 'routes' => ['mail' => 'admins']], 'mail', $ctx());
        $eq('Kategorie umgeleitet (an die Admins)', $d['to'], $adm);
        $d = Alerts::decide(['agency_email' => 'technik@agentur.example', 'extra' => ['backup' => 'TECHNIK@agentur.example, ops@example.net']], 'backup', $ctx());
        $eq('Zusätzliche Adressen, Entdoppelung ohne Groß/klein', $d['to'], ['technik@agentur.example', 'ops@example.net']);
        $n = Alerts::normalize(['mode' => 'quatsch', 'agency_email' => 'kein-mail', 'routes' => ['cron' => 'nirgends', 'x' => 'off'],
            'extra' => ['cron' => array_map(fn($i) => "a$i@example.org", range(1, 9))]]);
        $eq('Bereinigt: Modus, Adresse, Kategorien, höchstens ' . Alerts::EXTRA_MAX . ' Zusatzadressen',
            [$n['mode'], $n['agency_email'], $n['routes'], count($n['extra']['cron'])], ['auto', '', [], Alerts::EXTRA_MAX]);

        // ---------------------------------------------------------- Echt gegen die Datenbank
        $site = app()->db;
        $net = SystemMail::store();
        if (!$net) { $fails[] = 'Keine Netzwerk-Datenbank'; return ['ok' => $ok, 'fails' => $fails]; }
        $same = $net->pdo === $site->pdo;
        $s = app()->settings;
        $prevRedirect = $s->get('sys.mail_redirect');
        $dump = sys_get_temp_dir() . '/klxm-alerts-selftest-' . bin2hex(random_bytes(3));
        $sent = function () use ($dump): array {
            $out = [];
            foreach (glob($dump . '/*.eml') ?: [] as $f) {
                $raw = (string) file_get_contents($f);
                preg_match('~^To: (.+)$~mi', $raw, $m);
                preg_match_all('~[\w.+-]+@[\w.-]+~', (string) ($m[1] ?? ''), $a);
                $out[] = $a[0];
                @unlink($f);
            }
            return $out;
        };
        $site->pdo->beginTransaction();
        if (!$same) $net->pdo->beginTransaction();
        try {
            $s->override(['sys.mail_redirect' => '']);
            Mailer::$testDump = $dump;
            $net->query("DELETE FROM network_mail WHERE k IN ('alerts', 'system_to')");
            $tag = bin2hex(random_bytes(3));
            $kunde = "kunde-$tag@example.org";
            $site->insert('users', ['email' => $kunde, 'name' => 'Erika Musterfrau', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => now()]);
            $multi = Sites::multi();

            // Rückfall: nichts eingerichtet → bisherige Empfänger
            $eq('Ohne Einstellung: automatisch', Alerts::config()['mode'], 'auto');
            if ($multi) {
                $eq('Netzwerk: Rückfall Installation = Netzwerk-Konten', Alerts::recipients('backup'), SystemMail::networkAdmins() ?: Alerts::admins('install'));
                $eq('Netzwerk: Rückfall Website (Datenbank) = Kunden-Admins der Website', in_array($kunde, Alerts::recipients('integrity', ['scope' => 'site']), true), true);
            } else {
                $eq('Einzelinstallation: Rückfall = alle Konten mit allen Rechten', in_array($kunde, Alerts::recipients('backup'), true), true);
            }
            // Agentur-Konto über den Agentur-Zugang (OpenID Connect): automatisch bevorzugt
            $hub = "hub-$tag@agentur.example";
            if ($multi) $net->insert('users', ['email' => $hub, 'name' => 'Agentur', 'password_hash' => \Core\Oidc\Accounts::NO_PASSWORD, 'role' => 'network', 'idp' => 'main', 'created_at' => now()]);
            else $site->insert('users', ['email' => $hub, 'name' => 'Agentur', 'password_hash' => \Core\Oidc\Accounts::NO_PASSWORD, 'role' => 'admin', 'idp' => 'main', 'created_at' => now()]);
            $eq('Agentur-Konto erkannt', in_array($hub, Alerts::agencyAccounts('install'), true), true);
            $r = Alerts::resolve('cron');
            $eq('Agentur-Konten vorhanden: Agentur bevorzugt', [$r['via'], in_array($hub, $r['to'], true)], ['agency', true]);
            $eq('Agentur-Modus: Kunden-Admin bekommt nichts (Installation)', in_array($kunde, $r['to'], true), false);
            $eq('Agentur-Modus: Kunden-Admin bekommt nichts (Website-Datenbank)', in_array($kunde, Alerts::recipients('integrity', ['scope' => 'site']), true), false);
            $eq('Agentur-Modus: Zustellprobleme nicht an die Benachrichtigungsadresse', Alerts::recipients('mail', ['fallback' => [$kunde]]), Alerts::recipients('mail', ['fallback' => []]));

            // Agentur-Kontakt speichern (wie das Formular)
            $eq('Ungültige Agentur-Adresse abgelehnt', Alerts::save(['agency_email' => 'kein-mail']) !== null, true);
            $eq('Speichern', Alerts::save(['mode' => 'agency', 'agency_email' => "Technik-$tag@Agentur.example", 'agency_name' => 'Agentur Musterstadt',
                'route' => ['security' => 'off', 'mail' => 'admins'], 'extra' => ['backup' => "ops-$tag@example.net"]]), null);
            $agency = "technik-$tag@agentur.example";
            $eq('Agentur-Kontakt gilt', Alerts::recipients('integrity'), [$agency]);
            $eq('Zusatzadresse je Kategorie', Alerts::recipients('backup'), [$agency, "ops-$tag@example.net"]);
            $eq('Kategorie aus', Alerts::recipients('security'), []);
            $eq('Ansicht: Name der Agentur', Alerts::viaText('agency'), __('Agentur ({name})', ['name' => 'Agentur Musterstadt']));

            // Versand: eine Mail, nur an die Agentur; Fehlerserie = eine Mail
            $sent();
            $eq('send: an die Agentur', [Alerts::send('integrity', 'QA Integrität', 'Test'), $sent()], [1, [[$agency]]]);
            $key = 'selftest:' . $tag;
            $eq('once: erste Meldung', Alerts::once('cron', $key, 'QA Serie', 'x'), 1);
            $eq('once: Wiederholung unterdrückt', Alerts::once('cron', $key, 'QA Serie', 'x'), 0);
            Alerts::clear($key);
            $eq('clear: nächster Fehler wird wieder gemeldet', Alerts::once('cron', $key, 'QA Serie', 'x'), 1);
            $net->query('UPDATE network_mail SET v = ? WHERE k = ?', [(string) (time() - 7200), 'notice:' . substr(hash('sha256', $key), 0, 32)]);
            $eq('once mit Frist: nach Ablauf wieder', Alerts::once('cron', $key, 'QA Serie', 'x', [], 3600), 1);
            $eq('Kategorie aus: kein Versand', Alerts::send('security', 'QA aus', 'x'), 0);
            $mails = $sent();
            $eq('Serie: genau drei Mails, keine an Kunden', [count($mails), in_array($kunde, array_merge(...($mails ?: [[]])), true)], [3, false]);
            $eq('Test je Kategorie', Alerts::test('backup')[0], 'success');
            $sent();
            $eq('Test einer abgewählten Kategorie', Alerts::test('security')[0], 'error');
            // Integrität und Zustellprobleme nutzen die Regel
            $eq('Integrity::recipients folgt der Regel', \Core\Integrity::recipients('db'), [$agency]);

            // Bisheriges Verhalten ausdrücklich
            Alerts::save(['mode' => 'admins']);
            $eq('Alle Admins: Kunden-Admin bekommt ' . ($multi ? 'Meldungen seiner Website' : 'Meldungen'), in_array($kunde, $multi ? Alerts::recipients('integrity', ['scope' => 'site']) : Alerts::recipients('cron'), true), true);
            if ($multi) $eq('Netzwerk: Installation nie an Kunden-Admins der Website', in_array($kunde, Alerts::recipients('cron'), true), false);
        } finally {
            Mailer::$testDump = null;
            $s->override(['sys.mail_redirect' => $prevRedirect ?? '']);
            if (!$same && $net->pdo->inTransaction()) $net->pdo->rollBack();
            if ($site->pdo->inTransaction()) $site->pdo->rollBack();
            foreach (glob($dump . '/*') ?: [] as $f) @unlink($f);
            @rmdir($dump);
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
