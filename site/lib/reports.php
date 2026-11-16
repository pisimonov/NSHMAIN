<?php
/*
 * Реестр отчётов. Каждый отчёт - папка reports/<id>/ с файлом report.php, который возвращает описание:
 *   id, title, group (раздел меню), desc, order, data_var (имя переменной данных в JS),
 *   defaults (настройки по умолчанию), build (callable), admin / admin_save (callable, необязательно).
 * Новый отчёт = новая папка: меню, обновление по расписанию и раздел настроек подключаются сами.
 */
defined('APP_ROOT') || exit;

function reports(): array {
    static $list = null;
    if ($list !== null) return $list;
    $list = [];
    foreach (glob(APP_ROOT . '/reports/*/report.php') ?: [] as $f) {
        $m = require $f;
        if (!is_array($m) || empty($m['id']) || empty($m['build'])) continue;
        $m['dir'] = dirname($f);
        $list[$m['id']] = $m;
    }
    uasort($list, function ($a, $b) { return ($a['order'] ?? 100) <=> ($b['order'] ?? 100); });
    return $list;
}
function report(string $id): ?array { return reports()[$id] ?? null; }

function report_dir(string $id): string {
    $d = DATA_DIR . '/reports/' . preg_replace('/[^a-z0-9_-]/', '', $id);
    if (!is_dir($d . '/cache')) @mkdir($d . '/cache', 0750, true);
    return $d;
}
function report_status(string $id): ?array { return read_json(report_dir($id) . '/status.json'); }
function report_data_file(string $id): string { return report_dir($id) . '/data.json'; }
function app_version(): string { $v = @file_get_contents(APP_ROOT . '/VERSION'); return $v ? trim($v) : 'dev'; }

/** Обновление одного отчёта. build() возвращает ['payload'=>..., 'sources'=>[...], 'summary'=>[подпись=>значение]]. */
function run_report_update(string $id, string $trigger): array {
    $m = report($id);
    if (!$m) return ['ok' => false, 'error' => 'Неизвестный отчёт ' . $id];
    @set_time_limit(600);
    @ini_set('memory_limit', '512M');
    $dir = report_dir($id);
    $lock = fopen($dir . '/update.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['ok' => false, 'error' => 'Обновление уже выполняется.'];
    $t0 = microtime(true); $warn = [];
    try {
        $res = ($m['build'])(settings(), make_source(), $dir, $warn);
        write_json_atomic($dir . '/data.json', $res['payload']);
        $status = ['ok' => true, 'at' => date('c'), 'trigger' => $trigger, 'duration' => round(microtime(true) - $t0, 1),
                   'warnings' => $warn, 'sources' => $res['sources'] ?? [], 'summary' => $res['summary'] ?? [], 'version' => app_version()];
        app_log('«' . $m['title'] . '» (' . $trigger . '): ок за ' . $status['duration'] . ' с, предупреждений: ' . count($warn));
    } catch (Throwable $e) {
        $status = ['ok' => false, 'at' => date('c'), 'trigger' => $trigger, 'error' => $e->getMessage(), 'warnings' => $warn,
                   'duration' => round(microtime(true) - $t0, 1), 'version' => app_version()];
        app_log('«' . $m['title'] . '» (' . $trigger . '): ОШИБКА ' . $e->getMessage());
    }
    write_json_atomic($dir . '/status.json', $status);
    flock($lock, LOCK_UN); fclose($lock);
    return $status;
}
/** Все отчёты по порядку (order): отчёты, которые используют данные других, идут после них. */
function run_all_updates(string $trigger): array {
    $out = [];
    foreach (reports() as $id => $m) $out[$id] = run_report_update($id, $trigger);
    return $out;
}
