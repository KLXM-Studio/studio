<?php
declare(strict_types=1);

namespace Klxm\Dav;

use Core\Data\Entries;
use Core\Data\Tables;
use Core\Lang;
use Core\Media;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\PropPatch;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;

/**
 * CardDAV: Tabellen mit „Als Adressbuch nutzen“ (Einstellung der Erweiterung) = je ein Adressbuch, Eintrag = vCard 3.0.
 *
 * Zuordnung (Dav::CARD_MAP, im Admin einstellbar): FN bzw. N (Nachname/Vorname), ORG, TITLE, ADR (Straße, PLZ, Ort, Land), URL, NOTE, BDAY,
 * PHOTO (Bildfeld → eingebettetes JPEG, nur Ausgabe), CATEGORIES (Auswahl). EMAIL/TEL: alle Felder vom Typ E-Mail bzw. Telefon
 * (Typ aus dem Feldnamen: mobil/handy → CELL, fax → FAX, privat → HOME, sonst WORK).
 * Alle übrigen Felder als X-MYCMS-{FELD} (z. B. X-MYCMS-FACHGEBIET) – in beide Richtungen; Rich-Text als reiner Text.
 * Nur FN zugeordnet → N aus dem ganzen Namen (splitName); ORG ohne Feld aus der Einstellung org; published_only blendet Entwürfe aus.
 * Unbekannte Eigenschaften aus Apps bleiben in dav_objects.raw erhalten.
 *
 * Kontakte-Tabellen (Core\Data\Kinds, Art contacts) brauchen keine Zuordnung: das Grundschema geht fest auf die vCard
 * (contactCard/contactIn) – Mehrfachfelder mit vCard-TYPE bzw. Apple-Beschriftung (item.X-ABLabel), Listen als CATEGORIES.
 * Eine in der App neue oder geänderte Angabe ist nie von selbst öffentlich: „Öffentlich“ bleibt nur bei unverändertem Wert.
 * Felder, die bewusst nur für andere Ziele freigegeben sind (Exposure::appFields, z. B. „Nur Website“), und Altfelder gehen nie an
 * Apps und werden von Apps nie geschrieben.
 *
 * Listen als Gruppen (Adressbücher mit Funktionen, Grundschema contacts ab v2, Core\Data\Roles): je Liste eine Gruppen-Karte
 * liste-{schlüssel}.vcf (X-ADDRESSBOOKSERVER-KIND:group, Mitglieder als X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:{UID}) – Apple Kontakte
 * zeigt sie als Gruppen, andere Apps nutzen weiter CATEGORIES. Mitglied ist, wer eine aktuelle Funktion in der Liste hat oder von
 * Hand zugeordnet ist. Mitglieder in der App ändern = Kontakt der Liste zuordnen bzw. entfernen (wie im Adressbuch der Verwaltung,
 * entfernen beendet die Funktion). Neue Gruppen und das Löschen von Gruppen gehen nur im CMS. Adressbücher ohne Funktionen und
 * Inhaltstabellen bekommen keine Gruppen-Karten (unverändert).
 */
final class CardBackend extends \Sabre\CardDAV\Backend\AbstractBackend
{
    private function table(mixed $id): array
    {
        $t = Tables::find((int) $id);
        if (!$t || !(Dav::tableSettings($t)['addressbook'] || Dav::aboTable($t)) || !Dav::canSee($t)) throw new NotFound('Adressbuch nicht gefunden.');
        return $t;
    }

    public function getAddressBooksForUser($principalUri)
    {
        $out = [];
        foreach (Dav::addressBookTables() as $t) {
            if (!Dav::canSee($t)) continue;
            $out[] = ['id' => (int) $t['id'], 'uri' => $t['handle'], 'principaluri' => $principalUri, '{DAV:}displayname' => $t['name'],
                '{urn:ietf:params:xml:ns:carddav}addressbook-description' => (string) ($t['description'] ?? ''),
                '{http://calendarserver.org/ns/}getctag' => Dav::ctag($t, 'card')];
        }
        return $out;
    }

    public function updateAddressBook($addressBookId, PropPatch $propPatch)
    {
    }

    public function createAddressBook($principalUri, $url, array $properties)
    {
        throw new Forbidden('Adressbücher werden im CMS angelegt.');
    }

    public function deleteAddressBook($addressBookId)
    {
        throw new Forbidden('Adressbücher können nur im CMS gelöscht werden.');
    }

    private function entries(array $t): array
    {
        $objs = Dav::objects('card', $t['handle']);
        $s = Dav::tableSettings($t);
        $out = [];
        foreach (Entries::query($t, ['status' => 'all', 'lang' => Lang::default(), 'limit' => 5000]) as $e) {
            $o = $objs[$e['id']] ?? null;
            if ($o && (int) $o['hidden'] === 1 && $e['status'] === 'draft') continue;
            if (!Dav::visible($s, $e) || !Dav::aboAllows($t, $e)) continue;
            $out[] = [$e, $o];
        }
        return $out;
    }

    private function row(array $t, array $e, ?array $o): array
    {
        $data = self::render($t, Dav::aboEntry($t, $e), $o);   // Abo-Token „nur öffentlich“: nur freigegebene Angaben
        return ['id' => (int) $e['id'], 'uri' => $o['uri'] ?? 'cms-' . (int) $e['id'] . '.vcf', 'lastmodified' => strtotime((string) $e['updated_at']) ?: time(),
            'etag' => Dav::etag($data), 'size' => strlen($data), 'carddata' => $data];
    }

    public function getCards($addressbookId)
    {
        $t = $this->table($addressbookId);
        $entries = $this->entries($t);
        return [...array_map(fn($x) => $this->row($t, $x[0], $x[1]), $entries), ...array_values(self::groupRows($t, $entries))];
    }

    // ------------------------------------------------------------------ Listen als Gruppen (Grundschema contacts v2)

    /** Hat das Adressbuch Gruppen-Karten? (Kontakte mit Funktionen und Listen) */
    public static function hasGroups(array $t): bool
    {
        return \Core\Data\Kinds::isContacts($t) && \Core\Data\Roles::has($t) && \Core\Data\AddressBook::lists($t) !== [];
    }

    /** Adresse der Gruppen-Karte einer Liste */
    public static function groupUri(string $key): string
    {
        return 'liste-' . $key . '.vcf';
    }

    /** UID der Gruppen-Karte (fest je Website, Tabelle und Liste) */
    public static function groupUid(array $t, string $key): string
    {
        return 'klxm-liste-' . substr(md5(site()->key . '|' . $t['handle'] . '|' . $key), 0, 20);
    }

    /** UID der Kontakt-Karte (wie render(): gespeicherte UID der App, sonst die des CMS) */
    private static function cardUid(array $t, array $e, ?array $o): string
    {
        return (string) ($o['uid'] ?? self::uid($t, $e));
    }

    /**
     * Gruppen-Karte einer Liste: FN/N = Name der Liste, Mitglieder = Kontakte mit aktueller Funktion in der Liste oder von Hand
     * zugeordnet. $entries = sichtbare Einträge [[Eintrag, dav_objects-Zeile], …] (wie getCards).
     */
    public static function groupCard(array $t, string $key, string $label, array $entries): string
    {
        $card = new VCard(['VERSION' => '3.0', 'PRODID' => '-//KLXM//' . CMS_NAME . ' ' . CMS_VERSION . '//DE', 'UID' => self::groupUid($t, $key)]);
        $card->add('FN', $label);
        $card->add('N', [$label, '', '', '', '']);
        $card->add('X-ADDRESSBOOKSERVER-KIND', 'group');
        foreach ($entries as [$e, $o]) {
            if (in_array(\Core\Data\Roles::membership($t, $e, $key), ['current', 'manual'], true)) $card->add('X-ADDRESSBOOKSERVER-MEMBER', 'urn:uuid:' . self::cardUid($t, $e, $o));
        }
        return $card->serialize();
    }

    /** Zeilen der Gruppen-Karten (getCards/getCard), Schlüssel = Liste */
    private static function groupRows(array $t, array $entries): array
    {
        if (!self::hasGroups($t)) return [];
        $out = [];
        $mod = strtotime((string) ($t['updated_at'] ?? '')) ?: time();
        foreach ($entries as [$e]) $mod = max($mod, strtotime((string) ($e['updated_at'] ?? '')) ?: 0);
        foreach (\Core\Data\AddressBook::lists($t) as $k => $label) {
            $data = self::groupCard($t, (string) $k, $label, $entries);
            $out[(string) $k] = ['uri' => self::groupUri((string) $k), 'lastmodified' => $mod, 'etag' => Dav::etag($data), 'size' => strlen($data), 'carddata' => $data];
        }
        return $out;
    }

    /** Liste zur Adresse einer Gruppen-Karte oder null */
    private static function groupKey(array $t, string $uri): ?string
    {
        if (!self::hasGroups($t) || !preg_match('~^liste-([a-z0-9_-]+)\.vcf$~', $uri, $m)) return null;
        return isset(\Core\Data\AddressBook::lists($t)[$m[1]]) ? $m[1] : null;
    }

    /**
     * Mitglieder einer Gruppen-Karte aus der App übernehmen: neu aufgeführte Kontakte der Liste zuordnen, fehlende entfernen
     * (Core\Data\AddressBook::assign – mit Revision, Rechten, Bearbeitungssperre; entfernen beendet die Funktion in der Liste).
     * @return array{added: int, removed: int}
     */
    public static function applyGroup(array $t, string $key, array $entries, string $cardData): array
    {
        try {
            $card = Reader::read($cardData, Reader::OPTION_FORGIVING);
        } catch (\Throwable $ex) {
            throw new BadRequest('Ungültige vCard: ' . $ex->getMessage());
        }
        $want = [];
        foreach ($card->select('X-ADDRESSBOOKSERVER-MEMBER') as $p) $want[strtolower((string) preg_replace('~^urn:uuid:~i', '', trim((string) $p)))] = true;
        $add = $remove = [];
        foreach ($entries as [$e, $o]) {
            if (\Core\Data\Shared::isForeign($t, $e)) continue;
            $in = in_array(\Core\Data\Roles::membership($t, $e, $key), ['current', 'manual'], true);
            $should = isset($want[strtolower(self::cardUid($t, $e, $o))]);
            if ($should && !$in) $add[] = (int) $e['id'];
            if (!$should && $in) $remove[] = (int) $e['id'];
        }
        $a = $add ? \Core\Data\AddressBook::assign($t, $add, $key, true) : ['changed' => 0, 'errors' => []];
        $r = $remove ? \Core\Data\AddressBook::assign($t, $remove, $key, false) : ['changed' => 0, 'errors' => []];
        if ($a['errors'] || $r['errors']) throw new BadRequest('Gruppe: ' . implode(' ', [...$a['errors'], ...$r['errors']]));
        return ['added' => $a['changed'], 'removed' => $r['changed']];
    }

    private function find(array $t, string $uri): array
    {
        $o = Dav::objectByUri($t['handle'], $uri);
        $id = $o ? (int) $o['entry_id'] : (preg_match('~^cms-(\d+)\.vcf$~', $uri, $m) ? (int) $m[1] : 0);
        $e = $id ? Entries::find($t, $id) : null;
        // Geteilte Tabellen: fremde Einträge nur, wenn die Website sie sehen darf
        if ($e && !\Core\Data\Shared::visibleAll($t, $e)) $e = null;
        if (!$e || ($o && (int) $o['hidden'] === 1 && $e['status'] === 'draft')) return [null, $o];
        // „Nur veröffentlichte Einträge“: Entwürfe sind für Apps nicht vorhanden (Abruf/Ändern → 404, Löschen → nichts zu tun)
        if (!Dav::visible(Dav::tableSettings($t), $e) || !Dav::aboAllows($t, $e)) return [null, $o];
        return [$e, $o ?: (Dav::objects('card', $t['handle'])[$id] ?? null)];
    }

    public function getCard($addressBookId, $cardUri)
    {
        $t = $this->table($addressBookId);
        if (($key = self::groupKey($t, (string) $cardUri)) !== null) return self::groupRows($t, $this->entries($t))[$key] ?? false;
        [$e, $o] = $this->find($t, (string) $cardUri);
        return $e ? $this->row($t, $e, $o) : false;
    }

    public function createCard($addressBookId, $cardUri, $cardData)
    {
        $t = $this->table($addressBookId);
        if (!Dav::canWrite($t)) throw new Forbidden('Nur lesender Zugriff.');
        if (Dav::objectByUri($t['handle'], (string) $cardUri)) throw new BadRequest('Kontakt existiert bereits.');
        // Gruppen (Listen) legt nur das CMS an – eine in der App neue Gruppe wäre sonst ein Kontakt ohne Namen
        if (self::groupKey($t, (string) $cardUri) !== null || preg_match('~X-ADDRESSBOOKSERVER-KIND:\s*group~i', (string) $cardData)) throw new Forbidden('Listen werden im CMS angelegt (Adressbuch → Neue Liste).');
        // Adresse eines vorhandenen, für Apps nicht sichtbaren Eintrags (Entwurf bei „Nur veröffentlichte Einträge“) nicht neu belegen
        if (Dav::reservedUri($t, (string) $cardUri, 'vcf')) throw new Forbidden('Kontakt ist im CMS nicht veröffentlicht – nur dort bearbeitbar.');
        [$in, $uid] = self::parse($t, null, (string) $cardData);
        if (\Core\Data\Kinds::isContacts($t)) $in[\Core\Data\VCards::UID] = $uid;   // UID der App am Kontakt (vCard-Export, Import-Abgleich)
        $in['status'] = Dav::tableSettings($t)['status'] === 'draft' || ($t['settings']['workflow'] && !Dav::can('data.publish', $t)) ? 'draft' : 'published';
        [$id, $errors] = Entries::save($t, null, $in);
        if ($errors) throw new BadRequest('Ungültige Eingaben: ' . implode(' ', $errors));
        Dav::saveObject('card', $t['handle'], (int) $id, (string) $cardUri, $uid, (string) $cardData);
        return null;
    }

    public function updateCard($addressBookId, $cardUri, $cardData)
    {
        $t = $this->table($addressBookId);
        if (!Dav::canWrite($t)) throw new Forbidden('Nur lesender Zugriff.');
        if (($key = self::groupKey($t, (string) $cardUri)) !== null) {
            self::applyGroup($t, $key, $this->entries($t), (string) $cardData);
            return null;
        }
        [$e, $o] = $this->find($t, (string) $cardUri);
        if (!$e) throw new NotFound('Kontakt nicht gefunden.');
        if (\Core\Data\Shared::isForeign($t, $e)) throw new Forbidden('Kontakt einer anderen Website – nur lesbar.');
        [$in, $uid] = self::parse($t, $e, (string) $cardData);
        [, $errors] = Entries::save($t, (int) $e['id'], $in);
        if ($errors) throw new BadRequest('Ungültige Eingaben: ' . implode(' ', $errors));
        Dav::saveObject('card', $t['handle'], (int) $e['id'], (string) $cardUri, $o['uid'] ?? $uid, (string) $cardData);
        return null;
    }

    public function deleteCard($addressBookId, $cardUri)
    {
        $t = $this->table($addressBookId);
        if (!Dav::canWrite($t)) throw new Forbidden('Nur lesender Zugriff.');
        if (self::groupKey($t, (string) $cardUri) !== null) throw new Forbidden('Listen werden im CMS gelöscht.');
        [$e, $o] = $this->find($t, (string) $cardUri);
        if (!$e) return false;
        if (\Core\Data\Shared::isForeign($t, $e)) throw new Forbidden('Kontakt einer anderen Website – nur lesbar.');
        if (Dav::tableSettings($t)['on_delete'] === 'delete' && Dav::can('data.delete', $t)) {
            Entries::delete($t, (int) $e['id']);
            app()->db->query('DELETE FROM dav_objects WHERE table_handle = ? AND entry_id = ?', [$t['handle'], (int) $e['id']]);
            return true;
        }
        Entries::setStatus($t, [(int) $e['id']], 'draft');
        Dav::saveObject('card', $t['handle'], (int) $e['id'], $o['uri'] ?? 'cms-' . (int) $e['id'] . '.vcf', $o['uid'] ?? self::uid($t, $e), $o['raw'] ?? null);
        app()->db->query('UPDATE dav_objects SET hidden = 1 WHERE table_handle = ? AND entry_id = ?', [$t['handle'], (int) $e['id']]);
        return true;
    }

    // ------------------------------------------------------------------ Zuordnung

    public static function uid(array $t, array $e): string
    {
        return \Core\Data\Calendar::uid($t, $e);
    }

    /** Felder vom Typ E-Mail/Telefon mit vCard-Typ */
    private static function typed(array $t, string $type): array
    {
        $out = [];
        foreach ($t['fields'] as $f) {
            if ($f['type'] !== $type) continue;
            $s = mb_strtolower($f['name'] . ' ' . $f['label']);
            $out[$f['name']] = match (true) {
                (bool) preg_match('~mobil|handy|cell~', $s) => 'CELL',
                str_contains($s, 'fax') => 'FAX',
                (bool) preg_match('~privat|home|zuhause~', $s) => 'HOME',
                default => 'WORK',
            };
        }
        return $out;
    }

    /** Name der X-Eigenschaft für ein Feld: X-MYCMS-FACHGEBIET */
    public static function xName(string $field): string
    {
        return 'X-MYCMS-' . strtoupper(str_replace('_', '-', $field));
    }

    /** Felder mit verschachtelten Werten (JSON: Blöcke, Kombifelder, Gruppen) – nicht als Text abbildbar, daher nie X-MYCMS-* */
    private const NESTED = ['blocks', 'mediatext', 'person', 'faq', 'address', 'downloads', 'group', 'recurrence', 'roles'];

    /** Felder, die als X-MYCMS-* ausgegeben werden (alles, was nicht zugeordnet ist, ohne verschachtelte Felder) */
    private static function extraFields(array $t, array $map): array
    {
        $used = \Core\Data\Kinds::isContacts($t) ? \Core\Data\Kinds::systemNames(\Core\Data\Kinds::CONTACTS)
            : array_merge(array_values($map), array_keys(self::typed($t, 'email')), array_keys(self::typed($t, 'tel')));
        return array_values(array_filter(\Core\Data\Exposure::appFields($t), fn($f) => !in_array($f['name'], $used, true) && !in_array($f['type'], self::NESTED, true)
            && !\Core\Combo::isMulti($f['type'])));
    }

    /**
     * Kontakt (Art contacts) → vCard ohne Zuordnung: Grundschema fest (Core\Data\VCards::card – dieselbe Abbildung wie der
     * vCard-Export), Mehrfachfelder beschriftet, Listen = CATEGORIES. $offset: Nummer der letzten Gruppe itemN der Zusatzzeilen.
     */
    private static function contactCard(array $t, array $e, VCard $card, int $offset = 0): void
    {
        \Core\Data\VCards::card($t, \Core\Data\Exposure::forApps($t, $e), $card, ['offset' => $offset]);
    }

    /** vCard → Felder eines Kontakts (Core\Data\VCards::fields): neue/geänderte Angaben sind nie von selbst öffentlich */
    private static function contactIn(array $t, ?array $e, VCard $card): array
    {
        return \Core\Data\VCards::fields($t, $e, $card);
    }

    private static function fieldText(array $f, mixed $v): string
    {
        return match (true) {
            $f['type'] === 'bool' => $v ? '1' : '0',
            $f['type'] === 'richtext' => self::plain($v),
            is_array($v) => implode(',', array_map('strval', $v)),
            default => (string) ($v ?? ''),
        };
    }

    /** vCard 3.0 eines Eintrags (mit Rohdaten des Clients zusammengeführt); $s = Einstellungen der Tabelle (Standard: gespeicherte) */
    public static function render(array $t, array $e, ?array $o, ?array $s = null): string
    {
        $s ??= Dav::tableSettings($t);
        $e = \Core\Data\Exposure::forApps($t, $e);           // „Nur Website“-Felder und Altfelder nie an Apps
        $map = $s['map'];
        $v = fn(string $k) => isset($map[$k]) && $map[$k] !== '' ? trim(strip_tags((string) ($e[$map[$k]] ?? ''))) : '';
        $card = new VCard(['VERSION' => '3.0', 'PRODID' => '-//KLXM//' . CMS_NAME . ' ' . CMS_VERSION . '//DE', 'UID' => $o['uid'] ?? self::uid($t, $e)]);
        $contacts = \Core\Data\Kinds::isContacts($t);
        // Kontakte: Zusatzzeilen aus vCard-Import bzw. App (_vcard_extra) – bekannt (auch leer) = maßgeblich statt der Rohdaten
        $extra = $contacts ? \Core\Data\VCards::extraProps(is_string($e[\Core\Data\VCards::EXTRA] ?? null) ? $e[\Core\Data\VCards::EXTRA] : null) : [];
        if ($contacts) {
            self::contactCard($t, $e, $card, \Core\Data\VCards::groupOffset($extra));
            $map = [];
        } else {
            $family = $v('n_family');
            $given = $v('n_given');
            $fn = $v('fn') ?: trim($given . ' ' . $family) ?: $v('org') ?: Entries::title($t, $e);
            $card->add('FN', $fn);
            // Nur „Name (FN)“ zugeordnet: N aus dem ganzen Namen ableiten (Nachname;Vorname;;Präfix;Suffix), damit Apps richtig sortieren
            $card->add('N', $family === '' && $given === '' && $v('fn') !== '' ? self::splitName($fn) : [$family, $given, '', '', '']);
            if ($v('org') !== '') $card->add('ORG', [isset($map['org']) && (Tables::field($t, $map['org'])['type'] ?? '') === 'select' ? Tables::optionLabel(Tables::field($t, $map['org']), $v('org')) : $v('org')]);
            elseif (empty($map['org']) && ($org = Dav::fixedOrg($s)) !== '') $card->add('ORG', [$org]);
            if ($v('title') !== '') $card->add('TITLE', $v('title'));
            foreach (self::typed($t, 'email') as $name => $type) {
                if (trim((string) ($e[$name] ?? '')) !== '') $card->add('EMAIL', trim((string) $e[$name]), ['TYPE' => ['INTERNET', $type === 'HOME' ? 'HOME' : 'WORK']]);
            }
            foreach (self::typed($t, 'tel') as $name => $type) {
                if (trim((string) ($e[$name] ?? '')) !== '') $card->add('TEL', trim((string) $e[$name]), ['TYPE' => $type === 'CELL' ? ['CELL', 'VOICE'] : [$type, $type === 'FAX' ? 'FAX' : 'VOICE']]);
            }
            if ($v('adr_street') . $v('adr_zip') . $v('adr_city') . $v('adr_country') !== '') {
                $country = $v('adr_country');
                if ($country !== '' && (Tables::field($t, $map['adr_country'])['type'] ?? '') === 'select') $country = Tables::optionLabel(Tables::field($t, $map['adr_country']), $country);
                $card->add('ADR', ['', '', $v('adr_street'), $v('adr_city'), '', $v('adr_zip'), $country], ['TYPE' => 'WORK']);
            }
            if ($v('url') !== '') $card->add('URL', link_href($v('url')));
            if (isset($map['note']) && $map['note'] !== '' && ($note = self::plain($e[$map['note']] ?? '')) !== '') $card->add('NOTE', $note);
            if ($v('bday') !== '' && preg_match('~^\d{4}-\d{2}-\d{2}$~', $v('bday'))) $card->add('BDAY', $v('bday'), ['VALUE' => 'DATE']);
            if (!empty($map['photo']) && !empty($e[$map['photo']]) && ($jpeg = self::photo((int) $e[$map['photo']]))) {
                $card->add('PHOTO', $jpeg, ['ENCODING' => 'b', 'TYPE' => 'JPEG']);
            }
            if (!empty($map['categories']) && ($cf = Tables::field($t, $map['categories']))) {
                $cats = array_values(array_filter(array_map(fn($k) => Tables::optionLabel($cf, (string) $k, Lang::default()), (array) ($e[$map['categories']] ?? []))));
                if ($cats) $card->add('CATEGORIES', $cats);
            }
        }
        foreach (self::extraFields($t, $map) as $f) {
            $val = self::fieldText($f, $e[$f['name']] ?? null);
            if ($val !== '' && $val !== '0' || $f['type'] === 'bool') $card->add(self::xName($f['name']), $val);
        }
        foreach ($extra as $p) $card->add(clone $p);
        $card->add('REV', gmdate('Ymd\THis\Z', strtotime((string) $e['updated_at']) ?: time()));
        if (empty($o['raw']) || ($contacts && is_string($e[\Core\Data\VCards::EXTRA] ?? null))) return $card->serialize();

        try {
            $raw = Reader::read($o['raw'], Reader::OPTION_FORGIVING);
        } catch (\Throwable) {
            return $card->serialize();
        }
        if (!$raw instanceof VCard) return $card->serialize();
        if ((string) $raw->VERSION !== '3.0') $raw = $raw->convert(\Sabre\VObject\Document::VCARD30);
        $managed = ['FN', 'N', 'ORG', 'TITLE', 'EMAIL', 'TEL', 'ADR', 'URL', 'NOTE', 'BDAY', 'REV', 'PRODID'];
        if (!empty($map['photo']) || $contacts) $managed[] = 'PHOTO';
        if (!empty($map['categories']) || $contacts) $managed[] = 'CATEGORIES';
        if ($contacts) array_push($managed, 'X-ABLABEL', 'X-ABSHOWAS');
        // Namenszusätze (Titel „Dr.“, Suffix) und Anzeigename der App behalten, solange Vor-/Nachname gleich sind
        $rawN = isset($raw->N) ? array_pad($raw->N->getParts(), 5, '') : null;
        $rawFn = isset($raw->FN) ? (string) $raw->FN : null;
        foreach ($managed as $p) $raw->remove($p);
        foreach ($raw->children() as $p) {
            if ($p instanceof \Sabre\VObject\Property && str_starts_with(strtoupper($p->name), 'X-MYCMS-')) $raw->remove($p);
        }
        foreach ($card->children() as $p) {
            if (in_array($p->name, ['VERSION', 'UID'], true)) continue;
            $raw->add(clone $p);
        }
        $newN = $card->N->getParts();
        if (!$contacts && $rawN && $rawN[0] === (string) ($newN[0] ?? '') && $rawN[1] === (string) ($newN[1] ?? '')) {
            $raw->remove('N');
            $raw->add('N', $rawN);
            if ($rawFn !== null && empty($map['fn'])) {
                $raw->remove('FN');
                $raw->add('FN', $rawFn);
            }
        }
        return $raw->serialize();
    }

    /** Bild aus der Mediathek als JPEG (max. 400 px) */
    private static function photo(int $id): ?string
    {
        return \Core\Data\VCards::photoJpeg($id);
    }

    /** vCard → Feldwerte; $s = Einstellungen der Tabelle (Standard: gespeicherte). @return array{0: array, 1: string} */
    public static function parse(array $t, ?array $e, string $data, ?array $s = null): array
    {
        try {
            $card = Reader::read($data, Reader::OPTION_FORGIVING);
        } catch (\Throwable $ex) {
            throw new BadRequest('Ungültige vCard: ' . $ex->getMessage());
        }
        if (!$card instanceof VCard) throw new BadRequest('Erwartet wird eine vCard.');
        if (\Core\Data\Kinds::isContacts($t)) {
            $in = self::contactIn($t, $e, $card);
            // Unbekannte Eigenschaften (Spitzname, Jahrestage …) verlustfrei am Eintrag – auch für vCard-Export und Netzwerk
            if (($x = \Core\Data\VCards::extra($t, $card)) !== null) $in[\Core\Data\VCards::EXTRA] = $x;
            $map = [];
        } else {
            $map = ($s ?? Dav::tableSettings($t))['map'];
            $in = [];
            $set = function (string $k, string $val) use (&$in, $map) { if (!empty($map[$k])) $in[$map[$k]] = trim($val); };
            $n = isset($card->N) ? $card->N->getParts() : [];
            $fn = trim((string) ($card->FN ?? ''));
            // Nur „Name (FN)“ zugeordnet: FN hat Vorrang (Pflicht in vCard 3/4). Ohne FN – oder wenn die App nur N geändert hat (FN wie
            // gespeichert, Vor-/Nachname anders) – den Namen aus N zusammensetzen
            if (!empty($map['fn']) && empty($map['n_family']) && empty($map['n_given'])) {
                $fromN = self::joinName($n);
                $cur = $e ? trim(strip_tags((string) ($e[$map['fn']] ?? ''))) : '';
                $was = self::splitName($cur);
                $nChanged = [trim((string) ($n[0] ?? '')), trim((string) ($n[1] ?? ''))] !== [$was[0], $was[1]];
                if ($fromN !== '' && ($fn === '' || ($cur !== '' && $fn === $cur && $nChanged))) $fn = $fromN;
            }
            $set('fn', $fn);
            $set('n_family', (string) ($n[0] ?? '') ?: (empty($map['n_given']) ? $fn : ''));
            $set('n_given', (string) ($n[1] ?? ''));
            $org = isset($card->ORG) ? (string) ($card->ORG->getParts()[0] ?? '') : '';
            if (!empty($map['org']) && (Tables::field($t, $map['org'])['type'] ?? '') === 'select') {
                $org = self::optionKey(Tables::field($t, $map['org']), [$org])[0] ?? '';
            }
            $set('org', $org);
            $set('title', (string) ($card->TITLE ?? ''));
            $adr = isset($card->ADR) ? $card->ADR->getParts() : [];
            $set('adr_street', (string) ($adr[2] ?? ''));
            $set('adr_city', (string) ($adr[3] ?? ''));
            $set('adr_zip', (string) ($adr[5] ?? ''));
            $country = (string) ($adr[6] ?? '');
            if (!empty($map['adr_country']) && (Tables::field($t, $map['adr_country'])['type'] ?? '') === 'select') $country = self::optionKey(Tables::field($t, $map['adr_country']), [$country])[0] ?? '';
            $set('adr_country', $country);
            $set('url', (string) ($card->URL ?? ''));
            if (!empty($map['note']) && ($nf = Tables::field($t, $map['note']))) {
                $note = self::textIn($nf, $e, (string) ($card->NOTE ?? ''));
                if ($note !== null) $in[$map['note']] = $note;
            }
            if (!empty($map['bday'])) {
                $b = (string) ($card->BDAY ?? '');
                $in[$map['bday']] = preg_match('~^(\d{4})-?(\d{2})-?(\d{2})~', $b, $m) ? "$m[1]-$m[2]-$m[3]" : '';
            }
            if (!empty($map['categories']) && ($cf = Tables::field($t, $map['categories'])) && isset($card->CATEGORIES)) {
                $keys = self::optionKey($cf, $card->CATEGORIES->getParts());
                $in[$map['categories']] = $cf['type'] === 'multiselect' ? $keys : ($keys[0] ?? '');
            }
            // E-Mail und Telefon: passender Typ zuerst, übrige der Reihe nach
            foreach (['email' => 'EMAIL', 'tel' => 'TEL'] as $type => $prop) {
                $fields = self::typed($t, $type);
                foreach ($fields as $name => $_) $in[$name] = '';
                $values = [];
                foreach ($card->select($prop) as $p) {
                    $types = array_map('strtoupper', isset($p['TYPE']) ? $p['TYPE']->getParts() : []);
                    $values[] = [trim(preg_replace('~^tel:~i', '', (string) $p)), $types];
                }
                foreach ($values as $i => [$val, $types]) {
                    foreach ($fields as $name => $ft) {
                        if ($in[$name] === '' && in_array($ft, $types, true)) { $in[$name] = $val; unset($values[$i]); break; }
                    }
                }
                foreach ($values as [$val]) {
                    foreach ($fields as $name => $ft) if ($in[$name] === '' && $ft !== 'FAX') { $in[$name] = $val; break; }
                }
            }
        }
        // Übrige Felder aus X-MYCMS-*
        $extra = [];
        foreach (self::extraFields($t, $map) as $f) $extra[self::xName($f['name'])] = $f;
        foreach ($card->children() as $p) {
            $name = strtoupper((string) $p->name);
            if (!isset($extra[$name])) continue;
            $f = $extra[$name];
            $val = (string) $p;
            if ($f['type'] === 'richtext') {
                // Ausgabe ist reiner Text: unverändert → Formatierung im CMS bleibt; geändert → Absätze/Umbrüche als HTML
                if (($val = self::textIn($f, $e, $val)) !== null) $in[$f['name']] = $val;
                continue;
            }
            $in[$f['name']] = match ($f['type']) {
                'bool' => in_array(strtolower($val), ['1', 'true', 'ja', 'yes'], true),
                'multiselect', 'relations' => array_values(array_filter(array_map('trim', explode(',', $val)), fn($x) => $x !== '')),
                default => $val,
            };
        }
        // „Nur Website“-Felder und Altfelder schreiben Apps nie (sie bekommen sie auch nicht)
        $apps = array_column(\Core\Data\Exposure::appFields($t, 'vcard'), 'name');
        foreach ($t['fields'] as $f) if (!in_array($f['name'], $apps, true)) unset($in[$f['name']]);
        $tf = $t['settings']['title_field'];
        $fn = trim((string) ($card->FN ?? ''));
        $org = isset($card->ORG) ? (string) ($card->ORG->getParts()[0] ?? '') : '';
        if (!\Core\Data\Kinds::isContacts($t) && $tf !== '' && trim((string) ($in[$tf] ?? $e[$tf] ?? '')) === '') $in[$tf] = $fn ?: ($org ?: '(ohne Namen)');
        return [$in, trim((string) ($card->UID ?? '')) ?: bin2hex(random_bytes(16))];
    }

    /** Feldwert als reiner Text für Apps: Absätze → Leerzeile, Umbrüche/Listenpunkte → Zeile, Entities dekodiert, ohne Redaktionsnotizen [# … #] */
    private static function plain(mixed $v): string
    {
        return \Core\Data\ICal::plain(\Core\EditorNotes::strip((string) ($v ?? '')));
    }

    /**
     * Text aus der App für ein Text-/Rich-Text-Feld (NOTE, X-MYCMS-* von Rich-Text): null, wenn er dem gespeicherten (als reiner Text)
     * entspricht – dann bleiben Formatierung und Redaktionsnotizen im CMS –, sonst der Wert (Rich-Text: Leerzeile → Absatz,
     * Zeilenumbruch → <br>, alles escaped); vorhandene Redaktionsnotizen werden wieder angehängt.
     */
    private static function textIn(array $f, ?array $e, string $text): ?string
    {
        $text = trim(str_replace("\r", '', $text));
        $cur = (string) ($e[$f['name']] ?? '');
        if ($e && $text === self::plain($cur)) return null;
        $notes = \Core\EditorNotes::find($cur);
        if ($f['type'] !== 'richtext') return trim($text . implode('', array_map(fn($n) => "\n\n[# $n #]", $notes)));
        return implode('', array_map(fn($p) => '<p>' . str_replace("\n", '<br>', e($p)) . '</p>', $text === '' ? [] : (preg_split("~\n{2,}~", $text) ?: [])))
            . implode('', array_map(fn($n) => '<p>[# ' . e($n) . ' #]</p>', $notes));
    }

    // ------------------------------------------------------------------ Namen

    /** Ganzer Name → N-Teile [Nachname, Vorname, weitere, Präfix, Suffix] (Core\Data\VCards::splitName) */
    public static function splitName(string $fn): array
    {
        return \Core\Data\VCards::splitName($fn);
    }

    /** N-Teile → ganzer Name (Core\Data\VCards::joinName) */
    public static function joinName(array $n): string
    {
        return \Core\Data\VCards::joinName($n);
    }

    /** Auswahl-Kurznamen zu Texten (Kurzname, Beschriftung oder Übersetzung, ohne Groß/klein) */
    private static function optionKey(array $f, array $texts): array
    {
        $wanted = array_map(fn($x) => mb_strtolower(trim((string) $x)), $texts);
        $out = [];
        foreach ((array) ($f['options'] ?? []) as $k => $label) {
            $names = array_map('mb_strtolower', array_merge([(string) $k, (string) $label], array_map(fn($l) => (string) ($l[$k] ?? ''), (array) ($f['options_i18n'] ?? []))));
            if (array_intersect($names, $wanted)) $out[] = (string) $k;
        }
        return $out;
    }
}
