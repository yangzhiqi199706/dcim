<?php
/**
 * Legacy protocol -> importProtocolV2 format migrator
 *
 * Usage examples:
 * php scripts/migrate_protocol_legacy_to_v2.php --execute=0
 * php scripts/migrate_protocol_legacy_to_v2.php --execute=1
 * php scripts/migrate_protocol_legacy_to_v2.php --dsn="mysql:host=127.0.0.1;port=3306;dbname=dcim;charset=utf8mb4" --user=root --password=123456 --execute=0
 * php scripts/migrate_protocol_legacy_to_v2.php --dsn="mysql:host=127.0.0.1;port=3306;dbname=dcim;charset=utf8mb4" --user=root --password=123456 --execute=1
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
            'options' => [],
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
        'options' => [],
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

function quoteList(PDO $db, array $names): string
{
    return implode(',', array_map(static function (string $name) use ($db): string {
        return quoteName($db, $name);
    }, $names));
}

function tableIsReadable(PDO $db, string $table): bool
{
    try {
        $db->query('SELECT 1 FROM ' . quoteName($db, $table) . ' WHERE 1=0');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function rowValue(array $row, string $key, $default = null)
{
    if (array_key_exists($key, $row)) {
        return $row[$key];
    }
    $lower = strtolower($key);
    foreach ($row as $k => $v) {
        if (strtolower((string)$k) === $lower) {
            return $v;
        }
    }
    return $default;
}

function normalizeInt($value, int $default = 0): int
{
    if ($value === null || $value === '') {
        return $default;
    }
    if (is_numeric($value)) {
        return (int)$value;
    }
    return $default;
}

function normalizeFloatOrString($value)
{
    if ($value === null) {
        return '';
    }
    if (is_numeric($value)) {
        return (float)$value;
    }
    return trim((string)$value);
}

function parseLegacyProtocolValue(string $protocolValue): array
{
    $protocolValue = trim($protocolValue);
    if ($protocolValue === '') {
        return [];
    }

    $decoded = json_decode($protocolValue, true);
    if (is_array($decoded) && isset($decoded['commands']) && is_array($decoded['commands'])) {
        $result = [];
        foreach ($decoded['commands'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cmd = trim((string)($row['CommandType'] ?? ''));
            if ($cmd === '') {
                continue;
            }
            $result[$cmd] = [
                'CommandType' => $cmd,
                'CommandDesc' => trim((string)($row['CommandDesc'] ?? $cmd)),
                'RequestTemplate' => trim((string)($row['RequestTemplate'] ?? '')),
                'AddrMode' => trim((string)($row['AddrMode'] ?? 'hex_1byte')),
                'CrcMode' => trim((string)($row['CrcMode'] ?? 'modbus_crc16')),
                'Transport' => trim((string)($row['Transport'] ?? 'tcp')),
            ];
        }
        return $result;
    }

    $result = [];
    $segments = explode('|', $protocolValue);
    foreach ($segments as $seg) {
        $seg = trim($seg);
        if ($seg === '') {
            continue;
        }
        $parts = explode(':', $seg, 3);
        $commandType = trim((string)($parts[0] ?? ''));
        if ($commandType === '') {
            continue;
        }
        $result[$commandType] = [
            'CommandType' => $commandType,
            'CommandDesc' => trim((string)($parts[1] ?? $commandType)),
            'RequestTemplate' => trim((string)($parts[2] ?? '')),
            'AddrMode' => 'hex_1byte',
            'CrcMode' => 'modbus_crc16',
            'Transport' => 'tcp',
        ];
    }
    return $result;
}

function defaultAlarmMode(string $commandType, string $paramKey, string $dataType, string $alarmValue, string $alarmUp, string $alarmDown): array
{
    return [
        'AlarmType' => 0,
        'AlarmKey' => $paramKey,
        'AlarmName' => $paramKey,
        'AlarmUpLimit' => $alarmUp,
        'AlarmDownLimit' => $alarmDown,
        'AlarmValue' => $alarmValue,
        'PhoneNotify' => 0,
        'SMSNotify' => 0,
        'WeixinNotify' => 0,
        'WeComNotify' => 0,
        'DingdingNotify' => 0,
        'EmailNotify' => 0,
        'NoiseNotify' => 0,
        'UserID' => '1',
        'MasterID' => '0',
        'ConfirmNum' => 3,
        'NotifyNum' => 1,
        'IntervalTime' => 1800,
        'AlarmLevel' => 1,
        'UpgradeTime' => 0,
        'UpgradeUser' => null,
        'Linkage' => '',
        'CancelLinkage' => '',
        'snmpSource' => null,
        'LinkVideoChannel' => null,
        'CommandType' => $commandType,
        'DataType' => $dataType,
        'TogetherAlarm' => '',
        'NotifyWindowID' => 0,
        'status' => 1,
    ];
}

function normalizeAlarmMode(array $mode, string $commandType, string $paramKey, string $dataType): array
{
    $base = defaultAlarmMode($commandType, $paramKey, $dataType, '', '', '');
    foreach ($base as $k => $v) {
        if (array_key_exists($k, $mode)) {
            $base[$k] = $mode[$k];
        }
    }
    $base['AlarmKey'] = trim((string)($base['AlarmKey'] ?? $paramKey));
    if ($base['AlarmKey'] === '') {
        $base['AlarmKey'] = $paramKey;
    }
    $base['AlarmName'] = trim((string)($base['AlarmName'] ?? $base['AlarmKey']));
    if ($base['AlarmName'] === '') {
        $base['AlarmName'] = $base['AlarmKey'];
    }
    $base['CommandType'] = $commandType;
    $base['DataType'] = (string)($base['DataType'] ?? $dataType);
    $base['ConfirmNum'] = max(1, normalizeInt($base['ConfirmNum'], 3));
    $base['AlarmLevel'] = normalizeInt($base['AlarmLevel'], 1);
    if ($base['AlarmLevel'] < 1) {
        $base['AlarmLevel'] = 1;
    }
    if ($base['AlarmLevel'] > 5) {
        $base['AlarmLevel'] = 5;
    }
    return $base;
}

function parseParseModelsFromProtocolJson(string $protocolJson): array
{
    $protocolJson = trim($protocolJson);
    if ($protocolJson === '') {
        return [];
    }
    $decoded = json_decode($protocolJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    $models = [];
    if (isset($decoded['parseModels']) && is_array($decoded['parseModels'])) {
        $models = $decoded['parseModels'];
    } elseif (isset($decoded[0]) && is_array($decoded[0]) && isset($decoded[0]['CommandType'])) {
        $models = $decoded;
    }
    if (!$models) {
        return [];
    }

    $result = [];
    foreach ($models as $m) {
        if (!is_array($m)) {
            continue;
        }
        $cmd = trim((string)($m['CommandType'] ?? ''));
        if ($cmd === '') {
            continue;
        }
        $processType = trim((string)($m['ProcessType'] ?? '1'));
        $processModel = trim((string)($m['ProcessModel'] ?? ''));
        $params = [];
        if (isset($m['Params']) && is_array($m['Params'])) {
            foreach ($m['Params'] as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $paramNo = trim((string)($p['ParamNo'] ?? ''));
                $paramKey = trim((string)($p['AlarmKey'] ?? ($p['ParamName'] ?? '')));
                if ($paramNo === '' || $paramKey === '') {
                    continue;
                }
                $dataType = trim((string)($p['DataType'] ?? ''));
                $alarmMode = [];
                if (isset($p['AlarmMode']) && is_array($p['AlarmMode'])) {
                    $alarmMode = normalizeAlarmMode($p['AlarmMode'], $cmd, $paramKey, $dataType);
                } else {
                    $alarmMode = normalizeAlarmMode([], $cmd, $paramKey, $dataType);
                }
                $params[] = [
                    'ParamNo' => $paramNo,
                    'AlarmKey' => $paramKey,
                    'ParamName' => trim((string)($p['ParamName'] ?? $paramKey)),
                    'Offset' => normalizeInt($p['Offset'] ?? 0, 0),
                    'Scale' => normalizeFloatOrString($p['Scale'] ?? ''),
                    'Unit' => trim((string)($p['Unit'] ?? '')),
                    'DataLen' => trim((string)($p['DataLen'] ?? '')),
                    'DataOrder' => trim((string)($p['DataOrder'] ?? '')),
                    'DataType' => $dataType,
                    'ProcessModel' => trim((string)($p['ProcessModel'] ?? $processModel)),
                    'AlarmMode' => $alarmMode,
                ];
            }
        }
        $result[$cmd] = [
            'CommandType' => $cmd,
            'ProcessType' => $processType === '' ? '1' : $processType,
            'ProcessModel' => $processModel,
            'Params' => $params,
        ];
    }
    return $result;
}

function parseLegacyProtocolData(string $protocolData): array
{
    $protocolData = trim($protocolData);
    if ($protocolData === '') {
        return [];
    }
    $result = [];
    $segments = explode('|', $protocolData);
    foreach ($segments as $seg) {
        $seg = trim($seg);
        if ($seg === '') {
            continue;
        }
        $parts = explode('&', $seg, 3);
        $commandType = trim((string)($parts[0] ?? ''));
        if ($commandType === '') {
            continue;
        }
        $processType = trim((string)($parts[1] ?? '1'));
        $payload = trim((string)($parts[2] ?? ''));
        $params = [];
        if ($payload !== '') {
            $paramRows = explode(':', $payload);
            foreach ($paramRows as $row) {
                $row = trim($row);
                if ($row === '') {
                    continue;
                }
                $cols = explode(',', $row);
                $paramNo = trim((string)($cols[0] ?? ''));
                $paramName = trim((string)($cols[1] ?? ''));
                $paramKey = $paramName;
                if ($paramNo === '' || $paramKey === '') {
                    continue;
                }
                $rate = trim((string)($cols[2] ?? ''));
                $unit = trim((string)($cols[3] ?? ''));
                $dataLen = trim((string)($cols[4] ?? ''));
                $dataOrder = trim((string)($cols[5] ?? ''));
                $alarmValue = trim((string)($cols[6] ?? ''));
                $alarmUp = trim((string)($cols[7] ?? ''));
                $alarmDown = trim((string)($cols[8] ?? ''));
                $dataOffset = trim((string)($cols[9] ?? '0'));
                $dataFixed = trim((string)($cols[10] ?? '0'));
                $dataType = trim((string)($cols[11] ?? ''));
                $alarmMode = normalizeAlarmMode(
                    defaultAlarmMode($commandType, $paramKey, $dataType, $alarmValue, $alarmUp, $alarmDown),
                    $commandType,
                    $paramKey,
                    $dataType
                );
                $params[] = [
                    'ParamNo' => $paramNo,
                    'AlarmKey' => $paramKey,
                    'ParamName' => $paramName,
                    'Offset' => normalizeInt($dataOffset, 0),
                    'Scale' => normalizeFloatOrString($rate),
                    'Unit' => $unit,
                    'DataLen' => $dataLen,
                    'DataOrder' => $dataOrder,
                    'DataType' => $dataType,
                    'DataFixed' => $dataFixed,
                    'ProcessModel' => '',
                    'AlarmMode' => $alarmMode,
                ];
            }
        }
        $result[$commandType] = [
            'CommandType' => $commandType,
            'ProcessType' => $processType === '' ? '1' : $processType,
            'ProcessModel' => '',
            'Params' => $params,
        ];
    }
    return $result;
}

function ensureDetailTables(PDO $db): void
{
    if (dbDriver($db) !== 'mysql') {
        $required = [
            'dcim-deviceprotocol',
            'dcim_protocol_command',
            'dcim_protocol_param',
            'dcim_protocol_alarmmode',
            'dcim-device',
            'dcim-alarmnotifymode',
        ];
        $missing = [];
        foreach ($required as $table) {
            if (!tableIsReadable($db, $table)) {
                $missing[] = $table;
            }
        }
        if ($missing) {
            throw new RuntimeException(
                'Missing or unreadable tables for current driver: ' . implode(', ', $missing)
                . '. Create them first or run the matching schema SQL.'
            );
        }
        return;
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS `dcim_protocol_command` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `ProtocolCode` varchar(50) NOT NULL,
            `ProtocolType` varchar(20) NOT NULL DEFAULT '',
            `CommandType` varchar(50) NOT NULL,
            `CommandDesc` varchar(255) DEFAULT '',
            `RequestTemplate` text,
            `AddrMode` varchar(50) DEFAULT '',
            `CrcMode` varchar(50) DEFAULT '',
            `Transport` varchar(20) DEFAULT '',
            `SortNo` int(11) NOT NULL DEFAULT 0,
            `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `update_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `status` tinyint(4) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `idx_protocol_code` (`ProtocolCode`),
            KEY `idx_protocol_code_cmd` (`ProtocolCode`,`CommandType`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `dcim_protocol_param` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `ProtocolCode` varchar(50) NOT NULL,
            `CommandType` varchar(50) NOT NULL,
            `ParamNo` varchar(20) DEFAULT '',
            `ParamKey` varchar(255) NOT NULL DEFAULT '',
            `ParamName` varchar(255) DEFAULT '',
            `ProcessType` varchar(50) DEFAULT '',
            `ProcessModel` varchar(100) DEFAULT '',
            `Rate` varchar(50) DEFAULT '',
            `Unit` varchar(50) DEFAULT '',
            `DataLen` varchar(50) DEFAULT '',
            `DataOrder` varchar(50) DEFAULT '',
            `DataOffset` varchar(50) DEFAULT '',
            `DataFixed` varchar(50) DEFAULT '',
            `DataType` varchar(50) DEFAULT '',
            `SortNo` int(11) NOT NULL DEFAULT 0,
            `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `update_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `status` tinyint(4) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `idx_protocol_code` (`ProtocolCode`),
            KEY `idx_protocol_code_param` (`ProtocolCode`,`ParamKey`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `dcim_protocol_alarmmode` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `ProtocolCode` varchar(50) NOT NULL,
            `CommandType` varchar(50) DEFAULT NULL,
            `ParamKey` varchar(255) DEFAULT NULL,
            `AlarmType` int(4) DEFAULT 0,
            `AlarmKey` varchar(100) DEFAULT NULL,
            `AlarmName` varchar(255) DEFAULT NULL,
            `AlarmUpLimit` varchar(255) DEFAULT '',
            `AlarmDownLimit` varchar(255) DEFAULT '',
            `AlarmValue` varchar(255) DEFAULT '',
            `PhoneNotify` int(11) NOT NULL DEFAULT 0,
            `SMSNotify` int(11) NOT NULL DEFAULT 0,
            `WeixinNotify` int(1) NOT NULL DEFAULT 0,
            `WeComNotify` int(1) NOT NULL DEFAULT 0,
            `DingdingNotify` int(1) NOT NULL DEFAULT 0,
            `EmailNotify` int(11) NOT NULL DEFAULT 0,
            `NoiseNotify` int(11) NOT NULL DEFAULT 0,
            `UserID` varchar(255) DEFAULT '1',
            `MasterID` varchar(20) DEFAULT '0',
            `ConfirmNum` int(2) NOT NULL DEFAULT 3,
            `NotifyNum` int(2) NOT NULL DEFAULT 1,
            `IntervalTime` int(2) NOT NULL DEFAULT 1800,
            `AlarmLevel` int(1) NOT NULL DEFAULT 1,
            `UpgradeTime` int(4) DEFAULT 0,
            `UpgradeUser` varchar(20) DEFAULT NULL,
            `Linkage` varchar(255) DEFAULT '',
            `CancelLinkage` varchar(255) DEFAULT '',
            `snmpSource` int(11) DEFAULT NULL,
            `LinkVideoChannel` varchar(535) DEFAULT NULL,
            `DataType` varchar(11) DEFAULT NULL,
            `TogetherAlarm` varchar(535) NOT NULL DEFAULT '',
            `NotifyWindowID` int(11) NOT NULL DEFAULT 0,
            `create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `update_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `status` tinyint(4) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id`),
            KEY `idx_protocol_code` (`ProtocolCode`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
    );
}

function insertBrokenAlarm(PDO $db, int $devId): void
{
    $table = quoteName($db, 'dcim-alarmnotifymode');
    $cols = quoteList($db, ['AlarmType', 'AlarmKey', 'AlarmName', 'DevId', 'AlarmLevel', 'ConfirmNum', 'status', 'TogetherAlarm']);
    $stmt = $db->prepare(
        "INSERT INTO {$table}
        ({$cols})
        VALUES
        (5,'设备断开','设备断开',:devId,0,1,1,'')"
    );
    $stmt->execute([':devId' => $devId]);
}

function rebuildDeviceAlarms(PDO $db, string $protocolCode, array $detailAlarmModes): void
{
    $deviceTable = quoteName($db, 'dcim-device');
    $alarmTable = quoteName($db, 'dcim-alarmnotifymode');
    $q = $db->prepare(
        'SELECT ' . quoteName($db, 'id') . " FROM {$deviceTable} WHERE "
        . quoteName($db, 'ProtocolCode') . '=:code AND ' . quoteName($db, 'status') . '=1'
    );
    $q->execute([':code' => $protocolCode]);
    $devices = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$devices) {
        return;
    }
    $delete = $db->prepare(
        "DELETE FROM {$alarmTable} WHERE "
        . quoteName($db, 'DevId') . '=:id AND ' . quoteName($db, 'status') . '<>-1'
    );
    $alarmCols = [
        'AlarmType', 'AlarmKey', 'AlarmName', 'DevId', 'AlarmUpLimit', 'AlarmDownLimit', 'AlarmValue',
        'PhoneNotify', 'SMSNotify', 'WeixinNotify', 'WeComNotify', 'DingdingNotify', 'EmailNotify',
        'NoiseNotify', 'UserID', 'MasterID', 'ConfirmNum', 'NotifyNum', 'IntervalTime', 'AlarmLevel',
        'UpgradeTime', 'UpgradeUser', 'Linkage', 'CancelLinkage', 'snmpSource', 'LinkVideoChannel',
        'CommandType', 'DataType', 'TogetherAlarm', 'NotifyWindowID', 'status',
    ];
    $insert = $db->prepare(
        "INSERT INTO {$alarmTable}
        (" . quoteList($db, $alarmCols) . ")
        VALUES
        (:AlarmType,:AlarmKey,:AlarmName,:DevId,:AlarmUpLimit,:AlarmDownLimit,:AlarmValue,:PhoneNotify,:SMSNotify,:WeixinNotify,:WeComNotify,:DingdingNotify,:EmailNotify,:NoiseNotify,:UserID,:MasterID,:ConfirmNum,:NotifyNum,:IntervalTime,:AlarmLevel,:UpgradeTime,:UpgradeUser,:Linkage,:CancelLinkage,:snmpSource,:LinkVideoChannel,:CommandType,:DataType,:TogetherAlarm,:NotifyWindowID,:status)"
    );
    foreach ($devices as $device) {
        $devId = (int)rowValue($device, 'id', 0);
        if ($devId <= 0) {
            continue;
        }
        $delete->execute([':id' => $devId]);
        foreach ($detailAlarmModes as $mode) {
            $row = $mode;
            $row['DevId'] = $devId;
            $insert->execute([
                ':AlarmType' => $row['AlarmType'],
                ':AlarmKey' => $row['AlarmKey'],
                ':AlarmName' => $row['AlarmName'],
                ':DevId' => $row['DevId'],
                ':AlarmUpLimit' => $row['AlarmUpLimit'],
                ':AlarmDownLimit' => $row['AlarmDownLimit'],
                ':AlarmValue' => $row['AlarmValue'],
                ':PhoneNotify' => $row['PhoneNotify'],
                ':SMSNotify' => $row['SMSNotify'],
                ':WeixinNotify' => $row['WeixinNotify'],
                ':WeComNotify' => $row['WeComNotify'],
                ':DingdingNotify' => $row['DingdingNotify'],
                ':EmailNotify' => $row['EmailNotify'],
                ':NoiseNotify' => $row['NoiseNotify'],
                ':UserID' => $row['UserID'],
                ':MasterID' => $row['MasterID'],
                ':ConfirmNum' => $row['ConfirmNum'],
                ':NotifyNum' => $row['NotifyNum'],
                ':IntervalTime' => $row['IntervalTime'],
                ':AlarmLevel' => $row['AlarmLevel'],
                ':UpgradeTime' => $row['UpgradeTime'],
                ':UpgradeUser' => $row['UpgradeUser'],
                ':Linkage' => $row['Linkage'],
                ':CancelLinkage' => $row['CancelLinkage'],
                ':snmpSource' => $row['snmpSource'],
                ':LinkVideoChannel' => $row['LinkVideoChannel'],
                ':CommandType' => $row['CommandType'],
                ':DataType' => $row['DataType'],
                ':TogetherAlarm' => $row['TogetherAlarm'],
                ':NotifyWindowID' => $row['NotifyWindowID'],
                ':status' => $row['status'],
            ]);
        }
        insertBrokenAlarm($db, $devId);
    }
}

function shouldMigrateRow(array $row, bool $force): bool
{
    if ($force) {
        return true;
    }
    $protocolValue = trim((string)rowValue($row, 'ProtocolValue', ''));
    $protocolJson = trim((string)rowValue($row, 'ProtocolJson', ''));
    $valueDecoded = json_decode($protocolValue, true);
    $jsonDecoded = json_decode($protocolJson, true);
    $valueV2 = is_array($valueDecoded) && isset($valueDecoded['version']) && (int)$valueDecoded['version'] === 2 && isset($valueDecoded['commands']);
    $jsonV2 = is_array($jsonDecoded) && isset($jsonDecoded['version']) && (int)$jsonDecoded['version'] === 2 && isset($jsonDecoded['parseModels']);
    return !($valueV2 && $jsonV2);
}

function buildPayload(array $row): array
{
    $protocolCode = trim((string)rowValue($row, 'ProtocolCode', ''));
    $protocolType = trim((string)rowValue($row, 'ProtocolType', '0'));
    $protocolName = trim((string)rowValue($row, 'ProtocolName', $protocolCode));

    $commandsMap = parseLegacyProtocolValue((string)rowValue($row, 'ProtocolValue', ''));
    $parseModelsMap = parseParseModelsFromProtocolJson((string)rowValue($row, 'ProtocolJson', ''));
    if (!$parseModelsMap) {
        $parseModelsMap = parseLegacyProtocolData((string)rowValue($row, 'ProtocolData', ''));
    }

    foreach ($parseModelsMap as $cmd => $model) {
        if (!isset($commandsMap[$cmd])) {
            $commandsMap[$cmd] = [
                'CommandType' => $cmd,
                'CommandDesc' => $cmd,
                'RequestTemplate' => '',
                'AddrMode' => 'hex_1byte',
                'CrcMode' => 'modbus_crc16',
                'Transport' => 'tcp',
            ];
        }
    }

    $commands = [];
    $sortCommand = 0;
    foreach ($commandsMap as $cmd => $c) {
        $sortCommand++;
        $commands[] = [
            'CommandType' => $cmd,
            'CommandDesc' => trim((string)($c['CommandDesc'] ?? $cmd)),
            'RequestTemplate' => trim((string)($c['RequestTemplate'] ?? '')),
            'AddrMode' => trim((string)($c['AddrMode'] ?? 'hex_1byte')),
            'CrcMode' => trim((string)($c['CrcMode'] ?? 'modbus_crc16')),
            'Transport' => trim((string)($c['Transport'] ?? 'tcp')),
            'SortNo' => $sortCommand,
        ];
    }

    $parseModels = [];
    $detailCommands = [];
    $detailParams = [];
    $detailAlarmModes = [];
    $sortParam = 0;
    foreach ($commands as $idx => $command) {
        $cmd = $command['CommandType'];
        $model = $parseModelsMap[$cmd] ?? [
            'CommandType' => $cmd,
            'ProcessType' => '1',
            'ProcessModel' => '',
            'Params' => [],
        ];
        $params = [];
        foreach (($model['Params'] ?? []) as $p) {
            $paramNo = trim((string)($p['ParamNo'] ?? ''));
            $paramKey = trim((string)($p['AlarmKey'] ?? ($p['ParamName'] ?? '')));
            if ($paramNo === '' || $paramKey === '') {
                continue;
            }
            $paramName = trim((string)($p['ParamName'] ?? $paramKey));
            $dataType = trim((string)($p['DataType'] ?? ''));
            $alarmMode = normalizeAlarmMode((array)($p['AlarmMode'] ?? []), $cmd, $paramKey, $dataType);
            $offset = normalizeInt($p['Offset'] ?? 0, 0);
            $scale = normalizeFloatOrString($p['Scale'] ?? '');
            $unit = trim((string)($p['Unit'] ?? ''));
            $dataLen = trim((string)($p['DataLen'] ?? ''));
            $dataOrder = trim((string)($p['DataOrder'] ?? ''));
            $dataFixed = trim((string)($p['DataFixed'] ?? '0'));
            $processModel = trim((string)($p['ProcessModel'] ?? ($model['ProcessModel'] ?? '')));

            $params[] = [
                'ParamNo' => $paramNo,
                'AlarmKey' => $paramKey,
                'ParamName' => $paramName,
                'Offset' => $offset,
                'Scale' => $scale,
                'Unit' => $unit,
                'DataLen' => $dataLen,
                'DataOrder' => $dataOrder,
                'DataType' => $dataType,
                'ProcessModel' => $processModel,
                'AlarmMode' => $alarmMode,
            ];

            $sortParam++;
            $detailParams[] = [
                'ProtocolCode' => $protocolCode,
                'CommandType' => $cmd,
                'ParamNo' => $paramNo,
                'ParamKey' => $paramKey,
                'ParamName' => $paramName,
                'ProcessType' => trim((string)($model['ProcessType'] ?? '1')),
                'ProcessModel' => $processModel,
                'Rate' => is_scalar($scale) ? (string)$scale : '',
                'Unit' => $unit,
                'DataLen' => $dataLen,
                'DataOrder' => $dataOrder,
                'DataOffset' => (string)$offset,
                'DataFixed' => $dataFixed,
                'DataType' => $dataType,
                'SortNo' => $sortParam,
                'status' => 1,
            ];

            $detailAlarmModes[] = array_merge([
                'ProtocolCode' => $protocolCode,
                'CommandType' => $cmd,
                'ParamKey' => $paramKey,
            ], $alarmMode);
        }
        $parseModels[] = [
            'CommandType' => $cmd,
            'ProcessType' => trim((string)($model['ProcessType'] ?? '1')),
            'ProcessModel' => trim((string)($model['ProcessModel'] ?? '')),
            'Params' => $params,
        ];
        $detailCommands[] = [
            'ProtocolCode' => $protocolCode,
            'ProtocolType' => $protocolType,
            'CommandType' => $cmd,
            'CommandDesc' => $command['CommandDesc'],
            'RequestTemplate' => $command['RequestTemplate'],
            'AddrMode' => $command['AddrMode'],
            'CrcMode' => $command['CrcMode'],
            'Transport' => $command['Transport'],
            'SortNo' => $idx + 1,
            'status' => 1,
        ];
    }

    return [
        'ProtocolCode' => $protocolCode,
        'ProtocolType' => $protocolType,
        'ProtocolName' => $protocolName,
        'ProtocolValue' => [
            'version' => 2,
            'commands' => $commands,
        ],
        'ProtocolJson' => [
            'version' => 2,
            'protocolMeta' => [
                'ProtocolCode' => $protocolCode,
                'ProtocolName' => $protocolName,
                'ProtocolType' => $protocolType,
            ],
            'parseModels' => $parseModels,
        ],
        'detailCommands' => $detailCommands,
        'detailParams' => $detailParams,
        'detailAlarmModes' => $detailAlarmModes,
    ];
}

function runMigration(PDO $db, bool $execute, bool $force, bool $rebuildDeviceAlarmsFlag): void
{
    ensureDetailTables($db);

    $protocolTable = quoteName($db, 'dcim-deviceprotocol');
    $commandTable = quoteName($db, 'dcim_protocol_command');
    $paramTable = quoteName($db, 'dcim_protocol_param');
    $alarmModeTable = quoteName($db, 'dcim_protocol_alarmmode');

    $rows = $db->query(
        "SELECT * FROM {$protocolTable} WHERE " . quoteName($db, 'status') . '=1 ORDER BY ' . quoteName($db, 'id') . ' ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $total = count($rows);
    $need = 0;
    $done = 0;

    $updateStmt = $db->prepare(
        "UPDATE {$protocolTable}
         SET " . quoteName($db, 'ProtocolValue') . "=:ProtocolValue, "
         . quoteName($db, 'ProtocolData') . "='', "
         . quoteName($db, 'ProtocolJson') . "=:ProtocolJson, "
         . quoteName($db, 'ProtocolType') . "=:ProtocolType
         WHERE " . quoteName($db, 'id') . '=:id'
    );
    $delCmd = $db->prepare("DELETE FROM {$commandTable} WHERE " . quoteName($db, 'ProtocolCode') . '=:code');
    $delParam = $db->prepare("DELETE FROM {$paramTable} WHERE " . quoteName($db, 'ProtocolCode') . '=:code');
    $delAlarm = $db->prepare("DELETE FROM {$alarmModeTable} WHERE " . quoteName($db, 'ProtocolCode') . '=:code');
    $commandCols = ['ProtocolCode', 'ProtocolType', 'CommandType', 'CommandDesc', 'RequestTemplate', 'AddrMode', 'CrcMode', 'Transport', 'SortNo', 'status'];
    $insCmd = $db->prepare(
        "INSERT INTO {$commandTable}
        (" . quoteList($db, $commandCols) . ")
        VALUES
        (:ProtocolCode,:ProtocolType,:CommandType,:CommandDesc,:RequestTemplate,:AddrMode,:CrcMode,:Transport,:SortNo,:status)"
    );
    $paramCols = [
        'ProtocolCode', 'CommandType', 'ParamNo', 'ParamKey', 'ParamName', 'ProcessType', 'ProcessModel',
        'Rate', 'Unit', 'DataLen', 'DataOrder', 'DataOffset', 'DataFixed', 'DataType', 'SortNo', 'status',
    ];
    $insParam = $db->prepare(
        "INSERT INTO {$paramTable}
        (" . quoteList($db, $paramCols) . ")
        VALUES
        (:ProtocolCode,:CommandType,:ParamNo,:ParamKey,:ParamName,:ProcessType,:ProcessModel,:Rate,:Unit,:DataLen,:DataOrder,:DataOffset,:DataFixed,:DataType,:SortNo,:status)"
    );
    $alarmCols = [
        'ProtocolCode', 'CommandType', 'ParamKey', 'AlarmType', 'AlarmKey', 'AlarmName', 'AlarmUpLimit',
        'AlarmDownLimit', 'AlarmValue', 'PhoneNotify', 'SMSNotify', 'WeixinNotify', 'WeComNotify',
        'DingdingNotify', 'EmailNotify', 'NoiseNotify', 'UserID', 'MasterID', 'ConfirmNum', 'NotifyNum',
        'IntervalTime', 'AlarmLevel', 'UpgradeTime', 'UpgradeUser', 'Linkage', 'CancelLinkage',
        'snmpSource', 'LinkVideoChannel', 'DataType', 'TogetherAlarm', 'NotifyWindowID', 'status',
    ];
    $insAlarm = $db->prepare(
        "INSERT INTO {$alarmModeTable}
        (" . quoteList($db, $alarmCols) . ")
        VALUES
        (:ProtocolCode,:CommandType,:ParamKey,:AlarmType,:AlarmKey,:AlarmName,:AlarmUpLimit,:AlarmDownLimit,:AlarmValue,:PhoneNotify,:SMSNotify,:WeixinNotify,:WeComNotify,:DingdingNotify,:EmailNotify,:NoiseNotify,:UserID,:MasterID,:ConfirmNum,:NotifyNum,:IntervalTime,:AlarmLevel,:UpgradeTime,:UpgradeUser,:Linkage,:CancelLinkage,:snmpSource,:LinkVideoChannel,:DataType,:TogetherAlarm,:NotifyWindowID,:status)"
    );

    foreach ($rows as $row) {
        if (!shouldMigrateRow($row, $force)) {
            continue;
        }
        $need++;
        $payload = buildPayload($row);

        if (!$execute) {
            echo '[DRYRUN] id=' . rowValue($row, 'id', '?') . ' code=' . $payload['ProtocolCode'] . ' name=' . $payload['ProtocolName']
                . ' commands=' . count($payload['detailCommands'])
                . ' params=' . count($payload['detailParams']) . PHP_EOL;
            continue;
        }

        $db->beginTransaction();
        try {
            $updateStmt->execute([
                ':id' => rowValue($row, 'id', 0),
                ':ProtocolValue' => json_encode($payload['ProtocolValue'], JSON_UNESCAPED_UNICODE),
                ':ProtocolJson' => json_encode($payload['ProtocolJson'], JSON_UNESCAPED_UNICODE),
                ':ProtocolType' => $payload['ProtocolType'],
            ]);
            $code = $payload['ProtocolCode'];
            $delCmd->execute([':code' => $code]);
            $delParam->execute([':code' => $code]);
            $delAlarm->execute([':code' => $code]);

            foreach ($payload['detailCommands'] as $r) {
                $insCmd->execute($r);
            }
            foreach ($payload['detailParams'] as $r) {
                $insParam->execute($r);
            }
            foreach ($payload['detailAlarmModes'] as $r) {
                $insAlarm->execute($r);
            }
            if ($rebuildDeviceAlarmsFlag) {
                rebuildDeviceAlarms($db, $code, $payload['detailAlarmModes']);
            }

            $db->commit();
            $done++;
            echo '[OK] id=' . rowValue($row, 'id', '?') . ' code=' . $code
                . ' commands=' . count($payload['detailCommands'])
                . ' params=' . count($payload['detailParams']) . PHP_EOL;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo '[FAIL] id=' . rowValue($row, 'id', '?') . ' reason=' . $e->getMessage() . PHP_EOL;
            throw $e;
        }
    }

    echo '---- SUMMARY ----' . PHP_EOL;
    echo 'total_active=' . $total . PHP_EOL;
    echo 'need_migrate=' . $need . PHP_EOL;
    echo 'done=' . $done . PHP_EOL;
    echo 'execute=' . ($execute ? '1' : '0') . PHP_EOL;
}

$execute = boolArg($argv, 'execute', false);
$force = boolArg($argv, 'force', false);
$rebuildDeviceAlarmsFlag = boolArg($argv, 'rebuild-device-alarms', true);

$connection = buildConnectionConfig($argv);
if (empty($connection['dsn']) || !array_key_exists('user', $connection) || !array_key_exists('password', $connection)) {
    fwrite(STDERR, "Missing database config. Provide --dsn=... --user=... --password=... or --config=path/to/dbconfig.json\n");
    exit(1);
}

try {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    foreach (($connection['options'] ?? []) as $key => $value) {
        $options[(int)$key] = $value;
    }
    $db = new PDO((string)$connection['dsn'], (string)$connection['user'], (string)$connection['password'], $options);
    runMigration($db, $execute, $force, $rebuildDeviceAlarmsFlag);
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
