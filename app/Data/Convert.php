<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Combo;
use Core\PageCache;

/**
 * Bestehende Tabelle an Ort und Stelle in eine Tabellenart mit Grundschema umwandeln (php bin/console data:convert, Core\Data\Kinds) –
 * in ein Adressbuch (contacts), einen Kalender mit Serien (calendar) oder eine Ortsliste (places). Gleiche Tabelle, gleiche IDs:
 * Verknüpfungen, Revisionen, Detailseiten bleiben.
 *
 * Nach der Umwandlung stehen die Felder wie in der Vorlage der Art (Kinds::presetFields): Grundfelder in ihren Abschnitten, danach die
 * eigenen Felder mit ihren bisherigen Abschnitten; Abschnitte, die nur noch Altfelder enthielten, entfallen; Altfelder stehen am Ende.
 * Felder, die zu einem Grundfeld werden, bekommen dessen Beschriftung und Pflicht-Angabe. --revert stellt Reihenfolge, Abschnitte,
 * Beschriftungen und Pflicht wieder her (alte Feldliste im Zustand).
 *
 *  - plan(): Probelauf (Standard) – Feldzuordnung aufs Grundschema (automatischer Vorschlag, überschreibbar mit --map), Bericht je
 *    Feld, Namensaufteilung, Freigabe-Vorschlag (mobil/privat und „Nie öffentlich“ → intern), betroffene Blöcke/Seiten, Einstellungen.
 *  - apply(): Sicherung der Datenbank (VACUUM INTO), Revision je Eintrag, Schema ändern (gleichartige Felder werden umbenannt, Felder
 *    mit anderem Typ – z. B. Telefon → beschriftete Telefonnummern – bleiben als ausgeblendete Altfelder „legacy“ stehen), Werte
 *    übernehmen, Feldverweise in Blöcken/Einstellungen umschreiben. Zustand in storage/sites/{website}/convert/{tabelle}.json.
 *  - revert(): Art zurück, Umbenennungen zurück, Altfelder wieder sichtbar (Werte unverändert seit der Umwandlung), Seiten wieder
 *    wie vorher (sofern seither unverändert, sonst Verweise zurückgeschrieben). Neue Grundfelder bleiben als ausgeblendete Spalten.
 *  - dropLegacy(): Altfelder endgültig löschen (danach kein Zurückdrehen mehr).
 *
 * Nie automatisch: die Umwandlung läuft nur auf ausdrücklichen Befehl für eine Tabelle. Geteilte Tabellen und Eingänge: nicht möglich.
 */
final class Convert
{
    /** Gleichartige Felder → Grundfeld (Typen, Namensmuster); erstes passendes Feld gewinnt – je Art */
    private const SAME_CALENDAR = [
        'ort' => [['text'], '~^(ort|location|veranstaltungsort|treffpunkt)$~'],
        'standort' => [['geo'], '~^(standort|karte|geo|ort_?karte|koordinaten)$~'],
        'kategorie' => [['multiselect', 'select'], '~^(kategorie|kategorien|kalender|category|art)$~'],
        'beschreibung' => [['textarea', 'text', 'richtext'], '~^(beschreibung|kurztext|description|teaser)$~'],
        'abgesagt' => [['bool'], '~^(abgesagt|faellt_?aus|cancelled|ausfall)$~'],
    ];
    private const SAME_PLACES = [
        'standort' => [['geo'], '~^(standort|karte|geo|ort_?karte|koordinaten)$~'],
        'hinweise' => [['textarea'], '~^(hinweise|anfahrt|beschreibung)$~'],
    ];
    /** Feldtypen, deren Werte sich verlustarm in den Typ eines Grundfelds übertragen lassen (Altfeld bleibt ausgeblendet) */
    private const CASTS = ['select>multiselect', 'date>datetime', 'text>textarea'];
    /** Titel-Feld der Art */
    private const TITLE = [Kinds::CONTACTS => 'name', Kinds::CALENDAR => 'titel', Kinds::PLACES => 'name'];
    private const SAME = [
        'vorname' => [['text'], '~^(vorname|first_?name|given_?name)$~'],
        'nachname' => [['text'], '~^(nachname|familienname|last_?name|surname|family_?name)$~'],
        'praefix' => [['text'], '~^(praefix|akad_?titel|titel_?person|namenszusatz)$~'],
        'firma' => [['text'], '~^(firma|organisation|organization|company|unternehmen|verein)$~'],
        'abteilung' => [['text'], '~^(abteilung|department|bereich)$~'],
        'position' => [['text'], '~^(position|funktion|job|beruf|amt|rolle_?text|job_?title)$~'],
        'foto' => [['media'], '~^(foto|bild|portrait|portraet|image|photo|avatar)$~'],
        'geburtstag' => [['date'], '~^(geburtstag|geburtsdatum|birthday)$~'],
        'notiz' => [['textarea'], '~^(notiz|notizen|bemerkung|bemerkungen|note|notes)$~'],
        'listen' => [['multiselect'], '~^(listen|liste|rolle|rollen|gruppe|gruppen|kategorie|kategorien)$~'],
    ];
    /** Teile einer Postanschrift in Textfeldern */
    private const ADR = ['street' => '~^(strasse|street|adresse|anschrift|strasse_?nr|hausanschrift)$~', 'zip' => '~^(plz|zip|postleitzahl)$~',
        'city' => '~^(ort|stadt|city|wohnort)$~', 'country' => '~^(land|country)$~'];

    // ------------------------------------------------------------------ Plan

    /**
     * Zuordnung + Bericht. $map: Überschreibungen aus --map (quelle => ['to' => ziel|'-', 'label' => …, 'public' => bool|null]).
     * Rückgabe: ['ok', 'errors', 'fields' => [quelle => [action, to, label, public, note]], 'added' => [...], 'entries' => n, 'sample' => …]
     */
    public static function plan(array $t, string $kind, array $map = []): array
    {
        $errors = [];
        if (!Kinds::isSpecial($kind)) $errors[] = __('Unbekannte Tabellenart „{kind}“ (möglich: {list}).', ['kind' => $kind, 'list' => implode(', ', array_keys(Kinds::VERSIONS))]);
        if (Tables::isInbox($t)) $errors[] = __('Eingangs-Tabellen lassen sich nicht umwandeln.');
        if (Tables::isShared($t)) $errors[] = __('Geteilte Tabellen lassen sich in dieser Version noch nicht umwandeln.');
        if (Kinds::of($t) === $kind) $errors[] = __('Die Tabelle hat schon die Art „{kind}“.', ['kind' => Kinds::label($kind)]);
        if (Kinds::isSpecial(Kinds::of($t)) && Kinds::of($t) !== $kind) $errors[] = __('Die Tabelle hat schon eine Art mit Grundschema – erst zurückdrehen (--revert).');
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $schema = array_column(Kinds::schema($kind), null, 'name');
        $fields = array_column($t['fields'], null, 'name');
        $plan = [];                       // quelle => [action, to, label, public, note]
        $taken = [];                      // Grundfeld → Quelle (umbenannt/behalten)
        // 1. ausdrückliche Zuordnung (--map)
        foreach ($map as $src => $m) {
            if (!isset($fields[$src])) { $errors[] = __('--map: Feld „{f}“ gibt es nicht.', ['f' => $src]); continue; }
            if ($m['to'] === '-') { $plan[$src] = ['action' => 'own', 'to' => $src]; continue; }
            if (!isset($schema[$m['to']])) { $errors[] = __('--map: „{f}“ ist kein Grundfeld ({list}).', ['f' => $m['to'], 'list' => implode(', ', array_keys($schema))]); continue; }
            $plan[$src] = self::action($fields[$src], $m['to'], $schema[$m['to']]['type'], $m['label'] ?? null, $m['public'] ?? null);
            if (in_array($plan[$src]['action'], ['keep', 'rename'], true)) {
                if (isset($taken[$m['to']])) $errors[] = __('--map: „{to}“ ist zweimal zugeordnet.', ['to' => $m['to']]);
                $taken[$m['to']] = $src;
            }
        }
        // 2. gleichnamige Felder mit passendem Typ: bleiben (werden Grundfeld)
        foreach ($schema as $n => $def) {
            if (isset($taken[$n]) || isset($plan[$n]) || !isset($fields[$n])) continue;
            if ($fields[$n]['type'] === $def['type']) { $plan[$n] = ['action' => 'keep', 'to' => $n]; $taken[$n] = $n; }
        }
        // 3. Titel-Feld → Titel der Art (Anzeigename, Titel, Name)
        $tf = (string) ($t['settings']['title_field'] ?? '');
        $tn = self::TITLE[$kind] ?? 'name';
        if (!isset($taken[$tn]) && $tf !== '' && !isset($plan[$tf]) && ($fields[$tf]['type'] ?? '') === 'text' && (!isset($fields[$tn]) || $tf === $tn)) {
            $plan[$tf] = ['action' => $tf === $tn ? 'keep' : 'rename', 'to' => $tn];
            $taken[$tn] = $tf;
        }
        // 3b. Kalender: Zuordnung der Kalender-Einstellung (Beginn, Ende, ganztägig, Wiederholung, Ort, Beschreibung, Kategorie)
        if ($kind === Kinds::CALENDAR && !empty($t['settings']['calendar']['enabled'])) {
            $cal = (array) $t['settings']['calendar'];
            foreach (['start' => 'beginn', 'end' => 'ende', 'all_day' => 'ganztaegig', 'recurrence' => 'wiederholung', 'location' => 'ort', 'description' => 'beschreibung', 'category' => 'kategorie'] as $k => $to) {
                $src = (string) ($cal[$k] ?? '');
                if ($src === '' || !isset($fields[$src]) || isset($plan[$src])) continue;
                if ($k === 'location' && $fields[$src]['type'] === 'geo') $to = 'standort';
                if (isset($taken[$to])) continue;
                $plan[$src] = self::action($fields[$src], $to, $schema[$to]['type'], null, null);
                if (in_array($plan[$src]['action'], ['keep', 'rename'], true)) $taken[$to] = $src;
                if (in_array($plan[$src]['action'], ['cast', 'combine', 'copy'], true)) $taken[$to] = $src;
            }
        }
        // 4. gleichartige Felder nach Namensmuster (Bildfeld der Tabelle zuerst fürs Foto)
        $order = array_keys($fields);
        $img = Tables::imageField($t);
        if ($img !== '') $order = array_values(array_unique([$img, ...$order]));
        foreach (match ($kind) { Kinds::CALENDAR => self::SAME_CALENDAR, Kinds::PLACES => self::SAME_PLACES, default => self::SAME } as $to => [$types, $re]) {
            if (isset($taken[$to]) || !isset($schema[$to])) continue;
            foreach ($order as $n) {
                $f = $fields[$n];
                if (isset($plan[$n]) || !in_array($f['type'], $types, true) || !preg_match($re, $n)) continue;
                if (isset($fields[$to]) && $to !== $n && !isset($plan[$to])) continue;   // Ziel belegt (anderer Typ) – Konflikt unten
                $plan[$n] = self::action($f, $to, $schema[$to]['type'], null, null);
                if ($plan[$n]['action'] === 'own') { unset($plan[$n]); continue; }
                $taken[$to] = $n;
                break;
            }
        }
        // 5. Adressbuch: Telefon, E-Mail, Web und Anschrift → beschriftete Mehrfachfelder (Altfelder bleiben ausgeblendet)
        foreach ($kind === Kinds::CONTACTS ? $fields : [] as $n => $f) {
            if (isset($plan[$n])) continue;
            $to = match ($f['type']) { 'tel' => 'telefon', 'email' => 'email', 'url' => 'web', default => null };
            if ($to === null && $f['type'] === 'text') foreach (self::ADR as $part => $re) if (preg_match($re, $n)) { $to = 'adresse'; break; }
            if ($to === null || !isset($schema[$to])) continue;
            $plan[$n] = self::action($f, $to, $schema[$to]['type'], null, null);
        }
        // 6. übrige Felder bleiben eigene Felder; belegt ein eigenes Feld den Namen eines Grundfelds mit anderem Typ: umbenennen
        foreach ($fields as $n => $f) {
            if (isset($plan[$n])) continue;
            $plan[$n] = isset($schema[$n]) ? ['action' => 'own', 'to' => self::free($n . ($kind === Kinds::CALENDAR ? '_web' : '_eigen'), $fields), 'note' => __('Name gehört zum Grundschema – umbenannt')] : ['action' => 'own', 'to' => $n];
        }
        // Grundfelder ohne Quelle: neu (leer); Namensaufteilung, wenn Vor-/Nachname fehlen
        $added = [];
        foreach ($schema as $n => $def) {
            if (isset($taken[$n]) || array_filter($plan, fn($p) => $p['to'] === $n && in_array($p['action'], ['combine', 'cast', 'copy'], true))) continue;
            $added[] = $n;
        }
        $split = $kind === Kinds::CONTACTS && in_array('vorname', $added, true) && in_array('nachname', $added, true) ? ($taken['name'] ?? (isset($fields['name']) ? 'name' : $tf)) : null;
        // Altfelder, deren Name ein neues Grundfeld braucht (Telefon tel → telefon phones): umbenennen in „…_alt“
        foreach ($plan as $n => &$p) {
            $p += ['label' => null, 'public' => null, 'note' => ''];
            if (in_array($p['action'], ['combine', 'cast'], true)) $p['legacy_name'] = isset($schema[$n]) ? self::free($n . '_alt', $fields) : $n;
            if ($p['action'] === 'copy') $p['own_to'] = isset($schema[$n]) ? self::free($n . ($kind === Kinds::CALENDAR ? '_web' : '_eigen'), $fields) : $n;
            // Grundfeld übernimmt Beschriftung und Pflicht des Grundschemas (Bericht nennt Abweichungen; --revert stellt alt her)
            if (in_array($p['action'], ['keep', 'rename'], true) && isset($schema[$p['to']])) {
                $def = $schema[$p['to']];
                $p['label_from'] = (string) $fields[$n]['label'];
                $p['label_to'] = (string) $def['label'];
                $p['required_from'] = !empty($fields[$n]['required']);
                $p['required_to'] = !empty($def['required']);
            }
        }
        unset($p);
        $entries = Entries::query($t, ['status' => 'all', 'lang' => 'all', 'source' => 'all', 'internal' => true]);
        return ['ok' => !$errors, 'errors' => $errors, 'kind' => $kind, 'table' => $t['handle'], 'fields' => $plan, 'added' => $added, 'split' => $split,
            'layout' => self::layout($t, $kind, $plan),
            'entries' => count($entries), 'drafts' => count(array_filter($entries, fn($e) => $e['status'] !== 'published')),
            'refs' => self::references($t, self::rewrites($plan)), 'settings' => self::settingsRewrites($t, $plan)];
    }

    /** Zuordnung eines Feldes auf ein Grundfeld: gleicher Typ → umbenennen/behalten, sonst → in Mehrfachfeld übernehmen (Altfeld) */
    private static function action(array $f, string $to, string $toType, ?string $label, ?bool $public): array
    {
        if ($f['type'] === $toType) return ['action' => $f['name'] === $to ? 'keep' : 'rename', 'to' => $to];
        if (in_array($f['type'] . '>' . $toType, self::CASTS, true) && !Combo::isMulti($toType)) return ['action' => 'cast', 'to' => $to, 'from_type' => $f['type']];
        // Rich-Text → Text des Grundfelds: Text wird kopiert, das Rich-Text-Feld bleibt sichtbar (Website unverändert, „Nur Website“)
        if (in_array($f['type'], ['richtext', 'mediatext'], true) && $toType === 'textarea') return ['action' => 'copy', 'to' => $to, 'from_type' => $f['type']];
        if (!Combo::isMulti($toType)) return ['action' => 'own', 'to' => $f['name'], 'note' => __('Typ passt nicht zu „{to}“ – bleibt eigenes Feld', ['to' => $to])];
        $s = mb_strtolower($f['name'] . ' ' . $f['label']);
        $label ??= match ($toType) {
            'phones' => match (true) { (bool) preg_match('~mobil|handy|cell~', $s) => 'mobile', str_contains($s, 'fax') => 'fax', (bool) preg_match('~privat|home~', $s) => 'home', (bool) preg_match('~geschäftsstelle|geschaeftsstelle|buero|büro~u', $s) => 'office', default => 'work' },
            'emails' => (bool) preg_match('~privat|home~', $s) ? 'home' : 'work',
            'urls' => 'homepage',
            default => (bool) preg_match('~privat|home|wohn~', $s) ? 'home' : 'work',
        };
        // Freigabe-Vorschlag: bisher sichtbar = öffentlich; „Nie öffentlich“ und mobil/privat = intern; „Redaktion entscheidet“ = wie je
        // Eintrag entschieden ('entry')
        if ($public === null && Exposure::isEditorChoice($f)) return ['action' => 'combine', 'to' => $to, 'label' => $label, 'public' => 'entry'];
        $public ??= !Exposure::isPrivate($f) && (!empty($f['public_ok']) || !preg_match(Exposure::PRIVATE_HINT, $s));
        return ['action' => 'combine', 'to' => $to, 'label' => $label, 'public' => $public];
    }

    private static function free(string $name, array $fields): string
    {
        $n = $name;
        for ($i = 2; isset($fields[$n]); $i++) $n = $name . $i;
        return $n;
    }

    /**
     * Felder nach der Umwandlung (Eingabe für Tables::validate): umbenannt, Altfelder ausgeblendet, Grundfelder mit Beschriftung/Pflicht
     * des Grundschemas, fehlende Grundfelder ergänzt (Auswahl übernimmt die Möglichkeiten des Quellfeldes) und geordnet wie die Vorlage
     * der Art: Grundfelder in ihren Abschnitten, eigene Felder mit ihren Abschnitten, Altfelder am Ende.
     * @return array{fields: list<array>, renames: array<string, string>, legacy: array<string, string>, lines: list<string>, dropped: list<string>}
     */
    private static function prepare(array $t, array $plan): array
    {
        $kind = (string) $plan['kind'];
        $schema = array_column(Kinds::schema($kind), null, 'name');
        $fields = Tables::toInput($t)['fields'];
        $renames = [];
        $legacy = [];
        $srcOf = [];                                     // Grundfeld → Quellfeld (für Auswahl-Möglichkeiten)
        foreach ($fields as &$f) {
            $n = (string) ($f['name'] ?? '');
            $p = $plan['fields'][$n] ?? null;
            if (!$p) continue;
            if (in_array($p['action'], ['rename', 'own'], true) && $p['to'] !== $n) { $f['name'] = $p['to']; $renames[$n] = $p['to']; }
            if ($p['action'] === 'copy' && ($p['own_to'] ?? $n) !== $n) { $f['name'] = (string) $p['own_to']; $renames[$n] = (string) $p['own_to']; }
            if (in_array($p['action'], ['keep', 'rename'], true) && isset($schema[$p['to']])) {
                $f['label'] = $schema[$p['to']]['label'];
                $f['required'] = !empty($schema[$p['to']]['required']);
                $f['in_list'] = !empty($schema[$p['to']]['in_list']);
                unset($f['labels'], $f['legacy']);          // ausgeblendete Spalte einer früheren Umwandlung wird wieder Grundfeld
            }
            if (in_array($p['action'], ['combine', 'cast'], true)) {
                $f['legacy'] = 1;
                $f['in_list'] = 0;
                $f['required'] = false;
                if ($p['legacy_name'] !== $n) $f['name'] = $p['legacy_name'];
                $legacy[$n] = $p['legacy_name'];
                $srcOf[$p['to']] ??= $f;
            }
        }
        unset($f);
        $fields = self::ruleRefs($fields, $renames + $legacy);
        // Ordnen nach der Vorlage der Art: Gruppen = Abschnitt + Felder. Eigene Abschnitte mit gleicher Überschrift wie ein Abschnitt der
        // Vorlage gehen in diesem auf (z. B. „Termin“), andere folgen mit ihren eigenen Feldern; leere Abschnitte entfallen
        $byName = [];
        foreach ($fields as $f) if (!in_array($f['type'] ?? '', Tables::LAYOUT, true)) $byName[(string) $f['name']] = $f;
        $names = array_flip(array_column($fields, 'name'));
        $sysLabels = array_map(fn($d) => mb_strtolower((string) $d['label']), $schema);
        $groups = [];                                    // [key => ['head' => ?section, 'items' => [...], 'sys' => [...], 'own' => [...]]]
        $key = '_';
        $used = [];
        foreach (Kinds::presetFields($kind) as $pf) {
            if (($pf['type'] ?? '') === 'section') {
                $pf['name'] = self::free((string) $pf['name'], $names);
                $names[$pf['name']] = true;
                $key = 'p:' . mb_strtolower((string) $pf['label']);
                $groups[$key] = ['head' => $pf, 'items' => [], 'sys' => [], 'own' => []];
                continue;
            }
            $groups[$key] ??= ['head' => null, 'items' => [], 'sys' => [], 'own' => []];
            $n = (string) $pf['name'];
            $f = isset($byName[$n]) && empty($byName[$n]['legacy']) ? $byName[$n] : $pf;
            if (!isset($byName[$n]) && isset($srcOf[$n]['options']) && in_array($pf['type'], ['select', 'multiselect'], true)) {
                $f['options'] = $srcOf[$n]['options'];   // z. B. Kategorie (Auswahl) → Kalender (Mehrfachauswahl) mit denselben Möglichkeiten
                if (!empty($srcOf[$n]['options_i18n'])) $f['options_i18n'] = $srcOf[$n]['options_i18n'];
            }
            $groups[$key]['items'][] = $f;
            $groups[$key]['sys'][] = $n;
            $used[$n] = true;
        }
        $dropped = [];
        $key = null;
        $pendingContent = [];
        foreach ($fields as $f) {
            $type = (string) ($f['type'] ?? '');
            if ($type === 'section') {
                $lk = mb_strtolower((string) $f['label']);
                $key = isset($groups['p:' . $lk]) ? 'p:' . $lk : 'o:' . (string) $f['name'];
                $groups[$key] ??= ['head' => $f, 'items' => [], 'sys' => [], 'own' => [], 'orig' => true];
                $pendingContent = [];
                continue;
            }
            if ($type === 'content') { $pendingContent[] = $f; continue; }
            $n = (string) $f['name'];
            if (isset($used[$n]) || !empty($f['legacy'])) continue;
            // eigenes Feld, das wegen des Grundschemas umbenannt wurde und gleich heißt wie ein Grundfeld: „… (Website)“
            if (in_array($n, $renames, true) && in_array(mb_strtolower((string) $f['label']), $sysLabels, true) && !isset($schema[$n])) $f['label'] .= ' (' . __('Website') . ')';
            $k = $key ?? 'o:_';
            $groups[$k] ??= ['head' => null, 'items' => [], 'sys' => [], 'own' => []];
            array_push($groups[$k]['items'], ...$pendingContent, ...[$f]);
            $groups[$k]['own'][] = $n;
            $pendingContent = [];
        }
        $out = [];
        $lines = [];
        foreach ($groups as $g) {
            if (!$g['items']) { if (!empty($g['orig']) && $g['head']) $dropped[] = (string) $g['head']['label']; continue; }
            if ($g['head']) $out[] = $g['head'];
            array_push($out, ...$g['items']);
            $lines[] = ($g['head'] ? '„' . $g['head']['label'] . '“' : __('ohne Abschnitt')) . ':' . ($g['sys'] ? ' ' . implode(' ', $g['sys']) : '')
                . ($g['own'] ? ' + ' . __('eigene') . ': ' . implode(' ', $g['own']) : '');
        }
        $old = array_values(array_filter($fields, fn($f) => !empty($f['legacy'])));
        array_push($out, ...$old);
        if ($old) $lines[] = __('Altfelder (ausgeblendet) am Ende') . ': ' . implode(' ', array_column($old, 'name'));
        if ($dropped) $lines[] = __('Abschnitte entfallen (enthielten nur noch Grund- oder Altfelder)') . ': ' . implode(', ', array_map(fn($x) => '„' . $x . '“', $dropped));
        return ['fields' => $out, 'renames' => $renames, 'legacy' => $legacy, 'lines' => $lines, 'dropped' => $dropped];
    }

    /** Reihenfolge für den Bericht des Probelaufs */
    private static function layout(array $t, string $kind, array $plan): array
    {
        $p = self::prepare($t, ['kind' => $kind, 'fields' => $plan]);
        return ['lines' => $p['lines'], 'dropped' => $p['dropped']];
    }

    /** Verweise alt → neu für Blöcke (feld → grundfeld bzw. Mehrfachfeld) */
    private static function rewrites(array $plan): array
    {
        $out = [];
        foreach ($plan as $n => $p) {
            $to = $p['action'] === 'copy' ? (string) ($p['own_to'] ?? $n) : $p['to'];
            if ($to !== $n) $out[$n] = $to;
        }
        return $out;
    }

    /** Seiten (Entwurf/veröffentlicht), deren Blöcke Felder dieser Tabelle nennen: [id => [title, [alt => neu]]] */
    private static function references(array $t, array $rewrites): array
    {
        $out = [];
        if (!$rewrites) return $out;
        foreach (app()->db->fetchAll("SELECT id, title, content_draft, content_published FROM pages WHERE content_draft LIKE ? OR content_published LIKE ?",
            ['%"' . $t['handle'] . '.%', '%"' . $t['handle'] . '.%']) as $pg) {
            $hit = [];
            foreach ($rewrites as $old => $new) {
                $needle = '"' . $t['handle'] . '.' . $old . '"';
                if (str_contains((string) $pg['content_draft'], $needle) || str_contains((string) $pg['content_published'], $needle)) $hit[$old] = $new;
            }
            if ($hit) $out[(int) $pg['id']] = [(string) $pg['title'], $hit];
        }
        return $out;
    }

    /** Einstellungen der Tabelle mit Feldnamen (Titel, Bild, Beschreibung, Sortierung) – [schlüssel => [alt, neu]] */
    private static function settingsRewrites(array $t, array $plan): array
    {
        $out = [];
        foreach (['title_field', 'image_field', 'description_field', 'sort_field'] as $k) {
            $v = (string) ($t['settings'][$k] ?? '');
            if ($v === '' || !isset($plan[$v]) || $plan[$v]['to'] === $v) continue;
            // Sortierung nach einem Feld, das im Mehrfachfeld aufgeht → nach Nachname
            $out[$k] = [$v, $plan[$v]['action'] === 'combine' && $k === 'sort_field' ? 'nachname' : $plan[$v]['to']];
        }
        return $out;
    }

    /** Bericht des Probelaufs als Zeilen (Kommandozeile) */
    public static function report(array $plan, array $t): array
    {
        if (!$plan['ok']) return array_map(fn($e) => '! ' . $e, $plan['errors']);
        $lbl = fn(string $type, ?string $k) => $k === null ? '' : (Combo::LABELS[$type][$k] ?? $k);
        $l = ["Tabelle „{$t['name']}“ ({$t['handle']}): " . Kinds::label(Kinds::of($t)) . ' → ' . Kinds::label($plan['kind']) . " · {$plan['entries']} Einträge"
            . ($plan['drafts'] ? ", davon {$plan['drafts']} Entwürfe" : ''), '', 'Feldzuordnung:'];
        $by = array_column($t['fields'], null, 'name');
        foreach ($plan['fields'] as $n => $p) {
            $type = (string) (Kinds::schema($plan['kind'])[array_search($p['to'], Kinds::systemNames($plan['kind']), true)]['type'] ?? '');
            $l[] = sprintf('  %-16s %-10s → %s', $n, '(' . $by[$n]['type'] . ')', match ($p['action']) {
                'keep' => "{$p['to']} (bleibt, wird Grundfeld)",
                'rename' => "{$p['to']} (umbenannt, Werte bleiben)",
                'copy' => "{$p['to']} (Text daraus kopiert) · bleibt sichtbar als eigenes Feld „" . ($p['own_to'] ?? $n) . '“',
                'cast' => "{$p['to']} ({$type}, Werte übernommen) · Altfeld bleibt ausgeblendet" . ($p['legacy_name'] !== $n ? " als „{$p['legacy_name']}“" : ''),
                'combine' => "{$p['to']}[" . $lbl($type, $p['label']) . ', ' . ($p['public'] === 'entry' ? 'wie je Eintrag freigegeben' : ($p['public'] === true ? 'öffentlich' : 'intern')) . ']'
                    . ($type === 'addresses' ? ' (Teil der Anschrift)' : '') . ' · Altfeld bleibt ausgeblendet' . ($p['legacy_name'] !== $n ? " als „{$p['legacy_name']}“" : ''),
                default => ($p['to'] !== $n ? "{$p['to']} (eigenes Feld, umbenannt)" : 'bleibt eigenes Feld'),
            } . ($p['note'] !== '' ? ' – ' . $p['note'] : ''));
        }
        $adr = array_filter($plan['fields'], fn($p) => $p['action'] === 'combine' && $p['to'] === 'adresse');
        if ($adr) {
            $scope = self::addressScope($t, $adr);
            if (array_filter($adr, fn($p) => $p['public'] === 'entry')) $scope = 'entry';
            $l[] = '  Anschrift: ' . match ($scope) { 'entry' => 'je Eintrag wie freigegeben (Redaktion entscheidet)', '1' => 'öffentlich (alle Teile waren öffentlich)', 'city' => 'nur Ort öffentlich (Straße/PLZ waren nicht öffentlich)', default => 'intern' };
        }
        if ($plan['added']) $l[] = '  Neue Grundfelder (leer): ' . implode(', ', $plan['added']);
        $relabel = [];
        foreach ($plan['fields'] as $n => $p) {
            if (!isset($p['label_to'])) continue;
            if ($p['label_from'] !== $p['label_to']) $relabel[] = "„{$p['label_from']}“ → „{$p['label_to']}“";
            if ($p['required_from'] !== $p['required_to']) $relabel[] = "{$p['to']}: " . ($p['required_to'] ? 'jetzt Pflicht' : 'nicht mehr Pflicht');
        }
        if ($relabel) $l[] = '  Beschriftung/Pflicht aus dem Grundschema: ' . implode(', ', $relabel);
        if ($plan['kind'] === Kinds::CALENDAR) {
            $own = array_keys(array_filter($plan['fields'], fn($p, $n) => in_array($p['action'], ['own', 'copy'], true) && empty($by[$n]['legacy']), ARRAY_FILTER_USE_BOTH));
            if ($own) $l[] = '  Eigene Felder werden „Nur Website“ (nie in ICS-Datei, Abo, CalDAV): ' . implode(', ', array_map(fn($n) => $plan['fields'][$n]['action'] === 'copy' ? $plan['fields'][$n]['own_to'] : $plan['fields'][$n]['to'], $own));
        }
        $l[] = '';
        $l[] = 'Reihenfolge im Formular:';
        foreach ($plan['layout']['lines'] as $line) $l[] = '  ' . $line;
        if ($plan['split']) $l[] = "  Vor-/Nachname: aus „{$plan['split']}“ aufgeteilt (letztes Wort = Nachname, Titel wie „Dr.“ → Titel) – Beispiele unten prüfen";
        $l[] = '';
        $l[] = 'Freigabe:';
        if ($plan['kind'] === Kinds::CONTACTS) $l[] = '  „Auf der Website zeigen“: veröffentlichte Einträge an (Website bleibt gleich)' . ($plan['drafts'] ? ", {$plan['drafts']} Entwürfe aus" : '');
        else $l[] = '  Status der Einträge bleibt (veröffentlicht/Entwurf) – Website, Feed und Kalender-Blöcke zeigen dieselben Termine.';
        $lost = [];
        foreach (Entries::query($t, ['status' => 'published', 'lang' => 'all', 'source' => 'all', 'internal' => true]) as $e) {
            foreach ($plan['fields'] as $n => $p) {
                if ($p['action'] !== 'combine' || $p['public'] === true || !Exposure::allowed($by[$n], $e) || trim((string) ($e[$n] ?? '')) === '') continue;
                if ($p['public'] === 'entry' && $p['to'] !== 'adresse') continue;
                if ($p['to'] === 'adresse' && self::addressScope($t, $adr, $e) !== '0' && (self::addressScope($t, $adr, $e) === '1' || preg_match(self::ADR['city'], $n))) continue;
                $lost[$n] = ($lost[$n] ?? 0) + 1;
            }
        }
        foreach ($lost as $n => $c) $l[] = "  Nicht mehr öffentlich: „{$by[$n]['label']}“ bei {$c} Einträgen (Vorschlag intern – mit --map={$n}:{$plan['fields'][$n]['to']}[…+] öffentlich lassen)";
        if (!$lost) $l[] = '  Keine Angabe, die bisher öffentlich war, wird intern.';
        if ($plan['split']) {
            $l[] = '';
            $l[] = 'Namensaufteilung (Titel | Vorname | Nachname):';
            foreach (array_slice(Entries::query($t, ['status' => 'all', 'lang' => 'all', 'source' => 'all', 'internal' => true]), 0, 60) as $e) {
                [$pre, $given, $family] = self::splitName((string) ($e[$plan['split']] ?? ''));
                $l[] = sprintf('  %-34s → %s | %s | %s', mb_strimwidth((string) ($e[$plan['split']] ?? ''), 0, 34, '…'), $pre ?: '–', $given ?: '–', $family ?: '–');
            }
        }
        $l[] = '';
        $l[] = 'Blöcke und Einstellungen:';
        foreach ($plan['settings'] as $k => [$a, $b]) $l[] = "  Einstellung {$k}: {$a} → {$b}";
        foreach ($plan['refs'] as $id => [$title, $hit]) $l[] = "  Seite {$id} „{$title}“: " . implode(', ', array_map(fn($a, $b) => "{$a} → {$b}", array_keys($hit), $hit));
        if (!$plan['settings'] && !$plan['refs']) $l[] = '  keine Verweise';
        $l[] = '';
        $l[] = 'Verknüpfungen anderer Tabellen bleiben (gleiche Tabelle, gleiche IDs). Zurückdrehen: data:convert ' . $t['handle'] . ' --revert';
        return $l;
    }

    /**
     * Freigabe der übernommenen Anschrift: '1' (alle Teile öffentlich), 'city' (nur Ort öffentlich), '0'. Teile mit „Redaktion
     * entscheidet“ zählen je Eintrag ($e; ohne Eintrag: Standard des Feldes).
     */
    private static function addressScope(array $t, array $adrPlan, ?array $e = null): string
    {
        $by = array_column($t['fields'], null, 'name');
        $pub = [];
        foreach ($adrPlan as $n => $p) {
            foreach (self::ADR as $part => $re) if (preg_match($re, $n)) {
                $pub[$part] = $p['public'] === 'entry' ? Exposure::allowed($by[$n], $e ?? []) : ($p['public'] === true && !Exposure::isPrivate($by[$n] ?? null));
            }
        }
        if ($pub && !in_array(false, $pub, true)) return '1';
        return !empty($pub['city']) ? 'city' : '0';
    }

    /** „Dr. Erika von Musterfrau“ → [Titel, Vorname, Nachname]; „Musterfrau, Erika“ = Nachname, Vorname */
    public static function splitName(string $name): array
    {
        $name = trim((string) preg_replace('~\s+~u', ' ', $name));
        if ($name === '') return ['', '', ''];
        if (preg_match('~^([^,]+),\s*(.+)$~u', $name, $m)) return ['', trim($m[2]), trim($m[1])];
        $w = explode(' ', $name);
        $pre = [];
        while (count($w) > 1 && (str_ends_with($w[0], '.') || in_array(mb_strtolower($w[0]), ['herr', 'frau'], true))) $pre[] = array_shift($w);
        $family = [(string) array_pop($w)];
        while (count($w) > 1 && in_array((string) end($w), ['von', 'van', 'vom', 'zu', 'zum', 'zur', 'de', 'der', 'den', 'di', 'da', 'ten', 'ter'], true)) array_unshift($family, (string) array_pop($w));
        return [implode(' ', $pre), implode(' ', $w), implode(' ', $family)];
    }

    // ------------------------------------------------------------------ Ausführen

    private static function stateFile(array $t): string
    {
        return site()->storage('convert') . '/' . $t['handle'] . '.json';
    }

    /** Umwandlung ausführen (nach plan()). @return list<string> Protokoll */
    public static function apply(array $t, array $plan): array
    {
        if (!$plan['ok']) throw new \RuntimeException(implode(' ', $plan['errors']));
        $log = [];
        $kind = $plan['kind'];
        // 1. Seiten vorher merken (für --revert)
        $pages = [];
        foreach (array_keys($plan['refs']) as $id) $pages[$id] = app()->db->fetch('SELECT content_draft, content_published FROM pages WHERE id = ?', [$id]);
        // 2. Schema vorbereiten und prüfen (vor jeder Änderung): umbenennen, Altfelder ausgeblendet, Grundfelder ergänzt und geordnet
        // (prepare), Art setzen
        $in = Tables::toInput($t);
        $before = $in['fields'];
        $prep = self::prepare($t, $plan);
        $in['fields'] = $prep['fields'];
        $renames = $prep['renames'];
        $legacy = $prep['legacy'];
        foreach ($plan['settings'] as $k => [$a, $b]) $in['settings'][$k] = $b;
        if (isset($in['settings']['search']['fields']) && is_array($in['settings']['search']['fields'])) {
            foreach ($renames as $a => $b) if (isset($in['settings']['search']['fields'][$a])) { $in['settings']['search']['fields'][$b] = $in['settings']['search']['fields'][$a]; unset($in['settings']['search']['fields'][$a]); }
        }
        $in['settings']['schema_type'] = ($in['settings']['schema_type'] ?? '') ?: match ($kind) { Kinds::CALENDAR => 'Event', Kinds::PLACES => 'Place', default => 'Person' };
        if ($kind === Kinds::CALENDAR) $in['settings']['title_field'] = 'titel';
        $in['_kind'] = $kind;
        $in['_convert'] = ['kind' => $kind, 'from' => Kinds::of($t), 'at' => now(), 'renames' => $renames, 'legacy' => $legacy, 'added' => $plan['added']];
        [$def, $errors] = Tables::validate($in, $t);
        if ($errors) throw new \RuntimeException('Schema: ' . implode(' ', array_map('strval', $errors)));
        // 3. Sicherung der Datenbank
        if (app()->db->driver !== 'mysql') {
            $dir = site()->storage('convert');
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $bak = $dir . '/' . $t['handle'] . '-' . date('Ymd-His') . '.sqlite';
            app()->db->pdo->exec('VACUUM INTO ' . app()->db->pdo->quote($bak));
            $log[] = '✓ Sicherung: ' . $bak;
        }
        // 4. Revision je Eintrag (Ausgangsstand), dann Schema ändern
        $rows = Entries::query($t, ['status' => 'all', 'lang' => 'all', 'source' => 'all', 'internal' => true]);
        foreach ($rows as $e) Revisions::record($t, $e, __('Vor der Umwandlung in „{kind}“', ['kind' => Kinds::label($kind)]));
        $log[] = '✓ Revision je Eintrag (' . count($rows) . ')';
        Tables::update($t, $def);
        $nt = Tables::find((string) $t['handle']) ?? throw new \RuntimeException('Tabelle fehlt nach der Umwandlung.');
        $log[] = '✓ Schema: Art ' . Kinds::label($kind) . ', ' . count($renames) . ' umbenannt, ' . count($legacy) . ' Altfelder, ' . count($plan['added']) . ' Grundfelder ergänzt';
        // 5. Werte übernehmen (direkt, ohne Prüfung der Formate – Altwerte bleiben unverändert in den Altfeldern)
        $by = array_column($t['fields'], null, 'name');
        $adr = array_filter($plan['fields'], fn($p) => $p['action'] === 'combine' && $p['to'] === 'adresse');
        $db = Tables::db($nt);
        $n = 0;
        foreach ($rows as $e) {
            $set = [];
            $multi = [];
            $address = [];
            foreach ($plan['fields'] as $src => $p) {
                if ($p['action'] === 'copy') {
                    $x = ICal::plain(($p['from_type'] ?? '') === 'mediatext' ? Entries::html($t, $e, $src) : (string) ($e[$src] ?? ''));
                    $set[$p['to']] = $x !== '' ? $x : null;
                    continue;
                }
                if ($p['action'] === 'cast') {
                    // Typ des Grundfelds: Auswahl → Mehrfachauswahl, Datum → Datum & Uhrzeit (ganztägig), Rich-Text → Text
                    $v = $e[$src] ?? null;
                    $set[$p['to']] = match ($p['from_type'] ?? '') {
                        'select' => json_encode(is_scalar($v) && (string) $v !== '' ? [(string) $v] : [], JSON_UNESCAPED_UNICODE),
                        'richtext' => ($x = ICal::plain((string) $v)) !== '' ? $x : null,
                        default => is_scalar($v) && trim((string) $v) !== '' ? (string) $v : null,
                    };
                    if (($p['from_type'] ?? '') === 'date' && $p['to'] === 'beginn' && is_scalar($v) && trim((string) $v) !== ''
                        && !array_filter($plan['fields'], fn($q) => $q['to'] === 'ganztaegig')) $set['ganztaegig'] = 1;
                    continue;
                }
                if ($p['action'] !== 'combine') continue;
                $v = trim((string) ($e[$src] ?? ''));
                if ($v === '') continue;
                if ($p['to'] === 'adresse') {
                    foreach (self::ADR as $part => $re) if (preg_match($re, $src)) $address[$part] = $v;
                    continue;
                }
                $multi[$p['to']][] = ['label' => $p['label'], 'value' => $v, 'public' => $p['public'] === 'entry' ? Exposure::allowed($by[$src], $e) : $p['public'] === true];
            }
            if ($address) $multi['adresse'][] = ['label' => 'work', 'street' => $address['street'] ?? '', 'zip' => $address['zip'] ?? '', 'city' => $address['city'] ?? '',
                'country' => $address['country'] ?? '', 'public' => self::addressScope($t, $adr, $e)];
            foreach ($multi as $field => $items) $set[$field] = json_encode($items, JSON_UNESCAPED_UNICODE);
            if ($plan['split']) {
                [$pre, $given, $family] = self::splitName((string) ($e[$plan['split']] ?? ''));
                $set += ['praefix' => $pre !== '' ? $pre : null, 'vorname' => $given, 'nachname' => $family];
            }
            if ($kind === Kinds::CONTACTS) $set[Kinds::PUBLIC_FIELD] = $e['status'] === 'published' ? 1 : 0;
            if ($set) $db->update($nt['table'], $set, 'id = :id', ['id' => (int) $e['id']]);
            $n++;
        }
        $log[] = "✓ Werte übernommen ({$n} Einträge)";
        // 6. Verweise in Blöcken
        $after = [];
        foreach ($plan['refs'] as $id => [$title, $hit]) {
            $pg = $pages[$id];
            $upd = [];
            foreach (['content_draft', 'content_published'] as $col) if ($pg[$col] !== null) $upd[$col] = self::rewritePage((string) $pg[$col], $t['handle'], $hit);
            app()->db->update('pages', $upd, 'id = :id', ['id' => $id]);
            $after[$id] = $upd;
            $log[] = "✓ Seite {$id} „{$title}“: Feldverweise umgeschrieben";
        }
        // 7. Zustand für --revert
        file_put_contents(self::stateFile($t), json_encode(['plan' => $plan, 'renames' => $renames, 'legacy' => $legacy, 'pages_before' => $pages, 'pages_after' => $after,
            'fields_before' => $before, 'calendar_before' => $t['settings']['calendar'] ?? null, 'schema_type_before' => (string) ($t['settings']['schema_type'] ?? ''),
            'title_field_before' => (string) ($t['settings']['title_field'] ?? ''), 'at' => now()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        PageCache::clear();
        Tables::flush();
        if (($nt = Tables::find((string) $t['handle'])) && Calendar::enabled($nt)) {
            $log[] = '✓ Vorkommens-Index: ' . Occurrences::rebuild($nt) . ' Termine in den nächsten ' . Occurrences::HORIZON . ' Monaten';
        }
        $log[] = '✓ Fertig. Suchindex neu aufbauen: php bin/console search:index --site=' . site()->key;
        return $log;
    }

    /** Bedingungen der Felder (anzeigen wenn, Pflicht wenn, Vergleich – Core\Data\Rules) auf umbenannte Felder umstellen */
    private static function ruleRefs(array $fields, array $map): array
    {
        foreach ($fields as &$f) {
            foreach (['visible_if', 'required_if'] as $k) {
                foreach ((array) ($f[$k]['rules'] ?? []) as $i => $r) if (isset($map[$r['field'] ?? ''])) $f[$k]['rules'][$i]['field'] = $map[$r['field']];
            }
            foreach ((array) ($f['compare'] ?? []) as $i => $c) if (isset($map[$c['field'] ?? ''])) $f['compare'][$i]['field'] = $map[$c['field']];
        }
        unset($f);
        return $fields;
    }

    /** Feldverweise „tabelle.alt“ → „tabelle.neu“ im Blöcke-JSON einer Seite (doppelte in Listen fallen weg) */
    public static function rewritePage(string $json, string $handle, array $map): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) return $json;
        $walk = function (mixed $v) use (&$walk, $handle, $map): mixed {
            if (is_string($v) && str_starts_with($v, $handle . '.') && isset($map[substr($v, strlen($handle) + 1)])) return $handle . '.' . $map[substr($v, strlen($handle) + 1)];
            if (!is_array($v)) return $v;
            $out = [];
            foreach ($v as $k => $x) $out[$k] = $walk($x);
            return array_is_list($out) && $out && array_filter($out, 'is_string') === $out ? array_values(array_unique($out)) : $out;
        };
        return (string) json_encode($walk($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Zurückdrehen (solange die Altfelder da sind). @return list<string> */
    public static function revert(array $t): array
    {
        $file = self::stateFile($t);
        $conv = (array) ($t['settings']['convert'] ?? []);
        if (!$conv || !is_file($file)) throw new \RuntimeException(__('Keine Umwandlung zum Zurückdrehen gefunden.'));
        $st = json_decode((string) file_get_contents($file), true) ?: [];
        $in = Tables::toInput($t);
        if (!empty($st['fields_before'])) return self::revertFields($t, $st, $in, $conv, $file);
        $back = array_flip((array) ($st['renames'] ?? []));
        $legacyBack = array_flip((array) ($st['legacy'] ?? []));
        $restoredNames = array_merge(array_keys((array) ($st['renames'] ?? [])), array_keys((array) ($st['legacy'] ?? [])));
        $names = array_column($in['fields'], 'name');
        // Neue Grundfelder und Mehrfachfelder der Umwandlung: bleiben als ausgeblendete Spalten (Werte gehen nicht verloren);
        // belegt eines einen wiederhergestellten Namen (z. B. telefon), heißt es danach „telefon_kontakt“
        $combined = array_column(array_filter((array) ($st['plan']['fields'] ?? []), fn($p) => ($p['action'] ?? '') === 'combine'), 'to');
        $hide = array_unique([...(array) ($st['plan']['added'] ?? []), ...$combined]);
        foreach ($in['fields'] as &$f) {
            $n = (string) ($f['name'] ?? '');
            if (isset($back[$n])) { $f['name'] = $back[$n]; continue; }
            if (isset($legacyBack[$n])) { $f['name'] = $legacyBack[$n]; unset($f['legacy']); continue; }
            if (!in_array($n, $hide, true)) continue;
            $f['legacy'] = 1;
            $f['in_list'] = 0;
            if (in_array($n, $restoredNames, true)) $f['name'] = self::free($n . '_kontakt', array_flip($names));
        }
        unset($f);
        $in['fields'] = self::ruleRefs($in['fields'], $back + $legacyBack);
        foreach ((array) ($st['plan']['settings'] ?? []) as $k => [$a, $b]) $in['settings'][$k] = $a;
        $in['_kind'] = (string) ($conv['from'] ?? 'content');
        $in['_convert'] = [];
        unset($in['settings']['convert']);
        [$def, $errors] = Tables::validate($in, $t);
        if ($errors) throw new \RuntimeException('Schema: ' . implode(' ', array_map('strval', $errors)));
        unset($def['settings']['convert'], $def['settings']['kind_version']);
        Tables::update($t, $def);
        $log = ['✓ Schema zurück: Art ' . Kinds::label((string) $in['_kind']) . ', Umbenennungen und Altfelder wiederhergestellt'];
        foreach ((array) ($st['pages_before'] ?? []) as $id => $before) {
            $now = app()->db->fetch('SELECT content_draft, content_published FROM pages WHERE id = ?', [(int) $id]);
            if (!$now) continue;
            $after = (array) ($st['pages_after'][$id] ?? []);
            $upd = [];
            foreach (['content_draft', 'content_published'] as $col) {
                if (!array_key_exists($col, $after)) continue;
                // unverändert seit der Umwandlung → Stand von vorher; sonst Verweise zurückschreiben (nur 1:1-Umbenennungen)
                $upd[$col] = $now[$col] === $after[$col] ? $before[$col] : self::rewritePage((string) $now[$col], $t['handle'], $back);
            }
            if ($upd) app()->db->update('pages', $upd, 'id = :id', ['id' => (int) $id]);
            $log[] = "✓ Seite {$id}: Blöcke zurück";
        }
        @rename($file, $file . '.reverted-' . date('Ymd-His'));
        PageCache::clear();
        Tables::flush();
        return $log;
    }

    /**
     * Zurückdrehen mit gespeicherter Feldliste (ab Kalender-Stufe): alte Reihenfolge, Abschnitte, Namen, Beschriftungen und Pflicht der
     * Felder von damals; seither ergänzte eigene Felder bleiben (am Ende), Felder der Umwandlung (neue Grundfelder, Mehrfachfelder)
     * bleiben als ausgeblendete Spalten (Werte gehen nicht verloren).
     */
    private static function revertFields(array $t, array $st, array $in, array $conv, string $file): array
    {
        $cur = [];
        foreach ($in['fields'] as $f) $cur[(string) ($f['id'] ?? '')] = $f;
        $beforeIds = array_flip(array_map(fn($f) => (string) ($f['id'] ?? ''), (array) $st['fields_before']));
        $out = [];
        foreach ((array) $st['fields_before'] as $f) {
            $id = (string) ($f['id'] ?? '');
            if (in_array($f['type'] ?? '', Tables::LAYOUT, true)) { $out[] = $f; continue; }
            if (!isset($cur[$id])) continue;                                   // seither gelöscht
            $c = $cur[$id];
            foreach (['name', 'label', 'labels', 'required', 'in_list', 'type', 'options', 'options_i18n', 'exposure', 'private', 'website_only'] as $k) {
                if (array_key_exists($k, $f)) $c[$k] = $f[$k]; else unset($c[$k]);
            }
            unset($c['legacy'], $c['system']);
            if (!empty($f['legacy'])) { $c['legacy'] = 1; $c['in_list'] = 0; }   // schon vorher ausgeblendet (frühere Umwandlung)
            $out[] = $c;
        }
        $restored = array_flip(array_column($out, 'name'));
        $made = array_flip([...(array) ($st['plan']['added'] ?? []), ...array_column(array_filter((array) ($st['plan']['fields'] ?? []), fn($p) => in_array($p['action'] ?? '', ['combine', 'cast', 'copy'], true)), 'to')]);
        foreach ($in['fields'] as $f) {
            $id = (string) ($f['id'] ?? '');
            if (isset($beforeIds[$id])) continue;
            $n = (string) ($f['name'] ?? '');
            if (in_array($f['type'] ?? '', Tables::LAYOUT, true)) continue;      // Abschnitte der Vorlage fallen weg
            unset($f['system']);
            if (isset($made[$n])) {
                $f['legacy'] = 1;
                $f['in_list'] = 0;
                $f['required'] = false;
                $suffix = ($st['plan']['kind'] ?? '') === Kinds::CONTACTS ? '_kontakt' : '_' . (string) ($st['plan']['kind'] ?? 'art');
                if (isset($restored[$n])) $f['name'] = self::free($n . $suffix, $restored + array_flip(array_column($in['fields'], 'name')));
            }
            $restored[(string) $f['name']] = true;
            $out[] = $f;
        }
        $in['fields'] = $out;
        foreach ((array) ($st['plan']['settings'] ?? []) as $k => [$a, $b]) $in['settings'][$k] = $a;
        if (array_key_exists('calendar_before', $st) && is_array($st['calendar_before'])) $in['settings']['calendar'] = $st['calendar_before'];
        foreach (['schema_type', 'title_field'] as $k) if (array_key_exists($k . '_before', $st)) $in['settings'][$k] = (string) $st[$k . '_before'];
        $in['_kind'] = (string) ($conv['from'] ?? 'content');
        $in['_convert'] = [];
        unset($in['settings']['convert']);
        [$def, $errors] = Tables::validate($in, $t);
        if ($errors) throw new \RuntimeException('Schema: ' . implode(' ', array_map('strval', $errors)));
        unset($def['settings']['convert'], $def['settings']['kind_version']);
        Tables::update($t, $def);
        $log = ['✓ Schema zurück: Art ' . Kinds::label((string) $in['_kind']) . ', Reihenfolge, Abschnitte, Beschriftungen und Pflicht wie vor der Umwandlung'];
        $back = array_flip((array) ($st['renames'] ?? []));
        foreach ((array) ($st['pages_before'] ?? []) as $id => $before) {
            $now = app()->db->fetch('SELECT content_draft, content_published FROM pages WHERE id = ?', [(int) $id]);
            if (!$now) continue;
            $after = (array) ($st['pages_after'][$id] ?? []);
            $upd = [];
            foreach (['content_draft', 'content_published'] as $col) {
                if (!array_key_exists($col, $after)) continue;
                $upd[$col] = $now[$col] === $after[$col] ? $before[$col] : self::rewritePage((string) $now[$col], $t['handle'], $back);
            }
            if ($upd) app()->db->update('pages', $upd, 'id = :id', ['id' => (int) $id]);
            $log[] = "✓ Seite {$id}: Blöcke zurück";
        }
        @rename($file, $file . '.reverted-' . date('Ymd-His'));
        PageCache::clear();
        Tables::flush();
        if (($nt = Tables::find((string) $t['handle'])) && Calendar::enabled($nt)) Occurrences::rebuild($nt);
        return $log;
    }

    /** Altfelder endgültig entfernen (danach kein --revert mehr). @return list<string> */
    public static function dropLegacy(array $t): array
    {
        $in = Tables::toInput($t);
        $drop = array_column(array_filter($in['fields'], fn($f) => !empty($f['legacy'])), 'name');
        if (!$drop) return ['= Keine Altfelder.'];
        $in['fields'] = array_values(array_filter($in['fields'], fn($f) => empty($f['legacy'])));
        $in['_convert'] = ['dropped' => $drop, 'at' => now()] + (array) ($t['settings']['convert'] ?? []);
        [$def, $errors] = Tables::validate($in, $t);
        if ($errors) throw new \RuntimeException(implode(' ', array_map('strval', $errors)));
        Tables::update($t, $def);
        if (is_file($f = self::stateFile($t))) @rename($f, $f . '.dropped-' . date('Ymd-His'));
        return ['✓ Altfelder entfernt: ' . implode(', ', $drop)];
    }

    /** --map=quelle:ziel[label+],quelle:- → [quelle => [to, label, public]] */
    public static function parseMap(string $s): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $s))) as $part) {
            if (!preg_match('~^([a-z][a-z0-9_]*):(-|[a-z][a-z0-9_]*)(?:\[([a-z_]*)([+-]?)\])?$~', $part, $m)) continue;
            $out[$m[1]] = ['to' => $m[2], 'label' => ($m[3] ?? '') !== '' ? $m[3] : null, 'public' => match ($m[4] ?? '') { '+' => true, '-' => false, default => null }];
        }
        return $out;
    }
}
