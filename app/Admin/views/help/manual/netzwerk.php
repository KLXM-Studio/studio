<?php /** Handbuch · Kapitel „Netzwerk-Verwaltung“ – eigener Bereich für alles, was die ganze Installation betrifft (Core\Network\Area) */ ?>
  <p class="lead">Laufen mehrere Websites in einer Installation, gibt es zwei klar getrennte Bereiche: die <b>Verwaltung jeder Website</b> – das gewohnte CMS mit Seiten, Medien, Daten, Benutzern und Grundeinstellungen – und den <b>Netzwerk-Bereich</b> für alles, was für die ganze Installation gilt. Den Netzwerk-Bereich sehen nur Konten mit der Rolle <b>Netzwerk-Administration</b>.</p>

  <h3 id="netzwerk-erkennen">So erkennen Sie, wo Sie sind</h3>
  <ul>
    <li><b>Netzwerk-Bereich:</b> dunkler Kopf mit dem gelben Kennzeichen <b>NETZWERK</b> und dem Satz „Einstellungen für die ganze Installation – gelten für alle … Websites“, dazu eine eigene, fast schwarze Seitenleiste.</li>
    <li><b>Verwaltung einer Website:</b> die gewohnte Seitenleiste in der Farbe der Verwaltung – ohne Netzwerk-Punkte.</li>
    <li><b>Umschalter</b> oben in jeder Seitenleiste (nur für Netzwerk-Konten): links <b>Netzwerk</b>, rechts <b>Website</b> mit der Liste aller Websites. Das aktive Feld ist hervorgehoben. Eine andere Website öffnen Sie mit einem Klick – Sie werden dort automatisch angemeldet und haben die vollen Rechte der Website.</li>
  </ul>

  <h3 id="netzwerk-was-wo">Was liegt wo?</h3>
  <table class="doc-table">
    <tr><th>Netzwerk-Bereich (ganze Installation)</th><th>Verwaltung jeder Website (nur diese Website)</th></tr>
    <tr><td><b>Websites</b> – Übersicht, neue Website, Name, Domains, Wartung, Testumgebung, Sicherung je Website</td><td>Übersicht, Seiten, Entwürfe, Medien, Daten, Eingang, KI, Chat, Mitteilungen</td></tr>
    <tr><td><b>Administratoren</b> – Netzwerk-Konten, Einladungen, Sperren, 2FA zurücksetzen</td><td>Benutzer &amp; Rollen der Website (Netzwerk-Konten erscheinen dort nur als Zeile „wird im Netzwerk verwaltet“)</td></tr>
    <tr><td><b>Anmeldung &amp; Domain</b> – Anmelde-Richtlinie der Netzwerk-Konten, Netzwerk-Domain, Adresse der Verwaltung, Agentur-Zugang per OpenID Connect</td><td>Benutzer &amp; Rollen → Sicherheit: Zwei-Faktor-Regeln und ein eigener Anmelde-Anbieter für die Redaktion der Website</td></tr>
    <tr><td><b>E-Mail</b> – Netzwerk-Konto (Absender der Installation), Kurzfassung der Systemmeldungen, Übersicht aller Websites</td><td>Grundeinstellungen → E-Mail-Versand: Konten, Absender je Zweck, Empfänger, Test und Umleitung, Impressum</td></tr>
    <tr><td><b>Kits</b>, <b>Schriften</b>, <b>Branding &amp; Support</b></td><td>Grundeinstellungen → Allgemein: welches Kit die Website nutzt; Einstellungen → Gestaltung</td></tr>
    <tr><td><b>Geteilte Medien &amp; Daten</b> – Medien-Pools anlegen und zuordnen, Überblick geteilter Tabellen</td><td>Mediathek (Umschaltung auf geteilte Medien), Grundeinstellungen → Geteilte Daten (Tabellen, an denen die Website teilnimmt)</td></tr>
    <tr><td><b>KI-Verbindungen</b> – Anbieter, Schlüssel, Modelle, Verwendung</td><td>Grundeinstellungen → KI: ob die Website KI nutzt, Datenschutz, Besucher-Chat</td></tr>
    <tr><td><b>Geplante Aufgaben</b>, <b>Sicherung außer Haus</b>, <b>Integrität der Dateien</b>, <b>Systemmeldungen</b>, <b>Speicher</b>, <b>Protokoll</b></td><td>Grundeinstellungen → Integrität (Datenbank der Website), Überwachung, Umgebung, Systeminfo</td></tr>
  </table>
  <p>Lesezeichen und Favoriten auf die früheren Adressen (z. B. <code>/admin/system/aufgaben</code>, <code>/admin/system/kits</code>, <code>/admin/system/fonts</code>) funktionieren weiter: Sie landen automatisch auf der neuen Seite im Netzwerk-Bereich.</p>

  <h3 id="netzwerk-rechte">Wer darf was?</h3>
  <ul>
    <li><b>Netzwerk-Administration</b> (Rolle „network“): alles im Netzwerk-Bereich – und auf jeder Website die vollen Rechte wie bisher.</li>
    <li><b>Administration einer Website</b> (auch der Website, auf der das Netzwerk liegt): nur ihre Website. Installationsweite Seiten sieht sie nicht.</li>
    <li><b>Einzelinstallation</b> (nur eine Website): kein Netzwerk-Bereich – Geplante Aufgaben, Sicherung, Kits, Schriften, Geteilte Medien und Adresse der Verwaltung bleiben in den Grundeinstellungen.</li>
  </ul>

  <h3 id="systemmeldungen">Systemmeldungen: wer bekommt technische Warnungen?</h3>
  <p>Unter <b>Netzwerk → Systemmeldungen</b> legen Sie fest, wer technische Warnungen bekommt: <b>Integrität</b> (Dateien der Installation und Datenbank jeder Website), <b>Sicherheit</b>, <b>Sicherung</b> außer Haus, <b>Geplante Aufgaben</b>, <b>technische Fehler</b>, <b>Updates und Lebensende</b> sowie <b>Mail-Zustellprobleme</b> von Anfragen. Gibt es eine Agentur, sollte sie diese Meldungen bekommen – die Admins der Kunden-Websites bleiben dann unbehelligt und bekommen nur Redaktionelles (neue Anfragen, Freigaben, Aufgaben).</p>
  <ul>
    <li><b>Automatisch</b> (Standard): Ist ein <b>Agentur-Kontakt</b> eingetragen, gehen die Meldungen an ihn; sonst an <b>Bestimmte Adressen</b>, falls eingetragen; sonst an die <b>Agentur-Konten</b> (Anmeldung über den Agentur-Zugang per OpenID Connect); sonst wie bisher an alle Netzwerk-Admins – Hinweise zur Datenbank einer Website an deren Administration.</li>
    <li><b>Agentur bevorzugt</b>: nur Agentur-Kontakt bzw. Agentur-Konten. Mit „Alle Netzwerk-Admins zusätzlich informieren“ bekommen auch die Admins eine Kopie.</li>
    <li><b>Bestimmte Adressen</b>: die eingetragene Liste (höchstens 10 Adressen). <b>Alle Netzwerk-Admins</b>: das bisherige Verhalten.</li>
  </ul>
  <p>In der Tabelle <b>Kategorien</b> lässt sich jede Kategorie abwählen („aus“) oder umleiten (z. B. Zustellprobleme an die Admins), dazu kommen bei Bedarf weitere Adressen. Die Spalte <b>Wirksam</b> zeigt, wer die Meldung heute bekommt; <b>Test</b> schickt eine Testnachricht an genau diese Empfänger. Jede Fehlerserie meldet sich nur einmal – nach der nächsten erfolgreichen Ausführung wieder; Zustellprobleme höchstens einmal je Stunde und Tabelle. Ist das Ziel nicht eingerichtet (z. B. „Agentur bevorzugt“ ohne Kontakt), greift der Rückfall – eine Warnung geht nie ins Leere.</p>
  <p><b>Wichtig für bestehende Installationen:</b> Ohne Einstellung bleibt alles wie bisher. Damit die Agentur die Meldungen bekommt, tragen Sie unter Netzwerk → Systemmeldungen die Agentur-Adresse ein (oder richten den Agentur-Zugang ein). Wer was bekommt, sehen Sie auch in der <a href="#mail-uebersicht">E-Mail-Übersicht</a> („Integrität → technik@…“).</p>
  <p>Mit mehreren Websites braucht das Netzwerk ein <b>Netzwerk-Konto</b> für E-Mails (Netzwerk → E-Mail). Fehlt es, meldet die Systemprüfung einen Fehler.</p>
