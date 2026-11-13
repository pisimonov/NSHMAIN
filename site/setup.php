<?php
/* Первичная настройка: создание логина и пароля. Работает, только пока вход не настроен. */
define('APP_ROOT', __DIR__);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/templates/layout.php';
web_boot();

if (settings()['auth']) redirect('index.php');
$err = null;
$keyOk = cfg('setup_key') && mb_strpos((string)cfg('setup_key'), 'ЗАМЕНИТЕ') === false && strlen((string)cfg('setup_key')) >= 16;

if ($keyOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $user = trim((string)($_POST['user'] ?? ''));
    $p1 = (string)($_POST['pass'] ?? ''); $p2 = (string)($_POST['pass2'] ?? '');
    $att = login_attempts(); $k = 'setup' . client_ip_key();
    if (($att[$k]['n'] ?? 0) >= 5 && time() - $att[$k]['t'] < 900) $err = 'Слишком много попыток. Повторите через 15 минут.';
    elseif (!hash_equals((string)cfg('setup_key'), (string)($_POST['key'] ?? ''))) { $err = 'Неверный ключ настройки (он записан в config.php, параметр setup_key).'; $att[$k] = ['n' => ($att[$k]['n'] ?? 0) + 1, 't' => time()]; login_attempts($att); }
    elseif (!preg_match('/^[\w.@-]{3,40}$/u', $user)) $err = 'Логин: 3-40 символов, буквы, цифры, точка, дефис, @.';
    elseif (mb_strlen($p1) < 10) $err = 'Пароль должен быть не короче 10 символов.';
    elseif ($p1 !== $p2) $err = 'Пароли не совпадают.';
    else {
        $s = settings();
        $s['auth'] = ['user' => $user, 'hash' => password_hash($p1, PASSWORD_DEFAULT), 'created' => date('c')];
        save_settings($s);
        app_log('Первичная настройка: создан пользователь ' . $user);
        redirect('index.php');
    }
}
page_head('Первичная настройка'); ?>
<div class="auth">
  <form class="box" method="post" action="setup.php" autocomplete="off">
    <h1>Первичная настройка</h1>
    <?php if (!$keyOk): ?>
      <div class="flash err">В config.php не задан ключ <code>setup_key</code> (не короче 16 символов). Откройте config.php, впишите случайную строку и обновите страницу.</div>
    <?php else: ?>
      <p>Создайте логин и пароль для входа в отчёт. Эта страница перестанет работать сразу после сохранения.</p>
      <?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>
      <?= csrf_field() ?>
      <div class="field"><label>Ключ настройки (из config.php, параметр setup_key)</label><input type="password" name="key" required></div>
      <div class="field"><label>Логин</label><input type="text" name="user" required value="<?= e($_POST['user'] ?? 'admin') ?>"></div>
      <div class="field"><label>Пароль (не короче 10 символов)</label><input type="password" name="pass" required autocomplete="new-password"></div>
      <div class="field"><label>Пароль ещё раз</label><input type="password" name="pass2" required autocomplete="new-password"></div>
      <button class="btn primary" type="submit" style="width:100%;justify-content:center;padding:10px">Сохранить</button>
    <?php endif; ?>
  </form>
</div>
<?php page_foot();
