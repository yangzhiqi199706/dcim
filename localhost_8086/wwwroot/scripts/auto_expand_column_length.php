<?php
/**
 * Auto expand string column length when current length is insufficient.
 *
 * Usage:
 *   php scripts/auto_expand_column_length.php --table=dcim_protocol_param --column=Unit --required=77 --execute=0
 *   php scripts/auto_expand_column_length.php --table=dcim_protocol_param --column=Unit --required=77 --execute=1
 *   php scripts/auto_expand_column_length.php --from-error="detailParams[17]字段[dcim_protocol_param.Unit]长度为77，超过数据库上限50，请调整导入数据后重试。" --execute=1
 *   php scripts/auto_expand_column_length.php --from-error="detailParams[17] field[dcim_protocol_param.Unit] length 77 exceeds max 50" --execute=1
 *
 * Optional:
 *   --headroom=8      add extra characters/bytes to reduce repeated alters (default: 8)
 *   --config=...      db config path (default: ../dbconfig.json)
 *   --dsn=... --user=... --password=...  override config file
 */

declare(strict_types=1);

function argValue(array $argv, string $name, ?string $default = null): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function boolArg(array $argv, string $name, bool $default): bool
{
    $v = argValue($argv, $name, null);
    if ($v === null) {
        return $default;
    }
    $v = strtolower(trim($v));
    return in_array($v, ['1', 'true', 'yes', 'y', 'on'], true);
}

function intArg(array $argv, string $name, int $default): int
{
    $v = argValue($argv, $name, null);
    if ($v === null || trim($v) === '' || !is_numeric($v)) {
        return $default;
    }
    return (int)$v;
}

function fail(string $msg, int $code = 1): void
{
    fwrite(STDERR, '[ERROR] ' . $msg . PHP_EOL);
    exit($code);
}

function loadDbConfig(array $argv): array
{
    $configPath = argValue($argv, 'config', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dbconfig.json');
    if (!is_string($configPath) || trim($configPath) === '' || !is_file($configPath)) {
        return [];
    }
    $content = file_get_contents($configPath);
    if ($content === false) {
        return [];
    }
    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
        $content = substr($content, 3);
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function buildConnectionConfig(array $argv): array
{
    $fileConfig = loadDbConfig($argv);
    $get = static function (string $key, $default = null) use ($fileConfig) {
        return array_key_exists($key, $fileConfig) ? $fileConfig[$key] : $default;
    };

    $dsn = argValue($argv, 'dsn', null);
    $user = argValue($argv, 'user', null);
    $password = argValue($argv, 'password', null);
    if ($dsn !== null && $user !== null && $password !== null) {
        return [
            'dsn' => $dsn,
            'user' => $user,
            'password' => $password,
        ];
    }

    $type = strtolower((string)$get('type', 'dameng'));
    $driverMatrix = [
        'mysql' => ['driver' => 'mysql', 'port' => 3306],
        'pgsql' => ['driver' => 'pgsql', 'port' => 5432],
        'opengauss' => ['driver' => 'pgsql', 'port' => 5432],
        'kingbase' => ['driver' => 'pgsql', 'port' => 5432],
        'dameng' => ['driver' => 'dm', 'port' => 5236],
    ];
    $driverInfo = $driverMatrix[$type] ?? ['driver' => $type, 'port' => 3306];
    $driver = $driverInfo['driver'];
    $host = (string)$get('host', '127.0.0.1');
    $port = (string)$get('port', $driverInfo['port']);
    $name = (string)$get('name', 'dcim');
    $schema = (string)$get('schema', $name);
    $charset = (string)$get('charset', 'utf8');
    $dsn = (string)$get('dsn', '');
    if ($dsn === '') {
        if ($driver === 'dm') {
            $dsn = sprintf('dm:host=%s;port=%s;schema=%s', $host, $port, $schema);
        } elseif ($driver === 'pgsql') {
            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);
        } elseif ($driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);
        } else {
            $dsn = sprintf('%s:host=%s;port=%s;dbname=%s', $driver, $host, $port, $name);
        }
    }

    return [
        'dsn' => $dsn,
        'user' => $user ?? (string)$get('user', 'dcim'),
        'password' => $password ?? (string)$get('password', ''),
    ];
}

function dbDriver(PDO $db): string
{
    try {
        return strtolower((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME));
    } catch (Throwable $e) {
        return '';
    }
}

function quoteName(PDO $db, string $name): string
{
    $quote = dbDriver($db) === 'mysql' ? '`' : '"';
    $escaped = str_replace($quote, $quote . $quote, $name);
    return $quote . $escaped . $quote;
}

function parseTargetFromError(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    $patterns = [
        '/\[(?<table>[A-Za-z0-9_-]+)\.(?<column>[A-Za-z0-9_]+)\].*?(?<required>\d+)/u',
        '/field\[(?<table>[A-Za-z0-9_-]+)\.(?<column>[A-Za-z0-9_]+)\]\s+length\s+(?<required>\d+)/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $raw, $m) === 1) {
            return [
                'table' => (string)($m['table'] ?? ''),
                'column' => (string)($m['column'] ?? ''),
                'required' => (int)($m['required'] ?? 0),
            ];
        }
    }
    return null;
}

function getColumnMeta(PDO $db, string $table, string $column): array
{
    $driver = dbDriver($db);
    if ($driver === 'dm') {
        $stmt = $db->prepare(
            'SELECT DATA_TYPE, DATA_LENGTH, NULLABLE, DATA_DEFAULT
             FROM USER_TAB_COLUMNS
             WHERE UPPER(TABLE_NAME)=UPPER(:table) AND UPPER(COLUMN_NAME)=UPPER(:column)'
        );
        $stmt->execute([':table' => $table, ':column' => $column]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            fail("Column not found: {$table}.{$column}");
        }
        return [
            'driver' => 'dm',
            'data_type' => strtoupper((string)($row['DATA_TYPE'] ?? $row['data_type'] ?? '')),
            'length' => (int)($row['DATA_LENGTH'] ?? $row['data_length'] ?? 0),
        ];
    }

    if ($driver === 'mysql') {
        $stmt = $db->prepare(
            'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1'
        );
        $stmt->execute([':table' => $table, ':column' => $column]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            fail("Column not found: {$table}.{$column}");
        }
        return [
            'driver' => 'mysql',
            'data_type' => strtoupper((string)($row['DATA_TYPE'] ?? $row['data_type'] ?? '')),
            'length' => (int)($row['CHARACTER_MAXIMUM_LENGTH'] ?? $row['character_maximum_length'] ?? 0),
        ];
    }

    fail('Unsupported driver for this script: ' . $driver);
    return [];
}

function buildAlterSql(PDO $db, string $table, string $column, string $baseType, int $newLen): string
{
    $driver = dbDriver($db);
    $qt = quoteName($db, $table);
    $qc = quoteName($db, $column);
    $type = strtoupper($baseType);

    if (!in_array($type, ['VARCHAR', 'VARCHAR2', 'NVARCHAR', 'NVARCHAR2', 'CHAR', 'NCHAR'], true)) {
        fail("Column type {$baseType} is not a variable/fixed char type, refuse auto alter.");
    }

    if ($driver === 'dm') {
        if ($type === 'VARCHAR2') {
            $type = 'VARCHAR';
        } elseif ($type === 'NVARCHAR2') {
            $type = 'NVARCHAR';
        }
        return sprintf('ALTER TABLE %s MODIFY (%s %s(%d))', $qt, $qc, $type, $newLen);
    }

    if ($driver === 'mysql') {
        if ($type === 'VARCHAR2') {
            $type = 'VARCHAR';
        } elseif ($type === 'NVARCHAR' || $type === 'NVARCHAR2') {
            $type = 'VARCHAR';
        }
        return sprintf('ALTER TABLE %s MODIFY %s %s(%d)', $qt, $qc, $type, $newLen);
    }

    fail('Unsupported driver for ALTER: ' . $driver);
    return '';
}

$table = trim((string)argValue($argv, 'table', ''));
$column = trim((string)argValue($argv, 'column', ''));
$required = intArg($argv, 'required', 0);
$headroom = max(0, intArg($argv, 'headroom', 8));
$execute = boolArg($argv, 'execute', false);
$fromError = (string)argValue($argv, 'from-error', '');

if ($fromError !== '') {
    $parsed = parseTargetFromError($fromError);
    if ($parsed === null) {
        fail('Cannot parse --from-error. Please pass --table/--column/--required explicitly.');
    }
    if ($table === '') {
        $table = $parsed['table'];
    }
    if ($column === '') {
        $column = $parsed['column'];
    }
    if ($required <= 0) {
        $required = (int)$parsed['required'];
    }
}

if ($table === '' || $column === '' || $required <= 0) {
    fail('Missing required args. Need --table=... --column=... --required=... (or --from-error=...).');
}

$connection = buildConnectionConfig($argv);
if (empty($connection['dsn']) || !array_key_exists('user', $connection) || !array_key_exists('password', $connection)) {
    fail('Missing database config. Provide --dsn/--user/--password or configure dbconfig.json.');
}

try {
    $db = new PDO(
        (string)$connection['dsn'],
        (string)$connection['user'],
        (string)$connection['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $meta = getColumnMeta($db, $table, $column);
    $currentLen = (int)($meta['length'] ?? 0);
    $dataType = (string)($meta['data_type'] ?? '');

    if ($currentLen <= 0) {
        fail("Current length is invalid for {$table}.{$column}");
    }

    $targetLen = max($required, $required + $headroom);
    if ($targetLen <= $currentLen) {
        echo '[SKIP] ' . $table . '.' . $column . ' current=' . $currentLen . ' required=' . $required . PHP_EOL;
        exit(0);
    }

    $sql = buildAlterSql($db, $table, $column, $dataType, $targetLen);
    echo '[PLAN] driver=' . dbDriver($db) . ' table=' . $table . ' column=' . $column
        . ' type=' . $dataType . ' current=' . $currentLen . ' required=' . $required
        . ' target=' . $targetLen . PHP_EOL;
    echo '[SQL] ' . $sql . PHP_EOL;

    if (!$execute) {
        echo '[DRYRUN] no changes applied. Use --execute=1 to apply.' . PHP_EOL;
        exit(0);
    }

    $db->exec($sql);
    echo '[DONE] altered successfully.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fail($e->getMessage(), 2);
}
