<?php
/**
 * Schlagworte einer Tabelle verwalten (Core\Data\Tags): je Vokabular alle Schlagworte mit Anzahl; umbenennen, zusammenführen,
 * entfernen – in allen Einträgen aller beteiligten Felder auf einmal, mit Versionsnotiz. Mögliche Dubletten (1× benutzt, ähnlicher
 * Partner) sind markiert und lassen sich per Klick zusammenführen. Aktionen: Recht data.publish.
 * @var array $t  @var list<array> $vocabs  @var bool $canManage
 */
$base = '/admin/data/' . $t['handle'];
$names = fn(array $fs) => implode(', ', array_map(fn($m) => (\Core\Data\Tables::find($m['handle'])['name'] ?? $m['handle']) . ' · ' . $m['field'], $fs));
?>
<header class="adm-head dt-head">
  <div><p class="adm-eyebrow"><a href="<?= e(url($base)) ?>"><span aria-hidden="true"><?= icon($t['icon']) ?></span> <?= e($t['name']) ?></a></p>
    <h1><?= e(__('Schlagworte')) ?></h1>
    <p class="adm-muted"><?= e(__('Alle Schlagworte mit Anzahl der Einträge. Umbenennen, Zusammenführen und Löschen wirkt in allen Einträgen auf einmal; jede Änderung steht in den Versionen.')) ?></p></div>
</header>
<?php foreach ($vocabs as $vi => $v): ?>
<section class="adm-card tg-vocab" aria-labelledby="tg-h<?= $vi ?>">
  <h2 id="tg-h<?= $vi ?>"><?= e($v['label']) ?> <small class="adm-muted"><?= e(count($v['values']) === 1 ? __('1 Schlagwort') : __('{n} Schlagworte', ['n' => count($v['values'])])) ?></small></h2>
  <?php if (count($v['fields']) > 1): ?><p class="adm-muted"><?= e(__('Gemeinsames Vokabular von: {fields}', ['fields' => $names($v['fields'])])) ?></p><?php endif; ?>
  <?php if ($v['dups']): ?><p class="tg-dupnote"><?= icon('warning') ?> <?= e(count($v['dups']) === 1 ? __('1 mögliche Dublette – nur einmal benutzt und einem anderen Schlagwort sehr ähnlich.') : __('{n} mögliche Dubletten – nur einmal benutzt und einem anderen Schlagwort sehr ähnlich.', ['n' => count($v['dups'])])) ?></p><?php endif; ?>
  <p><label><span class="adm-sr"><?= e(__('Schlagworte filtern')) ?></span><input type="search" class="tg-filter" data-tags-filter placeholder="<?= e(__('Filtern …')) ?>"></label></p>
  <?php if (!$v['values']): ?><p class="adm-muted"><?= e(__('Noch keine Schlagworte vergeben.')) ?></p><?php else: ?>
  <table class="adm-table tg-table">
    <thead><tr><th scope="col"><?= e(__('Schlagwort')) ?></th><th scope="col"><?= e(__('Einträge')) ?></th><?php if ($canManage): ?><th scope="col"><?= e(__('Aktionen')) ?></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($v['values'] as $tag => $n): $dup = $v['dups'][$tag] ?? null; ?>
      <tr data-tag-row="<?= e(mb_strtolower($tag)) ?>"<?= $dup ? ' class="is-dup"' : '' ?>>
        <td><b><?= e($tag) ?></b><?php if ($dup): ?> <span class="tg-dup"><?= e(__('mögliche Dublette von „{tag}“', ['tag' => $dup])) ?></span><?php endif; ?></td>
        <td class="dt-c-nowrap"><a href="<?= e(url($base) . '?' . http_build_query(['q' => $tag])) ?>"><?= (int) $n ?></a></td>
        <?php if ($canManage): ?>
        <td class="tg-acts">
          <?php if ($dup): ?>
          <form method="post" action="<?= e(url($base . '/schlagworte')) ?>"><?= csrf_field() ?><input type="hidden" name="vocab" value="<?= e($v['key']) ?>"><input type="hidden" name="tag" value="<?= e($tag) ?>"><input type="hidden" name="action" value="merge"><input type="hidden" name="into" value="<?= e($dup) ?>">
            <button class="adm-btn adm-btn--small adm-btn--primary"><?= e(__('Mit „{tag}“ zusammenführen', ['tag' => $dup])) ?></button></form>
          <?php endif; ?>
          <details class="tg-more"><summary class="adm-btn adm-btn--small adm-btn--ghost"><?= e(__('Ändern …')) ?></summary>
            <form method="post" action="<?= e(url($base . '/schlagworte')) ?>" class="tg-form"><?= csrf_field() ?><input type="hidden" name="vocab" value="<?= e($v['key']) ?>"><input type="hidden" name="tag" value="<?= e($tag) ?>"><input type="hidden" name="action" value="rename">
              <label><span><?= e(__('Umbenennen in')) ?></span><input name="to" value="<?= e($tag) ?>" maxlength="60" required></label><button class="adm-btn adm-btn--small"><?= e(__('Umbenennen')) ?></button></form>
            <form method="post" action="<?= e(url($base . '/schlagworte')) ?>" class="tg-form"><?= csrf_field() ?><input type="hidden" name="vocab" value="<?= e($v['key']) ?>"><input type="hidden" name="tag" value="<?= e($tag) ?>"><input type="hidden" name="action" value="merge">
              <label><span><?= e(__('Zusammenführen mit')) ?></span><select name="into" required><option value=""><?= e(__('– wählen –')) ?></option><?php foreach (array_keys($v['values']) as $o): if ($o === $tag) continue; ?><option value="<?= e($o) ?>"><?= e($o) ?></option><?php endforeach; ?></select></label><button class="adm-btn adm-btn--small"><?= e(__('Zusammenführen')) ?></button></form>
            <form method="post" action="<?= e(url($base . '/schlagworte')) ?>" class="tg-form" data-confirm="<?= e(__('Schlagwort „{tag}“ aus {n} Einträgen entfernen?', ['tag' => $tag, 'n' => $n])) ?>"><?= csrf_field() ?><input type="hidden" name="vocab" value="<?= e($v['key']) ?>"><input type="hidden" name="tag" value="<?= e($tag) ?>"><input type="hidden" name="action" value="delete">
              <button class="adm-btn adm-btn--small adm-btn--ghost adm-btn--danger-text"><?= e(__('Entfernen')) ?></button></form>
          </details>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php endforeach; ?>
