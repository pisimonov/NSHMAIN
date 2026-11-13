<?php
/*
 * Ежедневное обновление данных.
 *   Из планировщика хостинга (cron):   php /полный/путь/к/сайту/cron.php
 *   Или по ссылке:                     https://ваш-домен/cron.php?key=<cron_key из config.php>
 */
define('APP_ROOT', __DIR__);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sources.php';
require __DIR__ . '/lib/builder.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $key = (string)cfg('cron_key', '');
    if ($key === '' || mb_strpos($key, 'ЗАМЕНИТЕ') !== false || strlen($key) < 16 || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Доступ запрещён.\n");
    }
    ignore_user_abort(true);
}
$st = run_update(PHP_SAPI === 'cli' ? 'cron' : 'cron по ссылке');
if ($st['ok']) {
    echo 'OK ' . $st['at'] . ' за ' . $st['duration'] . " с; срез: {$st['asOf']}; школ: {$st['schools']}\n";
    foreach ($st['warnings'] as $w) echo '! ' . $w . "\n";
    exit(0);
}
echo 'ОШИБКА: ' . $st['error'] . "\n";
exit(1);
