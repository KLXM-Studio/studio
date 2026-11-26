<?php /** Handbuch · Website-Vorlagen, Schnelleinrichtung und Website kopieren (Core\Blueprint) */ ?>
  <p class="lead">Mit einer <b>Website-Vorlage</b> legen Sie neue Websites in wenigen Minuten an – zum Beispiel einen weiteren Landesverein mit
  denselben Seiten, Tabellen und Rollen und ein paar Beispiel-Einträgen. Danach führt die <b>Schnelleinrichtung</b> durch die eigenen Angaben.
  Vorlagen verwaltet die Netzwerk-Administration unter <b>Netzwerk → Website-Vorlagen</b> (Recht „Website-Vorlagen verwalten“).</p>

  <h3 id="vorlagen-inhalt">Was eine Vorlage enthält – und was nie</h3>
  <table class="doc-table">
    <tr><th>Geht mit</th><th>Geht nie mit</th></tr>
    <tr><td>Kit und Design-Werte, Seitenbaum mit Menü, Detail- und Listen-Vorlagen, eigene Blöcke, Datentabellen mit Feldern, Abschnitten,
      Freigaben und Art (Adressbuch, Kalender, Wissen, Glossar …), Rollen mit Blockrechten, Projekt-Hinweise, ausgewählte Grundeinstellungen,
      Funktionen und Erweiterungen</td>
      <td>Konten und Passwörter, Schlüssel und Tokens, E-Mail-Konten, Abonnenten, Anfragen und Formulareingänge, Aufgaben, Statistik,
      Protokolle, Versionen, Domains</td></tr>
  </table>
  <ul>
    <li><b>Angaben der Organisation werden Platzhalter:</b> Name, Kurzname, Claim, Anschrift, Telefon, E-Mail und Domain der Quelle stehen im
      Paket als <code>{{org.name}}</code>, <code>{{org.city}}</code> usw. – auch in Seitentexten. Beim Anlegen und in der Schnelleinrichtung
      werden sie mit Ihren Angaben gefüllt. Ebenso die Region der Quelle – Langname und gängige Abkürzung („Nordrhein-Westfalen“, „NRW“) werden
      <code>{{region.name}}</code> bzw. <code>{{region.short}}</code>.</li>
    <li><b>Texte zum Anpassen:</b> Steht die Quelle nur als Wortteil oder in anderer Form im Text („NRW-weit“, „nordrhein-westfälisch“) oder nennt
      ein Absatz Zahlen zur Region (Anzahl der Gruppen, Statistik – auch als Wort: „in elf Regionen“), ersetzt die Vorlage nichts selbst.
      Rechtsverweise („Art. 6 Abs. 1 lit. a DSGVO“), Adressen und Video-Kennungen zählen nicht. Mit einer Erweiterung wie Gebietskarten
      erkennt die Prüfung auch Orts- und Kreisnamen der Quell-Region; ein Absatz mit mehreren davon wird ein Fund „Absatz ist auf … bezogen“.
      Einträge, die an Flächen einer Karte hängen (z. B. Standorte), heißen in der Vorlage nach ihrer Lage („Standort Nord“) –
      geändert werden nur echte Verweise (Titel, Adresse, Links auf den Eintrag, Werte genau gleich dem Namen). Derselbe Name als Wort in
      einem Satz bleibt stehen und wird als „Absatz ist auf … bezogen“ gemeldet; die neue Website bekommt dafür eine Aufgabe. Die neue Website bekommt dafür je Stelle die
      Aufgabe „Text an Ihre Organisation anpassen“ mit Link. Das <b>Impressum</b> der Quelle geht nie mit – die Vorlage enthält ein Impressum mit
      Platzhaltern; „Vertreten durch“ und „Verantwortlich nach § 18 Abs. 2 MStV“ füllt die Schnelleinrichtung. Vorab prüfen: <code>php bin/console site:blueprint &lt;website&gt; --check</code> zeigt den Plan, ohne ein Paket zu schreiben.</li>
    <li><b>Bilder:</b> Der Bericht jeder Version (Versionen → „Bericht des Exports“) nennt die Bilder, die als Platzhalter ersetzt werden, mit Grund.
      Damit eine Vorlage echte Beispielbilder bekommt, in der Quelle unter Medien → Bild → Rechte eine freie Lizenz (z. B. „Pixabay-Lizenz“) und den
      Urheber eintragen und eine neue Version erstellen. Nennt der Urheberhinweis Pixabay, Pexels oder Unsplash („Image by … from Pixabay“),
      gilt die Lizenz des Portals als erkannt – das Bild geht mit, der Bericht nennt es, und die Mediathek schlägt die Lizenz zum Übernehmen vor.
      Das Platzhalterbild ist eine ruhige Fläche im dunklen Ton Ihrer Akzentfarbe (folgt jeder neuen Palette); angemeldete Personen sehen
      darauf den Hinweis „Bild fehlt“, Besucher nicht. Fehlt ein Video im Einstieg, zeigt er ebenfalls diese Fläche.</li>
    <li><b>Demoinhalte</b> (wählbar: wenige, alle oder keine): immer anonymisiert – Personen werden Platzhalter („Erika Musterfrau“),
      Organisationen „Beispiel-Organisation“, Kontaktangaben Beispielwerte, Termine liegen relativ zu heute in der Zukunft. Bilder nur, wenn sie
      frei sind (Lizenz wie Pixabay/Pexels und Urheber), sonst ein neutrales Platzhalterbild. Redaktionsnotizen fallen weg. Namen von Personen aus
      Konten und Daten (Kontakte, Ansprechpersonen) werden auch in Seitentexten und Einstellungen durch Platzhalter-Personen ersetzt.</li>
    <li><b>Netzwerk-Tabellen</b> (z. B. ein geteiltes Glossar) werden nicht kopiert: Die neue Website wird eingeladen und tritt bei.</li>
    <li>Vor dem Speichern prüft das System das ganze Paket auf Geheimnisse und personenbezogene Daten der Quelle. Gibt es einen Fund,
      entsteht kein Paket.</li>
  </ul>

  <h3 id="vorlagen-verwalten">Vorlagen verwalten</h3>
  <ul>
    <li><b>Aus Website erstellen:</b> Quelle, Kurzname, Name und Umfang der Demoinhalte wählen – es entsteht Version 1.0.0.</li>
    <li><b>Angaben und Schnelleinrichtung:</b> Beschreibung, Vorschaubild, welche Schritte die Schnelleinrichtung zeigt und eigene Platzhalter
      (z. B. <code>{{verband.name}}</code> mit Vorgabe).</li>
    <li><b>Demo-Einträge kuratieren:</b> je Tabelle die Einträge der Quelle ankreuzen, die als Beispiel mitgehen sollen.</li>
    <li><b>Neue Version aus Website:</b> mit kurzer Notiz für das Änderungsprotokoll. Angaben und Auswahl gelten ab der nächsten Version.</li>
    <li><b>Updates ausrollen:</b> Die Tabelle „Websites aus dieser Vorlage“ zeigt, was neu ist. „Als Vorschlag ausrollen“ legt den Vorschlag in
      die gewählten Websites – wie bei Kit-Updates wird nur Struktur übernommen (neue Tabellen und Felder, neue Seiten als Entwurf, Rollen),
      nie Inhalte, und erst, wenn die Website ihn in der Schnelleinrichtung übernimmt.</li>
  </ul>

  <h3 id="vorlagen-neue-website">Neue Website aus einer Vorlage</h3>
  <p>In der Vorlage „Neue Website aus Vorlage“: Kurzname, Domains und – optional – Name, Kurzname, Region und Farbe. Die Website entsteht mit
  dem Kit der Vorlage im <b>Wartungsmodus</b>. Angaben beim Anlegen gelten in der Schnelleinrichtung als erledigt bzw. vorbelegt (Farbe und Logo:
  Erscheinungsbild; Region: Gebiet; Name und Kurzname: Organisation); Besucher sehen „Diese Website wird gerade eingerichtet“. Das erste Konto legen Sie wie gewohnt
  über <code>/admin/setup</code> mit dem angezeigten Setup-Token an oder öffnen die Website als Netzwerk-Administration.</p>

  <h3 id="schnelleinrichtung">Schnelleinrichtung</h3>
  <p>Beim ersten Anmelden (Recht „Grundeinstellungen“) öffnet sich <b>Schnelleinrichtung</b>. Jeder Schritt speichert für sich, alles lässt sich
  später an der gewohnten Stelle ändern:</p>
  <ol>
    <li><b>Organisation:</b> Name, Kurzname, Claim, Rechtsform, Registereintrag, Anschrift, Telefon, E-Mail – erscheinen im Impressum, im
      Fußbereich, in den Kontaktdaten und als Absendername der E-Mails. Dazu für das Impressum: „Vertreten durch“ (z. B. der Vorstand mit
      Funktion) und die inhaltlich verantwortliche Person nach § 18 Abs. 2 MStV.</li>
    <li><b>Erscheinungsbild:</b> Logo und Akzentfarbe. Aus der Farbe entstehen alle Farben der Website – Kopf und dunkle Abschnitte, getönte
      Flächen, Linien, zweite Farbe, hell und dunkel. Zur Wahl stehen vier Paletten (Ausgewogen, Kräftig, Ruhig, Hoher Kontrast) mit Vorschau.
      Jede Farbe wird so angepasst, dass Text darauf lesbar bleibt (Text mindestens 4,5 : 1, Bedienelemente 3 : 1). Dunkle Abschnitte behalten
      den Farbton (dunkelgrün statt schwarz); die Diagrammfarben entstehen ebenfalls aus der Palette. Die beim Anlegen gewählte Palette bleibt,
      bis Sie eine andere wählen. Die Website der Vorlage bleibt unverändert.</li>
    <li><b>Gebiet:</b> Region bzw. Bundesland (auch „NRW“ oder der Landesschlüssel „05“) – setzt den Kartenpunkt der Organisation und „In deiner
      Nähe“ und ersetzt die Region in den Texten. Mit der Erweiterung Gebietskarten zeigen die Karten das neue Land; die Beispiel-Gebiete bekommen
      einzelne Kreise, dazu entsteht die Aufgabe „Gebiete zuordnen“.</li>
    <li><b>Netzwerk:</b> den geteilten Tabellen der Vorlage beitreten.</li>
    <li><b>Konten:</b> die ersten Personen mit Rolle einladen.</li>
    <li><b>Demoinhalte:</b> behalten oder entfernen (auch später mit <code>site:demo-remove</code>).</li>
    <li><b>E-Mail:</b> eigenes SMTP-Konto oder das Konto des Netzwerks, auf Wunsch Testmail.</li>
    <li><b>Recht:</b> Impressum und Datenschutzerklärung werden mit Ihren Angaben gefüllt; dazu entsteht je Seite die Aufgabe „Bitte prüfen“.</li>
  </ol>
  <p><b>Abschließen</b> legt alle offenen Punkte (übersprungene Schritte, ungefüllte Platzhalter, Erweiterungen zum Selbst-Einschalten) als
  Aufgaben an und beendet den Wartungsmodus. Die Seite bleibt erreichbar – etwa um später einen Vorlagen-Vorschlag zu übernehmen.</p>

  <h3 id="website-kopieren">Website kopieren</h3>
  <p>Unter <b>Netzwerk → Website-Vorlagen → Website kopieren</b> entsteht aus einer Website eine neue – als Testkopie, als Grundlage für
  einen neuen Verein oder als Archiv-Stand. Wählbar: vollständiger Inhalt, nur Struktur oder Struktur mit Beispiel-Einträgen; Medien
  kopieren, dieselben Dateien teilen oder weglassen; Konten übernehmen (ohne Passkeys) oder nicht; Personen anonymisieren; Versionen
  behalten; Testumgebung. Die Kopie bekommt immer neue Schlüssel; Abonnenten, Anfragen, Formulareingänge, Verläufe, Statistik und Tokens
  werden nie kopiert. An geteilten Tabellen ist die Kopie zunächst nicht beteiligt – die Schnelleinrichtung bietet „einladen und beitreten“ an.</p>
