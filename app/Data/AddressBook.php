<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Combo;

/**
 * Adressbuch-Ansicht der Verwaltung für Tabellen der Art „contacts“ (Core\Data\Kinds) – aufgebaut wie Kontakte-Apps: links
 * Adressbücher, Listen (Werte des Feldes „listen“) und Filter, in der Mitte die Namen A–Z nach Nachname mit Suche, rechts die
 * Karte des gewählten Kontakts mit gruppierten Angaben und dem Merkmal Öffentlich/Intern (Exposure::reach).
 *
 * Abfragen: Zähler in einer Abfrage (counts), Namen als schlanke Zeilen (index) – gefiltert und durchsucht in SQL, sortiert in
 * PHP (Umlaute wie im Telefonbuch: „Ährlich“ unter A), in Abschnitten zu PAGE Namen. Listen zuordnen (assign) speichert jeden
 * Kontakt über Entries::save – mit Revision, Ereignissen und Rechten wie im Formular. vCards (vcards) mit allen bzw. nur den
 * öffentlichen Angaben (Exposure-Ziel vcard). Die Wahl „Adressbuch | Liste“ merkt sich jedes Konto (users.ui_prefs).
 */
final class AddressBook
{
    /** Namen je Abschnitt der mittleren Spalte (weitere laden beim Scrollen bzw. per Link) */
    public const PAGE = 150;
    /** Ansichten einer Kontakte-Tabelle: Adressbuch (Standard) | Tabellenliste */
    public const VIEWS = ['book', 'list'];
    /** Feste Filter der linken Spalte (Schlüssel im Parameter ?filter=) */
    public const FILTERS = ['offline', 'nofoto', 'nomail'];
    /** Schlüssel in users.ui_prefs */
    private const PREF = 'contacts_view';

    /** Tabelle mit Adressbuch-Ansicht? (Art contacts) */
    public static function enabled(array $t): bool
    {
        return Kinds::isContacts($t) && !Tables::isInbox($t);
    }

    /** Gewählte Ansicht eines Kontos (Standard: Adressbuch) */
    public static function view(?array $user): string
    {
        $p = json_decode((string) ($user['ui_prefs'] ?? ''), true);
        $v = is_array($p) ? (string) ($p[self::PREF] ?? '') : '';
        return in_array($v, self::VIEWS, true) ? $v : 'book';
    }

    /** Ansicht für ein Konto merken (Zeile der Website-Datenbank wie Core\Accent) */
    public static function setView(int $userId, string $view): void
    {
        if (!in_array($view, self::VIEWS, true) || $userId <= 0) return;
        $row = app()->db->fetch('SELECT ui_prefs FROM users WHERE id = ?', [$userId]);
        if (!$row) return;
        $p = json_decode((string) ($row['ui_prefs'] ?? ''), true);
        $p = is_array($p) ? $p : [];
        if (($p[self::PREF] ?? null) === $view) return;
        $p[self::PREF] = $view;
        app()->db->update('users', ['ui_prefs' => json_encode($p)], 'id = :id', ['id' => $userId]);
        if (isset(app()->auth) && ($u = app()->auth->user()) && (int) $u['id'] === $userId) app()->auth->reset();
    }

    /** Kontakte-Tabellen, die das Konto pflegen darf (Spalte „Adressbücher“, Menüpunkt „Kontakte“) */
    public static function tables(): array
    {
        return array_values(array_filter(Tables::content(), fn($t) => self::enabled($t) && can('data.edit', $t['handle'])));
    }

    /** Listen (Gruppen) der Tabelle: Schlüssel → Beschriftung (Optionen des Feldes „listen“) */
    public static function lists(array $t): array
    {
        $f = Tables::field($t, 'listen');
        if (!$f || !is_array($f['options'] ?? null)) return [];
        $out = [];
        foreach (array_keys($f['options']) as $k) $out[(string) $k] = Tables::optionLabel($f, (string) $k);
        return $out;
    }

    /** Spalten, die es in der Tabelle gibt (ältere Tabellen nach data:convert können Grundfelder anders nutzen) */
    private static function has(array $t, string $name): bool
    {
        $f = Tables::field($t, $name);
        return $f !== null && ($f['type'] ?? '') !== 'relations' && !Tables::isLayout($f);
    }

    /** Grundbedingungen: eigene Einträge (geteilte Tabellen), Standardsprache – wie die Tabellenliste */
    private static function base(array $t, array &$params): array
    {
        $where = [];
        if (\Core\Lang::multi()) {
            $where[] = \Core\Lang::sql();
            $params[] = \Core\Lang::default();
        }
        if (Tables::isShared($t)) $where[] = Shared::sourceSql($t, 'own', $params, fn(array $w, array &$p) => Entries::conditions($t, $w, $p));
        return $where;
    }

    /** SQL: Kontakt nicht auf der Website (Freigabe aus oder nicht veröffentlicht) */
    private static function offlineSql(): string
    {
        return '(COALESCE(' . Kinds::PUBLIC_FIELD . ", 0) = 0 OR status != 'published')";
    }

    /** SQL: leeres Mehrfach- bzw. Bildfeld */
    private static function emptySql(string $col, bool $json): string
    {
        return $json ? "($col IS NULL OR $col IN ('', '[]', 'null'))" : "($col IS NULL OR $col = 0 OR $col = '')";
    }

    /** SQL-Teil einer Liste (Mehrfachauswahl als JSON-Array: ["schluessel", …]) */
    private static function listSql(string $key, array &$params): string
    {
        $params[] = '%' . json_encode($key, JSON_UNESCAPED_UNICODE) . '%';
        return 'listen LIKE ?';
    }

    /**
     * Zähler der linken Spalte in einer Abfrage: alle, je Liste, nicht auf der Website, ohne Foto, ohne E-Mail.
     * @return array{all: int, lists: array<string, int>, offline: int, nofoto: int, nomail: int}
     */
    public static function counts(array $t): array
    {
        $params = [];
        $sel = ['COUNT(*) AS n_all'];
        $sel[] = 'SUM(CASE WHEN ' . self::offlineSql() . ' THEN 1 ELSE 0 END) AS n_offline';
        $sel[] = self::has($t, 'foto') ? 'SUM(CASE WHEN ' . self::emptySql('foto', false) . ' THEN 1 ELSE 0 END) AS n_nofoto' : '0 AS n_nofoto';
        $sel[] = self::has($t, 'email') ? 'SUM(CASE WHEN ' . self::emptySql('email', true) . ' THEN 1 ELSE 0 END) AS n_nomail' : '0 AS n_nomail';
        $keys = array_keys(self::lists($t));
        $lp = [];
        foreach ($keys as $i => $k) $sel[] = 'SUM(CASE WHEN ' . self::listSql((string) $k, $lp) . " THEN 1 ELSE 0 END) AS n_l$i";
        $where = self::base($t, $params);
        $row = Tables::db($t)->fetch('SELECT ' . implode(', ', $sel) . " FROM {$t['table']}" . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), array_merge($lp, $params)) ?? [];
        $lists = [];
        foreach ($keys as $i => $k) $lists[(string) $k] = (int) ($row["n_l$i"] ?? 0);
        return ['all' => (int) ($row['n_all'] ?? 0), 'lists' => $lists, 'offline' => (int) ($row['n_offline'] ?? 0),
            'nofoto' => (int) ($row['n_nofoto'] ?? 0), 'nomail' => (int) ($row['n_nomail'] ?? 0)];
    }

    /**
     * Namen der mittleren Spalte: schlanke Zeilen (id, Namensteile, Funktion, Foto, Listen, Freigabe), gefiltert nach Liste
     * bzw. festem Filter und Suche, sortiert nach Nachname (Organisationen nach Namen), dann Vorname. Jede Zeile bekommt
     * '_key' (Sortierschlüssel) und '_letter' (A–Z bzw. #).
     * Suche: Name, Vor-/Nachname, Organisation, Funktion, Telefon (auch ohne Leerzeichen/Striche), E-Mail.
     * @return list<array<string, mixed>>
     */
    public static function index(array $t, string $list = '', string $filter = '', string $q = ''): array
    {
        $params = [];
        $where = self::base($t, $params);
        if ($list !== '' && isset(self::lists($t)[$list])) $where[] = self::listSql($list, $params);
        $where = array_merge($where, match ($filter) {
            'offline' => [self::offlineSql()],
            'nofoto' => self::has($t, 'foto') ? [self::emptySql('foto', false)] : [],
            'nomail' => self::has($t, 'email') ? [self::emptySql('email', true)] : [],
            default => [],
        });
        $q = trim($q);
        if ($q !== '') {
            $or = [];
            foreach (preg_split('~\s+~u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                // Jedes Wort muss irgendwo vorkommen („erika muster“ findet Erika Musterfrau)
                $any = [];
                foreach (['name', 'vorname', 'nachname', 'firma', 'position', 'abteilung', 'email', 'telefon'] as $c) {
                    if (!self::has($t, $c)) continue;
                    // Mehrfachfelder sind JSON: Schlüsselnamen ("label", "value" …) nicht mitdurchsuchen – sonst fände „abel“ jede E-Mail
                    $any[] = (in_array($c, ['email', 'telefon'], true) ? self::jsonText($c) : $c) . ' LIKE ?';
                    $params[] = '%' . $word . '%';
                }
                $digits = preg_replace('~\D+~', '', $word);
                if (self::has($t, 'telefon') && strlen((string) $digits) >= 3) {
                    $any[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefon, ' ', ''), '-', ''), '/', ''), '(', ''), ')', '') LIKE ?";
                    $params[] = '%' . $digits . '%';
                }
                if ($any) $or[] = '(' . implode(' OR ', $any) . ')';
            }
            if ($or) $where[] = implode(' AND ', $or);
        }
        $cols = array_values(array_filter(['name', 'praefix', 'vorname', 'nachname', 'firma', 'ist_firma', 'position', 'abteilung', 'foto', 'listen', Kinds::PUBLIC_FIELD], fn($c) => self::has($t, $c)));
        $rows = Tables::db($t)->fetchAll('SELECT id, status, ' . implode(', ', $cols) . " FROM {$t['table']}" . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params);
        foreach ($rows as &$r) {
            $r['_key'] = self::sortKey($r);
            $r['_letter'] = self::letter($r['_key']);
        }
        unset($r);
        usort($rows, fn($a, $b) => [$a['_key'], (int) $a['id']] <=> [$b['_key'], (int) $b['id']]);
        return $rows;
    }

    /**
     * Adresse der Ansicht mit Zustand $s (list, filter, q, e, select) und Änderungen $over (liste, filter, q, k, auswahl,
     * ansicht, ab, buchstabe – leere Werte entfallen). Ohne JavaScript tragen diese Links die ganze Bedienung.
     */
    public static function link(array $t, array $s, array $over = []): string
    {
        $p = array_merge(['liste' => $s['list'] ?? '', 'filter' => $s['filter'] ?? '', 'q' => $s['q'] ?? '',
            'k' => isset($s['e']['id']) ? (string) $s['e']['id'] : '', 'auswahl' => !empty($s['select']) ? '1' : ''], $over);
        $p = array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
        return url('/admin/data/' . $t['handle']) . ($p ? '?' . http_build_query($p) : '');
    }

    /** SQL: JSON eines Mehrfachfeldes ohne die Schlüsselnamen der Angaben (für LIKE) */
    private static function jsonText(string $col): string
    {
        $sql = $col;
        foreach (['"label_custom":', '"label":', '"value":', '"public":'] as $k) $sql = "REPLACE($sql, '$k', '')";
        return $sql;
    }

    /** Ist der Kontakt eine Organisation (oder hat er nur einen Namen der Organisation)? */
    public static function isOrg(array $r): bool
    {
        $flag = $r['ist_firma'] ?? null;
        $on = $flag !== null && $flag !== '' && $flag !== '0' && $flag !== 0 && $flag !== false;
        return $on || (trim((string) ($r['nachname'] ?? '')) === '' && trim((string) ($r['vorname'] ?? '')) === '' && trim((string) ($r['firma'] ?? '')) !== '' && trim((string) ($r['name'] ?? '')) === trim((string) ($r['firma'] ?? '')));
    }

    /** Sortierschlüssel: Nachname, Vorname (Personen) bzw. Name (Organisationen, Kontakte ohne Namensteile) – Umlaute wie A/O/U */
    public static function sortKey(array $r): string
    {
        $last = trim((string) ($r['nachname'] ?? ''));
        $first = trim((string) ($r['vorname'] ?? ''));
        $main = !self::isOrg($r) && $last !== '' ? $last . ' ' . $first : trim((string) ($r['name'] ?? '') ?: ($r['firma'] ?? '') ?: $first);
        return self::fold($main);
    }

    /** Text für Vergleiche: klein, ohne Akzente (ä → a, ß → ss), Satzzeichen am Anfang weg */
    public static function fold(string $s): string
    {
        $s = strtr($s, ['Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'ẞ' => 'SS']);
        static $tr = null;
        $tr ??= class_exists(\Transliterator::class) ? (\Transliterator::create('Latin-ASCII') ?: false) : false;
        if ($tr && preg_match('~[^\x20-\x7e]~', $s)) $s = (string) $tr->transliterate($s);
        return ltrim(mb_strtolower($s), " \t\"'„“»«(.-_");
    }

    /** Buchstabe des Abschnitts: A–Z, sonst # */
    public static function letter(string $key): string
    {
        $c = strtoupper(substr($key, 0, 1));
        return $c >= 'A' && $c <= 'Z' ? $c : '#';
    }

    /** Buchstaben der Sprungleiste: A–Z und # */
    public static function alphabet(): array
    {
        return [...range('A', 'Z'), '#'];
    }

    /** Anzeigename in Teilen: [vorderer Teil, fetter Teil] – Personen „Erika **Musterfrau**“, Organisationen ganz fett */
    public static function nameParts(array $r): array
    {
        $last = trim((string) ($r['nachname'] ?? ''));
        if (self::isOrg($r) || $last === '') return ['', trim((string) ($r['name'] ?? '')) ?: trim((string) ($r['firma'] ?? '')) ?: __('Ohne Namen')];
        return [trim(trim((string) ($r['praefix'] ?? '')) . ' ' . trim((string) ($r['vorname'] ?? ''))), $last];
    }

    /** Initialen für das Bild ohne Foto */
    public static function initials(array $r): string
    {
        [, $b] = self::nameParts($r);
        $first = trim((string) ($r['vorname'] ?? ''));
        $s = self::isOrg($r) || $first === '' ? mb_substr($b, 0, 2) : mb_substr($first, 0, 1) . mb_substr($b, 0, 1);
        return mb_strtoupper($s);
    }

    /** Farbe der Initialen (fest je Kontakt, Klassen wie Core\Avatar: adm-ava--c0 … c7) */
    public static function color(array $r): int
    {
        return (int) (hexdec(substr(md5((string) ($r['id'] ?? '') . '|' . self::sortKey($r)), 0, 4)) % 8);
    }

    /** Zweite Zeile in der Namensliste: Funktion · Organisation bzw. erste Listen */
    public static function subtitle(array $t, array $r): string
    {
        $bits = [];
        $pos = trim((string) ($r['position'] ?? ''));
        if ($pos !== '') $bits[] = $pos;
        if (!self::isOrg($r) && ($org = trim((string) ($r['firma'] ?? ''))) !== '') $bits[] = $org;
        if (count($bits) < 2) {
            $labels = self::lists($t);
            foreach (self::listKeys($r) as $k) {
                if (isset($labels[$k])) $bits[] = $labels[$k];
                if (count($bits) >= 2) break;
            }
        }
        return implode(' · ', $bits);
    }

    /** Listen eines Kontakts (Schlüssel) */
    public static function listKeys(array $r): array
    {
        $v = $r['listen'] ?? [];
        if (is_string($v)) $v = json_decode($v, true);
        return array_values(array_map('strval', array_filter((array) $v, fn($x) => $x !== null && $x !== '')));
    }

    /** Kontakt auf der Website sichtbar? (Freigabe + veröffentlicht) */
    public static function online(array $t, array $r): bool
    {
        return ($r['status'] ?? '') === 'published' && Exposure::visible($t, $r);
    }

    /**
     * Abschnitt der Namensliste: Beginn ($offset) aus Buchstabe bzw. gewähltem Kontakt, sonst wie angegeben.
     * @param list<array<string, mixed>> $rows
     */
    public static function offset(array $rows, int $offset = 0, string $letter = '', int $selected = 0): int
    {
        if ($letter !== '') {
            foreach ($rows as $i => $r) if (strcmp((string) $r['_letter'], $letter) >= 0 && ($letter !== '#' || $r['_letter'] === '#')) return $i;
            return max(0, count($rows) - 1);
        }
        if ($selected > 0 && $offset === 0) {
            foreach ($rows as $i => $r) if ((int) $r['id'] === $selected) return intdiv($i, self::PAGE) * self::PAGE;
        }
        return max(0, min($offset, max(0, count($rows) - 1)));
    }

    /**
     * Angaben der Karte, gruppiert wie in Kontakte-Apps: Mehrfachfelder (Telefon, E-Mail, Adresse, Web, eigene) je Angabe mit
     * Beschriftung, dann „Weitere Angaben“ (alle übrigen ausgefüllten Felder). Jede Zeile mit reach (Exposure::reach) für das
     * Merkmal Öffentlich/Intern. Namensteile, Foto, Funktion/Organisation, Listen und der Schalter stehen im Kopf der Karte.
     * @return list<array{label: string, rows: list<array{label: string, html: string, reach: array, text: string}>}>
     */
    public static function groups(array $t, array $e): array
    {
        $head = ['name', 'praefix', 'vorname', 'nachname', 'ist_firma', 'position', 'firma', 'abteilung', 'foto', 'listen', Kinds::PUBLIC_FIELD];
        $groups = [];
        $more = [];
        foreach (Tables::dataFields((array) $t['fields']) as $f) {
            $n = (string) $f['name'];
            $type = (string) $f['type'];
            if (in_array($n, $head, true) || !empty($f['legacy']) || in_array($type, ['blocks', EntryBlocks::TYPE], true)) continue;
            if (Combo::isMulti($type)) {
                $rows = [];
                foreach (Combo::items($e[$n] ?? null) as $i) {
                    $text = Combo::itemText($type, $i);
                    if ($text === '') continue;
                    $rows[] = ['label' => Combo::labelText($type, $i), 'html' => self::itemHtml($type, $i), 'text' => $text, 'reach' => Exposure::reach($t, $f, $e, $i)];
                }
                if ($rows) $groups[] = ['label' => Tables::label($f), 'rows' => $rows];
                continue;
            }
            $v = $e[$n] ?? null;
            if ($v === null || $v === '' || $v === [] || ($type === 'bool' && !$v)) continue;
            $html = Entries::html($t, $e, $n, ['link' => false]);
            if (trim(strip_tags($html)) === '' && !in_array($type, ['media', 'file'], true)) continue;
            $more[] = ['label' => Tables::label($f), 'html' => $html, 'text' => trim(strip_tags($html)), 'reach' => Exposure::reach($t, $f, $e)];
        }
        if ($more) $groups[] = ['label' => __('Weitere Angaben'), 'rows' => $more];
        return $groups;
    }

    /** Wert einer Angabe als HTML: Telefon/E-Mail/Web als Link, Adresse zweizeilig */
    private static function itemHtml(string $type, array $i): string
    {
        $v = trim((string) ($i['value'] ?? ''));
        return match ($type) {
            'phones' => ($h = tel_href($v)) ? '<a href="' . e($h) . '">' . e($v) . '</a>' : e($v),
            'emails' => filter_var($v, FILTER_VALIDATE_EMAIL) ? '<a href="mailto:' . e($v) . '">' . e($v) . '</a>' : e($v),
            'urls' => preg_match('~^https?://~i', $v) ? '<a href="' . e($v) . '" target="_blank" rel="noopener noreferrer">' . e((string) preg_replace('~^https?://(www\.)?~', '', rtrim($v, '/'))) . '</a>' : e($v),
            'addresses' => implode('<br>', array_map('e', array_values(array_filter([trim((string) ($i['street'] ?? '')),
                trim(trim((string) ($i['zip'] ?? '')) . ' ' . trim((string) ($i['city'] ?? ''))), trim((string) ($i['country'] ?? ''))])))),
            default => e($v),
        };
    }

    /**
     * Schnellknöpfe der Karte: erste Telefonnummer, erste E-Mail-Adresse, erste Adresse (Karte bei OpenStreetMap).
     * @return array{call: ?string, mail: ?string, map: ?string}
     */
    public static function quick(array $t, array $e): array
    {
        $first = function (string $type) use ($t, $e): ?array {
            foreach ((array) $t['fields'] as $f) {
                if (($f['type'] ?? '') !== $type || !empty($f['legacy'])) continue;
                foreach (Combo::items($e[$f['name']] ?? null) as $i) if (Combo::itemText($type, $i) !== '') return $i;
            }
            return null;
        };
        $tel = $first('phones');
        $mail = $first('emails');
        $adr = $first('addresses');
        $mailV = trim((string) ($mail['value'] ?? ''));
        return [
            'call' => $tel ? tel_href(trim((string) $tel['value'])) : null,
            'mail' => $mailV !== '' && filter_var($mailV, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $mailV : null,
            'map' => $adr ? 'https://www.openstreetmap.org/search?query=' . rawurlencode(Combo::itemText('addresses', $adr)) : null,
        ];
    }

    // ================================================================= Ändern und Ausgeben

    /**
     * Kontakte einer Liste zuordnen ($add) bzw. aus ihr entfernen. Jeder geänderte Kontakt wird über Entries::save gespeichert
     * (Revision „Zu Liste … hinzugefügt“, Ereignisse, Bearbeitungssperre, fremde/externe Einträge geschützt).
     * @param list<int> $ids
     * @return array{changed: int, skipped: int, errors: list<string>}
     */
    public static function assign(array $t, array $ids, string $list, bool $add): array
    {
        $out = ['changed' => 0, 'skipped' => 0, 'errors' => []];
        $labels = self::lists($t);
        if (!isset($labels[$list])) return ['changed' => 0, 'skipped' => 0, 'errors' => [__('Unbekannte Liste.')]];
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 5000);
        $note = $add ? __('Zu Liste „{list}“ hinzugefügt', ['list' => $labels[$list]]) : __('Aus Liste „{list}“ entfernt', ['list' => $labels[$list]]);
        foreach ($ids as $id) {
            $e = Entries::find($t, $id);
            if (!$e) { $out['skipped']++; continue; }
            $keys = self::listKeys($e);
            $has = in_array($list, $keys, true);
            if ($has === $add) { $out['skipped']++; continue; }
            $keys = $add ? [...$keys, $list] : array_values(array_diff($keys, [$list]));
            [, $err] = Entries::save($t, $id, ['listen' => $keys], $note);
            if ($err) {
                $out['errors'][] = Entries::title($t, $e) . ': ' . implode(' ', array_map('strval', $err));
                continue;
            }
            $out['changed']++;
        }
        return $out;
    }

    /**
     * Neue Liste: Option im Feld „listen“ ergänzen (Tabellen-Designer, Recht data.schema). Gibt den Schlüssel zurück oder einen
     * Fehlertext als ['error' => …].
     * @return array{key?: string, error?: string}
     */
    public static function addList(array $t, string $label): array
    {
        $label = trim(preg_replace('~[\r\n=]+~', ' ', $label) ?? '');
        if ($label === '' || mb_strlen($label) > 80) return ['error' => __('Bitte einen Namen für die Liste angeben (höchstens 80 Zeichen).')];
        foreach (self::lists($t) as $k => $l) if (mb_strtolower($l) === mb_strtolower($label)) return ['key' => (string) $k];
        if (isset($t['shared']) && !Shared::isOwner($t)) return ['error' => __('Die Felder dieser geteilten Tabelle ändert nur die Eigentümer-Website.')];
        $in = Tables::toInput($t);
        $found = false;
        foreach ($in['fields'] as &$f) {
            if (($f['name'] ?? '') !== 'listen') continue;
            $f['options'] = rtrim((string) ($f['options'] ?? '')) . "\n" . $label;
            $found = true;
        }
        unset($f);
        if (!$found) return ['error' => __('Diese Tabelle hat kein Feld „Listen“.')];
        [$clean, $errors] = Tables::validate($in, $t);
        if ($errors) return ['error' => implode(' ', array_map('strval', $errors))];
        Tables::update($t, $clean);
        $t2 = Tables::find($t['handle']) ?? $t;
        foreach (self::lists($t2) as $k => $l) if ($l === $label) return ['key' => (string) $k];
        return ['error' => __('Die Liste konnte nicht angelegt werden.')];
    }

    /**
     * vCards der Kontakte ($ids) – $internal: alle Angaben wie für die Adressbuch-Apps der Redaktion; sonst nur Kontakte auf der
     * Website mit ihren öffentlichen Angaben (Exposure-Ziel vcard, wie die öffentliche Visitenkarte).
     * @param list<int> $ids
     */
    public static function vcards(array $t, array $ids, bool $internal): string
    {
        $out = '';
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 200) as $chunk) {
            foreach (Entries::query($t, ['ids' => $chunk, 'status' => 'all', 'internal' => true, 'lang' => 'all', 'source' => 'own']) as $e) {
                if (!$internal && !self::online($t, $e)) continue;
                $out .= \Core\VCard::person($t, $e, $internal);
            }
        }
        return $out;
    }

    /** Selbsttest (Teil von data:selftest): Sortierung, Buchstaben, Namensteile, Abschnitte – ohne Datenbank */
    public static function selftest(): array
    {
        $ok = 0;
        $fails = [];
        $eq = \Core\Testing\Check::eq($ok, $fails, 'Adressbuch-Ansicht – ');
        $rows = [
            ['id' => 1, 'vorname' => 'Erika', 'nachname' => 'Musterfrau', 'name' => 'Erika Musterfrau'],
            ['id' => 2, 'vorname' => 'Änne', 'nachname' => 'Ährlich', 'name' => 'Änne Ährlich'],
            ['id' => 3, 'name' => 'Musterdruck GmbH', 'firma' => 'Musterdruck GmbH', 'ist_firma' => 1],
            ['id' => 4, 'vorname' => 'Max', 'nachname' => 'Beispiel', 'name' => 'Max Beispiel'],
            ['id' => 5, 'name' => '123 Hilfe'],
            ['id' => 6, 'vorname' => 'Anna', 'nachname' => 'Musterfrau', 'name' => 'Anna Musterfrau'],
        ];
        $rows = array_map(fn(array $r): array => $r + ['_key' => self::sortKey($r), '_letter' => self::letter(self::sortKey($r))], $rows);
        usort($rows, fn($a, $b) => [$a['_key'], $a['id']] <=> [$b['_key'], $b['id']]);
        $eq('Sortierung nach Nachname, Umlaute wie Grundbuchstabe, Organisationen nach Namen', array_column($rows, 'id'), [5, 2, 4, 3, 6, 1]);
        $eq('Buchstaben', array_column($rows, '_letter'), ['#', 'A', 'B', 'M', 'M', 'M']);
        $eq('Namensteile Person/Organisation', [self::nameParts($rows[5]), self::nameParts($rows[3])], [['Erika', 'Musterfrau'], ['', 'Musterdruck GmbH']]);
        $eq('Initialen', [self::initials($rows[5]), self::initials($rows[3]), self::initials($rows[1])], ['EM', 'MU', 'ÄÄ']);
        $eq('Organisation erkannt', [self::isOrg($rows[3]), self::isOrg($rows[5])], [true, false]);
        $eq('Abschnitt: Buchstabe, gewählter Kontakt, Ende', [self::offset($rows, 0, 'M'), self::offset($rows, 0, 'C'), self::offset($rows, 0, '#'), self::offset($rows, 0, '', 1), self::offset($rows, 99)], [3, 3, 0, 0, 5]);
        $eq('Listen eines Kontakts', [self::listKeys(['listen' => '["vorstand","presse"]']), self::listKeys(['listen' => null])], [['vorstand', 'presse'], []]);
        $eq('Ansicht je Konto (Standard Adressbuch)', [self::view(null), self::view(['ui_prefs' => '{"contacts_view":"list"}']), self::view(['ui_prefs' => '{"contacts_view":"quatsch"}'])], ['book', 'list', 'book']);

        // Mit Datenbank: vorübergehende Kontakte-Tabelle – Zähler, Filter, Suche, Listen zuordnen (mit Revision), vCards
        $h = 'selftest_ab_' . bin2hex(random_bytes(3));
        try {
            $def = \Core\Http\Controllers\Admin\DataController::presetDef('contacts');
            $def['handle'] = $h;
            $def['name'] = 'Selbsttest Adressbuch-Ansicht';
            $def['settings']['route'] = '';
            foreach ($def['fields'] as &$f) if (($f['name'] ?? '') === 'listen') $f['options'] = "Vorstand\nPresse";
            unset($f);
            [$clean, $err] = Tables::validate($def);
            $eq('Tabelle gültig', $err, []);
            Tables::create($clean);
            $t = Tables::find($h) ?? throw new \RuntimeException('Tabelle fehlt');
            $eq('Listen aus dem Feld', self::lists($t), ['vorstand' => 'Vorstand', 'presse' => 'Presse']);
            [$a] = Entries::save($t, null, ['vorname' => 'Erika', 'nachname' => 'Musterfrau', 'position' => 'Vorsitz', 'oeffentlich' => true, 'status' => 'published', 'listen' => ['vorstand'],
                'telefon' => [['label' => 'office', 'value' => '+49 2841 555-10', 'public' => '1'], ['label' => 'mobile', 'value' => '0170 5550188', 'public' => '0']],
                'email' => [['label' => 'work', 'value' => 'erika@example.org', 'public' => '1']]]);
            [$b] = Entries::save($t, null, ['vorname' => 'Max', 'nachname' => 'Beispiel', 'status' => 'published', 'listen' => []]);
            [$c] = Entries::save($t, null, ['firma' => 'Musterdruck GmbH', 'ist_firma' => '1', 'status' => 'published']);
            $cnt = self::counts($t);
            $eq('Zähler: alle, Listen, nicht auf der Website, ohne Foto, ohne E-Mail', [$cnt['all'], $cnt['lists'], $cnt['offline'], $cnt['nofoto'], $cnt['nomail']], [3, ['vorstand' => 1, 'presse' => 0], 2, 3, 2]);
            $ids = fn(array $rows) => array_map(fn($r) => (int) $r['id'], $rows);
            $eq('Reihenfolge A–Z (Organisation nach Namen)', $ids(self::index($t)), [(int) $b, (int) $c, (int) $a]);
            $eq('Filter Liste / nicht auf der Website / ohne E-Mail', [$ids(self::index($t, 'vorstand')), $ids(self::index($t, '', 'offline')), $ids(self::index($t, '', 'nomail'))],
                [[(int) $a], [(int) $b, (int) $c], [(int) $b, (int) $c]]);
            $eq('Suche: Name, Funktion, Telefon ohne Leerzeichen, E-Mail, keine JSON-Schlüssel', [$ids(self::index($t, '', '', 'muster')), $ids(self::index($t, '', '', 'vorsitz')),
                $ids(self::index($t, '', '', '5550188')), $ids(self::index($t, '', '', 'erika@')), $ids(self::index($t, '', '', 'label')), $ids(self::index($t, '', '', 'erika muster'))],
                [[(int) $c, (int) $a], [(int) $a], [(int) $a], [(int) $a], [], [(int) $a]]);
            $before = count(Revisions::list($t, (int) $b));
            $res = self::assign($t, [(int) $a, (int) $b], 'vorstand', true);
            $eq('Zuordnen: nur wer fehlt, mit Revision', [$res['changed'], $res['skipped'], self::listKeys(Entries::find($t, (int) $b) ?? []), count(Revisions::list($t, (int) $b)) > $before],
                [1, 1, ['vorstand'], true]);
            $res = self::assign($t, [(int) $a], 'vorstand', false);
            $eq('Entfernen und unbekannte Liste', [$res['changed'], self::listKeys(Entries::find($t, (int) $a) ?? []), self::assign($t, [(int) $a], 'quatsch', true)['errors'] !== []], [1, [], true]);
            $all = self::vcards($t, [(int) $a, (int) $b], true);
            $pub = self::vcards($t, [(int) $a, (int) $b], false);
            $eq('vCard: alle Angaben bzw. nur öffentliche (nur Kontakte auf der Website)', [substr_count($all, 'BEGIN:VCARD'), str_contains($all, '5550188'), substr_count($pub, 'BEGIN:VCARD'), str_contains($pub, '5550188'), str_contains($pub, '+492841555')],
                [2, true, 1, false, true]);
            $nl = self::addList($t, 'Jugend');
            $t2 = Tables::find($h);
            $eq('Neue Liste', [isset($nl['key']) ? $nl['key'] : ($nl['error'] ?? ''), $t2 ? array_keys(self::lists($t2)) : []], ['jugend', ['vorstand', 'presse', 'jugend']]);
        } catch (\Throwable $x) {
            $fails[] = 'Adressbuch-Ansicht – Ausnahme: ' . $x->getMessage() . ' (' . basename($x->getFile()) . ':' . $x->getLine() . ')';
        } finally {
            if ($x = Tables::find($h)) Tables::delete($x);
            Tables::flush();
        }
        return ['ok' => $ok, 'fails' => $fails];
    }
}
