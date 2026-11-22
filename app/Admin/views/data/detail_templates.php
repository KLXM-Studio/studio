<?php
/**
 * Detailseiten-Vorlagen einer Tabelle (Core\Data\DetailTemplates): Liste mit Standard und Nutzung, neue Vorlage (leer, Kopie,
 * mitgeliefert), Zuordnung je Feldwert bzw. Eintrag (im Wissensbereich vererbt an Unterkapitel).
 * @var array $t  @var array $list  @var array $rules  @var array $usage [seiten-id => anzahl]
 */
use Core\Data\DetailTemplates;
use Core\Data\Entries;
use Core\Data\Knowledge;
use Core\Data\Tables;

$base = '/admin/data/' . $t['handle'] . '/detailvorlagen';
$def = DetailTemplates::defaultId($t);
$kn = Knowledge::is($t);
$names = array_column($list, 'title', 'id');
// Wofür eine Regel gelten kann: Werte von Auswahlfeldern, Einträge (Wissen: Kapitel mit Nummer, gelten auch für Unterkapitel)
$what = [];
foreach ($t['fields'] as $f) {
    if (!in_array($f['type'] ?? '', ['select', 'multiselect'], true)) continue;
    foreach ((array) ($f['options'] ?? []) as $v => $l) $what[(string) ($f['label'] ?? $f['name'])]['f:' . $f['name'] . '=' . $v] = (string) $l;
}
$ents = [];
if ($kn) {
    $tree = Knowledge::tree($t);
    foreach ($tree['order'] as $nid) $ents['e:' . $nid] = trim(($tree['nodes'][$nid]['number'] ?? '') . ' ' . ($tree['nodes'][$nid]['title'] ?? ''));
} else {
    foreach (Entries::query($t, ['status' => 'all', 'limit' => 300, 'source' => 'site']) as $e) $ents['e:' . (int) $e['id']] = Entries::title($t, $e);
}
if ($ents) $what[$kn ? __('Kapitel (mit Unterkapiteln)') : __('Eintrag')] = $ents;
$ruleKey = fn(array $r) => isset($r['entry']) ? 'e:' . (int) $r['entry'] : 'f:' . $r['field'] . '=' . $r['value'];
$rows = [...$rules, ['page' => 0], ['page' => 0]];
?>
<header class="adm-head dt-head">
  <div><h1><span aria-hidden="true" class="dt-h1icon"><?= icon($t['icon']) ?></span> <?= e($t['name']) ?> <span class="dt-h1sub">· <?= e(__('Detailseiten-Vorlagen')) ?></span></h1>
    <p class="adm-muted"><?= e(__('Eine Tabelle kann mehrere Detailseiten-Vorlagen haben. Welche gilt, bestimmen Sie unten je Eintrag oder Feldwert – alle anderen Einträge nutzen die Standard-Vorlage.')) ?></p></div>
</header>

<section class="adm-card" aria-labelledby="dtv-list">
  <h2 id="dtv-list"><?= e(__('Vorlagen')) ?></h2>
  <table class="adm-table">
    <thead><tr><th scope="col"><?= e(__('Name')) ?></th><th scope="col"><?= e(__('Einträge')) ?></th><th scope="col"><?= e(__('Aktionen')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($list as $p): $pid = (int) $p['id']; ?>
      <tr>
        <td>
          <form method="post" action="<?= e(url($base)) ?>" style="display:flex;gap:.4rem;align-items:center"><?= csrf_field() ?>
            <input type="hidden" name="do" value="rename"><input type="hidden" name="id" value="<?= $pid ?>">
            <input name="name" value="<?= e((string) $p['title']) ?>" maxlength="120" aria-label="<?= e(__('Name der Vorlage')) ?>">
            <button class="adm-btn adm-btn--small adm-btn--ghost"><?= e(__('Umbenennen')) ?></button>
            <?php if ($pid === $def): ?><span class="adm-badge"><?= icon('star') ?> <?= e(__('Standard')) ?></span><?php endif; ?>
          </form>
        </td>
        <td><?= (int) ($usage[$pid] ?? 0) ?></td>
        <td><div style="display:flex;flex-wrap:wrap;gap:.4rem">
          <form method="post" action="<?= e(url('/admin/data/' . $t['handle'] . '/template')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $pid ?>"><button class="adm-btn adm-btn--small"><?= e(__('Gestalten')) ?> ↗</button></form>
          <?php if ($pid !== $def): ?>
          <form method="post" action="<?= e(url($base)) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="default"><input type="hidden" name="id" value="<?= $pid ?>"><button class="adm-btn adm-btn--small adm-btn--ghost"><?= e(__('Als Standard')) ?></button></form>
          <form method="post" action="<?= e(url($base)) ?>" data-confirm="<?= e(__('Vorlage „{name}“ löschen?', ['name' => (string) $p['title']])) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $pid ?>"><button class="adm-btn adm-btn--small adm-btn--ghost"><?= e(__('Löschen')) ?></button></form>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="adm-card" aria-labelledby="dtv-new">
  <h2 id="dtv-new"><?= e(__('Neue Vorlage')) ?></h2>
  <form method="post" action="<?= e(url($base)) ?>" class="adm-fields"><?= csrf_field() ?><input type="hidden" name="do" value="create">
    <div class="f f--inline"><label for="dtv-name"><?= e(__('Name')) ?></label><input id="dtv-name" name="name" maxlength="120" required placeholder="<?= e(__('z. B. Satzung')) ?>"></div>
    <div class="f f--inline"><label for="dtv-from"><?= e(__('Ausgangspunkt')) ?></label>
      <select id="dtv-from" name="from">
        <?php if ($kn): foreach (Knowledge::STYLES as $sk => $sl): ?><option value="<?= e($sk) ?>"><?= e(__('Mitgeliefert: {name}', ['name' => __($sl)])) ?></option><?php endforeach; ?>
        <?php else: ?><option value="hero"><?= e(__('Kopf wie Seiten (Einstieg aus dem Eintrag)')) ?></option><?php endif; ?>
        <?php foreach ($list as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e(__('Kopie von „{name}“', ['name' => (string) $p['title']])) ?></option><?php endforeach; ?>
        <option value=""><?= e(__('Leer')) ?></option>
      </select></div>
    <button class="adm-btn adm-btn--primary"><?= icon('plus') ?> <?= e(__('Anlegen')) ?></button>
  </form>
</section>

<?php if (count($list) > 1): ?>
<section class="adm-card" aria-labelledby="dtv-rules">
  <h2 id="dtv-rules"><?= e(__('Welche Vorlage gilt?')) ?></h2>
  <p class="adm-muted"><?= e($kn ? __('Reihenfolge: das Kapitel selbst, dann sein nächstes Oberkapitel (gilt für alle Unterkapitel), dann ein Feldwert wie der Bereich, sonst die Standard-Vorlage.')
      : __('Reihenfolge: der Eintrag selbst, dann ein Feldwert (z. B. Kategorie), sonst die Standard-Vorlage.')) ?></p>
  <form method="post" action="<?= e(url($base)) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="rules">
    <table class="adm-table">
      <thead><tr><th scope="col"><?= e(__('Für')) ?></th><th scope="col"><?= e(__('Vorlage')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $rule): $cur = $rule['page'] ? $ruleKey($rule) : ''; ?>
        <tr>
          <td><select name="rules[<?= $i ?>][what]" aria-label="<?= e(__('Für (Zeile {n})', ['n' => $i + 1])) ?>"><option value="">–</option>
            <?php foreach ($what as $grp => $opts): ?><optgroup label="<?= e($grp) ?>"><?php foreach ($opts as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $cur ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
          </select></td>
          <td><select name="rules[<?= $i ?>][page]" aria-label="<?= e(__('Vorlage (Zeile {n})', ['n' => $i + 1])) ?>"><option value="">– <?= e(__('keine Regel')) ?> –</option>
            <?php foreach ($names as $pid => $nm): ?><option value="<?= (int) $pid ?>"<?= (int) $rule['page'] === (int) $pid ? ' selected' : '' ?>><?= e((string) $nm) ?></option><?php endforeach; ?>
          </select></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p><button class="adm-btn adm-btn--primary"><?= e(__('Zuordnung speichern')) ?></button></p>
  </form>
</section>
<?php endif; ?>
