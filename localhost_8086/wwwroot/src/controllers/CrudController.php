<?php
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CrudController {
    private $table;
    private $tableName;
    private $tableForSql;
    private $readTableForSql;
    private static $dmColumnMetaCache = [];

    private function getDriverName(): string
    {
        static $driver = null;
        if ($driver !== null) {
            return $driver;
        }
        try {
            $driver = strtolower((string) Flight::db()->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            $driver = '';
        }
        return $driver;
    }

    private function isDmDriver(): bool
    {
        return $this->getDriverName() === 'dm';
    }

    private function identifierQuote(): string
    {
        return $this->isDmDriver() ? '"' : '`';
    }

    private function normalizeTableInput($table, string $default = 'dcim-person'): string
    {
        if (is_string($table)) {
            $name = trim($table);
            return $name === '' ? $default : $name;
        }
        if (is_object($table) && method_exists($table, '__toString')) {
            $name = trim((string)$table);
            return $name === '' ? $default : $name;
        }
        return $default;
    }

    public function __construct($table = 'dcim-person') {
        $tableName = $this->normalizeTableInput($table, 'dcim-person');
        if (!is_string($tableName)) {
            $tableName = (string)$tableName;
        }
        $this->table = $tableName;
        $this->tableName = $tableName;
        $this->tableForSql = $this->quoteTable($tableName);
        $this->readTableForSql = null;
    }

    private function resolveReadSource($table)
    {
        $flag = getenv('DCIM_USE_VIEWS');
        if ($flag !== false && ($flag === '0' || strtolower($flag) === 'false')) {
            return $this->tableForSql;
        }
        $mapPath = __DIR__ . '/../viewmap.php';
        if (is_file($mapPath)) {
            $map = include $mapPath;
            if (is_array($map) && isset($map[$table])) {
                $view = $this->normalizeTableInput($map[$table], $table);
                return $this->quoteTable($view);
            }
        }
        try {
            $db = Flight::db();
            $normalized = str_replace('-', '_', $table);
            $candidates = ['v_' . $normalized, 'vw_' . $normalized, $normalized . '_v', 'v-' . $table];
            $placeholders = implode(',', array_fill(0, count($candidates), '?'));
            $sql = 'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ') LIMIT 1';
            $stmt = $db->prepare($sql);
            foreach ($candidates as $i => $name) { $stmt->bindValue($i + 1, $name); }
            if ($stmt->execute()) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && isset($row['TABLE_NAME'])) {
                    $view = $this->normalizeTableInput($row['TABLE_NAME'], $table);
                    return $this->quoteTable($view);
                }
            }
        } catch (Throwable $e) { }
        return $this->tableForSql;
    }

    private function tableForRead()
    {
        if ($this->readTableForSql === null) {
            $this->readTableForSql = $this->resolveReadSource($this->table);
        }
        return $this->readTableForSql ?: $this->tableForSql;
    }

    private function decodeArrayInput($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        if (!is_string($value)) {
            return [];
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }
        $candidates = [$trimmed];
        if (strpos($trimmed, '%') !== false) {
            $candidates[] = urldecode($trimmed);
            $candidates[] = rawurldecode($trimmed);
        }

        foreach ($candidates as $candidate) {
            $current = trim((string) $candidate);
            if ($current === '') {
                continue;
            }
            // Legacy clients may send a JSON string that itself wraps JSON text:
            // "\"{\\\"field\\\":\\\"value\\\"}\""
            for ($i = 0; $i < 3; $i++) {
                $decoded = json_decode($current, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
                if (is_string($decoded)) {
                    $next = trim($decoded);
                    if ($next === '' || $next === $current) {
                        break;
                    }
                    $current = $next;
                    continue;
                }
                break;
            }
        }

        return [];
    }

    private function decodeRawJsonPayload(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $contentType = '';
        foreach (['CONTENT_TYPE', 'HTTP_CONTENT_TYPE'] as $key) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && trim($_SERVER[$key]) !== '') {
                $contentType = trim($_SERVER[$key]);
                break;
            }
        }

        $raw = '';
        try {
            $request = Flight::request();
            if (is_object($request) && method_exists($request, 'getBody')) {
                $raw = (string) $request->getBody();
            } else {
                $raw = (string) @file_get_contents('php://input');
            }
        } catch (\Throwable $e) {
            $raw = (string) @file_get_contents('php://input');
        }
        if (!is_string($raw) || trim($raw) === '') {
            $cached = [];
            return $cached;
        }

        $rawTrimmed = ltrim($raw);
        $looksJson = $rawTrimmed !== '' && ($rawTrimmed[0] === '{' || $rawTrimmed[0] === '[');
        if ($contentType !== '' && stripos($contentType, 'json') === false && !$looksJson) {
            $cached = [];
            return $cached;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $cached = [];
            return $cached;
        }
        if ($decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1)) {
            // Object payload is expected for API calls; ignore top-level JSON list.
            $cached = [];
            return $cached;
        }

        $cached = $decoded;
        return $cached;
    }

    private function isWhereSqlCompatibleWithColumns(string $whereSql, array $columnSet): bool
    {
        if (!$columnSet) {
            return true;
        }
        $trimmed = trim($whereSql);
        if ($trimmed === '') {
            return false;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*(=|<>|<|>|<=|>=|LIKE|IN|BETWEEN)\b/i', $trimmed, $m)) {
            return true;
        }
        $field = (string)($m[1] ?? '');
        if ($field === '') {
            return true;
        }
        return isset($columnSet[$field]) || isset($columnSet[strtolower($field)]);
    }

    private function mergeRequestPayload(array $payload): array
    {
        $rawPayload = $this->decodeRawJsonPayload();
        if (!$rawPayload) {
            return $payload;
        }
        return array_merge($payload, $rawPayload);
    }

    private function normalizeStringList($value): array
    {
        $items = [];
        if (is_string($value)) {
            $parts = explode(',', $value);
            foreach ($parts as $part) {
                $item = trim($part);
                if ($item !== '') {
                    $items[] = $item;
                }
            }
            return array_values(array_unique($items));
        }
        if (is_array($value)) {
            foreach ($value as $part) {
                if (!is_scalar($part)) {
                    continue;
                }
                $item = trim((string) $part);
                if ($item !== '') {
                    $items[] = $item;
                }
            }
        }
        return array_values(array_unique($items));
    }

    private function normalizeTableName($table): ?string
    {
        if (!is_string($table) || trim($table) === '') {
            return null;
        }
        $name = str_replace(['`', '"'], '', trim($table));
        return preg_match('/^[A-Za-z0-9_-]+$/', $name) === 1 ? $name : null;
    }

    private function isSafeFieldName(string $field): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) === 1;
    }

    private function quoteField(string $field): string
    {
        $q = $this->identifierQuote();
        $clean = str_replace(['`', '"'], '', $field);
        if ($q === '"') {
            return '"' . str_replace('"', '""', $clean) . '"';
        }
        return '`' . $clean . '`';
    }

    private function quoteTable(string $table): string
    {
        $q = $this->identifierQuote();
        $clean = str_replace(['`', '"'], '', $table);
        if ($q === '"') {
            return '"' . str_replace('"', '""', $clean) . '"';
        }
        return '`' . $clean . '`';
    }

    private function getTableColumnMapInsensitive(string $table): array
    {
        static $cache = [];
        $cacheKey = strtolower($this->getDriverName() . '|' . $table);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $map = [];
        foreach ($this->getTableColumns($table) as $column) {
            $name = trim((string) $column);
            if ($name === '') {
                continue;
            }
            $map[strtolower($name)] = $name;
        }
        $cache[$cacheKey] = $map;
        return $map;
    }

    private function normalizeRawSqlForDriver(string $sql): string
    {
        if (!$this->isDmDriver()) {
            return $sql;
        }
        if (trim($sql) === '') {
            return $sql;
        }

        $columnMap = $this->getTableColumnMapInsensitive($this->tableName);
        if (!$columnMap) {
            return $sql;
        }

        $segments = preg_split('/(\'(?:\'\'|[^\'])*\'|"(?:""|[^"])*")/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($segments) || !$segments) {
            return $sql;
        }

        foreach ($segments as $idx => $segment) {
            if ($segment === '' || ($idx % 2) === 1) {
                continue;
            }
            $segments[$idx] = preg_replace_callback(
                '/(?<![:`"])\b([A-Za-z_][A-Za-z0-9_]*)\b(?![`"])/',
                function (array $matches) use ($columnMap): string {
                    $token = (string) ($matches[1] ?? '');
                    if ($token === '') {
                        return $matches[0];
                    }
                    $lookup = strtolower($token);
                    if (!isset($columnMap[$lookup])) {
                        return $matches[0];
                    }
                    return $this->quoteField($columnMap[$lookup]);
                },
                $segment
            );
        }

        return implode('', $segments);
    }

    private function expandDuplicateNamedParams(string $sql, array $params): array
    {
        if ($sql === '' || !$params || strpos($sql, ':') === false) {
            return [$sql, $params];
        }

        $normalized = [];
        foreach ($params as $ph => $val) {
            if (!is_string($ph) || trim($ph) === '') {
                continue;
            }
            $key = $ph[0] === ':' ? $ph : (':' . $ph);
            $normalized[$key] = $val;
        }
        if (!$normalized) {
            return [$sql, []];
        }

        $seen = [];
        $expanded = [];
        $rewritten = preg_replace_callback(
            '/:([A-Za-z_][A-Za-z0-9_]*)/',
            function (array $m) use (&$seen, &$expanded, $normalized): string {
                $base = ':' . (string)($m[1] ?? '');
                if (!array_key_exists($base, $normalized)) {
                    return $m[0];
                }
                $idx = isset($seen[$base]) ? (int)$seen[$base] : 0;
                $seen[$base] = $idx + 1;
                if ($idx === 0) {
                    $expanded[$base] = $normalized[$base];
                    return $base;
                }

                $suffix = $idx + 1;
                $candidate = $base . '__' . $suffix;
                while (array_key_exists($candidate, $expanded)) {
                    $suffix++;
                    $candidate = $base . '__' . $suffix;
                }
                $expanded[$candidate] = $normalized[$base];
                return $candidate;
            },
            $sql
        );

        return [$rewritten, $expanded];
    }

    private function findColumnNameInsensitive(array $columns, string $target): ?string
    {
        $needle = strtolower(trim($target));
        if ($needle === '') {
            return null;
        }
        foreach ($columns as $column) {
            $name = trim((string)$column);
            if ($name !== '' && strtolower($name) === $needle) {
                return $name;
            }
        }
        return null;
    }

    private function buildReadableRowWhereSql(): string
    {
        $columns = $this->getTableColumns($this->tableName);
        $isDeletedCol = $this->findColumnNameInsensitive($columns, 'is_deleted');
        if ($isDeletedCol !== null) {
            return $this->quoteField($isDeletedCol) . ' = 1';
        }
        $statusCol = $this->findColumnNameInsensitive($columns, 'status');
        if ($statusCol !== null) {
            $qStatus = $this->quoteField($statusCol);
            return '(' . $qStatus . ' <> -1 OR ' . $qStatus . ' IS NULL)';
        }
        return '1=1';
    }

    private function resolveAllowedFieldName(string $field, array $allowedFieldSet = []): string
    {
        $name = trim($field);
        if ($name === '') {
            return '';
        }
        if (!$allowedFieldSet) {
            return $name;
        }
        if (isset($allowedFieldSet[$name]) && is_string($allowedFieldSet[$name]) && $allowedFieldSet[$name] !== '') {
            return $allowedFieldSet[$name];
        }
        $lower = strtolower($name);
        if (isset($allowedFieldSet[$lower]) && is_string($allowedFieldSet[$lower]) && $allowedFieldSet[$lower] !== '') {
            return $allowedFieldSet[$lower];
        }
        return '';
    }

    private function getTableColumns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $cols = [];
        try {
            if ($this->isDmDriver()) {
                $stmt = Flight::db()->prepare('SELECT COLUMN_NAME FROM USER_TAB_COLUMNS WHERE UPPER(TABLE_NAME) = UPPER(:table_name) ORDER BY COLUMN_ID');
                $stmt->bindValue(':table_name', $table);
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $col = (string)($row['COLUMN_NAME'] ?? '');
                    if ($this->isSafeFieldName($col)) {
                        $cols[] = $col;
                    }
                }
            } else {
                $stmt = Flight::db()->prepare('SHOW COLUMNS FROM ' . $this->quoteTable($table));
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (isset($row['Field']) && $this->isSafeFieldName((string) $row['Field'])) {
                        $cols[] = (string) $row['Field'];
                    }
                }
            }
        } catch (\Throwable $e) {
            $cols = [];
        }

        $cache[$table] = $cols;
        return $cols;
    }

    private function resolveGenericBindType($value): int
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }
        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }
        return PDO::PARAM_STR;
    }

    private function isDmLobType(string $type): bool
    {
        $normalized = strtoupper(trim($type));
        return in_array($normalized, ['CLOB', 'NCLOB', 'BLOB', 'TEXT', 'LONG', 'LONGVARCHAR'], true);
    }

    private function resolveBindTypeForField(string $field, $value): int
    {
        $generic = $this->resolveGenericBindType($value);
        if ($generic !== PDO::PARAM_STR) {
            return $generic;
        }
        if (!$this->isDmDriver()) {
            return PDO::PARAM_STR;
        }
        $lookup = strtolower(trim($field));
        if ($lookup === '') {
            return PDO::PARAM_STR;
        }
        $meta = $this->getDmColumnMetaMap($this->tableName);
        if (!isset($meta[$lookup]) || !is_array($meta[$lookup])) {
            return PDO::PARAM_STR;
        }
        $type = (string)($meta[$lookup]['type'] ?? '');
        if ($this->isDmLobType($type)) {
            return PDO::PARAM_LOB;
        }
        return PDO::PARAM_STR;
    }

    private function getDmColumnMetaMap(string $table): array
    {
        if (!$this->isDmDriver()) {
            return [];
        }
        $cacheKey = strtolower(trim($table));
        if ($cacheKey === '') {
            return [];
        }
        if (isset(self::$dmColumnMetaCache[$cacheKey]) && is_array(self::$dmColumnMetaCache[$cacheKey])) {
            return self::$dmColumnMetaCache[$cacheKey];
        }

        $meta = [];
        try {
            $stmt = Flight::db()->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE, DATA_LENGTH
                 FROM USER_TAB_COLUMNS
                 WHERE UPPER(TABLE_NAME) = UPPER(:table_name)'
            );
            $stmt->bindValue(':table_name', $table);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $column = trim((string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? ''));
                if ($column === '' || !$this->isSafeFieldName($column)) {
                    continue;
                }
                $type = (string) ($row['DATA_TYPE'] ?? $row['data_type'] ?? '');
                $length = (int) ($row['DATA_LENGTH'] ?? $row['data_length'] ?? 0);
                $meta[strtolower($column)] = [
                    'name' => $column,
                    'type' => $type,
                    'length' => $length,
                ];
            }
        } catch (\Throwable $e) {
            error_log('[crud.dm.column_meta] load column metadata failed: ' . $e->getMessage());
            $meta = [];
        }

        self::$dmColumnMetaCache[$cacheKey] = $meta;
        return $meta;
    }

    private function hasTableColumnInsensitive(string $field): bool
    {
        $target = strtolower(trim($field));
        if ($target === '') {
            return false;
        }
        $columns = $this->getTableColumns($this->tableName);
        foreach ($columns as $column) {
            if (strtolower((string) $column) === $target) {
                return true;
            }
        }
        return false;
    }

    private function containsConditionField(array $conditions, string $field): bool
    {
        foreach ($conditions as $cond) {
            if (!is_array($cond) || count($cond) < 2) {
                continue;
            }
            $condField = trim((string) ($cond[0] ?? ''));
            if ($condField !== '' && strcasecmp($condField, $field) === 0) {
                return true;
            }
        }
        return false;
    }

    private function remapConditionField(array $conditions, string $fromField, string $toField): array
    {
        $mapped = [];
        foreach ($conditions as $cond) {
            if (!is_array($cond) || count($cond) < 2) {
                $mapped[] = $cond;
                continue;
            }
            $field = trim((string) ($cond[0] ?? ''));
            if ($field === '' || strcasecmp($field, $fromField) !== 0) {
                $mapped[] = $cond;
                continue;
            }

            if (count($cond) === 2) {
                $cond[0] = $toField;
                $mapped[] = $cond;
                continue;
            }

            $operator = strtoupper(trim((string) ($cond[1] ?? '')));
            if ($operator === '=') {
                $cond[0] = $toField;
            }
            $mapped[] = $cond;
        }
        return $mapped;
    }

    private function isMissingColumnErrorForField(\Throwable $e, string $field): bool
    {
        $msg = (string) $e->getMessage();
        if ($msg === '') {
            return false;
        }
        $patterns = [
            "Unknown column '{$field}'",
            "Unknown column `{$field}`",
            'Invalid column name [' . strtoupper($field) . ']',
        ];
        foreach ($patterns as $needle) {
            if (stripos($msg, $needle) !== false) {
                return true;
            }
        }
        if (preg_match('/Invalid column name\s*\[([^\]]+)\]/i', $msg, $m) === 1) {
            $missing = trim((string)($m[1] ?? ''));
            if ($missing !== '' && strcasecmp($missing, $field) === 0) {
                return true;
            }
        }
        return false;
    }

    private function normalizeFilterPayload(array $requestData): array
    {
        foreach (['params', 'query_params', 'filters'] as $key) {
            if (!array_key_exists($key, $requestData)) {
                continue;
            }
            $decoded = $this->decodeArrayInput($requestData[$key]);
            if ($decoded) {
                return $decoded;
            }
        }

        $reserved = [
            'token' => true,
            'pageNo' => true,
            'pageSize' => true,
            'limit' => true,
            'offset' => true,
            'order' => true,
            'sequence' => true,
            'orderBy' => true,
            'sort' => true,
            'table' => true,
            'columns' => true,
            'fields' => true,
            'search' => true,
            'search_fields' => true,
            'file_name' => true,
            'zip_name' => true,
            'file_path' => true,
            'mappings' => true,
            'field_map' => true,
            'upsert_keys' => true,
            'header_row' => true,
            'skip_rows' => true,
            'target' => true,
            'active_only' => true,
            'not_deleted_only' => true,
        ];

        $result = [];
        foreach ($requestData as $key => $value) {
            if (!is_string($key) || isset($reserved[$key])) {
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function normalizeLegacyBetweenBoundary($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $normalized = trim((string) $value, " \t\n\r\0\x0B'\"");
        if ($normalized === '') {
            return '';
        }

        if (strpos($normalized, '%') !== false && preg_match('/%[0-9A-Fa-f]{2}/', $normalized) === 1) {
            $decoded = rawurldecode($normalized);
            if (is_string($decoded) && $decoded !== '') {
                $normalized = trim($decoded, " \t\n\r\0\x0B'\"");
            }
        }

        if (strpos($normalized, '+') !== false && preg_match('/^\d{4}-\d{2}-\d{2}\+\d{2}:\d{2}(:\d{2})?$/', $normalized) === 1) {
            $normalized = str_replace('+', ' ', $normalized);
        }
        if (strpos($normalized, 'T') !== false && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $normalized) === 1) {
            $normalized = str_replace('T', ' ', $normalized);
        }
        return $normalized;
    }

    private function normalizeLegacyLikeBoundary($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        if (strpos($normalized, '%') !== false && preg_match('/%[0-9A-Fa-f]{2}/', $normalized) === 1) {
            $decoded = rawurldecode($normalized);
            if (is_string($decoded) && $decoded !== '') {
                $normalized = $decoded;
            }
        }

        // Legacy clients sometimes use "$" as wildcard placeholder in LIKE payloads.
        // Only convert when the string is purely wildcard characters to avoid touching real content.
        if (strpos($normalized, '$') !== false && preg_match('/^[%_$]+$/', $normalized) === 1) {
            $normalized = str_replace('$', '%', $normalized);
        }

        return $normalized;
    }

    private function appendFilterCondition(array &$conditions, array &$params, string $field, string $operator, $value, string $prefix, int &$counter, array $allowedFieldSet = []): void
    {
        if (!$this->isSafeFieldName($field)) {
            return;
        }
        $resolvedField = $this->resolveAllowedFieldName($field, $allowedFieldSet);
        if ($allowedFieldSet && $resolvedField === '') {
            return;
        }
        if ($resolvedField === '') {
            $resolvedField = $field;
        }
        if (!$this->isSafeFieldName($resolvedField)) {
            return;
        }

        $op = strtolower(trim($operator));
        $opMap = [
            '=' => '=',
            'eq' => '=',
            '!=' => '!=',
            '<>' => '!=',
            'neq' => '!=',
            '>' => '>',
            '>=' => '>=',
            '<' => '<',
            '<=' => '<=',
            'like' => 'LIKE',
            'not like' => 'NOT LIKE',
            'in' => 'IN',
            'not in' => 'NOT IN',
            'between' => 'BETWEEN',
            'is' => 'IS',
            'is not' => 'IS NOT',
        ];
        $sqlOp = $opMap[$op] ?? '=';
        $fieldSql = $this->quoteField($resolvedField);

        if ($sqlOp === 'IN' || $sqlOp === 'NOT IN') {
            $values = is_array($value) ? $value : explode(',', str_replace(['(', ')'], '', (string) $value));
            $placeholders = [];
            foreach ($values as $val) {
                if (is_string($val)) {
                    $val = trim($val);
                }
                if ($val === '' || $val === null) {
                    continue;
                }
                $ph = ':' . $prefix . $counter++;
                $placeholders[] = $ph;
                $params[$ph] = $val;
            }
            if (!$placeholders) {
                return;
            }
            $conditions[] = sprintf('%s %s (%s)', $fieldSql, $sqlOp, implode(', ', $placeholders));
            return;
        }

        if ($sqlOp === 'BETWEEN') {
            if (is_array($value)) {
                $values = array_values($value);
            } else {
                $raw = trim((string) $value);
                if ($raw === '') {
                    return;
                }
                // Support legacy BETWEEN payload style:
                // "('2025-01-01 00:00:00','2025-12-31 23:59:59')"
                if ((substr($raw, 0, 1) === '(' && substr($raw, -1) === ')') || (substr($raw, 0, 1) === '[' && substr($raw, -1) === ']')) {
                    $raw = substr($raw, 1, -1);
                }
                $values = preg_split('/\s*,\s*/', $raw, 2);
            }
            if (count($values) < 2) {
                return;
            }
            $start = $this->normalizeLegacyBetweenBoundary($values[0] ?? null);
            $end = $this->normalizeLegacyBetweenBoundary($values[1] ?? null);
            if ($start === '' || $end === '') {
                return;
            }
            $ph1 = ':' . $prefix . $counter++;
            $ph2 = ':' . $prefix . $counter++;
            $conditions[] = sprintf('%s BETWEEN %s AND %s', $fieldSql, $ph1, $ph2);
            $params[$ph1] = $start;
            $params[$ph2] = $end;
            return;
        }

        if ($sqlOp === 'IS' || $sqlOp === 'IS NOT') {
            $nullLike = $value === null || (is_string($value) && strtolower(trim($value)) === 'null');
            if ($nullLike) {
                $conditions[] = sprintf('%s %s NULL', $fieldSql, $sqlOp);
                return;
            }
        }

        if (($sqlOp === 'LIKE' || $sqlOp === 'NOT LIKE') && is_scalar($value)) {
            $value = $this->normalizeLegacyLikeBoundary($value);
        }

        $ph = ':' . $prefix . $counter++;
        $conditions[] = sprintf('%s %s %s', $fieldSql, $sqlOp, $ph);
        $params[$ph] = $value;
    }

    private function buildOrderBy(array $requestData, array $allowedFieldSet = [], string $defaultField = 'id', string $defaultSequence = 'DESC'): string
    {
        $rawField = isset($requestData['order']) ? trim((string) $requestData['order']) : '';
        $sequence = isset($requestData['sequence']) ? strtoupper(trim((string) $requestData['sequence'])) : '';

        if ($rawField === '' && isset($requestData['orderBy']) && is_string($requestData['orderBy'])) {
            $parts = preg_split('/\s+/', trim($requestData['orderBy']));
            if ($parts && isset($parts[0])) {
                $rawField = trim($parts[0]);
            }
            if ($sequence === '' && $parts && isset($parts[1])) {
                $sequence = strtoupper(trim($parts[1]));
            }
        }

        if (!$this->isSafeFieldName($rawField)) {
            $rawField = '';
        }
        $resolvedField = $this->resolveAllowedFieldName($rawField, $allowedFieldSet);
        if ($resolvedField === '') {
            $resolvedField = $this->resolveAllowedFieldName($defaultField, $allowedFieldSet);
        }
        if ($resolvedField === '') {
            $resolvedField = $defaultField;
        }
        if (!$this->isSafeFieldName($resolvedField)) {
            return '';
        }

        if ($sequence !== 'ASC' && $sequence !== 'DESC') {
            $sequence = strtoupper($defaultSequence) === 'ASC' ? 'ASC' : 'DESC';
        }

        return sprintf('ORDER BY %s %s', $this->quoteField($resolvedField), $sequence);
    }

    private function buildFilterQueryParts(array $requestData, array $options = []): array
    {
        $table = $options['table'] ?? $this->table;
        $safeTable = $this->normalizeTableName((string) $table);
        if ($safeTable === null) {
            throw new InvalidArgumentException('invalid table name');
        }

        $columns = $this->getTableColumns($safeTable);
        $allowedFields = isset($options['allowed_fields']) ? $this->normalizeStringList($options['allowed_fields']) : $columns;
        if (!$allowedFields) {
            $allowedFields = $columns;
        }
        $allowedFieldSet = [];
        foreach ($allowedFields as $allowedField) {
            if (!is_string($allowedField)) {
                continue;
            }
            $name = trim($allowedField);
            if ($name === '') {
                continue;
            }
            $allowedFieldSet[$name] = $name;
            $allowedFieldSet[strtolower($name)] = $name;
        }

        $conditions = [];
        $params = [];
        $counter = 1;

        if (!empty($options['base_conditions']) && is_array($options['base_conditions'])) {
            foreach ($options['base_conditions'] as $baseCond) {
                if (!is_array($baseCond) || count($baseCond) < 2) {
                    continue;
                }
                if (count($baseCond) >= 3) {
                    [$field, $operator, $value] = $baseCond;
                } else {
                    [$field, $value] = $baseCond;
                    $operator = '=';
                }
                $this->appendFilterCondition($conditions, $params, (string) $field, (string) $operator, $value, 'b', $counter, $allowedFieldSet);
            }
        }

        if (!empty($options['active_only']) && isset($allowedFieldSet['status'])) {
            $this->appendFilterCondition($conditions, $params, 'status', '=', 1, 'b', $counter, $allowedFieldSet);
        }
        if (!empty($options['not_deleted_only']) && isset($allowedFieldSet['is_deleted'])) {
            $this->appendFilterCondition($conditions, $params, 'is_deleted', '=', 1, 'b', $counter, $allowedFieldSet);
        }

        $queryFilters = $this->normalizeFilterPayload($requestData);
        foreach ($queryFilters as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_array($value) && isset($value[0]) && is_array($value[0]) && array_key_exists('operator', $value[0])) {
                foreach ($value as $cond) {
                    $operator = isset($cond['operator']) ? (string) $cond['operator'] : '=';
                    $val = $cond['value'] ?? null;
                    $this->appendFilterCondition($conditions, $params, $key, $operator, $val, 'f', $counter, $allowedFieldSet);
                }
                continue;
            }

            if (is_array($value) && array_key_exists('operator', $value)) {
                $operator = isset($value['operator']) ? (string) $value['operator'] : '=';
                $val = $value['value'] ?? null;
                $this->appendFilterCondition($conditions, $params, $key, $operator, $val, 'f', $counter, $allowedFieldSet);
                continue;
            }

            if (is_string($value) && strpos($value, '%') !== false) {
                $this->appendFilterCondition($conditions, $params, $key, 'LIKE', $value, 'f', $counter, $allowedFieldSet);
                continue;
            }

            $this->appendFilterCondition($conditions, $params, $key, '=', $value, 'f', $counter, $allowedFieldSet);
        }

        $searchRaw = $requestData['search'] ?? '';
        $search = is_scalar($searchRaw) ? trim((string) $searchRaw) : '';
        if ($search !== '') {
            $searchFields = [];
            if (isset($requestData['search_fields'])) {
                $searchFields = $this->normalizeStringList($requestData['search_fields']);
            }
            if (!$searchFields && !empty($options['search_fields'])) {
                $searchFields = $this->normalizeStringList($options['search_fields']);
            }
            $searchParts = [];
            foreach ($searchFields as $field) {
                if (!$this->isSafeFieldName($field)) {
                    continue;
                }
                $resolvedField = $this->resolveAllowedFieldName($field, $allowedFieldSet);
                if ($allowedFieldSet && $resolvedField === '') {
                    continue;
                }
                if ($resolvedField === '') {
                    $resolvedField = $field;
                }
                $ph = ':s' . $counter++;
                $searchParts[] = sprintf('%s LIKE %s', $this->quoteField($resolvedField), $ph);
                $params[$ph] = '%' . $search . '%';
            }
            if ($searchParts) {
                $conditions[] = '(' . implode(' OR ', $searchParts) . ')';
            }
        }

        $whereSql = $conditions ? implode(' AND ', $conditions) : '1=1';
        $defaultOrder = isset($options['default_order']) ? (string) $options['default_order'] : 'id';
        $defaultSequence = isset($options['default_sequence']) ? (string) $options['default_sequence'] : 'DESC';
        $orderBy = $this->buildOrderBy($requestData, $allowedFieldSet, $defaultOrder, $defaultSequence);

        return [
            'table' => $safeTable,
            'columns' => $columns,
            'allowed_fields' => $allowedFields,
            'where' => $whereSql,
            'params' => $params,
            'order_by' => $orderBy,
        ];
    }

    private function resolveLocalPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        $candidates = [$path];
        $projectRoot = dirname(__DIR__, 1);
        $trim = ltrim(str_replace('\\', '/', $path), '/');
        $candidates[] = $projectRoot . '/' . $trim;
        $candidates[] = dirname(__DIR__, 2) . '/' . $trim;
        $candidates[] = dirname(__DIR__, 2) . '/public/' . $trim;

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function resolveUploadedTempPath(): ?string
    {
        $arrayGet = static function (array $value, array $path) {
            $cursor = $value;
            foreach ($path as $part) {
                if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                    return null;
                }
                $cursor = $cursor[$part];
            }
            return $cursor;
        };

        $flattenUploadValues = function ($tmpNode, $errorNode = UPLOAD_ERR_OK, array $path = []) use (&$flattenUploadValues, $arrayGet): array {
            if (is_string($tmpNode)) {
                $errorCode = is_array($errorNode) ? $arrayGet($errorNode, $path) : $errorNode;
                $errorCode = $errorCode === null ? UPLOAD_ERR_OK : (int)$errorCode;
                return $errorCode === UPLOAD_ERR_OK && $tmpNode !== '' ? [$tmpNode] : [];
            }

            if (!is_array($tmpNode)) {
                return [];
            }

            $result = [];
            foreach ($tmpNode as $key => $child) {
                $result = array_merge($result, $flattenUploadValues($child, $errorNode, array_merge($path, [$key])));
            }
            return $result;
        };

        $extractNode = function ($node) use (&$extractNode, $flattenUploadValues): array {
            $result = [];

            if (is_array($node)) {
                $tmpName = $node['tmp_name'] ?? null;
                if (is_string($tmpName) || is_array($tmpName)) {
                    $result = array_merge($result, $flattenUploadValues($tmpName, $node['error'] ?? UPLOAD_ERR_OK));
                }
                foreach ($node as $child) {
                    if (is_array($child) || is_object($child)) {
                        $result = array_merge($result, $extractNode($child));
                    }
                }
                return $result;
            }

            if (is_object($node)) {
                foreach (['getPathname', 'getRealPath'] as $method) {
                    if (!method_exists($node, $method)) {
                        continue;
                    }
                    try {
                        $tmpName = $node->{$method}();
                        if (is_string($tmpName) && $tmpName !== '') {
                            $result[] = $tmpName;
                            break;
                        }
                    } catch (\Throwable $e) {
                    }
                }

                $objectData = (array)$node;
                if ($objectData) {
                    $result = array_merge($result, $extractNode($objectData));
                }
            }

            return $result;
        };

        $sourceFiles = [];
        if (isset($_FILES) && is_array($_FILES) && !empty($_FILES)) {
            $sourceFiles = $_FILES;
        } else {
            try {
                $request = Flight::request();
                $requestFiles = is_object($request) && isset($request->files) ? (array)$request->files : [];
                if ($requestFiles) {
                    $sourceFiles = $requestFiles;
                }
            } catch (\Throwable $e) {
                $sourceFiles = [];
            }
        }

        foreach ($extractNode($sourceFiles) as $tmpName) {
            if (!is_string($tmpName) || $tmpName === '') {
                continue;
            }
            if (is_uploaded_file($tmpName) || is_file($tmpName)) {
                return $tmpName;
            }
        }

        return null;
    }

    private function sanitizeFileName(string $name, string $fallback): string
    {
        $base = trim($name);
        if ($base === '') {
            $base = $fallback;
        }
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
        $base = trim((string) $base, '_-.');
        return $base === '' ? $fallback : $base;
    }

    private function toPublicPath(string $absolutePath): string
    {
        $publicRoot = str_replace('\\', '/', realpath(dirname(__DIR__, 2) . '/public') ?: '');
        $abs = str_replace('\\', '/', $absolutePath);
        if ($publicRoot !== '' && strpos($abs, $publicRoot) === 0) {
            $relative = substr($abs, strlen($publicRoot));
            return '/' . ltrim((string) $relative, '/');
        }
        return $absolutePath;
    }

    private function resolveWritablePublicDir(array $relativeCandidates): array
    {
        $publicRootReal = realpath(dirname(__DIR__, 2) . '/public');
        if ($publicRootReal === false) {
            throw new RuntimeException('public root missing');
        }
        $publicRoot = rtrim(str_replace('\\', '/', $publicRootReal), '/');
        $attempts = [];

        foreach ($relativeCandidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $relative = '/' . ltrim(str_replace('\\', '/', trim($candidate)), '/');
            $relative = preg_replace('#/+#', '/', $relative);
            if (strpos($relative, '..') !== false) {
                continue;
            }

            if ($relative === '/') {
                $absolute = $publicRoot;
            } else {
                $absolute = $publicRoot . $relative;
                if (!is_dir($absolute)) {
                    if (!@mkdir($absolute, 0777, true) && !is_dir($absolute)) {
                        $attempts[] = $relative . ':mkdir_failed';
                        continue;
                    }
                }
            }
            if (!is_writable($absolute)) {
                $attempts[] = $relative . ':not_writable';
                continue;
            }

            return [
                'relative' => $relative,
                'absolute' => $absolute,
            ];
        }

        $detail = $attempts ? implode(', ', $attempts) : 'no valid candidate';
        throw new RuntimeException('no writable export directory: ' . $detail);
    }

    private function normalizeTokenCandidate($value): string
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

    private function extractTokenFromHeaders(array $headers): string
    {
        $allowedKeys = [
            'auth' => true,
            'authorization' => true,
            'x-auth-token' => true,
        ];
        foreach ($headers as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $normalizedKey = strtolower(trim($key));
            if (!isset($allowedKeys[$normalizedKey])) {
                continue;
            }
            $token = $this->normalizeTokenCandidate($value);
            if ($token !== '') {
                return $token;
            }
        }
        return '';
    }

    private function extractTokenFromServer(): string
    {
        $serverKeys = [
            'HTTP_AUTH',
            'HTTP_AUTHORIZATION',
            'REDIRECT_HTTP_AUTHORIZATION',
            'REDIRECT_AUTHORIZATION',
            'AUTHORIZATION',
            'Authorization',
            'HTTP_X_AUTH_TOKEN',
            'REDIRECT_HTTP_X_AUTH_TOKEN',
            'HTTP_X_FORWARDED_AUTHORIZATION',
        ];
        foreach ($serverKeys as $key) {
            if (!isset($_SERVER[$key])) {
                continue;
            }
            $token = $this->normalizeTokenCandidate($_SERVER[$key]);
            if ($token !== '') {
                return $token;
            }
        }

        // Fallback for custom gateway/proxy forwarding variable names.
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $upperKey = strtoupper($key);
            if (strpos($upperKey, 'AUTH') === false && strpos($upperKey, 'TOKEN') === false) {
                continue;
            }
            $token = $this->normalizeTokenCandidate($value);
            if ($token !== '') {
                return $token;
            }
        }
        return '';
    }

    private function getAuthUserFromPayload(array $payload = [])
    {
        $payload = $this->mergeRequestPayload($payload);
        $headers = function_exists('getallheaders') ? (array) getallheaders() : [];
        $token = $this->extractTokenFromHeaders($headers);
        if ($token === '') {
            $token = $this->extractTokenFromServer();
        }
        if ($token === '') {
            foreach (['token', 'auth', 'authorization', 'Auth', 'Authorization'] as $key) {
                if (!isset($payload[$key])) {
                    continue;
                }
                $token = $this->normalizeTokenCandidate($payload[$key]);
                if ($token !== '') {
                    break;
                }
            }
        }
        if ($token === '' && class_exists('Flight') && method_exists('Flight', 'request_token')) {
            try {
                $fallbackToken = Flight::request_token();
                if (is_string($fallbackToken) && trim($fallbackToken) !== '') {
                    $token = trim($fallbackToken);
                }
            } catch (\Throwable $e) {
                // ignore fallback errors
            }
        }
        if ($token === '') {
            return null;
        }

        $user = Flight::validateToken($token);
        if ($user) {
            return $user;
        }

        try {
            $sql = sprintf(
                'SELECT * FROM %s WHERE %s = :token AND %s = 1 LIMIT 1',
                $this->quoteTable('dcim-person'),
                $this->quoteField('token'),
                $this->quoteField('status')
            );
            $stmt = Flight::db()->prepare($sql);
            $stmt->bindValue(':token', $token);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function currentRequestPayload(): array
    {
        $data = Flight::request_data();
        return $this->mergeRequestPayload(is_array($data) ? $data : []);
    }

    private function requireLegacyAuth(array $requestData = [])
    {
        $user = $this->getAuthUserFromPayload($requestData);
        if (!$user) {
            L_E();
            return null;
        }
        return $user;
    }

    public function legacyEnsureAuth(array $requestData = [])
    {
        return $this->requireLegacyAuth($requestData);
    }

    private function cleanupLegacyMutationPayload(array $requestData): array
    {
        foreach (['token', 'auth', 'Auth', 'authorization', 'Authorization'] as $key) {
            if (array_key_exists($key, $requestData)) {
                unset($requestData[$key]);
            }
        }
        return $requestData;
    }

    private function legacySyslog(string $actionKey, array $requestData, array $payload = []): void
    {
        try {
            $uid = null;
            $user = $this->getAuthUserFromPayload($requestData);
            if ($user && isset($user['id'])) {
                $tmpUid = (int)$user['id'];
                if ($tmpUid > 0) {
                    $uid = $tmpUid;
                }
            }
            $msg = str_replace('{table}', $this->table, dcim_msg($actionKey));
            // Always attempt to write operation log. addLog() will fallback to token lookup or default user.
            self::syslog($msg, $payload, $uid);
        } catch (\Throwable $e) {
            error_log('[crud.legacySyslog] failed: ' . $e->getMessage());
        }
    }

    private function isDeviceTable(): bool
    {
        return strcasecmp((string)$this->tableName, 'dcim-device') === 0;
    }

    private function ensureDeviceBrokenAlarm($deviceId): void
    {
        if (!$this->isDeviceTable()) {
            return;
        }
        $devId = (int)$deviceId;
        if ($devId <= 0) {
            return;
        }

        try {
            $alarmCrud = new self('dcim-alarmnotifymode');
            $exists = $alarmCrud->findOne([
                ['DevId', '=', $devId],
                ['AlarmType', '=', 5],
                ['status', '=', 1],
            ]);
            if ($exists) {
                return;
            }

            $alarmCrud->insert([
                'AlarmType' => 5,
                'AlarmKey' => dcim_msg('app.alarm_disconnected'),
                'AlarmName' => dcim_msg('app.alarm_disconnected'),
                'DevId' => $devId,
                'AlarmLevel' => 0,
                'AlertPeriod' => '',
                'TogetherAlarm' => '',
                'status' => 1,
            ]);
        } catch (\Throwable $e) {
            error_log('[crud.device_broken_alarm] ensure failed: ' . $e->getMessage());
        }
    }

    public function legacyCreate(array $requestData, array $options = [])
    {
        $skipAuth = !empty($options['skip_auth']);
        if (!$skipAuth && !$this->requireLegacyAuth($requestData)) {
            return null;
        }

        $requestData = $this->cleanupLegacyMutationPayload($requestData);
        $dropFields = isset($options['drop_fields']) && is_array($options['drop_fields']) ? $options['drop_fields'] : [];
        foreach ($dropFields as $field) {
            if (is_string($field) && array_key_exists($field, $requestData)) {
                unset($requestData[$field]);
            }
        }
        $nullIfEmptyFields = isset($options['null_if_empty_fields']) && is_array($options['null_if_empty_fields'])
            ? $options['null_if_empty_fields']
            : [];
        foreach ($nullIfEmptyFields as $field) {
            if (!is_string($field) || !array_key_exists($field, $requestData)) {
                continue;
            }
            if ($requestData[$field] === '') {
                $requestData[$field] = null;
            }
        }
        $onlyFields = isset($options['only_fields']) && is_array($options['only_fields']) ? $options['only_fields'] : [];
        if ($onlyFields) {
            $allowMap = [];
            foreach ($onlyFields as $field) {
                if (is_string($field) && $field !== '') {
                    $allowMap[$field] = true;
                }
            }
            if ($allowMap) {
                $requestData = array_intersect_key($requestData, $allowMap);
            }
        }
        $defaults = isset($options['defaults']) && is_array($options['defaults']) ? $options['defaults'] : [];
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $requestData)) {
                $requestData[$key] = $value;
            }
        }

        $required = isset($options['required_fields']) && is_array($options['required_fields']) ? $options['required_fields'] : [];
        foreach ($required as $field => $message) {
            if (is_int($field)) {
                $field = (string) $message;
                $message = str_replace('{field}', $field, dcim_msg('error.field_required'));
            }
            if (!array_key_exists($field, $requestData) || (is_string($requestData[$field]) && trim($requestData[$field]) === '')) {
                P_E((string) $message);
                return null;
            }
        }

        $id = $this->insert($requestData);
        if ($id !== false && $id !== null) {
            $ensureDeviceBrokenAlarm = array_key_exists('ensure_device_broken_alarm', $options)
                ? (bool)$options['ensure_device_broken_alarm']
                : true;
            if ($ensureDeviceBrokenAlarm) {
                $this->ensureDeviceBrokenAlarm($id);
            }
            $this->legacySyslog('log.crud_create', $requestData, ['id' => $id] + $requestData);
        }
        return $id;
    }

    public function legacyInsert(array $requestData, array $options = [])
    {
        $options['skip_auth'] = array_key_exists('skip_auth', $options)
            ? (bool) $options['skip_auth']
            : true;
        return $this->legacyCreate($requestData, $options);
    }

    public function legacyList(array $requestData, array $options = []): ?array
    {
        $skipAuth = !empty($options['skip_auth']);
        if (!$skipAuth && !$this->requireLegacyAuth($requestData)) {
            return null;
        }

        $columnSet = [];
        $tableColumns = $this->getTableColumns($this->tableName);
        foreach ($tableColumns as $col) {
            $name = (string) $col;
            $columnSet[$name] = true;
            $columnSet[strtolower($name)] = true;
        }

        $conditions = [];
        $params = [];
        $baseWhere = isset($options['base_where']) && is_array($options['base_where']) ? $options['base_where'] : ['status = 1'];
        foreach ($baseWhere as $whereSql) {
            if (is_string($whereSql) && trim($whereSql) !== '') {
                if (!$this->isWhereSqlCompatibleWithColumns($whereSql, $columnSet)) {
                    continue;
                }
                $conditions[] = $whereSql;
            }
        }

        $exactFilters = isset($options['exact_filters']) && is_array($options['exact_filters']) ? $options['exact_filters'] : [];
        foreach ($exactFilters as $requestKey => $dbField) {
            if (!is_string($requestKey) || !is_string($dbField) || !$this->isSafeFieldName($dbField)) {
                continue;
            }
            if ($columnSet && !isset($columnSet[$dbField])) {
                continue;
            }
            if (!array_key_exists($requestKey, $requestData)) {
                continue;
            }
            $value = $requestData[$requestKey];
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }
            $ph = ':eq_' . $requestKey;
            $conditions[] = $this->quoteField($dbField) . ' = ' . $ph;
            $params[$ph] = $value;
        }

        $betweenFilters = isset($options['between_filters']) && is_array($options['between_filters']) ? $options['between_filters'] : [];
        $betweenIndex = 0;
        foreach ($betweenFilters as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $dbField = isset($filter['field']) && is_string($filter['field']) ? $filter['field'] : '';
            $startKey = isset($filter['start_key']) && is_string($filter['start_key']) ? $filter['start_key'] : '';
            $endKey = isset($filter['end_key']) && is_string($filter['end_key']) ? $filter['end_key'] : '';
            if ($dbField === '' || $startKey === '' || $endKey === '' || !$this->isSafeFieldName($dbField)) {
                continue;
            }
            if ($columnSet && !isset($columnSet[$dbField])) {
                continue;
            }
            if (!array_key_exists($startKey, $requestData) || !array_key_exists($endKey, $requestData)) {
                continue;
            }
            $startValue = $requestData[$startKey];
            $endValue = $requestData[$endKey];
            if ($startValue === null || $endValue === null) {
                continue;
            }
            if (is_string($startValue) && trim($startValue) === '') {
                continue;
            }
            if (is_string($endValue) && trim($endValue) === '') {
                continue;
            }
            $startPh = ':bt_start_' . $betweenIndex;
            $endPh = ':bt_end_' . $betweenIndex;
            $conditions[] = $this->quoteField($dbField) . ' BETWEEN ' . $startPh . ' AND ' . $endPh;
            $params[$startPh] = $startValue;
            $params[$endPh] = $endValue;
            $betweenIndex++;
        }

        $search = trim((string) ($requestData['key'] ?? ($requestData['search'] ?? '')));
        $searchFields = isset($options['search_fields']) && is_array($options['search_fields']) ? $options['search_fields'] : [];
        if ($search !== '' && $searchFields) {
            $orParts = [];
            foreach ($searchFields as $idx => $field) {
                if (!is_string($field) || !$this->isSafeFieldName($field)) {
                    continue;
                }
                if ($columnSet && !isset($columnSet[$field])) {
                    continue;
                }
                $ph = ':search_' . $idx;
                $orParts[] = $this->quoteField($field) . ' LIKE ' . $ph;
                $params[$ph] = '%' . $search . '%';
            }
            if ($orParts) {
                $conditions[] = '(' . implode(' OR ', $orParts) . ')';
            }
        }

        $where = $conditions ? implode(' AND ', $conditions) : '1=1';
        $page = max(1, (int) ($requestData['pageNo'] ?? ($requestData['page'] ?? 1)));
        $pageSize = (int) ($requestData['pageSize'] ?? ($requestData['limit'] ?? 15));
        if ($pageSize <= 0) {
            $pageSize = 15;
        }
        $orderBy = isset($options['order_by']) && is_string($options['order_by']) ? trim($options['order_by']) : '';
        return $this->selectWithPagination($where, $params, $orderBy, $page, $pageSize);
    }

    public function legacySelectByFilters(array $requestData, array $options = []): ?array
    {
        $skipAuth = !empty($options['skip_auth']);
        if (!$skipAuth && !$this->requireLegacyAuth($requestData)) {
            return null;
        }

        $columnSet = [];
        $tableColumns = $this->getTableColumns($this->tableName);
        foreach ($tableColumns as $col) {
            $name = (string) $col;
            $columnSet[$name] = true;
            $columnSet[strtolower($name)] = true;
        }

        $conditions = [];
        $params = [];
        $baseWhere = isset($options['base_where']) && is_array($options['base_where']) ? $options['base_where'] : ['status = 1'];
        foreach ($baseWhere as $whereSql) {
            if (is_string($whereSql) && trim($whereSql) !== '') {
                if (!$this->isWhereSqlCompatibleWithColumns($whereSql, $columnSet)) {
                    continue;
                }
                $conditions[] = $whereSql;
            }
        }

        $exactFilters = isset($options['exact_filters']) && is_array($options['exact_filters']) ? $options['exact_filters'] : [];
        foreach ($exactFilters as $requestKey => $dbField) {
            if (!is_string($requestKey) || !is_string($dbField) || !$this->isSafeFieldName($dbField)) {
                continue;
            }
            if ($columnSet && !isset($columnSet[$dbField])) {
                continue;
            }
            if (!array_key_exists($requestKey, $requestData)) {
                continue;
            }
            $value = $requestData[$requestKey];
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }
            $ph = ':eq_' . $requestKey;
            $conditions[] = $this->quoteField($dbField) . ' = ' . $ph;
            $params[$ph] = $value;
        }

        $search = trim((string)($requestData['search'] ?? ($requestData['key'] ?? '')));
        $searchFields = isset($options['search_fields']) && is_array($options['search_fields']) ? $options['search_fields'] : [];
        if ($search !== '' && $searchFields) {
            $orParts = [];
            foreach ($searchFields as $idx => $field) {
                if (!is_string($field) || !$this->isSafeFieldName($field)) {
                    continue;
                }
                if ($columnSet && !isset($columnSet[$field])) {
                    continue;
                }
                $ph = ':search_' . $idx;
                $orParts[] = $this->quoteField($field) . ' LIKE ' . $ph;
                $params[$ph] = '%' . $search . '%';
            }
            if ($orParts) {
                $conditions[] = '(' . implode(' OR ', $orParts) . ')';
            }
        }

        $extraWhere = isset($options['extra_where']) && is_array($options['extra_where']) ? $options['extra_where'] : [];
        foreach ($extraWhere as $whereSql) {
            if (is_string($whereSql) && trim($whereSql) !== '') {
                if (!$this->isWhereSqlCompatibleWithColumns($whereSql, $columnSet)) {
                    continue;
                }
                $conditions[] = $whereSql;
            }
        }

        $where = $conditions ? implode(' AND ', $conditions) : '1=1';
        $orderBy = isset($options['order_by']) && is_string($options['order_by']) ? trim($options['order_by']) : '';
        return $this->selectByRawCondition($where, $orderBy, $params);
    }

    public function legacyInfo(array $requestData, array $options = [])
    {
        $skipAuth = !empty($options['skip_auth']);
        if (!$skipAuth && !$this->requireLegacyAuth($requestData)) {
            return null;
        }

        $id = $requestData['id'] ?? 0;
        if (!$id) {
            return [];
        }
        $conditions = [['id', '=', $id]];
        $extraConditions = isset($options['extra_conditions']) && is_array($options['extra_conditions']) ? $options['extra_conditions'] : [['status', '=', 1]];
        foreach ($extraConditions as $condition) {
            if (is_array($condition) && count($condition) >= 2) {
                $conditions[] = $condition;
            }
        }
        $row = $this->findOne($conditions);
        return $row ?: [];
    }

    public function legacyUpdate(array $requestData, array $options = [])
    {
        $skipAuth = !empty($options['skip_auth']);
        if (!$skipAuth && !$this->requireLegacyAuth($requestData)) {
            return null;
        }

        if (empty($requestData['id'])) {
            $message = (string) ($options['id_required_message'] ?? dcim_msg('common.id_required'));
            P_E($message);
            return null;
        }

        $id = $requestData['id'];
        unset($requestData['id']);
        $requestData = $this->cleanupLegacyMutationPayload($requestData);
        $dropFields = isset($options['drop_fields']) && is_array($options['drop_fields']) ? $options['drop_fields'] : [];
        foreach ($dropFields as $field) {
            if (is_string($field) && array_key_exists($field, $requestData)) {
                unset($requestData[$field]);
            }
        }
        $nullIfEmptyFields = isset($options['null_if_empty_fields']) && is_array($options['null_if_empty_fields'])
            ? $options['null_if_empty_fields']
            : [];
        foreach ($nullIfEmptyFields as $field) {
            if (!is_string($field) || !array_key_exists($field, $requestData)) {
                continue;
            }
            if ($requestData[$field] === '') {
                $requestData[$field] = null;
            }
        }
        $onlyFields = isset($options['only_fields']) && is_array($options['only_fields']) ? $options['only_fields'] : [];
        if ($onlyFields) {
            $allowMap = [];
            foreach ($onlyFields as $field) {
                if (is_string($field) && $field !== '') {
                    $allowMap[$field] = true;
                }
            }
            if ($allowMap) {
                $requestData = array_intersect_key($requestData, $allowMap);
            }
        }
        $res = $this->updateById($id, $requestData);
        if ($res) {
            $this->legacySyslog('log.crud_update', $requestData, ['id' => $id] + $requestData);
        }
        return $res;
    }

    public function legacySoftDelete(array $requestData, array $options = [])
    {
        $skipAuth = !empty($options['skip_auth']);
        if (!$skipAuth && !$this->requireLegacyAuth($requestData)) {
            return null;
        }

        if (empty($requestData['id'])) {
            $message = (string) ($options['id_required_message'] ?? dcim_msg('common.id_required'));
            P_E($message);
            return null;
        }

        $id = $requestData['id'];
        $deleteField = isset($options['delete_field']) && is_string($options['delete_field']) && $options['delete_field'] !== ''
            ? $options['delete_field']
            : 'status';
        $deleteValue = $options['delete_value'] ?? -1;
        $res = $this->updateById($id, [$deleteField => $deleteValue]);
        if ($res) {
            $this->legacySyslog('log.crud_delete', $requestData, ['id' => $id, $deleteField => $deleteValue]);
        }
        return $res;
    }

    public function legacyUpdateWhere(array $whereConditions, array $updateData, array $options = [])
    {
        $skipAuth = array_key_exists('skip_auth', $options) ? (bool) $options['skip_auth'] : true;
        $requestData = isset($options['request_data']) && is_array($options['request_data'])
            ? $options['request_data']
            : [];
        if (!$skipAuth && !$this->requireLegacyAuth($requestData)) {
            return null;
        }

        $keepAuthFields = !empty($options['keep_auth_fields']);
        if (!$keepAuthFields) {
            $updateData = $this->cleanupLegacyMutationPayload($updateData);
        }
        $dropFields = isset($options['drop_fields']) && is_array($options['drop_fields']) ? $options['drop_fields'] : [];
        foreach ($dropFields as $field) {
            if (is_string($field) && array_key_exists($field, $updateData)) {
                unset($updateData[$field]);
            }
        }
        $nullIfEmptyFields = isset($options['null_if_empty_fields']) && is_array($options['null_if_empty_fields'])
            ? $options['null_if_empty_fields']
            : [];
        foreach ($nullIfEmptyFields as $field) {
            if (!is_string($field) || !array_key_exists($field, $updateData)) {
                continue;
            }
            if ($updateData[$field] === '') {
                $updateData[$field] = null;
            }
        }
        $onlyFields = isset($options['only_fields']) && is_array($options['only_fields']) ? $options['only_fields'] : [];
        if ($onlyFields) {
            $allowMap = [];
            foreach ($onlyFields as $field) {
                if (is_string($field) && $field !== '') {
                    $allowMap[$field] = true;
                }
            }
            if ($allowMap) {
                $updateData = array_intersect_key($updateData, $allowMap);
            }
        }

        $res = $this->updateWhere($whereConditions, $updateData);
        if ($res) {
            $this->legacySyslog('log.crud_update', $requestData, $updateData);
        }
        return $res;
    }

    public function legacyDeleteByRawCondition(string $conditionSql, array $params = [], array $options = []): int
    {
        $skipAuth = array_key_exists('skip_auth', $options) ? (bool) $options['skip_auth'] : true;
        $requestData = isset($options['request_data']) && is_array($options['request_data'])
            ? $options['request_data']
            : [];
        if (!$skipAuth && !$this->requireLegacyAuth($requestData)) {
            return 0;
        }
        return $this->deleteByRawCondition($conditionSql, $params);
    }

    public function legacyAdjustStockAndRecord(array $requestData, array $options = [])
    {
        $skipAuth = !empty($options['skip_auth']);
        if (!$skipAuth && !$this->requireLegacyAuth($requestData)) {
            return null;
        }

        $stockTable = isset($options['stock_table']) ? trim((string)$options['stock_table']) : '';
        if ($stockTable === '') {
            throw new InvalidArgumentException('stock_table required');
        }
        $recordTable = isset($options['record_table']) && trim((string)$options['record_table']) !== ''
            ? trim((string)$options['record_table'])
            : $this->table;

        $idParam = isset($options['id_param']) && is_string($options['id_param']) && $options['id_param'] !== ''
            ? $options['id_param']
            : 'id';
        $stockId = $requestData[$idParam] ?? null;
        if ($stockId === null || $stockId === '') {
            P_E((string)($options['id_required_message'] ?? dcim_msg('common.id_required')));
            return null;
        }

        $numberParam = isset($options['number_param']) && is_string($options['number_param']) && $options['number_param'] !== ''
            ? $options['number_param']
            : 'number';
        $numberRaw = $requestData[$numberParam] ?? 0;
        $number = is_numeric($numberRaw) ? (float)$numberRaw : 0.0;
        if ($number < 0) {
            $number = 0.0;
        }

        $statusField = isset($options['status_field']) && is_string($options['status_field']) && $options['status_field'] !== ''
            ? $options['status_field']
            : 'status';
        $activeValue = $options['active_value'] ?? 1;
        $stockCrud = new self($stockTable);
        $stock = $stockCrud->findOne([
            ['id', '=', $stockId],
            [$statusField, '=', $activeValue],
        ]);
        if (!$stock) {
            P_E((string)($options['not_found_message'] ?? dcim_msg('error.record_not_found')));
            return null;
        }

        $availableField = isset($options['available_field']) ? trim((string)$options['available_field']) : '';
        if ($availableField === '') {
            throw new InvalidArgumentException('available_field required');
        }

        $mode = strtolower(trim((string)($options['mode'] ?? 'out')));
        if ($mode !== 'in' && $mode !== 'out' && $mode !== 'return') {
            $mode = 'out';
        }

        $currentAvailable = is_numeric($stock[$availableField] ?? null) ? (float)$stock[$availableField] : 0.0;
        if ($mode === 'out') {
            $newAvailable = $currentAvailable - $number;
            if ($newAvailable < 0) {
                P_E((string)($options['insufficient_message'] ?? dcim_msg('error.insufficient_stock')));
                return null;
            }
        } else {
            $newAvailable = $currentAvailable + $number;
        }

        $updateData = [$availableField => $newAvailable];
        $totalField = isset($options['total_field']) ? trim((string)$options['total_field']) : '';
        if ($mode === 'in' && $totalField !== '') {
            $currentTotal = is_numeric($stock[$totalField] ?? null) ? (float)$stock[$totalField] : 0.0;
            $updateData[$totalField] = $currentTotal + $number;
        }

        $recordFkField = isset($options['record_fk_field']) && is_string($options['record_fk_field']) && $options['record_fk_field'] !== ''
            ? $options['record_fk_field']
            : 'id';
        $recordNumberField = isset($options['record_number_field']) && is_string($options['record_number_field']) && $options['record_number_field'] !== ''
            ? $options['record_number_field']
            : 'number';
        $recordStatusField = isset($options['record_status_field']) && is_string($options['record_status_field']) && $options['record_status_field'] !== ''
            ? $options['record_status_field']
            : 'status';
        $recordStatusValue = $options['record_status_value'] ?? 1;

        $recordData = [
            $recordFkField => $stockId,
            $recordNumberField => $number,
            $recordStatusField => $recordStatusValue,
        ];

        $recordTypeField = isset($options['record_type_field']) ? (string)$options['record_type_field'] : 'type';
        if ($recordTypeField !== '') {
            $typeMap = isset($options['record_type_map']) && is_array($options['record_type_map']) ? $options['record_type_map'] : [];
            if (isset($typeMap[$mode])) {
                $recordData[$recordTypeField] = $typeMap[$mode];
            } elseif (isset($options['record_type_value'])) {
                $recordData[$recordTypeField] = $options['record_type_value'];
            }
        }

        $extraFieldMap = isset($options['record_extra_fields']) && is_array($options['record_extra_fields']) ? $options['record_extra_fields'] : [];
        foreach ($extraFieldMap as $requestKey => $recordKey) {
            if (!is_string($recordKey) || $recordKey === '') {
                continue;
            }
            if (is_string($requestKey) && array_key_exists($requestKey, $requestData)) {
                $recordData[$recordKey] = $requestData[$requestKey];
            }
        }

        $constantFields = isset($options['record_constant_fields']) && is_array($options['record_constant_fields']) ? $options['record_constant_fields'] : [];
        foreach ($constantFields as $field => $value) {
            if (!is_string($field) || $field === '') {
                continue;
            }
            $recordData[$field] = $value;
        }

        $recordCrud = new self($recordTable);
        $recordId = $recordCrud->insert($recordData);
        if ($recordId === false) {
            P_E((string)($options['record_insert_failed_message'] ?? dcim_msg('error.save_record_failed')));
            return null;
        }

        $updated = $stockCrud->updateById($stockId, $updateData);
        if (!$updated) {
            P_E((string)($options['stock_update_failed_message'] ?? dcim_msg('error.update_stock_failed')));
            return null;
        }

        return [
            'stock_id' => $stockId,
            'record_id' => $recordId,
            'number' => $number,
            'mode' => $mode,
            'available_before' => $currentAvailable,
            'available_after' => $newAvailable,
        ];
    }

    public function legacyUpdateAssetStatusWithRecord(array $requestData, array $options = [])
    {
        $skipAuth = !empty($options['skip_auth']);
        if (!$skipAuth && !$this->requireLegacyAuth($requestData)) {
            return null;
        }

        $assetTable = isset($options['asset_table']) && trim((string)$options['asset_table']) !== ''
            ? trim((string)$options['asset_table'])
            : 'dcim-asset';
        $recordTable = isset($options['record_table']) && trim((string)$options['record_table']) !== ''
            ? trim((string)$options['record_table'])
            : $this->table;
        if ($recordTable === '') {
            throw new InvalidArgumentException('record_table required');
        }

        $idParam = isset($options['id_param']) && is_string($options['id_param']) && $options['id_param'] !== ''
            ? $options['id_param']
            : 'id';
        $idRaw = $requestData[$idParam] ?? null;
        if ($idRaw === null || $idRaw === '') {
            P_E((string)($options['id_required_message'] ?? dcim_msg('common.id_required')));
            return null;
        }

        $allowCsvIds = array_key_exists('allow_csv_ids', $options) ? (bool)$options['allow_csv_ids'] : true;
        $idItems = [$idRaw];
        if ($allowCsvIds && is_string($idRaw) && strpos($idRaw, ',') !== false) {
            $idItems = explode(',', $idRaw);
        }
        $assetIds = [];
        foreach ($idItems as $item) {
            $assetId = trim((string)$item);
            if ($assetId !== '') {
                $assetIds[] = $assetId;
            }
        }
        if (!$assetIds) {
            P_E((string)($options['id_required_message'] ?? dcim_msg('common.id_required')));
            return null;
        }

        $statusValue = $options['status_value'] ?? null;
        if ($statusValue === null) {
            $statusParam = isset($options['status_param']) && is_string($options['status_param']) && $options['status_param'] !== ''
                ? $options['status_param']
                : 'status';
            $statusValue = $requestData[$statusParam] ?? null;
        }
        if ($statusValue === null || $statusValue === '') {
            P_E((string)($options['status_required_message'] ?? dcim_msg('error.status_required')));
            return null;
        }

        $assetStatusField = isset($options['asset_status_field']) && is_string($options['asset_status_field']) && $options['asset_status_field'] !== ''
            ? $options['asset_status_field']
            : 'AssetStatus';
        $assetStatusFilterField = isset($options['asset_status_filter_field']) && is_string($options['asset_status_filter_field']) && $options['asset_status_filter_field'] !== ''
            ? $options['asset_status_filter_field']
            : 'status';
        $assetStatusFilterValue = $options['asset_status_filter_value'] ?? 1;
        $enforceAssetExists = array_key_exists('enforce_asset_exists', $options) ? (bool)$options['enforce_asset_exists'] : true;
        $skipMissingAssets = !empty($options['skip_missing_assets']);

        $recordFkField = isset($options['record_fk_field']) && is_string($options['record_fk_field']) && $options['record_fk_field'] !== ''
            ? $options['record_fk_field']
            : 'AssetsId';
        $recordStatusField = isset($options['record_status_field']) && is_string($options['record_status_field']) && $options['record_status_field'] !== ''
            ? $options['record_status_field']
            : 'status';
        $recordStatusValue = $options['record_status_value'] ?? 1;
        $recordModeField = isset($options['record_mode_field']) && is_string($options['record_mode_field'])
            ? trim($options['record_mode_field'])
            : '';

        $extraFieldMap = isset($options['record_extra_fields']) && is_array($options['record_extra_fields']) ? $options['record_extra_fields'] : [];
        $constantFields = isset($options['record_constant_fields']) && is_array($options['record_constant_fields']) ? $options['record_constant_fields'] : [];
        $defaultFields = isset($options['record_default_fields']) && is_array($options['record_default_fields']) ? $options['record_default_fields'] : [];
        $nullIfEmptyFields = isset($options['record_null_if_empty_fields']) && is_array($options['record_null_if_empty_fields']) ? $options['record_null_if_empty_fields'] : [];

        $assetCrud = new self($assetTable);
        $recordCrud = new self($recordTable);
        $updatedCount = 0;
        $recordCount = 0;

        foreach ($assetIds as $assetId) {
            if ($enforceAssetExists) {
                $assetInfo = $assetCrud->findOne([
                    ['id', '=', $assetId],
                    [$assetStatusFilterField, '=', $assetStatusFilterValue],
                ]);
                if (!$assetInfo) {
                    if ($skipMissingAssets) {
                        continue;
                    }
                    P_E((string)($options['not_found_message'] ?? dcim_msg('error.asset_not_found')));
                    return null;
                }
            }

            $updated = $assetCrud->updateById($assetId, [$assetStatusField => $statusValue]);
            if (!$updated) {
                P_E((string)($options['asset_update_failed_message'] ?? dcim_msg('error.update_asset_status_failed')));
                return null;
            }
            $updatedCount++;

            $recordData = [
                $recordFkField => $assetId,
                $recordStatusField => $recordStatusValue,
            ];
            if ($recordModeField !== '') {
                $recordData[$recordModeField] = $statusValue;
            }

            foreach ($extraFieldMap as $requestKey => $recordKey) {
                if (!is_string($recordKey) || $recordKey === '') {
                    continue;
                }
                if (is_string($requestKey) && array_key_exists($requestKey, $requestData)) {
                    $recordData[$recordKey] = $requestData[$requestKey];
                }
            }

            foreach ($constantFields as $field => $value) {
                if (!is_string($field) || $field === '') {
                    continue;
                }
                $recordData[$field] = $value;
            }

            foreach ($defaultFields as $field => $value) {
                if (!is_string($field) || $field === '') {
                    continue;
                }
                if (!array_key_exists($field, $recordData) || $recordData[$field] === '' || $recordData[$field] === null) {
                    $recordData[$field] = $value;
                }
            }

            foreach ($nullIfEmptyFields as $field) {
                if (!is_string($field) || $field === '') {
                    continue;
                }
                if (array_key_exists($field, $recordData) && $recordData[$field] === '') {
                    $recordData[$field] = null;
                }
            }

            $recordId = $recordCrud->insert($recordData);
            if ($recordId === false || $recordId === null) {
                P_E((string)($options['record_insert_failed_message'] ?? dcim_msg('error.save_record_failed')));
                return null;
            }
            $recordCount++;
        }

        return [
            'asset_ids' => $assetIds,
            'status_value' => $statusValue,
            'updated_count' => $updatedCount,
            'record_count' => $recordCount,
        ];
    }

    public function legacyReplaceAttrMappings(array $requestData, array $options = [])
    {
        $skipAuth = !empty($options['skip_auth']);
        if (!$skipAuth && !$this->requireLegacyAuth($requestData)) {
            return null;
        }

        $ownerField = isset($options['owner_field']) && is_string($options['owner_field']) && $options['owner_field'] !== ''
            ? $options['owner_field']
            : '';
        if ($ownerField === '') {
            throw new InvalidArgumentException('owner_field required');
        }
        $ownerParam = isset($options['owner_param']) && is_string($options['owner_param']) && $options['owner_param'] !== ''
            ? $options['owner_param']
            : $ownerField;
        $ownerValue = $requestData[$ownerParam] ?? null;
        if ($ownerValue === null || $ownerValue === '') {
            P_E((string)($options['owner_required_message'] ?? str_replace('{field}', (string)$ownerField, dcim_msg('error.field_required'))));
            return null;
        }

        $attrKey = isset($options['attr_key']) && is_string($options['attr_key']) && $options['attr_key'] !== ''
            ? $options['attr_key']
            : 'Attr';
        $attrs = $this->normalizeLegacyAttrInput($requestData, $attrKey);
        if (!$attrs) {
            P_E((string)($options['attr_required_message'] ?? dcim_msg('error.attr_required')));
            return null;
        }

        $statusField = isset($options['status_field']) && is_string($options['status_field']) && $options['status_field'] !== ''
            ? $options['status_field']
            : 'status';
        $activeStatus = $options['active_status'] ?? 1;
        $deletedStatus = $options['deleted_status'] ?? -1;

        $attributeIdInputKey = isset($options['attribute_id_input_key']) && is_string($options['attribute_id_input_key']) && $options['attribute_id_input_key'] !== ''
            ? $options['attribute_id_input_key']
            : 'AttributeId';
        $attributeIdField = isset($options['attribute_id_field']) && is_string($options['attribute_id_field']) && $options['attribute_id_field'] !== ''
            ? $options['attribute_id_field']
            : 'AttributeId';
        $attributeValInputKey = isset($options['attribute_val_input_key']) && is_string($options['attribute_val_input_key']) && $options['attribute_val_input_key'] !== ''
            ? $options['attribute_val_input_key']
            : 'AttributeVal';
        $attributeValField = isset($options['attribute_val_field']) && is_string($options['attribute_val_field']) && $options['attribute_val_field'] !== ''
            ? $options['attribute_val_field']
            : 'AttributeVal';
        $extraFieldMap = isset($options['extra_field_map']) && is_array($options['extra_field_map']) ? $options['extra_field_map'] : [];
        $constantFields = isset($options['constant_fields']) && is_array($options['constant_fields']) ? $options['constant_fields'] : [];
        $defaultFields = isset($options['default_fields']) && is_array($options['default_fields']) ? $options['default_fields'] : [];

        $this->updateWhere(
            [
                [$ownerField, '=', $ownerValue],
                [$statusField, '=', $activeStatus],
            ],
            [$statusField => $deletedStatus]
        );

        $inserted = 0;
        foreach ($attrs as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $attributeId = $this->legacyArrayGetIgnoreCase($attr, $attributeIdInputKey, null);
            if ($attributeId === null || $attributeId === '') {
                continue;
            }

            $row = [
                $ownerField => $ownerValue,
                $attributeIdField => $attributeId,
                $attributeValField => $this->legacyArrayGetIgnoreCase($attr, $attributeValInputKey, ''),
                $statusField => $activeStatus,
            ];
            foreach ($extraFieldMap as $inputKey => $field) {
                if (!is_string($field) || $field === '') {
                    continue;
                }
                $value = $this->legacyArrayGetIgnoreCase($attr, (string)$inputKey, null);
                if ($value !== null) {
                    $row[$field] = $value;
                }
            }
            foreach ($constantFields as $field => $value) {
                if (!is_string($field) || $field === '') {
                    continue;
                }
                $row[$field] = $value;
            }
            foreach ($defaultFields as $field => $value) {
                if (!is_string($field) || $field === '') {
                    continue;
                }
                if (!array_key_exists($field, $row) || $row[$field] === '' || $row[$field] === null) {
                    $row[$field] = $value;
                }
            }

            $id = $this->insert($row);
            if ($id) {
                $inserted++;
            }
        }

        if ($inserted <= 0) {
            P_E((string)($options['valid_attr_required_message'] ?? dcim_msg('error.valid_attr_required')));
            return null;
        }

        return [
            'owner_field' => $ownerField,
            'owner_value' => $ownerValue,
            'inserted' => $inserted,
        ];
    }

    public function legacySyncAssetAndPutout(array $requestData, array $options = [])
    {
        $assetTable = isset($options['asset_table']) && trim((string)$options['asset_table']) !== ''
            ? trim((string)$options['asset_table'])
            : 'dcim-asset';
        $putoutTable = isset($options['putout_table']) && trim((string)$options['putout_table']) !== ''
            ? trim((string)$options['putout_table'])
            : 'dcim-assetputout';

        $assetIdParam = isset($options['asset_id_param']) && is_string($options['asset_id_param']) && $options['asset_id_param'] !== ''
            ? $options['asset_id_param']
            : 'AssetsId';
        $putoutIdParam = isset($options['putout_id_param']) && is_string($options['putout_id_param']) && $options['putout_id_param'] !== ''
            ? $options['putout_id_param']
            : 'PutoutId';

        $assetId = $requestData[$assetIdParam] ?? null;
        $putoutId = $requestData[$putoutIdParam] ?? null;

        $assetUpdateMap = isset($options['asset_update_field_map']) && is_array($options['asset_update_field_map'])
            ? $options['asset_update_field_map']
            : [];
        $assetStatusField = isset($options['asset_status_field']) && is_string($options['asset_status_field']) && $options['asset_status_field'] !== ''
            ? $options['asset_status_field']
            : 'AssetStatus';
        $assetStatusValue = array_key_exists('asset_status_value', $options) ? $options['asset_status_value'] : null;
        $statusRequiresFieldChanges = !empty($options['status_requires_field_changes']);

        $assetData = [];
        $hasMappedFieldChanges = false;
        foreach ($assetUpdateMap as $requestField => $assetField) {
            if (!is_string($requestField) || !is_string($assetField) || $assetField === '') {
                continue;
            }
            if (!array_key_exists($requestField, $requestData)) {
                continue;
            }
            if ($requestData[$requestField] === null) {
                continue;
            }
            $assetData[$assetField] = $requestData[$requestField];
            $hasMappedFieldChanges = true;
        }

        if ($assetStatusValue !== null) {
            if (!$statusRequiresFieldChanges || $hasMappedFieldChanges) {
                $assetData[$assetStatusField] = $assetStatusValue;
            }
        }

        $assetUpdated = false;
        if ($assetId !== null && $assetId !== '' && $assetData) {
            $assetCrud = new self($assetTable);
            $assetUpdated = (bool)$assetCrud->updateById($assetId, $assetData);
        }

        $putoutUpdated = false;
        if ($putoutId !== null && $putoutId !== '') {
            $putoutStatusField = isset($options['putout_status_field']) && is_string($options['putout_status_field']) && $options['putout_status_field'] !== ''
                ? $options['putout_status_field']
                : 'PutoutStatus';
            $putoutStatusValue = array_key_exists('putout_status_value', $options) ? $options['putout_status_value'] : 'true';
            $putoutCrud = new self($putoutTable);
            $putoutUpdated = (bool)$putoutCrud->updateById($putoutId, [$putoutStatusField => $putoutStatusValue]);
        }

        return [
            'asset_id' => $assetId,
            'asset_updated' => $assetUpdated,
            'putout_id' => $putoutId,
            'putout_updated' => $putoutUpdated,
        ];
    }

    public function legacyUpdatePutoutStatusByAsset(array $requestData, array $options = []): bool
    {
        $putoutTable = isset($options['putout_table']) && trim((string)$options['putout_table']) !== ''
            ? trim((string)$options['putout_table'])
            : 'dcim-assetputout';
        $assetIdParam = isset($options['asset_id_param']) && is_string($options['asset_id_param']) && $options['asset_id_param'] !== ''
            ? $options['asset_id_param']
            : 'AssetsId';
        $assetId = $requestData[$assetIdParam] ?? null;
        if ($assetId === null || $assetId === '') {
            return false;
        }

        $statusField = isset($options['putout_status_field']) && is_string($options['putout_status_field']) && $options['putout_status_field'] !== ''
            ? $options['putout_status_field']
            : 'PutoutStatus';
        $fromStatus = array_key_exists('from_status', $options) ? $options['from_status'] : null;
        $toStatus = array_key_exists('to_status', $options) ? $options['to_status'] : 'false';

        $where = [
            ['AssetsId', '=', $assetId],
        ];
        if ($fromStatus !== null && $fromStatus !== '') {
            $where[] = [$statusField, '=', $fromStatus];
        }

        $extraWhere = isset($options['extra_where']) && is_array($options['extra_where']) ? $options['extra_where'] : [];
        foreach ($extraWhere as $cond) {
            if (is_array($cond) && count($cond) >= 3) {
                $where[] = $cond;
            }
        }

        $putoutCrud = new self($putoutTable);
        $res = $putoutCrud->updateWhere($where, [$statusField => $toStatus]);
        return (bool)$res;
    }

    public function legacyFilterAssetIds(array $requestData, array $options = []): ?array
    {
        $assetTable = isset($options['asset_table']) && trim((string)$options['asset_table']) !== ''
            ? trim((string)$options['asset_table'])
            : 'dcim-asset';
        $modelTable = isset($options['model_table']) && trim((string)$options['model_table']) !== ''
            ? trim((string)$options['model_table'])
            : 'dcim-brandmodel';

        $assetStatusField = isset($options['asset_status_field']) && is_string($options['asset_status_field']) && $options['asset_status_field'] !== ''
            ? $options['asset_status_field']
            : 'AssetStatus';
        $assetStatusParam = isset($options['asset_status_param']) && is_string($options['asset_status_param']) && $options['asset_status_param'] !== ''
            ? $options['asset_status_param']
            : 'AssetStatus';

        $assetsTypeParam = isset($options['assets_type_param']) && is_string($options['assets_type_param']) && $options['assets_type_param'] !== ''
            ? $options['assets_type_param']
            : 'AssetsTypeId';
        $assetModelField = isset($options['asset_model_field']) && is_string($options['asset_model_field']) && $options['asset_model_field'] !== ''
            ? $options['asset_model_field']
            : 'ModelId';
        $modelTypeField = isset($options['model_type_field']) && is_string($options['model_type_field']) && $options['model_type_field'] !== ''
            ? $options['model_type_field']
            : 'AssetsTypeId';

        $searchKey = isset($options['search_key']) && is_string($options['search_key']) && $options['search_key'] !== ''
            ? $options['search_key']
            : 'search';
        $searchFields = isset($options['search_fields']) && is_array($options['search_fields']) && $options['search_fields']
            ? $options['search_fields']
            : ['AssetsNumber', 'AssetsDescribe'];
        $requireFilter = array_key_exists('require_filter', $options) ? (bool)$options['require_filter'] : true;

        $conditions = ['status = 1'];
        $params = [];
        $needFilter = false;

        $search = isset($requestData[$searchKey]) ? trim((string)$requestData[$searchKey]) : '';
        if ($search !== '') {
            $searchParts = [];
            foreach ($searchFields as $idx => $field) {
                if (!is_string($field) || !$this->isSafeFieldName($field)) {
                    continue;
                }
                $ph = ':asset_search_' . $idx;
                $searchParts[] = $this->quoteField($field) . ' LIKE ' . $ph;
                $params[$ph] = '%' . $search . '%';
            }
            if ($searchParts) {
                $needFilter = true;
                $conditions[] = '(' . implode(' OR ', $searchParts) . ')';
            }
        }

        if (array_key_exists($assetStatusParam, $requestData)) {
            $assetStatusValue = $requestData[$assetStatusParam];
            if ($assetStatusValue !== null && (!is_string($assetStatusValue) || trim($assetStatusValue) !== '')) {
                $needFilter = true;
                if ($this->isSafeFieldName($assetStatusField)) {
                    $conditions[] = $this->quoteField($assetStatusField) . ' = :asset_status_value';
                    $params[':asset_status_value'] = $assetStatusValue;
                }
            }
        }

        if (array_key_exists($assetsTypeParam, $requestData)) {
            $assetsTypeValue = $requestData[$assetsTypeParam];
            if ($assetsTypeValue !== null && (!is_string($assetsTypeValue) || trim($assetsTypeValue) !== '')) {
                $needFilter = true;
                if ($this->isSafeFieldName($modelTypeField) && $this->isSafeFieldName($assetModelField)) {
                    $modelCrud = new self($modelTable);
                    $modelRows = $modelCrud->selectByRawCondition(
                        'status = 1 AND ' . $this->quoteField($modelTypeField) . ' = :assets_type_id',
                        '',
                        [':assets_type_id' => $assetsTypeValue]
                    );
                    $modelIds = $this->legacyExtractDistinctIds($modelRows, 'id');
                    if (!$modelIds) {
                        return [];
                    }
                    $phs = [];
                    foreach ($modelIds as $idx => $mid) {
                        $ph = ':asset_model_' . $idx;
                        $phs[] = $ph;
                        $params[$ph] = $mid;
                    }
                    $conditions[] = $this->quoteField($assetModelField) . ' IN (' . implode(',', $phs) . ')';
                }
            }
        }

        if ($requireFilter && !$needFilter) {
            return null;
        }

        $assetCrud = new self($assetTable);
        $rows = $assetCrud->selectByRawCondition(implode(' AND ', $conditions), '', $params);
        return $this->legacyExtractDistinctIds($rows, 'id');
    }

    public function legacyEnrichAssetPutoutRows(array $rows, array $options = []): array
    {
        if (!$rows) {
            return $rows;
        }

        $assetIdField = isset($options['asset_id_field']) && is_string($options['asset_id_field']) && $options['asset_id_field'] !== ''
            ? $options['asset_id_field']
            : 'AssetsId';
        $areaIdField = isset($options['area_id_field']) && is_string($options['area_id_field']) && $options['area_id_field'] !== ''
            ? $options['area_id_field']
            : 'AreaId';
        $serverIdField = isset($options['server_id_field']) && is_string($options['server_id_field']) && $options['server_id_field'] !== ''
            ? $options['server_id_field']
            : 'ServerCode';
        $assetActiveStatus = array_key_exists('asset_active_status', $options) ? $options['asset_active_status'] : 1;
        $includeUAttr = array_key_exists('include_u_attr', $options) ? (bool)$options['include_u_attr'] : true;

        $assetIds = $this->legacyExtractDistinctIds($rows, $assetIdField);
        $areaIds = $this->legacyExtractDistinctIds($rows, $areaIdField);
        $serverIds = $this->legacyExtractDistinctIds($rows, $serverIdField);

        $assetMap = [];
        if ($assetIds) {
            $assetRows = (new self('dcim-asset'))->selectByIds(
                $assetIds,
                ['id', 'AssetsNumber', 'AssetsDescribe', 'AssetStatus', 'EmpId', 'StoreLocationId', 'ModelId', 'UId', 'GatewayId', 'status']
            );
            foreach ($assetRows as $item) {
                if ((string)($item['status'] ?? '') !== (string)$assetActiveStatus) {
                    continue;
                }
                $id = isset($item['id']) ? (string)$item['id'] : '';
                if ($id === '') {
                    continue;
                }
                $assetMap[$id] = $item;
            }
        }

        $modelIds = [];
        $empIds = [];
        foreach ($assetMap as $asset) {
            $modelId = isset($asset['ModelId']) ? trim((string)$asset['ModelId']) : '';
            if ($modelId !== '') {
                $modelIds[$modelId] = true;
            }
            $empId = isset($asset['EmpId']) ? trim((string)$asset['EmpId']) : '';
            if ($empId !== '') {
                $empIds[$empId] = true;
            }
        }
        $modelIds = array_keys($modelIds);
        $empIds = array_keys($empIds);

        $brandMap = [];
        if ($modelIds) {
            $brandRows = (new self('dcim-brandmodel'))->selectByIds($modelIds, ['id', 'BrandModel', 'AssetsTypeId']);
            foreach ($brandRows as $item) {
                $id = isset($item['id']) ? (string)$item['id'] : '';
                if ($id !== '') {
                    $brandMap[$id] = $item;
                }
            }
        }

        $typeIds = [];
        foreach ($brandMap as $brand) {
            $typeId = isset($brand['AssetsTypeId']) ? trim((string)$brand['AssetsTypeId']) : '';
            if ($typeId !== '') {
                $typeIds[$typeId] = true;
            }
        }
        $typeIds = array_keys($typeIds);

        $typeMap = [];
        if ($typeIds) {
            $typeRows = (new self('dcim-assettype'))->selectByIds($typeIds, ['id', 'AssetsTypeName']);
            foreach ($typeRows as $item) {
                $id = isset($item['id']) ? (string)$item['id'] : '';
                if ($id !== '') {
                    $typeMap[$id] = $item;
                }
            }
        }

        $personMap = [];
        if ($empIds) {
            $personRows = (new self('dcim-person'))->selectByIds($empIds, ['id', 'PersonName', 'DeptId']);
            foreach ($personRows as $item) {
                $id = isset($item['id']) ? (string)$item['id'] : '';
                if ($id !== '') {
                    $personMap[$id] = $item;
                }
            }
        }

        $deptIds = [];
        foreach ($personMap as $person) {
            $deptId = isset($person['DeptId']) ? trim((string)$person['DeptId']) : '';
            if ($deptId !== '') {
                $deptIds[$deptId] = true;
            }
        }
        $deptIds = array_keys($deptIds);

        $deptMap = [];
        if ($deptIds) {
            $deptRows = (new self('dcim-department'))->selectByIds($deptIds, ['id', 'DeptName']);
            foreach ($deptRows as $item) {
                $id = isset($item['id']) ? (string)$item['id'] : '';
                if ($id !== '') {
                    $deptMap[$id] = $item;
                }
            }
        }

        $areaMap = [];
        if ($areaIds) {
            $areaRows = (new self('dcim-area'))->selectByIds($areaIds, ['id', 'AreaName']);
            foreach ($areaRows as $item) {
                $id = isset($item['id']) ? (string)$item['id'] : '';
                if ($id !== '') {
                    $areaMap[$id] = $item;
                }
            }
        }

        $serverMap = [];
        if ($serverIds) {
            $serverRows = (new self('dcim-server'))->selectByIds($serverIds, ['id', 'ServerName']);
            foreach ($serverRows as $item) {
                $id = isset($item['id']) ? (string)$item['id'] : '';
                if ($id !== '') {
                    $serverMap[$id] = $item;
                }
            }
        }

        $uAttrByModel = [];
        $uAttrByType = [];
        if ($includeUAttr) {
            $modelUAttrRows = [];
            if ($modelIds) {
                $params = [':status' => 1];
                $phs = [];
                foreach ($modelIds as $idx => $mid) {
                    $ph = ':mid_' . $idx;
                    $phs[] = $ph;
                    $params[$ph] = $mid;
                }
                $modelUAttrRows = (new self('dcim-brandmodelattr'))->selectByRawCondition(
                    'status = :status AND ' . $this->quoteField('ModelId') . ' IN (' . implode(',', $phs) . ')',
                    '',
                    $params
                );
            }

            $typeUAttrRows = [];
            if ($typeIds) {
                $params = [':status' => 1];
                $phs = [];
                foreach ($typeIds as $idx => $tid) {
                    $ph = ':tid_' . $idx;
                    $phs[] = $ph;
                    $params[$ph] = $tid;
                }
                $typeUAttrRows = (new self('dcim-assettypeattr'))->selectByRawCondition(
                    'status = :status AND ' . $this->quoteField('AssetsTypeId') . ' IN (' . implode(',', $phs) . ')',
                    '',
                    $params
                );
            }

            $attrIds = [];
            foreach ($modelUAttrRows as $item) {
                $aid = isset($item['AttributeId']) ? trim((string)$item['AttributeId']) : '';
                if ($aid !== '') {
                    $attrIds[$aid] = true;
                }
            }
            foreach ($typeUAttrRows as $item) {
                $aid = isset($item['AttributeId']) ? trim((string)$item['AttributeId']) : '';
                if ($aid !== '') {
                    $attrIds[$aid] = true;
                }
            }

            $uAttrNameById = [];
            if ($attrIds) {
                $attrRows = (new self('dcim-assetattr'))->selectByIds(array_keys($attrIds), ['id', 'AttrName']);
                foreach ($attrRows as $attrRow) {
                    $attrId = isset($attrRow['id']) ? trim((string)$attrRow['id']) : '';
                    $attrName = isset($attrRow['AttrName']) ? (string)$attrRow['AttrName'] : '';
                    if ($attrId === '' || $attrName === '') {
                        continue;
                    }
                    if ($this->legacyIsUAttrName($attrName)) {
                        $uAttrNameById[$attrId] = $attrName;
                    }
                }
            }

            foreach ($modelUAttrRows as $item) {
                $modelId = isset($item['ModelId']) ? trim((string)$item['ModelId']) : '';
                $attrId = isset($item['AttributeId']) ? trim((string)$item['AttributeId']) : '';
                if ($modelId === '' || $attrId === '' || isset($uAttrByModel[$modelId]) || !isset($uAttrNameById[$attrId])) {
                    continue;
                }
                $uAttrByModel[$modelId] = [
                    'AttrName' => $uAttrNameById[$attrId],
                    'AttributeVal' => $item['AttributeVal'] ?? '',
                ];
            }

            foreach ($typeUAttrRows as $item) {
                $typeId = isset($item['AssetsTypeId']) ? trim((string)$item['AssetsTypeId']) : '';
                $attrId = isset($item['AttributeId']) ? trim((string)$item['AttributeId']) : '';
                if ($typeId === '' || $attrId === '' || isset($uAttrByType[$typeId]) || !isset($uAttrNameById[$attrId])) {
                    continue;
                }
                $uAttrByType[$typeId] = [
                    'AttrName' => $uAttrNameById[$attrId],
                    'AttributeVal' => $item['AttributeVal'] ?? '',
                ];
            }
        }

        foreach ($rows as &$row) {
            $assetKey = isset($row[$assetIdField]) ? trim((string)$row[$assetIdField]) : '';
            $asset = $assetKey !== '' ? ($assetMap[$assetKey] ?? null) : null;
            $brand = $asset ? ($brandMap[(string)($asset['ModelId'] ?? '')] ?? null) : null;
            $type = $brand ? ($typeMap[(string)($brand['AssetsTypeId'] ?? '')] ?? null) : null;
            $person = $asset ? ($personMap[(string)($asset['EmpId'] ?? '')] ?? null) : null;
            $dept = $person ? ($deptMap[(string)($person['DeptId'] ?? '')] ?? null) : null;
            $area = isset($row[$areaIdField]) ? ($areaMap[(string)$row[$areaIdField]] ?? null) : null;
            $server = isset($row[$serverIdField]) ? ($serverMap[(string)$row[$serverIdField]] ?? null) : null;

            $row['AssetsNumber'] = $asset['AssetsNumber'] ?? '';
            $row['AssetsDescribe'] = $asset['AssetsDescribe'] ?? '';
            $row['AssetStatus'] = $asset['AssetStatus'] ?? '';
            $row['EmpId'] = $asset['EmpId'] ?? null;
            $row['StoreLocationId'] = $asset['StoreLocationId'] ?? null;
            $row['PersonName'] = $person['PersonName'] ?? '';
            $row['DeptId'] = $dept['id'] ?? ($person['DeptId'] ?? null);
            $row['DeptName'] = $dept['DeptName'] ?? '';
            $row['ModelId'] = $asset['ModelId'] ?? null;
            $row['BrandModel'] = $brand['BrandModel'] ?? '';
            $row['AssetsTypeId'] = $type['id'] ?? ($brand['AssetsTypeId'] ?? null);
            $row['AssetsTypeName'] = $type['AssetsTypeName'] ?? '';
            $row['AreaName'] = $area['AreaName'] ?? '';
            $row['ServerName'] = $server['ServerName'] ?? '';
            $row['UId'] = $asset['UId'] ?? '';
            $row['GatewayId'] = $asset['GatewayId'] ?? '';
            $row['AttrName'] = '';
            $row['AttributeVal'] = '';

            if ($includeUAttr && $asset && $brand) {
                $modelId = (string)($asset['ModelId'] ?? '');
                $typeId = (string)($brand['AssetsTypeId'] ?? '');
                if ($modelId !== '' && isset($uAttrByModel[$modelId])) {
                    $row['AttrName'] = $uAttrByModel[$modelId]['AttrName'] ?? '';
                    $row['AttributeVal'] = $uAttrByModel[$modelId]['AttributeVal'] ?? '';
                } elseif ($typeId !== '' && isset($uAttrByType[$typeId])) {
                    $row['AttrName'] = $uAttrByType[$typeId]['AttrName'] ?? '';
                    $row['AttributeVal'] = $uAttrByType[$typeId]['AttributeVal'] ?? '';
                }
            }
        }
        unset($row);

        return $rows;
    }

    private function legacyExtractDistinctIds(array $rows, string $field): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($field, $row)) {
                continue;
            }
            $value = $row[$field];
            if ($value === null) {
                continue;
            }
            $id = trim((string)$value);
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    private function legacyIsUAttrName(string $attrName): bool
    {
        $normalized = trim($attrName);
        if ($normalized === '') {
            return false;
        }
        return strtoupper(substr($normalized, 0, 1)) === 'U';
    }

    private function normalizeLegacyAttrInput(array $requestData, string $attrKey = 'Attr'): array
    {
        $attrs = $requestData[$attrKey] ?? [];
        if (is_string($attrs)) {
            $decoded = json_decode($attrs, true);
            if (is_array($decoded)) {
                $attrs = $decoded;
            } else {
                $attrs = [];
            }
        } elseif (is_object($attrs)) {
            $attrs = (array)$attrs;
        }

        $list = [];
        if (is_array($attrs)) {
            foreach ($attrs as $item) {
                if (is_object($item)) {
                    $item = (array)$item;
                }
                if (is_array($item)) {
                    $list[] = $item;
                }
            }
        }
        if ($list) {
            return $list;
        }

        $bucket = [];
        $quotedKey = preg_quote($attrKey, '/');
        foreach ($requestData as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (preg_match('/^' . $quotedKey . '\[(\d+)\]\[([A-Za-z0-9_]+)\]$/', $key, $m) !== 1) {
                continue;
            }
            $idx = (int)$m[1];
            $field = $m[2];
            if (!isset($bucket[$idx])) {
                $bucket[$idx] = [];
            }
            $bucket[$idx][$field] = $value;
        }
        if ($bucket) {
            ksort($bucket);
            return array_values($bucket);
        }

        if (isset($requestData['AttributeId']) || isset($requestData['AttributeVal'])) {
            return [[
                'AttributeId' => $requestData['AttributeId'] ?? null,
                'AttributeVal' => $requestData['AttributeVal'] ?? '',
            ]];
        }

        return [];
    }

    private function legacyArrayGetIgnoreCase(array $row, string $targetKey, $default = null)
    {
        if (array_key_exists($targetKey, $row)) {
            return $row[$targetKey];
        }
        foreach ($row as $key => $value) {
            if (is_string($key) && strcasecmp($key, $targetKey) === 0) {
                return $value;
            }
        }
        return $default;
    }

    private function normalizeLookupPairs(array $mapping): array
    {
        $pairs = [];
        if (isset($mapping['lookup_pairs']) && is_array($mapping['lookup_pairs'])) {
            foreach ($mapping['lookup_pairs'] as $pair) {
                if (!is_array($pair)) {
                    continue;
                }
                $source = $pair['excel_field'] ?? $pair['source_field'] ?? '';
                $lookup = $pair['lookup_field'] ?? '';
                if (is_string($source) && is_string($lookup) && $source !== '' && $lookup !== '') {
                    $pairs[] = ['source' => $source, 'lookup' => $lookup];
                }
            }
            if ($pairs) {
                return $pairs;
            }
        }

        if (isset($mapping['lookup_fields']) && is_array($mapping['lookup_fields'])) {
            $isAssoc = array_keys($mapping['lookup_fields']) !== range(0, count($mapping['lookup_fields']) - 1);
            if ($isAssoc) {
                foreach ($mapping['lookup_fields'] as $source => $lookup) {
                    if (!is_string($source) || !is_string($lookup) || $source === '' || $lookup === '') {
                        continue;
                    }
                    $pairs[] = ['source' => $source, 'lookup' => $lookup];
                }
            } else {
                $sourceList = isset($mapping['excel_fields']) ? $this->normalizeStringList($mapping['excel_fields']) : [];
                foreach ($mapping['lookup_fields'] as $idx => $lookup) {
                    if (!isset($sourceList[$idx])) {
                        continue;
                    }
                    if (!is_string($lookup) || $lookup === '') {
                        continue;
                    }
                    $pairs[] = ['source' => $sourceList[$idx], 'lookup' => $lookup];
                }
            }
            if ($pairs) {
                return $pairs;
            }
        }

        $source = $mapping['excel_field'] ?? '';
        $lookup = $mapping['lookup_field'] ?? '';
        if (is_string($source) && is_string($lookup) && $source !== '' && $lookup !== '') {
            $pairs[] = ['source' => $source, 'lookup' => $lookup];
        }
        return $pairs;
    }
    public function getFiltered() {
        $user = $this->getAuthUserFromPayload($this->currentRequestPayload());

        if (!$user) {
            json_string_response(['status' => 'error', 'message' => tp_msg_login()], 401);
            return;
        }

        $request = Flight::request();
        $queryPayload = [
            'params' => $request->query->params ?: '{}',
            'order' => $request->query->order ?: 'id',
            'sequence' => $request->query->sequence ?: 'DESC',
        ];
        if (isset($request->query->search)) {
            $queryPayload['search'] = $request->query->search;
        }
        if (isset($request->query->search_fields)) {
            $queryPayload['search_fields'] = $request->query->search_fields;
        }

        $limit = isset($request->query->limit) ? (int) $request->query->limit : 10;
        $offset = isset($request->query->offset) ? (int) $request->query->offset : 0;
        if ($limit <= 0) {
            $limit = 10;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        try {
            $parts = $this->buildFilterQueryParts($queryPayload, [
                'table' => $this->table,
                'not_deleted_only' => true,
                'default_order' => 'id',
                'default_sequence' => 'DESC',
            ]);

            $db = Flight::db();
            $whereSql = $parts['where'];
            $params = $parts['params'];
            $orderBy = $parts['order_by'] ? ' ' . $parts['order_by'] : '';

            $countSql = sprintf('SELECT COUNT(*) FROM %s WHERE %s', $this->tableForSql, $whereSql);
            $stmt = $db->prepare($countSql);
            foreach ($params as $ph => $val) {
                $stmt->bindValue($ph, $val);
            }
            $stmt->execute();
            $totalRecords = (int) $stmt->fetchColumn();

            $sql = sprintf('SELECT * FROM %s WHERE %s%s LIMIT :limit OFFSET :offset', $this->tableForSql, $whereSql, $orderBy);
            $stmt = $db->prepare($sql);
            foreach ($params as $ph => $val) {
                $stmt->bindValue($ph, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_string_response(['total' => $totalRecords, 'data' => $results]);
        } catch (\Throwable $e) {
            json_string_response([
                'status' => 'error',
                'message' => str_replace(
                    ['{op}', '{reason}'],
                    [dcim_msg('error.query_action'), $e->getMessage()],
                    dcim_msg('error.operation_failed_with_reason')
                ),
            ], 500);
        }
    }


    public function getAll() {
        // error_log("getAll: ");
        $user = $this->getAuthUserFromPayload($this->currentRequestPayload());
        
        if ($user) {
            $limit = Flight::request()->query->limit ?: 10; // default 10
            $offset = Flight::request()->query->offset ?: 0; // default 0

            $db = Flight::db();

            $strContact = "";

            $visibleWhereSql = $this->buildReadableRowWhereSql();
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$this->tableForSql} WHERE {$visibleWhereSql} $strContact");
            $stmt->execute();
            $totalRecords = $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT * FROM {$this->tableForSql} WHERE {$visibleWhereSql} $strContact LIMIT :limit OFFSET :offset");
        
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT); 

            // error_log("Statement after execute: " . print_r($stmt, true));
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_string_response(['total' => $totalRecords, 'data' => $results]);
            // return ['total' => $totalRecords, 'data' => $results];
            
        }
    }

    public function getById($id) {
        // error_log("getById: ");
        $user = $this->getAuthUserFromPayload($this->currentRequestPayload());

        if (!$user) {
            json_string_response(['status' => 'error', 'message' => tp_msg_login()], 401);
            return;
        }

        $db = Flight::db();
        
        $columns = $this->getTableColumns($this->tableName);
        $idField = $this->findColumnNameInsensitive($columns, 'id') ?: 'id';
        $whereSql = $this->quoteField($idField) . ' = :id';
        $visibleWhereSql = $this->buildReadableRowWhereSql();
        if ($visibleWhereSql !== '1=1') {
            $whereSql .= ' AND ' . $visibleWhereSql;
        }

        $stmt = $db->prepare("SELECT * FROM {$this->tableForSql} WHERE {$whereSql}");
        
        $stmt->bindParam(':id', $id);

        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            result_json(100, tp_msg_success(), $result, 1);
            return;
        }
        result_json(400, dcim_msg('common.request_failed'), false, 0);
    }

    public function create() {
        $requestData = $this->currentRequestPayload();
        $user = $this->getAuthUserFromPayload($requestData);
        if (!$user) {
            json_string_response(['status' => 'error', 'message' => tp_msg_login()], 401);
            return;
        }

        $data = $this->cleanupLegacyMutationPayload($requestData);
        if (empty($data)) {
            json_string_response(['status' => 'error', 'message' => dcim_msg('common.no_update_data')], 400);
            return;
        }

        try {
            $insertId = $this->insert($data);
            if ($insertId === false || $insertId === null) {
                json_string_response(['status' => 'error', 'message' => dcim_msg('common.request_failed')], 400);
                return;
            }
            $this->ensureDeviceBrokenAlarm($insertId);
            json_string_response(['status' => 'ok', 'id' => $insertId]);
            $tb = $this->table;
            self::syslog(str_replace('{table}', $tb, dcim_msg('log.crud_create')), $data, $user['id']);
        } catch (\Throwable $e) {
            json_string_response(['status' => 'error', 'message' => dcim_msg('common.request_failed')], 400);
        }
    }

    public function update($id) {
        $user = $this->getAuthUserFromPayload($this->currentRequestPayload());
        // error_log("update user: " . print_r($user, true));

        if ($user) {

            // error_log("data: " . print_r(json_encode(Flight::request()->data->getData()), true));
            $data = Flight::request()->data->getData();
            error_log("data: " . print_r($data, true));

        if (empty($data)) {
                json_string_response(['status' => 'error', 'message' => dcim_msg('common.no_update_data')], 400);
                return;
            }

            if (strcasecmp((string)$this->tableName, 'dcim-alarmnotifymode') === 0) {
                $safeMap = [
                    'AlarmName' => 'AlarmName',
                    'PhoneNotify' => 'PhoneNotify',
                    'SMSNotify' => 'SMSNotify',
                    'EmailNotify' => 'EmailNotify',
                    'NoiseNotify' => 'NoiseNotify',
                    'WeixinNotify' => 'WeixinNotify',
                    'WeComNotify' => 'WeComNotify',
                    'DingdingNotify' => 'DingdingNotify',
                    'UserID' => 'UserID',
                    'ConfirmNum' => 'ConfirmNum',
                    'NotifyNum' => 'NotifyNum',
                    'IntervalTime' => 'IntervalTime',
                    'AlarmLevel' => 'AlarmLevel',
                    'NotifyWindowID' => 'NotifyWindowID',
                    'UpgradeTime' => 'UpgradeTime',
                ];
                $intFields = array_fill_keys([
                    'PhoneNotify', 'SMSNotify', 'EmailNotify', 'NoiseNotify',
                    'WeixinNotify', 'WeComNotify', 'DingdingNotify',
                    'ConfirmNum', 'NotifyNum', 'IntervalTime', 'AlarmLevel',
                    'NotifyWindowID', 'UpgradeTime',
                ], true);
                $updateData = [];
                foreach ($safeMap as $reqField => $dbField) {
                    if (!array_key_exists($reqField, $data)) {
                        continue;
                    }
                    $rawVal = $data[$reqField];
                    if (isset($intFields[$reqField])) {
                        $txt = trim((string)$rawVal);
                        if ($txt === '' || !is_numeric($txt)) {
                            continue;
                        }
                        $updateData[$dbField] = (int)$txt;
                        continue;
                    }
                    $txt = trim((string)$rawVal);
                    if ($txt === '') {
                        continue;
                    }
                    $updateData[$dbField] = $txt;
                }
                if (!$updateData) {
                    json_string_response(['status' => 'error', 'message' => dcim_msg('common.no_update_data')], 400);
                    return;
                }
                $updateData['update_time'] = date('Y-m-d H:i:s');

                $idList = array_values(array_filter(array_map('trim', explode(',', (string)$id)), static function ($v) {
                    return $v !== '' && ctype_digit((string)$v);
                }));
                if (!$idList) {
                    json_string_response(['status' => 'error', 'message' => dcim_msg('common.invalid_batch_id')], 400);
                    return;
                }

                $quoteVal = static function ($v): string {
                    if (is_int($v) || is_float($v)) {
                        return (string)$v;
                    }
                    if (is_bool($v)) {
                        return $v ? '1' : '0';
                    }
                    return "'" . str_replace("'", "''", (string)$v) . "'";
                };
                $setParts = [];
                foreach ($updateData as $field => $val) {
                    $setParts[] = $this->quoteField((string)$field) . ' = ' . $quoteVal($val);
                }
                $idSql = implode(',', array_map(static function ($oneId) {
                    return (string)((int)$oneId);
                }, $idList));
                $sql = 'UPDATE ' . $this->tableForSql
                    . ' SET ' . implode(', ', $setParts)
                    . ' WHERE ' . $this->quoteField('id') . ' IN (' . $idSql . ')'
                    . ' AND (' . $this->quoteField('status') . ' <> -1 OR ' . $this->quoteField('status') . ' IS NULL)';
                $res = $db->exec($sql);
                if ($res === false) {
                    json_string_response(['status' => 'error', 'message' => dcim_msg('common.operation_failed')], 400);
                    return;
                }
                json_string_response(['status' => 'ok']);
                $tb = $this->table;
                self::syslog(str_replace('{table}', $tb, dcim_msg('log.crud_update')), $updateData, $user['id']);
                return;
            }

            $set = "";
            foreach ($data as $key => $val) {
                if($key != "auth") $set .= "$key = :$key, ";
            }

            $set = rtrim($set, ", ");

            $db = Flight::db();

            // --- Dynamically build the "SET" clause and parameters ---
            $setParts = [];
            $params = [];


            foreach ($data as $key => $val) {
                // if ($key == "auth") continue; // Skip non-column data
                // error_log("key: " . $key);
                // error_log("val: " . $val);

                // Check for the special increment/decrement syntax
                if (is_string($val) && preg_match('/^([+-])=([\d\.]+)$/', $val, $matches)) {
                    // This is an increment/decrement operation like "+=1" or "-=10"
                    $operator = $matches[1]; // '+' or '-'
                    $numericValue = $matches[2]; // '1' or '10'
                    // error_log("operator: " . $operator);
                    // error_log("numericValue: " . $numericValue);
                    // Use backticks for column name safety
                    $quotedKey = $this->quoteField((string)$key);
                    $setParts[] = $quotedKey . " = " . $quotedKey . " {$operator} ?";
                    $params[] = $numericValue;
                } else {
                    // This is a standard set operation
                    $setParts[] = $this->quoteField((string)$key) . " = ?";
                    $params[] = $val;
                }
            }
            // error_log("setParts: " .print_r($setParts, true));
            // error_log("params: " .print_r($params, true));

        if (empty($data)) {
                json_string_response(['status' => 'error', 'message' => dcim_msg('common.no_update_data')], 400);
                return;
            }

            $setClause = implode(", ", $setParts);
            // error_log("setClause: " . $setClause);
            // --- Distinguish between single and batch update ---
            $columns = $this->getTableColumns($this->tableName);
            $idField = $this->findColumnNameInsensitive($columns, 'id') ?: 'id';
            $qIdField = $this->quoteField($idField);

            if (strpos((string)$id, ',') !== false) {
                // --- BATCH UPDATE LOGIC (SECURE and CORRECTED) ---
                $idArray = array_filter(array_map('trim', explode(',', $id)));
                if(empty($idArray)) {
                    json_string_response(['status' => 'error', 'message' => dcim_msg('common.invalid_batch_id')], 400);
                    return;
                }
                
                // Create placeholders for the IN clause: ?,?,?
                $inPlaceholders = implode(',', array_fill(0, count($idArray), '?'));
                
                $sql = "UPDATE {$this->tableForSql} SET {$setClause} WHERE {$qIdField} IN ({$inPlaceholders})";

                error_log("update sql1: " . $sql);
                // Merge the SET parameters with the ID array for execution
                $finalParams = array_merge($params, $idArray);

            } else {
                // --- SINGLE UPDATE LOGIC ---
                $sql = "UPDATE {$this->tableForSql} SET {$setClause} WHERE {$qIdField} = ?";
                error_log("update sql2: " . $sql);
                // Merge the SET parameters with the single ID for execution
                $finalParams = array_merge($params, [$id]);
            }

            error_log("finalParams: " . print_r($finalParams, true));
            // --- Execute Query and Return Response ---
            $stmt = $db->prepare($sql);
            $stmt->execute($finalParams);

            json_string_response(['status' => 'ok']);
            $tb = $this->table;
                self::syslog(str_replace('{table}', $tb, dcim_msg('log.crud_update')) , $data, $user['id']);
        }
    }

    public function delete($id) {
        $user = $this->getAuthUserFromPayload($this->currentRequestPayload());

        if ($user) {

            $db = Flight::db();
            $columns = $this->getTableColumns($this->tableName);
            $idField = $this->findColumnNameInsensitive($columns, 'id') ?: 'id';
            $deleteField = $this->findColumnNameInsensitive($columns, 'is_deleted');
            if ($deleteField === null) {
                $deleteField = $this->findColumnNameInsensitive($columns, 'status') ?: 'status';
            }
            $qIdField = $this->quoteField($idField);
            $qDeleteField = $this->quoteField($deleteField);

            if (strpos((string)$id, ',') !== false) {
                $idArray = array_values(array_filter(array_map('trim', explode(',', (string)$id)), function ($v) {
                    return $v !== '';
                }));
                if (!$idArray) {
                    json_string_response(['status' => 'error', 'message' => dcim_msg('common.invalid_batch_id')], 400);
                    return;
                }
                $inPlaceholders = implode(',', array_fill(0, count($idArray), '?'));
                $sql = "UPDATE {$this->tableForSql} SET {$qDeleteField} = -1 WHERE {$qIdField} IN ({$inPlaceholders})";
                $stmt = $db->prepare($sql);
                $stmt->execute($idArray);
            } else {
                $sql = "UPDATE {$this->tableForSql} SET {$qDeleteField} = -1 WHERE {$qIdField} = :id";
                $stmt = $db->prepare($sql);
                $stmt->bindValue(':id', $id);
                $stmt->execute();
            }
            json_string_response(['status' => 'ok']);
            // error_log("table: " . $this->table);
            $tb = $this->table;
                self::syslog(str_replace('{table}', $tb, dcim_msg('log.crud_delete')), ["id" => $id], $user['id']);
        }
    }

    public function save() {
        $user = $this->getAuthUserFromPayload($this->currentRequestPayload());

        if ($user) {
            try {
                // Encode payload as JSON
                $data = Flight::request()->data->getData();
                $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);

                $db = Flight::db();
                // Stored procedure naming: p_{table}
                $procName = "sp_" . $this->table;
                // Execute procedure

                error_log("jsonData: " . print_r($jsonData, true));

                $sql = "CALL {$procName}(:jsonData))";
                error_log("data: " . print_r($sql, true));
                
                $stmt = $db->prepare("CALL {$procName}(:jsonData)");
                $stmt->bindParam(":jsonData", $jsonData);
                
                $stmt->execute();
                $stmt->closeCursor(); // release cursor for next query

                json_string_response(['status' => 'ok']);
                $tb = $this->table;
                self::syslog(str_replace('{table}', $tb, dcim_msg('log.crud_save')) , $data, $user['id']);
            }
            catch (Exception $e) 
            {
                error_log("error: " . print_r($e->getMessage(), true));
                json_string_response([
                    'status' => 'error',
                    'message' => str_replace(
                        ['{op}', '{reason}'],
                        [dcim_msg('error.save_action'), $e->getMessage()],
                        dcim_msg('error.operation_failed_with_reason')
                    ),
                ], 500);
            }
        }
    }

    private static function syslog($action, $data, $user) {
        try {
            if (function_exists('addLog')) {
                addLog((string)$action, $data, $user);
            }
        } catch (\Throwable $e) {
            error_log('[crud.syslog] failed: ' . $e->getMessage());
        }
    }

    public function uploads() {
        $user = $this->getAuthUserFromPayload($this->currentRequestPayload());

        if (!$user) {
            json_string_response(['status' => 'error', 'message' => tp_msg_login()], 401);
            return;
        }

        // Keep upload target selection runtime-safe across different deployment roots and permissions.
        $relativePath = '/uploads/';
        $rootDir = dirname(__DIR__, 2);
        $candidateDirs = [
            // Prefer persistent project directories so returned file paths remain stable.
            $rootDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads',
            $rootDir . DIRECTORY_SEPARATOR . 'uploads',
            // Temp dir is only fallback when project paths are not writable.
            rtrim((string)sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'dcim_uploads',
        ];
        $isDirReallyWritable = static function (string $dir): bool {
            $probe = @tempnam($dir, 'upw_');
            if (!is_string($probe) || $probe === '') {
                return false;
            }
            $ok = (@file_put_contents($probe, 'ok') !== false);
            @unlink($probe);
            return $ok;
        };
        $uploadDir = '';
        $isPublicUploadDir = false;
        foreach ($candidateDirs as $candidateDir) {
            $candidateDir = rtrim((string)$candidateDir, '\\/');
            if ($candidateDir === '') {
                continue;
            }
            if (!is_dir($candidateDir)) {
                @mkdir($candidateDir, 0777, true);
            }
            if (!is_dir($candidateDir)) {
                continue;
            }
            if (!is_writable($candidateDir) || !$isDirReallyWritable($candidateDir)) {
                @chmod($candidateDir, 0777);
            }
            if (is_writable($candidateDir) && $isDirReallyWritable($candidateDir)) {
                $uploadDir = $candidateDir;
                $normalizedPublicUploads = str_replace('\\', '/', $rootDir . '/public/uploads');
                $normalizedCurrent = str_replace('\\', '/', $candidateDir);
                $isPublicUploadDir = ($normalizedCurrent === $normalizedPublicUploads);
                break;
            }
        }
        if ($uploadDir === '') {
            json_string_response(['status' => 'error', 'message' => dcim_msg('upload.save_failed')], 500);
            return;
        }
    
        $request = Flight::request();
            $fileNode = is_object($request) && isset($request->files['file']) ? $request->files['file'] : null;
            $file = null;
            if (is_array($fileNode)) {
                $file = $fileNode;
            } elseif (is_object($fileNode)) {
                $tmpName = '';
                $fileName = '';
                foreach (['getPathname', 'getRealPath'] as $method) {
                    if (!method_exists($fileNode, $method)) {
                        continue;
                    }
                    try {
                        $tmp = $fileNode->{$method}();
                        if (is_string($tmp) && $tmp !== '') {
                            $tmpName = $tmp;
                            break;
                        }
                    } catch (\Throwable $e) {
                    }
                }
                foreach (['getClientFilename', 'getFilename', 'getBasename'] as $method) {
                    if ($fileName !== '' || !method_exists($fileNode, $method)) {
                        continue;
                    }
                    try {
                        $name = $fileNode->{$method}();
                        if (is_string($name) && $name !== '') {
                            $fileName = $name;
                        }
                    } catch (\Throwable $e) {
                    }
                }
                if ($tmpName !== '') {
                    $file = [
                        'error' => UPLOAD_ERR_OK,
                        'name' => $fileName,
                        'tmp_name' => $tmpName,
                    ];
                }
            }
            if (!is_array($file) || empty($file['tmp_name']) || !is_string($file['tmp_name'])) {
                json_string_response(['status' => 'error', 'message' => dcim_msg('error.failed_read_file')], 400);
                return;
            }
            $errorCode = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_OK;
            if ($errorCode === UPLOAD_ERR_OK) {
                // $filename = basename($file['name']);
                // $targetFile = $uploadDir . $filename;
                $extension = pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION);
                $filename = uniqid() . ($extension !== '' ? ('.' . $extension) : '');
                $targetFile = $uploadDir . DIRECTORY_SEPARATOR . $filename;
                $moved = false;
                if (is_uploaded_file($file['tmp_name'])) {
                    $moved = @move_uploaded_file($file['tmp_name'], $targetFile);
                } elseif (is_file($file['tmp_name'])) {
                    $moved = @copy($file['tmp_name'], $targetFile);
                }
                if ($moved && is_file($targetFile)) {
                    $publicPath = $relativePath . $filename;
                    $responsePath = $isPublicUploadDir ? $publicPath : $targetFile;
                    self::syslog(str_replace('{path}', $responsePath, dcim_msg('log.upload_file')), '', $user['id']);
                    json_string_response([
                        'status' => 'ok',
                        'path' => $responsePath,
                        'file_path' => $targetFile,
                    ]);
                } else {
                    @unlink($targetFile);
                    json_string_response(['status' => 'error', 'message' => dcim_msg('upload.save_failed')], 500);
                }
            } else {
                json_string_response(['status' => 'error', 'message' => dcim_msg('common.request_failed')], 400);
            }
    }

    // Backup database
    public function backup() {
        $user = $this->getAuthUserFromPayload($this->currentRequestPayload());

        if ($user) {
            $dbname = 'kuercrm';
            $dbuser = 'root';
            $dbpass = '123456';
            $backupFile = './backup/' . $dbname . '_' . date('Ymd_His') . '.sql';

            $command = "D:\phpstudy_pro\Extensions\MySQL5.7.26\bin\mysqldump --user={$dbuser} --password={$dbpass} --databases {$dbname} > {$backupFile}";
            // error_log("backup command: " . $command);
            system($command, $retval);
            
            if ($retval == 0) {
            self::syslog(str_replace('{file}', $backupFile, dcim_msg('log.backup_db')), '', $user['id']);
                json_string_response(['status' => 'ok', 'message' => dcim_msg('common.backup_ok'), 'file' => $backupFile]);
            } else {
                json_string_response(['status' => 'error', 'message' => dcim_msg('common.backup_failed')], 500);
            }
        }
    }

    // Restore database
    public function restore() {
        $user = $this->getAuthUserFromPayload($this->currentRequestPayload());

        if ($user) {
            $dbname = 'kuercrm';
            $dbuser = 'root';
            $dbpass = '123456';
            $backupFile = Flight::request()->data->backup_file;

            if (!file_exists($backupFile)) {
                json_string_response(['status' => 'error', 'message' => dcim_msg('common.backup_file_missing')], 400);
                return;
            }

            $command = "D:\phpstudy_pro\Extensions\MySQL5.7.26\bin\mysql --user={$dbuser} --password={$dbpass} {$dbname} < {$backupFile}";

            system($command, $retval);

            if ($retval == 0) {
            self::syslog(str_replace('{file}', $backupFile, dcim_msg('log.restore_db')), '', $user['id']);
                json_string_response(['status' => 'ok', 'message' => dcim_msg('common.restore_ok')]);
            } else {
                json_string_response(['status' => 'error', 'message' => dcim_msg('common.restore_failed')], 500);
            }
        }
    }

    public function importExcel() {
        $data = Flight::request()->data->getData();
        $data = $this->mergeRequestPayload(is_array($data) ? $data : []);
        $user = $this->getAuthUserFromPayload($data);
        if (!$user) {
            json_string_response(['status' => 'error', 'message' => tp_msg_login()], 401);
            return;
        }

        try {
            $result = $this->importMappedData($data);
            if (!empty($result['errors'])) {
                json_string_response([
                    'status' => 'error',
                    'message' => dcim_msg('common.import_finished_with_errors'),
                    'data' => $result,
                    'errors' => $result['errors'],
                ]);
                return;
            }
            json_string_response(['status' => 'ok', 'message' => dcim_msg('common.import_ok'), 'data' => $result]);
        } catch (\Throwable $e) {
            json_string_response([
                'status' => 'error',
                'message' => str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')),
            ], 400);
        }
    }

    public function importMapped()
    {
        $data = Flight::request_data();
        $data = $this->mergeRequestPayload(is_array($data) ? $data : []);
        $user = $this->getAuthUserFromPayload($data);
        if (!$user) {
            json_string_response(['status' => 'error', 'message' => tp_msg_login()], 401);
            return;
        }

        try {
            $result = $this->importMappedData($data);
            if (!empty($result['errors'])) {
                json_string_response([
                    'status' => 'error',
                    'message' => dcim_msg('common.import_finished_with_errors'),
                    'data' => $result,
                    'errors' => $result['errors'],
                ]);
                return;
            }
            json_string_response(['status' => 'ok', 'message' => dcim_msg('common.import_ok'), 'data' => $result]);
        } catch (\Throwable $e) {
            json_string_response([
                'status' => 'error',
                'message' => str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')),
            ], 400);
        }
    }

    public function importMappedData(array $requestData, array $options = []): array
    {
        $tableInput = $options['table'] ?? ($requestData['table'] ?? $this->table);
        $table = $this->normalizeTableName((string) $tableInput);
        if ($table === null) {
            throw new InvalidArgumentException('table name required');
        }

        $inlineRowsProvided = array_key_exists('rows', $requestData);
        $rowsPayload = $this->decodeArrayInput($requestData['rows'] ?? []);
        $headerNames = $this->normalizeStringList($requestData['headers'] ?? []);
        $inputMode = 'excel';
        $filePath = null;
        $sourceRows = [];

        $isSequentialArray = static function (array $arr): bool {
            return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
        };

        if ($inlineRowsProvided || $rowsPayload) {
            $inputMode = 'rows';
            if ($rowsPayload && !$isSequentialArray($rowsPayload)) {
                $rowsPayload = [$rowsPayload];
            }
            if (!$rowsPayload) {
                throw new InvalidArgumentException('rows payload is empty');
            }

            $skipRows = isset($requestData['skip_rows']) ? (int) $requestData['skip_rows'] : 0;
            if ($skipRows < 0) {
                $skipRows = 0;
            }
            $headerRow = isset($requestData['header_row']) ? (int) $requestData['header_row'] : 1;
            if ($headerRow <= 0) {
                $headerRow = 1;
            }
            $headerIndex = $headerRow - 1;

            $hasAssocRows = false;
            foreach ($rowsPayload as $candidateRow) {
                if (is_object($candidateRow)) {
                    $candidateRow = (array) $candidateRow;
                }
                if (is_array($candidateRow) && !$isSequentialArray($candidateRow)) {
                    $hasAssocRows = true;
                    break;
                }
            }

            if (!$headerNames) {
                if ($hasAssocRows) {
                    foreach ($rowsPayload as $candidateRow) {
                        if (is_object($candidateRow)) {
                            $candidateRow = (array) $candidateRow;
                        }
                        if (!is_array($candidateRow) || $isSequentialArray($candidateRow)) {
                            continue;
                        }
                        $headerNames = array_values(array_filter(array_map(static function ($key) {
                            return trim((string) $key);
                        }, array_keys($candidateRow)), static function ($key) {
                            return $key !== '';
                        }));
                        if ($headerNames) {
                            break;
                        }
                    }
                } else {
                    if (!isset($rowsPayload[$headerIndex])) {
                        throw new RuntimeException('header row out of range');
                    }
                    $headerNames = array_map(static function ($value) {
                        return trim((string) $value);
                    }, (array) $rowsPayload[$headerIndex]);
                }
            }

            if (!$headerNames) {
                throw new RuntimeException('headers missing');
            }

            $hasCustomHeaders = isset($requestData['headers']);
            $dataStart = ($hasAssocRows || $hasCustomHeaders) ? $skipRows : ($headerIndex + 1 + $skipRows);
            for ($rowIndex = $dataStart; $rowIndex < count($rowsPayload); $rowIndex++) {
                $rawRow = $rowsPayload[$rowIndex];
                if (is_object($rawRow)) {
                    $rawRow = (array) $rawRow;
                }
                if (!is_array($rawRow)) {
                    continue;
                }

                $sourceRow = [];
                foreach ($rawRow as $rawKey => $value) {
                    if (is_int($rawKey)) {
                        $headerName = $headerNames[$rawKey] ?? '';
                        if ($headerName === '') {
                            continue;
                        }
                        $sourceRow[$headerName] = $value;
                        continue;
                    }

                    $field = trim((string) $rawKey);
                    if ($field === '') {
                        continue;
                    }
                    $sourceRow[$field] = $value;
                }

                $sourceRows[] = [
                    'row_no' => $rowIndex + 1,
                    'data' => $sourceRow,
                ];
            }
        } else {
            $filePathInput = (string)($requestData['file_path'] ?? ($requestData['filePath'] ?? ($requestData['path'] ?? ($requestData['file'] ?? ''))));
            $filePath = $this->resolveLocalPath($filePathInput);
            if ($filePath === null) {
                $filePath = $this->resolveUploadedTempPath();
            }
            if ($filePath === null) {
                throw new InvalidArgumentException('excel file missing');
            }

            try {
                $spreadsheet = IOFactory::load($filePath);
            } catch (\Throwable $e) {
                throw new RuntimeException('failed to read excel file');
            }

            $rows = $spreadsheet->getActiveSheet()->toArray(null, false, false, false);
            if (!$rows) {
                throw new RuntimeException('excel rows are empty');
            }

            $headerRow = isset($requestData['header_row']) ? (int) $requestData['header_row'] : 1;
            if ($headerRow <= 0) {
                $headerRow = 1;
            }
            $headerIndex = $headerRow - 1;
            if (!isset($rows[$headerIndex])) {
                throw new RuntimeException('header row out of range');
            }

            $headerNames = array_map(static function ($value) {
                return trim((string) $value);
            }, $rows[$headerIndex]);
            $skipRows = isset($requestData['skip_rows']) ? (int) $requestData['skip_rows'] : 1;
            if ($skipRows < 0) {
                $skipRows = 0;
            }
            $dataStart = $headerIndex + 1 + $skipRows;
            for ($rowIndex = $dataStart; $rowIndex < count($rows); $rowIndex++) {
                $rawRow = $rows[$rowIndex];
                $sourceRow = [];
                foreach ($headerNames as $idx => $headerName) {
                    if ($headerName === '') {
                        continue;
                    }
                    $sourceRow[$headerName] = $rawRow[$idx] ?? null;
                }
                $sourceRows[] = [
                    'row_no' => $rowIndex + 1,
                    'data' => $sourceRow,
                ];
            }
        }

        $fieldMap = $this->decodeArrayInput($requestData['field_map'] ?? []);
        $mappings = $this->decodeArrayInput($requestData['mappings'] ?? []);
        $extraFields = $this->decodeArrayInput($requestData['extra_fields'] ?? []);
        $primaryKey = isset($requestData['primary_key']) && is_scalar($requestData['primary_key'])
            ? trim((string)$requestData['primary_key'])
            : '';
        $primaryFieldName = isset($requestData['primary_field_name']) && is_scalar($requestData['primary_field_name'])
            ? trim((string)$requestData['primary_field_name'])
            : '';
        if ($primaryKey !== '' && $primaryFieldName !== '' && $primaryKey !== $primaryFieldName && !isset($fieldMap[$primaryFieldName])) {
            $fieldMap[$primaryFieldName] = $primaryKey;
        }
        $upsertKeys = $this->normalizeStringList($requestData['upsert_keys'] ?? ($primaryKey !== '' ? $primaryKey : []));
        $strictUpsertMatch = !empty($options['strict_upsert_match']) || !empty($requestData['strict_upsert_match']);

        $targetCrud = new self($table);
        $tableColumns = $targetCrud->getTableColumns($table);
        $allowedFieldSet = $tableColumns ? array_fill_keys($tableColumns, true) : [];
        $statusColumn = $targetCrud->findColumnNameInsensitive($tableColumns, 'status');
        $idColumn = $targetCrud->findColumnNameInsensitive($tableColumns, 'id');

        $db = Flight::db();
        $processed = 0;
        $inserted = 0;
        $updated = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];
        $stmtCache = [
            'select' => [],
            'update' => [],
            'insert' => [],
        ];

        foreach ($sourceRows as $sourceMeta) {
            $sourceRow = isset($sourceMeta['data']) && is_array($sourceMeta['data']) ? $sourceMeta['data'] : [];
            $excelRowNo = isset($sourceMeta['row_no']) ? (int) $sourceMeta['row_no'] : 0;
            if ($excelRowNo <= 0) {
                $excelRowNo = $processed + $failed + $skipped + 1;
            }

            $sourceRow = array_filter($sourceRow, function ($value) {
                if ($value === null) {
                    return false;
                }
                return !(is_string($value) && trim($value) === '');
            });

            if (!$sourceRow) {
                $skipped++;
                continue;
            }

            try {
                $record = [];
                foreach ($sourceRow as $field => $value) {
                    $targetField = isset($fieldMap[$field]) && is_string($fieldMap[$field]) && $fieldMap[$field] !== '' ? $fieldMap[$field] : $field;
                    $record[$targetField] = $value;
                }
                foreach ($extraFields as $field => $value) {
                    if (!is_string($field) || trim($field) === '') {
                        continue;
                    }
                    $record[trim($field)] = $value;
                }

                foreach ($mappings as $mapping) {
                    if (!is_array($mapping)) {
                        continue;
                    }

                    $directSource = $mapping['excel_field'] ?? null;
                    $directTarget = $mapping['target_field'] ?? null;
                    if ($directSource && $directTarget && empty($mapping['lookup_table']) && array_key_exists($directSource, $sourceRow)) {
                        $record[$directTarget] = $sourceRow[$directSource];
                    }

                    if (empty($mapping['lookup_table'])) {
                        continue;
                    }

                    $lookupTable = $this->normalizeTableName((string)$mapping['lookup_table']);
                    $lookupValueField = isset($mapping['lookup_value']) ? (string) $mapping['lookup_value'] : '';
                    if ($lookupTable === null || !$this->isSafeFieldName($lookupValueField)) {
                        throw new RuntimeException('invalid lookup mapping config');
                    }

                    $pairs = $this->normalizeLookupPairs($mapping);
                    if (!$pairs) {
                        throw new RuntimeException('lookup mapping pairs required');
                    }

                    $whereParts = [];
                    $lookupBind = [];
                    $lookupCounter = 1;
                    foreach ($pairs as $pair) {
                        $sourceField = (string) $pair['source'];
                        $lookupField = (string) $pair['lookup'];
                        if (!$this->isSafeFieldName($lookupField)) {
                            throw new RuntimeException('invalid lookup field name');
                        }
                        $value = $sourceRow[$sourceField] ?? ($record[$sourceField] ?? null);
                        if ($value === null || (is_string($value) && trim($value) === '')) {
                            if (!empty($mapping['required'])) {
                                throw new RuntimeException('lookup source value missing: ' . $sourceField);
                            }
                            continue 2;
                        }
                        $ph = ':lk' . $lookupCounter++;
                        $whereParts[] = $this->quoteField($lookupField) . ' = ' . $ph;
                        $lookupBind[$ph] = $value;
                    }

                    if (!$whereParts) {
                        continue;
                    }

                    $lookupSql = sprintf(
                        'SELECT %s AS mapped_value FROM %s WHERE %s LIMIT 1',
                        $this->quoteField($lookupValueField),
                        $this->quoteTable($lookupTable),
                        implode(' AND ', $whereParts)
                    );
                    $lookupStmt = $db->prepare($lookupSql);
                    foreach ($lookupBind as $ph => $val) {
                        $lookupStmt->bindValue($ph, $val);
                    }
                    $lookupStmt->execute();
                    $lookupRow = $lookupStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$lookupRow || !array_key_exists('mapped_value', $lookupRow)) {
                        throw new RuntimeException('mapping lookup failed');
                    }

                    $targetField = isset($mapping['target_field']) && is_string($mapping['target_field']) && $mapping['target_field'] !== ''
                        ? $mapping['target_field']
                        : (isset($mapping['excel_field']) ? (string)$mapping['excel_field'] : $lookupValueField);
                    $record[$targetField] = $lookupRow['mapped_value'];
                }

                $record = array_filter($record, function ($value) {
                    if ($value === null) {
                        return false;
                    }
                    return !(is_string($value) && trim($value) === '');
                });
                if ($allowedFieldSet) {
                    $record = array_intersect_key($record, $allowedFieldSet);
                }
                if (!$record) {
                    throw new RuntimeException('no valid columns to write');
                }
                $recordValueInsensitive = static function (array $arr, string $field) {
                    foreach ($arr as $k => $v) {
                        if (is_string($k) && strcasecmp($k, $field) === 0) {
                            return $v;
                        }
                    }
                    return null;
                };
                $normalizeCompareText = static function ($raw): string {
                    $txt = trim((string)$raw);
                    if ($txt === '') {
                        return '';
                    }
                    $txt = preg_replace('/\s+/u', ' ', $txt);
                    if (!is_string($txt)) {
                        $txt = trim((string)$raw);
                    }
                    return function_exists('mb_strtolower') ? mb_strtolower($txt, 'UTF-8') : strtolower($txt);
                };

                $effectiveUpsertKeys = [];
                foreach ($upsertKeys as $key) {
                    if (!$this->isSafeFieldName($key)) {
                        continue;
                    }
                    if (!array_key_exists($key, $record)) {
                        continue;
                    }
                    if ($record[$key] === null || (is_string($record[$key]) && trim($record[$key]) === '')) {
                        continue;
                    }
                    if (is_string($record[$key])) {
                        $record[$key] = trim($record[$key]);
                    }
                    $effectiveUpsertKeys[] = $key;
                }

                $existingFound = false;
                $existingRow = null;
                $upsertWhereParts = [];
                $upsertWhereBind = [];
                if ($effectiveUpsertKeys) {
                    foreach ($effectiveUpsertKeys as $idx => $key) {
                        $fieldSql = $this->quoteField($key);
                        $value = $record[$key];
                        if (is_string($value)) {
                            if ($strictUpsertMatch) {
                                $ph = ':u' . $idx;
                                $upsertWhereParts[] = $fieldSql . ' = ' . $ph;
                                $upsertWhereBind[$ph] = trim($value);
                            } else {
                                $phRaw = ':u' . $idx . '_raw';
                                $phTrim = ':u' . $idx . '_trim';
                                $phTrimLower = ':u' . $idx . '_trim_lower';
                                $upsertWhereParts[] = '(' . $fieldSql . ' = ' . $phRaw . ' OR TRIM(' . $fieldSql . ') = ' . $phTrim . ' OR LOWER(TRIM(' . $fieldSql . ')) = ' . $phTrimLower . ')';
                                $upsertWhereBind[$phRaw] = $value;
                                $upsertWhereBind[$phTrim] = trim($value);
                                $upsertWhereBind[$phTrimLower] = strtolower(trim($value));
                            }
                        } else {
                            $ph = ':u' . $idx;
                            $upsertWhereParts[] = $fieldSql . ' = ' . $ph;
                            $upsertWhereBind[$ph] = $value;
                        }
                    }
                    $selectFields = $idColumn !== null ? $this->quoteField($idColumn) : '1';
                    if ($statusColumn !== null) {
                        $selectFields .= ', ' . $this->quoteField($statusColumn);
                    }
                    $orderByParts = [];
                    if ($statusColumn !== null) {
                        $qStatus = $this->quoteField($statusColumn);
                        $orderByParts[] = 'CASE WHEN ' . $qStatus . ' = -1 THEN 1 ELSE 0 END ASC';
                    }
                    if ($idColumn !== null) {
                        $orderByParts[] = $this->quoteField($idColumn) . ' DESC';
                    }
                    $selectSql = sprintf(
                        'SELECT %s FROM %s WHERE %s%s LIMIT 1',
                        $selectFields,
                        $this->quoteTable($table),
                        implode(' AND ', $upsertWhereParts),
                        $orderByParts ? (' ORDER BY ' . implode(', ', $orderByParts)) : ''
                    );
                    if (!isset($stmtCache['select'][$selectSql])) {
                        $stmtCache['select'][$selectSql] = $db->prepare($selectSql);
                    }
                    $selectStmt = $stmtCache['select'][$selectSql];
                    $selectStmt->execute($upsertWhereBind);
                    $existingRow = $selectStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    $selectStmt->closeCursor();
                    $existingFound = (bool) $existingRow;
                }
                if (!$existingFound && strtolower($table) === 'dcim-devicecommand' && $idColumn !== null) {
                    $commandColumn = $targetCrud->findColumnNameInsensitive($tableColumns, 'Command');
                    $devIdColumn = $targetCrud->findColumnNameInsensitive($tableColumns, 'DevID')
                        ?? $targetCrud->findColumnNameInsensitive($tableColumns, 'DevId');
                    $commandTypeColumn = $targetCrud->findColumnNameInsensitive($tableColumns, 'CommandType');
                    if ($commandColumn !== null) {
                        $commandValue = $recordValueInsensitive($record, $commandColumn);
                        $normalizedCommand = $normalizeCompareText($commandValue);
                        if ($normalizedCommand !== '') {
                            $candidateWhere = ['1=1'];
                            $candidateBind = [];
                            if ($devIdColumn !== null) {
                                $devValue = $recordValueInsensitive($record, $devIdColumn);
                                if ($devValue !== null && $devValue !== '') {
                                    $candidateWhere[] = $this->quoteField($devIdColumn) . ' = :cand_dev';
                                    $candidateBind[':cand_dev'] = $devValue;
                                }
                            }
                            if ($commandTypeColumn !== null) {
                                $typeValue = $recordValueInsensitive($record, $commandTypeColumn);
                                if ($typeValue !== null && $typeValue !== '') {
                                    $candidateWhere[] = $this->quoteField($commandTypeColumn) . ' = :cand_type';
                                    $candidateBind[':cand_type'] = $typeValue;
                                }
                            }
                            $candidateSql = sprintf(
                                'SELECT * FROM %s WHERE %s',
                                $this->quoteTable($table),
                                implode(' AND ', $candidateWhere)
                            );
                            $candidateStmt = $db->prepare($candidateSql);
                            foreach ($candidateBind as $ph => $val) {
                                $candidateStmt->bindValue($ph, $val);
                            }
                            $candidateStmt->execute();
                            $candidateRows = $candidateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                            $bestRow = null;
                            foreach ($candidateRows as $candidateRow) {
                                $candidateCommand = $candidateRow[$commandColumn] ?? ($candidateRow[strtoupper($commandColumn)] ?? ($candidateRow[strtolower($commandColumn)] ?? ''));
                                if ($normalizeCompareText($candidateCommand) !== $normalizedCommand) {
                                    continue;
                                }
                                if ($bestRow === null) {
                                    $bestRow = $candidateRow;
                                    continue;
                                }
                                $bestStatus = 0;
                                $curStatus = 0;
                                if ($statusColumn !== null) {
                                    $bestStatus = isset($bestRow[$statusColumn]) ? (int)$bestRow[$statusColumn] : (isset($bestRow[strtoupper($statusColumn)]) ? (int)$bestRow[strtoupper($statusColumn)] : 0);
                                    $curStatus = isset($candidateRow[$statusColumn]) ? (int)$candidateRow[$statusColumn] : (isset($candidateRow[strtoupper($statusColumn)]) ? (int)$candidateRow[strtoupper($statusColumn)] : 0);
                                }
                                if ($bestStatus === -1 && $curStatus !== -1) {
                                    $bestRow = $candidateRow;
                                }
                            }
                            if (is_array($bestRow)) {
                                $existingRow = $bestRow;
                                $existingFound = true;
                            }
                        }
                    }
                }

                if ($existingFound) {
                    $updateData = $record;
                    if (isset($updateData['id'])) {
                        unset($updateData['id']);
                    }
                    if ($statusColumn !== null) {
                        $existingStatusRaw = $existingRow[$statusColumn] ?? ($existingRow[strtoupper($statusColumn)] ?? ($existingRow[strtolower($statusColumn)] ?? null));
                        if ($existingStatusRaw !== null && (string)$existingStatusRaw === '-1') {
                            // Upsert should revive soft-deleted rows instead of inserting duplicates.
                            $updateData[$statusColumn] = 1;
                        }
                    }
                    if (!$updateData) {
                        $processed++;
                        continue;
                    }
                    $setParts = [];
                    $updateBind = [];
                    $bindIdx = 1;
                    foreach ($updateData as $field => $value) {
                        if (!$this->isSafeFieldName((string)$field)) {
                            continue;
                        }
                        $ph = ':v' . $bindIdx++;
                        $setParts[] = $this->quoteField((string)$field) . ' = ' . $ph;
                        $updateBind[$ph] = $value;
                    }
                    if (!$setParts) {
                        throw new RuntimeException('no valid update fields');
                    }
                    $updateWhereSql = implode(' AND ', $upsertWhereParts);
                    if ($idColumn !== null && is_array($existingRow)) {
                        $existingId = $existingRow[$idColumn] ?? ($existingRow[strtoupper($idColumn)] ?? ($existingRow[strtolower($idColumn)] ?? null));
                        if ($existingId !== null && $existingId !== '') {
                            $updateWhereSql = $this->quoteField($idColumn) . ' = :w_id';
                            $updateBind[':w_id'] = $existingId;
                        } else {
                            foreach ($upsertWhereBind as $ph => $val) {
                                $updateBind[$ph] = $val;
                            }
                        }
                    } else {
                        foreach ($upsertWhereBind as $ph => $val) {
                            $updateBind[$ph] = $val;
                        }
                    }
                    $updateSql = sprintf(
                        'UPDATE %s SET %s WHERE %s',
                        $this->quoteTable($table),
                        implode(', ', $setParts),
                        $updateWhereSql
                    );
                    if (!isset($stmtCache['update'][$updateSql])) {
                        $stmtCache['update'][$updateSql] = $db->prepare($updateSql);
                    }
                    $updateStmt = $stmtCache['update'][$updateSql];
                    $updateStmt->execute($updateBind);
                    $updateStmt->closeCursor();
                    $updated++;
                    $processed++;
                    continue;
                }

                $insertCols = array_keys($record);
                $insertColSql = implode(', ', array_map([$this, 'quoteField'], $insertCols));
                $insertPhs = [];
                $insertBind = [];
                foreach ($insertCols as $idx => $field) {
                    $ph = ':i' . $idx;
                    $insertPhs[] = $ph;
                    $insertBind[$ph] = $record[$field];
                }
                $insertSql = sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    $this->quoteTable($table),
                    $insertColSql,
                    implode(', ', $insertPhs)
                );
                if (!isset($stmtCache['insert'][$insertSql])) {
                    $stmtCache['insert'][$insertSql] = $db->prepare($insertSql);
                }
                $insertStmt = $stmtCache['insert'][$insertSql];
                $insertStmt->execute($insertBind);
                $insertStmt->closeCursor();
                $inserted++;
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'row' => $excelRowNo,
                    'error' => str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')),
                ];
            }
        }

        return [
            'table' => $table,
            'input_mode' => $inputMode,
            'file_path' => $filePath,
            'processed' => $processed,
            'inserted' => $inserted,
            'updated' => $updated,
            'failed' => $failed,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    public function exportByFilter()
    {
        $data = Flight::request_data();
        $data = $this->mergeRequestPayload(is_array($data) ? $data : []);
        $user = $this->getAuthUserFromPayload($data);
        if (!$user) {
            json_string_response(['status' => 'error', 'message' => tp_msg_login()], 401);
            return;
        }

        try {
            $result = $this->exportByFilterData($data);
            json_string_response(['status' => 'ok', 'message' => dcim_msg('common.export_ok'), 'data' => $result]);
        } catch (\Throwable $e) {
            json_string_response([
                'status' => 'error',
                'message' => str_replace('{reason}', $e->getMessage(), dcim_msg('error.export_failed_with_reason')),
            ], 400);
        }
    }

    public function exportByFilterData(array $requestData, array $options = []): array
    {
        $tableInput = $options['table'] ?? ($requestData['table'] ?? $this->table);
        $table = $this->normalizeTableName((string) $tableInput);
        if ($table === null) {
            throw new InvalidArgumentException('table name required');
        }

        $targetCrud = new self($table);
        $parts = $targetCrud->buildFilterQueryParts($requestData, [
            'table' => $table,
            'allowed_fields' => $options['allowed_fields'] ?? [],
            'search_fields' => $options['search_fields'] ?? [],
            'base_conditions' => $options['base_conditions'] ?? [],
            'active_only' => !empty($options['active_only']),
            'not_deleted_only' => !empty($options['not_deleted_only']),
            'default_order' => $options['default_order'] ?? 'id',
            'default_sequence' => $options['default_sequence'] ?? 'DESC',
        ]);

        $availableColumns = $parts['columns'];
        $columnSet = $availableColumns ? array_fill_keys($availableColumns, true) : [];
        $requestedColumns = [];
        if (isset($requestData['columns'])) {
            $requestedColumns = $this->normalizeStringList($requestData['columns']);
        } elseif (isset($options['columns'])) {
            $requestedColumns = $this->normalizeStringList($options['columns']);
        }
        if (!$requestedColumns) {
            $requestedColumns = $availableColumns;
        }
        $exportColumns = [];
        foreach ($requestedColumns as $column) {
            if (!$this->isSafeFieldName($column)) {
                continue;
            }
            if ($columnSet && !isset($columnSet[$column])) {
                continue;
            }
            $exportColumns[] = $column;
        }
        if (!$exportColumns && $availableColumns) {
            $exportColumns = $availableColumns;
        }

        $selectSql = '*';
        if ($exportColumns) {
            $selectSql = implode(', ', array_map([$this, 'quoteField'], $exportColumns));
        }

        $orderBy = $parts['order_by'] ? ' ' . $parts['order_by'] : '';
        $tableSql = !empty($options['read_from_view']) ? $targetCrud->tableForRead() : $targetCrud->tableForSql;
        $exportDate = date('Ymd');
        $dirInfo = $this->resolveWritablePublicDir([
            '/exports/' . $exportDate,
            '/exports',
            '/',
            '/uploads/exports/' . $exportDate,
            '/uploads/exports',
            '/uploads',
        ]);
        $relativeDir = $dirInfo['relative'];
        $absoluteDir = $dirInfo['absolute'];

        $defaultName = $table . '_' . date('Ymd_His');
        $excelBase = $this->sanitizeFileName((string)($requestData['file_name'] ?? ($options['file_name'] ?? $defaultName)), $defaultName);
        $excelBase = preg_replace('/\.xlsx$/i', '', $excelBase);
        $zipBase = $this->sanitizeFileName((string)($requestData['zip_name'] ?? ($options['zip_name'] ?? $excelBase)), $excelBase);
        $zipBase = preg_replace('/\.zip$/i', '', $zipBase);

        $excelPath = $absoluteDir . '/' . $excelBase . '.xlsx';
        $zipPath = $absoluteDir . '/' . $zipBase . '.zip';

        $whereSql = $parts['where'];
        if (!empty($options['exclude_deleted_device'])) {
            $sourceDevField = $targetCrud->findColumnNameInsensitive($availableColumns, 'DevId')
                ?: $targetCrud->findColumnNameInsensitive($availableColumns, 'DevID')
                ?: $targetCrud->findColumnNameInsensitive($availableColumns, 'DeviceId')
                ?: $targetCrud->findColumnNameInsensitive($availableColumns, 'DeviceID');
            if ($sourceDevField !== null && $sourceDevField !== '') {
                $deviceCrud = new self('dcim-device');
                $deviceColumns = $deviceCrud->getTableColumns('dcim-device');
                $deviceIdField = $deviceCrud->findColumnNameInsensitive($deviceColumns, 'id') ?: 'id';
                $deviceStatusField = $deviceCrud->findColumnNameInsensitive($deviceColumns, 'status');
                $sourceDevFieldSql = $targetCrud->quoteField($sourceDevField);
                $deviceIdFieldSql = $deviceCrud->quoteField($deviceIdField);
                $deviceTableSql = $deviceCrud->tableForSql;
                $statusSql = '';
                if ($deviceStatusField !== null && $deviceStatusField !== '') {
                    $deviceStatusFieldSql = $deviceCrud->quoteField($deviceStatusField);
                    $statusSql = ' AND (d.' . $deviceStatusFieldSql . ' <> -1 OR d.' . $deviceStatusFieldSql . ' IS NULL)';
                }
                $whereSql .= ' AND EXISTS (SELECT 1 FROM ' . $deviceTableSql . ' d WHERE d.' . $deviceIdFieldSql . ' = ' . $sourceDevFieldSql . $statusSql . ')';
            }
        }
        if (!empty($options['exclude_deleted_person_by_dev_id'])) {
            $sourceDevField = $targetCrud->findColumnNameInsensitive($availableColumns, 'DevId')
                ?: $targetCrud->findColumnNameInsensitive($availableColumns, 'DevID')
                ?: $targetCrud->findColumnNameInsensitive($availableColumns, 'DeviceId')
                ?: $targetCrud->findColumnNameInsensitive($availableColumns, 'DeviceID');
            if ($sourceDevField !== null && $sourceDevField !== '') {
                $personCrud = new self('dcim-person');
                $personColumns = $personCrud->getTableColumns('dcim-person');
                $personIdField = $personCrud->findColumnNameInsensitive($personColumns, 'id') ?: 'id';
                $personStatusField = $personCrud->findColumnNameInsensitive($personColumns, 'status');
                $sourceDevFieldSql = $targetCrud->quoteField($sourceDevField);
                $personIdFieldSql = $personCrud->quoteField($personIdField);
                $personTableSql = $personCrud->tableForSql;
                $statusSql = '';
                if ($personStatusField !== null && $personStatusField !== '') {
                    $personStatusFieldSql = $personCrud->quoteField($personStatusField);
                    $statusSql = ' AND (p.' . $personStatusFieldSql . ' <> -1 OR p.' . $personStatusFieldSql . ' IS NULL)';
                }
                $whereSql .= ' AND EXISTS (SELECT 1 FROM ' . $personTableSql . ' p WHERE p.' . $personIdFieldSql . ' = ' . $sourceDevFieldSql . $statusSql . ')';
            }
        }
        $sql = sprintf('SELECT %s FROM %s WHERE %s%s', $selectSql, $tableSql, $whereSql, $orderBy);
        $stmt = Flight::db()->prepare($sql);
        foreach ($parts['params'] as $ph => $val) {
            $stmt->bindValue($ph, $val);
        }

        $forceCsv = !empty($options['force_csv']) || !empty($requestData['force_csv']);
        $csvBuilder = isset($options['csv_builder']) ? trim((string)$options['csv_builder']) : '';
        $streamCsvMode = $forceCsv && $csvBuilder === '';
        if ($streamCsvMode) {
            $csvPath = $absoluteDir . '/' . $excelBase . '.csv';
            $fp = @fopen($csvPath, 'wb');
            if (!is_resource($fp)) {
                throw new RuntimeException('failed to create export csv');
            }
            fwrite($fp, "\xEF\xBB\xBF");
            if ($exportColumns) {
                fputcsv($fp, $exportColumns);
            }
            $stmt->execute();
            $total = 0;
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (!$exportColumns) {
                    $exportColumns = array_keys($row);
                    fseek($fp, 3);
                    ftruncate($fp, 3);
                    fputcsv($fp, $exportColumns);
                }
                $line = [];
                foreach ($exportColumns as $column) {
                    $line[] = $row[$column] ?? '';
                }
                fputcsv($fp, $line);
                $total++;
            }
            fclose($fp);
            $stmt->closeCursor();
            return [
                'table' => $table,
                'total' => $total,
                'where' => $whereSql,
                'columns' => $exportColumns,
                'csv_file' => basename($csvPath),
                'csv_abs_path' => $csvPath,
                'csv_path' => $this->toPublicPath($csvPath),
                'file_abs_path' => $csvPath,
                'file_path' => $this->toPublicPath($csvPath),
            ];
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt->closeCursor();

        if (!$exportColumns && !empty($rows[0]) && is_array($rows[0])) {
            $exportColumns = array_keys($rows[0]);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('export');
        $sheet->fromArray($exportColumns, null, 'A1');
        $lineNo = 2;
        foreach ($rows as $row) {
            $line = [];
            foreach ($exportColumns as $column) {
                $line[] = $row[$column] ?? '';
            }
            $sheet->fromArray($line, null, 'A' . $lineNo);
            $lineNo++;
        }
        $writer = new Xlsx($spreadsheet);
        $writer->save($excelPath);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $zip = new ZipArchive();
        $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($openResult !== true) {
            throw new RuntimeException('failed to create export zip');
        }
        $zip->addFile($excelPath, basename($excelPath));
        $zip->close();

        return [
            'table' => $table,
            'total' => count($rows),
            'where' => $whereSql,
            'columns' => $exportColumns,
            'rows' => $rows,
            'info' => $rows,
            'excel_file' => basename($excelPath),
            'zip_file' => basename($zipPath),
            'excel_abs_path' => $excelPath,
            'zip_abs_path' => $zipPath,
            'excel_path' => $this->toPublicPath($excelPath),
            'zip_path' => $this->toPublicPath($zipPath),
        ];
    }

    /**
     * Fetch single row with simple conditions.
     * $conditions: array of [field, operator, value] or [field, value] (defaults to '=').
     */
    public function findOne(array $conditions)
    {
        if (empty($conditions)) {
            return null;
        }

        $whereParts = [];
        $params = [];
        foreach ($conditions as $idx => $cond) {
            if (!is_array($cond) || count($cond) < 2) {
                continue;
            }
            if (count($cond) === 2) {
                [$field, $value] = $cond;
                $operator = '=';
            } else {
                [$field, $operator, $value] = $cond;
            }
            if (!is_string($field) || !$this->isSafeFieldName($field)) {
                continue;
            }
            $placeholder = ':c' . $idx;
            $whereParts[] = sprintf('%s %s %s', $this->quoteField($field), $operator, $placeholder);
            $params[$placeholder] = $value;
        }
        if (!$whereParts) {
            return null;
        }

        $sql = sprintf('SELECT * FROM %s WHERE %s LIMIT 1', $this->tableForSql, implode(' AND ', $whereParts));
        try {
            $stmt = Flight::db()->prepare($sql);
            foreach ($params as $ph => $val) {
                $stmt->bindValue($ph, $val);
            }
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            $shouldFallbackFromLshToId = $this->isMissingColumnErrorForField($e, 'Lsh')
                && $this->containsConditionField($conditions, 'Lsh')
                && !$this->hasTableColumnInsensitive('Lsh')
                && $this->hasTableColumnInsensitive('id');
            if (!$shouldFallbackFromLshToId) {
                throw $e;
            }
            $fallbackConditions = $this->remapConditionField($conditions, 'Lsh', 'id');
            if ($fallbackConditions === $conditions) {
                throw $e;
            }
            return $this->findOne($fallbackConditions);
        }
    }

    /**
     * Fetch single column value.
     */
    public function findValue(array $conditions, string $field)
    {
        if ($field === '') {
            return null;
        }
        $row = $this->findOne($conditions);
        return $row[$field] ?? null;
    }

    /**
     * Select rows with raw condition fragment (already contains operators).
     */
    public function selectByRawCondition(string $conditionSql, string $orderBy = '', array $params = [])
    {
        $conditionSql = $this->normalizeRawSqlForDriver($conditionSql);
        $orderBy = $this->normalizeRawSqlForDriver($orderBy);
        $sql = sprintf('SELECT * FROM %s WHERE %s', $this->tableForSql, $conditionSql);
        if ($orderBy) {
            $sql .= ' ' . $orderBy;
        }
        [$sql, $bindParams] = $this->expandDuplicateNamedParams($sql, $params);
        $stmt = Flight::db()->prepare($sql);
        foreach ($bindParams as $ph => $val) {
            $stmt->bindValue($ph, $val);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Paginated select with raw condition fragment.
     */
    public function selectByRawConditionWithPagination(string $conditionSql, array $params = [], string $orderBy = '', int $page = 1, int $pageSize = 15)
    {
        return $this->selectWithPagination($conditionSql, $params, $orderBy, $page, $pageSize);
    }

    /**
     * Select rows by id list with optional field selection.
     */
    public function selectByIds(array $ids, array $fields = ['*'], string $idField = 'id')
    {
        $unique = [];
        foreach ($ids as $id) {
            if ($id === null) {
                continue;
            }
            $key = trim((string)$id);
            if ($key === '') {
                continue;
            }
            $unique[$key] = true;
        }
        $idValues = array_keys($unique);
        if (!$idValues) {
            return [];
        }

        $safeField = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $idField) ? $idField : 'id';
        $columnMap = $this->getTableColumnMapInsensitive($this->tableName);
        $resolveColumn = static function (string $name) use ($columnMap): string {
            $lookup = strtolower(trim($name));
            if ($lookup === '') {
                return '';
            }
            return isset($columnMap[$lookup]) ? (string)$columnMap[$lookup] : '';
        };
        $resolvedIdField = $resolveColumn($safeField);
        if ($resolvedIdField === '') {
            $resolvedIdField = $resolveColumn('id');
        }
        if ($resolvedIdField !== '') {
            $safeField = $resolvedIdField;
        } else {
            $safeField = 'id';
        }

        $fieldSql = '*';
        if ($fields && !(count($fields) === 1 && $fields[0] === '*')) {
            $parts = [];
            foreach ($fields as $field) {
                if (!is_string($field) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
                    continue;
                }
                $resolved = $resolveColumn($field);
                if ($resolved === '') {
                    continue;
                }
                $parts[] = $this->quoteField($resolved);
            }
            if ($parts) {
                $fieldSql = implode(',', $parts);
            }
        }

        $phs = [];
        $bind = [];
        foreach ($idValues as $idx => $value) {
            $ph = ':id_' . $idx;
            $phs[] = $ph;
            $bind[$ph] = $value;
        }

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s IN (%s)',
            $fieldSql,
            $this->tableForSql,
            $this->quoteField($safeField),
            implode(', ', $phs)
        );
        try {
            $stmt = Flight::db()->prepare($sql);
            foreach ($bind as $ph => $val) {
                $stmt->bindValue($ph, $val);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // Metadata can be unavailable in some runtime paths; fallback to id-only to avoid 500 on missing fields.
            $fallbackIdField = $resolveColumn('id');
            if ($fallbackIdField === '') {
                $fallbackIdField = $safeField;
            }
            $fallbackSql = sprintf(
                'SELECT %s FROM %s WHERE %s IN (%s)',
                $this->quoteField($fallbackIdField),
                $this->tableForSql,
                $this->quoteField($safeField),
                implode(', ', $phs)
            );
            try {
                $stmt = Flight::db()->prepare($fallbackSql);
                foreach ($bind as $ph => $val) {
                    $stmt->bindValue($ph, $val);
                }
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e2) {
                return [];
            }
        }
    }

    /**
     * Group by one field and return grouped counts.
     */
    public function countGroupBy(string $groupField, string $whereSql = '1=1', array $params = [], string $countField = 'id', string $orderBy = '')
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $groupField)) {
            return [];
        }
        if ($countField !== '*' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $countField)) {
            $countField = 'id';
        }
        $whereSql = $this->normalizeRawSqlForDriver($whereSql);
        $orderBy = $this->normalizeRawSqlForDriver($orderBy);
        $countExpr = $countField === '*' ? 'COUNT(*)' : ('COUNT(' . $this->quoteField($countField) . ')');
        $quotedGroupField = $this->quoteField($groupField);
        $sql = sprintf(
            'SELECT %s AS grp, %s AS cnt FROM %s WHERE %s GROUP BY %s',
            $quotedGroupField,
            $countExpr,
            $this->tableForSql,
            $whereSql,
            $quotedGroupField
        );
        if ($orderBy) {
            $sql .= ' ' . $orderBy;
        }
        [$sql, $bindParams] = $this->expandDuplicateNamedParams($sql, $params);
        $stmt = Flight::db()->prepare($sql);
        foreach ($bindParams as $ph => $val) {
            $stmt->bindValue($ph, $val);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count rows by raw condition fragment.
     */
    public function countByRawCondition(string $whereSql = '1=1', array $params = []): int
    {
        $whereSql = $this->normalizeRawSqlForDriver($whereSql);
        $sql = sprintf('SELECT COUNT(*) FROM %s WHERE %s', $this->tableForSql, $whereSql);
        [$sql, $bindParams] = $this->expandDuplicateNamedParams($sql, $params);
        $stmt = Flight::db()->prepare($sql);
        foreach ($bindParams as $ph => $val) {
            $stmt->bindValue($ph, $val);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Update by id with provided fields.
     */
    public function updateById($id, array $fields)
    {
        if (empty($fields)) {
            return false;
        }
        $dropFields = ['pageNo', 'pageSize', 'search', 'token'];
        $tableName = strtolower(str_replace(['`', '"'], '', (string) $this->table));
        if ($tableName === 'dcim-person' && array_key_exists('token', $fields)) {
            // Login flow writes session token into dcim-person.token.
            $dropFields = ['pageNo', 'pageSize', 'search'];
        }
        foreach ($dropFields as $drop) {
            if (array_key_exists($drop, $fields)) {
                unset($fields[$drop]);
            }
        }
        $fields = $this->filterColumns($fields);
        if (empty($fields)) {
            return false;
        }
        $setParts = [];
        $params = [];
        $paramTypes = [];
        $idx = 0;
        foreach ($fields as $key => $val) {
            if (!is_string($key) || !$this->isSafeFieldName($key)) {
                continue;
            }
            $ph = ':f_' . $idx++;
            $setParts[] = $this->quoteField($key) . " = {$ph}";
            $params[$ph] = $val;
            $paramTypes[$ph] = $this->resolveBindTypeForField($key, $val);
        }
        if (!$setParts) {
            return false;
        }
        $params[':id'] = $id;
        $paramTypes[':id'] = $this->resolveGenericBindType($id);
        $sql = sprintf('UPDATE %s SET %s WHERE %s = :id', $this->tableForSql, implode(', ', $setParts), $this->quoteField('id'));
        $stmt = Flight::db()->prepare($sql);
        foreach ($params as $ph => $val) {
            $type = isset($paramTypes[$ph]) ? (int)$paramTypes[$ph] : $this->resolveGenericBindType($val);
            $stmt->bindValue($ph, $val, $type);
        }
        return $stmt->execute();
    }

    /**
     * Update by arbitrary conditions.
     */
    public function updateWhere(array $conditions, array $fields)
    {
        if (empty($conditions) || empty($fields)) {
            return false;
        }
        $whereParts = [];
        $params = [];
        $paramTypes = [];
        $allowedOperators = [
            '=' => true,
            '!=' => true,
            '<>' => true,
            '>' => true,
            '>=' => true,
            '<' => true,
            '<=' => true,
            'LIKE' => true,
        ];
        foreach ($conditions as $idx => $cond) {
            if (!is_array($cond) || count($cond) < 2) {
                continue;
            }
            if (count($cond) === 2) {
                [$field, $value] = $cond;
                $operator = '=';
            } else {
                [$field, $operator, $value] = $cond;
            }
            if (!is_string($field) || !$this->isSafeFieldName($field)) {
                continue;
            }
            $operator = strtoupper(trim((string) $operator));
            if (!isset($allowedOperators[$operator])) {
                continue;
            }
            $ph = ':w' . $idx;
            $whereParts[] = sprintf('%s %s %s', $this->quoteField($field), $operator, $ph);
            $params[$ph] = $value;
            $paramTypes[$ph] = $this->resolveBindTypeForField($field, $value);
        }
        if (!$whereParts) {
            return false;
        }

        $fields = $this->filterColumns($fields);
        if (empty($fields)) {
            return false;
        }
        $setParts = [];
        $setIdx = 0;
        foreach ($fields as $key => $val) {
            if (!is_string($key) || !$this->isSafeFieldName($key)) {
                continue;
            }
            $ph = ':f_' . $setIdx++;
            $setParts[] = $this->quoteField($key) . " = {$ph}";
            $params[$ph] = $val;
            $paramTypes[$ph] = $this->resolveBindTypeForField($key, $val);
        }
        if (!$setParts) {
            return false;
        }

        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->tableForSql, implode(', ', $setParts), implode(' AND ', $whereParts));
        $stmt = Flight::db()->prepare($sql);
        foreach ($params as $ph => $val) {
            $type = isset($paramTypes[$ph]) ? (int)$paramTypes[$ph] : $this->resolveGenericBindType($val);
            $stmt->bindValue($ph, $val, $type);
        }
        return $stmt->execute();
    }

    /**
     * Hard-delete rows by raw condition fragment.
     * Use carefully and avoid broad conditions.
     */
    public function deleteByRawCondition(string $whereSql, array $params = []): bool
    {
        $whereSql = trim($whereSql);
        if ($whereSql === '' || $whereSql === '1=1') {
            return false;
        }
        $whereSql = $this->normalizeRawSqlForDriver($whereSql);
        $sql = sprintf('DELETE FROM %s WHERE %s', $this->tableForSql, $whereSql);
        [$sql, $bindParams] = $this->expandDuplicateNamedParams($sql, $params);
        $stmt = Flight::db()->prepare($sql);
        foreach ($bindParams as $ph => $val) {
            $stmt->bindValue($ph, $val);
        }
        return (bool) $stmt->execute();
    }

    /**
     * Insert data and return inserted id.
     */
    public function insert(array $data)
    {
        if (empty($data)) {
            return false;
        }
        foreach (['pageNo', 'pageSize', 'search', 'token'] as $drop) {
            if (array_key_exists($drop, $data)) {
                unset($data[$drop]);
            }
        }
        $data = $this->filterColumns($data);
        if (empty($data)) {
            return false;
        }
        $columns = [];
        $placeholders = [];
        $params = [];
        $paramTypes = [];
        $idx = 0;
        foreach ($data as $key => $val) {
            if (!is_string($key) || !$this->isSafeFieldName($key)) {
                continue;
            }
            $ph = ':v_' . $idx++;
            $columns[] = $this->quoteField($key);
            $placeholders[] = $ph;
            $params[$ph] = $val;
            $paramTypes[$ph] = $this->resolveBindTypeForField($key, $val);
        }
        if (!$columns) {
            return false;
        }
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->tableForSql, implode(', ', $columns), implode(', ', $placeholders));
        $stmt = Flight::db()->prepare($sql);
        foreach ($params as $ph => $val) {
            $type = isset($paramTypes[$ph]) ? (int)$paramTypes[$ph] : $this->resolveGenericBindType($val);
            $stmt->bindValue($ph, $val, $type);
        }
        $stmt->execute();
        return Flight::db()->lastInsertId();
    }

    private function filterColumns(array $data): array
    {
        static $cache = [];
        $cacheKey = strtolower($this->getDriverName() . '|' . $this->tableName);
        if (!isset($cache[$cacheKey]) || !is_array($cache[$cacheKey])) {
            $allowed = [];
            foreach ($this->getTableColumns($this->tableName) as $column) {
                $name = trim((string) $column);
                if ($name === '' || !$this->isSafeFieldName($name)) {
                    continue;
                }
                $allowed[strtolower($name)] = $name;
            }
            $cache[$cacheKey] = $allowed;
        }

        $allowed = $cache[$cacheKey];
        if (!$allowed) {
            return $data;
        }

        $filtered = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $lookup = strtolower(trim($key));
            if ($lookup === '' || !isset($allowed[$lookup])) {
                continue;
            }
            $filtered[$allowed[$lookup]] = $value;
        }

        return $filtered;
    }

    /**
     * Paginated select returning info + page structure.
     */
    public function selectWithPagination(string $whereSql = '1=1', array $params = [], string $orderBy = '', int $page = 1, int $pageSize = 15)
    {
        $combo = null;
        try {
            $combo = Flight::request_data('ComboBox', null);
        } catch (\Throwable $e) {
            $combo = null;
        }
        $comboAll = $combo !== null && $combo !== '';

        $page = $page > 0 ? $page : 1;
        $pageSize = $pageSize > 0 ? $pageSize : 15;
        $offset = ($page - 1) * $pageSize;

        $db = Flight::db();
        $whereSql = $this->normalizeRawSqlForDriver($whereSql);
        $orderBy = $this->normalizeRawSqlForDriver($orderBy);

        $total = 0;
        if (!$comboAll) {
            $countSql = sprintf('SELECT COUNT(*) FROM %s WHERE %s', $this->tableForSql, $whereSql);
            [$countSql, $countParams] = $this->expandDuplicateNamedParams($countSql, $params);
            $stmt = $db->prepare($countSql);
            foreach ($countParams as $ph => $val) {
                $stmt->bindValue($ph, $val);
            }
            $stmt->execute();
            $total = (int) $stmt->fetchColumn();
        }

        $sql = sprintf('SELECT * FROM %s WHERE %s', $this->tableForSql, $whereSql);
        if ($orderBy) {
            $sql .= ' ' . $orderBy;
        }
        if (!$comboAll) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        [$sql, $queryParams] = $this->expandDuplicateNamedParams($sql, $params);
        $stmt = $db->prepare($sql);
        foreach ($queryParams as $ph => $val) {
            $stmt->bindValue($ph, $val);
        }
        if (!$comboAll) {
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($comboAll) {
            $total = count($rows);
        }

        $pageInfo = [
            'total' => $total,
            'p_n'   => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
            'p'     => $page,
        ];

        return ['info' => $rows, 'page' => $pageInfo];
    }



    // Merged from CheckPlanController to reduce controller files.
private static function cpTryProxyLegacy(string $url, array $data): bool
    {
        try {
            $postBody = http_build_query($data);
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $postBody,
                    'timeout' => 3,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false || $raw === '') {
                return false;
            }
            $statusCode = null;
            $contentType = null;
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $hdr) {
                    if ($statusCode === null && preg_match('/^HTTP\/\S+\s+(\d{3})/i', $hdr, $m)) {
                        $statusCode = (int) $m[1];
                    }
                    if ($contentType === null && stripos($hdr, 'Content-Type:') === 0) {
                        $contentType = trim(substr($hdr, strlen('Content-Type:')));
                    }
                }
            }
            if ($statusCode !== null && $statusCode >= 400) {
                return false;
            }
            $trimmed = ltrim($raw);
            if ($trimmed !== '' && (
                stripos($trimmed, '<!DOCTYPE html') === 0 ||
                stripos($trimmed, '<html') === 0
            )) {
                return false;
            }
            if ($statusCode !== null) {
                $proto = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
                if (!headers_sent()) {
                    header($proto . ' ' . $statusCode, true, $statusCode);
                }
                http_response_code($statusCode);
            }
            if (!headers_sent()) {
                header('Content-Type: ' . ($contentType ?: 'text/html; charset=utf-8'));
            }
            echo $raw;
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function cpCrud(string $table)
    {
        return new CrudController($table);
    }

    private static function cpAuthCrud()
    {
        return new CrudController('dcim-person');
    }

    private static function cpRequireAuth(array $data = [])
    {
        $user = self::cpAuthCrud()->legacyEnsureAuth($data);
        if (!$user) {
            L_E();
        }
        return $user;
    }

    private static function cpPickAssetsForPlan(array $data): array
    {
        $assetsType = $data['AssetsType'] ?? 'A';
        $checkWay = $data['CheckWay'] ?? '';
        $checkRange = $data['CheckRange'] ?? null;

        $assetWhere = ['status = 1'];
        $assetParams = [];

        if ($assetsType === 'F') {
            $assetWhere[] = "AssetStatus = 'F'";
        } elseif ($assetsType === 'CF') {
            $assetWhere[] = "AssetStatus <> 'F'";
        }

        // Determine source table and additional filters.
        if ($checkWay === 'area' && $checkRange !== null) {
            $assetWhere[] = 'AreaId = :areaId';
            $assetParams[':areaId'] = $checkRange;
            try {
                $rows = self::cpCrud('dcim-assetputout-areaview')->selectByRawCondition(implode(' AND ', $assetWhere), '', $assetParams);
            } catch (\Throwable $e) {
                $rows = [];
            }
            if (!is_array($rows) || !$rows) {
                $rows = self::cpCrud('dcim-asset')->selectByRawCondition(implode(' AND ', $assetWhere), '', $assetParams);
            }
        } elseif ($checkWay === 'dept' && $checkRange !== null) {
            $persons = self::cpCrud('dcim-person')->selectByRawCondition('status = 1 AND DeptId = :dept', '', [':dept' => $checkRange]);
            if ($persons) {
                $phs = [];
                foreach ($persons as $idx => $person) {
                    $ph = ':pid' . $idx;
                    $phs[] = $ph;
                    $assetParams[$ph] = $person['id'];
                }
                $assetWhere[] = 'EmpId IN (' . implode(',', $phs) . ')';
            } else {
                return [];
            }
            $rows = self::cpCrud('dcim-asset')->selectByRawCondition(implode(' AND ', $assetWhere), '', $assetParams);
        } elseif ($checkWay === 'store' && $checkRange !== null) {
            $assetWhere[] = 'StoreLocationId = :sid';
            $assetParams[':sid'] = $checkRange;
            $rows = self::cpCrud('dcim-asset')->selectByRawCondition(implode(' AND ', $assetWhere), '', $assetParams);
        } else {
            $rows = self::cpCrud('dcim-asset')->selectByRawCondition(implode(' AND ', $assetWhere), '', $assetParams);
        }

        return $rows;
    }

    public static function infoAdd()
    {
        $data = Flight::request_data();
        $user = self::cpRequireAuth($data);

        $assets = self::cpPickAssetsForPlan($data);
        $assetIdsMap = [];
        foreach ($assets as $asset) {
            $aid = $asset['id'] ?? ($asset['AssetsId'] ?? null);
            if ($aid === null || $aid === '') {
                continue;
            }
            $assetIdsMap[(string)$aid] = true;
        }
        $assetIds = array_keys($assetIdsMap);

        $planData = [
            'TaskNumber'       => $data['TaskNumber'] ?? ('PD' . date('YmdHis')),
            'TaskName'         => $data['TaskName'] ?? '',
            'PlanComplateTime' => $data['PlanComplateTime'] ?? null,
            'AssetsType'       => $data['AssetsType'] ?? 'A',
            'DoEmpId'          => $data['DoEmpId'] ?? null,
            'CheckWay'         => $data['CheckWay'] ?? '',
            'CheckRange'       => $data['CheckRange'] ?? null,
            'SeePerson'        => $data['SeePerson'] ?? '',
            'TaskDescribe'     => $data['TaskDescribe'] ?? ($data['remark'] ?? ''),
            'PlanStatus'       => $data['PlanStatus'] ?? dcim_msg('crud.plan_status_pending'),
            'CreateEmpId'      => $user['id'] ?? null,
            'status'           => $data['status'] ?? 1,
        ];
        $planCrud = self::cpCrud('dcim-assetcheckplan');
        $planId = $planCrud->legacyInsert($planData);
        if (!$planId) {
            P_E(dcim_msg('error.create_check_plan_failed'));
        }

        $resultCrud = self::cpCrud('dcim-assetcheckresult');
        foreach ($assetIds as $aid) {
            $rid = $resultCrud->legacyInsert([
                'PlanId'      => $planId,
                'AssetsId'    => $aid,
                'CheckStatus' => dcim_msg('crud.check_status_pending'),
                'status'      => 1,
            ]);
            if (!$rid) {
                P_E(dcim_msg('error.create_check_plan_detail_failed'));
            }
        }

        O_E(['id' => $planId], tp_msg_success(), 100, false);
    }

    public static function getList()
    {
        $data = Flight::request_data();
        self::cpRequireAuth($data);

        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;

        $conditions = ['status = 1'];
        $params = [];
        if (!empty($data['search'])) {
            $conditions[] = '(TaskName LIKE :search OR TaskNumber LIKE :search)';
            $params[':search'] = '%' . $data['search'] . '%';
        }
        if (!empty($data['PlanStatus'])) {
            $conditions[] = 'PlanStatus = :planStatus';
            $params[':planStatus'] = $data['PlanStatus'];
        }
        if (!empty($data['startDateTime']) && !empty($data['endDateTime'])) {
            $conditions[] = 'DoTime BETWEEN :start AND :end';
            $params[':start'] = $data['startDateTime'];
            $params[':end'] = $data['endDateTime'];
        }

        $where = implode(' AND ', $conditions);
        try {
            $result = self::cpCrud('vw_asset_checkplan_list')->selectWithPagination($where, $params, 'ORDER BY id DESC', $page, $pageSize);
        } catch (Throwable $e) {
            error_log('[GetCheckPlanListKey] view query failed, fallback to legacy N+1 logic: ' . $e->getMessage());
            $crud = self::cpCrud('dcim-assetcheckplan');
            $result = $crud->selectWithPagination($where, $params, 'ORDER BY id DESC', $page, $pageSize);

            $areaCrud = self::cpCrud('dcim-area');
            $deptCrud = self::cpCrud('dcim-department');
            $storeCrud = self::cpCrud('dcim-store');
            $areaIds = [];
            $deptIds = [];
            $storeIds = [];

            foreach ($result['info'] as $row) {
                $checkRange = isset($row['CheckRange']) ? (string) $row['CheckRange'] : '';
                if ($checkRange === '') {
                    continue;
                }
                if (($row['CheckWay'] ?? '') === 'area') {
                    $areaIds[$checkRange] = true;
                } elseif (($row['CheckWay'] ?? '') === 'dept') {
                    $deptIds[$checkRange] = true;
                } elseif (($row['CheckWay'] ?? '') === 'store') {
                    $storeIds[$checkRange] = true;
                }
            }

            $areaMap = [];
            if (!empty($areaIds)) {
                $areaRows = $areaCrud->selectByIds(array_keys($areaIds), ['id', 'AreaName', 'status']);
                foreach ($areaRows as $item) {
                    if ((int)($item['status'] ?? 0) !== 1) {
                        continue;
                    }
                    $areaMap[(string)($item['id'] ?? '')] = $item['AreaName'] ?? '';
                }
            }

            $deptMap = [];
            if (!empty($deptIds)) {
                $deptRows = $deptCrud->selectByIds(array_keys($deptIds), ['id', 'DeptName', 'status']);
                foreach ($deptRows as $item) {
                    if ((int)($item['status'] ?? 0) !== 1) {
                        continue;
                    }
                    $deptMap[(string)($item['id'] ?? '')] = $item['DeptName'] ?? '';
                }
            }

            $storeMap = [];
            if (!empty($storeIds)) {
                $storeRows = $storeCrud->selectByIds(array_keys($storeIds), ['id', 'StoreLocationName', 'status']);
                foreach ($storeRows as $item) {
                    if ((int)($item['status'] ?? 0) !== 1) {
                        continue;
                    }
                    $storeMap[(string)($item['id'] ?? '')] = $item['StoreLocationName'] ?? '';
                }
            }

            foreach ($result['info'] as &$row) {
                $row['CheckRangeName'] = '';
                $checkRange = isset($row['CheckRange']) ? (string) $row['CheckRange'] : '';
                if (($row['CheckWay'] ?? '') === 'area') {
                    $row['CheckRangeName'] = $areaMap[$checkRange] ?? '';
                } elseif (($row['CheckWay'] ?? '') === 'dept') {
                    $row['CheckRangeName'] = $deptMap[$checkRange] ?? '';
                } elseif (($row['CheckWay'] ?? '') === 'store') {
                    $row['CheckRangeName'] = $storeMap[$checkRange] ?? '';
                }
            }
            unset($row);
        }

        $num = $result['info'] ? count($result['info']) : false;
        O_E($result, tp_msg_success(), 100, $num);
    }

    public static function getInfo()
    {
        $data = Flight::request_data();
        self::cpRequireAuth($data);
        $id = $data['id'] ?? ($data['planId'] ?? 0);
        if (!$id) {
            O_E(['CheckPlanData' => []], tp_msg_success(), 100, 0);
            return;
        }
        $crud = self::cpCrud('dcim-assetcheckplan');
        $plan = $crud->findOne([['id', '=', $id], ['status', '=', 1]]);
        if (!$plan) {
            O_E(['CheckPlanData' => []], tp_msg_success(), 100, 0);
            return;
        }

        $where = 'PlanId = :pid';
        $params = [':pid' => $id];
        if (!empty($data['CheckStatus'])) {
            $where .= ' AND CheckStatus LIKE :cstatus';
            $params[':cstatus'] = '%' . $data['CheckStatus'] . '%';
        }
        try {
            $details = self::cpCrud('vw_asset_check_result_detail')->selectByRawCondition($where, '', $params);
        } catch (Throwable $e) {
            error_log('[GetCheckPlanDetailKey] view query failed, fallback to legacy N+1 logic: ' . $e->getMessage());
            $resultCrud = self::cpCrud('dcim-assetcheckresult');
            $legacyWhere = 'status = 1 AND PlanId = :pid';
            $legacyParams = [':pid' => $id];
            if (!empty($data['CheckStatus'])) {
                $legacyWhere .= ' AND CheckStatus LIKE :cstatus';
                $legacyParams[':cstatus'] = '%' . $data['CheckStatus'] . '%';
            }
            $details = $resultCrud->selectByRawCondition($legacyWhere, '', $legacyParams);

            $assetIds = [];
            foreach ($details as $item) {
                $aid = isset($item['AssetsId']) ? (string) $item['AssetsId'] : '';
                if ($aid !== '') {
                    $assetIds[$aid] = true;
                }
            }

            $assetMap = [];
            if (!empty($assetIds)) {
                $assetRows = self::cpCrud('dcim-asset')->selectByIds(
                    array_keys($assetIds),
                    ['id', 'AssetsNumber', 'AssetsDescribe', 'AssetStatus', 'ModelId', 'status']
                );
                foreach ($assetRows as $item) {
                    if ((int)($item['status'] ?? 0) !== 1) {
                        continue;
                    }
                    $assetMap[(string)($item['id'] ?? '')] = $item;
                }
            }

            $modelIds = [];
            foreach ($assetMap as $asset) {
                $mid = isset($asset['ModelId']) ? (string) $asset['ModelId'] : '';
                if ($mid !== '') {
                    $modelIds[$mid] = true;
                }
            }

            $brandMap = [];
            if (!empty($modelIds)) {
                $brandRows = self::cpCrud('dcim-brandmodel')->selectByIds(
                    array_keys($modelIds),
                    ['id', 'BrandModel', 'AssetsTypeId']
                );
                foreach ($brandRows as $item) {
                    $brandMap[(string)($item['id'] ?? '')] = $item;
                }
            }

            $typeIds = [];
            foreach ($brandMap as $brand) {
                $tid = isset($brand['AssetsTypeId']) ? (string) $brand['AssetsTypeId'] : '';
                if ($tid !== '') {
                    $typeIds[$tid] = true;
                }
            }

            $typeMap = [];
            if (!empty($typeIds)) {
                $typeRows = self::cpCrud('dcim-assettype')->selectByIds(
                    array_keys($typeIds),
                    ['id', 'AssetsTypeName']
                );
                foreach ($typeRows as $item) {
                    $typeMap[(string)($item['id'] ?? '')] = $item;
                }
            }

            $cabinetUByAsset = [];
            if (!empty($assetIds)) {
                $inParams = [];
                $inPhs = [];
                $idx = 0;
                foreach (array_keys($assetIds) as $aid) {
                    $ph = ':aid_' . $idx;
                    $inPhs[] = $ph;
                    $inParams[$ph] = $aid;
                    $idx++;
                }
                $cuRows = self::cpCrud('dcim-cabinetu')->selectByRawCondition(
                    'status = 1 AND AssetsId IN (' . implode(', ', $inPhs) . ')',
                    'ORDER BY id ASC',
                    $inParams
                );
                foreach ($cuRows as $item) {
                    $aid = isset($item['AssetsId']) ? (string) $item['AssetsId'] : '';
                    if ($aid === '' || isset($cabinetUByAsset[$aid])) {
                        continue;
                    }
                    $cabinetUByAsset[$aid] = $item;
                }
            }

            $cabinetIds = [];
            foreach ($cabinetUByAsset as $item) {
                $cid = isset($item['CabinetId']) ? (string) $item['CabinetId'] : '';
                if ($cid !== '') {
                    $cabinetIds[$cid] = true;
                }
            }

            $cabinetMap = [];
            if (!empty($cabinetIds)) {
                $cabinetRows = self::cpCrud('dcim-cabinet')->selectByIds(
                    array_keys($cabinetIds),
                    ['id', 'position', 'column', 'status']
                );
                foreach ($cabinetRows as $item) {
                    if ((int)($item['status'] ?? 0) !== 1) {
                        continue;
                    }
                    $cabinetMap[(string)($item['id'] ?? '')] = $item;
                }
            }

            foreach ($details as &$row) {
                $asset = $assetMap[(string)($row['AssetsId'] ?? '')] ?? null;
                $brand = $asset ? ($brandMap[(string)($asset['ModelId'] ?? '')] ?? null) : null;
                $type = $brand ? ($typeMap[(string)($brand['AssetsTypeId'] ?? '')] ?? null) : null;

                $row['AssetsNumber'] = $asset['AssetsNumber'] ?? '';
                $row['AssetsDescribe'] = $asset['AssetsDescribe'] ?? '';
                $row['AssetStatus'] = $asset['AssetStatus'] ?? '';
                $row['BrandModel'] = $brand['BrandModel'] ?? '';
                $row['AssetsTypeName'] = $type['AssetsTypeName'] ?? '';
                $row['position'] = '';
                $row['column'] = '';
                $row['ULocation'] = '';

                if ($asset) {
                    $cabinetU = $cabinetUByAsset[(string)($asset['id'] ?? '')] ?? null;
                    if ($cabinetU) {
                        $cabinet = $cabinetMap[(string)($cabinetU['CabinetId'] ?? '')] ?? null;
                        $row['position'] = $cabinet['position'] ?? '';
                        $row['column'] = $cabinet['column'] ?? '';
                        $row['ULocation'] = $cabinetU['ULocation'] ?? '';
                    }
                }
            }
            unset($row);
        }

        $plan['CheckPlanData'] = $details;
        $num = $plan ? 1 : false;
        O_E($plan, tp_msg_success(), 100, $num);
    }

    public static function infoDel()
    {
        $data = Flight::request_data();
        self::cpRequireAuth($data);
        $res = self::cpCrud('dcim-assetcheckplan')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function CancelCheckPlan()
    {
        $data = Flight::request_data();
        self::cpRequireAuth($data);
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }
        $res = self::cpCrud('dcim-assetcheckplan')->legacyUpdate([
            'id' => $data['id'],
            'PlanStatus' => dcim_msg('crud.plan_status_cancelled'),
            'EndTime'    => date('Y-m-d H:i:s'),
        ], [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if (!$res) {
            P_E(dcim_msg('common.operation_failed'));
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function DoCheckPlan()
    {
        $data = Flight::request_data();
        self::cpRequireAuth($data);
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }
        $plan = self::cpCrud('dcim-assetcheckplan')->findOne([['id', '=', $data['id']], ['status', '=', 1]]);
        if ($plan && ($plan['PlanStatus'] ?? '') === dcim_msg('crud.plan_status_processing')) {
            O_E(true, tp_msg_success(), 100, 1);
            return;
        }
        $res = self::cpCrud('dcim-assetcheckplan')->legacyUpdate([
            'id' => $data['id'],
            'PlanStatus' => dcim_msg('crud.plan_status_processing'),
            'DoTime'     => date('Y-m-d H:i:s'),
        ], [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if (!$res) {
            P_E(dcim_msg('common.operation_failed'));
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function SubmitCheckPlan()
    {
        $data = Flight::request_data();
        self::cpRequireAuth($data);
        if (empty($data['PlanId'])) {
            P_E(dcim_msg('error.plan_id_required'));
        }
        $res = self::cpCrud('dcim-assetcheckplan')->legacyUpdate([
            'id' => $data['PlanId'],
            'PlanStatus' => $data['PlanStatus'] ?? dcim_msg('crud.plan_status_completed'),
            'EndTime'    => date('Y-m-d H:i:s'),
        ], [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('error.plan_id_required'),
        ]);
        if (!$res) {
            P_E(dcim_msg('common.operation_failed'));
        }
        if (!empty($data['CheckPlanData']) && is_array($data['CheckPlanData'])) {
            $resultCrud = self::cpCrud('dcim-assetcheckresult');
            foreach ($data['CheckPlanData'] as $row) {
                if (empty($row['DetailId'])) {
                    continue;
                }
                $update = [];
                if (isset($row['CheckStatus'])) {
                    $update['CheckStatus'] = $row['CheckStatus'];
                }
                if (array_key_exists('Remark', $row)) {
                    $update['remark'] = $row['Remark'];
                }
                if ($update) {
                    $update['id'] = $row['DetailId'];
                    $resultCrud->legacyUpdate($update, [
                        'skip_auth' => true,
                        'id_required_message' => dcim_msg('error.detail_id_required'),
                    ]);
                }
            }
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function AutoCheckPlan()
    {
        $data = Flight::request_data();
        self::cpRequireAuth($data);
            O_E(dcim_msg('error.auto_check_plan_unavailable'), dcim_msg('common.operation_failed'), 100, false);
    }

    public static function GetAssetCheckHisInfo()
    {
        $data = Flight::request_data();
        self::cpRequireAuth($data);
        if (empty($data['id'])) {
            O_E(false, dcim_msg('common.id_required'), 400, 0);
            return;
        }
        $planCrud = self::cpCrud('dcim-assetcheckplan');
        $plan = $planCrud->findOne([['id', '=', $data['id']], ['status', '=', 1]]);
        if (!$plan) {
            O_E([], tp_msg_success(), 100, false);
            return;
        }
        $doEmp = self::cpCrud('dcim-person')->findOne([['id', '=', $plan['DoEmpId'] ?? 0]]);

        try {
            $results = self::cpCrud('vw_asset_check_his_rows')->selectByRawCondition(
                'PlanId = :pid AND status = 1',
                '',
                [':pid' => $data['id']]
            );
        } catch (Throwable $e) {
            error_log('[GetAssetCheckHisInfoKey] view query failed, fallback to legacy N+1 logic: ' . $e->getMessage());
            $resultCrud = self::cpCrud('dcim-assetcheckresult');
            $results = $resultCrud->selectByRawCondition('PlanId = :pid AND status = 1', '', [':pid' => $data['id']]);
        }

        if (!empty($results)) {
            $needEnrich = false;
            foreach ($results as $item) {
                if (!isset($item['AssetsTypeId']) || !isset($item['ModelId']) || !isset($item['BrandModel']) || !isset($item['AssetsTypeName'])) {
                    $needEnrich = true;
                    break;
                }
            }
            if ($needEnrich) {
                $assetIds = [];
                foreach ($results as $item) {
                    $aid = isset($item['AssetsId']) ? trim((string) $item['AssetsId']) : '';
                    if ($aid !== '') {
                        $assetIds[$aid] = true;
                    }
                }

                $assetMap = [];
                if (!empty($assetIds)) {
                    $assetRows = self::cpCrud('dcim-asset')->selectByIds(
                        array_keys($assetIds),
                        ['id', 'ModelId']
                    );
                    foreach ($assetRows as $item) {
                        $aid = isset($item['id']) ? (string) $item['id'] : '';
                        if ($aid === '') {
                            continue;
                        }
                        $assetMap[$aid] = $item;
                    }
                }

                $modelIds = [];
                foreach ($assetMap as $asset) {
                    $mid = isset($asset['ModelId']) ? trim((string) $asset['ModelId']) : '';
                    if ($mid !== '') {
                        $modelIds[$mid] = true;
                    }
                }

                $modelMap = [];
                if (!empty($modelIds)) {
                    $modelRows = self::cpCrud('dcim-brandmodel')->selectByIds(
                        array_keys($modelIds),
                        ['id', 'BrandModel', 'AssetsTypeId']
                    );
                    foreach ($modelRows as $item) {
                        $mid = isset($item['id']) ? (string) $item['id'] : '';
                        if ($mid === '') {
                            continue;
                        }
                        $modelMap[$mid] = $item;
                    }
                }

                $typeIds = [];
                foreach ($modelMap as $model) {
                    $tid = isset($model['AssetsTypeId']) ? trim((string) $model['AssetsTypeId']) : '';
                    if ($tid !== '') {
                        $typeIds[$tid] = true;
                    }
                }

                $typeMap = [];
                if (!empty($typeIds)) {
                    $typeRows = self::cpCrud('dcim-assettype')->selectByIds(
                        array_keys($typeIds),
                        ['id', 'AssetsTypeName']
                    );
                    foreach ($typeRows as $item) {
                        $tid = isset($item['id']) ? (string) $item['id'] : '';
                        if ($tid === '') {
                            continue;
                        }
                        $typeMap[$tid] = $item;
                    }
                }

                foreach ($results as &$item) {
                    $aid = isset($item['AssetsId']) ? trim((string) $item['AssetsId']) : '';
                    $asset = $aid !== '' ? ($assetMap[$aid] ?? null) : null;
                    $mid = $asset ? trim((string) ($asset['ModelId'] ?? '')) : '';
                    $model = $mid !== '' ? ($modelMap[$mid] ?? null) : null;
                    $tid = $model ? trim((string) ($model['AssetsTypeId'] ?? '')) : '';
                    $type = $tid !== '' ? ($typeMap[$tid] ?? null) : null;

                    if (!isset($item['ModelId']) || $item['ModelId'] === '' || $item['ModelId'] === null) {
                        $item['ModelId'] = $mid;
                    }
                    if (!isset($item['BrandModel']) || $item['BrandModel'] === '' || $item['BrandModel'] === null) {
                        $item['BrandModel'] = $model['BrandModel'] ?? '';
                    }
                    if (!isset($item['AssetsTypeId']) || $item['AssetsTypeId'] === '' || $item['AssetsTypeId'] === null) {
                        $item['AssetsTypeId'] = $tid;
                    }
                    if (!isset($item['AssetsTypeName']) || $item['AssetsTypeName'] === '' || $item['AssetsTypeName'] === null) {
                        $item['AssetsTypeName'] = $type['AssetsTypeName'] ?? '';
                    }
                }
                unset($item);
            }
        }

        $total = count($results);
        $normal = 0;
        $nocheck = 0;
        $typeStats = [];
        $modelStats = [];

        $normalStatusSet = [dcim_msg('crud.check_status_normal'), dcim_msg('crud.check_status_normal_alt')];
        $nocheckStatusSet = [dcim_msg('crud.check_status_pending')];
        foreach ($results as $row) {
            $status = $row['CheckStatus'] ?? '';
            if (in_array($status, $normalStatusSet, true)) {
                $normal++;
            } elseif (in_array($status, $nocheckStatusSet, true)) {
                $nocheck++;
            }

            $typeId = $row['AssetsTypeId'] ?? null;
            $typeName = $row['AssetsTypeName'] ?? '';

            if ($typeId) {
                if (!isset($typeStats[$typeId])) {
                    $typeStats[$typeId] = [
                        'AssetsTypeName' => $typeName,
                        'total'          => 0,
                        'normal'         => 0,
                        'nocheck'        => 0,
                    ];
                }
                $typeStats[$typeId]['total']++;
                if (in_array($status, $normalStatusSet, true)) {
                    $typeStats[$typeId]['normal']++;
                } elseif (in_array($status, $nocheckStatusSet, true)) {
                    $typeStats[$typeId]['nocheck']++;
                }
            }

            $modelId = $row['ModelId'] ?? null;
            if ($modelId) {
                if (!isset($modelStats[$modelId])) {
                    $modelStats[$modelId] = [
                        'BrandModel' => $row['BrandModel'] ?? '',
                        'total'      => 0,
                        'normal'     => 0,
                        'nocheck'    => 0,
                    ];
                }
                $modelStats[$modelId]['total']++;
                if (in_array($status, $normalStatusSet, true)) {
                    $modelStats[$modelId]['normal']++;
                } elseif (in_array($status, $nocheckStatusSet, true)) {
                    $modelStats[$modelId]['nocheck']++;
                }
            }
        }

        foreach ($typeStats as &$row) {
            $row['abnormal'] = $row['total'] - $row['normal'] - $row['nocheck'];
        }
        unset($row);
        foreach ($modelStats as &$row) {
            $row['abnormal'] = $row['total'] - $row['normal'] - $row['nocheck'];
        }
        unset($row);

        $info = [
            'PlanComplateTime' => $plan['PlanComplateTime'] ?? '',
            'DoTime'           => $plan['DoTime'] ?? '',
            'DoEmpId'          => $plan['DoEmpId'] ?? null,
            'DoEmpPersonName'  => $doEmp['PersonName'] ?? '',
            'TotalNumber'      => $total,
            'NormalNumber'     => $normal,
            'NocheckNumber'    => $nocheck,
            'AbnormalNumber'   => $total - $normal - $nocheck,
            'assetstype'       => array_values($typeStats),
            'brandmodel'       => array_values($modelStats),
        ];

        $num = $info ? 1 : false;
        O_E($info, tp_msg_success(), 100, $num);
    }

    private static function cpOpsPlanCreate(string $table, array $data, array $options = []): ?int
    {
        return self::cpCrud($table)->legacyCreate($data, $options + [
            'defaults' => ['status' => 1],
        ]);
    }

    private static function cpOpsPlanList(string $table, array $data, array $options): ?array
    {
        return self::cpCrud($table)->legacyList($data, $options);
    }

    private static function cpOpsPlanInfo(string $table, array $data): ?array
    {
        $info = self::cpCrud($table)->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info) {
            return $info;
        }
        $id = $data['id'] ?? ($data['Lsh'] ?? null);
        if ($id === null || $id === '') {
            return $info;
        }
        $rows = self::cpCrud($table)->selectByRawCondition(
            'id = :id AND (status <> -1 OR status IS NULL)',
            'LIMIT 1',
            [':id' => $id]
        );
        return $rows ? ($rows[0] ?? null) : $info;
    }

    private static function cpEnrichSupplierNameRows(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $supplierIds = [];
        foreach ($rows as $row) {
            if (!empty($row['SupplierId'])) {
                $supplierIds[] = $row['SupplierId'];
            }
        }
        if (!$supplierIds) {
            foreach ($rows as &$row) {
                if (!isset($row['SupplierName'])) {
                    $row['SupplierName'] = '';
                }
            }
            unset($row);
            return $rows;
        }

        $supplierMap = [];
        foreach (self::cpCrud('dcim-supplier')->selectByIds($supplierIds, ['id', 'SupplierName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $supplierMap[$key] = $item;
            }
        }

        foreach ($rows as &$row) {
            $row['SupplierName'] = $supplierMap[(string)($row['SupplierId'] ?? '')]['SupplierName'] ?? '';
        }
        unset($row);
        return $rows;
    }

    private static function cpOpsPlanUpdate(string $table, array $data, array $options = [])
    {
        return self::cpCrud($table)->legacyUpdate($data, $options + [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
    }

    private static function cpOpsPlanDel(string $table, array $data, ?string $idRequiredMessage = null)
    {
        if ($idRequiredMessage === null || $idRequiredMessage === '') {
            $idRequiredMessage = dcim_msg('common.id_required');
        }
        return self::cpCrud($table)->legacySoftDelete($data, [
            'id_required_message' => $idRequiredMessage,
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
    }

    public static function opsPlanCreateWB(): void
    {
        $data = Flight::request_data();
        $id = self::cpOpsPlanCreate('dcim-wbrecord', $data);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function opsPlanGetWBList(): void
    {
        $data = Flight::request_data();
        $result = self::cpOpsPlanList('dcim-wbrecord', $data, [
            'base_where' => ['(status <> -1 OR status IS NULL)'],
            'exact_filters' => ['SupplierId' => 'SupplierId'],
            'between_filters' => [
                ['field' => 'create_time', 'start_key' => 'startDateTime', 'end_key' => 'endDateTime'],
            ],
            'search_fields' => ['WBPerson'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        if ($rows) {
            $supplierIds = [];
            $personIds = [];
            foreach ($rows as $row) {
                $sid = trim((string)($row['SupplierId'] ?? ($row['SupplierID'] ?? ($row['SupplierLsh'] ?? ''))));
                $hid = trim((string)($row['HandlerEmpId'] ?? ($row['HandlerId'] ?? ($row['HandlerUserId'] ?? ($row['EmpId'] ?? ($row['DoEmpId'] ?? ''))))));
                if ($sid !== '') {
                    $supplierIds[] = $sid;
                }
                if ($hid !== '') {
                    $personIds[] = $hid;
                }
            }
            $supplierMap = [];
            foreach (self::cpCrud('dcim-supplier')->selectByIds(array_values(array_unique($supplierIds)), ['id', 'SupplierName']) as $item) {
                $key = (string)($item['id'] ?? '');
                if ($key !== '') {
                    $supplierMap[$key] = (string)($item['SupplierName'] ?? '');
                }
            }
            $personMap = [];
            foreach (self::cpCrud('dcim-person')->selectByIds(array_values(array_unique($personIds)), ['id', 'PersonName']) as $item) {
                $key = (string)($item['id'] ?? '');
                if ($key !== '') {
                    $personMap[$key] = (string)($item['PersonName'] ?? '');
                }
            }
            foreach ($rows as &$row) {
                $sid = trim((string)($row['SupplierId'] ?? ($row['SupplierID'] ?? ($row['SupplierLsh'] ?? ''))));
                $hid = trim((string)($row['HandlerEmpId'] ?? ($row['HandlerId'] ?? ($row['HandlerUserId'] ?? ($row['EmpId'] ?? ($row['DoEmpId'] ?? ''))))));
                $row['SupplierName'] = $supplierMap[$sid] ?? ($row['SupplierName'] ?? '');
                $row['HandlerEmpName'] = $personMap[$hid] ?? ($row['HandlerEmpName'] ?? '');
            }
            unset($row);
            $result['info'] = $rows;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function opsPlanGetWBDetail(): void
    {
        $data = Flight::request_data();
        $info = self::cpOpsPlanInfo('dcim-wbrecord', $data);
        if ($info === null) {
            return;
        }
        if ($info) {
            $supplierId = trim((string)($info['SupplierId'] ?? ($info['SupplierID'] ?? ($info['SupplierLsh'] ?? ''))));
            $handlerEmpId = trim((string)($info['HandlerEmpId'] ?? ($info['HandlerId'] ?? ($info['HandlerUserId'] ?? ($info['EmpId'] ?? ($info['DoEmpId'] ?? ''))))));
            if ($supplierId !== '') {
                $supplier = self::cpCrud('dcim-supplier')->findOne([['id', '=', $supplierId]]) ?: [];
                $info['SupplierName'] = $supplier['SupplierName'] ?? ($info['SupplierName'] ?? '');
            }
            if ($handlerEmpId !== '') {
                $person = self::cpCrud('dcim-person')->findOne([['id', '=', $handlerEmpId]]) ?: [];
                $info['HandlerEmpName'] = $person['PersonName'] ?? ($info['HandlerEmpName'] ?? '');
            }
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function opsPlanChangeWB(): void
    {
        $data = Flight::request_data();
        $res = self::cpOpsPlanUpdate('dcim-wbrecord', $data);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function opsPlanDelWB(): void
    {
        $data = Flight::request_data();
        $res = self::cpOpsPlanDel('dcim-wbrecord', $data);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function opsPlanCreateXJModel(): void
    {
        $data = Flight::request_data();
        $id = self::cpOpsPlanCreate('dcim-xjmodel', $data, [
            'drop_fields' => ['XJEmpName'],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function opsPlanGetXJModelList(): void
    {
        $data = Flight::request_data();
        $result = self::cpOpsPlanList('dcim-xjmodel', $data, [
            'base_where' => ['status = 1'],
            'search_fields' => ['XJModelName'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $result['info'] = self::cpEnrichXJModelRows($rows);
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function opsPlanGetXJModelDetail(): void
    {
        $data = Flight::request_data();
        $info = self::cpOpsPlanInfo('dcim-xjmodel', $data);
        if ($info === null) {
            return;
        }
        if ($info) {
            $rows = self::cpEnrichXJModelRows([$info]);
            $info = $rows ? $rows[0] : $info;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function opsPlanChangeXJModel(): void
    {
        $data = Flight::request_data();
        $res = self::cpOpsPlanUpdate('dcim-xjmodel', $data, [
            'drop_fields' => ['XJEmpName'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function opsPlanDelXJModel(): void
    {
        $data = Flight::request_data();
        $res = self::cpOpsPlanDel('dcim-xjmodel', $data);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function opsPlanCreateMaintenance(): void
    {
        $data = Flight::request_data();
        $id = self::cpCrud('dcim-maintenance')->legacyCreate($data, [
            'required_fields' => [
                'SupplierId' => dcim_msg('error.supplier_id_required'),
            ],
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E($id ? true : false, tp_msg_success(), 100, $id ? 1 : false);
    }

    public static function opsPlanGetMaintenanceList(): void
    {
        $data = Flight::request_data();
        $result = self::cpOpsPlanList('dcim-maintenance', $data, [
            'base_where' => ['status = 1'],
            'exact_filters' => ['SupplierId' => 'SupplierId'],
            'between_filters' => [
                ['field' => 'create_time', 'start_key' => 'startDateTime', 'end_key' => 'endDateTime'],
            ],
            'search_fields' => ['MaintenanceName', 'SignPerson'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $rows = self::cpEnrichSupplierNameRows($rows);
        if ($rows) {
            $maintenanceIds = [];
            foreach ($rows as $row) {
                $mid = trim((string)($row['id'] ?? ''));
                if ($mid !== '') {
                    $maintenanceIds[$mid] = true;
                }
            }
            $relationMap = [];
            if ($maintenanceIds) {
                $holders = [];
                $params = [];
                $idx = 0;
                foreach (array_keys($maintenanceIds) as $mid) {
                    $ph = ':m_' . $idx++;
                    $holders[] = $ph;
                    $params[$ph] = $mid;
                }
                $assetRows = self::cpCrud('dcim-asset')->selectByRawCondition(
                    'status = 1 AND MaintenanceId IN (' . implode(', ', $holders) . ')',
                    '',
                    $params
                );
                foreach ($assetRows as $assetRow) {
                    $mid = trim((string)($assetRow['MaintenanceId'] ?? ''));
                    if ($mid === '') {
                        continue;
                    }
                    if (!isset($relationMap[$mid])) {
                        $relationMap[$mid] = 0;
                    }
                    $relationMap[$mid]++;
                }
            }
            foreach ($rows as &$row) {
                $mid = trim((string)($row['id'] ?? ''));
                $row['RelationNumber'] = (int)($relationMap[$mid] ?? 0);
            }
            unset($row);
        }
        $result['info'] = $rows;
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function opsPlanGetMaintenanceDetail(): void
    {
        $data = Flight::request_data();
        $info = self::cpOpsPlanInfo('dcim-maintenance', $data);
        if ($info === null) {
            return;
        }
        if ($info) {
            $rows = self::cpEnrichSupplierNameRows([$info]);
            $info = $rows ? $rows[0] : $info;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function opsPlanChangeMaintenance(): void
    {
        $data = Flight::request_data();
        $res = self::cpOpsPlanUpdate('dcim-maintenance', $data);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function opsPlanDelMaintenance(): void
    {
        $data = Flight::request_data();
        $res = self::cpOpsPlanDel('dcim-maintenance', $data);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function opsPlanCreateCapacityPlan(): void
    {
        $data = Flight::request_data();
        $user = self::cpRequireAuth($data);
        if (!isset($data['CreateEmpId']) && isset($user['id'])) {
            $data['CreateEmpId'] = $user['id'];
        }
        $id = self::cpOpsPlanCreate('dcim-capacityplan', $data);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function opsPlanGetCapacityPlanList(): void
    {
        $data = Flight::request_data();
        $result = self::cpOpsPlanList('dcim-capacityplan', $data, [
            'base_where' => ['status = 1'],
            'search_fields' => ['PlanName'],
            'between_filters' => [
                ['field' => 'create_time', 'start_key' => 'startDateTime', 'end_key' => 'endDateTime'],
            ],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $result['info'] = self::cpEnrichCapacityPlanRows($rows);
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function opsPlanGetCapacityPlanDetail(): void
    {
        $data = Flight::request_data();
        $info = self::cpOpsPlanInfo('dcim-capacityplan', $data);
        if ($info === null) {
            return;
        }
        if ($info) {
            $rows = self::cpEnrichCapacityPlanRows([$info]);
            $info = $rows ? $rows[0] : $info;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    private static function cpEnrichXJModelRows(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $personIds = [];
        $pointIds = [];
        foreach ($rows as $row) {
            if (!empty($row['XJEmpId'])) {
                foreach (explode(',', (string)$row['XJEmpId']) as $pid) {
                    $pid = trim($pid);
                    if ($pid !== '') {
                        $personIds[] = $pid;
                    }
                }
            }
            if (!empty($row['XJPointId'])) {
                foreach (explode(',', (string)$row['XJPointId']) as $ptid) {
                    $ptid = trim($ptid);
                    if ($ptid !== '') {
                        $pointIds[] = $ptid;
                    }
                }
            }
        }
        $personMap = [];
        foreach (self::cpCrud('dcim-person')->selectByIds($personIds, ['id', 'PersonName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $personMap[$key] = $item['PersonName'] ?? '';
            }
        }
        $pointMap = [];
        foreach (self::cpCrud('dcim-xjpoint')->selectByIds($pointIds, ['id', 'XJPointName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $pointMap[$key] = $item['XJPointName'] ?? '';
            }
        }
        foreach ($rows as &$row) {
            $empNames = [];
            foreach (explode(',', (string)($row['XJEmpId'] ?? '')) as $pid) {
                $pid = trim($pid);
                if ($pid !== '' && isset($personMap[$pid])) {
                    $empNames[] = (string)$personMap[$pid];
                }
            }
            if ($empNames) {
                $row['XJEmpName'] = implode(',', array_values(array_unique($empNames)));
            } else {
                $row['XJEmpName'] = $personMap[(string)($row['XJEmpId'] ?? '')] ?? ($row['XJEmpName'] ?? '');
            }

            $pointNames = [];
            foreach (explode(',', (string)($row['XJPointId'] ?? '')) as $ptid) {
                $ptid = trim($ptid);
                if ($ptid !== '' && isset($pointMap[$ptid])) {
                    $pointNames[] = (string)$pointMap[$ptid];
                }
            }
            if ($pointNames) {
                $row['XJPointName'] = implode(',', array_values(array_unique($pointNames)));
            } else {
                $row['XJPointName'] = $pointMap[(string)($row['XJPointId'] ?? '')] ?? ($row['XJPointName'] ?? '');
            }
        }
        unset($row);
        return $rows;
    }

    private static function cpEnrichCapacityPlanRows(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $serverIds = [];
        $areaIds = [];
        $personIds = [];
        foreach ($rows as $row) {
            if (!empty($row['ServerCode'])) {
                $serverIds[] = $row['ServerCode'];
            }
            if (!empty($row['AreaId'])) {
                $areaIds[] = $row['AreaId'];
            }
            if (!empty($row['CreateEmpId'])) {
                $personIds[] = $row['CreateEmpId'];
            }
        }
        $serverMap = [];
        foreach (self::cpCrud('dcim-server')->selectByIds($serverIds, ['id', 'ServerName', 'ServerCode']) as $item) {
            $idKey = (string)($item['id'] ?? '');
            if ($idKey !== '') {
                $serverMap[$idKey] = $item['ServerName'] ?? '';
            }
            $codeKey = (string)($item['ServerCode'] ?? '');
            if ($codeKey !== '' && !isset($serverMap[$codeKey])) {
                $serverMap[$codeKey] = $item['ServerName'] ?? '';
            }
        }
        $areaMap = [];
        foreach (self::cpCrud('dcim-area')->selectByIds($areaIds, ['id', 'AreaName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $areaMap[$key] = $item['AreaName'] ?? '';
            }
        }
        $personMap = [];
        foreach (self::cpCrud('dcim-person')->selectByIds($personIds, ['id', 'PersonName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $personMap[$key] = $item['PersonName'] ?? '';
            }
        }
        foreach ($rows as &$row) {
            $row['ServerName'] = $serverMap[(string)($row['ServerCode'] ?? '')] ?? ($row['ServerName'] ?? '');
            $row['AreaName'] = $areaMap[(string)($row['AreaId'] ?? '')] ?? ($row['AreaName'] ?? '');
            $row['CreateEmpName'] = $personMap[(string)($row['CreateEmpId'] ?? '')] ?? ($row['CreateEmpName'] ?? '');
        }
        unset($row);
        return $rows;
    }

    public static function opsPlanChangeCapacityPlan(): void
    {
        $data = Flight::request_data();
        $res = self::cpOpsPlanUpdate('dcim-capacityplan', $data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function opsPlanDelCapacityPlan(): void
    {
        $data = Flight::request_data();
        $res = self::cpOpsPlanDel('dcim-capacityplan', $data, dcim_msg('common.id_required'));
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    // ParamPlanController merged methods
    public static function paramPlanCreateParam(): void
    {
        $data = Flight::request_data();
        $id = self::cpCrud('dcim-param')->legacyCreate($data);
        if ($id === null) {
            return;
        }
        O_E($id ? true : false, tp_msg_success(), 100, $id ? 1 : false);
    }

    public static function paramPlanGetParamList(): void
    {
        $data = Flight::request_data();
        $result = self::cpCrud('dcim-param')->legacyList($data, [
            'skip_auth' => true,
            'base_where' => ['status = 1'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $combo = strtolower(trim((string)($data['ComboBox'] ?? '')));
        if ($combo === 'calc') {
            self::cpRunParamCalcTime();
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    private static function cpRunParamCalcTime(): void
    {
        $paramRows = self::cpCrud('dcim-param')->selectByRawCondition('status = 1', 'ORDER BY id ASC', []);
        foreach ($paramRows as $paramRow) {
            if (!is_array($paramRow)) {
                continue;
            }
            $calc = strtolower(trim((string)($paramRow['Calc'] ?? '')));
            if (in_array($calc, ['max', 'min', 'daymax', 'daymin'], true)) {
                continue;
            }

            $onenum = 0.0;
            $onetotal = 1;
            $twonum = 0.0;
            $calcres = 0.0;

            $paramIdOne = (string)($paramRow['ParamIdOne'] ?? '');
            if (strpos($paramIdOne, '{') > 0) {
                $onejson = self::cpDecodeLegacyJsonArray($paramIdOne);
                if ($onejson) {
                    $onetotal = count($onejson);
                    foreach ($onejson as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $devId = trim((string)($item['id'] ?? ''));
                        $paramKey = trim((string)($item['paramKey'] ?? ''));
                        if ($devId === '' || $paramKey === '') {
                            continue;
                        }
                        $oneRows = self::cpCrud('dcim-devicecommand')->selectByRawCondition(
                            'status = 1 AND DevID = :dev',
                            '',
                            [':dev' => $devId]
                        );
                        foreach ($oneRows as $oneRow) {
                            $payload = (string)($oneRow['LastReceiveData'] ?? '');
                            if ($payload === '') {
                                continue;
                            }
                            $parsed = self::cpParseLegacyParamPayload($payload);
                            if (!is_array($parsed) || !array_key_exists($paramKey, $parsed)) {
                                continue;
                            }
                            $rawVal = (string)$parsed[$paramKey];
                            $num = (float)explode('(', $rawVal)[0];
                            $onenum += $num;
                        }
                    }
                }
            } else {
                $onenumStr = (string)self::cpCrud('dcim-param')->findValue([['id', '=', $paramIdOne]], 'Result');
                $onenumStr = strpos($onenumStr, ',') > 0 ? str_replace(',', '', $onenumStr) : $onenumStr;
                $onenum = (float)$onenumStr;
            }

            $paramIdTwo = (string)($paramRow['ParamIdTwo'] ?? '');
            if (strpos($paramIdTwo, '{') > 0) {
                $twojson = self::cpDecodeLegacyJsonArray($paramIdTwo);
                if ($twojson && isset($twojson[0]) && is_array($twojson[0])) {
                    $devId = trim((string)($twojson[0]['id'] ?? ''));
                    $paramKey = trim((string)($twojson[0]['paramKey'] ?? ''));
                    if ($devId !== '' && $paramKey !== '') {
                        $twoRows = self::cpCrud('dcim-devicecommand')->selectByRawCondition(
                            'DevID = :dev',
                            '',
                            [':dev' => $devId]
                        );
                        foreach ($twoRows as $twoRow) {
                            $payload = (string)($twoRow['LastReceiveData'] ?? '');
                            if ($payload === '') {
                                continue;
                            }
                            $parsed = self::cpParseLegacyParamPayload($payload);
                            if (!is_array($parsed) || !array_key_exists($paramKey, $parsed)) {
                                continue;
                            }
                            $rawVal = (string)$parsed[$paramKey];
                            $twonum = (float)explode('(', $rawVal)[0];
                        }
                    }
                }
            } else {
                $twonumStr = (string)self::cpCrud('dcim-param')->findValue([['id', '=', $paramIdTwo]], 'Result');
                $twonumStr = strpos($twonumStr, ',') > 0 ? str_replace(',', '', $twonumStr) : $twonumStr;
                $twonum = (float)$twonumStr;
            }

            $onenum = $onenum ?: 0.0;
            $twonum = $twonum ?: 0.0;
            switch ($calc) {
                case '1+2':
                    $calcres = $onenum + $twonum;
                    break;
                case '1-2':
                    $calcres = $onenum - $twonum;
                    break;
                case '1:2':
                    $calcres = round($onenum / ($twonum == 0 ? 1 : $twonum), 2);
                    break;
                case '1*2':
                    $calcres = round($onenum * $twonum, 2);
                    break;
                case 'avg1':
                    $calcres = round($onenum / ($onetotal > 0 ? $onetotal : 1), 2);
                    break;
                case 'sum1':
                    $calcres = $onenum;
                    break;
                default:
                    continue 2;
            }

            self::cpCrud('dcim-param')->legacyUpdateWhere(
                [['id', '=', $paramRow['id'] ?? 0]],
                ['Result' => number_format($calcres, 2)],
                [
                    'skip_auth' => true,
                ]
            );
        }
    }

    private static function cpDecodeLegacyJsonArray(string $raw): array
    {
        $txt = trim($raw);
        if ($txt === '') {
            return [];
        }
        $decoded = json_decode($txt, true);
        if (!is_array($decoded)) {
            $decoded = json_decode(str_replace("'", '"', $txt), true);
        }
        return is_array($decoded) ? $decoded : [];
    }

    private static function cpParseLegacyParamPayload(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $raw = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (strlen($raw) >= 2 && (($raw[0] === '"' && substr($raw, -1) === '"') || ($raw[0] === '\'' && substr($raw, -1) === '\''))) {
            $unquoted = trim(substr($raw, 1, -1));
            $decoded = json_decode($unquoted, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            $decoded = json_decode(str_replace('\"', '"', $unquoted), true);
            if (is_array($decoded)) {
                return $decoded;
            }
            $raw = $unquoted;
        }
        $decoded = json_decode(str_replace("'", '"', $raw), true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [];
    }

    public static function paramPlanGetParamDetail(): void
    {
        $data = Flight::request_data();
        $info = self::cpCrud('dcim-param')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: null, tp_msg_success(), 100, $info ? 1 : 0);
    }

    public static function paramPlanChangeParam(): void
    {
        $data = Flight::request_data();
        $res = self::cpCrud('dcim-param')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function paramPlanDelParam(): void
    {
        $data = Flight::request_data();
        $res = self::cpCrud('dcim-param')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function paramPlanCreateCheckPlanModel(): void
    {
        $data = Flight::request_data();
        $id = self::cpCrud('dcim-assetcheckplanmodel')->legacyCreate($data, [
            'defaults' => ['status' => 1],
            'drop_fields' => ['DeptName', 'PersonName'],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function paramPlanGetCheckPlanModelList(): void
    {
        $data = Flight::request_data();
        $result = self::cpCrud('dcim-assetcheckplanmodel')->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => ['PlanName'],
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $deptIds = [];
        $empIds = [];
        foreach ($rows as $row) {
            if (!empty($row['DeptId'])) {
                $deptIds[] = $row['DeptId'];
            }
            if (!empty($row['EmpId'])) {
                $empIds[] = $row['EmpId'];
            }
        }
        $deptMap = [];
        foreach (self::cpCrud('dcim-department')->selectByIds($deptIds, ['id', 'DeptName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $deptMap[$key] = $item;
            }
        }
        $personMap = [];
        foreach (self::cpCrud('dcim-person')->selectByIds($empIds, ['id', 'PersonName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $personMap[$key] = $item;
            }
        }
        foreach ($rows as &$row) {
            $row['DeptName'] = $deptMap[(string)($row['DeptId'] ?? '')]['DeptName'] ?? '';
            $row['PersonName'] = $personMap[(string)($row['EmpId'] ?? '')]['PersonName'] ?? '';
        }
        unset($row);
        $result['info'] = $rows;
        O_E($result, tp_msg_success(), 100, false);
    }

    public static function paramPlanGetCheckPlanModelDetail(): void
    {
        $data = Flight::request_data();
        $info = self::cpCrud('dcim-assetcheckplanmodel')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if ($info) {
            $dept = self::cpCrud('dcim-department')->findOne([['id', '=', $info['DeptId'] ?? 0]]);
            $person = self::cpCrud('dcim-person')->findOne([['id', '=', $info['EmpId'] ?? 0]]);
            $info['DeptName'] = $dept['DeptName'] ?? '';
            $info['PersonName'] = $person['PersonName'] ?? '';
        }
        O_E($info ?: [], tp_msg_success(), 100, false);
    }

    public static function paramPlanChangeCheckPlanModel(): void
    {
        $data = Flight::request_data();
        $res = self::cpCrud('dcim-assetcheckplanmodel')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'drop_fields' => ['DeptName', 'PersonName'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, false);
    }

    public static function paramPlanDelCheckPlanModel(): void
    {
        $data = Flight::request_data();
        $res = self::cpCrud('dcim-assetcheckplanmodel')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E(true, tp_msg_success(), 100, false);
    }

    private static function compatCreateByTable(string $table, array $dropFields = []): void
    {
        $data = Flight::request_data();
        $id = self::cpCrud($table)->legacyCreate($data, [
            'defaults' => ['status' => 1],
            'drop_fields' => $dropFields,
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    private static function compatListByTable(string $table, array $searchFields = []): void
    {
        $data = Flight::request_data();
        $result = self::cpCrud($table)->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => $searchFields,
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    private static function compatDetailByTable(string $table): void
    {
        $data = Flight::request_data();
        $info = self::cpCrud($table)->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    private static function compatChangeByTable(string $table, array $dropFields = []): void
    {
        $data = Flight::request_data();
        $res = self::cpCrud($table)->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'drop_fields' => $dropFields,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    private static function compatDelByTable(string $table): void
    {
        $data = Flight::request_data();
        $res = self::cpCrud($table)->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function compatCreateCapacityNeed(): void
    {
        self::compatCreateByTable('dcim-capacityneed');
    }

    public static function compatGetCapacityNeedList(): void
    {
        self::compatListByTable('dcim-capacityneed', ['NeedName', 'NeedCode']);
    }

    public static function compatGetCapacityNeedDetail(): void
    {
        self::compatDetailByTable('dcim-capacityneed');
    }

    public static function compatChangeCapacityNeed(): void
    {
        self::compatChangeByTable('dcim-capacityneed');
    }

    public static function compatDelCapacityNeed(): void
    {
        self::compatDelByTable('dcim-capacityneed');
    }

    public static function compatCreateCapacityTable(): void
    {
        self::compatCreateByTable('dcim-capacitytable');
    }

    public static function compatGetCapacityTableList(): void
    {
        self::compatListByTable('dcim-capacitytable', ['TableName', 'TableCode']);
    }

    public static function compatGetCapacityTableDetail(): void
    {
        self::compatDetailByTable('dcim-capacitytable');
    }

    public static function compatChangeCapacityTable(): void
    {
        self::compatChangeByTable('dcim-capacitytable');
    }

    public static function compatDelCapacityTable(): void
    {
        self::compatDelByTable('dcim-capacitytable');
    }

    public static function compatCreateDeviceProtocolCtrl(): void
    {
        self::compatCreateByTable('dcim-deviceprotocolctrl');
    }

    public static function compatGetDeviceProtocolCtrlList(): void
    {
        self::compatListByTable('dcim-deviceprotocolctrl', ['ProtocolName', 'ProtocolCode']);
    }

    public static function compatGetDeviceProtocolCtrlDetail(): void
    {
        self::compatDetailByTable('dcim-deviceprotocolctrl');
    }

    public static function compatChangeDeviceProtocolCtrl(): void
    {
        self::compatChangeByTable('dcim-deviceprotocolctrl');
    }

    public static function compatDelDeviceProtocolCtrl(): void
    {
        self::compatDelByTable('dcim-deviceprotocolctrl');
    }

    public static function compatCreateDeviceProtocol(): void
    {
        self::compatCreateByTable('dcim-deviceprotocol');
    }

    public static function compatGetDeviceProtocolDetail(): void
    {
        self::compatDetailByTable('dcim-deviceprotocol');
    }

    public static function compatChangeDeviceProtocol(): void
    {
        self::compatChangeByTable('dcim-deviceprotocol');
    }

    public static function compatDelDeviceProtocol(): void
    {
        self::compatDelByTable('dcim-deviceprotocol');
    }

    public static function compatGetIndexParam(): void
    {
        self::compatDetailByTable('dcim-indexparam');
    }

    public static function compatChangeIndexParam(): void
    {
        self::compatChangeByTable('dcim-indexparam');
    }
}


