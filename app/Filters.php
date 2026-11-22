<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core;

/**
 * Filter für Erweiterungen (Extension::filter): Werte des Cores an festen Stellen ändern, ergänzen oder verwerfen.
 *
 *   $x->filter('search.document', fn(array $doc, array $ctx) => $ctx['type'] === 'entry' && $ctx['table'] === 'intern' ? false : $doc);
 *
 * Regeln: fn($wert, array $ctx) gibt den neuen Wert zurück. null = unverändert (vergessenes return schadet nicht). false =
 * verwerfen – nur wo die Beschreibung es erlaubt. Fehler einer Erweiterung lassen den Wert unverändert (Protokoll). Filter
 * laufen in der Reihenfolge der Erweiterungen. Eigene Filter einer Erweiterung: Name „ext:{erweiterung}.{name}“.
 * Anders als Ereignisse (Extension::on, nur Mitteilung danach) wirken Filter auf das Ergebnis.
 */
final class Filters
{
    /** Name → [Wert, Kontext, Beschreibung] – Grundlage für Doku und Selbsttest */
    public const ALL = [
        'search.document' => ['array $doc (id, type, title, text, url, lang …)', 'type: page|entry|file|ext, page_id | table + entry_id | provider + id', 'Dokument für die Website-Suche ändern oder mit false aus der Suche nehmen.'],
        'search.block' => ['array{head: list<string>, text: list<string>} indexierbarer Text eines Blocks', 'type (Blocktyp), data, page_id', 'Text eines Blocks für die Website-Suche ändern oder mit false weglassen – nach der Blockdefinition (search_text) bzw. der Standard-Extraktion.'],
        'sitemap.urls' => ['list<array{loc: string, lastmod?: string}>', 'host', 'Adressen der sitemap.xml ergänzen, ändern oder entfernen.'],
        'menu.items' => ['list<array{id, label, href, active, children, draft}> (Baum)', 'drafts: bool', 'Navigation der Kits (Pages::menu) ändern – Einträge ergänzen, umbenennen, ausblenden.'],
        'seo.meta' => ['array{title, description, canonical, og_image, noindex, jsonld}', 'page | entry + table', 'Angaben für den Kopf der Seite (Seo::forPage/forEntry) ändern.'],
        'form.validate' => ['array<string, string> $errors (Feld → Meldung, \'_form\' = allgemein)', 'table, values, ip', 'Eigene Prüfung von Formularen der Website vor dem Speichern – Fehler ergänzen bricht das Absenden ab.'],
        'mail.message' => ['array{subject, text, to, options}', 'account: false', 'Ausgehende E-Mail ändern (z. B. Fußzeile) oder mit false unterdrücken. Mails zur Kontosicherheit (Passwort, Einladung) laufen nie durch den Filter; Test-Umleitung gilt danach weiter.'],
        'admin.nav' => ['array{main: list<array{0: href, 1: label, 2: key, 3?: bool}>, groups: list<array{key, label, icon, items}>}', 'user, view', 'Seitenleiste der Verwaltung ändern – Punkte ausblenden, umsortieren, ergänzen (z. B. vereinfachte Verwaltung). Nur Darstellung: Rechte prüft weiter jede Seite.'],
        'admin.home' => ['?string Adresse (null = Übersicht /admin)', 'user', 'Startseite der Verwaltung nach der Anmeldung bzw. bei /admin (z. B. vereinfachte Startseite); /admin?uebersicht=1 zeigt immer die Übersicht.'],
        'admin.brand' => ['array{0: string, 1: string} Zeile 1 und 2', 'site', 'Marke oben in der Seitenleiste der Verwaltung (Standard: project.brand bzw. Website-Name), z. B. „Verbands-CMS“ für einen Verbund.'],
        'media.usages' => ['list<array{label: string, url: ?string, graphic?: int}> „Verwendet auf“ einer Datei', 'media (ID)', 'Verwendungen einer Datei der Mediathek ergänzen oder Adressen setzen (z. B. Bearbeiten im Grafik-Editor). Einträge ohne label fallen weg.'],
        'presence.object' => ['array{allowed: bool, last: ?array{user_id: ?int, created_at: string}}', 'type, id (z. B. graphic, media:12)', 'Eigene Arten für „Wer arbeitet gerade wo“ (Core\\Http\\Controllers\\Admin\\PresenceController): darf die Person das Objekt bearbeiten, wer hat zuletzt gespeichert. Kern-Arten page und entry laufen nicht durch den Filter.'],
        'subscriptions.apps' => ['list<array{key, label, url, user, password, help}> weitere Zugänge eines Abos', 'table, scope (public|internal), token (Klartext nur direkt nach dem Anlegen, sonst null)', 'Seite „Abonnieren“ einer Tabelle: weitere Zugänge für Apps nennen (z. B. CardDAV der Erweiterung dav mit dem Abo-Token als Passwort).'],
        'rowmenu.actions' => ['list<array{label: string, href: string, icon?: string}> weitere Punkte im Zeilenmenü', 'kind (entry), table, id', 'Zeilenmenü der Verwaltung (Core\\RowMenu) um eigene Aktionen ergänzen – stehen vor „Löschen“; nur Pfade dieser Installation (/…), Rechte prüft die Ziel-Route.'],
        'list_templates' => ['array<string, array{name, options, tables?, requires?, default?, block?}> Listen-Vorlagen (nur lesbar, Schlüssel im Block ext:{name})', 'table, block (Blocktyp, z. B. data_list)', 'Vorlagen für Listenblöcke vordefinieren (Core\\Blocks\\ListTemplates) – wie theme.php → list_templates.'],
        'data_list.query' => ['array $options für Entries::query (where, order, limit, q …)', 'table, block (Daten des Blocks)', 'Abfrage des Blocks „Datenliste“ ändern (z. B. zusätzliche Bedingung).'],
        'data.nearby_point' => ['?array{lat: float, lng: float} (null = keiner)', 'table (Kurzname), id', '„In deiner Nähe“ (Core\\Data\\Nearby::point): Kartenpunkt eines Eintrags ohne Kartenfeld – z. B. Mitte des Gebiets aus einer Gebietskarte (Regionalgruppe).'],
        'blueprint.export' => ['array<string, mixed> Abschnitte der Erweiterungen (Schlüssel = Name der Erweiterung, Wert = JSON-fähige Daten)', 'demo (anonym|none|full), site', 'Website-Vorlage (Core\\Blueprint\\Export): eigene Einstellungen ins Paket legen – z. B. Konfiguration der Gebietskarten. Nur Struktur, keine Geheimnisse und keine personenbezogenen Daten (das Paket wird danach geprüft und sonst verworfen).'],
        'blueprint.apply' => ['list<string|array{task: string, link?: string, body?: string}> Meldungen bzw. Aufgaben', 'sections (alle Abschnitte aus blueprint.export), params (name, short, region …), manifest, values (Platzhalter → Wert, Core\\Blueprint\\Placeholders::fill)', 'Website aus einer Vorlage (Core\\Blueprint\\Apply::applyExtensions, eigener Prozess nach dem Einspielen – die Erweiterung ist dann aktiv): den eigenen Abschnitt einspielen. Einträge mit „task“ werden Aufgaben der Website.'],
        'blueprint.region' => ['list<string|array{task: string, link?: string, body?: string}> Meldungen bzw. Aufgaben', 'region (Name, z. B. Bundesland), code (Landesschlüssel „06“ oder null), short (Abkürzung), blueprint (Herkunft der Website), values (Platzhalter → Wert)', 'Schnelleinrichtung, Schritt Gebiet (Core\\Blueprint\\QuickConfig): Region übernehmen – z. B. Ausschnitt der Gebietskarte, Beispiel-Zuordnung, Aufgabe „Gebiete zuordnen“.'],
        'blueprint.source' => ['array{region?: string} Angaben der Quelle', 'site', 'Website-Vorlage (Core\\Blueprint\\Export): Region der Quelle melden (Bundesland, Abkürzung oder Landesschlüssel), wenn sie nicht in den Einstellungen steht – z. B. aus dem Ausschnitt einer Gebietskarte. Ihre Namen werden in Texten Platzhalter.'],
        'data.nearby' => ['array{point: ?array{lat, lng}, label: string, regions: list<string "tabelle:id">, sites: list<string>}', 'q (Eingabe: PLZ oder Ort), point (nach der Geokodierung)', '„In deiner Nähe“ (Core\\Data\\Nearby): Ort der Besucher-Eingabe auflösen – Kartenpunkt, zuständige Gebiete als Einträge und zuständige Websites (z. B. Gebietskarten).'],
    ];

    /** Ist der Name erlaubt? Bekannte Filter des Cores oder eigene einer Erweiterung (ext:{name}.…) */
    public static function known(string $name): bool
    {
        return isset(self::ALL[$name]) || (bool) preg_match('~^ext:[a-z0-9_]+\.[a-z0-9_.-]+$~', $name);
    }
}
