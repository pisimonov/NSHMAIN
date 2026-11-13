<?php defined('APP_ROOT') || exit;
function asset_v(string $f): string { return 'assets/' . $f . '?v=' . @filemtime(APP_ROOT . '/assets/' . $f); }
function page_head(string $title): void { ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset_v('dashboard.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_v('app.css')) ?>">
<script nonce="<?= e(csp_nonce()) ?>">try{var t=localStorage.getItem('ns_dash_theme');if(t)document.documentElement.dataset.theme=JSON.parse(t);}catch(e){}</script>
</head>
<body>
<?php }
function page_foot(): void { ?>
</body>
</html>
<?php }
function flashes(): void {
    foreach (flash() as [$kind, $msg]) echo '<div class="flash ' . e($kind) . '">' . e($msg) . '</div>';
}
function fmt_dt(?string $iso): string { return $iso ? date('d.m.Y H:i', strtotime($iso)) : '-'; }
