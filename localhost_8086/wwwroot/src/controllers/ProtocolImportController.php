<?php

class ProtocolImportController
{
    private static function crud(string $table): CrudController
    {
        return new CrudController($table);
    }

    private static function requireAuth(array $data = [])
    {
        $user = self::crud('dcim-person')->legacyEnsureAuth($data);
        if (!$user) {
            L_E(tp_msg_login());
        }
        return $user;
    }

    private static function resolveUploadFileMeta(): array
    {
        $extractAssocValue = static function (array $arr, array $keys) {
            foreach ($arr as $k => $v) {
                $key = strtolower(str_replace(["\0", '_', '-'], '', (string)$k));
                foreach ($keys as $expect) {
                    if ($key === strtolower(str_replace(["\0", '_', '-'], '', $expect))) {
                        return $v;
                    }
                }
            }
            return null;
        };

        $extractNode = static function ($node) use (&$extractNode, $extractAssocValue): array {
            $result = [];
            if (is_array($node)) {
                $tmpValue = $extractAssocValue($node, ['tmp_name', 'tmpName', 'tmp', 'path', 'pathname']);
                $nameValue = $extractAssocValue($node, ['name', 'fileName', 'filename', 'clientFilename', 'originalName']);
                if (is_string($tmpValue) && $tmpValue !== '' && (is_uploaded_file($tmpValue) || is_file($tmpValue))) {
                    $result[] = [
                        'tmp' => $tmpValue,
                        'name' => is_string($nameValue) ? $nameValue : '',
                    ];
                } elseif (is_array($tmpValue)) {
                    foreach ($tmpValue as $idx => $tmpOne) {
                        if (!is_string($tmpOne) || $tmpOne === '') {
                            continue;
                        }
                        if (!(is_uploaded_file($tmpOne) || is_file($tmpOne))) {
                            continue;
                        }
                        $nameOne = '';
                        if (is_array($nameValue) && array_key_exists($idx, $nameValue) && is_string($nameValue[$idx])) {
                            $nameOne = $nameValue[$idx];
                        }
                        $result[] = ['tmp' => $tmpOne, 'name' => $nameOne];
                    }
                }
                foreach ($node as $child) {
                    $result = array_merge($result, $extractNode($child));
                }
                return $result;
            }
            if (is_object($node)) {
                $tmp = null;
                $name = '';
                foreach (['getPathname', 'getRealPath'] as $method) {
                    if (!method_exists($node, $method)) {
                        continue;
                    }
                    try {
                        $val = $node->{$method}();
                        if (is_string($val) && $val !== '' && (is_uploaded_file($val) || is_file($val))) {
                            $tmp = $val;
                            break;
                        }
                    } catch (\Throwable $e) {
                    }
                }
                foreach (['getClientFilename', 'getFilename', 'getBasename'] as $method) {
                    if ($name !== '' || !method_exists($node, $method)) {
                        continue;
                    }
                    try {
                        $val = $node->{$method}();
                        if (is_string($val) && $val !== '') {
                            $name = $val;
                        }
                    } catch (\Throwable $e) {
                    }
                }
                if ($tmp !== null) {
                    $result[] = ['tmp' => $tmp, 'name' => $name];
                }
                $arrNode = (array)$node;
                if ($arrNode) {
                    $result = array_merge($result, $extractNode($arrNode));
                }
            }
            return $result;
        };

        $sourceFiles = [];
        if (is_array($_FILES) && !empty($_FILES)) {
            $sourceFiles = $_FILES;
        } else {
            try {
                $req = Flight::request();
                $reqFiles = is_object($req) && isset($req->files) ? (array)$req->files : [];
                if ($reqFiles) {
                    $sourceFiles = $reqFiles;
                }
            } catch (\Throwable $e) {
                $sourceFiles = [];
            }
        }
        if (!$sourceFiles && isset($_REQUEST['file'])) {
            $sourceFiles = ['file' => $_REQUEST['file']];
        }
        if (!$sourceFiles) {
            return ['tmp' => null, 'name' => ''];
        }

        $candidates = $extractNode($sourceFiles);
        foreach ($candidates as $item) {
            $tmp = isset($item['tmp']) && is_string($item['tmp']) ? $item['tmp'] : '';
            if ($tmp === '') {
                continue;
            }
            return [
                'tmp' => $tmp,
                'name' => isset($item['name']) && is_string($item['name']) ? $item['name'] : '',
            ];
        }
        return ['tmp' => null, 'name' => ''];
    }

    private static function resolveUploadFile(array $data = []): array
    {
        $meta = self::resolveUploadFileMeta();
        $tmp = is_string($meta['tmp'] ?? null) ? $meta['tmp'] : null;
        $name = (string)($meta['name'] ?? '');
        if ($tmp !== null && $tmp !== '') {
            return ['tmp' => $tmp, 'name' => $name];
        }

        $candidates = [];
        if (isset($data['file_path']) && is_string($data['file_path'])) {
            $candidates[] = $data['file_path'];
        }
        if (isset($data['file']) && is_string($data['file'])) {
            $candidates[] = $data['file'];
        }
        foreach ($candidates as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            if (is_file($path) && is_readable($path)) {
                return ['tmp' => $path, 'name' => $name];
            }
        }
        return ['tmp' => null, 'name' => $name];
    }

    private static function normalizeHeader($value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }
        $value = str_replace(["\xC2\xA0", ' '], '', $value);
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }

    private static function importNormalizeProtocolNameToken(string $raw): string
    {
        $value = trim($raw, " \t\n\r\0\x0B\"'");
        if ($value === '') {
            return '';
        }
        if (preg_match("/^UTF-8''(.+)$/i", $value, $m) === 1) {
            $value = rawurldecode((string)$m[1]);
        } elseif (strpos($value, '%') !== false) {
            $decoded = rawurldecode($value);
            if (is_string($decoded) && $decoded !== '') {
                $value = $decoded;
            }
        }
        $value = str_replace('\\', '/', $value);
        $base = basename($value);
        if ($base === '') {
            $base = $value;
        }
        $filename = pathinfo($base, PATHINFO_FILENAME);
        $name = is_string($filename) ? trim($filename) : '';
        return $name !== '' ? $name : trim($base);
    }

    private static function resolveProtocolImportName(array $data, string $originalName): string
    {
        foreach (['ProtocolName', 'protocolName'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                continue;
            }
            $resolved = self::importNormalizeProtocolNameToken($data[$key]);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        $candidates = [$originalName];
        foreach (['file_name', 'FileName', 'filename', 'name', 'file_path', 'path', 'file'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                continue;
            }
            $candidates[] = $data[$key];
        }

        $bestName = '';
        $bestScore = -1;
        foreach ($candidates as $candidate) {
            $name = self::importNormalizeProtocolNameToken((string)$candidate);
            if ($name === '') {
                continue;
            }
            $length = function_exists('mb_strlen') ? (int)mb_strlen($name, 'UTF-8') : strlen($name);
            $cjkCount = 0;
            if (preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $name, $matches) === false) {
                $cjkCount = 0;
            } else {
                $cjkCount = count($matches[0]);
            }
            $score = $cjkCount * 1000 + $length;
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestName = $name;
            }
        }
        return $bestName !== '' ? $bestName : 'protocol_import';
    }

    private static function readCell(array $row, ?int $index, $fallback = '')
    {
        if ($index !== null && array_key_exists($index, $row)) {
            return $row[$index];
        }
        return $fallback;
    }

    private static function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') {
                return false;
            }
        }
        return true;
    }

    private static function toInt($value, int $default = 0): int
    {
        if ($value === '' || $value === null) {
            return $default;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        return $default;
    }

    /**
     * Normalize open-ended range process types (e.g. 1<19->) for parsers that
     * only accept explicit end indexes.
     */
    private static function normalizeProcessTypeExpression(string $processType): string
    {
        $normalized = trim($processType);
        if ($normalized === '') {
            return '';
        }
        if (preg_match('/^(\d+)\s*<\s*(\d+)\s*-\s*>$/', $normalized, $m) === 1) {
            return $m[1] . '<' . $m[2] . '-999999>';
        }
        return $normalized;
    }

    private static function resolveColumnMaxLength(\PDO $db, string $table, string $column, int $fallback): int
    {
        if ($fallback < 0) {
            $fallback = 0;
        }
        static $cache = [];
        try {
            $driver = (string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable $e) {
            $driver = '';
        }
        $cacheKey = strtolower($driver . '|' . $table . '|' . $column . '|' . $fallback);
        if (array_key_exists($cacheKey, $cache)) {
            return (int)$cache[$cacheKey];
        }
        try {
            if ($driver === 'mysql') {
                $stmt = $db->prepare(
                    "SELECT CHARACTER_MAXIMUM_LENGTH AS max_len
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = :table
                       AND COLUMN_NAME = :column
                     LIMIT 1"
                );
                $stmt->execute([
                    ':table' => $table,
                    ':column' => $column,
                ]);
                $row = $stmt->fetch();
                $len = isset($row['max_len']) ? (int)$row['max_len'] : 0;
                if ($len > 0) {
                    $cache[$cacheKey] = $len;
                    return $len;
                }
            } elseif ($driver === 'pgsql') {
                $stmt = $db->prepare(
                    "SELECT character_maximum_length AS max_len
                     FROM information_schema.columns
                     WHERE table_name = :table
                       AND column_name = :column
                     LIMIT 1"
                );
                $stmt->execute([
                    ':table' => $table,
                    ':column' => strtolower($column),
                ]);
                $row = $stmt->fetch();
                $len = isset($row['max_len']) ? (int)$row['max_len'] : 0;
                if ($len > 0) {
                    $cache[$cacheKey] = $len;
                    return $len;
                }
            } elseif ($driver === 'dm') {
                $stmt = $db->prepare(
                    "SELECT DATA_LENGTH AS max_len, DATA_TYPE AS data_type
                     FROM USER_TAB_COLUMNS
                     WHERE UPPER(TABLE_NAME) = UPPER(:table)
                       AND UPPER(COLUMN_NAME) = UPPER(:column)"
                );
                $stmt->execute([
                    ':table' => $table,
                    ':column' => $column,
                ]);
                $row = $stmt->fetch();
                $dataType = strtoupper((string)($row['data_type'] ?? ($row['DATA_TYPE'] ?? '')));
                if ($dataType === 'CLOB' || $dataType === 'TEXT' || $dataType === 'LONG') {
                    $cache[$cacheKey] = 0;
                    return 0;
                }
                $len = isset($row['max_len'])
                    ? (int)$row['max_len']
                    : (isset($row['MAX_LEN']) ? (int)$row['MAX_LEN'] : 0);
                if ($len > 0) {
                    $cache[$cacheKey] = $len;
                    return $len;
                }
            }
        } catch (\Throwable $e) {
            error_log('[ImportDevicePtlKeyV2] resolveColumnMaxLength failed: ' . $e->getMessage());
        }
        $cache[$cacheKey] = $fallback;
        return $fallback;
    }

    private static function pickRowKeys(array $row, array $keys): array
    {
        $picked = [];
        foreach ($keys as $key) {
            $picked[$key] = array_key_exists($key, $row) ? $row[$key] : null;
        }
        return $picked;
    }

    private static function dbDriverName(\PDO $db): string
    {
        try {
            return strtolower((string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function quoteIdentifier(\PDO $db, string $identifier): string
    {
        $driver = self::dbDriverName($db);
        if ($driver === 'mysql') {
            $clean = str_replace('`', '', $identifier);
            return '`' . $clean . '`';
        }
        $clean = str_replace('"', '""', str_replace(['`', '"'], '', $identifier));
        return '"' . $clean . '"';
    }

    private static function stringLength(\PDO $db, string $value): int
    {
        if (self::dbDriverName($db) === 'dm') {
            return strlen($value);
        }
        if (function_exists('mb_strlen')) {
            return (int)mb_strlen($value, 'UTF-8');
        }
        return strlen($value);
    }

    private static function validateStringColumnLength(\PDO $db, string $table, string $column, string $value, int $fallback = 0, string $context = ''): void
    {
        $maxLen = self::resolveColumnMaxLength($db, $table, $column, $fallback);
        if ($maxLen <= 0) {
            return;
        }
        $len = self::stringLength($db, $value);
        if ($len <= $maxLen) {
            return;
        }
        $msg = dcim_msg('error.protocol_field_too_long');
        $msg = str_replace(
            ['{context}', '{table}', '{column}', '{length}', '{max}'],
            [$context, $table, $column, (string)$len, (string)$maxLen],
            $msg
        );
        P_E($msg);
    }

    private static function validateRowStringColumns(\PDO $db, string $table, array $row, array $fallbackMaxLens = [], string $context = ''): void
    {
        foreach ($row as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            $fallback = 0;
            if (isset($fallbackMaxLens[$key]) && is_numeric($fallbackMaxLens[$key])) {
                $fallback = (int)$fallbackMaxLens[$key];
            }
            self::validateStringColumnLength($db, $table, $key, $value, $fallback, $context);
        }
    }

    private static function validateProtocolPayloadForStorage(array $payload): void
    {
        $db = Flight::db();

        if (isset($payload['ProtocolName']) && is_string($payload['ProtocolName'])) {
            self::validateStringColumnLength($db, 'dcim-deviceprotocol', 'ProtocolName', $payload['ProtocolName'], 100, 'protocol');
        }
        if (isset($payload['ProtocolCode']) && is_string($payload['ProtocolCode'])) {
            self::validateStringColumnLength($db, 'dcim-deviceprotocol', 'ProtocolCode', $payload['ProtocolCode'], 10, 'protocol');
        }

        $commandFallbacks = [
            'ProtocolCode' => 50, 'ProtocolType' => 20, 'CommandType' => 50, 'CommandDesc' => 255,
            'AddrMode' => 50, 'CrcMode' => 50, 'Transport' => 20,
        ];
        if (isset($payload['detailCommands']) && is_array($payload['detailCommands'])) {
            foreach ($payload['detailCommands'] as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }
                self::validateRowStringColumns($db, 'dcim_protocol_command', $row, $commandFallbacks, 'detailCommands[' . $idx . ']');
            }
        }

        $paramFallbacks = [
            'ProtocolCode' => 50, 'CommandType' => 50, 'ParamNo' => 20, 'ParamKey' => 255, 'ParamName' => 255,
            'ProcessType' => 50, 'ProcessModel' => 100, 'Rate' => 50, 'Unit' => 100, 'DataLen' => 50,
            'DataOrder' => 50, 'DataOffset' => 50, 'DataFixed' => 50, 'DataType' => 50,
        ];
        if (isset($payload['detailParams']) && is_array($payload['detailParams'])) {
            foreach ($payload['detailParams'] as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }
                self::validateRowStringColumns($db, 'dcim_protocol_param', $row, $paramFallbacks, 'detailParams[' . $idx . ']');
            }
        }

        $alarmFallbacks = [
            'ProtocolCode' => 50, 'CommandType' => 50, 'ParamKey' => 255, 'AlarmKey' => 100, 'AlarmName' => 255,
            'AlarmUpLimit' => 255, 'AlarmDownLimit' => 255, 'AlarmValue' => 255, 'UserID' => 255,
            'MasterID' => 20, 'UpgradeUser' => 20, 'Linkage' => 255, 'CancelLinkage' => 255,
            'LinkVideoChannel' => 535, 'DataType' => 11, 'TogetherAlarm' => 535,
        ];
        if (isset($payload['detailAlarmModes']) && is_array($payload['detailAlarmModes'])) {
            foreach ($payload['detailAlarmModes'] as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }
                self::validateRowStringColumns($db, 'dcim_protocol_alarmmode', $row, $alarmFallbacks, 'detailAlarmModes[' . $idx . ']');
            }
        }
    }

    private static function persistProtocolMainRow(\PDO $db, array $payload, string $protocolValueStr, string $protocolJsonStr): void
    {
        $isDm = self::dbDriverName($db) === 'dm';
        $protocolName = (string)($payload['ProtocolName'] ?? '');
        $protocolCode = (string)($payload['ProtocolCode'] ?? '');
        $protocolTypeRaw = $payload['ProtocolType'] ?? '';
        if ($isDm) {
            if (!is_numeric($protocolTypeRaw)) {
                P_E(dcim_msg('common.invalid_params'));
            }
            $protocolType = (int)$protocolTypeRaw;
        } else {
            $protocolType = $protocolTypeRaw;
        }

        $protocolCrud = self::crud('dcim-deviceprotocol');
        $existing = $protocolCrud->findOne([
            ['ProtocolName', '=', $protocolName],
            ['ProtocolType', '=', $protocolType],
            ['status', '=', 1],
        ]);

        $upsertData = [
            'ProtocolValue' => $protocolValueStr,
            'ProtocolData' => '',
            'ProtocolJson' => $protocolJsonStr,
            'ProtocolType' => $protocolType,
            'AlarmType' => 5,
        ];

        if ($existing && isset($existing['id'])) {
            $ok = $protocolCrud->updateById($existing['id'], $upsertData);
            if ($ok === false) {
                throw new \RuntimeException(dcim_msg('error.import_failed'));
            }
            return;
        }

        $insertData = array_merge([
            'ProtocolName' => $protocolName,
            'ProtocolCode' => $protocolCode,
            'status' => 1,
        ], $upsertData);
        $insertedId = $protocolCrud->insert($insertData);
        if ($insertedId === false) {
            throw new \RuntimeException(dcim_msg('error.import_failed'));
        }
    }

    private static function dmTableExists(\PDO $db, string $table): bool
    {
        $stmt = $db->prepare('SELECT COUNT(1) AS c FROM USER_TABLES WHERE UPPER(TABLE_NAME) = UPPER(:table_name)');
        $stmt->execute([':table_name' => $table]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0) > 0;
    }

    private static function dmIsAlreadyExistsError(\Throwable $e): bool
    {
        $msg = strtolower((string)$e->getMessage());
        if ($msg === '') {
            return false;
        }
        if (strpos($msg, 'already exists') !== false) {
            return true;
        }
        if (strpos($msg, 'object [') !== false && strpos($msg, ']') !== false && strpos($msg, 'exists') !== false) {
            return true;
        }
        return strpos($msg, '-2124') !== false;
    }

    private static function dmExecIgnoreExists(\PDO $db, string $sql): void
    {
        try {
            $db->exec($sql);
        } catch (\Throwable $e) {
            if (self::dmIsAlreadyExistsError($e)) {
                return;
            }
            throw $e;
        }
    }

    private static function ensureDmProtocolDetailTables(\PDO $db): void
    {
        if (!self::dmTableExists($db, 'dcim_protocol_command')) {
            self::dmExecIgnoreExists(
                $db,
                'CREATE TABLE "dcim_protocol_command" (
                    "id" INTEGER IDENTITY(1,1) NOT NULL,
                    "ProtocolCode" varchar(50) NOT NULL,
                    "ProtocolType" varchar(20) NOT NULL DEFAULT \'\',
                    "CommandType" varchar(50) NOT NULL,
                    "CommandDesc" varchar(255) DEFAULT \'\',
                    "RequestTemplate" CLOB,
                    "AddrMode" varchar(50) DEFAULT \'\',
                    "CrcMode" varchar(50) DEFAULT \'\',
                    "Transport" varchar(20) DEFAULT \'\',
                    "SortNo" INTEGER NOT NULL DEFAULT \'0\',
                    "create_time" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    "update_time" TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    "status" SMALLINT NOT NULL DEFAULT \'1\',
                    PRIMARY KEY ("id")
                )'
            );
            self::dmExecIgnoreExists($db, 'CREATE INDEX idx_dcim_protocol_command_idx_protocol_code ON "dcim_protocol_command" ("ProtocolCode")');
            self::dmExecIgnoreExists($db, 'CREATE INDEX idx_dcim_protocol_command_idx_protocol_code_cmd ON "dcim_protocol_command" ("ProtocolCode", "CommandType")');
        }

        if (!self::dmTableExists($db, 'dcim_protocol_param')) {
            self::dmExecIgnoreExists(
                $db,
                'CREATE TABLE "dcim_protocol_param" (
                    "id" INTEGER IDENTITY(1,1) NOT NULL,
                    "ProtocolCode" varchar(50) NOT NULL,
                    "CommandType" varchar(50) NOT NULL,
                    "ParamNo" varchar(20) DEFAULT \'\',
                    "ParamKey" varchar(255) NOT NULL DEFAULT \'\',
                    "ParamName" varchar(255) DEFAULT \'\',
                    "ProcessType" varchar(50) DEFAULT \'\',
                    "ProcessModel" varchar(100) DEFAULT \'\',
                    "Rate" varchar(50) DEFAULT \'\',
                    "Unit" varchar(100) DEFAULT \'\',
                    "DataLen" varchar(50) DEFAULT \'\',
                    "DataOrder" varchar(50) DEFAULT \'\',
                    "DataOffset" varchar(50) DEFAULT \'\',
                    "DataFixed" varchar(50) DEFAULT \'\',
                    "DataType" varchar(50) DEFAULT \'\',
                    "SortNo" INTEGER NOT NULL DEFAULT \'0\',
                    "create_time" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    "update_time" TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    "status" SMALLINT NOT NULL DEFAULT \'1\',
                    PRIMARY KEY ("id")
                )'
            );
            self::dmExecIgnoreExists($db, 'CREATE INDEX idx_dcim_protocol_param_idx_protocol_code ON "dcim_protocol_param" ("ProtocolCode")');
            self::dmExecIgnoreExists($db, 'CREATE INDEX idx_dcim_protocol_param_idx_protocol_code_param ON "dcim_protocol_param" ("ProtocolCode", "ParamKey")');
        }

        if (!self::dmTableExists($db, 'dcim_protocol_alarmmode')) {
            self::dmExecIgnoreExists(
                $db,
                'CREATE TABLE "dcim_protocol_alarmmode" (
                    "id" INTEGER IDENTITY(1,1) NOT NULL,
                    "ProtocolCode" varchar(50) NOT NULL,
                    "CommandType" varchar(50) DEFAULT NULL,
                    "ParamKey" varchar(255) DEFAULT NULL,
                    "AlarmType" INTEGER DEFAULT \'0\',
                    "AlarmKey" varchar(100) DEFAULT NULL,
                    "AlarmName" varchar(255) DEFAULT NULL,
                    "AlarmUpLimit" varchar(255) DEFAULT \'\',
                    "AlarmDownLimit" varchar(255) DEFAULT \'\',
                    "AlarmValue" varchar(255) DEFAULT \'\',
                    "PhoneNotify" INTEGER NOT NULL DEFAULT \'0\',
                    "SMSNotify" INTEGER NOT NULL DEFAULT \'0\',
                    "WeixinNotify" INTEGER NOT NULL DEFAULT \'0\',
                    "WeComNotify" INTEGER NOT NULL DEFAULT \'0\',
                    "DingdingNotify" INTEGER NOT NULL DEFAULT \'0\',
                    "EmailNotify" INTEGER NOT NULL DEFAULT \'0\',
                    "NoiseNotify" INTEGER NOT NULL DEFAULT \'0\',
                    "UserID" varchar(255) DEFAULT \'1\',
                    "MasterID" varchar(20) DEFAULT \'0\',
                    "ConfirmNum" INTEGER NOT NULL DEFAULT \'3\',
                    "NotifyNum" INTEGER NOT NULL DEFAULT \'1\',
                    "IntervalTime" INTEGER NOT NULL DEFAULT \'1800\',
                    "AlarmLevel" INTEGER NOT NULL DEFAULT \'1\',
                    "UpgradeTime" INTEGER DEFAULT \'0\',
                    "UpgradeUser" varchar(20) DEFAULT NULL,
                    "Linkage" varchar(255) DEFAULT \'\',
                    "CancelLinkage" varchar(255) DEFAULT \'\',
                    "snmpSource" INTEGER DEFAULT NULL,
                    "LinkVideoChannel" varchar(535) DEFAULT NULL,
                    "DataType" varchar(11) DEFAULT NULL,
                    "TogetherAlarm" varchar(535) NOT NULL DEFAULT \'\',
                    "NotifyWindowID" INTEGER NOT NULL DEFAULT \'0\',
                    "create_time" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    "update_time" TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    "status" SMALLINT NOT NULL DEFAULT \'1\',
                    PRIMARY KEY ("id")
                )'
            );
            self::dmExecIgnoreExists($db, 'CREATE INDEX idx_dcim_protocol_alarmmode_idx_protocol_code ON "dcim_protocol_alarmmode" ("ProtocolCode")');
        }
    }

    private static function ensureProtocolDetailTables(): void
    {
        $db = Flight::db();
        $driver = self::dbDriverName($db);
        if ($driver === 'dm') {
            self::ensureDmProtocolDetailTables($db);
            return;
        }
        if ($driver !== 'mysql') {
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
                    `Unit` varchar(100) DEFAULT '',
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

    private static function buildImportPayload(array $sheetContent, string $originalName, array $requestData = []): array
    {
        if (!$sheetContent || count($sheetContent) < 2) {
            P_E(dcim_msg('error.sheet_content_empty'));
        }

        $protocolName = self::resolveProtocolImportName($requestData, $originalName);

        $headerRow = isset($sheetContent[0]) && is_array($sheetContent[0]) ? $sheetContent[0] : [];
        $headerIndex = [];
        foreach ($headerRow as $idx => $title) {
            if (!is_int($idx) || !is_string($title)) {
                continue;
            }
            $normalized = self::normalizeHeader($title);
            if ($normalized !== '' && !array_key_exists($normalized, $headerIndex)) {
                $headerIndex[$normalized] = $idx;
            }
        }
        $findHeaderIndex = static function (array $names) use ($headerIndex): ?int {
            foreach ($names as $name) {
                if (!is_string($name)) {
                    continue;
                }
                $normalized = ProtocolImportController::normalizeHeader($name);
                if ($normalized !== '' && array_key_exists($normalized, $headerIndex)) {
                    return (int)$headerIndex[$normalized];
                }
            }
            return null;
        };

        $alarmUserGroupCol = null;
        $alarmUpgradeGroupCol = null;
        $alarmLevelCol = null;
        $faultToleranceCol = null;
        $alarmUserGroupCol = $findHeaderIndex([
            dcim_msg('import.protocol_header_alarm_user_group_1'),
            dcim_msg('import.protocol_header_alarm_user_group_2'),
            'alarm_user_group',
            'user_group',
        ]);
        $alarmUpgradeGroupCol = $findHeaderIndex([
            dcim_msg('import.protocol_header_alarm_upgrade_group_1'),
            dcim_msg('import.protocol_header_alarm_upgrade_group_2'),
            'alarm_upgrade_group',
            'upgrade_user_group',
        ]);
        $alarmLevelCol = $findHeaderIndex([
            dcim_msg('import.protocol_header_alarm_level_1'),
            dcim_msg('import.protocol_header_alarm_level_2'),
            'alarm_level',
        ]);
        $faultToleranceCol = $findHeaderIndex([
            dcim_msg('import.protocol_header_fault_tolerance'),
            dcim_msg('import.protocol_header_alarm_period_1'),
            dcim_msg('import.protocol_header_alarm_period_2'),
            'confirm_num',
            'fault_tolerance',
        ]);
        if ($alarmUserGroupCol === null || $alarmUpgradeGroupCol === null || $alarmLevelCol === null || $faultToleranceCol === null) {
            P_E(dcim_msg('error.protocol_import_template_upgraded'));
        }

        $protocolType = '';
        $protocolCode = '';
        $existing = null;

        $commandsByType = [];
        $commands = [];
        $parseModelsByType = [];
        $detailCommands = [];
        $detailParams = [];
        $detailAlarmModes = [];

        $sortCommand = 0;
        $sortParam = 0;
        $currentCommandType = '';
        $currentCommandDesc = '';
        $currentRequestTemplate = '';
        $currentProcessType = '';
        $dataRows = count($sheetContent);
        for ($i = 1; $i < $dataRows; $i++) {
            $row = is_array($sheetContent[$i] ?? null) ? $sheetContent[$i] : [];
            if (self::isEmptyRow($row)) {
                continue;
            }
            if ($protocolType === '') {
                $protocolType = trim((string)($row[0] ?? ''));
                if ($protocolType === '') {
                    P_E(dcim_msg('error.protocol_type_required'));
                }
                $existing = self::crud('dcim-deviceprotocol')->findOne([
                    ['ProtocolName', '=', $protocolName],
                    ['ProtocolType', '=', $protocolType],
                    ['status', '=', 1],
                ]);
                if ($existing) {
                    $protocolCode = (string)($existing['ProtocolCode'] ?? '');
                } else {
                    $catnum = self::crud('dcim-deviceprotocol')->countByRawCondition(
                        'ProtocolType = :type',
                        [':type' => $protocolType]
                    );
                    $catnum = $catnum + 1;
                    if ($catnum > -1 && $catnum < 10) {
                        $catnum = '00' . $catnum;
                    } elseif ($catnum >= 10 && $catnum < 100) {
                        $catnum = '0' . $catnum;
                    } else {
                        $catnum = (string)$catnum;
                    }
                    $protocolCode = $protocolType . $catnum;
                }
            }

            $rawCommandType = trim((string)($row[1] ?? ''));
            $rawCommandDesc = trim((string)($row[2] ?? ''));
            $rawRequestTemplate = trim((string)($row[3] ?? ''));
            $rawProcessType = self::normalizeProcessTypeExpression((string)($row[4] ?? ''));

            $isContinuationByTypeOnly = ($rawCommandType !== '' && $rawCommandType === $currentCommandType
                && $rawCommandDesc === '' && $rawRequestTemplate === '' && $rawProcessType === '');

            if ($rawCommandType === '') {
                if ($currentCommandType === '') {
                    P_E(str_replace('{row}', (string)$i, dcim_msg('error.protocol_row_command_fields_required')));
                }
                $commandType = $currentCommandType;
                $commandDesc = $currentCommandDesc;
                $requestTemplate = $currentRequestTemplate;
                $processType = $currentProcessType;
            } elseif ($isContinuationByTypeOnly) {
                $commandType = $currentCommandType;
                $commandDesc = $currentCommandDesc;
                $requestTemplate = $currentRequestTemplate;
                $processType = $currentProcessType;
            } else {
                $commandType = $rawCommandType;
                if ($rawCommandType === $currentCommandType) {
                    $commandDesc = $rawCommandDesc !== '' ? $rawCommandDesc : $currentCommandDesc;
                    $requestTemplate = $rawRequestTemplate !== '' ? $rawRequestTemplate : $currentRequestTemplate;
                    $processType = $rawProcessType !== '' ? $rawProcessType : $currentProcessType;
                } else {
                    $commandDesc = $rawCommandDesc;
                    $requestTemplate = $rawRequestTemplate;
                    $processType = $rawProcessType;
                }

                if ($commandType === '' || $commandDesc === '' || $requestTemplate === '' || $processType === '') {
                    P_E(str_replace('{row}', (string)$i, dcim_msg('error.protocol_row_command_fields_required')));
                }

                $currentCommandType = $commandType;
                $currentCommandDesc = $commandDesc;
                $currentRequestTemplate = $requestTemplate;
                $currentProcessType = $processType;
            }

            if (!isset($commandsByType[$commandType])) {
                $sortCommand++;
                $commandRow = [
                    'CommandType' => $commandType,
                    'CommandDesc' => $commandDesc,
                    'RequestTemplate' => $requestTemplate,
                    'AddrMode' => 'hex_1byte',
                    'CrcMode' => 'modbus_crc16',
                    'Transport' => 'tcp',
                    'SortNo' => $sortCommand,
                ];
                $commandsByType[$commandType] = $commandRow;
                $commands[] = $commandRow;
                $detailCommands[] = [
                    'ProtocolCode' => $protocolCode,
                    'ProtocolType' => $protocolType,
                    'CommandType' => $commandType,
                    'CommandDesc' => $commandDesc,
                    'RequestTemplate' => $requestTemplate,
                    'AddrMode' => 'hex_1byte',
                    'CrcMode' => 'modbus_crc16',
                    'Transport' => 'tcp',
                    'SortNo' => $sortCommand,
                    'status' => 1,
                ];
                $parseModelsByType[$commandType] = [
                    'CommandType' => $commandType,
                    'ProcessType' => $processType,
                    'ProcessModel' => trim((string)($row[10] ?? '')),
                    'Params' => [],
                ];
            }

            $paramNo = trim((string)($row[5] ?? ''));
            $paramKey = trim((string)($row[6] ?? ''));
            if ($paramNo === '' || $paramKey === '') {
                P_E(str_replace('{row}', (string)$i, dcim_msg('error.protocol_row_sequence_param_required')));
            }

            $rate = trim((string)($row[7] ?? ''));
            $unit = trim((string)($row[8] ?? ''));
            $dataLen = trim((string)($row[9] ?? ''));
            $processModel = trim((string)($row[10] ?? ''));
            $dataOrder = trim((string)($row[11] ?? ''));
            $alarmValue = trim((string)($row[12] ?? ''));
            $alarmUp = trim((string)($row[13] ?? ''));
            $alarmDown = trim((string)($row[14] ?? ''));
            $dataOffset = trim((string)($row[15] ?? 0));
            $dataFixed = trim((string)($row[16] ?? 0));
            $dataType = trim((string)($row[17] ?? ''));

            $alarmUserGroup = trim((string)self::readCell($row, $alarmUserGroupCol, '1'));
            $alarmUpgradeGroup = trim((string)self::readCell($row, $alarmUpgradeGroupCol, ''));
            $alarmLevel = self::toInt(self::readCell($row, $alarmLevelCol, 1), 1);
            if ($alarmLevel < 1) {
                $alarmLevel = 1;
            }
            if ($alarmLevel > 5) {
                $alarmLevel = 5;
            }
            $confirmNum = self::toInt(self::readCell($row, $faultToleranceCol, 3), 3);
            if ($confirmNum <= 0) {
                $confirmNum = 1;
            }

            $sortParam++;
            $alarmMode = [
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
                'UserID' => $alarmUserGroup === '' ? '1' : $alarmUserGroup,
                'MasterID' => '0',
                'ConfirmNum' => $confirmNum,
                'NotifyNum' => 1,
                'IntervalTime' => 1800,
                'AlarmLevel' => $alarmLevel,
                'UpgradeTime' => 0,
                'UpgradeUser' => $alarmUpgradeGroup,
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

            $parseModelsByType[$commandType]['Params'][] = [
                'ParamNo' => $paramNo,
                'AlarmKey' => $paramKey,
                'ParamName' => $paramKey,
                'Offset' => self::toInt($dataOffset, 0),
                'Scale' => is_numeric($rate) ? (float)$rate : $rate,
                'Unit' => $unit,
                'DataLen' => $dataLen,
                'DataOrder' => $dataOrder,
                'DataType' => $dataType,
                'ProcessModel' => $processModel,
                'AlarmMode' => $alarmMode,
            ];

            $detailParams[] = [
                'ProtocolCode' => $protocolCode,
                'CommandType' => $commandType,
                'ParamNo' => $paramNo,
                'ParamKey' => $paramKey,
                'ParamName' => $paramKey,
                'ProcessType' => $processType,
                'ProcessModel' => $processModel,
                'Rate' => $rate,
                'Unit' => $unit,
                'DataLen' => $dataLen,
                'DataOrder' => $dataOrder,
                'DataOffset' => $dataOffset,
                'DataFixed' => $dataFixed,
                'DataType' => $dataType,
                'SortNo' => $sortParam,
                'status' => 1,
            ];

            $detailAlarmModes[] = array_merge([
                'ProtocolCode' => $protocolCode,
                'CommandType' => $commandType,
                'ParamKey' => $paramKey,
            ], $alarmMode);
        }

        if ($protocolType === '' || $protocolCode === '') {
            P_E(dcim_msg('error.sheet_content_empty'));
        }

        $protocolValueJson = [
            'version' => 2,
            'commands' => $commands,
        ];
        $protocolJson = [
            'version' => 2,
            'protocolMeta' => [
                'ProtocolCode' => $protocolCode,
                'ProtocolName' => $protocolName,
                'ProtocolType' => $protocolType,
            ],
            'parseModels' => array_values($parseModelsByType),
        ];

        return [
            'existing' => $existing,
            'ProtocolName' => $protocolName,
            'ProtocolType' => $protocolType,
            'ProtocolCode' => $protocolCode,
            'ProtocolValue' => $protocolValueJson,
            'ProtocolJson' => $protocolJson,
            'detailCommands' => $detailCommands,
            'detailParams' => $detailParams,
            'detailAlarmModes' => $detailAlarmModes,
        ];
    }

    private static function persistProtocolPayload(array $payload): void
    {
        self::ensureProtocolDetailTables();
        self::validateProtocolPayloadForStorage($payload);

        $protocolValueStr = json_encode($payload['ProtocolValue'], JSON_UNESCAPED_UNICODE);
        $protocolJsonStr = json_encode($payload['ProtocolJson'], JSON_UNESCAPED_UNICODE);
        if (!is_string($protocolValueStr) || !is_string($protocolJsonStr)) {
            P_E(dcim_msg('error.json_parse_failed'));
        }

        $db = Flight::db();
        $db->beginTransaction();
        try {
            self::persistProtocolMainRow($db, $payload, $protocolValueStr, $protocolJsonStr);

            $code = (string)$payload['ProtocolCode'];
            $commandCrud = self::crud('dcim_protocol_command');
            $paramCrud = self::crud('dcim_protocol_param');
            $alarmCrud = self::crud('dcim_protocol_alarmmode');

            if (!$commandCrud->deleteByRawCondition('ProtocolCode = :code', [':code' => $code])) {
                throw new \RuntimeException(dcim_msg('error.import_failed'));
            }
            if (!$paramCrud->deleteByRawCondition('ProtocolCode = :code', [':code' => $code])) {
                throw new \RuntimeException(dcim_msg('error.import_failed'));
            }
            if (!$alarmCrud->deleteByRawCondition('ProtocolCode = :code', [':code' => $code])) {
                throw new \RuntimeException(dcim_msg('error.import_failed'));
            }

            if (!empty($payload['detailCommands'])) {
                foreach ($payload['detailCommands'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if ($commandCrud->insert($row) === false) {
                        throw new \RuntimeException(dcim_msg('error.import_failed'));
                    }
                }
            }

            if (!empty($payload['detailParams'])) {
                foreach ($payload['detailParams'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if ($paramCrud->insert($row) === false) {
                        throw new \RuntimeException(dcim_msg('error.import_failed'));
                    }
                }
            }

            if (!empty($payload['detailAlarmModes'])) {
                $alarmInsertKeys = [
                    'ProtocolCode', 'CommandType', 'ParamKey', 'AlarmType', 'AlarmKey', 'AlarmName',
                    'AlarmUpLimit', 'AlarmDownLimit', 'AlarmValue', 'PhoneNotify', 'SMSNotify',
                    'WeixinNotify', 'WeComNotify', 'DingdingNotify', 'EmailNotify', 'NoiseNotify',
                    'UserID', 'MasterID', 'ConfirmNum', 'NotifyNum', 'IntervalTime', 'AlarmLevel',
                    'UpgradeTime', 'UpgradeUser', 'Linkage', 'CancelLinkage', 'snmpSource',
                    'LinkVideoChannel', 'DataType', 'TogetherAlarm', 'NotifyWindowID', 'status',
                ];
                foreach ($payload['detailAlarmModes'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $insertRow = self::pickRowKeys($row, $alarmInsertKeys);
                    if ($alarmCrud->insert($insertRow) === false) {
                        throw new \RuntimeException(dcim_msg('error.import_failed'));
                    }
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function insertDeviceBroken(int $devId): void
    {
        try {
            self::crud('dcim-alarmnotifymode')->legacyInsert([
                'AlarmType' => 5,
                'AlarmKey' => dcim_msg('app.alarm_disconnected'),
                'AlarmName' => dcim_msg('app.alarm_disconnected'),
                'DevId' => $devId,
                'AlarmLevel' => 0,
                'ConfirmNum' => 1,
                'status' => 1,
                'TogetherAlarm' => '',
            ]);
        } catch (\Throwable $e) {
            error_log('[ImportDevicePtlKeyV2] insert broken alarm failed: ' . $e->getMessage());
        }
    }

    private static function rebuildDeviceAlarmModes(string $protocolCode, array $detailAlarmModes): void
    {
        $db = Flight::db();
        self::validateStringColumnLength($db, 'dcim-deviceprotocol', 'ProtocolCode', $protocolCode, 10, 'protocol');
        $deviceCrud = self::crud('dcim-device');
        $devices = $deviceCrud->selectByRawCondition(
            'ProtocolCode = :code AND status = 1',
            '',
            [':code' => $protocolCode]
        );
        if (!$devices) {
            return;
        }
        $alarmCrud = self::crud('dcim-alarmnotifymode');
        $alarmFallbacks = [
            'AlarmKey' => 100, 'AlarmName' => 255, 'AlarmUpLimit' => 255, 'AlarmDownLimit' => 255,
            'AlarmValue' => 255, 'UserID' => 255, 'MasterID' => 20, 'UpgradeUser' => 20,
            'Linkage' => 255, 'CancelLinkage' => 255, 'CommandType' => 50, 'DataType' => 11,
            'LinkVideoChannel' => 535, 'TogetherAlarm' => 535,
        ];
        foreach ($devices as $device) {
            $devId = (int)($device['id'] ?? 0);
            if ($devId <= 0) {
                continue;
            }
            $alarmCrud->legacyDeleteByRawCondition(
                'DevId = :id AND status <> -1',
                [':id' => $devId]
            );
            foreach ($detailAlarmModes as $template) {
                $row = $template;
                unset($row['ProtocolCode'], $row['ParamKey']);
                $row['DevId'] = $devId;
                self::validateRowStringColumns($db, 'dcim-alarmnotifymode', $row, $alarmFallbacks, 'deviceAlarmModes[DevId=' . $devId . ']');
                $alarmCrud->legacyInsert($row);
            }
            self::insertDeviceBroken($devId);
        }
    }

    private static function protocolExportHeaders(): array
    {
        return [
            dcim_msg('import.protocol_export_header_device_type'),
            dcim_msg('import.protocol_export_header_command_type'),
            dcim_msg('import.protocol_export_header_command_name'),
            dcim_msg('import.protocol_export_header_collect_command'),
            dcim_msg('import.protocol_export_header_process_type'),
            dcim_msg('import.protocol_export_header_param_index'),
            dcim_msg('import.protocol_export_header_param_name'),
            dcim_msg('import.protocol_export_header_param_ratio'),
            dcim_msg('import.protocol_export_header_param_unit'),
            dcim_msg('import.protocol_export_header_param_length'),
            dcim_msg('import.protocol_export_header_process_model'),
            dcim_msg('import.protocol_export_header_data_order'),
            dcim_msg('import.protocol_export_header_alarm_feature'),
            dcim_msg('import.protocol_export_header_upper_limit'),
            dcim_msg('import.protocol_export_header_lower_limit'),
            dcim_msg('import.protocol_export_header_offset'),
            dcim_msg('import.protocol_export_header_decimal_keep'),
            dcim_msg('import.protocol_export_header_data_type'),
            dcim_msg('import.protocol_header_alarm_user_group_1'),
            dcim_msg('import.protocol_header_alarm_upgrade_group_1'),
            dcim_msg('import.protocol_header_alarm_level_1'),
            dcim_msg('import.protocol_header_fault_tolerance'),
        ];
    }

    private static function exportCell(array $row, string $key, $default = '')
    {
        return array_key_exists($key, $row) && $row[$key] !== null ? $row[$key] : $default;
    }

    private static function decodeJsonArray($raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function requestStringList($value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[,，\\s]+/', (string)$value);
        }
        $result = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = trim((string)$item);
            if ($item !== '' && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }
        return $result;
    }

    private static function safeExportFileName(string $name, string $fallback): string
    {
        $name = trim($name);
        if ($name === '') {
            $name = $fallback;
        }
        $name = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $name);
        $name = preg_replace('/\s+/u', '_', (string)$name);
        $name = trim((string)$name, " \t\n\r\0\x0B._-");
        return $name === '' ? $fallback : $name;
    }

    private static function resolveProtocolExportDir(): array
    {
        $publicRootReal = realpath(dirname(__DIR__, 2) . '/public');
        if ($publicRootReal === false) {
            throw new \RuntimeException('public root missing');
        }
        $publicRoot = rtrim(str_replace('\\', '/', $publicRootReal), '/');
        $date = date('Ymd');
        $candidates = [
            '/exports/' . $date,
            '/exports',
            '/uploads/exports/' . $date,
            '/uploads/exports',
            '/uploads',
            '/',
        ];
        $attempts = [];
        foreach ($candidates as $relative) {
            $relative = '/' . ltrim(str_replace('\\', '/', $relative), '/');
            $relative = preg_replace('#/+#', '/', $relative);
            if (strpos($relative, '..') !== false) {
                continue;
            }
            $absolute = $relative === '/' ? $publicRoot : $publicRoot . $relative;
            if (!is_dir($absolute) && !@mkdir($absolute, 0777, true) && !is_dir($absolute)) {
                $attempts[] = $relative . ':mkdir_failed';
                continue;
            }
            if (!is_writable($absolute)) {
                $attempts[] = $relative . ':not_writable';
                continue;
            }
            return ['absolute' => $absolute, 'relative' => $relative];
        }
        throw new \RuntimeException('no writable export directory: ' . implode(', ', $attempts));
    }

    private static function toProtocolPublicPath(string $absolutePath): string
    {
        $publicRoot = str_replace('\\', '/', realpath(dirname(__DIR__, 2) . '/public') ?: '');
        $abs = str_replace('\\', '/', $absolutePath);
        if ($publicRoot !== '' && strpos($abs, $publicRoot) === 0) {
            return '/' . ltrim(substr($abs, strlen($publicRoot)), '/');
        }
        return $absolutePath;
    }

    private static function findExportProtocolRows(array $data): array
    {
        $hasFilter = false;
        $where = ['(`status` = 1 OR `status` IS NULL)'];
        $params = [];

        $codeList = [];
        foreach (['ProtocolCode', 'protocolCode', 'protocol_code'] as $key) {
            if (array_key_exists($key, $data)) {
                $codeList = array_merge($codeList, self::requestStringList($data[$key]));
            }
        }
        $codeList = array_values(array_unique($codeList));
        if ($codeList) {
            $hasFilter = true;
            $placeholders = [];
            foreach ($codeList as $idx => $code) {
                $ph = ':code' . $idx;
                $placeholders[] = $ph;
                $params[$ph] = $code;
            }
            $where[] = '`ProtocolCode` IN (' . implode(',', $placeholders) . ')';
        }

        $id = trim((string)($data['id'] ?? $data['ID'] ?? ''));
        if ($id !== '' && ctype_digit($id)) {
            $hasFilter = true;
            $where[] = '`id` = :id';
            $params[':id'] = (int)$id;
        }

        $protocolName = trim((string)($data['ProtocolName'] ?? $data['protocolName'] ?? ''));
        if ($protocolName !== '') {
            $hasFilter = true;
            $where[] = '`ProtocolName` LIKE :name';
            $params[':name'] = '%' . $protocolName . '%';
        }

        $protocolType = trim((string)($data['ProtocolType'] ?? $data['protocolType'] ?? ''));
        if ($protocolType !== '') {
            $hasFilter = true;
            $where[] = '`ProtocolType` = :type';
            $params[':type'] = $protocolType;
        }

        if (!$hasFilter) {
            P_E(dcim_msg('error.protocol_export_require_code_or_name'));
        }

        $sql = 'SELECT * FROM `dcim-deviceprotocol` WHERE ' . implode(' AND ', $where) . ' ORDER BY `id` ASC LIMIT 2';
        $stmt = Flight::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function requestBool(array $data, array $keys, bool $default = false): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int)$value !== 0;
            }
            $value = strtolower(trim((string)$value));
            return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
        }
        return $default;
    }

    private static function findBatchExportProtocolRows(array $data): array
    {
        $idLt = trim((string)($data['id_lt'] ?? $data['IdLt'] ?? $data['IDLT'] ?? ''));
        if ($idLt === '' || !ctype_digit($idLt)) {
            P_E('batch migrate requires id_lt');
        }
        $idLtNum = (int)$idLt;
        if ($idLtNum <= 0) {
            P_E('batch migrate id_lt must be greater than 0');
        }

        $stmt = Flight::db()->prepare(
            'SELECT * FROM `dcim-deviceprotocol`
             WHERE (`status` = 1 OR `status` IS NULL)
               AND `id` < :id_lt
             ORDER BY `id` ASC'
        );
        $stmt->execute([':id_lt' => $idLtNum]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $onlyNotEmpty = self::requestBool($data, ['only_protocol_value_not_empty', 'OnlyProtocolValueNotEmpty'], true);
        if (!$onlyNotEmpty) {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            $value = (string)self::exportCell($row, 'ProtocolValue');
            if (trim($value) !== '') {
                $filtered[] = $row;
            }
        }
        return $filtered;
    }

    private static function loadProtocolDetail(string $protocolCode): array
    {
        $db = Flight::db();
        $queries = [
            'commands' => "SELECT * FROM `dcim_protocol_command` WHERE `ProtocolCode` = :code AND `status` <> -1 ORDER BY `SortNo` ASC, `id` ASC",
            'params' => "SELECT * FROM `dcim_protocol_param` WHERE `ProtocolCode` = :code AND `status` <> -1 ORDER BY `SortNo` ASC, `id` ASC",
            'alarms' => "SELECT * FROM `dcim_protocol_alarmmode` WHERE `ProtocolCode` = :code AND `status` <> -1 ORDER BY `id` ASC",
        ];
        $result = [];
        foreach ($queries as $key => $sql) {
            $stmt = $db->prepare($sql);
            $stmt->execute([':code' => $protocolCode]);
            $result[$key] = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
        return $result;
    }

    private static function buildProtocolExportRowsFromDetail(array $protocol, array $detail): array
    {
        $commands = $detail['commands'] ?? [];
        $params = $detail['params'] ?? [];
        $alarms = $detail['alarms'] ?? [];
        if (!$commands && !$params) {
            return [];
        }

        $commandsByType = [];
        foreach ($commands as $command) {
            $type = (string)self::exportCell($command, 'CommandType');
            if ($type !== '' && !isset($commandsByType[$type])) {
                $commandsByType[$type] = $command;
            }
        }

        $paramsByCommand = [];
        foreach ($params as $param) {
            $type = (string)self::exportCell($param, 'CommandType');
            if ($type === '') {
                $type = '_default';
            }
            if (!isset($paramsByCommand[$type])) {
                $paramsByCommand[$type] = [];
            }
            $paramsByCommand[$type][] = $param;
            if (!isset($commandsByType[$type])) {
                $commandsByType[$type] = ['CommandType' => $type, 'CommandDesc' => '', 'RequestTemplate' => ''];
            }
        }

        $alarmsByKey = [];
        foreach ($alarms as $alarm) {
            $type = (string)self::exportCell($alarm, 'CommandType');
            $paramKey = (string)self::exportCell($alarm, 'ParamKey');
            $key = $type . "\n" . $paramKey;
            if ($paramKey !== '' && !isset($alarmsByKey[$key])) {
                $alarmsByKey[$key] = $alarm;
            }
        }

        $rows = [];
        $firstProtocolRow = true;
        foreach ($commandsByType as $type => $command) {
            $commandParams = $paramsByCommand[$type] ?? [];
            if (!$commandParams) {
                $commandParams = [[
                    'CommandType' => $type,
                    'ParamNo' => '',
                    'ParamKey' => '',
                    'ParamName' => '',
                    'ProcessType' => '',
                ]];
            }
            $firstCommandRow = true;
            foreach ($commandParams as $param) {
                $paramKey = (string)self::exportCell($param, 'ParamKey');
                $alarm = $alarmsByKey[$type . "\n" . $paramKey] ?? [];
                $rows[] = [
                    $firstProtocolRow ? (string)self::exportCell($protocol, 'ProtocolType') : '',
                    (string)self::exportCell($param, 'CommandType', $type),
                    $firstCommandRow ? (string)self::exportCell($command, 'CommandDesc') : '',
                    $firstCommandRow ? (string)self::exportCell($command, 'RequestTemplate') : '',
                    $firstCommandRow ? (string)self::exportCell($param, 'ProcessType') : '',
                    self::exportCell($param, 'ParamNo'),
                    self::exportCell($param, 'ParamName', $paramKey),
                    self::exportCell($param, 'Rate'),
                    self::exportCell($param, 'Unit'),
                    self::exportCell($param, 'DataLen'),
                    self::exportCell($param, 'ProcessModel'),
                    self::exportCell($param, 'DataOrder'),
                    self::exportCell($alarm, 'AlarmValue'),
                    self::exportCell($alarm, 'AlarmUpLimit'),
                    self::exportCell($alarm, 'AlarmDownLimit'),
                    self::exportCell($param, 'DataOffset', 0),
                    self::exportCell($param, 'DataFixed', 0),
                    self::exportCell($param, 'DataType'),
                    self::exportCell($alarm, 'UserID', 1),
                    self::exportCell($alarm, 'UpgradeUser'),
                    self::exportCell($alarm, 'AlarmLevel', 1),
                    self::exportCell($alarm, 'ConfirmNum', 3),
                ];
                $firstProtocolRow = false;
                $firstCommandRow = false;
            }
        }
        return $rows;
    }

    private static function legacyCommandMap(array $protocol): array
    {
        $commands = [];
        $protocolValue = (string)self::exportCell($protocol, 'ProtocolValue');
        foreach (explode('|', $protocolValue) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || strpos($segment, ':') === false) {
                continue;
            }
            $parts = explode(':', $segment);
            $type = trim((string)($parts[0] ?? ''));
            if ($type === '') {
                continue;
            }
            $commands[$type] = [
                'CommandType' => $type,
                'CommandDesc' => trim((string)($parts[1] ?? '')),
                'RequestTemplate' => trim((string)($parts[count($parts) - 1] ?? '')),
            ];
        }
        return $commands;
    }

    private static function legacyParamTail(array $items): array
    {
        $count = count($items);
        $tail = [
            'alarmValue' => '',
            'alarmUp' => '',
            'alarmDown' => '',
            'offset' => 0,
            'fixed' => 0,
            'dataType' => '',
            'userGroup' => '',
            'upgradeGroup' => '',
            'alarmLevel' => '',
            'confirmNum' => '',
        ];
        if ($count === 0) {
            return $tail;
        }

        $tail['alarmLevel'] = (string)($items[$count - 2] ?? '');
        $tail['confirmNum'] = (string)($items[$count - 1] ?? '');

        $dataTypeIndex = $count - 3;
        if ($count >= 5) {
            $ugToken = (string)($items[$count - 4] ?? '');
            $upgToken = (string)($items[$count - 3] ?? '');
            if (strpos($ugToken, 'UG:') === 0 || strpos($upgToken, 'UPG:') === 0) {
                $dataTypeIndex = $count - 5;
                if (strpos($ugToken, 'UG:') === 0) {
                    $tail['userGroup'] = str_replace(';', ',', substr($ugToken, 3));
                }
                if (strpos($upgToken, 'UPG:') === 0) {
                    $tail['upgradeGroup'] = str_replace(';', ',', substr($upgToken, 4));
                }
            }
        }
        $tail['dataType'] = (string)($items[$dataTypeIndex] ?? '');
        $tail['fixed'] = (string)($items[$dataTypeIndex - 1] ?? 0);
        $tail['offset'] = (string)($items[$dataTypeIndex - 2] ?? 0);
        $tail['alarmDown'] = (string)($items[$dataTypeIndex - 3] ?? '');
        $tail['alarmUp'] = (string)($items[$dataTypeIndex - 4] ?? '');
        $tail['alarmValue'] = (string)($items[$dataTypeIndex - 5] ?? '');
        return $tail;
    }

    private static function buildProtocolExportRowsFromLegacy(array $protocol): array
    {
        $protocolData = (string)self::exportCell($protocol, 'ProtocolData');
        if (trim($protocolData) === '') {
            return [];
        }

        $commandMap = self::legacyCommandMap($protocol);
        $rows = [];
        $firstProtocolRow = true;
        foreach (explode('|', $protocolData) as $commandSegment) {
            $commandSegment = trim($commandSegment);
            if ($commandSegment === '' || strpos($commandSegment, '&') === false) {
                continue;
            }
            $pieces = explode('&', $commandSegment, 3);
            $commandType = trim((string)($pieces[0] ?? ''));
            $processType = trim((string)($pieces[1] ?? ''));
            $paramText = (string)($pieces[2] ?? '');
            if ($commandType === '' || $paramText === '') {
                continue;
            }
            $command = $commandMap[$commandType] ?? [];
            $firstCommandRow = true;
            $paramText = str_replace(['UG:', 'UPG:'], ['UG__COLON__', 'UPG__COLON__'], $paramText);
            foreach (explode(':', $paramText) as $paramSegment) {
                $paramSegment = str_replace(['UG__COLON__', 'UPG__COLON__'], ['UG:', 'UPG:'], $paramSegment);
                $paramSegment = trim($paramSegment);
                if ($paramSegment === '') {
                    continue;
                }
                $items = explode(',', $paramSegment);
                if (count($items) < 2) {
                    continue;
                }
                $tail = self::legacyParamTail($items);
                $paramNo = (string)($items[0] ?? '');
                $paramName = (string)($items[1] ?? '');
                $rate = '';
                $unit = '';
                $dataLen = '';
                $processModel = '';
                $dataOrder = '';

                if ($processType === '2' || $processType === '4' || strpos($processType, '2<') !== false || strpos($processType, '4<') !== false) {
                    $unit = (string)($items[2] ?? '');
                } elseif ($processType === '3' || $processType === '5' || $processType === '8' || $processType === '12' || $processType === '14'
                    || strpos($processType, '3<') !== false || strpos($processType, '5<') !== false || strpos($processType, '8<') !== false
                    || strpos($processType, '12<') !== false || strpos($processType, '14<') !== false) {
                    $dataLen = (string)($items[2] ?? '');
                    $dataOrder = (string)($items[3] ?? '');
                    $unit = (string)($items[4] ?? '');
                } else {
                    $rate = (string)($items[2] ?? '');
                    $unit = (string)($items[3] ?? '');
                    $dataLen = (string)($items[4] ?? '');
                    $processModel = (string)($items[5] ?? '');
                }

                $rows[] = [
                    $firstProtocolRow ? (string)self::exportCell($protocol, 'ProtocolType') : '',
                    $commandType,
                    $firstCommandRow ? (string)self::exportCell($command, 'CommandDesc') : '',
                    $firstCommandRow ? (string)self::exportCell($command, 'RequestTemplate') : '',
                    $firstCommandRow ? $processType : '',
                    $paramNo,
                    $paramName,
                    $rate,
                    $unit,
                    $dataLen,
                    $processModel,
                    $dataOrder,
                    $tail['alarmValue'],
                    $tail['alarmUp'],
                    $tail['alarmDown'],
                    $tail['offset'],
                    $tail['fixed'],
                    $tail['dataType'],
                    $tail['userGroup'] === '' ? 1 : $tail['userGroup'],
                    $tail['upgradeGroup'],
                    $tail['alarmLevel'] === '' ? 1 : $tail['alarmLevel'],
                    $tail['confirmNum'] === '' ? 3 : $tail['confirmNum'],
                ];
                $firstProtocolRow = false;
                $firstCommandRow = false;
            }
        }
        return $rows;
    }

    private static function buildProtocolExportRowsFromJson(array $protocol): array
    {
        $protocolValue = self::decodeJsonArray($protocol['ProtocolValue'] ?? '');
        $protocolJson = self::decodeJsonArray($protocol['ProtocolJson'] ?? '');
        $commands = is_array($protocolValue['commands'] ?? null) ? $protocolValue['commands'] : [];
        $parseModels = is_array($protocolJson['parseModels'] ?? null) ? $protocolJson['parseModels'] : [];
        if (!$commands || !$parseModels) {
            return [];
        }

        $commandsByType = [];
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            $type = (string)self::exportCell($command, 'CommandType');
            if ($type !== '') {
                $commandsByType[$type] = $command;
            }
        }

        $rows = [];
        $firstProtocolRow = true;
        foreach ($parseModels as $model) {
            if (!is_array($model)) {
                continue;
            }
            $type = (string)self::exportCell($model, 'CommandType');
            $command = $commandsByType[$type] ?? [];
            $params = is_array($model['Params'] ?? null) ? $model['Params'] : [];
            $firstCommandRow = true;
            foreach ($params as $param) {
                if (!is_array($param)) {
                    continue;
                }
                $alarm = is_array($param['AlarmMode'] ?? null) ? $param['AlarmMode'] : [];
                $rows[] = [
                    $firstProtocolRow ? (string)self::exportCell($protocol, 'ProtocolType') : '',
                    $type,
                    $firstCommandRow ? (string)self::exportCell($command, 'CommandDesc') : '',
                    $firstCommandRow ? (string)self::exportCell($command, 'RequestTemplate') : '',
                    $firstCommandRow ? (string)self::exportCell($model, 'ProcessType') : '',
                    self::exportCell($param, 'ParamNo'),
                    self::exportCell($param, 'ParamName', self::exportCell($param, 'AlarmKey')),
                    self::exportCell($param, 'Scale'),
                    self::exportCell($param, 'Unit'),
                    self::exportCell($param, 'DataLen'),
                    self::exportCell($param, 'ProcessModel'),
                    self::exportCell($param, 'DataOrder'),
                    self::exportCell($alarm, 'AlarmValue'),
                    self::exportCell($alarm, 'AlarmUpLimit'),
                    self::exportCell($alarm, 'AlarmDownLimit'),
                    self::exportCell($param, 'Offset', 0),
                    '',
                    self::exportCell($param, 'DataType'),
                    self::exportCell($alarm, 'UserID', 1),
                    self::exportCell($alarm, 'UpgradeUser'),
                    self::exportCell($alarm, 'AlarmLevel', 1),
                    self::exportCell($alarm, 'ConfirmNum', 3),
                ];
                $firstProtocolRow = false;
                $firstCommandRow = false;
            }
        }
        return $rows;
    }

    private static function writeProtocolWorkbook(array $rows, string $baseName, string $fileType): array
    {
        if (!$rows) {
            P_E(dcim_msg('error.protocol_export_rows_empty'));
        }

        $dir = self::resolveProtocolExportDir();
        $baseName = self::safeExportFileName($baseName, 'protocol_export_' . date('Ymd_His'));
        $fileType = strtolower(trim($fileType));
        if (!in_array($fileType, ['xls', 'xlsx'], true)) {
            $fileType = 'xls';
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');
        $sheet->fromArray(self::protocolExportHeaders(), null, 'A1');
        $rowNo = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, 'A' . $rowNo);
            $rowNo++;
        }
        $spreadsheet->createSheet()->setTitle('Sheet2');
        $spreadsheet->createSheet()->setTitle('Sheet3');
        $spreadsheet->setActiveSheetIndex(0);

        $extension = $fileType;
        $path = rtrim($dir['absolute'], '/\\') . '/' . $baseName . '.' . $extension;
        try {
            if ($fileType === 'xls') {
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($spreadsheet);
            } else {
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            }
            $writer->save($path);
        } catch (\Throwable $e) {
            $extension = 'xlsx';
            $path = rtrim($dir['absolute'], '/\\') . '/' . $baseName . '.xlsx';
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($path);
        }
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'file' => basename($path),
            'path' => self::toProtocolPublicPath($path),
            'abs_path' => $path,
            'row_count' => count($rows),
            'file_type' => $extension,
        ];
    }

    private static function buildProtocolExportRows(array $protocol): array
    {
        $protocolCode = (string)self::exportCell($protocol, 'ProtocolCode');
        $detail = self::loadProtocolDetail($protocolCode);
        $rows = self::buildProtocolExportRowsFromDetail($protocol, $detail);
        if (!$rows) {
            $rows = self::buildProtocolExportRowsFromJson($protocol);
        }
        if (!$rows) {
            $rows = self::buildProtocolExportRowsFromLegacy($protocol);
        }
        return $rows;
    }

    private static function migrateProtocolRowV2(array $protocol, array $data, bool $dryRun): array
    {
        $protocolCode = (string)self::exportCell($protocol, 'ProtocolCode');
        $protocolName = (string)self::exportCell($protocol, 'ProtocolName', $protocolCode);
        $rows = self::buildProtocolExportRows($protocol);
        if (!$rows) {
            P_E('protocol has no command or param rows to migrate');
        }

        $backupName = self::safeExportFileName($protocolName . '_legacy_backup_' . date('Ymd_His'), 'protocol_legacy_backup_' . date('Ymd_His'));
        $backup = self::writeProtocolWorkbook($rows, $backupName, (string)($data['file_type'] ?? $data['FileType'] ?? 'xls'));
        if ($dryRun) {
            return [
                'migrated' => false,
                'dry_run' => true,
                'backup' => $backup,
                'ProtocolCode' => $protocolCode,
                'ProtocolName' => $protocolName,
                'ProtocolType' => self::exportCell($protocol, 'ProtocolType'),
                'row_count' => count($rows),
            ];
        }

        $sheetContent = array_merge([self::protocolExportHeaders()], $rows);
        $payload = self::buildImportPayload($sheetContent, $protocolName . '.xls');

        try {
            self::persistProtocolPayload($payload);
            self::rebuildDeviceAlarmModes((string)$payload['ProtocolCode'], $payload['detailAlarmModes']);
        } catch (\Throwable $e) {
            error_log('[MigrateDevicePtlKeyV2] failed: ' . $e->getMessage());
            P_E(str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')));
        }

        return [
            'migrated' => true,
            'dry_run' => false,
            'backup' => $backup,
            'ProtocolCode' => $payload['ProtocolCode'],
            'ProtocolName' => $payload['ProtocolName'],
            'ProtocolType' => $payload['ProtocolType'],
            'commands' => count($payload['detailCommands']),
            'params' => count($payload['detailParams']),
            'alarms' => count($payload['detailAlarmModes']),
        ];
    }

    public static function exportProtocolV2(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        self::ensureProtocolDetailTables();

        $protocols = self::findExportProtocolRows($data);
        if (!$protocols) {
            P_E(dcim_msg('error.protocol_export_protocol_missing'));
        }
        if (count($protocols) > 1) {
            P_E(dcim_msg('error.protocol_export_single_required'));
        }

        $protocol = $protocols[0];
        $protocolCode = (string)self::exportCell($protocol, 'ProtocolCode');
        $rows = self::buildProtocolExportRows($protocol);

        $fileName = trim((string)($data['file_name'] ?? $data['FileName'] ?? ''));
        if ($fileName === '') {
            $fileName = (string)self::exportCell($protocol, 'ProtocolName', $protocolCode);
        }
        $fileName = preg_replace('/\.(xls|xlsx)$/i', '', $fileName);
        $fileType = (string)($data['file_type'] ?? $data['FileType'] ?? 'xls');
        $result = self::writeProtocolWorkbook($rows, $fileName, $fileType);
        $result['ProtocolCode'] = $protocolCode;
        $result['ProtocolName'] = self::exportCell($protocol, 'ProtocolName');
        $result['ProtocolType'] = self::exportCell($protocol, 'ProtocolType');

        O_E($result, tp_msg_success(), 100, 1);
    }

    public static function migrateProtocolV2Batch(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        self::ensureProtocolDetailTables();
        $dryRun = self::requestBool($data, ['dry_run', 'DryRun'], false);

        if (self::requestBool($data, ['batch', 'Batch'], false)) {
            $protocols = self::findBatchExportProtocolRows($data);
            if (!$protocols) {
                P_E('no protocols matched batch migrate condition');
            }
            $items = [];
            foreach ($protocols as $protocol) {
                $items[] = self::migrateProtocolRowV2($protocol, $data, $dryRun);
            }
            O_E([
                'batch' => true,
                'migrated' => !$dryRun,
                'dry_run' => $dryRun,
                'total' => count($items),
                'success' => count($items),
                'condition' => [
                    'id_lt' => (int)($data['id_lt'] ?? $data['IdLt'] ?? $data['IDLT'] ?? 0),
                    'only_protocol_value_not_empty' => self::requestBool($data, ['only_protocol_value_not_empty', 'OnlyProtocolValueNotEmpty'], true),
                ],
                'items' => $items,
            ], tp_msg_success(), 100, count($items));
            return;
        }

        $protocols = self::findExportProtocolRows($data);
        if (!$protocols) {
            P_E('protocol not found or no data to migrate');
        }
        if (count($protocols) > 1) {
            P_E('please specify one protocol to migrate');
        }

        O_E(self::migrateProtocolRowV2($protocols[0], $data, $dryRun), tp_msg_success(), 100, 1);
    }

    public static function migrateProtocolV2(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        self::ensureProtocolDetailTables();

        $protocols = self::findExportProtocolRows($data);
        if (!$protocols) {
            P_E(dcim_msg('error.protocol_migrate_protocol_missing'));
        }
        if (count($protocols) > 1) {
            P_E(dcim_msg('error.protocol_migrate_single_required'));
        }

        $protocol = $protocols[0];
        $protocolCode = (string)self::exportCell($protocol, 'ProtocolCode');
        $protocolName = (string)self::exportCell($protocol, 'ProtocolName', $protocolCode);
        $rows = self::buildProtocolExportRows($protocol);
        if (!$rows) {
            P_E(dcim_msg('error.protocol_migrate_rows_empty'));
        }

        $backupName = self::safeExportFileName($protocolName . '_legacy_backup_' . date('Ymd_His'), 'protocol_legacy_backup_' . date('Ymd_His'));
        $backup = self::writeProtocolWorkbook($rows, $backupName, (string)($data['file_type'] ?? $data['FileType'] ?? 'xls'));
        $dryRun = !empty($data['dry_run']) || !empty($data['DryRun']);
        if ($dryRun) {
            O_E([
                'migrated' => false,
                'dry_run' => true,
                'backup' => $backup,
                'ProtocolCode' => $protocolCode,
                'ProtocolName' => $protocolName,
                'ProtocolType' => self::exportCell($protocol, 'ProtocolType'),
                'row_count' => count($rows),
            ], tp_msg_success(), 100, 1);
            return;
        }

        $sheetContent = array_merge([self::protocolExportHeaders()], $rows);
        $payload = self::buildImportPayload($sheetContent, $protocolName . '.xls');

        try {
            self::persistProtocolPayload($payload);
            self::rebuildDeviceAlarmModes((string)$payload['ProtocolCode'], $payload['detailAlarmModes']);
        } catch (\Throwable $e) {
            error_log('[MigrateDevicePtlKeyV2] failed: ' . $e->getMessage());
            P_E(str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')));
        }

        O_E([
            'migrated' => true,
            'dry_run' => false,
            'backup' => $backup,
            'ProtocolCode' => $payload['ProtocolCode'],
            'ProtocolName' => $payload['ProtocolName'],
            'ProtocolType' => $payload['ProtocolType'],
            'commands' => count($payload['detailCommands']),
            'params' => count($payload['detailParams']),
            'alarms' => count($payload['detailAlarmModes']),
        ], tp_msg_success(), 100, 1);
    }

    public static function importProtocolV2(): void
    {
        error_log('[ImportDevicePtlKeyV2] start');
        $data = Flight::request_data();
        self::requireAuth($data);

        $upload = self::resolveUploadFile($data);
        $tmpPath = is_string($upload['tmp'] ?? null) ? $upload['tmp'] : '';
        $originalName = (string)($upload['name'] ?? '');
        if ($tmpPath === '') {
            P_E(dcim_msg('error.failed_read_file'));
        }
        if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
            P_E(dcim_msg('error.failed_read_file'));
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === 'xlsx') {
            $readerTypes = ['Xlsx', 'Xls'];
        } elseif ($extension === 'xls') {
            $readerTypes = ['Xls', 'Xlsx'];
        } else {
            $readerTypes = ['Xlsx', 'Xls'];
        }

        $spreadsheet = null;
        $lastReadError = '';
        foreach ($readerTypes as $readerType) {
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($readerType);
                $spreadsheet = $reader->load($tmpPath);
                break;
            } catch (\Throwable $e) {
                $lastReadError = $e->getMessage();
            }
        }
        if ($spreadsheet === null) {
            $reason = trim((string)$lastReadError);
            if ($reason === '') {
                $reason = dcim_msg('error.failed_read_file');
            }
            P_E(str_replace('{reason}', $reason, dcim_msg('error.import_failed_with_reason')));
        }

        $sheetContent = $spreadsheet->getSheet(0)->toArray(null, false, false, false);
        $payload = self::buildImportPayload($sheetContent, $originalName, $data);

        try {
            self::persistProtocolPayload($payload);
            self::rebuildDeviceAlarmModes((string)$payload['ProtocolCode'], $payload['detailAlarmModes']);
        } catch (\Throwable $e) {
            error_log('[ImportDevicePtlKeyV2] failed: ' . $e->getMessage());
            P_E(str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')));
        }

        error_log('[ImportDevicePtlKeyV2] success ProtocolCode=' . $payload['ProtocolCode']);
        O_E(true, dcim_msg('error.import_success'), 100, false);
    }
}
