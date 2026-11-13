<?php
define('APP_ROOT', __DIR__);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sources.php';
require __DIR__ . '/lib/builder.php';
require __DIR__ . '/templates/layout.php';
web_boot();

if (!settings()['auth']) redirect('setup.php');
$action = $_GET['action'] ?? '';
$loginError = null;

if ($action === 'login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $loginError = try_login(trim((string)($_POST['user'] ?? '')), (string)($_POST['pass'] ?? ''));
    if ($loginError === null) redirect('index.php');
}
if ($action === 'logout') {
    csrf_check();
    $_SESSION = [];
    session_destroy();
    redirect('index.php');
}
if (!is_logged_in()) {
    require __DIR__ . '/templates/login.php';
    exit;
}
if ($action === 'refresh') {
    csrf_check();
    $st = run_update('кнопка «Обновить»');
    if ($st['ok']) flash('Данные обновлены за ' . $st['duration'] . ' с.' . (!empty($st['warnings']) ? ' Есть предупреждения - см. «Настройки».' : ''));
    else flash('Обновление не удалось: ' . $st['error'], 'err');
    redirect('index.php');
}

$dataFile = DATA_DIR . '/dashboard.json';
$status = read_json(DATA_DIR . '/status.json');
require __DIR__ . '/templates/dashboard.php';
