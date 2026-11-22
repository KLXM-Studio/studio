<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

use Core\Data\Shared;
use Core\Data\Tables;

/**
 * Netzwerk-Bezüge einer Website-Vorlage: geteilte Tabellen (z. B. Glossar, Termine des Verbands), die beim Anlegen nicht kopiert,
 * sondern per Einladung und Beitritt verbunden werden – mit den vorhandenen Werkzeugen (data:invite, data:network-join,
 * Core\Data\Network::invite/join). Danach gelten die Anzeige-Einstellungen der Vorlage (Detailseite, Mitglieder, Regeln) und die
 * Zusatzfelder je Website.
 */
final class Network
{
    /**
     * Bezüge der Website (aus sys.blueprint) mit Stand. @return list<array{key: string, label: string, kind: string, handle: string,
     * plan: string, exists: bool, owner: string, invited: bool, joined: bool}>
     */
    public static function refs(): array
    {
        $out = [];
        foreach ((array) (app()->settings->get(Apply::KEY, [])['network'] ?? []) as $r) {
            $key = (string) ($r['key'] ?? '');
            if ($key === '') continue;
            $meta = Shared::meta($key);
            $out[] = ['key' => $key, 'label' => (string) ($r['label'] ?? $key), 'kind' => (string) ($r['kind'] ?? 'content'), 'handle' => (string) ($r['handle'] ?? $key),
                'plan' => (string) ($r['plan'] ?? 'existing'), 'exists' => $meta !== null, 'owner' => (string) ($meta['owner'] ?? ''),
                'invited' => $meta !== null && Shared::isInvited($key), 'joined' => $meta !== null && in_array(site()->key, Shared::participants($meta), true)];
        }
        return $out;
    }

    /**
     * Einer geteilten Tabelle beitreten: fehlt die Einladung, lädt die Netzwerk-Administration bzw. die Kommandozeile (als
     * Eigentümer-Website) ein, dann Beitritt mit Plan. Anzeige-Einstellungen aus der Vorlage ($package: Eintrag aus manifest.network).
     * @return array{error: ?string, lines: list<string>}
     */
    public static function join(array $ref, ?array $user = null, array $package = []): array
    {
        $key = (string) $ref['key'];
        $label = (string) ($ref['label'] ?? $key);
        $meta = Shared::meta($key);
        if (!$meta) return ['error' => __('Die geteilte Tabelle „{key}“ gibt es in dieser Installation nicht.', ['key' => $key]), 'lines' => []];
        if (in_array(site()->key, Shared::participants($meta), true)) return ['error' => null, 'lines' => [__('{name}: schon verbunden.', ['name' => $label])]];
        $lines = [];
        if (!Shared::isInvited($key)) {
            try {
                if (\Core\Features::integrator()) {
                    array_push($lines, ...\Core\Data\Network::invite($key, [site()->key]));
                } elseif (PHP_SAPI === 'cli') {
                    $code = self::console(['data:invite', $key, site()->key, '--site=' . $meta['owner']], $out);
                    if ($code !== 0) return ['error' => trim((string) $out) ?: __('Einladung fehlgeschlagen.'), 'lines' => []];
                    $lines[] = __('Eingeladen durch {site}.', ['site' => $meta['owner']]);
                } else {
                    return ['error' => __('{name}: Die Website ist noch nicht eingeladen – bitte die Netzwerk-Administration bzw. die Website {site} darum bitten.', ['name' => $label, 'site' => $meta['owner']]), 'lines' => []];
                }
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage(), 'lines' => []];
            }
            Shared::flush();
        }
        try {
            array_push($lines, ...\Core\Data\Network::join($key, [], (string) ($user['email'] ?? 'blueprint')));
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage(), 'lines' => $lines];
        }
        Tables::flush();
        // Anzeige-Einstellungen der Vorlage und Detailseite (Vorlage für diese Tabelle)
        $local = array_intersect_key((array) ($package['local'] ?? []), Shared::CONFIG);
        unset($local['owner']);
        $tpl = app()->db->fetchValue("SELECT id FROM pages WHERE type = 'template' AND template_for = ? ORDER BY id LIMIT 1", [(string) ($ref['handle'] ?? $key)]);
        if ($tpl) $local['detail_page_id'] = (int) $tpl;
        if ($local) Shared::saveLocal($key, $local);
        if (!empty($package['site_fields']) && ($t = Tables::sharedTable($key)) && \Core\Data\Network::siteFieldsOn($t)) {
            [, $errs] = \Core\Data\Network::saveSiteFields($t, (array) $package['site_fields']);
            if ($errs) $lines[] = __('Zusatzfelder nicht übernommen: {list}', ['list' => implode(' ', $errs)]);
        }
        $lines[] = __('{name}: verbunden.', ['name' => $label]);
        return ['error' => null, 'lines' => $lines];
    }

    /**
     * Befehl der Kommandozeile als eigener Prozess (andere Website) – Ausgabe in $out
     * @param-out string $out
     */
    public static function console(array $args, ?string &$out = null): int
    {
        $php = \Core\Network\Stats::phpBinary();
        $p = proc_open([$php, ROOT . '/bin/console', ...array_map('strval', $args)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT);
        if (!is_resource($p)) { $out = 'proc_open'; return 1; }
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($p);
    }
}
