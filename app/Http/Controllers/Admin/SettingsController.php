<?php
declare(strict_types=1);

namespace Core\Http\Controllers\Admin;

use Core\Fields;
use Core\Lang;
use Core\Http\Request;
use Core\Http\Response;
use Core\SettingsRevisions;
use Core\SiteSettings;

/**
 * Website-Einstellungen des Kits (Titel und Felder vom Kit). Zwei Stufen (Core\SiteSettings): mit settings.info nur die
 * „Website-Angaben“, mit settings.edit alles. Jede Änderung landet als Version in settings_revisions („Zurücknehmen“).
 */
final class SettingsController extends AdminController
{
    /** Bearbeitete Sprache: leer = Standardsprache, sonst Übersetzung (gespeichert als „feld@en“) */
    private function lang(Request $r): string
    {
        $l = (string) ($r->query['lang'] ?? $r->post['_lang'] ?? '');
        return Lang::multi() && Lang::valid($l) && $l !== Lang::default() ? $l : '';
    }

    public function edit(Request $r, array $errors = [], ?array $values = null): Response
    {
        $this->auth($r, 'settings.info');
        $lang = $this->lang($r);
        $groups = SiteSettings::groups();
        $source = [];
        foreach (SiteSettings::fields() as $f) {
            if (isset($f['name'])) $source[$f['name']] = app()->settings->get($f['name']);
        }
        if ($lang !== '') {
            // Übersetzung: nur übersetzbare Felder; leere Felder zeigen später den Text der Standardsprache
            $groups = array_values(array_filter(array_map(fn($g) => ['fields' => Fields::forTranslation($g['fields'], $source)] + $g, $groups), fn($g) => $g['fields']));
            if ($values === null) {
                $values = [];
                foreach ($groups as $g) {
                    foreach ($g['fields'] as $f) {
                        if (!isset($f['name'])) continue;
                        $v = app()->settings->get($f['name'] . '@' . $lang);
                        // Listen (z. B. Hero-Themen) mit der Standardsprache vorbelegen, damit man sie direkt übersetzen kann
                        $values[$f['name']] = $v ?? (($f['type'] ?? '') === 'repeater' ? ($source[$f['name']] ?? []) : null);
                    }
                }
            }
        } elseif ($values === null) {
            $values = $source;
        }
        return $this->view('settings', [
            'title' => SiteSettings::title(), 'groups' => SiteSettings::decorate($groups), 'lang' => $lang, 'full' => SiteSettings::full(),
            'values' => $values, 'errors' => $errors, 'action' => '/admin/settings',
            'revisions' => SettingsRevisions::latest('settings', 6), 'fieldsByName' => array_column(array_filter(app()->theme->settingsFields(), fn($f) => isset($f['name'])), null, 'name'),
        ], $errors ? 422 : 200);
    }

    /**
     * Live-Vorschau: Startseite (bzw. ?page=ID) mit den ungespeicherten Formularwerten – nur im Speicher.
     * Mit repeater + index wird nur dieser Eintrag gezeigt (aktiv, ohne Zeitraum), z. B. ein einzelnes Hero-Thema.
     */
    public function preview(Request $r): Response
    {
        $this->auth($r, 'settings.info');
        $lang = $this->lang($r);
        $fields = SiteSettings::fields();
        if ($lang !== '') {
            $fields = array_values(array_filter(Fields::forTranslation($fields), fn($f) => isset($f['name'])));
        }
        [$values] = Fields::sanitize($fields, (array) ($r->post['f'] ?? []));
        // Einzelnen Listeneintrag zuerst zeigen (sichtbar gemacht, Zeitraum ignoriert); Themes lesen preview_focus()
        $rep = (string) ($r->post['_repeater'] ?? '');
        if ($rep !== '' && isset($values[$rep]) && is_array($values[$rep])) {
            $list = array_values($values[$rep]);
            $idx = (int) ($r->post['_index'] ?? 0);
            if (isset($list[$idx])) {
                $def = current(array_filter($fields, fn($f) => ($f['name'] ?? '') === $rep));
                foreach ((array) ($def['fields'] ?? []) as $sf) {
                    if (($sf['type'] ?? '') === 'date') $list[$idx][$sf['name']] = '';
                    if (($sf['type'] ?? '') === 'bool' && in_array($sf['name'], ['aktiv', 'active', 'sichtbar', 'visible'], true)) $list[$idx][$sf['name']] = true;
                }
                $values[$rep] = $list;
                app()->previewFocus[$rep] = $idx;
            }
        }
        $over = [];
        foreach ($values as $k => $v) {
            $over[$lang !== '' ? "$k@$lang" : $k] = $lang !== '' && ($v === '' || $v === [] || $v === null) ? null : $v;
        }
        app()->settings->override($over);
        if ($lang !== '') app()->lang = $lang;
        $page = ctype_digit((string) ($r->post['_page'] ?? '')) ? \Core\Pages::find((int) $r->post['_page']) : \Core\Pages::home($lang !== '' ? $lang : null);
        if (!$page) {
            return Response::fail(__('Keine Startseite gefunden.'), 404);
        }
        return Response::json(['ok' => true, 'html' => (new \Core\Http\Controllers\SiteController())->previewHtml($page)]);
    }

    public function save(Request $r): Response
    {
        $this->auth($r, 'settings.info');
        $lang = $this->lang($r);
        // Nur Felder, die die Person bearbeiten darf (Website-Angaben bzw. alles) – übrige Werte bleiben unberührt
        $fields = SiteSettings::fields();
        if ($lang !== '') {
            $fields = array_values(array_filter(Fields::forTranslation($fields), fn($f) => isset($f['name'])));
        }
        // Bisherige Werte: unveränderte, schon gespeicherte Texte über der Längengrenze blockieren das Speichern nicht
        $current = [];
        foreach ($fields as $f) if (isset($f['name'])) $current[$f['name']] = app()->settings->get($f['name'] . ($lang !== '' ? '@' . $lang : ''));
        [$values, $errors] = Fields::sanitize($fields, (array) ($r->post['f'] ?? []), $current);
        // Pflichtwerte, die einmal gesetzt nicht mehr leer sein dürfen (Impressum, Datenschutz) – nur in der Standardsprache
        if ($lang === '') $errors += SiteSettings::keepErrors($fields, $values, $current);
        if ($errors) {
            app()->session->flash('error', __('Bitte prüfen Sie die markierten Felder – es wurde nichts gespeichert.'));
            return $this->edit($r, $errors, $values);
        }
        if ($lang !== '') {
            foreach ($values as $k => $v) {
                $empty = $v === null || $v === '' || $v === [] || $v == app()->settings->get($k);
                $empty ? app()->settings->delete($k . '@' . $lang) : app()->settings->set($k . '@' . $lang, $v);
            }
            // Version: was in dieser Sprache wirklich gespeichert ist (leer = Rückfall auf die Standardsprache)
            $after = [];
            foreach ($values as $k => $_) $after[$k] = app()->settings->get($k . '@' . $lang);
            SettingsRevisions::record('settings', $current, $after, 'admin', $lang);
        } else {
            app()->settings->setMany($values);
            SettingsRevisions::record('settings', $current, $values, 'admin');
        }
        // Erweiterungen (Extension::on 'settings.saved'): nur geänderte Schlüssel, ohne Werte
        $changed = array_keys(array_filter($values, fn($v, $k) => $v != ($current[$k] ?? null), ARRAY_FILTER_USE_BOTH));
        if ($changed) \Core\Extensions::emit(new \Core\Events\SettingsSaved('settings', $lang !== '' ? array_map(fn($k) => $k . '@' . $lang, $changed) : $changed));
        $this->changed();
        $q = $lang !== '' ? '?lang=' . $lang : '';
        return $this->back('/admin/settings' . $q . ($r->str('_tab') ? '#' . preg_replace('~[^\w\-]~', '', $r->str('_tab')) : ''),
            'success', __('{title} gespeichert.', ['title' => SiteSettings::title() . ($lang !== '' ? ' (' . Lang::all()[$lang] . ')' : '')]));
    }

    /** Letzte Änderung zurücknehmen (Core\SettingsRevisions) – nur Felder, die die Person bearbeiten darf */
    public function undo(Request $r, string $id): Response
    {
        $this->auth($r, 'settings.info');
        $allowed = array_column(array_filter(SiteSettings::fields(), fn($f) => isset($f['name'])), 'name');
        $err = SettingsRevisions::undo((int) $id, $allowed, 'settings');
        if ($err === null) $this->changed();
        return $this->back('/admin/settings#aenderungen', $err === null ? 'success' : 'error', $err ?? __('Änderung zurückgenommen.'));
    }
}
