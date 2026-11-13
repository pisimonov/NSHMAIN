<?php
/* Сборка набора данных для дашборда: загрузка месяцев, кэш архивов, агрегирование. */

const DATA_COLS = 'A1:M';   // A Страна ... H Выручка (рубли) ... K Школа, L Партнер, M Закрыта

/** Разбор строк листа raw_data_income. */
function process_values(array $rows): array {
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
    return ['months' => $months, 'meta' => $meta, 'count' => $count, 'dominant' => array_key_first($count), 'dups' => $dups, 'warnings' => $warn];
}

/** Компактная упаковка одного месяца для кэша. */
function pack_month(array $p, string $ym): array {
    $nd = month_days($ym); $maxDay = 0; $schools = [];
    foreach ($p['months'][$ym] as $name => $days) {
        $maxDay = max($maxDay, max(array_keys($days)));
        $v = [];
        for ($d = 1; $d <= $nd; $d++) $v[] = round($days[$d] ?? 0, 2);
        $schools[$name] = $p['meta'][$ym][$name] + ['v' => $v];
    }
    return ['month' => $ym, 'maxDay' => $maxDay, 'rows' => $p['count'][$ym], 'schools' => $schools,
            'dups' => array_keys($p['dups'][$ym] ?? []), 'warnings' => $p['warnings']];
}

function cache_file(string $ym): string { return DATA_DIR . '/cache/' . $ym . '.json'; }

/** Основной запуск обновления. Возвращает статус. */
function run_update(string $trigger = 'manual'): array {
    @set_time_limit(600);
    @ini_set('memory_limit', '512M');
    $lock = fopen(DATA_DIR . '/update.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['ok' => false, 'error' => 'Обновление уже выполняется.'];
    $t0 = microtime(true);
    $s = settings(); $warn = []; $sources = [];
    try {
        $src = make_source();
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
        $live = process_values($src->values($liveId, $s['data_sheet'], DATA_COLS));
        $liveMonth = $live['dominant'];
        foreach ($live['warnings'] as $w) $warn[] = 'Текущий файл: ' . $w;
        $packs = [];
        foreach ($live['months'] as $ym => $_) {
            if (!in_array($ym, $need, true) && $ym !== $liveMonth) continue;
            $pk = pack_month($live, $ym);
            if ($ym === $liveMonth) {
                write_json_atomic(cache_file($ym), ['kind' => 'live', 'id' => $liveId, 'fetchedAt' => date('c'), 'pack' => $pk]);
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
            $cache = read_json(cache_file($ym));
            $cacheOk = is_array($cache) && isset($cache['pack']);
            $cacheFresh = $cacheOk && $cache['kind'] === 'archive' && $ym < $recentFrom && in_array($cache['id'], $cands[$ym] ?? [$cache['id']], true);
            if ($cacheFresh) { $packs[$ym] = $cache['pack']; $sources[$ym] = ['kind' => 'cache', 'id' => $cache['id'], 'rows' => $cache['pack']['rows'], 'at' => $cache['fetchedAt']]; continue; }
            $got = false;
            foreach ($cands[$ym] ?? [] as $id) {
                try {
                    $p = process_values($src->values($id, $s['data_sheet'], DATA_COLS));
                    if (!isset($p['months'][$ym]) || $p['dominant'] !== $ym) { $warn[] = month_label($ym) . ': файл ' . $id . ' содержит данные за ' . month_label($p['dominant']) . ' - пропущен.'; continue; }
                    $pk = pack_month($p, $ym);
                    write_json_atomic(cache_file($ym), ['kind' => 'archive', 'id' => $id, 'fetchedAt' => date('c'), 'pack' => $pk]);
                    $packs[$ym] = $pk; $sources[$ym] = ['kind' => 'archive', 'id' => $id, 'rows' => $pk['rows']]; $got = true; break;
                } catch (Throwable $e) { $warn[] = month_label($ym) . ': ' . $e->getMessage(); }
            }
            if (!$got && $cacheOk) {
                $packs[$ym] = $cache['pack']; $sources[$ym] = ['kind' => 'cache', 'id' => $cache['id'], 'rows' => $cache['pack']['rows'], 'at' => $cache['fetchedAt']];
                if ($cache['kind'] === 'live') $warn[] = month_label($ym) . ': архивный файл не найден, использую последнюю выгрузку текущего файла от ' . date('d.m.Y H:i', strtotime($cache['fetchedAt'])) . '. Добавьте ссылку на архив этого месяца.';
            } elseif (!$got) {
                $warn[] = month_label($ym) . ': нет данных - добавьте ссылку на архив этого месяца в настройках или на лист «' . $s['archive_sheet'] . '».';
            }
        }
        if (!isset($packs[$cur])) throw new RuntimeException('Нет данных за текущий месяц (' . month_label($cur) . '). В текущем файле: ' . month_label($liveMonth) . '.');

        // 4. Сборка
        $payload = build_payload($packs, $cur, $asOf, $now, $liveMonth, $s, $warn);
        write_json_atomic(DATA_DIR . '/dashboard.json', $payload);
        $status = ['ok' => true, 'at' => date('c'), 'trigger' => $trigger, 'duration' => round(microtime(true) - $t0, 1),
                   'cur' => $cur, 'asOf' => $payload['meta']['asOf'], 'liveMonth' => $liveMonth, 'warnings' => $warn, 'sources' => $sources,
                   'schools' => count($payload['schools'])];
        app_log('Обновление (' . $trigger . '): ок за ' . $status['duration'] . ' с, предупреждений: ' . count($warn));
    } catch (Throwable $e) {
        $status = ['ok' => false, 'at' => date('c'), 'trigger' => $trigger, 'error' => $e->getMessage(), 'warnings' => $warn, 'sources' => $sources,
                   'duration' => round(microtime(true) - $t0, 1)];
        app_log('Обновление (' . $trigger . '): ОШИБКА ' . $e->getMessage());
    }
    write_json_atomic(DATA_DIR . '/status.json', $status);
    flock($lock, LOCK_UN); fclose($lock);
    return $status;
}

function build_payload(array $packs, string $cur, string $asOf, DateTimeImmutable $now, string $liveMonth, array $s, array &$warn): array {
    ksort($packs);
    $pm = month_add($cur, -1); $ly = month_add($cur, -12);
    // единый список школ; атрибуты - из самого свежего месяца
    $meta = [];
    foreach ($packs as $ym => $pk) foreach ($pk['schools'] as $name => $sc) { unset($sc['v']); $meta[$name] = $sc; }
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

    $monthly = []; $monthDays = []; $dups = [];
    foreach ($packs as $ym => $pk) {
        $arr = array_fill(0, count($names), null);
        foreach ($pk['schools'] as $n => $sc) $arr[$idx[$n]] = round(array_sum($sc['v']), 2);
        $monthly[$ym] = $arr; $monthDays[$ym] = $pk['maxDay'];
        if (!empty($pk['dups'])) $dups[] = ['m' => $ym, 'schools' => $pk['dups']];
    }
    // дата среза не позже последнего дня, по которому есть данные
    $curMax = $packs[$cur]['maxDay'];
    if ((int)substr($asOf, 8, 2) > $curMax) {
        $warn[] = 'Данные текущего месяца есть только по ' . $curMax . '-е число - срез сдвинут.';
        $asOf = $cur . '-' . sprintf('%02d', $curMax);
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
            'asOf' => $asOf, 'today' => $today, 'todayInCur' => substr($today, 0, 7) === $cur,
            'cur' => $cur, 'pm' => $pm, 'ly' => $ly, 'months' => array_keys($packs), 'monthDays' => $monthDays,
            'own' => $own, 'ownPartner' => $s['own_partner'], 'generatedAt' => $now->format('c'), 'liveMonth' => $liveMonth,
            'defaults' => ['thr' => (float)$s['flag_threshold'], 'minBase' => (float)$s['min_base'], 'silentN' => (int)$s['silent_days']],
        ],
        'schools' => $schools,
        'daily' => $daily,
        'monthly' => $monthly,
        'quality' => ['dups' => $dups, 'warnings' => array_values($warn)],
    ];
}
