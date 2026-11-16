<?php
/* Общая инициализация: конфиг, настройки, журнал, сессия, CSRF. */
defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('Нужен PHP 7.4 или новее. Сейчас: ' . PHP_VERSION);
}

$cfgFile = getenv('NS_CONFIG') ?: APP_ROOT . '/config.php';   // NS_CONFIG - только для автотестов
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('Не найден config.php. Скопируйте config.sample.php в config.php и заполните ключи.');
}
$GLOBALS['CFG'] = require $cfgFile;

function cfg(string $key, $default = null) { return $GLOBALS['CFG'][$key] ?? $default; }

date_default_timezone_set(cfg('timezone', 'Europe/Moscow'));
ini_set('display_errors', '0');
error_reporting(E_ALL);

define('DATA_DIR', rtrim(cfg('data_dir', APP_ROOT . '/data'), '/'));
foreach ([DATA_DIR, DATA_DIR . '/cache', DATA_DIR . '/sessions'] as $d) {
    if (!is_dir($d)) { @mkdir($d, 0750, true); }
}
if (!is_file(DATA_DIR . '/.htaccess')) {
    @file_put_contents(DATA_DIR . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
}

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/sources.php';
require_once __DIR__ . '/reports.php';

/* ---------- журнал ---------- */
function app_log(string $msg): void {
    $line = date('Y-m-d H:i:s') . '  ' . str_replace(["\r", "\n"], ' ', $msg) . "\n";
    @file_put_contents(DATA_DIR . '/app.log', $line, FILE_APPEND | LOCK_EX);
    $f = DATA_DIR . '/app.log';
    if (@filesize($f) > 2 * 1024 * 1024) { @rename($f, $f . '.1'); }
}
set_exception_handler(function ($e) {
    app_log('ОШИБКА: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    if (PHP_SAPI !== 'cli') { http_response_code(500); echo 'Внутренняя ошибка. Подробности - в журнале на странице настроек.'; }
    else { fwrite(STDERR, $e->getMessage() . "\n"); }
    exit(1);
});

/* ---------- настройки (data/settings.json) ---------- */
function default_settings(): array {
    $d = ['auth' => null];
    foreach (reports() as $m) $d = array_replace($d, $m['defaults'] ?? []);   // настройки по умолчанию каждого отчёта
    return $d;
}
function settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $f = DATA_DIR . '/settings.json';
    $s = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    $cache = array_replace(default_settings(), is_array($s) ? $s : []);
    return $cache;
}
function save_settings(array $s): void {
    write_json_atomic(DATA_DIR . '/settings.json', $s);
    @chmod(DATA_DIR . '/settings.json', 0640);
}

/* ---------- HTTP-окружение ---------- */
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') == 443) return true;
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    if (strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') return true;
    return false;
}
function web_boot(): void {
    if (cfg('force_https', true) && !is_https()) {
        header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
    $GLOBALS['CSP_NONCE'] = base64_encode(random_bytes(16));
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . $GLOBALS['CSP_NONCE'] . "'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('Cache-Control: no-store');
    $days = (int)cfg('session_days', 14);
    ini_set('session.gc_maxlifetime', (string)($days * 86400));
    ini_set('session.use_strict_mode', '1');
    session_save_path(DATA_DIR . '/sessions');
    session_name('nsdash');
    session_set_cookie_params([
        'lifetime' => $days * 86400, 'path' => '/', 'secure' => is_https(),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}
function csp_nonce(): string { return $GLOBALS['CSP_NONCE'] ?? ''; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Сессия устарела. Обновите страницу и повторите действие.');
    }
}
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $to): void { header('Location: ' . $to, true, 303); exit; }
function flash(?string $msg = null, string $kind = 'ok') {
    if ($msg !== null) { $_SESSION['flash'][] = [$kind, $msg]; return null; }
    $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f;
}

/* ---------- вход ---------- */
function is_logged_in(): bool {
    $a = settings()['auth'];
    return $a && !empty($_SESSION['user']) && hash_equals((string)$a['user'], (string)$_SESSION['user'])
        && ($_SESSION['pwv'] ?? '') === substr(hash('sha256', (string)$a['hash']), 0, 16);
}
function require_login(): void {
    if (!settings()['auth']) redirect('setup.php');
    if (!is_logged_in()) redirect('index.php');
}
function client_ip_key(): string { return substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|ns'), 0, 24); }
function login_attempts(?array $set = null): array {
    $f = DATA_DIR . '/login_attempts.json';
    if ($set !== null) { write_json_atomic($f, $set); return $set; }
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    return is_array($d) ? $d : [];
}
/** Возвращает null при успехе или текст ошибки. */
function try_login(string $user, string $pass): ?string {
    $a = settings()['auth'];
    $all = login_attempts(); $k = client_ip_key(); $now = time();
    foreach ($all as $kk => $v) { if ($now - ($v['t'] ?? 0) > 900) unset($all[$kk]); }
    if (($all[$k]['n'] ?? 0) >= 5) {
        $wait = (int)ceil((900 - ($now - $all[$k]['t'])) / 60);
        return 'Слишком много неудачных попыток. Повторите через ' . max(1, $wait) . ' мин.';
    }
    if ($a && hash_equals((string)$a['user'], $user) && password_verify($pass, (string)$a['hash'])) {
        unset($all[$k]); login_attempts($all);
        session_regenerate_id(true);
        $_SESSION['user'] = $a['user'];
        $_SESSION['pwv'] = substr(hash('sha256', (string)$a['hash']), 0, 16);
        app_log('Вход: ' . $user);
        return null;
    }
    $all[$k] = ['n' => ($all[$k]['n'] ?? 0) + 1, 't' => $now];
    login_attempts($all);
    app_log('Неудачный вход: ' . mb_substr($user, 0, 40));
    usleep(400000);
    return 'Неверный логин или пароль.';
}
