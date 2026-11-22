<?php
/**
 * Personen (Kern-Block, vom Kit überschreibbar: kits/{name}/blocks/people.php). Ausgewählte Einträge einer zentral gepflegten
 * Personen-Tabelle (Feld „Person“ = Verweis entry:{tabelle}:{id} aus dem Struktur-Browser) oder – Quelle „gefiltert“ – alle
 * Einträge der Tabelle mit bestimmten Werten in einem Feld (Core\Blocks\PeopleSource). Name, Foto, E-Mail, Telefon und
 * Funktion kommen aus dem Eintrag; Funktion, Text und Foto lassen sich je Stelle überschreiben. Visitenkarte (vCard mit Foto)
 * über vcard_entry_url(), wenn die Tabelle den Schema-Typ „Person“ hat. Aussehen: resources/css/people.css (--kit-*).
 * Adressbücher mit Funktionen (Core\Data\Roles): Funktion passend zum Filter bzw. zur Detailseite, „Ehemalige“ als eigene Liste.
 * @var \Core\Block $b  @var array $d
 */
use Core\Blocks\PeopleSource;
use Core\Data\Entries;
use Core\Data\Tables;
use Core\StructuredData;

$wrap = app()->theme->def['container_class'] ?? 'wrap';
$t = Tables::findContent((string) ($d['table'] ?? ''));
$layout = in_array($d['layout'] ?? '', ['cards', 'list', 'feature'], true) ? $d['layout'] : 'cards';
$cols = in_array((string) ($d['columns'] ?? ''), ['2', '3', '4'], true) ? (string) $d['columns'] : '3';
$people = [];
$byFilter = ($d['source'] ?? 'manual') === 'filter';
if ($t) {
    $img = Tables::imageField($t);
    // Von Hand (Liste „Personen“) oder alle Einträge der Tabelle nach Filter (Core\Blocks\PeopleSource)
    foreach (PeopleSource::rows($t, $d, is_editing()) as $row) {
        ['i' => $i, 'entry' => $e, 'item' => $it] = $row;
        $role = trim((string) ($it['role'] ?? '')) ?: (string) (StructuredData::field($t, $e, ['text'], ['funktion', 'position', 'rolle', 'job', 'beruf', 'title']) ?? '');
        $people[] = ['former' => !empty($row['former']), 'i' => $i, 'name' => Entries::title($t, $e), 'role' => $role, 'note' => trim((string) ($it['note'] ?? '')),
            'image' => (int) ($it['image'] ?? 0) ?: ($img !== '' ? (int) ($e[$img] ?? 0) : 0),
            'email' => (string) (StructuredData::field($t, $e, ['email']) ?? ''), 'tel' => (string) (StructuredData::field($t, $e, ['tel']) ?? ''),
            'href' => !empty($d['link_detail']) ? Entries::href($t, $e) : null, 'vcard' => !empty($d['vcard']) ? vcard_entry_url($t, $e) : null,
            'origin' => \Core\Data\Network::originNote($t, $e)];   // Netzwerk: Herkunft fremder Kontakte („von …“)
    }
}
$initials = function (string $name): string {
    $w = array_values(array_filter(preg_split('~\s+~u', preg_replace('~^(Dr\.|Prof\.)\s*~u', '', $name))));
    return mb_strtoupper(mb_substr($w[0] ?? '', 0, 1) . (count($w) > 1 ? mb_substr($w[count($w) - 1], 0, 1) : ''));
};
$sizes = $layout === 'feature' ? '(min-width: 1080px) 420px, 80vw' : ($layout === 'list' ? '120px' : '(min-width: 1080px) 360px, (min-width: 640px) 45vw, 90vw');
$ratio = $layout === 'list' ? '1:1' : '3:4';
?>
<div class="<?= e($wrap) ?> cms-people cms-people--<?= e($layout) ?> cms-people--c<?= e($cols) ?>">
  <?php if (!empty($d['eyebrow']) || !empty($d['title']) || !empty($d['intro'])): ?>
  <header class="cms-people__head">
    <?php if (!empty($d['eyebrow'])): ?><p class="eyebrow"<?= $b->edit('eyebrow') ?>><?= emphasis((string) $d['eyebrow']) ?></p><?php endif; ?>
    <?php if (!empty($d['title'])): ?><h2 class="h2" id="<?= e($b->titleId()) ?>"<?= $b->edit('title') ?>><?= emphasis((string) $d['title']) ?></h2><?php endif; ?>
    <?php if (!empty($d['intro'])): ?><p class="cms-people__intro"<?= $b->edit('intro') ?>><?= emphasis((string) $d['intro']) ?></p><?php endif; ?>
  </header>
  <?php endif; ?>
  <?= $byFilter && !empty($d['nearby']) ? \Core\Data\Nearby::form($d, \Core\Data\Nearby::forBlock($d), count($people)) : '' ?>
  <?php if ($people):
    $groups = array_filter([array_values(array_filter($people, fn($p) => !$p['former'])), array_values(array_filter($people, fn($p) => $p['former']))]);
    foreach ($groups as $gi => $list): ?>
  <?php if ($gi === 1): ?><h<?= !empty($d['title']) ? '3' : '2' ?> class="cms-people__former"><?= e(lt('Ehemalige')) ?></h<?= !empty($d['title']) ? '3' : '2' ?>><?php endif; ?>
  <ul class="cms-people__list<?= $gi === 1 ? ' cms-people__list--former' : '' ?>" role="list">
    <?php foreach ($list as $p): $tag = !empty($d['title']) ? ($gi === 1 ? 'h4' : 'h3') : ($gi === 1 ? 'h3' : 'h2'); ?>
    <li class="cms-person">
      <div class="cms-person__img<?= $p['image'] ? '' : ' cms-person__img--none' ?>">
        <?php if ($p['image'] && ($pic = img($p['image'], $sizes, ['ratio' => $ratio, 'alt' => $p['name']]))): ?><?= $pic ?>
        <?php else: ?><span class="cms-person__initials" aria-hidden="true"><?= e($initials($p['name'])) ?></span><?php endif; ?>
      </div>
      <div class="cms-person__body">
        <<?= $tag ?> class="cms-person__name"><?= $p['href'] ? '<a href="' . e($p['href']) . '">' . e($p['name']) . '</a>' : e($p['name']) ?></<?= $tag ?>>
        <?php if ($p['role'] !== ''): ?><p class="cms-person__role"<?= $p['i'] !== null ? $b->edit('items.' . $p['i'] . '.role') : '' ?>><?= e($p['role']) ?></p><?php endif; ?>
        <?= $p['origin'] ?>
        <?php if ($p['note'] !== ''): ?><p class="cms-person__note"<?= $p['i'] !== null ? $b->edit('items.' . $p['i'] . '.note') : '' ?>><?= e($p['note']) ?></p><?php endif; ?>
        <?php if ((!empty($d['contact']) && ($p['email'] !== '' || $p['tel'] !== '')) || $p['vcard']): ?>
        <ul class="cms-person__actions" role="list">
          <?php if (!empty($d['contact']) && $p['email'] !== ''): ?><li><a href="mailto:<?= e($p['email']) ?>"><?= icon('envelope-simple') ?><span><?= e($p['email']) ?></span></a></li><?php endif; ?>
          <?php if (!empty($d['contact']) && $p['tel'] !== '' && ($th = tel_href($p['tel']))): ?><li><a href="<?= e($th) ?>"><?= icon('phone') ?><span><?= e($p['tel']) ?></span></a></li><?php endif; ?>
          <?php if ($p['vcard']): ?><li><a href="<?= e($p['vcard']) ?>" download><?= icon('address-book') ?><span><?= e(lt('Kontakt speichern')) ?></span></a></li><?php endif; ?>
        </ul>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endforeach; ?>
  <?php elseif (is_editing()): ?><p class="empty-hint"><?= e($byFilter && $t ? __('Keine veröffentlichten Einträge mit diesem Filter.') : __('Noch keine Personen – Tabelle wählen und Personen hinzufügen.')) ?></p><?php endif; ?>
</div>
