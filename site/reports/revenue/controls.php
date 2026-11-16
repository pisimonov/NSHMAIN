<?php defined('APP_ROOT') || exit; ?>
<div class="filters">
  <div class="seg" id="scope">
    <button data-v="all">Вся сеть</button>
    <button data-v="own">Собственная сеть</button>
  </div>
  <label>Страна
    <select id="f-country"><option value="all">Все</option><option value="ru">Россия</option><option value="foreign">Зарубежье</option></select>
  </label>
  <label>Направление <select id="f-dir"><option value="all">Все</option></select></label>
  <label>Срез на дату <select id="f-cut"></select></label>
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
