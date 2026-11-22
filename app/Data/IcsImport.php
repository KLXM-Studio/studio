<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
// Teile übernommen aus KLXM/scheduler (MIT, © 2026 KLXM Crossmedia GmbH): Ical/EventParser (Abbildung VEVENT, Zeitzonen, Overrides)
declare(strict_types=1);

namespace Core\Data;

use DateTimeImmutable;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * iCalendar-Import (RFC 5545) in Kalender der Tabellenart calendar – Datei, einmalige Adresse oder Abo (Externe Quellen, Format
 * „ics“, Core\Sources). Mit Probelauf.
 *
 * Abbildung aufs Grundschema: SUMMARY → Titel, DTSTART/DTEND bzw. DURATION → Beginn/Ende (ganztägig bei VALUE=DATE, Ende exklusiv
 * → letzter Tag), Zeitzonen (TZID, UTC, „schwebend“) → Zeitzone der Website, RRULE/EXDATE → Wiederholung, RECURRENCE-ID → abweichender
 * Einzeltermin der Serie (Core\Data\Series), STATUS:CANCELLED → „Fällt aus“, LOCATION → Ort, GEO → Kartenpunkt, DESCRIPTION →
 * Beschreibung (Text), CATEGORIES → Kalender-Kategorien (fehlende werden angelegt, wenn erlaubt). Nicht abbildbare Regeln (z. B.
 * stündlich, RDATE) → Hinweis, der erste Termin bleibt.
 *
 * Abgleich per UID (Spalte _uid; eigene Exporte tragen die UID des CMS): Änderungen aktualisieren den Termin statt einen zweiten
 * anzulegen; Einzeltermine einer Serie, die in der Datei fehlen, gelten wieder als normale Vorkommen. Beim Abo (Quelle) werden
 * Termine, die im Kalender fehlen, nach der Einstellung der Quelle ausgeblendet, gelöscht oder behalten.
 */
final class IcsImport
{
    public const MAX_EVENTS = 5000;

    /**
     * @throws \RuntimeException mit lesbarer Meldung
     */
    public static function read(string $data): VCalendar
    {
        $data = VCards::clean($data);
        if (!preg_match('~^\s*BEGIN:VCALENDAR~i', $data)) throw new \RuntimeException(__('Keine Kalender-Datei (erwartet wird „BEGIN:VCALENDAR“ am Anfang).'));
        try {
            $vc = Reader::read($data, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        } catch (\Throwable $e) {
            throw new \RuntimeException(__('Die Kalender-Datei ist fehlerhaft: {error}', ['error' => mb_strimwidth($e->getMessage(), 0, 160, '…')]));
        }
        if (!$vc instanceof VCalendar) throw new \RuntimeException(__('Keine Kalender-Datei.'));
        if (count($vc->select('VEVENT')) > self::MAX_EVENTS) throw new \RuntimeException(__('Zu viele Termine in der Datei (höchstens {n}).', ['n' => self::MAX_EVENTS]));
        return $vc;
    }

    /**
     * VEVENTs nach UID: [uid => ['master' => ?Component, 'overrides' => list<Component>]] in Reihenfolge der Datei. Termine ohne UID
     * bekommen eine aus Beginn und Titel (stabil bei erneutem Import derselben Datei).
     */
    public static function group(VCalendar $vc): array
    {
        $out = [];
        foreach ($vc->select('VEVENT') as $ev) {
            if (!isset($ev->DTSTART)) continue;
            $uid = mb_substr(trim((string) ($ev->UID ?? '')), 0, 255);
            if ($uid === '') $uid = 'ics-' . substr(sha1((string) $ev->DTSTART . '|' . (string) ($ev->SUMMARY ?? '')), 0, 24);
            $out[$uid] ??= ['master' => null, 'overrides' => []];
            if (isset($ev->{'RECURRENCE-ID'})) $out[$uid]['overrides'][] = $ev;
            elseif ($out[$uid]['master'] === null) $out[$uid]['master'] = $ev;
        }
        return $out;
    }

    private static function txt(mixed $v, int $max = 255): string
    {
        return VCards::txt($v, $max);
    }

    /** Beginn eines VEVENT in Ortszeit der Website + ganztägig? */
    public static function start(Component $ev): array
    {
        $tz = Calendar::tz();
        $s = self::dt($ev, 'DTSTART');
        return [DateTimeImmutable::createFromInterface($s->getDateTime($tz))->setTimezone($tz), !$s->hasTime()];
    }

    /** Datums-Eigenschaft (DTSTART, RECURRENCE-ID) eines VEVENT */
    private static function dt(Component $ev, string $name): \Sabre\VObject\Property\ICalendar\DateTime
    {
        $p = $ev->select($name)[0] ?? null;
        if (!$p instanceof \Sabre\VObject\Property\ICalendar\DateTime) throw new \RuntimeException(__('Termin ohne gültiges Datum ({name}).', ['name' => $name]));
        return $p;
    }

    /** Ursprünglicher Beginn eines Einzeltermins (RECURRENCE-ID) als „JJJJ-MM-TT HH:MM“ in Ortszeit */
    public static function rid(Component $ev): string
    {
        $tz = Calendar::tz();
        $r = self::dt($ev, 'RECURRENCE-ID');
        $d = DateTimeImmutable::createFromInterface($r->getDateTime($tz))->setTimezone($tz);
        return $r->hasTime() ? $d->format('Y-m-d H:i') : $d->format('Y-m-d') . ' 00:00';
    }

    /**
     * VEVENT → Felder des Grundschemas (Art calendar). $warn bekommt Hinweise, $cats die Namen der Kategorien (auch unbekannte).
     */
    public static function fields(array $t, Component $ev, array &$warn, array &$cats): array
    {
        $tz = Calendar::tz();
        [$start, $allDay] = self::start($ev);
        if (isset($ev->DTEND)) $end = DateTimeImmutable::createFromInterface($ev->DTEND->getDateTime($tz))->setTimezone($tz);
        elseif (isset($ev->DURATION)) {
            try {
                $end = $start->add($ev->DURATION->getDateInterval());
            } catch (\Throwable) {
                $end = $start;
            }
        } else $end = $allDay ? $start->modify('+1 day') : $start;
        $in = ['titel' => self::txt($ev->SUMMARY ?? '') ?: __('(ohne Titel)')];
        $in['beginn'] = ($allDay ? $start->setTime(0, 0) : $start)->format('Y-m-d H:i');
        $in['ganztaegig'] = $allDay;
        $last = $allDay ? $end->modify('-1 day') : $end;
        $in['ende'] = ($allDay ? $last->format('Y-m-d') <= $start->format('Y-m-d') : $end <= $start) ? '' : ($allDay ? $last->setTime(0, 0) : $last)->format('Y-m-d H:i');
        // Wiederholung: Regel wie in der Datei, Ausnahmen als Tage in Ortszeit
        $value = '';
        if (isset($ev->RRULE)) {
            $rule = (string) $ev->RRULE->getValue();
            $ex = [];
            foreach ($ev->select('EXDATE') as $x) {
                foreach ($x->getDateTimes($tz) as $d) $ex[] = DateTimeImmutable::createFromInterface($d)->setTimezone($tz)->format('Y-m-d');
            }
            [$norm, $err] = Calendar::normalize($rule . ($ex ? "\nEXDATE:" . implode(',', array_unique($ex)) : ''));
            if ($err === null) $value = $norm;
            else $warn[] = __('Wiederholung „{rule}“ wird nicht unterstützt – nur der erste Termin wird übernommen.', ['rule' => mb_strimwidth($rule, 0, 60, '…')]);
            // Serie in fremder Zeitzone: Zeiten in die Zeitzone der Website umgerechnet (bei Zeitumstellungen kann ein Vorkommen abweichen)
            $ds = self::dt($ev, 'DTSTART');
            $tzid = ($tp = $ds['TZID']) instanceof \Sabre\VObject\Parameter ? (string) $tp->getValue() : (str_ends_with((string) $ds->getValue(), 'Z') ? 'UTC' : '');
            if (!$allDay && $tzid !== '' && $value !== '') {
                $src = $ds->getDateTime()->getTimezone()->getName();
                if ($src !== $tz->getName() && $src !== '') $warn[] = __('Zeitzone {tz} in die Zeitzone der Website ({site}) umgerechnet.', ['tz' => $src, 'site' => $tz->getName()]);
            }
        }
        if (isset($ev->RDATE)) $warn[] = __('Zusätzliche Termine (RDATE) werden nicht übernommen.');
        $in['wiederholung'] = $value;
        if (Tables::field($t, 'ort')) $in['ort'] = self::txt($ev->LOCATION ?? '');
        if (Tables::field($t, 'standort')) {
            $geo = isset($ev->GEO) ? explode(';', (string) $ev->GEO) : [];
            $in['standort'] = count($geo) === 2 && is_numeric(trim($geo[0])) && is_numeric(trim($geo[1])) && abs((float) $geo[0]) <= 90 && abs((float) $geo[1]) <= 180
                ? round((float) $geo[0], 6) . ', ' . round((float) $geo[1], 6) : '';
        }
        if ($bf = Tables::field($t, 'beschreibung')) {
            $txt = self::txt($ev->DESCRIPTION ?? '', 20000);
            $in['beschreibung'] = $bf['type'] === 'richtext'
                ? implode('', array_map(fn($p) => '<p>' . str_replace("\n", '<br>', e($p)) . '</p>', $txt === '' ? [] : (preg_split("~\n{2,}~", $txt) ?: [])))
                : $txt;
        }
        foreach ($ev->select('CATEGORIES') as $c) foreach ($c->getParts() as $p) if (($p = self::txt($p, 80)) !== '') $cats[mb_strtolower($p)] = $p;
        if ($cf = Tables::field($t, 'kategorie')) {
            $keys = VCards::optionKey($cf, array_values($cats));
            $in['kategorie'] = $cf['type'] === 'multiselect' ? $keys : ($keys[0] ?? '');
        }
        if (Tables::field($t, Kinds::CANCELLED_FIELD)) $in[Kinds::CANCELLED_FIELD] = strtoupper(trim((string) ($ev->STATUS ?? ''))) === 'CANCELLED';
        return $in;
    }

    /**
     * Flache Einträge für Externe Quellen (Format ics) in beliebige Tabellen mit Zuordnung: je VEVENT ein Eintrag mit uid,
     * recurrence_id, summary, description, location, geo, start, end (Ortszeit „JJJJ-MM-TT HH:MM“), all_day, rrule, exdate,
     * categories, status, url.
     * @return array{items: list<array>, meta: array}
     */
    public static function items(string $data): array
    {
        $vc = self::read($data);
        $tz = Calendar::tz();
        $items = [];
        foreach (self::group($vc) as $uid => $g) {
            foreach ([$g['master'], ...$g['overrides']] as $ev) {
                if (!$ev) continue;
                [$start, $allDay] = self::start($ev);
                $end = isset($ev->DTEND) ? DateTimeImmutable::createFromInterface($ev->DTEND->getDateTime($tz))->setTimezone($tz) : null;
                $ex = [];
                foreach ($ev->select('EXDATE') as $x) foreach ($x->getDateTimes($tz) as $d) $ex[] = DateTimeImmutable::createFromInterface($d)->setTimezone($tz)->format('Y-m-d');
                $cats = [];
                foreach ($ev->select('CATEGORIES') as $c) foreach ($c->getParts() as $p) $cats[] = self::txt($p, 80);
                $rid = isset($ev->{'RECURRENCE-ID'}) ? self::rid($ev) : '';
                $items[] = ['uid' => $uid . ($rid !== '' ? '#' . $rid : ''), 'recurrence_id' => $rid, 'summary' => self::txt($ev->SUMMARY ?? ''),
                    'description' => self::txt($ev->DESCRIPTION ?? '', 20000), 'location' => self::txt($ev->LOCATION ?? ''),
                    'geo' => isset($ev->GEO) ? str_replace(';', ', ', (string) $ev->GEO) : '', 'start' => $start->format($allDay ? 'Y-m-d' : 'Y-m-d H:i'),
                    'end' => $end ? $end->format($allDay ? 'Y-m-d' : 'Y-m-d H:i') : '', 'all_day' => $allDay ? '1' : '0',
                    'rrule' => isset($ev->RRULE) ? (string) $ev->RRULE->getValue() : '', 'exdate' => implode(',', $ex), 'categories' => implode(', ', array_filter($cats)),
                    'status' => strtoupper(trim((string) ($ev->STATUS ?? ''))), 'url' => preg_match('~^https?://~i', $u = trim((string) ($ev->URL ?? ''))) ? $u : ''];
            }
        }
        return ['items' => $items, 'meta' => ['format' => 'ics', 'title' => self::txt($vc->{'X-WR-CALNAME'} ?? '')]];
    }

    // ================================================================== Import

    /**
     * Kalender-Datei abgleichen. $o: dry (Probelauf), status (published|draft für neue Termine), categories (fehlende Kategorien
     * anlegen), source (Quelle aus Core\Sources – Herkunft je Termin, fehlende nach missing: hide|delete|keep), note (Revision).
     * @return array{ok: bool, error: ?string, total: int, created: int, updated: int, unchanged: int, skipped: int, failed: int, deleted: int,
     *     hidden: int, overrides: int, new_categories: list<string>, missing_categories: list<string>, rows: list<array>, warnings: list<string>}
     */
    public static function import(array $t, string $data, array $o = []): array
    {
        $dry = !empty($o['dry']);
        $src = $o['source'] ?? null;
        $rep = ['ok' => false, 'error' => null, 'total' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'deleted' => 0,
            'hidden' => 0, 'overrides' => 0, 'new_categories' => [], 'missing_categories' => [], 'rows' => [], 'warnings' => []];
        if (!Kinds::isCalendar($t)) {
            $rep['error'] = __('ICS-Import in diese Ansicht gibt es für Kalender (Tabellenart „Kalender“).');
            return $rep;
        }
        try {
            $groups = self::group(self::read($data));
        } catch (\RuntimeException $e) {
            $rep['error'] = $e->getMessage();
            return $rep;
        }
        $rep['total'] = count($groups);
        // Kategorien: fehlende anlegen (im Probelauf nur melden)
        $cf = Tables::field($t, 'kategorie');
        $names = [];
        foreach ($groups as $g) foreach ([$g['master'], ...$g['overrides']] as $ev) if ($ev) foreach ($ev->select('CATEGORIES') as $c) foreach ($c->getParts() as $p) {
            if (($p = self::txt($p, 80)) !== '') $names[mb_strtolower($p)] = $p;
        }
        foreach ($names as $label) {
            if (!$cf || VCards::optionKey($cf, [$label])) continue;
            if (empty($o['categories'])) { $rep['missing_categories'][] = $label; continue; }
            $rep['new_categories'][] = $label;
            if (!$dry) {
                $err = self::addOption($t, 'kategorie', $label);
                if ($err !== null) $rep['warnings'][] = __('Kategorie „{name}“: {error}', ['name' => $label, 'error' => $err]);
                $t = Tables::find($t['handle']) ?? $t;
                $cf = Tables::field($t, 'kategorie');
            }
        }
        $heads = self::uidIndex($t);
        $db = app()->db;
        // Kalender-Abo: Herkunft am Termin (Quelle, Veranstalter, Original-Link) – bleibt auch beim Weitergeben im Netzwerk sichtbar
        if ($src && !$dry) Network::ensureExternal($t);
        $items = [];
        if ($src) foreach ($db->fetchAll('SELECT * FROM ext_source_items WHERE source_id = ?', [(int) $src['id']]) as $r) $items[(string) $r['ext_id']] = $r;
        $seen = [];
        $status = ($o['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
        $note = (string) ($o['note'] ?? __('Import (ICS)'));
        foreach ($groups as $uid => $g) {
            $warn = [];
            $cats = [];
            $ev = $g['master'] ?? $g['overrides'][0];
            $row = ['name' => self::txt($ev->SUMMARY ?? '') ?: __('(ohne Titel)'), 'when' => '', 'action' => '', 'detail' => '', 'warnings' => [], 'series' => false, 'overrides' => count($g['overrides'])];
            try {
                [$start, $allDay] = self::start($ev);
                $row['when'] = $allDay ? \Core\Format::admin()->date($start->getTimestamp()) : \Core\Format::admin()->datetime($start->getTimestamp());
                $item = $items[$uid] ?? null;
                $id = $item ? (int) $item['entry_id'] : ($heads[strtolower($uid)] ?? null);
                $cur = $id ? Entries::find($t, $id) : null;
                if ($cur && Series::isOverride($t, $cur)) $cur = null;
                if ($cur && Tables::isShared($t) && Shared::isForeign($t, $cur)) { $row['action'] = 'skipped'; $row['detail'] = __('Termin einer anderen Website – nur lesbar.'); $rep['skipped']++; continue; }
                if (!$src && $cur && \Core\Sources\Sources::isExternal($t, (int) $cur['id'])) { $row['action'] = 'skipped'; $row['detail'] = __('Termin aus einer externen Quelle – wird dort abgeglichen.'); $rep['skipped']++; continue; }
                if (!$g['master']) {
                    // Nur geänderte Einzeltermine ohne Serie in der Datei: auf eine vorhandene Serie anwenden, sonst als einzelne Termine
                    if ($cur && Series::isHead($t, $cur)) {
                        $seen[$uid] = true;
                        $n = self::applyOverrides($t, $cur, $g['overrides'], false, $dry, $note, $rep, $warn);
                        $row['action'] = $n ? 'updated' : 'unchanged';
                        $row['detail'] = __('{n} Einzeltermine der Serie', ['n' => count($g['overrides'])]);
                        $rep[$n ? 'updated' : 'unchanged']++;
                        continue;
                    }
                    $warn[] = __('Geänderter Einzeltermin ohne seine Serie – als eigener Termin übernommen.');
                }
                $in = self::fields($t, $ev, $warn, $cats);
                if ($src) {
                    $org = isset($ev->ORGANIZER) ? self::txt((string) ($ev->ORGANIZER['CN'] ?? ''), 120) : '';
                    $link = isset($ev->URL) ? trim((string) $ev->URL) : '';
                    $in[Network::EXTERNAL] = Network::externalValue((string) $src['name'], $org, preg_match('~^https?://~i', $link) ? mb_substr($link, 0, 500) : '');
                }
                $row['series'] = $in['wiederholung'] !== '';
                $in[Series::UID] = $uid;
                if (!$g['master']) { $in[Series::UID] = $uid . '#' . self::rid($ev); $id = $heads[strtolower($in[Series::UID])] ?? null; $cur = $id ? Entries::find($t, $id) : null; }
                if ($cur && (string) ($cur[Series::UID] ?? '') === '' && $uid === Calendar::uid($t, ['id' => (int) $cur['id']] + $cur)) unset($in[Series::UID]);
                $seen[$g['master'] ? $uid : $in[Series::UID]] = true;
                $hash = substr(hash('sha256', json_encode([$in, array_map(fn($x) => $x->serialize(), $g['overrides'])]) ?: ''), 0, 64);
                if ($cur) {
                    self::keepOpenEnd($t, $cur, $in);
                    $changes = self::changes($t, $cur, $in);
                    $headId = (int) $cur['id'];
                    if (!$dry && $changes) {
                        [, $err] = Entries::save($t, $headId, $in, $note);
                        if ($err) throw new \RuntimeException(implode(' ', array_map('strval', $err)));
                    }
                    $cur2 = $dry ? array_merge($cur, $in) : (Entries::find($t, $headId) ?? $cur);
                    $ovChanged = $g['master'] ? self::applyOverrides($t, $cur2, $g['overrides'], true, $dry, $note, $rep, $warn) : 0;
                    if ($changes || $ovChanged) {
                        $row['action'] = 'updated';
                        $row['detail'] = implode(', ', [...$changes, ...($ovChanged ? [__('{n} Einzeltermine', ['n' => $ovChanged])] : [])]);
                        $rep['updated']++;
                    } else {
                        $row['action'] = 'unchanged';
                        $rep['unchanged']++;
                    }
                    // wieder aufgetaucht (Abo): nur einblenden, was der Abgleich selbst ausgeblendet hat
                    if (!$dry && $item && (int) $item['hidden_by_sync'] === 1 && ($cur['status'] ?? '') === 'draft') Entries::setStatus($t, [$headId], 'published');
                } else {
                    $row['action'] = 'created';
                    $row['detail'] = trim(($in['ort'] ?? '') . ($row['series'] ? ' · ' . Calendar::describe($in['wiederholung']) : ''), ' ·');
                    $headId = 0;
                    if (!$dry) {
                        [$newId, $err] = Entries::save($t, null, $in + ['status' => $status], $note);
                        if ($err || !$newId) throw new \RuntimeException(implode(' ', array_map('strval', $err)));
                        $headId = (int) $newId;
                        $heads[strtolower((string) $in[Series::UID])] = $headId;
                        if ($g['master'] && ($h = Entries::find($t, $headId))) self::applyOverrides($t, $h, $g['overrides'], true, false, $note, $rep, $warn);
                    } elseif ($g['master']) {
                        $rep['overrides'] += count($g['overrides']);
                    }
                    $rep['created']++;
                }
                if ($src && !$dry && $headId) {
                    $key = $g['master'] ? $uid : (string) $in[Series::UID];
                    $vals = ['entry_id' => $headId, 'hash' => $hash, 'last_seen' => now(), 'state' => 'active', 'hidden_by_sync' => 0, 'table_handle' => $t['handle']];
                    if (isset($items[$key])) $db->update('ext_source_items', $vals, 'id = :id', ['id' => (int) $items[$key]['id']]);
                    else $db->insert('ext_source_items', $vals + ['source_id' => (int) $src['id'], 'ext_id' => $key, 'first_seen' => now()]);
                }
            } catch (\Throwable $ex) {
                $row['action'] = 'failed';
                $row['detail'] = mb_strimwidth($ex->getMessage(), 0, 300, '…');
                $rep['failed']++;
            } finally {
                $row['warnings'] = array_values(array_unique($warn));
                if (count($rep['rows']) < 500) $rep['rows'][] = $row;
            }
        }
        // Abo: Termine, die im Kalender nicht mehr vorkommen (nicht bei leerer Antwort – meist ein Fehler der Quelle)
        if ($src && $groups) {
            $mode = (string) ($o['missing'] ?? 'hide');
            foreach ($items as $ext => $r) {
                if (isset($seen[$ext]) || $mode === 'keep' || $r['state'] !== 'active') continue;
                $e = Entries::find($t, (int) $r['entry_id']);
                if ($mode === 'delete') {
                    if (!$dry) {
                        if ($e) Entries::delete($t, (int) $r['entry_id']);
                        $db->query('DELETE FROM ext_source_items WHERE id = ?', [(int) $r['id']]);
                    }
                    $rep['deleted']++;
                    continue;
                }
                $hidden = 0;
                if ($e && $e['status'] === 'published') {
                    if (!$dry) Entries::setStatus($t, [(int) $e['id']], 'draft');
                    $hidden = 1;
                    $rep['hidden']++;
                }
                if (!$dry) $db->update('ext_source_items', ['state' => 'missing', 'hidden_by_sync' => $hidden], 'id = :id', ['id' => (int) $r['id']]);
            }
        }
        $rep['ok'] = true;
        return $rep;
    }

    /**
     * Einzeltermine (RECURRENCE-ID) einer Serie übernehmen: vorhandene ändern, neue anlegen; $complete (die Serie stand in der Datei):
     * Einzeltermine, die fehlen, löschen (das Vorkommen gilt wieder wie in der Serie). @return int Anzahl Änderungen
     */
    private static function applyOverrides(array $t, array $head, array $ovs, bool $complete, bool $dry, string $note, array &$rep, array &$warn): int
    {
        $have = !$dry || !empty($head['id']) ? Series::overrides($t, (int) $head['id']) : [];
        $n = 0;
        $want = [];
        foreach ($ovs as $ov) {
            $cats = [];
            try {
                $rid = self::rid($ov);
            } catch (\Throwable) {
                continue;
            }
            $want[$rid] = true;
            $in = self::fields($t, $ov, $warn, $cats);
            $in['wiederholung'] = '';
            $in[Series::SERIES] = (int) $head['id'];
            $in[Series::RID] = $rid;
            $cur = $have[$rid] ?? null;
            if ($cur) self::keepOpenEnd($t, $cur, $in);
            if ($cur && !self::changes($t, $cur, $in)) continue;
            $n++;
            $rep['overrides']++;
            if ($dry) continue;
            [, $err] = $cur ? Entries::save($t, (int) $cur['id'], $in, $note)
                : Entries::save($t, null, $in + ['status' => $head['status'] ?? 'published', 'slug' => ($head['slug'] ?? 'termin') . '-' . str_replace('-', '', substr($rid, 0, 10))], $note);
            if ($err) $warn[] = __('Einzeltermin am {date}: {error}', ['date' => substr($rid, 0, 10), 'error' => implode(' ', array_map('strval', $err))]);
        }
        if ($complete) {
            foreach ($have as $rid => $ov) {
                if (isset($want[$rid])) continue;
                $n++;
                if (!$dry) Entries::delete($t, (int) $ov['id']);
            }
        }
        return $n;
    }

    /** UID → Serienkopf bzw. einfacher Termin: gespeicherte UIDs (_uid) und die des CMS (eigener Export) */
    private static function uidIndex(array $t): array
    {
        $out = [];
        foreach (Tables::db($t)->fetchAll('SELECT id, ' . Series::UID . " FROM {$t['table']} WHERE " . Series::SERIES . ' IS NULL OR ' . Series::SERIES . ' = 0') as $r) {
            $u = trim((string) ($r[Series::UID] ?? ''));
            if ($u !== '') $out[strtolower($u)] = (int) $r['id'];
            $out[strtolower(Calendar::uid($t, ['id' => (int) $r['id']]))] ??= (int) $r['id'];
        }
        return $out;
    }

    /**
     * Termin ohne gepflegtes Ende: der Export schreibt DTEND = Beginn + Dauer der Kalender-Einstellung – kommt genau das zurück, bleibt
     * das Ende leer (sonst wäre jeder eigene Export beim erneuten Import eine Änderung).
     */
    private static function keepOpenEnd(array $t, array $cur, array &$in): void
    {
        if (trim((string) ($cur['ende'] ?? '')) !== '' || ($in['ende'] ?? '') === '' || !empty($in['ganztaegig'])) return;
        $s = Calendar::parse($in['beginn'] ?? null);
        if ($s && $s[0]->modify('+' . (int) Calendar::config($t)['duration'] . ' minutes')->format('Y-m-d H:i') === $in['ende']) $in['ende'] = '';
    }

    /** Geänderte Felder (Beschriftungen) */
    private static function changes(array $t, array $cur, array $in): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            $f = Tables::field($t, $k);
            $a = self::norm($f, $cur[$k] ?? null);
            $b = self::norm($f, $v);
            if ($a === $b) continue;
            $out[] = $f ? (string) $f['label'] : ($k === Network::EXTERNAL ? __('Herkunft') : $k);
        }
        return array_values(array_unique($out));
    }

    private static function norm(?array $f, mixed $v): string
    {
        $type = (string) ($f['type'] ?? '');
        if ($type === 'multiselect') {
            $a = array_map('strval', is_array($v) ? $v : (json_decode((string) $v, true) ?: []));
            sort($a);
            return implode(',', $a);
        }
        if ($type === 'bool') return !empty($v) ? '1' : '0';
        if ($type === 'recurrence') return Calendar::normalize((string) $v)[0];
        if ($type === 'relation') return (string) (int) $v;
        return trim(str_replace("\r", '', strip_tags((string) (is_scalar($v) ? $v : ''))));
    }

    /** Auswahl-Option ergänzen (Tabellen-Designer, Recht data.schema prüft der Aufrufer); null = ok, sonst Fehlertext */
    public static function addOption(array $t, string $field, string $label): ?string
    {
        $label = trim(preg_replace('~[\r\n=]+~', ' ', $label) ?? '');
        if ($label === '' || mb_strlen($label) > 80) return __('Bitte einen Namen angeben (höchstens 80 Zeichen).');
        if (isset($t['shared']) && !Shared::isOwner($t)) return __('Die Felder dieser geteilten Tabelle ändert nur die Eigentümer-Website.');
        $in = Tables::toInput($t);
        $found = false;
        foreach ($in['fields'] as &$f) {
            if (($f['name'] ?? '') !== $field) continue;
            $f['options'] = ltrim(rtrim((string) ($f['options'] ?? '')) . "\n" . $label, "\n");
            $found = true;
        }
        unset($f);
        if (!$found) return __('Feld fehlt.');
        [$clean, $errors] = Tables::validate($in, $t);
        if ($errors) return implode(' ', array_map('strval', $errors));
        Tables::update($t, $clean);
        return null;
    }
}
