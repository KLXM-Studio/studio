<?php /** Handbuch · Kapitel „zentrale Angaben“ (Titel aus dem Kit) · @var string $settingsTitle  @var callable $has */ ?>
  <p class="lead">Hier stehen alle Angaben, die an mehreren Stellen der Website erscheinen. Einmal ändern – überall aktuell. Änderungen gelten sofort nach <b>Speichern</b>.</p>
  <table class="doc-table">
    <tr><th>Reiter</th><th>Enthält</th></tr>
    <?php foreach (app()->theme->settingsGroups() as $__g): $__labels = array_filter(array_column($__g['fields'], 'label')); ?>
    <tr><td><?= e($__g['label']) ?></td><td><?= e(implode(', ', array_slice($__labels, 0, 6))) ?><?= count($__labels) > 6 ? ' …' : '' ?></td></tr>
    <?php endforeach; ?>
  </table>
  <ul>
    <li><b>Vorschau:</b> Rechts zeigt die Seite auf Wunsch die echte Startseite mit Ihren <b>noch nicht gespeicherten</b> Werten – auf dem Computer und dem Handy.</li>
    <li><b>Mehrere Sprachen:</b> Über dem Formular wählen Sie die Sprache. In einer weiteren Sprache erscheinen nur Felder, die übersetzt werden können (Texte); leere Felder zeigen den Text der Standardsprache. Telefon, E-Mail und Adresse gelten für alle Sprachen.</li>
    <li><b>Visitenkarte:</b> Aus Name, Adresse, Telefon, E-Mail, Logo, Standort und Öffnungszeiten entsteht automatisch eine Visitenkarte zum Speichern im Adressbuch: <a href="<?= e(url('/vcard.vcf')) ?>"><code>/vcard.vcf</code></a>. Sie ist da, sobald Telefon, E-Mail oder Adresse eingetragen sind, und immer aktuell. Verlinken Sie sie z. B. als „Kontakt speichern“ (Link-Feld: Adresse <code>/vcard.vcf</code>). Personen einer Tabelle mit „Strukturierte Daten: Person“ (z. B. Team) haben eigene Karten unter <code>/vcard/{tabelle}/{adresse-des-eintrags}.vcf</code>.</li>
    <?php if (\Core\Notice::has()): ?><li><b>Hinweisbalken:</b> Mit <b>Anzeigen ab</b> und <b>Anzeigen bis</b> planen Sie einen Hinweis im Voraus (z. B. Betriebsferien) – er erscheint und verschwindet von selbst, der Haken muss dafür gesetzt sein. Unter <b>Darstellung</b> wählen Sie den Balken oben (wie im Design, linksbündig oder zentriert) oder eine <b>schwebende Bubble</b> unten links, unten mittig oder mitten im Bildschirm, die Besucher schließen können. Optional machen <b>Farbe</b> (z. B. Signalgelb, Rot) und <b>Hervorheben</b> (Pulsieren, kurz wackeln, Leuchten) den Hinweis auffälliger – die Bewegung läuft nur wenige Sekunden.</li><?php endif; ?>
    <li>Mit dem <b>Stern</b> merken Sie sich einen Reiter als Favorit.</li>
  </ul>
  <h3 id="website-angaben">Website-Angaben und Website-Einstellungen</h3>
  <p>Die Seite gibt es in zwei Stufen – je nach Rolle:</p>
  <ul>
    <li><b>Website-Angaben pflegen</b> (Recht <code>settings.info</code>): Name, Adresse und Kontakt, Öffnungszeiten, Hinweisbalken, Social Media, Texte im Fußbereich, Beschriftung und Link des Buttons im Kopfbereich und das Standard-Vorschaubild für soziale Netzwerke. Wer nur dieses Recht hat, sieht die Seite als <b>Website-Angaben</b> mit genau diesen Feldern.</li>
    <li><b><?= e($settingsTitle ?? app()->theme->settingsTitle()) ?> ändern</b> (Recht <code>settings.edit</code>): alles, zusätzlich Logo, Rechtstexte (Impressum, Datenschutz, Barrierefreiheit), SEO und Titel-Zusatz sowie die Schalter des Kits.</li>
  </ul>
  <p>Bestehende Rollen behalten ihre Rechte. Auf <b>neuen</b> Websites bekommt die Rolle „Redaktion“ nur die Website-Angaben; die Administration kann unter <b>Benutzer &amp; Rollen</b> jederzeit mehr freigeben. Technisches – Kit, Domain, E-Mail-Versand, Suchmaschinen aussperren – steht in den <a href="#grundeinstellungen">Grundeinstellungen</a>.</p>
  <ul>
    <li><b>Pflichtfelder</b> sind mit * markiert. <b>Impressum</b> und <b>Datenschutzerklärung</b> lassen sich, einmal gewählt, nicht mehr leeren – nur durch eine andere Seite ersetzen.</li>
    <li>Jede Gruppe beginnt mit einem Satz, wofür sie gilt und was passiert, wenn Felder leer bleiben; leere Felder zeigen den Wert, der dann gilt (z. B. „leer: Name der Website“).</li>
    <li><b>Letzte Änderungen:</b> Unter dem Formular stehen die letzten Änderungen mit Zeit, Person und Weg (Verwaltung, REST-API, MCP, KI), jeweils vorher → nachher. <b>Zurücknehmen</b> stellt die Werte vor dieser Änderung wieder her – solange niemand die Felder seitdem erneut geändert hat; sonst sagt ein Hinweis, welches Feld, und nichts wird überschrieben.</li>
  </ul>
  <h3 id="video-lightbox">Video-Links in der Lightbox</h3>
  <p>Unter <b>Darstellung → Video-Links</b> schalten Sie <b>Video-Links in Lightbox öffnen</b> ein (Standard: aus). Dann öffnen Buttons und Links auf ein YouTube- oder Vimeo-Video (Adressen wie <code>youtube.com/watch?v=…</code>, <code>youtu.be/…</code>, <code>youtube.com/shorts/…</code>, <code>vimeo.com/123456789</code>) den Player in einer großen Ansicht über der Seite – im Format 16:9, auf dem Telefon in voller Breite. Schließen mit Esc oder dem Kreuz; danach steht man wieder am Link.</p>
  <ul>
    <li><b>Nur mit Einwilligung:</b> Die Lightbox öffnet sich nur, wenn Besucher in den Dienst eingewilligt haben – über die Cookie-Einwilligung (Dienst YouTube bzw. Vimeo) oder, ohne Cookie-Einwilligung, mit „YouTube-Videos künftig direkt laden“ am Video-Block. Wer nicht eingewilligt hat, folgt einem ganz normalen Link wie bisher; bis dahin lädt die Seite nichts vom Anbieter. Ändert sich die Einwilligung, gilt das ab dem nächsten Klick.</li>
    <li><b>Zwei-Klick-Hinweis</b> (optional, Standard: aus): Mit <b>Ohne Einwilligung Zwei-Klick-Hinweis in der Lightbox zeigen</b> erklärt die Lightbox zuerst, dass der Anbieter Daten erhält, und lädt das Video erst nach „YouTube-Video laden“. Ein Vorschaubild erscheint nur, wenn es auf Ihrem Server zwischengespeichert ist.</li>
    <li><b>Einzelne Buttons ausnehmen:</b> Steht im Link-Feld eines Buttons eine Video-Adresse, erscheint darunter <b>Video ohne Lightbox öffnen (normaler Link)</b>. Im Fließtext finden Sie dieselbe Option in der Linkauswahl (Reiter „Web-Adresse“).</li>
    <li>Ohne JavaScript bleibt jeder Video-Link ein normaler Link. Der Link „Auf YouTube ansehen“ im Video-Block öffnet weiter die Seite des Anbieters.</li>
  </ul>
  <?php include __DIR__ . '/_design.php'; ?>
