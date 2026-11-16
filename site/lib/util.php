<?php
/* Вспомогательные функции без побочных эффектов. */

function write_json_atomic(string $file, $data): void {
    $tmp = $file . '.tmp' . bin2hex(random_bytes(3));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) throw new RuntimeException('Не удалось сериализовать JSON: ' . json_last_error_msg());
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Не удалось записать файл ' . basename($file) . ' - проверьте права на папку data.');
    if (!@rename($tmp, $file)) { @unlink($file); rename($tmp, $file); }
}
function read_json(string $file) {
    if (!is_file($file)) return null;
    $d = json_decode((string)file_get_contents($file), true);
    return $d;
}

/** Число из ячейки: 307.38 | "307,38" | "1 234,5" | "" */
function parse_num($v): float {
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = str_replace(["\xc2\xa0", "\xe2\x80\xaf", ' '], '', (string)$v);
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : 0.0;
}

/** Дата из ячейки -> 'YYYY-MM-DD' или null. Поддерживает 2026-11-01, 01.11.2026, серийный номер Google/Excel. */
function parse_date($v): ?string {
    if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d{5}(\.\d+)?$/', $v))) {
        $n = (int)floor((float)$v);
        if ($n > 20000 && $n < 80000) return gmdate('Y-m-d', ($n - 25569) * 86400);
        return null;
    }
    $s = trim((string)$v);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', $s, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $s, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
    return null;
}

function spreadsheet_id(string $urlOrId): ?string {
    $s = trim($urlOrId);
    if ($s === '') return null;
    if (preg_match('~/spreadsheets/d/([a-zA-Z0-9_-]{20,})~', $s, $m)) return $m[1];
    if (preg_match('~^[a-zA-Z0-9_-]{20,}$~', $s)) return $s;
    if (preg_match('~^csv:[\w.-]+$~', $s)) return $s;          // локальный тестовый источник
    return null;
}

function month_add(string $ym, int $delta): string {
    [$y, $m] = array_map('intval', explode('-', $ym));
    $t = $y * 12 + ($m - 1) + $delta;
    return sprintf('%04d-%02d', intdiv($t, 12), $t % 12 + 1);
}
function month_days(string $ym): int {
    [$y, $m] = array_map('intval', explode('-', $ym));
    return cal_days_in_month_safe($m, $y);
}
function cal_days_in_month_safe(int $m, int $y): int { return (int)date('t', mktime(0, 0, 0, $m, 1, $y)); }

const RU_MONTHS = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
function month_label(string $ym): string {
    [$y, $m] = array_map('intval', explode('-', $ym));
    return mb_convert_case(RU_MONTHS[$m - 1], MB_CASE_TITLE, 'UTF-8') . ' ' . $y;
}

/** «Окрябрь 2026» -> '2026-10' (допускает опечатку в 1-2 буквы и падежи «октября»). */
function parse_ru_month(string $text): ?string {
    $t = mb_strtolower(trim($text), 'UTF-8');
    if (!preg_match('/(\p{L}+)[^\d]*(\d{4})/u', $t, $m)) return null;
    $word = $m[1]; $year = (int)$m[2];
    $best = null; $bestD = 99;
    $forms = [];
    foreach (RU_MONTHS as $i => $nom) {
        $stem = mb_substr($nom, 0, mb_strlen($nom) - 1);
        $forms[$i] = [$nom, $stem . 'я', $stem . 'е', $stem . 'ь', 'мая', 'мае'];
    }
    foreach ($forms as $i => $list) {
        foreach ($list as $f) {
            $d = levenshtein_utf8($word, $f);
            if ($d < $bestD) { $bestD = $d; $best = $i; }
        }
    }
    if ($best === null || $bestD > 2) return null;
    return sprintf('%04d-%02d', $year, $best + 1);
}
function levenshtein_utf8(string $a, string $b): int {
    $map = [];
    $conv = function ($s) use (&$map) {
        $out = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if (!isset($map[$ch])) $map[$ch] = chr(count($map) + 33);
            $out .= $map[$ch];
        }
        return $out;
    };
    return levenshtein($conv($a), $conv($b));
}

function norm_space(string $s): string { return trim(preg_replace('/\s+/u', ' ', $s)); }
function norm_city(string $city, string $country): string {
    $c = norm_space($city);
    $suffix = ', ' . norm_space($country);
    if ($country !== '' && mb_substr($c, -mb_strlen($suffix)) === $suffix) $c = trim(mb_substr($c, 0, mb_strlen($c) - mb_strlen($suffix)));
    return $c;
}

/** Строки «2025-12 https://...» или «Декабрь 2025 https://...» -> ['2025-12' => ссылка]. */
function parse_month_links(string $text, array &$errors): array {
    $out = [];
    foreach (preg_split('/\R/u', $text) as $i => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!preg_match('~^(.*?)\s*[|;,\t ]\s*(https?://\S+|[a-zA-Z0-9_-]{20,}|csv:\S+)\s*$~u', $line, $m)) { $errors[] = 'Строка ' . ($i + 1) . ': не найдена ссылка.'; continue; }
        $label = trim($m[1], " |;,\t");
        $ym = preg_match('/^(\d{4})-(\d{2})$/', $label) ? $label : parse_ru_month($label);
        if (!$ym) { $errors[] = 'Строка ' . ($i + 1) . ': не распознан месяц «' . $label . '» (пишите 2025-12 или «Декабрь 2025»).'; continue; }
        if (!spreadsheet_id($m[2])) { $errors[] = 'Строка ' . ($i + 1) . ': ссылка не похожа на Google-таблицу.'; continue; }
        $out[$ym] = $m[2];
    }
    ksort($out);
    return $out;
}

/**
 * Находит лист таблицы по названию из настроек, если оно записано неточно:
 * регистр, ё/е, лишние пробелы, обрезанный или дописанный конец названия.
 * Возвращает настоящее название или null, если подходящего листа нет или их несколько.
 */
function match_sheet_title(string $wanted, array $titles): ?string {
    $n = function (string $t): string { return trim(preg_replace('/\s+/u', ' ', str_replace('ё', 'е', mb_strtolower($t)))); };
    if (in_array($wanted, $titles, true)) return $wanted;
    $w = $n($wanted);
    $same = array_values(array_filter($titles, function ($t) use ($n, $w) { return $n($t) === $w; }));
    if (count($same) === 1) return $same[0];
    // общее начало названия: берём лист с самым длинным совпадением, если оно заметно длиннее, чем у остальных
    $best = null; $bestLen = 0; $second = 0;
    foreach ($titles as $t) {
        $a = $n($t); $len = 0; $max = min(mb_strlen($a), mb_strlen($w));
        while ($len < $max && mb_substr($a, $len, 1) === mb_substr($w, $len, 1)) $len++;
        if ($len > $bestLen) { $second = $bestLen; $bestLen = $len; $best = $t; } elseif ($len > $second) $second = $len;
    }
    $full = $best !== null && ($bestLen === mb_strlen($w) || $bestLen === mb_strlen($n($best)));   // одно название - начало другого
    if ($best !== null && $bestLen >= 12 && ($full || $bestLen >= 25) && $bestLen - $second >= 4) return $best;
    return null;
}

