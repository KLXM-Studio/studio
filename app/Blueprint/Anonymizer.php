<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Blueprint;

use Core\Data\Kinds;
use Core\Data\Tables;

/**
 * Demoinhalte einer Website-Vorlage anonymisieren (Core\Blueprint\Export): aus echten Einträgen werden Beispiel-Einträge.
 *
 *   Personen        Platzhalter-Personen (Erika Musterfrau, Max Mustermann …) – Kontakte (Art contacts) immer, sonst Felder mit
 *                   Namen wie name/vorname/nachname/ansprechperson/autor und Kombifelder „Ansprechperson“
 *   Anschrift       Musterstraße 1, 12345 Musterstadt; Kartenpunkte auf die Mitte Deutschlands
 *   Kontakt         E-Mail kontakt@example.org, Telefon +49 30 1234567-n, Webadressen https://example.org, IBAN leer
 *   Privates        Felder mit 'private' (Geburtstag, Notiz) leer
 *   Texte           E-Mail-Adressen, Telefonnummern, IBANs und Webadressen der Quelle im Fließtext ersetzt, Angaben der Organisation →
 *                   Platzhalter (Core\Blueprint\Placeholders)
 *   Termine         Datumsfelder relativ zu heute ({"@rel": Tage, "t": "18:30"}), beim Anlegen wieder absolute Daten
 *
 * Alles deterministisch (gleiche Eingabe → gleiche Ausgabe), damit Pakete vergleichbar bleiben.
 */
final class Anonymizer
{
    public const PERSONS = [
        ['Erika', 'Musterfrau'], ['Max', 'Mustermann'], ['Paula', 'Platzhalter'], ['Jonas', 'Beispiel'], ['Lena', 'Vorlage'],
        ['Tim', 'Muster'], ['Clara', 'Probe'], ['Ben', 'Demo'],
    ];
    public const EMAIL = 'kontakt@example.org';
    public const STREET = 'Musterstraße 1';
    public const ZIP = '12345';
    public const CITY = 'Musterstadt';
    public const URL = 'https://example.org';
    /** Mitte Deutschlands (Kartenpunkt der Demo) */
    public const GEO = '51.1634, 10.4477';

    /** Feldnamen, die Personen tragen (Text) */
    private const PERSON_NAMES = '~^(name|vorname|nachname|praefix|ansprechperson|ansprechpartner(in)?|kontaktperson|person|autor(in)?|leitung|referent(in)?|verantwortlich|sprecher(in)?|vorsitz(ende)?|trainer(in)?)$~';
    private const ADDRESS_NAMES = '~^(strasse|straße|street|anschrift|hausnummer)$~';
    private const ZIP_NAMES = '~^(plz|zip|postleitzahl)$~';
    private const CITY_NAMES = '~^(ort|stadt|city|wohnort)$~';
    /** schema.org-Typen von Tabellen mit Organisationen (Mitglieder, Vereine, Partner) – Titel und Beschreibung werden Beispiele */
    public const ORG_TYPES = ['Organization', 'LocalBusiness', 'NGO', 'EducationalOrganization', 'SportsOrganization', 'Corporation', 'MedicalOrganization', 'Person'];

    /** Namen von Personen der Quelle (Konten, Kontakte, Personenfelder) → Platzhalter-Person; Muster in Blöcken zu je 150 Namen */
    private array $personRegex = [];
    /** @var array<string, string> Name → Platzhalter-Person */
    private array $personMap = [];
    /** Neutrale Namen für Gebiete der Quelle (Filter blueprint.areas → rename): Muster und Zuordnung */
    private string $renameRegex = '';
    /** @var array<string, string> */
    private array $renameMap = [];

    /** Wie oft Namen von Personen ersetzt wurden */
    public int $personHits = 0;

    /** @param array<string, string> $src Werte der Quelle für Platzhalter  @param list<string> $persons Namen von Personen der Quelle */
    public function __construct(private array $src = [], private int $today = 0, array $persons = [])
    {
        $this->today = $today ?: (int) strtotime('today');
        $persons = array_values(array_unique(array_filter(array_map('trim', $persons), fn($n) => mb_strlen($n) >= 5)));
        usort($persons, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($persons as $i => $n) {
            [$f, $l] = self::person($i);
            $this->personMap[mb_strtolower($n)] = $f . ' ' . $l;
        }
        foreach (array_chunk($persons, 150) as $chunk) {
            $this->personRegex[] = '~(?<![\p{L}\d])(?:' . implode('|', array_map(fn($n) => preg_quote($n, '~'), $chunk)) . ')(?![\p{L}\d])~iu';
        }
    }

    /**
     * Namen der Quelle in allen Texten ersetzen (z. B. „Musterheide“ → „Regionalgruppe Nord“). $prefixes: Wörter, die davor
     * stehen dürfen und mit ersetzt werden („Regionalgruppe Musterheide“ → „Regionalgruppe Nord“, nicht doppelt).
     * @param array<string, string> $map @param list<string> $prefixes
     */
    public function renames(array $map, array $prefixes = []): void
    {
        $map = array_filter($map, fn($v, $k) => is_string($k) && mb_strlen(trim($k)) >= 3 && is_string($v) && $v !== '', ARRAY_FILTER_USE_BOTH);
        if (!$map) return;
        uksort($map, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $this->renameMap = $map;
        $pre = array_values(array_filter(array_map('trim', $prefixes), fn($p) => $p !== ''));
        $this->renameRegex = '~(?<![\p{L}\d])' . ($pre ? '(?:(?:' . implode('|', array_map(fn($p) => preg_quote($p, '~'), $pre)) . ')\s+)?' : '')
            . '(' . implode('|', array_map(fn($n) => preg_quote($n, '~'), array_keys($map))) . ')(?![\p{L}\d-])~u';   // Zusammensetzungen („…-Fest“) bleiben – die Prüfung meldet sie
    }

    /**
     * Namen von Personen aus den Daten der Website: Konten, Kontakte (Art contacts), Personenfelder (Ansprechperson, Autor, Leitung …) und
     * Kombifelder „Ansprechperson“ – auch kommagetrennt („A, B und C“). Nur Namen aus mindestens zwei Wörtern. @return list<string>
     */
    public static function personNames(): array
    {
        $names = [];
        $add = function (mixed $v) use (&$names): void {
            foreach (preg_split('~\s*(?:,|;|/|\bund\b|&|\n)\s*~u', trim(strip_tags((string) $v))) ?: [] as $n) {
                $n = trim((string) preg_replace('~^(Dr\.|Prof\.|Frau|Herr)\s+~u', '', trim($n)));
                if (preg_match('~^\p{Lu}[\p{L}.\'-]+(?:\s+\p{Lu}?[\p{L}.\'-]+){1,3}$~u', $n) && mb_strlen($n) <= 60) $names[$n] = true;
            }
        };
        try {
            foreach (app()->db->fetchAll('SELECT name FROM users') as $u) $add($u['name']);
        } catch (\Throwable) {
        }
        foreach (Tables::all() as $t) {
            if (Tables::isShared($t) || Tables::isInbox($t)) continue;
            $contacts = Kinds::isContacts($t);
            $title = (string) ($t['settings']['title_field'] ?? '');
            $cols = [];
            $persons = [];
            foreach (Tables::dataFields($t['fields']) as $f) {
                $n = (string) $f['name'];
                if ($f['type'] === 'person') $persons[] = $n;
                elseif ($f['type'] === 'text' && (($contacts && in_array($n, ['name', 'vorname', 'nachname'], true)) || ($n !== 'name' && $n !== $title && preg_match(self::PERSON_NAMES, $n)))) $cols[] = $n;
            }
            if (!$cols && !$persons) continue;
            try {
                foreach (Tables::db($t)->fetchAll('SELECT * FROM ' . $t['table'] . ' LIMIT 5000') as $r) {
                    foreach ($cols as $c) $add($r[$c] ?? '');
                    if ($contacts) $add(trim((string) ($r['vorname'] ?? '') . ' ' . (string) ($r['nachname'] ?? '')));
                    foreach ($persons as $c) foreach ((array) json_decode((string) ($r[$c] ?? ''), true) as $p) if (is_array($p)) $add($p['name'] ?? '');
                }
            } catch (\Throwable) {
            }
        }
        return array_keys($names);
    }

    /** Fließtext säubern: E-Mail, Telefon, IBAN, eigene Domains → Beispielwerte, Angaben der Organisation → Platzhalter */
    public function text(string $s): string
    {
        if ($s === '') return $s;
        // Redaktionsnotizen [# … #] sind intern (Core\EditorNotes) – nie in eine Vorlage
        if (str_contains($s, '[#')) $s = (string) preg_replace('~\s?\[#.*?#\]~su', '', $s);
        // Personen der Quelle (aus Konten und Daten) → immer dieselbe Platzhalter-Person je Name
        foreach ($this->personRegex as $re) {
            $s = (string) preg_replace_callback($re, function ($m) { $this->personHits++; return $this->personMap[mb_strtolower($m[0])] ?? 'Erika Musterfrau'; }, $s);
        }
        if ($this->renameRegex !== '') $s = (string) preg_replace_callback($this->renameRegex, fn($m) => $this->renameMap[$m[1]] ?? $m[0], $s);
        $s = Placeholders::tokenize($s, $this->src);
        // E-Mail-Adressen (außer Beispiel-Domains und Platzhaltern)
        $s = (string) preg_replace_callback('~[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+\.)+[A-Za-z]{2,}~', fn($m) => preg_match('~@(example\.(org|com|net)|[a-z0-9-]+\.(test|example|invalid))$~i', $m[0]) ? $m[0] : self::EMAIL, $s);
        $s = str_replace(['@{{org.domain}}', 'mailto:' . self::EMAIL], ['@example.org', 'mailto:' . self::EMAIL], $s);
        // IBAN (DE + 20 Ziffern, auch mit Leerzeichen) und andere Länder grob
        $s = (string) preg_replace('~\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){3,7}(?:[ ]?[A-Z0-9]{1,3})?\b~', "\x1FIBAN\x1F", $s);
        // Telefonnummern: +49 / 0 gefolgt von mindestens 6 Ziffern (Leerzeichen, /, -, Klammern erlaubt) – Jahreszahlen und Uhrzeiten nicht
        $s = (string) preg_replace_callback('~(?<![\w./-])(?:\+\d{2}[\s/-]?|\(0\d+\)\s?|0\d{2,5}[\s/-]?)(?:\d[\s/-]?){5,12}\d(?![\w.-])~', fn($m) => preg_match('~^\+49 30 1234567~', $m[0]) ? $m[0] : '+49 30 1234567', $s);
        // Beispiel-IBAN erst nach den Telefonnummern einsetzen (ihre Nullen sähen sonst wie eine Nummer aus)
        return str_replace("\x1FIBAN\x1F", 'DE00 0000 0000 0000 0000 00', $s);
    }

    /** Strings einer Struktur säubern (JSON von Blöcken, Kombifeldern) */
    public function deep(mixed $v): mixed
    {
        if (is_string($v)) return $this->text($v);
        if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = $this->deep($x); }
        return $v;
    }

    /** Platzhalter-Person Nr. $i @return array{0: string, 1: string} */
    public static function person(int $i): array
    {
        return self::PERSONS[$i % count(self::PERSONS)];
    }

    /**
     * Eintrag anonymisieren. $i = laufende Nummer (Platzhalter-Person), $mediaMap: alte Medien-ID → neue bzw. 0 (Platzhalter),
     * $keepIds: exportierte Einträge je Tabelle (Verknüpfungen nur darauf). Datumsfelder werden relativ ({"@rel": …}).
     */
    public function entry(array $t, array $row, int $i, array $mediaMap = [], array $keepIds = []): array
    {
        $contacts = Kinds::isContacts($t);
        [$first, $last] = self::person($i);
        $out = [];
        foreach ($row as $col => $val) $out[$col] = $val;
        foreach (Tables::dataFields($t['fields']) as $f) {
            $n = (string) $f['name'];
            if (!array_key_exists($n, $row)) continue;
            $v = $row[$n];
            $type = (string) $f['type'];
            if (!empty($f['private'])) { $out[$n] = null; continue; }
            if ($v === null || $v === '') continue;
            $out[$n] = match (true) {
                $type === 'date' || $type === 'datetime' => $this->relDate((string) $v),
                $type === 'media' || $type === 'file' => $this->media($v, $mediaMap),
                $type === 'email' => self::EMAIL,
                $type === 'tel' => '+49 30 1234567',
                $type === 'url' => self::URL,
                $type === 'iban' => null,
                $type === 'geo' => self::GEO,
                $type === 'relation' || $type === 'contact' => in_array((int) $v, $keepIds[(string) ($f['target'] ?? '')] ?? [], true) ? $v : null,
                in_array($type, ['phones', 'emails', 'urls', 'addresses', 'address', 'person'], true) => $this->combo($type, (string) $v, $i),
                in_array($type, ['richtext', 'textarea', 'faq', 'mediatext', 'downloads', 'blocks', 'group', 'roles'], true) => is_string($v) ? $this->jsonOrText($v, $mediaMap) : $v,
                $type === 'text' && $contacts && in_array($n, ['name', 'vorname', 'nachname', 'praefix', 'position', 'firma', 'abteilung'], true)
                    => match ($n) { 'name' => $first . ' ' . $last, 'vorname' => $first, 'nachname' => $last, 'praefix' => '', 'firma' => $this->text((string) $v), default => $this->text((string) $v) },
                $type === 'text' && preg_match(self::PERSON_NAMES, $n) === 1 && !$this->isTitleLike($t, $n) => $first . ' ' . $last,
                $type === 'text' && preg_match(self::ADDRESS_NAMES, $n) === 1 => self::STREET,
                $type === 'text' && preg_match(self::ZIP_NAMES, $n) === 1 => self::ZIP,
                $type === 'text' && preg_match(self::CITY_NAMES, $n) === 1 && Kinds::isCalendar($t) === false => self::CITY,
                is_string($v) => $this->text($v),
                default => $v,
            };
        }
        // Organisationen (schema.org-Typ der Tabelle): Titel und Beschreibung werden Beispiel-Angaben – echte Namen gehen nicht mit
        if (!$contacts && in_array((string) ($t['settings']['schema_type'] ?? ''), self::ORG_TYPES, true)) {
            $tf = (string) ($t['settings']['title_field'] ?? '');
            $label = 'Beispiel-Organisation ' . ($i + 1);
            if ($tf !== '' && array_key_exists($tf, $out)) $out[$tf] = $label;
            foreach (Tables::dataFields($t['fields']) as $f) {
                $n = (string) $f['name'];
                if ($n === $tf || ($out[$n] ?? null) === null || ($out[$n] ?? '') === '') continue;
                $out[$n] = match ((string) $f['type']) {
                    'richtext' => '<p>Kurzbeschreibung: Was bietet ' . $label . ' an, für wen, wo?</p>',
                    'textarea' => 'Kurzbeschreibung: Was bietet ' . $label . ' an, für wen, wo?',
                    'blocks', 'mediatext', 'faq', 'downloads' => '[]',
                    'text' => preg_match(self::PERSON_NAMES, $n) || preg_match(self::ADDRESS_NAMES, $n) || preg_match(self::ZIP_NAMES, $n) || preg_match(self::CITY_NAMES, $n) ? $out[$n] : '',
                    default => $out[$n],
                };
            }
            if (isset($out['slug'])) $out['slug'] = self::slug($label);
        }
        // Kontakte: Titel und Adresse passen zur Platzhalter-Person
        if ($contacts) {
            if (array_key_exists('name', $out) && (int) ($row['ist_firma'] ?? 0) === 0) $out['name'] = $first . ' ' . $last;
            if (isset($out['slug'])) $out['slug'] = self::slug($first . '-' . $last . '-' . ($i + 1));
        }
        foreach (['created_at', 'updated_at', 'published_at'] as $c) if (array_key_exists($c, $out)) $out[$c] = null;
        return $out;
    }

    /** Ist das Feld die Titelspalte einer Nicht-Kontakt-Tabelle (z. B. „name“ eines Ortes)? Dann bleibt es Text */
    private function isTitleLike(array $t, string $n): bool
    {
        return !Kinds::isContacts($t) && (($t['settings']['title_field'] ?? '') === $n || Kinds::isPlaces($t));
    }

    /** Datum relativ zu heute: {"@rel": Tage, "t": "HH:MM"} (Uhrzeit nur bei Datum mit Zeit) */
    public function relDate(string $v): mixed
    {
        $ts = strtotime(substr($v, 0, 10));
        if ($ts === false) return null;
        $days = (int) round(($ts - $this->today) / 86400);
        $time = strlen($v) > 10 ? substr(str_replace('T', ' ', $v), 11, 5) : '';
        return ['@rel' => $days, 't' => $time, 'f' => strlen($v) > 10 ? (str_contains($v, 'T') ? 'T' : ' ') : ''];
    }

    /** Relatives Datum wieder absolut (beim Anlegen) */
    public static function absDate(mixed $v, int $today = 0, int $shift = 0): ?string
    {
        if (!is_array($v) || !isset($v['@rel'])) return is_string($v) ? $v : null;
        $n = (int) $v['@rel'] + $shift;
        $d = date('Y-m-d', (int) strtotime(($n >= 0 ? '+' : '') . $n . ' days', $today ?: (int) strtotime('today')));
        if (($v['t'] ?? '') === '') return $d;
        return $d . (($v['f'] ?? ' ') === 'T' ? 'T' : ' ') . $v['t'];
    }

    private function media(mixed $v, array $map): ?int
    {
        $id = (int) $v;
        if ($id <= 0) return null;
        // Beim Export (ohne Zuordnung) bleibt die ID – das Anlegen ordnet freie Bilder zu, alle übrigen werden Platzhalter
        return !$map ? $id : (array_key_exists($id, $map) ? (int) $map[$id] : (int) ($map[0] ?? 0));
    }

    /** JSON-Feld (Kombifelder, Blöcke) oder Text säubern; Medien-IDs darin auf Platzhalter */
    private function jsonOrText(string $v, array $mediaMap): string
    {
        $t = ltrim($v);
        if ($t !== '' && ($t[0] === '[' || $t[0] === '{') && ($d = json_decode($v, true)) !== null) {
            return (string) json_encode(self::remapMedia($this->deep($d), $mediaMap), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $this->text($v);
    }

    /** Kombi- und Mehrfachfelder: Werte durch Beispielwerte ersetzen, Beschriftungen und Freigaben bleiben */
    private function combo(string $type, string $raw, int $i): string
    {
        $d = json_decode($raw, true);
        if (!is_array($d)) return $type === 'emails' ? self::EMAIL : '';
        [$first, $last] = self::person($i);
        $fix = function (array $row) use ($type, $first, $last, $i): array {
            foreach ($row as $k => $x) {
                if (!is_string($x) || $x === '' || in_array($k, ['label', 'public', 'label_custom', 'country'], true)) continue;
                $row[$k] = match (true) {
                    $k === 'street' => self::STREET,
                    $k === 'zip' => self::ZIP,
                    $k === 'city' => self::CITY,
                    $k === 'name' => $first . ' ' . $last,
                    $k === 'email' || ($k === 'value' && $type === 'emails') => self::EMAIL,
                    $k === 'phone' || $k === 'tel' || ($k === 'value' && $type === 'phones') => '+49 30 1234567-' . ($i + 1),
                    $k === 'value' && $type === 'urls' => self::URL,
                    $k === 'image' || $k === 'photo' => '',
                    default => $this->text($x),
                };
            }
            return $row;
        };
        $d = array_is_list($d) ? array_map(fn($r) => is_array($r) ? $fix($r) : $r, $d) : $fix($d);
        return (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Medien-IDs in Block- bzw. JSON-Daten umschreiben (Schlüssel wie image, media, poster, logo, file – Core\ContentSync::isMediaKey):
     * alte ID → neue; nicht zugeordnete → $map[0] (Platzhalterbild). Leere Zuordnung: unverändert.
     */
    public static function remapMedia(mixed $v, array $map, string $key = ''): mixed
    {
        if (!$map) return $v;
        // Video, das nicht mitgeht (Platzhalter sind Bilder): Feld leeren und – ohne eigenes Bild – das Platzhalterbild zeigen,
        // sonst bliebe z. B. der vollflächige Einstieg schwarz
        if (is_array($v) && !array_is_list($v) && isset($v['video']) && is_numeric($v['video']) && (int) $v['video'] > 0 && !array_key_exists((int) $v['video'], $map)) {
            $v['video'] = null;
            if (array_key_exists('image', $v) && empty($v['image']) && !empty($map[0])) $v['image'] = (int) $map[0];
        }
        if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = self::remapMedia($x, $map, is_string($k) ? $k : $key); return $v; }
        if ($key !== '' && \Core\ContentSync::isMediaKey($key) && (is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v > 0) {
            $new = array_key_exists((int) $v, $map) ? (int) $map[(int) $v] : (int) ($map[0] ?? 0);
            return is_int($v) ? $new : (string) $new;
        }
        return $v;
    }

    /** Medien-IDs, auf die eine Struktur verweist (gleiche Regel wie remapMedia) @return list<int> */
    public static function mediaIds(mixed $v, string $key = ''): array
    {
        if (is_array($v)) {
            $ids = [];
            foreach ($v as $k => $x) array_push($ids, ...self::mediaIds($x, is_string($k) ? $k : $key));
            return array_values(array_unique($ids));
        }
        return $key !== '' && \Core\ContentSync::isMediaKey($key) && (is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v > 0 ? [(int) $v] : [];
    }

    public static function slug(string $s): string
    {
        $s = strtr(mb_strtolower($s), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        return trim((string) preg_replace('~[^a-z0-9]+~', '-', $s), '-') ?: 'eintrag';
    }
}
