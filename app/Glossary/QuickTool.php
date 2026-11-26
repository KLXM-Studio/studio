<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Copyright (C) 2026 KLXM and contributors (see LICENSE, COPYRIGHT)
declare(strict_types=1);

namespace Core\Glossary;

use Core\Data\Entries;
use Core\Lang;

/**
 * Quick-Glossar: Werkzeug beim Bearbeiten auf der Website (Core\FrontendTools) – zugleich das Referenzbeispiel für
 * $x->frontendTool([...]) einer Erweiterung. Knopf „Glossar“ in der Werkzeugleiste (⌥G), Seitenleiste in der Shadow-DOM-Ebene,
 * Modul resources/js/quick-glossary.mjs (erst beim ersten Öffnen geladen).
 *
 *  - Suchen: Begriff, Varianten und Kurz-Erklärung; „Einfügen“ setzt einen Link entry:glossar:{id} an die Schreibmarke bzw. um
 *    den markierten Text (wie die Linkauswahl).
 *  - „Neuer Begriff“: vorbelegt mit dem markierten Text; Entwurf – veröffentlicht nur mit Recht data.publish auf die Tabelle.
 *    Die Doppelprüfung („Gibt es schon?“) bietet je ähnlichem Begriff – und über eine Suche nach beliebigem Begriff – „Als Schreibweise
 *    hinzufügen“ (addVariant): der Text wird Variante des vorhandenen Eintrags (Version, Recht wie beim Bearbeiten des Eintrags).
 *  - „Auf dieser Seite“: Begriffe, die die automatische Markierung (Annotator) auf der Seite kennzeichnen würde, mit Sprung.
 *  - Auch beim Ansehen ('view' => true): dort ist JEDER Text der Seite markierbar (beim Bearbeiten nur die Textfelder) – Markierung
 *    + ⌥G bzw. der schwebende Knopf „Als Glossar-Begriff“ (chip) öffnet „Neuer Begriff“ vorbelegt; „Einfügen“ gibt es nur beim Bearbeiten.
 * Endpunkte (GlossaryController::api*): Funktion „glossary“ an, Tabelle eingerichtet, Recht data.edit auf die Tabelle, CSRF bei POST.
 * Geteiltes Glossar: Suche, Doppelprüfung und „Auf dieser Seite“ über alle sichtbaren Begriffe; Treffer anderer Websites mit deren Namen.
 */
final class QuickTool
{
    public const LIMIT = 25;
    public const HTML_MAX = 1572864;   // 1,5 MB Seitentext für „Auf dieser Seite“

    /** Angaben für Core\FrontendTools – genau so, wie eine Erweiterung sie an $x->frontendTool() übergibt */
    public static function definition(): array
    {
        return [
            'id' => 'glossary', 'label' => __('Glossar'), 'icon' => 'book-open-text', 'module' => asset('js/quick-glossary.mjs'),
            'placement' => 'main', 'shortcut' => 'Alt+G', 'hint' => __('Begriffe suchen, verlinken und anlegen'),
            'view' => true, 'chip' => __('Als Glossar-Begriff'),
            'feature' => Glossary::FEATURE,
            'visible' => fn(array $bar) => ($t = Glossary::table()) !== null && can('data.edit', $t['handle']),
            'panel' => ['title' => __('Quick-Glossar')],
            'endpoints' => ['search' => '/admin/api/glossar/suche', 'create' => '/admin/api/glossar/begriff', 'page' => '/admin/api/glossar/seite',
                'variant' => '/admin/api/glossar/schreibweise'],
            'data' => fn(array $bar) => self::data($bar),
            'texts' => self::texts(),
        ];
    }

    /** Angaben je Werkzeugleiste: Rechte, Sprache der Seite, Adressen */
    private static function data(array $bar): array
    {
        $t = Glossary::table();
        $page = $bar['page'] ?? [];
        $self = isset($bar['table'], $bar['entry']) && ($bar['table']['handle'] ?? '') === ($t['handle'] ?? '') ? (int) $bar['entry']['id'] : 0;
        return [
            'publish' => $t && $t['settings']['workflow'] && can('data.publish', $t['handle']),
            'workflow' => (bool) ($t['settings']['workflow'] ?? true),
            'lang' => Lang::norm($bar['entry']['lang'] ?? ($page['lang'] ?? null)),
            'path' => (string) (app()->request?->path ?? '/'),
            'self' => $self, 'shortMax' => Glossary::SHORT_MAX,
            'admin' => url('/admin/glossar'), 'tableUrl' => $t ? url('/admin/data/' . $t['handle']) : '',
            'mode' => Glossary::settings()['mode'],
            // Ansehen: Wechsel in den Bearbeiten-Modus (Seite: ?edit=1; Eintrag: Umschalter in der Werkzeugleiste ohne Neuladen)
            'editUrl' => ($bar['kind'] ?? '') === 'page' && !empty($bar['hasPage']) && !empty($bar['canEditPages']) ? (string) ($bar['pageEditUrl'] ?? '') : '',
        ];
    }

    private static function texts(): array
    {
        return [
            'search' => __('Begriffe durchsuchen'), 'searchPh' => __('Begriff suchen …'), 'tabSearch' => __('Suchen'), 'tabNew' => __('Neuer Begriff'),
            'tabPage' => __('Auf dieser Seite'), 'insert' => __('Einfügen'), 'insertAria' => __('„{term}“ als Link einfügen'),
            'inserted' => __('Link auf „{term}“ eingefügt.'), 'insertedText' => __('„{term}“ als Text eingefügt (dieses Feld kennt keine Links).'),
            'noResults' => __('Keine Begriffe gefunden.'), 'results' => __('{n} Begriffe'), 'result1' => __('1 Begriff'),
            'more' => __('Weitere Treffer – Suche verfeinern.'), 'draft' => __('Entwurf'), 'draftLink' => __('Entwurf – der Link wirkt erst nach dem Veröffentlichen.'),
            'newFrom' => __('„{term}“ als neuen Begriff anlegen'),
            'term' => __('Begriff'), 'short' => __('Kurz-Erklärung'), 'shortHelp' => __('Klartext, höchstens {n} Zeichen – erscheint im Hinweisfenster.'),
            'long' => __('Ausführliche Erklärung (optional)'), 'format' => __('Formatierung'), 'bold' => __('Fett'), 'italic' => __('Kursiv'), 'list' => __('Aufzählung'), 'link' => __('Link'), 'linkPrompt' => __('Adresse des Links (https://… oder /seite)'), 'editForm' => __('Im Formular weiter bearbeiten'), 'variants' => __('Varianten (optional)'), 'variantsHelp' => __('Mit Komma getrennt, z. B. Mehrzahl oder Abkürzung.'),
            'saveDraft' => __('Als Entwurf anlegen'), 'savePublish' => __('Anlegen & veröffentlichen'), 'saveOnly' => __('Anlegen'),
            'createdDraft' => __('„{term}“ als Entwurf angelegt.'), 'createdPub' => __('„{term}“ angelegt und veröffentlicht.'),
            'insertNew' => __('Jetzt im Text verlinken'), 'exists' => __('„{term}“ gibt es schon.'), 'showExisting' => __('Vorhandenen Begriff zeigen'),
            'draftOnly' => __('Neue Begriffe werden als Entwurf angelegt – veröffentlichen kann, wer das Recht dazu hat.'),
            'pageIntro' => __('Diese Begriffe würde die automatische Markierung auf dieser Seite kennzeichnen (jeweils das erste Vorkommen).'),
            'pageNone' => __('Auf dieser Seite würde kein Begriff markiert.'), 'pageOff' => __('Die automatische Markierung ist ausgeschaltet – so sähe sie aus.'),
            'pageExcluded' => __('Diese Seite ist von der Markierung ausgenommen – so sähe sie sonst aus.'), 'pageCheck' => __('Neu prüfen'),
            'jump' => __('Zur Stelle'), 'jumpAria' => __('Zur Stelle mit „{term}“ springen'), 'notFound' => __('Stelle nicht gefunden – vielleicht in einem Bereich, der gerade nicht sichtbar ist.'),
            'manage' => __('Glossar verwalten'), 'noText' => __('Bitte zuerst in einen Text klicken – dort wird eingefügt.'),
            'selected' => __('Markiert: „{text}“ – Einfügen verlinkt diesen Text.'), 'cursor' => __('Einfügen an der Schreibmarke in „{field}“.'), 'cursorAny' => __('Einfügen an der Schreibmarke im Text.'),
            'requiredTerm' => __('Bitte einen Begriff eingeben.'), 'requiredShort' => __('Bitte eine Kurz-Erklärung eingeben.'),
            'tooLong' => __('Höchstens {n} Zeichen.'), 'chars' => __('{n} von {max} Zeichen'),
            // Ansehen
            'viewCtx' => __('Text auf der Seite markieren und {key} drücken – er wird als neuer Begriff vorbelegt.'),
            'viewSelected' => __('Markiert: „{text}“ – unter „Neuer Begriff“ vorbelegt.'),
            'viewLink' => __('Zum Verlinken in den Bearbeiten-Modus wechseln.'), 'toEdit' => __('Bearbeiten'),
            'open' => __('Öffnen'), 'openAria' => __('„{term}“ in der Verwaltung öffnen'),
            'autoHint' => __('Wird automatisch auf allen Seiten markiert, sobald veröffentlicht.'),
            'autoDraft' => __('Als Entwurf sieht nur die angemeldete Redaktion die Markierung.'),
            'autoOff' => __('Die automatische Markierung ist ausgeschaltet – der Begriff steht im Glossar.'),
            'reload' => __('Seite neu laden'),
            'from' => __('von {site}'), 'foreignHint' => __('Begriff einer anderen Website des geteilten Glossars – ändern lässt er sich nur dort.'),
            'dupeQ' => __('Gibt es schon?'), 'dupeNone' => __('Noch kein ähnlicher Begriff.'), 'dupeSome' => __('Ähnlich:'),
            // Als Schreibweise (Variante) eines vorhandenen Begriffs
            'existsTerm' => __('„{text}“ steht schon als Begriff im Glossar.'), 'existsVariant' => __('„{text}“ steht schon als Schreibweise von „{term}“ im Glossar.'),
            'openEntry' => __('Eintrag öffnen'), 'openEntryAria' => __('Eintrag „{term}“ in der Verwaltung öffnen'),
            'addVariant' => __('Als Schreibweise zu „{term}“ hinzufügen'),
            'foreignVariant' => __('„{term}“ gehört der Website {site} – die Schreibweise bitte dort ergänzen.'),
            'noVariantRight' => __('„{term}“ ist veröffentlicht – Schreibweisen ergänzen darf, wer veröffentlichen darf.'),
            'addTo' => __('Als Schreibweise zu einem anderen Begriff …'), 'addToSearch' => __('Vorhandenen Begriff suchen'),
            'addToPh' => __('Begriff suchen …'), 'addToNone' => __('Kein Begriff gefunden.'),
            'variantAdded' => __('„{text}“ ist jetzt eine Schreibweise von „{term}“.'),
            'variantLive' => __('Die Stelle im Text ist jetzt markiert.'), 'variantLater' => __('Auf der Website wird der Text ab jetzt automatisch als Glossar-Begriff markiert.'),
            'variantEdit' => __('Beim Bearbeiten markiert die Website nichts – unter „Auf dieser Seite“ steht der Begriff jetzt mit dieser Stelle.'),
            'showOnPage' => __('Auf dieser Seite zeigen'),
        ];
    }

    // ================================================================= Endpunkte (GlossaryController)

    /** Sprache aus der Anfrage (gültig, sonst Standardsprache) */
    public static function lang(string $l): string
    {
        return $l !== '' && Lang::valid($l) ? $l : Lang::default();
    }

    /**
     * Suche: Begriffe (auch Entwürfe) einer Sprache; Treffer am Anfang des Begriffs zuerst, dann im Begriff, in Varianten,
     * in der Kurz-Erklärung. Ohne Suchtext: alphabetisch. @return array{items: list<array>, total: int}
     */
    public static function search(string $q, string $lang, int $limit = self::LIMIT): array
    {
        $t = Glossary::table();
        if (!$t) return ['items' => [], 'total' => 0];
        $q = mb_strtolower(trim($q));
        $nq = Sharing::norm($q);
        $scored = [];
        foreach (Glossary::terms(true, $lang) as $i => $x) {
            if ($q === '') { $scored[] = [0, $i, $x]; continue; }
            $term = mb_strtolower($x['term']);
            $s = match (true) {
                $term === $q => 100, str_starts_with($term, $q) => 80, str_contains($term, $q) => 60, default => 0,
            };
            if (!$s) foreach ($x['variants'] as $v) { $v = mb_strtolower($v); if (str_starts_with($v, $q)) { $s = 50; break; } if (str_contains($v, $q)) $s = max($s, 40); }
            // Ähnlich ohne Akzente, ß/ss, Binde- und Leerzeichen („Anmeldeschluß“ → „Anmeldeschluss“, „E Mail“ → „E-Mail“)
            if (!$s && mb_strlen($nq) >= 3) foreach (array_keys(Sharing::keys($x)) as $k) { if (str_contains((string) $k, $nq) || (mb_strlen((string) $k) >= 3 && str_contains($nq, (string) $k))) { $s = 30; break; } }
            if (!$s && str_contains(mb_strtolower($x['short']), $q)) $s = 20;
            if ($s) $scored[] = [-$s, $i, $x];
        }
        usort($scored, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $limit = max(1, min(100, $limit));
        return ['items' => array_map(fn($r) => self::item($t, $r[2]), array_slice($scored, 0, $limit)), 'total' => count($scored)];
    }

    /**
     * Vergleichsform einer Schreibweise: ohne Groß-/Kleinschreibung, Leerraum zusammengefasst, ohne Anführungszeichen außen. Bewusst
     * enger als Sharing::norm (das auch Akzente, ß/ss und Bindestriche gleichsetzt): „Anmeldeschluß“ oder „E Mail“ sind eigene
     * Schreibweisen, die die Markierung (Annotator) sonst nicht fände – genau die soll man ergänzen können.
     */
    public static function variantKey(string $s): string
    {
        $s = trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return mb_strtolower(trim((string) preg_replace('~^["„“](.+)["“”]$~u', '$1', $s)));
    }

    /** Begriff + Schreibweisen eines Begriffs als Vergleichsformen (variantKey) → Original */
    private static function variantKeys(array $x): array
    {
        $out = [];
        foreach ([(string) $x['term'], ...array_map('strval', (array) ($x['variants'] ?? []))] as $v) {
            $k = self::variantKey($v);
            if ($k !== '' && !isset($out[$k])) $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Steht der Text schon im Glossar – als Begriff oder als Schreibweise (Variante)? Ohne Groß-/Kleinschreibung und Leerraum
     * (variantKey); im geteilten Glossar auch Begriffe der anderen Websites. @return array|null Begriff + matched (term|variant)
     */
    public static function exact(string $text, string $lang): ?array
    {
        $t = Glossary::table();
        $k = self::variantKey($text);
        if (!$t || mb_strlen(Sharing::norm($k)) < 2) return null;
        foreach (Glossary::terms(true, $lang) as $x) {
            if (!isset(self::variantKeys($x)[$k])) continue;
            return self::item($t, $x) + ['matched' => self::variantKey($x['term']) === $k ? 'term' : 'variant'];
        }
        return null;
    }

    /** Begriff für das Werkzeug: Verweis entry:glossar:{id} (wie die Linkauswahl) + Adresse der Detailseite */
    public static function item(array $t, array $x): array
    {
        $foreign = !empty($x['foreign']);
        return ['id' => (int) $x['id'], 'term' => $x['term'], 'short' => $x['short'], 'variants' => array_values(array_diff($x['variants'], [$x['term']])),
            'category' => $x['category'], 'draft' => (bool) $x['draft'], 'href' => (string) ($x['url'] ?? ''),
            'ref' => 'entry:' . $t['handle'] . ':' . (int) $x['id'], 'edit' => url('/admin/data/' . $t['handle'] . '/' . (int) $x['id']),
            // Geteiltes Glossar: Begriff einer anderen Website (nur lesbar) mit deren Namen
            'foreign' => $foreign, 'origin' => $foreign && isset($t['shared']) ? \Core\Data\Shared::siteInfo((string) $x['origin'], $t['shared']['key'])['name'] : '',
            // „Als Schreibweise hinzufügen“: eigener Begriff; veröffentlicht nur mit data.publish (wie EntryEdit::reason – der Server prüft erneut)
            'canVariant' => !$foreign && (!empty($x['draft']) || can('data.publish', $t['handle']))];
    }

    /**
     * Neuen Begriff anlegen. $in: term, short, long (Klartext, Absätze durch Leerzeile), variants (Komma), publish (bool), lang.
     * Veröffentlicht nur mit Freigabe-Ablauf + Recht data.publish – sonst Entwurf. @return array{ok: bool, item?: array, error?: string, field?: string, exists?: array}
     */
    public static function create(array $in): array
    {
        $t = Glossary::table();
        if (!$t) return ['ok' => false, 'error' => __('Das Glossar ist noch nicht eingerichtet.')];
        $term = trim((string) preg_replace('~\s+~u', ' ', strip_tags((string) ($in['term'] ?? ''))));
        $shortRaw = trim((string) preg_replace('~\s+~u', ' ', strip_tags((string) ($in['short'] ?? ''))));
        $lang = self::lang((string) ($in['lang'] ?? ''));
        if ($term === '' || mb_strlen($term) > 120) return ['ok' => false, 'field' => 'term', 'error' => $term === '' ? __('Bitte einen Begriff eingeben.') : __('Höchstens {n} Zeichen.', ['n' => 120])];
        if ($shortRaw === '') return ['ok' => false, 'field' => 'short', 'error' => __('Bitte eine Kurz-Erklärung eingeben.')];
        if (mb_strlen($shortRaw) > Glossary::SHORT_MAX) return ['ok' => false, 'field' => 'short', 'error' => __('Höchstens {n} Zeichen.', ['n' => Glossary::SHORT_MAX])];
        // Gibt es den Begriff (oder eine gleichlautende Variante) in dieser Sprache schon? → kein Doppel, Vorhandenen zeigen.
        // Ohne Groß-/Kleinschreibung und Akzente; im geteilten Glossar auch Begriffe der anderen Websites (hier ausgeblendete nicht)
        $norm = Sharing::norm($term);
        foreach (Glossary::terms(true, $lang) as $x) {
            if ($norm !== '' && isset(Sharing::keys($x)[$norm])) {
                return ['ok' => false, 'field' => 'term', 'error' => __('„{term}“ gibt es schon.', ['term' => $x['term']]), 'exists' => self::item($t, $x)];
            }
        }
        $publish = !empty($in['publish']) && $t['settings']['workflow'] && can('data.publish', $t['handle']);
        $long = trim((string) ($in['long'] ?? ''));
        $html = '';
        foreach (preg_split('~\R{2,}~u', mb_substr($long, 0, 20000)) ?: [] as $para) {
            $para = trim($para);
            if ($para !== '') $html .= '<p>' . nl2br(e($para), false) . '</p>';
        }
        // Mini-Editor im Werkzeug (fett, kursiv, Liste, Link): HTML über den Rich-Text-Filter des Kerns
        $rich = trim((string) ($in['long_html'] ?? ''));
        if ($rich !== '') {
            $clean = \Core\Sanitizer::block(mb_substr($rich, 0, 40000));
            if (!preg_match('~^\s*<(p|ul|ol|h[2-4]|blockquote)\b~i', $clean)) $clean = '<p>' . $clean . '</p>';
            if (trim(strip_tags($clean)) !== '') $html = $clean;
        }
        $variants = Glossary::splitVariants((string) ($in['variants'] ?? ''));
        $row = ['begriff' => $term, 'kurz' => $shortRaw, 'varianten' => implode("\n", $variants), 'status' => $publish ? 'published' : 'draft'];
        $names = array_column($t['fields'], 'name');
        if ($html !== '' && in_array('erklaerung', $names, true)) $row['erklaerung'] = $html;
        if (Lang::multi()) $row['lang'] = $lang;
        [$id, $errors] = Entries::save($t, null, $row);
        if ($errors) return ['ok' => false, 'error' => implode(' ', array_map('strval', $errors))];
        Glossary::flush();
        $e = Entries::find($t, (int) $id);
        return ['ok' => true, 'item' => self::item($t, Glossary::term($t, $e)), 'published' => ($e['status'] ?? '') === 'published'];
    }

    /** Text als Schreibweise: Tags weg, Leerraum zusammengefasst, Satzzeichen am Ende (außer Punkt, z. B. „z. B.“) entfernt */
    public static function cleanVariant(string $s): string
    {
        $s = trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return trim((string) preg_replace('~[\s,;:!?]+$~u', '', $s));
    }

    /**
     * Markierten Text als weitere Schreibweise (Variante) eines vorhandenen Begriffs ergänzen. $in: id (Begriff), text, lang,
     * view (Ansehen: Markup für die Live-Markierung), match (Text wie auf der Seite). Prüft: Eintrag da, eigener Begriff (geteiltes
     * Glossar: fremde nur dort), Recht wie beim Bearbeiten des Eintrags (EntryEdit::reason), keine Dublette in dieser Sprache
     * (variantKey – Groß/Klein, Leerraum, Anführungszeichen). Speichern mit Version („Schreibweise … ergänzt“).
     * @return array{ok: bool, status?: int, error?: string, code?: string, item?: array, exists?: array, origin?: string, text?: string, markup?: string, assets?: array}
     */
    public static function addVariant(array $in): array
    {
        $t = Glossary::table();
        if (!$t) return ['ok' => false, 'status' => 404, 'error' => __('Das Glossar ist noch nicht eingerichtet.')];
        if (!in_array('varianten', array_column($t['fields'], 'name'), true)) return ['ok' => false, 'status' => 422, 'error' => __('Die Glossar-Tabelle hat kein Feld für Varianten.')];
        $text = self::cleanVariant((string) ($in['text'] ?? ''));
        $lang = self::lang((string) ($in['lang'] ?? ''));
        if ($text === '') return ['ok' => false, 'status' => 422, 'code' => 'text', 'error' => __('Bitte einen Text markieren oder eingeben.')];
        if (mb_strlen($text) > 80) return ['ok' => false, 'status' => 422, 'code' => 'text', 'error' => __('Eine Schreibweise hat höchstens {n} Zeichen.', ['n' => 80])];
        if (preg_match('~[,;]~u', $text)) return ['ok' => false, 'status' => 422, 'code' => 'text', 'error' => __('Eine Schreibweise darf kein Komma oder Semikolon enthalten.')];
        $n = self::variantKey($text);
        if (mb_strlen(Sharing::norm($n)) < 2) return ['ok' => false, 'status' => 422, 'code' => 'text', 'error' => __('Die Schreibweise ist zu kurz.')];
        $e = Entries::find($t, (int) ($in['id'] ?? 0));
        if (!$e) return ['ok' => false, 'status' => 404, 'error' => __('Begriff nicht gefunden.')];
        $x = Glossary::term($t, $e);
        // Geteiltes Glossar: nur eigene Begriffe – fremde ergänzt die Website, der sie gehören
        if (!empty($x['foreign'])) {
            $site = isset($t['shared']) ? \Core\Data\Shared::siteInfo((string) $x['origin'], $t['shared']['key'])['name'] : (string) $x['origin'];
            return ['ok' => false, 'status' => 403, 'code' => 'foreign', 'origin' => $site,
                'error' => __('„{term}“ gehört der Website {site} – die Schreibweise bitte dort ergänzen.', ['term' => $x['term'], 'site' => $site])];
        }
        if ($x['lang'] !== $lang) return ['ok' => false, 'status' => 422, 'error' => __('Der Begriff gehört zu einer anderen Sprache.')];
        if (($why = \Core\Data\EntryEdit::reason($t, $e)) !== null) return ['ok' => false, 'status' => 403, 'error' => $why];
        // Dublette: Text ist schon Begriff oder Schreibweise – dieses oder eines anderen Eintrags (auch fremder im geteilten Glossar)
        foreach (Glossary::terms(true, $lang) as $o) {
            if (!isset(self::variantKeys($o)[$n])) continue;
            $msg = (int) $o['id'] === (int) $x['id']
                ? (self::variantKey($o['term']) === $n ? __('„{text}“ ist der Begriff selbst.', ['text' => $text]) : __('„{text}“ ist schon eine Schreibweise von „{term}“.', ['text' => $text, 'term' => $o['term']]))
                : __('„{text}“ steht schon im Glossar – bei „{term}“.', ['text' => $text, 'term' => $o['term']]);
            return ['ok' => false, 'status' => 409, 'code' => 'exists', 'error' => $msg, 'exists' => self::item($t, $o)];
        }
        $variants = Glossary::splitVariants((string) ($e['varianten'] ?? ''));
        if (count($variants) >= 20) return ['ok' => false, 'status' => 422, 'error' => __('Höchstens {n} Schreibweisen je Begriff.', ['n' => 20])];
        $variants[] = $text;
        [, $errors] = Entries::save($t, (int) $e['id'], ['varianten' => implode("\n", $variants)], __('Schreibweise „{text}“ ergänzt', ['text' => $text]));
        if ($errors) return ['ok' => false, 'status' => 422, 'error' => implode(' ', array_map('strval', $errors))];
        Glossary::flush();
        $saved = Entries::find($t, (int) $e['id']) ?? $e;
        $term = Glossary::term($t, $saved);
        $out = ['ok' => true, 'item' => self::item($t, $term), 'text' => $text];
        // Ansehen: Markup der automatischen Markierung für genau diese Stelle (Live-Markierung ohne Neuladen) + Stylesheet/Skript
        if (!empty($in['view'])) {
            $match = trim((string) preg_replace('~\s+~u', ' ', (string) ($in['match'] ?? '')));
            if ($match === '' || mb_strlen($match) > 120 || self::variantKey($match) !== $n) $match = $text;
            $a = new Annotator([$term], ['labels' => Glossary::labels(), 'prefix' => 'glq' . bin2hex(random_bytes(3)) . '-', 'lang' => $lang]);
            $out['markup'] = $a->markup($term, e($match));
            $out['assets'] = ['css' => Glossary::stylesheets(), 'js' => asset('js/glossary.js')];
        }
        return $out;
    }

    /**
     * Treffer der Markierung in einem HTML-Ausschnitt: [Begriffs-ID => Text wie im Inhalt]. Bearbeitbare Bereiche (contenteditable
     * des Editors) zählen als Text; Regeln, Überschriften und Modus wie auf der Website (Annotator). Ohne Datenbank (Selbsttest).
     */
    public static function hits(array $terms, string $html, array $settings, string $lang = 'de', int $self = 0): array
    {
        $a = new Annotator($terms, ['mode' => ($settings['mode'] ?? 'page') === 'section' ? 'section' : 'page', 'headings' => (int) ($settings['headings'] ?? 3),
            'exclude' => $self ? [$self] : [], 'labels' => Glossary::labels(), 'lang' => $lang, 'main' => false]);
        $html = (string) preg_replace('~\scontenteditable(\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?~i', '', mb_substr($html, 0, self::HTML_MAX));
        $a->annotate('<body>' . $html . '</body>');
        return $a->hits;
    }

    /**
     * „Auf dieser Seite“: Begriffe, die die automatische Markierung im übergebenen HTML (Inhalt der Seite im Editor, ohne
     * contenteditable) kennzeichnen würde – mit Einstellungen der Website (Modus, Überschriften). Ausgenommene Seiten und
     * „aus“ melden das, rechnen aber trotzdem (Vorschau). @return array{items: list<array>, mode: string, excluded: bool, count: int}
     */
    public static function onPage(string $html, string $lang, string $path = '/', int $self = 0): array
    {
        $t = Glossary::table();
        if (!$t) return ['items' => [], 'mode' => 'off', 'excluded' => false, 'count' => 0];
        $s = Glossary::settings();
        $terms = Glossary::terms(true, $lang);
        $byId = [];
        foreach ($terms as $x) $byId[(int) $x['id']] = $x;
        $items = [];
        foreach (self::hits($terms, $html, $s, $lang, $self) as $id => $match) {
            if (isset($byId[$id])) $items[] = self::item($t, $byId[$id]) + ['match' => (string) $match];
        }
        return ['items' => $items, 'mode' => (string) $s['mode'], 'excluded' => Glossary::excluded($path, (string) $s['exclude']), 'count' => count($items)];
    }

    /**
     * Selbsttest der Endpunkte mit Datenbank (glossary:selftest): nur wenn das Glossar eingerichtet ist; alles in einer Transaktion,
     * die zurückgerollt wird. Anlegen (Entwurf ohne data.publish), Doppel/Variante, Pflichtfelder, Länge, Suche, Verweis.
     * @param callable(string, mixed, mixed): void $eq
     */
    public static function selftestDb(callable $eq): bool
    {
        $t = Glossary::table();
        if (!$t) return false;
        $pdo = \Core\Data\Tables::db($t)->pdo;
        $auth = self::authState();   // Anmeldung merken – der Selbsttest setzt Rollen vorübergehend (actAs)
        $pdo->beginTransaction();
        try {
            $lang = Lang::default();
            $r = self::create(['term' => 'QA-Selbsttestbegriff', 'short' => 'Nur für den Selbsttest.', 'variants' => 'QA-Selbsttestwort', 'long' => "Absatz 1\n\nAbsatz <b>2</b>", 'publish' => true, 'lang' => $lang]);
            $eq('Anlegen: ok', $r['ok'], true);
            $eq('Anlegen: ohne data.publish als Entwurf', [$r['published'] ?? null, $r['item']['draft'] ?? null], [can('data.publish', $t['handle']), !can('data.publish', $t['handle'])]);
            $eq('Anlegen: Verweis entry:glossar:{id}', $r['item']['ref'] ?? '', 'entry:' . $t['handle'] . ':' . ($r['item']['id'] ?? 0));
            $e = \Core\Data\Entries::find($t, (int) ($r['item']['id'] ?? 0));
            $eq('Anlegen: ausführlich als Absätze, maskiert', str_contains((string) ($e['erklaerung'] ?? ''), '<p>Absatz 1</p>') && !str_contains((string) ($e['erklaerung'] ?? ''), '<b>'), true);
            $d = self::create(['term' => 'qa-selbsttestbegriff', 'short' => 'x', 'lang' => $lang]);
            $eq('Doppelt (Groß/Klein): abgelehnt mit Vorhandenem', [$d['ok'], $d['field'] ?? null, $d['exists']['id'] ?? null], [false, 'term', $r['item']['id'] ?? null]);
            $v = self::create(['term' => 'QA-Selbsttestwort', 'short' => 'x', 'lang' => $lang]);
            $eq('Doppelt als Variante: abgelehnt', $v['ok'], false);
            $eq('Ohne Begriff', self::create(['term' => ' ', 'short' => 'x'])['field'] ?? null, 'term');
            $eq('Ohne Kurz-Erklärung', self::create(['term' => 'QA neu', 'short' => ''])['field'] ?? null, 'short');
            $eq('Kurz-Erklärung zu lang', self::create(['term' => 'QA neu', 'short' => str_repeat('x', Glossary::SHORT_MAX + 1)])['field'] ?? null, 'short');
            $eq('HTML im Begriff entfernt', self::create(['term' => '<script>x</script>', 'short' => 'y'])['item']['term'] ?? null, 'x');
            $s = self::search('QA-Selbsttest', $lang);
            $eq('Suche: Treffer am Anfang zuerst', $s['items'][0]['term'] ?? null, 'QA-Selbsttestbegriff');
            $eq('Suche: über Variante', array_column(self::search('selbsttestwort', $lang)['items'], 'term'), ['QA-Selbsttestbegriff']);
            $eq('Suche: Grenze', count(self::search('', $lang, 1)['items']) <= 1, true);
            $p = self::onPage('<p contenteditable="true">Hier steht der QA-Selbsttestbegriff im Text.</p>', $lang, '/x');
            $eq('Auf dieser Seite: Entwurf gefunden, Text wie im Inhalt', [array_column($p['items'], 'term'), $p['items'][0]['match'] ?? null], [['QA-Selbsttestbegriff'], 'QA-Selbsttestbegriff']);
            $eq('Auf dieser Seite: eigene Detailseite ausgenommen', self::onPage('<p>QA-Selbsttestbegriff</p>', $lang, '/x', (int) ($r['item']['id'] ?? 0))['items'], []);
            // Doppelprüfung (exact): Begriff bzw. Schreibweise, ohne Groß/Klein und Leerraum – ß/ss und Bindestriche bleiben verschieden
            $eq('Gibt es schon: Begriff', [self::exact(' qa-SELBSTTESTbegriff ', $lang)['id'] ?? null, self::exact('QA-Selbsttestbegriff', $lang)['matched'] ?? null], [$r['item']['id'] ?? null, 'term']);
            $eq('Gibt es schon: Schreibweise', self::exact('QA-SELBSTTESTWORT', $lang)['matched'] ?? null, 'variant');
            $eq('Gibt es schon: unbekannt', self::exact('QA-Unbekanntwort', $lang), null);
            // Als Schreibweise hinzufügen: angemeldet mit Recht data.edit (+ data.publish) auf die Tabelle
            $id = (int) ($r['item']['id'] ?? 0);
            self::actAs(null);
            $eq('Schreibweise: ohne Anmeldung abgelehnt (Recht)', [self::addVariant(['id' => $id, 'text' => 'QA-Schreibweise', 'lang' => $lang])['status'] ?? null], [403]);
            self::actAs(['data.edit', 'data.publish'], [$t['handle']]);
            $v = self::addVariant(['id' => $id, 'text' => '  QA-Schreibweise ,', 'lang' => $lang, 'view' => true, 'match' => 'QA-Schreibweise']);
            $eq('Schreibweise: ergänzt (bereinigt)', [$v['ok'], $v['text'] ?? null, in_array('QA-Schreibweise', $v['item']['variants'] ?? [], true)], [true, 'QA-Schreibweise', true]);
            $eq('Schreibweise: Version mit Notiz', str_contains((string) json_encode(\Core\Data\Revisions::list($t, $id), JSON_UNESCAPED_UNICODE), 'QA-Schreibweise'), true);
            $eq('Schreibweise: Markup für die Live-Markierung', str_contains((string) ($v['markup'] ?? ''), 'class="gl-term"') && str_contains((string) ($v['markup'] ?? ''), '>QA-Schreibweise</button>'), true);
            $eq('Schreibweise: Markierung erkennt sie sofort', array_column(self::onPage('<p>Hier steht die QA-Schreibweise.</p>', $lang, '/x')['items'], 'id'), [$id]);
            $d2 = self::addVariant(['id' => $id, 'text' => '  qa-SCHREIBweise ', 'lang' => $lang]);
            $eq('Schreibweise: doppelt (Groß/Klein, Leerzeichen) abgelehnt', [$d2['ok'], $d2['status'] ?? null, $d2['exists']['id'] ?? null], [false, 409, $id]);
            $eq('Gibt es schon: ß/ss ist eine eigene Schreibweise', self::exact('QA-Selbsttestwort-ß', $lang), null);
            $eq('Ähnlich: ohne Bindestrich gefunden', array_column(self::search('QA Selbsttestbegriff', $lang)['items'], 'id'), [$id]);
            $ss = self::addVariant(['id' => $id, 'text' => 'QA-Selbsttestbegriffß', 'lang' => $lang]);
            $eq('Schreibweise: ß als eigene Schreibweise erlaubt', $ss['ok'], true);
            $eq('Schreibweise: Begriff selbst abgelehnt', self::addVariant(['id' => $id, 'text' => 'QA-SELBSTTESTBEGRIFF', 'lang' => $lang])['status'] ?? null, 409);
            $o = self::create(['term' => 'QA-Zweitbegriff', 'short' => 'Zweiter Begriff.', 'lang' => $lang]);
            $eq('Schreibweise: bei anderem Begriff vorhanden abgelehnt', self::addVariant(['id' => (int) ($o['item']['id'] ?? 0), 'text' => 'QA-Schreibweise', 'lang' => $lang])['exists']['id'] ?? null, $id);
            $eq('Schreibweise: Komma abgelehnt', self::addVariant(['id' => $id, 'text' => 'QA a, b', 'lang' => $lang])['code'] ?? null, 'text');
            $eq('Schreibweise: leer/zu kurz abgelehnt', [self::addVariant(['id' => $id, 'text' => ' ', 'lang' => $lang])['code'] ?? null, self::addVariant(['id' => $id, 'text' => 'x', 'lang' => $lang])['code'] ?? null], ['text', 'text']);
            $eq('Schreibweise: zu lang abgelehnt', self::addVariant(['id' => $id, 'text' => str_repeat('q', 81), 'lang' => $lang])['code'] ?? null, 'text');
            $eq('Schreibweise: unbekannter Begriff', self::addVariant(['id' => 999999999, 'text' => 'QA-Irgendwas', 'lang' => $lang])['status'] ?? null, 404);
            self::actAs(['data.edit'], ['qa-andere-tabelle']);
            $eq('Schreibweise: Recht nur auf andere Tabelle abgelehnt', self::addVariant(['id' => $id, 'text' => 'QA-Drittwort', 'lang' => $lang])['status'] ?? null, 403);
            self::actAs(['data.edit'], [$t['handle']]);
            $pub = Entries::find($t, $id);
            $eq('Schreibweise: veröffentlicht ohne data.publish abgelehnt', ($pub['status'] ?? '') === 'published' && $t['settings']['workflow'] ? self::addVariant(['id' => $id, 'text' => 'QA-Drittwort', 'lang' => $lang])['status'] ?? null : 403, 403);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            self::authState($auth);
            Glossary::flush();
        }
        return true;
    }

    /** Selbsttest: Anmeldung lesen bzw. ($restore) wiederherstellen – loaded, user, role von Core\Auth */
    private static function authState(?array $restore = null): array
    {
        $a = app()->auth;
        $out = [];
        foreach (['loaded', 'user', 'role'] as $prop) {
            $p = new \ReflectionProperty($a, $prop);
            if ($restore !== null) $p->setValue($a, $restore[$prop]);
            else $out[$prop] = $p->getValue($a);
        }
        return $out;
    }

    /** Selbsttest: vorübergehend angemeldet mit diesen Rechten (null = abgemeldet), $tables = Tabellenauswahl der Rolle */
    private static function actAs(?array $perms, ?array $tables = null): void
    {
        self::authState(['loaded' => true,
            'user' => $perms === null ? null : ['id' => 0, 'email' => 'selbsttest@example.test', 'name' => 'Selbsttest', 'role' => 'qa', 'appearance' => null, 'locale' => null],
            'role' => $perms === null ? null : ['key' => 'qa', 'name' => 'QA', 'description' => '', 'permissions' => $perms, 'tables' => $tables, 'builtin' => false]]);
    }
}
