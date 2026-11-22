<?php
/**
 * Netzwerk (Core\Data\Network) im Formular eines Eintrags einer geteilten Tabelle:
 *  - $part = 'level': Weitergabe-Stufe (nur diese Website | ausgewählte Websites | ganzes Netzwerk) – nur mit Schalter „Weitergabe je
 *    Eintrag“; ohne Recht „Einträge veröffentlichen“ nur Anzeige
 *  - $part = 'extra': Zusatzfelder dieser Website (nur hier sichtbar) – Formularfelder x[…]; $form = eigenes Formular (fremde Einträge)
 * @var array $t  @var ?array $e  @var array $values  @var string $part  @var ?string $action
 */
use Core\Data\Entries;
use Core\Data\Network;
use Core\Data\Shared;

if (!Shared::isShared($t)) return;
$key = (string) $t['shared']['key'];
if ($part === 'level' && Network::levelsOn($t)):
    $level = $e ? Network::level(array_replace($e, array_intersect_key($values, [Network::COL => 1]))) : (string) ($values[Network::COL] ?? Network::defaultLevel($t));
    $chosen = $e ? Network::shareSites($e) : [];
    if (isset($values[Network::COL_SITES])) $chosen = array_map('strval', (array) $values[Network::COL_SITES]);
    $others = array_values(array_diff(Shared::participants(Shared::meta($key) ?? ['owner' => '', 'members' => []]), [site()->key]));
    $may = can('data.publish', $t['handle']); ?>
<fieldset class="f sh-fieldset net-level"<?= $may ? '' : ' disabled' ?>>
  <legend><?= e(__('Weitergabe im Netzwerk')) ?></legend>
  <?php foreach (Network::LEVELS as $lv): ?>
  <label class="f-check"><input type="radio" name="share_level" value="<?= e($lv) ?>"<?= $level === $lv ? ' checked' : '' ?>> <span><?= e(Network::levelLabel($lv)) ?></span></label>
  <?php endforeach; ?>
  <?php if ($others): ?>
  <div class="net-level__sites" role="group" aria-label="<?= e(__('Ausgewählte Websites')) ?>">
    <?php foreach ($others as $s): ?>
    <label class="f-check"><input type="checkbox" name="share_sites[]" value="<?= e($s) ?>"<?= in_array($s, $chosen, true) ? ' checked' : '' ?>> <span><?= e(Shared::siteInfo($s, $key)['name']) ?></span></label>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <p class="f-help"><?= e(__('Nur freigegebene Angaben gehen weiter – die Freigabe je Feld gilt immer. „Ausgewählte Websites“: die angekreuzten Websites zeigen den Eintrag von selbst.')) ?></p>
  <?php if (!$may): ?><p class="f-help"><?= e(__('Die Weitergabe ändert, wer veröffentlichen darf.')) ?></p><?php endif; ?>
</fieldset>
<?php elseif ($part === 'extra' && !empty($t['site_fields'])):
    $schema = Entries::schema(['fields' => [...$t['site_fields'], ...array_values(array_filter($t['fields'], fn($f) => $f['name'] === 'listen'))], 'settings' => []] + $t);
    $schema = array_values(array_filter($schema, fn($f) => in_array($f['name'], array_column($t['site_fields'], 'name'), true)));
    $vals = (array) ($e['_site_extra'] ?? []);
    if (!empty($action)): ?>
<section class="adm-card net-extra">
  <h2 class="sh-h"><?= e(__('Nur auf dieser Website')) ?></h2>
  <p class="f-help"><?= e(__('Eigene Zusatzangaben dieser Website – die anderen Websites sehen sie nicht.')) ?></p>
  <form method="post" action="<?= e(url($action)) ?>">
    <?= csrf_field() ?>
    <div class="adm-fields"><?= \Core\Fields::renderForm($schema, $vals, [], 'x') ?></div>
    <button class="adm-btn adm-btn--primary"><?= e(__('Zusatzangaben speichern')) ?></button>
  </form>
</section>
    <?php else: ?>
<div class="net-extra">
  <h2 class="sh-h"><?= e(__('Nur auf dieser Website')) ?></h2>
  <p class="f-help"><?= e(__('Eigene Zusatzangaben dieser Website – die anderen Websites sehen sie nicht.')) ?></p>
  <div class="adm-fields"><?= \Core\Fields::renderForm($schema, $vals, [], 'x') ?></div>
</div>
    <?php endif;
endif;
