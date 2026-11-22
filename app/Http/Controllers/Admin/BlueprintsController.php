<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Http\Controllers\Admin;

use Core\Blueprint\Diff;
use Core\Blueprint\Export;
use Core\Blueprint\Library;
use Core\Blueprint\Package;
use Core\Blueprint\QuickConfig;
use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Network\Network;
use Core\Sites;

/**
 * Netzwerk-Verwaltung → Website-Vorlagen (/admin/network/vorlagen, Core\Blueprint\Library): Vorlagen aus Websites erstellen,
 * Versionen mit Änderungsprotokoll, Demo-Einträge kuratieren, Platzhalter und Schritte der Schnelleinrichtung, neue Website aus
 * einer Vorlage, Updates als Vorschlag ausrollen, Website kopieren. Nur Netzwerk-Konten mit Recht network.blueprints, nur auf der
 * Netzwerk-Website. Export, Anlegen und Kopieren laufen als eigener Prozess der Kommandozeile.
 */
final class BlueprintsController extends AdminController
{
    private const BASE = '/admin/network/vorlagen';

    private function guard(Request $r): array
    {
        $user = $this->auth($r);
        if (!Network::isNetworkUser($user) || !can('network.blueprints')) throw new HttpException(403, __('Nur für die Netzwerk-Administration.'));
        if (!Network::isNetworkSite()) throw new HttpException(404);
        return $user;
    }

    private function sites(): array
    {
        $out = [];
        foreach (array_keys(Sites::all()) as $k) $out[(string) $k] = (new \Core\Site((string) $k, Sites::all()[$k]))->label();
        return $out;
    }

    public function index(Request $r): Response
    {
        $this->guard($r);
        $created = app()->session->get('_bp_created');
        app()->session->forget('_bp_created');
        return $this->view('network/vorlagen', ['list' => Library::all(), 'sites' => $this->sites(), 'created' => is_array($created) ? $created : null,
            'css' => ['css/network.css', 'css/blueprint.css']]);
    }

    public function show(Request $r, string $name): Response
    {
        $this->guard($r);
        $b = Library::get($name) ?? throw new HttpException(404);
        $created = app()->session->get('_bp_created');
        app()->session->forget('_bp_created');
        return $this->view('network/vorlage', ['b' => $b, 'manifest' => Library::manifest($name), 'sites' => $this->sites(), 'affected' => Library::affected($name),
            'candidates' => isset(Sites::all()[$b['source']]) ? Library::candidates((string) $b['source']) : [], 'created' => is_array($created) ? $created : null,
            'css' => ['css/network.css', 'css/blueprint.css']]);
    }

    public function preview(Request $r, string $name): Response
    {
        $this->guard($r);
        $f = Package::validName($name) ? Library::previewFile($name) : null;
        if (!$f) throw new HttpException(404);
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][pathinfo($f, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
        return (new Response((string) file_get_contents($f)))->header('Content-Type', $mime)->header('Cache-Control', 'private, max-age=300')->header('X-Content-Type-Options', 'nosniff');
    }

    /** „Aus Website erstellen“: neue Vorlage, erste Version */
    public function create(Request $r): Response
    {
        $user = $this->guard($r);
        $name = strtolower(trim($r->str('name')));
        if (Library::get($name)) return $this->back(self::BASE, 'error', __('Eine Vorlage „{name}“ gibt es schon – dort „Neue Version aus Website“.', ['name' => $name]));
        try {
            $res = Library::createVersion(['name' => $name, 'title' => $this->clean($r->str('title')) ?: $name, 'description' => $this->clean($r->str('description'), 500),
                'source' => $r->str('source'), 'demo' => in_array($r->str('demo'), Export::DEMO_MODES, true) ? $r->str('demo') : 'anonym',
                'per_table' => max(1, min(20, (int) $r->str('per_table', '3'))), 'note' => $this->clean($r->str('note'), 300)], (string) $user['email']);
        } catch (\Throwable $e) {
            return $this->back(self::BASE, 'error', $e->getMessage());
        }
        Network::log('blueprint.create', $r->str('source'), (string) $user['email'], $name . ' ' . $res['version']);
        return $this->back(self::BASE . '/' . $name, 'success', __('Vorlage „{name}“ {v} erstellt.', ['name' => $name, 'v' => $res['version']]));
    }

    /** Website kopieren (site:copy als eigener Prozess) */
    public function copy(Request $r): Response
    {
        $user = $this->guard($r);
        $src = $r->str('source');
        $key = strtolower(trim($r->str('key')));
        $hosts = implode(',', preg_split('~[\s,]+~', trim($r->str('hosts'))) ?: []);
        if (!isset(Sites::all()[$src])) return $this->back(self::BASE . '#kopieren', 'error', __('Bitte eine Website als Quelle wählen.'));
        $args = ['site:copy', $src, $key, $hosts, '--content=' . (in_array($r->str('content'), ['full', 'structure', 'demo'], true) ? $r->str('content') : 'full'),
            '--media=' . (in_array($r->str('media'), ['copy', 'share', 'none'], true) ? $r->str('media') : 'copy'), '--users=' . ($r->str('users') === 'copy' ? 'copy' : 'none')];
        foreach (['anonymize', 'revisions', 'quick-config'] as $f) if (!empty($r->post[str_replace('-', '_', $f)])) $args[] = '--' . $f;
        if ($r->str('environment') === 'staging') $args[] = '--environment=staging';
        $code = \Core\Blueprint\Network::console($args, $out);
        if ($code !== 0) return $this->back(self::BASE . '#kopieren', 'error', trim((string) $out) ?: __('Kopieren fehlgeschlagen.'));
        Network::log('site.copy', $key, (string) $user['email'], $src . ' → ' . $key);
        app()->session->set('_bp_created', ['key' => $key, 'output' => self::lines((string) $out), 'token' => self::token((string) $out)]);
        return $this->back(self::BASE . '#kopieren', 'success', __('Website „{site}“ als Kopie von „{src}“ angelegt.', ['site' => $key, 'src' => $src]));
    }

    public function action(Request $r, string $name, string $action): Response
    {
        $user = $this->guard($r);
        $b = Library::get($name) ?? throw new HttpException(404);
        $back = self::BASE . '/' . $name;
        try {
            switch ($action) {
                case 'meta':
                    $steps = array_values(array_intersect(QuickConfig::STEPS, array_map('strval', (array) ($r->post['steps'] ?? []))));
                    $ph = [];
                    foreach (array_values((array) ($r->post['ph'] ?? [])) as $row) {
                        $k = strtolower(trim((string) ($row['key'] ?? '')));
                        if ($k === '') continue;
                        $ph[$k] = ['label' => (string) ($row['label'] ?? ''), 'default' => (string) ($row['default'] ?? ''), 'step' => (string) ($row['step'] ?? 'org')];
                    }
                    $b['title'] = $this->clean($r->str('title')) ?: $name;
                    $b['description'] = $this->clean($r->str('description'), 500);
                    $b['demo'] = in_array($r->str('demo'), Export::DEMO_MODES, true) ? $r->str('demo') : $b['demo'];
                    $b['per_table'] = max(1, min(20, (int) $r->str('per_table', (string) $b['per_table'])));
                    $b['steps'] = $steps ?: QuickConfig::STEPS;
                    $b['placeholders'] = Export::cleanPlaceholders($ph);
                    Library::save($b);
                    if (is_array($f = $r->files['preview'] ?? null) && (int) ($f['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file((string) $f['tmp_name'])) {
                        if ($err = Library::setPreview($name, (string) $f['tmp_name'])) return $this->back($back, 'error', $err);
                    }
                    $msg = __('Angaben gespeichert – gelten ab der nächsten Version.');
                    break;
                case 'demo':
                    $pick = [];
                    foreach ((array) ($r->post['pick'] ?? []) as $h => $ids) if (is_string($h) && preg_match('~^[a-z][a-z0-9_]*$~', $h)) $pick[$h] = array_values(array_filter(array_map('intval', (array) $ids)));
                    $b['pick'] = $r->str('auto') === '1' ? [] : $pick;
                    Library::save($b);
                    $msg = $b['pick'] ? __('Demo-Einträge ausgewählt – gelten ab der nächsten Version.') : __('Demo-Einträge: automatisch die ersten je Tabelle.');
                    break;
                case 'version':
                    $res = Library::createVersion(['name' => $name, 'source' => $r->str('source') ?: $b['source'], 'note' => $this->clean($r->str('note'), 300)], (string) $user['email']);
                    Network::log('blueprint.version', $b['source'], (string) $user['email'], $name . ' ' . $res['version']);
                    $msg = __('Version {v} erstellt.', ['v' => $res['version']]);
                    break;
                case 'site':
                    $params = ['name' => $this->clean($r->str('org_name')), 'short' => $this->clean($r->str('short'), 40), 'region' => $this->clean($r->str('region'), 120)];
                    if (\Core\Design::color($r->str('color')) !== null && $r->str('use_color') === '1') $params['color'] = $r->str('color');
                    $key = strtolower(trim($r->str('key')));
                    $hosts = implode(',', preg_split('~[\s,]+~', trim($r->str('hosts'))) ?: []);
                    $res = Library::createSite($name . ($r->str('version') !== '' && Package::validVersion($r->str('version')) ? '@' . $r->str('version') : ''), $key, $hosts, array_filter($params));
                    if (!$res['ok']) return $this->back($back . '#neu', 'error', trim($res['output']) ?: __('Anlegen fehlgeschlagen.'));
                    Network::log('site.create', $key, (string) $user['email'], __('aus Vorlage {name}', ['name' => $name]));
                    app()->session->set('_bp_created', ['key' => $key, 'output' => self::lines($res['output']), 'token' => self::token($res['output'])]);
                    $msg = __('Website „{site}“ aus der Vorlage angelegt – beim ersten Anmelden startet die Schnelleinrichtung.', ['site' => $key]);
                    $back .= '#neu';
                    break;
                case 'rollout':
                    $sites = array_values(array_intersect(array_map('strval', (array) ($r->post['sites'] ?? [])), array_column(Library::affected($name), 'key')));
                    if (!$sites) return $this->back($back . '#updates', 'error', __('Bitte Websites auswählen.'));
                    foreach ($sites as $s) Library::propose($name, $s);
                    Network::log('blueprint.rollout', null, (string) $user['email'], $name . ' ' . $b['latest'] . ' → ' . implode(', ', $sites));
                    $msg = __('Vorschlag an {n} Websites ausgerollt – übernommen wird erst dort (Schnelleinrichtung).', ['n' => count($sites)]);
                    $back .= '#updates';
                    break;
                case 'delete':
                    if (Library::affected($name)) return $this->back($back, 'error', __('Aus dieser Vorlage stammen Websites – die Vorlage bleibt, damit Updates möglich sind.'));
                    Library::delete($name);
                    Network::log('blueprint.delete', null, (string) $user['email'], $name);
                    return $this->back(self::BASE, 'success', __('Vorlage „{name}“ gelöscht.', ['name' => $name]));
                default:
                    throw new HttpException(404);
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->back($back, 'error', $e->getMessage());
        }
        return $this->back($back, 'success', $msg);
    }

    private function clean(string $s, int $max = 120): string
    {
        return mb_substr(trim((string) preg_replace('~\s+~u', ' ', strip_tags($s))), 0, $max);
    }

    /** Ausgabe des Prozesses für die Anzeige (ohne Setup-Token-Zeile) */
    private static function lines(string $out): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $out)), fn($l) => $l !== '' && !str_contains($l, 'Setup-Token')));
    }

    private static function token(string $out): string
    {
        return preg_match('~Setup-Token: ([0-9a-f]{16,})~', $out, $m) ? $m[1] : (preg_match('~neuer app_key und Setup-Token: ([0-9a-f]{16,})~', $out, $m2) ? $m2[1] : '');
    }
}
