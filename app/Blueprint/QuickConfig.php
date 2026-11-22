<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

/**
 * Schnelleinrichtung einer Website aus einer Vorlage (Verwaltung → /admin/schnelleinrichtung, CLI site:quick-config).
 *
 * Wenige Schritte, alles später änderbar; welche Schritte es gibt, legt die Vorlage fest (manifest.json → quick_config.steps):
 *   org       Organisation: Name, Kurzname, Claim, Rechtsform/Register, Anschrift, Telefon, E-Mail → Einstellungen des Kits,
 *             Impressum, Fuß, Kontaktdaten, Absendername der E-Mails; füllt alle Platzhalter (Core\Blueprint\Placeholders)
 *   look      Erscheinungsbild: Logo, Akzentfarbe mit Kontrastprüfung (Design-Werte des Kits), Vorschau
 *   region    Gebiet: Region bzw. Bundesland → Kartenpunkt der Organisation, „In deiner Nähe“, Gebietskarten (Filter blueprint.region)
 *   network   Netzwerk: geteilten Tabellen der Vorlage beitreten (Beitritt mit Plan, Core\Data\Network)
 *   accounts  Konten: erste Personen einladen (E-Mail + Rolle, Core\Invites)
 *   demo      Demoinhalte behalten oder entfernen (Core\Blueprint\Demo)
 *   mail      E-Mail: eigenes SMTP-Konto oder Konto des Netzwerks, Testmail
 *   legal     Recht: Impressum und Datenschutz mit den Angaben füllen, Aufgabe „bitte prüfen“
 * Abschluss: offene Punkte werden Aufgaben (Core\Tasks), der Wartungsmodus endet. Bis dahin sehen Besucher die Wartungsseite.
 *
 * Zustand: Einstellung sys.quick_config {open, steps, done: {schritt: Zeit}, values, started_at, finished_at}.
 */
final class QuickConfig
{
    public const KEY = 'sys.quick_config';
    public const STEPS = ['org', 'look', 'region', 'network', 'accounts', 'demo', 'mail', 'legal'];
    /** Bundesländer mit Mittelpunkt (Kartenpunkt der Organisation, solange keine Anschrift geokodiert ist) */
    public const REGIONS = [
        'Baden-Württemberg' => '48.66, 9.35', 'Bayern' => '48.79, 11.50', 'Berlin' => '52.52, 13.40', 'Brandenburg' => '52.41, 12.53',
        'Bremen' => '53.08, 8.80', 'Hamburg' => '53.55, 9.99', 'Hessen' => '50.65, 9.16', 'Mecklenburg-Vorpommern' => '53.61, 12.43',
        'Niedersachsen' => '52.64, 9.85', 'Nordrhein-Westfalen' => '51.43, 7.66', 'Rheinland-Pfalz' => '50.12, 7.31', 'Saarland' => '49.40, 6.95',
        'Sachsen' => '51.10, 13.20', 'Sachsen-Anhalt' => '51.95, 11.69', 'Schleswig-Holstein' => '54.22, 9.70', 'Thüringen' => '50.86, 11.05',
    ];
    /** Amtlicher Gemeindeschlüssel (Land, zweistellig) → Bundesland – Gebietskarten nutzen die Schlüssel, --region=06 geht auch */
    public const REGION_CODES = ['01' => 'Schleswig-Holstein', '02' => 'Hamburg', '03' => 'Niedersachsen', '04' => 'Bremen', '05' => 'Nordrhein-Westfalen',
        '06' => 'Hessen', '07' => 'Rheinland-Pfalz', '08' => 'Baden-Württemberg', '09' => 'Bayern', '10' => 'Saarland', '11' => 'Berlin',
        '12' => 'Brandenburg', '13' => 'Mecklenburg-Vorpommern', '14' => 'Sachsen', '15' => 'Sachsen-Anhalt', '16' => 'Thüringen'];
    /** Gängige Abkürzungen der Bundesländer (Platzhalter {{region.short}}); zweistellige werden beim Export nicht automatisch ersetzt */
    public const REGION_SHORT = ['Schleswig-Holstein' => 'SH', 'Hamburg' => 'HH', 'Niedersachsen' => 'NDS', 'Bremen' => 'HB', 'Nordrhein-Westfalen' => 'NRW',
        'Hessen' => 'HE', 'Rheinland-Pfalz' => 'RLP', 'Baden-Württemberg' => 'BW', 'Bayern' => 'BY', 'Saarland' => 'SL', 'Berlin' => 'BE',
        'Brandenburg' => 'BB', 'Mecklenburg-Vorpommern' => 'MV', 'Sachsen' => 'SN', 'Sachsen-Anhalt' => 'ST', 'Thüringen' => 'TH'];

    /**
     * Region aus einer Eingabe: Bundesland (auch Abkürzung wie „NRW“ oder Schlüssel „05“) oder freie Angabe.
     * @return array{name: string, short: string, code: ?string}
     */
    public static function region(string $in): array
    {
        $in = trim($in);
        $name = ctype_digit($in) ? (self::REGION_CODES[str_pad($in, 2, '0', STR_PAD_LEFT)] ?? null) : null;
        if ($name === null) foreach (self::REGION_SHORT as $n => $s) if (strcasecmp($in, $s) === 0 || strcasecmp($in, $n) === 0 || ($s === 'NRW' && strcasecmp($in, 'NW') === 0)) { $name = $n; break; }
        if ($name === null) return ['name' => $in, 'short' => $in, 'code' => null];
        // Zweistellige Abkürzungen („HE“) liest kaum jemand – dann der Name
        $short = mb_strlen(self::REGION_SHORT[$name]) >= 3 ? self::REGION_SHORT[$name] : $name;
        return ['name' => $name, 'short' => $short, 'code' => (string) array_search($name, self::REGION_CODES, true)];
    }

    /**
     * Rückmeldungen der Erweiterungen (Filter blueprint.apply / blueprint.region): Texte werden Meldungen, Einträge
     * ['task' => Titel, 'link' => '/admin/…', 'body' => …] werden Aufgaben. @return list<string>
     */
    public static function handleLines(mixed $lines, ?array $user = null): array
    {
        $out = [];
        foreach (is_array($lines) ? $lines : [] as $l) {
            if (is_string($l)) { $out[] = $l; continue; }
            if (is_array($l) && trim((string) ($l['task'] ?? '')) !== '') {
                self::task(mb_substr(trim((string) $l['task']), 0, 180), (string) ($l['body'] ?? __('Offener Punkt aus der Schnelleinrichtung der Website-Vorlage.')),
                    (string) ($l['link'] ?? ''), \Core\Tasks\Tasks::actor($user, $user ? 'admin' : 'cli'));
                $out[] = __('Aufgabe angelegt: {title}', ['title' => (string) $l['task']]);
            }
        }
        return $out;
    }

    /** Felder des Schritts „Organisation“: Eingabe → Platzhalter */
    public const ORG_FIELDS = ['name' => 'org.name', 'short' => 'org.short', 'claim' => 'org.claim', 'legal_form' => 'org.legal_form', 'register' => 'org.register',
        'street' => 'org.street', 'zip' => 'org.zip', 'city' => 'org.city', 'phone' => 'org.phone', 'email' => 'org.email',
        'representatives' => 'org.representatives', 'responsible' => 'org.responsible'];

    public static function state(): array
    {
        $s = (array) app()->settings->get(self::KEY, []);
        return $s + ['open' => false, 'steps' => [], 'done' => [], 'values' => [], 'started_at' => null, 'finished_at' => null];
    }

    public static function open(): bool
    {
        return !empty(self::state()['open']);
    }

    /** Schritte dieser Website (Reihenfolge wie STEPS) */
    public static function steps(): array
    {
        $s = self::state()['steps'];
        return array_values(array_intersect(self::STEPS, $s ?: self::STEPS));
    }

    public static function label(string $step): string
    {
        return match ($step) {
            'org' => __('Organisation'), 'look' => __('Erscheinungsbild'), 'region' => __('Gebiet'), 'network' => __('Netzwerk'),
            'accounts' => __('Konten'), 'demo' => __('Demoinhalte'), 'mail' => __('E-Mail'), 'legal' => __('Recht'), default => $step,
        };
    }

    public static function icon(string $step): string
    {
        return ['org' => 'buildings', 'look' => 'palette', 'region' => 'map-trifold', 'network' => 'share-network', 'accounts' => 'users-three',
            'demo' => 'sparkle', 'mail' => 'envelope-simple', 'legal' => 'scales'][$step] ?? 'circle';
    }

    /** Schnelleinrichtung beginnen: Zustand anlegen, Website in den Wartungsmodus (Besucher sehen „wird eingerichtet“) */
    public static function start(array $steps, array $values = []): void
    {
        $steps = array_values(array_intersect(self::STEPS, $steps)) ?: self::STEPS;
        // Ohne Netzwerk-Bezüge in der Vorlage gibt es keinen Schritt „Netzwerk“
        if (!(array) (app()->settings->get(Apply::KEY, [])['network'] ?? [])) $steps = array_values(array_diff($steps, ['network']));
        app()->settings->set(self::KEY, ['open' => true, 'steps' => $steps, 'done' => [], 'values' => $values, 'started_at' => date('c'), 'finished_at' => null]);
        app()->settings->set('sys.maintenance', true);
        if (trim((string) app()->settings->get('sys.maintenance_text', '')) === '') app()->settings->set('sys.maintenance_text', lt('Diese Website wird gerade eingerichtet.'));
    }

    private static function save(array $s): void
    {
        app()->settings->set(self::KEY, $s);
    }

    private static function done(string $step, array $values = []): void
    {
        $s = self::state();
        $s['done'][$step] = date('c');
        $s['values'] = array_merge((array) $s['values'], $values);
        self::save($s);
    }

    /** Schritt als erledigt merken (z. B. Werte schon aus site:create) */
    public static function markDone(string $step, array $values = []): void
    {
        if (in_array($step, self::STEPS, true)) self::done($step, $values);
    }

    /** Werte merken, ohne einen Schritt zu erledigen (Vorbelegung) */
    public static function remember(array $values): void
    {
        $s = self::state();
        $s['values'] = array_merge((array) $s['values'], $values);
        self::save($s);
    }

    /** Aufgabe aus der Vorlage anlegen (einmal je Titel, solange offen) */
    public static function addTask(string $title, string $body, string $link, array $actor): void
    {
        if (\Core\Tasks\Tasks::cleanLink($link) === false) $link = '';
        self::task($title, $body, $link, $actor);
    }

    /** Werte der Platzhalter (bisher gesammelt) */
    public static function values(): array
    {
        return array_filter((array) self::state()['values'], 'is_string');
    }

    /**
     * Einen Schritt speichern. $in: Eingaben des Formulars bzw. der Kommandozeile, $user: angemeldete Person (Einladungen, Testmail).
     * @return array{errors: array<string, string>, messages: list<string>}
     */
    public static function saveStep(string $step, array $in, ?array $user = null): array
    {
        if (!in_array($step, self::STEPS, true)) return ['errors' => ['_' => __('Unbekannter Schritt.')], 'messages' => []];
        return match ($step) {
            'org' => self::saveOrg($in),
            'look' => self::saveLook($in),
            'region' => self::saveRegion($in, $user),
            'network' => self::saveNetwork($in, $user),
            'accounts' => self::saveAccounts($in, $user),
            'demo' => self::saveDemo($in),
            'mail' => self::saveMail($in, $user),
            'legal' => self::saveLegal($user),
        };
    }

    private static function clean(mixed $v, int $max = 191): string
    {
        return mb_substr(trim((string) preg_replace('~\s+~u', ' ', strip_tags((string) $v))), 0, $max);
    }

    private static function saveOrg(array $in): array
    {
        $values = [];
        $errors = [];
        foreach (self::ORG_FIELDS as $f => $key) if (array_key_exists($f, $in)) $values[$key] = self::clean($in[$f], in_array($f, ['claim', 'representatives', 'responsible'], true) ? 240 : 191);
        // Vorbelegung aus site:create (--name, --short …) gilt, wenn die Eingabe das Feld nicht nennt – nicht erneut abfragen
        if (!array_key_exists('name', $in)) $values['org.name'] = (string) (self::values()['org.name'] ?? '');
        if (($values['org.name'] ?? '') === '') $errors['name'] = __('Bitte den Namen der Organisation angeben.');
        if (($values['org.email'] ?? '') !== '' && !filter_var($values['org.email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = __('Ungültige E-Mail-Adresse.');
        foreach ((array) (app()->settings->get(Apply::KEY, [])['placeholders'] ?? []) as $k => $p) {
            if (is_array($p) && ($p['step'] ?? 'org') === 'org' && isset($in['ph'][$k])) $values[(string) $k] = self::clean($in['ph'][$k], 200);
        }
        if ($errors) return ['errors' => $errors, 'messages' => []];
        $all = array_merge(self::values(), array_filter($values, fn($v) => $v !== ''));
        Apply::applyParams([], $all);
        // Absendername der E-Mails = Name der Organisation (Konto „Standard“, solange keiner gesetzt ist)
        $fromName = trim((string) app()->settings->get('sys.mail_from_name', ''));
        if ($fromName === '' || $fromName === 'Website') app()->settings->set('sys.mail_from_name', $all['org.name']);
        self::done('org', $values);
        return ['errors' => [], 'messages' => [__('Angaben der Organisation gespeichert – Impressum, Fußbereich und Kontaktdaten sind aktualisiert.')]];
    }

    private static function saveLook(array $in): array
    {
        $msg = [];
        if (!empty($in['logo_file']) && is_file((string) $in['logo_file'])) {
            $err = self::setLogo((string) $in['logo_file'], (string) ($in['logo_name'] ?? 'logo.png'));
            if ($err) return ['errors' => ['logo' => $err], 'messages' => []];
            $msg[] = __('Logo übernommen.');
        }
        if (trim((string) ($in['color'] ?? '')) !== '') {
            $c = \Core\Design::color($in['color']);
            if ($c === null) return ['errors' => ['color' => __('Bitte eine Farbe als #RRGGBB angeben.')], 'messages' => []];
            // Palette: Eingabe, sonst die zuletzt gewählte (z. B. site:create --palette=calm), sonst „Ausgewogen“
            $variant = self::palette((string) ($in['palette'] ?? ''));
            $r = self::setAccent($c, $variant);
            $msg[] = $r['adjusted'] ? __('Farben übernommen (Palette „{palette}“) – Akzent für gute Lesbarkeit angepasst ({color}).', ['palette' => Palette::label($variant), 'color' => $r['color']])
                : __('Farben übernommen (Palette „{palette}“).', ['palette' => Palette::label($variant)]);
            self::done('look', ['look.palette' => $variant, 'look.color' => $c]);
        }
        self::done('look');
        return ['errors' => [], 'messages' => $msg ?: [__('Erscheinungsbild bleibt wie in der Vorlage.')]];
    }

    /** Gültige Palette: $given, sonst die gespeicherte (look.palette), sonst 'balanced' */
    public static function palette(string $given = ''): string
    {
        foreach ([$given, (string) (self::values()['look.palette'] ?? '')] as $v) if (isset(Palette::VARIANTS[$v])) return $v;
        return 'balanced';
    }

    /**
     * Farben aus der Akzentfarbe setzen (Core\Blueprint\Palette): Akzent und alle abhängigen Farben des Kits hell und dunkel,
     * je Token mit Kontrastprüfung. Nur in dieser (neuen) Website. @return array{color: string, adjusted: bool, set: list<string>, fixed: list<string>}
     */
    public static function setAccent(string $color, string $variant = 'balanced'): array
    {
        return Palette::apply($color, $variant);
    }

    /** Logo aus einer Datei in die Mediathek und als Logo des Kits (Einstellung „logo“) – Fehlertext oder null */
    public static function setLogo(string $file, string $name = 'logo.png'): ?string
    {
        $tmp = Package::tempDir() . '/' . (preg_replace('~[^a-z0-9._-]~i', '-', basename($name)) ?: 'logo.png');
        copy($file, $tmp);
        [$m, $err] = \Core\Media::import($tmp, basename($tmp), __('Logo'), ['title' => __('Logo'), 'require_alt' => false, 'graphic' => true]);
        Package::remove(dirname($tmp));
        if (!$m) return (string) $err;
        app()->settings->set('logo', (int) $m['id']);
        \Core\PageCache::clear();
        return null;
    }

    private static function saveRegion(array $in, ?array $user = null): array
    {
        $raw = self::clean($in['region'] ?? '', 120);
        if ($raw === '') return ['errors' => ['region' => __('Bitte eine Region wählen oder eingeben.')], 'messages' => []];
        $r = self::region($raw);
        $region = $r['name'];
        $values = ['region.name' => $region, 'region.short' => $r['short']];
        Apply::applyParams([], array_merge(self::values(), $values));
        app()->settings->set('org.region', $region);
        // Kartenpunkt der Organisation (Feld „geo“ des Kits) auf die Mitte des Bundeslands, solange keiner gesetzt ist
        if (isset(self::REGIONS[$region]) && trim((string) app()->settings->get('geo', '')) === '') app()->settings->set('geo', self::REGIONS[$region]);
        // Erweiterungen (z. B. Gebietskarten: Ausschnitt der Region, Beispiel-Zuordnung, Aufgabe „Gebiete zuordnen“)
        $lines = \Core\Extensions::filter('blueprint.region', [], ['region' => $region, 'code' => $r['code'], 'short' => $r['short'], 'blueprint' => app()->settings->get(Apply::KEY, []),
            'values' => array_merge(self::values(), $values)]);
        self::done('region', $values);
        return ['errors' => [], 'messages' => array_merge([__('Gebiet „{region}“ gespeichert.', ['region' => $region])], self::handleLines($lines, $user))];
    }

    private static function saveNetwork(array $in, ?array $user): array
    {
        $msg = [];
        $errors = [];
        $join = array_values(array_filter(array_map('strval', (array) ($in['join'] ?? []))));
        foreach (Network::refs() as $ref) {
            if (!in_array($ref['key'], $join, true)) continue;
            $res = Network::join($ref, $user);
            if ($res['error'] !== null) $errors['join_' . $ref['key']] = $res['error'];
            array_push($msg, ...$res['lines']);
        }
        if (!$errors) self::done('network');
        return ['errors' => $errors, 'messages' => $msg ?: [__('Keine Tabellen ausgewählt – Beitritt geht später unter Daten.')]];
    }

    private static function saveAccounts(array $in, ?array $user): array
    {
        $msg = [];
        $errors = [];
        $role = $user ? \Core\Permissions::role((string) ($user['role'] ?? '')) : \Core\Permissions::role('admin');
        foreach (array_values((array) ($in['invite'] ?? [])) as $i => $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') continue;
            $res = \Core\Invites::create(['email' => $email, 'role' => (string) ($row['role'] ?? 'editor'), 'name' => (string) ($row['name'] ?? '')], $user, $role);
            if (!isset($res['token'])) { $errors['invite_' . $i] = $email . ': ' . (string) reset($res); continue; }
            $inv = \Core\Invites::get((int) $res['id']);
            $sent = $inv ? \Core\Invites::send($inv, $res['token']) : ['delivered' => false, 'error' => null];
            $msg[] = $sent['delivered'] ? __('Einladung an {email} gesendet.', ['email' => $email]) : __('Einladung an {email} angelegt – E-Mail nicht zugestellt, Link unter Benutzer & Rollen erneut senden.', ['email' => $email]);
        }
        if (!$errors) self::done('accounts');
        return ['errors' => $errors, 'messages' => $msg ?: [__('Niemand eingeladen – geht später unter Benutzer & Rollen.')]];
    }

    private static function saveDemo(array $in): array
    {
        if (($in['demo'] ?? 'keep') === 'remove') {
            $n = Demo::remove();
            self::done('demo');
            return ['errors' => [], 'messages' => [__('Demoinhalte entfernt: {n} Einträge und Bilder.', ['n' => $n])]];
        }
        self::done('demo');
        return ['errors' => [], 'messages' => [__('Demoinhalte bleiben – entfernen geht später hier oder mit site:demo-remove.')]];
    }

    private static function saveMail(array $in, ?array $user): array
    {
        $mode = (string) ($in['mode'] ?? 'network');
        if ($mode === 'smtp') {
            $a = ['transport' => 'smtp', 'host' => self::clean($in['host'] ?? ''), 'port' => (int) ($in['port'] ?? 587), 'user' => self::clean($in['user'] ?? ''),
                'pass' => (string) ($in['pass'] ?? ''), 'encryption' => in_array($in['encryption'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? (string) $in['encryption'] : 'tls',
                'verify_peer' => true, 'from' => strtolower(self::clean($in['from'] ?? '')), 'from_name' => self::clean($in['from_name'] ?? '') ?: (string) app()->settings->get('sys.mail_from_name', '')];
            if ($a['host'] === '') return ['errors' => ['host' => __('Bitte den SMTP-Server angeben.')], 'messages' => []];
            if (!filter_var($a['from'], FILTER_VALIDATE_EMAIL)) return ['errors' => ['from' => __('Ungültige E-Mail-Adresse.')], 'messages' => []];
            if ($a['pass'] === '') $a['pass'] = (string) app()->settings->get('sys.mail_pass', '');   // leer = bisheriges Passwort behalten
            \Core\Mail\Accounts::saveStandard($a);
        }
        $msg = [$mode === 'smtp' ? __('SMTP-Konto gespeichert.') : (\Core\Mail\Accounts::network() ? __('E-Mails gehen über das Konto des Netzwerks.') : __('Noch kein Konto des Netzwerks – E-Mails werden erst verschickt, wenn ein Konto eingerichtet ist.'))];
        if (!empty($in['test']) && $user && filter_var((string) ($user['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $err = \Core\Mailer::send(__('Testmail der Schnelleinrichtung'), __('Diese E-Mail bestätigt, dass der Versand der Website funktioniert.'), [(string) $user['email']], ['purpose' => 'notify']);
            $msg[] = $err === null ? (\Core\Mailer::$delivered ? __('Testmail an {email} gesendet.', ['email' => $user['email']]) : __('Testmail angenommen, aber nicht zugestellt (Testumgebung).')) : __('Testmail fehlgeschlagen: {error}', ['error' => $err]);
        }
        self::done('mail');
        return ['errors' => [], 'messages' => $msg];
    }

    private static function saveLegal(?array $user): array
    {
        $res = Placeholders::applySite(self::values());
        $pages = [];
        foreach (['imprint', 'privacy'] as $w) if ($p = \Core\Legal::page($w)) $pages[] = $p;
        $actor = \Core\Tasks\Tasks::actor($user, $user ? 'admin' : 'cli');
        foreach ($pages as $p) {
            self::task(__('Bitte prüfen: {title}', ['title' => $p['title']]), __('Die Seite wurde aus der Vorlage mit den Angaben der Organisation gefüllt. Bitte rechtlich prüfen und ergänzen.'), 'page:' . $p['id'], $actor);
        }
        self::done('legal');
        $openLegal = array_filter($res['open'], fn($w) => (bool) array_filter($w, fn($x) => in_array($x, array_map(fn($p) => 'page:' . $p['id'], $pages), true)));
        $msg = [__('Impressum und Datenschutz gefüllt, Aufgabe „bitte prüfen“ angelegt.')];
        if ($openLegal) $msg[] = __('Noch offen in den Rechtstexten: {list}', ['list' => implode(', ', array_map(fn($k) => Placeholders::label((string) $k), array_keys($openLegal)))]);
        return ['errors' => [], 'messages' => $msg];
    }

    /** Aufgabe anlegen (einmal je Titel, solange offen) */
    private static function task(string $title, string $body, string $link, array $actor): void
    {
        if (app()->db->fetchValue("SELECT COUNT(*) FROM tasks WHERE title = ? AND status = 'open'", [$title])) return;
        [$vals] = \Core\Tasks\Tasks::clean(['title' => $title, 'body' => $body, 'link' => $link, 'assignee' => '', 'priority' => 'normal']);
        \Core\Tasks\Tasks::create($vals, $actor, ['source' => 'blueprint', 'notify' => false]);
    }

    /**
     * Offene Punkte (Checkliste): Schritte ohne Abschluss, offene Platzhalter, Erweiterungen zum Selbst-Einschalten, Netzwerk-Tabellen
     * ohne Beitritt. @return list<array{title: string, link: string}>
     */
    public static function checklist(): array
    {
        $s = self::state();
        $out = [];
        foreach (self::steps() as $st) if (empty($s['done'][$st])) $out[] = ['title' => __('Schnelleinrichtung: {step}', ['step' => self::label($st)]), 'link' => '/admin/schnelleinrichtung?schritt=' . $st];
        $bp = (array) app()->settings->get(Apply::KEY, []);
        foreach (Placeholders::applySite(self::values())['open'] as $k => $where) {
            $first = (string) ($where[0] ?? '');
            $out[] = ['title' => __('Platzhalter ausfüllen: {name}', ['name' => Placeholders::label($k, (array) ($bp['placeholders'] ?? []))]),
                'link' => str_starts_with($first, 'page:') ? $first : '/admin/schnelleinrichtung?schritt=org'];
        }
        foreach ((array) ($bp['extensions_later'] ?? []) as $x) $out[] = ['title' => __('Erweiterung prüfen: {name}', ['name' => $x]), 'link' => '/admin/features'];
        foreach (Network::refs() as $ref) if (!$ref['joined']) $out[] = ['title' => __('Netzwerk-Tabelle beitreten: {name}', ['name' => $ref['label']]), 'link' => '/admin/schnelleinrichtung?schritt=network'];
        return $out;
    }

    /** Health: Website aus einer Vorlage – Schnelleinrichtung offen, Vorschlag einer neuen Vorlagen-Version (Hinweise, kein Fehler) */
    public static function health(): array
    {
        try {
            $out = [];
            if (self::open()) $out['Schnelleinrichtung der Website-Vorlage offen – Website im Wartungsmodus (/admin/schnelleinrichtung)'] = null;
            if ($p = Diff::proposal()) $out['Neue Version der Website-Vorlage ' . $p['name'] . ' ' . $p['version'] . ' vorgeschlagen (blueprint:update)'] = null;
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Abschließen: offene Punkte als Aufgaben, Wartungsmodus aus. @return list<string> */
    public static function finish(?array $user = null, bool $live = true): array
    {
        $actor = \Core\Tasks\Tasks::actor($user, $user ? 'admin' : 'cli');
        $n = 0;
        foreach (self::checklist() as $c) {
            self::task($c['title'], __('Offener Punkt aus der Schnelleinrichtung der Website-Vorlage.'), $c['link'], $actor);
            $n++;
        }
        $s = self::state();
        $s['open'] = false;
        $s['finished_at'] = date('c');
        self::save($s);
        if ($live) app()->settings->set('sys.maintenance', false);
        \Core\PageCache::clear();
        return [$n ? __('{n} offene Punkte als Aufgaben angelegt.', ['n' => $n]) : __('Alles erledigt.'), $live ? __('Wartungsmodus beendet – die Website ist erreichbar.') : __('Wartungsmodus bleibt an.')];
    }
}
