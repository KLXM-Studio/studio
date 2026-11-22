<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

use Core\Data\Kinds;
use Core\Data\Shared;
use Core\Data\Tables;

/**
 * Website-Vorlage aus der aktuellen Website erzeugen (php bin/console site:blueprint <key> --out=… [--demo=anonym|none|full]).
 *
 * Mitgenommen wird die Struktur: Kit und Design-Werte, Grundeinstellungen nach Whitelist (SYS_SETTINGS + Felder des Kits ohne
 * Geheimnisse), Funktionen und Erweiterungen, Seitenbaum mit Menü, Detail- und Listen-Vorlagen, eigene Blöcke, Datentabellen
 * mit Schema und Art, Rollen mit Blockrechten, Projekt-Hinweise und die Abschnitte der Erweiterungen (Filter blueprint.export).
 * Geteilte Tabellen werden Netzwerk-Bezüge (beim Anlegen Einladung + Beitritt statt Kopie).
 *
 * Demoinhalte (--demo): anonym = wenige Einträge je Tabelle (Standard 3, anonymisiert, Core\Blueprint\Anonymizer), full = alle
 * Einträge (ebenso anonymisiert), none = keine. Nie: Konten, Abonnenten, Aufgaben, Formulareingänge, Statistik, Protokolle,
 * Versionen, E-Mail-Konten, Schlüssel. Bilder nur, wenn sie frei sind (Lizenz + Urheber), sonst Platzhalter beim Anlegen.
 * Zum Schluss prüft audit() das ganze Paket auf Geheimnisse und personenbezogene Daten – bei einem Fund gibt es kein Paket.
 */
final class Export
{
    /**
     * Grundeinstellungen, die eine Vorlage mitnimmt – ausdrücklich (Whitelist). Alles andere bleibt bei der Quelle, insbesondere
     * sys.mail_*, sys.form_*, sys.ai_*, sys.site_url, sys.maintenance*, Schlüssel, Tokens und Zeitstempel.
     */
    public const SYS_SETTINGS = [
        'sys.symbol_style', 'sys.phone_country', 'sys.datetime_picker', 'sys.admin_locale', 'sys.admin_accent_lock', 'sys.admin_accents',
        'sys.icon_topics', 'sys.icon_mode', 'sys.icon_shape', 'sys.icon_bg', 'sys.icon_fg', 'sys.icon_dot', 'sys.icon_dot_color',
        'sys.pwa_theme', 'sys.pwa_bg', 'sys.pwa_display', 'sys.pwa_offline',
        'sys.map_style', 'sys.map_style_dark', 'sys.map_click', 'sys.map_radius', 'sys.calendar_links', 'sys.languages',
        'sys.media_trash_days', 'sys.media_rights', 'sys.media_max_mb', 'sys.media_office', 'sys.media_large_mb', 'sys.media_large_px',
        'sys.spam_honeypot', 'sys.spam_pow', 'sys.spam_pow_difficulty', 'sys.spam_block_links', 'sys.spam_min_seconds', 'sys.spam_max_hours',
        'sys.spam_rate_ip', 'sys.spam_rate_global', 'sys.inbox_log_months', 'sys.presence', 'sys.edit_lock', 'sys.block_defaults',
        'sys.search_boost_headings', 'sys.search_boost_title', 'sys.search_media', 'sys.search_syn_de', 'sys.redirects_auto', 'sys.redirects_log404',
        'sys.tasks_roles', 'sys.support_roles', 'sys.data_schema',
    ];
    /** Felder des Kits mit Angaben der Organisation → Platzhalter (Core\Blueprint\Placeholders) */
    public const ORG_SETTINGS = ['org_name' => 'org.name', 'short_name' => 'org.short', 'tagline' => 'org.claim', 'street' => 'org.street',
        'zip' => 'org.zip', 'city' => 'org.city', 'phone' => 'org.phone', 'email' => 'org.email', 'legal_form' => 'org.legal_form', 'register' => 'org.register'];
    /** Felder des Kits, die nie mitgehen (Ort der Organisation, eigene Profile) */
    public const SKIP_KIT = ['geo', 'social'];
    /** Feldtypen, die nie mitgehen */
    public const SECRET_TYPES = ['secret', 'password'];
    /** Funktionen, die eine Vorlage nicht einschaltet (schicken Daten nach außen bzw. brauchen eigene Zugänge) */
    public const FEATURES_OFF = ['ai', 'chat.visitor', 'chat.assistant', 'api', 'mcp'];
    /** Freie Lizenzen (Bilder gehen nur damit und mit Urheber ins Paket) */
    public const FREE_LICENSE = '~\b(pixabay|pexels|unsplash|cc0|cc[ -]?by(-sa)?|public domain|gemeinfrei|freie lizenz)\b~i';
    public const DEMO_MODES = ['anonym', 'none', 'full'];
    public const DEMO_PER_TABLE = 3;

    /**
     * @param array{out: string, demo?: string, name?: string, title?: string, description?: string, version?: string, note?: string,
     *   per_table?: int, pick?: array<string, list<int>>, steps?: list<string>, placeholders?: array, preview?: string, params?: array} $o
     * @return array{path: string, manifest: array, report: list<string>}
     */
    public static function run(array $o): array
    {
        $demo = (string) ($o['demo'] ?? 'anonym');
        if (!in_array($demo, self::DEMO_MODES, true)) throw new \InvalidArgumentException('--demo=anonym|none|full');
        $out = rtrim((string) ($o['out'] ?? ''), '/');
        if ($out === '') throw new \InvalidArgumentException('Ziel fehlt (--out=ordner oder datei.zip).');
        $zip = str_ends_with(strtolower($out), '.zip');
        if ($zip && is_file($out)) throw new \RuntimeException("Datei gibt es schon: $out");
        $name = (string) ($o['name'] ?? '') ?: Anonymizer::slug(site()->key . '-vorlage');
        if (!Package::validName($name)) throw new \InvalidArgumentException('Name der Vorlage: a–z, 0–9, Bindestrich (2–48 Zeichen).');
        $version = (string) ($o['version'] ?? '1.0.0');
        if (!Package::validVersion($version)) throw new \InvalidArgumentException('Version: z. B. 1.0.0');

        $app = app();
        $report = [];
        $setting = fn(string $k) => $app->settings->get($k);
        // Region der Quelle: Angabe beim Export, sonst Einstellung org.region, sonst von Erweiterungen (z. B. Ausschnitt der Gebietskarte)
        $srcRegion = trim((string) ($o['region'] ?? ''));
        if ($srcRegion === '') {
            $fromExt = \Core\Extensions::filter('blueprint.source', [], ['site' => site()->key]);
            $srcRegion = is_array($fromExt) ? trim((string) ($fromExt['region'] ?? '')) : '';
        }
        $src = Placeholders::sourceValues($setting, (string) (site()->hosts()[0] ?? ''), $srcRegion);
        // Personen der Quelle (Konten, Kontakte, Personenfelder): in allen Texten Platzhalter-Personen, im Paket geprüft (audit)
        $persons = Anonymizer::personNames();
        $anon = new Anonymizer($src, 0, $persons);
        // Gebiete der Quelle (Filter blueprint.areas, z. B. Gebietskarten): Ortsnamen für die Prüfung, neutrale Namen für Einträge an Flächen
        $areas = \Core\Extensions::filter('blueprint.areas', [], ['region' => (string) ($src['region.name'] ?? $srcRegion), 'site' => site()->key]);
        $areas = is_array($areas) ? $areas : [];
        // Neutrale Namen nur an echten Verweisen (Titel, Adresse, Links auf den Eintrag, Werte genau gleich dem Namen) – nie im Fließtext;
        // dort meldet die Prüfung den Namen als Ortsbezug (Core\Blueprint\References)
        $refs = self::areaRefs($areas);
        $files = [];
        $copies = [];   // Dateien, die unverändert ins Paket kopiert werden: Pfad im Paket → Quelldatei
        $media = [];   // alte ID → ['demo' => bool] (Verwendung)

        // ---------------------------------------------------------------- Einstellungen
        $settings = [];
        foreach (self::SYS_SETTINGS as $k) if ($app->settings->has($k)) $settings[$k] = $app->settings->get($k);
        $kitFields = [];
        foreach (app()->theme->settingsGroups() as $g) foreach ((array) ($g['fields'] ?? []) as $f) if (isset($f['name'])) $kitFields[(string) $f['name']] = $f;
        foreach ($kitFields as $k => $f) {
            $type = (string) ($f['type'] ?? 'text');
            if ($type === 'heading' || in_array($type, self::SECRET_TYPES, true) || in_array($k, self::SKIP_KIT, true) || !$app->settings->has($k)) continue;
            $v = $app->settings->get($k);
            if (isset(self::ORG_SETTINGS[$k])) { $settings[$k] = trim((string) $v) !== '' ? Placeholders::token(self::ORG_SETTINGS[$k]) : ''; continue; }
            if ($type === 'media') {
                if ((int) $v > 0) $media[(int) $v] ??= ['demo' => false];
                $settings[$k] = (int) $v ?: null;
                continue;
            }
            if ($k === 'site_title' && trim((string) $v) !== '' && trim((string) $v) === trim((string) $setting('org_name'))) { $settings[$k] = '{{org.name}}'; continue; }
            $settings[$k] = $anon->deep($v);
        }
        $kit = (string) app()->theme->name;
        if ($app->settings->has('design.' . $kit)) $settings['design.' . $kit] = $app->settings->get('design.' . $kit);
        // Detailseiten-Vorlagen je Tabelle (Core\Data\DetailTemplates): Regeln auf Seiten- und Eintrags-IDs – die IDs bleiben beim Anlegen gleich
        foreach ($app->db->fetchAll("SELECT skey FROM settings WHERE skey LIKE 'data.detail_templates.%'") as $dt) $settings[(string) $dt['skey']] = $app->settings->get((string) $dt['skey']);
        $skipped = count(array_diff(array_column($app->db->fetchAll('SELECT skey FROM settings'), 'skey'), array_keys($settings)));
        $report[] = 'Einstellungen: ' . count($settings) . ' übernommen (Whitelist), ' . $skipped . ' nicht (Geheimnisse, Konten, Zustände, Angaben der Quelle)';

        // ---------------------------------------------------------------- Seiten
        $pages = [];
        foreach ($app->db->fetchAll('SELECT * FROM pages ORDER BY id') as $p) {
            unset($p['updated_at'], $p['published_at']);
            foreach (['content_draft', 'content_published'] as $c) {
                if ($p[$c] === null || $p[$c] === '') continue;
                $d = json_decode((string) $p[$c], true);
                if (!is_array($d)) continue;
                foreach (Anonymizer::mediaIds($d) as $mid) $media[$mid] ??= ['demo' => false];
                $p[$c] = $anon->deep($d);
            }
            foreach (['title', 'nav_title', 'meta_title', 'meta_description'] as $c) if (is_string($p[$c] ?? null)) $p[$c] = $anon->text($p[$c]);
            if ((int) ($p['og_image'] ?? 0) > 0) $media[(int) $p['og_image']] ??= ['demo' => false];
            $pages[] = $p;
        }
        // Impressum: Text der Quelle nennt verantwortliche Personen – stattdessen Vorlage mit Platzhaltern und Redaktionsnotizen
        $imprintId = (int) ($app->settings->get('imprint_page') ?: $app->settings->get('impressum_seite'));
        foreach ($pages as $i => $p) {
            if ($imprintId <= 0 || (int) $p['id'] !== $imprintId) continue;
            $pages[$i] = self::imprintTemplate($p);
            $report[] = 'Impressum: Text durch eine Vorlage mit Platzhaltern ersetzt (Personen der Quelle gehen nicht mit)';
        }
        $report[] = 'Seiten: ' . count(array_filter($pages, fn($p) => $p['type'] === 'page')) . ' Seiten, ' . count(array_filter($pages, fn($p) => $p['type'] !== 'page')) . ' Vorlagen';

        // ---------------------------------------------------------------- Tabellen und Netzwerk-Bezüge
        $tables = [];
        $network = [];
        foreach (Tables::all() as $t) {
            if (Tables::isShared($t)) {
                $key = (string) $t['shared']['key'];
                $local = Shared::localConfig($key);
                unset($local['owner']);
                $network[] = ['key' => $key, 'handle' => (string) $t['handle'], 'label' => (string) ($t['shared']['label'] ?: $t['name']),
                    'kind' => Kinds::of($t), 'owner' => (string) $t['shared']['owner'], 'mode' => 'join', 'plan' => 'existing',
                    'local' => $local, 'site_fields' => array_values((array) ($t['site_fields'] ?? []))];
                continue;
            }
            $row = $app->db->fetch('SELECT * FROM data_tables WHERE id = ?', [(int) $t['id']]);
            if (!$row) continue;
            $settingsJson = (array) json_decode((string) $row['settings_json'], true);
            // Benachrichtigungen an Personen und Empfangsbestätigungen gehören der Quelle
            if (isset($settingsJson['form']['notify'])) $settingsJson['form']['notify'] = '';
            $settingsJson = $anon->deep($settingsJson);
            $tables[] = ['id' => (int) $row['id'], 'handle' => (string) $row['handle'], 'name' => (string) $row['name'], 'singular' => $row['singular'],
                'icon' => $row['icon'], 'description' => $anon->text((string) $row['description']), 'sort' => (int) $row['sort'],
                'fields' => $anon->deep((array) json_decode((string) $row['fields_json'], true)), 'settings' => $settingsJson];
        }
        $report[] = 'Tabellen: ' . count($tables) . ($network ? ' · Netzwerk-Bezüge: ' . implode(', ', array_column($network, 'key')) : '');

        // ---------------------------------------------------------------- Rollen, Listen-Vorlagen, eigene Blöcke
        $roles = $app->db->fetchAll("SELECT rkey, name, description, permissions_json, tables_json, blocks_json, builtin FROM roles WHERE rkey NOT IN ('admin', 'network') ORDER BY rkey");
        $lists = array_map(fn($r) => ['created_by' => null] + $r, $app->db->fetchAll('SELECT * FROM list_templates ORDER BY id'));
        $customBlocks = [];
        try {
            $customBlocks = array_map(fn($r) => ['updated_by' => null] + $r, $app->db->fetchAll('SELECT * FROM custom_blocks ORDER BY id'));
        } catch (\Throwable) {
        }
        $report[] = 'Rollen: ' . count($roles) . ' · Listen-Vorlagen: ' . count($lists) . ' · eigene Blöcke: ' . count($customBlocks);

        // ---------------------------------------------------------------- Demoinhalte
        $entries = [];
        $per = max(1, (int) ($o['per_table'] ?? self::DEMO_PER_TABLE));
        if ($demo !== 'none') {
            $keep = [];
            $rowsBy = [];
            foreach ($tables as $t) {
                $full = Tables::find((int) $t['id']);
                // Eingang und Tabellen mit öffentlichem Formular: Einträge sind Formulareingänge – nie in eine Vorlage
                if (!$full || Tables::isInbox($full) || !empty($full['settings']['form']['enabled'])) continue;
                $db = Tables::db($full);
                $tbl = $full['table'];
                $pick = $o['pick'][$t['handle']] ?? null;
                if (is_array($pick)) {
                    $ids = array_values(array_filter(array_map('intval', $pick)));
                    $rows = $ids ? $db->fetchAll('SELECT * FROM ' . $tbl . ' WHERE id IN (' . implode(',', $ids) . ') ORDER BY sort, id') : [];
                } else {
                    $order = Kinds::isCalendar($full) || ($full['settings']['calendar']['enabled'] ?? false)
                        ? self::calendarOrder($full) : "CASE WHEN status = 'published' THEN 0 ELSE 1 END, sort, id";
                    $rows = $db->fetchAll('SELECT * FROM ' . $tbl . ' ORDER BY ' . $order . ($demo === 'anonym' ? ' LIMIT ' . $per : ''));
                }
                $rowsBy[$t['handle']] = [$full, $rows];
                $keep[$t['handle']] = array_map(fn($r) => (int) $r['id'], $rows);
            }
            foreach ($rowsBy as $handle => [$full, $rows]) {
                $clean = [];
                foreach ($rows as $i => $r) {
                    foreach (Tables::dataFields($full['fields']) as $f) {
                        if (in_array($f['type'], ['media', 'file'], true) && (int) ($r[$f['name']] ?? 0) > 0) $media[(int) $r[$f['name']]] ??= ['demo' => true];
                        if (is_string($r[$f['name']] ?? null) && in_array($f['type'], ['mediatext', 'downloads', 'blocks', 'person', 'group'], true)) {
                            foreach (Anonymizer::mediaIds(json_decode((string) $r[$f['name']], true)) as $mid) $media[$mid] ??= ['demo' => true];
                        }
                    }
                    $row = $anon->entry($full, $r, $i, [], $keep);
                    // Einträge an Flächen (Gebietskarten): neutraler Name statt der Landschaft der Quelle, Adresse passend
                    $al = $areas['entries'][$handle] ?? null;
                    if (is_array($al) && isset($al['labels'][(int) $r['id']]) && array_key_exists((string) $al['field'], $row)) {
                        $row[(string) $al['field']] = (string) $al['labels'][(int) $r['id']];
                        if (array_key_exists('slug', $row)) $row['slug'] = Anonymizer::slug((string) $al['labels'][(int) $r['id']]);
                    }
                    $clean[] = $row;
                }
                // Termine relativ zu heute: liegen alle in der Vergangenheit, rücken sie so, dass der erste in einer Woche ist
                $rels = [];
                array_walk_recursive($clean, function ($v, $k) use (&$rels) { if ($k === '@rel') $rels[] = (int) $v; });
                $shift = $rels && min($rels) < 1 && (Kinds::isCalendar($full) || ($full['settings']['calendar']['enabled'] ?? false)) ? 7 - min($rels) : 0;
                // Verknüpfungen (mehrere): Zwischentabellen nur zwischen exportierten Einträgen
                $pivots = [];
                foreach ($full['fields'] as $f) {
                    if (!in_array($f['type'], ['relations', 'contacts'], true)) continue;
                    $pt = Tables::pivot($full, (string) $f['name']);
                    try {
                        $prows = Tables::db($full)->fetchAll('SELECT * FROM ' . $pt);
                    } catch (\Throwable) {
                        continue;
                    }
                    $mine = $keep[$handle] ?? [];
                    $theirs = $keep[(string) ($f['target'] ?? '')] ?? [];
                    $pivots[$f['name']] = array_values(array_filter($prows, function ($p) use ($mine, $theirs) {
                        $vals = array_values($p);
                        return in_array((int) ($vals[0] ?? 0), $mine, true) && in_array((int) ($vals[1] ?? 0), $theirs, true);
                    }));
                }
                $entries[$handle] = ['rows' => $clean, 'shift' => $shift, 'pivots' => $pivots];
            }
            $report[] = 'Demoinhalte (' . $demo . '): ' . (implode(', ', array_map(fn($h, $e) => $h . ' ' . count($e['rows']), array_keys($entries), $entries)) ?: 'keine');
        }

        // ---------------------------------------------------------------- Medien: nur freie mit Urheber, sonst Platzhalter
        $mediaRows = [];
        $placeholderIds = [];
        $creditLicense = [];   // Lizenz aus dem Urheberhinweis erkannt (Bericht)
        $mediaReport = [];   // Bilder, die Platzhalter werden – mit Grund (Bericht: so bekommt eine Vorlage echte Beispielbilder)
        foreach ($media as $id => $use) {
            $m = \Core\Media::find((int) $id);
            $reason = self::mediaReason($m);
            if ($reason !== null) {
                $placeholderIds[] = (int) $id;
                $mediaReport[] = ['id' => (int) $id, 'name' => $m ? $anon->text((string) ($m['title'] ?: $m['original_name'] ?: $m['file'])) : '#' . (int) $id, 'reason' => $reason,
                    'fixable' => in_array($reason, ['license', 'credit', 'both'], true)];
                continue;
            }
            $ext = strtolower(pathinfo((string) $m['file'], PATHINFO_EXTENSION));
            $rel = 'demo/media/' . (int) $id . '.' . $ext;
            if (Package::cleanPath($rel) === null) { $placeholderIds[] = (int) $id; continue; }
            // Quelle aus dem Medienordner der Website und dem geprüften Dateinamen (keine Pool-Dateien, siehe oben)
            $file = Package::cleanPath((string) $m['file']);
            if ($file === null) { $placeholderIds[] = (int) $id; continue; }
            $copies[$rel] = site()->mediaDir() . '/' . $file;
            if (trim((string) $m['license']) === '') $creditLicense[] = '#' . (int) $id . ' ' . mb_strimwidth($anon->text((string) ($m['title'] ?: $m['original_name'] ?: $m['file'])), 0, 50, '…') . ' → ' . self::license($m);
            $mediaRows[] = ['id' => (int) $id, 'file' => $rel, 'name' => basename((string) ($m['original_name'] ?: $m['file'])), 'alt' => $anon->text((string) $m['alt']),
                'title' => $anon->text((string) ($m['title'] ?? '')), 'credit' => (string) $m['credit'], 'license' => self::license($m),
                'focus_x' => (int) ($m['focus_x'] ?? 50), 'focus_y' => (int) ($m['focus_y'] ?? 50), 'demo' => (bool) $use['demo']];
        }
        $report[] = 'Bilder: ' . count($mediaRows) . ' frei mit Urheber, ' . count($placeholderIds) . ' werden Platzhalter';
        array_push($report, ...self::mediaReportLines($mediaReport));
        if ($creditLicense) {
            $report[] = 'Lizenz aus dem Urheberhinweis erkannt (' . count($creditLicense) . ') – geht mit, bitte in der Quelle unter Medien → Rechte bestätigen:';
            foreach (array_slice($creditLicense, 0, 25) as $l) $report[] = '  - ' . $l;
        }

        // ---------------------------------------------------------------- Projekt-Hinweise (Kit-Guide der Website)
        $guide = 0;
        foreach (glob(\Core\Guide::siteDir() . '/*') ?: [] as $gf) {
            $base = basename($gf);
            $rel = Package::cleanPath('guide/' . $base);
            if ($rel === null || !is_file($gf)) continue;
            if (str_ends_with($base, '.md')) $files[$rel] = $anon->text((string) file_get_contents($gf)); else $copies[$rel] = $gf;
            $guide++;
        }

        // ---------------------------------------------------------------- Erweiterungen (Filter blueprint.export, z. B. Gebietskarten)
        $ext = \Core\Extensions::filter('blueprint.export', [], ['demo' => $demo, 'site' => site()->key]);
        $ext = is_array($ext) ? array_filter($ext, fn($k) => is_string($k) && preg_match('~^[a-z0-9_]+$~', $k), ARRAY_FILTER_USE_KEY) : [];
        $ext = $anon->deep($ext);

        // ---------------------------------------------------------------- Funktionen, Erweiterungen
        $features = array_diff_key((array) $app->settings->get(\Core\Features::UI_KEY, []), array_flip(self::FEATURES_OFF));
        $extensions = [];
        foreach (\Core\Extensions::active() as $n => $x) $extensions[] = ['name' => (string) $n, 'version' => (string) ($x->manifest['version'] ?? ''), 'label' => (string) ($x->manifest['label'] ?? $n)];
        $config = array_filter(['preset' => $app->config->get('preset'), 'features' => array_diff_key((array) $app->config->get('features', []), array_flip(self::FEATURES_OFF)),
            'blocks' => $app->config->get('blocks')], fn($v) => $v !== null && $v !== []);

        // Neutrale Namen an echten Verweisen (Links auf den Eintrag, Werte gleich dem Namen) – vor dem Schreiben und der Prüfung
        if ($refs) {
            $pages = References::apply($pages, $refs);
            $settings = References::apply($settings, $refs);
            $ext = References::apply($ext, $refs);
            foreach ($entries as $h => $set) $entries[$h]['rows'] = References::apply($set['rows'], $refs);
        }
        $files['structure/settings.json'] = $settings;
        $files['structure/pages.json'] = $pages;
        $files['structure/tables.json'] = $tables;
        $files['structure/roles.json'] = $roles;
        $files['structure/list_templates.json'] = $lists;
        $files['structure/custom_blocks.json'] = $customBlocks;
        $files['structure/extensions.json'] = (object) $ext;
        $files['demo/entries.json'] = (object) $entries;
        $files['demo/media.json'] = $mediaRows;
        if (!empty($o['preview']) && is_file((string) $o['preview'])) {
            $pe = strtolower(pathinfo((string) $o['preview'], PATHINFO_EXTENSION));
            if (in_array($pe, ['png', 'jpg', 'jpeg', 'webp'], true)) $copies['preview.' . $pe] = (string) $o['preview'];
        }

        $steps = array_values(array_intersect((array) ($o['steps'] ?? QuickConfig::STEPS), QuickConfig::STEPS)) ?: QuickConfig::STEPS;
        $manifest = [
            'format' => Package::FORMAT, 'format_version' => Package::FORMAT_VERSION,
            'name' => $name, 'title' => (string) ($o['title'] ?? '') ?: $name, 'description' => (string) ($o['description'] ?? ''),
            'version' => $version, 'created_at' => date('c'), 'studio_version' => CMS_VERSION,
            'kit' => $kit, 'demo' => $demo, 'demo_counts' => array_map(fn($e) => count($e['rows']), $entries),
            'features' => (object) $features, 'config' => (object) $config, 'extensions' => $extensions,
            'network' => $network,
            'quick_config' => ['steps' => $steps, 'placeholders' => (object) self::cleanPlaceholders((array) ($o['placeholders'] ?? []))],
            'params' => (object) array_merge(['name' => ['label' => 'Name'], 'short' => ['label' => 'Kurzname'], 'color' => ['label' => 'Farbe'],
                'logo' => ['label' => 'Logo'], 'region' => ['label' => 'Region']], (array) ($o['params'] ?? [])),
            'counts' => ['pages' => count($pages), 'tables' => count($tables), 'roles' => count($roles), 'media' => count($mediaRows), 'guide' => $guide],
            'changelog' => array_values((array) ($o['changelog'] ?? [['version' => $version, 'date' => date('Y-m-d'), 'note' => (string) ($o['note'] ?? 'Erste Version')]])),
        ];
        // Texte der Quelle: was ersetzt wurde und was die neue Website von Hand anpassen sollte (beim Anlegen: Aufgaben)
        [$replaced, $review] = self::review($pages, $entries, $settings, $tables, $src, $areas);
        $manifest['review'] = $review;
        $manifest['media_report'] = $mediaReport;
        $report[] = 'Texte: ' . ($replaced ? implode(', ', array_map(fn($k, $n) => '{{' . $k . '}} ' . $n . '×', array_keys($replaced), $replaced)) : 'keine Angaben der Quelle gefunden')
            . ' · ' . count($review) . ' Stellen zum Anpassen';
        $report[] = 'Personen der Quelle: ' . $anon->personHits . ' Nennungen durch Platzhalter-Personen ersetzt (' . count($persons) . ' Namen aus Konten und Daten bekannt)';
        if (!empty($o['check'])) {
            // Probelauf: Plan ohne Paket
            $shown = array_intersect_key($src, array_flip(['org.name', 'org.short', 'region.name', 'region.short', 'org.domain']));
            $report[] = 'Quelle erkannt: ' . (implode(', ', array_map(fn($k, $v) => $k . ' = ' . $v, array_keys($shown), $shown)) ?: '–');
            foreach ($review as $rv) $report[] = '  ! ' . Placeholders::readable($rv['title']) . ' (' . $rv['where'] . '): ' . $rv['reason'] . ($rv['sample'] !== '' ? ' – „' . Placeholders::readable($rv['sample']) . '“' : '');
            $report[] = 'Probelauf – kein Paket geschrieben (ohne --check exportieren).';
            return ['path' => '', 'manifest' => $manifest, 'report' => $report];
        }

        // Paket schreiben (bei ZIP zuerst in ein temporäres Verzeichnis), dann prüfen
        $dir = $zip ? Package::tempDir() . '/pkg' : $out;
        try {
            Package::write($dir, $manifest, $files, $copies);
            $findings = self::audit($dir, $persons);
            if ($findings) throw new \RuntimeException("Paket verworfen – möglicherweise vertrauliche Angaben:\n  " . implode("\n  ", $findings));
            if ($zip) Package::zip($dir, $out);
        } catch (\Throwable $e) {
            self::discard($dir, $zip);
            throw $e;
        }
        if ($zip) self::discard($dir, true);
        $report[] = 'Prüfung auf Geheimnisse und personenbezogene Daten: ohne Fund';
        return ['path' => $out, 'manifest' => $manifest, 'report' => $report];
    }

    /** Impressum der Vorlage: Angaben als Platzhalter, Personen als Redaktionsnotiz (unsichtbar auf der Website) */
    public const IMPRINT = '<p><strong>{{org.name}}</strong><br>{{org.street}}<br>{{org.zip}} {{org.city}}</p>'
        . '<p>Telefon: {{org.phone}}<br>E-Mail: {{org.email}}</p>'
        . '<p>Rechtsform: {{org.legal_form}}<br>Registereintrag: {{org.register}}</p>'
        . '<p>Vertreten durch: {{org.representatives}}</p>'
        . '<p>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV: {{org.responsible}}</p>';

    /** Seite „Impressum“: erster Textblock (richtext) bekommt die Vorlage, weitere Textblöcke entfallen; andere Blöcke bleiben */
    private static function imprintTemplate(array $p): array
    {
        foreach (['content_published', 'content_draft'] as $c) {
            if (!is_array($p[$c] ?? null)) continue;
            $blocks = [];
            $done = false;
            foreach ((array) ($p[$c]['blocks'] ?? []) as $b) {
                if (($b['type'] ?? '') === 'richtext') {
                    if ($done) continue;
                    $b['data'] = ['text' => self::IMPRINT, 'intro' => '', 'title' => ''] + (array) ($b['data'] ?? []);
                    $b['data']['text'] = self::IMPRINT;
                    $b['data']['intro'] = '';
                    $done = true;
                }
                $blocks[] = $b;
            }
            if (!$done) $blocks[] = ['id' => substr(bin2hex(random_bytes(6)), 0, 10), 'type' => 'richtext', 'data' => ['text' => self::IMPRINT], 'tunes' => []];
            $p[$c]['blocks'] = $blocks;
        }
        return $p;
    }

    /**
     * Warum ein Bild nicht ins Paket geht (null = es geht mit): missing (gelöscht/fehlt), pool (geteilter Pool), license (Urheber da,
     * Lizenz fehlt oder ist nicht frei), credit (freie Lizenz, Urheber fehlt), both (beides fehlt).
     */
    public static function mediaReason(?array $m): ?string
    {
        if (!$m || !empty($m['deleted_at']) || !is_file(\Core\Media::path($m))) return 'missing';
        if (!empty($m['pool_ref'])) return 'pool';
        $credit = trim((string) ($m['credit'] ?? '')) !== '';
        $free = preg_match(self::FREE_LICENSE, self::license($m)) === 1;
        if ($credit && $free) return null;
        return $credit ? 'license' : ($free ? 'credit' : 'both');
    }

    /** Lizenz eines Bildes: eingetragen, sonst aus dem Urheberhinweis erkannt („Image by … from Pixabay“ → Pixabay-Lizenz) */
    public static function license(array $m): string
    {
        $l = trim((string) ($m['license'] ?? ''));
        return $l !== '' ? $l : \Core\Media::licenseFromCredit((string) ($m['credit'] ?? ''));
    }

    /** Bericht der Platzhalter-Bilder: Gründe, welche mitgehen könnten und wie man sie markiert @return list<string> */
    public static function mediaReportLines(array $rows): array
    {
        if (!$rows) return [];
        $why = ['missing' => 'Datei fehlt', 'pool' => 'aus einem geteilten Medien-Pool', 'license' => 'Lizenz fehlt oder ist nicht frei',
            'credit' => 'Urheber fehlt', 'both' => 'Lizenz und Urheber fehlen'];
        $fix = array_values(array_filter($rows, fn($r) => $r['fixable']));
        $out = ['Platzhalter statt Bild (' . count($rows) . '):'];
        foreach (array_slice($rows, 0, 25) as $r) $out[] = '  - #' . $r['id'] . ' ' . mb_strimwidth($r['name'], 0, 60, '…') . ': ' . ($why[$r['reason']] ?? $r['reason']);
        if (count($rows) > 25) $out[] = '  … und ' . (count($rows) - 25) . ' weitere';
        if ($fix) $out[] = 'Könnten mitgehen (' . count($fix) . '): in der Quelle unter Medien → Bild → Rechte eine freie Lizenz (z. B. „Pixabay-Lizenz“, „Pexels-Lizenz“, „CC0“) und den Urheber eintragen, dann neue Version exportieren.';
        return $out;
    }

    /**
     * Texte nach Angaben der Quelle durchsuchen (nach dem Ersetzen durch Platzhalter): gezählte Platzhalter je Schlüssel und Stellen
     * zum Anpassen – Name, Kurzname, Region oder Domain als Wortteil („NRW-weit“, „ÖJV-Landesverein“), Adjektive der Region
     * („nordrhein-westfälisch“) und Zahlen neben Angaben der Quelle (Statistik, Anzahl der Gruppen).
     * @return array{0: array<string, int>, 1: list<array{where: string, link: string, title: string, reason: string, sample: string}>}
     */
    public static function review(array $pages, array $entries, array $settings, array $tables, array $src, array $areas = []): array
    {
        // Ortsnamen der Quell-Region (blueprint.areas): Kreise, Städte, Landschaften – ganze Wörter, Groß-/Kleinschreibung wie geschrieben
        $places = array_values(array_unique(array_filter(array_map(fn($p) => trim((string) $p), (array) ($areas['places'] ?? [])), fn($p) => mb_strlen($p) >= 4)));
        usort($places, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $placeRe = array_map(fn($chunk) => '~(?<![\p{L}\d-])(' . implode('|', array_map(fn($p) => preg_quote($p, '~'), $chunk)) . ')(?![\p{L}\d])~u', array_chunk($places, 200));
        $regionName = (string) ($src['region.name'] ?? '') ?: 'die Quell-Region';
        $skipFields = array_flip(array_map('strval', (array) ($areas['fields'] ?? [])));
        $counts = [];
        $items = [];
        $needles = [];
        foreach (['org.name', 'org.short', 'region.name', 'region.short', 'org.domain'] as $k) {
            if (!isset($src[$k])) continue;
            $needles[$src[$k]] = $k;
            // Wortstamm der Region für Adjektive („Nordrhein…“, „Hess…“, „Bayer…“)
            if ($k === 'region.name') {
                $first = explode('-', $src[$k])[0];
                if (mb_strlen($first) >= 5) $needles[mb_substr($first, 0, max(4, mb_strlen($first) - 2))] = $k;
            }
        }
        $check = function (string $s, string $where, string $link, string $title) use (&$counts, &$items, $needles, $placeRe, $regionName): void {
            if ($s === '' || count($items) >= 100) return;
            foreach (Placeholders::found($s) as $k) $counts[$k] = ($counts[$k] ?? 0) + preg_match_all('~\{\{\s*' . preg_quote($k, '~') . '\s*\}\}~', $s);
            $plain = trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8')));
            $rest = (string) preg_replace(Placeholders::PATTERN, ' ', $plain);
            $hits = [];
            $found = array_keys(array_filter($needles, fn($k, $n) => mb_stripos($rest, (string) $n) !== false, ARRAY_FILTER_USE_BOTH));
            if ($found) $hits[] = [(string) $found[0], 'Angabe der Quelle als Wortteil oder in anderer Form („' . implode('“, „', array_map('strval', $found)) . '“) – bitte an die Organisation anpassen'];
            // Zahlen neben der Region (Anzahl der Gruppen, Mitglieder, Statistik) – Jahreszahlen, Datum, Uhrzeit und Rechtsverweise
            // („Art. 6 Abs. 1 lit. a DSGVO“, „§ 25 Abs. 1 TTDSG“) zählen nicht; Zahlwörter („in elf Regionen“, „Dutzende“) schon
            if (preg_match('~\{\{\s*region\.[a-z_]+\s*\}\}~', $plain)) {
                $num = self::regionNumber($rest);
                if ($num !== null) $hits[] = [$num, 'Zahlen und Angaben zur Region (z. B. Anzahl, Statistik) – bitte für die eigene Organisation prüfen'];
            }
            // Ortsnamen: je Absatz ein Fund „Absatz ist auf … bezogen“ (alle Namen des Absatzes) – Fließtext wird nie ersetzt; höchstens 5 je Stelle
            if ($placeRe) {
                $n = 0;
                foreach (preg_split('~</p>|<br\s*/?>|</li>|</h\d>|\n{2,}~i', $s) ?: [] as $para) {
                    $pv = trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($para), ENT_QUOTES, 'UTF-8')));   // Auszug mit Platzhaltern
                    $pt = (string) preg_replace(Placeholders::PATTERN, ' ', $pv);
                    $found = [];
                    foreach ($placeRe as $re) if (preg_match_all($re, $pt, $pm)) foreach ($pm[1] as $p) $found[$p] = true;
                    if (!$found || ++$n > 5) continue;
                    $key = $where . "\x1Fplaces\x1F" . md5($pt);
                    $items[$key] ??= ['where' => $where, 'link' => $link, 'title' => $title, 'reason' => 'Absatz ist auf ' . $regionName . ' bezogen („' . implode('“, „', array_slice(array_keys($found), 0, 5)) . '“) – bitte für die eigene Region neu schreiben',
                        'sample' => mb_strimwidth($pv, 0, 160, '…')];
                }
            }
            foreach ($hits as [$needle, $reason]) {
                $pos = (int) mb_stripos($plain, $needle);
                $sample = mb_substr($plain, max(0, $pos - 40), 90);
                $key = $where . "\x1F" . $reason;
                if (!isset($items[$key])) $items[$key] = ['where' => $where, 'link' => $link, 'title' => $title, 'reason' => $reason, 'sample' => trim($sample)];
            }
        };
        // Nur Texte prüfen: technische Felder (Adressen, IDs, Video, Verweise, Varianten) und Werte wie URLs oder Kennungen nicht;
        // Felder mit Blöcken als JSON (Inhaltsblöcke eines Eintrags) werden entpackt – der Auszug zeigt Klartext statt JSON
        $walk = function (mixed $v, callable $fn, string $key = '') use (&$walk): void {
            if (self::technicalKey($key)) return;
            if (is_string($v)) {
                $t = ltrim($v);
                if (($t[0] ?? '') === '[' || ($t[0] ?? '') === '{') {
                    $d = json_decode($v, true);
                    if (is_array($d)) { $walk($d, $fn, $key); return; }
                }
                if (!self::technicalValue($v)) $fn($v);
                return;
            }
            if (is_array($v)) foreach ($v as $k => $x) $walk($x, $fn, is_string($k) ? $k : $key);
        };
        foreach ($pages as $p) {
            $where = 'page:' . (int) $p['id'];
            $title = (string) ($p['title'] ?? $where);
            foreach (['title', 'nav_title', 'meta_title', 'meta_description', 'content_published'] as $c) {
                $walk($p[$c] ?? null, fn(string $s) => $check($s, $where, $where, $title));
            }
        }
        $names = array_column($tables, 'name', 'handle');
        $titleFields = [];
        foreach ($tables as $t) $titleFields[(string) $t['handle']] = (string) ($t['settings']['title_field'] ?? '');
        foreach ($entries as $h => $set) {
            foreach ((array) ($set['rows'] ?? []) as $r) {
                $where = 'entry:' . $h . ':' . (int) ($r['id'] ?? 0);
                // Titel der Stelle: Titel des Eintrags (Aufgabe „Text an Ihre Organisation anpassen: Regionalgruppe Nord“), sonst Tabelle #ID
                $et = '';
                foreach (array_filter([$titleFields[$h] ?? '', 'titel', 'title', 'name']) as $tf) if (is_string($r[$tf] ?? null) && trim((string) $r[$tf]) !== '') { $et = mb_strimwidth(trim(strip_tags((string) $r[$tf])), 0, 80, '…'); break; }
                foreach (array_keys($r) as $c) if (isset($skipFields[$h . '.' . $c])) unset($r[$c]);   // Felder mit Flächen (Gebietskarten)
                $walk($r, fn(string $s) => $check($s, $where, '/admin/data/' . $h . '/' . (int) ($r['id'] ?? 0), $et !== '' ? $et : ($names[$h] ?? $h) . ' #' . (int) ($r['id'] ?? 0)));
            }
        }
        foreach ($settings as $k => $v) $walk($v, fn(string $s) => $check($s, 'setting:' . $k, '/admin/settings', 'Einstellung ' . $k));
        ksort($counts);
        return [$counts, array_values($items)];
    }

    /**
     * Verweise für neutrale Namen (blueprint.areas → entries: Titelfeld, ID → Name): bisheriger Titel und Slug aus der Datenbank.
     * @return list<array{handle: string, id: int, old: string, new: string, slug_old: string, slug_new: string, prefix: string}>
     */
    private static function areaRefs(array $areas): array
    {
        $out = [];
        foreach ((array) ($areas['entries'] ?? []) as $h => $e) {
            $t = is_array($e) ? Tables::find((string) $h) : null;
            $tf = (string) ($e['field'] ?? '');
            if (!$t || !Tables::field($t, $tf)) continue;
            foreach ((array) ($e['labels'] ?? []) as $id => $label) {
                $row = Tables::db($t)->fetch('SELECT * FROM ' . $t['table'] . ' WHERE id = ?', [(int) $id]);
                $old = trim((string) ($row[$tf] ?? ''));
                if (!$row || $old === '' || $old === (string) $label) continue;
                $out[] = ['handle' => (string) $h, 'id' => (int) $id, 'old' => $old, 'new' => (string) $label, 'slug_old' => (string) ($row['slug'] ?? ''),
                    'slug_new' => Anonymizer::slug((string) $label), 'prefix' => (string) ($e['singular'] ?? '')];
            }
        }
        return $out;
    }

    /** Zahlwörter (eins bis zwanzig, Zehner, hundert, tausend, Dutzend – auch gebeugt: „zweier“, „dreien“, „Dutzende“) */
    public const NUMBER_WORDS = '~\b(?:(?:zwei|drei|vier)(?:en|er)?|eins|fünf|sechs|sieben|acht|neun|zehn|elf|zwölf|dreizehn|vierzehn|fünfzehn|sechzehn|siebzehn|achtzehn|neunzehn|'
        . 'zwanzig|dreißig|vierzig|fünfzig|sechzig|siebzig|achtzig|neunzig|hundert|hunderte|tausend|tausende|dutzend|dutzende|dutzenden)\b~iu';
    /** Rechtsverweise, deren Ziffern keine Angaben der Organisation sind: Art. 6 Abs. 1 lit. a, § 25 Abs. 1, Nr. 3, Satz 2 */
    private const LEGAL_REF = '~(?:\bArt(?:ikel|\.)?|§§?|\bAbs(?:atz|\.)|\bNr\.|\blit\.|\bSatz|\bS\.|\bZiff(?:er|\.)|\bUAbs\.)\s*\d+[a-z]?(?:\s*(?:,|und|bis|-|–)\s*\d+[a-z]?)*~u';

    /** Erste Zahl bzw. erstes Zahlwort in einem Text zur Region – ohne Jahreszahlen, Datum, Uhrzeit, Rechtsverweise; null = keine */
    public static function regionNumber(string $text): ?string
    {
        $t = (string) preg_replace(self::LEGAL_REF, ' ', $text);
        if (preg_match_all('~(?<![\d.:/-])\d{2,}(?:[.,]\d+)?(?![\d.:/-]|\s*(?:Uhr|h\b))~u', $t, $mm)) {
            foreach ($mm[0] as $n) if (!preg_match('~^(19|20)\d\d$~', $n)) return $n;
        }
        return preg_match(self::NUMBER_WORDS, $t, $w) ? $w[0] : null;
    }

    /** Feldname ohne Fließtext (Adresse, Kennung, Video, Verweis, Variante …) – nicht prüfen */
    public static function technicalKey(string $key): bool
    {
        return $key !== '' && preg_match('~(?:^|_)(?:url|urls|link|href|src|id|ids|ref|key|handle|slug|table|tables|source|type|variant|ratio|anchor|embed|video|file|mime|icon|class|'
            . 'color|layout|preset|format|lang|code|token|hash|uuid|time|tunes|field|fields|sort|filter|op|mode|style|fit|align|height|width|space|bg|overlay|motion|toggle)$~i', $key) === 1;
    }

    /** Wert ohne Fließtext: Adresse, interner Verweis, Kennung mit Ziffern ohne Leerzeichen (Video-ID, Hash) */
    public static function technicalValue(string $v): bool
    {
        $v = trim($v);
        return preg_match('~^(?:https?:|mailto:|tel:|page:|media:|entry:|data:|/|#)~i', $v) === 1 || (preg_match('~^\S+$~u', $v) === 1 && preg_match('~\d~', $v) === 1);
    }

    /** Verworfenes Paket entfernen: temporäres Verzeichnis bzw. das von write() angelegte Ziel (war leer oder neu) */
    private static function discard(string $dir, bool $temp): void
    {
        if ($temp) { Package::remove(dirname($dir)); return; }
        Package::remove($dir, true);
    }

    /** Kalender: kommende Termine zuerst (Datum ab heute), dann die letzten */
    private static function calendarOrder(array $t): string
    {
        $start = (string) (Kinds::isCalendar($t) ? 'beginn' : ($t['settings']['calendar']['start'] ?? ''));
        if ($start === '' || !Tables::field($t, $start)) return 'sort, id';
        $col = '"' . str_replace('"', '', $start) . '"';
        return "CASE WHEN $col >= '" . date('Y-m-d') . "' THEN 0 ELSE 1 END, CASE WHEN $col >= '" . date('Y-m-d') . "' THEN $col END ASC, $col DESC";
    }

    /** Eigene Platzhalter aus dem Manifest/Formular: Schlüssel a.b → {label, default, step} */
    public static function cleanPlaceholders(array $in): array
    {
        $out = [];
        foreach ($in as $k => $p) {
            if (!is_string($k) || !preg_match('~^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$~', $k) || isset(Placeholders::KEYS[$k]) || !is_array($p)) continue;
            $out[$k] = ['label' => mb_substr(trim(strip_tags((string) ($p['label'] ?? $k))), 0, 80), 'default' => mb_substr(trim(strip_tags((string) ($p['default'] ?? ''))), 0, 200),
                'step' => in_array($p['step'] ?? '', QuickConfig::STEPS, true) ? (string) $p['step'] : 'org'];
        }
        return $out;
    }

    /**
     * Paket auf Geheimnisse und personenbezogene Daten prüfen: E-Mail-Adressen der Konten und der Grundeinstellungen, Werte geheimer
     * Einstellungen, Schlüssel der Website, IBANs, Abonnenten, Domains der Website. @return list<string> Funde (ohne die Werte selbst)
     */
    public static function audit(string $dir, array $persons = []): array
    {
        $needles = [];
        $add = function (string $kind, mixed $v) use (&$needles): void {
            $v = trim((string) $v);
            if (mb_strlen($v) >= 6) $needles[$v] = $kind;
        };
        $db = app()->db;
        foreach ($db->fetchAll('SELECT email, name FROM users') as $u) { $add('E-Mail eines Kontos', strtolower((string) $u['email'])); $add('Name eines Kontos', $u['name']); }
        // Zugangsdaten und Adressen der E-Mail-Konten, Geheimnisse (Schlüssel mit token/secret/pass/private) – entschlüsselt und roh
        foreach (['sys.mail_pass', 'sys.mail_user', 'sys.mail_host', 'sys.mail_from', 'sys.mail_to', 'sys.mail_redirect'] as $k) $add('Wert einer geschützten Einstellung', app()->settings->get($k));
        foreach ((array) app()->settings->get('sys.mail_accounts', []) as $acc) {
            foreach (['user', 'pass', 'host', 'dsn', 'from', 'reply_to'] as $k) if (is_array($acc) && is_string($acc[$k] ?? null)) $add('Zugang eines E-Mail-Kontos', $acc[$k]);
        }
        foreach ($db->fetchAll("SELECT skey, value_json FROM settings WHERE skey LIKE '%token%' OR skey LIKE '%secret%' OR skey LIKE '%pass%' OR skey LIKE '%private%'") as $s) {
            $add('Wert einer geschützten Einstellung', trim((string) $s['value_json'], '"'));
            $v = app()->settings->get((string) $s['skey']);
            if (is_string($v)) $add('Wert einer geschützten Einstellung', $v);
        }
        $add('Schlüssel der Website', (string) app()->config->get('app_key'));
        foreach ($persons as $p) $add('Name einer Person aus den Daten', $p);
        foreach (site()->hosts() as $h) if (str_contains($h, '.') && !preg_match('~\.(localhost|test)(:\d+)?$~', $h)) $add('Domain der Website', preg_replace('~^www\.~', '', $h));
        foreach (['newsletter_subscribers' => 'email', 'members_access' => 'email', 'user_invites' => 'email', 'tasks' => 'title'] as $tbl => $col) {
            try {
                foreach ($db->fetchAll("SELECT $col AS v FROM $tbl LIMIT 2000") as $r) $add($tbl === 'tasks' ? 'Aufgabe' : 'Adresse aus ' . $tbl, strtolower((string) $r['v']));
            } catch (\Throwable) {
            }
        }
        $findings = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || !preg_match('~\.(json|md|svg)$~', $f->getFilename())) continue;
            $rel = ltrim(substr($f->getPathname(), strlen($dir)), '/');
            $raw = (string) file_get_contents($f->getPathname());
            $txt = mb_strtolower(self::unescape($raw));
            foreach ($needles as $n => $kind) if (str_contains($txt, mb_strtolower($n))) $findings[] = "$kind in $rel";
            if (preg_match_all('~[a-z0-9._%+-]+@([a-z0-9-]+\.)+[a-z]{2,}~', $txt, $m)) {
                foreach (array_unique($m[0]) as $mail) if (!preg_match('~@(example\.(org|com|net)|[a-z0-9-]+\.(test|example|invalid))$~', $mail) && !str_contains($mail, '{{')) $findings[] = "E-Mail-Adresse in $rel";
            }
            if (preg_match('~\b(?!DE00)[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){4,7}\b~', self::unescape($raw))) $findings[] = "IBAN in $rel";
        }
        return array_values(array_unique($findings));
    }

    /** JSON-Escapes (ä, \/) für die Suche auflösen */
    private static function unescape(string $raw): string
    {
        return (string) preg_replace_callback('~\\\\u([0-9a-fA-F]{4})~', fn($m) => mb_chr((int) hexdec($m[1])), str_replace('\/', '/', $raw));
    }
}
