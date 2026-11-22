<?php /** Handbuch · Kapitel „Wissensbereich & Handbuch“ (Core\Data\Knowledge; Variablen: siehe help/manual.php) */ ?>
  <p class="lead">Ein <b>Wissensbereich</b> ist ein Handbuch auf der Website: Kapitel mit Unterkapiteln, Kapitelnummern, einer Seitenleiste zum Blättern, einer Suche nur in diesem Bereich und Filtern nach Schlagworten. Die Kapitel pflegen Sie als Einträge einer Datentabelle der Art <b>„Wissen“</b> – Rechte, Entwürfe, Versionen, Zeitplan und das Teilen im Netzwerk funktionieren wie bei allen Daten.</p>

  <h3>Kurz erklärt: wie die Gliederung entsteht</h3>
  <ul>
    <li><b>Bereich</b> – die oberste Gliederung, z. B. „Grundlagen“, „Praxis“, „Recht“. Die Reihenfolge der Bereiche ergibt die erste Stelle der Kapitelnummer.</li>
    <li><b>Oberkapitel</b> – leer heißt: Kapitel der ersten Ebene. Mit Oberkapitel steht ein Kapitel darunter (2.1 unter 2, 2.1.3 unter 2.1).</li>
    <li><b>Reihenfolge</b> – wie in der Eintragsliste (Ziehen oder „Sortierung“), bei Gleichstand nach Titel.</li>
    <li><b>Abschnitte</b> – die Zwischenüberschriften (H2) im Inhalt eines Kapitels. Sie erscheinen als „In diesem Kapitel“ und laufen beim Lesen mit.</li>
  </ul>
  <div class="doc-note doc-note--tip"><strong>Merke</strong> Kapitel sind Einträge, Abschnitte sind Zwischenüberschriften. Eine Überschrift im Text erzeugt nie ein eigenes Kapitel – dafür legen Sie einen neuen Eintrag an.</div>

  <h3>Einrichten (einmal, Administration)</h3>
  <ol class="doc-steps">
    <li><b>Daten → Neue Tabelle → „Inhalte auf der Website“ → Vorlage „Wissen“.</b> Adresse prüfen (z. B. <code>wissen</code> → Kapitel unter <code>/wissen/…</code>) und anlegen. Detailseiten-Vorlage und Übersicht entstehen beim Einsetzen.</li>
    <li><b>Einsetzen → „Neue Seite“</b> legt die Übersicht an (Startseite des Wissensbereichs; im Block unter „Kapitel als“ wahlweise <b>Inhaltsverzeichnis</b> oder <b>Karten</b>: Kapitel der obersten Ebene im Raster mit Bild, Nummer, Anreißer und bis zu drei Unterkapiteln; Spalten und Bildformat wie bei anderen Karten; Verweis-Kapitel ohne eigenes Bild zeigen das Bild ihrer Seite): Suchfeld, Filter nach Bereich und Schlagworten und das <b>Inhaltsverzeichnis</b> (Block „Datenliste“, Darstellung „Kapitel“). Darunter passt eine zweite Datenliste „Neu im Wissensbereich“ (sortiert nach „Geändert“, absteigend, 5 Einträge).</li>
    <li><b>Felder &amp; Einstellungen → Auf der Website → Wissensbereich:</b> Layout wählen, Kapitelnummern, Vor/Zurück und verwandte Kapitel ein- oder ausschalten.</li>
    <li>Optional: Glossar einschalten (Kapitel „Glossar“) – dann lassen sich Begriffe mit Kapiteln verknüpfen.</li>
  </ol>
  <p>Per Kommandozeile geht es auch: <code>php bin/console data:template knowledge --with-detail-page --with-list-page [--style=sachlich]</code>.</p>

  <h3 id="wissen-stile">Zwei Stile: Magazin und Sachlich</h3>
  <p>Beim Anlegen wählen Sie den <b>Stil der Kapitelseiten</b>. Beide sind normale Detailseiten-Vorlagen, die Sie wie jede Seite weiter gestalten können:</p>
  <ul>
    <li><b>Magazin</b> (Standard): großes Kapitelbild über die volle Breite mit Titel, Anreißer und der Nummer als Dachzeile („Kapitel 2.1“), darunter die Kapitelnavigation neben dem Text. Die Übersicht zeigt die Kapitel als Karten mit Bild.</li>
    <li><b>Sachlich / Handbuch:</b> ohne großes Bild, Seitenleiste mit Kapitelbaum und Abschnitten von Anfang an, große Nummer vor dem Titel, ruhigere, kompakte Schrift, „Zuletzt geprüft“ im Fuß. Die Übersicht ist ein Inhaltsverzeichnis. Gedacht für Satzungen, Ordnungen und Prüfungsordnungen.</li>
  </ul>
  <p>Später umschalten oder beide nutzen: <b>Daten → Tabelle → Detailseiten-Vorlagen</b> → „Neue Vorlage“ mit Ausgangspunkt „Mitgeliefert: …“, dann „Als Standard“ – oder nur für einen Bereich bzw. ein Kapitel samt Unterkapiteln zuordnen (siehe <a href="<?= e(url('/admin/hilfe#detailvorlagen')) ?>">Mehrere Detailseiten-Vorlagen</a>). Mehrere Wissensbereiche einer Website können so jeweils ihren eigenen Stil haben.</p>

  <h3 id="kapitelnavigation">Block „Kapitelnavigation“</h3>
  <p>In Vorlagen der Stile Magazin und Sachlich steckt die Navigation im Block <b>Kapitelnavigation</b>. Blöcke <b>darüber</b> stehen über die volle Breite (z. B. der Einstieg), Blöcke <b>darunter</b> neben bzw. unter der Navigation. Verschieben Sie den Block, ändert sich also, was über die volle Breite läuft. Einstellungen:</p>
  <ul>
    <li><b>Position:</b> Seitenleiste links, Seitenleiste rechts oder oben als aufklappbares Menü.</li>
    <li><b>Kapitelbaum, Abschnitte, Suche, Brotkrumen, Fuß</b> (Schlagworte, Begriffe, Verwandte, Zurück/Weiter) jeweils an oder aus; <b>Kompakt</b> für eine schmale, dichte Leiste und ruhigere Schrift.</li>
    <li>Block <b>ausblenden</b> (Auge) = keine Navigation, nur der Text. Fehlt der Block ganz (alle bisherigen Vorlagen), legt die Website den Rahmen wie bisher nach dem Layout aus den Einstellungen der Tabelle um die Vorlage.</li>
  </ul>
  <p><b>Kapitelnummer:</b> Im Einstieg wählen Sie unter „Kapitelnummer“ <i>Automatisch</i> (als Dachzeile, zeigt die Dachzeile ein anderes Feld: als kleine Plakette vor dem Titel), <i>Als Plakette</i> oder <i>Keine Nummer</i>; der Text der Dachzeile lässt sich anpassen („Kapitel {nummer}“, „Abschnitt {nummer}“ …). Im schlichten Kopf (Block „Datensatz-Felder“, Darstellung „Kopf“) schaltet „Kapitelnummer groß vor dem Titel“ die Nummer an oder aus. In Seitenleiste und Karten bleiben die Nummern, solange sie in den Einstellungen der Tabelle eingeschaltet sind.</p>

  <h3>Layouts</h3>
  <table class="doc-table">
    <thead><tr><th>Layout</th><th>Kapitel</th><th>Abschnitte des Kapitels</th></tr></thead>
    <tbody>
      <tr><td><b>Klassisch</b></td><td>Seitenleiste links, läuft beim Scrollen mit</td><td>in der Seitenleiste unter dem aufgerufenen Kapitel</td></tr>
      <tr><td><b>Kompakt</b></td><td>wie Klassisch, schmaler und dichter</td><td>in der Seitenleiste</td></tr>
      <tr><td><b>Fokus</b></td><td>keine Seitenleiste – der Knopf „Kapitel“ unten links öffnet das Kapitelmenü als Schublade</td><td>in der Schublade</td></tr>
      <tr><td><b>Handbuch</b></td><td>Seitenleiste nur mit Kapiteln</td><td>„In diesem Kapitel“ rechts neben dem Text (ab etwa 1200 px Breite), sonst aufklappbar unter der Überschrift</td></tr>
      <tr><td><b>Handbuch kompakt</b></td><td>wie Handbuch</td><td>immer aufklappbar unter der Überschrift</td></tr>
    </tbody>
  </table>
  <p>„Handbuch“ passt für lange Kapitel mit vielen Abschnitten: Die Seitenleiste bleibt kurz, die Abschnitte stehen neben dem Text. Auf dem Telefon öffnet in allen Layouts der Knopf <b>„Kapitel“</b> (unten links) das Kapitelmenü mit Suche; schließen mit ×, <kbd>Esc</kbd> oder Tippen daneben.</p>

  <h3>Schritt für Schritt: ein Kapitel schreiben</h3>
  <ol class="doc-steps">
    <li><b>Daten → Wissen → Neuer Eintrag.</b> Titel vergeben – kurz und eindeutig („Wildbestand zählen“ statt „Allgemeines“).</li>
    <li><b>Bereich</b> wählen bzw. unter <b>„Neuer Bereich“</b> einen anlegen; bei Unterkapiteln das <b>Oberkapitel</b>.</li>
    <li><b>Kapitelnummer</b> leer lassen – sie entsteht automatisch. Nur für feste Nummern (z. B. „A.1“ im Anhang) selbst eintragen.</li>
    <li><b>Anreißer:</b> ein, zwei Sätze, worum es geht. Er steht unter der Überschrift, im Inhaltsverzeichnis und in der Suche.</li>
    <li><b>Bild</b> (optional): erscheint im Kopf des Kapitels, in Karten und beim Teilen.</li>
    <li><b>Inhalt:</b> Blöcke wie auf einer Seite – Fließtext, Bilder, Interaktives Bild, Downloads, auch eine <b>Datenliste</b> (z. B. die Termine zum Thema) … Gliedern Sie mit <b>Zwischenüberschriften (H2)</b>; sie werden zu den Abschnitten.</li>
    <li><b>Zuordnung</b> (aufklappen): <b>Schlagworte</b> ankreuzen oder unter „Neue Schlagworte“ ergänzen (durch Komma getrennt), <b>Verwandte Kapitel</b> und <b>Glossarbegriffe</b> verknüpfen, <b>Autor/in</b> und <b>„Zuletzt geprüft“</b> eintragen.</li>
    <li>Speichern, auf der Website prüfen, veröffentlichen. Auch beim Bearbeiten direkt auf der Website („Bearbeiten“ in der Leiste) bleibt das Kapitel-Layout mit Seitenleiste, Nummer und Brotkrumen stehen – die Seitenleiste ist dabei nur Orientierung (ohne Suche, nicht anklickbar).</li>
  </ol>

  <h3>Kapitel als Verweis auf eine Seite</h3>
  <p>Manche Inhalte bleiben besser eine eigene Seite – etwa eine Seite mit einer Datenliste. Damit sie trotzdem im Handbuch steht, legen Sie ein Kapitel an und wählen unter <b>Zuordnung → „Verweis auf Seite“</b> die Seite aus (Linkauswahl). Den Inhalt lassen Sie leer. Das Kapitel erscheint dann mit Nummer in Seitenleiste, Inhaltsverzeichnis, Suche und Zurück/Weiter und führt direkt auf die Seite; seine eigene Adresse leitet dorthin weiter.</p>

  <h3>Schlagworte, verwandte Kapitel und Begriffe</h3>
  <ul>
    <li><b>Schlagworte</b> stehen unter jedem Kapitel als Links: Ein Klick zeigt die Übersicht gefiltert nach diesem Schlagwort. Lieber wenige, klare Schlagworte in einheitlicher Schreibweise (immer „Jagdrecht“, nicht mal „Jagd-Recht“).</li>
    <li><b>Verwandte Kapitel</b> erscheinen am Ende des Kapitels: zuerst die, die Sie verknüpft haben, dann Kapitel mit gemeinsamen Schlagworten.</li>
    <li><b>Begriffe in diesem Kapitel:</b> verknüpfte Glossarbegriffe mit Kurz-Erklärung. Das Glossar bleibt das eine Glossar der Website – es markiert Begriffe überall, auch in Wissenskapiteln. Es gibt kein eigenes Glossar je Wissensbereich.</li>
  </ul>

  <h3>Was Besucher sehen</h3>
  <ul>
    <li><b>Übersicht:</b> Suchfeld mit Treffern und Textauszug (Suchwort markiert), passende Glossarbegriffe als eigene Gruppe, Filter-Chips nach Bereich und Schlagworten (kombinierbar: innerhalb einer Reihe genügt eine der gewählten Optionen, beide Reihen gelten zusammen), ohne Suche das Inhaltsverzeichnis mit Nummern. Die Auswahl steht in der Adresse – teilbar, die Zurück-Taste führt zur vorigen Auswahl. Ohne JavaScript funktioniert alles als normales Formular.</li>
    <li><b>Menü:</b> Mit „Einträge im Menü zeigen“ (Felder &amp; Einstellungen → Auf der Website) stehen die Kapitel unter der Übersichtsseite im Kopfmenü – oberste Ebene oder zwei Ebenen, auf Wunsch mit Nummer. Der Umstieg übernimmt den Menüstand der alten Seiten.</li>
    <li><b>Kapitel:</b> Brotkrumen, Nummer vor dem Titel, Seitenleiste mit Suche, „In diesem Kapitel“, Schlagworte, Begriffe, verwandte Kapitel und <b>Zurück / Weiter</b> in Lesereihenfolge.</li>
    <li><b>Kopf wie auf Seiten</b> (Handbuch → Daten → <a href="<?= e(url('/admin/hilfe#kopf-wie-seiten')) ?>">Kopf wie auf Seiten</a>): Beginnt die Detailseiten-Vorlage mit dem Block „Einstieg (Hero)“ und der Quelle „Felder des Eintrags“, steht oben das Kapitelbild über die volle Breite mit Titel und Anreißer; die Kapitelnummer erscheint dezent als Dachzeile („Kapitel 2.1“) – ist die Dachzeile mit einem anderen Feld belegt (z. B. Bereich), als kleine Plakette vor dem Titel; ohne Nummern gar nicht. In Seitenleiste und Karten bleiben die Nummern wie gewohnt. Seitenleiste, Brotkrumen und Kapiteltext beginnen darunter. Neue Wissensbereiche im Stil „Magazin“ beginnen so (siehe <a href="#wissen-stile">Zwei Stile</a>).</li>
    <li>Farben und Schrift kommen aus dem Kit – Sie müssen nichts gestalten.</li>
    <li>Für Suchmaschinen beschreibt die Website den Wissensbereich als Sammlung von Artikeln mit Kapitelnummer, Bereich, Schlagworten, Begriffen und „Zuletzt geprüft“ – automatisch, ohne Pflege.</li>
  </ul>

  <h3>Vorhandene Seiten übernehmen</h3>
  <p>Gibt es schon einen Seitenzweig „Wissen“ mit Unterseiten, stellt die Administration ihn per Kommandozeile um: <code>php bin/console data:import-pages wissen</code> zeigt zuerst einen <b>Probelauf</b> (welche Seite welches Kapitel wird, welche Adressen sich ändern, welche Blöcke zusätzlich erlaubt werden und welche nicht übertragbar sind), <code>--apply</code> führt ihn aus. Kapitel der ersten Ebene behalten ihre Adresse, tiefere bekommen eine Weiterleitung; die alten Seiten liegen offline unter „… – vor der Umstellung“. <code>--revert</code> dreht alles zurück.</p>
  <ul>
    <li><b>Blöcke:</b> Was in Kapiteln wählbar ist (Text, Bilder, Interaktives Bild, Blöcke von Erweiterungen …), wird übernommen und im Feld „Inhalt“ freigeschaltet. Der Seitenkopf wird nicht übernommen – sein Text wird zum Anreißer, sein Bild zum Bild des Kapitels.</li>
    <li><b>Datenlisten</b> werden mit übernommen – Seiten mit einer Liste werden ganz normale Kapitel.</li>
    <li><b>Nicht übertragbar</b> sind andere Daten-Blöcke (z. B. Formulare). Für solche Seiten schlägt der Probelauf <code>--keep=seite,…</code> vor: Diese Seiten bleiben Seiten und werden als Kapitel vom Typ „Verweis“ eingehängt.</li>
    <li><code>--blocks=richtext,gallery,…</code> legt beim Anlegen der Tabelle fest, welche Blöcke im Feld „Inhalt“ erlaubt sind (Blöcke der Seiten kommen dazu).</li>
  </ul>

  <h3>Häufige Fehler</h3>
  <ol>
    <li><b>Ein Kapitel fehlt in der Seitenleiste</b> – Entwurf? Nur veröffentlichte Kapitel erscheinen. Oder das Oberkapitel ist offline: Unterkapitel eines Entwurfs rücken nach oben.</li>
    <li><b>„In diesem Kapitel“ ist leer</b> – der Text hat keine Zwischenüberschriften (H2) bzw. nur eine. Ab zwei Abschnitten erscheint die Übersicht.</li>
    <li><b>Nummern springen</b> – Reihenfolge der Bereiche (Felder &amp; Einstellungen → Feld „Bereich“) oder der Kapitel in der Eintragsliste prüfen.</li>
  </ol>

  <h3>Checkliste vor dem Veröffentlichen</h3>
  <ul>
    <li>Titel verständlich, Bereich und Oberkapitel richtig</li>
    <li>Anreißer geschrieben, Zwischenüberschriften gesetzt</li>
    <li>Schlagworte einheitlich, verwandte Kapitel und Begriffe verknüpft</li>
    <li>Bilder mit Alternativtext, Links mit sprechendem Text (nicht „hier klicken“)</li>
    <li>„Zuletzt geprüft“ aktuell</li>
  </ul>

  <h3>Barrierefreiheit</h3>
  <ul>
    <li>Überschriften der Reihe nach: H2 für Abschnitte, darunter H3 – keine Sprünge, Überschriften nicht nur für die Optik.</li>
    <li>Wichtige Informationen nie nur im Bild; kein Text in Bildern, wenn er als Text möglich ist.</li>
    <li>Tabellen nur für echte Tabellen, lange Inhalte lieber auf mehrere Kapitel verteilen.</li>
    <li>Die Ansicht selbst ist geprüft: Navigations-Landmarken, „Zum Kapiteltext springen“, aktuelles Kapitel für Screenreader markiert, Schublade mit Tastatur bedienbar, Trefferzahl wird angesagt.</li>
  </ul>
