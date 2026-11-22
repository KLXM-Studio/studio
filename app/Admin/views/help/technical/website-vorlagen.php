<?php /** Entwicklerhandbuch · Website-Vorlagen, Schnelleinrichtung, Website kopieren (Core\Blueprint) */ ?>
  <p>Eine <b>Website-Vorlage</b> ist ein Paket aus der Struktur einer Website (<code>Core\Blueprint\Export</code>), aus dem neue Websites
  entstehen (<code>Core\Blueprint\Apply</code>), gefolgt von der Schnelleinrichtung (<code>Core\Blueprint\QuickConfig</code>). Bibliothek der
  Installation: <code>storage/blueprints/{name}/</code> mit <code>meta.json</code> (Angaben, Versionen, kuratierte Demo-Einträge, Platzhalter,
  Schritte), <code>preview.(png|jpg|webp)</code> und je Version ein Paket <code>{version}/</code> (<code>Core\Blueprint\Library</code>).</p>

  <h3 id="blueprint-paket">Paketformat (Format 1)</h3>
  <table class="doc-table">
    <tr><th>Datei</th><th>Inhalt</th></tr>
    <tr><td><code>manifest.json</code></td><td><code>format</code> = <code>klxm-site-blueprint</code>, <code>format_version</code>, <code>name</code>, <code>title</code>, <code>description</code>,
      <code>version</code> (x.y.z), <code>created_at</code>, <code>studio_version</code>, <code>kit</code>, <code>demo</code> (anonym | none | full), <code>demo_counts</code>,
      <code>features</code> (<code>sys.features_ui</code> ohne ai, chat.*, api, mcp), <code>config</code> (preset, features, blocks), <code>extensions</code>,
      <code>network</code> (Netzwerk-Bezüge: key, handle, label, kind, owner, mode join, plan, local = <code>shared.{key}</code>, site_fields),
      <code>quick_config</code> {steps, placeholders}, <code>params</code>, <code>counts</code>, <code>changelog</code>, <code>files</code> (SHA-256 je Datei)</td></tr>
    <tr><td><code>structure/settings.json</code></td><td>Grundeinstellungen nach Whitelist <code>Export::SYS_SETTINGS</code>, Felder des Kits (ohne Typ secret/password, ohne geo/social;
      Angaben der Organisation <code>Export::ORG_SETTINGS</code> als Platzhalter), <code>design.{kit}</code>, Regeln der Detailseiten-Vorlagen <code>data.detail_templates.{tabelle}</code></td></tr>
    <tr><td><code>structure/pages.json</code></td><td>Zeilen von <code>pages</code> mit ihren IDs (Seiten und Vorlagen), Inhalte als JSON – <code>page:ID</code>, Detailseiten
      (<code>detail_page_id</code>) und Rechtstexte bleiben dadurch gültig</td></tr>
    <tr><td><code>structure/tables.json</code></td><td>eigene Datentabellen (id, handle, name, singular, icon, description, sort, fields, settings) – ohne Einträge;
      <code>settings.form.notify</code> geleert</td></tr>
    <tr><td><code>structure/roles.json</code>, <code>list_templates.json</code>, <code>custom_blocks.json</code></td><td>Rollen ohne admin/network (Rechte, Tabellen, Blockrechte),
      Listen-Vorlagen, eigene Blöcke</td></tr>
    <tr><td><code>structure/extensions.json</code></td><td>Abschnitte der Erweiterungen (Filter <code>blueprint.export</code>)</td></tr>
    <tr><td><code>guide/*</code></td><td>Projekt-Hinweise der Website (<code>Core\Guide::siteDir()</code>)</td></tr>
    <tr><td><code>demo/entries.json</code>, <code>demo/media.json</code>, <code>demo/media/*</code></td><td>Demo-Einträge je Tabelle {rows, shift, pivots}, freie Bilder mit Urheber und Lizenz</td></tr>
  </table>
  <p>Paket als Verzeichnis oder ZIP (<code>Package::open()</code> entpackt nur erlaubte Pfade/Endungen, begrenzt Größen und prüft alle Prüfsummen).
  Neue Einträge im Format nur additiv; ältere Pakete bleiben lesbar.</p>

  <h3 id="blueprint-datenschutz">Anonymisieren und Prüfen</h3>
  <ul class="doc-list">
    <li><b>Platzhalter</b> (<code>Core\Blueprint\Placeholders</code>): <code>sourceValues()</code> liest Name, Kurzname, Claim, Anschrift, Telefon, E-Mail und Domain
      der Quelle; <code>tokenize()</code> ersetzt sie in allen Texten durch <code>{{org.*}}</code>, <code>fill()</code> setzt Werte ein (in HTML escaped),
      <code>applySite()</code> füllt Seiten, Einstellungen und Einträge der Website und meldet offene Platzhalter. Eigene Platzhalter je Vorlage:
      <code>quick_config.placeholders</code> {schlüssel: {label, default, step}}.</li>
    <li><b>Demo-Einträge</b> (<code>Core\Blueprint\Anonymizer</code>): Kontakte (Art contacts) und Personenfelder → Platzhalter-Personen; Tabellen mit
      schema.org-Typ Organization u. a. → „Beispiel-Organisation n“ mit neutralem Text; E-Mail, Telefon, Web, IBAN, Kartenpunkt → Beispielwerte;
      Felder mit <code>private</code> leer; Fließtext ohne E-Mail-Adressen, Telefonnummern, IBANs und Redaktionsnotizen <code>[# … #]</code>;
      Datum relativ (<code>{"@rel": Tage}</code>, Kalender so verschoben, dass der erste Termin in einer Woche liegt). Keine Einträge aus Eingangs-Tabellen
      und Tabellen mit öffentlichem Formular. Verknüpfungen nur zwischen exportierten Einträgen.</li>
    <li><b>Bilder:</b> nur mit Urheber und freier Lizenz (<code>Export::FREE_LICENSE</code>; ohne Lizenzfeld zählt <code>Media::licenseFromCredit()</code> –
      Pixabay, Pexels, Unsplash im Urheberhinweis), sonst beim Anlegen ein Platzhalterbild (<code>Apply::PLACEHOLDER_TAG</code>): Fläche in
      <code>dark_section</code> mit feinen Linien, weiße Schrift überall ≥ 4,5; <code>Palette::apply()</code> zeichnet es neu (<code>Apply::refreshPlaceholder()</code>,
      gleiche ID). <code>Media::pictureOf()</code> setzt <code>data-cms-ph</code>, die Werkzeugleiste (<code>resources/js/_bar.js</code>) zeigt
      Angemeldeten „Bild fehlt“. Medien-IDs in Blöcken über die Schlüssel-Regel von <code>ContentSync::isMediaKey()</code>; ein nicht
      mitgenommenes <code>video</code> wird leer, ein leeres <code>image</code> daneben bekommt das Platzhalterbild (<code>Anonymizer::remapMedia()</code>).</li>
    <li><b>Personen der Quelle</b> (<code>Anonymizer::personNames()</code>): Namen aus Konten, Kontakten, Personenfeldern und Kombifeldern „Ansprechperson“ werden in allen Texten (Seiten, Einstellungen, Einträge, Abschnitte der Erweiterungen) durch Platzhalter-Personen ersetzt und im Paket geprüft.</li>
    <li><b>Prüfung</b> (<code>Export::audit()</code>): Nach dem Schreiben wird das Paket gegen E-Mail-Adressen und Namen der Konten, Zugangsdaten der
      E-Mail-Konten, Werte geheimer Einstellungen, <code>app_key</code>, Domains, Abonnenten, Einladungen und Aufgaben der Quelle sowie gegen
      E-Mail-Adressen und IBANs allgemein geprüft – bei einem Fund wird es verworfen (Meldung nennt Art und Datei, nie den Wert).</li>
  </ul>

  <h3 id="blueprint-anlegen">Anlegen</h3>
  <p><code>site:create &lt;key&gt; &lt;domain&gt; --blueprint=&lt;name|name@version|pfad&gt;</code> schreibt die Konfiguration mit dem Kit der Vorlage und
  <code>'seed' =&gt; 'blueprint'</code> (kein Seeder) und startet <code>blueprint:apply</code> als eigenen Prozess im Kontext der neuen Website.
  <code>Apply::run()</code> nur in leere Websites: Standardwerte, Einstellungen (<code>Apply::allowedSetting()</code>), Rollen, Seiten mit IDs, Tabellen
  (<code>Tables::sync</code>), Listen-Vorlagen, eigene Blöcke, Bilder, Demo-Einträge, Kalender-Index, Hinweise, Funktionen, Erweiterungen (nur
  installierte ohne <code>risk</code>), Filter <code>blueprint.apply</code>, Platzhalter mit den Parametern (<code>--name --short --color --logo --region</code>
  bzw. <code>--params=datei.json</code> mit <code>values</code>). Danach Netzwerk-Bezüge: fehlt die Einladung, lädt die Kommandozeile als Eigentümer-Website
  ein (<code>data:invite</code>), dann <code>Data\Network::join()</code>, Anzeige-Einstellungen und Detailseite aus der Vorlage
  (<code>Core\Blueprint\Network</code>). Herkunft in <code>sys.blueprint</code> {name, version, network, placeholders, structure}, Demoinhalte in
  <code>sys.blueprint_demo</code> (<code>Core\Blueprint\Demo::remove()</code>, <code>site:demo-remove</code>).</p>

  <h3 id="blueprint-schnell">Schnelleinrichtung</h3>
  <p><code>QuickConfig::start()</code> setzt <code>sys.quick_config</code> {open, steps, done, values} und den Wartungsmodus; die Übersicht leitet Personen
  mit <code>system.manage</code> nach <code>/admin/schnelleinrichtung</code> (mit <code>?uebersicht=1</code> nicht). Schritte <code>QuickConfig::STEPS</code>:
  org, look (<code>setAccent()</code> → <code>Palette::apply()</code>, siehe „Farben aus der Akzentfarbe“; <code>setLogo()</code>), region (<code>org.region</code>, Kartenpunkt <code>geo</code> je Bundesland, Filter <code>blueprint.region</code>),
  network, accounts (<code>Invites</code>), demo, mail (<code>Mail\Accounts::saveStandard</code> bzw. Netzwerk-Konto, Testmail), legal (Platzhalter,
  Aufgaben „Bitte prüfen“). <code>finish()</code> legt <code>checklist()</code> als Aufgaben an und beendet den Wartungsmodus. CLI:
  <code>site:quick-config --site=… [--step=… --feld=wert] [--finish]</code>. Health meldet eine offene Schnelleinrichtung als Hinweis.</p>

  <h3 id="blueprint-palette">Farben aus der Akzentfarbe (<code>Core\Blueprint\Palette</code>)</h3>
  <p><code>Palette::derive($farbe, $variante)</code> leitet aus einer Farbe alle abhängigen Werte ab – <code>accent</code>, <code>accent_strong</code>,
  <code>on_accent</code>, <code>highlight</code>, <code>secondary</code>, <code>on_secondary</code>, <code>surface</code>, <code>line</code>, <code>dark_section</code>, je mit
  <code>@dark</code> –, Varianten <code>balanced</code>, <code>vivid</code>, <code>calm</code>, <code>contrast</code> (HSL, Mischen mit Schwarz/Weiß bzw. dem Hintergrund).
  <code>Palette::forKit()</code> setzt nur Tokens, die das aktive Kit hat (fluid, native und Kits mit diesen Namen), und prüft danach jeden Wert gegen die
  Kontrast-Vorgabe seines Tokens (<code>contrast</code> =&gt; with, min, dark_with) mit <code>Design::readable()</code>. <code>Palette::apply()</code> speichert in
  <code>design.{kit}</code> der aktuellen (neuen) Website; die Quelle bleibt unverändert. Die Schnelleinrichtung zeigt die Varianten mit Vorschau
  (<code>GET /admin/schnelleinrichtung/paletten?color=</code>, <code>resources/js/_quickconfig.js</code>); <code>site:create --color=#… [--palette=…]</code>.
  <code>dark_section</code>: Farbton und Sättigung der Akzentfarbe bei HSL-Helligkeit 13–20 % (nicht fast schwarz), weiße Schrift ≥ 7.
  <code>Palette::charts()</code>: <code>chart_1</code> … <code>chart_6</code> (<code>Charts::designGroup</code>) – Akzent, zweite Farbe (wenn unterscheidbar), dann
  Farbtöne mit größtem Abstand, abwechselnd heller/dunkler, je ≥ 3 gegen den Hintergrund hell und dunkel. Gewählte Palette:
  <code>look.palette</code> in <code>sys.quick_config.values</code>; <code>QuickConfig::palette()</code> nimmt sie, wenn keine angegeben ist.
  Selbsttest: fünf Farben × vier Varianten (Akzent und Fläche mit Text ≥ 4,5, zweite Farbe ≥ 3, dunkle Abschnitte mit weißer Schrift ≥ 7, dunkel,
  Diagrammfarben ≥ 3 und paarweise unterscheidbar).</p>

  <h3 id="blueprint-texte">Texte der Quelle, Bilder, Probelauf</h3>
  <p>Region der Quelle: <code>--region=</code> beim Export, sonst Einstellung <code>org.region</code>, sonst Filter <code>blueprint.source</code> (z. B. Ausschnitt einer
  Gebietskarte). <code>QuickConfig::region()</code> kennt Bundesländer, Abkürzungen ab drei Zeichen (NRW, NDS, RLP) und Landesschlüssel. <code>Export::review()</code>
  zählt die Platzhalter und meldet Stellen, die nicht eindeutig ersetzt werden konnten: Angabe der Quelle als Wortteil bzw. Wortstamm der Region
  („NRW-weit“, „nordrhein-westfälisch“) und Zahlen bzw. Zahlwörter (<code>Export::NUMBER_WORDS</code>) neben <code>{{region.*}}</code> (ohne Jahreszahlen,
  Datum, Uhrzeit, Rechtsverweise wie „Art. 6 Abs. 1 lit. a“, „§ 25 Abs. 1“; <code>Export::regionNumber()</code>). Technische Felder und Werte
  (<code>Export::technicalKey()</code>/<code>technicalValue()</code>: Adressen, IDs, Video, Verweise) werden nicht geprüft; Felder mit Block-JSON werden
  entpackt, der Auszug ist Klartext – als <code>manifest.review</code>
  {where, link, title, reason, sample}; beim Anlegen je Stelle eine Aufgabe „Text an Ihre Organisation anpassen“. Das Impressum der Quelle wird durch
  <code>Export::IMPRINT</code> ersetzt – mit <code>{{org.representatives}}</code> und <code>{{org.responsible}}</code> (Schritt Organisation, CLI
  <code>--representatives=… --responsible=…</code>); ältere Pakete mit Redaktionsnotizen übersetzt <code>Placeholders::legacyImprint()</code> beim Anlegen. Bilder, die Platzhalter werden, stehen mit Grund (<code>Export::mediaReason()</code>:
  missing, pool, license, credit, both) in <code>manifest.media_report</code> und im Bericht der Version (<code>meta.json → versions[].report</code>).
  <code>site:blueprint … --check</code>: Plan ohne Paket.</p>

  <h3 id="blueprint-zweiter-schritt">Zweiter Schritt: Erweiterungen und Gebiet</h3>
  <p>Nach <code>blueprint:apply</code> startet <code>blueprint:apply-ext</code> als eigener Prozess: Erst dann laufen die gerade eingeschalteten Erweiterungen
  (Tabellen angelegt) und bekommen <code>blueprint.apply</code> mit <code>ctx.values</code> (Platzhalter). Danach der Schritt Gebiet aus <code>--region</code>
  (Filter <code>blueprint.region</code> mit <code>code</code>, <code>short</code>, <code>values</code>). Rückgaben der Filter: Texte als Meldung, Einträge
  <code>['task' =&gt; …, 'link' =&gt; …, 'body' =&gt; …]</code> als Aufgabe (<code>QuickConfig::handleLines()</code>). Werte aus <code>site:create</code> sind Vorbelegung:
  Farbe/Logo erledigen „Erscheinungsbild“, Region „Gebiet“, verbundene Netzwerk-Tabellen „Netzwerk“; Name/Kurzname belegen „Organisation“ vor.</p>

  <h3 id="blueprint-updates">Versionen und Vorschläge</h3>
  <p><code>Diff::signature()</code> bildet den Fingerabdruck der Struktur (Seiten nach Sprache und Pfad, Tabellen mit Feldtypen, Rollen mit Rechten,
  Listen-Vorlagen, eigene Blöcke); er wird beim Anlegen in <code>sys.blueprint.structure</code> gemerkt. <code>Library::affected()</code> vergleicht ihn mit der
  neuesten Version, <code>blueprint:propose &lt;name&gt; --site=…</code> legt den Vorschlag in <code>sys.blueprint_update</code>, <code>Diff::applyProposal()</code>
  (Schnelleinrichtung bzw. <code>blueprint:update --apply</code>) übernimmt nur Hinzufügendes, was die Website noch nicht hat: Tabellen, Felder, Seiten als
  Entwurf ohne Menü, Rollen, Listen-Vorlagen, eigene Blöcke. Entfallenes und Geändertes zeigt der Vorschlag nur an.</p>

  <h3 id="blueprint-kopie">Website kopieren</h3>
  <p><code>site:copy &lt;quelle&gt; &lt;neu&gt; &lt;domain&gt; [--content=full|structure|demo] [--media=copy|share|none] [--users=none|copy] [--anonymize]
  [--revisions] [--environment=staging] [--quick-config]</code> (<code>Core\Blueprint\Copy</code>). <b>structure/demo</b> laufen über Export und Anlegen.
  <b>full</b> (nur SQLite): neue Konfiguration (neuer <code>app_key</code>, Setup-Token; Konfiguration nach <code>Copy::CONFIG_KEYS</code>), Datenbank per
  <code>VACUUM INTO</code>, Medien kopieren bzw. per harten Links teilen (<code>share</code>), Projekt-Hinweise; dann <code>blueprint:copy-finish</code> in
  der Kopie: <code>Copy::PRIVATE_TABLES</code> leeren (Abonnenten, Anfragen-Protokoll, Statistik, Verläufe, Tokens, Passkeys, Einladungen …),
  Versionen ohne <code>--revisions</code>, Einträge von Eingangs-Tabellen und Tabellen mit öffentlichem Formular, Konten ohne <code>--users=copy</code>,
  Einstellungen <code>Copy::DROP_SETTINGS</code> und alle mit token/secret/private im Schlüssel. Geteilte Tabellen: Die Kopie ist nicht beteiligt –
  sie stehen als Netzwerk-Bezüge in <code>sys.blueprint</code> und werden in der Schnelleinrichtung per Einladung und Beitritt verbunden.
  <code>Copy::checksum()</code> liefert die Struktur-Prüfsumme (gleich der Quelle).</p>

  <h3 id="blueprint-erweiterungen">Erweiterungen</h3>
  <pre><code>$x->filter('blueprint.export', fn(array $s, array $c) => $s + ['gebiete' => ['karte' => …]]);   // nur Struktur, keine Personendaten
$x->filter('blueprint.apply', function (array $lines, array $c) { $mine = $c['sections']['gebiete'] ?? null; … return [...$lines, 'Gebietskarte eingerichtet']; });
$x->filter('blueprint.region', fn(array $lines, array $c) => [...$lines, 'Gebiet ' . $c['region'] . ' gesetzt']);</code></pre>

  <h3 id="blueprint-cli">Befehle und Selbsttest</h3>
  <ul class="doc-list">
    <li><code>site:blueprint &lt;key&gt; --out=ordner|datei.zip [--demo=anonym|none|full] [--name=…] [--title=…] [--version=1.0.0] [--per-table=3] [--options=datei.json]</code></li>
    <li><code>blueprint:list</code> (Bibliothek mit betroffenen Websites), <code>blueprint:propose</code>, <code>blueprint:update [--apply]</code>, <code>site:demo-remove</code></li>
    <li><code>blueprint:selftest</code>: Platzhalter, Anonymisieren, Paket, Whitelist, Vergleich, Kopie (ohne Daten). <code>blueprint:selftest --sandbox</code>
      (nur in einer Kopie der Installation): Wegwerf-Websites <code>bpst-…</code> mit Testdaten → Export → kein Passwort, kein Name, keine E-Mail/Telefon/IBAN im
      Paket → neue Website → gleiche Struktur, Platzhalter-Personen, Termine in der Zukunft, Wartungsmodus → Schnelleinrichtung, health, data:selftest →
      Demoinhalte entfernen → Version 1.1.0 als Vorschlag → <code>site:copy</code> mit gleicher Struktur-Prüfsumme, neuem Schlüssel, leeren
      Datenschutz-Tabellen, health, data:selftest, links:selftest. Am Ende werden die Websites entfernt (<code>--keep</code> lässt sie stehen).</li>
    <li>Nebenbei: <code>site:list</code> und <code>health</code> nennen <b>Domains ohne Website</b>, die über <code>fallback_site</code> eine andere Website
      ausliefern (<code>Core\Domains::noteFallback()</code> merkt sie beim Aufruf in <code>storage/cache/fallback-hosts.json</code>).</li>
  </ul>
