<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Http\Controllers\Admin;

use Core\Blocks\ListTemplates;
use Core\Data\Tables;
use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;

/**
 * Listen-Vorlagen einer Tabelle (Daten → Tabelle → Listen-Vorlagen) und „Als Vorlage speichern“ aus der Seitenleiste des Editors.
 * Recht: design.blocks (Core\Blocks\ListTemplates::PERMISSION). Auswählen dürfen alle, die Seiten bearbeiten – im Block.
 */
final class ListTemplateController extends AdminController
{
    private const TYPE = 'data_list';

    private function table(string $handle): array
    {
        $t = Tables::findContent($handle) ?? throw new HttpException(404);
        if (\Core\Data\Placement::blockType($t) !== self::TYPE) throw new HttpException(404);
        return $t;
    }

    private function def(): array
    {
        return app()->theme->block(self::TYPE) ?? throw new HttpException(404, __('Das Kit dieser Website hat keine Datenliste.'));
    }

    protected function view(string $view, array $vars = [], int $status = 200): Response
    {
        $vars['drill'] ??= \Core\Theme::capture(ROOT . '/app/Admin/views/data/_nav.php', ['cur' => 'templates', 'active' => $vars['t'] ?? null]);
        $vars['drillTitle'] ??= __('Daten');
        return parent::view($view, $vars, $status);
    }

    /** Übersicht: Vorlagen als Karten mit Vorschaubild, Standard, Verwendung */
    public function index(Request $r, string $handle): Response
    {
        $this->auth($r, ListTemplates::PERMISSION);
        $t = $this->table($handle);
        $this->def();
        return $this->view('data/templates', ['t' => $t, 'list' => ListTemplates::forTable($t, self::TYPE, true),
            'usage' => ListTemplates::usage($t, self::TYPE), 'std' => ListTemplates::defaultKey($t, self::TYPE)]);
    }

    /** Formular: neue Vorlage (key leer) oder bestehende; Vorlagen aus Kit/Erweiterung nur ansehen */
    public function edit(Request $r, string $handle, string $key = ''): Response
    {
        $this->auth($r, ListTemplates::PERMISSION);
        $t = $this->table($handle);
        $def = $this->def();
        $tpl = $key === '' ? null : (ListTemplates::find($t, $key, self::TYPE) ?? throw new HttpException(404));
        return $this->view('data/template_edit', ['t' => $t, 'def' => $def, 'tpl' => $tpl, 'errors' => [],
            'name' => (string) ($tpl['name'] ?? ''), 'values' => $tpl ? ListTemplates::apply([], $tpl, $def, $t) : array_intersect_key(\Core\Fields::defaults($def['fields']), array_flip(ListTemplates::keys($def))),
            'usage' => $tpl ? (ListTemplates::usage($t, self::TYPE)[$key] ?? []) : [], 'isDefault' => $tpl && ListTemplates::defaultKey($t, self::TYPE) === $key]);
    }

    public function editNew(Request $r, string $handle): Response
    {
        return $this->edit($r, $handle, '');
    }

    /** Speichern (neu oder ändern) */
    public function save(Request $r, string $handle): Response
    {
        $user = $this->auth($r, ListTemplates::PERMISSION);
        $t = $this->table($handle);
        $def = $this->def();
        $key = $r->str('key');
        $tpl = $key === '' ? null : ListTemplates::find($t, $key, self::TYPE);
        if ($key !== '' && (!$tpl || $tpl['source'] !== 'db')) throw new HttpException(403, __('Vorlagen aus dem Kit oder einer Erweiterung lassen sich nicht ändern – bitte duplizieren.'));
        $name = trim($r->str('name'));
        $opts = ListTemplates::optionsFrom((array) ($r->post['f'] ?? []), $def, $t);
        if ($name === '') {
            return $this->view('data/template_edit', ['t' => $t, 'def' => $def, 'tpl' => $tpl, 'errors' => ['name' => __('Bitte einen Namen angeben.')],
                'name' => '', 'values' => ListTemplates::apply([], ['options' => $opts], $def, $t), 'usage' => [], 'isDefault' => false], 422);
        }
        if ($tpl) {
            ListTemplates::update($t, self::TYPE, $key, $name, $opts);
        } else {
            $key = ListTemplates::create($t, self::TYPE, $name, $opts, (int) $user['id'] ?: null);
        }
        if ($r->str('default') === '1') ListTemplates::setDefault($t, self::TYPE, $key);
        elseif ($tpl && $r->str('default') === '0' && ListTemplates::defaultKey($t, self::TYPE) === $key) ListTemplates::setDefault($t, self::TYPE, null);
        $this->changed();
        return $this->back('/admin/data/' . $handle . '/vorlagen', 'success', __('Vorlage „{name}“ gespeichert.', ['name' => $name]));
    }

    /** Aktionen aus dem Zeilenmenü: default, undefault, duplicate, delete (Feld key) */
    public function action(Request $r, string $handle): Response
    {
        $user = $this->auth($r, ListTemplates::PERMISSION);
        $t = $this->table($handle);
        $this->def();
        $key = $r->str('key');
        $tpl = ListTemplates::find($t, $key, self::TYPE) ?? throw new HttpException(404);
        $back = '/admin/data/' . $handle . '/vorlagen';
        $db = $tpl['source'] === 'db';
        switch ($r->str('do')) {
            case 'default':
                if (!$db) return $this->back($back, 'error', __('Als Standard eignen sich nur Vorlagen dieser Website – bitte zuerst duplizieren.'));
                ListTemplates::setDefault($t, self::TYPE, $key);
                return $this->back($back, 'success', __('„{name}“ ist jetzt der Standard für neue Listen dieser Tabelle.', ['name' => $tpl['name']]));
            case 'undefault':
                ListTemplates::setDefault($t, self::TYPE, null);
                return $this->back($back, 'success', __('Kein Standard mehr – neue Listen beginnen mit eigenen Einstellungen.'));
            case 'duplicate':
                $new = ListTemplates::duplicate($t, self::TYPE, $key, (int) $user['id'] ?: null);
                return $this->back($back . '/' . rawurlencode((string) $new), 'success', __('Kopie angelegt.'));
            case 'delete':
                if (!$db) throw new HttpException(403);
                if (!ListTemplates::delete($t, self::TYPE, $key)) {
                    $pages = ListTemplates::usage($t, self::TYPE)[$key] ?? [];
                    return $this->back($back, 'error', __('„{name}“ wird noch verwendet ({pages}) – bitte dort zuerst eine andere Vorlage wählen.', ['name' => $tpl['name'], 'pages' => implode(', ', $pages)]));
                }
                return $this->back($back, 'success', __('Vorlage gelöscht.'));
        }
        throw new HttpException(400);
    }

    /**
     * „Als Vorlage speichern“ aus der Seitenleiste (JSON: type, table, name, data = aktuelle Blockdaten): legt die Vorlage an und
     * liefert Schlüssel, Name und die Kachel für die Auswahl.
     */
    public function fromBlock(Request $r): Response
    {
        $user = $this->auth($r, ListTemplates::PERMISSION);
        $type = $r->str('type') ?: self::TYPE;
        $def = app()->theme->block($type);
        if (!ListTemplates::supports($def)) return Response::fail(__('Dieser Block kennt keine Listen-Vorlagen.'), 422);
        $t = Tables::findContent($r->str('table'));
        if (!$t) return Response::fail(__('Bitte zuerst eine Tabelle wählen.'), 422);
        $name = trim(mb_substr($r->str('name'), 0, 80));
        if ($name === '') return Response::fail(__('Bitte einen Namen angeben.'), 422);
        $data = (array) ($r->post['data'] ?? []);
        // Block mit Vorlage: deren Darstellung übernehmen (sonst die eigenen Werte)
        if (($data['template'] ?? '') !== '' && ($cur = ListTemplates::find($t, (string) $data['template'], $type))) $data = ListTemplates::apply($data, $cur, $def, $t);
        $key = ListTemplates::create($t, (string) $type, $name, ListTemplates::optionsFrom($data, $def, $t), (int) $user['id'] ?: null);
        $tpl = ListTemplates::find($t, $key, (string) $type);
        $this->changed();
        return Response::json(['ok' => true, 'key' => $key, 'name' => $tpl['name'] ?? $name, 'table' => $t['handle'], 'svg' => ListTemplates::preview($tpl['base'] ?? 'cards'),
            'options' => $tpl ? ListTemplates::apply([], $tpl, $def, $t) : []]);
    }
}
