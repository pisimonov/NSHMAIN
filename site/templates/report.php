<?php defined('APP_ROOT') || exit;
/* Общая страница отчёта: шапка с меню и действиями, панель фильтров отчёта, данные и скрипт отчёта. */
$rid = $report['id'];
$css = !empty($report['css']) ? ['reports/' . $rid . '/' . $report['css']] : [];
page_head($report['title'] . ' - НЕ ШКОЛА', $css);
$dataFile = report_data_file($rid);
$hasData = is_file($dataFile);
$status = report_status($rid);
$genAt = $status['at'] ?? null;
$stale = $genAt && (time() - strtotime($genAt) > 36 * 3600);
?>
<header class="top">
  <div class="wrap">
    <div class="brandrow">
      <div class="brand">НЕ ШКОЛА<small>Управляющая компания</small></div>
      <?php global_nav($rid); ?>
      <div class="asof" id="asof"></div>
      <div class="spacer"></div>
      <div class="navlinks">
        <span class="upd<?= ($stale || ($status && !$status['ok'])) ? ' warn' : '' ?>" title="Время последней загрузки из Google Sheets">
          <?= ($status && !$status['ok']) ? 'Ошибка обновления ' : 'Обновлено ' ?><?= e(fmt_dt($genAt)) ?>
        </span>
        <form method="post" action="index.php?r=<?= e($rid) ?>&amp;action=refresh"><?= csrf_field() ?><button class="btn" type="submit" title="Загрузить свежие данные этого отчёта">Обновить</button></form>
        <button class="btn" id="theme" type="button" title="Светлая / тёмная тема">Тема</button>
        <form method="post" action="index.php?action=logout"><?= csrf_field() ?><button class="btn" type="submit">Выйти</button></form>
      </div>
    </div>
    <?php if ($hasData) include $report['dir'] . '/controls.php'; ?>
  </div>
</header>
<div class="wrap" style="padding-top:14px"><?php flashes(); ?></div>
<?php if (!$hasData): ?>
  <div class="empty">
    <h2 style="margin-top:0">Данных пока нет</h2>
    <p>Нажмите «Обновить» вверху или откройте <a href="admin.php">Настройки</a>: проверьте ключ сервисного аккаунта Google и ссылки на таблицы.</p>
    <?php if ($status && !$status['ok']): ?><div class="flash err">Последняя ошибка: <?= e($status['error']) ?></div><?php endif; ?>
  </div>
<?php else: ?>
<div class="wrap"><main id="main"></main></div>
<div id="tip"></div>
<script nonce="<?= e(csp_nonce()) ?>">window.<?= preg_replace('/\W/', '', $report['data_var']) ?>=<?= str_replace('<', '<', (string)file_get_contents($dataFile)) ?>;</script>
<script src="<?= e(asset_v('reports/' . $rid . '/view.js')) ?>"></script>
<?php endif; ?>
<?php page_foot();
