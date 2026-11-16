<?php
/*
 * Самообновление кода сайта из GitHub (без FTP и SSH).
 *
 * Сайт сам скачивает архив ветки (по умолчанию pisimonov/NSHMAIN, main), берёт из него папку site/
 * и заменяет свои файлы. Обновление ставится, только если тесты GitHub Actions для этого коммита
 * прошли (можно отключить разово кнопкой на странице настроек).
 *
 * Не трогает: config.php, папку data/ (настройки, ключи, данные отчётов), файлы, которых нет в репозитории.
 * Перед заменой сохраняет копию изменяемых файлов (data/update/backup-*) - из неё работает «Откатить».
 * Запуск: кнопка на странице «Настройки», а при включённом автообновлении - каждым запуском cron.php.
 */

const UPD_MAX_ZIP = 30 * 1024 * 1024;   // предельный размер архива, байт
const UPD_KEEP_BACKUPS = 3;
const UPD_REQUIRED = ['index.php', 'admin.php', 'cron.php', 'lib/bootstrap.php', 'lib/reports.php', 'lib/updater.php'];

function upd_repo(): string {
    $r = (string)cfg('update_repo', 'pisimonov/NSHMAIN');
    if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $r)) throw new RuntimeException('Неверный update_repo в config.php: ожидается «владелец/репозиторий».');
    return $r;
}
function upd_branch(): string {
    $b = (string)cfg('update_branch', 'main');
    if (!preg_match('~^[A-Za-z0-9_./-]+$~', $b)) throw new RuntimeException('Неверный update_branch в config.php.');
    return $b;
}
function upd_dir(): string { $d = DATA_DIR . '/update'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function upd_state(): array { $s = read_json(upd_dir() . '/state.json'); return is_array($s) ? $s : []; }
function upd_last(): array { $s = read_json(upd_dir() . '/last.json'); return is_array($s) ? $s : []; }
/** Короткий хеш коммита, из которого собран текущий код: из журнала обновлений или из файла VERSION. */
function upd_current_sha(): string {
    $s = upd_state();
    if (!empty($s['sha'])) return (string)$s['sha'];
    return preg_match('~\b([0-9a-f]{7,40})\s*$~', app_version(), $m) ? $m[1] : '';
}

/* ---------- GitHub ---------- */
function upd_headers(): array {
    $h = ['User-Agent: ns-dashboard-updater', 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
    $t = (string)cfg('github_token', '');
    if ($t !== '') $h[] = 'Authorization: Bearer ' . $t;
    return $h;
}
function upd_api(string $path): array {
    [$code, $resp] = http_request('GET', 'https://api.github.com/repos/' . upd_repo() . '/' . $path, upd_headers(), null, 30);
    $j = json_decode((string)$resp, true);
    if ($code === 403 || $code === 429) throw new RuntimeException('GitHub временно ограничил запросы с этого сервера (HTTP ' . $code . '). Повторите позже или добавьте github_token в config.php.');
    if ($code === 404) throw new RuntimeException('Репозиторий ' . upd_repo() . ' или ветка ' . upd_branch() . ' не найдены (HTTP 404). Для закрытого репозитория нужен github_token в config.php.');
    if ($code !== 200 || !is_array($j)) throw new RuntimeException('GitHub ответил HTTP ' . $code . ': ' . mb_substr((string)($j['message'] ?? $resp), 0, 200));
    return $j;
}
/** Последний коммит ветки: sha, дата, первая строка сообщения. */
function upd_remote_head(): array {
    $j = upd_api('commits/' . rawurlencode(upd_branch()));
    return ['sha' => (string)$j['sha'], 'date' => (string)($j['commit']['committer']['date'] ?? ''),
            'msg' => trim(strtok((string)($j['commit']['message'] ?? ''), "\n"))];
}
/** Состояние тестов GitHub Actions для коммита: success | pending | failure | none. */
function upd_ci(string $sha): array {
    $j = upd_api('commits/' . $sha . '/check-runs?per_page=100');
    $runs = array_values(array_filter($j['check_runs'] ?? [], function ($r) { return mb_strpos((string)($r['name'] ?? ''), 'Тесты') === 0; }));
    if (!$runs) return ['state' => 'none', 'text' => 'тесты для этого коммита не запускались'];
    foreach ($runs as $r) if (($r['status'] ?? '') !== 'completed') return ['state' => 'pending', 'text' => 'тесты ещё идут'];
    foreach ($runs as $r) if (!in_array($r['conclusion'] ?? '', ['success', 'skipped', 'neutral'], true))
        return ['state' => 'failure', 'text' => 'тесты не прошли: ' . $r['name']];
    return ['state' => 'success', 'text' => 'тесты прошли (' . count($runs) . ')'];
}
function upd_download(string $sha): string {
    $f = upd_dir() . '/src-' . substr($sha, 0, 12) . '.zip';
    [$code, $body] = http_request('GET', 'https://codeload.github.com/' . upd_repo() . '/zip/' . $sha, upd_headers(), null, 120);
    if ($code !== 200) throw new RuntimeException('Не удалось скачать архив кода (HTTP ' . $code . ').');
    if (strlen($body) > UPD_MAX_ZIP) throw new RuntimeException('Архив кода слишком большой.');
    if (file_put_contents($f, $body) === false) throw new RuntimeException('Не удалось сохранить архив в data/update - проверьте права на папку data.');
    return $f;
}

/* ---------- архив -> файлы сайта ---------- */
/** Путь внутри site/ берём, кроме config.php, data/, VERSION и служебных файлов git. */
function upd_take(string $rel): bool {
    if ($rel === '' || substr($rel, -1) === '/') return false;
    if (strpos($rel, '..') !== false || $rel[0] === '/' || strpos($rel, '\\') !== false || strpos($rel, "\0") !== false) return false;
    if ($rel === 'config.php' || $rel === 'VERSION' || strpos($rel, 'data/') === 0) return false;
    foreach (explode('/', $rel) as $p) if (strpos($p, '.git') === 0) return false;
    return true;
}
/** Распаковывает архив GitHub и возвращает [путь в site/ => содержимое]. */
function upd_extract(string $zipFile): array {
    $files = [];
    $add = function (string $name, callable $read) use (&$files) {
        $name = str_replace('\\', '/', $name);
        $parts = explode('/', $name, 3);                    // <корень архива>/site/<путь>
        if (count($parts) < 3 || $parts[1] !== 'site' || !upd_take($parts[2])) return;
        if (count($files) >= 1000) throw new RuntimeException('В архиве слишком много файлов.');
        $files[$parts[2]] = $read();
    };
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($zipFile) !== true) throw new RuntimeException('Архив кода повреждён.');
        for ($i = 0; $i < $z->numFiles; $i++) {
            $name = (string)$z->getNameIndex($i);
            $add($name, function () use ($z, $i) { $c = $z->getFromIndex($i); if ($c === false) throw new RuntimeException('Не удалось прочитать файл из архива.'); return $c; });
        }
        $z->close();
    } elseif (class_exists('PharData')) {
        $p = new PharData($zipFile);
        $base = 'phar://' . $zipFile . '/';
        foreach (new RecursiveIteratorIterator($p) as $f) {
            $name = substr(str_replace('\\', '/', $f->getPathname()), strlen($base));
            $add($name, function () use ($f) { return (string)file_get_contents($f->getPathname()); });
        }
    } else {
        throw new RuntimeException('На хостинге нет расширения PHP zip (или phar) - самообновление невозможно.');
    }
    return $files;
}
/** Проверка нового кода до установки: обязательные файлы и синтаксис PHP (без выполнения). */
function upd_validate(array $files): void {
    foreach (UPD_REQUIRED as $r) if (!isset($files[$r])) throw new RuntimeException('В новом коде нет файла ' . $r . ' - обновление отменено.');
    foreach ($files as $rel => $c) {
        if (substr($rel, -4) !== '.php') continue;
        try { token_get_all($c, TOKEN_PARSE); }
        catch (ParseError $e) { throw new RuntimeException('Синтаксическая ошибка в ' . $rel . ', строка ' . $e->getLine() . ' - обновление отменено.'); }
    }
}
/** Что изменится: новые и изменённые файлы, а также удалённые из репозитория (только те, что ставило самообновление). */
function upd_plan(string $root, array $files, array $prevManifest): array {
    $changed = []; $added = [];
    foreach ($files as $rel => $c) {
        $t = $root . '/' . $rel;
        if (!is_file($t)) $added[] = $rel;
        elseif (sha1_file($t) !== sha1($c)) $changed[] = $rel;
    }
    $removed = array_values(array_filter($prevManifest, function ($rel) use ($files, $root) { return !isset($files[$rel]) && upd_take($rel) && is_file($root . '/' . $rel); }));
    return ['added' => $added, 'changed' => $changed, 'removed' => $removed];
}

/* ---------- установка и откат ---------- */
function upd_write(string $file, string $content): void {
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Не удалось создать папку ' . $dir);
    $tmp = $dir . '/.upd-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content) === false) throw new RuntimeException('Нет прав на запись в ' . $dir . ' - PHP не может изменять файлы сайта.');
    @chmod($tmp, 0644);
    if (!@rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException('Не удалось заменить файл ' . $file); }
}
/**
 * Ставит файлы в $root. Перед этим копирует изменяемые и удаляемые файлы в $backupDir.
 * При ошибке на середине возвращает всё как было и бросает исключение.
 */
function upd_install(string $root, array $files, array $plan, string $backupDir): void {
    if (!is_writable($root)) throw new RuntimeException('PHP не может записывать в папку сайта (' . $root . '). На хостинге PHP должен работать от того же пользователя, что владеет файлами.');
    foreach (array_merge($plan['changed'], $plan['removed']) as $rel) upd_write($backupDir . '/' . $rel, (string)file_get_contents($root . '/' . $rel));
    $done = [];
    try {
        foreach (array_merge($plan['changed'], $plan['added']) as $rel) { upd_write($root . '/' . $rel, $files[$rel]); $done[] = $rel; }
        foreach ($plan['removed'] as $rel) { if (!@unlink($root . '/' . $rel)) throw new RuntimeException('Не удалось удалить ' . $rel); $done[] = $rel; }
    } catch (Throwable $e) {
        upd_restore($root, $backupDir, $plan['added']);
        throw new RuntimeException($e->getMessage() . ' Изменения отменены, сайт работает на прежней версии.');
    }
}
/** Возвращает файлы из резервной копии и удаляет добавленные обновлением. */
function upd_restore(string $root, string $backupDir, array $added): void {
    $it = is_dir($backupDir) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backupDir, FilesystemIterator::SKIP_DOTS)) : [];
    foreach ($it as $f) {
        $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($backupDir) + 1);
        if (upd_take($rel)) upd_write($root . '/' . $rel, (string)file_get_contents($f->getPathname()));
    }
    foreach ($added as $rel) if (upd_take($rel)) @unlink($root . '/' . $rel);
}
function upd_rmtree(string $d): void {
    if (!is_dir($d)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($d);
}
function upd_finish(array $last): array {
    $last += ['ok' => false, 'updated' => false];
    $last['at'] = date('c');
    write_json_atomic(upd_dir() . '/last.json', $last);
    app_log('Обновление кода (' . ($last['trigger'] ?? '') . '): ' . $last['msg']);
    return $last;
}

/**
 * Проверяет GitHub и при необходимости ставит новую версию.
 * $apply=false - только проверка. $force=true - ставить, даже если тесты не прошли или не запускались.
 * $manual=true - запуск кнопкой: ставит и версию, от которой раньше откатились (автообновление её пропускает).
 */
function upd_run(string $trigger, bool $apply = true, bool $force = false, bool $manual = false): array {
    $last = ['trigger' => $trigger];   // ok / updated / msg дополняются в upd_finish
    $lock = fopen(upd_dir() . '/lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['trigger' => $trigger, 'ok' => false, 'updated' => false, 'msg' => 'обновление кода уже выполняется'];
    try {
        $head = upd_remote_head();
        $last['remote'] = $head;
        $cur = upd_current_sha();
        if ($cur !== '' && strpos($head['sha'], $cur) === 0) return upd_finish($last + ['ok' => true, 'msg' => 'код актуален (' . substr($head['sha'], 0, 7) . ')']);
        $state = upd_state();
        if (!$manual && $apply && ($state['skip'] ?? '') === $head['sha'])
            return upd_finish($last + ['ok' => true, 'msg' => 'версия ' . substr($head['sha'], 0, 7) . ' была отменена откатом - автообновление ждёт следующую (поставить её можно кнопкой)']);
        $zip = upd_download($head['sha']);
        try { $files = upd_extract($zip); } finally { @unlink($zip); }
        upd_validate($files);
        $plan = upd_plan(APP_ROOT, $files, $state['files'] ?? []);
        $n = count($plan['added']) + count($plan['changed']) + count($plan['removed']);
        $last['plan'] = ['added' => count($plan['added']), 'changed' => count($plan['changed']), 'removed' => count($plan['removed'])];
        $ver = date('Y-m-d') . ' ' . substr($head['sha'], 0, 7);
        $newState = ['sha' => $head['sha'], 'at' => date('c'), 'files' => array_keys($files), 'prev' => $state['sha'] ?? $cur, 'backup' => null, 'added' => []];
        if ($n === 0) {
            if ($apply) { write_json_atomic(upd_dir() . '/state.json', $newState); @file_put_contents(APP_ROOT . '/VERSION', $ver . "\n"); }
            return upd_finish($last + ['ok' => true, 'msg' => 'файлы сайта не менялись, версия отмечена как ' . substr($head['sha'], 0, 7)]);
        }
        $ci = upd_ci($head['sha']);
        $last['ci'] = $ci;
        $desc = 'новая версия ' . substr($head['sha'], 0, 7) . ' «' . $head['msg'] . '»: файлов +' . count($plan['added']) . ' / изменено ' . count($plan['changed']) . ' / удалено ' . count($plan['removed']) . '; ' . $ci['text'];
        if (!$apply) return upd_finish($last + ['ok' => true, 'msg' => 'доступна ' . $desc]);
        if ($ci['state'] !== 'success' && !$force) return upd_finish($last + ['ok' => $ci['state'] === 'pending', 'msg' => 'не установлена ' . $desc]);
        $backup = upd_dir() . '/backup-' . date('Ymd-His') . '-' . ($cur !== '' ? substr($cur, 0, 7) : 'start');
        upd_install(APP_ROOT, $files, $plan, $backup);
        $newState['backup'] = $backup; $newState['added'] = $plan['added'];
        write_json_atomic(upd_dir() . '/state.json', $newState);
        @file_put_contents(APP_ROOT . '/VERSION', $ver . "\n");
        if (function_exists('opcache_reset')) @opcache_reset();
        $old = glob(upd_dir() . '/backup-*', GLOB_ONLYDIR) ?: [];
        sort($old);
        foreach (array_slice($old, 0, max(0, count($old) - UPD_KEEP_BACKUPS)) as $d) upd_rmtree($d);
        return upd_finish($last + ['ok' => true, 'updated' => true, 'msg' => 'установлена ' . $desc . ($force && $ci['state'] !== 'success' ? ' (установлено без проверки тестов)' : '')]);
    } catch (Throwable $e) {
        return upd_finish($last + ['msg' => 'ошибка: ' . $e->getMessage()]);
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}
/** Откат последнего обновления кода из резервной копии. */
function upd_rollback(): array {
    $s = upd_state();
    if (empty($s['backup']) || !is_dir($s['backup'])) return ['ok' => false, 'msg' => 'нет резервной копии для отката'];
    upd_restore(APP_ROOT, $s['backup'], $s['added'] ?? []);
    $prev = (string)($s['prev'] ?? '');
    write_json_atomic(upd_dir() . '/state.json', ['sha' => $prev, 'at' => date('c'), 'files' => [], 'prev' => '', 'backup' => null, 'added' => [], 'skip' => $s['sha']]);
    @file_put_contents(APP_ROOT . '/VERSION', date('Y-m-d') . ' ' . ($prev !== '' ? substr($prev, 0, 7) : 'прежняя версия') . " (откат)\n");
    if (function_exists('opcache_reset')) @opcache_reset();
    upd_rmtree($s['backup']);
    $r = ['trigger' => 'откат', 'ok' => true, 'updated' => true, 'msg' => 'откат с ' . substr((string)$s['sha'], 0, 7) . ' на ' . ($prev !== '' ? substr($prev, 0, 7) : 'прежнюю версию')];
    return upd_finish($r);
}
