<?php
declare(strict_types=1);

namespace Core\Data;

use DateTimeImmutable;
use DateTimeZone;
use RRule\RRule;

/**
 * Termin-Aktionen auf Detailseiten von Kalender-Tabellen – Block „Termin-Aktionen“ (event_actions) und „Datensatz-Felder“ in der
 * Darstellung „Kopf“ (Fragment event-actions, vom Kit überschreibbar):
 *
 *   „In den Kalender“  .ics des Termins: GET /kalender/{tabelle}/{slug}.ics, bei Serien ?vorkommen=JJJJMMTTTHHMM (genau dieses Vorkommen)
 *   „Abonnieren“       webcal-Adresse des öffentlichen Feeds (nur mit Feed der Tabelle)
 *   Google Kalender / Outlook.com  reine Links (kein Skript, kein Einbetten) mit Titel, Zeit in UTC, Ort und Beschreibung als Text –
 *                      Standard aus (Datenschutz): Grundeinstellung sys.calendar_links oder je Block.
 *
 * Vorkommen: Serien zeigen das Vorkommen aus ?vorkommen= (abweichender Einzeltermin, falls vorhanden), sonst das nächste.
 * Vorbei → Hinweis statt Knopf (Serie: nächstes Vorkommen als Link), „Fällt aus“ → Hinweis, kein Knopf.
 * Exposure: Ort und Beschreibung nur aus Feldern mit Freigabe für das Ziel „ics“ – wie in der .ics-Datei.
 */
final class EventActions
{
    public const SERVICES = ['google', 'outlook'];

    /** Höchstzahl geprüfter Vorkommen bei der Suche nach dem nächsten Termin einer Serie */
    private const SCAN = 400;

    /** Beschreibung in Fremd-Links kürzen (Adresslänge) */
    private const DETAILS_MAX = 1000;

    /** Auswahl im Block: '' = wie Grundeinstellung, none, google, outlook, both */
    public const CHOICES = ['', 'none', 'google', 'outlook', 'both'];

    /** Fremd-Dienste für eine Auswahl im Block ('' = Grundeinstellung sys.calendar_links, Standard: keine) */
    public static function services(string $choice = ''): array
    {
        return match ($choice) {
            'none' => [],
            'google' => ['google'],
            'outlook' => ['outlook'],
            'both' => self::SERVICES,
            default => array_values(array_intersect(self::SERVICES, array_map('strval', (array) (setting('sys.calendar_links') ?: [])))),
        };
    }

    /**
     * Alles für die Ausgabe. $o: key (Vorkommens-Schlüssel JJJJMMTTTHHMM, Standard ?vorkommen= der Anfrage), subscribe (bool, Standard
     * true), services (Liste, Standard Grundeinstellung), now (Zeitpunkt, für Tests).
     * keyed: Vorkommen kam aus der Adresse, skipped: Datum eines abgesagten Termins vor dem gezeigten (Serie ohne Vorkommen in der Adresse),
     * until: Zeitpunkt, bis zu dem die Ausgabe gilt (Seiten-Cache).
     * @return ?array{state: string, when: string, series: bool, key: string, keyed: bool, ics: ?string, webcal: ?string,
     *                links: list<array{service: string, url: string, label: string}>, next: ?array{when: string, url: ?string}, skipped: ?string, until: ?int}
     */
    public static function forEntry(array $t, array $e, array $o = []): ?array
    {
        if (!Calendar::enabled($t)) return null;
        $now = $o['now'] ?? new DateTimeImmutable('now', Calendar::tz());
        $key = (string) ($o['key'] ?? self::requestKey());
        $series = self::isSeries($t, $e);
        $occ = self::resolve($t, $e, $series ? $key : '', $now);
        $feed = !empty(Calendar::config($t)['feed']);
        $out = ['state' => 'past', 'when' => '', 'series' => $series, 'key' => '', 'keyed' => false, 'ics' => null, 'webcal' => null, 'links' => [], 'next' => null,
            'skipped' => null, 'until' => null];
        if ($feed && ($o['subscribe'] ?? true)) $out['webcal'] = Calendar::feedUrls($t, \Core\Lang::current())['webcal'];
        if ($occ) {
            $out['when'] = Calendar::when($occ);
            $out['key'] = $occ['key'];
            $out['keyed'] = $key !== '' && $occ['key'] === $key;   // Vorkommen aus der Adresse (sonst: nächster Termin)
            $out['state'] = $occ['cancelled'] ? 'cancelled' : ($occ['end'] <= $now ? 'past' : 'open');
        }
        // Serie ohne Vorkommen in der Adresse: fällt der Termin davor aus, darauf hinweisen
        if ($series && $occ && !$out['keyed'] && ($first = self::next($t, $e, $now, null, false)) && $first['cancelled'] && $first['start'] < $occ['start']) {
            $out['skipped'] = Calendar::when($first);
        }
        if ($out['state'] === 'open') {
            $out['until'] = $occ['end']->getTimestamp();   // Seiten-Cache: nach dem Ende neu erzeugen (Hinweis „vorbei“, nächstes Vorkommen)
            if ($feed) $out['ics'] = self::icsUrl($t, $e, $series ? $occ['key'] : '');
            $data = self::eventData($t, $occ, $series ? $e : null);
            foreach (array_intersect(self::SERVICES, (array) ($o['services'] ?? self::services())) as $s) {
                $out['links'][] = ['service' => $s, 'url' => $s === 'google' ? self::googleUrl($data) : self::outlookUrl($data),
                    'label' => $s === 'google' ? 'Google Kalender' : 'Outlook.com'];
            }
        } elseif ($series && ($next = self::next($t, $e, $now, $occ['key'] ?? null, true))) {
            $href = Entries::href($t, $e);
            $out['next'] = ['when' => Calendar::when($next), 'url' => $href !== null ? $href . '?vorkommen=' . rawurlencode($next['key']) : null];
            $out['until'] = $next['start']->getTimestamp();
        }
        if ($out['state'] === 'cancelled' && $occ && $occ['end'] > $now) $out['until'] = $occ['end']->getTimestamp();
        return $out;
    }

    /** Vorkommens-Schlüssel aus der Adresse (?vorkommen=JJJJMMTTTHHMM), sonst '' */
    public static function requestKey(): string
    {
        $k = app()->request?->query['vorkommen'] ?? null;
        return is_string($k) && Series::ridFromKey($k) !== null ? $k : '';
    }

    /**
     * Vorkommen, das die aufgerufene Detailseite zeigt: nur für den Eintrag der Seite (app()->entry), nur Serien, nur mit gültigem
     * ?vorkommen= (Ausnahme/unbekannt → null). Für „Termin“ (_when), Seitentitel und JSON-LD der Detailseite.
     */
    public static function current(array $t, array $e): ?array
    {
        static $memo = [];
        $key = self::requestKey();
        $ctx = app()->entry;
        if ($key === '' || !$ctx || ($ctx['table']['handle'] ?? '') !== ($t['handle'] ?? null) || (int) ($ctx['entry']['id'] ?? 0) !== (int) ($e['id'] ?? -1)) return null;
        $m = ($t['handle'] ?? '') . ':' . (int) $e['id'] . ':' . $key;
        if (!array_key_exists($m, $memo)) {
            $memo[$m] = Calendar::enabled($t) && self::isSeries($t, $e) ? self::at($t, $e, (string) Series::ridFromKey($key)) : null;
        }
        return $memo[$m];
    }

    /**
     * Link eines Vorkommens aus den Blöcken „Kalender“ und „Nächste Termine“: Serien-Vorkommen → Detailseite der Serie mit
     * ?vorkommen=JJJJMMTTTHHMM, abweichende Einzeltermine und einzelne Termine → ihre eigene Detailseite.
     */
    public static function href(array $t, array $o): ?string
    {
        $href = Entries::href($t, $o['entry']);
        if ($href === null || empty($o['series']) || !empty($o['override']) || ($o['rid'] ?? '') === '' || str_contains($href, '://')) return $href;
        return $href . '?vorkommen=' . rawurlencode(Calendar::ridKey((string) $o['rid']));
    }

    /** Wiederkehrender Termin (Wiederholung gesetzt, kein abweichender Einzeltermin)? */
    public static function isSeries(array $t, array $e): bool
    {
        return !Series::isOverride($t, $e) && Calendar::recurrence($t, $e)['rrule'] !== '';
    }

    /**
     * Gezeigtes Vorkommen: einzelner Termin, Vorkommen aus $key (Serie) oder das nächste nicht beendete, nicht abgesagte Vorkommen der Serie.
     * @return ?array{entry: array, start: DateTimeImmutable, end: DateTimeImmutable, all_day: bool, has_end: bool, key: string, cancelled: bool, recurring: bool}
     */
    public static function resolve(array $t, array $e, string $key, DateTimeImmutable $now): ?array
    {
        $span = Calendar::span($t, $e);
        if (!$span) return null;
        if (!self::isSeries($t, $e)) return self::occ($e, $span['start'], $span['end'], $span, '', self::cancelled($t, $e));
        if ($key !== '' && ($rid = Series::ridFromKey($key)) && ($occ = self::at($t, $e, $rid))) return $occ;
        // Ohne (gültiges) Vorkommen: der nächste stattfindende Termin – sind alle abgesagt, der nächste abgesagte
        return self::next($t, $e, $now, null, true) ?? self::next($t, $e, $now, null, false);
    }

    /**
     * Vorkommen einer Serie zum ursprünglichen Beginn („JJJJ-MM-TT HH:MM“): abweichender Einzeltermin (nur veröffentlicht, gleiche Sprache –
     * wie auf der Website und in der öffentlichen .ics), sonst das Vorkommen der Regel; Ausnahme (EXDATE) bzw. kein Vorkommen → null.
     */
    public static function at(array $t, array $head, string $rid): ?array
    {
        $span = Calendar::span($t, $head);
        $at = Series::ridTime($rid);
        if (!$span || !$at || !self::isSeries($t, $head)) return null;
        $key = Calendar::ridKey($rid);
        $ovs = Series::overrides($t, (int) $head['id']);
        if (isset($ovs[$rid])) {
            $ov = $ovs[$rid];
            if (($ov['status'] ?? '') !== 'published' || ($ov['lang'] ?? null) !== ($head['lang'] ?? null)) return null;
            $s = Calendar::span($t, $ov);
            return $s ? self::occ($ov, $s['start'], $s['end'], $s, $key, self::cancelled($t, $ov)) : null;
        }
        if ($span['all_day']) $at = $at->setTime(0, 0);
        if (in_array($at->format('Y-m-d'), Calendar::recurrence($t, $head)['exdates'], true) || !Series::hasOccurrence($t, $head, $rid)) return null;
        return self::occ($head, $at, $at->add($span['start']->diff($span['end'])), $span, $key, self::cancelled($t, $head));
    }

    /** Nächstes Vorkommen einer Serie, das nach $now endet ($open: ohne abgesagte; $skip: Schlüssel überspringen) */
    public static function next(array $t, array $head, DateTimeImmutable $now, ?string $skip, bool $open): ?array
    {
        $span = Calendar::span($t, $head);
        if (!$span || !self::isSeries($t, $head)) return null;
        $dur = $span['start']->diff($span['end']);
        try {
            $rule = new RRule(Calendar::recurrence($t, $head)['rrule'], \DateTime::createFromImmutable($span['start']));
            $starts = $rule->getOccurrencesAfter(\DateTime::createFromImmutable($now->sub($dur)), false, self::SCAN);
        } catch (\Throwable) {
            return null;
        }
        $best = null;
        $found = 0;
        foreach ($starts as $st) {
            $st = DateTimeImmutable::createFromInterface($st)->setTimezone(Calendar::tz());
            $occ = self::at($t, $head, $st->format('Y-m-d H:i'));
            if (!$occ || $occ['end'] <= $now || ($open && $occ['cancelled']) || ($skip !== null && $occ['key'] === $skip)) continue;
            // Einzeltermine können verschoben sein – unter den nächsten Treffern den frühesten nehmen
            if (!$best || $occ['start'] < $best['start']) $best = $occ;
            if (++$found >= 8) break;
        }
        return $best;
    }

    /**
     * Einzelnes Vorkommen einer Serie für die .ics-Datei: [Eintrag mit Beginn/Ende des Vorkommens und ohne Wiederholung, UID]
     * – UID je Vorkommen ({tabelle}-{id}-{schlüssel}@host), damit erneutes Herunterladen denselben Termin aktualisiert.
     * @return ?array{0: array, 1: string}
     */
    public static function icsOccurrence(array $t, array $head, string $key): ?array
    {
        $rid = Series::ridFromKey($key);
        $occ = $rid !== null ? self::at($t, $head, $rid) : null;
        if (!$occ) return null;
        $uid = (string) preg_replace('~@~', '-' . $occ['key'] . '@', Calendar::uid($t, $head), 1);
        $e = $occ['entry'];
        if ((int) $e['id'] === (int) $head['id']) {
            $e = array_replace($e, Series::valuesFor($t, $head, (string) $rid));
            $c = Calendar::config($t);
            if ($c['recurrence'] !== '') $e[$c['recurrence']] = '';
        }
        return [$e, $uid];
    }

    /** Adresse der .ics-Datei (Sprache der Seite, Vorkommen einer Serie) */
    public static function icsUrl(array $t, array $e, string $key = ''): ?string
    {
        if (empty($e['slug'])) return null;
        $q = array_filter(['vorkommen' => $key, 'lang' => \Core\Lang::current() !== \Core\Lang::default() ? \Core\Lang::current() : '']);
        return url('/kalender/' . $t['handle'] . '/' . $e['slug'] . '.ics') . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
    }

    /**
     * Angaben für Fremd-Links: Titel, Beginn/Ende, ganztägig, Ort und Beschreibung als Text (Freigabe „ics“), Adresse der Detailseite.
     * $head: Serienkopf (Link der Detailseite mit ?vorkommen=).
     * @return array{title: string, start: DateTimeImmutable, end: DateTimeImmutable, all_day: bool, location: string, details: string}
     */
    public static function eventData(array $t, array $occ, ?array $head = null): array
    {
        $raw = $occ['entry'];
        if (!empty($raw['id']) && !Series::isOverride($t, $raw)) $raw = Entries::find($t, (int) $raw['id']) ?? $raw;   // nicht die Website-Fassung
        $e = Exposure::publicEntry($t, $raw, 'ics');
        $c = Calendar::config($t);
        $details = $c['description'] !== '' ? ICal::plain(Entries::html($t, $e, $c['description'])) : '';
        $details = \Core\EditorNotes::strip($details);
        if (mb_strlen($details) > self::DETAILS_MAX) $details = rtrim(mb_substr($details, 0, self::DETAILS_MAX - 1)) . '…';
        $url = ($head && $occ['key'] !== '' && ($u = Entries::absUrl($t, $head))) ? $u . '?vorkommen=' . rawurlencode($occ['key']) : Entries::absUrl($t, $head ?? $raw);
        if ($url) $details = trim($details . "\n\n" . $url);
        return ['title' => \Core\EditorNotes::strip(Entries::title($t, $e)), 'start' => $occ['start'], 'end' => $occ['end'], 'all_day' => $occ['all_day'],
            'location' => \Core\EditorNotes::strip(Calendar::place($t, $e)[0]), 'details' => $details];
    }

    /** Google Kalender: Vorlage „Termin anlegen“ (render?action=TEMPLATE), Zeiten in UTC, ganztägig als Datum (Ende exklusiv) */
    public static function googleUrl(array $d): string
    {
        $p = ['action' => 'TEMPLATE', 'text' => $d['title'], 'dates' => self::stamp($d['start'], $d['all_day'], 'Ymd\THis\Z') . '/' . self::stamp($d['end'], $d['all_day'], 'Ymd\THis\Z')];
        if ($d['details'] !== '') $p['details'] = $d['details'];
        if ($d['location'] !== '') $p['location'] = $d['location'];
        return 'https://calendar.google.com/calendar/render?' . http_build_query($p, '', '&', PHP_QUERY_RFC3986);
    }

    /** Outlook.com: Termin verfassen (deeplink/compose), Zeiten in UTC, ganztägig mit allday=true */
    public static function outlookUrl(array $d): string
    {
        $p = ['path' => '/calendar/action/compose', 'rru' => 'addevent', 'subject' => $d['title'],
            'startdt' => self::stamp($d['start'], $d['all_day'], 'Y-m-d\TH:i:s\Z', 'Y-m-d'), 'enddt' => self::stamp($d['end'], $d['all_day'], 'Y-m-d\TH:i:s\Z', 'Y-m-d'),
            'allday' => $d['all_day'] ? 'true' : 'false'];
        if ($d['details'] !== '') $p['body'] = $d['details'];
        if ($d['location'] !== '') $p['location'] = $d['location'];
        return 'https://outlook.live.com/calendar/0/deeplink/compose?' . http_build_query($p, '', '&', PHP_QUERY_RFC3986);
    }

    /** Zeitpunkt in UTC bzw. ganztägig als Datum der Ortszeit */
    private static function stamp(DateTimeImmutable $d, bool $allDay, string $utc, string $date = 'Ymd'): string
    {
        return $allDay ? $d->format($date) : $d->setTimezone(new DateTimeZone('UTC'))->format($utc);
    }

    private static function cancelled(array $t, array $e): bool
    {
        return Kinds::isCalendar($t) && !empty($e[Kinds::CANCELLED_FIELD]);
    }

    private static function occ(array $e, DateTimeImmutable $start, DateTimeImmutable $end, array $span, string $key, bool $cancelled): array
    {
        return ['entry' => $e, 'start' => $start, 'end' => $end, 'all_day' => $span['all_day'], 'has_end' => $span['has_end'], 'key' => $key,
            'cancelled' => $cancelled, 'recurring' => $key !== ''];
    }
}
