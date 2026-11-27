<?php
/**
 * Umgebung der Website (staging ↔ production) – Grundeinstellungen → Umgebung (DomainController::environment).
 * Erwartet $env (environment()). Formular steht außerhalb des Einstellungsformulars (form="environment").
 */
$envLabel = ['production' => __('Livebetrieb (production)'), 'staging' => __('Testumgebung (staging)'), 'development' => __('Entwicklung (development)')];
?>
    <h2><?= e(__('Umgebung')) ?></h2>
    <section class="set-group">
      <div class="set-list">
        <div class="set-row"><div class="set-row__main"><span class="set-row__label"><?= e(__('Aktuell')) ?></span></div>
          <div class="set-row__ctl"><span class="adm-badge<?= $env === 'production' ? '' : ' adm-badge--warn' ?>"><?= e($envLabel[$env] ?? $env) ?></span></div></div>
        <div class="f f--inline"><label for="env-select"><?= e(__('Umgebung')) ?></label>
          <select id="env-select" name="environment" form="environment">
            <?php foreach (['production', 'staging'] as $k): ?><option value="<?= e($k) ?>"<?= $env === $k ? ' selected' : '' ?>><?= e($envLabel[$k]) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <p class="set-group__note"><?= e(__('Livebetrieb: Suchmaschinen dürfen die Website aufnehmen (sofern nicht in den Grundeinstellungen gesperrt), E-Mails gehen an die echten Empfänger.')) ?></p>
      <p class="set-group__note"><?= e(__('Testumgebung: Suchmaschinen werden immer ausgesperrt (noindex). E-Mails gehen nie an echte Empfänger – sie werden an die Adresse aus mail_redirect umgeleitet (Betreff mit [STAGING]) oder nur protokolliert und nicht versendet. Ist staging_auth eingerichtet, fragt die Website nach einem Passwort.')) ?></p>
      <div class="set-actions"><button class="adm-btn adm-btn--primary" type="submit" form="environment" data-confirm="<?= e(__('Umgebung wirklich umstellen? Das ändert, ob Suchmaschinen die Website sehen und ob E-Mails versendet werden.')) ?>"><?= e(__('Umstellen')) ?></button></div>
    </section>

    <?php
    // Zugangsschutz der Testumgebung (Core\StagingAuth): Benutzername + Passwort je Website, wirkt nur außerhalb von production
    $saCfg = \Core\StagingAuth::fromConfig(); $saSet = \Core\StagingAuth::stored(); $saOn = $saCfg !== null || $saSet !== null;
    ?>
    <h2 id="zugangsschutz"><?= e(__('Zugangsschutz')) ?></h2>
    <section class="set-group">
      <div class="set-list">
        <div class="set-row"><div class="set-row__main"><span class="set-row__label"><?= e(__('Status')) ?></span>
            <span class="set-row__sub"><?= e(__('Solange die Website auf „Testumgebung“ steht, fragt der Browser vor jeder Seite nach Benutzername und Passwort – auch vor der Verwaltung. Im Livebetrieb wirkt der Schutz nicht.')) ?></span></div>
          <div class="set-row__ctl"><span class="adm-badge<?= $saOn && $env !== 'production' ? '' : ' adm-badge--muted' ?>"><?= e(!$saOn ? __('Aus') : ($env === 'production' ? __('Eingerichtet – wirkt erst in der Testumgebung') : __('Aktiv'))) ?></span></div></div>
        <?php if ($saCfg): ?>
        <p class="set-group__note"><?= e(__('Festgelegt in der Konfiguration (staging_auth, Benutzer „{user}“) – Änderungen dort.', ['user' => $saCfg['user']])) ?></p>
        <?php else: ?>
        <div class="f f--inline"><label for="sa-user"><?= e(__('Benutzername')) ?></label>
          <input id="sa-user" name="staging_user" form="staging-auth" value="<?= e((string) ($saSet['user'] ?? 'vorschau')) ?>" maxlength="60" autocomplete="off" required></div>
        <div class="f f--inline"><label for="sa-pass"><?= e($saSet ? __('Neues Passwort') : __('Passwort')) ?></label>
          <input id="sa-pass" name="staging_pass" form="staging-auth" type="password" minlength="8" maxlength="200" autocomplete="new-password" required></div>
        <div class="f f--inline"><label for="sa-pass2"><?= e(__('Passwort wiederholen')) ?></label>
          <input id="sa-pass2" name="staging_pass2" form="staging-auth" type="password" minlength="8" maxlength="200" autocomplete="new-password" required></div>
        <?php endif; ?>
      </div>
      <?php if ($saSet): ?><p class="set-group__note"><?= e(__('Zuletzt gesetzt am {date} von {by}. Das Passwort selbst wird nicht gespeichert – nur ein Prüfwert; vergessen = neu setzen.', ['date' => fmt()->datetime((string) $saSet['at']), 'by' => (string) (($saSet['by'] ?? '') ?: '–')])) ?></p><?php endif; ?>
      <p class="set-group__note"><?= e(__('Geteilte Vorschau-Links (z. B. „Entwurf teilen“) liegen ebenfalls hinter dem Schutz – Empfänger brauchen dann zusätzlich diesen Zugang. Schnittstellen mit eigenem Schlüssel (API, MCP, Kalender-Abos) bleiben erreichbar.')) ?></p>
      <?php if (!$saCfg): ?>
      <div class="set-actions">
        <button class="adm-btn adm-btn--primary" type="submit" form="staging-auth"><?= e($saSet ? __('Passwort ändern') : __('Zugangsschutz einschalten')) ?></button>
        <?php if ($saSet): ?><button class="adm-btn" type="submit" form="staging-auth-off" data-confirm="<?= e(__('Zugangsschutz ausschalten? Die Testumgebung ist dann ohne Passwort erreichbar.')) ?>"><?= e(__('Ausschalten')) ?></button><?php endif; ?>
      </div>
      <?php endif; ?>
    </section>
