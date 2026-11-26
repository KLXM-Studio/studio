<?php
/**
 * Handbuch & Hilfe → „Mit KI-Agenten arbeiten (MCP)“: was ein Agent über MCP auf dieser Website für die angemeldete Person tun kann –
 * nach Aufgaben gruppiert, mit Beispiel-Aufträgen (echte Namen), Art (liest/ändert/löscht), technischen Namen aufklappbar; oben
 * die Verbindung für verbreitete Programme. Daten: Core\Api\AgentGuide. Suche: resources/js/admin.js ([data-agent-filter]).
 * @var list<array> $groups  @var bool $all  @var bool $mcpOn  @var list<array> $tokens  @var bool $canTokens  @var string $endpoint
 */
$helpTab = 'agent';
include ROOT . '/app/Admin/views/support/_helptabs.php';
$kind = ['read' => [__('liest'), 'eye'], 'change' => [__('ändert'), 'pencil-simple'], 'delete' => [__('löscht'), 'trash']];
$tok = '<TOKEN>';
$cfgJson = fn(array $x) => json_encode($x, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$clients = [
    ['Claude Code', __('Im Terminal einmal ausführen:'), 'claude mcp add --transport http studio ' . $endpoint . ' --header "Authorization: Bearer ' . $tok . '"'],
    ['Claude Desktop', __('In den Einstellungen unter „Entwickler“ die Konfiguration öffnen und ergänzen (verbindet über das Hilfsprogramm mcp-remote, Node.js nötig):'),
        $cfgJson(['mcpServers' => ['studio' => ['command' => 'npx', 'args' => ['-y', 'mcp-remote', $endpoint, '--header', 'Authorization: Bearer ' . $tok]]]])],
    ['Gemini CLI', __('Im Terminal einmal ausführen (für alle Projekte mit -s user):'), 'gemini mcp add --transport http --header "Authorization: Bearer ' . $tok . '" studio ' . $endpoint],
    ['Cursor, Windsurf, LM Studio', __('In der Datei mcp.json des Programms ergänzen:'), $cfgJson(['mcpServers' => ['studio' => ['url' => $endpoint, 'headers' => ['Authorization' => 'Bearer ' . $tok]]]])],
    ['VS Code (Copilot)', __('In .vscode/mcp.json bzw. der Benutzer-Konfiguration ergänzen:'), $cfgJson(['servers' => ['studio' => ['type' => 'http', 'url' => $endpoint, 'headers' => ['Authorization' => 'Bearer ' . $tok]]]])],
    ['Open WebUI (z. B. mit Ollama)', __('Ollama selbst spricht kein MCP – die Werkzeuge hängt eine Oberfläche wie Open WebUI an: in den Admin-Einstellungen einen Werkzeug-Server hinzufügen, Typ „MCP (Streamable HTTP)“, Adresse unten, Anmeldung „Bearer“ mit dem Token als Schlüssel. Lokale Modelle brauchen Werkzeug-Unterstützung; kleine Modelle vertun sich bei vielen Werkzeugen eher – lieber zuerst nur lesen lassen.'), $endpoint],
];
?>
<div class="doc doc-agent">
  <div class="adm-head">
    <div>
      <h1><?= e(__('Mit KI-Agenten arbeiten (MCP)')) ?></h1>
      <p class="adm-muted"><?= e(__('Ein KI-Agent – etwa Claude, ChatGPT, Gemini oder ein lokales Modell – kann über die MCP-Schnittstelle in Ihrem Auftrag auf dieser Website arbeiten. Sie schreiben, was geschehen soll; der Agent nutzt dafür die Werkzeuge unten. Was er darf, richtet sich nach den Funktionen dieser Website und nach den Rechten des Zugangs.')) ?></p>
    </div>
  </div>
  <?php if (!$mcpOn): ?>
  <div class="adm-flash adm-flash--info" role="status"><p><?= e(__('Die MCP-Schnittstelle ist auf dieser Website ausgeschaltet. Die Administration schaltet sie unter „Funktionen & Erweiterungen“ ein.')) ?></p></div>
  <?php endif; ?>

  <section class="doc-ch" id="verbinden" aria-labelledby="ag-connect">
    <h2 id="ag-connect"><?= e(__('So verbinden Sie einen Agenten')) ?></h2>
    <ol class="ag-steps">
      <li><?= e(__('Zugang (Token) anlegen – wahlweise „nur lesen“ oder „zur Freigabe“: Dann ändert der Agent nichts direkt, sondern reicht Vorschläge ein, die geprüft und übernommen werden. Zugänge setzen derzeit ein Konto der Administration voraus; mit der geplanten Anmeldung per Konto (OAuth) arbeitet ein Agent mit Ihren eigenen Rechten.')) ?>
        <?php if ($canTokens): ?><a href="<?= e(url('/admin/api-tokens')) ?>"><?= e(__('Zugänge verwalten')) ?></a><?php else: ?><span class="adm-muted"><?= e(__('Den Zugang legt die Administration für Sie an.')) ?></span><?php endif; ?></li>
      <li><?= e(__('Im Programm die Adresse und den Token eintragen (Beispiele unten, „<TOKEN>“ ersetzen).')) ?></li>
      <li><?= e(__('Den Agenten fragen: „Was kannst du auf dieser Website?“ – er antwortet mit dieser Übersicht.')) ?></li>
    </ol>
    <p><b><?= e(__('Adresse:')) ?></b> <code id="ag-endpoint"><?= e($endpoint) ?></code> <button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-copy="#ag-endpoint"><?= e(__('Kopieren')) ?></button></p>
    <?php if ($tokens): ?>
    <p class="adm-muted"><?= e(__('Ihre Zugänge:')) ?> <?= e(implode(' · ', array_map(fn($x) => $x['name'] . ' (' . ($x['scope'] === 'write' ? ($x['mode'] === 'review' ? __('zur Freigabe') : __('ändert direkt')) : __('nur lesen')) . ')', $tokens))) ?></p>
    <?php endif; ?>
    <?php foreach ($clients as $ci => [$name, $how, $code]): ?>
    <details class="ag-client">
      <summary><?= e($name) ?></summary>
      <p><?= e($how) ?></p>
      <pre class="ag-code" id="ag-c<?= $ci ?>"><?= e($code) ?></pre>
      <button type="button" class="adm-btn adm-btn--small adm-btn--ghost" data-copy="#ag-c<?= $ci ?>"><?= e(__('Kopieren')) ?></button>
    </details>
    <?php endforeach; ?>
    <details class="ag-client">
      <summary><?= e(__('ChatGPT, claude.ai und andere Web-Apps')) ?></summary>
      <p><?= e(__('Web-Apps verbinden sich über eine Anmeldung mit Ihrem Konto (OAuth) statt über einen Token. Diese Anmeldung ist in Vorbereitung; bis dahin nutzen Sie eines der Programme oben.')) ?></p>
    </details>
  </section>

  <section class="doc-ch" id="aufgaben" aria-labelledby="ag-tasks">
    <h2 id="ag-tasks"><?= e(__('Was ein Agent hier für Sie tun kann')) ?></h2>
    <p class="lead"><?= e($all ? __('Alle Werkzeuge dieser Website – ausgegraut, wofür Ihnen ein Recht fehlt.') : __('Nur, was Ihre Rechte erlauben. Beispiele mit „Kopieren“ übernehmen und im Agenten anpassen.')) ?>
      <a href="<?= e(url('/admin/hilfe/agent') . ($all ? '' : '?alle=1')) ?>"><?= e($all ? __('Nur Erlaubtes zeigen') : __('Alles zeigen')) ?></a></p>
    <p><label class="ag-filter"><span class="adm-sr"><?= e(__('Aufgaben durchsuchen')) ?></span><input type="search" data-agent-filter placeholder="<?= e(__('Suchen, z. B. Termin, Bild, übersetzen …')) ?>"></label></p>
    <div class="ag-groups">
      <?php foreach ($groups as $gi => $g): ?>
      <article class="ag-group" data-agent-group id="ag-<?= e($g['key']) ?>">
        <h3><?= icon($g['icon']) ?> <?= e($g['title']) ?></h3>
        <p class="adm-muted"><?= e($g['intro']) ?></p>
        <?php if ($g['examples']): ?>
        <ul class="ag-ex" role="list">
          <?php foreach ($g['examples'] as $ei => $x): ?>
          <li><span id="ag-x<?= $gi ?>-<?= $ei ?>"><?= e($x) ?></span> <button type="button" class="cms-iconbtn" data-copy="#ag-x<?= $gi ?>-<?= $ei ?>" aria-label="<?= e(__('Beispiel kopieren')) ?>" title="<?= e(__('Kopieren')) ?>"><?= icon('copy') ?></button></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <details class="ag-tools">
          <summary><?= e(__('{n} Werkzeuge', ['n' => count($g['tools'])])) ?></summary>
          <ul role="list">
            <?php foreach ($g['tools'] as $t): [$kl, $ki] = $kind[$t['kind']]; ?>
            <li class="<?= $t['allowed'] ? '' : 'is-denied' ?>"><span class="ag-kind ag-kind--<?= e($t['kind']) ?>"><?= icon($ki) ?> <?= e($kl) ?></span>
              <b><?= e($t['title']) ?></b> <code><?= e($t['name']) ?></code>
              <?php if (!$t['allowed']): ?><span class="adm-muted"><?= e(__('braucht das Recht „{perm}“', ['perm' => $t['perm_label']])) ?></span><?php endif; ?>
              <br><span class="adm-muted ag-desc"><?= e($t['description']) ?></span></li>
            <?php endforeach; ?>
          </ul>
        </details>
      </article>
      <?php endforeach; ?>
    </div>
    <p class="ag-none adm-muted" data-agent-none hidden><?= e(__('Nichts gefunden.')) ?></p>
  </section>

  <div class="doc-note doc-note--warn"><strong><?= e(__('Die Verantwortung bleibt bei Ihnen')) ?></strong><p><?= e(__('Seitenänderungen landen als Entwurf; jede Änderung steht unter „Versionen“ mit dem Namen des Zugangs. Lassen Sie keine Fakten, Preise oder rechtlichen Aussagen erfinden und lesen Sie Texte vor dem Veröffentlichen.')) ?></p></div>
</div>
