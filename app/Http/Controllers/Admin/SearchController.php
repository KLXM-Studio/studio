<?php
declare(strict_types=1);

namespace Core\Http\Controllers\Admin;

use Core\Data\Entries;
use Core\Data\Tables;
use Core\Http\Request;
use Core\Http\Response;
use Core\Media;
use Core\Pages;
use Core\SystemSchema;

/**
 * Übergreifende Suche (Suche in der Kopfleiste und Befehlspalette ⌘K): Aktionen, Seiten, Datensätze, Medien, Einstellungen, Hilfe.
 * Ohne Suchbegriff: Favoriten, zuletzt bearbeitet (Seiten und Einträge), Aktionen.
 * Quelle (?scope=…, Kürzel in der Kopfleiste): f Favoriten · c Befehle (Aktionen, Einstellungen & Hilfe) · p Seiten · d Daten ·
 * k Kontakte (Adressbuch-Tabellen) · t Termine (Kalender-Tabellen) · m Medien – leer = alles. Jede Gruppe trägt ihre Quelle ('scope').
 * Nur, was die Person öffnen darf: Seiten mit pages.edit, Einträge mit data.edit je Tabelle, Medien mit media.upload.
 */
final class SearchController extends AdminController
{
    public function search(Request $r): Response
    {
        $user = $this->auth($r);
        $q = trim(mb_substr($r->str('q'), 0, 80));
        $isAdmin = in_array($user['role'], ['admin', 'network'], true);
        $want = in_array($w = $r->str('scope'), self::SCOPES, true) ? $w : '';
        $in = fn(string $sc): bool => $want === '' || $want === $sc;
        $groups = [];
        $tables = array_values(array_filter(Tables::content(), fn($t) => can('data.edit', $t['handle'])));
        // Quelle einer Datentabelle: Kontakte (Adressbuch) bzw. Termine (Kalender), sonst Daten
        $tScope = fn(array $t): string => \Core\Data\AddressBook::enabled($t) ? 'k' : (\Core\Data\Calendar::enabled($t) ? 't' : 'd');

        // Favoriten des Benutzers zuerst (Core\Favorites)
        $items = [];
        foreach (\Core\Favorites::all((int) $user['id']) as $i => $f) {
            $s = self::score($q, $f['title'] . ' ' . rawurldecode($f['url']));
            if ($q !== '' && $s === 0) continue;
            $items[] = ['title' => $f['title'], 'sub' => __('Favorit'), 'url' => url($f['url']), 'icon' => 'star',
                'glyph' => $f['icon'] !== '' ? $f['icon'] : null, 's' => $q === '' ? 1000 - $i : $s];
        }
        if ($items && $in('f')) $groups[] = ['label' => __('Favoriten'), 'scope' => 'f', 'items' => self::top($items, $want === 'f' ? 30 : ($q === '' ? 8 : 6))];

        // Zuletzt bearbeitet (ohne Suchbegriff): Seiten und Einträge nach Änderungszeit, gemischt
        if ($q === '' && $want === '') {
            $recent = [];
            if (can('pages.edit')) {
                foreach (Pages::flat(false) as $p) {
                    $recent[] = ['title' => $p['title'], 'sub' => __('Seite') . ' · ' . \Core\Format::admin()->relative((string) $p['updated_at']),
                        'url' => Pages::url($p) . '?edit=1', 'icon' => $p['is_home'] ? 'home' : 'page', 'alt' => url('/admin/pages/' . $p['id']), 's' => strtotime((string) $p['updated_at'])];
                }
            }
            foreach ($tables as $t) {
                foreach (Entries::query($t, ['status' => 'all', 'sort' => 'updated_at', 'dir' => 'desc', 'limit' => 3]) as $e) {
                    $recent[] = ['title' => Entries::title($t, $e), 'sub' => $t['singular'] . ' · ' . \Core\Format::admin()->relative((string) ($e['updated_at'] ?? '')),
                        'url' => url('/admin/data/' . $t['handle'] . '/' . $e['id']), 'icon' => 'table', 'glyph' => $t['icon'], 'alt' => Entries::url($t, $e), 's' => strtotime((string) ($e['updated_at'] ?? ''))];
                }
            }
            if ($recent) $groups[] = ['label' => __('Zuletzt bearbeitet'), 'scope' => 'r', 'items' => self::top($recent, 6)];
        }

        // Assistent-Chat (Core\AI\Assistant): „Assistent fragen …“ – öffnet das Chat-Fenster mit dem Suchtext (resources/js/_assistant.js)
        if (\Core\AI\Assistant::available() && ($q === '' || mb_strlen($q) >= 3) && $in('c')) {
            $groups[] = ['label' => \Core\AI\Assist::brand(), 'scope' => 'c', 'items' => [[
                'title' => $q === '' ? __('Assistent fragen …') : __('Assistent fragen: „{q}“', ['q' => $q]), 'sub' => __('Antwort aus Handbuch und Hilfe, Aktionen mit Bestätigung'),
                'url' => url('/admin/ai/assistent') . ($q !== '' ? '?q=' . rawurlencode($q) : ''), 'ico' => \Core\Icons::resolve('chat-teardrop-dots'), 'cmd' => 'assistant', 'q' => $q,
            ]]];
        }

        // Aktionen
        $actions = [
            [__('Neue Seite'), __('Seite anlegen'), '/admin/pages/new', 'plus', 'neu seite anlegen erstellen'],
            [__('Medien hochladen'), __('Mediathek öffnen'), '/admin/media', 'upload', 'bild foto datei pdf upload hochladen'],
            [app()->theme->settingsTitle(), __('Zentrale Angaben'), '/admin/settings', 'gear', 'einstellungen kontakt adresse ' . project('search_keywords', '')],
            ...(can('requests.read') && \Core\Data\Inbox::readable() ? [[__('Eingang'), term('requests'), '/admin/requests', 'inbox', 'eingang inbox anfragen anfrage formular ' . mb_strtolower(term('requests'))]] : []),
            [__('Website ansehen'), __('Startseite öffnen'), url('/'), 'globe', 'website live ansehen'],
            [__('Handbuch'), __('Hilfe & Anleitungen'), '/admin/hilfe', 'help', 'hilfe handbuch anleitung'],
            ...(\Core\Guide::exists() ? [[__('Projekt-Hinweise'), __('Hinweise zu diesem Projekt'), '/admin/hilfe#projekt', 'lightbulb', 'projekt hinweise agentur anleitung kit']] : []),
        ];
        foreach ($tables as $t) {
            $actions[] = [__('Neu: {name}', ['name' => $t['singular']]), $t['name'], '/admin/data/' . $t['handle'] . '/new', 'plus', 'neu anlegen ' . $t['name'] . ' ' . $t['singular']];
        }
        // Eingangs-Tabellen: nur der Name (Inhalte sind verschlüsselt und werden nie durchsucht)
        foreach (\Core\Data\Inbox::readable() as $t) {
            $actions[] = [$t['name'], __('Eingang'), '/admin/requests?table=' . $t['handle'], 'inbox', 'anfragen eingang inbox ' . $t['name'] . ' ' . $t['singular']];
        }
        if ($isAdmin) {
            $actions[] = [__('Neue Datentabelle'), __('Eigenen Inhaltstyp anlegen'), '/admin/data/new', 'table', 'tabelle collection daten neu'];
        }
        // Hauptmenü: Bereiche des Kerns und Inhalts-/Werkzeugseiten von Funktionen und Erweiterungen (Core\AdminPages::nav('main'),
        // z. B. Feedback, Buchungen, Animationen) – gleiche Sichtbarkeit wie in der Seitenleiste (layout.php)
        $netUser = \Core\Network\Network::isNetworkUser($user);
        $main = [
            ['/admin/network', __('Netzwerk'), 'network', $netUser && \Core\Network\Network::isNetworkSite(), 'netzwerk websites konten'],
            ['/admin', __('Übersicht'), 'dashboard', true, 'übersicht start dashboard kennzahlen'],
            ['/admin/pages', __('Seiten'), 'pages', can('pages.edit'), 'seiten seitenbaum navigation'],
            ['/admin/entwuerfe', __('Entwürfe'), 'drafts', \Core\Review\Drafts::canView(), 'entwürfe veröffentlichen'],
            ['/admin/data', __('Daten'), 'data', can('data.schema') || (bool) array_filter(Tables::content(), fn($t) => can('data.edit', $t['handle'])), 'daten tabellen einträge'],
            ['/admin/chat', __('Chat'), 'chat', \Core\Chat\Chat::canUse(), 'chat nachrichten'],
            ['/admin/ai', \Core\AI\Assist::brand(), 'ai', \Core\AI\Assist::navVisible(), 'ki assistent texte übersetzen seo'],
            ['/admin/ai/eingereicht', __('Eingereicht'), 'review', \Core\Review\Queue::canReview(), 'eingereicht freigabe prüfen review'],
            ['/admin/mitteilungen', __('Mitteilungen'), 'push', \Core\Push\Push::on() && (can('push.view') || can('push.send')), 'mitteilungen push benachrichtigung senden kanäle statistik abos'],
        ];
        foreach (\Core\AdminPages::nav('main') as [$href, $label, $key, $vis]) $main[] = [$href, $label, $key, $vis, mb_strtolower($label) . ' ' . $href];
        foreach ($main as [$href, $label, $key, $vis, $kw]) {
            if ($vis) $actions[] = [$label, __('Bereich'), $href, $key, $kw];
        }
        // Administration: Punkte der Gruppen „Einstellungen“ und „Werkzeuge“ (Core\AdminPages::groups) – Untertitel = Gruppe wie im Menü
        $kwAdmin = ['system' => 'system einstellungen mail smtp spam icon favicon pwa', 'features' => 'funktionen erweiterungen schalten an aus',
            'prefs' => 'einstellungen funktionen erweiterungen konfiguration', 'users' => 'benutzer nutzer rollen rechte zugang passwort',
            'design' => 'design farben schriften kit', 'stats' => 'statistik bericht'];
        foreach (\Core\AdminPages::groups() as $g) {
            foreach ($g['items'] as [$href, $label, $key]) {
                $actions[] = [$label, $g['label'], $href, $key, ($kwAdmin[$key] ?? '') . ' ' . mb_strtolower($g['label'])];
            }
        }
        // Einstellungen im Detail: Bereiche der Grundeinstellungen (mit ihren Feldern als Suchwörtern), Funktionen, Konto, Handbuch
        $more = [];
        // Einstellungs- und Statistikseiten der Funktionen und Erweiterungen (Core\AdminPages kind settings/stats): Treffer unter
        // „Einstellungen & Hilfe“ – „Newsletter Einstellungen“ findet die Einstellungen des Newsletters
        foreach (\Core\AdminPages::ofKind('settings', 'stats') as $ap) {
            $more[] = [$ap['label'] . ($ap['kind'] === 'stats' ? '' : ' › ' . __('Einstellungen')), $ap['description'] !== '' ? $ap['description'] : ($ap['kind'] === 'stats' ? __('Statistiken') : __('Funktionen & Erweiterungen')),
                $ap['href'], $ap['icon'], ($ap['kind'] === 'stats' ? 'statistik bericht ' : 'einstellungen konfiguration ') . $ap['description'] . ' ' . $ap['href']];
        }
        if (can('system.manage')) {
            $sysIco = ['website' => 'gear-six', 'index' => 'tree-structure', 'app' => 'device-mobile', 'proxy' => 'map-trifold', 'sprachen' => 'translate',
                'mail' => 'envelope-simple', 'spam' => 'shield-check', 'suche' => 'magnifying-glass', 'ki' => 'sparkle'];
            foreach (\Core\SystemSchema::groups() as $g) {
                $kw = implode(' ', array_map(fn($f) => (string) ($f['label'] ?? '') . ' ' . (string) ($f['help'] ?? ''), $g['fields']));
                $more[] = [$g['label'], __('Grundeinstellungen'), '/admin/system#' . $g['id'], $sysIco[$g['id']] ?? 'gear-six', mb_strtolower($kw)];
            }
            foreach ([['keys', 'Verschlüsselung', 'lock-key', 'schlüssel verschlüsselung anfragen secret'], ['pools', __('Geteilte Medien'), 'images', 'pool geteilt medien'],
                ['push', __('Push-Benachrichtigungen'), 'bell-ringing', 'push benachrichtigung mitteilung vapid cron'], ['umgebung', __('Umgebung'), 'hard-drives', 'staging testumgebung live production noindex'],
                ['adminpath', __('Adresse der Verwaltung'), 'link', 'admin pfad adresse login verwaltung'], ['info', 'Systeminfo', 'info', 'system php version speicher']] as [$id, $l, $i, $k]) {
                $more[] = [$l, __('Grundeinstellungen'), '/admin/system#' . $id, $i, $k];
            }
            if (\Core\Fonts::canManage()) $more[] = [__('Schriften'), __('Grundeinstellungen'), '/admin/system/fonts', 'text-t', 'schrift fonts google'];
            if (\Core\KitPackages::canManage()) $more[] = [__('Kits'), __('Grundeinstellungen'), '/admin/system/kits', 'package', 'kit theme paket'];
            if (\Core\Domains::available()) $more[] = [__('Domain'), __('Grundeinstellungen'), '/admin/system/domain', 'globe', 'domain adresse hauptadresse weiterleitung'];
        }
        // Netzwerk-Bereich (Core\Network\Area): umgezogene Punkte nicht mehr unter „Grundeinstellungen“, dafür die Seiten des Netzwerks
        if (\Core\Network\Area::active()) {
            $more = array_values(array_filter($more, fn($m) => \Core\Network\Area::path((string) $m[2]) === (string) $m[2]
                && !in_array((string) $m[2], ['/admin/system#pools', '/admin/system#adminpath'], true)));
            if (\Core\Network\Network::isNetworkUser() && \Core\Network\Network::isNetworkSite()) {
                foreach (\Core\Network\Area::nav() as $g) foreach ($g['items'] as [$nk, $nh, $nl, $ni]) {
                    $more[] = [$nl, __('Netzwerk'), $nh, $ni, mb_strtolower('netzwerk network ' . $nk . ' ' . $nl)];
                }
            }
        }
        if (\Core\Features::canView()) {
            foreach (\Core\Features::catalog() as $key => $def) {
                $more[] = [(string) $def[0], __('Funktionen & Erweiterungen'), '/admin/funktionen#f-' . preg_replace('~[^a-z0-9]~', '-', $key), 'toggle-right',
                    mb_strtolower($key . ' ' . (string) (\Core\FeatureInfo::get($key)['desc'] ?? ''))];
            }
        }
        foreach ([['profil', __('Profil'), 'name sprache darstellung'], ['anmeldedaten', __('Anmeldedaten'), 'passwort e-mail passkey'], ['zwei-faktor', __('Zwei-Faktor-Anmeldung'), '2fa totp passkey sicherheit'],
            ['favoriten', __('Favoriten'), 'favoriten stern'], ['benachrichtigungen', __('Benachrichtigungen'), 'push mitteilungen geräte']] as [$a, $l, $k]) {
            $more[] = [$l, __('Konto'), '/admin/account#' . $a, 'user', $k . ' konto'];
        }
        // Handbuch: Kapitel aus help/manual.php ($core) – Titel als Treffer, Sprung auf das Kapitel
        static $chapters = null;
        if ($chapters === null) {
            $chapters = [];
            $src = (string) @file_get_contents(ROOT . '/app/Admin/views/help/manual.php');
            if (preg_match('~\$core = \[(.*?)\n\];~s', $src, $m) && preg_match_all("~'([a-z0-9-]+)' => '([^']+)'~u", $m[1], $mm, PREG_SET_ORDER)) {
                foreach ($mm as [, $k, $t]) $chapters[$k] = $t;
            }
        }
        foreach ($chapters as $k => $t) $more[] = [__($t), __('Handbuch'), '/admin/hilfe#' . $k, 'book-open', 'hilfe handbuch anleitung ' . $k];
        if ($q !== '') {
            $hits = [];
            foreach ($more as [$t, $sub, $href, $icon, $kw]) {
                $s = self::score($q, $t) * 3 + self::score($q, $t . ' ' . $kw);
                if ($s > 0) $hits[] = ['title' => $t, 'sub' => $sub, 'url' => url($href), 'icon' => $icon, 's' => $s];
            }
            if ($hits && $in('c')) $groups[] = ['label' => __('Einstellungen & Hilfe'), 'scope' => 'c', 'items' => self::top($hits, $want === 'c' ? 12 : 6)];
        }
        $items = [];
        foreach ($actions as [$t, $sub, $href, $icon, $kw]) {
            if ($q === '' || self::score($q, $t . ' ' . $kw) > 0) {
                $items[] = ['title' => $t, 'sub' => $sub, 'url' => str_starts_with($href, '/admin') ? url($href) : $href, 'icon' => $icon, 's' => self::score($q, $t . ' ' . $kw)];
            }
        }
        if ($in('c')) $groups[] = ['label' => __('Aktionen'), 'scope' => 'c', 'items' => self::top($items, $want === 'c' ? 20 : ($q === '' ? 6 : 5))];

        // Seiten
        $items = [];
        foreach ($in('p') && can('pages.edit') && ($q !== '' || $want === 'p') ? Pages::flat(true) : [] as $p) {
            $hay = $p['title'] . ' ' . $p['path'] . ' ' . $p['meta_description'];
            if ($q !== '') {
                $hay .= ' ' . strip_tags((string) $p['content_draft']);
            }
            $s = self::score($q, $p['title'] . ' ' . $p['path']) * 2 + self::score($q, $hay);
            if ($q !== '' && $s === 0) continue;
            $nf = \Core\NotFound::isPage($p);   // Seite „Nicht gefunden (404)“: wie eine Seite im Editor öffnen
            $tpl = $p['type'] === 'template' && !$nf;
            $items[] = ['title' => $p['title'], 'sub' => $nf ? __('Nicht gefunden (404)') : ($tpl ? __('Detailseiten-Vorlage') : '/' . ($p['is_home'] ? '' : $p['path']) . ($p['status'] === 'published' ? '' : __(' · Entwurf'))),
                'url' => $tpl ? url('/admin/pages') : Pages::url($p) . '?edit=1', 'icon' => $p['is_home'] ? 'home' : 'page',
                'alt' => $tpl ? null : url('/admin/pages/' . $p['id']), 's' => $s ?: strtotime((string) $p['updated_at'])];
        }
        $groups[] = ['label' => __('Seiten'), 'scope' => 'p', 'items' => self::top($items, $want === 'p' ? 20 : 8)];

        // Datensätze aller Inhaltstabellen (Eingangs-Tabellen nie)
        foreach ($tables as $t) {
            $ts = $tScope($t);
            if (!$in($ts) || ($q === '' && $want === '')) continue;   // ohne Suchbegriff stehen Einträge unter „Zuletzt bearbeitet“
            $rows = Entries::query($t, $q === '' ? ['status' => 'all', 'sort' => 'updated_at', 'dir' => 'desc', 'limit' => $want !== '' ? 8 : 3] : ['status' => 'all', 'q' => $q, 'limit' => $want !== '' ? 12 : 6]);
            $items = array_map(fn($e) => ['title' => Entries::title($t, $e), 'sub' => $t['singular'] . ($e['status'] === 'draft' ? __(' · Entwurf') : ''),
                'url' => url('/admin/data/' . $t['handle'] . '/' . $e['id']), 'icon' => 'table', 'glyph' => $t['icon'],
                'alt' => Entries::url($t, $e)], $rows);
            if ($items) $groups[] = ['label' => $t['name'], 'scope' => $ts, 'items' => $items];
        }

        // Medien
        $media = !$in('m') || !can('media.upload') ? [] : ($q === '' ? array_slice(Media::all(), 0, $want === 'm' ? 12 : 4) : array_slice(Media::all(['q' => $q]), 0, $want === 'm' ? 12 : 6));
        $groups[] = ['label' => __('Medien'), 'scope' => 'm', 'items' => array_map(fn($m) => [
            'title' => Media::displayName($m), 'sub' => Media::typeLabel($m['mime']) . ' · ' . Media::humanSize((int) $m['size']),
            'url' => url('/admin/media') . '#m' . $m['id'], 'icon' => str_starts_with($m['mime'], 'image/') ? 'image' : 'file',
            'thumb' => str_starts_with($m['mime'], 'image/') ? Media::url($m, 480) : null, 'alt' => Media::url($m)], $media)];

        // Einstellungen (Feldnamen) – nur mit Suchbegriff
        if ($q !== '' && $in('c')) {
            $items = [];
            foreach (app()->theme->settingsGroups() as $g) {
                foreach ($g['fields'] as $f) {
                    if (!isset($f['label']) || ($f['type'] ?? '') === 'heading') continue;
                    $s = self::score($q, $f['label'] . ' ' . $g['label'] . ' ' . (string) ($f['name'] ?? ''));
                    if ($s) $items[] = ['title' => $f['label'], 'sub' => app()->theme->settingsTitle() . ' › ' . $g['label'], 'url' => url('/admin/settings') . '#' . $g['id'], 'icon' => 'gear', 's' => $s + 5];
                }
            }
            if ($isAdmin) {
                foreach (SystemSchema::groups() as $g) {
                    foreach ($g['fields'] as $f) {
                        if (!isset($f['label'], $f['name'])) continue;
                        $s = self::score($q, $f['label'] . ' ' . $g['label']);
                        if ($s) $items[] = ['title' => $f['label'], 'sub' => 'Grundeinstellungen › ' . $g['label'], 'url' => url('/admin/system') . '#' . $g['id'], 'icon' => 'gear', 's' => $s];
                    }
                }
            }
            $groups[] = ['label' => __('Einstellungen'), 'scope' => 'c', 'items' => self::top($items, 6)];
        }

        // Hinweise zu diesem Projekt (Core\Guide) – Titel und Text, nur mit Suchbegriff
        if ($q !== '' && $in('c')) {
            $items = [];
            foreach (\Core\Guide::notes() as $n) {
                $s = self::score($q, $n['title']) * 2 + self::score($q, $n['title'] . ' ' . \Core\Guide::plain($n));
                if ($s) $items[] = ['title' => $n['title'], 'sub' => __('Hinweis zum Projekt'), 'url' => \Core\Guide::url($n), 'icon' => 'lightbulb', 's' => $s];
            }
            $groups[] = ['label' => __('Projekt-Hinweise'), 'scope' => 'c', 'items' => self::top($items, 4)];
        }

        // Support & Wissensdatenbank (Core\Support): „Problem melden“, Wissensartikel, beantwortete Fragen
        try {
            if ($in('c')) foreach (\Core\Support\Support::spotlight($q, self::score(...)) as $sg) $groups[] = $sg + ['scope' => 'c'];
        } catch (\Throwable $e) {
            error_log('[support] spotlight: ' . $e->getMessage());
        }

        $groups = array_values(array_filter($groups, fn($g) => $g['items']));
        // Reihenfolge: Einstellungs-Treffer direkt nach den Aktionen; sucht man ausdrücklich Einstellungen („Newsletter Einstellungen“,
        // „Logo“ …), stehen sie vor allem anderen (nach den Favoriten)
        if ($q !== '') {
            $isSet = fn($g) => in_array($g['label'], [__('Einstellungen'), __('Einstellungen & Hilfe')], true);
            $setFirst = (bool) preg_match('~einstell|konfig|setting~iu', $q);
            $set = array_values(array_filter($groups, $isSet));
            $rest = array_values(array_filter($groups, fn($g) => !$isSet($g)));
            $at = 0;
            foreach ($rest as $i => $g) {
                if ($g['label'] === __('Favoriten')) $at = $i + 1;
                if (!$setFirst && $g['label'] === __('Aktionen')) { $at = $i + 1; break; }
            }
            $groups = [...array_slice($rest, 0, $at), ...$set, ...array_slice($rest, $at)];
            // „Assistent fragen …“ nicht vor die echten Treffer: hinter Einstellungen und Aktionen
            $ai = array_values(array_filter($groups, fn($g) => ($g['items'][0]['cmd'] ?? '') === 'assistant'));
            if ($ai) {
                $groups = array_values(array_filter($groups, fn($g) => ($g['items'][0]['cmd'] ?? '') !== 'assistant'));
                $pos = 0;
                foreach ($groups as $i => $g) if ($isSet($g) || $g['label'] === __('Aktionen') || $g['label'] === __('Favoriten')) $pos = $i + 1;
                array_splice($groups, $pos, 0, $ai);
            }
        }
        foreach ($groups as &$g) {
            foreach ($g['items'] as &$i) {
                unset($i['s']);
                // ico: Symbolname im Sprite (Core\Icons) – Tabellensymbol (glyph) vor Art des Treffers (icon)
                $i['ico'] = \Core\Icons::resolve((string) ($i['glyph'] ?? '')) ?? \Core\Icons::resolve((string) ($i['icon'] ?? ''));
                if ($i['ico'] !== null && isset($i['glyph']) && \Core\Icons::resolve((string) $i['glyph']) !== null) $i['glyph'] = null;
            }
        }
        return Response::json(['q' => $q, 'scope' => $want, 'groups' => $groups]);
    }

    /** Einfache Trefferbewertung: alle Wörter enthalten, Wortanfang zählt mehr */
    /** Adresse → Koordinaten (für Kartenfelder; serverseitig über den zentralen Proxy) */
    public function geocode(Request $r): Response
    {
        $this->auth($r);
        $q = trim(mb_substr($r->str('q'), 0, 200));
        $limiter = new \Core\RateLimiter(app()->db);
        // Nominatim erlaubt höchstens 1 Anfrage pro Sekunde
        if ($limiter->tooMany('geocode', 1, 1)) {
            usleep(1_100_000);
        }
        $limiter->hit('geocode');
        return Response::json(['results' => \Core\Maps::geocode($q)]);
    }

    /** Quellen der Suche (?scope=…) – siehe Klassenkommentar */
    public const SCOPES = ['f', 'c', 'p', 'd', 'k', 't', 'm'];

    /**
     * Synonyme der Suche: Suchwort (Anfang) → weitere Wörter, die als Treffer gelten (Englisch/Deutsch, Alltagsbegriffe der Redaktion).
     * Gilt für jedes Wort einzeln; das Wort selbst zählt immer.
     */
    private const SYNONYMS = [
        'footer' => ['fußbereich', 'fuß', 'fusszeile', 'fußzeile'], 'fuß' => ['footer'], 'fuss' => ['fuß', 'footer'],
        'header' => ['kopfbereich', 'kopf', 'kopfzeile'], 'kopf' => ['header'],
        'cookie' => ['einwilligung', 'consent'], 'consent' => ['cookie', 'einwilligung'], 'einwilligung' => ['cookie', 'consent'],
        'logo' => ['bildmarke', 'wortmarke', 'website-angaben'],
        'kategorie' => ['auswahlliste', 'liste', 'thema', 'themen'], 'kategorien' => ['auswahlliste', 'liste', 'themen'],
        'menü' => ['navigation'], 'menu' => ['menü', 'navigation'], 'navigation' => ['menü'],
        'favicon' => ['app-icon', 'icon'], 'icon' => ['symbol'],
        'mail' => ['e-mail', 'smtp'], 'email' => ['e-mail', 'mail', 'smtp'], 'smtp' => ['e-mail'],
        'farbe' => ['design', 'farben'], 'farben' => ['design'], 'schrift' => ['font', 'schriften'], 'font' => ['schrift'],
        'einstellung' => ['konfiguration', 'grundeinstellung'], 'einstellungen' => ['einstellung', 'konfiguration', 'grundeinstellung'],
        'settings' => ['einstellung', 'konfiguration'], 'konfiguration' => ['einstellung'],
        'benutzer' => ['nutzer', 'konto', 'rollen'], 'user' => ['benutzer', 'konto'], 'passwort' => ['anmeldedaten', 'kennwort'],
        'redirect' => ['weiterleitung'], 'weiterleitung' => ['redirect'], 'seo' => ['suchmaschine', 'meta'],
        'spam' => ['schutz', 'honeypot'], 'backup' => ['sicherung'], 'sicherung' => ['backup'], 'domain' => ['adresse'],
    ];

    private static function score(string $q, string $hay): int
    {
        if ($q === '') return 1;
        $hay = mb_strtolower($hay);
        $s = 0;
        foreach (preg_split('~\s+~', mb_strtolower($q)) as $w) {
            if ($w === '') continue;
            // bestes Vorkommen des Worts oder eines Synonyms (Synonyme etwas schwächer)
            $best = 0;
            foreach ([$w, ...(self::SYNONYMS[$w] ?? [])] as $k => $alt) {
                $pos = mb_strpos($hay, $alt);
                if ($pos === false) continue;
                $v = $pos === 0 ? 30 : (preg_match('~(^|[\s/\-›(])' . preg_quote($alt, '~') . '~u', $hay) ? 20 : 8);
                $best = max($best, $k === 0 ? $v : $v - 2);
            }
            if ($best === 0) return 0;
            $s += $best;
        }
        return $s;
    }

    private static function top(array $items, int $n): array
    {
        usort($items, fn($a, $b) => ($b['s'] ?? 0) <=> ($a['s'] ?? 0));
        return array_slice($items, 0, $n);
    }
}
