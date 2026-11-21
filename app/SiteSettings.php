<?php
declare(strict_types=1);

namespace Core;

/**
 * Website-Einstellungen des Kits (theme.php → 'settings', Verwaltung /admin/settings) in zwei Stufen:
 *
 *  - „Website-Angaben“ (Recht settings.info): ungefährliche Angaben, die die Redaktion pflegt – Stammdaten und Kontakt,
 *    Öffnungszeiten, Hinweisbalken, Social Media, Header-Button, Texte im Fußbereich, Standard-Vorschaubild.
 *  - „Website-Einstellungen“ (Recht settings.edit, wie bisher): alles, also zusätzlich Logo, Rechtstexte, SEO/Titelzusatz und
 *    Kit-spezifische Schalter.
 *
 * Einstufung je Feld: 'level' => 'info'|'site' am Feld oder an der Gruppe in theme.php; ohne Angabe gilt die Vorgabe des Kerns
 * nach Feldname (INFO, INFO_PREFIX, Hinweisbalken des Kits über project('notice.*')), alles andere ist 'site'.
 * Pflichtwerte: 'required' wie bisher; 'keep' (bzw. KEEP) = einmal gesetzt, nicht mehr leer speicherbar (Impressum, Datenschutz).
 * Wer nur settings.info hat, sieht und speichert nur die Angaben (Verwaltung, KI-Aktion set_notice); Werte der übrigen Felder
 * bleiben unberührt. Bestehende Rollen behalten settings.edit – es geht nichts verloren.
 */
final class SiteSettings
{
    /** Felder, die ohne Angabe im Kit als „Website-Angaben“ gelten */
    public const INFO = ['org_name', 'short_name', 'tagline', 'street', 'zip', 'city', 'country', 'phone', 'email', 'hours', 'hours_note', 'geo',
        'topbar_text', 'footer_statement', 'footer_text', 'social', 'og_default_image', 'notice_active', 'notice_text',
        // Besuchs- und Ortsangaben einzelner Kits (galerie, nature, editorial)
        'edition', 'seasons', 'directions', 'venue_name', 'admission', 'appointment_note', 'hours_exceptions', 'venues', 'enquiry_label'];
    /** Präfixe für „Website-Angaben“: Hinweisbalken (Core\Notice) und Beschriftungen/Links im Kopfbereich (Core\HeaderActions) */
    public const INFO_PREFIX = ['notice_', 'header_'];
    /** Einmal gesetzt, nicht leer speicherbar (Hinweis statt stiller Lücke im Fußbereich) */
    public const KEEP = ['imprint_page', 'privacy_page'];

    /** Stufe eines Felds: 'info' (Website-Angaben) oder 'site' (nur mit settings.edit) */
    public static function level(array $f, array $group = []): string
    {
        $l = $f['level'] ?? $group['level'] ?? null;
        if ($l === 'info' || $l === 'site') return $l;
        $n = (string) ($f['name'] ?? '');
        if ($n === '') return 'site';
        if (in_array($n, self::INFO, true)) return 'info';
        foreach (self::INFO_PREFIX as $p) if (str_starts_with($n, $p)) return 'info';
        // Hinweisbalken eines Kits mit eigenen Schlüsseln (theme.php → project.notice.text/active)
        if (in_array($n, array_filter([(string) project('notice.text', ''), (string) project('notice.active', '')]), true)) return 'info';
        return 'site';
    }

    /** Darf alles ändern (bisheriges Recht settings.edit) */
    public static function full(): bool
    {
        return can('settings.edit');
    }

    /** Darf die Seite öffnen (mindestens Website-Angaben) */
    public static function canView(): bool
    {
        return can('settings.info');   // settings.edit schließt settings.info ein (Permissions::allows)
    }

    /** Titel im Menü und auf der Seite: mit allen Rechten wie das Kit ihn nennt, sonst „Website-Angaben“ */
    public static function title(): string
    {
        return self::full() ? app()->theme->settingsTitle() : __('Website-Angaben');
    }

    /**
     * Gruppen des Kits für die angemeldete Person: ohne settings.edit nur die Felder der Stufe „info“ (Überschriften nur, wenn
     * darunter etwas bleibt; leere Gruppen fallen weg). Markiert KEEP-Felder mit 'keep'. Mit $full = true alle Felder.
     */
    public static function groups(?bool $full = null): array
    {
        $full ??= self::full();
        $out = [];
        foreach (app()->theme->settingsGroups() as $g) {
            $fields = [];
            $pending = null;   // Überschrift, die erst mit dem nächsten sichtbaren Feld ausgegeben wird
            foreach ((array) ($g['fields'] ?? []) as $f) {
                if (in_array($f['name'] ?? '', self::KEEP, true) && !isset($f['keep'])) $f['keep'] = true;
                if ($full) { $fields[] = $f; continue; }   // alle Rechte: unverändert wie bisher
                if (($f['type'] ?? '') === 'heading') { $pending = $f; continue; }
                if (self::level($f, $g) !== 'info') continue;
                if ($pending !== null) { $fields[] = $pending; $pending = null; }
                $fields[] = $f;
            }
            if ($full || array_filter($fields, fn($f) => isset($f['name']))) $out[] = ['fields' => $fields] + $g;
        }
        return $out;
    }

    /** Satz je üblicher Gruppe (Kennung im Kit): wofür sie gilt und was greift, wenn sie leer bleibt. Kit: 'lead' => '…' bzw. false. */
    private static function leads(): array
    {
        return [
            'stammdaten' => __('Name, Adresse und Kontakt erscheinen im Kopf, im Fußbereich, im Kontakt-Block und in den Daten für Suchmaschinen. Felder mit * sind Pflicht.'),
            'darstellung' => __('Logo und kurze Texte in Kopf- und Fußbereich. Ohne Logo zeigt die Website den Kurznamen bzw. den Namen.'),
            'hinweis' => __('Ein kurzer Hinweis oben auf jeder Seite, z. B. Betriebsferien. Standard: aus.'),
            'hinweise' => __('Kurze Hinweise für Besucher, z. B. Urlaub oder Vertretung. Standard: aus.'),
            'social' => __('Links zu Ihren Profilen im Fußbereich – ohne eingebettete Inhalte oder Tracking. Leer: keine Links.'),
            'recht' => __('Welche Seiten als Impressum, Datenschutzerklärung und Erklärung zur Barrierefreiheit verlinkt werden (Fußbereich, Formulare, E-Mails). Einmal gesetzt, lassen sich Impressum und Datenschutz nicht mehr leeren.'),
            'seo' => __('Wie die Website in Suchergebnissen und beim Teilen erscheint. Leere Felder: Name der Website bzw. Text der jeweiligen Seite.'),
        ];
    }

    /**
     * Darstellung in der Verwaltung (nur Anzeige, keine Werte): ein Satz je Gruppe, „Gilt für: diese Website“ mit mehreren Websites,
     * wirksamer Wert als Platzhalter (Kurzname, Titel-Zusatz), Felder des Hinweisbalkens nur bei eingeschaltetem Hinweis,
     * Einstufung „nur mit allen Rechten“ als Hinweis, wenn die Person beides sieht.
     */
    public static function decorate(array $groups): array
    {
        $leads = self::leads();
        $org = trim((string) app()->settings->get('org_name', ''));
        $active = (string) (project('notice.active', '') ?: 'notice_active');
        foreach ($groups as &$g) {
            $lead = array_key_exists('lead', $g) ? $g['lead'] : ($leads[$g['id'] ?? ''] ?? null);
            $first = $g['fields'][0] ?? null;
            if (($lead || Sites::multi()) && ($first['type'] ?? '') !== 'heading') {
                array_unshift($g['fields'], ['type' => 'heading', 'label' => '', 'lead' => $lead ?: null, 'scope' => Sites::multi() ? 'site' : null]);
            } elseif ($first !== null && ($first['type'] ?? '') === 'heading') {
                $g['fields'][0] += array_filter(['lead' => $lead ?: null, 'scope' => Sites::multi() ? 'site' : null]);
            }
            $names = array_column(array_filter($g['fields'], fn($f) => isset($f['name'])), 'name');
            foreach ($g['fields'] as &$f) {
                $n = $f['name'] ?? '';
                if ($org !== '' && in_array($n, ['short_name', 'site_title_suffix'], true) && empty($f['placeholder'])) $f['placeholder'] = __('leer: {v}', ['v' => $org]);
                // Zeitraum und Darstellung des Hinweisbalkens (Core\Notice) nur, wenn der Hinweis eingeschaltet ist
                if (in_array($active, $names, true) && in_array($n, ['notice_from', 'notice_until', 'notice_style', 'notice_color', 'notice_fx'], true) && !isset($f['show_if'])) {
                    $f['show_if'] = [$active => ['1']];
                }
            }
            unset($f);
        }
        unset($g);
        return $groups;
    }

    /** Felder (flach) für die angemeldete Person bzw. alle */
    public static function fields(?bool $full = null): array
    {
        $out = [];
        foreach (self::groups($full) as $g) foreach ($g['fields'] as $f) $out[] = $f;
        return $out;
    }

    /** Darf die angemeldete Person diese Schlüssel ändern? @return list<string> Schlüssel, die sie nicht ändern darf */
    public static function forbidden(array $keys): array
    {
        if (self::full()) return [];
        $allowed = array_column(array_filter(self::fields(false), fn($f) => isset($f['name'])), 'name');
        return array_values(array_diff($keys, $allowed));
    }

    /**
     * Pflichtwerte, die nicht geleert werden dürfen ('keep'): Fehler je Feld, wenn ein bisher gesetzter Wert leer gespeichert würde.
     * @return array<string, string>
     */
    public static function keepErrors(array $fields, array $values, array $current): array
    {
        $err = [];
        foreach ($fields as $f) {
            $n = $f['name'] ?? null;
            if ($n === null || !array_key_exists($n, $values) || !(!empty($f['keep']) || in_array($n, self::KEEP, true))) continue;
            $empty = fn($v) => $v === null || $v === '' || $v === [] || $v === 0 || $v === '0';
            if ($empty($values[$n]) && !$empty($current[$n] ?? null)) {
                $err[$n] = __('Dieses Feld darf nicht geleert werden. Wählen Sie bei Bedarf eine andere Seite.');
            }
        }
        return $err;
    }
}
