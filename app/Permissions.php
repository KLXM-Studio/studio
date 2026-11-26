<?php
declare(strict_types=1);

namespace Core;

/**
 * Rollen und Berechtigungen.
 *
 * Rollen liegen in der Tabelle `roles` (key, name, description, permissions_json, tables_json, blocks_json, builtin).
 * „admin“ hat immer alle Rechte und ist nicht änderbar. Alle anderen Rollen sind frei einstellbar –
 * bei Datentabellen und Anfragen (Eingangs-Tabellen) optional nur für ausgewählte Tabellen.
 */
final class Permissions
{
    /** Alle Rechte, gruppiert (Bezeichnungen übersetzbar) */
    public static function catalog(): array
    {
        return [
            __('Seiten') => [
                'pages.edit' => __('Seiten bearbeiten (als Entwurf)'),
                'pages.publish' => __('Seiten veröffentlichen'),
                'pages.manage' => __('Seiten anlegen, verschieben, löschen, Seiteneinstellungen'),
                // Website-Einstellungen des Kits in zwei Stufen (Core\SiteSettings): Angaben (ungefährlich) und alles
                'settings.info' => __('Website-Angaben pflegen (Kontakt, Öffnungszeiten, Hinweisbalken, Social Media, Fußbereich)'),
                'settings.edit' => __('{title} ändern – alles, auch Logo, Rechtstexte, SEO und Schalter des Kits', ['title' => app()->theme->settingsTitle()]),
                // Weiterleitungen und 404-Protokoll (Core\Redirects) – zusätzlich Funktion „redirects“
                'redirects.manage' => __('Weiterleitungen und 404-Protokoll verwalten'),
            ],
            __('Medien') => [
                'media.upload' => __('Dateien hochladen und bearbeiten'),
                'media.delete' => __('Dateien löschen'),
                'media.shared' => __('Geteilte Medien (Pools) pflegen – hochladen, Alt-Texte, Zuschnitte, Sammlungen'),
            ],
            __('Daten') => [
                'data.edit' => __('Einträge anlegen und bearbeiten'),
                'data.publish' => __('Einträge veröffentlichen'),
                'data.delete' => __('Einträge löschen'),
                'data.schema' => __('Tabellen und Felder ändern'),
                'data.import' => __('Einträge importieren (CSV, Excel, vCard, ICS)'),
                'data.export' => __('Einträge exportieren (CSV, Excel, vCard, ICS)'),
                'data.shared.manage' => __('Geteilte Daten verwalten (Tabellen für mehrere Websites anlegen, Mitglieder zuordnen)'),
                // Externe Quellen (Core\Sources) – zusätzlich Funktion „sources“ der Website
                'sources.manage' => __('Externe Quellen einrichten und abrufen (Feeds, APIs, OpenImmo)'),
            ],
            // Gestaltung der Blöcke in zwei Stufen (Core\BlockGuard): „im Rahmen des Kits“ = Abschnitts-Optionen (Hintergrund, Abstände,
            // Trennlinie, Höhe, Hintergrundbild, Effekt) und Varianten – haben alle Rollen mit pages.edit (Migration, nichts ändert sich);
            // „voll“ zusätzlich Blöcke sperren/entsperren, gesperrte und nicht erlaubte Blöcke ändern, Listen-Vorlagen (Core\Blocks\ListTemplates)
            __('Gestaltung') => [
                'design.blocks.basic' => __('Blöcke gestalten im Rahmen des Kits: Variante, Hintergrund, Abstände und weitere Abschnitts-Optionen'),
                'design.blocks' => __('Blöcke gestalten (voll): zusätzlich Blöcke sperren und entsperren, gesperrte Blöcke ändern, Listen-Vorlagen anlegen und als Standard festlegen'),
            ],
            __('Eingang') => [
                'requests.read' => __('Online-Anfragen lesen (mit Schlüssel)'),
                'requests.manage' => __('Anfragen erledigen und löschen'),
            ],
            __('Administration') => [
                'system.manage' => __('Grundeinstellungen'),
                // Haupt-Admin: Funktionen & Erweiterungen der Website schalten (Core\Features) – im Netzwerk nur mit Freigabe
                'system.features' => __('Funktionen & Erweiterungen ein- und ausschalten (Haupt-Admin)'),
                'design.edit' => __('Design (Style-Editor)'),
                'blocks.build' => __('Eigene Blöcke bauen und freigeben (Block-Designer)'),
                'users.manage' => __('Benutzer und Rollen'),
                'api.manage' => __('API & MCP'),
            ],
            // Netzwerk-Verwaltung (nur Netzwerk-Konten, Rolle network): Website-Vorlagen und Kopien (Core\Blueprint)
            __('Netzwerk') => [
                'network.blueprints' => __('Website-Vorlagen verwalten, Websites aus Vorlagen anlegen und kopieren (Netzwerk-Verwaltung)'),
            ],
            // KI (Core\AI\Ai) – zusätzlich Funktion „ai“ und Schalter der Website (Grundeinstellungen → KI)
            __('KI') => [
                'ai.use' => __('KI-Funktionen nutzen (Texte, Übersetzung, SEO, Alt-Texte)'),
                // Prüf-Ebene „Eingereicht“ (Core\Review\Queue) – Standard: nur Administration
                'review.manage' => __('Eingereichte Änderungen von API, MCP und KI prüfen und freigeben'),
            ],
            // Support & Wissensdatenbank (Core\Support) – zentral für alle Websites
            __('Support') => [
                'support.report' => __('Probleme melden, eigene Meldungen sehen, Wissensdatenbank lesen'),
                'support.answer' => __('Fragen stellen und beantworten'),
                'support.manage' => __('Alle Meldungen dieser Website sehen und beantworten (auf der Hauptwebsite: Support-Team für alle Websites)'),
            ],
            // Chat zwischen Benutzern (Core\Chat) – zusätzlich Funktion „chat“ der Website
            __('Chat') => [
                'chat.use' => __('Chat nutzen (Direktnachrichten, Kanäle der Website)'),
                'chat.manage' => __('Kanäle anlegen, Mitglieder festlegen, Nachrichten in Kanälen entfernen'),
            ],
            // Aufgaben der Redaktion (Core\Tasks): abhaken darf jede zuständige Person auch ohne diese Rechte
            __('Aufgaben') => [
                'tasks.use' => __('Aufgaben für sich selbst anlegen'),
                'tasks.manage' => __('Aufgaben für andere anlegen, zuweisen, bearbeiten und löschen; alle Aufgaben sehen'),
            ],
            // Push-Benachrichtigungen (Core\Push) – zusätzlich Funktion „push“ der Website
            __('Mitteilungen') => [
                'push.view' => __('Mitteilungen: Verlauf, Kanäle und Statistik ansehen'),
                'push.send' => __('Mitteilungen verfassen und senden, Kanäle und Website-Einbindung verwalten'),
            ],
        ] + Extensions::permissions();
    }

    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::catalog())));
    }

    /**
     * Rechte einer Rolle beim Speichern: angehakte Rechte, soweit es sie gibt, plus die bisherigen Rechte, die die Oberfläche nicht
     * zeigt (z. B. einer gerade abgeschalteten Erweiterung) – sonst gingen sie beim Speichern verloren. Aufräumen: roles:clean.
     */
    public static function keepHidden(array $posted, array $old): array
    {
        $all = self::all();
        $known = array_values(array_intersect(array_map('strval', $posted), $all));
        $hidden = array_values(array_filter(array_map('strval', $old), fn($p) => $p !== '*' && !in_array($p, $all, true)));
        return array_values(array_unique([...$known, ...$hidden]));
    }

    /** Vorgaben beim ersten Start: Standardrollen – Rechte danach frei änderbar (außer admin/network), aber nie löschbar (builtin) */
    public static function defaults(): array
    {
        return [
            'admin' => ['name' => 'Administration', 'description' => 'Alle Rechte', 'permissions' => ['*'], 'builtin' => 1],
            // Zentrale Konten der Netzwerk-Website (Core\Network) – nicht in „Benutzer & Rollen“ vergebbar
            'network' => ['name' => 'Netzwerk-Administration', 'description' => 'Alle Websites und alle Daten (zentral verwaltet)', 'permissions' => ['*'], 'builtin' => 1],
            'editor' => ['name' => 'Redaktion', 'description' => 'Inhalte pflegen und veröffentlichen',
                // Neue Websites: nur Website-Angaben (settings.info); bestehende Rollen behalten ihre Rechte (seed legt nur Fehlendes an)
                'permissions' => ['pages.edit', 'pages.publish', 'pages.manage', 'design.blocks.basic', 'settings.info', 'media.upload', 'media.delete',
                    'data.edit', 'data.publish', 'data.delete', 'requests.read', 'requests.manage', 'support.report', 'support.answer', 'ai.use', 'chat.use', 'push.view', 'tasks.use'], 'builtin' => 1],
            'author' => ['name' => 'Autorin / Autor', 'description' => 'Entwürfe schreiben, nichts veröffentlichen',
                'permissions' => ['pages.edit', 'design.blocks.basic', 'media.upload', 'data.edit', 'support.report'], 'builtin' => 1],
            'requests' => ['name' => 'Anfragen bearbeiten', 'description' => 'Nur Online-Anfragen',
                'permissions' => ['requests.read', 'requests.manage', 'support.report'], 'builtin' => 1],
        ];
    }

    /**
     * Fehlende Standardrollen anlegen und als Standardrolle markieren (idempotent). Ältere Installationen hatten nur admin und
     * network als builtin – Redaktion, Autorin/Autor und Anfragen bearbeiten ließen sich dort löschen.
     */
    public static function seed(Database $db): void
    {
        $keys = array_keys(array_filter(self::defaults(), fn($r) => !empty($r['builtin'])));
        $in = 'builtin = 0 AND rkey IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
        if ($db->fetchValue("SELECT COUNT(*) FROM roles WHERE $in", $keys)) $db->query("UPDATE roles SET builtin = 1 WHERE $in", $keys);   // nur schreiben, wenn nötig (läuft bei jedem Start)
        foreach (self::defaults() as $key => $r) {
            if (!$db->fetchValue('SELECT COUNT(*) FROM roles WHERE rkey = ?', [$key])) {
                $db->insert('roles', ['rkey' => $key, 'name' => $r['name'], 'description' => $r['description'],
                    'permissions_json' => json_encode($r['permissions']), 'tables_json' => null, 'builtin' => (int) ($r['builtin'] ?? 0)]);   // blocks_json: Standard null (alle)
            }
        }
    }

    public static function roles(): array
    {
        $out = [];
        foreach (app()->db->fetchAll('SELECT * FROM roles ORDER BY builtin DESC, name') as $r) {
            $out[$r['rkey']] = self::hydrate($r);
        }
        return $out;
    }

    public static function role(string $key): ?array
    {
        $r = app()->db->fetch('SELECT * FROM roles WHERE rkey = ?', [$key]);
        return $r ? self::hydrate($r) : null;
    }

    /**
     * Wirksame Rolle eines Kontos: Hauptrolle (users.role) plus Zusatzrollen (users.roles_extra, JSON-Liste) – Rechte addieren sich.
     * Rechte: Vereinigung („*“ gewinnt); Tabellen bzw. Blöcke: uneingeschränkt (null), sobald eine Rolle uneingeschränkt ist, sonst
     * Vereinigung; „nur bearbeiten“-Blöcke vereinigt. Schlüssel bleibt der der Hauptrolle, der Name nennt alle („Redaktion + Newsletter“).
     */
    public static function effective(array $user): ?array
    {
        $base = self::role((string) ($user['role'] ?? '')) ?? self::role('author');
        $extra = array_values(array_filter(array_map('strval', (array) (json_decode((string) ($user['roles_extra'] ?? ''), true) ?: [])), fn($k) => $k !== '' && $k !== 'network' && $k !== ($base['key'] ?? '')));
        if (!$base || !$extra) return $base;
        $r = $base;
        foreach ($extra as $k) {
            $x = self::role($k);
            if (!$x) continue;
            $r['permissions'] = array_values(array_unique([...$r['permissions'], ...$x['permissions']]));
            $r['tables'] = $r['tables'] === null || $x['tables'] === null ? null : array_values(array_unique([...$r['tables'], ...$x['tables']]));
            $r['blocks'] = $r['blocks'] === null || $x['blocks'] === null ? null : array_values(array_unique([...$r['blocks'], ...$x['blocks']]));
            $r['blocks_edit'] = array_values(array_unique([...(array) ($r['blocks_edit'] ?? []), ...(array) ($x['blocks_edit'] ?? [])]));
            $r['name'] .= ' + ' . $x['name'];
            $r['extra'][] = $x['key'];
        }
        if (in_array('*', $r['permissions'], true)) $r['permissions'] = ['*'];
        return $r;
    }

    private static function hydrate(array $r): array
    {
        return ['key' => $r['rkey'], 'name' => $r['name'], 'description' => (string) $r['description'],
            'permissions' => json_decode((string) $r['permissions_json'], true) ?: [],
            'tables' => $r['tables_json'] === null ? null : (json_decode((string) $r['tables_json'], true) ?: []),
            // Erlaubte Blöcke (Core\BlockGuard): null = alle, die die Website erlaubt; Liste = nur diese Typen einfügen und ändern
            'blocks' => ($r['blocks_json'] ?? null) === null ? null : array_values(array_map('strval', (array) (json_decode((string) $r['blocks_json'], true) ?: []))),
            // Zusätzlich „nur bearbeiten“: bestehende Blöcke dieser Typen ändern, keine neuen einfügen (z. B. Preisvergleich pflegen)
            'blocks_edit' => array_values(array_map('strval', (array) (json_decode((string) ($r['blocks_edit_json'] ?? '[]'), true) ?: []))),
            'builtin' => (bool) $r['builtin']];
    }

    /**
     * Einmalige Migration beim Anlegen der Spalte roles.blocks_json (Database::migrate): Rollen mit „Seiten bearbeiten“ bekommen
     * „Blöcke gestalten im Rahmen des Kits“ – sie durften Abschnitts-Optionen und Varianten schon immer ändern.
     * @return list<string> geänderte Rollen
     */
    public static function grantDesignBasic(Database $db): array
    {
        $out = [];
        foreach ($db->fetchAll('SELECT rkey, permissions_json FROM roles') as $r) {
            $perms = json_decode((string) $r['permissions_json'], true) ?: [];
            if (!is_array($perms) || in_array('*', $perms, true) || !in_array('pages.edit', $perms, true) || in_array('design.blocks.basic', $perms, true)) continue;
            $perms[] = 'design.blocks.basic';
            $db->update('roles', ['permissions_json' => json_encode(array_values($perms))], 'rkey = :k', ['k' => (string) $r['rkey']]);
            $out[] = (string) $r['rkey'];
        }
        return $out;
    }

    /**
     * Darf die Rolle diesen Blocktyp neu einfügen? Rahmen der Website (Features::allowsBlock) und – wenn gesetzt – die
     * „Erlaubten Blöcke“ der Rolle. Rollen mit allen Rechten: nur der Rahmen.
     */
    public static function allowsBlock(?array $role, string $type): bool
    {
        if (!Features::allowsBlock($type)) return false;
        if (!$role) return false;
        if (in_array('*', $role['permissions'], true) || !is_array($role['blocks'] ?? null)) return true;
        return in_array($type, $role['blocks'], true);
    }

    /** Darf die Rolle das? Optional für eine bestimmte Datentabelle. */
    /**
     * Darf die angemeldete Person Einstellungen ändern, die für die ganze Installation gelten (Kits, Schriften, geteilte Medien,
     * geplante Aufgaben, Sicherung, Dateien-Integrität)? Mit mehreren Websites nur Netzwerk-Konten (Rolle network) – die Seiten
     * liegen dann im Netzwerk-Bereich (Core\Network\Area); die Administration einer Website (auch der Netzwerk-Website) verwaltet
     * nur ihre Website. Einzelinstallation: Integratoren und die Administration (system.manage) wie bisher.
     */
    public static function installationAdmin(): bool
    {
        if (Sites::multi()) return Network\Network::isNetworkUser();
        return Features::integrator() || (can('system.manage') && Network\Network::isNetworkSite());
    }

    public static function allows(?array $role, string $perm, ?string $table = null): bool
    {
        if (!$role) return false;
        // Funktionsumfang der Website (config) schlägt jede Rolle
        if (!Features::allowsPermission($perm)) return false;
        if (in_array('*', $role['permissions'], true)) return true;
        // „Website-Einstellungen“ (alles) schließt „Website-Angaben“ ein (Core\SiteSettings)
        if ($perm === 'settings.info' && in_array('settings.edit', $role['permissions'], true)) return true;
        // „Blöcke gestalten (voll)“ schließt die Stufe „im Rahmen des Kits“ ein (Core\BlockGuard)
        if ($perm === 'design.blocks.basic' && in_array('design.blocks', $role['permissions'], true)) return true;
        if (!in_array($perm, $role['permissions'], true)) return false;
        // Tabellenauswahl der Rolle gilt für Daten-Rechte und für Anfragen (Eingangs-Tabellen, z. B. nur „Rezeptanfragen“)
        if ($table !== null && is_array($role['tables'])
            && ((str_starts_with($perm, 'data.') && $perm !== 'data.schema') || str_starts_with($perm, 'requests.'))) {
            if (in_array($table, $role['tables'], true)) return true;
            // Auswahlliste einer Tabelle (Core\Data\OptionSource): wer die Haupttabelle bearbeiten darf, pflegt auch ihre Liste –
            // außer die Liste ist gesperrt (Einstellung „Liste nur über Felder & Einstellungen ändern“)
            if (in_array($perm, ['data.edit', 'data.delete', 'data.publish'], true)) {
                foreach (Data\OptionSource::parentsOf($table) as $parent) if (in_array($parent, $role['tables'], true)) return true;
            }
            return false;
        }
        return true;
    }
}
