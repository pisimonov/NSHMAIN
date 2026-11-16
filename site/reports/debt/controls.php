<?php defined('APP_ROOT') || exit; ?>
<div class="filters">
  <label>Регион
    <select id="f-reg"><option value="all">Все</option><option value="ru">Россия</option><option value="foreign">Зарубежье</option></select>
  </label>
  <label>Направление <select id="f-dir"><option value="all">Все</option></select></label>
  <label>Статус школы
    <select id="f-sst"><option value="all">Все</option><option value="работает">Работает</option><option value="закрыта">Закрыта</option><option value="нет данных">Нет в отчёте выручки</option></select>
  </label>
  <label title="Сколько месяцев прошло со срока оплаты самого старого неоплаченного начисления школы">Самый старый долг
    <select id="f-age"><option value="all">Все школы</option><option value="debt">Есть просроченный долг</option><option value="0">1 мес.</option><option value="1">2-3 мес.</option><option value="2">4-6 мес.</option><option value="3">7-12 мес.</option><option value="4">более 12 мес.</option><option value="none">Нет просроченного долга</option></select>
  </label>
</div>
<nav class="tabs" id="tabs">
  <button data-v="overview">Обзор</button>
  <button data-v="top">Топ-50 должников</button>
  <button data-v="schools">Школы</button>
  <button data-v="incidents">Первичные и повторные</button>
  <button data-v="matrix">Матрица года</button>
  <button data-v="returns">Возврат старых долгов</button>
  <button data-v="check">Проверка начислений</button>
  <button data-v="dirs">Направления</button>
  <button data-v="method">Методика</button>
</nav>
