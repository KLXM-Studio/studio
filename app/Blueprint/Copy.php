<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

use Core\Database;
use Core\Sites;

/**
 * Website 1:1 kopieren (php bin/console site:copy <quelle> <neu> <domain> …, Netzwerk → Website-Vorlagen → „Website kopieren“).
 *
 *   --content=full       ganze Datenbank (Seiten, Einträge, Medien, Glossar, Weiterleitungen, Design, Einstellungen), dann bereinigt
 *   --content=structure  wie eine Website-Vorlage ohne Demoinhalte (Export + Anlegen, Core\Blueprint\Export/Apply)
 *   --content=demo       wie eine Website-Vorlage mit anonymisierten Demoinhalten
 *   --media=copy|share|none   Dateien kopieren | dieselben Dateien teilen (harte Links, kein doppelter Speicher) | weglassen
 *   --users=none|copy    Konten nur mit copy (ohne Passkeys – die gelten je Domain)
 *   --revisions          Versionen von Seiten und Einträgen behalten (Standard: nicht)
 *   --anonymize          Personen und Kontakte werden Platzhalter (Core\Blueprint\Anonymizer)
 *   --environment=staging  Kopie als Testumgebung (Suchmaschinen aus, keine Mails an echte Empfänger)
 *   --quick-config       danach die Schnelleinrichtung (Wartungsmodus bis zum Abschluss)
 *
 * Immer neu bzw. leer: app_key und Setup-Token (neue Konfiguration), API- und Abo-Tokens, Passwörter der E-Mail-Konten,
 * Schlüssel der verschlüsselten Formulare, Tokens der Erweiterungen. Nie kopiert (Datenschutz): PRIVATE_TABLES, Eingangs-Tabellen,
 * Einträge von Tabellen mit öffentlichem Formular, Sitzungen, Protokolle und Sicherheitsprotokoll (liegen nicht in der Datenbank).
 * Netzwerk: An geteilten Tabellen der Quelle ist die Kopie nicht beteiligt – die Schnelleinrichtung bzw. „Netzwerk“ bietet
 * „einladen und beitreten“ an (nie eine zweite Eigentümer-Website).
 */
final class Copy
{
    /** Tabellen, deren Inhalt nie in eine Kopie geht (Personen, Verläufe, Statistik, Tokens) – Struktur bleibt */
    public const PRIVATE_TABLES = [
        'newsletter_subscribers', 'newsletter_subscriber_lists', 'newsletter_queue', 'newsletter_events', 'newsletter_suppressions',
        'newsletter_repermits', 'newsletter_repermit_entries', 'inbox_log', 'hits', 'activity_log', 'change_log', 'feature_log', 'settings_revisions',
        'task_log', 'redirect_404', 'push_subscriptions', 'push_queue', 'push_stats', 'push_user_events', 'user_email_changes', 'user_password_resets',
        'user_invites', 'user_passkeys', 'user_identities', 'api_tokens', 'subscription_tokens', 'members_tokens', 'members_passkeys', 'members_access',
        'members_member_groups', 'host_handover', 'media_jobs', 'draft_discards', 'ext_source_log',
    ];
    /** Nur mit --revisions */
    public const REVISION_TABLES = ['revisions', 'entry_revisions', 'graphic_versions', 'shared_block_versions', 'custom_block_versions'];
    /** Einstellungen, die die Kopie nie übernimmt (Geheimnisse, Zustände, Adressen der Quelle) */
    public const DROP_SETTINGS = ['sys.mail_pass', 'sys.mail_user', 'sys.mail_host', 'sys.mail_accounts', 'sys.mail_routes', 'sys.mail_redirect', 'sys.site_url',
        'sys.form_public_key', 'sys.form_key_created', 'sys.domain_check', 'sys.integrity_baseline', Apply::KEY, Apply::DEMO_KEY, QuickConfig::KEY, Diff::PROPOSAL_KEY];
    /** Konfiguration der Quelle, die mitgeht (keine Schlüssel, keine Domains) */
    public const CONFIG_KEYS = ['preset', 'features', 'blocks', 'extensions', 'themes', 'kits', 'media_pools', 'environment', 'languages'];
    public const CONTENT = ['full', 'structure', 'demo'];
    public const MEDIA = ['copy', 'share', 'none'];

    /**
     * Kopie anlegen. @param array{content?: string, media?: string, users?: string, revisions?: bool, anonymize?: bool, environment?: string,
     * quick_config?: bool, name?: string} $o  @return list<string> Bericht
     */
    public static function run(string $src, string $key, array $hosts, array $o = []): array
    {
        if (!isset(Sites::all()[$src])) throw new \InvalidArgumentException("Unbekannte Website „{$src}“.");
        $content = (string) ($o['content'] ?? 'full');
        $media = (string) ($o['media'] ?? 'copy');
        if (!in_array($content, self::CONTENT, true)) throw new \InvalidArgumentException('--content=full|structure|demo');
        if (!in_array($media, self::MEDIA, true)) throw new \InvalidArgumentException('--media=copy|share|none');
        if ($content !== 'full') return self::viaBlueprint($src, $key, $hosts, $content, $o);

        $srcCfg = \Core\Network\Network::config($src);
        $db = (array) $srcCfg->get('db');
        if (($db['driver'] ?? 'sqlite') !== 'sqlite') throw new \RuntimeException('site:copy --content=full kopiert nur SQLite-Datenbanken – für MySQL: site:backup + site:restore.');
        $srcSite = new \Core\Site($src, Sites::all()[$src]);
        $extra = ['seed' => 'blueprint', 'label' => mb_substr((string) ($o['name'] ?? '') ?: ((string) (Sites::all()[$src]['label'] ?? '') ?: $hosts[0]), 0, 60)];
        foreach (self::CONFIG_KEYS as $k) if (array_key_exists($k, Sites::all()[$src])) $extra[$k] = Sites::all()[$src][$k];
        if (!empty($o['environment'])) $extra['environment'] = (string) $o['environment'];
        $r = Sites::create($key, $hosts, (string) ($srcCfg->get('kit') ?: $srcCfg->get('theme')), $extra);
        \Core\Network\Network::ensureKey();
        $site = new \Core\Site($key, Sites::all()[$key]);
        $lines = ['Konfiguration: ' . str_replace(ROOT . '/', '', $r['file']) . ' (neuer app_key und Setup-Token: ' . $r['setup_token'] . ')'];
        // Datenbank: konsistente Kopie per VACUUM INTO (Quelle bleibt unverändert)
        $target = $site->storage('database/site.sqlite');
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0770, true);
        $sdb = new Database($db);
        $sdb->pdo->exec('VACUUM INTO ' . $sdb->pdo->quote($target));
        $lines[] = 'Datenbank kopiert (' . round(filesize($target) / 1048576, 1) . ' MB)';
        // Medien und Projekt-Hinweise
        if ($media !== 'none') {
            [$n, $bytes] = self::tree($srcSite->mediaDir(), $site->mediaDir(), $media === 'share', ['cache']);
            $lines[] = sprintf('Medien: %d Dateien %s (%.1f MB)', $n, $media === 'share' ? 'geteilt (harte Links)' : 'kopiert', $bytes / 1048576);
        }
        self::tree($srcSite->storage('guide'), $site->storage('guide'), false);
        $opts = ['from' => $src, 'media' => $media, 'users' => (string) ($o['users'] ?? 'none'), 'revisions' => !empty($o['revisions']), 'anonymize' => !empty($o['anonymize']),
            'quick_config' => !empty($o['quick_config'])];
        $f = Package::tempDir() . '/copy.json';
        file_put_contents($f, Package::json($opts));
        $code = Network::console(['blueprint:copy-finish', '--options=' . $f, '--site=' . $key], $out);
        Package::remove(dirname($f));
        foreach (array_filter(array_map('trim', explode("\n", (string) $out))) as $l) $lines[] = $l;
        if ($code !== 0) throw new \RuntimeException("Bereinigen der Kopie fehlgeschlagen:\n" . $out);
        return $lines;
    }

    /** Struktur bzw. Demo: wie eine Website-Vorlage (Export in ein temporäres Paket, dann Anlegen) */
    private static function viaBlueprint(string $src, string $key, array $hosts, string $content, array $o): array
    {
        $dir = Package::tempDir();
        try {
            $code = Network::console(['site:blueprint', $src, '--out=' . $dir . '/pkg', '--demo=' . ($content === 'demo' ? 'anonym' : 'none'), '--name=' . Anonymizer::slug($src . '-kopie')], $out);
            if ($code !== 0) throw new \RuntimeException(trim((string) $out));
            $args = ['site:create', $key, implode(',', $hosts), '--blueprint=' . $dir . '/pkg'];
            if (!empty($o['name'])) $args[] = '--name=' . $o['name'];
            if (empty($o['quick_config'])) $args[] = '--no-quick-config';
            $code = Network::console($args, $out2);
            if ($code !== 0) throw new \RuntimeException(trim((string) $out2));
            return array_values(array_filter(array_map('trim', explode("\n", (string) $out . "\n" . (string) $out2))));
        } finally {
            Package::remove($dir);
        }
    }

    /** Verzeichnis kopieren bzw. per harten Links teilen ($skip: oberste Unterordner) @return array{0: int, 1: int} Dateien, Bytes */
    private static function tree(string $from, string $to, bool $link, array $skip = []): array
    {
        if (!is_dir($from)) return [0, 0];
        $n = $bytes = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($from) + 1);
            if (in_array(explode('/', $rel)[0], $skip, true) || $f->isLink()) continue;
            $dest = $to . '/' . $rel;
            if ($f->isDir()) { if (!is_dir($dest)) mkdir($dest, 0775, true); continue; }
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0775, true);
            if (!($link && @link($f->getPathname(), $dest))) copy($f->getPathname(), $dest);
            $n++;
            $bytes += (int) $f->getSize();
        }
        return [$n, $bytes];
    }

    /**
     * Bereinigen im Kontext der Kopie (blueprint:copy-finish): Datenschutz-Tabellen leeren, Eingang und Formulareingänge leeren,
     * Konten, Versionen, Geheimnisse, Netzwerk-Bezüge, optional Anonymisieren und Schnelleinrichtung. @return list<string>
     */
    public static function finish(array $o): array
    {
        $db = app()->db;
        $lines = [];
        $have = array_flip(array_column($db->objects(), 'name'));
        $tables = self::PRIVATE_TABLES;
        if (empty($o['revisions'])) array_push($tables, ...self::REVISION_TABLES);
        $cleared = 0;
        foreach ($tables as $t) if (isset($have[$t])) { $cleared += (int) $db->fetchValue("SELECT COUNT(*) FROM $t"); $db->query("DELETE FROM $t"); }
        // Eingang und Tabellen mit öffentlichem Formular: Einträge sind Anfragen bzw. Formulareingänge
        $inbox = 0;
        foreach (\Core\Data\Tables::all() as $t) {
            if (\Core\Data\Tables::isShared($t) || !(\Core\Data\Tables::isInbox($t) || !empty($t['settings']['form']['enabled']))) continue;
            $inbox += (int) $db->fetchValue('SELECT COUNT(*) FROM ' . $t['table']);
            $db->query('DELETE FROM ' . $t['table']);
            foreach ($t['fields'] as $f) if (in_array($f['type'], ['relations', 'contacts'], true) && isset($have[\Core\Data\Tables::pivot($t, (string) $f['name'])])) $db->query('DELETE FROM ' . \Core\Data\Tables::pivot($t, (string) $f['name']));
        }
        $lines[] = "Datenschutz: $cleared Zeilen aus Verläufen, Statistik, Tokens und Abonnenten entfernt, $inbox Anfragen/Formulareingänge nicht kopiert";
        if (($o['users'] ?? 'none') !== 'copy') {
            $db->query('DELETE FROM users');
            $lines[] = 'Konten: keine (Konto über /admin/setup bzw. user:create)';
        } else {
            $db->query('UPDATE users SET auth_ver = auth_ver + 1');
            $lines[] = 'Konten übernommen (' . (int) $db->fetchValue('SELECT COUNT(*) FROM users') . '), ohne Passkeys – Anmeldung mit Passwort';
        }
        foreach (self::DROP_SETTINGS as $k) $db->query('DELETE FROM settings WHERE skey = ?', [$k]);
        $db->query("DELETE FROM settings WHERE skey LIKE '%token%' OR skey LIKE '%secret%' OR skey LIKE '%private%' OR skey LIKE 'newsletter.hook%'");
        app()->settings->forget();
        if (empty($o['media']) || $o['media'] === 'none') {
            $db->query('UPDATE media SET deleted_at = ? WHERE deleted_at IS NULL AND pool_ref IS NULL', [now()]);
            $lines[] = 'Medien weggelassen (Einträge im Papierkorb)';
        }
        if (!empty($o['anonymize'])) $lines[] = 'Anonymisiert: ' . self::anonymize() . ' Personen/Kontakte';
        // Netzwerk: Kopie ist an geteilten Tabellen nicht beteiligt – Bezüge für „einladen und beitreten“ merken
        $refs = [];
        foreach ($db->fetchAll("SELECT skey FROM settings WHERE skey LIKE 'shared.%'") as $s) {
            $sk = substr((string) $s['skey'], 7);
            if (($meta = \Core\Data\Shared::meta($sk)) === null) continue;
            $refs[] = ['key' => $sk, 'label' => (string) ($meta['label'] ?? $sk), 'kind' => 'content', 'plan' => 'existing', 'handle' => $sk];
        }
        app()->settings->set(Apply::KEY, ['name' => 'copy:' . (string) ($o['from'] ?? ''), 'version' => '', 'title' => __('Kopie von {site}', ['site' => (string) ($o['from'] ?? '')]),
            'applied_at' => date('c'), 'network' => $refs, 'placeholders' => [], 'extensions_later' => [], 'structure' => Diff::siteSignature()]);
        if ($refs) $lines[] = 'Netzwerk: nicht beteiligt an ' . implode(', ', array_column($refs, 'key')) . ' – einladen und beitreten über die Schnelleinrichtung bzw. data:network-join';
        if (!empty($o['quick_config'])) {
            QuickConfig::start(QuickConfig::STEPS, ['org.name' => (string) app()->settings->get('org_name', '')]);
            $lines[] = 'Schnelleinrichtung offen (Wartungsmodus bis zum Abschluss)';
        }
        \Core\PageCache::clear();
        try {
            \Core\Data\Occurrences::rebuildAll();
        } catch (\Throwable) {
        }
        return $lines;
    }

    /** Alle Kontakte und Personenfelder anonymisieren (Anonymizer::entry je Eintrag) @return int */
    public static function anonymize(): int
    {
        $n = 0;
        $a = new Anonymizer();
        foreach (\Core\Data\Tables::all() as $t) {
            if (\Core\Data\Tables::isShared($t) || \Core\Data\Tables::isInbox($t)) continue;
            $tdb = \Core\Data\Tables::db($t);
            foreach ($tdb->fetchAll('SELECT * FROM ' . $t['table'] . ' ORDER BY id') as $i => $row) {
                $new = $a->entry($t, $row, $i);
                foreach ($new as $c => $v) if (is_array($v)) $new[$c] = Anonymizer::absDate($v, (int) strtotime('today'));   // Datum bleibt absolut
                $upd = array_diff_assoc(array_map(fn($v) => $v === null ? null : (string) $v, array_intersect_key($new, $row)), array_map(fn($v) => $v === null ? null : (string) $v, $row));
                unset($upd['created_at'], $upd['updated_at'], $upd['published_at']);
                foreach ($upd as $c => $v) if (is_array($new[$c] ?? null)) unset($upd[$c]);
                // Datumsfelder nicht verschieben: relDate→absDate mit heute ergibt das Original
                if ($upd) { $tdb->update($t['table'], $upd, 'id = :id', ['id' => (int) $row['id']]); $n++; }
            }
        }
        return $n;
    }

    /** Struktur-Prüfsumme einer Website-Datenbank (Seiten, Tabellen, Rollen, Listen-Vorlagen, eigene Blöcke) */
    public static function checksum(Database $db): string
    {
        $tables = [];
        foreach ($db->fetchAll('SELECT handle, fields_json, settings_json FROM data_tables') as $r) {
            $tables[] = ['handle' => $r['handle'], 'fields' => (array) json_decode((string) $r['fields_json'], true), 'settings' => (array) json_decode((string) $r['settings_json'], true)];
        }
        try {
            $blocks = $db->fetchAll('SELECT bkey FROM custom_blocks');
        } catch (\Throwable) {
            $blocks = [];
        }
        $sig = Diff::build($db->fetchAll('SELECT * FROM pages'), $tables, $db->fetchAll("SELECT rkey, permissions_json FROM roles WHERE rkey NOT IN ('admin', 'network')"),
            $db->fetchAll('SELECT table_handle, block, tkey FROM list_templates'), $blocks);
        return sha1((string) json_encode($sig, JSON_UNESCAPED_UNICODE));
    }

    /** Logik ohne Datenbank (Teil von blueprint:selftest) */
    public static function selftest(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Kopie – ');
        $eq('Abonnenten, Anfragen-Protokoll, Statistik, Tokens nie', array_values(array_diff(['newsletter_subscribers', 'inbox_log', 'hits', 'api_tokens', 'subscription_tokens', 'task_log', 'activity_log'], self::PRIVATE_TABLES)), []);
        $eq('Mail-Passwort und Formular-Schlüssel nie', array_values(array_diff(['sys.mail_pass', 'sys.form_public_key', 'sys.mail_accounts'], self::DROP_SETTINGS)), []);
        $eq('Konfiguration ohne Schlüssel und Domains', array_values(array_intersect(['app_key', 'setup_token', 'hosts', 'landing_hosts', 'db', 'network_key'], self::CONFIG_KEYS)), []);
        return ['ok' => $ok, 'fails' => $fails];
    }
}
