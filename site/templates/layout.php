<?php defined('APP_ROOT') || exit;
function asset_v(string $f): string { return $f . '?v=' . @filemtime(APP_ROOT . '/' . $f); }
function page_head(string $title, array $css = []): void { ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset_v('assets/dashboard.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_v('assets/app.css')) ?>">
<?php foreach ($css as $c): ?><link rel="stylesheet" href="<?= e(asset_v($c)) ?>">
<?php endforeach; ?>
<script nonce="<?= e(csp_nonce()) ?>">try{var t=localStorage.getItem('ns_dash_theme');if(t)document.documentElement.dataset.theme=JSON.parse(t);}catch(e){}</script>
</head>
<body>
<?php }
function page_foot(): void { ?>
<script src="<?= e(asset_v('assets/nav.js')) ?>"></script>
</body>
</html>
<?php }
/** Выпадающее меню отчётов: строится из реестра reports(). $cur - id текущего отчёта или 'admin'. */
function global_nav(string $cur): void {
    $groups = [];
    foreach (reports() as $id => $m) $groups[$m['group'] ?? 'Отчёты'][$id] = $m;
    $curTitle = $cur === 'admin' ? 'Настройки' : (report($cur)['title'] ?? 'Отчёты'); ?>
<div class="gnav" id="gnav">
  <button class="gnav-btn" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="gnav-menu"><span><?= e($curTitle) ?></span><span class="gnav-car" aria-hidden="true">▾</span></button>
  <div class="gnav-menu" id="gnav-menu" role="menu" hidden>
    <?php foreach ($groups as $g => $list): ?>
      <div class="gnav-group"><?= e($g) ?></div>
      <?php foreach ($list as $id => $m): ?>
        <a role="menuitem" href="index.php?r=<?= e($id) ?>"<?= $id === $cur ? ' aria-current="page"' : '' ?>><b><?= e($m['title']) ?></b><span><?= e($m['desc'] ?? '') ?></span></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <div class="gnav-sep"></div>
    <a role="menuitem" href="admin.php"<?= $cur === 'admin' ? ' aria-current="page"' : '' ?>><b>Настройки</b><span>Источники данных, доступ к Google, обновление, пароль</span></a>
  </div>
</div>
<?php }
function flashes(): void {
    foreach (flash() as [$kind, $msg]) echo '<div class="flash ' . e($kind) . '">' . e($msg) . '</div>';
}
function fmt_dt(?string $iso): string { return $iso ? date('d.m.Y H:i', strtotime($iso)) : '-'; }
