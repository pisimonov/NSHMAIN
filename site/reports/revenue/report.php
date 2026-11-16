<?php
/* Отчёт «Накопленная выручка месяца»: загрузка месяцев, кэш архивов, агрегирование, настройки. */
defined('APP_ROOT') || exit;

const REVENUE_COLS = 'A1:AD';  // A Страна ... H Выручка (рубли) ... K Школа, L Партнер, M Закрыта ... «Сумма возвратов» (обычно Z)
const REVENUE_PACK_VER = 2;    // 2 - в кэше месяца есть возвраты по школам

/** Разбор строк листа raw_data_income. */
function revenue_process_values(array $rows): array {
    if (!$rows) throw new RuntimeException('Лист пуст.');
    $hdr = array_map(function ($h) { return mb_strtolower(norm_space((string)$h), 'UTF-8'); }, $rows[0]);
    $want = ['страна' => 0, 'город' => 1, 'направление' => 3, 'дата' => 5, 'выручка (рубли)' => 7, 'школа' => 10, 'партнер' => 11, 'закрыта' => 12];
    $ix = []; $warn = [];
    foreach ($want as $name => $fallback) {
        $pos = array_search($name, $hdr, true);
        if ($pos === false && $name === 'партнер') $pos = array_search('партнёр', $hdr, true);
        if ($pos === false) {
            if (in_array($name, ['дата', 'выручка (рубли)', 'школа'], true)) {
                if (isset($hdr[$fallback]) && $hdr[$fallback] !== '') $warn[] = "Не найден заголовок «{$name}», использую столбец №" . ($fallback + 1) . " («{$hdr[$fallback]}»).";
            }
            $pos = $fallback;
        }
        $ix[$name] = $pos;
    }
    $refIx = array_search('сумма возвратов', $hdr, true);   // нужен для проверки начисления роялти
    $refunds = [];
    $months = []; $meta = []; $seen = []; $dups = []; $count = []; $bad = 0;
    $n = count($rows);
    for ($r = 1; $r < $n; $r++) {
        $row = $rows[$r];
        $name = norm_space((string)($row[$ix['школа']] ?? ''));
        if ($name === '') continue;
        $date = parse_date($row[$ix['дата']] ?? '');
        if ($date === null) { $bad++; continue; }
        $ym = substr($date, 0, 7); $day = (int)substr($date, 8, 2);
        $rev = parse_num($row[$ix['выручка (рубли)']] ?? 0);
        $key = $name . '|' . $date;
        if (isset($seen[$key])) $dups[$ym][$name] = true; else $seen[$key] = true;
        $months[$ym][$name][$day] = ($months[$ym][$name][$day] ?? 0) + $rev;
        if ($refIx !== false) $refunds[$ym][$name] = ($refunds[$ym][$name] ?? 0) + parse_num($row[$refIx] ?? 0);
        $count[$ym] = ($count[$ym] ?? 0) + 1;
        $country = norm_space((string)($row[$ix['страна']] ?? ''));
        $meta[$ym][$name] = [
            'c' => norm_city((string)($row[$ix['город']] ?? ''), $country),
            'co' => $country,
            'd' => norm_space((string)($row[$ix['направление']] ?? '')),
            'p' => norm_space((string)($row[$ix['партнер']] ?? '')),
            'cl' => norm_space((string)($row[$ix['закрыта']] ?? '')) !== '',
        ];
    }
    if (!$count) throw new RuntimeException('В листе нет строк с датой и школой.');
    arsort($count);
    if ($bad) $warn[] = "Пропущено строк без распознанной даты: {$bad}.";
    if ($refIx === false) $warn[] = 'Не найден столбец «Сумма возвратов» - проверка начисления роялти по этому месяцу будет без учёта возвратов.';
    return ['months' => $months, 'meta' => $meta, 'count' => $count, 'dominant' => array_key_first($count), 'dups' => $dups, 'warnings' => $warn,
            'refunds' => $refunds, 'hasRef' => $refIx !== false];
}

/** Компактная упаковка одного месяца для кэша. */
function revenue_pack_month(array $p, string $ym): array {
    $nd = month_days($ym); $maxDay = 0; $schools = [];
    foreach ($p['months'][$ym] as $name => $days) {
        $maxDay = max($maxDay, max(array_keys($days)));
        $v = [];
        for ($d = 1; $d <= $nd; $d++) $v[] = round($days[$d] ?? 0, 2);
        $schools[$name] = $p['meta'][$ym][$name] + ['v' => $v, 'r' => round($p['refunds'][$ym][$name] ?? 0, 2)];
    }
    return ['month' => $ym, 'maxDay' => $maxDay, 'rows' => $p['count'][$ym], 'schools' => $schools, 'ver' => REVENUE_PACK_VER, 'hasRef' => !empty($p['hasRef']),
            'dups' => array_keys($p['dups'][$ym] ?? []), 'warnings' => $p['warnings']];
}

/** Сборка данных отчёта (вызывается из run_report_update). */
function revenue_build(array $s, SheetSource $src, string $dir, array &$warn): array {
    $sources = [];
    // dev_now - только для тестового режима CSV (проверка смены месяца)
    $now = new DateTimeImmutable((!empty($s['dev_csv_dir']) && !empty($s['dev_now'])) ? $s['dev_now'] : 'now');
    $yday = $now->modify('-1 day');
    $cur = $yday->format('Y-m');
    $asOf = $yday->format('Y-m-d');
    $hist = max(1, (int)$s['history_months']);
    $need = []; for ($k = $hist; $k >= 0; $k--) $need[] = month_add($cur, -$k);

    // 1. Живой файл текущего месяца
    $liveId = spreadsheet_id((string)$s['current_url']);
    if (!$liveId) throw new RuntimeException('Не указана ссылка на таблицу текущего месяца.');
    $live = revenue_process_values($src->values($liveId, $s['data_sheet'], REVENUE_COLS));
    $liveMonth = $live['dominant'];
    foreach ($live['warnings'] as $w) $warn[] = 'Текущий файл: ' . $w;
    $packs = [];
    foreach ($live['months'] as $ym => $_) {
        if (!in_array($ym, $need, true) && $ym !== $liveMonth) continue;
        $pk = revenue_pack_month($live, $ym);
        if ($ym === $liveMonth) {
            write_json_atomic(($dir . '/cache/' . $ym . '.json'), ['kind' => 'live', 'id' => $liveId, 'fetchedAt' => date('c'), 'pack' => $pk]);
            $packs[$ym] = $pk; $sources[$ym] = ['kind' => 'live', 'id' => $liveId, 'rows' => $pk['rows']];
        }
    }

    // 2. Кандидаты архива: ручные ссылки + лист «Предыдущие месяца»
    $cands = [];
    foreach ((array)$s['archive_overrides'] as $ym => $url) { $id = spreadsheet_id((string)$url); if ($id && preg_match('/^\d{4}-\d{2}$/', $ym)) $cands[$ym][] = $id; }
    if (!empty($s['read_archive_sheet'])) {
        try {
            foreach ($src->values($liveId, $s['archive_sheet'], 'A1:B300') as $row) {
                $ym = parse_ru_month((string)($row[0] ?? '')); $id = spreadsheet_id((string)($row[1] ?? ''));
                if ($ym && $id && $id !== $liveId) $cands[$ym][] = $id;
            }
        } catch (Throwable $e) { $warn[] = 'Лист «' . $s['archive_sheet'] . '» не прочитан: ' . $e->getMessage(); }
    }
    foreach ($cands as $ym => $ids) $cands[$ym] = array_values(array_unique($ids));

    // 3. Остальные месяцы: кэш или загрузка
    $recentFrom = month_add($cur, -1);
    foreach ($need as $ym) {
        if (isset($packs[$ym])) continue;
        $cache = read_json(($dir . '/cache/' . $ym . '.json'));
        $cacheOk = is_array($cache) && isset($cache['pack']);
        $cacheFresh = $cacheOk && $cache['kind'] === 'archive' && ($cache['pack']['ver'] ?? 1) >= REVENUE_PACK_VER && $ym < $recentFrom && in_array($cache['id'], $cands[$ym] ?? [$cache['id']], true);
        if ($cacheFresh) { $packs[$ym] = $cache['pack']; $sources[$ym] = ['kind' => 'cache', 'id' => $cache['id'], 'rows' => $cache['pack']['rows'], 'at' => $cache['fetchedAt']]; continue; }
        $got = false;
        foreach ($cands[$ym] ?? [] as $id) {
            try {
                $p = revenue_process_values($src->values($id, $s['data_sheet'], REVENUE_COLS));
                if (!isset($p['months'][$ym]) || $p['dominant'] !== $ym) { $warn[] = month_label($ym) . ': файл ' . $id . ' содержит данные за ' . month_label($p['dominant']) . ' - пропущен.'; continue; }
                $pk = revenue_pack_month($p, $ym);
                write_json_atomic(($dir . '/cache/' . $ym . '.json'), ['kind' => 'archive', 'id' => $id, 'fetchedAt' => date('c'), 'pack' => $pk]);
                $packs[$ym] = $pk; $sources[$ym] = ['kind' => 'archive', 'id' => $id, 'rows' => $pk['rows']]; $got = true; break;
            } catch (Throwable $e) { $warn[] = month_label($ym) . ': ' . $e->getMessage(); }
        }
        if (!$got && $cacheOk) {
            $packs[$ym] = $cache['pack']; $sources[$ym] = ['kind' => 'cache', 'id' => $cache['id'], 'rows' => $cache['pack']['rows'], 'at' => $cache['fetchedAt'], 'from' => $cache['kind']];
            if ($cache['kind'] === 'live') $warn[] = month_label($ym) . ': архивный файл не найден, использую последнюю выгрузку текущего файла от ' . date('d.m.Y H:i', strtotime($cache['fetchedAt'])) . '. Добавьте ссылку на архив этого месяца.';
        } elseif (!$got) {
            $warn[] = month_label($ym) . ': нет данных - добавьте ссылку на архив этого месяца в настройках или на лист «' . $s['archive_sheet'] . '».';
        }
    }
    if (!isset($packs[$cur])) throw new RuntimeException('Нет данных за текущий месяц (' . month_label($cur) . '). В текущем файле: ' . month_label($liveMonth) . '.');

    // 4. Сборка
    $payload = revenue_payload($packs, $cur, $asOf, $now, $liveMonth, $s, $warn);
    // месяцы, собранные из последней выгрузки основного файла (архив ещё не подключён) - данные могут быть неполными
    $payload['meta']['stale'] = (object)[];
    foreach ($sources as $ym => $x) if (($x['from'] ?? '') === 'live') $payload['meta']['stale']->$ym = $x['at'];
    return [
        'payload' => $payload,
        'sources' => $sources,
        'summary' => [
            'Текущий месяц / срез' => month_label($cur) . ' · по ' . date('d.m.Y', strtotime($payload['meta']['asOf'])) . ' включительно',
            'В текущем файле' => month_label($liveMonth),
            'Школ' => (string)count($payload['schools']),
        ],
    ];
}

function revenue_payload(array $packs, string $cur, string $asOf, DateTimeImmutable $now, string $liveMonth, array $s, array &$warn): array {
    ksort($packs);
    $pm = month_add($cur, -1); $ly = month_add($cur, -12);
    // единый список школ; атрибуты - из самого свежего месяца
    $meta = [];
    foreach ($packs as $ym => $pk) foreach ($pk['schools'] as $name => $sc) { unset($sc['v'], $sc['r']); $meta[$name] = $sc; }
    $names = array_keys($meta);
    sort($names, SORT_STRING);
    $idx = array_flip($names);
    $ownKey = mb_strtolower(norm_space((string)$s['own_partner']), 'UTF-8');
    $schools = []; $own = [];
    foreach ($names as $n) {
        $m = $meta[$n];
        $isOwn = $ownKey !== '' && mb_strtolower($m['p'], 'UTF-8') === $ownKey;
        if ($isOwn) $own[] = $n;
        $schools[] = ['n' => $n, 'c' => $m['c'], 'co' => $m['co'], 'd' => $m['d'], 'p' => $m['p'], 'cl' => (bool)$m['cl'], 'own' => $isOwn];
    }
    if (!$own) $warn[] = 'Не найдено ни одной школы с партнёром «' . $s['own_partner'] . '» - Собственная сеть будет пустой.';

    $monthly = []; $monthDays = []; $dups = []; $refunds = [];
    foreach ($packs as $ym => $pk) {
        $arr = array_fill(0, count($names), null); $ref = array_fill(0, count($names), null);
        foreach ($pk['schools'] as $n => $sc) {
            $arr[$idx[$n]] = round(array_sum($sc['v']), 2);
            if (!empty($pk['hasRef'])) $ref[$idx[$n]] = round($sc['r'] ?? 0, 2);
        }
        $monthly[$ym] = $arr; $monthDays[$ym] = $pk['maxDay'];
        if (!empty($pk['hasRef'])) $refunds[$ym] = $ref;   // возвраты по школам - для проверки начисления роялти
        if (!empty($pk['dups'])) $dups[] = ['m' => $ym, 'schools' => $pk['dups']];
    }
    // дата среза не позже последнего дня, по которому есть данные
    $curMax = $packs[$cur]['maxDay'];
    if ((int)substr($asOf, 8, 2) > $curMax) {
        $warn[] = 'Данные текущего месяца есть только по ' . $curMax . '-е число - срез сдвинут.';
        $asOf = $cur . '-' . sprintf('%02d', $curMax);
        $shifted = true;
    }
    $daily = [];
    foreach ([$cur, $pm, $ly] as $ym) {
        if (!isset($packs[$ym])) continue;
        $len = $ym === $cur ? $packs[$ym]['maxDay'] : month_days($ym);
        $arr = array_fill(0, count($names), null);
        foreach ($packs[$ym]['schools'] as $n => $sc) $arr[$idx[$n]] = array_slice($sc['v'], 0, $len);
        $daily[$ym] = $arr;
    }
    $today = $now->format('Y-m-d');
    return [
        'meta' => [
            'asOf' => $asOf, 'today' => $today, 'todayInCur' => empty($shifted) && substr($today, 0, 7) === $cur, 'asOfShifted' => !empty($shifted),
            'cur' => $cur, 'pm' => $pm, 'ly' => $ly, 'months' => array_keys($packs), 'monthDays' => $monthDays,
            'own' => $own, 'ownPartner' => $s['own_partner'], 'generatedAt' => $now->format('c'), 'liveMonth' => $liveMonth,
            'defaults' => ['thr' => (float)$s['flag_threshold'], 'minBase' => (float)$s['min_base'], 'silentN' => (int)$s['silent_days']],
        ],
        'schools' => $schools,
        'daily' => $daily,
        'monthly' => $monthly,
        'refunds' => (object)$refunds,
        'quality' => ['dups' => $dups, 'warnings' => array_values($warn)],
    ];
}

/* ---------- раздел на странице «Настройки» ---------- */
function revenue_admin(array $s): void {
    $ov = '';
    foreach ((array)$s['archive_overrides'] as $ym => $url) $ov .= $ym . ' ' . $url . "\n"; ?>
    <div class="field"><label>Ссылка на таблицу текущего месяца</label>
      <input type="url" name="current_url" required value="<?= e($s['current_url']) ?>">
      <span class="hint">1-го числа вставьте сюда ссылку на файл нового месяца. Отчёт сам определит месяц по датам в файле.</span></div>
    <label class="check"><input type="checkbox" name="move_old" value="1" checked> <span>Если ссылка изменилась - сохранить прежнюю как архив её месяца</span></label>
    <label class="check"><input type="checkbox" name="read_archive_sheet" value="1" <?= $s['read_archive_sheet'] ? 'checked' : '' ?>> <span>Брать ссылки на архивы с листа «<?= e($s['archive_sheet']) ?>» текущего файла</span></label>
    <div class="field"><label>Дополнительные ссылки на архивы (по одной в строке: месяц и ссылка)</label>
      <textarea name="overrides" placeholder="2025-12 https://docs.google.com/spreadsheets/d/.../edit"><?= e($ov) ?></textarea>
      <span class="hint">Эти ссылки важнее листа архива. Месяц можно писать как 2025-12 или «Декабрь 2025».</span></div>
    <div class="row">
      <div class="field"><label>Лист с данными</label><input type="text" name="data_sheet" value="<?= e($s['data_sheet']) ?>"></div>
      <div class="field"><label>Лист со списком архивов</label><input type="text" name="archive_sheet" value="<?= e($s['archive_sheet']) ?>"></div>
    </div>
    <div class="row3">
      <div class="field"><label>Партнёр Собственной сети</label><input type="text" name="own_partner" value="<?= e($s['own_partner']) ?>"><span class="hint">Школы с этим значением в столбце «Партнер».</span></div>
      <div class="field"><label>Порог флага, ±%</label><input type="number" name="flag_threshold" min="1" max="99" step="1" value="<?= e($s['flag_threshold']) ?>"></div>
      <div class="field"><label>Минимальная база для флага, ₽</label><input type="number" name="min_base" min="0" step="10000" value="<?= e($s['min_base']) ?>"></div>
      <div class="field"><label>«Тишина»: дней без продаж</label><input type="number" name="silent_days" min="1" max="31" value="<?= e($s['silent_days']) ?>"></div>
      <div class="field"><label>Глубина истории, месяцев</label><input type="number" name="history_months" min="1" max="24" value="<?= e($s['history_months']) ?>"><span class="hint">12 - нужно для сравнения с прошлым годом.</span></div>
    </div>
<?php }

/** Сохранение раздела. Возвращает [настройки, сообщения [[тип, текст]]]. */
function revenue_admin_save(array $p, array $s): array {
    $msgs = [];
    $newUrl = trim((string)($p['current_url'] ?? ''));
    if (!spreadsheet_id($newUrl)) return [$s, [['err', 'Ссылка на текущий месяц не похожа на Google-таблицу.']]];
    $errors = [];
    $ov = parse_month_links((string)($p['overrides'] ?? ''), $errors);
    $oldId = spreadsheet_id((string)$s['current_url']);
    if ($oldId !== spreadsheet_id($newUrl) && !empty($p['move_old'])) {
        $moved = false;
        foreach (glob(report_dir('revenue') . '/cache/*.json') ?: [] as $f) {
            $c = read_json($f);
            if (($c['kind'] ?? '') === 'live' && ($c['id'] ?? '') === $oldId) {
                $ym = basename($f, '.json');
                if (!isset($ov[$ym])) { $ov[$ym] = $s['current_url']; ksort($ov); }
                $msgs[] = ['ok', 'Прежняя ссылка сохранена как архив за ' . month_label($ym) . '.'];
                $moved = true;
            }
        }
        if (!$moved) $msgs[] = ['err', 'Не удалось определить месяц прежней ссылки (нет выгрузки). Добавьте её в архив вручную, если нужно.'];
    }
    $s['current_url'] = $newUrl;
    $s['archive_overrides'] = $ov;
    $s['read_archive_sheet'] = !empty($p['read_archive_sheet']);
    $s['data_sheet'] = trim((string)($p['data_sheet'] ?? '')) ?: 'raw_data_income';
    $s['archive_sheet'] = trim((string)($p['archive_sheet'] ?? '')) ?: 'Предыдущие месяца';
    $s['own_partner'] = norm_space((string)($p['own_partner'] ?? '')) ?: 'УК';
    $s['flag_threshold'] = max(1, min(99, (float)($p['flag_threshold'] ?? 20)));
    $s['min_base'] = max(0, (float)($p['min_base'] ?? 100000));
    $s['silent_days'] = max(1, min(31, (int)($p['silent_days'] ?? 5)));
    $s['history_months'] = max(1, min(24, (int)($p['history_months'] ?? 12)));
    foreach ($errors as $er) $msgs[] = ['err', $er];
    return [$s, $msgs];
}

return [
    'id' => 'revenue',
    'title' => 'Накопленная выручка месяца',
    'group' => 'Выручка',
    'desc' => 'Факт по дням, сравнение с прошлым месяцем и годом, прогноз',
    'order' => 10,
    'data_var' => 'DASH_DATA',
    'defaults' => [
        'current_url' => 'https://docs.google.com/spreadsheets/d/1RSr3AWZGEbn0PUHHdsoA87t5UIq75sTYsrqbP2gFNiQ/edit',
        'archive_overrides' => ['2025-12' => 'https://docs.google.com/spreadsheets/d/1WnyB2qqJlgqPQHqTEBOSnVZ7AJz206zD0Ps-FqDloeU/edit'],
        'read_archive_sheet' => true,
        'data_sheet' => 'raw_data_income',
        'archive_sheet' => 'Предыдущие месяца',
        'own_partner' => 'УК',
        'flag_threshold' => 20,
        'min_base' => 100000,
        'silent_days' => 5,
        'history_months' => 12,
    ],
    'build' => 'revenue_build',
    'admin' => 'revenue_admin',
    'admin_save' => 'revenue_admin_save',
];
