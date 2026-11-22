<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * Tabellenarten mit festem Grundschema (settings.kind). Neben content und inbox gibt es Arten wie contacts (Adressbuch): eine
 * normale Inhaltstabelle – Route, Detailseiten, Revisionen, Workflow, geteilte Tabellen, Suche, API und Blöcke laufen wie immer –
 * deren Grundfelder (System-Felder) feste Namen und Typen haben. Der Tabellen-Designer lässt sie stehen (nicht löschbar, Typ fest,
 * Beschriftung änderbar); eigene Zusatzfelder kommen wie gewohnt dazu.
 *
 * Das Grundschema ist versioniert (settings.kind_version) und wächst nur additiv: eine neue Version ergänzt Felder, entfernt oder
 * ändert nie welche – so passen Tabellen verschiedener Websites (Verband, Vereine) ohne Feldzuordnung zusammen. Tabellen ohne
 * besondere Art (content) verhalten sich unverändert.
 *
 * Kontakte (contacts): Freigabe je Kontakt (Feld oeffentlich = „Auf der Website zeigen“, Standard aus) und je Angabe
 * (Mehrfachfelder Core\Combo::MULTI) – ausgewertet in Core\Data\Exposure. Bestehende Tabellen wandelt data:convert um
 * (Core\Data\Convert, mit Probelauf und Zurückdrehen).
 */
final class Kinds
{
    public const CONTACTS = 'contacts';
    /** Kalender: Termine mit Serien (RFC 5545), abweichenden Einzelterminen und Ausfällen (Core\Data\Series, Core\Data\Occurrences) */
    public const CALENDAR = 'calendar';
    /** Orte: Veranstaltungsorte mit Anschrift und Kartenpunkt – Verknüpfung „Ort aus der Liste“ (ort_ref) in Kalendern */
    public const PLACES = 'places';
    /** Wissen: Kapitel eines Wissensbereichs/Handbuchs mit Bereich, Oberkapitel, Nummer, Schlagworten (Core\Data\Knowledge) */
    public const KNOWLEDGE = 'knowledge';
    /** Aktuelle Version des Grundschemas je Art */
    public const VERSIONS = [self::CONTACTS => 1, self::CALENDAR => 1, self::PLACES => 1, self::KNOWLEDGE => 1];
    /** Feste Kalender-Zuordnung der Art calendar (settings.calendar → Grundfelder) */
    public const CALENDAR_MAP = ['start' => 'beginn', 'end' => 'ende', 'all_day' => 'ganztaegig', 'recurrence' => 'wiederholung', 'location' => 'ort',
        'description' => 'beschreibung', 'category' => 'kategorie'];
    /** Feld „Fällt aus“ (Termin bzw. einzelner Termin einer Serie wird angezeigt, aber als abgesagt) */
    public const CANCELLED_FIELD = 'abgesagt';
    /** Feld „Auf der Website zeigen“ (Freigabe des ganzen Kontakts) */
    public const PUBLIC_FIELD = 'oeffentlich';

    /** content | inbox | contacts – Unbekanntes wird content */
    public static function normalize(string $kind): string
    {
        return $kind === 'inbox' || isset(self::VERSIONS[$kind]) ? $kind : 'content';
    }

    /** Art mit Grundschema (nicht content/inbox)? */
    public static function isSpecial(string $kind): bool
    {
        return isset(self::VERSIONS[$kind]);
    }

    public static function of(array $t): string
    {
        return self::normalize((string) ($t['settings']['kind'] ?? 'content'));
    }

    public static function isContacts(array $t): bool
    {
        return self::of($t) === self::CONTACTS;
    }

    /** Kalender mit Grundschema (Serien, Einzeltermine, Ausfälle)? */
    public static function isCalendar(array $t): bool
    {
        return self::of($t) === self::CALENDAR;
    }

    public static function isPlaces(array $t): bool
    {
        return self::of($t) === self::PLACES;
    }

    /** Wissensbereich (Kapitel mit Bereich, Oberkapitel, Nummern, Schlagworten – Ansicht Core\Data\Knowledge)? */
    public static function isKnowledge(array $t): bool
    {
        return self::of($t) === self::KNOWLEDGE;
    }

    /**
     * Kalender-Einstellung (settings.calendar) einer Tabelle der Art calendar: Zuordnung fest aufs Grundschema, eingeschaltet;
     * Dauer ohne Ende, öffentlicher Feed und „Datum im Seitentitel“ bleiben einstellbar.
     */
    public static function calendarSettings(array $c): array
    {
        return array_merge(Calendar::DEFAULTS, array_intersect_key($c, array_flip(['duration', 'feed', 'title_date'])), self::CALENDAR_MAP, ['enabled' => true]);
    }
    public static function version(string $kind): int
    {
        return self::VERSIONS[$kind] ?? 0;
    }

    /** Bezeichnung der Art (Verwaltung) */
    public static function label(string $kind): string
    {
        return match ($kind) {
            self::CONTACTS => __('Adressbuch'),
            self::CALENDAR => __('Kalender'),
            self::PLACES => __('Orte'),
            self::KNOWLEDGE => __('Wissen'),
            'inbox' => __('Eingang'),
            default => __('Inhalte'),
        };
    }

    /**
     * Grundschema einer Art bis zur Version $version (Standard: aktuelle) als Eingabe für Tables::validate – nur Datenfelder.
     * Jede Version hängt Felder an (nur additiv). 'since' = Version, mit der das Feld dazukam.
     * @return list<array<string, mixed>>
     */
    public static function schema(string $kind, ?int $version = null): array
    {
        $version ??= self::version($kind);
        $all = match ($kind) {
            self::CONTACTS => [
                ['name' => self::PUBLIC_FIELD, 'label' => 'Auf der Website zeigen', 'type' => 'bool', 'in_list' => 1, 'since' => 1,
                    'help' => 'Nur dann erscheint der Kontakt auf der Website – mit den Angaben, die auf „Öffentlich“ stehen. Neue Kontakte sind zuerst intern.'],
                ['name' => 'name', 'label' => 'Anzeigename', 'type' => 'text', 'in_list' => 1, 'searchable' => 1, 'since' => 1,
                    'help' => 'Leer lassen: wird aus Titel, Vor- und Nachname gebildet (bei Organisationen aus dem Namen der Organisation).'],
                ['name' => 'praefix', 'label' => 'Titel', 'type' => 'text', 'width' => 'half', 'since' => 1, 'help' => 'z. B. Dr.'],
                ['name' => 'vorname', 'label' => 'Vorname', 'type' => 'text', 'width' => 'half', 'since' => 1],
                ['name' => 'nachname', 'label' => 'Nachname', 'type' => 'text', 'width' => 'half', 'since' => 1],
                ['name' => 'ist_firma', 'label' => 'Ist eine Organisation', 'type' => 'bool', 'width' => 'half', 'since' => 1],
                ['name' => 'position', 'label' => 'Funktion', 'type' => 'text', 'in_list' => 1, 'width' => 'half', 'since' => 1, 'help' => 'z. B. 1. Vorsitzende'],
                ['name' => 'firma', 'label' => 'Organisation', 'type' => 'text', 'width' => 'half', 'since' => 1],
                ['name' => 'abteilung', 'label' => 'Abteilung', 'type' => 'text', 'width' => 'half', 'since' => 1],
                ['name' => 'foto', 'label' => 'Foto', 'type' => 'media', 'width' => 'half', 'since' => 1],
                ['name' => 'telefon', 'label' => 'Telefon', 'type' => 'phones', 'since' => 1],
                ['name' => 'email', 'label' => 'E-Mail', 'type' => 'emails', 'since' => 1],
                ['name' => 'adresse', 'label' => 'Adresse', 'type' => 'addresses', 'since' => 1],
                ['name' => 'web', 'label' => 'Web', 'type' => 'urls', 'since' => 1],
                ['name' => 'geburtstag', 'label' => 'Geburtstag', 'type' => 'date', 'width' => 'half', 'private' => 1, 'since' => 1],
                ['name' => 'listen', 'label' => 'Listen', 'type' => 'multiselect', 'in_list' => 1, 'options' => '', 'since' => 1,
                    'help' => 'Gruppen wie „Vorstand“ oder „Presse“ – in Adressbuch-Apps als Gruppen bzw. Kategorien.'],
                ['name' => 'notiz', 'label' => 'Notiz', 'type' => 'textarea', 'private' => 1, 'since' => 1],
            ],
            self::CALENDAR => [
                ['name' => 'titel', 'label' => 'Titel', 'type' => 'text', 'required' => 1, 'in_list' => 1, 'searchable' => 1, 'since' => 1],
                ['name' => 'beginn', 'label' => 'Beginn', 'type' => 'datetime', 'required' => 1, 'in_list' => 1, 'width' => 'half', 'since' => 1],
                ['name' => 'ende', 'label' => 'Ende', 'type' => 'datetime', 'width' => 'half', 'since' => 1,
                    'compare' => [['op' => '>=', 'field' => 'beginn', 'message' => 'Das Ende liegt vor dem Beginn.']]],
                ['name' => 'ganztaegig', 'label' => 'Ganztägig', 'type' => 'bool', 'width' => 'half', 'since' => 1, 'help' => 'Beginn und Ende dann ohne Uhrzeit.'],
                ['name' => 'wiederholung', 'label' => 'Wiederholung', 'type' => 'recurrence', 'since' => 1],
                ['name' => 'kategorie', 'label' => 'Kalender', 'type' => 'multiselect', 'in_list' => 1, 'options' => '', 'since' => 1,
                    'help' => 'Zum Beispiel „Training“, „Versammlung“ – Besucher filtern danach, Kalender-Apps zeigen sie als Kategorien.'],
                ['name' => 'ort', 'label' => 'Ort', 'type' => 'text', 'in_list' => 1, 'width' => 'half', 'since' => 1, 'help' => 'Freie Angabe, z. B. „Vereinsheim, Musterstraße 1“.'],
                ['name' => 'ort_ref', 'label' => 'Ort aus der Liste', 'type' => 'relation', 'target' => '', 'width' => 'half', 'since' => 1,
                    'help' => 'Ort aus einer Tabelle „Orte“ – Anschrift und Kartenpunkt kommen dann von dort.'],
                ['name' => 'standort', 'label' => 'Ort (Karte)', 'type' => 'geo', 'since' => 1],
                ['name' => 'beschreibung', 'label' => 'Beschreibung', 'type' => 'textarea', 'since' => 1,
                    'help' => 'Kurzer Text, auch für Kalender-Apps. Ausführliches mit Bildern gehört in ein Zusatzfeld „Nur Website“.'],
                ['name' => self::CANCELLED_FIELD, 'label' => 'Fällt aus', 'type' => 'bool', 'width' => 'half', 'since' => 1,
                    'help' => 'Der Termin bleibt sichtbar, aber als abgesagt gekennzeichnet (in Kalender-Apps „abgesagt“).'],
            ],
            self::PLACES => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => 1, 'in_list' => 1, 'searchable' => 1, 'since' => 1],
                ['name' => 'adresse', 'label' => 'Anschrift', 'type' => 'address', 'since' => 1],
                ['name' => 'standort', 'label' => 'Ort (Karte)', 'type' => 'geo', 'since' => 1],
                ['name' => 'hinweise', 'label' => 'Anfahrt und Hinweise', 'type' => 'textarea', 'since' => 1],
            ],
            // Wissen: Verknüpfungen auf die eigene Tabelle (oberkapitel, verwandt) bzw. das Glossar (begriffe) setzt enforceInput
            self::KNOWLEDGE => [
                ['name' => 'titel', 'label' => 'Titel', 'type' => 'text', 'required' => 1, 'in_list' => 1, 'searchable' => 1, 'since' => 1],
                ['name' => 'bereich', 'label' => 'Bereich', 'type' => 'select', 'in_list' => 1, 'options' => '', 'width' => 'half', 'since' => 1,
                    'help' => 'Oberste Gliederung, z. B. „Grundlagen“, „Praxis“. Die Reihenfolge der Bereiche ergibt die erste Stelle der Kapitelnummer.'],
                ['name' => 'oberkapitel', 'label' => 'Oberkapitel', 'type' => 'relation', 'target' => '', 'width' => 'half', 'since' => 1,
                    'help' => 'Leer = Kapitel der ersten Ebene. Sonst steht das Kapitel darunter (z. B. 2.1 unter 2).'],
                ['name' => 'kapitelnummer', 'label' => 'Kapitelnummer', 'type' => 'text', 'width' => 'half', 'since' => 1,
                    'help' => 'Leer = automatisch aus Bereich, Oberkapitel und Reihenfolge. Eigene Nummer, z. B. „A.1“, ersetzt die automatische.'],
                ['name' => 'anreisser', 'label' => 'Anreißer', 'type' => 'textarea', 'searchable' => 1, 'since' => 1,
                    'help' => 'Ein, zwei Sätze: worum es im Kapitel geht – in Übersicht, Suche und Inhaltsverzeichnis.'],
                ['name' => 'inhalt', 'label' => 'Inhalt', 'type' => 'blocks', 'searchable' => 1, 'since' => 1,
                    'help' => 'Text, Bilder, Downloads … wie auf einer Seite. Zwischenüberschriften (H2) erscheinen als Abschnitte „In diesem Kapitel“.'],
                ['name' => 'schlagworte', 'label' => 'Schlagworte', 'type' => 'multiselect', 'in_list' => 1, 'options' => '', 'since' => 1,
                    'help' => 'Besucher filtern danach; Kapitel mit gleichen Schlagworten erscheinen als „Verwandte Kapitel“.'],
                ['name' => 'verwandt', 'label' => 'Verwandte Kapitel', 'type' => 'relations', 'target' => '', 'since' => 1],
                ['name' => 'begriffe', 'label' => 'Glossarbegriffe', 'type' => 'relations', 'target' => '', 'since' => 1,
                    'help' => 'Begriffe aus dem Glossar der Website, die das Kapitel erklärt – erscheinen als „Begriffe in diesem Kapitel“.'],
                ['name' => 'autor', 'label' => 'Autor/in', 'type' => 'text', 'width' => 'half', 'since' => 1],
                ['name' => 'geprueft_am', 'label' => 'Zuletzt geprüft', 'type' => 'date', 'width' => 'half', 'since' => 1,
                    'help' => 'Wann der Inhalt zuletzt fachlich geprüft wurde – steht unter dem Kapitel.'],
            ],
            default => [],
        };
        return array_values(array_filter($all, fn($f) => (int) $f['since'] <= $version));
    }

    /** Namen der System-Felder einer Art */
    public static function systemNames(string $kind, ?int $version = null): array
    {
        return array_column(self::schema($kind, $version), 'name');
    }

    public static function isSystem(string $kind, string $name): bool
    {
        return self::isSpecial($kind) && in_array($name, self::systemNames($kind), true);
    }

    /** Auswahlfeld, das ohne Optionen gespeichert werden darf (Listen einer Kontakte-Tabelle) */
    public static function allowsEmptyOptions(string $kind, string $name): bool
    {
        return ($kind === self::CONTACTS && $name === 'listen') || ($kind === self::CALENDAR && $name === 'kategorie')
            || ($kind === self::KNOWLEDGE && in_array($name, ['bereich', 'schlagworte'], true));
    }

    /** Verknüpfung, die ohne Zieltabelle gespeichert werden darf (Ort aus der Liste, solange es keine Orte-Tabelle gibt) */
    public static function allowsEmptyTarget(string $kind, string $name): bool
    {
        return ($kind === self::CALENDAR && $name === 'ort_ref') || ($kind === self::KNOWLEDGE && $name === 'begriffe');
    }

    /** Erste Tabelle der Art „Orte“ (Ziel für ort_ref neuer Kalender), sonst '' */
    public static function placesTable(): string
    {
        foreach (Tables::content() as $t) if (self::isPlaces($t)) return (string) $t['handle'];
        return '';
    }

    /**
     * Eingabe des Designers (Tables::validate) an das Grundschema anpassen: fehlende System-Felder anhängen, Typ eines vorhandenen
     * System-Feldes festhalten (mit Hinweis). Beschriftung, Hilfe, Breite, „In der Liste“ und Optionen der Listen bleiben änderbar.
     */
    public static function enforceInput(string $kind, array $fields, array &$errors, string $handle = ''): array
    {
        $schema = array_column(self::schema($kind), null, 'name');
        // Wissen: Oberkapitel und verwandte Kapitel verweisen immer auf die eigene Tabelle, Glossarbegriffe aufs Glossar (falls vorhanden)
        if ($kind === self::KNOWLEDGE) {
            foreach (['oberkapitel', 'verwandt'] as $n) $schema[$n]['target'] = $handle;
            $schema['begriffe']['target'] = Tables::findContent(\Core\Glossary\Glossary::HANDLE) ? \Core\Glossary\Glossary::HANDLE : '';
        }
        $seen = [];
        foreach ($fields as $i => $f) {
            if (!is_array($f)) continue;
            $n = Tables::normName((string) (($f['name'] ?? '') ?: ($f['label'] ?? '')));
            // Kalender: Zusatzfelder (Rich-Text, Bilder, Verknüpfungen …) sind „Nur Website“ – nie in ICS-Datei, Abo oder CalDAV
            if ($kind === self::CALENDAR && !isset($schema[$n]) && empty($f['legacy']) && !in_array($f['type'] ?? 'text', Tables::LAYOUT, true)
                && !isset($f['exposure']) && empty($f['private']) && empty($f['website_only'])) {
                $fields[$i]['exposure'] = ['mode' => 'always', 'web' => 1, 'vcard' => 0, 'ics' => 0];
            }
            if (!isset($schema[$n]) || isset($seen[$n])) continue;
            $seen[$n] = true;
            if (($f['type'] ?? '') !== $schema[$n]['type']) {
                if (($f['type'] ?? '') !== '' && empty($f['legacy'])) {
                    $errors["fields.$i"] = __('Feld „{label}“ gehört zum Grundschema dieser Tabellenart – der Typ bleibt „{type}“.',
                        ['label' => (string) ($f['label'] ?? $n), 'type' => __(Tables::TYPES[$schema[$n]['type']][0])]);
                }
                $fields[$i]['type'] = $schema[$n]['type'];
            }
            $fields[$i]['name'] = $n;
            if (isset($schema[$n]['target']) && $kind === self::KNOWLEDGE && ($n !== 'begriffe' || $schema[$n]['target'] !== '' || ($f['target'] ?? '') === '')) {
                $fields[$i]['target'] = $schema[$n]['target'];
            }
            unset($fields[$i]['legacy']);
        }
        foreach ($schema as $n => $def) {
            if (isset($seen[$n])) continue;
            unset($def['since']);
            $fields[] = $def;
        }
        return $fields;
    }

    /**
     * Vollständige Vorlage für „Neue Tabelle → Adressbuch“: Grundschema mit Abschnitten wie in Adressbuch-Apps
     * (Name & Organisation, Kontakt, Weitere Angaben). Eigene Felder ergänzt die Redaktion danach.
     */
    public static function presetFields(string $kind): array
    {
        $by = array_column(self::schema($kind), null, 'name');
        $pick = function (string ...$names) use ($by): array {
            $out = [];
            foreach ($names as $n) {
                $f = $by[$n];
                unset($f['since']);
                $out[] = $f;
            }
            return $out;
        };
        if ($kind === self::CALENDAR) {
            $ref = $pick('ort_ref');
            $ref[0]['target'] = self::placesTable();
            return [
                ['label' => 'Termin', 'name' => 'abschnitt_termin', 'type' => 'section'],
                ...$pick('titel', 'kategorie', 'beginn', 'ende', 'ganztaegig', 'wiederholung'),
                ['label' => 'Ort', 'name' => 'abschnitt_ort', 'type' => 'section'],
                ...$pick('ort'), ...$ref, ...$pick('standort'),
                ['label' => 'Beschreibung', 'name' => 'abschnitt_beschreibung', 'type' => 'section'],
                ...$pick('beschreibung', self::CANCELLED_FIELD),
            ];
        }
        if ($kind === self::PLACES) return $pick('name', 'adresse', 'standort', 'hinweise');
        if ($kind === self::KNOWLEDGE) {
            return [
                ['label' => 'Kapitel', 'name' => 'abschnitt_kapitel', 'type' => 'section'],
                ...$pick('titel', 'bereich', 'oberkapitel', 'kapitelnummer', 'anreisser', 'inhalt'),
                ['label' => 'Zuordnung', 'name' => 'abschnitt_zuordnung', 'type' => 'section', 'style' => 'collapsed'],
                ...$pick('schlagworte', 'verwandt', 'begriffe', 'autor', 'geprueft_am'),
            ];
        }
        if ($kind !== self::CONTACTS) return [];
        return [
            ...$pick(self::PUBLIC_FIELD),
            ['label' => 'Name & Organisation', 'name' => 'abschnitt_name', 'type' => 'section'],
            ...$pick('name', 'praefix', 'vorname', 'nachname', 'position', 'firma', 'abteilung', 'ist_firma', 'foto'),
            ['label' => 'Kontakt', 'name' => 'abschnitt_kontakt', 'type' => 'section', 'help' => 'Jede Angabe ist zuerst intern – „Öffentlich“ zeigt sie auf der Website.'],
            ...$pick('telefon', 'email', 'adresse', 'web'),
            ['label' => 'Weitere Angaben', 'name' => 'abschnitt_weiteres', 'type' => 'section'],
            ...$pick('geburtstag', 'listen', 'notiz'),
        ];
    }

    /**
     * Felder der Detailseite eines Kontakts ohne eigene Auswahl (Block „Datensatz-Felder“): Foto, Listen, Funktion, Organisation,
     * Kontaktangaben, dann eigene Felder – ohne Namensteile, Schalter und Felder, die auf der Website nie erscheinen.
     */
    public static function detailFields(array $t): array
    {
        $first = ['foto', 'listen', 'position', 'firma', 'telefon', 'email', 'web', 'adresse'];
        $skip = [self::PUBLIC_FIELD, 'name', 'praefix', 'vorname', 'nachname', 'ist_firma', 'abteilung', 'geburtstag', 'notiz'];
        $names = array_column($t['fields'], 'name');
        $out = array_values(array_intersect($first, $names));
        foreach ($t['fields'] as $f) {
            if (in_array($f['name'], $first, true) || in_array($f['name'], $skip, true) || $f['type'] === 'blocks') continue;
            $out[] = $f['name'];
        }
        return Exposure::publicNames($t, $out);
    }

    /**
     * Beim Speichern (Entries::save): Anzeigename aus Titel, Vor- und Nachname bzw. Organisation, wenn leer.
     * Neue Kontakte: „Auf der Website zeigen“ ohne Angabe = aus.
     */
    public static function beforeSave(array $t, ?array $current, array $in): array
    {
        if (!self::isContacts($t)) return $in;
        if (!$current && !array_key_exists(self::PUBLIC_FIELD, $in)) $in[self::PUBLIC_FIELD] = false;
        $get = fn(string $k) => trim((string) (array_key_exists($k, $in) ? $in[$k] : ($current[$k] ?? '')));
        if ($get('name') === '') {
            $person = trim(implode(' ', array_filter([$get('praefix'), $get('vorname'), $get('nachname')], fn($s) => $s !== '')));
            $org = $get('firma');
            $in['name'] = ($get('ist_firma') !== '' && $get('ist_firma') !== '0' && $org !== '') ? $org : ($person !== '' ? $person : $org);
        }
        return $in;
    }

    /** Selbsttest (Teil von data:selftest): Grundschema, Versionen, Durchsetzen im Designer – ohne Datenbank */
    public static function selftest(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Tabellenarten – ');
        $eq('normalize', [self::normalize('contacts'), self::normalize('inbox'), self::normalize('quatsch'), self::normalize('')], ['contacts', 'inbox', 'content', 'content']);
        $names = self::systemNames(self::CONTACTS);
        $eq('Grundschema Kontakte', array_values(array_intersect(['oeffentlich', 'name', 'vorname', 'nachname', 'position', 'foto', 'telefon', 'email', 'adresse', 'web', 'listen'], $names)),
            ['oeffentlich', 'name', 'vorname', 'nachname', 'position', 'foto', 'telefon', 'email', 'adresse', 'web', 'listen']);
        $eq('Version 1 = aktuell, nur additiv', [self::version(self::CONTACTS), count(self::schema(self::CONTACTS, 1)) <= count(self::schema(self::CONTACTS))], [1, true]);
        $eq('Mehrfachfelder sind Combo-Typen', array_values(array_filter(array_column(self::schema(self::CONTACTS), 'type'), fn($t) => \Core\Combo::isMulti($t))), ['phones', 'emails', 'addresses', 'urls']);
        $eq('Geburtstag und Notiz nie öffentlich', [Tables::field(['fields' => self::schema(self::CONTACTS)], 'geburtstag')['private'] ?? 0, Tables::field(['fields' => self::schema(self::CONTACTS)], 'notiz')['private'] ?? 0], [1, 1]);
        $err = [];
        $in = self::enforceInput(self::CONTACTS, [['label' => 'Telefon', 'name' => 'telefon', 'type' => 'tel'], ['label' => 'Vita', 'type' => 'richtext']], $err);
        $by = array_column($in, null, 'name');
        $eq('Typ eines Grundfelds bleibt fest (mit Hinweis)', [$by['telefon']['type'] ?? null, isset($err['fields.0'])], ['phones', true]);
        $eq('Fehlende Grundfelder ergänzt, Zusatzfeld bleibt', [isset($by['vorname'], $by['oeffentlich']), ($in[1]['type'] ?? '') === 'richtext'], [true, true]);
        $eq('Kein Grundfeld doppelt', count($in), count(self::schema(self::CONTACTS)) + 1);
        $s = self::beforeSave(['settings' => ['kind' => 'contacts']], null, ['vorname' => 'Erika', 'nachname' => 'Musterfrau', 'praefix' => 'Dr.']);
        $eq('Anzeigename aus Titel/Vor-/Nachname, neu = nicht öffentlich', [$s['name'], $s['oeffentlich']], ['Dr. Erika Musterfrau', false]);
        $s = self::beforeSave(['settings' => ['kind' => 'contacts']], null, ['firma' => 'Musterverein e. V.', 'ist_firma' => '1']);
        $eq('Anzeigename einer Organisation', $s['name'], 'Musterverein e. V.');
        $eq('Inhaltstabellen unverändert', self::beforeSave(['settings' => ['kind' => 'content']], null, ['a' => 1]), ['a' => 1]);
        return ['ok' => $ok, 'fails' => $fails];
    }
}
