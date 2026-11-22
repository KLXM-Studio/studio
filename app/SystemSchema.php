<?php
declare(strict_types=1);

namespace Core;

/** Schema der Grundeinstellungen (Admin → System). Theme-unabhängig. */
final class SystemSchema
{
    /** Gruppen gefiltert nach dem Funktionsumfang der Website (Features) */
    public static function groups(): array
    {
        $out = [];
        foreach (self::allGroups() as $g) {
            if (($g['id'] === 'app' && !Features::on('pwa')) || ($g['id'] === 'sprachen' && !Features::on('languages'))) continue;
            $g['fields'] = array_values(array_filter($g['fields'], fn($f) => !(
                (($f['name'] ?? '') === 'sys.theme' && !Features::on('theme_switch'))
                || (str_starts_with((string) ($f['name'] ?? ''), 'sys.map_') && !Features::on('maps')))));
            $out[] = $g;
        }
        return $out;
    }

    /**
     * Reiter und Gruppen. Muster (wie E-Mail-Versand): Wichtiges zuerst, Seltenes eingeklappt ('collapse'); jede Gruppe mit einem
     * Satz zu Geltung und Rückfall ('lead', 'scope'); gefährliche Schalter mit 'danger'; abhängige Felder mit 'show_if';
     * wirksamer Wert als Platzhalter bzw. 'effective'. Alle Texte über __() (Übersetzung lang/en.php). Schlüssel und Standardwerte
     * bleiben unverändert – nur Darstellung.
     */
    private static function allGroups(): array
    {
        $app = app()->theme->def['app']['defaults'] ?? [];
        $info = AppIcons::appInfo();
        $multi = Sites::multi();
        $scope = $multi ? 'site' : null;   // „Gilt für: diese Website“ nur, wenn es mehrere Websites gibt
        $domains = Domains::available();   // Hauptadresse dann auf der Seite „Domain“ (eine Einstellung = ein Ort)
        $forcedNoindex = environment() !== 'production';
        $themes = site()->allowedThemes();
        return [
            ['id' => 'website', 'label' => __('Allgemein'), 'fields' => [
                ['type' => 'heading', 'label' => __('Kit und Adresse'), 'scope' => $scope,
                    'lead' => $domains ? __('Welches Kit diese Website zeigt. Domains und Hauptadresse stellen Sie auf der Seite „Domain“ ein.')
                        : __('Welches Kit diese Website zeigt und unter welcher Adresse sie sich in Links, Sitemap und E-Mails nennt. Ohne Hauptadresse gilt die aufgerufene Domain.'),
                    'links' => $domains ? [['label' => __('Domains und Hauptadresse verwalten (Grundeinstellungen → Domain)'), 'url' => '/admin/system/domain']] : []],
                ['name' => 'sys.theme', 'label' => __('Kit der Website'), 'type' => 'select', 'required' => true,
                    'options' => $themes, 'default' => site()->defaultTheme(),
                    'danger' => count($themes) > 1 ? __('Ein Wechsel ändert sofort das Aussehen der ganzen Website.') : '',
                    'help' => __('Weitere Kits installieren Sie unter Grundeinstellungen → Kits.')],
                ...($domains ? [] : [['name' => 'sys.site_url', 'label' => __('Hauptadresse (kanonische Domain)'), 'type' => 'url',
                    'placeholder' => __('leer: {url}', ['url' => rtrim(absolute_url('/'), '/')]),
                    'help' => __('Für Canonical-Links, Sitemap und Links in E-Mails (auch aus geplanten Aufgaben). Nur nötig, wenn die Website unter mehreren Domains erreichbar ist.'),
                    'danger' => __('Eine falsche Adresse führt Links in E-Mails und in der Sitemap ins Leere.')]]),
                ['type' => 'heading', 'label' => __('Wartung'), 'scope' => $scope,
                    'lead' => __('Während der Wartung sehen Besucher nur den Hinweistext; angemeldete Personen sehen die Website wie gewohnt.')],
                ['name' => 'sys.maintenance', 'label' => __('Wartungsmodus: Website nur für Angemeldete'), 'type' => 'bool', 'default' => false,
                    'danger' => __('Besucher und Suchmaschinen sehen dann keine Inhalte mehr.')],
                ['name' => 'sys.maintenance_text', 'label' => __('Text für Besucher während der Wartung'), 'type' => 'textarea', 'rows' => 2,
                    'default' => 'Unsere Website wird gerade überarbeitet. Telefonisch sind wir wie gewohnt für Sie da.', 'show_if' => ['sys.maintenance' => ['1']]],
                ['type' => 'heading', 'label' => __('Zusammenarbeit der Redaktion'),
                    'lead' => __('Wer gleichzeitig an derselben Seite oder demselben Eintrag arbeitet. Besucher sehen davon nichts.')],
                ['name' => 'sys.presence', 'label' => __('Anzeigen, wer gerade wo arbeitet'), 'type' => 'bool', 'default' => true,
                    'help' => __('Name bzw. Bild und Zeitpunkt in Werkzeugleiste, Seitenbaum, Eintragslisten und Übersicht; Warnung vor dem Speichern, wenn jemand anderes inzwischen gespeichert hat. Keine Protokollierung.')],
                ['name' => 'sys.edit_lock', 'label' => __('Bearbeitungssperre: wer zuerst bearbeitet, sperrt für andere'), 'type' => 'bool', 'default' => false, 'show_if' => ['sys.presence' => ['1']],
                    'help' => __('Andere können die Seite bzw. den Eintrag nur ansehen, bis die Person fertig ist (spätestens 45 Sekunden nach dem Schließen frei) – auch über KI, REST-API und MCP. Administratoren können übernehmen.')],
                ['type' => 'heading', 'label' => __('Darstellung auf der Website'),
                    'lead' => __('Gilt für alle Seiten dieser Website; Symbolstil und Landesvorwahl ändern die Verwaltung nicht.')],
                ['name' => 'sys.symbol_style', 'label' => __('Symbolstil'), 'type' => 'select', 'default' => 'duotone',
                    'options' => Icons::styleOptions(),
                    'help' => __('Für alle Symbole in Blöcken und im Kit. Lucide und Tabler sind freie Linien-Sätze – fehlende Symbole kommen aus Phosphor „Linie“.')],
                ['name' => 'sys.phone_country', 'label' => __('Landesvorwahl'), 'type' => 'text', 'width' => 'half', 'default' => '+49', 'max' => 5,
                    'help' => __('Für Anruf-Links und die internationale Schreibweise auf Seiten in weiteren Sprachen (z. B. „0211 …“ → „+49 211 …“).')],
                // Datums- und Zeitauswahl (Core\DatetimePicker, resources/js/_datetime.js) – Verwaltung und Formulare der Website
                ['name' => 'sys.datetime_picker', 'label' => __('Datums- und Zeitauswahl'), 'type' => 'select', 'required' => true, 'default' => 'picker',
                    'options' => DatetimePicker::options(),
                    'help' => __('Gilt auch für die Verwaltung. Der barrierefreie Kalender zeigt Datum und Uhrzeit deutsch (TT.MM.JJJJ, HH:MM), lässt sich per Tastatur bedienen und sagt Monat und Auswahl für Screenreader an; Eintippen bleibt möglich. Ohne JavaScript gilt die Auswahl des Browsers. Ein Kit kann den Kalender auf der Website abschalten.')],
                ['type' => 'heading', 'label' => __('Verwaltung'), 'collapse' => true, 'summary' => __('Sprache, Akzentfarben, Symbolauswahl'),
                    'lead' => __('Vorgaben für alle, die diese Website verwalten. Jede Person kann Sprache und Akzentfarbe unter „Konto“ selbst wählen, soweit hier erlaubt.')],
                ['name' => 'sys.admin_locale', 'label' => __('Sprache der Verwaltung (Standard)'), 'type' => 'select', 'required' => true, 'default' => 'de',
                    'options' => I18n::available()],
                // Persönliche Akzentfarbe der Verwaltung (Core\Accent): welche Vorlagen die Personen unter „Konto“ wählen dürfen
                ['name' => 'sys.admin_accent_lock', 'label' => __('Verwaltung immer im Standard „KLXM Navy“ (persönliche Akzentfarben aus)'), 'type' => 'bool', 'default' => false],
                ['name' => 'sys.admin_accents', 'label' => __('Erlaubte Akzentfarben'), 'type' => 'multiselect', 'show_if' => ['sys.admin_accent_lock' => ['']],
                    'options' => array_diff_key(Accent::labels(), [Accent::DEFAULT => true]),
                    'help' => __('Nichts angehakt = alle Vorlagen erlaubt. „KLXM Navy“ (Standard) ist immer dabei. Alle Vorlagen sind für Hell und Dunkel auf Kontrast (WCAG AA) geprüft.')],
                // Symbolauswahl (Core\Icons): welche Themenbereiche die Redaktion sieht – „Allgemein“ und „Bedienung“ immer
                ['name' => 'sys.icon_topics', 'label' => __('Symbolbereiche in der Symbolauswahl'), 'type' => 'multiselect',
                    'options' => Icons::topicOptions(),
                    'help' => __('Nichts angehakt = alle Bereiche. „Allgemein“ und „Bedienung“ sind immer dabei; über die Suche lassen sich weitere einblenden.')],
            ]],
            // Indexierung & Crawler (Core\Indexing): robots.txt, KI-Crawler, llms.txt
            ['id' => 'index', 'label' => __('Indexierung & Crawler'), 'fields' => [
                ['type' => 'heading', 'label' => __('Suchmaschinen'), 'scope' => $scope, 'alert' => implode(' ', Indexing::warnings()),
                    'lead' => __('Ob Google & Co. diese Website aufnehmen dürfen. Einzelne Seiten sperren Sie in den Seiteneinstellungen („Nicht indexieren“), ganze Datentabellen in deren Einstellungen.')],
                ['name' => 'sys.noindex', 'label' => __('Suchmaschinen aussperren'), 'type' => 'bool', 'default' => false,
                    'danger' => __('Die ganze Website verschwindet aus den Suchergebnissen (robots.txt „Disallow: /“, noindex).'),
                    'help' => __('Für Testumgebungen und vor dem Start.'),
                    'effective' => $forcedNoindex ? __('gesperrt – Testumgebung sperrt immer') : ''],
                ['type' => 'heading', 'label' => __('KI-Crawler und KI-Assistenten'),
                    'lead' => __('Was Crawler von KI-Anbietern dürfen. Seriöse Anbieter halten sich an die robots.txt – eine technische Sperre ist es nicht.')],
                ...array_filter(Indexing::fields(), fn($f) => $f['name'] !== 'sys.robots_extra'),
                ['type' => 'heading', 'label' => __('Eigene Regeln für die robots.txt'), 'collapse' => true,
                    'summary' => trim((string) app()->settings->get('sys.robots_extra', '')) !== '' ? __('eingetragen') : __('keine'),
                    'lead' => __('Für Fachleute: zusätzliche Zeilen am Ende der robots.txt. Leer: nur die Regeln aus den Schaltern oben.')],
                ...array_filter(Indexing::fields(), fn($f) => $f['name'] === 'sys.robots_extra'),
                ['type' => 'heading', 'label' => __('Ergebnis prüfen'), 'help' => __('Aktuelle Fassung:') . ' ' . absolute_url('/robots.txt') . ' · ' . absolute_url('/sitemap.xml') . (Indexing::llmsEnabled() ? ' · ' . absolute_url('/llms.txt') : '')],
            ]],
            ['id' => 'app', 'label' => __('App-Icon & PWA'), 'fields' => [
                ['type' => 'heading', 'label' => __('Favicon & App-Icon'), 'scope' => $scope,
                    'lead' => __('Daraus entstehen automatisch alle Größen: Browser-Tab, iPhone/iPad, Android und App. Die Vorschau zeigt das Ergebnis sofort.')],
                ['name' => 'sys.icon_mode', 'label' => __('Icon aus …'), 'type' => 'select', 'required' => true, 'default' => 'letters',
                    'options' => ['letters' => __('Buchstaben in der Hausschrift'), 'image' => __('Bild oder Logo aus der Mediathek')]],
                ['name' => 'sys.icon_text', 'label' => __('Buchstabe(n)'), 'type' => 'text', 'width' => 'half', 'max' => 2, 'default' => $app['icon_text'] ?? '',
                    'help' => __('1–2 Zeichen, z. B. der Anfangsbuchstabe.'), 'show_if' => ['sys.icon_mode' => ['letters']]],
                ['name' => 'sys.icon_shape', 'label' => __('Form im Browser-Tab'), 'type' => 'select', 'width' => 'half', 'required' => true, 'default' => 'rounded',
                    'options' => ['rounded' => __('Abgerundetes Quadrat'), 'circle' => __('Kreis'), 'square' => __('Quadrat')]],
                ['name' => 'sys.icon_bg', 'label' => __('Hintergrund'), 'type' => 'color', 'transparent' => true, 'width' => 'half', 'default' => $app['icon_bg'] ?? '#16201E',
                    'help' => __('„Transparent“ gilt für Browser-Tab und Android; iPhone-Icon und „maskable“ brauchen eine Fläche und werden weiß hinterlegt.')],
                ['name' => 'sys.icon_fg', 'label' => __('Schriftfarbe'), 'type' => 'color', 'width' => 'half', 'default' => $app['icon_fg'] ?? '#FFFFFF', 'show_if' => ['sys.icon_mode' => ['letters']]],
                ['name' => 'sys.icon_dot', 'label' => __('Akzentpunkt wie in der Wortmarke'), 'type' => 'bool', 'default' => $app['icon_dot_enabled'] ?? true, 'show_if' => ['sys.icon_mode' => ['letters']]],
                ['name' => 'sys.icon_dot_color', 'label' => __('Farbe des Punkts'), 'type' => 'color', 'width' => 'half', 'default' => $app['icon_dot'] ?? '#F6C9A8',
                    'show_if' => ['sys.icon_mode' => ['letters'], 'sys.icon_dot' => ['1']]],
                ['name' => 'sys.icon_image', 'label' => __('Bild oder Logo'), 'type' => 'media', 'show_if' => ['sys.icon_mode' => ['image']],
                    'help' => __('Quadratisch, mindestens 512 × 512 px. Genutzt wird der 1:1-Zuschnitt bzw. der Fokuspunkt; der Rand füllt sich mit der Hintergrundfarbe.')
                        . ' ' . (\Core\Svg::canRasterize() ? __('SVG-Logos werden ganz eingepasst.') : __('SVG-Logos gehen hier nicht (dem Server fehlt Imagick mit SVG) – bitte ein PNG wählen.'))],
                ['type' => 'heading', 'label' => __('Installierbare Web-App (PWA)'),
                    'lead' => __('Besucher können die Website auf dem Handy „Zum Home-Bildschirm“ hinzufügen – mit eigenem Icon, Namen und Kurzbefehlen. Für Besucher im Browser wird nichts gespeichert.')],
                ['name' => 'sys.pwa', 'label' => __('Als App installierbar machen (Web-App-Manifest)'), 'type' => 'bool', 'default' => true],
                ['name' => 'sys.pwa_name', 'label' => __('Name der App'), 'type' => 'text', 'width' => 'half', 'placeholder' => __('leer: {v}', ['v' => $info['name']]), 'show_if' => ['sys.pwa' => ['1']]],
                ['name' => 'sys.pwa_short_name', 'label' => __('Kurzname unter dem Icon'), 'type' => 'text', 'width' => 'half', 'max' => 12, 'placeholder' => __('leer: {v}', ['v' => $info['short_name']]),
                    'help' => __('Höchstens 12 Zeichen, sonst wird er abgeschnitten.'), 'show_if' => ['sys.pwa' => ['1']]],
                ['name' => 'sys.pwa_theme', 'label' => __('Farbe der Statusleiste'), 'type' => 'color', 'width' => 'half', 'default' => $app['icon_bg'] ?? '#16201E', 'show_if' => ['sys.pwa' => ['1']]],
                ['name' => 'sys.pwa_bg', 'label' => __('Hintergrund beim Start'), 'type' => 'color', 'width' => 'half', 'default' => '#FFFFFF', 'show_if' => ['sys.pwa' => ['1']]],
                ['name' => 'sys.pwa_display', 'label' => __('Darstellung'), 'type' => 'select', 'required' => true, 'default' => 'standalone', 'show_if' => ['sys.pwa' => ['1']],
                    'options' => ['standalone' => __('Wie eine App (ohne Adressleiste)'), 'minimal-ui' => __('Mit schmaler Browserleiste'), 'browser' => __('Normaler Browser')]],
                ['name' => 'sys.pwa_offline', 'label' => __('Offline-Modus in der installierten App'), 'type' => 'bool', 'default' => true, 'show_if' => ['sys.pwa' => ['1']],
                    'help' => __('Nur wer die App installiert, bekommt einen Service Worker: zuletzt besuchte Seiten und die Offline-Seite des Kits bleiben ohne Netz abrufbar. Formulare, Verwaltung und API werden nie zwischengespeichert.')],
            ]],
            ['id' => 'proxy', 'label' => __('Karten & externe Quellen'), 'fields' => [
                ['type' => 'heading', 'label' => __('Karten (MapLibre + OpenFreeMap)'), 'scope' => $scope,
                    'lead' => __('Karten laden über den eigenen Server – Besucher haben keinen Kontakt zu Dritten, eine Einwilligung ist nicht nötig. Den Standort der Website pflegen Sie in den Website-Angaben, weitere Orte im Block „Karte“.')],
                ['name' => 'sys.map_style', 'label' => __('Kartenstil'), 'type' => 'select', 'required' => true, 'default' => 'liberty', 'width' => 'half',
                    'options' => Maps::STYLES],
                ['name' => 'sys.map_style_dark', 'label' => __('Kartenstil im Dunkelmodus'), 'type' => 'select', 'required' => true, 'default' => 'dark', 'width' => 'half',
                    'options' => Maps::STYLES, 'help' => __('Nur bei Kits mit dunklem Farbschema, wenn Besucher es im Gerät eingestellt haben.')],
                ['name' => 'sys.map_click', 'label' => __('Karte erst nach Klick laden (Zwei-Klick)'), 'type' => 'select', 'default' => '', 'width' => 'half',
                    'options' => ['' => __('Vorgabe des Kits'), '1' => __('Ja – Hinweis mit Link zur Datenschutzerklärung, Laden nach Klick'), '0' => __('Nein – laden, sobald die Karte in Sichtweite kommt')],
                    'help' => __('Manche Praxen oder Behörden verlangen das auch ohne Drittanbieter. Spart außerdem Daten (MapLibre ≈ 800 KB).')],
                ['name' => 'sys.map_radius', 'label' => __('Kartengebiet je Ort (km)'), 'type' => 'number', 'default' => 25, 'width' => 'half',
                    'help' => __('Detailkacheln liefert der Server nur in diesem Umkreis um Orte, die auf der Website als Karte vorkommen – so taugt er nicht als kostenloser Kachelserver für andere.')],
                // „In deiner Nähe“ (Core\Data\Nearby): Orte ohne Treffer in Erweiterungen (z. B. Gebietskarten) online suchen – Standard aus
                ['name' => 'sys.nearby_geocode', 'label' => __('„In deiner Nähe“: PLZ und Orte online suchen'), 'type' => 'bool', 'default' => false,
                    'help' => __('Schickt die Eingabe der Besucher (PLZ oder Ort, ohne weitere Angaben) an OpenStreetMap Nominatim, wenn sie nicht schon über die eigenen Daten bzw. Gebietskarten gefunden wird. Ergebnisse werden 30 Tage zwischengespeichert. Standard: aus.')],
                // Termin-Aktionen (Core\Data\EventActions): reine Links zu Kalender-Diensten – Standard aus, Blöcke können abweichen
                ['type' => 'heading', 'label' => __('Termine: Links zu Kalender-Diensten'),
                    'lead' => __('„In den Kalender“ und „Abonnieren“ auf Termin-Detailseiten kommen immer vom eigenen Server. Zusätzlich lassen sich reine Links zu Google Kalender und Outlook.com zeigen: ohne Skript und ohne Kontakt beim Laden der Seite – erst beim Klick öffnet sich die Seite des Anbieters, der dann Titel, Zeit, Ort und Beschreibung des Termins erhält.')],
                ['name' => 'sys.calendar_links', 'label' => __('Links auf Termin-Detailseiten'), 'type' => 'multiselect', 'default' => [],
                    'options' => ['google' => __('Google Kalender'), 'outlook' => 'Outlook.com'],
                    'help' => __('Nichts angehakt = keine Links zu Anbietern (Standard). Die Blöcke „Termin-Aktionen“ und „Datensatz-Felder“ können abweichen.')],
                ['type' => 'heading', 'label' => __('Zwischenspeicher'), 'collapse' => true, 'summary' => __('höchstens {n} MB', ['n' => (int) app()->settings->get('sys.proxy_cache_mb', 500)]),
                    'lead' => __('Externe Inhalte liegen unter /storage/cache/proxy (außerhalb des öffentlichen Ordners). Ist die Größe erreicht, fallen die ältesten Dateien weg.')],
                ['name' => 'sys.proxy_cache_mb', 'label' => __('Maximale Größe (MB)'), 'type' => 'number', 'default' => 500, 'width' => 'half'],
            ]],
            ['id' => 'sprachen', 'label' => __('Sprachen'), 'fields' => [
                ['type' => 'heading', 'label' => __('Sprachen der Website'), 'scope' => $scope,
                    'lead' => __('Die erste aktive Sprache ist die Standardsprache (Adressen ohne Präfix); jede weitere erhält ein Präfix, z. B. /en/…. Seiten und Einträge werden je Sprache angelegt und verknüpft.')],
                ['name' => 'sys.languages', 'label' => __('Sprachen'), 'type' => 'repeater', 'item_label' => __('Sprache'), 'title_field' => 'label',
                    'default' => [['code' => 'de', 'label' => 'Deutsch', 'active' => true]],
                    'help' => __('Eine Sprache lieber deaktivieren als entfernen: Inhalte in dieser Sprache bleiben dann erhalten.'),
                    'fields' => [
                        ['name' => 'code', 'label' => __('Kürzel (ISO, z. B. de, en, fr)'), 'type' => 'text', 'required' => true, 'width' => 'half', 'max' => 5],
                        ['name' => 'label', 'label' => __('Bezeichnung'), 'type' => 'text', 'required' => true, 'width' => 'half', 'placeholder' => __('z. B. English')],
                        ['name' => 'active', 'label' => __('Aktiv (auf der Website sichtbar)'), 'type' => 'bool', 'default' => true],
                    ]],
            ]],
            ['id' => 'medien', 'label' => __('Mediathek'), 'fields' => [
                ['type' => 'heading', 'label' => __('Löschen und Bildrechte'), 'scope' => $scope,
                    'lead' => __('Was mit gelöschten Dateien passiert und welche Angaben zu Rechten die Mediathek verlangt.')],
                ['name' => 'sys.media_trash_days', 'label' => __('Papierkorb'), 'type' => 'select', 'required' => true, 'default' => '30',
                    'options' => ['0' => __('Aus – Löschen ist sofort endgültig'), '7' => __('7 Tage aufbewahren'), '14' => __('14 Tage aufbewahren'), '30' => __('30 Tage aufbewahren'), '60' => __('60 Tage aufbewahren'), '90' => __('90 Tage aufbewahren')],
                    'help' => __('Gelöschte Dateien lassen sich so lange wiederherstellen; danach werden sie endgültig gelöscht.')],
                ['name' => 'sys.media_rights', 'label' => __('Bildrechte und Einwilligungen'), 'type' => 'select', 'required' => true, 'default' => 'optional',
                    'options' => ['off' => __('Ausblenden'), 'optional' => __('Optional – Felder zugeklappt in den Details'), 'required' => __('Pflicht – Lizenz/Quelle und Einwilligung beim Hochladen und Speichern')],
                    'help' => __('Lizenz bzw. Quelle, „Nutzungsrecht bis“ und ob abgebildete Personen eingewilligt haben. Fehlende oder ablaufende Angaben zeigt die Mediathek unter „Prüfen“.')],
                ['type' => 'heading', 'label' => __('Hochladen'),
                    'lead' => __('Gilt für Mediathek, Bildauswahl und Erweiterungen. Hochgeladen wird in Stücken – das PHP-Limit des Servers spielt keine Rolle, wohl aber der freie Speicherplatz.')],
                ['name' => 'sys.media_max_mb', 'label' => __('Größte Datei beim Hochladen (MB)'), 'type' => 'number', 'width' => 'half', 'default' => 50,
                    'help' => __('Für Videos z. B. 500 oder 2000.')],
                ['name' => 'sys.media_office', 'label' => __('Office-Dokumente erlauben (Word, Excel, PowerPoint, OpenDocument)'), 'type' => 'bool', 'default' => false,
                    'help' => __('Nur moderne Formate (DOCX, XLSX, PPTX, ODT, ODS, ODP); alte Formate und Dateien mit Makros werden immer abgelehnt. Empfehlung: PDF – sicher und auf jedem Gerät lesbar.')],
                ['type' => 'heading', 'label' => __('Prüfen: zu große Dateien'), 'collapse' => true,
                    'summary' => __('ab {mb} MB bzw. {px} px', ['mb' => (int) app()->settings->get('sys.media_large_mb', 8), 'px' => (int) app()->settings->get('sys.media_large_px', 6000)]),
                    'lead' => __('Solche Dateien zeigt die Mediathek unter „Prüfen → Zu große Dateien“; Bilder lassen sich dort auf 4000 px verkleinern.')],
                ['name' => 'sys.media_large_mb', 'label' => __('Als „zu groß“ gilt eine Datei ab (MB)'), 'type' => 'number', 'width' => 'half', 'default' => 8],
                ['name' => 'sys.media_large_px', 'label' => __('… bzw. ein Bild ab (Pixel, längere Seite)'), 'type' => 'number', 'width' => 'half', 'default' => 6000],
            ]],
            ['id' => 'mail', 'label' => __('E-Mail-Versand'), 'fields' => [
                // Konto „Standard“: zeigt system/_mail.php nur, solange noch kein Konto eingerichtet ist (sonst Seite E-Mail-Konten)
                ['type' => 'heading', 'label' => __('Konto „Standard“'),
                    'help' => __('Gilt nur für diese Website. Versendet alles, wofür unter „Verwendung“ kein anderes Konto gewählt ist. Für Plesk empfohlen: SMTP mit dem Postfach der Domain (Port 587, STARTTLS).')],
                ['name' => 'sys.mail_transport', 'label' => __('Versandart'), 'type' => 'select', 'required' => true, 'default' => 'smtp',
                    'options' => ['smtp' => 'SMTP', 'null' => __('Deaktiviert (nur protokollieren)')]],
                ['name' => 'sys.mail_host', 'label' => __('SMTP-Server'), 'type' => 'text', 'width' => 'half', 'placeholder' => __('z. B. localhost oder mail.ihre-domain.de')],
                ['name' => 'sys.mail_port', 'label' => __('Port'), 'type' => 'number', 'width' => 'half', 'default' => 587],
                ['name' => 'sys.mail_user', 'label' => __('Benutzername'), 'type' => 'text', 'width' => 'half'],
                ['name' => 'sys.mail_pass', 'label' => __('Passwort'), 'type' => 'secret', 'width' => 'half'],
                ['name' => 'sys.mail_encryption', 'label' => __('Verschlüsselung'), 'type' => 'select', 'default' => 'tls', 'required' => true,
                    'options' => ['tls' => __('STARTTLS (Port 587)'), 'ssl' => __('SSL/TLS (Port 465)'), 'none' => __('Keine (nur localhost)')]],
                ['name' => 'sys.mail_verify_peer', 'label' => __('TLS-Zertifikat prüfen'), 'type' => 'bool', 'default' => true,
                    'help' => __('Nur deaktivieren, wenn der Server „localhost“ mit abweichendem Zertifikat nutzt.')],
                ['name' => 'sys.mail_from', 'label' => __('Absender-Adresse'), 'type' => 'email', 'width' => 'half', 'placeholder' => 'website@ihre-domain.de',
                    'help' => __('Pflicht für den Versand – am besten das Postfach, mit dem angemeldet wird.')],
                ['name' => 'sys.mail_from_name', 'label' => __('Absender-Name'), 'type' => 'text', 'width' => 'half', 'default' => 'Website'],
                ['type' => 'heading', 'label' => __('Empfänger und Test'),
                    'help' => __('Benachrichtigungen enthalten nie Formularinhalte – nur den Hinweis „Neue Anfrage liegt vor“.')],
                ['name' => 'sys.mail_to', 'label' => __('Benachrichtigungen an'), 'type' => 'text',
                    'help' => __('Mehrere Adressen mit Komma trennen.'), 'placeholder' => 'info@ihre-domain.de'],
                ['name' => 'sys.mail_redirect', 'label' => __('Test-Umleitung: alle E-Mails an'), 'type' => 'text', 'placeholder' => 'test@ihre-domain.de',
                    'help' => __('Alle E-Mails dieser Website (Formulare, Eingangsbestätigungen, Benachrichtigungen, Anfragen) gehen stattdessen an diese Adresse(n), die eigentlichen Empfänger stehen in der Nachricht. Leer lassen = aus.')],
                // Core\Mail\Imprint: steht in jeder E-Mail der Website (Text und HTML), dazu der Link zur Impressum-Seite
                ['type' => 'heading', 'label' => __('Impressum für E-Mails')],
                ['name' => 'sys.mail_imprint', 'label' => __('Anbieterkennzeichnung'), 'type' => 'textarea', 'rows' => 6,
                    'placeholder' => "Muster GmbH\nMusterstraße 1, 12345 Musterstadt\nTelefon 01234 567890 · info@example.org\nGeschäftsführung: Erika Musterfrau\nAmtsgericht Musterstadt, HRB 12345 · USt-IdNr. DE123456789",
                    'help' => __('Eine Angabe je Zeile: Name bzw. Firma mit Rechtsform, Anschrift, Kontakt (Telefon, E-Mail), ggf. Vertretungsberechtigte, Registergericht und -nummer, USt-IdNr.')],
            ]],
            ['id' => 'spam', 'label' => __('Spamschutz'), 'fields' => [
                ['type' => 'heading', 'label' => __('Schutz der Formulare'), 'scope' => $scope,
                    'lead' => __('Mehrstufig und ohne externe Dienste: kein Captcha, keine Cookies, keine Daten an Dritte. Empfohlen: alle Stufen an.')],
                ['name' => 'sys.spam_honeypot', 'label' => __('Unsichtbares Fangfeld (Honeypot)'), 'type' => 'bool', 'default' => true],
                ['name' => 'sys.spam_pow', 'label' => __('Unsichtbare Rechenaufgabe im Browser (Proof-of-Work)'), 'type' => 'bool', 'default' => true,
                    'help' => __('Barrierefreie Alternative zu Captchas: kostet echte Besucher etwa eine Sekunde Rechenzeit, macht Massen-Spam teuer.')],
                ['name' => 'sys.spam_pow_difficulty', 'label' => __('Schwierigkeit der Rechenaufgabe'), 'type' => 'select', 'default' => '16', 'required' => true, 'show_if' => ['sys.spam_pow' => ['1']],
                    'options' => ['14' => __('Niedrig'), '16' => __('Mittel (empfohlen)'), '18' => __('Hoch'), '20' => __('Sehr hoch')]],
                ['name' => 'sys.spam_block_links', 'label' => __('Anfragen mit Links (http, www) ablehnen'), 'type' => 'bool', 'default' => true],
                ['name' => 'sys.spam_blocklist', 'label' => __('Gesperrte Begriffe'), 'type' => 'textarea', 'rows' => 3,
                    'help' => __('Ein Begriff pro Zeile, Groß-/Kleinschreibung egal.'), 'default' => "viagra\ncasino\ncrypto\nbitcoin\nseo service"],
                ['type' => 'heading', 'label' => __('Feineinstellungen'), 'collapse' => true, 'summary' => __('Zeiten und Mengen'),
                    'lead' => __('Grenzen für Ausfüllzeit und Anzahl der Anfragen. Die Standardwerte passen für fast alle Websites.')],
                ['name' => 'sys.spam_min_seconds', 'label' => __('Mindest-Ausfüllzeit (Sekunden)'), 'type' => 'number', 'width' => 'half', 'default' => 4,
                    'help' => __('Schneller abgeschickte Formulare werden verworfen (Bots).')],
                ['name' => 'sys.spam_max_hours', 'label' => __('Formular gültig für (Stunden)'), 'type' => 'number', 'width' => 'half', 'default' => 3],
                ['name' => 'sys.spam_rate_ip', 'label' => __('Höchstens Anfragen je Absender und Stunde'), 'type' => 'number', 'width' => 'half', 'default' => 5],
                ['name' => 'sys.spam_rate_global', 'label' => __('Höchstens Anfragen insgesamt je Stunde'), 'type' => 'number', 'width' => 'half', 'default' => 60],
                ['type' => 'heading', 'label' => __('Protokoll der Anfragen'), 'collapse' => true, 'summary' => __('{n} Monate', ['n' => (int) app()->settings->get('sys.inbox_log_months', 12)]),
                    'lead' => __('Jedes Entschlüsseln, jede Statusänderung und jedes Löschen wird protokolliert (ohne Inhalte). Wie lange erledigte Anfragen selbst bleiben, stellen Sie je Eingang unter Daten → Felder & Einstellungen ein.')],
                ['name' => 'sys.inbox_log_months', 'label' => __('Protokoll aufbewahren (Monate)'), 'type' => 'number', 'width' => 'half', 'default' => 12],
            ]],
            // Website-Suche und KI (Core\Search\AdminSettings – je nach Funktionsumfang „search“ bzw. „ai“)
            ...Search\AdminSettings::groups(),
        ];
    }

    public static function fields(): array
    {
        return array_merge(...array_map(fn($g) => $g['fields'], self::groups()));
    }

    public static function defaults(): array
    {
        return Fields::defaults(self::fields());
    }
}
