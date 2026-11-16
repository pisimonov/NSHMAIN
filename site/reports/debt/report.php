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
            $nm = str_replace(' ', '', preg_replace('/,[^-]*-/u', '-', $sc['n'], 1));
            $status[$c . '|' . str_replace('Барабны', 'Барабаны', $sc['d'])][$nm] = (bool)$sc['cl'];
        }
    } else {
        $warn[] = 'Нет данных отчёта по выручке - статус школы «закрыта» не определён, страны взяты из встроенного списка.';
    }
    $schoolStatus = function (string $city, string $d) use ($status) {
        if (preg_match('/^(.*?)\s+(\d+)$/u', $city, $m)) { $base = $m[1]; $n = (int)$m[2]; } else { $base = $city; $n = 1; }
        $sch = $status[$base . '|' . $d] ?? null;
        if (!$sch) return 'нет данных';
        $name = str_replace(' ', '', $base . '-' . str_replace('Барабаны', 'Барабны', $d) . '-' . $n);
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
