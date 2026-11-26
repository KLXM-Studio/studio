<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * „Passend dazu“: zu einem Eintrag die Einträge anderer (und derselben) Tabellen mit gemeinsamen Schlagworten – über ein geteiltes
 * Vokabular (Core\Data\Tags, Feld-Einstellung vocab) bzw. dieselbe Auswahlliste (Core\Data\OptionSource, z. B. Thema). Gewichtung:
 * je gemeinsames Schlagwort 1, je gemeinsame Kategorie 0,5. Termine (Kalender-Tabellen) nur kommende, nach Beginn; sonst neueste.
 * Nur veröffentlichte Einträge mit Detailseite, die Besucher sehen dürfen (Entries::query, Exposure::publicRows).
 * Block „Passend dazu“ (app/Blocks/related.php) – für Detailseiten und Detailseiten-Vorlagen.
 */
final class Related
{
    /**
     * @param list<array{table: string, limit?: int|string, badge?: string}> $sources leer = alle passenden Tabellen (je 3)
     * @return list<array{table: array, entry: array, href: string, badge: string, score: float}>
     */
    public static function items(array $t, array $e, array $sources = [], int $total = 6): array
    {
        $tags = [];   // Vokabular => [klein => Text]
        $cats = [];   // Liste (options_from) => [Kurzname]
        foreach ($t['fields'] as $f) {
            if (!Tags::isFree($f) && (empty($f['options_from']) || !in_array($f['type'], ['select', 'multiselect'], true))) continue;
            $vals = array_values(array_filter(array_map(fn($v) => is_scalar($v) ? (string) $v : '', (array) (is_array($e[$f['name']] ?? null) ? $e[$f['name']] : [$e[$f['name']] ?? ''])), fn($v) => $v !== ''));
            if (!$vals) continue;
            if (Tags::isFree($f)) foreach ($vals as $v) $tags[Tags::vocab($t['handle'], $f)][mb_strtolower($v)] = $v;
            elseif (!empty($f['options_from'])) foreach ($vals as $v) $cats[(string) $f['options_from']][$v] = $v;
        }
        if (!$tags && !$cats) return [];
        $want = [];
        foreach ($sources as $s) if (is_array($s) && ($h = trim((string) ($s['table'] ?? ''))) !== '') $want[$h] = ['limit' => max(1, min(12, (int) ($s['limit'] ?? 3) ?: 3)), 'badge' => trim((string) ($s['badge'] ?? ''))];
        $out = [];
        $now = new \DateTimeImmutable('now');
        foreach (Tables::content() as $ct) {
            if ($want && !isset($want[$ct['handle']])) continue;
            if (empty($ct['settings']['detail_page_id']) || trim((string) ($ct['settings']['route'] ?? ''), '/') === '') continue;
            // passende Felder dieser Tabelle und ihre Bedingungen
            $conds = [];
            $params = [];
            $match = [];   // Feld => ['tags' => [...]] bzw. ['cats' => [...]]
            foreach ($ct['fields'] as $f) {
                if (Tags::isFree($f) && isset($tags[$v = Tags::vocab($ct['handle'], $f)])) {
                    foreach ($tags[$v] as $tag) { $conds[] = "{$f['name']} LIKE ?"; $params[] = '%' . trim((string) json_encode($tag, JSON_UNESCAPED_UNICODE), '"') . '%'; }
                    $match[$f['name']] = ['tags', array_keys($tags[$v])];
                } elseif (!empty($f['options_from']) && isset($cats[(string) $f['options_from']])) {
                    foreach ($cats[(string) $f['options_from']] as $slug) {
                        if ($f['type'] === 'select') { $conds[] = "{$f['name']} = ?"; $params[] = $slug; }
                        else { $conds[] = "{$f['name']} LIKE ?"; $params[] = '%' . json_encode($slug, JSON_UNESCAPED_UNICODE) . '%'; }
                    }
                    $match[$f['name']] = ['cats', array_values($cats[(string) $f['options_from']])];
                }
            }
            if (!$conds) continue;
            try {
                $ids = array_map('intval', array_column(Tables::db($ct)->fetchAll("SELECT id FROM {$ct['table']} WHERE status = 'published' AND (" . implode(' OR ', $conds) . ') LIMIT 400', $params), 'id'));
            } catch (\Throwable) {
                continue;
            }
            if ($ct['handle'] === $t['handle']) $ids = array_values(array_diff($ids, [(int) $e['id']]));
            if (!$ids) continue;
            $rows = Exposure::publicRows($ct, Entries::query($ct, ['status' => 'published', 'ids' => $ids, 'limit' => 400] + (\Core\Lang::multi() ? ['lang' => \Core\Lang::current()] : [])));
            $cal = Calendar::enabled($ct);
            $cand = [];
            foreach ($rows as $r) {
                if ((int) $r['id'] === (int) $e['id'] && $ct['handle'] === $t['handle']) continue;
                $score = 0.0;
                foreach ($match as $name => [$kind, $vals]) {
                    $have = array_map('strval', (array) (is_array($r[$name] ?? null) ? $r[$name] : [$r[$name] ?? '']));
                    if ($kind === 'tags') $score += count(array_intersect(array_map('mb_strtolower', $have), $vals));
                    else $score += 0.5 * count(array_intersect($have, $vals));
                }
                if ($score <= 0) continue;
                $ts = 0;
                if ($cal) {
                    $span = Calendar::span($ct, $r);
                    if (!$span) continue;
                    $end = $span['has_end'] ? $span['end'] : $span['start']->setTime(23, 59);
                    if ($end < $now) continue;   // Termine: nur kommende
                    $ts = -$span['start']->getTimestamp();   // früher Beginn zuerst
                } else {
                    $ts = strtotime((string) ($r['published_at'] ?? '') ?: (string) ($r['created_at'] ?? '')) ?: 0;
                }
                $href = Entries::href($ct, $r);
                if (!$href) continue;
                $cand[] = ['table' => $ct, 'entry' => $r, 'href' => $href, 'badge' => ($want[$ct['handle']]['badge'] ?? '') ?: (string) $ct['name'], 'score' => $score, '_ts' => $ts];
            }
            usort($cand, fn($a, $b) => [$b['score'], $b['_ts']] <=> [$a['score'], $a['_ts']]);
            foreach (array_slice($cand, 0, $want[$ct['handle']]['limit'] ?? 3) as $c) { unset($c['_ts']); $out[] = $c; }
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return $total > 0 ? array_slice($out, 0, $total) : $out;
    }
}
