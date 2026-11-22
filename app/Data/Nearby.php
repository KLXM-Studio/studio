<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Extensions;
use Core\Maps;

/**
 * „In deiner Nähe“ für Personen, Nächste Termine und Datenliste: Besucher geben PLZ oder Ort ein (ohne Standortfreigabe des
 * Browsers), der Block zeigt zuerst Einträge des zuständigen Gebiets (z. B. der Regionalgruppe), danach Einträge im Umkreis.
 *
 * Ort auflösen (locate): Erweiterungen über den Filter „data.nearby“ (z. B. Gebietskarten: Gemeinde/Kreis/Land → Kartenpunkt,
 * zuständige Gebiete als Einträge „tabelle:id“, zuständige Websites), danach – nur wenn eingeschaltet (Grundeinstellung
 * „Orte für ‚In deiner Nähe‘ online suchen“, Standard aus) – die Geokodierung des Cores (Core\Maps::geocode, schickt die Eingabe an
 * OpenStreetMap Nominatim) und noch einmal der Filter mit dem Punkt (Gebiet zum Punkt). Ergebnisse je Eingabe zwischengespeichert.
 *
 * Zuständig ist ein Eintrag, wenn er selbst das Gebiet ist, eine Verknüpfung auf das Gebiet hat (z. B. Termin → Regionalgruppe) oder
 * eine Funktion mit Verknüpfung auf das Gebiet (Kontakt mit Funktion „Ansprechperson“ der Regionalgruppe). Entfernung über das
 * erste Kartenfeld (Typ geo) bzw. den Ort aus der Liste (Kalender). Modus „site“: nur Einträge der zuständigen Websites (z. B. der
 * Verband zeigt „Ansprechpartner in deinem Land“).
 */
final class Nearby
{
    public const PARAM = 'nahe';
    public const SETTING = 'sys.nearby_geocode';
    public const MODES = ['order', 'site'];

    /** Blockfelder (Personen, Nächste Termine, Datenliste) */
    public static function blockFields(): array
    {
        return [
            ['name' => 'nearby', 'label' => '„In deiner Nähe“: Besucher geben PLZ oder Ort ein', 'type' => 'bool', 'default' => false,
                'help' => 'Zuerst Einträge des zuständigen Gebiets (z. B. Regionalgruppe über die Gebietskarte), dann im Umkreis. Ohne Eingabe wie gewohnt.'],
            ['name' => 'nearby_km', 'label' => 'Umkreis (km)', 'type' => 'number', 'default' => 50, 'width' => 'half'],
            ['name' => 'nearby_mode', 'label' => 'Bei einer Eingabe', 'type' => 'select', 'default' => 'order', 'width' => 'half',
                'options' => ['order' => 'Zuständige zuerst, dann im Umkreis', 'site' => 'Nur Einträge der zuständigen Website (Netzwerk)']],
        ];
    }

    /** Eingabe des Besuchers (bereinigt, '' = keine) */
    public static function query(): string
    {
        $q = trim((string) (app()->request?->query[self::PARAM] ?? ''));
        $q = (string) preg_replace('~[^\p{L}\p{N} .,\-/\']~u', '', $q);
        return mb_substr($q, 0, 80);
    }

    /**
     * Ort auflösen. @return array{q: string, point: ?array{lat: float, lng: float}, label: string, regions: list<string>, sites: list<string>}
     */
    public static function locate(string $q): array
    {
        $res = ['q' => $q, 'point' => null, 'label' => '', 'regions' => [], 'sites' => []];
        if (mb_strlen($q) < 2) return $res;
        $file = site()->storage('cache/nearby') . '/' . substr(hash('sha256', mb_strtolower($q)), 0, 32) . '.json';
        if (is_file($file) && filemtime($file) > time() - 30 * 86400) {
            $c = json_decode((string) file_get_contents($file), true);
            if (is_array($c)) return array_replace($res, $c);
        }
        $res = self::filtered($res, $q);
        if ($res['point'] === null && app()->settings->get(self::SETTING, false)) {
            try {
                $hit = Maps::geocode(preg_match('~^\d{5}$~', $q) ? $q . ', Deutschland' : $q)[0] ?? null;
            } catch (\Throwable) {
                $hit = null;
            }
            if ($hit) {
                $res['point'] = ['lat' => (float) $hit['lat'], 'lng' => (float) $hit['lng']];
                $res['label'] = $res['label'] !== '' ? $res['label'] : (string) strtok((string) $hit['label'], ',');
                if (!$res['regions'] && !$res['sites']) $res = self::filtered($res, $q);
            }
        }
        if ($res['point'] !== null || $res['regions'] || $res['sites']) {
            @mkdir(dirname($file), 0770, true);
            @file_put_contents($file, json_encode($res, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        return $res;
    }

    private static function filtered(array $res, string $q): array
    {
        if (!Extensions::filters('data.nearby')) return $res;
        $out = Extensions::filter('data.nearby', $res, ['q' => $q, 'point' => $res['point']]);
        if (!is_array($out)) return $res;
        $p = $out['point'] ?? null;
        $res['point'] = is_array($p) && isset($p['lat'], $p['lng']) ? ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']] : $res['point'];
        $res['label'] = mb_substr(trim(strip_tags((string) ($out['label'] ?? $res['label']))), 0, 120);
        $res['regions'] = array_values(array_unique(array_filter(array_map('strval', (array) ($out['regions'] ?? [])), fn($r) => preg_match('~^[a-z][a-z0-9_]{0,63}:\d+$~', $r) === 1)));
        $res['sites'] = array_values(array_unique(array_filter(array_map('strval', (array) ($out['sites'] ?? [])), fn($s) => preg_match('~^[a-z0-9_-]{1,40}$~', $s) === 1)));
        return $res;
    }

    /** Kartenpunkt eines Eintrags: erstes Kartenfeld, sonst der Ort aus der Liste (ort_ref → Orte-Tabelle) */
    public static function point(array $t, array $e): ?array
    {
        foreach ($t['fields'] as $f) {
            if ($f['type'] !== 'geo') continue;
            if ($p = Maps::parse($e[$f['name']] ?? null)) return ['lat' => (float) $p[0], 'lng' => (float) $p[1]];
        }
        if (Kinds::isCalendar($t) && ($rf = Tables::field($t, 'ort_ref')) && (int) ($e['ort_ref'] ?? 0) > 0 && ($pt = Tables::findContent((string) ($rf['target'] ?? '')))) {
            $pl = Entries::find($pt, (int) $e['ort_ref']);
            if ($pl) return self::point($pt, $pl);
        }
        return null;
    }

    /** Entfernung in km (Haversine) */
    public static function km(array $a, array $b): float
    {
        $r = 6371.0;
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);
        $x = sin($dLat / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLng / 2) ** 2;
        return round($r * 2 * atan2(sqrt($x), sqrt(1 - $x)), 1);
    }

    /** Gehört der Eintrag zu einem der Gebiete („tabelle:id“)? – selbst, über eine Verknüpfung oder eine Funktion mit Verknüpfung */
    public static function inRegion(array $t, array $e, array $regions): bool
    {
        if (!$regions) return false;
        $set = array_flip($regions);
        if (isset($set[$t['handle'] . ':' . (int) $e['id']])) return true;
        foreach ($t['fields'] as $f) {
            if ($f['type'] === 'relation' && (int) ($e[$f['name']] ?? 0) > 0 && isset($set[($f['target'] ?? '') . ':' . (int) $e[$f['name']]])) return true;
            if ($f['type'] === 'relations') foreach ((array) ($e[$f['name']] ?? []) as $id) if (isset($set[($f['target'] ?? '') . ':' . (int) $id])) return true;
            if ($f['type'] === Roles::TYPE) {
                foreach (Roles::current(Roles::items($e[$f['name']] ?? null)) as $i) {
                    if (preg_match('~^entry:([a-z][a-z0-9_]*):(\d+)$~', (string) ($i['ref'] ?? ''), $m) && isset($set[$m[1] . ':' . $m[2]])) return true;
                }
            }
        }
        return false;
    }

    /**
     * Einträge bzw. Vorkommen ordnen: zuständige zuerst (Reihenfolge bleibt), dann im Umkreis (nach Entfernung); übrige fallen weg.
     * $items: Einträge oder Vorkommen (['entry' => …]); Rückgabe in gleicher Form, je Element 'nearby' => [km, region].
     */
    public static function arrange(array $t, array $items, array $loc, array $d): array
    {
        $km = max(1.0, min(1000.0, (float) ($d['nearby_km'] ?? 50) ?: 50.0));
        $mode = in_array($d['nearby_mode'] ?? '', self::MODES, true) ? (string) $d['nearby_mode'] : 'order';
        $region = [];
        $near = [];
        foreach ($items as $it) {
            $e = isset($it['entry']) && is_array($it['entry']) ? $it['entry'] : $it;
            if ($mode === 'site') {
                if ($loc['sites'] && in_array((string) ($e['origin_site'] ?? site()->key), $loc['sites'], true)) $region[] = $it;
                continue;
            }
            if (self::inRegion($t, $e, $loc['regions'])) { $region[] = $it; continue; }
            if ($loc['point'] !== null && ($p = self::point($t, $e)) && ($dist = self::km($loc['point'], $p)) <= $km) $near[] = [$dist, $it];
        }
        usort($near, fn($a, $b) => $a[0] <=> $b[0]);
        return [...$region, ...array_column($near, 1)];
    }

    /** Eingabeformular über der Liste (ohne JavaScript, GET) samt Hinweis zum Ergebnis */
    public static function form(array $d, ?array $loc, int $count): string
    {
        app()->theme->markUsed('@nearby');
        $q = $loc['q'] ?? '';
        static $n = 0;
        $id = 'nb-' . substr(hash('crc32b', (string) json_encode([$d['table'] ?? '', $d['title'] ?? ''])), 0, 6) . '-' . (++$n);   // je Block eindeutig (Landmarke über das Etikett)
        $h = '<form class="nearby" method="get" role="search" aria-labelledby="' . e($id) . '-l">'
            . '<label class="nearby__label" id="' . e($id) . '-l" for="' . e($id) . '">' . e(lt('In deiner Nähe – PLZ oder Ort')) . (!empty($d['title']) ? '<span class="nearby__sr"> (' . e(strip_emphasis((string) $d['title'])) . ')</span>' : '') . '</label>'
            . '<span class="nearby__row"><input class="nearby__input" id="' . e($id) . '" type="search" name="' . self::PARAM . '" value="' . e($q) . '" maxlength="80" autocomplete="postal-code" inputmode="text">'
            . '<button class="nearby__btn" type="submit">' . e(lt('Suchen')) . '</button></span></form>';
        if ($q !== '') {
            $where = $loc && $loc['label'] !== '' ? $loc['label'] : $q;
            $msg = !$loc || ($loc['point'] === null && !$loc['regions'] && !$loc['sites']) ? lt('„{ort}“ wurde nicht gefunden – es werden alle Einträge gezeigt.', ['ort' => $q])
                : ($count ? lt('In der Nähe von {ort}: {n}', ['ort' => $where, 'n' => $count]) : lt('In der Nähe von {ort} gibt es keine Einträge.', ['ort' => $where]));
            $h .= '<p class="nearby__note" role="status">' . e($msg) . ' <a href="?">' . e(lt('Alle zeigen')) . '</a></p>';
        }
        return $h;
    }

    /** Für den Block: Ort zur Eingabe (null = keine Eingabe bzw. Block ohne „In deiner Nähe“) */
    public static function forBlock(array $d): ?array
    {
        if (empty($d['nearby']) || ($q = self::query()) === '') return null;
        return self::locate($q);
    }

    /** Wurde der Ort gefunden? (sonst bleibt die Liste ungefiltert) */
    public static function found(?array $loc): bool
    {
        return $loc !== null && ($loc['point'] !== null || $loc['regions'] || $loc['sites']);
    }
}
