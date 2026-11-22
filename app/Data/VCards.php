<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
// Teile übernommen aus KLXM/contacts (MIT, © 2026 KLXM Crossmedia GmbH): VCard/Parser, VCard/Serializer, VCard/Labels, Import/Importer
declare(strict_types=1);

namespace Core\Data;

use Core\Combo;
use Core\Lang;
use Core\Media;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * vCard-Austausch für Adressbücher (Tabellenart contacts, Core\Data\Kinds): Import mit Probelauf und Export (einzeln, je Liste,
 * gesamt) – dieselbe Abbildung wie CardDAV (Erweiterung „dav“ ruft card()/fields() auf).
 *
 * Abbildung aufs Grundschema: N/FN → Titel, Vor- und Nachname (bzw. Anzeigename), ORG → Organisation/Abteilung, X-ABShowAs:COMPANY →
 * „Ist eine Organisation“, TITLE → Funktion, TEL/EMAIL/ADR/URL → Mehrfachfelder mit Beschriftung (vCard-TYPE bzw. Apple-Beschriftung
 * itemN.X-ABLabel, sonst „eigene“), NOTE, BDAY, PHOTO → Mediathek (nur eingebettete Bilder, keine Abrufe fremder Adressen),
 * CATEGORIES und Gruppen-Karten (X-ADDRESSBOOKSERVER-KIND:group bzw. KIND:group mit MEMBER) → Listen. Eigene Zusatzfelder reisen
 * als X-MYCMS-{FELD} (wie bei CardDAV).
 *
 * Alles andere (Spitzname, Jahrestage, soziale Profile, Felder anderer Programme …) bleibt verlustfrei in der Spalte _vcard_extra
 * (vCard-Zeilen, wie gelesen) und geht beim Export und über CardDAV unverändert wieder hinaus. Es erscheint nie auf der Website.
 *
 * Abgleich über die UID (Spalte _uid; eigene Exporte tragen die UID des CMS „{tabelle}-{id}@{host}“): vorhandene Kontakte werden
 * aktualisiert statt doppelt angelegt. Neue Angaben sind nie von selbst öffentlich („Öffentlich“ bleibt nur bei unverändertem Wert),
 * neue Kontakte stehen nicht auf der Website.
 */
final class VCards
{
    public const EXTRA = '_vcard_extra';
    public const UID = Series::UID;
    public const MAX_CARDS = 5000;
    public const MAX_EXTRA = 65536;
    /** Größe eines eingebetteten Fotos höchstens (Bytes) */
    public const MAX_PHOTO = 8 * 1024 * 1024;
    /** vCard-Eigenschaft je Mehrfachfeld des Grundschemas */
    public const MULTI_PROPS = ['telefon' => ['phones', 'TEL'], 'email' => ['emails', 'EMAIL'], 'adresse' => ['addresses', 'ADR'], 'web' => ['urls', 'URL']];
    /** Eigenschaften, die das Grundschema abbildet (ohne Gruppe) – der Rest reist in _vcard_extra */
    public const MANAGED = ['VERSION', 'PRODID', 'UID', 'FN', 'N', 'ORG', 'TITLE', 'TEL', 'EMAIL', 'ADR', 'URL', 'NOTE', 'BDAY', 'PHOTO', 'CATEGORIES', 'REV',
        'X-ABSHOWAS', 'X-ADDRESSBOOKSERVER-KIND', 'X-ADDRESSBOOKSERVER-MEMBER', 'KIND', 'MEMBER'];
    /** Felder mit verschachtelten Werten – nie als X-MYCMS-* */
    private const NESTED = ['blocks', 'mediatext', 'person', 'faq', 'address', 'downloads', 'group', 'recurrence', 'roles', 'relation', 'relations', 'media', 'file', 'hotspots', 'geo'];

    // ================================================================== Lesen

    /**
     * Datei → einzelne vCards (3.0; vCard 4 und 2.1 werden umgewandelt). Mehr als MAX_CARDS → Fehler.
     * @return list<VCard>
     * @throws \RuntimeException mit lesbarer Meldung
     */
    public static function split(string $data): array
    {
        $data = self::clean($data);
        if (!preg_match('~^\s*BEGIN:VCARD~i', $data)) throw new \RuntimeException(__('Keine vCard-Datei (erwartet wird „BEGIN:VCARD“ am Anfang).'));
        $out = [];
        try {
            $stream = fopen('php://memory', 'r+') ?: throw new \RuntimeException(__('Die vCard-Datei ist fehlerhaft: {error}', ['error' => 'stream']));
            fwrite($stream, $data);
            rewind($stream);
            $sp = new \Sabre\VObject\Splitter\VCard($stream, Reader::OPTION_FORGIVING);
            while ($c = $sp->getNext()) {
                if (!$c instanceof VCard) continue;
                if (count($out) >= self::MAX_CARDS) throw new \RuntimeException(__('Zu viele Kontakte in der Datei (höchstens {n}).', ['n' => self::MAX_CARDS]));
                $v = strtoupper((string) ($c->VERSION ?? '3.0'));
                if ($v !== '3.0') {
                    try {
                        $c = $c->convert(\Sabre\VObject\Document::VCARD30);
                    } catch (\Throwable) {
                    }
                }
                $out[] = $c;
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException(__('Die vCard-Datei ist fehlerhaft: {error}', ['error' => mb_strimwidth($e->getMessage(), 0, 160, '…')]));
        }
        return $out;
    }

    /** BOM, NUL und Zeilenenden vereinheitlichen */
    public static function clean(string $data): string
    {
        if (str_starts_with($data, "\xEF\xBB\xBF")) $data = substr($data, 3);
        if (!mb_check_encoding($data, 'UTF-8')) $data = mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
        return str_replace("\0", '', $data);
    }

    /** Gruppen-Karte (Apple: X-ADDRESSBOOKSERVER-KIND:group, vCard 4: KIND:group)? */
    public static function isGroup(VCard $c): bool
    {
        return strtolower(trim((string) ($c->{'X-ADDRESSBOOKSERVER-KIND'} ?? $c->KIND ?? ''))) === 'group';
    }

    /** UIDs der Mitglieder einer Gruppen-Karte (klein geschrieben) */
    public static function members(VCard $c): array
    {
        $out = [];
        foreach ([...$c->select('X-ADDRESSBOOKSERVER-MEMBER'), ...$c->select('MEMBER')] as $p) {
            $u = strtolower(trim((string) preg_replace('~^urn:uuid:~i', '', trim((string) $p))));
            if ($u !== '') $out[] = $u;
        }
        return array_values(array_unique($out));
    }

    /** Text aus einer Datei: ohne HTML (wie beim Speichern von Textfeldern) und Steuerzeichen, begrenzt */
    public static function txt(mixed $v, int $max = 255): string
    {
        $s = trim((string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', str_replace("\r", '', strip_tags((string) $v))));
        return mb_substr($s, 0, $max);
    }

    /**
     * vCard → Felder eines Kontakts (Art contacts). Mehrfachfelder: Beschriftung aus TYPE bzw. X-ABLabel (eigene Beschriftung,
     * wenn unbekannt); „Öffentlich“ nur, wenn dieselbe Angabe vorher schon öffentlich war (neue/geänderte Angaben sind intern).
     * Listen: nur vorhandene (unbekannte Namen liefert categories()).
     */
    public static function fields(array $t, ?array $e, VCard $card): array
    {
        $in = [];
        $n = isset($card->N) ? array_pad($card->N->getParts(), 5, '') : ['', '', '', '', ''];
        $fn = self::txt($card->FN ?? '');
        $org = isset($card->ORG) ? array_pad($card->ORG->getParts(), 2, '') : ['', ''];
        $in['nachname'] = self::txt($n[0]);
        $in['vorname'] = self::txt($n[1]);
        $in['praefix'] = self::txt($n[3]);
        $in['firma'] = self::txt($org[0]);
        $in['abteilung'] = self::txt($org[1]);
        $in['position'] = self::txt($card->TITLE ?? '');
        $in['ist_firma'] = strtoupper(trim((string) ($card->{'X-ABSHOWAS'} ?? ''))) === 'COMPANY';
        // Anzeigename: FN der App, außer er ist nur „Vorname Nachname“ (dann bildet ihn das CMS selbst)
        $in['name'] = $fn !== '' && $fn !== trim($in['vorname'] . ' ' . $in['nachname']) && $fn !== trim($in['praefix'] . ' ' . $in['vorname'] . ' ' . $in['nachname']) ? $fn : '';
        $labels = [];
        foreach ($card->children() as $p) {
            if ($p instanceof Property && strtoupper($p->name) === 'X-ABLABEL' && $p->group) $labels[strtolower((string) $p->group)] = (string) $p;
        }
        foreach (self::MULTI_PROPS as $field => [$type, $prop]) {
            if ((Tables::field($t, $field)['type'] ?? '') !== $type) continue;
            $before = [];
            foreach (Combo::items($e[$field] ?? null) as $i) $before[self::itemKey($type, $i)] = $i['public'] ?? false;
            $items = [];
            foreach ($card->select($prop) as $p) {
                $types = array_map('strtoupper', isset($p['TYPE']) ? $p['TYPE']->getParts() : []);
                $ab = $p->group ? ($labels[strtolower((string) $p->group)] ?? null) : null;
                $item = ['label' => self::labelFrom($type, $types, $ab)];
                if ($item['label'] === Combo::CUSTOM) $item['label_custom'] = mb_substr(self::appleLabel((string) $ab), 0, 60);
                if ($prop === 'ADR') {
                    $a = array_pad($p->getParts(), 7, '');
                    $item += ['street' => self::txt($a[2]), 'zip' => self::txt($a[5], 20), 'city' => self::txt($a[3]), 'country' => self::txt($a[6])];
                } else {
                    $item['value'] = self::txt(preg_replace('~^(tel|mailto):~i', '', (string) $p) ?? '');
                    // Webadressen nur http(s) – „javascript:“ & Co. fallen weg
                    if ($prop === 'URL' && !preg_match('~^https?://[^\s<>"]+$~i', $item['value'])) continue;
                }
                if (Combo::itemText($type, $item) === '') continue;
                $item['public'] = $before[self::itemKey($type, $item)] ?? ($type === 'addresses' ? '0' : false);
                $items[] = $item;
            }
            $in[$field] = $items;
        }
        $in['notiz'] = self::txt($card->NOTE ?? '', 10000);
        $b = (string) ($card->BDAY ?? '');
        $in['geburtstag'] = preg_match('~^(\d{4})-?(\d{2})-?(\d{2})~', $b, $m) ? "$m[1]-$m[2]-$m[3]" : '';
        if (($lf = Tables::field($t, 'listen')) && isset($card->CATEGORIES)) $in['listen'] = self::optionKey($lf, $card->CATEGORIES->getParts());
        if (!$e) $in[Kinds::PUBLIC_FIELD] = false;   // neu: nie von selbst auf der Website
        return $in;
    }

    /** Namen aus CATEGORIES (getrimmt, ohne leere) */
    public static function categories(VCard $card): array
    {
        $out = [];
        foreach ($card->select('CATEGORIES') as $p) foreach ($p->getParts() as $x) if (($x = self::txt($x, 80)) !== '') $out[mb_strtolower($x)] = $x;
        return array_values($out);
    }

    /**
     * Eigenschaften, die das Grundschema nicht abbildet, als vCard-Zeilen (wie gelesen, gefaltet, CRLF) – gruppierte Zeilen bleiben
     * zusammen; X-MYCMS-* von Feldern der Tabelle und Beschriftungen abgebildeter Angaben gehören nicht dazu. '' = nichts übrig,
     * null = zu groß (dann bleibt der bisherige Stand).
     */
    public static function extra(array $t, VCard $card): ?string
    {
        $own = array_flip(array_map(fn($f) => self::xName((string) $f['name']), (array) ($t['fields'] ?? [])));
        $managedGroups = [];
        foreach ($card->children() as $p) {
            if ($p instanceof Property && $p->group && in_array(strtoupper($p->name), ['TEL', 'EMAIL', 'ADR', 'URL'], true)) $managedGroups[strtolower((string) $p->group)] = true;
        }
        $out = '';
        foreach ($card->children() as $p) {
            if (!$p instanceof Property) continue;
            $name = strtoupper($p->name);
            $g = $p->group ? strtolower((string) $p->group) : '';
            if ($g !== '' && isset($managedGroups[$g])) continue;            // itemN.TEL + itemN.X-ABLabel: abgebildet
            if ($g === '' && in_array($name, self::MANAGED, true)) continue;
            if ($g === '' && $name === 'X-ABLABEL') continue;
            if (isset($own[$name])) continue;
            $out .= $p->serialize();
        }
        return strlen($out) > self::MAX_EXTRA ? null : $out;
    }

    /** Zeilen aus _vcard_extra als Eigenschaften (fehlerhafte Zeilen fallen weg) @return list<Property> */
    public static function extraProps(?string $extra): array
    {
        if ($extra === null || trim($extra) === '') return [];
        try {
            $c = Reader::read("BEGIN:VCARD\r\nVERSION:3.0\r\n" . rtrim($extra, "\r\n") . "\r\nEND:VCARD\r\n", Reader::OPTION_FORGIVING);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($c->children() as $p) if ($p instanceof Property && !in_array(strtoupper($p->name), ['VERSION'], true)) $out[] = $p;
        return $out;
    }

    /** Höchste Nummer einer Gruppe „itemN“ in den Zusatzzeilen (abgebildete Angaben zählen dahinter weiter) */
    public static function groupOffset(array $props): int
    {
        $max = 0;
        foreach ($props as $p) if ($p->group && preg_match('~^item(\d+)$~i', (string) $p->group, $m)) $max = max($max, (int) $m[1]);
        return $max;
    }

    // ================================================================== Schreiben

    /**
     * Kontakt (Art contacts) → vCard-Eigenschaften des Grundschemas: Name, Organisation, Mehrfachfelder beschriftet, Notiz,
     * Geburtstag, Foto, Listen = CATEGORIES. $e ist für das Ziel schon bereinigt. $o: offset (Nummer der ersten Gruppe itemN − 1),
     * photo (false = ohne Foto)
     */
    public static function card(array $t, array $e, VCard $card, array $o = []): void
    {
        $s = fn(string $k) => trim(strip_tags((string) (is_scalar($e[$k] ?? null) ? $e[$k] : '')));
        $isOrg = !empty($e['ist_firma']) && $s('firma') !== '';
        $fn = $s('name') ?: Entries::title($t, $e);
        $card->add('FN', $fn);
        $card->add('N', $isOrg ? ['', '', '', '', ''] : ($s('nachname') . $s('vorname') !== '' ? [$s('nachname'), $s('vorname'), '', $s('praefix'), ''] : self::splitName($fn)));
        if ($s('firma') !== '') $card->add('ORG', array_values(array_filter([$s('firma'), $s('abteilung')], fn($x) => $x !== '')));
        if ($isOrg) $card->add('X-ABSHOWAS', 'COMPANY');
        if ($s('position') !== '') $card->add('TITLE', $s('position'));
        $n = (int) ($o['offset'] ?? 0);
        foreach (self::MULTI_PROPS as $field => [$type, $prop]) {
            if ((Tables::field($t, $field)['type'] ?? '') !== $type) continue;
            foreach (Combo::items($e[$field] ?? null) as $i) {
                if (Combo::itemText($type, $i) === '') continue;
                $val = $prop === 'ADR' ? ['', '', trim((string) ($i['street'] ?? '')), trim((string) ($i['city'] ?? '')), '', trim((string) ($i['zip'] ?? '')), trim((string) ($i['country'] ?? ''))]
                    : trim((string) $i['value']);
                $types = Combo::VCARD_TYPES[$type][(string) ($i['label'] ?? '')] ?? null;
                if ($types !== null) {
                    $card->add($prop, $val, ['TYPE' => $types]);
                    continue;
                }
                $n++;
                // Gruppe „itemN.“: Wert und Apple-Beschriftung gehören zusammen
                $card->add("item$n.$prop", $val, $prop === 'EMAIL' ? ['TYPE' => ['INTERNET']] : []);
                $card->add("item$n.X-ABLABEL", Combo::labelText($type, $i, true));
            }
        }
        if (($note = $s('notiz')) !== '' && Tables::field($t, 'notiz')) $card->add('NOTE', $note);
        if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $s('geburtstag'))) $card->add('BDAY', $s('geburtstag'), ['VALUE' => 'DATE']);
        if (($o['photo'] ?? true) && !empty($e['foto']) && ($jpeg = self::photoJpeg((int) $e['foto']))) $card->add('PHOTO', $jpeg, ['ENCODING' => 'b', 'TYPE' => 'JPEG']);
        if (($lf = Tables::field($t, 'listen')) && ($cats = array_values(array_filter(array_map(fn($k) => Tables::optionLabel($lf, (string) $k, Lang::default()), (array) ($e['listen'] ?? [])))))) {
            $card->add('CATEGORIES', $cats);
        }
    }

    /** Eigene Zusatzfelder, die als X-MYCMS-* reisen (an Apps freigegeben, keine Grundfelder, keine verschachtelten Werte) */
    public static function ownFields(array $t): array
    {
        $sys = Kinds::systemNames(Kinds::CONTACTS);
        return array_values(array_filter(Exposure::appFields($t, 'vcard'), fn($f) => !in_array($f['name'], $sys, true) && !in_array($f['type'], self::NESTED, true)
            && !Combo::isMulti($f['type']) && !in_array($f['type'], Tables::LAYOUT, true)));
    }

    public static function xName(string $field): string
    {
        return 'X-MYCMS-' . strtoupper(str_replace('_', '-', $field));
    }

    /** UID eines Kontakts: gespeicherte (aus App oder Import), sonst die des CMS */
    public static function uid(array $t, array $e): string
    {
        return Calendar::uid($t, $e);
    }

    /**
     * vollständige vCard 3.0 eines Kontakts für den Export (Redaktion: alle Angaben außer „Nur Website“) inkl. eigener Zusatzfelder
     * (X-MYCMS-*) und der unveränderten Zusatzzeilen (_vcard_extra).
     */
    public static function export(array $t, array $e): string
    {
        $e = Exposure::forApps($t, $e, 'vcard');
        $card = new VCard(['VERSION' => '3.0', 'PRODID' => ICal::prodId(), 'UID' => self::uid($t, $e)]);
        $extra = self::extraProps(is_string($e[self::EXTRA] ?? null) ? $e[self::EXTRA] : null);
        self::card($t, $e, $card, ['offset' => self::groupOffset($extra)]);
        foreach (self::ownFields($t) as $f) {
            $v = $e[$f['name']] ?? null;
            $val = match (true) {
                $f['type'] === 'bool' => $v ? '1' : '0',
                $f['type'] === 'richtext' => ICal::plain(\Core\EditorNotes::strip((string) ($v ?? ''))),
                is_array($v) => implode(',', array_map('strval', $v)),
                default => \Core\EditorNotes::strip((string) ($v ?? '')),
            };
            if (($val !== '' && $val !== '0') || $f['type'] === 'bool') $card->add(self::xName($f['name']), $val);
        }
        foreach ($extra as $p) $card->add(clone $p);
        $card->add('REV', gmdate('Ymd\THis\Z', strtotime((string) ($e['updated_at'] ?? '')) ?: time()));
        return $card->serialize();
    }

    /** Werte eigener Zusatzfelder aus X-MYCMS-* */
    public static function ownIn(array $t, ?array $e, VCard $card): array
    {
        $in = [];
        $by = [];
        foreach (self::ownFields($t) as $f) $by[self::xName($f['name'])] = $f;
        foreach ($card->children() as $p) {
            if (!$p instanceof Property || $p->group || !isset($by[strtoupper($p->name)])) continue;
            $f = $by[strtoupper($p->name)];
            $val = self::txt((string) $p, 10000);
            $in[$f['name']] = match ($f['type']) {
                'bool' => in_array(strtolower($val), ['1', 'true', 'ja', 'yes'], true),
                'multiselect' => self::optionKey($f, array_map('trim', explode(',', $val))),
                'select' => self::optionKey($f, [$val])[0] ?? '',
                'richtext' => $e && $val === ICal::plain((string) ($e[$f['name']] ?? '')) ? $e[$f['name']]
                    : implode('', array_map(fn($x) => '<p>' . str_replace("\n", '<br>', e($x)) . '</p>', $val === '' ? [] : (preg_split("~\n{2,}~", $val) ?: []))),
                'url' => preg_match('~^https?://~i', $val) ? $val : '',
                default => $val,
            };
        }
        return $in;
    }

    // ================================================================== Hilfen (gemeinsam mit CardDAV)

    /** Vergleichsschlüssel einer Angabe (Ziffern einer Nummer, E-Mail klein, Adresse ohne Leerraum) */
    public static function itemKey(string $type, array $i): string
    {
        $v = Combo::itemText($type, $i);
        return $type . ':' . ($type === 'phones' ? preg_replace('~[^\d+]~', '', $v) : mb_strtolower((string) preg_replace('~\s+~u', '', $v)));
    }

    /** Beschriftung aus vCard-TYPE bzw. X-ABLabel (Teile übernommen aus KLXM/contacts VCard/Labels, MIT) */
    public static function labelFrom(string $type, array $types, ?string $ab): string
    {
        if ($ab !== null && trim($ab) !== '') {
            $text = self::appleLabel($ab);
            foreach (Combo::LABELS[$type] as $k => $de) if (mb_strtolower($text) === mb_strtolower($de) || mb_strtolower($text) === mb_strtolower(lt($de)) || strtolower($text) === $k) return $k;
            return Combo::CUSTOM;
        }
        $has = fn(string $x) => in_array($x, $types, true);
        return match ($type) {
            'phones' => match (true) { $has('FAX') => 'fax', $has('CELL') || $has('IPHONE') => 'mobile', $has('MAIN') => 'main', $has('HOME') => 'home', $has('WORK') => 'work', default => 'other' },
            'urls' => $has('HOME') ? 'home' : ($has('WORK') ? 'work' : 'homepage'),
            default => $has('HOME') ? 'home' : ($has('WORK') ? 'work' : 'other'),
        };
    }

    /** Apple-Beschriftung „_$!<Mobile>!$_“ → „Mobile“, sonst der Text */
    public static function appleLabel(string $ab): string
    {
        $ab = trim($ab);
        if (preg_match('~^_\$!<(.+)>!\$_$~', $ab, $m)) {
            return ['Mobile' => 'mobile', 'Home' => 'home', 'Work' => 'work', 'Main' => 'main', 'Other' => 'other', 'HomePage' => 'homepage'][$m[1]] ?? $m[1];
        }
        return $ab;
    }

    /** Auswahl-Kurznamen zu Texten (Kurzname, Beschriftung oder Übersetzung, ohne Groß/klein) */
    public static function optionKey(array $f, array $texts): array
    {
        $wanted = array_map(fn($x) => mb_strtolower(trim((string) $x)), $texts);
        $out = [];
        foreach ((array) ($f['options'] ?? []) as $k => $label) {
            $names = array_map('mb_strtolower', array_merge([(string) $k, (string) $label], array_map(fn($l) => (string) ($l[$k] ?? ''), (array) ($f['options_i18n'] ?? []))));
            if (array_intersect($names, $wanted)) $out[] = (string) $k;
        }
        return $out;
    }

    /** Titel/Anrede vor dem Namen: Dr., Prof., Dr. med., Dipl.-Ing., Priv.-Doz., h. c., Herr, Frau … */
    public static function isPrefix(string $w): bool
    {
        return (bool) preg_match('~^(?:(?:dr|prof|med|dent|vet|rer|nat|pol|oec|phil|jur|theol|habil|soc|sc|mult|ing|mag|apl|univ|hon|em|hr|fr|mr|mrs|ms)\.'
            . '|(?:dr|prof)\.(?:\p{L}+\.)+|\p{L}+\.-\p{L}+\.?|h\.c\.|e\.h\.|herr|frau|pd)$~iu', $w);
    }

    /** Zusatz nach dem Namen (nach Komma oder am Ende): M.A., B.Sc., MBA, LL.M., Ph.D., jun., sen., III … */
    public static function isSuffix(string $w): bool
    {
        return (bool) preg_match('~^(?:(?:[mb]\.\s?(?:a|sc|eng|ed)\.|[mb]sc|mba|ll\.\s?[mb]\.|ph\.\s?d\.|phd|mdb|mdl|jun\.|jr\.|sen\.|sr\.|ii|iii|iv)[\s,]*)+$~iu', $w);
    }

    /** Nachnamen-Partikel (nur klein geschrieben): von, van, de, zu, von der, van den, … */
    public static function isParticle(string $w): bool
    {
        return in_array($w, ['von', 'van', 'vom', 'zu', 'zum', 'zur', 'und', 'de', 'der', 'den', 'des', 'del', 'della', 'di', 'da', 'du', 'dos', 'das',
            'le', 'la', 'ten', 'ter', 'op', 'af', 'av', 'y'], true);
    }

    /**
     * Ganzer Name → N-Teile [Nachname, Vorname, weitere, Präfix, Suffix]: letztes Wort = Nachname (mit Partikeln davor: „von der Leyen“),
     * der Rest Vorname; führende Titel/Anreden als Präfix, Zusätze am Ende als Suffix; „Musterfrau, Erika“ = Nachname, Vorname.
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    public static function splitName(string $fn): array
    {
        $fn = trim((string) preg_replace('~\s+~u', ' ', $fn));
        if ($fn === '') return ['', '', '', '', ''];
        $suffix = [];
        $family = null;
        if (preg_match('~^(.+?),\s*(.+)$~u', $fn, $m)) {
            if (self::isSuffix($m[2])) {
                [$fn, $suffix] = [$m[1], [$m[2]]];
            } elseif (!str_contains($m[2], ',')) {
                [$family, $fn] = [$m[1], $m[2]];          // „Musterfrau, Erika“ bzw. „Musterfrau, Dr. Erika“
            }
        }
        $w = explode(' ', $fn);
        $prefix = [];
        while (count($w) > ($family === null ? 1 : 0) && self::isPrefix($w[0])) $prefix[] = array_shift($w);
        if ($family === null) {
            while (count($w) > 1 && self::isSuffix((string) end($w))) array_unshift($suffix, (string) array_pop($w));
            $last = [(string) array_pop($w)];
            while (count($w) > 1 && self::isParticle((string) end($w))) array_unshift($last, (string) array_pop($w));
            $family = implode(' ', $last);
        }
        return [$family, implode(' ', $w), '', implode(' ', $prefix), implode(' ', $suffix)];
    }

    /** N-Teile → ganzer Name („Prof. Dr. Erika Musterfrau, M.A.“); leer ohne Vor- und Nachname */
    public static function joinName(array $n): string
    {
        $n = array_map(fn($x) => trim((string) $x), array_pad(array_values($n), 5, ''));
        if ($n[0] === '' && $n[1] === '') return '';
        $name = implode(' ', array_filter([$n[3], $n[1], $n[2], $n[0]], fn($x) => $x !== ''));
        return $n[4] !== '' ? $name . ', ' . $n[4] : $name;
    }

    /** Bild aus der Mediathek als JPEG (max. 400 px) – Foto in vCards */
    public static function photoJpeg(int $id): ?string
    {
        $m = Media::find($id);
        if (!$m || !str_starts_with((string) $m['mime'], 'image/')) return null;
        $dir = !empty($m['_pool']) ? \Core\MediaPools::mediaDir((string) $m['_pool']) : site()->mediaDir();
        $file = rtrim($dir, '/') . '/' . $m['file'];
        if (!is_file($file) || !function_exists('imagecreatefromstring')) return null;
        $img = @imagecreatefromstring((string) file_get_contents($file));
        if (!$img) return null;
        $w = imagesx($img);
        $h = imagesy($img);
        $s = min(1, 400 / max($w, $h));
        $out = imagecreatetruecolor(max(1, (int) round($w * $s)), max(1, (int) round($h * $s)));
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
        ob_start();
        imagejpeg($out, null, 82);
        return (string) ob_get_clean();
    }

    // ================================================================== Export

    /** vCards mehrerer Kontakte (Redaktion, alle Angaben außer „Nur Website“) @param list<int> $ids */
    public static function exportMany(array $t, array $ids): string
    {
        $out = '';
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 200) as $chunk) {
            foreach (Entries::query($t, ['ids' => $chunk, 'status' => 'all', 'internal' => true, 'lang' => 'all', 'source' => 'own']) as $e) $out .= self::export($t, $e);
        }
        return $out;
    }

    // ================================================================== Import

    /**
     * vCard-Datei abgleichen. $o: dry (Probelauf, nichts wird geschrieben), status (published|draft für neue Kontakte),
     * lists (neue Listen anlegen erlaubt), photos (Fotos übernehmen, Standard an), note (Text der Revision).
     * @return array{ok: bool, error: ?string, total: int, created: int, updated: int, unchanged: int, skipped: int, failed: int,
     *     groups: int, photos: int, new_lists: list<string>, missing_lists: list<string>, rows: list<array>, warnings: list<string>}
     */
    public static function import(array $t, string $data, array $o = []): array
    {
        $dry = !empty($o['dry']);
        $rep = ['ok' => false, 'error' => null, 'total' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'groups' => 0,
            'photos' => 0, 'new_lists' => [], 'missing_lists' => [], 'rows' => [], 'warnings' => []];
        if (!Kinds::isContacts($t)) {
            $rep['error'] = __('vCard-Import gibt es für Adressbücher (Tabellenart „Adressbuch“).');
            return $rep;
        }
        try {
            $cards = self::split($data);
        } catch (\RuntimeException $e) {
            $rep['error'] = $e->getMessage();
            return $rep;
        }
        // Gruppen-Karten → Listen der Mitglieder; übrige Karten = Kontakte
        $groupOf = [];
        $people = [];
        foreach ($cards as $c) {
            if (!self::isGroup($c)) { $people[] = $c; continue; }
            $rep['groups']++;
            $name = self::txt($c->FN ?? '', 80);
            if ($name === '') continue;
            foreach (self::members($c) as $u) $groupOf[$u][mb_strtolower($name)] = $name;
        }
        $rep['total'] = count($people);
        if (!$people) {
            $rep['error'] = __('Die Datei enthält keine Kontakte.');
            return $rep;
        }
        // Listen: vorhandene zuordnen, fehlende anlegen (im Probelauf nur melden)
        $lf = Tables::field($t, 'listen');
        $wanted = [];
        foreach ($people as $c) {
            foreach (self::categories($c) as $x) $wanted[mb_strtolower($x)] = $x;
            foreach ($groupOf[strtolower(self::txt($c->UID ?? ''))] ?? [] as $k => $x) $wanted[$k] = $x;
        }
        $known = $lf ? array_map('mb_strtolower', AddressBook::lists($t)) : [];
        foreach ($wanted as $k => $label) {
            if (in_array($k, $known, true) || ($lf && self::optionKey($lf, [$label]))) continue;
            if (!$lf || empty($o['lists'])) { $rep['missing_lists'][] = $label; continue; }
            $rep['new_lists'][] = $label;
            if (!$dry) {
                $res = AddressBook::addList($t, $label);
                if (isset($res['error'])) $rep['warnings'][] = __('Liste „{list}“: {error}', ['list' => $label, 'error' => $res['error']]);
                $t = Tables::find($t['handle']) ?? $t;
            }
        }
        $lf = Tables::field($t, 'listen');
        $byUid = self::uidIndex($t);
        $seen = [];
        $status = ($o['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
        foreach ($people as $c) {
            $uid = self::txt($c->UID ?? '', 255);
            $name = self::txt($c->FN ?? '') ?: trim(implode(' ', array_slice(array_pad(isset($c->N) ? $c->N->getParts() : [], 2, ''), 0, 2))) ?: __('(ohne Namen)');
            $row = ['name' => $name, 'uid' => $uid, 'action' => '', 'detail' => '', 'warnings' => []];
            try {
                if ($uid !== '' && isset($seen[strtolower($uid)])) {
                    $row['action'] = 'skipped';
                    $row['detail'] = __('UID doppelt in der Datei – nur die erste Karte zählt.');
                    $rep['skipped']++;
                    continue;
                }
                if ($uid !== '') $seen[strtolower($uid)] = true;
                $id = $uid !== '' ? ($byUid[strtolower($uid)] ?? null) : null;
                $cur = $id ? Entries::find($t, $id) : null;
                if ($cur && Tables::isShared($t) && Shared::isForeign($t, $cur)) {
                    $row['action'] = 'skipped';
                    $row['detail'] = __('Kontakt einer anderen Website – nur lesbar.');
                    $rep['skipped']++;
                    continue;
                }
                $in = self::fields($t, $cur, $c) + self::ownIn($t, $cur, $c);
                // Listen: CATEGORIES + Gruppen-Karten; vorhandene Zuordnungen bleiben
                if ($lf) {
                    $names = [...self::categories($c), ...array_values($groupOf[strtolower($uid)] ?? [])];
                    $keys = self::optionKey($lf, $names);
                    if ($dry) foreach ($names as $nm) if (!self::optionKey($lf, [$nm]) && in_array($nm, $rep['new_lists'], true)) $keys[] = '+' . $nm;
                    $in['listen'] = array_values(array_unique([...array_map('strval', (array) ($cur['listen'] ?? [])), ...$keys]));
                    if ($dry) $in['listen'] = array_values(array_filter($in['listen'], fn($k) => !str_starts_with($k, '+')));
                }
                $extra = self::extra($t, $c);
                if ($extra === null) $row['warnings'][] = __('Zusatzangaben zu groß – nicht übernommen.');
                else $in[self::EXTRA] = $extra;
                if ($uid !== '' && (!$cur || (string) ($cur[self::UID] ?? '') === '') && !self::isOwnUid($t, $uid)) $in[self::UID] = $uid;
                // Foto (nur eingebettet; Adressen fremder Server werden nicht abgerufen und bleiben in den Zusatzangaben)
                $photo = self::photoData($c);
                if (isset($c->PHOTO) && $photo === null) {
                    $in[self::EXTRA] = (string) ($in[self::EXTRA] ?? '') . $c->PHOTO->serialize();
                    $row['warnings'][] = __('Foto als Adresse – nicht geladen, bleibt in den Zusatzangaben.');
                }
                if ($photo !== null && ($o['photos'] ?? true) && Tables::field($t, 'foto')) {
                    $photoId = self::photoKnown($photo);
                    if (!$photoId && !$dry) {
                        $photoId = self::photoImport($t, $photo, $name);
                        if ($photoId) $rep['photos']++; else $row['warnings'][] = __('Foto konnte nicht übernommen werden.');
                    } elseif (!$photoId) {
                        $rep['photos']++;
                    }
                    if ($photoId) $in['foto'] = $photoId;
                    elseif ($dry && (int) ($cur['foto'] ?? 0) === 0) $row['warnings'][] = __('Foto wird in die Mediathek übernommen.');
                }
                if ($cur) {
                    $changes = self::changes($t, $cur, $in);
                    if (!$changes) {
                        $row['action'] = 'unchanged';
                        $rep['unchanged']++;
                        continue;
                    }
                    $row['action'] = 'updated';
                    $row['detail'] = implode(', ', $changes);
                    if (!$dry) {
                        unset($in[Kinds::PUBLIC_FIELD]);
                        [, $err] = Entries::save($t, (int) $cur['id'], $in, (string) ($o['note'] ?? __('Import (vCard)')));
                        if ($err) throw new \RuntimeException(implode(' ', array_map('strval', $err)));
                    }
                    $rep['updated']++;
                } else {
                    $row['action'] = 'created';
                    $row['detail'] = self::summary($in);
                    if (!$dry) {
                        [$newId, $err] = Entries::save($t, null, $in + ['status' => $status], (string) ($o['note'] ?? __('Import (vCard)')));
                        if ($err || !$newId) throw new \RuntimeException(implode(' ', array_map('strval', $err)));
                        if ($uid !== '') $byUid[strtolower($uid)] = (int) $newId;
                    }
                    $rep['created']++;
                }
            } catch (\Throwable $ex) {
                $row['action'] = 'failed';
                $row['detail'] = mb_strimwidth($ex->getMessage(), 0, 300, '…');
                $rep['failed']++;
            } finally {
                unset($photoId);
                if (count($rep['rows']) < 500) $rep['rows'][] = $row;
            }
        }
        $rep['ok'] = true;
        return $rep;
    }

    /** UID → Eintrag: gespeicherte UIDs (_uid) und die des CMS für eigene Exporte */
    private static function uidIndex(array $t): array
    {
        $out = [];
        $db = Tables::db($t);
        try {
            $rows = $db->fetchAll('SELECT id, ' . self::UID . " FROM {$t['table']}");
        } catch (\Throwable) {
            $rows = $db->fetchAll("SELECT id FROM {$t['table']}");
        }
        foreach ($rows as $r) {
            $u = trim((string) ($r[self::UID] ?? ''));
            if ($u !== '') $out[strtolower($u)] = (int) $r['id'];
            $out[strtolower(Calendar::uid($t, ['id' => (int) $r['id']]))] ??= (int) $r['id'];
        }
        return $out;
    }

    /** UID im Format des CMS für diese Tabelle (eigener Export) – nicht als fremde UID speichern */
    private static function isOwnUid(array $t, string $uid): bool
    {
        return (bool) preg_match('~^' . preg_quote((string) $t['handle'], '~') . '-\d+@~', $uid);
    }

    /** Eingebettetes Foto (Binärdaten) oder null */
    private static function photoData(VCard $c): ?string
    {
        if (!isset($c->PHOTO)) return null;
        $p = $c->PHOTO;
        $v = $p instanceof \Sabre\VObject\Property\Binary ? (string) $p->getValue() : (string) $p;
        if (preg_match('~^data:image/[a-z+.-]+;base64,(.+)$~is', $v, $m)) $v = (string) base64_decode($m[1], true);
        elseif (preg_match('~^(https?|ftp|file|cid):~i', $v)) return null;
        if ($v === '' || strlen($v) > self::MAX_PHOTO) return null;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($v) ?: '';
        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) ? $v : null;
    }

    /** Schon übernommenes Foto (gleiche Bytes, Schlagwort vcard-{sha1}) */
    private static function photoKnown(string $bytes): ?int
    {
        $tag = 'vcard-' . substr(sha1($bytes), 0, 16);
        try {
            $id = app()->db->fetchValue('SELECT id FROM media WHERE tags LIKE ? ORDER BY id DESC LIMIT 1', ['%' . $tag . '%']);
        } catch (\Throwable) {
            return null;
        }
        return $id ? (int) $id : null;
    }

    /** Foto in die Mediathek (neu kodiert, ohne EXIF), Sammlung „Import: {Tabelle}“ */
    private static function photoImport(array $t, string $bytes, string $name): ?int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'vcf');
        if ($tmp === false) return null;
        try {
            file_put_contents($tmp, $bytes);
            $ext = match ((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)) { 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', default => 'jpg' };
            $coll = null;
            try {
                $cn = 'Import: ' . $t['name'];
                $coll = app()->db->fetchValue('SELECT id FROM media_collections WHERE name = ?', [$cn]);
                $coll = $coll ? (int) $coll : Media::createCollection($cn, __('Fotos aus dem vCard-Import des Adressbuchs „{name}“', ['name' => $t['name']]));
            } catch (\Throwable) {
            }
            [$m, $err] = Media::import($tmp, \Core\VCard::fileName($name) . '.' . $ext, mb_substr($name, 0, 120), ['require_alt' => false,
                'tags' => ['import', 'vcard', 'vcard-' . substr(sha1($bytes), 0, 16)], 'collection' => $coll]);
            return !$err && $m ? (int) $m['id'] : null;
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    /** Geänderte Felder (Beschriftungen) zwischen Eintrag und Eingabe */
    private static function changes(array $t, array $cur, array $in): array
    {
        $out = [];
        // Anzeigename leer = bildet das CMS selbst (Kinds::beforeSave) – verglichen wird der gebildete Name
        if (($in['name'] ?? null) === '') $in['name'] = (string) (Kinds::beforeSave($t, null, $in)['name'] ?? '');
        foreach ($in as $k => $v) {
            if ($k === Kinds::PUBLIC_FIELD) continue;
            if (self::norm($t, $k, $cur[$k] ?? null) === self::norm($t, $k, $v)) continue;
            $f = Tables::field($t, $k);
            $out[] = $f ? (string) $f['label'] : ($k === self::EXTRA ? __('Zusatzangaben') : $k);
        }
        return array_values(array_unique($out));
    }

    private static function norm(array $t, string $k, mixed $v): string
    {
        $f = Tables::field($t, $k);
        $type = (string) ($f['type'] ?? '');
        if (Combo::isMulti($type)) {
            $keys = ['label', 'label_custom', 'value', 'street', 'zip', 'city', 'country'];
            return json_encode(array_map(function ($i) use ($type, $keys) {
                $x = [];
                foreach ($keys as $kk) if (trim((string) ($i[$kk] ?? '')) !== '') $x[$kk] = trim((string) $i[$kk]);
                $x['public'] = Combo::publicScope($type, $i);
                return $x;
            }, Combo::items($v))) ?: '';
        }
        if ($type === 'multiselect') {
            $a = array_map('strval', is_array($v) ? $v : (json_decode((string) $v, true) ?: []));
            sort($a);
            return implode(',', $a);
        }
        if ($type === 'bool') return !empty($v) ? '1' : '0';
        if ($type === 'media') return (string) (int) $v;
        return trim(str_replace("\r", '', strip_tags((string) (is_scalar($v) ? $v : ''))));
    }

    /** Kurzfassung eines neuen Kontakts für die Vorschau */
    private static function summary(array $in): string
    {
        $bits = [];
        foreach (['position', 'firma'] as $k) if (trim((string) ($in[$k] ?? '')) !== '') $bits[] = (string) $in[$k];
        foreach (['telefon' => 'phones', 'email' => 'emails', 'adresse' => 'addresses', 'web' => 'urls'] as $k => $type) {
            $n = count(Combo::items($in[$k] ?? null));
            if ($n) $bits[] = $n . ' ' . match ($type) { 'phones' => __('Telefon'), 'emails' => __('E-Mail'), 'addresses' => __('Adresse'), default => __('Web') };
        }
        if (!empty($in[self::EXTRA])) $bits[] = __('Zusatzangaben');
        return implode(' · ', $bits);
    }
}
