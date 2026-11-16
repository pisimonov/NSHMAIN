<?php
/* Настройки: состояние и источники всех отчётов, доступ к Google, расписание, пароль, журнал. */
define('APP_ROOT', __DIR__);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/templates/layout.php';
web_boot();
require_login();

$s = settings();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $rid = (string)($_POST['id'] ?? '');
    $rep = $rid !== '' ? report($rid) : null;
    if ($act === 'report_save' && $rep && !empty($rep['admin_save'])) {
        [$s2, $msgs] = ($rep['admin_save'])($_POST, $s);
        $hasErr = false;
        foreach ($msgs as [$k, $t]) { flash($t, $k); if ($k === 'err') $hasErr = true; }
        if ($s2 !== $s) { save_settings($s2); flash('Настройки «' . $rep['title'] . '» сохранены. Нажмите «Обновить», чтобы загрузить данные.'); }
        elseif (!$hasErr) flash('Изменений нет.');
        redirect('admin.php#r-' . $rid);
    }
    if ($act === 'update') {
        $list = $rep ? [$rid => run_report_update($rid, 'страница настроек')] : run_all_updates('страница настроек');
        foreach ($list as $id => $st) {
            $t = report($id)['title'];
            if ($st['ok']) flash('«' . $t . '» обновлён за ' . $st['duration'] . ' с.' . ($st['warnings'] ? ' Предупреждений: ' . count($st['warnings']) . '.' : ''));
            else flash('«' . $t . '»: обновление не удалось - ' . $st['error'], 'err');
        }
        redirect('admin.php');
    }
    if ($act === 'clear_cache' && $rep) {
        $n = 0; foreach (glob(report_dir($rid) . '/cache/*.json') ?: [] as $f) { if (@unlink($f)) $n++; }
        app_log('Очищен кэш «' . $rep['title'] . '»: ' . $n . ' файлов');
        flash('Кэш «' . $rep['title'] . '» очищен (' . $n . ' файлов). Следующее обновление загрузит всё заново.');
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
    if (in_array($act, ['code_check', 'code_update'], true)) {
        $u = upd_run('страница настроек', $act === 'code_update', !empty($_POST['force']), true);
        flash('Код сайта: ' . $u['msg'] . '.', $u['ok'] ? 'ok' : 'err');
        redirect('admin.php#code');
    }
    if ($act === 'code_rollback') {
        $u = upd_rollback();
        flash('Код сайта: ' . $u['msg'] . '.', $u['ok'] ? 'ok' : 'err');
        redirect('admin.php#code');
    }
    if ($act === 'code_auto') {
        $s['update_auto'] = !empty($_POST['auto']);
        save_settings($s);
        app_log('Автообновление кода: ' . ($s['update_auto'] ? 'включено' : 'выключено'));
        flash('Автообновление кода ' . ($s['update_auto'] ? 'включено' : 'выключено') . '.');
        redirect('admin.php#code');
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

$sa = read_json(DATA_DIR . '/service-account.json');
$logLines = is_file(DATA_DIR . '/app.log') ? array_slice(file(DATA_DIR . '/app.log', FILE_IGNORE_NEW_LINES), -80) : [];
$host = $_SERVER['HTTP_HOST'] ?? 'ваш-домен';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
$cronUrl = 'https://' . $host . $base . '/cron.php?key=' . cfg('cron_key');
$gsheet = function ($id) { return strpos((string)$id, 'csv:') === 0 ? e($id) : '<a href="https://docs.google.com/spreadsheets/d/' . e($id) . '/edit" target="_blank" rel="noopener noreferrer">' . e(substr((string)$id, 0, 10)) . '…</a>'; };
$updLast = upd_last(); $updState = upd_state();
$updWritable = is_writable(APP_ROOT) && is_writable(APP_ROOT . '/lib') && is_writable(APP_ROOT . '/reports');
$updZip = class_exists('ZipArchive') || class_exists('PharData');
$kinds = ['live' => 'основной файл', 'archive' => 'архив (загружен)', 'cache' => 'архив (из кэша)'];

page_head('Настройки - отчёты НЕ ШКОЛА'); ?>
<div class="admin">
<header class="top"><div class="wrap"><div class="brandrow">
  <div class="brand">НЕ ШКОЛА<small>Управляющая компания</small></div>
  <?php global_nav('admin'); ?>
  <div class="spacer"></div>
  <div class="navlinks"><span class="ver">версия <?= e(app_version()) ?></span>
  <form method="post" action="index.php?action=logout"><?= csrf_field() ?><button class="btn" type="submit">Выйти</button></form></div>
</div></div></header>
<div class="wrap">
<h1>Настройки</h1>
<div style="margin-top:12px"><?php flashes(); ?></div>

<section>
  <h2>Состояние отчётов</h2>
  <?php foreach (reports() as $id => $m): $st = report_status($id); ?>
    <h3 style="margin:14px 0 6px"><?= e($m['title']) ?></h3>
    <?php if (!$st): ?><p style="font-size:13px;margin:0">Данные ещё не загружались.</p>
    <?php else: ?>
      <div class="kv">
        <div>Последний запуск</div><div><?= e(fmt_dt($st['at'])) ?> · <?= e($st['trigger'] ?? '') ?> · <?= e($st['duration'] ?? '') ?> с</div>
        <div>Результат</div><div><?= $st['ok'] ? '<span class="ok-t">успешно</span>' : '<span class="err-t">ошибка: ' . e($st['error']) . '</span>' ?></div>
        <?php foreach ($st['summary'] ?? [] as $k => $v): ?><div><?= e($k) ?></div><div><?= e($v) ?></div><?php endforeach; ?>
      </div>
      <?php if (!empty($st['warnings'])): ?>
        <div class="flash err" style="margin-top:10px"><b>Предупреждения:</b><ul style="margin:6px 0 0 18px;padding:0"><?php foreach ($st['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
      <?php if (!empty($st['sources'])): $src = $st['sources']; ksort($src); ?>
        <details style="margin-top:8px"><summary style="cursor:pointer;font-size:13px">Источники (<?= count($src) ?>)</summary>
        <div class="tblwrap" style="margin-top:8px;max-height:none"><table>
          <thead><tr><th class="l">Период / файл</th><th class="l">Источник</th><th class="l">Таблица</th><th>Строк</th></tr></thead><tbody>
          <?php foreach ($src as $k => $x): ?>
            <tr><td class="l"><?= e(preg_match('/^\d{4}-\d{2}$/', (string)$k) ? month_label((string)$k) : $k) ?></td><td class="l"><?= e($kinds[$x['kind']] ?? $x['kind']) ?><?= isset($x['at']) ? ' от ' . e(fmt_dt($x['at'])) : '' ?></td><td class="l"><?= $gsheet($x['id']) ?></td><td><?= (int)$x['rows'] ?></td></tr>
          <?php endforeach; ?>
        </tbody></table></div></details>
      <?php endif; ?>
    <?php endif; ?>
    <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="update"><input type="hidden" name="id" value="<?= e($id) ?>"><button class="btn" type="submit">Обновить</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="clear_cache"><input type="hidden" name="id" value="<?= e($id) ?>"><button class="btn" type="submit" title="Заново загрузить все исходные файлы при следующем обновлении">Очистить кэш</button></form>
      <a class="btn" href="index.php?r=<?= e($id) ?>">Открыть отчёт</a>
    </div>
  <?php endforeach; ?>
  <div style="margin-top:16px"><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="update"><button class="btn primary" type="submit">Обновить все отчёты</button></form></div>
</section>

<?php foreach (reports() as $id => $m): if (empty($m['admin'])) continue; ?>
<section id="r-<?= e($id) ?>">
  <h2>Источники и параметры: <?= e($m['title']) ?></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="report_save"><input type="hidden" name="id" value="<?= e($id) ?>">
    <?php ($m['admin'])($s); ?>
    <button class="btn primary" type="submit">Сохранить</button>
  </form>
</section>
<?php endforeach; ?>

<section>
  <h2>Доступ к Google</h2>
  <?php if (!empty($s['dev_csv_dir'])): ?><div class="flash err">Включён тестовый режим чтения CSV (<?= e($s['dev_csv_dir']) ?>).</div><?php endif; ?>
  <div class="kv" style="margin-bottom:12px">
    <div>Сервисный аккаунт</div>
    <div><?= $sa ? '<b>' . e($sa['client_email']) . '</b>' : '<span class="err-t">ключ не загружен</span>' ?></div>
  </div>
  <?php if ($sa): ?><p style="font-size:13px;margin:0 0 12px">Этому адресу нужен доступ «Читатель» ко всем таблицам-источникам: файлы выручки и архивы, реестр задолженности.</p><?php endif; ?>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="key">
    <div class="field"><label>JSON-ключ сервисного аккаунта</label><input type="file" name="key" accept=".json,application/json" required></div>
    <button class="btn" type="submit"><?= $sa ? 'Заменить ключ' : 'Загрузить ключ' ?></button>
  </form>
</section>

<section>
  <h2>Ежедневное обновление</h2>
  <p style="font-size:13px;margin:0 0 8px">Задание в планировщике (cron) хостинга обновляет все отчёты по очереди. Вариант 1 - команда:</p>
  <pre><?= e('php ' . APP_ROOT . '/cron.php') ?></pre>
  <p style="font-size:13px;margin:12px 0 8px">Вариант 2 - если планировщик умеет только открывать ссылку:</p>
  <pre><?= e($cronUrl) ?></pre>
  <p class="hint" style="font-size:12px;color:var(--muted)">Ссылка содержит секретный ключ - не публикуйте её. Рекомендуемое расписание: каждые 2 часа с 07:00 до 23:00. Обновить один отчёт: добавьте <code>&amp;report=debt</code> к ссылке или <code>debt</code> к команде.</p>
</section>

<section id="code">
  <h2>Обновление кода сайта</h2>
  <p style="font-size:13px;margin:0 0 10px">Сайт сам скачивает новую версию из GitHub (<a href="https://github.com/<?= e(upd_repo()) ?>/commits/<?= e(upd_branch()) ?>" target="_blank" rel="noopener noreferrer"><?= e(upd_repo()) ?>, ветка <?= e(upd_branch()) ?></a>) и ставит её, только если для неё прошли автотесты. <code>config.php</code> и папка <code>data</code> не меняются. Перед заменой файлов сохраняется копия для отката.</p>
  <?php if (!$updWritable): ?><div class="flash err">PHP не может записывать файлы в папку сайта - самообновление работать не будет. Нужно, чтобы PHP на хостинге работал от того же пользователя, что владеет файлами (в ispmanager это режим PHP «CGI» или «FPM» от имени владельца сайта).</div><?php endif; ?>
  <?php if (!$updZip): ?><div class="flash err">На хостинге нет расширения PHP zip (и phar) - самообновление работать не будет. Включите zip в настройках PHP сайта.</div><?php endif; ?>
  <div class="kv" style="margin-bottom:12px">
    <div>Текущая версия</div><div><b><?= e(app_version()) ?></b><?= !empty($updState['at']) ? ' · поставлена ' . e(fmt_dt($updState['at'])) : '' ?></div>
    <div>Последняя проверка</div><div><?= $updLast ? e(fmt_dt($updLast['at'])) . ' · ' . e($updLast['trigger'] ?? '') . ' · ' . ($updLast['ok'] ? '' : '<span class="err-t">') . e($updLast['msg']) . ($updLast['ok'] ? '' : '</span>') : 'ещё не было' ?></div>
    <div>Автообновление</div><div><?= !empty($s['update_auto']) ? 'включено: при каждом запуске cron.php (см. «Ежедневное обновление»)' : 'выключено' ?></div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="code_check"><button class="btn" type="submit">Проверить обновления</button></form>
    <form method="post" style="display:flex;gap:10px;align-items:center"><?= csrf_field() ?><input type="hidden" name="act" value="code_update"><button class="btn primary" type="submit">Обновить код сейчас</button>
      <label style="font-size:12.5px;display:flex;gap:4px;align-items:center"><input type="checkbox" name="force" value="1"> даже если тесты не прошли</label></form>
    <?php if (!empty($updState['backup'])): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="code_rollback"><button class="btn" type="submit" title="Вернуть файлы, которые заменило последнее обновление">Откатить последнее обновление</button></form><?php endif; ?>
    <form method="post" style="display:flex;gap:6px;align-items:center"><?= csrf_field() ?><input type="hidden" name="act" value="code_auto">
      <label style="font-size:12.5px;display:flex;gap:4px;align-items:center"><input type="checkbox" name="auto" value="1"<?= !empty($s['update_auto']) ? ' checked' : '' ?>> ставить новые версии автоматически</label><button class="btn" type="submit">Сохранить</button></form>
  </div>
  <p class="hint" style="font-size:12px;color:var(--muted);margin-top:10px">Только код: <code><?= e('php ' . APP_ROOT . '/cron.php code') ?></code> или ссылка cron с <code>&amp;report=code</code>. После отката автообновление пропускает отменённую версию и ждёт следующую.</p>
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
  <p class="hint" style="font-size:12px;color:var(--muted);margin-top:10px">Логин: <b><?= e($s['auth']['user']) ?></b>. Если пароль забыт - удалите файл data/settings.json через файловый менеджер хостинга и пройдите setup.php заново.</p>
</section>

<section>
  <h2>Журнал</h2>
  <pre class="log"><?= e(implode("\n", array_reverse($logLines))) ?: 'Пусто' ?></pre>
</section>
</div></div>
<?php page_foot();
