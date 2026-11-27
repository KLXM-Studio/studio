<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Http\Controllers\Admin;

use Core\Data\Entries;
use Core\Data\ListFilters;
use Core\Data\TableExport;
use Core\Data\TableImport;
use Core\Data\Tables;
use Core\Data\Tabular;
use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;

/**
 * Importieren und Exportieren als Tabelle (CSV, XLSX) für jede Datentabelle – Rechte data.import bzw. data.export je Tabelle.
 *  - Export: GET /admin/data/{t}/export – Formular (Format, Spalten); mit ?format=… Download der aktuellen Ansicht (Status, Suche,
 *    Filter der Liste, Zeitraum) bzw. der gewählten Zeilen (ids[]).
 *  - Import: GET …/tabelle-import (Datei) → POST (lesen, zwischenspeichern) → GET ?token (Zuordnung) → POST probe (Probelauf)
 *    → POST run (Import, Sicherung, Protokoll).
 */
final class TableIoController extends AdminController
{
    private function table(Request $r, string $handle, string $perm): array
    {
        $this->auth($r, $perm, $handle);
        $t = Tables::find($handle) ?? throw new HttpException(404);
        if (Tables::isInbox($t)) throw new HttpException(403, __('Eingangs-Tabelle: Anfragen im „Eingang“ bearbeiten.'));
        return $t;
    }

    public function export(Request $r, string $handle): Response
    {
        $t = $this->table($r, $handle, 'data.export');
        $format = $r->str('format');
        if (!in_array($format, ['csv', 'csv-comma', 'xlsx'], true)) {
            return $this->view('data/table_export', ['t' => $t, 'cols' => TableExport::columns($t), 'query' => $r->query]);
        }
        $o = ['status' => in_array($r->str('status'), ['published', 'draft'], true) ? $r->str('status') : 'all', 'q' => $r->str('q'), 'source' => 'own', 'limit' => Tabular::MAX_ROWS];
        if ($sel = ListFilters::selection($t, $r->query)) $o['where'] = ListFilters::where($sel);
        if (\Core\Data\Calendar::enabled($t) && in_array($r->str('zeit'), ['vergangene', 'kommende'], true)) $o['when'] = $r->str('zeit') === 'kommende' ? 'upcoming' : 'past';
        $ids = array_values(array_filter(array_map('intval', (array) ($r->query['ids'] ?? []))));
        if ($ids) $o['ids'] = $ids;
        $cols = array_map('strval', (array) ($r->query['cols'] ?? []));
        $file = TableExport::build($t, Entries::query($t, $o), $cols, $format);
        \Core\Activity::log('settings', 'entry', 0, (string) $t['name'], ['tbl' => $t['handle'], 'detail' => __('Export: {n} Einträge ({format})', ['n' => $file['rows'], 'format' => strtoupper(str_starts_with($format, 'csv') ? 'csv' : 'xlsx')])]);
        return Response::download($file['body'], $file['name'], $file['type']);
    }

    public function importForm(Request $r, string $handle): Response
    {
        $t = $this->table($r, $handle, 'data.import');
        $token = $r->str('token');
        $rows = $token !== '' ? $this->unstash($t, $token) : null;
        return $this->view('data/table_import', ['t' => $t, 'token' => $rows !== null ? $token : '', 'rows' => $rows,
            'map' => $rows ? TableImport::guess($t, $rows[0]) : [], 'targets' => TableImport::targets($t), 'result' => null, 'opts' => []]);
    }

    public function importUpload(Request $r, string $handle): Response
    {
        $t = $this->table($r, $handle, 'data.import');
        $f = $r->files['datei'] ?? null;
        if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            return $this->back('/admin/data/' . $handle . '/tabelle-import', 'error', __('Bitte eine CSV- oder Excel-Datei (.xlsx) wählen.'));
        }
        if ((int) $f['size'] > 10 * 1024 * 1024) return $this->back('/admin/data/' . $handle . '/tabelle-import', 'error', __('Die Datei ist zu groß (höchstens 10 MB).'));
        try {
            $rows = Tabular::read((string) file_get_contents((string) $f['tmp_name']));
        } catch (\RuntimeException $e) {
            return $this->back('/admin/data/' . $handle . '/tabelle-import', 'error', $e->getMessage());
        }
        if (count($rows) < 2) return $this->back('/admin/data/' . $handle . '/tabelle-import', 'error', __('Die Datei braucht eine Kopfzeile und mindestens eine Zeile mit Daten.'));
        $token = bin2hex(random_bytes(12));
        $dir = ROOT . '/storage/tmp';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        file_put_contents($dir . '/import-' . site()->key . '-' . $token . '.json', json_encode(['table' => $t['handle'], 'user' => (int) (app()->auth->user()['id'] ?? 0), 'rows' => $rows, 'at' => time()], JSON_UNESCAPED_UNICODE));
        return Response::redirect(url('/admin/data/' . $handle . '/tabelle-import?token=' . $token));
    }

    public function importRun(Request $r, string $handle): Response
    {
        $t = $this->table($r, $handle, 'data.import');
        $token = $r->str('token');
        $rows = $this->unstash($t, $token) ?? throw new HttpException(410, __('Die hochgeladene Datei ist abgelaufen – bitte neu wählen.'));
        $opts = ['map' => array_map('strval', (array) ($r->post['map'] ?? [])), 'mode' => $r->str('mode'), 'match' => $r->str('match') ?: '_id', 'dry' => $r->str('step') !== 'run'];
        $res = TableImport::run($t, $rows, $opts);
        if (!$opts['dry']) {
            if (($sf = $this->stashFile($token)) !== '') @unlink($sf);
            \Core\Activity::log('updated', 'entry', 0, (string) $t['name'], ['tbl' => $t['handle'], 'detail' => __('Import: {new} neu, {up} aktualisiert, {err} Fehler', ['new' => $res['new'], 'up' => $res['updated'], 'err' => count($res['errors'])])]);
            app()->session->flash($res['errors'] ? 'error' : 'success', __('Import: {new} neu, {up} aktualisiert, {skip} übersprungen, {err} Zeilen mit Fehlern.', ['new' => $res['new'], 'up' => $res['updated'], 'skip' => $res['skipped'], 'err' => count(array_unique(array_column($res['errors'], 'row')))]));
            return Response::redirect(url('/admin/data/' . $handle));
        }
        return $this->view('data/table_import', ['t' => $t, 'token' => $token, 'rows' => $rows, 'map' => $opts['map'], 'targets' => TableImport::targets($t), 'result' => $res, 'opts' => $opts]);
    }

    /** Zwischenablage des Imports – Pfad aus dem Ordnerinhalt (nicht aus dem Token der Anfrage); '' = nicht vorhanden */
    private function stashFile(string $token): string
    {
        if (!preg_match('~^[a-f0-9]{24}$~', $token)) return '';
        $want = 'import-' . site()->key . '-' . $token . '.json';
        foreach (scandir(ROOT . '/storage/tmp') ?: [] as $f) if ($f === $want) return ROOT . '/storage/tmp/' . $f;
        return '';
    }

    /** Hochgeladene Zeilen (nur dieselbe Tabelle, dasselbe Konto, höchstens 2 Stunden alt) */
    private function unstash(array $t, string $token): ?array
    {
        $file = $this->stashFile($token);
        if ($file === '' || !is_file($file)) return null;
        $d = json_decode((string) file_get_contents($file), true);
        if (!is_array($d) || ($d['table'] ?? '') !== $t['handle'] || (int) ($d['user'] ?? -1) !== (int) (app()->auth->user()['id'] ?? 0) || time() - (int) ($d['at'] ?? 0) > 7200) return null;
        return is_array($d['rows'] ?? null) ? $d['rows'] : null;
    }
}
