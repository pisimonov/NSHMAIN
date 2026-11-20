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
            $out[$k] = ['id' => $k, 'city' => $city, 'dir' => str_replace('Барабны', 'Барабаны', $dir), 'plan' => null, 'tm' => null];
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

/** «Ижевск-Барабны-1» -> ['Ижевск', 'Барабаны']; «Москва-Кидс-5» -> ['Москва 5', 'Кидс']; город с дефисом сохраняется. */
function school_split_name(string $n): ?array {
    $p = array_map(function ($x) { return norm_space(str_replace("\u{00A0}", ' ', $x)); }, explode('-', $n));
    if (count($p) < 3) return null;
    $num = array_pop($p); $dir = array_pop($p); $city = implode('-', $p);
    if (!ctype_digit($num) || $city === '' || $dir === '') return null;
    return [$city . ((int)$num > 1 ? ' ' . (int)$num : ''), str_replace('Барабны', 'Барабаны', $dir)];
}
/**
 * Лист «РГ + ТМ» таблицы «Статус продажи <месяц>» (столбцы A-F).
 * Строка с ФИО ТМ (только в B) открывает группу; дальше по 3 строки на школу:
 * «- | Школа | КМ | План | Факт | %», «Школа | - | Выручка | план | факт | %», «Школа | - | Лиды | план | факт | %».
 * Возвращает [ключ школы => ['name', 'tm', 'rev', 'leads']]. Пустые блоки без названия школы пропускаются.
 */
function school_parse_rgtm(array $rows, int $firstRow, string $sheet, array &$warn): array {
    $txt = function ($v) { return norm_space(str_replace("\u{00A0}", ' ', (string)$v)); };
    $out = []; $tm = null; $badNames = [];
    foreach (array_slice($rows, max(0, $firstRow - 2)) as $row) {          // строка над первой школой - ТМ первой группы
        [$a, $b, $c, $d] = [$txt($row[0] ?? ''), $txt($row[1] ?? ''), school_norm((string)($row[2] ?? '')), school_norm((string)($row[3] ?? ''))];
        if ($b !== '' && $a === '' && $c === '' && $d === '') { $tm = preg_match('/^без\s*тм/iu', $b) ? null : $b; continue; }
        if ($b !== '' && $a === '' && $d === 'план') {
            $sp = school_split_name($b);
            if (!$sp) { $badNames[] = $b; continue; }
            $out[school_key($sp[0], $sp[1])] = ['name' => $b, 'tm' => $tm, 'rev' => null, 'leads' => null];
            continue;
        }
        if ($a !== '' && ($c === 'выручка' || $c === 'лиды')) {
            $sp = school_split_name($a);
            if (!$sp) continue;
            $k = school_key($sp[0], $sp[1]);
            if (isset($out[$k])) $out[$k][$c === 'выручка' ? 'rev' : 'leads'] = ($row[3] ?? '') === '' ? null : parse_num($row[3]);
        }
    }
    if ($badNames) $warn[] = "Лист «{$sheet}»: не разобраны названия школ (ожидается «Город-Направление-номер»): " . implode(', ', array_slice($badNames, 0, 10)) . '.';
    if (!$out) $warn[] = "Лист «{$sheet}»: не найдено ни одной школы - проверьте номер первой строки данных в настройках.";
    return $out;
}
/** Планы и ТМ текущего месяца из таблицы «Статус продажи»: [план по ключу школы, источник] или [[], null]. */
function school_month_plan(array $s, SheetSource $src, string $cur, array &$warn): array {
    $urls = (array)$s['school_month_urls'];
    $mid = spreadsheet_id((string)($urls[$cur] ?? ''));
    if (!$mid) {
        if ($urls) $warn[] = 'Нет ссылки на таблицу «Статус продажи» за ' . month_label($cur) . ' - добавьте её в «Настройках» (Экономика школы → таблицы по месяцам).';
        return [[], null];
    }
    try {
        $rows = $src->values($mid, (string)$s['school_month_sheet'], 'A1:F');
    } catch (Throwable $e) { $warn[] = 'Планы за ' . month_label($cur) . ' не загружены: ' . $e->getMessage(); return [[], null]; }
    $plan = school_parse_rgtm($rows, (int)$s['school_month_first_row'], (string)$s['school_month_sheet'], $warn);
    return [$plan, ['kind' => 'live', 'id' => $mid, 'rows' => count($rows)]];
}

/** Воронка по дням из файлов выручки (funnel.json отчёта «Накопленная выручка месяца»). */
function school_from_revenue(array $fun): array {
    $out = [];
    $n = ['cur' => month_days($fun['cur']), 'prev' => month_days($fun['prev'])];
    foreach ($fun['schools'] as $name => $x) {
        $sp = school_split_name((string)$name) ?: [norm_space((string)$x['c']), str_replace('Барабны', 'Барабаны', norm_space((string)$x['d']))];
        $k = school_key($sp[0], $sp[1]);
        $row = ['id' => $k, 'city' => $sp[0], 'dir' => $sp[1], 'name' => (string)$name, 'co' => $x['co'] ?? '', 'closed' => !empty($x['cl']), 'plan' => null, 'tm' => null];
        foreach (['cur', 'prev'] as $p) foreach (SCHOOL_METRICS as $m) $row[$p][$m] = isset($x[$p][$m]) ? array_map('floatval', $x[$p][$m]) : array_fill(0, $n[$p], 0.0);
        $out[$k] = $row;
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
    // воронка: отдельная таблица, если указана в настройках; иначе - файлы отчёта по выручке
    $fun = $id ? null : read_json(report_dir('revenue') . '/funnel.json');
    $useRev = is_array($fun) && !empty($fun['schools']) && ($fun['cur'] ?? '') === $cur;
    if (is_array($fun) && !$useRev && !empty($fun['cur'])) $warn[] = 'Воронка из файлов выручки - за ' . month_label($fun['cur']) . ', а нужен ' . month_label($cur) . ': сначала обновите отчёт «Накопленная выручка месяца».';
    [$mPlan, $mSrc] = school_month_plan($s, $src, $cur, $warn);
    $mSources = $mSrc ? ['Планы и ТМ: ' . month_label($cur) => $mSrc] : [];
    $mSummary = ['Планы и ТМ за ' . month_label($cur) => $mSrc ? count($mPlan) . ' школ, ТМ: ' . count(array_unique(array_filter(array_column($mPlan, 'tm')))) : 'нет таблицы за месяц'];
    if (!$useRev && !$id) {
        return ['payload' => ['meta' => $meta + ['configured' => false, 'planSchools' => count($mPlan)], 'schools' => [], 'refs' => new stdClass()], 'sources' => $mSources,
                'summary' => ['Воронка' => 'нет данных: обновите отчёт «Накопленная выручка месяца»'] + $mSummary];
    }
    $planId = spreadsheet_id((string)$s['school_plan_url']) ?: $id;
    $refId = spreadsheet_id((string)$s['school_ref_url']) ?: $id;
    if ($useRev) {
        $schools = school_from_revenue($fun);
        $lastDay = substr((string)$fun['asOf'], 0, 7) === $cur ? (int)substr((string)$fun['asOf'], 8, 2) : 0;
        $sources = ['Воронка: файлы выручки' => ['kind' => 'cache', 'id' => 'отчёт «Накопленная выручка месяца»', 'rows' => count($schools), 'at' => $fun['generatedAt'] ?? null]] + $mSources;
        if (empty($fun['has']['prev'])) $warn[] = 'Воронки за ' . month_label($prev) . ' нет - сравнение с прошлым месяцем будет пустым.';
    } else {
        $fRows = $src->values($id, (string)$s['school_funnel_sheet'], SCHOOL_COLS);
        [$schools, $lastDay] = school_parse_funnel($fRows, $cur, $prev, (string)$s['school_funnel_sheet'], $warn);
        $sources = ['Воронка' => ['kind' => 'live', 'id' => $id, 'rows' => count($fRows)]] + $mSources;
    }
    $plan = $mPlan; $refs = [];
    if (!$mPlan && $planId) {        // запасной вариант - лист «План» в таблице воронки
        try {
            $pRows = $src->values($planId, (string)$s['school_plan_sheet'], SCHOOL_COLS);
            $plan = school_parse_plan($pRows, $cur, (string)$s['school_plan_sheet'], $warn);
            $sources['План'] = ['kind' => 'live', 'id' => $planId, 'rows' => count($pRows)];
        } catch (Throwable $e) { $warn[] = 'План не загружен: ' . $e->getMessage(); }
    }
    if ($refId) try {            // эталон пока не показывается; читается, только если задан отдельный источник
        $rRows = $src->values($refId, (string)$s['school_ref_sheet'], SCHOOL_COLS);
        $refs = school_parse_ref($rRows, (string)$s['school_ref_sheet'], $warn);
        $sources['Эталон'] = ['kind' => 'live', 'id' => $refId, 'rows' => count($rRows)];
    } catch (Throwable $e) { $warn[] = 'Эталон не загружен: ' . $e->getMessage(); }
    $noFunnel = 0;
    foreach ($plan as $k => $p) {
        if (!isset($schools[$k])) { $noFunnel++; continue; }
        $schools[$k]['tm'] = $p['tm'] ?? null;
        if (($p['rev'] ?? 0) > 0 || ($p['leads'] ?? 0) > 0) $schools[$k]['plan'] = ['leads' => (float)($p['leads'] ?? 0), 'rev' => (float)($p['rev'] ?? 0)];
    }
    if ($noFunnel) $warn[] = "План: {$noFunnel} школ из листа плана нет в воронке (город и направление должны совпадать).";
    // срез: вчера, но не дальше последнего дня, за который в воронке есть данные
    $D = (int)$yday->format('j');
    if ($lastDay && $lastDay < $D) { $warn[] = "В воронке нет данных после {$lastDay} числа - срез сдвинут на {$lastDay}-е."; $D = $lastDay; }
    if (!$schools) $warn[] = 'В воронке нет строк за ' . month_label($cur) . ' и ' . month_label($prev) . '.';
    $meta['funnelSrc'] = $useRev ? 'revenue' : 'sheet';
    $meta['planSheet'] = $s['school_month_sheet'];
    // в списке - школы с активностью в текущем или прошлом месяце либо с планом (закрытые и пустые строки файла выручки - нет)
    $active = function ($x) { foreach (['cur', 'prev'] as $p) foreach (['L', 'NR', 'OR'] as $m) if (array_sum($x[$p][$m]) > 0) return true; return !empty($x['plan']); };
    $schools = array_filter($schools, $active);
    uasort($schools, function ($a, $b) { return [$a['dir'], $a['city']] <=> [$b['dir'], $b['city']]; });
    $payload = ['meta' => $meta + ['configured' => true, 'D' => max(1, $D), 'asOf' => $cur . '-' . sprintf('%02d', max(1, $D)), 'shifted' => $lastDay && $lastDay < (int)$yday->format('j')],
                'schools' => array_values($schools), 'refs' => $refs ?: new stdClass()];
    return ['payload' => $payload, 'sources' => $sources, 'summary' => [
        'Месяц / срез' => month_label($cur) . ' · по ' . max(1, $D) . '-е число',
        'Школ в воронке / с планом' => count($schools) . ' / ' . count(array_filter($schools, function ($x) { return $x['plan']; })),
    ] + $mSummary + [
        'Эталон' => $refs ? implode(', ', array_map(function ($k) { return $k === '*' ? 'вся сеть' : $k; }, array_keys($refs))) : 'не задан',
    ]];
}

/** Первые строки листа месячной таблицы «Статус продажи» - чтобы увидеть его структуру прямо в настройках. */
function school_month_preview(array $s, string $ym): void {
    $url = ((array)$s['school_month_urls'])[$ym] ?? '';
    $id = spreadsheet_id((string)$url);
    echo '<div class="flash" style="margin:10px 0"><b>Лист «' . e($s['school_month_sheet']) . '» таблицы за ' . e(month_label($ym)) . ': строки 1-12</b>';
    if (!$id) { echo '<div>Ссылки на этот месяц нет.</div></div>'; return; }
    try {
        $rows = make_source()->values($id, (string)$s['school_month_sheet'], 'A1:AZ12');
        $w = 0; foreach ($rows as $r) $w = max($w, count($r));
        echo '<div class="tblwrap" style="margin-top:8px;max-height:none"><table><thead><tr><th>#</th>';
        for ($c = 0; $c < $w; $c++) { $l = ''; $n = $c; do { $l = chr(65 + $n % 26) . $l; $n = intdiv($n, 26) - 1; } while ($n >= 0); echo '<th class="l">' . $l . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($rows as $i => $r) {
            echo '<tr><td>' . ($i + 1) . '</td>';
            for ($c = 0; $c < $w; $c++) echo '<td class="l">' . e(mb_substr((string)($r[$c] ?? ''), 0, 40)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div><div class="hint" style="margin-top:6px">Сделайте скриншот или скопируйте эту таблицу в чат - по ней настраивается разбор плана.</div></div>';
    } catch (Throwable $e) {
        echo '<div class="err-t">' . e($e->getMessage()) . '</div><div>Проверьте, что таблица открыта на чтение для сервисного аккаунта (страница «Настройки» → «Доступ к Google»).</div></div>';
    }
}

function school_admin(array $s): void {
    $mu = ''; foreach ((array)$s['school_month_urls'] as $ym => $url) $mu .= $ym . ' ' . $url . "\n"; ?>
    <h3 style="margin:4px 0 6px">Таблицы «Статус продажи» по месяцам (планы школ и ТМ)</h3>
    <p style="font-size:13px;margin:0 0 8px">Каждый месяц создаётся отдельная таблица. Добавляйте строку на каждый новый месяц: месяц и ссылка. С листа ниже берутся планы выручки и лидов по школам (блоки «Школа / Выручка / Лиды», столбец «План») и ФИО территориальных менеджеров (строка с ФИО над группой школ). Читаются только столбцы A-F.</p>
    <div class="field"><label>Месяц и ссылка (по одной в строке)</label>
      <textarea name="school_month_urls" rows="4" placeholder="2026-12 https://docs.google.com/spreadsheets/d/.../edit"><?= e($mu) ?></textarea>
      <span class="hint">Месяц - как 2026-12 или «Декабрь 2026». Каждую таблицу нужно открыть на чтение для сервисного аккаунта.</span></div>
    <div class="row3">
      <div class="field"><label>Лист с планами</label><input type="text" name="school_month_sheet" value="<?= e($s['school_month_sheet']) ?>"></div>
      <div class="field"><label>Первая школа - в строке</label><input type="number" name="school_month_first_row" min="2" max="50" value="<?= e($s['school_month_first_row']) ?>"></div>
    </div>
    <?php if ($s['school_month_urls']): ?>
      <div style="font-size:13px;margin:0 0 10px">Показать первые строки листа:
        <?php foreach (array_reverse(array_keys((array)$s['school_month_urls'])) as $ym): ?>
          <a class="btn" style="margin-left:6px" href="admin.php?school_preview=<?= e($ym) ?>#r-school"><?= e(month_label($ym)) ?></a>
        <?php endforeach; ?>
      </div>
      <?php $pv = (string)($_GET['school_preview'] ?? ''); if (preg_match('/^\d{4}-\d{2}$/', $pv)) school_month_preview($s, $pv); ?>
    <?php endif; ?>
    <h3 style="margin:14px 0 6px">Отдельный источник воронки (не обязательно)</h3>
    <p style="font-size:13px;margin:0 0 8px">Факт воронки берётся из файлов отчёта «Накопленная выручка месяца» (столбцы «Кол-во новых лидов», «Кол-во постановок», «Кол-во встреч», «Кол-во первых продаж», «Новые продажи (рубли)», «Старые продажи (рубли)»). Если указать ссылку ниже, воронка будет браться из этой таблицы вместо файлов выручки.</p>
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
    $errs = [];
    $s['school_month_urls'] = parse_month_links((string)($p['school_month_urls'] ?? ''), $errs);
    foreach ($errs as $er) $msgs[] = ['err', 'Таблицы по месяцам: ' . $er];
    $s['school_month_sheet'] = trim((string)($p['school_month_sheet'] ?? '')) ?: 'РГ + ТМ';
    $s['school_month_first_row'] = max(2, min(50, (int)($p['school_month_first_row'] ?? 7)));
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
        // таблицы «Статус продажи <месяц>»: планы лидов и выручки по школам, ФИО ТМ (лист «РГ + ТМ», данные с 7-й строки)
        'school_month_urls' => [
            '2026-10' => 'https://docs.google.com/spreadsheets/d/1_n-7OmghnRWOCaIYyFLGSUyscuH_8zPOEMdkrytFwtw/edit',
            '2026-11' => 'https://docs.google.com/spreadsheets/d/1Ya6bWo65cgYGWj4FJ7ae7myPBOUTNjZc--JJCk_DuhE/edit',
        ],
        'school_month_sheet' => 'РГ + ТМ',
        'school_month_first_row' => 7,
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
