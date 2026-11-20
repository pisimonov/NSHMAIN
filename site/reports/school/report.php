<?php
/*
 * Отчёт «Экономика школы»: воронка продаж школы за текущий месяц, сравнение с прошлым месяцем,
 * средним по направлению и эталоном, план и факт, потери выручки по шагам воронки.
 *
 * Источник - одна Google-таблица (или до трёх) с листами:
 *   «Воронка»: строка = школа × день. Дата | Город | Направление | Лиды | Постановки | Встречи |
 *              Первые продажи | Новые продажи (₽) | Старые продажи (₽)
 *   «План»:    Месяц | Город | Направление | План лидов | План выручки (₽)
 *   «Эталон»:  Направление (пусто или «Все» - для всех) | Лид → постановка | Постановка → встреча |
 *              Встреча → продажа | Средний чек (₽). Конверсии - в % или долях.
 * Столбцы ищутся по заголовкам (регистр и порядок не важны).
 */
defined('APP_ROOT') || exit;

const SCHOOL_COLS = 'A1:Z';
// ключ => варианты заголовка; вариант - набор подстрок, которые все должны быть в заголовке
const SCHOOL_FUNNEL_SPEC = [
    'date' => [['дата'], ['день']], 'city' => [['город']], 'dir' => [['направлен']],
    'S' => [['перв', 'продаж']], 'NR' => [['нов', 'продаж']], 'OR' => [['стар', 'продаж']],
    'L' => [['лид']], 'B' => [['постанов']], 'M' => [['встреч'], ['пробн', 'состоял']],
];
const SCHOOL_PLAN_SPEC = [
    'month' => [['месяц'], ['период']], 'city' => [['город']], 'dir' => [['направлен']],
    'leads' => [['лид']], 'rev' => [['выручк']],
];
const SCHOOL_REF_SPEC = [
    'dir' => [['направлен']], 'cr1' => [['лид', 'постанов']], 'cr2' => [['постанов', 'встреч']],
    'cr3' => [['встреч', 'продаж']], 'chk' => [['чек']],
];
const SCHOOL_METRICS = ['L', 'B', 'M', 'S', 'NR', 'OR'];

function school_norm(string $s): string { return str_replace('ё', 'е', mb_strtolower(norm_space($s), 'UTF-8')); }
/** Ключ школы: «город|направление» без учёта регистра, ё/е и пробелов. */
function school_key(string $city, string $dir): string { return school_norm($city) . '|' . school_norm(str_replace('Барабны', 'Барабаны', $dir)); }

/** Ищет строку заголовков среди первых строк листа: [номер строки, [ключ => номер столбца]]. */
function school_header(array $rows, array $spec, array $required, string $sheet): array {
    foreach (array_slice($rows, 0, 15, true) as $ri => $row) {
        $map = [];
        foreach ($row as $ci => $cell) {
            $t = school_norm((string)$cell);
            if ($t === '') continue;
            foreach ($spec as $key => $alts) {
                if (isset($map[$key])) continue;
                foreach ($alts as $parts) {
                    $all = true;
                    foreach ($parts as $p) if (mb_strpos($t, $p) === false) { $all = false; break; }
                    if ($all) { $map[$key] = $ci; continue 3; }
                }
            }
        }
        if (!array_diff($required, array_keys($map))) return [$ri, $map];
    }
    $names = ['date' => 'Дата', 'city' => 'Город', 'dir' => 'Направление', 'L' => 'Лиды', 'B' => 'Постановки', 'M' => 'Встречи', 'S' => 'Первые продажи',
              'NR' => 'Новые продажи', 'OR' => 'Старые продажи', 'leads' => 'План лидов', 'rev' => 'План выручки', 'cr1' => 'Лид → постановка',
              'cr2' => 'Постановка → встреча', 'cr3' => 'Встреча → продажа', 'chk' => 'Средний чек'];
    throw new RuntimeException('Лист «' . $sheet . '»: не найдена строка заголовков. Нужны столбцы: ' . implode(', ', array_map(function ($k) use ($names) { return $names[$k] ?? $k; }, $required)) . '.');
}
/** Месяц из ячейки: дата, «2026-11», «ноябрь 2026». */
function school_month($v): ?string {
    if (is_string($v) && preg_match('~^\s*(\d{4})[-./](\d{1,2})\s*$~', $v, $m)) return sprintf('%04d-%02d', $m[1], $m[2]);   // 2026-11
    if (is_string($v) && preg_match('~^\s*(\d{1,2})[-./](\d{4})\s*$~', $v, $m)) return sprintf('%04d-%02d', $m[2], $m[1]);   // 11.2026
    $d = parse_date($v);
    if ($d) return substr($d, 0, 7);
    return is_string($v) ? parse_ru_month($v) : null;
}
function school_rate($v): ?float {
    if ($v === '' || $v === null) return null;
    $x = parse_num(is_string($v) ? str_replace('%', '', $v) : $v);
    if ($x <= 0) return null;
    return $x > 1.5 ? $x / 100 : $x;      // 62 или 62% -> 0,62
}

/** Лист «Воронка» -> школы с дневными рядами текущего и прошлого месяца. */
function school_parse_funnel(array $rows, string $cur, string $prev, string $sheet, array &$warn): array {
    [$hr, $c] = school_header($rows, SCHOOL_FUNNEL_SPEC, ['date', 'city', 'dir', 'L', 'B', 'M', 'S', 'NR', 'OR'], $sheet);
    $n = ['cur' => month_days($cur), 'prev' => month_days($prev)];
    $out = []; $bad = 0; $lastDay = 0;
    foreach (array_slice($rows, $hr + 1) as $row) {
        $city = norm_space((string)($row[$c['city']] ?? '')); $dir = norm_space((string)($row[$c['dir']] ?? ''));
        if ($city === '' && $dir === '') continue;
        $d = parse_date($row[$c['date']] ?? null);
        if (!$d || $city === '' || $dir === '') { $bad++; continue; }
        $ym = substr($d, 0, 7);
        $per = $ym === $cur ? 'cur' : ($ym === $prev ? 'prev' : null);
        if (!$per) continue;
        $k = school_key($city, $dir);
        if (!isset($out[$k])) {
            $out[$k] = ['id' => $k, 'city' => $city, 'dir' => str_replace('Барабны', 'Барабаны', $dir), 'plan' => null];
            foreach (['cur', 'prev'] as $p) foreach (SCHOOL_METRICS as $m) $out[$k][$p][$m] = array_fill(0, $n[$p], 0);
        }
        $day = (int)substr($d, 8, 2);
        foreach (SCHOOL_METRICS as $m) $out[$k][$per][$m][$day - 1] += parse_num($row[$c[$m]] ?? 0);
        if ($per === 'cur') $lastDay = max($lastDay, $day);
    }
    if ($bad) $warn[] = "Лист «{$sheet}»: пропущено строк без даты, города или направления: {$bad}.";
    return [$out, $lastDay];
}
/** Лист «План» -> [ключ школы => [leads, rev]] на текущий месяц. */
function school_parse_plan(array $rows, string $cur, string $sheet, array &$warn): array {
    [$hr, $c] = school_header($rows, SCHOOL_PLAN_SPEC, ['city', 'dir', 'leads', 'rev'], $sheet);
    $out = []; $bad = 0;
    foreach (array_slice($rows, $hr + 1) as $row) {
        $city = norm_space((string)($row[$c['city']] ?? '')); $dir = norm_space((string)($row[$c['dir']] ?? ''));
        if ($city === '' || $dir === '') continue;
        if (isset($c['month'])) { $ym = school_month($row[$c['month']] ?? ''); if ($ym === null) { $bad++; continue; } if ($ym !== $cur) continue; }
        $k = school_key($city, $dir);
        $out[$k] = ['leads' => parse_num($row[$c['leads']] ?? 0) + ($out[$k]['leads'] ?? 0), 'rev' => parse_num($row[$c['rev']] ?? 0) + ($out[$k]['rev'] ?? 0)];
    }
    if ($bad) $warn[] = "Лист «{$sheet}»: не распознан месяц в {$bad} строках.";
    return $out;
}
/** Лист «Эталон» -> [направление (или «*») => конверсии и чек]. */
function school_parse_ref(array $rows, string $sheet, array &$warn): array {
    [$hr, $c] = school_header($rows, SCHOOL_REF_SPEC, ['cr1', 'cr2', 'cr3', 'chk'], $sheet);
    $out = [];
    foreach (array_slice($rows, $hr + 1) as $row) {
        $v = ['cr1' => school_rate($row[$c['cr1']] ?? null), 'cr2' => school_rate($row[$c['cr2']] ?? null), 'cr3' => school_rate($row[$c['cr3']] ?? null),
              'chk' => ($x = parse_num($row[$c['chk']] ?? 0)) > 0 ? $x : null];
        if (!array_filter($v)) continue;
        if (in_array(null, $v, true)) { $warn[] = "Лист «{$sheet}»: в строке «" . ($row[$c['dir'] ?? -1] ?? '') . '» заполнены не все значения - строка пропущена.'; continue; }
        $d = isset($c['dir']) ? school_norm(str_replace('Барабны', 'Барабаны', (string)($row[$c['dir']] ?? ''))) : '';
        $out[in_array($d, ['', 'все', 'вся сеть', '*'], true) ? '*' : $d] = $v;
    }
    return $out;
}

function school_build(array $s, SheetSource $src, string $dir, array &$warn): array {
    $now = new DateTimeImmutable((!empty($s['dev_csv_dir']) && !empty($s['dev_now'])) ? $s['dev_now'] : 'now');
    $yday = $now->modify('-1 day');
    $cur = $yday->format('Y-m'); $prev = month_add($cur, -1);
    $id = spreadsheet_id((string)$s['school_url']);
    $meta = ['today' => $now->format('Y-m-d'), 'loadedAt' => $now->format('c'), 'cur' => $cur, 'curN' => month_days($cur), 'prev' => $prev, 'prevN' => month_days($prev),
             'sheets' => ['funnel' => $s['school_funnel_sheet'], 'plan' => $s['school_plan_sheet'], 'ref' => $s['school_ref_sheet']]];
    if (!$id) {
        return ['payload' => ['meta' => $meta + ['configured' => false], 'schools' => [], 'refs' => new stdClass()], 'sources' => [],
                'summary' => ['Источник' => 'не указан - отчёт показывает инструкцию по подготовке таблицы']];
    }
    $planId = spreadsheet_id((string)$s['school_plan_url']) ?: $id;
    $refId = spreadsheet_id((string)$s['school_ref_url']) ?: $id;
    $fRows = $src->values($id, (string)$s['school_funnel_sheet'], SCHOOL_COLS);
    [$schools, $lastDay] = school_parse_funnel($fRows, $cur, $prev, (string)$s['school_funnel_sheet'], $warn);
    $sources = ['Воронка' => ['kind' => 'live', 'id' => $id, 'rows' => count($fRows)]];
    $plan = []; $refs = [];
    try {
        $pRows = $src->values($planId, (string)$s['school_plan_sheet'], SCHOOL_COLS);
        $plan = school_parse_plan($pRows, $cur, (string)$s['school_plan_sheet'], $warn);
        $sources['План'] = ['kind' => 'live', 'id' => $planId, 'rows' => count($pRows)];
    } catch (Throwable $e) { $warn[] = 'План не загружен: ' . $e->getMessage(); }
    try {
        $rRows = $src->values($refId, (string)$s['school_ref_sheet'], SCHOOL_COLS);
        $refs = school_parse_ref($rRows, (string)$s['school_ref_sheet'], $warn);
        $sources['Эталон'] = ['kind' => 'live', 'id' => $refId, 'rows' => count($rRows)];
    } catch (Throwable $e) { $warn[] = 'Эталон не загружен: ' . $e->getMessage(); }
    $noFunnel = 0;
    foreach ($plan as $k => $p) { if (isset($schools[$k])) $schools[$k]['plan'] = $p; else $noFunnel++; }
    if ($noFunnel) $warn[] = "План: {$noFunnel} школ из листа плана нет в воронке (город и направление должны совпадать).";
    // срез: вчера, но не дальше последнего дня, за который в воронке есть данные
    $D = (int)$yday->format('j');
    if ($lastDay && $lastDay < $D) { $warn[] = "В воронке нет данных после {$lastDay} числа - срез сдвинут на {$lastDay}-е."; $D = $lastDay; }
    if (!$schools) $warn[] = 'В воронке нет строк за ' . month_label($cur) . ' и ' . month_label($prev) . '.';
    uasort($schools, function ($a, $b) { return [$a['dir'], $a['city']] <=> [$b['dir'], $b['city']]; });
    $payload = ['meta' => $meta + ['configured' => true, 'D' => max(1, $D), 'asOf' => $cur . '-' . sprintf('%02d', max(1, $D)), 'shifted' => $lastDay && $lastDay < (int)$yday->format('j')],
                'schools' => array_values($schools), 'refs' => $refs ?: new stdClass()];
    return ['payload' => $payload, 'sources' => $sources, 'summary' => [
        'Месяц / срез' => month_label($cur) . ' · по ' . max(1, $D) . '-е число',
        'Школ в воронке / с планом' => count($schools) . ' / ' . count(array_filter($schools, function ($x) { return $x['plan']; })),
        'Эталон' => $refs ? implode(', ', array_map(function ($k) { return $k === '*' ? 'вся сеть' : $k; }, array_keys($refs))) : 'не задан',
    ]];
}

function school_admin(array $s): void { ?>
    <p style="font-size:13px;margin:0 0 10px">Таблица с листами «Воронка» (школа × день), «План» (месяц × школа) и «Эталон» (по направлениям). Формат листов - на странице отчёта, пока ссылка не указана, и в разделе «Методика».</p>
    <div class="field"><label>Ссылка на таблицу воронки</label>
      <input type="url" name="school_url" value="<?= e($s['school_url']) ?>" placeholder="https://docs.google.com/spreadsheets/d/...">
      <span class="hint">Пусто - отчёт не обновляется и показывает инструкцию. Таблица должна быть открыта на чтение для сервисного аккаунта.</span></div>
    <div class="row3">
      <div class="field"><label>Лист воронки</label><input type="text" name="school_funnel_sheet" value="<?= e($s['school_funnel_sheet']) ?>"></div>
      <div class="field"><label>Лист плана</label><input type="text" name="school_plan_sheet" value="<?= e($s['school_plan_sheet']) ?>"></div>
      <div class="field"><label>Лист эталона</label><input type="text" name="school_ref_sheet" value="<?= e($s['school_ref_sheet']) ?>"></div>
    </div>
    <div class="row3">
      <div class="field"><label>План - в другой таблице (необязательно)</label><input type="url" name="school_plan_url" value="<?= e($s['school_plan_url']) ?>"></div>
      <div class="field"><label>Эталон - в другой таблице (необязательно)</label><input type="url" name="school_ref_url" value="<?= e($s['school_ref_url']) ?>"></div>
    </div>
<?php }
function school_admin_save(array $p, array $s): array {
    $msgs = [];
    foreach (['school_url' => 'воронки', 'school_plan_url' => 'плана', 'school_ref_url' => 'эталона'] as $k => $what) {
        $u = trim((string)($p[$k] ?? ''));
        if ($u !== '' && !spreadsheet_id($u)) { $msgs[] = ['err', 'Ссылка на таблицу ' . $what . ' не похожа на Google-таблицу.']; continue; }
        $s[$k] = $u;
    }
    $s['school_funnel_sheet'] = trim((string)($p['school_funnel_sheet'] ?? '')) ?: 'Воронка';
    $s['school_plan_sheet'] = trim((string)($p['school_plan_sheet'] ?? '')) ?: 'План';
    $s['school_ref_sheet'] = trim((string)($p['school_ref_sheet'] ?? '')) ?: 'Эталон';
    return [$s, $msgs];
}

return [
    'id' => 'school',
    'title' => 'Экономика школы',
    'group' => 'Выручка',
    'desc' => 'Воронка продаж школы, план и потерянная выручка',
    'order' => 15,
    'data_var' => 'SCHOOL_DATA',
    'css' => 'view.css',
    'defaults' => [
        'school_url' => '',
        'school_funnel_sheet' => 'Воронка',
        'school_plan_sheet' => 'План',
        'school_ref_sheet' => 'Эталон',
        'school_plan_url' => '',
        'school_ref_url' => '',
    ],
    'build' => 'school_build',
    'admin' => 'school_admin',
    'admin_save' => 'school_admin_save',
];
