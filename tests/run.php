<?php
/*
 * Автотесты сайта на синтетических данных (реальные данные в репозиторий не попадают).
 *   php tests/run.php
 * Запускаются в GitHub Actions перед каждой выкладкой; при ошибке выкладка не выполняется.
 */
$tmp = sys_get_temp_dir() . '/ns-tests-' . getmypid();
@mkdir($tmp . '/csv', 0777, true);
@mkdir($tmp . '/data', 0777, true);
file_put_contents($tmp . '/config.php', "<?php return ['timezone'=>'Europe/Moscow','data_dir'=>'{$tmp}/data','setup_key'=>'x','cron_key'=>'x','force_https'=>false];");
putenv('NS_CONFIG=' . $tmp . '/config.php');
define('APP_ROOT', dirname(__DIR__) . '/site');
require APP_ROOT . '/lib/bootstrap.php';
set_exception_handler(null);

$fails = 0; $n = 0;
function check(string $name, $got, $exp): void {
    global $fails, $n; $n++;
    $ok = is_float($exp) ? abs((float)$got - $exp) < 0.01 : $got === $exp;
    if (!$ok) { $fails++; echo "FAIL {$name}: получено " . var_export($got, true) . ', ожидалось ' . var_export($exp, true) . "\n"; }
}
function csv_write(string $file, array $rows): void { $fh = fopen($file, 'w'); foreach ($rows as $r) fputcsv($fh, $r, ',', '"', '\\'); fclose($fh); }

/* ---------- утилиты ---------- */
check('ru_month typo', parse_ru_month('Окрябрь 2026'), '2026-10');
check('ru_month genitive', parse_ru_month('ноября 2025'), '2025-11');
check('ru_month junk', parse_ru_month('Итого'), null);
check('date iso', parse_date('2026-11-01'), '2026-11-01');
check('date ru', parse_date('02.11.2026'), '2026-11-02');
check('date serial', parse_date(46327), '2026-11-01');
check('num comma', parse_num('1 234,5'), 1234.5);
check('num float', parse_num(307.38), 307.38);
check('sheet id', spreadsheet_id('https://docs.google.com/spreadsheets/d/1RSr3AWZGEbn0PUHHdsoA87t5UIq75sTYsrqbP2gFNiQ/edit#gid=0'), '1RSr3AWZGEbn0PUHHdsoA87t5UIq75sTYsrqbP2gFNiQ');
check('month add', month_add('2026-01', -1), '2025-12');
$err = [];
check('month links', parse_month_links("2025-12 https://docs.google.com/spreadsheets/d/1WnyB2qqJlgqPQHqTEBOSnVZ7AJz206zD0Ps-FqDloeU/edit\nмусор", $err), ['2025-12' => 'https://docs.google.com/spreadsheets/d/1WnyB2qqJlgqPQHqTEBOSnVZ7AJz206zD0Ps-FqDloeU/edit']);
check('month links error', count($err), 1);
check('registry', array_keys(reports()), ['revenue', 'debt']);
check('debt month', debt_mi('2023_1'), 2023 * 12);

/* ---------- синтетическая выручка: 3 месяца, 3 школы ---------- */
$hdr = ['Страна', 'Город', 'Размер', 'Направление', 'Школа (суффикс)', 'Дата', 'Выручка (валюта)', 'Выручка (рубли)', 'URL', 'Валюта', 'Школа', 'Партнер', 'Закрыта', 'Сумма возвратов'];
$schools = [['Россия', 'Москва', 'Барабны', 'Москва-Барабны-1', 'УК', ''], ['Россия', 'Тула', 'Вокал', 'Тула-Вокал-1', 'Иванов', ''],
            ['Беларусь', 'Минск', 'Барабны', 'Минск-Барабны-1', 'Петров', 'Закрыта']];
$expect = [];
foreach (['2025-11' => 30, '2026-10' => 31, '2026-11' => 20] as $ym => $days) {
    $rows = [$hdr]; $tot = 0;
    foreach ($schools as $k => [$co, $city, $dir, $name, $partner, $closed]) {
        for ($d = 1; $d <= $days; $d++) { $v = 1000 * ($k + 1) + $d; $tot += $v; $rows[] = [$co, $city, '', $dir, 1, sprintf('%s-%02d', $ym, $d), $v, $v, 'u', 'RUB', $name, $partner, $closed, 10]; }
    }
    $expect[$ym] = $tot;
    csv_write("{$tmp}/csv/{$ym}.csv", $rows);
}
csv_write("{$tmp}/csv/2026-11__archive.csv", [['Окрябрь 2026', 'csv:2026-10'], ['Ноябрь 2025', 'csv:2025-11']]);

/* ---------- синтетический реестр задолженности ---------- */
$dh = ['', 'Партнер', 'ГОРОД', 'НАПРАВЛЕНИЕ', 'МЕСЯЦ НАЧИСЛЕНИЯ', 'ТИП НАЧИСЛЕНИЯ', 'СУММА НАЧИСЛЕНИЯ В РУБ.', 'ЗАДОЛЖЕННОСТЬ', 'Комментарий', 'МЕСЯЦ ОПЛАТЫ', 'СУММА ОПЛАТЫ В РУБ.', 'Месяц списания долга', 'Списание задолженности'];
$debt = [[''], ['', '', '', '', '', '', '999', '999'], $dh,
    ['', 'Иванов', 'Тула', 'Вокал', '2026_8', 'Роялти', 50000, 0, '', '2026_9', 50000, '', 0],          // в срок
    ['', 'Иванов', 'Тула', 'Вокал', '2026_7', 'Роялти', 40000, 0, '', '2026_10', 40000, '', 0],         // с опозданием
    ['', 'Петров', 'Минск', 'Гитара и вокал', '2026_6', 'Роялти', 30000, 30000, 'Ждем оплату', '', 0, '', 0],
    ['', 'Петров', 'Минск', 'Гитара и вокал', '2026_7', 'Роялти', 30000, 30000, '', '', 0, '', 0],
    ['', 'Петров', 'Минск', 'Гитара и Вокал', '2026_8', 'Роялти', 30000, 20000, '', '2026_10', 10000, '', 0],
    ['', 'Петров', 'Минск', 'Гитара и Вокал', '2026_10', 'Роялти', 30000, 30000, '', '', 0, '', 0],   // срок не наступил
    ['', 'Петров', 'Минск', 'Гитара и Вокал', '2026_9', 'Мероприятия', 5000, 5000, '', '', 0, '', 0],
    ['', 'Иванов', 'Тула', 'Вокал', '2026_10', 'Роялти', 11218.6, 0, '', '2026_11', 11218.6, '', 0]];   // = 10% × (62 496 - 310) + 5 000
csv_write("{$tmp}/csv/debt.csv", $debt);

$s = settings();
$s['dev_csv_dir'] = $tmp . '/csv'; $s['dev_now'] = '2026-11-21 12:00';
$s['current_url'] = 'csv:2026-11'; $s['archive_overrides'] = []; $s['debt_url'] = 'csv:debt';

$res = (function () use ($s) {
    $warn = []; $out = [];
    foreach (reports() as $id => $m) {
        $dir = report_dir($id);
        $r = ($m['build'])(array_replace(default_settings(), $s), make_source_for_test($s), $dir, $warn);
        write_json_atomic($dir . '/data.json', $r['payload']);
        $out[$id] = $r;
    }
    return [$out, $warn];
})();
function make_source_for_test(array $s): SheetSource { return new CsvDirSource($s['dev_csv_dir']); }
[$out, $warn] = $res;

$rp = $out['revenue']['payload'];
check('revenue cur', $rp['meta']['cur'], '2026-11');
check('revenue asOf', $rp['meta']['asOf'], '2026-11-20');
check('revenue own', $rp['meta']['own'], ['Москва-Барабны-1']);
foreach ($expect as $ym => $tot) check("revenue total {$ym}", (float)array_sum(array_filter($rp['monthly'][$ym])), (float)$tot);
check('revenue months', $rp['meta']['months'], ['2025-11', '2026-10', '2026-11']);
check('revenue city', $rp['schools'][array_search('Минск-Барабны-1', array_column($rp['schools'], 'n'))]['co'], 'Беларусь');

$dp = $out['debt']['payload'];
check('debt rows (только роялти)', count($dp['rows']), 7);
check('debt schools (гитара и вокал объединены)', count($dp['schools']), 2);
check('debt other types', $dp['meta']['otherTypesDebt'], ['Мероприятия' => 5000.0]);
check('debt curMi', $dp['meta']['curMi'], 2026 * 12 + 10);
$minsk = $dp['schools'][array_search('Минск', array_column($dp['schools'], 'c'))];
check('debt region', $minsk['reg'], 'foreign');
check('debt partner latest', $minsk['p'], 'Петров');
check('debt summary overdue', $out['debt']['summary']['Просроченный долг'], '80 000 ₽');   // 30000+30000+20000; октябрь в сроке
// проверка начисления роялти: выручка и возвраты из отчёта по выручке против реестра
$chk = $dp['check'];
check('check: месяцы', $chk['months'], ['2026-10']);
$byName = []; foreach ($chk['rows'] as $r) $byName[$r['c'] . '|' . $r['d']] = $r;
check('check: Тула выручка/возвраты/начислено', $byName['Тула|Вокал']['v']['2026-10'], [62496.0, 310.0, 11218.6]);
check('check: Москва - собственная сеть', $byName['Москва|Барабаны']['own'], true);
check('check: Москва - роялти нет', $byName['Москва|Барабаны']['v']['2026-10'][2], null);
check('check: Минск Барабаны - только выручка', $byName['Минск|Барабаны']['si'], -1);
check('revenue: возвраты в данных', (float)array_sum(array_filter(((array)$rp['refunds'])['2026-10'])), 3 * 31 * 10.0);
check('debt comment', $dp['comments'], ['Ждем оплату']);

/* ---------- 1-е число: прошлый месяц должен быть полным ---------- */
function rollover(array $s, string $now, string $cur, array $ov = []): array {
    $s['dev_now'] = $now; $s['current_url'] = $cur; $s['archive_overrides'] = $ov;
    $w = []; $dir = report_dir('revenue');
    $r = revenue_build(array_replace(default_settings(), $s), make_source_for_test($s), $dir, $w);
    return [$r['payload']['meta'], $r['sources'], $w, $r['payload']['monthly']];
}
foreach (glob(report_dir('revenue') . '/cache/*.json') as $f) unlink($f);
// а) бот ещё не переключил файл: основной файл = октябрь
[$m, $src] = rollover($s, '2026-11-01 10:00', 'csv:2026-10');
check('1-е: месяц', $m['cur'], '2026-10');
check('1-е: срез - последний день', $m['asOf'], '2026-10-31');
check('1-е: нет «день идёт»', $m['todayInCur'], false);
check('1-е: срез не сдвинут', $m['asOfShifted'], false);
// б) файл уже переключён на ноябрь, октябрь берётся из архива (лист «Предыдущие месяца»)
foreach (glob(report_dir('revenue') . '/cache/*.json') as $f) unlink($f);
[$m, $src, , $mon] = rollover($s, '2026-11-01 10:00', 'csv:2026-11');
check('1-е, файл переключён: месяц', $m['cur'], '2026-10');
check('1-е, файл переключён: источник октября', $src['2026-10']['kind'], 'archive');
check('1-е, файл переключён: октябрь полный', (float)array_sum(array_filter($mon['2026-10'])), (float)$expect['2026-10']);
check('1-е, файл переключён: нет пометки о неполных данных', (array)$m['stale'], []);
// в) файл переключён, архива нет, есть только прошлая выгрузка основного файла -> пометка stale
foreach (glob(report_dir('revenue') . '/cache/*.json') as $f) unlink($f);
rollover($s, '2026-10-31 20:00', 'csv:2026-10');              // последнее обновление до смены файла
csv_write("{$tmp}/csv/2026-11__archive.csv", [['Ноябрь 2025', 'csv:2025-11']]);   // архива октября ещё нет
[$m, $src, $w] = rollover($s, '2026-11-01 10:00', 'csv:2026-11');
check('без архива: октябрь из кэша', $src['2026-10']['from'] ?? '', 'live');
check('без архива: пометка stale', array_keys((array)$m['stale']), ['2026-10']);
check('без архива: предупреждение', (bool)preg_grep('/Октябрь 2026: архивный файл не найден/u', $w), true);

echo ($fails ? "ПРОВАЛЕНО: {$fails} из {$n}\n" : "OK: {$n} проверок\n");
exit($fails ? 1 : 0);
