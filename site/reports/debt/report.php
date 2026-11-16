<?php
/* Отчёт «Задолженность партнёров по роялти»: загрузка реестра, нормализация, связь со статусом школ из отчёта по выручке. */
defined('APP_ROOT') || exit;

const DEBT_COLS = 'A1:R';
// Страны зарубежных городов - запасной вариант, если данные отчёта по выручке ещё не загружены.
const DEBT_FOREIGN = [
    'Алматы' => 'Казахстан', 'Астана' => 'Казахстан', 'Шымкент' => 'Казахстан', 'Бишкек' => 'Киргизия', 'Ташкент' => 'Узбекистан',
    'Душанбе' => 'Таджикистан', 'Минск' => 'Беларусь', 'Брест' => 'Беларусь', 'Витебск' => 'Беларусь', 'Гомель' => 'Беларусь',
    'Гродно' => 'Беларусь', 'Кишинев' => 'Молдова', 'Тбилиси' => 'Грузия', 'Батуми' => 'Грузия', 'Стамбул' => 'Турция',
    'Барселона' => 'Испания', 'Валенсия' => 'Испания', 'Милан' => 'Италия', 'Прага' => 'Чехия', 'Братислава' => 'Словакия',
    'Лима' => 'Перу', 'Мехико' => 'Мексика',
];

/** «2023_1» -> индекс месяца (год*12 + месяц - 1). */
function debt_mi($v): ?int {
    if (preg_match('/^\s*(\d{4})[_.\-](\d{1,2})\s*$/', (string)$v, $m) && (int)$m[2] >= 1 && (int)$m[2] <= 12) return (int)$m[1] * 12 + (int)$m[2] - 1;
    return null;
}
function debt_norm_dir(string $d): string {
    $d = norm_space($d);
    return mb_strtolower($d, 'UTF-8') === 'гитара и вокал' ? 'Гитара и Вокал' : $d;
}

/** Разбор листа реестра. $rev - данные отчёта по выручке (для страны и статуса школы) или null. */
function debt_parse(array $rows, ?array $rev, int $curMi, int $tol, array &$warn): array {
    $hi = null; $hdr = [];
    for ($i = 0; $i < min(20, count($rows)); $i++) {
        $l = array_map(function ($x) { return mb_strtolower(norm_space((string)$x), 'UTF-8'); }, $rows[$i]);
        if (in_array('тип начисления', $l, true) && in_array('задолженность', $l, true)) { $hi = $i; $hdr = $l; break; }
    }
    if ($hi === null) throw new RuntimeException('В листе не найдена строка заголовков (ожидаются «ТИП НАЧИСЛЕНИЯ» и «ЗАДОЛЖЕННОСТЬ»).');
    $find = function (callable $pred, int $fallback) use ($hdr) { foreach ($hdr as $k => $h) if ($pred($h)) return $k; return $fallback; };
    $ix = [
        'p'    => $find(function ($h) { return $h === 'партнер' || $h === 'партнёр'; }, 1),
        'c'    => $find(function ($h) { return $h === 'город'; }, 2),
        'd'    => $find(function ($h) { return $h === 'направление'; }, 3),
        'm'    => $find(function ($h) { return $h === 'месяц начисления'; }, 4),
        't'    => $find(function ($h) { return $h === 'тип начисления'; }, 5),
        'a'    => $find(function ($h) { return strpos($h, 'сумма начисления') === 0; }, 6),
        'debt' => $find(function ($h) { return $h === 'задолженность'; }, 7),
        'cm'   => $find(function ($h) { return $h === 'комментарий'; }, 8),
        'pm'   => $find(function ($h) { return $h === 'месяц оплаты'; }, 9),
        'paid' => $find(function ($h) { return strpos($h, 'сумма оплаты') === 0; }, 10),
        'wo'   => $find(function ($h) { return $h === 'списание задолженности'; }, 12),
    ];
    foreach (['m' => [4, 'E', 'Месяц начисления'], 'a' => [6, 'G', 'Сумма начисления'], 'debt' => [7, 'H', 'Задолженность'], 'pm' => [9, 'J', 'Месяц оплаты']] as $k => [$pos, $col, $name])
        if ($ix[$k] !== $pos) $warn[] = "Столбец «{$name}» найден не в {$col}, а в №" . ($ix[$k] + 1) . ' - структура листа изменилась, проверьте отчёт.';

    // справочник из отчёта по выручке
    $country = DEBT_FOREIGN; $status = [];
    if ($rev && !empty($rev['schools'])) {
        foreach ($rev['schools'] as $sc) {
            $c = trim(preg_replace('/,.*$/u', '', $sc['c']));   // «Пушкин, Санкт-Петербург» -> «Пушкин»
            if ($sc['co'] !== 'Россия' || !isset($country[$c])) $country[$c] = $sc['co'];
            $nm = debt_nm(preg_replace('/,[^-]*-/u', '-', $sc['n'], 1));
            $status[debt_key($c, $sc['d'])][$nm] = (bool)$sc['cl'];
        }
    } else {
        $warn[] = 'Нет данных отчёта по выручке - статус школы «закрыта» не определён, страны взяты из встроенного списка.';
    }
    $schoolStatus = function (string $city, string $d) use ($status) {
        if (preg_match('/^(.*?)\s+(\d+)$/u', $city, $m)) { $base = $m[1]; $n = (int)$m[2]; } else { $base = $city; $n = 1; }
        $sch = $status[debt_key($base, $d)] ?? null;
        if (!$sch) return 'нет данных';
        $name = debt_nm($base . '-' . str_replace('Барабаны', 'Барабны', $d) . '-' . $n);
        if (isset($sch[$name])) return $sch[$name] ? 'закрыта' : 'работает';
        return count(array_filter($sch)) === count($sch) ? 'закрыта' : 'работает';
    };

    $schools = []; $sidx = []; $partnerMi = []; $out = []; $comments = []; $cidx = []; $other = []; $skipped = 0;
    $n = count($rows);
    for ($i = $hi + 1; $i < $n; $i++) {
        $r = $rows[$i];
        $typ = norm_space((string)($r[$ix['t']] ?? ''));
        if ($typ !== 'Роялти') {
            if ($typ !== '') $other[$typ] = ($other[$typ] ?? 0) + parse_num($r[$ix['debt']] ?? 0);
            continue;
        }
        $mi = debt_mi($r[$ix['m']] ?? '');
        if ($mi === null) { $skipped++; continue; }
        $city = norm_space((string)($r[$ix['c']] ?? '')); $d = debt_norm_dir((string)($r[$ix['d']] ?? ''));
        if ($city === '') { $skipped++; continue; }
        $key = $city . '|' . $d;
        if (!isset($sidx[$key])) {
            $base = preg_replace('/\s+\d+$/u', '', $city);
            $co = $country[$base] ?? 'Россия';
            $sidx[$key] = count($schools);
            $schools[] = ['c' => $city, 'd' => $d, 'p' => '', 'co' => $co, 'reg' => $co === 'Россия' ? 'ru' : 'foreign', 'st' => $schoolStatus($city, $d)];
            $partnerMi[] = -1;
        }
        $si = $sidx[$key];
        $partner = norm_space((string)($r[$ix['p']] ?? ''));
        if ($partner !== '' && $mi >= $partnerMi[$si]) { $schools[$si]['p'] = $partner; $partnerMi[$si] = $mi; }
        $cm = norm_space((string)($r[$ix['cm']] ?? ''));
        $ci = -1;
        if ($cm !== '' && mb_strtolower($cm, 'UTF-8') !== 'курсовая разница') {
            if (!isset($cidx[$cm])) { $cidx[$cm] = count($comments); $comments[] = $cm; }
            $ci = $cidx[$cm];
        }
        $out[] = [$si, $mi, round(parse_num($r[$ix['a']] ?? 0), 2), round(parse_num($r[$ix['debt']] ?? 0), 2),
                  round(parse_num($r[$ix['paid']] ?? 0), 2), debt_mi($r[$ix['pm']] ?? ''), round(parse_num($r[$ix['wo']] ?? 0), 2), $ci];
    }
    if (!$out) throw new RuntimeException('В реестре нет строк с типом начисления «Роялти».');
    if ($skipped) $warn[] = "Пропущено строк роялти без месяца начисления или города: {$skipped}.";
    $overdue = 0.0;
    foreach ($out as $r) if ($r[3] > $tol && $r[1] + 1 < $curMi) $overdue += $r[3];
    return ['schools' => $schools, 'rows' => $out, 'comments' => $comments,
            'other' => array_filter(array_map('round', $other), function ($v) { return abs($v) > 0.5; }), 'overdue' => $overdue];
}

/** Название школы для сравнения: без пробелов, регистра и разницы ё/е. */
function debt_nm(string $s): string { return str_replace(['ё', ' '], ['е', ''], mb_strtolower($s, 'UTF-8')); }

/** Ключ сопоставления школы: «москва 2|барабаны» (регистр, ё/е и пробелы не важны). */
function debt_key(string $city, string $dir): string {
    $f = function ($x) { return str_replace('ё', 'е', mb_strtolower(norm_space($x), 'UTF-8')); };
    return $f($city) . '|' . $f(str_replace('Барабны', 'Барабаны', $dir));
}

/**
 * Проверка начисления роялти: выручка и возвраты школы из отчёта по выручке против начисленного роялти в реестре.
 * Возвращает последние 6 месяцев (до последнего месяца с начислениями) и строки по школам.
 */
function debt_revcheck(?array $rev, array $schools, array $rows, int $curMi, array &$warn): array {
    if (!$rev || empty($rev['monthly'])) return ['months' => [], 'rows' => [], 'note' => 'нет данных отчёта по выручке'];
    $ymOf = function (int $mi) { return sprintf('%04d-%02d', intdiv($mi, 12), $mi % 12 + 1); };
    $last = -1;
    foreach ($rows as $r) if ($r[1] < $curMi && $r[1] > $last && isset($rev['monthly'][$ymOf($r[1])])) $last = $r[1];
    if ($last < 0) return ['months' => [], 'rows' => [], 'note' => 'нет общих месяцев в реестре и отчёте по выручке'];
    $months = [];
    for ($mi = $last - 5; $mi <= $last; $mi++) if (isset($rev['monthly'][$ymOf($mi)])) $months[$mi] = $ymOf($mi);
    $refunds = (array)($rev['refunds'] ?? []);

    // школы реестра: ключ -> индекс; и по «базовому» городу без номера (для запасного сопоставления)
    $byKey = []; $byBase = [];
    foreach ($schools as $si => $sc) {
        $byKey[debt_key($sc['c'], $sc['d'])] = $si;
        $byBase[debt_key(preg_replace('/\s+\d+$/u', '', $sc['c']), $sc['d'])][] = $si;
    }
    // начисления по школе и месяцу
    $acc = [];
    foreach ($rows as $r) if (isset($months[$r[1]])) $acc[$r[0]][$months[$r[1]]] = ($acc[$r[0]][$months[$r[1]]] ?? 0) + $r[2];

    $out = []; $revByBase = [];
    foreach ($rev['schools'] as $i => $sc) {
        $city = trim(preg_replace('/,.*$/u', '', $sc['c']));
        $num = preg_match('/-(\d+)\s*$/u', $sc['n'], $m) ? (int)$m[1] : 1;
        $revByBase[debt_key($city, $sc['d'])][] = $i;
        $v = []; $any = false;
        foreach ($months as $ym) {
            $x = $rev['monthly'][$ym][$i] ?? null;
            if ($x !== null) { $v[$ym] = [round($x, 2), isset($refunds[$ym]) ? $refunds[$ym][$i] : null, null]; if ($x > 0) $any = true; }
        }
        $out['r' . $i] = ['ri' => $i, 'key' => debt_key($num > 1 ? $city . ' ' . $num : $city, $sc['d']), 'base' => debt_key($city, $sc['d']), 'v' => $v, 'any' => $any];
    }
    // сопоставление: точный ключ, затем «город без номера + направление», если с обеих сторон школа одна
    // проход 1: точное совпадение города (с номером школы) и направления
    $match = []; $used = [];
    foreach ($out as $k => $o) {
        $si = $byKey[$o['key']] ?? null;
        if ($si !== null && !isset($used[$si])) { $match[$k] = $si; $used[$si] = true; }
    }
    // проход 2: если у города и направления в отчёте по выручке одна школа, а у найденной записи реестра нет начислений
    // за период (или записи нет) - берём запись реестра с тем же городом без номера/с другим номером, у которой начисления есть
    foreach ($out as $k => $o) {
        if (count($revByBase[$o['base']] ?? []) !== 1) continue;
        $cur = $match[$k] ?? null;
        if ($cur !== null && !empty($acc[$cur])) continue;
        foreach ($byBase[$o['base']] ?? [] as $x) {
            if (isset($used[$x]) || empty($acc[$x])) continue;
            if ($cur !== null) unset($used[$cur]);
            $match[$k] = $x; $used[$x] = true; break;
        }
    }
    $res = [];
    foreach ($out as $k => $o) {
        $si = $match[$k] ?? null;
        if ($si === null && !$o['any']) continue;            // нет ни начислений, ни выручки
        $rs = $rev['schools'][$o['ri']];
        $row = $si !== null
            ? ['si' => $si, 'c' => $schools[$si]['c'], 'd' => $schools[$si]['d'], 'p' => $schools[$si]['p'], 'reg' => $schools[$si]['reg'], 'st' => $schools[$si]['st']]
            : ['si' => -1, 'c' => trim(preg_replace('/,.*$/u', '', $rs['c'])) . (preg_match('/-(\d+)\s*$/u', $rs['n'], $m) && (int)$m[1] > 1 ? ' ' . $m[1] : ''),
               'd' => str_replace('Барабны', 'Барабаны', $rs['d']), 'p' => $rs['p'], 'reg' => $rs['co'] === 'Россия' ? 'ru' : 'foreign', 'st' => $rs['cl'] ? 'закрыта' : 'работает'];
        $row['rn'] = $rs['n'];
        $row['own'] = !empty($rs['own']);   // Собственная сеть (партнёр «УК» в отчёте по выручке): роялти не начисляется
        foreach ($months as $ym) {
            $x = $o['v'][$ym] ?? [null, null, null];
            $x[2] = $si !== null && isset($acc[$si][$ym]) ? round($acc[$si][$ym], 2) : null;
            $row['v'][$ym] = $x;
        }
        $res[] = $row;
    }
    // начисления без выручки (школы реестра, не найденные в отчёте по выручке)
    $nf = 0;
    foreach ($acc as $si => $am) {
        if (isset($used[$si])) continue;
        $nf++;
        $row = ['si' => $si, 'c' => $schools[$si]['c'], 'd' => $schools[$si]['d'], 'p' => $schools[$si]['p'], 'reg' => $schools[$si]['reg'], 'st' => $schools[$si]['st'], 'rn' => '', 'own' => false];
        foreach ($months as $ym) $row['v'][$ym] = [null, null, isset($am[$ym]) ? round($am[$ym], 2) : null];
        $res[] = $row;
    }
    if ($nf) $warn[] = "Проверка начислений: {$nf} школ реестра не найдены в отчёте по выручке (сравнение по ним невозможно).";
    return ['months' => array_values($months), 'rows' => $res];
}

function debt_build(array $s, SheetSource $src, string $dir, array &$warn): array {
    $id = spreadsheet_id((string)$s['debt_url']);
    if (!$id) throw new RuntimeException('Не указана ссылка на реестр задолженности.');
    $now = new DateTimeImmutable((!empty($s['dev_csv_dir']) && !empty($s['dev_now'])) ? $s['dev_now'] : 'now');
    $curMi = (int)$now->format('Y') * 12 + (int)$now->format('n') - 1;
    $tol = max(0, (int)$s['debt_tol']);
    $rev = read_json(report_data_file('revenue'));
    $rows = $src->values($id, (string)$s['debt_sheet'], DEBT_COLS);
    $p = debt_parse($rows, is_array($rev) ? $rev : null, $curMi, $tol, $warn);
    $payload = [
        'meta' => ['asOf' => $now->format('Y-m-d'), 'curMi' => $curMi, 'loadedAt' => $now->format('c'), 'tol' => $tol, 'mal' => max(2, (int)$s['debt_mal']),
                   'otherTypesDebt' => $p['other'], 'source' => 'лист «' . $s['debt_sheet'] . '»',
                   'revenueAt' => is_array($rev) ? ($rev['meta']['generatedAt'] ?? null) : null],
        'schools' => $p['schools'], 'rows' => $p['rows'], 'comments' => $p['comments'],
        'check' => debt_revcheck(is_array($rev) ? $rev : null, $p['schools'], $p['rows'], $curMi, $warn),
    ];
    return [
        'payload' => $payload,
        'sources' => ['Реестр задолженности' => ['kind' => 'live', 'id' => $id, 'rows' => count($rows)]],
        'summary' => [
            'Строк роялти / школ' => count($p['rows']) . ' / ' . count($p['schools']),
            'Просроченный долг' => number_format($p['overdue'], 0, ',', ' ') . ' ₽',
            'Статус школ' => is_array($rev) ? 'по отчёту выручки от ' . date('d.m.Y H:i', strtotime($rev['meta']['generatedAt'])) : 'нет данных отчёта по выручке',
        ],
    ];
}

function debt_admin(array $s): void { ?>
    <div class="field"><label>Ссылка на реестр задолженности</label>
      <input type="url" name="debt_url" required value="<?= e($s['debt_url']) ?>">
      <span class="hint">Файл должен быть открыт на чтение для сервисного аккаунта.</span></div>
    <div class="row3">
      <div class="field"><label>Лист реестра</label><input type="text" name="debt_sheet" value="<?= e($s['debt_sheet']) ?>"></div>
      <div class="field"><label>Злостный должник: неоплаченных месяцев от</label><input type="number" name="debt_mal" min="2" max="24" value="<?= e($s['debt_mal']) ?>"><span class="hint">Не обязательно подряд.</span></div>
      <div class="field"><label>Остаток, который считается погашенным, ₽</label><input type="number" name="debt_tol" min="0" max="10000" value="<?= e($s['debt_tol']) ?>"><span class="hint">Курсовые разницы и копейки.</span></div>
    </div>
<?php }
function debt_admin_save(array $p, array $s): array {
    $url = trim((string)($p['debt_url'] ?? ''));
    if (!spreadsheet_id($url)) return [$s, [['err', 'Ссылка на реестр не похожа на Google-таблицу.']]];
    $s['debt_url'] = $url;
    $s['debt_sheet'] = trim((string)($p['debt_sheet'] ?? '')) ?: 'Реестр задолженности СМОТРЕТЬ СЮДА';
    $s['debt_mal'] = max(2, min(24, (int)($p['debt_mal'] ?? 3)));
    $s['debt_tol'] = max(0, min(10000, (int)($p['debt_tol'] ?? 100)));
    return [$s, []];
}

return [
    'id' => 'debt',
    'title' => 'Задолженность по роялти',
    'group' => 'Партнёры',
    'desc' => 'Неплатежи, просрочки, злостные должники, возврат долгов',
    'order' => 20,                 // после выручки: использует её данные о статусе школ
    'data_var' => 'DEBT_DATA',
    'css' => 'view.css',
    'defaults' => [
        'debt_url' => 'https://docs.google.com/spreadsheets/d/1DQRt80rG67_Z2CZXs3DYGOAwDlWojJ72fzVpCEJMJVg/edit',
        'debt_sheet' => 'Реестр задолженности СМОТРЕТЬ СЮДА',
        'debt_mal' => 3,
        'debt_tol' => 100,
    ],
    'build' => 'debt_build',
    'admin' => 'debt_admin',
    'admin_save' => 'debt_admin_save',
];
