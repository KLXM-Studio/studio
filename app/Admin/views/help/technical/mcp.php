<?php /** Entwicklerhandbuch · MCP-Server (Tools, Resources, Prompts live aus McpController::catalog()) · @var array $mcp */ ?>
  <p class="lead">Model Context Protocol über <b>Streamable HTTP</b> unter <code><?= e($mcpUrl) ?></code> – zustandslos, JSON-Antworten, gleiche Tokens und Rechte wie die REST-API. Protokollversionen: <?= e(implode(', ', $mcp['versions'])) ?>.</p>
  <p>Für die Redaktion: <a href="<?= e(url('/admin/hilfe/agent')) ?>">Mit KI-Agenten arbeiten (MCP)</a> (<code>Core\Api\AgentGuide</code>) – Werkzeuge nach Aufgaben gruppiert (<code>AgentGuide::META</code>: Gruppe und Recht je Werkzeug, unbekannte unter „Weitere“), gefiltert nach Funktionen der Website und den Rechten der Person (<code>?alle=1</code> zeigt alles, Fehlendes ausgegraut), Beispiel-Aufträge mit echten Tabellen- und Seitennamen, Verbindungs-Schnipsel für verbreitete Programme. Erweiterungen ordnen eigene Werkzeuge mit <code>AgentGuide::meta($tool, $gruppe, $recht, $beispiele)</code> ein. Dieselbe Übersicht liefert der MCP-Server als Resource <code>cms://capabilities</code> (Werkzeuge dieses Tokens) und Prompt <code>was_kannst_du</code>.</p>
  <h3>Verbinden</h3>
  <h4>Claude Code</h4>
  <pre><code>claude mcp add --transport http klxm-studio <?= e($mcpUrl) ?> \
  --header "Authorization: Bearer cms_…"</code></pre>
  <h4>Claude Desktop (über mcp-remote)</h4>
  <pre><code>{
  "mcpServers": {
    "klxm-studio": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "<?= e($mcpUrl) ?>", "--header", "Authorization:${CMS_TOKEN}"],
      "env": { "CMS_TOKEN": "Bearer cms_…" }
    }
  }
}</code></pre>
  <h4>Testen mit dem MCP Inspector</h4>
  <pre><code>npx @modelcontextprotocol/inspector --cli <?= e($mcpUrl) ?> --transport http \
  --header "Authorization: Bearer cms_…" --method tools/list</code></pre>
  <div class="doc-note doc-note--info"><strong>Verhalten</strong><p>Tokens mit <span class="tag tag--read">read</span> sehen nur Lese-Tools. Seitenänderungen landen als Entwurf (Revision mit Token-Name); zentrale Einstellungen wirken sofort. Anfragen (Eingangs-Tabellen) sind nur als Metadaten sichtbar – Inhalte bleiben Ende-zu-Ende verschlüsselt. Fremde <code>Origin</code>-Header werden abgelehnt (DNS-Rebinding-Schutz). Tokens im Modus „Zur Freigabe“ erhalten bei Schreib-Tools ein normales Ergebnis mit <code>structuredContent</code> <code>{status: "pending_review", id, url, …}</code>; die Server-Anweisungen (<code>initialize → instructions</code>) weisen darauf hin.</p></div>
  <ul>
    <li><b>Protokoll:</b> nur <code>POST /mcp</code> (JSON-RPC 2.0, auch Batches); Notifications → HTTP 202 ohne Inhalt; <code>GET</code>/<code>DELETE</code> → 405. Unbekannte <code>protocolVersion</code> → Rückfall auf 2025-06-18. <code>initialize → clientInfo</code> wird am Token gespeichert (<code>mcp_client</code>, für die Herkunft in „Eingereicht“). Serverkennung: <code>klxm-studio</code> (rein informativ), Bearer-Realms <code>klxm-studio-mcp</code> bzw. <code>klxm-studio-api</code>.</li>
    <li><b>Werkzeuge je Website:</b> Die Liste unten entsteht live für diese Website. Bedingte Tools: <code>get_design</code>/<code>set_design</code> (Funktion <code>design</code> + Kit), <code>get_opening_hours</code>/<code>set_opening_hours</code>, <code>set_notice</code> (wenn das Kit Öffnungszeiten bzw. einen Hinweis anbietet), <code>list_occurrences</code> (Kalender), <code>list_suggestions</code>/<code>set_pick</code> (geteilte Tabellen), <code>search_site</code> (Suche), <code>get_change_status</code> (Freigabe), <code>search_knowledge</code>/<code>report_issue</code>/<code>support_list</code>/<code>support_get</code>/<code>support_reply</code>/<code>support_status</code> (Support – Meldungen lesen und beantworten in der Sicht des Token-Benutzers), <code>site_health</code> (Systemprüfung, nur Tokens ohne Benutzer oder mit Administrationsrolle), <code>task_list</code>/<code>task_create</code>/<code>task_done</code> (immer – Aufgaben der Redaktion im Namen des Token-Benutzers, nicht über die Prüf-Ebene, siehe <a href="#aufgaben">Aufgaben</a>), <code>translate_page</code>/<code>translate_entry</code> (mehrere Sprachen – dann haben alle Tools außer <code>site_overview</code> und <code>geocode_address</code> einen Parameter <code>lang</code>). Destruktive Tools (Löschen, Blöcke ersetzen, Entwurf verwerfen) tragen <code>destructiveHint</code>; <code>delete_page</code> verlangt <code>confirm=true</code>.</li>
    <li><b>Bilder hochladen:</b> <code>upload_media</code> nimmt die Datei als <code>base64</code> oder als öffentliche Adresse <code>url</code> (Bilder und PDF; der Server lädt sie selbst über <code>Core\Http\Fetch</code>, keine internen Adressen, höchstens die Upload-Grenze). Alt-Text (Pflicht bei Bildern), Titel, Fotonachweis (<code>credit</code>), Tags und Sammlung werden mit übernommen; nachträglich ändert <code>update_media</code> diese Angaben. <b>Upload-Link:</b> Chat-Programme reichen angehängte Dateien nicht an MCP weiter – <code>media_upload_link</code> erzeugt einen Link (30 Minuten, eine Datei, <code>/admin/hochladen/{token}</code>, ohne Anmeldung), die Person lädt dort hoch, <code>media_upload_status</code> liefert danach die Medien-ID (<code>Core\UploadLink</code>).</li>
  </ul>
  <h3>Tools (<?= count($mcp['tools']) ?>)</h3>
  <div class="doc-scroll"><table class="doc-table">
    <tr><th>Tool</th><th>Beschreibung</th><th>Parameter</th></tr>
    <?php foreach ($mcp['tools'] as $t): ?>
    <tr><td><code><?= e($t['name']) ?></code><br><?= $t['destructive'] ? '<span class="tag tag--danger">ändert/löscht</span>' : ($t['write'] ? '<span class="tag tag--write">write</span>' : '<span class="tag tag--read">read</span>') ?></td>
      <td><?= e($t['description']) ?></td>
      <td><?php foreach ($t['params'] as $p): ?><code><?= e($p) ?><?= in_array($p, $t['required'], true) ? '*' : '' ?></code> <?php endforeach; ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <h3>Resources</h3>
  <p><?php foreach ($mcp['resources'] as $r): ?><code><?= e($r['uri']) ?></code> <?php endforeach; ?> · Vorlage <code>cms://pages/{slug}</code></p>
  <h3>Prompts</h3>
  <table class="doc-table">
    <tr><th>Prompt</th><th>Beschreibung</th><th>Argumente</th></tr>
    <?php foreach ($mcp['prompts'] as $p): ?><tr><td><code><?= e($p['name']) ?></code></td><td><?= e($p['description']) ?></td><td><?= e(implode(', ', $p['arguments'])) ?></td></tr><?php endforeach; ?>
  </table>
