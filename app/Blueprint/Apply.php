<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

use Core\Data\Tables;
use Core\Media;

/**
 * Website-Vorlage in eine NEUE, leere Website einspielen (site:create … --blueprint=…, intern blueprint:apply --site=…).
 *
 * Reihenfolge: Grundeinstellungen (Standardwerte + Vorlage), Rollen, Seiten und Vorlagen (mit ihren IDs – page:ID-Verweise,
 * Detailseiten und Rechtstexte bleiben gültig), Datentabellen (Definition, dann Datenbanktabellen per Tables::sync), Listen-Vorlagen,
 * eigene Blöcke, Bilder (freie aus dem Paket, sonst ein Platzhalterbild), Demo-Einträge (Termine relativ zu heute), Projekt-Hinweise,
 * Funktionen und Erweiterungen (nur ohne Sicherheitshinweis), Abschnitte der Erweiterungen (Filter blueprint.apply), Platzhalter mit
 * den Angaben der neuen Website. Zum Schluss: Wartungsmodus an, bis die Schnelleinrichtung abgeschlossen ist (QuickConfig).
 * Netzwerk-Bezüge (Einladung + Beitritt) verbindet der Aufrufer – das braucht die Eigentümer-Website (Core\Blueprint\Network).
 */
final class Apply
{
    /** Einstellung: woher die Website stammt {name, version, title, applied_at, network, steps} */
    public const KEY = 'sys.blueprint';
    /** Einstellung: Demoinhalte {entries: {handle: [ids]}, media: [ids]} – „Demoinhalte entfernen“ (Core\Blueprint\Demo) */
    public const DEMO_KEY = 'sys.blueprint_demo';

    /**
     * @param array{name?: string, short?: string, color?: string, logo?: string, region?: string, values?: array<string, string>} $params
     * @return array{report: list<string>, network: list<array>, open: array<string, list<string>>}
     */
    public static function run(string $package, array $params = [], array $o = []): array
    {
        $pkg = Package::open($package);
        try {
            return self::apply($pkg['dir'], $pkg['manifest'], $params, $o);
        } finally {
            if ($pkg['temp']) Package::remove($pkg['dir']);
        }
    }

    private static function apply(string $dir, array $m, array $params, array $o): array
    {
        $app = app();
        $db = $app->db;
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM pages') > 0 && empty($o['force'])) {
            throw new \RuntimeException('Die Website hat schon Seiten – eine Vorlage wird nur in eine neue, leere Website eingespielt.');
        }
        $kit = (string) $m['kit'];
        if (!isset(\Core\Theme::available()[$kit])) throw new \RuntimeException("Kit „{$kit}“ der Vorlage ist nicht installiert.");
        $report = [];

        // ---------------------------------------------------------------- Einstellungen
        foreach (\Core\SystemSchema::defaults() as $k => $v) if (!$app->settings->has($k)) $app->settings->set($k, $v);
        $app->settings->set('sys.theme', $kit);
        $app->settings->set(\Core\Onboarding::SEED_KEY, 'empty');
        $settings = (array) Package::read($dir, 'structure/settings.json');
        foreach ($settings as $k => $v) {
            if (!is_string($k) || !self::allowedSetting($k)) continue;
            $app->settings->set($k, $v);
        }
        $report[] = 'Einstellungen: ' . count($settings);

        // ---------------------------------------------------------------- Rollen
        $roleCols = $db->columns('roles');
        foreach ((array) Package::read($dir, 'structure/roles.json') as $r) {
            if (!is_array($r) || !preg_match('~^[a-z][a-z0-9_-]{1,39}$~', (string) ($r['rkey'] ?? '')) || in_array($r['rkey'], ['admin', 'network'], true)) continue;
            $row = array_intersect_key($r, array_flip($roleCols));
            if ($db->fetchValue('SELECT COUNT(*) FROM roles WHERE rkey = ?', [$r['rkey']])) {
                $key = $row['rkey'];
                unset($row['rkey'], $row['builtin']);
                $db->update('roles', $row, 'rkey = :k', ['k' => $key]);
            } else {
                $db->insert('roles', $row);
            }
        }

        // ---------------------------------------------------------------- Seiten (mit IDs)
        $pageCols = $db->columns('pages');
        $pages = (array) Package::read($dir, 'structure/pages.json');
        foreach ($pages as $p) {
            if (!is_array($p) || (int) ($p['id'] ?? 0) <= 0) continue;
            foreach (['content_draft', 'content_published'] as $c) if (is_array($p[$c] ?? null)) $p[$c] = json_encode($p[$c], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $p['updated_at'] = now();
            $p['published_at'] = ($p['status'] ?? '') === 'published' ? now() : null;
            $db->insert('pages', array_intersect_key($p, array_flip($pageCols)));
        }
        $report[] = 'Seiten und Vorlagen: ' . count($pages);

        // ---------------------------------------------------------------- Tabellen
        $tables = (array) Package::read($dir, 'structure/tables.json');
        foreach ($tables as $t) {
            if (!is_array($t) || !preg_match('~^[a-z][a-z0-9_]{0,62}$~', (string) ($t['handle'] ?? '')) || $db->fetchValue('SELECT COUNT(*) FROM data_tables WHERE handle = ?', [$t['handle']])) continue;
            $db->insert('data_tables', ['id' => (int) $t['id'], 'handle' => $t['handle'], 'name' => (string) $t['name'], 'singular' => $t['singular'] ?? null,
                'icon' => $t['icon'] ?? null, 'description' => (string) ($t['description'] ?? ''), 'fields_json' => json_encode((array) $t['fields'], JSON_UNESCAPED_UNICODE),
                'settings_json' => json_encode((array) $t['settings'], JSON_UNESCAPED_UNICODE), 'sort' => (int) ($t['sort'] ?? 0), 'created_at' => now(), 'updated_at' => now()]);
        }
        Tables::flush();
        foreach ($tables as $t) if (is_array($t) && ($full = Tables::find((string) $t['handle']))) Tables::sync($full);
        Tables::flush();
        $report[] = 'Tabellen: ' . count($tables);

        // ---------------------------------------------------------------- Listen-Vorlagen und eigene Blöcke
        foreach (['list_templates' => 'structure/list_templates.json', 'custom_blocks' => 'structure/custom_blocks.json'] as $tbl => $file) {
            try {
                $cols = $db->columns($tbl);
            } catch (\Throwable) {
                continue;
            }
            foreach ((array) Package::read($dir, $file) as $r) if (is_array($r)) $db->insert($tbl, array_intersect_key($r, array_flip($cols)));
        }

        // ---------------------------------------------------------------- Bilder: freie aus dem Paket, Platzhalter für alle übrigen
        $map = [];
        $demoMedia = [];
        foreach ((array) Package::read($dir, 'demo/media.json') as $mr) {
            $rel = Package::cleanPath((string) ($mr['file'] ?? ''));
            if (!$rel || !is_file($dir . '/' . $rel)) continue;
            $tmp = Package::tempDir() . '/' . basename($rel);
            copy($dir . '/' . $rel, $tmp);
            [$row, $err] = Media::import($tmp, (string) ($mr['name'] ?? basename($rel)), (string) ($mr['alt'] ?? ''), ['credit' => (string) ($mr['credit'] ?? ''),
                'license' => (string) ($mr['license'] ?? ''), 'title' => (string) ($mr['title'] ?? ''), 'require_alt' => false]);
            Package::remove(dirname($tmp));
            if (!$row) { $report[] = '  ! Bild ' . ($mr['name'] ?? '') . ': ' . $err; continue; }
            $map[(int) $mr['id']] = (int) $row['id'];
            if (!empty($mr['demo'])) $demoMedia[] = (int) $row['id'];
        }
        $placeholder = self::placeholderImage();
        $map[0] = $placeholder;
        $report[] = 'Bilder: ' . (count($map) - 1) . ' frei' . ($placeholder ? ', übrige als Platzhalterbild' : '');
        self::remapPages($map);
        foreach ($settings as $k => $v) {
            if (!is_string($k) || !self::allowedSetting($k)) continue;
            $f = self::kitField($k);
            // Logo nur, wenn frei – sonst kommt es aus der Schnelleinrichtung; andere Bilder (Banner, Teilen) werden Platzhalter
            if ($f && ($f['type'] ?? '') === 'media' && (int) $v > 0) $app->settings->set($k, $map[(int) $v] ?? (str_starts_with($k, 'logo') ? null : ($placeholder ?: null)));
        }

        // ---------------------------------------------------------------- Demo-Einträge
        $demoIds = [];
        $entries = (array) Package::read($dir, 'demo/entries.json');
        $today = (int) strtotime('today');
        foreach ($entries as $handle => $set) {
            $t = Tables::find((string) $handle);
            if (!$t || Tables::isShared($t) || Tables::isInbox($t) || !is_array($set)) continue;
            $tdb = Tables::db($t);
            $cols = $tdb->columns($t['table']);
            $byName = array_column(Tables::dataFields($t['fields']), null, 'name');
            foreach ((array) ($set['rows'] ?? []) as $r) {
                if (!is_array($r)) continue;
                foreach ($r as $c => $v) {
                    $f = $byName[$c] ?? null;
                    if (is_array($v) && isset($v['@rel'])) $r[$c] = Anonymizer::absDate($v, $today, (int) ($set['shift'] ?? 0));
                    elseif ($f && in_array($f['type'], ['media', 'file'], true) && $v !== null) $r[$c] = (int) $v > 0 ? ($map[(int) $v] ?? $placeholder) : $placeholder;
                    elseif (is_string($v) && $f && in_array($f['type'], ['mediatext', 'downloads', 'blocks', 'person', 'group'], true) && ($d = json_decode($v, true)) !== null) {
                        $r[$c] = json_encode(Anonymizer::remapMedia($d, $map), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
                $r['created_at'] = $r['updated_at'] = now();
                if (($r['status'] ?? '') === 'published' && in_array('published_at', $cols, true)) $r['published_at'] = now();
                $tdb->insert($t['table'], array_intersect_key($r, array_flip($cols)));
                $demoIds[(string) $handle][] = (int) $r['id'];
            }
            foreach ((array) ($set['pivots'] ?? []) as $field => $prows) {
                $pt = Tables::pivot($t, (string) $field);
                try {
                    $pcols = $tdb->columns($pt);
                } catch (\Throwable) {
                    continue;
                }
                foreach ((array) $prows as $pr) if (is_array($pr)) $tdb->insert($pt, array_intersect_key($pr, array_flip($pcols)));
            }
        }
        try {
            \Core\Data\Occurrences::rebuildAll();
        } catch (\Throwable $e) {
            error_log('[blueprint] Kalender-Index: ' . $e->getMessage());
        }
        $report[] = 'Demo-Einträge: ' . (array_sum(array_map('count', $demoIds)) ?: 'keine');

        // ---------------------------------------------------------------- Projekt-Hinweise
        $gdir = \Core\Guide::siteDir();
        foreach (glob($dir . '/guide/*') ?: [] as $gf) {
            $rel = Package::cleanPath('guide/' . basename($gf));
            if ($rel === null) continue;
            if (!is_dir($gdir)) mkdir($gdir, 0770, true);
            copy($gf, $gdir . '/' . basename($gf));
        }

        // ---------------------------------------------------------------- Funktionen und Erweiterungen
        $features = array_diff_key((array) ($m['features'] ?? []), array_flip(Export::FEATURES_OFF));
        if ($features) $app->settings->set(\Core\Features::UI_KEY, array_map('boolval', $features));
        $ext = [];
        $later = [];
        $available = \Core\Extensions::available();
        foreach ((array) ($m['extensions'] ?? []) as $x) {
            $n = (string) ($x['name'] ?? '');
            if (!isset($available[$n])) { $later[] = $n . ' (nicht installiert)'; continue; }
            if (!empty($available[$n]['risk'])) { $later[] = $n . ' (Sicherheitshinweis – bitte selbst einschalten)'; continue; }
            $ext[$n] = true;
        }
        if ($ext) $app->settings->set(\Core\Extensions::UI_KEY, $ext + (array) $app->settings->get(\Core\Extensions::UI_KEY, []));
        if ($later) $report[] = 'Erweiterungen offen: ' . implode(', ', $later);
        // Abschnitte der Erweiterungen spielt der zweite Schritt ein (applyExtensions, eigener Prozess): erst dann laufen die gerade
        // eingeschalteten Erweiterungen und haben ihre Tabellen

        // ---------------------------------------------------------------- Angaben der neuen Website, Platzhalter
        $values = self::values($params, $m);
        $open = self::applyParams($params, $values);

        $app->settings->set(self::KEY, ['name' => (string) $m['name'], 'version' => (string) $m['version'], 'title' => (string) ($m['title'] ?? $m['name']),
            'applied_at' => date('c'), 'network' => array_values(array_map(fn($n) => ['key' => $n['key'], 'label' => $n['label'] ?? $n['key'], 'kind' => $n['kind'] ?? 'content',
                'plan' => $n['plan'] ?? 'existing', 'handle' => $n['handle'] ?? $n['key']], (array) ($m['network'] ?? []))),
            'placeholders' => (array) ($m['quick_config']['placeholders'] ?? []), 'extensions_later' => $later,
            'structure' => Diff::signature($dir)]);
        $app->settings->set(self::DEMO_KEY, ['entries' => $demoIds, 'media' => $demoMedia]);
        if (empty($o['no_quick_config'])) {
            QuickConfig::start((array) ($m['quick_config']['steps'] ?? QuickConfig::STEPS), $values);
            // Werte aus site:create sind Vorbelegung: Farbe/Logo erledigen „Erscheinungsbild“ (Gebiet erledigt applyExtensions)
            if (!empty($params['color']) || !empty($params['logo'])) QuickConfig::markDone('look', array_filter(['look.color' => (string) ($params['color'] ?? ''),
                'look.palette' => (string) ($params['palette'] ?? '')]));
        }
        // Stellen, die die Vorlage nicht automatisch anpassen konnte (Export::review) → Aufgaben „Text an Ihre Organisation anpassen“
        $actor = \Core\Tasks\Tasks::actor(null, 'cli', __('Website-Vorlage'));
        // Eine Aufgabe je Stelle (Seite, Eintrag, Einstellung) mit allen Gründen; Titel und Auszüge mit den Angaben der neuen Website
        $byWhere = [];
        foreach ((array) ($m['review'] ?? []) as $rv) {
            if (!is_array($rv) || !isset($rv['title'], $rv['where'])) continue;
            $w = (string) $rv['where'];
            $byWhere[$w] ??= ['title' => Placeholders::fill((string) $rv['title'], $values), 'link' => (string) ($rv['link'] ?? ''), 'body' => []];
            $byWhere[$w]['body'][] = (string) ($rv['reason'] ?? '') . (($rv['sample'] ?? '') !== '' ? "\n„" . Placeholders::fill((string) $rv['sample'], $values) . '“' : '');
        }
        foreach ($byWhere as $t) {
            QuickConfig::addTask(__('Text an Ihre Organisation anpassen: {title}', ['title' => mb_strimwidth($t['title'], 0, 80, '…')]), implode("\n\n", $t['body']), $t['link'], $actor);
        }
        if ($byWhere) $report[] = 'Aufgaben „Text an Ihre Organisation anpassen“: ' . count($byWhere);
        \Core\Fonts::requestSync();
        \Core\PageCache::clear();
        Tables::flush();
        return ['report' => $report, 'network' => (array) ($m['network'] ?? []), 'open' => $open];
    }

    /**
     * Zweiter Schritt (blueprint:apply-ext, eigener Prozess nach dem Einspielen): die jetzt eingeschalteten Erweiterungen spielen ihre
     * Abschnitte ein (Filter blueprint.apply), danach das Gebiet aus den Parametern (Schritt „Gebiet“ der Schnelleinrichtung, Filter
     * blueprint.region). Rückmeldungen als Bericht bzw. Aufgaben. @return list<string>
     */
    public static function applyExtensions(string $package, array $params = []): array
    {
        $pkg = Package::open($package);
        try {
            $lines = [];
            $sections = (array) Package::read($pkg['dir'], 'structure/extensions.json');
            if ($sections) {
                $res = \Core\Extensions::filter('blueprint.apply', [], ['sections' => $sections, 'params' => $params, 'manifest' => $pkg['manifest'],
                    'values' => array_merge(self::values($params, $pkg['manifest']), QuickConfig::values())]);
                foreach (QuickConfig::handleLines($res) as $l) $lines[] = 'Erweiterung: ' . $l;
            }
            if (trim((string) ($params['region'] ?? '')) !== '' && QuickConfig::open()) {
                $res = QuickConfig::saveStep('region', ['region' => (string) $params['region']]);
                array_push($lines, ...$res['messages']);
            }
            return $lines;
        } finally {
            if ($pkg['temp']) Package::remove($pkg['dir']);
        }
    }

    /** Darf eine Vorlage diese Einstellung setzen? Whitelist (Export::SYS_SETTINGS), Felder des Kits ohne Geheimnisse, Design-Werte */
    public static function allowedSetting(string $k): bool
    {
        if (in_array($k, Export::SYS_SETTINGS, true)) return true;
        if (str_starts_with($k, 'design.') && preg_match('~^design\.[a-z0-9_-]+$~', $k)) return true;
        if (preg_match('~^data\.detail_templates\.[a-z][a-z0-9_]{0,62}$~', $k)) return true;
        if (str_starts_with($k, 'sys.') || str_starts_with($k, 'ext.') || str_starts_with($k, 'shared.')) return false;
        $f = self::kitField($k);
        return $f !== null && !in_array($f['type'] ?? '', Export::SECRET_TYPES, true);
    }

    private static function kitField(string $k): ?array
    {
        static $fields = null;
        if ($fields === null) {
            $fields = [];
            foreach (app()->theme->settingsGroups() as $g) foreach ((array) ($g['fields'] ?? []) as $f) if (isset($f['name'])) $fields[(string) $f['name']] = $f;
        }
        return $fields[$k] ?? null;
    }

    /** Medien-IDs in Seiten und Vorlagen auf die neuen IDs umschreiben (alte, nicht mitgenommene → Platzhalterbild) */
    private static function remapPages(array $map): void
    {
        $db = app()->db;
        foreach ($db->fetchAll('SELECT id, og_image, content_draft, content_published FROM pages') as $p) {
            $upd = [];
            foreach (['content_draft', 'content_published'] as $c) {
                $d = json_decode((string) $p[$c], true);
                if (!is_array($d)) continue;
                $new = json_encode(Anonymizer::remapMedia($d, $map), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($new !== $p[$c]) $upd[$c] = $new;
            }
            if ((int) $p['og_image'] > 0) $upd['og_image'] = $map[(int) $p['og_image']] ?? null;
            if ($upd) $db->update('pages', $upd, 'id = :id', ['id' => (int) $p['id']]);
        }
    }

    /** Platzhalterbild (neutral, mit Hinweis „Bild folgt“) in die Mediathek – einmal je Website */
    public static function placeholderImage(): int
    {
        $have = app()->db->fetchValue("SELECT id FROM media WHERE tags LIKE '%vorlage-platzhalter%' AND deleted_at IS NULL ORDER BY id LIMIT 1");
        if ($have) return (int) $have;
        if (!function_exists('imagecreatetruecolor')) return 0;
        $w = 1600;
        $h = 1000;
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 226, 232, 236));
        $line = (int) imagecolorallocate($im, 203, 213, 220);
        for ($x = -$h; $x < $w; $x += 80) imageline($im, $x, $h, $x + $h, 0, $line);
        $ink = (int) imagecolorallocate($im, 96, 112, 124);
        imagefilledrectangle($im, (int) ($w / 2 - 170), (int) ($h / 2 - 120), (int) ($w / 2 + 170), (int) ($h / 2 + 120), (int) imagecolorallocate($im, 241, 245, 247));
        imagerectangle($im, (int) ($w / 2 - 170), (int) ($h / 2 - 120), (int) ($w / 2 + 170), (int) ($h / 2 + 120), $ink);
        imagefilledellipse($im, (int) ($w / 2 - 70), (int) ($h / 2 - 40), 60, 60, $ink);
        imagefilledpolygon($im, [(int) ($w / 2 - 150), (int) ($h / 2 + 100), (int) ($w / 2 - 20), (int) ($h / 2 - 10), (int) ($w / 2 + 60), (int) ($h / 2 + 60), (int) ($w / 2 + 100), (int) ($h / 2 + 20), (int) ($w / 2 + 150), (int) ($h / 2 + 100)], $ink);
        $dir = Package::tempDir();
        $file = $dir . '/platzhalter.png';
        imagepng($im, $file);
        [$row] = Media::import($file, 'platzhalter.png', __('Platzhalter – Bild folgt'), ['credit' => 'KLXM Studio', 'license' => 'CC0', 'tags' => 'vorlage-platzhalter, demo',
            'title' => __('Platzhalter – Bild folgt'), 'require_alt' => false]);
        Package::remove($dir);
        return $row ? (int) $row['id'] : 0;
    }

    /** Werte der Platzhalter aus den Parametern (Name, Kurzname, Region, eigene Werte, Vorgaben der Vorlage) */
    public static function values(array $params, array $m = []): array
    {
        $v = [];
        foreach ((array) ($m['quick_config']['placeholders'] ?? []) as $k => $p) if (is_array($p) && trim((string) ($p['default'] ?? '')) !== '') $v[(string) $k] = (string) $p['default'];
        if (trim((string) ($params['name'] ?? '')) !== '') $v['org.name'] = trim((string) $params['name']);
        if (trim((string) ($params['short'] ?? '')) !== '') $v['org.short'] = trim((string) $params['short']);
        if (trim((string) ($params['region'] ?? '')) !== '') {
            $r = QuickConfig::region((string) $params['region']);
            $v['region.name'] = $r['name'];
            $v['region.short'] = $r['short'];
        }
        foreach ((array) ($params['values'] ?? []) as $k => $x) if (is_string($k) && preg_match('~^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$~', $k) && is_scalar($x)) $v[$k] = trim((string) $x);
        $host = (string) (site()->hosts()[0] ?? '');
        if ($host !== '' && !isset($v['org.domain'])) $v['org.domain'] = (string) preg_replace(['~:\d+$~', '~^www\.~'], '', $host);
        return $v;
    }

    /**
     * Angaben der Website setzen: Einstellungen der Organisation (Platzhalter-Schlüssel → Felder des Kits), Farbe (Akzent im Design,
     * wenn lesbar), Logo (Datei), dann alle Platzhalter füllen. @return array<string, list<string>> offene Platzhalter
     */
    public static function applyParams(array $params, array $values): array
    {
        $app = app();
        foreach (Placeholders::KEYS as $key => [, , $setting]) {
            if ($setting !== '' && isset($values[$key]) && self::kitField($setting)) $app->settings->set($setting, $values[$key]);
        }
        foreach (['legal_form' => 'org.legal_form', 'register' => 'org.register', 'region' => 'region.name'] as $s => $key) {
            if (isset($values[$key])) $app->settings->set('org.' . $s, $values[$key]);
        }
        if (($c = \Core\Design::color($params['color'] ?? '')) !== null) QuickConfig::setAccent($c, (string) ($params['palette'] ?? 'balanced'));
        if (!empty($params['logo']) && is_file((string) $params['logo'])) QuickConfig::setLogo((string) $params['logo']);
        $open = Placeholders::applySite($values)['open'];
        // Angaben der Organisation ohne Wert: leer statt „{{org.short}}“ im Kopf – das Kit zeigt dann seinen Rückfall (z. B. den Namen);
        // die Schnelleinrichtung füllt sie später. Gemischte Texte („{{org.name}} – …“) bleiben bis dahin stehen.
        foreach (array_keys(Export::ORG_SETTINGS) as $k) {
            $v = $app->settings->get($k);
            if (is_string($v) && preg_match('~^\s*\{\{[a-z0-9_.]+\}\}\s*$~', $v)) $app->settings->set($k, '');
        }
        return $open;
    }
}
