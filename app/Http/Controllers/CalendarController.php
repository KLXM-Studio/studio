<?php
declare(strict_types=1);

namespace Core\Http\Controllers;

use Core\Data\Calendar;
use Core\Data\Entries;
use Core\Data\EventActions;
use Core\Data\ICal;
use Core\Data\Tables;
use Core\Features;
use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Lang;

/**
 * Öffentliche iCal-Dateien (nur veröffentlichte Einträge, nur Tabellen mit Kalender und aktivem Feed):
 *   GET /kalender/{handle}.ics            Feed zum Abonnieren (?lang=en für eine weitere Sprache)
 *   GET /kalender/{handle}/{slug}.ics     einzelner Termin zum Herunterladen (Serie: ganze Serie; ?vorkommen=JJJJMMTTTHHMM: nur dieses
 *                                         Vorkommen bzw. sein abweichender Einzeltermin – Core\Data\EventActions, unbekannt → 404)
 */
final class CalendarController
{
    private function table(Request $r, string $handle): array
    {
        if (!Features::on('calendar', false)) throw new HttpException(404);
        $t = Tables::find($handle);
        if (!$t || !Calendar::enabled($t) || !Calendar::config($t)['feed']) throw new HttpException(404);
        $lang = strtolower((string) ($r->query['lang'] ?? ''));
        if ($lang !== '' && Lang::valid($lang)) app()->lang = $lang;
        return $t;
    }

    public function feed(Request $r, string $handle): Response
    {
        $t = $this->table($r, $handle);
        $c = Calendar::config($t);
        $entries = Entries::query($t, ['status' => 'published', 'sort' => $c['start'], 'dir' => 'asc', 'limit' => 2000]);
        $name = $t['name'] . ' – ' . site_name();
        return $this->ics(ICal::calendar($t, $entries, ['publish' => true, 'name' => $name]), $t['handle'] . '.ics', false);
    }

    public function event(Request $r, string $handle, string $slug): Response
    {
        $t = $this->table($r, $handle);
        $e = Entries::bySlug($t, $slug, true, Lang::current(), 'site') ?? throw new HttpException(404);
        if (!Calendar::span($t, $e)) throw new HttpException(404);
        $key = is_string($k = $r->query['vorkommen'] ?? null) ? $k : '';
        if ($key !== '') {
            if (!EventActions::isSeries($t, $e)) throw new HttpException(404);
            [$one, $uid] = EventActions::icsOccurrence($t, $e, $key) ?? throw new HttpException(404);
            $url = ($u = Entries::absUrl($t, $e)) ? $u . '?vorkommen=' . rawurlencode($key) : null;
            return $this->ics(ICal::calendar($t, [$one], ['publish' => true, 'name' => Entries::title($t, $e), 'single' => true, 'uid' => $uid, 'url' => $url]),
                $t['handle'] . '-' . $e['slug'] . '-' . $key . '.ics', true);
        }
        return $this->ics(ICal::calendar($t, [$e], ['publish' => true, 'name' => Entries::title($t, $e)]), $t['handle'] . '-' . $e['slug'] . '.ics', true);
    }

    private function ics(string $body, string $file, bool $download): Response
    {
        return new Response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="' . preg_replace('~[^a-z0-9._-]+~i', '-', $file) . '"',
            'Cache-Control' => 'public, max-age=900',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
