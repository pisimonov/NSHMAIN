<?php
/*
 * Обновление данных всех отчётов (или одного) и, если включено, кода сайта из GitHub.
 *   Планировщик хостинга (cron):   php /полный/путь/к/сайту/cron.php [id-отчёта | code]
 *   По ссылке:                     https://ваш-домен/cron.php?key=<cron_key>[&report=id | &report=code]
 * «code» - только проверить и поставить новую версию кода.
 * Если при обычном запуске поставлена новая версия кода, данные обновятся следующим запуском
 * (в этом процессе ещё загружен старый код).
 */
define('APP_ROOT', __DIR__);
require __DIR__ . '/lib/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $key = (string)cfg('cron_key', '');
    if ($key === '' || mb_strpos($key, 'ЗАМЕНИТЕ') !== false || strlen($key) < 16 || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Доступ запрещён.\n");
    }
    ignore_user_abort(true);
    $only = (string)($_GET['report'] ?? '');
} else {
    $only = (string)($argv[1] ?? '');
}
$trigger = PHP_SAPI === 'cli' ? 'cron' : 'cron по ссылке';
if ($only === 'code' || ($only === '' && !empty(settings()['update_auto']))) {
    $u = upd_run($trigger);
    echo ($u['ok'] ? 'OK  ' : 'ERR ') . 'код: ' . $u['msg'] . "\n";
    if ($only === 'code') exit($u['ok'] ? 0 : 1);
    if (!empty($u['updated'])) { echo "Данные отчётов обновятся при следующем запуске (уже на новом коде).\n"; exit(0); }
}
if ($only !== '' && !report($only)) { echo "Неизвестный отчёт: {$only}. Есть: " . implode(', ', array_keys(reports())) . "\n"; exit(2); }
$res = $only !== '' ? [$only => run_report_update($only, $trigger)] : run_all_updates($trigger);
$fail = 0;
foreach ($res as $id => $st) {
    if ($st['ok']) {
        echo "OK  {$id}: {$st['at']} за {$st['duration']} с\n";
        foreach ($st['warnings'] as $w) echo "  ! {$w}\n";
    } else { $fail++; echo "ERR {$id}: {$st['error']}\n"; }
}
exit($fail ? 1 : 0);
