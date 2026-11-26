<?php
declare(strict_types=1);

namespace Core\Http\Controllers\Admin;

use Core\Data\AddressBook;
use Core\Data\Entries;
use Core\Data\Shared;
use Core\Data\Tables;
use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;

/**
 * Adressbuch-Ansicht für Kontakte-Tabellen (Core\Data\AddressBook): Listen · Namen · Karte. Ohne JavaScript über Links und
 * GET-Parameter (liste, filter, q, k, ab, buchstabe, auswahl, ansicht), mit JavaScript lädt resources/js/_addressbook.js nur
 * Teile nach (?_teil=app|namen|mehr|karte) und schreibt die Adresse per History API.
 */
final class AddressBookController extends AdminController
{
    /** Menüpunkt „Kontakte“: zum zuletzt geöffneten bzw. ersten Adressbuch */
    public function home(Request $r): Response
    {
        $this->auth($r);
        $tables = AddressBook::tables();
        if (!$tables) throw new HttpException(404);
        $last = (string) app()->session->get('ab_last', '');
        $t = array_values(array_filter($tables, fn($x) => $x['handle'] === $last))[0] ?? $tables[0];
        return Response::redirect(url('/admin/data/' . $t['handle']));
    }

    /** Ansicht (von DataController::entries, wenn das Konto das Adressbuch gewählt hat) */
    public function show(Request $r, array $t): Response
    {
        $s = $this->state($r, $t);
        $s['s'] = $s;
        app()->session->set('ab_last', $t['handle']);
        $part = $r->str('_teil');
        if ($part !== '') {
            $file = match ($part) { 'namen' => '_ab_names', 'mehr' => '_ab_rows', 'karte' => '_ab_card', default => '_ab_app' };
            $html = \Core\Theme::capture(ROOT . '/app/Admin/views/data/' . $file . '.php', $s);
            return self::secure(new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Vary' => 'X-Requested-With']));
        }
        // Ohne Bereichsnavigation „Daten“: Menüpunkt „Kontakte“ ist der aktuelle (Layout), die Spalte „Adressbücher“ wechselt die Tabelle
        return $this->view('data/addressbook', $s + ['drill' => '']);
    }

    /** Zustand der Ansicht aus der Adresse: Liste/Filter, Suche, gewählter Kontakt, Abschnitt */
    private function state(Request $r, array $t): array
    {
        $lists = AddressBook::lists($t);
        $list = isset($lists[$r->str('liste')]) ? $r->str('liste') : '';
        $filter = $list === '' && in_array($r->str('filter'), AddressBook::FILTERS, true) ? $r->str('filter') : '';
        $q = mb_substr(trim($r->str('q')), 0, 100);
        // Teile: nur die Karte braucht keine Namensliste, nur die ganze Ansicht die Zähler (schnelles Durchblättern)
        $part = $r->str('_teil');
        $rows = $part === 'karte' ? [] : AddressBook::index($t, $list, $filter, $q);
        $k = (int) $r->str('k');
        $e = $k > 0 ? Entries::find($t, $k) : null;
        if ($e && Tables::isShared($t) && Shared::isForeign($t, $e)) $e = null;
        $letter = in_array($r->str('buchstabe'), AddressBook::alphabet(), true) ? $r->str('buchstabe') : '';
        $offset = AddressBook::offset($rows, max(0, (int) $r->str('ab')), $letter, $e ? (int) $e['id'] : 0);
        // Bearbeiten bzw. neuer Kontakt in der rechten Spalte (_ab_edit.php) – nur mit Recht, nicht für Einträge aus einer Quelle
        $canEdit = can('data.edit', $t['handle']);
        $edit = $canEdit && $e && $r->str('bearbeiten') === '1' && !\Core\Sources\Sources::origin($t, (int) $e['id']);
        $new = $canEdit && !$e && $r->str('neu') === '1';
        $pane = $e || $new ? 'card' : ($r->str('ansicht') === 'listen' ? 'src' : 'names');
        return ['t' => $t, 'lists' => $lists, 'list' => $list, 'filter' => $filter, 'q' => $q, 'rows' => $rows, 'e' => $e,
            'offset' => $offset, 'until' => (int) $r->str('bis') ?: null, 'select' => $r->str('auswahl') === '1', 'pane' => $pane, 'counts' => in_array($part, ['karte', 'mehr', 'namen'], true) ? [] : AddressBook::counts($t),
            'books' => AddressBook::tables(), 'edit' => $edit, 'new' => $new];
    }

    /** Kontakte einer Liste zuordnen bzw. aus ihr entfernen (Ziehen auf eine Liste, Auswahl, Karte); „Neue Liste“ legt sie vorher an */
    public function assign(Request $r, string $handle): Response
    {
        $this->auth($r);
        $t = $this->table($handle);
        if (!can('data.edit', $handle)) throw new HttpException(403, __('Für diese Tabelle fehlt Ihrer Rolle die Berechtigung.'));
        $back = $this->backUrl($r, $handle);
        $list = $r->str('liste');
        $new = trim($r->str('neu'));
        if ($new !== '') {
            if (!can('data.schema')) throw new HttpException(403, __('Neue Listen legt an, wer Felder ändern darf.'));
            $res = AddressBook::addList($t, $new);
            if (isset($res['error'])) return $this->reply($r, $back, false, $res['error']);
            $list = (string) $res['key'];
            $t = $this->table($handle);
        }
        $ids = array_map('intval', (array) ($r->post['ids'] ?? []));
        $labels = AddressBook::lists($t);
        if (!isset($labels[$list])) return $this->reply($r, $back, false, __('Bitte eine Liste wählen.'));
        if (!$ids) {
            if ($new !== '') return $this->reply($r, $back, true, __('Liste „{list}“ angelegt.', ['list' => $labels[$list]]), ['list' => $list]);
            return $this->reply($r, $back, false, __('Bitte zuerst Kontakte auswählen.'));
        }
        $add = $r->str('op') !== 'remove';
        $res = AddressBook::assign($t, $ids, $list, $add);
        $this->changed();
        $msg = $add
            ? __('{n} zu „{list}“ hinzugefügt.', ['n' => $this->people($res['changed']), 'list' => $labels[$list]])
            : __('{n} aus „{list}“ entfernt.', ['n' => $this->people($res['changed']), 'list' => $labels[$list]]);
        if ($res['skipped'] && !$res['changed']) $msg = $add ? __('Schon in „{list}“.', ['list' => $labels[$list]]) : __('Nicht in „{list}“.', ['list' => $labels[$list]]);
        if ($res['errors']) $msg .= ' ' . implode(' ', $res['errors']);
        return $this->reply($r, $back, !$res['errors'], $msg, ['list' => $list, 'changed' => $res['changed'], 'counts' => AddressBook::counts($t)]);
    }

    /**
     * vCard-Export: gewählte Kontakte (POST ids[]) bzw. alle der aktuellen Liste/Suche (GET liste, filter, q); umfang=oeffentlich
     * nur Kontakte auf der Website mit ihren öffentlichen Angaben, sonst alle Angaben.
     */
    public function export(Request $r, string $handle): Response
    {
        $this->auth($r);
        $t = $this->table($handle);
        if (!can('data.export', $handle) || !AddressBook::enabled($t)) throw new HttpException(403, __('Für diese Tabelle fehlt Ihrer Rolle die Berechtigung.'));
        $ids = array_values(array_filter(array_map('intval', (array) ($r->post['ids'] ?? $r->query['ids'] ?? []))));
        if (!$ids) {
            $lists = AddressBook::lists($t);
            $list = isset($lists[$r->str('liste')]) ? $r->str('liste') : '';
            $filter = in_array($r->str('filter'), AddressBook::FILTERS, true) ? $r->str('filter') : '';
            $ids = array_map(fn($x) => (int) $x['id'], AddressBook::index($t, $list, $filter, mb_substr(trim($r->str('q')), 0, 100)));
        }
        $internal = $r->str('umfang') !== 'oeffentlich';
        // Alle Angaben: vollständige vCards mit eigenen Zusatzfeldern und unveränderten Zusatzangaben (Core\Data\VCards, wieder importierbar)
        $body = $internal ? \Core\Data\VCards::exportMany($t, $ids) : AddressBook::vcards($t, $ids, false);
        if ($body === '') {
            app()->session->flash('error', $internal ? __('Keine Kontakte zum Exportieren.') : __('Keiner dieser Kontakte steht auf der Website – es gibt nichts Öffentliches zu exportieren.'));
            return Response::redirect($this->backUrl($r, $handle));
        }
        $name = count($ids) === 1 && ($e = Entries::find($t, $ids[0])) ? Entries::title($t, $e) : $t['name'];
        $res = \Core\VCard::response($body, \Core\VCard::fileName($name) . ($internal ? '' : '-oeffentlich') . '.vcf');
        return self::secure($res->header('Cache-Control', 'private, no-store, max-age=0'));
    }

    private function table(string $handle): array
    {
        $t = Tables::find($handle) ?? throw new HttpException(404);
        if (!AddressBook::enabled($t)) throw new HttpException(404);
        return $t;
    }

    private function people(int $n): string
    {
        return $n === 1 ? __('1 Kontakt') : __('{n} Kontakte', ['n' => $n]);
    }

    /** Rücksprung: nur Adressen dieses Adressbuchs (aus dem Formular, sonst die Ansicht) */
    private function backUrl(Request $r, string $handle): string
    {
        $base = url('/admin/data/' . $handle);
        $to = $r->str('zurueck');
        return $to !== '' && ($to === $base || str_starts_with($to, $base . '?')) ? $to : $base;
    }

    private function reply(Request $r, string $back, bool $ok, string $msg, array $data = []): Response
    {
        if ($r->wantsJson() || $r->str('_json') === '1') return Response::json(['ok' => $ok, 'message' => $msg] + $data, $ok ? 200 : 422);
        app()->session->flash($ok ? 'success' : 'error', $msg);
        return Response::redirect($back);
    }
}
