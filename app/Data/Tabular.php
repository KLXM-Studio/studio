<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Data;

/**
 * Tabellendateien lesen und schreiben – ohne fremde Bibliothek: CSV (UTF-8 mit BOM, Trennzeichen ; oder , – beim Lesen erkannt,
 * Windows-1252 wird nach UTF-8 umgewandelt) und XLSX (Office Open XML, eine Tabelle; über ZipArchive). Für Import/Export der
 * Datentabellen (Core\Data\TableExport, Core\Data\TableImport).
 */
final class Tabular
{
    public const MAX_ROWS = 20000;

    /** CSV schreiben: BOM + Trennzeichen (deutsches Excel: ;) @param list<list<string>> $rows */
    public static function csv(array $rows, string $sep = ';'): string
    {
        $h = fopen('php://temp', 'r+');
        foreach ($rows as $r) fputcsv($h, array_map(fn($v) => self::safeCell((string) $v), $r), $sep, '"', '');
        rewind($h);
        $out = (string) stream_get_contents($h);
        fclose($h);
        return "\xEF\xBB\xBF" . $out;
    }

    /** XLSX schreiben (eine Tabelle, Text als Inline-Strings, erste Zeile fett) @param list<list<string>> $rows */
    public static function xlsx(array $rows, string $sheet = 'Daten'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $z = new \ZipArchive();
        $z->open($tmp, \ZipArchive::OVERWRITE);
        $x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $col = function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
        $data = '';
        foreach ($rows as $ri => $r) {
            $data .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($r) as $ci => $v) {
                $data .= '<c r="' . $col($ci) . ($ri + 1) . '" t="inlineStr"' . ($ri === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $x(self::safeCell((string) $v)) . '</t></is></c>';
            }
            $data .= '</row>';
        }
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $x(mb_substr($sheet, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>' . $data . '</sheetData></worksheet>');
        $z->close();
        $out = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $out;
    }

    /** Datei lesen (CSV oder XLSX, am Inhalt erkannt) → Zeilen; erste Zeile = Spaltenköpfe @return list<list<string>> @throws \RuntimeException */
    public static function read(string $body): array
    {
        if (str_starts_with($body, "PK\x03\x04")) return self::readXlsx($body);
        return self::readCsv($body);
    }

    /** @return list<list<string>> */
    public static function readCsv(string $body): array
    {
        if (str_starts_with($body, "\xEF\xBB\xBF")) $body = substr($body, 3);
        if (!mb_check_encoding($body, 'UTF-8')) $body = (string) mb_convert_encoding($body, 'UTF-8', 'Windows-1252');
        $first = strtok($body, "\n") ?: '';
        $sep = substr_count($first, ';') >= substr_count($first, ',') ? (substr_count($first, "\t") > substr_count($first, ';') ? "\t" : ';') : ',';
        $h = fopen('php://temp', 'r+');
        fwrite($h, $body);
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) {
            if ($r === [null]) continue;
            $rows[] = array_map(fn($v) => trim((string) $v), $r);
            if (count($rows) > self::MAX_ROWS + 1) throw new \RuntimeException(__('Zu viele Zeilen (höchstens {n}).', ['n' => self::MAX_ROWS]));
        }
        fclose($h);
        return $rows;
    }

    /** XLSX: erste Tabelle, Text, Zahlen und Inline-Strings; Datumszahlen bleiben Zahlen (Spalte im Import als Datum) @return list<list<string>> */
    public static function readXlsx(string $body): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $body);
        $z = new \ZipArchive();
        if ($z->open($tmp) !== true) { @unlink($tmp); throw new \RuntimeException(__('Die Datei ist keine gültige Excel-Datei (.xlsx).')); }
        $shared = [];
        if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = @simplexml_load_string($ss, \SimpleXMLElement::class, LIBXML_NONET);
            foreach ($sx ? $sx->si : [] as $si) {
                $t = isset($si->t) ? (string) $si->t : '';
                if (isset($si->r)) foreach ($si->r as $run) $t .= (string) $run->t;
                $shared[] = $t;
            }
        }
        $sheet = $z->getFromName('xl/worksheets/sheet1.xml');
        $z->close();
        @unlink($tmp);
        if ($sheet === false) throw new \RuntimeException(__('Die Excel-Datei enthält keine Tabelle.'));
        $sx = @simplexml_load_string($sheet, \SimpleXMLElement::class, LIBXML_NONET);
        if (!$sx) throw new \RuntimeException(__('Die Excel-Datei ließ sich nicht lesen.'));
        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $r = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $ci = 0;
                foreach (str_split((string) preg_replace('~\d+~', '', $ref)) as $ch) $ci = $ci * 26 + (ord($ch) - 64);
                $ci = max(0, $ci - 1);
                $type = (string) $c['t'];
                $v = match ($type) { 's' => $shared[(int) $c->v] ?? '', 'inlineStr' => (string) ($c->is->t ?? ''), default => (string) $c->v };
                $r[$ci] = trim($v);
            }
            if (!$r) continue;
            $max = max(array_keys($r));
            $line = [];
            for ($i = 0; $i <= $max; $i++) $line[] = $r[$i] ?? '';
            $rows[] = $line;
            if (count($rows) > self::MAX_ROWS + 1) throw new \RuntimeException(__('Zu viele Zeilen (höchstens {n}).', ['n' => self::MAX_ROWS]));
        }
        return $rows;
    }

    /** Formel-Injektion verhindern (=, +, -, @ am Anfang werden in Tabellenprogrammen als Formel gelesen) */
    private static function safeCell(string $v): string
    {
        return $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && !preg_match('~^-?\d+([.,]\d+)?$~', $v) ? "'" . $v : $v;
    }
}
