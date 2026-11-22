<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

use Core\Cli;
use Core\Sites;

/**
 * Kommandozeile der Website-Vorlagen (bin/console):
 *   site:blueprint <key> --out=ordner|datei.zip [--demo=anonym|none|full] [--name=…] [--title=…] [--version=1.0.0] [--per-table=3] [--options=datei.json]
 *   site:create <key> <domain,…> --blueprint=<name|name@version|pfad> [--name=… --short=… --color=#… --logo=datei --region=…]
 *              [--params=datei.json] [--no-network] [--no-quick-config]
 *   blueprint:list | blueprint:apply <paket> (intern, --site=neu) | blueprint:propose <name> | blueprint:update [--apply]
 *   site:quick-config [--status] [--step=org --name=… …] [--finish] · site:demo-remove · blueprint:selftest [--sandbox]
 */
final class Console
{
    public static function run(string $cmd, array $args): int
    {
        try {
            return match ($cmd) {
                'site:blueprint' => self::export($args),
                'blueprint:apply' => self::apply($args),
                'blueprint:apply-ext' => self::applyExt($args),
                'blueprint:list' => self::list(),
                'blueprint:propose' => self::propose($args),
                'blueprint:update' => self::update($args),
                'site:quick-config' => self::quick($args),
                'site:demo-remove' => self::demoRemove(),
                'blueprint:selftest' => SelfTest::run(Cli::flag($args, 'sandbox'), Cli::flag($args, 'keep')),
                'site:copy' => self::copy($args),
                'blueprint:copy-finish' => self::copyFinish($args),
                default => 1,
            };
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
    }

    /** Ausgabe auf die Kommandozeile (Text, kein HTML) */
    private static function out(string $s): void
    {
        fwrite(STDOUT, $s);
    }

    /** Läuft der Befehl schon im Kontext der Website? Sonst als eigener Prozess mit --site= neu starten */
    private static function inSite(string $cmd, string $key, array $args): ?int
    {
        if (site()->key === $key) return null;
        $code = Network::console([$cmd, ...array_values(array_filter($args, fn($a) => !str_starts_with((string) $a, '--site='))), '--site=' . $key], $out);
        self::out($out);
        return $code;
    }

    private static function export(array $args): int
    {
        $key = (string) (Cli::positional($args)[0] ?? '');
        if ($key === '' || !isset(Sites::all()[$key])) {
            fwrite(STDERR, "Aufruf: site:blueprint <key> --out=ordner|datei.zip [--demo=anonym|none|full] [--name=…] [--version=1.0.0]\nWebsites: " . implode(', ', array_keys(Sites::all())) . "\n");
            return 1;
        }
        if (($c = self::inSite('site:blueprint', $key, $args)) !== null) return $c;
        $o = [];
        if (($f = Cli::opt($args, 'options')) !== null) $o = (array) json_decode((string) @file_get_contents($f), true);
        foreach (['out', 'demo', 'name', 'title', 'description', 'version', 'note', 'region'] as $k) if (($v = Cli::opt($args, $k)) !== null) $o[$k] = $v;
        if (Cli::flag($args, 'check')) $o['check'] = true;
        if (($v = Cli::opt($args, 'per-table')) !== null) $o['per_table'] = (int) $v;
        $o['out'] ??= ROOT . '/storage/blueprints-export/' . $key . '-' . date('Ymd-His');
        $r = Export::run($o);
        foreach ($r['report'] as $l) self::out("  $l\n");
        if ($r['path'] !== '') self::out("Website-Vorlage „{$r['manifest']['name']}“ {$r['manifest']['version']}: {$r['path']}\n");
        return 0;
    }

    /** Parameter aus --params=datei.json und einzelnen Optionen */
    public static function params(array $args): array
    {
        $p = [];
        if (($f = Cli::opt($args, 'params')) !== null) $p = (array) json_decode((string) @file_get_contents($f), true);
        foreach (['name', 'short', 'color', 'palette', 'logo', 'region'] as $k) if (($v = Cli::opt($args, $k)) !== null) $p[$k] = $v;
        return $p;
    }

    /**
     * site:create … --blueprint=…: Konfiguration der Website mit dem Kit der Vorlage anlegen, dann blueprint:apply im Kontext der neuen
     * Website (eigener Prozess). Aufgerufen aus bin/console (case site:create).
     */
    public static function create(string $key, array $hosts, string $ref, array $args): int
    {
        $path = Library::resolve($ref) ?? throw new \InvalidArgumentException("Vorlage nicht gefunden: $ref (blueprint:list zeigt die Vorlagen)");
        $pkg = Package::open($path);
        $m = $pkg['manifest'];
        if ($pkg['temp']) Package::remove($pkg['dir']);
        $params = self::params($args);
        $label = trim((string) ($params['name'] ?? '')) ?: $hosts[0];
        $r = Sites::create($key, $hosts, (string) $m['kit'], ['seed' => 'blueprint', 'label' => mb_substr($label, 0, 60)]);
        \Core\Network\Network::ensureKey();
        $pf = Package::tempDir() . '/params.json';
        file_put_contents($pf, Package::json($params));
        $pass = ['blueprint:apply', $path, '--params=' . $pf, '--site=' . $key];
        foreach (['no-network', 'no-quick-config'] as $f) if (Cli::flag($args, $f)) $pass[] = '--' . $f;
        $code = Network::console($pass, $out);
        Package::remove(dirname($pf));
        self::out($out);
        self::out("\nWebsite „{$key}“ aus Vorlage „{$m['name']}“ {$m['version']} angelegt: " . str_replace(ROOT . '/', '', $r['file']) . "\n"
           . "1. Domain im Hosting auf denselben Ordner zeigen lassen (Plesk: Dokumentstamm = httpdocs/public).\n"
           . "2. Erstes Konto: https://{$hosts[0]}/admin/setup  –  Setup-Token: {$r['setup_token']}\n"
           . "   oder: php bin/console user:create <email> admin --site={$key}\n"
           . "3. Schnelleinrichtung beim ersten Anmelden (/admin/schnelleinrichtung) oder: php bin/console site:quick-config --site={$key}\n"
           . "   Bis zum Abschluss zeigt die Website Besuchern die Wartungsseite.\n");
        return $code;
    }

    private static function apply(array $args): int
    {
        $path = (string) (Cli::positional($args)[0] ?? '');
        if ($path === '') { fwrite(STDERR, "Aufruf: blueprint:apply <paket> --site=<neue website> [--params=datei.json]\n"); return 1; }
        $params = self::params($args);
        $r = Apply::run($path, $params, ['no_quick_config' => Cli::flag($args, 'no-quick-config'), 'force' => Cli::flag($args, 'force')]);
        foreach ($r['report'] as $l) self::out("  $l\n");
        if ($r['network'] && !Cli::flag($args, 'no-network')) {
            foreach ($r['network'] as $ref) {
                $res = Network::join(['key' => $ref['key'], 'label' => $ref['label'] ?? $ref['key'], 'handle' => $ref['handle'] ?? $ref['key']], null, $ref);
                foreach ($res['lines'] as $l) self::out("  Netzwerk: $l\n");
                if ($res['error'] !== null) self::out("  ! Netzwerk: {$res['error']} – später in der Schnelleinrichtung (Schritt Netzwerk)\n");
            }
            // Alles verbunden: der Schritt „Netzwerk“ ist erledigt (Vorbelegung, nicht erneut abfragen)
            if (QuickConfig::open() && !array_filter(Network::refs(), fn($x) => !$x['joined'])) QuickConfig::markDone('network');
        }
        // Zweiter Schritt als eigener Prozess: jetzt eingeschaltete Erweiterungen (blueprint.apply) und Gebiet aus den Parametern
        $pf = Package::tempDir() . '/params.json';
        file_put_contents($pf, Package::json($params));
        $code = Network::console(['blueprint:apply-ext', $path, '--params=' . $pf, '--site=' . site()->key], $out);
        Package::remove(dirname($pf));
        self::out((string) $out);
        if ($code !== 0) self::out("  ! Erweiterungen/Gebiet: Schritt fehlgeschlagen – später in der Schnelleinrichtung\n");
        if ($r['open']) self::out("  Offene Platzhalter: " . implode(', ', array_keys($r['open'])) . " – Schnelleinrichtung füllt sie\n");
        return 0;
    }

    private static function applyExt(array $args): int
    {
        $path = (string) (Cli::positional($args)[0] ?? '');
        foreach (Apply::applyExtensions($path, self::params($args)) as $l) self::out("  $l\n");
        return 0;
    }

    private static function list(): int
    {
        $all = Library::all();
        if (!$all) { self::out("Keine Website-Vorlagen. Anlegen: Netzwerk → Website-Vorlagen oder site:blueprint <key> --out=storage/blueprints/<name>/1.0.0\n"); return 0; }
        foreach ($all as $b) {
            self::out(sprintf("%-22s %-8s %-30s Quelle: %-12s Demo: %s\n", $b['name'], $b['latest'] ?? '–', mb_strimwidth((string) $b['title'], 0, 30, '…'), $b['source'] ?: '–', $b['demo']));
            foreach (Library::affected($b['name']) as $s) self::out("    Website {$s['key']} ({$s['version']})" . ($s['proposal'] ? " · Vorschlag {$s['proposal']} offen" : '') . "\n");
        }
        return 0;
    }

    /** Neue Version als Vorschlag in diese Website legen (aus der Netzwerk-Verwaltung, je Website) */
    private static function propose(array $args): int
    {
        $name = (string) (Cli::positional($args)[0] ?? '');
        $dir = Library::packageDir($name) ?? throw new \InvalidArgumentException("Vorlage nicht gefunden: $name");
        $bp = (array) app()->settings->get(Apply::KEY, []);
        if (($bp['name'] ?? '') !== $name) throw new \InvalidArgumentException('Diese Website stammt nicht aus der Vorlage „' . $name . '“.');
        $m = (array) json_decode((string) file_get_contents($dir . '/manifest.json'), true);
        $diff = Diff::compare((array) ($bp['structure'] ?? []), Diff::signature($dir));
        Diff::propose($name, (string) $m['version'], $diff);
        self::out("Vorschlag {$m['version']} für " . site()->key . ": " . (implode(' · ', Diff::summary($diff)) ?: 'keine Strukturänderungen') . "\n");
        return 0;
    }

    private static function update(array $args): int
    {
        $p = Diff::proposal();
        if (!$p) { self::out("Kein Vorschlag einer neuen Vorlagen-Version.\n"); return 0; }
        self::out("Vorschlag: {$p['name']} {$p['version']}\n");
        foreach (Diff::summary((array) $p['diff']) as $l) self::out("  $l\n");
        if (!Cli::flag($args, 'apply')) { self::out("Probelauf – mit --apply übernehmen (nur Hinzufügendes: Tabellen, Felder, Seiten als Entwurf, Rollen …).\n"); return 0; }
        foreach (Diff::applyProposal() as $l) self::out("  ✓ $l\n");
        return 0;
    }

    private static function quick(array $args): int
    {
        if (Cli::flag($args, 'start')) QuickConfig::start(QuickConfig::steps(), QuickConfig::values());
        $step = Cli::opt($args, 'step');
        if ($step !== null) {
            $in = [];
            foreach ($args as $a) if (preg_match('~^--([a-z_]+)=(.*)$~s', (string) $a, $x) && !in_array($x[1], ['step', 'site'], true)) $in[$x[1]] = $x[2];
            if ($step === 'network') $in['join'] = array_filter(explode(',', (string) ($in['join'] ?? '')));
            if ($step === 'accounts') $in['invite'] = array_map(fn($p) => ['email' => explode(':', $p)[0], 'role' => explode(':', $p)[1] ?? 'editor'], array_filter(explode(',', (string) ($in['invite'] ?? ''))));
            if ($step === 'look' && isset($in['logo'])) { $in['logo_file'] = $in['logo']; $in['logo_name'] = basename((string) $in['logo']); }
            $res = QuickConfig::saveStep($step, $in, null);
            foreach ($res['errors'] as $f => $e) fwrite(STDERR, "  ! $f: $e\n");
            foreach ($res['messages'] as $l) self::out("  ✓ $l\n");
            if ($res['errors']) return 1;
            if (!Cli::flag($args, 'finish')) return 0;
        }
        if (Cli::flag($args, 'finish')) {
            foreach (QuickConfig::finish(null, !Cli::flag($args, 'keep-maintenance')) as $l) self::out("  $l\n");
            return 0;
        }
        $s = QuickConfig::state();
        self::out('Schnelleinrichtung ' . site()->key . ': ' . ($s['open'] ? 'offen' : ($s['finished_at'] ? 'abgeschlossen ' . $s['finished_at'] : 'nicht begonnen')) . "\n");
        foreach (QuickConfig::steps() as $st) self::out('  ' . (empty($s['done'][$st]) ? '○' : '✓') . ' ' . str_pad($st, 9) . QuickConfig::label($st) . "\n");
        if ($s['open']) {
            self::out("Offene Punkte:\n");
            foreach (QuickConfig::checklist() as $c) self::out("  - {$c['title']}\n");
            self::out("Schritt speichern: site:quick-config --step=org --name=\"…\" --short=… --city=… | --step=look --color=#0F766E --logo=datei.png\n"
               . "  --step=region --region=Nordrhein-Westfalen | --step=network --join=glossar | --step=accounts --invite=a@example.org:editor\n"
               . "  --step=demo --demo=keep|remove | --step=mail --mode=network|smtp --host=… | --step=legal · Abschluss: --finish\n");
        }
        return 0;
    }

    /** site:copy <quelle> <neu> <domain,…> [--content=full|structure|demo] [--media=copy|share|none] [--users=none|copy] [--anonymize] [--revisions] */
    private static function copy(array $args): int
    {
        [$src, $key, $hosts] = Cli::positional($args) + ['', '', ''];
        if ($src === '' || $key === '' || $hosts === '') {
            fwrite(STDERR, "Aufruf: site:copy <quelle> <neuer-key> <domain[,domain2]> [--content=full|structure|demo] [--media=copy|share|none] [--users=none|copy]\n"
                . "        [--anonymize] [--revisions] [--environment=staging] [--quick-config] [--name=…]\n");
            return 1;
        }
        $o = ['content' => Cli::opt($args, 'content') ?? 'full', 'media' => Cli::opt($args, 'media') ?? 'copy', 'users' => Cli::opt($args, 'users') ?? 'none',
            'anonymize' => Cli::flag($args, 'anonymize'), 'revisions' => Cli::flag($args, 'revisions'), 'quick_config' => Cli::flag($args, 'quick-config'),
            'environment' => Cli::opt($args, 'environment'), 'name' => Cli::opt($args, 'name')];
        if (!in_array($o['users'], ['none', 'copy'], true)) throw new \InvalidArgumentException('--users=none|copy');
        foreach (Copy::run((string) $src, (string) $key, explode(',', (string) $hosts), $o) as $l) self::out("  $l\n");
        self::out("Kopie „{$key}“ von „{$src}“ angelegt. Erstes Konto: https://" . explode(',', (string) $hosts)[0] . "/admin/setup bzw. user:create <email> admin --site={$key}\n");
        return 0;
    }

    private static function copyFinish(array $args): int
    {
        $o = (array) json_decode((string) @file_get_contents((string) Cli::opt($args, 'options')), true);
        foreach (Copy::finish($o) as $l) self::out("$l\n");
        return 0;
    }

    private static function demoRemove(): int
    {
        $c = Demo::counts();
        if (!$c && !Demo::state()['media']) { self::out("Keine Demoinhalte auf " . site()->key . ".\n"); return 0; }
        $n = Demo::remove();
        self::out("Demoinhalte entfernt: $n Einträge und Bilder (" . implode(', ', array_map(fn($h, $x) => "$h $x", array_keys($c), $c)) . ").\n");
        return 0;
    }
}
