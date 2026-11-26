<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

use Core\Fields;

/**
 * Einträge aus CSV/XLSX importieren (Core\Data\Tabular) – Recht data.import. Ablauf wie beim vCard-/ICS-Import: Datei → Zuordnung
 * und Probelauf (Fehler je Zeile, nichts gespeichert) → Bestätigen.
 *
 *  - Zuordnung: Spaltenkopf = Feldname oder Beschriftung (ohne Groß/klein), „ID“, „Adresse (Slug)“, „Status“ – änderbar.
 *  - Modus: new (immer neu anlegen) | update (nur vorhandene ändern) | both (vorhandene ändern, übrige neu) – Abgleich über ID,
 *    Adresse oder ein gewähltes Feld.
 *  - Werte: Auswahl per Beschriftung oder Schlüssel, Mehrfachauswahl/Verknüpfungen mehrere mit „;“, Verknüpfung per Titel oder ID,
 *    Ja/Nein (ja/nein/1/0/x), Datum (TT.MM.JJJJ, JJJJ-MM-TT, Excel-Zahl). Bilder/Dateien werden nicht importiert (Hinweis).
 *  - Neue Einträge starten offline, außer die Datei hat „Status“ = Online und die Rolle darf veröffentlichen.
 *  - Vorher: Sicherung der betroffenen Einträge als JSON (storage/backups/import-…); geänderte Einträge haben zusätzlich Versionen.
 */
final class TableImport
{
    public const MODES = ['new', 'update', 'both'];
    private const SKIP = ['section', 'note', 'blocks', 'repeater', 'group', 'secret', 'hotspots', 'mediatext', 'collection', 'media', 'file'];

    /** Ziele der Zuordnung [schlüssel => Beschriftung] */
    public static function targets(array $t): array
    {
        $out = ['' => __('– nicht importieren –'), '_id' => __('ID'), 'slug' => __('Adresse (Slug)'), 'status' => __('Status')];
        foreach ($t['fields'] as $f) {
            // Kombifelder mit mehreren Angaben (Telefon, E-Mail, Adresse …) lassen sich aus einer Zelle nicht sicher lesen
            if (in_array($f['type'], self::SKIP, true) || Tables::isLayout($f) || !empty($f['legacy']) || \Core\Combo::isMulti((string) $f['type'])) continue;
            $out[(string) $f['name']] = (string) $f['label'];
        }
        return $out;
    }

    /** Automatische Zuordnung je Spalte @param list<string> $head @return list<string> */
    public static function guess(array $t, array $head): array
    {
        $map = [];
        $targets = self::targets($t);
        $norm = fn(string $s) => mb_strtolower(trim((string) preg_replace('~\s+~u', ' ', $s)));
        foreach ($head as $h) {
            $hn = $norm((string) $h);
            $hit = '';
            foreach ($targets as $k => $l) {
                if ($k === '') continue;
                if ($hn === $norm((string) $k) || $hn === $norm((string) $l)) { $hit = (string) $k; break; }
            }
            if ($hit === '' && in_array($hn, ['id', 'nr'], true)) $hit = '_id';
            if ($hit === '' && in_array($hn, ['slug', 'adresse'], true)) $hit = 'slug';
            $map[] = in_array($hit, $map, true) ? '' : $hit;
        }
        return $map;
    }

    /**
     * Probelauf bzw. Import. $o: map (Liste wie Spalten), mode, match ('_id' | 'slug' | Feldname), dry (bool)
     * @return array{new: int, updated: int, skipped: int, errors: list<array{row: int, msg: string}>, notes: list<string>, preview: list<array>}
     */
    public static function run(array $t, array $rows, array $o): array
    {
        $map = array_values((array) ($o['map'] ?? []));
        $mode = in_array($o['mode'] ?? '', self::MODES, true) ? (string) $o['mode'] : 'both';
        $match = (string) ($o['match'] ?? '_id');
        $dry = !empty($o['dry']);
        $canPub = can('data.publish', $t['handle']);
        $res = ['new' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'notes' => [], 'preview' => []];
        $head = array_shift($rows) ?? [];
        // Bilder/Dateien: nicht importierbar – Hinweis, wenn eine Spalte so heißt
        foreach ($t['fields'] as $f) {
            if (\Core\Combo::isMulti((string) $f['type']) && in_array(mb_strtolower((string) $f['label']), array_map('mb_strtolower', array_map('strval', $head)), true)) {
                $res['notes'][] = __('Spalte „{label}“: Felder mit mehreren Angaben werden nicht importiert – bitte im Eintrag pflegen.', ['label' => $f['label']]);
            }
            if (in_array($f['type'], ['media', 'file'], true) && in_array(mb_strtolower((string) $f['label']), array_map('mb_strtolower', array_map('strval', $head)), true)) {
                $res['notes'][] = __('Spalte „{label}“: Bilder und Dateien werden nicht importiert – bitte danach in der Mediathek wählen.', ['label' => $f['label']]);
            }
        }
        $lookup = self::matchIndex($t, $match);
        $backup = [];
        foreach ($rows as $i => $r) {
            $line = $i + 2;   // Zeilennummer in der Datei (Kopfzeile = 1)
            if (!array_filter($r, fn($v) => trim((string) $v) !== '')) continue;
            $in = [];
            $errs = [];
            $key = '';
            foreach ($map as $ci => $target) {
                if ($target === '' || !array_key_exists($ci, $r)) continue;
                $raw = trim((string) $r[$ci]);
                if ($target === '_id') { $key = $match === '_id' ? $raw : $key; continue; }
                if ($target === $match) $key = $raw;
                if ($target === 'slug') { if ($raw !== '') $in['slug'] = $raw; continue; }
                if ($target === 'status') { $in['status'] = in_array(mb_strtolower($raw), ['online', 'published', 'veröffentlicht', 'ja', '1'], true) ? 'published' : 'draft'; continue; }
                $f = Tables::field($t, $target);
                if (!$f) continue;
                [$val, $err] = self::convert($f, $raw);
                if ($err !== null) $errs[] = $f['label'] . ': ' . $err;
                elseif ($val !== null) $in[$target] = $val;
            }
            if (isset($in['status']) && $in['status'] === 'published' && !$canPub) $in['status'] = 'draft';
            $id = $key !== '' ? ($lookup[mb_strtolower($key)] ?? null) : null;
            if ($id === null && $mode === 'update') { $res['skipped']++; $res['preview'][] = ['row' => $line, 'action' => 'skip', 'title' => $key]; continue; }
            if ($id !== null && $mode === 'new') $id = null;
            $current = $id !== null ? Entries::find($t, $id) : null;
            if ($current === null) $in['status'] ??= 'draft';   // neu: offline, außer „Status“ sagt Online und die Rolle darf veröffentlichen
            if (!$errs) {
                [, $verrs] = Fields::sanitize(Entries::schema($t, $in + ($current ?? [])), $in, $current ?? []);
                foreach ($verrs as $vk => $vm) $errs[] = (is_string($vk) && ($vf = Tables::field($t, $vk)) ? $vf['label'] . ': ' : '') . $vm;
            }
            if ($errs) { foreach ($errs as $m) $res['errors'][] = ['row' => $line, 'msg' => $m]; continue; }
            $res['preview'][] = ['row' => $line, 'action' => $current ? 'update' : 'new', 'title' => Entries::title($t, $in + ($current ?? ['id' => 0]))];
            if ($dry) { $current ? $res['updated']++ : $res['new']++; continue; }
            if ($current) $backup[] = $current;
            [$newId, $serrs] = Entries::save($t, $current ? (int) $current['id'] : null, $in, __('Import'));
            if ($serrs || !$newId) { foreach ((array) $serrs as $m) $res['errors'][] = ['row' => $line, 'msg' => is_string($m) ? $m : json_encode($m, JSON_UNESCAPED_UNICODE)]; continue; }
            $current ? $res['updated']++ : $res['new']++;
        }
        if (!$dry && $backup) {
            $dir = ROOT . '/storage/backups';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($dir . '/import-' . site()->key . '-' . $t['handle'] . '-' . date('Ymd-His') . '.json', json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        $res['preview'] = array_slice($res['preview'], 0, 50);
        return $res;
    }

    /** Wert umwandeln @return array{0: mixed, 1: ?string} [Wert (null = leer lassen), Fehler] */
    public static function convert(array $f, string $raw): array
    {
        if ($raw === '') return [null, null];
        $type = $f['type'];
        $optKey = function (string $v) use ($f): ?string {
            $opts = Fields::options($f);
            if (isset($opts[$v])) return $v;
            foreach ($opts as $k => $l) if (mb_strtolower((string) $l) === mb_strtolower($v) || mb_strtolower((string) $k) === mb_strtolower($v)) return (string) $k;
            return null;
        };
        $split = fn(string $v) => array_values(array_filter(array_map('trim', preg_split('~[;|]~', $v) ?: []), fn($x) => $x !== ''));
        switch ($type) {
            case 'select':
                $k = $optKey($raw);
                return $k === null ? [null, __('„{v}“ ist keine Option.', ['v' => $raw])] : [$k, null];
            case 'multiselect':
                $out = [];
                foreach ($split($raw) as $p) { $k = $optKey($p); if ($k === null) return [null, __('„{v}“ ist keine Option.', ['v' => $p])]; $out[] = $k; }
                return [$out, null];
            case 'relation':
            case 'relations':
                $target = Tables::findContent((string) ($f['target'] ?? ''));
                if (!$target) return [null, null];
                $titles = array_change_key_case(array_flip(array_map(fn($x) => mb_strtolower((string) $x), Entries::titles($target))), CASE_LOWER);
                $ids = [];
                foreach ($type === 'relation' ? [$raw] : $split($raw) as $p) {
                    $id = ctype_digit($p) ? (int) $p : ($titles[mb_strtolower($p)] ?? null);
                    if (!$id) return [null, __('„{v}“ gibt es in „{table}“ nicht.', ['v' => $p, 'table' => $target['name']])];
                    $ids[] = (int) $id;
                }
                return [$type === 'relation' ? $ids[0] : $ids, null];
            case 'bool':
                $v = mb_strtolower($raw);
                if (in_array($v, ['1', 'ja', 'yes', 'true', 'x', 'wahr'], true)) return ['1', null];
                if (in_array($v, ['0', 'nein', 'no', 'false', 'falsch'], true)) return ['0', null];
                return [null, __('„{v}“ ist kein Ja/Nein.', ['v' => $raw])];
            case 'date':
            case 'datetime':
                $d = self::date($raw);
                if ($d === null) return [null, __('„{v}“ ist kein Datum.', ['v' => $raw])];
                return [$type === 'date' ? $d->format('Y-m-d') : $d->format('Y-m-d H:i'), null];
            case 'number':
                // deutsch „1.234,5“ bzw. englisch „1234.5“
                $n = str_replace(' ', '', $raw);
                if (str_contains($n, ',')) $n = str_replace(',', '.', str_replace('.', '', $n));
                return is_numeric($n) ? [$n, null] : [null, __('„{v}“ ist keine Zahl.', ['v' => $raw])];
            case 'richtext':
                return [\is_plain_text($raw) ? \text_to_rich($raw) : $raw, null];
            default:
                return [$raw, null];
        }
    }

    /** Datum aus TT.MM.JJJJ [HH:MM], JJJJ-MM-TT [HH:MM] oder Excel-Seriennummer */
    private static function date(string $v): ?\DateTimeImmutable
    {
        if (preg_match('~^\d{4,5}(\.\d+)?$~', $v) && (float) $v > 20000 && (float) $v < 80000) {
            return (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int) round(((float) $v) * 86400) . ' seconds');
        }
        foreach (['!d.m.Y H:i', '!d.m.Y', '!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d', '!d.m.y'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat($fmt, $v);
            $err = \DateTimeImmutable::getLastErrors();
            if ($d && ($err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0))) return $d;   // 31.31. wird nicht umgerechnet
        }
        return null;
    }

    /** Abgleich: Schlüssel (klein) → ID */
    private static function matchIndex(array $t, string $match): array
    {
        $idx = [];
        foreach (Entries::query($t, ['status' => 'all', 'limit' => 50000, 'source' => 'own', 'lang' => 'all']) as $e) {
            $k = match ($match) { '_id' => (string) $e['id'], 'slug' => (string) ($e['slug'] ?? ''), default => is_scalar($e[$match] ?? null) ? (string) $e[$match] : '' };
            if ($k !== '') $idx[mb_strtolower($k)] = (int) $e['id'];
        }
        return $idx;
    }

    /** Selbsttest ohne Datenbank: CSV/XLSX hin und zurück, Formel-Schutz, Umwandlung der Werte */
    public static function selftest(): array
    {
        $ok = 0;
        $fails = [];
        $eq = function (string $msg, mixed $a, mixed $b) use (&$ok, &$fails): void { if ($a === $b) $ok++; else $fails[] = 'Import/Export – ' . $msg . ': ' . json_encode($a, JSON_UNESCAPED_UNICODE); };
        $rows = [['ID', 'Name', 'Notiz'], ['1', 'Müller; „Erika“', '=SUMME(A1)'], ['2', 'Zeile 2', '-5']];
        $back = Tabular::read(Tabular::csv($rows));
        $eq('CSV hin und zurück (Umlaute, Semikolon, Anführungszeichen)', $back[1][1] ?? null, 'Müller; „Erika“');
        $eq('CSV: Formel wird entschärft', $back[1][2] ?? null, "'=SUMME(A1)");
        $eq('CSV: negative Zahl bleibt', $back[2][2] ?? null, '-5');
        $eq('CSV mit Komma erkannt', Tabular::read(Tabular::csv($rows, ','))[1][1] ?? null, 'Müller; „Erika“');
        if (class_exists(\ZipArchive::class)) {
            $x = Tabular::read(Tabular::xlsx($rows));
            $eq('XLSX hin und zurück', [$x[0][1] ?? null, $x[1][1] ?? null, count($x)], ['Name', 'Müller; „Erika“', 3]);
        }
        $eq('Windows-1252 wird UTF-8', Tabular::read((string) mb_convert_encoding("a;b\nÄpfel;Öl\n", 'Windows-1252', 'UTF-8'))[1][0] ?? null, 'Äpfel');
        $sel = ['name' => 'k', 'label' => 'Kategorie', 'type' => 'select', 'options' => ['kol' => 'Kolumne', 'news' => 'Neuigkeit']];
        $eq('Auswahl per Beschriftung', self::convert($sel, 'kolumne'), ['kol', null]);
        $eq('Auswahl per Schlüssel', self::convert($sel, 'news'), ['news', null]);
        $eq('Auswahl unbekannt = Fehler', self::convert($sel, 'xyz')[1] !== null, true);
        $eq('Mehrfachauswahl mit ;', self::convert(['type' => 'multiselect'] + $sel, 'Kolumne; news'), [['kol', 'news'], null]);
        $eq('Ja/Nein', [self::convert(['type' => 'bool'], 'ja')[0], self::convert(['type' => 'bool'], '0')[0], self::convert(['type' => 'bool'], 'vielleicht')[1] !== null], ['1', '0', true]);
        $eq('Datum deutsch', self::convert(['type' => 'date'], '24.12.2026'), ['2026-12-24', null]);
        $eq('Datum aus Excel-Zahl', self::convert(['type' => 'date'], '46380')[0], '2026-12-24');
        $eq('Ungültiges Datum = Fehler', self::convert(['type' => 'date'], '31.31.2026')[1] !== null, true);
        $eq('Zahl deutsch', self::convert(['type' => 'number'], '1.234,5'), ['1234.5', null]);
        return ['ok' => $ok, 'fails' => $fails];
    }
}
