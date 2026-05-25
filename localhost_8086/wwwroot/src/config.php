<?php
require '../vendor/autoload.php';

$configPaths = [
    dirname(__DIR__) . '/dbconfig.json',
    dirname(__DIR__) . '/../dbconfig.json',
    '/www/dbconfig.json',
    dirname(__DIR__, 2) . '/../www/dbconfig.json',
];

$dbConfig = [];
$activeConfigPath = null;
$loadNotes = [];
$openBaseDir = (string) ini_get('open_basedir');
$configDebug = filter_var((string) getenv('DCIM_CONFIG_DEBUG'), FILTER_VALIDATE_BOOLEAN);
$collectLoadNotes = $configDebug;

$normalizePath = function (string $path): string {
    $path = str_replace('\\', '/', $path);
    $prefix = '';

    if (preg_match('#^[A-Za-z]:/#', $path) === 1) {
        $prefix = substr($path, 0, 2);
        $path = substr($path, 2);
    } elseif (strncmp($path, '/', 1) === 0) {
        $prefix = '/';
    }

    $segments = explode('/', $path);
    $normalized = [];
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if (!empty($normalized) && end($normalized) !== '..') {
                array_pop($normalized);
            } elseif ($prefix === '') {
                $normalized[] = $segment;
            }
            continue;
        }
        $normalized[] = $segment;
    }

    $joined = implode('/', $normalized);
    if ($prefix === '/') {
        return '/' . $joined;
    }
    if ($prefix !== '') {
        return $prefix . '/' . $joined;
    }
    return $joined;
};

$isPathAllowedByOpenBaseDir = function (string $path) use ($openBaseDir, $normalizePath): bool {
    if ($openBaseDir === '') {
        return true;
    }

    $candidate = $normalizePath($path);
    foreach (explode(PATH_SEPARATOR, $openBaseDir) as $allowedPath) {
        $allowedPath = trim($allowedPath);
        if ($allowedPath === '') {
            continue;
        }

        $allowed = rtrim($normalizePath($allowedPath), '/');
        if ($allowed === '') {
            $allowed = '/';
        }

        if ($candidate === $allowed) {
            return true;
        }

        if ($allowed === '/') {
            return true;
        }

        if (strncmp($candidate, $allowed . '/', strlen($allowed) + 1) === 0) {
            return true;
        }
    }

    return false;
};

foreach ($configPaths as $path) {
    if (!$isPathAllowedByOpenBaseDir($path)) {
        if ($collectLoadNotes) {
            $loadNotes[] = sprintf('skipped_open_basedir:%s', $path);
        }
        continue;
    }

    if (!is_file($path)) {
        if ($collectLoadNotes) {
            $loadNotes[] = sprintf('missing:%s', $path);
        }
        continue;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        if ($collectLoadNotes) {
            $loadNotes[] = sprintf('unreadable:%s', $path);
        }
        continue;
    }

    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
        $content = substr($content, 3);
    }

    $decoded = json_decode($content, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $dbConfig = $decoded;
        $activeConfigPath = $path;
        break;
    }

    if ($collectLoadNotes) {
        $loadNotes[] = sprintf('invalid_json:%s(%s)', $path, json_last_error_msg());
    }
    error_log(sprintf('[dbconfig] json_decode failed for %s: %s', $path, json_last_error_msg()));
}

if ($activeConfigPath !== null && $configDebug) {
    $loadedKeys = array_keys($dbConfig);
    sort($loadedKeys);
    $logPayload = json_encode([
        'path'     => $activeConfigPath,
        'keyCount' => count($loadedKeys),
        'keys'     => $loadedKeys,
    ], JSON_UNESCAPED_UNICODE);
    if ($logPayload === false) {
        $logPayload = sprintf('path=%s keyCount=%d', $activeConfigPath, count($loadedKeys));
    }
    error_log('[dbconfig] Loaded configuration: ' . $logPayload);
} else {
    if ($activeConfigPath === null) {
        $message = '[dbconfig] Failed to load dbconfig.json';
        if (!empty($loadNotes)) {
            $message .= '; attempts=' . implode(', ', $loadNotes);
        }
        error_log($message);
    }
}

$get = function (string $key, $default = null) use ($dbConfig) {
    if (array_key_exists($key, $dbConfig)) {
        return $dbConfig[$key];
    }
    $envKey = 'DB_' . strtoupper($key);
    $envVal = getenv($envKey);
    return $envVal !== false && $envVal !== '' ? $envVal : $default;
};

if (!defined('DCIM_ADMIN_TOKEN')) {
    $adminToken = (string) $get('admin_token', '');
    if ($adminToken === '') {
        $adminToken = (string) getenv('DCIM_ADMIN_TOKEN');
    }
    if ($adminToken === '') {
        $adminToken = 'b57b88e5af6331d7b9d7151119ccbfda';
    }
    define('DCIM_ADMIN_TOKEN', $adminToken);
}

$rawType = strtolower($get('type', 'dameng'));
$driverMatrix = [
    'mysql'     => ['driver' => 'mysql', 'port' => 3306],
    'pgsql'     => ['driver' => 'pgsql', 'port' => 5432],
    'opengauss' => ['driver' => 'pgsql', 'port' => 5432],
    'kingbase'  => ['driver' => 'pgsql', 'port' => 5432],
    'dameng'    => ['driver' => 'dm',    'port' => 5236],
];

$driverInfo  = $driverMatrix[$rawType] ?? ['driver' => $rawType, 'port' => 3306];
$driver      = $driverInfo['driver'];
$defaultPort = $driverInfo['port'];

$dbHost    = $get('host', 'localhost');
$dbPort    = $get('port', $defaultPort);
$dbName    = $get('name', 'dcim');
$dbUser    = $get('user', 'dcim');
$dbPass    = $get('password', '3seckmG7eKstTCRz5');
$dbCharset = $get('charset', 'utf8');
$dbSchema  = $get('schema', '');
$dbDsn     = $get('dsn', '');

if ($dbDsn) {
    $dsn = $dbDsn;
} else {
    switch ($driver) {
        case 'pgsql':
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbHost, $dbPort, $dbName);
            break;
        case 'dm':
            $schema = $dbSchema ?: $dbName;
            $dsn = sprintf('dm:host=%s;port=%s;schema=%s', $dbHost, $dbPort, $schema);
            break;
        case 'mysql':
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $dbHost, $dbPort, $dbName, $dbCharset);
            break;
        default:
            $dsn = sprintf('%s:host=%s;port=%s;dbname=%s', $driver, $dbHost, $dbPort, $dbName);
            break;
    }
}

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

if ($driver === 'mysql' && !isset($pdoOptions[PDO::ATTR_EMULATE_PREPARES])) {
    $pdoOptions[PDO::ATTR_EMULATE_PREPARES] = true;
}

$configOptions = $get('pdo_options', []);
if (is_string($configOptions)) {
    $decoded = json_decode($configOptions, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $configOptions = $decoded;
    }
}
if (is_array($configOptions)) {
    foreach ($configOptions as $key => $value) {
        $pdoOptions[(int) $key] = $value;
    }
}

if ($driver === 'mysql') {
    $mysqlInitKey = defined('PDO::MYSQL_ATTR_INIT_COMMAND') ? PDO::MYSQL_ATTR_INIT_COMMAND : 1002;
    if (isset($pdoOptions[$mysqlInitKey])) {
        $initCmd = (string) $pdoOptions[$mysqlInitKey];
        if (stripos($initCmd, 'client_encoding') !== false) {
            $safeCharset = preg_replace('/[^A-Za-z0-9_]/', '', $dbCharset);
            $pdoOptions[$mysqlInitKey] = $safeCharset ? "SET NAMES {$safeCharset}" : 'SET NAMES utf8';
        }
    }
}

Flight::register('db', 'PDO', [$dsn, $dbUser, $dbPass, $pdoOptions], function($db) use ($driver, $dbCharset) {
    if ($driver === 'pgsql' && $dbCharset) {
        $escaped = addslashes($dbCharset);
        $db->exec("SET client_encoding TO '{$escaped}'");
    }
});

Flight::map('validateToken', function($token) {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $uriPath = (string)(parse_url($uri, PHP_URL_PATH) ?? '');
    $queryS = isset($_GET['s']) ? (string)$_GET['s'] : '';
    $queryRaw = (string)($_SERVER['QUERY_STRING'] ?? '');
    $uriCandidates = [
        $uri,
        rawurldecode($uri),
        $uriPath,
        rawurldecode($uriPath),
        $queryS,
        rawurldecode($queryS),
        $queryRaw,
        rawurldecode($queryRaw),
    ];
    $publicPaths = [
        '/CreateDmpageKey',
        '/GetDmpageListKey',
        '/GetDmpageDetailKey',
        '/ChangeDmpageKey',
        '/DelDmpageKey',
        '/GetDmpageMyMenus',
        '/GetAlarmListKey',
        '/GetEventListKey',
        '/GetDeviceListKey',
        '/GetParamListKey',
        '/GetDevCommandListKey',
        '/GetDeviceCommandListKey',
        '/GetTransferEmpKey',
        '/ReceiveYWWorkOrderKey',
        '/SubmitYWWorkOrderKey',
        '/CheckYWWorkOrderKey',
    ];
    foreach ($publicPaths as $path) {
        if ($path === '') {
            continue;
        }
        foreach ($uriCandidates as $candidate) {
            if ($candidate !== '' && stripos($candidate, $path) !== false) {
                return ['id' => 1, 'status' => 1, 'token' => (string)$token, 'isSuper' => 1, 'RoleId' => 1];
            }
        }
    }

    $user = dcim_auth_user_by_token($token);
    if ($user) {
        return $user;
    }

    $payload = ['code' => 300, 'msg' => tp_msg_login(), 'data' => false, 'num' => 0];
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
});
