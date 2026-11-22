<?php
/**
 * Kern-Fragment „breadcrumb“ (überschreibbar: kits/{kit}/fragments/breadcrumb.php): Brotkrumen-Navigation.
 * $items = [['label' => …, 'href' => …], …] (letzter Eintrag = aktuelle Seite, ohne Link); ohne $items aus dem
 * Seitenbaum (Startseite › Elternseiten › Seite), auf Detailseiten von Einträgen mit Übersichtsseite und Oberkapiteln (Wissen). Für die
 * strukturierten Daten sorgt Core\StructuredData.
 * Klassen: .breadcrumb (+ $class), aktuelle Seite aria-current="page".
 * @var ?array $items  @var ?array $page  @var ?string $class
 */
use Core\Pages;

// Detailseite eines Eintrags: Startseite › Übersichtsseite (mit Elternseiten) › Oberkapitel (Wissen) › Eintrag
if (!isset($items) && !isset($page) && ($ctx = app()->entry) && ($eu = \Core\Data\Entries::url($ctx['table'], $ctx['entry']))) {
    $items = [];
    if ($home = Pages::home()) $items[] = ['label' => $home['nav_title'] ?? '' ?: $home['title'], 'href' => Pages::url($home)];
    $route = (string) ($ctx['table']['settings']['route'] ?? '');
    if ($route !== '' && ($list = Pages::byPath($route)) && empty($list['is_home'])) {
        foreach ([...Pages::ancestors($list), $list] as $a) $items[] = ['label' => $a['nav_title'] ?? '' ?: $a['title'], 'href' => Pages::url($a)];
    }
    if (\Core\Data\Knowledge::is($ctx['table'])) {
        $tree = \Core\Data\Knowledge::tree($ctx['table']);
        foreach (\Core\Data\Knowledge::ancestors($tree, (int) $ctx['entry']['id']) as $a) if ($a['href']) $items[] = ['label' => $a['title'], 'href' => $a['href']];
    }
    $items[] = ['label' => \Core\Data\Entries::title($ctx['table'], $ctx['entry']), 'href' => null];
}
if (!isset($items)) {
    $page ??= app()->currentPage;
    $items = [];
    if ($page && empty($page['is_home']) && !empty($page['id'])) {
        if ($home = Pages::home()) $items[] = ['label' => $home['nav_title'] ?? '' ?: $home['title'], 'href' => Pages::url($home)];
        foreach (Pages::ancestors($page) as $a) $items[] = ['label' => $a['nav_title'] ?? '' ?: $a['title'], 'href' => Pages::url($a)];
        $items[] = ['label' => $page['title'], 'href' => null];
    }
}
if (count($items) < 2) return;
$last = array_key_last($items);
?>
<nav class="breadcrumb<?= !empty($class) ? ' ' . e($class) : '' ?>" aria-label="<?= e(lt('Brotkrumen')) ?>">
  <ol role="list">
    <?php foreach ($items as $i => $it): ?><li><?php if ($i === $last || empty($it['href'])): ?><span aria-current="page"><?= e($it['label']) ?></span><?php else: ?><a href="<?= e($it['href']) ?>"><?= e($it['label']) ?></a><?php endif; ?></li><?php endforeach; ?>
  </ol>
</nav>
