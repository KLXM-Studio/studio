<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

namespace Core;

use Core\Monitoring\Signature;
use Core\Monitoring\Status;
use Core\Network\Network;
use Core\Oidc\Client;
use Core\Oidc\Providers;

/**
 * Verbindung mit einem Agentur-Hub per Einmal-Code (Spezifikation klxm-instance-v1, Abschnitt „enroll“).
 *
 * Ablauf: Ein Admin der Einzelinstallation bzw. der Netzwerk-Website erzeugt in der Verwaltung einen Code (15 Minuten gültig,
 * einmal verwendbar). Der Code enthält die Adresse der Website und ein Geheimnis; die Website speichert nur dessen Hash
 * (Einstellung sys.enroll). Im Hub eingefügt, ruft der Hub POST {Adresse}/.well-known/klxm-studio/enroll auf:
 *   step=hello    → Name, Anmelde-Adressen und alle Websites mit Status-Adresse (Code bleibt gültig)
 *   step=complete → Issuer, Client-ID/-Geheimnis und öffentliche Überwachungsschlüssel des Hubs; die Website prüft den Issuer
 *                   (Discovery), richtet die Anmeldung über den Anbieter ein (Netzwerk-Website bzw. Einzelinstallation) und schaltet
 *                   die Überwachung auf allen Websites ein. Danach ist der Code verbraucht.
 * Ohne Code bleibt alles aus; Fehlversuche werden je IP begrenzt und im Sicherheitsprotokoll vermerkt.
 */
final class InstanceEnroll
{
    public const KEY = 'sys.enroll';
    public const DONE = 'sys.enroll_done';
    public const PATH = '/.well-known/klxm-studio/enroll';
    public const PREFIX = 'KLXM1.';
    public const TTL = 900;

    /** Darf hier ein Code erzeugt werden? Einzelinstallation oder Netzwerk-Website */
    public static function available(): bool
    {
        return !Sites::multi() || Network::isNetworkSite();
    }

    /** Neuen Code erzeugen (ersetzt einen offenen) @return string Code zum einmaligen Anzeigen */
    public static function create(string $by): string
    {
        $token = Oidc\Jwk::b64u(random_bytes(24));
        app()->settings->set(self::KEY, ['hash' => hash('sha256', $token), 'expires' => time() + self::TTL, 'by' => $by, 'created' => time()]);
        $url = Network::siteUrl(site()->key);
        return self::PREFIX . Oidc\Jwk::b64u((string) json_encode(['u' => rtrim($url, '/'), 't' => $token], JSON_UNESCAPED_SLASHES));
    }

    /** Offener Code (ohne Geheimnis) oder null */
    public static function pending(): ?array
    {
        $p = app()->settings->get(self::KEY);
        if (!is_array($p) || empty($p['hash']) || (int) ($p['expires'] ?? 0) < time()) return null;
        return ['expires' => (int) $p['expires'], 'by' => (string) ($p['by'] ?? ''), 'created' => (int) ($p['created'] ?? 0)];
    }

    public static function cancel(): void
    {
        app()->settings->set(self::KEY, null);
    }

    /** Letzte Verbindung @return array{issuer: string, at: int, sites: int}|null */
    public static function done(): ?array
    {
        $d = app()->settings->get(self::DONE);
        return is_array($d) && !empty($d['issuer']) ? $d : null;
    }

    /** Anfrage des Hubs bearbeiten @return array{0: int, 1: array} HTTP-Status, JSON */
    public static function handle(Http\Request $r): array
    {
        if (!self::available()) return [404, ['error' => 'not_found']];
        $limit = new RateLimiter(app()->db);
        $lk = 'enroll:' . hash_hmac('sha256', $r->ip(), app()->key());
        if ($limit->tooMany($lk, 10, 900)) return [429, ['error' => 'too_many_requests']];
        $in = json_decode((string) file_get_contents('php://input'), true);
        $in = is_array($in) ? $in : $r->post;
        $token = (string) ($in['token'] ?? '');
        $p = app()->settings->get(self::KEY);
        $valid = is_array($p) && !empty($p['hash']) && (int) ($p['expires'] ?? 0) >= time() && $token !== '' && hash_equals((string) $p['hash'], hash('sha256', $token));
        if (!$valid) {
            $limit->hit($lk);
            SecurityLog::write('enroll-failed', $r->ip());
            return [401, ['error' => 'invalid_code', 'error_description' => 'Der Code ist falsch, abgelaufen oder schon verwendet.']];
        }
        $step = (string) ($in['step'] ?? 'hello');
        if ($step === 'hello') return [200, self::info()];
        if ($step !== 'complete') return [400, ['error' => 'invalid_step']];

        // Issuer prüfen (https, Discovery erreichbar und passend)
        $issuer = rtrim(trim((string) ($in['issuer'] ?? '')), '/');
        $cid = trim((string) ($in['client_id'] ?? ''));
        $secret = (string) ($in['client_secret'] ?? '');
        $keys = array_values(array_filter((array) ($in['monitoring_keys'] ?? []), fn($k) => is_string($k) && Signature::parseKey($k) !== null));
        if ($cid === '' || $secret === '' || !preg_match('~^https://[^\s/?#]+(/[^\s?#]*)?$~', $issuer) && !(environment() === 'development' && preg_match('~^http://[^\s/?#]+(/[^\s?#]*)?$~', $issuer))) {
            return [400, ['error' => 'invalid_request', 'error_description' => 'Issuer (https), Client-ID und Client-Geheimnis sind nötig.']];
        }
        try {
            Client::discovery($issuer, true);
        } catch (\Throwable $e) {
            return [400, ['error' => 'issuer_unreachable', 'error_description' => mb_strimwidth($e->getMessage(), 0, 160, '…')]];
        }
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60) ?: 'Agentur Hub';

        // Anmeldung über den Anbieter (Netzwerk-Website bzw. Einzelinstallation); vorhandene Feineinstellungen bleiben
        if (is_array(app()->config->get('oidc')) && app()->config->get('oidc')) {
            return [409, ['error' => 'oidc_in_config', 'error_description' => 'Die Anmeldung über einen Anbieter ist in der Konfiguration der Website festgelegt.']];
        }
        $old = (array) (app()->settings->get(Providers::KEY)['main'] ?? []);
        $keep = fn(string $k, mixed $def) => array_key_exists($k, $old) ? $old[$k] : $def;
        $err = Providers::save([
            'enabled' => 1, 'name' => $name, 'label' => (string) $keep('label', ''), 'issuer' => $issuer, 'client_id' => $cid, 'client_secret' => $secret,
            'auth' => 'client_secret_basic', 'role' => (string) $keep('role', 'auto'), 'account_label' => (string) $keep('account_label', ''),
            'jit' => $keep('jit', true) ? 1 : 0, 'mfa' => (string) $keep('mfa', 'amr'), 'role_claim' => (string) $keep('role_claim', 'roles'),
            'editor_role' => (string) $keep('editor_role', 'editor'), 'claim' => '', 'claim_values' => '', 'domains' => implode(',', (array) $keep('domains', [])),
            'logout' => $keep('logout', false) ? 1 : 0, 'logo_media' => (int) $keep('logo_media', 0),
        ]);
        if ($err) return [400, ['error' => 'oidc_failed', 'error_description' => $err]];

        // Überwachung auf allen Websites einschalten (Schlüssel des Hubs ergänzen, vorhandene bleiben)
        $sites = 0;
        if ($keys) {
            foreach (array_keys(Sites::all()) as $key) {
                try {
                    if (self::addMonitoringKeys($key, $keys, $name)) $sites++;
                } catch (\Throwable $e) {
                    error_log('[enroll] monitoring ' . $key . ': ' . $e->getMessage());
                }
            }
        }
        app()->settings->set(self::KEY, null);
        app()->settings->set(self::DONE, ['issuer' => $issuer, 'name' => $name, 'at' => time(), 'sites' => $sites, 'by' => (string) ($p['by'] ?? '')]);
        InstanceInfo::flush();
        error_log('[enroll] verbunden mit ' . $issuer . ' (' . $sites . ' Websites überwacht)');
        return [200, ['ok' => true] + self::info()];
    }

    /** Auskunft für den Hub: wie /.well-known/klxm-studio, aber mit allen Websites */
    public static function info(): array
    {
        $net = Network::siteKey();
        $sites = [];
        foreach (array_keys(Sites::all()) as $key) {
            $entry = ['key' => $key, 'name' => InstanceInfo::name($key), 'url' => Network::siteUrl($key),
                'status_url' => Network::siteLink($key, '/api/monitoring/status'), 'network' => $key === $net];
            if ($key === $net) array_unshift($sites, $entry); else $sites[] = $entry;
        }
        return [
            'spec' => InstanceInfo::SPEC, 'system' => 'studio', 'name' => InstanceInfo::name($net), 'network' => Sites::multi(),
            'login' => ['redirect_uri' => Providers::redirectUri(), 'initiate_login_uri' => Providers::initiateUri(),
                'post_logout_redirect_uri' => admin_url_abs('/admin/login')],
            'monitoring' => ['sites' => $sites],
        ];
    }

    /** Schlüssel des Hubs in die Überwachung einer Website eintragen und einschalten */
    private static function addMonitoringKeys(string $key, array $keys, string $label): bool
    {
        if ($key === site()->key) {
            $cfg = app()->config->get('monitoring');
            if (is_array($cfg) && $cfg) return false;   // in der Konfiguration festgelegt
            $s = Status::settings();
            $lines = array_map(fn($k) => trim($k['label'] . ' ' . $k['key']), $s['keys']);
            foreach ($keys as $k) if (!in_array($k, array_column($s['keys'], 'key'), true)) $lines[] = $label . ' ' . $k;
            return Status::save(true, implode("\n", $lines)) === null;
        }
        $cfg = Network::config($key)->get('monitoring');
        if (is_array($cfg) && $cfg) return false;
        $dbc = (array) Network::config($key)->get('db');
        if (($dbc['driver'] ?? 'sqlite') === 'sqlite' && !is_file((string) ($dbc['path'] ?? ''))) return false;
        $db = new Database($dbc);
        $raw = json_decode((string) ($db->fetchValue('SELECT value_json FROM settings WHERE skey = ?', [Status::KEY]) ?? ''), true);
        $list = is_array($raw['keys'] ?? null) ? $raw['keys'] : [];
        $have = array_column($list, 'key');
        foreach ($keys as $k) if (!in_array($k, $have, true)) $list[] = ['label' => $label, 'key' => $k, 'added' => time()];
        $json = json_encode(['enabled' => true, 'keys' => array_slice($list, 0, 10)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($db->driver === 'mysql') $db->query('REPLACE INTO settings (skey, value_json) VALUES (?, ?)', [Status::KEY, $json]);
        else $db->query('INSERT INTO settings (skey, value_json) VALUES (?, ?) ON CONFLICT(skey) DO UPDATE SET value_json = excluded.value_json', [Status::KEY, $json]);
        return true;
    }
}
