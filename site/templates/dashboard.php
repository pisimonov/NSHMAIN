<?php defined('APP_ROOT') || exit;
page_head('Выручка сети');
$hasData = is_file($dataFile);
$genAt = $status['at'] ?? null;
$stale = $genAt && (time() - strtotime($genAt) > 36 * 3600);
?>
<header class="top">
  <div class="wrap">
    <div class="brandrow">
      <div class="brand">НЕ ШКОЛА<small>Накопленная выручка месяца</small></div>
      <div class="asof" id="asof"></div>
      <div class="spacer"></div>
      <div class="seg" id="scope">
        <button data-v="all">Вся сеть</button>
        <button data-v="own">Собственная сеть</button>
      </div>
      <div class="navlinks">
        <span class="upd<?= ($stale || ($status && !$status['ok'])) ? ' warn' : '' ?>" title="Время последней загрузки из Google Sheets">
          <?php if ($status && !$status['ok']): ?>Ошибка обновления <?= e(fmt_dt($genAt)) ?><?php else: ?>Обновлено <?= e(fmt_dt($genAt)) ?><?php endif; ?>
        </span>
        <form method="post" action="index.php?action=refresh"><?= csrf_field() ?><button class="btn" type="submit" title="Загрузить свежие данные из Google Sheets">Обновить</button></form>
        <a class="btn" href="admin.php">Настройки</a>
        <button class="btn" id="theme" type="button" title="Светлая / тёмная тема">Тема</button>
        <form method="post" action="index.php?action=logout"><?= csrf_field() ?><button class="btn" type="submit">Выйти</button></form>
      </div>
    </div>
    <?php if ($hasData): ?>
    <div class="filters">
      <label>Страна
        <select id="f-country"><option value="all">Все</option><option value="ru">Россия</option><option value="foreign">Зарубежье</option></select>
      </label>
      <label>Направление
        <select id="f-dir"><option value="all">Все</option></select>
      </label>
      <label>Срез на дату
        <select id="f-cut"></select>
      </label>
    </div>
    <nav class="tabs" id="tabs">
      <button data-v="overview">Обзор</button>
      <button data-v="daily">По дням</button>
      <button data-v="cities">Города</button>
      <button data-v="schools">Школы</button>
      <button data-v="dirs">Направления и страны</button>
      <button data-v="partners">Партнёры</button>
      <button data-v="trend">Динамика 12 мес.</button>
      <button data-v="method">Методика и данные</button>
    </nav>
    <?php endif; ?>
  </div>
</header>
<div class="wrap" style="padding-top:14px"><?php flashes(); ?></div>
<?php if (!$hasData): ?>
  <div class="empty">
    <h2 style="margin-top:0">Данных пока нет</h2>
    <p>Откройте <a href="admin.php">Настройки</a>: загрузите ключ сервисного аккаунта Google, проверьте ссылку на таблицу и нажмите «Обновить сейчас».</p>
    <?php if ($status && !$status['ok']): ?><div class="flash err">Последняя ошибка: <?= e($status['error']) ?></div><?php endif; ?>
  </div>
<?php else: ?>
<div class="wrap"><main id="main"></main></div>
<div id="tip"></div>
<script nonce="<?= e(csp_nonce()) ?>">window.DASH_DATA=<?= str_replace('<', '<', (string)file_get_contents($dataFile)) ?>;</script>
<script src="<?= e(asset_v('dashboard.js')) ?>"></script>
<?php endif; ?>
<?php page_foot();
