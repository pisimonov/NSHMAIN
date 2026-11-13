<?php
/* Источники данных: Google Sheets API (сервисный аккаунт) и локальные CSV (для проверки без Google). */

interface SheetSource {
    /** Возвращает строки листа как массив массивов. Пустые хвостовые ячейки могут отсутствовать. */
    public function values(string $spreadsheetId, string $sheet, string $cols): array;
}

function http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 90): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException('Сетевая ошибка: ' . $err);
        return [$code, $resp];
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", array_merge($headers, ['Accept-Encoding: identity'])),
        'content' => $body ?? '', 'timeout' => $timeout, 'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) throw new RuntimeException('Сетевая ошибка при обращении к ' . parse_url($url, PHP_URL_HOST));
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) $code = (int)$m[1];
    return [$code, $resp];
}

final class GoogleSheetsSource implements SheetSource {
    private $sa; private $tokenFile;

    public function __construct(string $keyFile, string $tokenFile) {
        if (!is_file($keyFile)) throw new RuntimeException('Не загружен ключ сервисного аккаунта Google (страница «Настройки» → «Доступ к Google»).');
        $sa = json_decode((string)file_get_contents($keyFile), true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) throw new RuntimeException('Файл ключа сервисного аккаунта повреждён или имеет неверный формат.');
        $this->sa = $sa; $this->tokenFile = $tokenFile;
    }

    private static function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

    private function token(): string {
        $t = read_json($this->tokenFile);
        if (is_array($t) && ($t['exp'] ?? 0) > time() + 120 && ($t['email'] ?? '') === $this->sa['client_email']) return $t['token'];
        $now = time();
        $aud = $this->sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $jwt = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . self::b64url(json_encode([
            'iss' => $this->sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
            'aud' => $aud, 'iat' => $now, 'exp' => $now + 3600,
        ]));
        $key = openssl_pkey_get_private($this->sa['private_key']);
        if (!$key || !openssl_sign($jwt, $sig, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Не удалось подписать запрос ключом сервисного аккаунта (нужно расширение PHP openssl).');
        $jwt .= '.' . self::b64url($sig);
        [$code, $resp] = http_request('POST', $aud, ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]), 30);
        $d = json_decode($resp, true);
        if ($code !== 200 || empty($d['access_token'])) throw new RuntimeException('Google не выдал токен доступа (' . $code . '): ' . mb_substr((string)($d['error_description'] ?? $resp), 0, 200));
        write_json_atomic($this->tokenFile, ['token' => $d['access_token'], 'exp' => $now + (int)($d['expires_in'] ?? 3600), 'email' => $this->sa['client_email']]);
        return $d['access_token'];
    }

    public function email(): string { return $this->sa['client_email']; }

    public function values(string $spreadsheetId, string $sheet, string $cols): array {
        $range = "'" . str_replace("'", "''", $sheet) . "'!" . $cols;
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range)
             . '?valueRenderOption=UNFORMATTED_VALUE&dateTimeRenderOption=FORMATTED_STRING&majorDimension=ROWS';
        for ($try = 1; ; $try++) {
            [$code, $resp] = http_request('GET', $url, ['Authorization: Bearer ' . $this->token()]);
            if ($code === 200) break;
            if (($code === 429 || $code >= 500) && $try < 4) { sleep(2 * $try); continue; }
            $d = json_decode($resp, true);
            $msg = $d['error']['message'] ?? mb_substr($resp, 0, 200);
            if ($code === 403 || $code === 404) $msg .= ' - проверьте, что таблица открыта на чтение для ' . $this->sa['client_email'] . ' и что в Google Cloud включён Google Sheets API.';
            throw new RuntimeException('Google Sheets (' . $code . ', ' . $spreadsheetId . ', лист «' . $sheet . '»): ' . $msg);
        }
        $d = json_decode($resp, true);
        return $d['values'] ?? [];
    }
}

/** Тестовый источник: id вида csv:2026-11 -> <dir>/2026-11.csv; лист архива -> <dir>/2026-11__archive.csv */
final class CsvDirSource implements SheetSource {
    private $dir;
    public function __construct(string $dir) { $this->dir = rtrim($dir, '/'); }
    public function values(string $spreadsheetId, string $sheet, string $cols): array {
        $name = preg_replace('~^csv:~', '', $spreadsheetId);
        $file = $this->dir . '/' . $name . ($sheet === settings()['archive_sheet'] ? '__archive' : '') . '.csv';
        if (!is_file($file)) throw new RuntimeException('CSV не найден: ' . basename($file));
        $out = []; $fh = fopen($file, 'r');
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) $out[] = $row;
        fclose($fh);
        return $out;
    }
}

function make_source(): SheetSource {
    $s = settings();
    if (!empty($s['dev_csv_dir'])) return new CsvDirSource($s['dev_csv_dir']);
    return new GoogleSheetsSource(DATA_DIR . '/service-account.json', DATA_DIR . '/token.json');
}
