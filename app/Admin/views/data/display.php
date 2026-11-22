<?php
/**
 * Geteilte Tabelle: welche Einträge erscheinen auf dieser Website? (Mitglieder: Quellen und Filter; Eigentümer: automatische Übernahme)
 * @var array $t  @var array $cfg  @var array $meta
 */
use Core\Data\Entries;
use Core\Data\Shared;

$base = '/admin/data/' . $t['handle'];
$key = $t['shared']['key'];
$isOwner = Shared::isOwner($t);
$canSave = can('data.publish', $t['handle']);
$members = array_values(array_diff($t['shared']['members'], [site()->key]));
$ownerName = Shared::siteInfo($t['shared']['owner'], $key)['name'];
$fields = array_values(array_filter($t['fields'], fn($f) => in_array($f['type'], ['text', 'select', 'multiselect', 'bool', 'number', 'date', 'datetime', 'relation', 'relations'], true)));
$ops = ['=' => __('ist gleich'), '!=' => __('ist nicht'), 'contains' => __('enthält'), '>=' => __('ab / größer gleich'), '<=' => __('bis / kleiner gleich')];
$rowsFor = fn(array $where) => array_pad(array_values($where), 3, ['', '=', '']);
$whereRows = function (string $name, array $where) use ($fields, $ops, $rowsFor): string {
    $h = '';
    foreach ($rowsFor($where) as $i => [$f, $op, $v]) {
        $h .= '<div class="sh-where">'
            . '<select name="' . $name . '[' . $i . '][field]" aria-label="' . e(__('Feld')) . '"><option value="">' . e(__('– kein Filter –')) . '</option>';
        foreach ($fields as $fd) $h .= '<option value="' . e($fd['name']) . '"' . ($fd['name'] === $f ? ' selected' : '') . '>' . e($fd['label']) . '</option>';
        $h .= '</select><select name="' . $name . '[' . $i . '][op]" aria-label="' . e(__('Bedingung')) . '">';
        foreach ($ops as $k => $l) $h .= '<option value="' . e($k) . '"' . ($k === $op ? ' selected' : '') . '>' . e($l) . '</option>';
        $h .= '</select><input name="' . $name . '[' . $i . '][value]" value="' . e((string) $v) . '" aria-label="' . e(__('Wert')) . '" placeholder="' . e(__('Wert, z. B. Kurzname der Auswahl')) . '"></div>';
    }
    return $h;
};
$count = Entries::count($t, ['status' => 'published', 'lang' => 'all', 'source' => 'site']);
$own = Entries::count($t, ['status' => 'published', 'lang' => 'all', 'source' => 'own']);
?>
<header class="adm-head dt-head">
  <div><p class="adm-eyebrow"><a href="<?= e(url($base)) ?>"><span aria-hidden="true"><?= icon($t['icon']) ?></span> <?= e($t['name']) ?></a> · <span class="dt-nav__shared"><?= e(__('geteilt')) ?></span></p>
    <h1><?= e(__('Anzeige auf dieser Website')) ?></h1>
    <p class="adm-muted"><?= e(__('Diese Website zeigt {n} veröffentlichte Einträge, davon {own} eigene. Blöcke können die Quelle einzeln überschreiben (Datenliste, Kalender, Nächste Termine → „Quelle“).', ['n' => $count, 'own' => $own])) ?></p></div>
</header>

<form method="post" action="<?= e(url($base . '/display')) ?>" class="adm-card sh-display">
  <?= csrf_field() ?>
  <p><strong><?= e(__('Eigene Einträge')) ?></strong> – <?= e(__('immer')) ?></p>
  <?php if ($isOwner): ?>
    <p class="adm-muted"><?= e(__('Diese Website ist Eigentümerin der Tabelle. Einträge der übrigen Websites erscheinen hier, wenn Sie einen Vorschlag übernehmen oder wenn sie zu den Regeln der automatischen Übernahme passen. Ausgeblendete und abgelehnte Einträge erscheinen nie.')) ?></p>
    <fieldset class="sh-fieldset"><legend><?= e(__('Automatische Übernahme')) ?></legend>
      <label class="f-check"><input type="checkbox" name="auto_enabled" value="1"<?= !empty($meta['auto']['enabled']) ? ' checked' : '' ?>> <span><?= e(__('Veröffentlichte Einträge der übrigen Websites automatisch zeigen, wenn sie zu den Regeln passen')) ?></span></label>
      <?php if ($members): ?>
      <p class="sh-sub"><?= e(__('Nur von diesen Websites (keine Auswahl = alle):')) ?></p>
      <?php foreach ($members as $m): ?><label class="f-check"><input type="checkbox" name="auto_sites[]" value="<?= e($m) ?>"<?= in_array($m, (array) $meta['auto']['sites'], true) ? ' checked' : '' ?>> <span><?= e(Shared::siteInfo($m, $key)['name']) ?></span></label><?php endforeach; ?>
      <?php endif; ?>
      <p class="sh-sub"><?= e(__('Nur Einträge, bei denen … (alle Bedingungen müssen passen)')) ?></p>
      <?= $whereRows('auto_where', (array) $meta['auto']['where']) ?>
    </fieldset>
  <?php else: ?>
    <fieldset class="sh-fieldset"><legend><?= e(__('Einträge von „{site}“', ['site' => $ownerName])) ?></legend>
      <label class="f-check"><input type="hidden" name="owner" value="0"><input type="checkbox" name="owner" value="1"<?= $cfg['owner'] ? ' checked' : '' ?>> <span><?= e(__('Auf dieser Website zeigen')) ?></span></label>
      <p class="adm-muted"><?= e(__('Einzelne Einträge blenden Sie unter „{label}“ aus.', ['label' => term('shared_from_owner')])) ?></p>
    </fieldset>
    <fieldset class="sh-fieldset"><legend><?= e(__('Einträge der übrigen Websites')) ?></legend>
      <?php if (!$t['shared']['members_see_members']): ?>
        <p class="adm-muted"><?= e(__('„{site}“ hat nicht freigegeben, dass die Websites Einträge untereinander zeigen.', ['site' => $ownerName])) ?></p>
        <input type="hidden" name="members" value="<?= e($cfg['members']) ?>">
      <?php else: ?>
        <?php foreach (['off' => __('Nicht zeigen'), 'all' => __('Von allen Websites'), 'selected' => __('Nur von ausgewählten Websites')] as $k => $l): ?>
        <label class="f-check"><input type="radio" name="members" value="<?= e($k) ?>"<?= $cfg['members'] === $k ? ' checked' : '' ?>> <span><?= e($l) ?></span></label>
        <?php endforeach; ?>
        <?php if ($members): ?><div class="sh-indent">
          <?php foreach ($members as $m): ?><label class="f-check"><input type="checkbox" name="sites[]" value="<?= e($m) ?>"<?= in_array($m, $cfg['sites'], true) ? ' checked' : '' ?>> <span><?= e(Shared::siteInfo($m, $key)['name']) ?></span></label><?php endforeach; ?>
        </div><?php endif; ?>
        <p class="sh-sub"><?= e(__('Nur Einträge, bei denen … (alle Bedingungen müssen passen)')) ?></p>
        <?= $whereRows('where', $cfg['where']) ?>
      <?php endif; ?>
    </fieldset>
    <fieldset class="sh-fieldset"><legend><?= e(__('Verlinkung fremder Einträge')) ?></legend>
      <label class="f-check"><input type="hidden" name="link_origin" value="0"><input type="checkbox" name="link_origin" value="1"<?= $cfg['link_origin'] ? ' checked' : '' ?>> <span><?= e(__('Direkt auf die Ursprungs-Website verlinken (statt Detailseite auf dieser Website)')) ?></span></label>
    </fieldset>
  <?php endif; ?>
    <fieldset class="sh-fieldset"><legend><?= e(__('Fremde Einträge nicht zeigen, wenn …')) ?></legend>
      <p class="adm-muted"><?= e(__('Jede Zeile für sich: Einträge anderer Websites, auf die eine Bedingung passt, erscheinen hier nicht – z. B. eine Kategorie des Verbands, die diese Website nicht braucht. Einzelne Einträge blenden Sie in der Liste aus.')) ?></p>
      <?= $whereRows('except', $cfg['except']) ?>
    </fieldset>
    <fieldset class="sh-fieldset"><legend><?= e(__('Suchmaschinen: Canonical fremder Einträge')) ?></legend>
      <?php foreach (['origin' => __('Ursprungs-Website (empfohlen)'), 'self' => __('Diese Website')] as $k => $l): ?>
      <label class="f-check"><input type="radio" name="canonical" value="<?= e($k) ?>"<?= $cfg['canonical'] === $k ? ' checked' : '' ?>> <span><?= e($l) ?></span></label>
      <?php endforeach; ?>
      <p class="adm-muted"><?= e(__('Detailseiten fremder Einträge gibt es auf jeder beteiligten Website unter deren eigener Adresse. Standard: Sie verweisen per Canonical auf die Ursprungs-Website (falls diese Detailseiten hat) und stehen nicht in der Sitemap – Suchmaschinen werten den gleichen Text nicht doppelt. „Diese Website“ nur wählen, wenn die Seiten hier für sich gefunden werden sollen; dann erscheinen sie auch in der Sitemap dieser Website.')) ?></p>
    </fieldset>
  <?php if ($canSave): ?><div class="adm-row"><button class="adm-btn adm-btn--primary" type="submit"><?= e(__('Speichern')) ?></button></div>
  <?php else: ?><p class="adm-muted"><?= e(__('Ändern darf, wer Einträge veröffentlichen darf.')) ?></p><?php endif; ?>
</form>

<?php // Netzwerk (Core\Data\Network): Schalter der Eigentümer-Website, Zusatzfelder je Website
$flags = \Core\Data\Network::flags($key);
$siteFields = \Core\Data\Network::siteFields($key);
$types = array_combine(\Core\Data\Network::SITE_TYPES, array_map(fn($ty) => __(\Core\Data\Tables::TYPES[$ty][0]), \Core\Data\Network::SITE_TYPES)); ?>
<section class="adm-card sh-display net-admin" aria-labelledby="net-h">
  <h2 class="sh-h" id="net-h"><?= e(__('Netzwerk')) ?></h2>
  <?php if (Shared::canAdmin($key)): ?>
  <form method="post" action="<?= e(url($base . '/netzwerk')) ?>">
    <?= csrf_field() ?>
    <label class="f-check"><input type="hidden" name="levels" value="0"><input type="checkbox" name="levels" value="1"<?= $flags['levels'] ? ' checked' : '' ?>> <span><?= e(__('Weitergabe je Eintrag: nur diese Website, ausgewählte Websites oder ganzes Netzwerk')) ?></span></label>
    <p class="adm-muted"><?= e(__('Andere Websites sehen fremde Einträge dann überall nur in der öffentlichen Fassung – auch in Verwaltung, Apps und Visitenkarten. Vorhandene Einträge gelten als „Ganzes Netzwerk“, neue Kontakte als „Nur diese Website“.')) ?></p>
    <label class="f-check"><input type="hidden" name="site_fields" value="0"><input type="checkbox" name="site_fields" value="1"<?= $flags['site_fields'] ? ' checked' : '' ?>> <span><?= e(__('Zusatzfelder je Website (nur auf der jeweiligen Website sichtbar)')) ?></span></label>
    <div class="adm-row"><button class="adm-btn" type="submit"><?= e(__('Netzwerk speichern')) ?></button></div>
  </form>
  <?php else: ?>
  <p class="adm-muted"><?= e(__('Weitergabe je Eintrag: {a} · Zusatzfelder je Website: {b} – stellt „{site}“ ein.', ['a' => $flags['levels'] ? __('an') : __('aus'), 'b' => $flags['site_fields'] ? __('an') : __('aus'), 'site' => $ownerName])) ?></p>
  <?php endif; ?>
  <?php if ($flags['site_fields'] && can('data.schema')): ?>
  <form method="post" action="<?= e(url($base . '/zusatzfelder')) ?>" class="net-fields">
    <?= csrf_field() ?>
    <h3 class="sh-sub"><?= e(__('Zusatzfelder dieser Website')) ?></h3>
    <p class="adm-muted"><?= e(__('Eigene Angaben zu jedem Eintrag, auch zu fremden – z. B. „Funktionen“ (Typ Funktionen) für Ämter auf dieser Website. Die anderen Websites sehen sie nicht. Leere Zeilen werden entfernt.')) ?></p>
    <?php foreach (array_pad($siteFields, count($siteFields) + 2, []) as $i => $sf): ?>
    <div class="sh-where">
      <input name="sf[<?= $i ?>][label]" value="<?= e((string) ($sf['label'] ?? '')) ?>" aria-label="<?= e(__('Beschriftung')) ?>" placeholder="<?= e(__('Beschriftung')) ?>">
      <input type="hidden" name="sf[<?= $i ?>][name]" value="<?= e((string) ($sf['name'] ?? '')) ?>">
      <select name="sf[<?= $i ?>][type]" aria-label="<?= e(__('Feldtyp')) ?>"><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"<?= ($sf['type'] ?? 'text') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <input name="sf[<?= $i ?>][options]" value="<?= e(implode(', ', (array) ($sf['options'] ?? []))) ?>" aria-label="<?= e(__('Optionen (Auswahl)')) ?>" placeholder="<?= e(__('Optionen, durch Komma getrennt')) ?>">
    </div>
    <?php endforeach; ?>
    <div class="adm-row"><button class="adm-btn" type="submit"><?= e(__('Zusatzfelder speichern')) ?></button></div>
  </form>
  <?php endif; ?>
</section>
