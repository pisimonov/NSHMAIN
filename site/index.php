<?php
/* Вход и страницы отчётов: index.php?r=<id отчёта>. */
define('APP_ROOT', __DIR__);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/templates/layout.php';
web_boot();

if (!settings()['auth']) redirect('setup.php');
$action = $_GET['action'] ?? '';
$loginError = null;

if ($action === 'login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $loginError = try_login(trim((string)($_POST['user'] ?? '')), (string)($_POST['pass'] ?? ''));
    if ($loginError === null) redirect('index.php' . (isset($_GET['r']) ? '?r=' . rawurlencode((string)$_GET['r']) : ''));
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

$all = reports();
if (!$all) exit('Не найдено ни одного отчёта в папке reports.');
$rid = (string)($_GET['r'] ?? '');
if (!isset($all[$rid])) $rid = (string)($_SESSION['last_report'] ?? array_key_first($all));
if (!isset($all[$rid])) $rid = array_key_first($all);
$_SESSION['last_report'] = $rid;
$report = $all[$rid];

if ($action === 'refresh') {
    csrf_check();
    $st = run_report_update($rid, 'кнопка «Обновить»');
    if ($st['ok']) flash('Данные обновлены за ' . $st['duration'] . ' с.' . (!empty($st['warnings']) ? ' Есть предупреждения - см. «Настройки».' : ''));
    else flash('Обновление не удалось: ' . $st['error'], 'err');
    redirect('index.php?r=' . rawurlencode($rid));
}
require __DIR__ . '/templates/report.php';
