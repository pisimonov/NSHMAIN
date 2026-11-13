<?php
define('APP_ROOT', __DIR__);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sources.php';
require __DIR__ . '/lib/builder.php';
require __DIR__ . '/templates/layout.php';
web_boot();
require_login();

function parse_overrides(string $text, array &$errors): array {
    $out = [];
    foreach (preg_split('/\R/u', $text) as $i => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (!preg_match('~^(.*?)\s*[|;,\t ]\s*(https?://\S+|[a-zA-Z0-9_-]{20,}|csv:\S+)\s*$~u', $line, $m)) { $errors[] = 'Строка ' . ($i + 1) . ': не найдена ссылка.'; continue; }
        $label = trim($m[1], " |;,\t");
        $ym = preg_match('/^(\d{4})-(\d{2})$/', $label) ? $label : parse_ru_month($label);
        $id = spreadsheet_id($m[2]);
        if (!$ym) { $errors[] = 'Строка ' . ($i + 1) . ': не распознан месяц «' . $label . '» (пишите 2025-12 или «Декабрь 2025»).'; continue; }
        if (!$id) { $errors[] = 'Строка ' . ($i + 1) . ': ссылка не похожа на Google-таблицу.'; continue; }
        $out[$ym] = $m[2];
    }
    ksort($out);
    return $out;
}

$s = settings();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    if ($act === 'sources') {
        $newUrl = trim((string)$_POST['current_url']);
        if (!spreadsheet_id($newUrl)) { flash('Ссылка на текущий месяц не похожа на Google-таблицу.', 'err'); redirect('admin.php'); }
        $errors = [];
        $ov = parse_overrides((string)$_POST['overrides'], $errors);
        $oldId = spreadsheet_id((string)$s['current_url']); $newId = spreadsheet_id($newUrl);
        if ($oldId !== $newId && !empty($_POST['move_old'])) {
            $moved = false;
            foreach (glob(DATA_DIR . '/cache/*.json') as $f) {
                $c = read_json($f);
                if (($c['kind'] ?? '') === 'live' && ($c['id'] ?? '') === $oldId) {
                    $ym = basename($f, '.json');
                    if (!isset($ov[$ym])) { $ov[$ym] = $s['current_url']; ksort($ov); }
                    flash('Прежняя ссылка сохранена как архив за ' . month_label($ym) . '.');
                    $moved = true;
                }
            }
            if (!$moved) flash('Не удалось определить месяц прежней ссылки (нет выгрузки). Добавьте её в архив вручную, если нужно.', 'err');
        }
        $s['current_url'] = $newUrl;
        $s['archive_overrides'] = $ov;
        $s['read_archive_sheet'] = !empty($_POST['read_archive_sheet']);
        $s['data_sheet'] = trim((string)$_POST['data_sheet']) ?: 'raw_data_income';
        $s['archive_sheet'] = trim((string)$_POST['archive_sheet']) ?: 'Предыдущие месяца';
        save_settings($s);
        foreach ($errors as $er) flash($er, 'err');
        flash('Источники сохранены. Нажмите «Обновить сейчас», чтобы загрузить данные.');
        redirect('admin.php');
    }
    if ($act === 'params') {
        $s['own_partner'] = norm_space((string)$_POST['own_partner']) ?: 'УК';
        $s['flag_threshold'] = max(1, min(99, (float)$_POST['flag_threshold']));
        $s['min_base'] = max(0, (float)$_POST['min_base']);
        $s['silent_days'] = max(1, min(31, (int)$_POST['silent_days']));
        $s['history_months'] = max(1, min(24, (int)$_POST['history_months']));
        save_settings($s);
        flash('Параметры сохранены. Собственная сеть и значения по умолчанию применятся после обновления данных.');
        redirect('admin.php');
    }
    if ($act === 'key') {
        $f = $_FILES['key'] ?? null;
        $j = ($f && $f['error'] === UPLOAD_ERR_OK && $f['size'] < 20000) ? json_decode((string)file_get_contents($f['tmp_name']), true) : null;
        if (!is_array($j) || ($j['type'] ?? '') !== 'service_account' || empty($j['client_email']) || empty($j['private_key'])) {
            flash('Это не JSON-ключ сервисного аккаунта Google (ожидается файл с "type": "service_account").', 'err');
        } else {
            write_json_atomic(DATA_DIR . '/service-account.json', $j);
            @chmod(DATA_DIR . '/service-account.json', 0600);
            @unlink(DATA_DIR . '/token.json');
            app_log('Загружен ключ сервисного аккаунта ' . $j['client_email']);
            flash('Ключ загружен: ' . $j['client_email'] . '. Откройте этому адресу доступ на чтение к таблицам.');
        }
        redirect('admin.php');
    }
    if ($act === 'update') {
        $st = run_update('страница настроек');
        if ($st['ok']) flash('Данные обновлены за ' . $st['duration'] . ' с.' . ($st['warnings'] ? ' Предупреждений: ' . count($st['warnings']) . ' (ниже).' : ''));
        else flash('Обновление не удалось: ' . $st['error'], 'err');
        redirect('admin.php');
    }
    if ($act === 'clear_cache') {
        $n = 0; foreach (glob(DATA_DIR . '/cache/*.json') as $f) { if (@unlink($f)) $n++; }
        app_log('Очищен кэш архивов: ' . $n . ' файлов');
        flash('Кэш очищен (' . $n . ' файлов). Следующее обновление заново загрузит все месяцы - это может занять 1-2 минуты.');
        redirect('admin.php');
    }
    if ($act === 'password') {
        $a = $s['auth'];
        if (!password_verify((string)$_POST['old'], (string)$a['hash'])) flash('Текущий пароль неверный.', 'err');
        elseif (mb_strlen((string)$_POST['new']) < 10) flash('Новый пароль должен быть не короче 10 символов.', 'err');
        elseif ($_POST['new'] !== $_POST['new2']) flash('Новые пароли не совпадают.', 'err');
        else {
            $s['auth']['hash'] = password_hash((string)$_POST['new'], PASSWORD_DEFAULT);
            save_settings($s);
            $_SESSION['pwv'] = substr(hash('sha256', (string)$s['auth']['hash']), 0, 16);
            app_log('Пароль изменён');
            flash('Пароль изменён.');
        }
        redirect('admin.php');
    }
}

$status = read_json(DATA_DIR . '/status.json');
$sa = read_json(DATA_DIR . '/service-account.json');
$ovText = '';
foreach ((array)$s['archive_overrides'] as $ym => $url) $ovText .= $ym . ' ' . $url . "\n";
$logLines = is_file(DATA_DIR . '/app.log') ? array_slice(file(DATA_DIR . '/app.log', FILE_IGNORE_NEW_LINES), -60) : [];
$host = $_SERVER['HTTP_HOST'] ?? 'ваш-домен';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
$cronUrl = 'https://' . $host . $base . '/cron.php?key=' . cfg('cron_key');
$gsheet = function ($id) { return strpos($id, 'csv:') === 0 ? e($id) : '<a href="https://docs.google.com/spreadsheets/d/' . e($id) . '/edit" target="_blank" rel="noopener noreferrer">' . e(substr($id, 0, 10)) . '…</a>'; };
$kinds = ['live' => 'текущий файл', 'archive' => 'архив (загружен)', 'cache' => 'архив (из кэша)'];

page_head('Настройки - выручка сети'); ?>
<div class="admin">
<header class="top"><div class="wrap"><div class="brandrow">
  <div class="brand">НЕ ШКОЛА<small>Настройки отчёта</small></div><div class="spacer"></div>
  <div class="navlinks"><a class="btn" href="index.php">← К отчёту</a>
  <form method="post" action="index.php?action=logout"><?= csrf_field() ?><button class="btn" type="submit">Выйти</button></form></div>
</div></div></header>
<div class="wrap">
<h1>Настройки</h1>
<div style="margin-top:12px"><?php flashes(); ?></div>

<section>
  <h2>Состояние данных</h2>
  <?php if (!$status): ?>
    <p>Данные ещё не загружались.</p>
  <?php else: ?>
    <div class="kv">
      <div>Последний запуск</div><div><?= e(fmt_dt($status['at'])) ?> · <?= e($status['trigger'] ?? '') ?> · <?= e($status['duration'] ?? '') ?> с</div>
      <div>Результат</div><div><?= $status['ok'] ? '<span class="ok-t">успешно</span>' : '<span class="err-t">ошибка: ' . e($status['error']) . '</span>' ?></div>
      <?php if ($status['ok']): ?>
      <div>Текущий месяц / срез</div><div><?= e(month_label($status['cur'])) ?> · по <?= e(date('d.m.Y', strtotime($status['asOf']))) ?> включительно · школ: <?= (int)$status['schools'] ?></div>
      <div>В текущем файле</div><div><?= e(month_label($status['liveMonth'])) ?></div>
      <?php endif; ?>
    </div>
    <?php if (!empty($status['warnings'])): ?>
      <div class="flash err" style="margin-top:12px"><b>Предупреждения:</b><ul style="margin:6px 0 0 18px;padding:0"><?php foreach ($status['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if (!empty($status['sources'])): ksort($status['sources']); ?>
      <div class="tblwrap" style="margin-top:12px;max-height:none"><table>
        <thead><tr><th class="l">Месяц</th><th class="l">Источник</th><th class="l">Таблица</th><th>Строк</th></tr></thead><tbody>
        <?php foreach ($status['sources'] as $ym => $src): ?>
          <tr><td class="l"><?= e(month_label($ym)) ?></td><td class="l"><?= e($kinds[$src['kind']] ?? $src['kind']) ?><?= isset($src['at']) ? ' от ' . e(fmt_dt($src['at'])) : '' ?></td><td class="l"><?= $gsheet($src['id']) ?></td><td><?= (int)$src['rows'] ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  <?php endif; ?>
  <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="update"><button class="btn primary" type="submit">Обновить сейчас</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="clear_cache"><button class="btn" type="submit" title="Заново загрузить все архивные месяцы при следующем обновлении">Очистить кэш архивов</button></form>
  </div>
</section>

<section>
  <h2>Источники данных</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="sources">
    <div class="field"><label>Ссылка на таблицу текущего месяца</label>
      <input type="url" name="current_url" required value="<?= e($s['current_url']) ?>">
      <span class="hint">1-го числа вставьте сюда ссылку на файл нового месяца. Отчёт сам определит месяц по датам в файле.</span></div>
    <label class="check"><input type="checkbox" name="move_old" value="1" checked> <span>Если ссылка изменилась - сохранить прежнюю как архив её месяца</span></label>
    <label class="check"><input type="checkbox" name="read_archive_sheet" value="1" <?= $s['read_archive_sheet'] ? 'checked' : '' ?>> <span>Брать ссылки на архивы с листа «<?= e($s['archive_sheet']) ?>» текущего файла</span></label>
    <div class="field"><label>Дополнительные ссылки на архивы (по одной в строке: месяц и ссылка)</label>
      <textarea name="overrides" placeholder="2025-12 https://docs.google.com/spreadsheets/d/.../edit"><?= e($ovText) ?></textarea>
      <span class="hint">Эти ссылки важнее листа архива. Месяц можно писать как 2025-12 или «Декабрь 2025».</span></div>
    <div class="row">
      <div class="field"><label>Лист с данными</label><input type="text" name="data_sheet" value="<?= e($s['data_sheet']) ?>"></div>
      <div class="field"><label>Лист со списком архивов</label><input type="text" name="archive_sheet" value="<?= e($s['archive_sheet']) ?>"></div>
    </div>
    <button class="btn primary" type="submit">Сохранить источники</button>
  </form>
</section>

<section>
  <h2>Доступ к Google</h2>
  <?php if (!empty($s['dev_csv_dir'])): ?><div class="flash err">Включён тестовый режим чтения CSV (<?= e($s['dev_csv_dir']) ?>).</div><?php endif; ?>
  <div class="kv" style="margin-bottom:12px">
    <div>Сервисный аккаунт</div>
    <div><?= $sa ? '<b>' . e($sa['client_email']) . '</b>' : '<span class="err-t">ключ не загружен</span>' ?></div>
  </div>
  <?php if ($sa): ?><p style="font-size:13px;margin:0 0 12px">Владелец таблиц должен открыть этому адресу доступ «Читатель» к текущему файлу и к архивам. Проще всего - к папке, где лежат все файлы.</p><?php endif; ?>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="key">
    <div class="field"><label>JSON-ключ сервисного аккаунта</label><input type="file" name="key" accept=".json,application/json" required></div>
    <button class="btn" type="submit"><?= $sa ? 'Заменить ключ' : 'Загрузить ключ' ?></button>
  </form>
</section>

<section>
  <h2>Параметры отчёта</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="params">
    <div class="row3">
      <div class="field"><label>Партнёр Собственной сети</label><input type="text" name="own_partner" value="<?= e($s['own_partner']) ?>"><span class="hint">Школы с этим значением в столбце «Партнер».</span></div>
      <div class="field"><label>Порог флага, ±%</label><input type="number" name="flag_threshold" min="1" max="99" step="1" value="<?= e($s['flag_threshold']) ?>"></div>
      <div class="field"><label>Минимальная база для флага, ₽</label><input type="number" name="min_base" min="0" step="10000" value="<?= e($s['min_base']) ?>"></div>
      <div class="field"><label>«Тишина»: дней без продаж</label><input type="number" name="silent_days" min="1" max="31" value="<?= e($s['silent_days']) ?>"></div>
      <div class="field"><label>Глубина истории, месяцев</label><input type="number" name="history_months" min="1" max="24" value="<?= e($s['history_months']) ?>"><span class="hint">12 - нужно для сравнения с прошлым годом.</span></div>
    </div>
    <button class="btn primary" type="submit">Сохранить параметры</button>
  </form>
</section>

<section>
  <h2>Ежедневное обновление</h2>
  <p style="font-size:13px;margin:0 0 8px">Добавьте задание в планировщик (cron) хостинга. Вариант 1 - команда:</p>
  <pre><?= e('php ' . APP_ROOT . '/cron.php') ?></pre>
  <p style="font-size:13px;margin:12px 0 8px">Вариант 2 - если планировщик умеет только открывать ссылку:</p>
  <pre><?= e($cronUrl) ?></pre>
  <p class="hint" style="font-size:12px;color:var(--muted)">Ссылка содержит секретный ключ - не публикуйте её. Рекомендуемое расписание: каждые 2 часа с 07:00 до 23:00.</p>
</section>

<section>
  <h2>Пароль</h2>
  <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="act" value="password">
    <div class="row3">
      <div class="field"><label>Текущий пароль</label><input type="password" name="old" required autocomplete="current-password"></div>
      <div class="field"><label>Новый пароль (от 10 символов)</label><input type="password" name="new" required autocomplete="new-password"></div>
      <div class="field"><label>Новый пароль ещё раз</label><input type="password" name="new2" required autocomplete="new-password"></div>
    </div>
    <button class="btn" type="submit">Сменить пароль</button>
  </form>
  <p class="hint" style="font-size:12px;color:var(--muted);margin-top:10px">Логин: <b><?= e($s['auth']['user']) ?></b>. Если пароль забыт - удалите файл data/settings.json через файловый менеджер хостинга и пройдите setup.php заново (источники и параметры придётся ввести снова).</p>
</section>

<section>
  <h2>Журнал</h2>
  <pre class="log"><?= e(implode("\n", array_reverse($logLines))) ?: 'Пусто' ?></pre>
</section>
</div></div>
<?php page_foot();
