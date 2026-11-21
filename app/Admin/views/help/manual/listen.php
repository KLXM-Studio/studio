<?php /** Handbuch · Kapitel „Arbeiten in Listen“ (Zeilenmenü und Mehrfachauswahl, Core\RowMenu) · @var callable $anchor */ ?>
  <p class="lead">Ob Datenliste, Kalender, Adressbuch, Seitenbaum, Medien oder Entwürfe: Jede Zeile, Karte oder Seite hat dasselbe Menü mit denselben Aktionen in derselben Reihenfolge. Wer es an einer Stelle kennt, findet sich überall zurecht.</p>
  <table class="doc-table">
    <tr><th>So öffnen Sie das Menü</th><th>Wie</th></tr>
    <tr><td><b>Drei-Punkte-Knopf</b> <span aria-hidden="true">⋯</span></td><td>Am Ende jeder Zeile bzw. oben an der Karte. Im Adressbuch erscheint er, sobald Sie über einen Namen fahren oder ihn mit der Tastatur erreichen.</td></tr>
    <tr><td><b>Rechtsklick</b></td><td>Irgendwo auf die Zeile, die Karte, den Termin im Kalender oder die Datei – das Menü erscheint direkt am Mauszeiger. Mit gedrückter <kbd>Umschalt</kbd>-Taste oder in Eingabefeldern bleibt das gewohnte Menü des Browsers.</td></tr>
    <tr><td><b>Tastatur</b></td><td><kbd>Umschalt</kbd> + <kbd>F10</kbd> oder die Menütaste öffnet das Menü der Zeile, in der Sie gerade stehen. <kbd>↑</kbd> <kbd>↓</kbd> wählen, <kbd>Pos1</kbd>/<kbd>Ende</kbd> springen an den Anfang bzw. ans Ende, ein Buchstabe springt zum passenden Punkt, <kbd>↵</kbd> führt aus, <kbd>Esc</kbd> oder <kbd>Tab</kbd> schließt.</td></tr>
    <tr><td><b>Telefon</b></td><td>Das Menü kommt als Blatt von unten, mit großen Zeilen und dem Namen des Eintrags oben. Tippen daneben schließt es.</td></tr>
  </table>
  <h3>Die Aktionen – immer in dieser Reihenfolge</h3>
  <table class="doc-table">
    <tr><th>Aktion</th><th>Wirkung</th></tr>
    <tr><td><?= icon('pencil-simple') ?> <b>Bearbeiten</b> / Öffnen</td><td>Eintrag, Seite oder Datei bearbeiten. Im Seitenbaum zusätzlich <b>Seiteneinstellungen</b>.</td></tr>
    <tr><td><?= icon('arrow-square-out') ?> <b>Ansehen</b> · <?= icon('eye') ?> <b>Vorschau</b></td><td>Auf der Website in einem neuen Tab bzw. als Vorschau (Seiten, Medien: Quick Look, Entwürfe: Unterschiede).</td></tr>
    <tr><td><?= icon('file-plus') ?> <b>Neue Unterseite</b></td><td>Nur im Seitenbaum.</td></tr>
    <tr><td><?= icon('copy') ?> <b>Duplizieren</b></td><td>Legt eine Kopie als Entwurf an (Seiten: „Als Vorlage speichern“ für die Administration).</td></tr>
    <tr><td><?= icon('arrows-down-up') ?> <b>Verschieben / Zuordnen</b></td><td>Seiten nach oben/unten, ein- und ausrücken (auch <kbd>Alt</kbd> + Pfeiltasten); Kontakte zu einer Liste; Dateien in eine Sammlung oder einen geteilten Pool.</td></tr>
    <tr><td><?= icon('list') ?> <b>Im Menü zeigen</b></td><td>Seiten im Hauptmenü ein- und ausblenden, Indexierung erlauben oder sperren.</td></tr>
    <tr><td><?= icon('globe') ?> <b>Veröffentlichen</b> · <?= icon('eye-slash') ?> <b>Offline nehmen</b></td><td>Wie der Status-Knopf der Zeile. Offline nehmen fragt vorher nach.</td></tr>
    <tr><td><?= icon('clock-counter-clockwise') ?> <b>Versionen</b></td><td>Frühere Stände ansehen, vergleichen und wiederherstellen.</td></tr>
    <tr><td><?= icon('link') ?> <b>Link kopieren</b></td><td>Adresse in die Zwischenablage – die Adresse auf der Website, wenn es eine gibt, sonst die in der Verwaltung.</td></tr>
    <tr><td><?= icon('export') ?> <b>Exportieren</b></td><td>Kontakte als vCard (alle Angaben oder nur die öffentlichen), Termine als ICS-Datei für Kalender-Apps, mehrere Dateien als ZIP.</td></tr>
    <tr><td><?= icon('list-checks') ?> <b>Aufgabe anlegen</b></td><td>Eine Aufgabe mit Verweis auf diesen Eintrag bzw. diese Seite – sie erscheint unter „Was ist zu tun?“.</td></tr>
    <tr><td><?= icon('puzzle-piece') ?> Aktionen von Erweiterungen</td><td>Erweiterungen hängen ihre eigenen Punkte vor „Löschen“ an.</td></tr>
    <tr><td><?= icon('trash') ?> <b>Löschen</b></td><td>Immer zuletzt, rot und mit Rückfrage.</td></tr>
  </table>
  <p>Sie sehen nur Aktionen, die Ihre Rolle ausführen darf – wer zum Beispiel nicht veröffentlichen darf, findet „Veröffentlichen“ nicht im Menü. Einträge aus externen Quellen oder von anderen Websites lassen sich nur öffnen.</p>
  <h3>Mehrere auf einmal</h3>
  <p>In Datenlisten wählen Sie Einträge mit dem Häkchen am Zeilenanfang (das Häkchen im Kopf wählt alle). Im Adressbuch schaltet <b>Auswählen</b> die Häkchen ein. Sobald etwas gewählt ist, zeigt eine Leiste die Anzahl und die Aktionen für alle gewählten – mit denselben Symbolen wie im Menü: Online stellen, Offline nehmen, Exportieren, zu einer Liste hinzufügen, Löschen. Ein Rechtsklick auf eine der gewählten Zeilen bietet genau diese Aktionen als Menü an.</p>
  <div class="doc-note doc-note--info"><strong>Ohne JavaScript</strong><p>Das Menü funktioniert auch ohne JavaScript: Der Drei-Punkte-Knopf öffnet es, jede Aktion ist ein Link oder ein Formular. Im Seitenbaum führt er dann zu den Seiteneinstellungen, wo Sie dieselben Aktionen finden.</p></div>
