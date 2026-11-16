<?php
/* Проверка после выкладки: версия кода и наличие отчётов. Данных и настроек не раскрывает. */
define('APP_ROOT', __DIR__);
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo json_encode(['ok' => true, 'version' => app_version(), 'reports' => array_keys(reports())], JSON_UNESCAPED_UNICODE), "\n";
