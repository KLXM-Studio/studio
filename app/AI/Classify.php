<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\AI;

use Core\Data\Entries;
use Core\Data\Tables;
use Core\Data\Tags;
use Core\Lang;

/**
 * KI-Vorschläge für Schlagworte und Kategorien eines Eintrags: aus Titel und Text je passendem Feld – Auswahl (eine), Mehrfachauswahl
 * (auch aus einer Liste, Core\Data\OptionSource) und freie Schlagworte (Core\Data\Tags, vorhandene bevorzugt, neue markiert).
 * Nichts wird automatisch gesetzt: Formular (Chips, ai/_entry.php) bzw. Prüfliste für den Bestand (/admin/data/{tabelle}/ki-vorschlaege).
 * Je Tabelle abschaltbar (settings.ai_classify = false).
 */
final class Classify
{
    /** Felder, für die Vorschläge taugen @return list<array{name: string, label: string, kind: string, options: array<string, string>}> */
    public static function fields(array $t): array
    {
        if (array_key_exists('ai_classify', $t['settings'] ?? []) && $t['settings']['ai_classify'] === false) return [];
        if (in_array($t['settings']['purpose'] ?? '', ['registration'], true) || Tables::isInbox($t) || \Core\Data\Kinds::isContacts($t)) return [];   // Anmeldungen, Eingänge: keine Inhalte zum Einordnen
        $out = [];
        foreach ($t['fields'] as $f) {
            if (!empty($f['legacy']) || !in_array($f['type'], ['select', 'multiselect'], true)) continue;
            if (Tags::isFree($f)) {
                $vals = Tags::values(Tags::vocab($t['handle'], $f));
                arsort($vals);
                $keys = array_slice(array_keys($vals), 0, 150);
                $out[] = ['name' => $f['name'], 'label' => (string) $f['label'], 'kind' => 'tags', 'options' => $keys ? array_combine($keys, $keys) : []];
                continue;
            }
            $opts = array_map('strval', (array) ($f['options'] ?? []));
            if (count($opts) < 2 || count($opts) > 150) continue;
            $out[] = ['name' => $f['name'], 'label' => (string) $f['label'], 'kind' => $f['type'] === 'select' ? 'one' : 'many', 'options' => $opts];
        }
        return $out;
    }

    public static function enabled(array $t): bool
    {
        return self::fields($t) !== [] && Assist::available('text');
    }

    /**
     * Vorschläge. @return array{fields: list<array{name: string, label: string, kind: string, suggestions: list<array{value: string, label: string, new: bool, set: bool}>}>, model: string, ms: int}
     */
    public static function suggest(array $t, string $title, string $text, string $lang, ?array $e = null): array
    {
        $fields = self::fields($t);
        if (!$fields) throw new AiException(__('Diese Tabelle hat keine Felder für Schlagworte oder Kategorien.'));
        if (trim($title . $text) === '') throw new AiException(__('Es gibt noch keinen Text, aus dem Vorschläge entstehen können.'));
        $pack = Prompts::classify($title, mb_substr($text, 0, 6000), $fields, Assist::ctx() + ['language' => $lang]);
        $fake = [];
        foreach ($fields as $f) $fake[$f['name']] = $f['kind'] === 'tags' ? ['Testschlagwort'] : array_slice(array_keys($f['options']), 0, 1);
        $r = Assist::call('text', $pack, (string) json_encode($fake, JSON_UNESCAPED_UNICODE));
        $d = Assist::json($r['text']);
        $out = [];
        foreach ($fields as $f) {
            $cur = $e ? array_map('strval', (array) (is_array($e[$f['name']] ?? null) ? $e[$f['name']] : [$e[$f['name']] ?? ''])) : [];
            $raw = array_values(array_filter(array_map(fn($x) => trim(strip_tags((string) $x)), (array) (self::pick($d, $f) ?? [])), fn($x) => $x !== ''));
            $sug = [];
            if ($f['kind'] === 'tags') {
                $have = [];
                foreach (array_keys($f['options']) as $k) $have[mb_strtolower($k)] = $k;
                $new = 0;
                foreach (array_slice($raw, 0, 8) as $x) {
                    $x = Tags::norm($x);
                    $known = $have[mb_strtolower($x)] ?? null;
                    if ($known === null) { if (++$new > 2) continue; }
                    $v = $known ?? $x;
                    $sug[mb_strtolower($v)] = ['value' => $v, 'label' => $v, 'new' => $known === null, 'set' => in_array($v, $cur, true)];
                }
            } else {
                $byLabel = [];
                foreach ($f['options'] as $k => $l) $byLabel[mb_strtolower($l)] = (string) $k;
                foreach ($raw as $x) {
                    $k = array_key_exists($x, $f['options']) ? $x : ($byLabel[mb_strtolower($x)] ?? null);
                    if ($k === null) continue;
                    $sug[$k] = ['value' => $k, 'label' => $f['options'][$k], 'new' => false, 'set' => in_array($k, $cur, true)];
                    if ($f['kind'] === 'one') break;
                }
            }
            $out[] = ['name' => $f['name'], 'label' => $f['label'], 'kind' => $f['kind'], 'suggestions' => array_values($sug)];
        }
        return ['fields' => $out, 'model' => $r['model'], 'ms' => $r['ms']];
    }

    /** Antwort zu einem Feld – auch bei leicht abweichendem Schlüssel (kleine Modelle: „themata“ statt „thema“, Beschriftung statt Name) */
    private static function pick(array $d, array $f): mixed
    {
        if (array_key_exists($f['name'], $d)) return $d[$f['name']];
        $best = null;
        $bd = 4;
        foreach ($d as $k => $v) {
            $k = mb_strtolower((string) $k);
            if ($k === mb_strtolower($f['label'])) return $v;
            $dist = levenshtein($k, $f['name']);
            if ($dist < $bd || str_starts_with($k, $f['name'])) { $best = $v; $bd = min($dist, $bd); }
        }
        return $best;
    }

    /** Für einen gespeicherten Eintrag */
    public static function forEntry(array $t, array $e): array
    {
        return self::suggest($t, Entries::title($t, $e), Assist::entryText($t, $e), Lang::norm($e['lang'] ?? null), $e);
    }

    /**
     * Übernehmen (Prüfliste): $pick = [feld => [Werte]] → zu den vorhandenen hinzufügen (Auswahl: ersetzen). @return ?string Fehler
     */
    public static function apply(array $t, int $id, array $pick): ?string
    {
        $e = Entries::find($t, $id);
        if (!$e) return __('Eintrag nicht gefunden.');
        $valid = array_column(self::fields($t), null, 'name');
        $in = [];
        foreach ($pick as $name => $vals) {
            $f = $valid[(string) $name] ?? null;
            $vals = array_values(array_filter(array_map('strval', (array) $vals), fn($v) => $v !== ''));
            if (!$f || !$vals) continue;
            if ($f['kind'] === 'one') { $in[$f['name']] = $vals[0]; continue; }
            $cur = array_map('strval', (array) ($e[$f['name']] ?? []));
            $in[$f['name']] = array_values(array_unique([...$cur, ...$vals]));
        }
        if (!$in) return null;
        [, $errs] = Entries::save($t, $id, $in, __('Schlagworte/Kategorien aus KI-Vorschlag übernommen'));
        return $errs ? implode(' ', array_map('strval', $errs)) : null;
    }
}
