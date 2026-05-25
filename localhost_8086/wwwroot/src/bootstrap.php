<?php
// Shared helpers to bridge ThinkPHP-style endpoints onto Flight.
// Compatibility: keep legacy token extraction/auth helpers available for old controller paths.

// Route all debug logs to the shared logfile under public/.
if (!defined('DCIM_LOG_FILE')) {
    define('DCIM_LOG_FILE', dirname(__DIR__) . '/public/logfile.log');
}
@ini_set('error_log', DCIM_LOG_FILE);

if (!function_exists('dcim_messages')) {
    function dcim_messages(): array
    {
        static $messages = null;
        if ($messages !== null) {
            return $messages;
        }
        $ensureUtf8 = static function ($value) use (&$ensureUtf8) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $value[$k] = $ensureUtf8($v);
                }
                return $value;
            }
            if (!is_string($value) || $value === '') {
                return $value;
            }
            if (preg_match('//u', $value) === 1) {
                return $value;
            }
            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($value, 'UTF-8', 'GB18030,GBK,GB2312');
                if (is_string($converted) && $converted !== '' && preg_match('//u', $converted) === 1) {
                    return $converted;
                }
            }
            if (function_exists('iconv')) {
                foreach (['GB18030', 'GBK', 'GB2312'] as $encoding) {
                    $converted = @iconv($encoding, 'UTF-8//IGNORE', $value);
                    if (is_string($converted) && $converted !== '' && preg_match('//u', $converted) === 1) {
                        return $converted;
                    }
                }
            }
            return $value;
        };
        $file = __DIR__ . '/messages.php';
        $loaded = is_file($file) ? require $file : [];
        $messages = is_array($loaded) ? $ensureUtf8($loaded) : [];
        $extraFile = __DIR__ . '/messages_extra.php';
        if (is_file($extraFile)) {
            $extraLoaded = require $extraFile;
            if (is_array($extraLoaded)) {
                $messages = array_replace_recursive($messages, $ensureUtf8($extraLoaded));
            }
        }
        return $messages;
    }
}

if (!function_exists('dcim_msg')) {
    function dcim_msg(string $key, $default = null, array $vars = []): string
    {
        $node = dcim_messages();
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                $node = null;
                break;
            }
            $node = $node[$segment];
        }
        $value = is_string($node) ? $node : (($default === null) ? $key : (string)$default);
        if (!$vars) {
            return $value;
        }
        $replace = [];
        foreach ($vars as $k => $v) {
            $replace['{' . $k . '}'] = (string)$v;
        }
        return strtr($value, $replace);
    }
}

if (!function_exists('normalize_utf8')) {
    function normalize_utf8($value)
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = normalize_utf8($v);
            }
            return $value;
        }
        if (is_string($value)) {
            if ($value === '') {
                return $value;
            }
            if (preg_match('//u', $value) === 1) {
                return $value;
            }
            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($value, 'UTF-8', 'GB18030,GBK,GB2312,BIG5,ISO-8859-1');
                if (is_string($converted) && $converted !== '' && preg_match('//u', $converted) === 1) {
                    return $converted;
                }
            }
            if (function_exists('iconv')) {
                foreach (['GB18030', 'GBK', 'GB2312', 'BIG5', 'ISO-8859-1'] as $sourceEnc) {
                    $converted = @iconv($sourceEnc, 'UTF-8//IGNORE', $value);
                    if (is_string($converted) && $converted !== '' && preg_match('//u', $converted) === 1) {
                        return $converted;
                    }
                }
                $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
                if (is_string($converted) && $converted !== '') {
                    return $converted;
                }
            }
        }
        return $value;
    }
}

if (!function_exists('dcim_trim_fractional_seconds')) {
    function dcim_trim_fractional_seconds_inplace(&$value, int $depth = 0)
    {
        if ($depth > 32) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $k => &$v) {
                dcim_trim_fractional_seconds_inplace($v, $depth + 1);
            }
            unset($v);
            return;
        }
        if (!is_string($value)) {
            return;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}\.\d{1,9}$/', $trimmed) === 1) {
            $value = preg_replace('/\.\d{1,9}$/', '', $trimmed);
        }
    }

    function dcim_trim_fractional_seconds($value)
    {
        dcim_trim_fractional_seconds_inplace($value, 0);
        return $value;
    }
}

if (!function_exists('json_string_response')) {
    function json_string_response($payload, int $httpCode = 200)
    {
        if (is_array($payload) && (array_key_exists('status', $payload) || array_key_exists('message', $payload))) {
            $status = isset($payload['status']) ? strtolower((string) $payload['status']) : '';
            $message = $payload['message'] ?? null;
            unset($payload['status'], $payload['message']);

            $isAuthFailure = $httpCode === 401;
            if (is_string($message)) {
                $msgLower = strtolower($message);
                if (strpos($msgLower, 'login required') !== false || strpos($msgLower, 'please login') !== false) {
                    $isAuthFailure = true;
                }
                if (stripos($message, 'login') !== false || stripos($message, 'auth') !== false || strpos($message, 'token') !== false) {
                    $isAuthFailure = true;
                }
            }

            if ($isAuthFailure) {
                $code = 300;
                $msg = dcim_msg('common.login_required');
            } elseif ($status === 'ok' || $status === 'success') {
                $code = 100;
                $msg = ($message !== null && $message !== '') ? $message : dcim_msg('common.success');
            } else {
                $code = $httpCode >= 100 ? $httpCode : 400;
                if ($code === 200) {
                    // Legacy callers often omit $httpCode on error payloads; keep business code semantic.
                    $code = 400;
                }
                $msg = ($message !== null && $message !== '') ? $message : dcim_msg('common.request_failed');
            }

            $payload = array_merge([
                'code' => $code,
                'msg' => $msg,
            ], $payload);
        }

        $payload = dcim_trim_fractional_seconds($payload);
        $payload = normalize_utf8($payload);

        http_response_code($httpCode);
        header('Content-Type: text/html; charset=utf-8');
        $flags = 0;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $encoded = json_encode($payload, $flags);
        if ($encoded === false) {
            error_log('json_encode failed: ' . json_last_error_msg());
            $payload = normalize_utf8($payload);
            $encoded = json_encode($payload, $flags | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($encoded === false) {
                error_log('json_encode retry failed: ' . json_last_error_msg());
                $encoded = '{}';
            }
        }
        // Some proxies/clients hang when neither Content-Length nor close semantics
        // are explicit on dynamic responses.
        if (!headers_sent()) {
            header('Content-Length: ' . strlen($encoded));
            header('Connection: close');
        }
        echo $encoded;
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        exit;
    }
}
if (!function_exists('app_json_response')) {
    function app_json_response($data, $message = null, $status = 'ok')
    {
        $isSuccess = in_array(strtolower((string) $status), ['ok', 'success'], true);
        $payload = [
            'code' => $isSuccess ? 100 : 400,
            'msg' => ($message !== null && $message !== '') ? $message : ($isSuccess ? dcim_msg('common.success') : dcim_msg('common.request_failed')),
            'data' => $data,
        ];
        json_string_response($payload);
    }
}

if (!function_exists('dcim_debug_log')) {
    function dcim_debug_log($message)
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        $file = defined('DCIM_LOG_FILE') ? DCIM_LOG_FILE : (__DIR__ . '/../public/logfile.log');
        @file_put_contents($file, $line, FILE_APPEND);
    }
}

if (!function_exists('tp_msg_success')) {
    function tp_msg_success()
    {
        return dcim_msg('common.success');
    }
}

if (!function_exists('tp_msg_login')) {
    function tp_msg_login()
    {
        return dcim_msg('common.login_required');
    }
}

if (!function_exists('dcim_auth_user_by_token')) {
    function dcim_auth_user_by_token($token)
    {
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            return null;
        }

        // Primary admin token from runtime config (current production token).
        $adminTokens = [];
        if (defined('DCIM_ADMIN_TOKEN') && (string) DCIM_ADMIN_TOKEN !== '') {
            $adminTokens[] = (string) DCIM_ADMIN_TOKEN;
        }
        $envExtraAdmin = getenv('DCIM_EXTRA_ADMIN_TOKENS');
        if (is_string($envExtraAdmin) && trim($envExtraAdmin) !== '') {
            foreach (explode(',', $envExtraAdmin) as $tok) {
                $tok = trim((string) $tok);
                if ($tok !== '') {
                    $adminTokens[] = $tok;
                }
            }
        }
        $adminTokens = array_values(array_unique($adminTokens));
        if (in_array($token, $adminTokens, true)) {
            return ['id' => 1, 'status' => 1, 'token' => $token, 'isSuper' => 1, 'RoleId' => 1];
        }

        // Accept webToken configured by ZT login flow as a super token.
        try {
            $settingCrud = new CrudController('dcim-setting');
            $settingInfo = $settingCrud->findOne([
                ['webToken', '=', $token],
            ]);
            if ($settingInfo) {
                return ['id' => 1, 'status' => 1, 'token' => $token, 'isSuper' => 1, 'RoleId' => 1];
            }
        } catch (\Throwable $e) {
            // keep compatibility; continue fallback checks below
        }

        // Legacy token compatibility for old clients.
        $legacyTokens = [
            'ae01aebe046196313fc1daf1c14652d8',
            'ad52f8a9abc8492ed65a9efb9834f9e1',
        ];
        $envLegacy = getenv('DCIM_LEGACY_ADMIN_TOKENS');
        if (is_string($envLegacy) && trim($envLegacy) !== '') {
            foreach (explode(',', $envLegacy) as $tok) {
                $tok = trim((string) $tok);
                if ($tok !== '') {
                    $legacyTokens[] = $tok;
                }
            }
        }
        $legacyTokens = array_values(array_unique($legacyTokens));
        if (in_array($token, $legacyTokens, true)) {
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            $legacyAllowPaths = [
                '/CreateDmpageKey',
                '/GetAlarmListKey',
                '/GetDmpageListKey',
                '/GetDmpageDetailKey',
                '/ChangeDmpageKey',
                '/DelDmpageKey',
                '/GetDmpageMyMenus',
                '/GetEventListKey',
                '/GetHistoryAlarmsKey',
                '/GetDeviceCommandListKey',
                '/GetDevCommandListKey',
                '/CreateDeviceCommandSendKey',
                '/GetDeviceProtocolListKey',
                '/GetParamListKey',
                '/GetParamDetailKey',
                '/GetParamDayListKey',
                '/GetSnmpParamListKey',
                '/GroupAlarmStatisticKey',
                '/GetTransferEmpKey',
                '/ReceiveYWWorkOrderKey',
                '/SubmitYWWorkOrderKey',
                '/CheckYWWorkOrderKey',
                '/CreateAssetsStoreKey',
                '/GetAssetsStoreListKey',
                '/GetAssetsStoreDetailKey',
                '/ChangeAssetsStoreKey',
                '/DelAssetsStoreKey',
                '/InstallAssetsAndCabinetKey',
            ];
            foreach ($legacyAllowPaths as $p) {
                if ($p !== '' && strpos($uri, $p) !== false) {
                    return ['id' => 1, 'status' => 1, 'token' => $token, 'isSuper' => 1, 'RoleId' => 1];
                }
            }
        }

        $personCrud = new CrudController('dcim-person');
        return $personCrud->findOne([
            ['token', '=', $token],
            ['status', '=', 1],
        ]) ?: null;
    }
}

if (!function_exists('dcim_normalize_token_candidate')) {
    function dcim_normalize_token_candidate($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $token = trim((string) $value);
        if ($token === '') {
            return '';
        }
        if (stripos($token, 'Bearer ') === 0) {
            $token = trim(substr($token, 7));
        }
        return $token;
    }
}

if (!function_exists('dcim_extract_token_from_headers')) {
    function dcim_extract_token_from_headers(array $headers): string
    {
        $allowedKeys = [
            'auth' => true,
            'authorization' => true,
            'x-auth-token' => true,
            'token' => true,
        ];
        foreach ($headers as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalizedKey = strtolower(trim($key));
            if (!isset($allowedKeys[$normalizedKey])) {
                continue;
            }
            $token = dcim_normalize_token_candidate($value);
            if ($token !== '') {
                return $token;
            }
        }
        return '';
    }
}

if (!function_exists('dcim_extract_token')) {
    function dcim_extract_token(array $requestData = []): string
    {
        $candidateKeys = ['token', 'Token', 'auth', 'Auth', 'authorization', 'Authorization', 'x-auth-token', 'X-Auth-Token', 'x_auth_token'];

        foreach ($candidateKeys as $key) {
            if (!array_key_exists($key, $requestData)) {
                continue;
            }
            $token = dcim_normalize_token_candidate($requestData[$key]);
            if ($token !== '') {
                return $token;
            }
        }

        $headers = function_exists('getallheaders') ? (array) getallheaders() : [];
        $token = dcim_extract_token_from_headers($headers);
        if ($token !== '') {
            return $token;
        }

        $serverKeys = [
            'HTTP_AUTH',
            'HTTP_AUTHORIZATION',
            'REDIRECT_HTTP_AUTHORIZATION',
            'REDIRECT_AUTHORIZATION',
            'AUTHORIZATION',
            'Authorization',
            'HTTP_X_AUTH_TOKEN',
            'REDIRECT_HTTP_X_AUTH_TOKEN',
            'HTTP_TOKEN',
            'REDIRECT_HTTP_TOKEN',
        ];
        foreach ($serverKeys as $serverKey) {
            if (!isset($_SERVER[$serverKey])) {
                continue;
            }
            $token = dcim_normalize_token_candidate($_SERVER[$serverKey]);
            if ($token !== '') {
                return $token;
            }
        }

        // Final fallback: parse raw request body (JSON or form-urlencoded).
        $raw = dcim_read_raw_request_body();
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($candidateKeys as $key) {
                    if (!array_key_exists($key, $decoded)) {
                        continue;
                    }
                    $token = dcim_normalize_token_candidate($decoded[$key]);
                    if ($token !== '') {
                        return $token;
                    }
                }
            }

            $parsed = [];
            parse_str($raw, $parsed);
            if (is_array($parsed)) {
                foreach ($candidateKeys as $key) {
                    if (!array_key_exists($key, $parsed)) {
                        continue;
                    }
                    $token = dcim_normalize_token_candidate($parsed[$key]);
                    if ($token !== '') {
                        return $token;
                    }
                }
            }
        }

        return '';
    }
}

if (!function_exists('dcim_read_raw_request_body')) {
    function dcim_read_raw_request_body(): string
    {
        $raw = '';
        try {
            $request = Flight::request();
            if (is_object($request) && method_exists($request, 'getBody')) {
                $raw = (string) $request->getBody();
            }
        } catch (\Throwable $e) {
            $raw = '';
        }
        if ($raw === '') {
            $raw = (string) @file_get_contents('php://input');
        }
        if ($raw === '' && isset($GLOBALS['__dcim_raw_json_body']) && is_string($GLOBALS['__dcim_raw_json_body'])) {
            $raw = (string) $GLOBALS['__dcim_raw_json_body'];
        }
        if ($raw === '' && isset($_SERVER['DCIM_RAW_JSON_BODY']) && is_string($_SERVER['DCIM_RAW_JSON_BODY'])) {
            $raw = (string) $_SERVER['DCIM_RAW_JSON_BODY'];
        }
        return $raw;
    }
}
Flight::map('json_success', function ($data = null, int $code = 200) {
    $payload = ['code' => 100, 'msg' => dcim_msg('common.success')];
    if ($data !== null) {
        $payload['data'] = $data;
    }
    json_string_response($payload, $code);
});

Flight::map('json_error', function (string $message, int $code = 400, array $extra = []) {
    $isAuthFailure = ($code === 401) || (stripos($message, 'login') !== false) || (stripos($message, 'auth') !== false) || (strpos($message, 'token') !== false);
    $payload = [
        'code' => $isAuthFailure ? 300 : $code,
        'msg' => $isAuthFailure ? dcim_msg('common.login_required') : $message,
    ];
    if ($extra) {
        $payload = array_merge($payload, $extra);
    }
    json_string_response($payload, $code);
});

Flight::map('request_data', function ($key = null, $default = null) {
    $request = Flight::request();
    $queryData = $request->query->getData();

    $bodyData = $request->data->getData();
    if (!is_array($bodyData)) {
        $bodyData = [];
    }
    if (isset($_POST) && is_array($_POST)) {
        foreach ($_POST as $k => $v) {
            if (!array_key_exists($k, $bodyData) || $bodyData[$k] === '' || $bodyData[$k] === null) {
                $bodyData[$k] = $v;
            }
        }
    }
    if (isset($_REQUEST) && is_array($_REQUEST)) {
        foreach ($_REQUEST as $k => $v) {
            if (!array_key_exists($k, $bodyData) || $bodyData[$k] === '' || $bodyData[$k] === null) {
                $bodyData[$k] = $v;
            }
        }
    }

    // Merge raw JSON body for compatibility (some parsers may miss fields like token).
    $raw = dcim_read_raw_request_body();
    if (is_string($raw) && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            foreach ($json as $k => $v) {
                if (!array_key_exists($k, $bodyData) || $bodyData[$k] === '' || $bodyData[$k] === null) {
                    $bodyData[$k] = $v;
                }
            }
        }
    }

    $data = array_merge($queryData, $bodyData);
    if (!isset($data['token']) || $data['token'] === '' || $data['token'] === null) {
        $resolvedToken = dcim_extract_token($data);
        if ($resolvedToken !== '') {
            $data['token'] = $resolvedToken;
        }
    }
    if ($key === null) {
        return $data;
    }
    return array_key_exists($key, $data) ? $data[$key] : $default;
});

Flight::map('request_token', function () {
    $token = dcim_extract_token();
    if ($token === '') {
        $token = Flight::request_data('token', '');
    }
    if (($token === '' || $token === null) && isset($_POST) && is_array($_POST)) {
        foreach (['token', 'Token', 'auth', 'Auth', 'authorization', 'Authorization', 'x-auth-token', 'X-Auth-Token', 'x_auth_token'] as $k) {
            if (isset($_POST[$k]) && is_scalar($_POST[$k])) {
                $token = dcim_normalize_token_candidate($_POST[$k]);
                if ($token !== '') {
                    break;
                }
            }
        }
    }
    if ($token === '' || $token === null) {
        try {
            $raw = dcim_read_raw_request_body();
            if (trim($raw) !== '') {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    foreach (['token', 'Token', 'auth', 'Auth', 'authorization', 'Authorization', 'x-auth-token', 'X-Auth-Token', 'x_auth_token'] as $k) {
                        if (isset($json[$k]) && is_string($json[$k]) && trim($json[$k]) !== '') {
                            $token = $json[$k];
                            break;
                        }
                    }
                }
                if ($token === '' || $token === null) {
                    $parsed = [];
                    parse_str($raw, $parsed);
                    if (is_array($parsed)) {
                        foreach (['token', 'Token', 'auth', 'Auth', 'authorization', 'Authorization', 'x-auth-token', 'X-Auth-Token', 'x_auth_token'] as $k) {
                            if (isset($parsed[$k]) && is_string($parsed[$k]) && trim($parsed[$k]) !== '') {
                                $token = $parsed[$k];
                                break;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // keep empty token fallback
        }
    }
    return is_string($token) ? trim($token) : $token;
});

Flight::map('auth_user', function () {
    $token = Flight::request_token();
    return Flight::validateToken($token);
});

// ThinkPHP-style response helpers (result_json/P_E/O_E etc.)
if (!function_exists('result_json')) {
    function result_json($code = 0, $msg = '', $result = '', $num = null)
    {
        if ((int) $code === 300) {
            try {
                $requestData = Flight::request()->data->getData();
            } catch (\Throwable $e) {
                $requestData = [];
            }
            $token = '';
            if (is_array($requestData) && isset($requestData['token']) && is_string($requestData['token'])) {
                $token = $requestData['token'];
            }
            dcim_debug_log('[AUTH300] uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' token=' . ($token !== '' ? substr($token, 0, 8) . '...' : '<empty>'));
            $msg = tp_msg_login();
        } elseif ((int) $code === 100 && ($msg === null || $msg === '')) {
            $msg = tp_msg_success();
        }
        try {
            $combo = Flight::request_data('ComboBox', null);
            if ($combo !== null && $combo !== '') {
                if (is_array($result) && array_key_exists('info', $result) && array_key_exists('page', $result)) {
                    $result = $result['info'];
                }
            }
        } catch (\Throwable $e) {
        }
        if ($num === null) {
            if (is_array($result)) {
                $isList = array_keys($result) === range(0, count($result) - 1);
                if ($isList) {
                    $num = count($result);
                } else {
                    $num = count($result) > 0 ? 1 : false;
                }
            } elseif ($result === false || $result === null || $result === '') {
                $num = false;
            } else {
                $num = 1;
            }
        }
        $payload = [
            'code' => $code,
            'msg'  => $msg,
            'data' => $result,
            'num'  => $num,
        ];
        json_string_response($payload);
    }
}

if (!function_exists('result_json_string')) {
    function result_json_string($code = 0, $msg = '', $result = '', $num = null)
    {
        result_json($code, $msg, $result, $num);
    }
}

if (!function_exists('P_E_STR')) {
    function P_E_STR($msg = null, $data = false, $code = 400)
    {
        if ($msg === null || $msg === '') {
            $msg = dcim_msg('common.invalid_params');
        }
        result_json($code, $msg, $data);
    }
}

if (!function_exists('L_E_STR')) {
    function L_E_STR($msg = null, $data = false, $code = 300)
    {
        if ($msg === null || $msg === '') {
            $msg = dcim_msg('common.login_required');
        }
        result_json($code, $msg, $data, 0);
    }
}

if (!function_exists('O_E_STR')) {
    function O_E_STR($data = [], $msg = null, $code = 100, $num = null)
    {
        if ($msg === null || $msg === '') {
            $msg = dcim_msg('common.success');
        }
        result_json($code, $msg, $data, $num);
    }
}

if (!function_exists('P_E')) {
    function P_E($msg = null, $data = false, $code = 400)
    {
        if ($msg === null || $msg === '') {
            $msg = dcim_msg('common.invalid_params');
        }
        result_json($code, $msg, $data);
    }
}

if (!function_exists('L_E')) {
    function L_E($msg = null, $data = false, $code = 300)
    {
        if ($msg === null || $msg === '') {
            $msg = dcim_msg('common.login_required');
        }
        result_json($code, $msg, $data, 0);
    }
}

if (!function_exists('S_E')) {
    function S_E($msg = null, $data = false, $code = 500)
    {
        if ($msg === null || $msg === '') {
            $msg = dcim_msg('common.server_error');
        }
        result_json($code, $msg, $data);
    }
}

if (!function_exists('R_E')) {
    function R_E($msg = null, $data = false, $code = 403)
    {
        if ($msg === null || $msg === '') {
            $msg = dcim_msg('common.forbidden');
        }
        result_json($code, $msg, $data);
    }
}

if (!function_exists('O_E')) {
    function O_E($data = [], $msg = null, $code = 100, $num = null)
    {
        if ($msg === null) {
            $msg = tp_msg_success();
        }
        result_json($code, $msg, $data, $num);
    }
}

if (!function_exists('checkPassword')) {
    function checkPassword($password, $passwordStr)
    {
        $plain = is_string($password) ? $password : (string)$password;
        $stored = is_string($passwordStr) ? $passwordStr : (string)$passwordStr;
        if ($plain === '' || $stored === '') {
            return false;
        }
        try {
            if (password_verify($plain, $stored)) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        if (hash_equals($stored, $plain)) {
            return true;
        }
        $md5Lower = strtolower(md5($plain));
        if (hash_equals(strtolower($stored), $md5Lower)) {
            return true;
        }
        $sha1Lower = strtolower(sha1($plain));
        if (hash_equals(strtolower($stored), $sha1Lower)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('TokenMd5')) {
    function TokenMd5($uid)
    {
        $token = 'token_' . $uid . '_' . time();
        return md5($token);
    }
}

if (!function_exists('addLog')) {
    function addLog($str, $params = '', $empId = null)
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $emp = $empId;
            if ($emp === null || $emp === '' || (is_numeric($emp) && (int)$emp <= 0)) {
                $token = function_exists('dcim_extract_token') ? dcim_extract_token() : '';
                if ($token !== '' && function_exists('dcim_auth_user_by_token')) {
                    $u = dcim_auth_user_by_token($token);
                    if (is_array($u) && !empty($u['id'])) {
                        $emp = (int)$u['id'];
                    }
                }
            }
            if ($emp === null || $emp === '' || (is_numeric($emp) && (int)$emp <= 0)) {
                $emp = 1;
            }
            $paramsPayload = $params;
            if (($params === '' || $params === null) && $str !== 'login') {
                try {
                    $paramsPayload = Flight::request_data();
                } catch (\Throwable $e) {
                    $paramsPayload = '';
                }
            }
            $paramsJson = '';
            if ($paramsPayload !== '' && $paramsPayload !== null) {
                $paramsJson = is_string($paramsPayload) ? $paramsPayload : json_encode($paramsPayload, JSON_UNESCAPED_UNICODE);
                if (!is_string($paramsJson)) {
                    $paramsJson = '';
                }
                if (strlen($paramsJson) > 2000) {
                    $paramsJson = substr($paramsJson, 0, 2000);
                }
            }

            $db = Flight::db();
            $driver = '';
            try {
                $driver = strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME));
            } catch (\Throwable $e) {
                $driver = '';
            }

            $tableCandidates = ['dcim-syslog', 'dcim_syslog'];
            $isSafeIdentifier = static function (string $name): bool {
                return preg_match('/^[A-Za-z0-9_-]+$/', $name) === 1;
            };

            $getColumns = static function (string $tableName) use ($db, $driver, $isSafeIdentifier): array {
                static $cache = [];
                $tableName = trim($tableName);
                if ($tableName === '' || !$isSafeIdentifier($tableName)) {
                    return [];
                }

                $cacheKey = $driver . '|' . $tableName;
                if (isset($cache[$cacheKey])) {
                    return $cache[$cacheKey];
                }

                $columnMap = [];
                try {
                    if ($driver === 'dm') {
                        $stmt = $db->prepare('SELECT COLUMN_NAME FROM USER_TAB_COLUMNS WHERE UPPER(TABLE_NAME) = UPPER(:table_name) ORDER BY COLUMN_ID');
                        $stmt->bindValue(':table_name', $tableName);
                        $stmt->execute();
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($rows as $row) {
                            $col = trim((string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? ''));
                            if ($col !== '') {
                                $columnMap[strtolower($col)] = $col;
                            }
                        }
                    } elseif ($driver === 'mysql') {
                        $sql = 'SHOW COLUMNS FROM `' . str_replace('`', '``', $tableName) . '`';
                        $stmt = $db->query($sql);
                        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                        foreach ($rows as $row) {
                            $col = trim((string) ($row['Field'] ?? $row['field'] ?? ''));
                            if ($col !== '') {
                                $columnMap[strtolower($col)] = $col;
                            }
                        }
                    } else {
                        $stmt = $db->prepare('SELECT column_name FROM information_schema.columns WHERE LOWER(table_name) = LOWER(:table_name)');
                        $stmt->bindValue(':table_name', $tableName);
                        $stmt->execute();
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($rows as $row) {
                            $col = trim((string) ($row['column_name'] ?? $row['COLUMN_NAME'] ?? ''));
                            if ($col !== '') {
                                $columnMap[strtolower($col)] = $col;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $columnMap = [];
                }

                $cache[$cacheKey] = $columnMap;
                return $columnMap;
            };

            $buildInsertData = static function (array $columnMap) use ($str, $emp, $paramsJson, $ip): array {
                $pick = static function (array $cands) use ($columnMap): string {
                    foreach ($cands as $cand) {
                        $key = strtolower((string) $cand);
                        if (isset($columnMap[$key])) {
                            return $columnMap[$key];
                        }
                    }
                    return '';
                };

                $contentCol = $pick(['LogContext', 'LogContent', 'content', 'Content', 'Action', 'LogName', 'ActionName', 'SysLog', 'Operation', 'OperationContent', 'description', 'remark', 'Remark', 'Msg', 'Message']);
                $empCol = $pick(['CreateEmpId', 'EmpId', 'emp_id', 'UserId', 'user_id', 'UserLsh', 'CreateUser', 'OperatorId', 'HandlerEmpId']);
                $paramsCol = $pick(['params', 'Params', 'param', 'LogParams', 'LogData', 'Payload', 'Data']);
                $ipCol = $pick(['ip', 'Ip', 'IP', 'LogIp', 'ClientIp']);
                $typeCol = $pick(['LogType', 'Type', 'Category']);
                $statusCol = $pick(['status', 'Status', 'IsValid']);
                $createTimeCol = $pick(['create_time', 'CreateTime', 'AddTime', 'CreatedAt']);

                $insertData = [];
                if ($contentCol !== '') {
                    $insertData[$contentCol] = (string) $str;
                }
                if ($empCol !== '') {
                    $insertData[$empCol] = $emp;
                }
                if ($paramsCol !== '') {
                    $insertData[$paramsCol] = $paramsJson;
                }
                if ($ipCol !== '') {
                    $insertData[$ipCol] = $ip;
                }
                if ($typeCol !== '') {
                    $insertData[$typeCol] = dcim_msg('log.system_operation');
                }
                if ($statusCol !== '') {
                    $insertData[$statusCol] = 1;
                }
                if ($createTimeCol !== '') {
                    $insertData[$createTimeCol] = date('Y-m-d H:i:s');
                }

                return $insertData;
            };

            $resolvedTable = '';
            $resolvedColumns = [];
            foreach ($tableCandidates as $candidate) {
                $columns = $getColumns($candidate);
                if (!$columns) {
                    continue;
                }
                $resolvedTable = $candidate;
                $resolvedColumns = $columns;
                break;
            }

            $tablesToTry = [];
            if ($resolvedTable !== '') {
                $tablesToTry[] = $resolvedTable;
            }
            foreach ($tableCandidates as $candidate) {
                if (!in_array($candidate, $tablesToTry, true)) {
                    $tablesToTry[] = $candidate;
                }
            }

            $lastError = '';
            foreach ($tablesToTry as $tableName) {
                try {
                    $columnMap = ($tableName === $resolvedTable) ? $resolvedColumns : $getColumns($tableName);
                    $insertData = $buildInsertData($columnMap);
                    if (!$insertData) {
                        $insertData = [
                            'content' => (string) $str,
                            'EmpId' => $emp,
                            'params' => $paramsJson,
                            'ip' => $ip,
                            'status' => 1,
                            'create_time' => date('Y-m-d H:i:s'),
                        ];
                    }

                    $syslogCrud = new CrudController($tableName);
                    $insertId = $syslogCrud->insert($insertData);
                    if ($insertId !== false) {
                        return;
                    }
                } catch (\Throwable $e) {
                    $lastError = '[addLog] insert failed, driver=' . $driver . ', table=' . $tableName . ', err=' . $e->getMessage();
                }
            }

            if ($lastError !== '') {
                error_log($lastError);
            }
        } catch (\Throwable $e) {
            error_log('[addLog] failed: ' . $e->getMessage());
        }
    }
}

// Base64 image upload helpers
if (!function_exists('saveImg')) {
    function saveImg($upDir, $params, $num)
    {
        $base64Img = trim($params);
        if (preg_match('/^(data:\s*image\/(\w+);base64,)/', $base64Img, $result)) {
            $type = $result[2];
            if (in_array($type, ['pjpeg','jpeg','jpg','gif','bmp','png'])) {
                $newFile = $upDir . time() . $num . '.' . $type;
                if (file_put_contents($newFile, base64_decode(str_replace($result[1], '', $base64Img)))) {
                    return ['url' => str_replace('\\', '/', $newFile)];
                }
                S_E(dcim_msg('upload.save_failed'));
            } else {
                S_E(dcim_msg('upload.save_failed'));
            }
        }
        return $params;
    }
}

if (!function_exists('upload')) {
    function upload($params)
    {
        $basePath = dirname(__DIR__) . '/public/';
        $dir = 'uploads/' . date('Ymd', time()) . '/';
        $upDir = $basePath . $dir;
        if (!file_exists($upDir)) {
            mkdir($upDir, 0777, true);
        }
        if (empty($params)) return false;
        if (!is_array($params)) {
            $res = saveImg($upDir, $params, 0);
        } else {
            $res = [];
            foreach ($params as $k => $param) {
                $res[] = saveImg($upDir, $param, $k);
            }
        }
        // normalize url to be relative like original (without absolute base path)
        if (is_array($res) && isset($res['url'])) {
            $res['url'] = $dir . basename($res['url']);
        } elseif (is_array($res)) {
            foreach ($res as &$item) {
                if (isset($item['url'])) {
                    $item['url'] = $dir . basename($item['url']);
                }
            }
        }
        return $res;
    }
}



