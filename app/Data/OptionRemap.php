<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * Auswahllisten im Tabellen-Schema: Wird eine Option (select, multiselect) entfernt – oder ändert sich ihr Schlüssel –, die Einträge
 * noch haben, fragt das Speichern nach: „N Einträge haben ‚X‘: neu zuordnen zu [Option] / leer lassen“ – statt verwaister Werte.
 *  - removed(): je Feld (Zuordnung über die Feld-ID, Umbenennen des Feldes eingeschlossen) die benutzten, entfernten Optionen.
 *  - apply(): nach Tables::update die Werte umschreiben ('' = leer lassen). Geteilte Tabellen: nur eigene Einträge.
 * Aufruf: DataController::saveSchema (Tabellen-Designer und Seitenleiste „Felder bearbeiten“), Eingabe optmap[feld-id][schlüssel].
 */
final class OptionRemap
{
    /**
     * @return list<array{id: string, name: string, label: string, type: string, key: string, option: string, count: int, choices: array<string, string>}>
     */
    public static function removed(array $t, array $def): array
    {
        if (Tables::isInbox($t)) return [];
        $new = [];
        foreach ($def['fields'] as $f) $new[(string) ($f['id'] ?? '')] = $f;
        $out = [];
        foreach ($t['fields'] as $old) {
            if (!in_array($old['type'], ['select', 'multiselect'], true) || empty($old['options'])) continue;
            $nf = $new[(string) ($old['id'] ?? '')] ?? null;
            if (!$nf || ($nf['type'] ?? '') !== $old['type']) continue;
            if (!empty($nf['free']) || !empty($old['free'])) continue;   // Schlagworte: Werte bleiben, Umstellung über Tags::keysToLabels   // Feld weg oder andere Art: eigene Rückfrage bzw. Umwandlung
            // Optionen aus einer Tabelle (Core\Data\OptionSource): deren Kurznamen gelten
            $newOpts = !empty($nf['options_from']) ? OptionSource::load((string) $nf['options_from'])[0] : (array) ($nf['options'] ?? []);
            if (!empty($nf['options_from']) && ($old['options_from'] ?? '') === $nf['options_from']) continue;
            $keep = array_map('strval', array_keys($newOpts));
            foreach (array_keys((array) $old['options']) as $k) {
                $k = (string) $k;
                if (in_array($k, $keep, true)) continue;
                $n = self::count($t, $old, $k);
                if ($n < 1) continue;
                $out[] = ['id' => (string) $old['id'], 'name' => (string) $old['name'], 'label' => (string) ($old['label'] ?? $old['name']), 'type' => $old['type'],
                    'key' => $k, 'option' => Tables::optionLabel($old, $k), 'count' => $n, 'choices' => array_map('strval', $newOpts)];
            }
        }
        return $out;
    }

    private static function own(array $t): array
    {
        return Tables::isShared($t) ? [' AND origin_site = ?', [site()->key]] : ['', []];
    }

    public static function count(array $t, array $f, string $key): int
    {
        [$w, $p] = self::own($t);
        $col = $f['name'];
        return $f['type'] === 'select'
            ? (int) Tables::db($t)->fetchValue("SELECT COUNT(*) FROM {$t['table']} WHERE {$col} = ?$w", [$key, ...$p])
            : (int) Tables::db($t)->fetchValue("SELECT COUNT(*) FROM {$t['table']} WHERE {$col} LIKE ?$w", ['%' . json_encode($key, JSON_UNESCAPED_UNICODE) . '%', ...$p]);
    }

    /**
     * Werte umschreiben. $t = Tabelle NACH dem Speichern, $removed aus removed(), $map[feld-id][schlüssel] = neuer Schlüssel ('' = leer).
     * @return int geänderte Einträge
     */
    public static function apply(array $t, array $removed, array $map): int
    {
        $byId = [];
        foreach ($t['fields'] as $f) $byId[(string) ($f['id'] ?? '')] = $f;
        $db = Tables::db($t);
        [$w, $p] = self::own($t);
        $n = 0;
        foreach ($removed as $r) {
            $f = $byId[$r['id']] ?? null;
            if (!$f) continue;
            $to = (string) ($map[$r['id']][$r['key']] ?? '');
            if ($to !== '' && !array_key_exists($to, (array) ($f['options'] ?? []))) $to = '';
            $col = $f['name'];
            if ($f['type'] === 'select') {
                $n += $db->query("UPDATE {$t['table']} SET {$col} = ? WHERE {$col} = ?$w", [$to === '' ? null : $to, $r['key'], ...$p])->rowCount();
                continue;
            }
            foreach ($db->fetchAll("SELECT id, {$col} AS v FROM {$t['table']} WHERE {$col} LIKE ?$w", ['%' . json_encode($r['key'], JSON_UNESCAPED_UNICODE) . '%', ...$p]) as $row) {
                $vals = array_map('strval', (array) (json_decode((string) $row['v'], true) ?: []));
                if (!in_array($r['key'], $vals, true)) continue;
                $vals = array_values(array_unique(array_filter(array_map(fn($v) => $v === $r['key'] ? $to : $v, $vals), fn($v) => $v !== '')));
                $db->query("UPDATE {$t['table']} SET {$col} = ? WHERE id = ?", [json_encode($vals, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
                $n++;
            }
        }
        if ($n) Entries::changed($t);
        return $n;
    }

    /** Rückfrage als Text (Fehler „_opts“) */
    public static function message(array $removed): string
    {
        return __('Diese Auswahl-Optionen werden noch benutzt. Bitte wählen Sie, was mit den Einträgen geschehen soll, und speichern Sie erneut.');
    }
}
