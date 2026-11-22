<?php
/**
 * Listen-Vorlagen einer Tabelle (Core\Blocks\ListTemplates): Karten mit Vorschaubild, Standard, Verwendung, Zeilenmenü.
 * @var array $t  @var array $list  @var array $usage  [schlüssel => [seiten-id => titel]]  @var ?string $std
 */
use Core\Blocks\ListTemplates;
use Core\RowMenu;

$base = '/admin/data/' . $t['handle'] . '/vorlagen';
$src = fn(array $tpl) => match ($tpl['source']) { 'kit' => __('aus dem Kit'), 'ext' => __('aus einer Erweiterung'), default => '' };
?>
<header class="adm-head dt-head">
  <div><h1><span aria-hidden="true" class="dt-h1icon"><?= icon($t['icon']) ?></span> <?= e($t['name']) ?> <span class="dt-h1sub">· <?= e(__('Listen-Vorlagen')) ?></span></h1>
    <p class="adm-muted"><?= e(__('Vorlagen legen fest, wie eine Datenliste aussieht: Darstellung, Felder, Spalten, Bild, Sortierung und Filter für Besucher. Die Redaktion wählt im Block nur noch Tabelle, Vorlage, Anzahl und Filter. Eine geänderte Vorlage gilt sofort überall, wo sie gewählt ist.')) ?></p></div>
  <a class="adm-btn adm-btn--primary" href="<?= e(url($base . '/neu')) ?>"><?= icon('plus') ?> <?= e(__('Neue Vorlage')) ?></a>
</header>

<?php if (!$list): ?>
<div class="adm-card"><p><?= e(__('Noch keine Vorlagen. Legen Sie eine an oder speichern Sie im Editor die Einstellungen einer Datenliste mit „Als Vorlage speichern“.')) ?></p></div>
<?php else: ?>
<ul class="ltpl-cards" role="list">
  <?php foreach ($list as $k => $tpl):
      $pages = $usage[$k] ?? [];
      $id = 'ltpl-m-' . substr(md5($k), 0, 10);
      $isStd = $k === $std;
      $db = $tpl['source'] === 'db';
      $items = [
          ['key' => 'open', 'label' => $db ? __('Bearbeiten') : __('Ansehen'), 'href' => url($base . '/' . rawurlencode($k))],
          ['key' => 'duplicate', 'label' => __('Duplizieren'), 'post' => url($base . '/aktion'), 'fields' => ['key' => $k, 'do' => 'duplicate']],
          $db ? ($isStd
              ? ['key' => 'default', 'group' => 'visibility', 'icon' => 'star', 'label' => __('Nicht mehr Standard'), 'post' => url($base . '/aktion'), 'fields' => ['key' => $k, 'do' => 'undefault']]
              : ['key' => 'default', 'group' => 'visibility', 'icon' => 'star', 'label' => __('Als Standard für neue Listen'), 'post' => url($base . '/aktion'), 'fields' => ['key' => $k, 'do' => 'default']]) : null,
          $db && !$pages ? ['key' => 'delete', 'label' => __('Löschen'), 'post' => url($base . '/aktion'), 'fields' => ['key' => $k, 'do' => 'delete'],
              'confirm' => __('Vorlage „{name}“ löschen?', ['name' => $tpl['name']])] : null,
      ];
  ?>
  <li class="ltpl-card<?= $isStd ? ' is-default' : '' ?>"<?= RowMenu::host($id) ?>>
    <a class="ltpl-card__prev" href="<?= e(url($base . '/' . rawurlencode($k))) ?>" tabindex="-1" aria-hidden="true"><?= ListTemplates::preview($tpl['base']) ?></a>
    <div class="ltpl-card__body">
      <h2 class="ltpl-card__name"><a href="<?= e(url($base . '/' . rawurlencode($k))) ?>"><?= e($tpl['name']) ?></a></h2>
      <p class="ltpl-card__meta"><?= e(ListTemplates::baseLabel($tpl['base'])) ?><?php if ($src($tpl) !== ''): ?> · <?= e($src($tpl)) ?><?php endif; ?></p>
      <p class="ltpl-card__tags">
        <?php if ($isStd): ?><span class="adm-badge"><?= icon('star') ?> <?= e(__('Standard')) ?></span><?php endif; ?>
        <?php if (!$db): ?><span class="adm-badge adm-badge--muted"><?= icon('lock') ?> <?= e(__('nur lesbar')) ?></span><?php endif; ?>
        <span class="ltpl-card__use" title="<?= e(implode(', ', $pages)) ?>"><?= e(!$pages ? __('nicht verwendet') : (count($pages) === 1 ? __('auf 1 Seite') : __('auf {n} Seiten', ['n' => count($pages)]))) ?></span>
      </p>
    </div>
    <div class="ltpl-card__menu"><?= RowMenu::render($id, $tpl['name'], array_values(array_filter($items))) ?></div>
  </li>
  <?php endforeach; ?>
</ul>
<p class="adm-muted ltpl-note"><?= e(__('„Standard“: neue Datenlisten dieser Tabelle beginnen mit dieser Vorlage. Bestehende Blöcke behalten ihre Einstellungen. Verwendete Vorlagen lassen sich erst löschen, wenn keine Seite sie mehr wählt.')) ?></p>
<?php endif; ?>
