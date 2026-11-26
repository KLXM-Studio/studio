<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Api;

use Core\Data\Tables;
use Core\Http\Controllers\McpController;

/**
 * „Was ein Agent über MCP hier tun kann“: Übersicht nach Aufgaben statt Werkzeugen – aus den tatsächlich angebotenen MCP-Werkzeugen
 * (McpController::catalog, also mit Funktionen und Erweiterungen dieser Website), gefiltert nach den Rechten der Person. Je Gruppe
 * Beispiel-Aufträge in Alltagssprache mit echten Namen (Tabellen, Seiten), Art (liest / ändert / löscht) und die technischen Namen.
 * Handbuch: /admin/hilfe/agent (app/Admin/views/help/agent.php); dieselben Daten als MCP-Resource cms://capabilities und Prompt
 * „was_kannst_du“. Erweiterungen ordnen eigene Werkzeuge über self::meta() ein (Gruppe, Recht, Beispiele) – unbekannte landen unter „Weitere“.
 */
final class AgentGuide
{
    /** Gruppen: Schlüssel => [Titel, Symbol, Beschreibung] */
    public static function groups(): array
    {
        return [
            'start' => [__('Überblick & Suche'), 'compass', __('Website kennenlernen, Inhalte finden, Fragen beantworten.')],
            'pages' => [__('Seiten & Inhalte'), 'file-text', __('Seiten anlegen, Texte und Blöcke ändern, veröffentlichen, Versionen zurückholen.')],
            'data' => [__('Daten & Einträge'), 'table', __('Einträge Ihrer Tabellen anlegen, ändern, löschen und auflisten.')],
            'media' => [__('Bilder & Dateien'), 'image', __('Dateien hochladen, Alt-Texte setzen, Bilder zuschneiden.')],
            'translate' => [__('Übersetzen'), 'translate', __('Seiten und Einträge in weitere Sprachen übertragen.')],
            'settings' => [__('Angaben & Design'), 'sliders-horizontal', __('Zentrale Angaben, Öffnungszeiten, aktuelle Hinweise, Farben und Schriften.')],
            'requests' => [__('Anfragen'), 'tray', __('Eingegangene Anfragen sichten, zuweisen, erledigen – ohne deren Inhalt zu lesen.')],
            'push' => [__('Mitteilungen'), 'bell', __('Push-Mitteilungen an Abonnenten planen und senden.')],
            'tasks' => [__('Aufgaben & Support'), 'list-checks', __('Aufgaben anlegen und abhaken, Probleme melden, Support-Meldungen beantworten.')],
            'check' => [__('Prüfen & Pflegen'), 'shield-check', __('Systemprüfung lesen, Weiterleitungen und externe Quellen prüfen, Stand eingereichter Änderungen.')],
            'more' => [__('Weitere'), 'puzzle-piece', __('Werkzeuge von Erweiterungen und Sonstiges.')],
        ];
    }

    /** Werkzeug → [Gruppe, Recht (null = jeder mit Zugang), Beispiel-Schlüssel] */
    private const META = [
        'site_overview' => ['start', null], 'search_site' => ['start', null], 'search_knowledge' => ['start', null], 'geocode_address' => ['start', null],
        'get_settings' => ['settings', null], 'get_settings_schema' => ['settings', null], 'update_settings' => ['settings', 'settings.edit'],
        'get_opening_hours' => ['settings', null], 'set_opening_hours' => ['settings', 'settings.info'], 'set_notice' => ['settings', 'settings.info'],
        'get_design' => ['settings', null], 'set_design' => ['settings', 'design.edit'],
        'list_pages' => ['pages', null], 'get_page' => ['pages', null], 'list_block_types' => ['pages', null], 'list_revisions' => ['pages', null],
        'create_page' => ['pages', 'pages.edit'], 'update_page' => ['pages', 'pages.edit'], 'delete_page' => ['pages', 'pages.manage'],
        'add_block' => ['pages', 'pages.edit'], 'update_block' => ['pages', 'pages.edit'], 'move_block' => ['pages', 'pages.edit'], 'remove_block' => ['pages', 'pages.edit'],
        'replace_blocks' => ['pages', 'pages.edit'], 'publish_page' => ['pages', 'pages.publish'], 'discard_draft' => ['pages', 'pages.edit'], 'restore_revision' => ['pages', 'pages.edit'],
        'list_landings' => ['pages', null],
        'list_data_tables' => ['data', null], 'list_entries' => ['data', null], 'get_entry' => ['data', null], 'list_occurrences' => ['data', null],
        'save_entry' => ['data', 'data.edit'], 'delete_entry' => ['data', 'data.delete'], 'list_suggestions' => ['data', 'data.shared.manage'], 'set_pick' => ['data', 'data.shared.manage'],
        'list_table_templates' => ['data', 'data.schema'], 'create_table' => ['data', 'data.schema'],
        'list_media' => ['media', null], 'upload_media' => ['media', 'media.upload'], 'media_upload_link' => ['media', 'media.upload'], 'media_upload_status' => ['media', 'media.upload'],
        'update_media' => ['media', 'media.upload'], 'crop_media' => ['media', 'media.upload'],
        'translate_page' => ['translate', 'pages.edit'], 'translate_entry' => ['translate', 'data.edit'],
        'list_requests' => ['requests', 'requests.read'], 'set_request_status' => ['requests', 'requests.manage'], 'assign_request' => ['requests', 'requests.manage'],
        'list_request_assignees' => ['requests', 'requests.manage'], 'delete_request' => ['requests', 'requests.manage'],
        'push_overview' => ['push', 'push.view'], 'send_push' => ['push', 'push.send'], 'cancel_push' => ['push', 'push.send'],
        'task_list' => ['tasks', 'tasks.use'], 'task_create' => ['tasks', 'tasks.use'], 'task_done' => ['tasks', 'tasks.use'],
        'report_issue' => ['tasks', 'support.report'], 'support_list' => ['tasks', 'support.report'], 'support_get' => ['tasks', 'support.report'],
        'support_reply' => ['tasks', 'support.answer'], 'support_status' => ['tasks', 'support.manage'],
        'site_health' => ['check', 'system.manage'], 'list_redirects' => ['check', null], 'list_sources' => ['check', 'sources.manage'], 'sync_source' => ['check', 'sources.manage'],
        'get_change_status' => ['check', null],
    ];

    /** Zusätzliche Einordnung (Erweiterungen): Werkzeug => [Gruppe, Recht, Beispiele[]] */
    private static array $extra = [];

    public static function meta(string $tool, string $group, ?string $perm = null, array $examples = []): void
    {
        self::$extra[$tool] = [$group, $perm, $examples];
    }

    /** Darf die angemeldete Person das (bzw. wer kein Konto hat: nur Werkzeuge ohne Recht)? */
    private static bool $grantAll = false;

    private static function allowed(?string $perm): bool
    {
        if ($perm === null || self::$grantAll) return true;
        if (str_starts_with($perm, 'data.')) {
            foreach (Tables::content() as $t) if (can($perm, $t['handle'])) return true;
            return false;
        }
        return can($perm);
    }

    /**
     * Übersicht für die angemeldete Person. $all = auch nicht erlaubte Werkzeuge (mit Hinweis „braucht Recht …“).
     * @return list<array{key: string, title: string, icon: string, intro: string, examples: list<string>, tools: list<array>}>
     */
    public static function overview(bool $all = false, ?array $only = null): array
    {
        $groups = self::groups();
        $labels = [];
        foreach (\Core\Permissions::catalog() as $grp) foreach ((array) $grp as $k => $l) $labels[(string) $k] = (string) $l;
        $out = [];
        foreach (McpController::catalog()['tools'] as $t) {
            if ($only !== null && !in_array($t['name'], $only, true)) continue;
            [$g, $perm] = self::$extra[$t['name']] ?? self::META[$t['name']] ?? ['more', null];
            $ok = self::allowed($perm);
            if (!$ok && !$all) continue;
            $out[$g] ??= ['key' => $g, 'title' => $groups[$g][0] ?? $g, 'icon' => $groups[$g][1] ?? 'puzzle-piece', 'intro' => $groups[$g][2] ?? '', 'examples' => [], 'tools' => []];
            $out[$g]['tools'][] = ['name' => $t['name'], 'title' => $t['title'], 'description' => $t['description'],
                'kind' => $t['destructive'] ? 'delete' : ($t['write'] ? 'change' : 'read'), 'allowed' => $ok, 'perm' => $perm, 'perm_label' => $perm ? ($labels[$perm] ?? $perm) : ''];
        }
        foreach ($out as $g => &$grp) {
            $names = array_column(array_filter($grp['tools'], fn($x) => $x['allowed']), 'name');
            $grp['examples'] = self::examples($g, $names);
        }
        unset($grp);
        $order = array_flip(array_keys($groups));
        uasort($out, fn($a, $b) => ($order[$a['key']] ?? 99) <=> ($order[$b['key']] ?? 99));
        return array_values($out);
    }

    /** Beispiel-Aufträge einer Gruppe, mit echten Namen dieser Website gefüllt (nur für erlaubte Werkzeuge) */
    private static function examples(string $g, array $tools): array
    {
        $has = fn(string ...$n) => (bool) array_intersect($n, $tools);
        $site = site_name();
        $pages = array_values(array_filter(\Core\Pages::all(), fn($p) => ($p['type'] ?? 'page') === 'page' && empty($p['is_home'])));
        $page = (string) ($pages[0]['title'] ?? __('Über uns'));
        $tables = array_values(array_filter(Tables::content(), fn($t) => self::$grantAll || can('data.edit', $t['handle'])));
        $cal = array_values(array_filter($tables, fn($t) => \Core\Data\Calendar::enabled($t)))[0] ?? null;
        $other = array_values(array_filter($tables, fn($t) => !\Core\Data\Calendar::enabled($t)))[0] ?? null;
        $sing = fn(array $t) => (string) ($t['singular'] ?? $t['name']);
        $ex = [];
        switch ($g) {
            case 'start':
                if ($has('site_overview')) $ex[] = __('Gib mir einen Überblick über die Website „{site}“.', ['site' => $site]);
                if ($has('search_site')) $ex[] = __('Wo auf der Website steht etwas zu den Öffnungszeiten?');
                if ($has('search_knowledge')) $ex[] = __('Wie lege ich eine neue Seite an? Schau im Handbuch nach.');
                break;
            case 'pages':
                if ($has('get_page')) $ex[] = __('Lies die Seite „{page}“ und liste offene Platzhalter und Bilder ohne Alt-Text auf.', ['page' => $page]);
                if ($has('update_block')) $ex[] = __('Kürze den Einleitungstext auf „{page}“ auf zwei Sätze – als Entwurf.', ['page' => $page]);
                if ($has('create_page')) $ex[] = __('Leg eine neue Seite „Häufige Fragen“ mit fünf Fragen und Antworten an, noch nicht veröffentlichen.');
                if ($has('restore_revision')) $ex[] = __('Hol die Version von „{page}“ von gestern zurück.', ['page' => $page]);
                break;
            case 'data':
                if ($cal && $has('save_entry')) $ex[] = __('Leg in „{table}“ einen {singular} „Sommerfest“ am Samstag in zwei Wochen um 15 Uhr an.', ['table' => $cal['name'], 'singular' => $sing($cal)]);
                if ($other && $has('save_entry')) $ex[] = __('Erstelle aus diesen Stichpunkten einen neuen Eintrag in „{table}“ als Entwurf: …', ['table' => $other['name']]);
                if ($cal && $has('list_occurrences')) $ex[] = __('Was steht in „{table}“ im nächsten Monat an?', ['table' => $cal['name']]);
                elseif ($other && $has('list_entries')) $ex[] = __('Liste alle Einträge in „{table}“, die noch offline sind.', ['table' => $other['name']]);
                if ($has('delete_entry')) $ex[] = __('Lösche den doppelten Eintrag und häng die Verweise auf den anderen um.');
                break;
            case 'media':
                if ($has('update_media')) $ex[] = __('Ergänze fehlende Alt-Texte in der Mediathek – zeig mir die Vorschläge vorher.');
                if ($has('media_upload_link')) $ex[] = __('Ich möchte ein Foto hochladen und auf der Startseite einsetzen.');
                if ($has('crop_media')) $ex[] = __('Schneide das Titelbild auf 16:9 zu.');
                break;
            case 'translate':
                if ($has('translate_page')) $ex[] = __('Übersetze die Seite „{page}“ ins Englische.', ['page' => $page]);
                if ($has('translate_entry')) $ex[] = __('Übersetze die neuesten drei Einträge, die noch keine englische Fassung haben.');
                break;
            case 'settings':
                if ($has('update_settings')) $ex[] = __('Ändere die Telefonnummer im Kontakt auf 01234 56789.');
                if ($has('set_notice')) $ex[] = __('Setz einen Hinweis „Heute ab 14 Uhr geschlossen“ bis morgen früh.');
                if ($has('set_opening_hours')) $ex[] = __('Trag für Freitag 9–13 Uhr als Öffnungszeit ein.');
                if ($has('set_design')) $ex[] = __('Mach die Akzentfarbe etwas dunkler und prüf den Kontrast.');
                break;
            case 'requests':
                if ($has('list_requests')) $ex[] = __('Wie viele Anfragen sind seit Montag offen?');
                if ($has('assign_request')) $ex[] = __('Weise die neuen Anfragen der zuständigen Person zu.');
                break;
            case 'push':
                if ($has('send_push')) $ex[] = __('Plane eine Mitteilung für Freitag 8 Uhr: „Heute Sommerfest – wir freuen uns auf euch!“');
                break;
            case 'tasks':
                if ($has('task_create')) $ex[] = __('Leg mir eine Aufgabe an: Bilder der Galerie bis Monatsende tauschen.');
                if ($has('report_issue')) $ex[] = __('Melde dem Support, dass das Kontaktformular keine Bestätigung zeigt.');
                if ($has('support_list')) $ex[] = __('Welche Support-Meldungen warten auf eine Antwort?');
                break;
            case 'check':
                if ($has('site_health')) $ex[] = __('Gibt es Fehler in der Systemprüfung? Erkläre sie verständlich.');
                if ($has('list_redirects')) $ex[] = __('Wohin führt die alte Adresse /aktuelles/2019?');
                if ($has('get_change_status')) $ex[] = __('Wurden meine eingereichten Änderungen schon freigegeben?');
                break;
        }
        foreach (self::$extra as $tool => [$eg, , $exs]) if ($eg === $g && in_array($tool, $tools, true)) array_push($ex, ...array_map('strval', $exs));
        return array_slice($ex, 0, 4);
    }

    /** Zugänge der Person: ändert ein Zugang direkt oder reicht zur Freigabe ein? @return list<array{name: string, scope: string, mode: string}> */
    public static function myTokens(): array
    {
        $u = app()->auth->user();
        if (!$u) return [];
        try {
            $rows = app()->db->fetchAll('SELECT name, scope, review_mode FROM api_tokens WHERE user_id = ? AND (expires_at IS NULL OR expires_at > ?) ORDER BY id', [(int) $u['id'], now()]);
        } catch (\Throwable) {
            return [];
        }
        return array_map(fn($r) => ['name' => (string) $r['name'], 'scope' => (string) $r['scope'], 'mode' => (string) ($r['review_mode'] ?? 'direct')], $rows);
    }

    /**
     * Für MCP (Resource cms://capabilities, Prompt was_kannst_du): kompakt. $tools = Werkzeuge dieses Zugangs (Token-Konten haben alle
     * Rechte; ein Lese-Token sieht nur lesende Werkzeuge).
     */
    public static function forAgent(array $tools): array
    {
        self::$grantAll = true;
        try {
            return array_map(fn($g) => ['group' => $g['title'], 'about' => $g['intro'], 'examples' => $g['examples'],
                'tools' => array_map(fn($t) => $t['name'] . ' (' . $t['kind'] . ')', $g['tools'])], self::overview(false, $tools));
        } finally {
            self::$grantAll = false;
        }
    }
}
