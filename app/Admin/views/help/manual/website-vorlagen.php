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
      werden sie mit Ihren Angaben gefüllt.</li>
    <li><b>Demoinhalte</b> (wählbar: wenige, alle oder keine): immer anonymisiert – Personen werden Platzhalter („Erika Musterfrau“),
      Organisationen „Beispiel-Organisation“, Kontaktangaben Beispielwerte, Termine liegen relativ zu heute in der Zukunft. Bilder nur, wenn sie
      frei sind (Lizenz wie Pixabay/Pexels und Urheber), sonst ein neutrales Platzhalterbild. Redaktionsnotizen fallen weg.</li>
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
  dem Kit der Vorlage im <b>Wartungsmodus</b>; Besucher sehen „Diese Website wird gerade eingerichtet“. Das erste Konto legen Sie wie gewohnt
  über <code>/admin/setup</code> mit dem angezeigten Setup-Token an oder öffnen die Website als Netzwerk-Administration.</p>

  <h3 id="schnelleinrichtung">Schnelleinrichtung</h3>
  <p>Beim ersten Anmelden (Recht „Grundeinstellungen“) öffnet sich <b>Schnelleinrichtung</b>. Jeder Schritt speichert für sich, alles lässt sich
  später an der gewohnten Stelle ändern:</p>
  <ol>
    <li><b>Organisation:</b> Name, Kurzname, Claim, Rechtsform, Registereintrag, Anschrift, Telefon, E-Mail – erscheinen im Impressum, im
      Fußbereich, in den Kontaktdaten und als Absendername der E-Mails.</li>
    <li><b>Erscheinungsbild:</b> Logo und Akzentfarbe mit Vorschau; ist die Farbe zu hell für weiße Schrift, wird sie beim Speichern so weit
      abgedunkelt, dass der Kontrast mindestens 4,5 : 1 beträgt.</li>
    <li><b>Gebiet:</b> Region bzw. Bundesland – setzt den Kartenpunkt der Organisation und „In deiner Nähe“; mit der Erweiterung Gebietskarten
      auch die zuständigen Gebiete.</li>
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
