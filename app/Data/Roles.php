<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * Funktionen je Person (Feldtyp „roles“, Grundschema contacts ab Version 2, Feld „funktionen“): eine Person hat mehrere Funktionen –
 * Vorsitz im Vorstand, Ansprechperson im Arbeitskreis, Kassenprüfung … Jede Funktion ist eine Zeile einer wiederholbaren Gruppe
 * (JSON wie Core\Combo): {funktion (Text mit Vorschlägen), liste (Schlüssel aus „listen“), ref (entry:{tabelle}:{id}, beliebige Tabelle),
 * seit, bis (JJJJ-MM-TT), reihenfolge (Zahl)}.
 *
 *  - Hauptfunktion = erste aktuelle Funktion (seit ≤ heute, bis leer oder ≥ heute) in der Reihenfolge der Zeilen. Das Feld „position“
 *    trägt sie (beim Speichern gebildet, auf der Website und in Visitenkarten/CardDAV stets frisch aus den Funktionen) – so zeigen
 *    Kits, JSON-LD (jobTitle) und vCard (TITLE) ohne Änderung die Hauptfunktion. Ändert eine App nur TITLE, landet der Text in der
 *    Hauptfunktion.
 *  - Listen: „listen“ = eigene Zuordnung ∪ Listen der aktuellen Funktionen (beim Speichern). Endet eine Funktion, fällt ihre Liste weg
 *    (sofern sie nicht von Hand gesetzt war). Die Anzeige prüft das Ende stets selbst (membership), auch vor dem nächsten Speichern.
 *  - Anzeige je Zusammenhang (pick/arrange): Filter nach Liste → die Funktion in dieser Liste; Detailseite eines verknüpften Eintrags →
 *    die Funktion mit dieser Verknüpfung; sonst die Hauptfunktion. „bis“ vergangen → nicht mehr in der Liste (wahlweise unter
 *    „Ehemalige“). Reihenfolge innerhalb einer Liste: Feld „reihenfolge“ der passenden Funktion (leer = danach).
 */
final class Roles
{
    public const TYPE = 'roles';
    public const FIELD = 'funktionen';
    /** Vorschläge für das Feld „Funktion“ (eigene Texte sind erlaubt; die Tabelle ergänzt ihre schon benutzten) */
    public const SUGGEST = ['Vorsitz', 'Stellv. Vorsitz', 'Kassenwart', 'Schatzmeisterin', 'Schatzmeister', 'Schriftführung', 'Beisitz',
        'Geschäftsführung', 'Ansprechperson', 'Kassenprüfung', 'Sprecherin', 'Sprecher', 'Leitung', 'Mitglied'];
    /** Muster einer Verknüpfung (Eintrag einer beliebigen Tabelle, Core\Links) */
    public const REF = '~^entry:[a-z][a-z0-9_]{0,40}:\d+$~';

    /** Fester Stichtag für Selbsttests (JJJJ-MM-TT); null = heute */
    public static ?string $today = null;

    public static function today(): string
    {
        return self::$today ?? date('Y-m-d');
    }

    /** Feld der Funktionen einer Tabelle (Typ roles, „funktionen“ zuerst) oder null */
    public static function field(array $t): ?array
    {
        $f = Tables::field($t, self::FIELD);
        if ($f && ($f['type'] ?? '') === self::TYPE && empty($f['legacy'])) return $f;
        foreach ((array) ($t['fields'] ?? []) as $x) if (($x['type'] ?? '') === self::TYPE && empty($x['legacy'])) return $x;
        return null;
    }

    /** Hat die Tabelle Funktionen je Person? */
    public static function has(array $t): bool
    {
        return self::field($t) !== null;
    }

    /** Funktionen eines Eintrags (Rohwert, JSON oder Liste) als bereinigte Liste */
    public static function items(mixed $raw): array
    {
        $out = [];
        foreach (\Core\Combo::items($raw) as $i) {
            $r = ['funktion' => trim((string) ($i['funktion'] ?? '')), 'liste' => trim((string) ($i['liste'] ?? '')), 'ref' => trim((string) ($i['ref'] ?? '')),
                'seit' => self::date($i['seit'] ?? ''), 'bis' => self::date($i['bis'] ?? ''), 'reihenfolge' => is_numeric($i['reihenfolge'] ?? null) ? (int) $i['reihenfolge'] : null];
            if ($r['funktion'] === '' && $r['liste'] === '' && $r['ref'] === '') continue;
            $out[] = $r;
        }
        return $out;
    }

    private static function date(mixed $v): string
    {
        $s = trim((string) (is_scalar($v) ? $v : ''));
        return preg_match('~^\d{4}-\d{2}-\d{2}~', $s) ? substr($s, 0, 10) : '';
    }

    /** Läuft die Funktion heute? (seit leer oder ≤ heute, bis leer oder ≥ heute) */
    public static function isCurrent(array $i, ?string $today = null): bool
    {
        $today ??= self::today();
        return ($i['seit'] === '' || $i['seit'] <= $today) && ($i['bis'] === '' || $i['bis'] >= $today);
    }

    /** Ist die Funktion vorbei? („bis“ vergangen) */
    public static function isFormer(array $i, ?string $today = null): bool
    {
        return $i['bis'] !== '' && $i['bis'] < ($today ?? self::today());
    }

    /** Aktuelle Funktionen in der Reihenfolge der Zeilen */
    public static function current(array $items, ?string $today = null): array
    {
        return array_values(array_filter($items, fn($i) => self::isCurrent($i, $today)));
    }

    /** Listen der aktuellen Funktionen (Schlüssel) */
    public static function listsOf(array $items, ?string $today = null): array
    {
        return array_values(array_unique(array_filter(array_column(self::current($items, $today), 'liste'), fn($k) => $k !== '')));
    }

    /** Hauptfunktion eines Eintrags (erste aktuelle) oder null */
    public static function main(array $t, array $e): ?array
    {
        $f = self::field($t);
        return $f ? (self::current(self::items($e[$f['name']] ?? null))[0] ?? null) : null;
    }

    /**
     * Text der Hauptfunktion – für position, vCard TITLE, JSON-LD jobTitle. null = keine Funktionen eingetragen (dann gilt das Feld
     * „position“ wie bisher), '' = Funktionen eingetragen, aber keine aktuelle.
     */
    public static function mainText(array $t, array $e): ?string
    {
        $f = self::field($t);
        if (!$f) return null;
        $items = self::items($e[$f['name']] ?? null);
        if (!$items) return null;
        $m = self::current($items)[0] ?? null;
        return $m ? self::text($t, $m) : '';
    }

    /** Text einer Funktion: „Vorsitz“ bzw. ohne eigenen Text der Name der Liste (z. B. „Vorstand“) */
    public static function text(array $t, array $i, bool $site = true): string
    {
        if ($i['funktion'] !== '') return $i['funktion'];
        if ($i['liste'] !== '' && ($lf = Tables::field($t, 'listen'))) return Tables::optionLabel($lf, $i['liste']);
        return $i['ref'] !== '' ? self::refTitle($i['ref']) : ($site ? lt('Mitglied') : __('Mitglied'));
    }

    /** Titel des verknüpften Eintrags (leer, wenn es ihn nicht gibt) */
    public static function refTitle(string $ref): string
    {
        if (!preg_match(self::REF, $ref)) return '';
        [, $h, $id] = explode(':', $ref);
        $rt = Tables::findContent($h);
        $re = $rt ? Entries::find($rt, (int) $id) : null;
        return $rt && $re && ($re['status'] ?? '') === 'published' ? Entries::title($rt, $re) : '';
    }

    /**
     * Zugehörigkeit zu einer Liste: 'current' (aktuelle Funktion in der Liste), 'former' (nur beendete Funktionen in der Liste),
     * 'manual' (von Hand in „listen“, keine Funktion in der Liste), 'future' (Funktion beginnt erst) oder '' (nicht in der Liste).
     */
    public static function membership(array $t, array $e, string $list, ?string $today = null): string
    {
        $f = self::field($t);
        $in = $f ? array_values(array_filter(self::items($e[$f['name']] ?? null), fn($i) => $i['liste'] === $list)) : [];
        return self::state($in, in_array($list, array_map('strval', (array) ($e['listen'] ?? [])), true), $today);
    }

    /** Zugehörigkeit zu einem verknüpften Eintrag (ref = entry:{tabelle}:{id}) – wie membership(), ohne „von Hand“ */
    public static function refMembership(array $t, array $e, string $ref, ?string $today = null): string
    {
        $f = self::field($t);
        $in = $f ? array_values(array_filter(self::items($e[$f['name']] ?? null), fn($i) => $i['ref'] === $ref)) : [];
        return self::state($in, false, $today);
    }

    private static function state(array $in, bool $manual, ?string $today): string
    {
        if (!$in) return $manual ? 'manual' : '';
        foreach ($in as $i) if (self::isCurrent($i, $today)) return 'current';
        foreach ($in as $i) if (self::isFormer($i, $today)) return 'former';
        return 'future';
    }

    /**
     * Passende Funktion im Zusammenhang $ctx: ['lists' => [schlüssel…]] (Filter nach Liste; erste Liste mit Treffer), ['ref' => entry:…]
     * (Detailseite eines verknüpften Eintrags), ['text' => …] (Filter nach Funktion, „enthält“). Ohne Treffer: Hauptfunktion.
     * $former: auch beendete Funktionen (Abschnitt „Ehemalige“). Rückgabe null = keine Funktionen.
     */
    public static function pick(array $t, array $e, array $ctx = [], bool $former = false, ?string $today = null): ?array
    {
        $f = self::field($t);
        $items = $f ? self::items($e[$f['name']] ?? null) : [];
        if (!$items) return null;
        $ok = fn(array $i) => self::isCurrent($i, $today) || ($former && self::isFormer($i, $today));
        $best = function (array $cands) use ($ok): ?array {
            $c = array_values(array_filter($cands, $ok));
            if (!$c) return null;
            // aktuelle vor beendeten, dann Reihenfolge der Zeilen
            usort($c, fn($a, $b) => (int) self::isFormer($a) <=> (int) self::isFormer($b));
            return $c[0];
        };
        foreach ((array) ($ctx['lists'] ?? []) as $l) if (($m = $best(array_filter($items, fn($i) => $i['liste'] === (string) $l)))) return $m;
        if (!empty($ctx['ref']) && ($m = $best(array_filter($items, fn($i) => $i['ref'] === (string) $ctx['ref'])))) return $m;
        if (!empty($ctx['text']) && ($m = $best(array_filter($items, fn($i) => $i['funktion'] !== '' && mb_stripos($i['funktion'], (string) $ctx['text']) !== false)))) return $m;
        return self::current($items, $today)[0] ?? null;
    }

    /** Schlüssel der Liste zu einem Wert (Schlüssel oder Beschriftung), sonst null */
    public static function listKey(array $t, string $v): ?string
    {
        $lf = Tables::field($t, 'listen');
        if (!$lf) return null;
        $v = trim($v);
        foreach (array_keys((array) ($lf['options'] ?? [])) as $k) {
            if ((string) $k === $v || mb_strtolower((string) $k) === mb_strtolower($v) || mb_strtolower(Tables::optionLabel($lf, (string) $k)) === mb_strtolower($v)) return (string) $k;
        }
        return null;
    }

    /**
     * Zusammenhang eines Blocks: Listen aus dem Filter (Feld „listen“ bzw. „funktionen“, Werte = Listen), Funktionstext (Filter auf
     * „funktionen“ mit anderem Wert) und – auf der Detailseite eines Eintrags einer anderen Tabelle – dessen Verknüpfung.
     * @param list<string> $values Filterwerte
     */
    public static function context(array $t, string $filterField, array $values): array
    {
        $ctx = [];
        $rf = self::field($t);
        if ($rf && in_array($filterField, ['listen', $rf['name']], true)) {
            foreach ($values as $v) {
                $k = self::listKey($t, (string) $v);
                if ($k !== null) $ctx['lists'][] = $k;
                elseif ($filterField === $rf['name'] && !preg_match(self::REF, (string) $v) && !isset($ctx['text'])) $ctx['text'] = (string) $v;
            }
        }
        $cur = app()->entry;
        if ($cur && ($cur['table']['handle'] ?? '') !== $t['handle'] && !empty($cur['entry']['id'])) $ctx['ref'] = 'entry:' . $cur['table']['handle'] . ':' . (int) $cur['entry']['id'];
        return $ctx;
    }

    /**
     * Einträge einer Liste/Ausgabe ordnen: je Eintrag die passende Funktion (pick) in position (und _funktion), beendete
     * Zugehörigkeiten (Filter nach Liste bzw. verknüpftem Eintrag) fallen heraus bzw. kommen nach $former. Mit $order wird nach dem
     * Feld „reihenfolge“ der passenden Funktion sortiert (leer = danach, sonst wie bisher). Tabellen ohne Funktionen: unverändert.
     * @return array{0: list<array>, 1: list<array>} [aktuelle, ehemalige]
     */
    public static function arrange(array $t, array $rows, array $ctx, bool $order = true, bool $withFormer = false): array
    {
        if (!self::has($t)) return [array_values($rows), []];
        $cur = [];
        $former = [];
        $scope = !empty($ctx['lists']) ? 'lists' : (!empty($ctx['ref']) && !empty($ctx['ref_filter']) ? 'ref' : '');
        foreach (array_values($rows) as $n => $e) {
            $state = 'current';
            if ($scope === 'lists') {
                $states = array_map(fn($l) => self::membership($t, $e, (string) $l), (array) $ctx['lists']);
                $state = in_array('current', $states, true) || in_array('manual', $states, true) ? 'current' : (in_array('former', $states, true) ? 'former' : (in_array('future', $states, true) ? 'future' : 'current'));
            } elseif ($scope === 'ref') {
                $s = self::refMembership($t, $e, (string) $ctx['ref']);
                $state = $s === '' ? 'current' : $s;
            }
            if ($state === 'future' || ($state === 'former' && !$withFormer)) continue;
            $m = self::pick($t, $e, $ctx, $state === 'former');
            if ($m !== null) {
                $e['position'] = self::text($t, $m);
                $e['_funktion'] = $m;
            } elseif (($mt = self::mainText($t, $e)) !== null) {
                $e['position'] = $mt;
            }
            $e['_n'] = $n;
            if ($state === 'former') $former[] = $e; else $cur[] = $e;
        }
        if ($order && (!empty($ctx['lists']) || !empty($ctx['ref']) || !empty($ctx['text']))) {
            $by = fn($a, $b) => [($a['_funktion']['reihenfolge'] ?? null) === null ? 1 : 0, $a['_funktion']['reihenfolge'] ?? 0, $a['_n']]
                <=> [($b['_funktion']['reihenfolge'] ?? null) === null ? 1 : 0, $b['_funktion']['reihenfolge'] ?? 0, $b['_n']];
            usort($cur, $by);
            usort($former, $by);
        }
        $strip = fn(array $e) => array_diff_key($e, ['_n' => 1]);
        return [array_map($strip, $cur), array_map($strip, $former)];
    }

    /**
     * Datenliste (Kern-Block data_list und Kits mit eigener Datenliste): Zusammenhang aus dem Filter des Blocks (Feld, Bedingung,
     * Wert; „verknüpft mit dem aufgerufenen Eintrag“ auf „Funktionen“), dann arrange(). $order: Block sortiert nicht selbst.
     */
    public static function listRows(array $t, array $d, array $rows, bool $order = true): array
    {
        if (!self::has($t)) return $rows;
        $pfx = $t['handle'] . '.';
        $ff = str_starts_with((string) ($d['filter_field'] ?? ''), $pfx) ? substr((string) $d['filter_field'], strlen($pfx)) : '';
        $op = (string) ($d['filter_op'] ?? '=');
        $ctx = self::context($t, $ff, in_array($op, ['current', 'same', '!='], true) ? [] : array_filter([trim((string) ($d['filter_value'] ?? ''))]));
        if ($op === 'current' && $ff === (string) self::field($t)['name'] && !empty($ctx['ref'])) $ctx['ref_filter'] = true;
        return self::arrange($t, $rows, $ctx, $order)[0];
    }

    /** Hauptfunktion frisch in position (öffentliche Ausgabe, Apps) – Tabellen ohne Funktionen bzw. Einträge ohne Funktionen unverändert */
    public static function refresh(array $t, array $e): array
    {
        if (!self::has($t) || !array_key_exists('position', $e)) return $e;
        $m = self::mainText($t, $e);
        if ($m !== null) $e['position'] = $m;
        return $e;
    }

    /**
     * Beim Speichern (Entries::save, nach der Bereinigung): Verknüpfungen prüfen, Listen ableiten, Hauptfunktion in position.
     * Ändert jemand nur position (z. B. TITLE aus einer Adressbuch-App), landet der Text in der Hauptfunktion.
     * @return array{0: array, 1: array} [Werte, Fehler]
     */
    public static function apply(array $t, ?array $current, array $values): array
    {
        $f = self::field($t);
        if (!$f) return [$values, []];
        $n = (string) $f['name'];
        $errors = [];
        $raw = \Core\Combo::items($values[$n] ?? null);
        foreach ($raw as $k => $i) {
            $ref = trim((string) ($i['ref'] ?? ''));
            if ($ref !== '' && !preg_match(self::REF, $ref)) $errors["$n.$k.ref"] = __('Funktion {n}: Als Verknüpfung bitte einen Eintrag einer Datentabelle wählen.', ['n' => $k + 1]);
            if (($s = self::date($i['seit'] ?? '')) !== '' && ($b = self::date($i['bis'] ?? '')) !== '' && $b < $s) $errors["$n.$k.bis"] = __('Funktion {n}: „bis“ liegt vor „seit“.', ['n' => $k + 1]);
        }
        if ($errors) return [$values, $errors];
        $items = self::items($raw);
        $old = $current ? self::items($current[$n] ?? null) : [];
        // Liste gibt es nicht (mehr) → Zuordnung leeren
        $lf = Tables::field($t, 'listen');
        $keys = $lf ? array_map('strval', array_keys((array) ($lf['options'] ?? []))) : [];
        foreach ($items as &$i) if ($i['liste'] !== '' && !in_array($i['liste'], $keys, true)) $i['liste'] = '';
        unset($i);
        $changed = $current === null || json_encode($items) !== json_encode($old);
        if (array_key_exists('position', $values)) {
            $pos = trim((string) $values['position']);
            $oldPos = trim((string) ($current['position'] ?? ''));
            if ($items && !$changed && $pos !== '' && $pos !== $oldPos) {
                // nur position geändert (TITLE aus einer App, API): Text der Hauptfunktion
                foreach ($items as $k => $i) if (self::isCurrent($i)) { $items[$k]['funktion'] = $pos; break; }
            } elseif (!$items && !$old && $pos !== '' && ($current === null || $pos !== $oldPos)) {
                $items = [['funktion' => $pos, 'liste' => '', 'ref' => '', 'seit' => '', 'bis' => '', 'reihenfolge' => null]];   // Funktion aus App/API
            } elseif (!$items && $old) {
                $values['position'] = '';                                                      // alle Funktionen entfernt
            }
        }
        $values[$n] = array_map(fn($i) => array_filter($i, fn($v) => $v !== '' && $v !== null), $items);
        if (array_key_exists('position', $values) && $items) {
            $m = self::current($items)[0] ?? null;
            $values['position'] = $m ? self::text($t, $m, false) : '';
        }
        if ($lf) {
            $listen = array_values(array_map('strval', (array) ($values['listen'] ?? ($current['listen'] ?? []))));
            $now = self::listsOf($items);
            $gone = array_diff(self::listsOf($old), $now);
            $values['listen'] = array_values(array_unique([...array_values(array_diff($listen, $gone)), ...$now]));
        }
        return [$values, []];
    }

    /**
     * Aus einer Liste entfernen (Adressbuch „Aus Liste entfernen“): aktuelle Funktionen in dieser Liste enden gestern (bleiben als
     * „Ehemalige“ erhalten); Funktionen, die erst heute begonnen haben, fallen weg. Gibt die neuen Funktionen zurück.
     */
    public static function endIn(array $items, string $list, ?string $today = null): array
    {
        $today ??= self::today();
        $y = date('Y-m-d', (int) strtotime($today . ' -1 day'));
        $out = [];
        foreach ($items as $i) {
            if ($i['liste'] === $list && (self::isCurrent($i, $today) || ($i['seit'] !== '' && $i['seit'] > $today))) {
                if ($i['seit'] !== '' && $i['seit'] > $y) continue;
                $i['bis'] = $y;
            }
            $out[] = $i;
        }
        return $out;
    }

    // ------------------------------------------------------------------ Eingabe und Ausgabe

    /** Eingabe als Wiederholer (Core\Fields): Unterfelder mit den Listen der Tabelle und Vorschlägen für „Funktion“ */
    public static function schema(array $f, ?array $t = null): array
    {
        $lists = [];
        $suggest = self::SUGGEST;
        if ($t) {
            if ($lf = Tables::field($t, 'listen')) foreach (array_keys((array) ($lf['options'] ?? [])) as $k) $lists[(string) $k] = Tables::optionLabel($lf, (string) $k);
            $suggest = array_values(array_unique([...self::used($t, (string) $f['name']), ...$suggest]));
        }
        $fields = [
            ['name' => 'funktion', 'label' => __('Funktion / Amt'), 'type' => 'text', 'max' => 120, 'width' => 'half', 'suggest' => $suggest, 'placeholder' => __('z. B. Vorsitz')],
        ];
        if ($lists) $fields[] = ['name' => 'liste', 'label' => __('Gremium / Liste'), 'type' => 'select', 'options' => $lists, 'width' => 'half'];
        array_push($fields,
            ['name' => 'ref', 'label' => __('Verknüpft mit (optional)'), 'type' => 'link', 'help' => __('Ein Eintrag einer beliebigen Tabelle, z. B. Arbeitskreis oder Regionalgruppe – auf dessen Detailseite erscheint diese Funktion.')],
            ['name' => 'seit', 'label' => __('Seit'), 'type' => 'date', 'width' => 'half'],
            ['name' => 'bis', 'label' => __('Bis'), 'type' => 'date', 'width' => 'half', 'help' => __('Nach diesem Tag erscheint die Funktion nicht mehr in Listen der Website.')],
            ['name' => 'reihenfolge', 'label' => __('Reihenfolge in der Liste'), 'type' => 'number', 'step' => '1', 'width' => 'half', 'help' => __('1 = zuerst; leer = danach.')],
        );
        return ['type' => 'repeater', 'fields' => $fields, 'item_label' => __('Funktion / Amt'), 'add_label' => __('Funktion hinzufügen'), 'max_items' => 20, 'title_field' => 'funktion',
            'help' => __('Die erste aktuelle Funktion ist die Hauptfunktion (Visitenkarte, Adressbuch-Apps). Listen ergeben sich aus den Funktionen.')];
    }

    /** Schon benutzte Funktionstexte der Tabelle (für Vorschläge, höchstens 40) */
    private static function used(array $t, string $col): array
    {
        $out = [];
        try {
            foreach (Tables::db($t)->fetchAll("SELECT $col AS v FROM {$t['table']} WHERE $col IS NOT NULL AND $col != '' LIMIT 500") as $r) {
                foreach (self::items($r['v']) as $i) if ($i['funktion'] !== '') $out[$i['funktion']] = true;
                if (count($out) >= 40) break;
            }
        } catch (\Throwable) {
        }
        return array_keys($out);
    }

    /**
     * Darstellung auf der Website und in der Verwaltung: aktuelle Funktionen (Funktion · Gremium · verknüpfter Eintrag, seit), mit
     * $o['former'] auch beendete (seit–bis). $o['link'] = false: verknüpfte Einträge ohne Link.
     */
    public static function html(array $t, mixed $raw, array $o = []): string
    {
        $items = self::items($raw);
        $lf = Tables::field($t, 'listen');
        $h = '';
        foreach ($items as $i) {
            $was = self::isFormer($i);
            if (($was && empty($o['former'])) || (!$was && !self::isCurrent($i))) continue;
            $bits = [];
            if ($i['liste'] !== '' && $lf && $i['funktion'] !== '') $bits[] = '<span class="ctr__list">' . e(Tables::optionLabel($lf, $i['liste'])) . '</span>';
            if ($i['ref'] !== '' && ($title = self::refTitle($i['ref'])) !== '') {
                $href = ($o['link'] ?? true) ? \Core\Links::href($i['ref']) : null;
                $bits[] = $href ? '<a class="ctr__ref" href="' . e($href) . '">' . e($title) . '</a>' : '<span class="ctr__ref">' . e($title) . '</span>';
            }
            $when = $was ? ($i['seit'] !== '' ? self::year($i['seit']) . '–' : '') . self::year($i['bis']) : ($i['seit'] !== '' ? lt('seit {date}', ['date' => self::year($i['seit'])]) : '');
            $h .= '<li class="ctr__item' . ($was ? ' ctr__item--former' : '') . '"><span class="ctr__role">' . e(self::text($t, $i)) . '</span>'
                . ($bits ? ' <span class="ctr__meta">' . implode(' · ', $bits) . '</span>' : '')
                . ($when !== '' ? ' <span class="ctr__when">' . e($when) . '</span>' : '') . '</li>';
        }
        return $h !== '' ? '<ul class="ctr" role="list">' . $h . '</ul>' : '';
    }

    /** Monat/Jahr einer Angabe für die Anzeige (2024-03-01 → 03/2024) */
    private static function year(string $d): string
    {
        return $d === '' ? '' : substr($d, 5, 2) . '/' . substr($d, 0, 4);
    }

    /** Kurzfassung (Listen der Verwaltung, Suche): aktuelle Funktionstexte */
    public static function summary(mixed $raw): string
    {
        return implode(' · ', array_filter(array_column(self::current(self::items($raw)), 'funktion')));
    }

    /**
     * Gruppen für die Karte im Adressbuch: aktuelle Funktionen je Gremium/Liste (ohne Liste: „Weitere Funktionen“), danach „Ehemalige
     * Funktionen“. Je Zeile Text, Verknüpfung, Zeitraum.
     * @return list<array{label: string, rows: list<array{role: string, ref: string, href: ?string, when: string, former: bool}>}>
     */
    public static function groups(array $t, array $e): array
    {
        $f = self::field($t);
        if (!$f) return [];
        $lf = Tables::field($t, 'listen');
        $by = [];
        $former = [];
        foreach (self::items($e[$f['name']] ?? null) as $i) {
            $was = self::isFormer($i);
            $row = ['role' => self::text($t, $i, false), 'ref' => $i['ref'] !== '' ? \Core\Links::describe($i['ref'])['label'] : '', 'href' => $i['ref'] !== '' ? url('/admin/data/' . explode(':', $i['ref'])[1] . '/' . explode(':', $i['ref'])[2]) : null,
                'when' => $was ? trim(($i['seit'] !== '' ? fmt()->date($i['seit']) : '') . ' – ' . fmt()->date($i['bis'])) : ($i['seit'] !== '' ? __('seit {date}', ['date' => fmt()->date($i['seit'])]) : ($i['bis'] !== '' ? __('bis {date}', ['date' => fmt()->date($i['bis'])]) : '')),
                'former' => $was, 'future' => !$was && !self::isCurrent($i)];
            if ($was) { $former[] = $row + ['list' => $i['liste'] !== '' && $lf ? Tables::optionLabel($lf, $i['liste']) : '']; continue; }
            $key = $i['liste'] !== '' && $lf ? Tables::optionLabel($lf, $i['liste']) : '';
            $by[$key][] = $row;
        }
        $out = [];
        foreach ($by as $label => $rows) if ($label !== '') $out[] = ['label' => (string) $label, 'rows' => $rows];
        if (isset($by[''])) $out[] = ['label' => $out ? __('Weitere Funktionen') : __('Funktionen und Ämter'), 'rows' => $by['']];
        if ($former) $out[] = ['label' => __('Ehemalige Funktionen'), 'rows' => array_map(fn($r) => $r['list'] !== '' ? ['role' => $r['role'] . ' · ' . $r['list']] + $r : $r, $former)];
        return $out;
    }
}
