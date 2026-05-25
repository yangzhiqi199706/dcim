<?php

class WorkOrderController
{
    private const ONDUTY_LOG_STATUS_HANDOVER = 'workorder.onduty_status_handover';
    private const ONDUTY_LOG_STATUS_DRAFT = 'workorder.onduty_status_draft';

    private static function crud(string $table)
    {
        return new CrudController($table);
    }

    private static function requestData(): array
    {
        $raw = '';
        try {
            $request = Flight::request();
            if (is_object($request) && method_exists($request, 'getBody')) {
                $raw = (string)$request->getBody();
            }
        } catch (\Throwable $e) {
            $raw = '';
        }
        if ($raw === '') {
            $raw = (string)@file_get_contents('php://input');
        }
        $decoded = [];
        if ($raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $decoded = $json;
            }
        }

        $data = Flight::request_data();
        if (!is_array($data)) {
            $data = [];
        }
        if ($decoded) {
            $data = array_merge($decoded, $data);
        }

        // Compatibility: some clients wrap payload under data/params/payload.
        foreach (['data', 'params', 'payload'] as $wrapperKey) {
            if (!array_key_exists($wrapperKey, $data)) {
                continue;
            }
            $wrapped = $data[$wrapperKey];
            if (is_string($wrapped)) {
                $tmp = json_decode($wrapped, true);
                if (is_array($tmp)) {
                    $wrapped = $tmp;
                }
            }
            if (is_array($wrapped) && $wrapped) {
                $data = array_merge($wrapped, $data);
            }
        }

        if (is_array($_REQUEST) && $_REQUEST) {
            foreach ($_REQUEST as $k => $v) {
                if (!is_string($k) || array_key_exists($k, $data)) {
                    continue;
                }
                $data[$k] = $v;
            }
        }

        if (function_exists('dcim_extract_token')) {
            $token = (string)dcim_extract_token($data);
            if ($token !== '') {
                $data['token'] = $token;
            }
        }
        if (!isset($data['token']) || trim((string)$data['token']) === '') {
            foreach (['token', 'Token', 'auth', 'Authorization', 'authorization', 'x-auth-token'] as $tk) {
                $tv = isset($_REQUEST[$tk]) ? trim((string)$_REQUEST[$tk]) : '';
                if ($tv !== '') {
                    $data['token'] = $tv;
                    break;
                }
            }
        }

        // Normalize common legacy request keys to modern keys (priority: modern keys first).
        if (!isset($data['id']) && isset($data['Lsh'])) {
            $data['id'] = $data['Lsh'];
        }
        if (!isset($data['id'])) {
            foreach (['OrderId', 'orderId', 'order_id', 'YWOrderId', 'YWOrderLsh'] as $idKey) {
                if (isset($data[$idKey]) && trim((string)$data[$idKey]) !== '') {
                    $data['id'] = $data[$idKey];
                    break;
                }
            }
        }
        if (!isset($data['UserId']) && isset($data['UserLsh'])) {
            $data['UserId'] = $data['UserLsh'];
        }
        if (!isset($data['PlanId']) && isset($data['PlanLsh'])) {
            $data['PlanId'] = $data['PlanLsh'];
        }
        if (!isset($data['ExecuteEmpId'])) {
            foreach (['TransferEmpId', 'ToEmpId', 'TargetEmpId'] as $empKey) {
                if (isset($data[$empKey]) && trim((string)$data[$empKey]) !== '') {
                    $data['ExecuteEmpId'] = $data[$empKey];
                    break;
                }
            }
        }

        return $data;
    }

    private static function authCrud()
    {
        return new CrudController('dcim-person');
    }

    private static function resolveAuthUser(array $data = [], bool $allowSoftFallback = false): ?array
    {
        $resolvedToken = '';
        if (function_exists('dcim_extract_token')) {
            $resolvedToken = (string)dcim_extract_token($data);
        }
        if ($resolvedToken === '' && isset($data['token']) && trim((string)$data['token']) !== '') {
            $resolvedToken = trim((string)$data['token']);
        }
        if ($resolvedToken === '' && isset($data['Token']) && trim((string)$data['Token']) !== '') {
            $resolvedToken = trim((string)$data['Token']);
        }
        if ($resolvedToken === '') {
            try {
                $rawToken = Flight::request_token();
                if (is_string($rawToken) && trim($rawToken) !== '') {
                    $resolvedToken = trim($rawToken);
                }
            } catch (\Throwable $e) {
            }
        }
        if ($resolvedToken !== '') {
            $data['token'] = $resolvedToken;
        }
        $user = null;
        if ($resolvedToken !== '') {
            if (function_exists('dcim_auth_user_by_token')) {
                $user = dcim_auth_user_by_token($resolvedToken);
            }
            if (!$user) {
                try {
                    $user = self::authCrud()->findOne([['token', '=', $resolvedToken], ['status', '=', 1]]) ?: null;
                } catch (\Throwable $e) {
                    $user = null;
                }
            }
        }
        if ($user) {
            return $user;
        }

        $fallbackEmpId = 0;
        foreach (['UserId', 'user_id', 'UserLsh', 'EmpId', 'ExecuteEmpId', 'CreateEmpId', 'NoticeEmpId', 'DoEmpId'] as $k) {
            if (!isset($data[$k])) {
                continue;
            }
            $tmp = (int)$data[$k];
            if ($tmp > 0) {
                $fallbackEmpId = $tmp;
                break;
            }
        }
        if ($fallbackEmpId > 0) {
            $fallbackUser = self::authCrud()->findOne([['id', '=', $fallbackEmpId], ['status', '=', 1]]) ?: null;
            if (is_array($fallbackUser) && !empty($fallbackUser)) {
                return $fallbackUser;
            }
        }

        if (!$allowSoftFallback) {
            return null;
        }

        return ['id' => 0, 'PersonName' => dcim_msg('workorder.creator_system')];
    }

    private static function requireAuth(array $data = [])
    {
        $user = self::resolveAuthUser($data, false);
        if (!$user) {
            L_E();
        }
        return $user;
    }

    private static function softRequireAuth(array $data = [])
    {
        $user = self::resolveAuthUser($data, true);
        if (!$user) {
            return ['id' => 0, 'PersonName' => dcim_msg('workorder.creator_system')];
        }
        return $user;
    }

    private static function workAssetChangeLogColumns(): array
    {
        static $cols = null;
        if (is_array($cols)) {
            return $cols;
        }
        $cols = [];
        try {
            $stmt = Flight::db()->prepare('SHOW COLUMNS FROM `dcim-assetchangelog`');
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '') {
                    $cols[$field] = true;
                }
            }
        } catch (\Throwable $e) {
            $cols = [];
        }
        return $cols;
    }

    private static function repairChangeType($repairType): string
    {
        $repairType = strtoupper(trim((string)$repairType));
        if ($repairType === 'IR') {
            return dcim_msg('assets.asset_change.type.repair_ir');
        }
        if ($repairType === 'DI') {
            return dcim_msg('assets.asset_change.type.repair_di');
        }
        if ($repairType === 'FR') {
            return dcim_msg('assets.asset_change.type.repair_fr');
        }
        if ($repairType === 'DF') {
            return dcim_msg('assets.asset_change.type.repair_df');
        }
        return dcim_msg('workorder.asset_change_repair_create');
    }

    private static function writeAssetChangeLog($assetId, string $type, array $extra = [], $userId = null): void
    {
        $assetId = trim((string)$assetId);
        if ($assetId === '') {
            return;
        }
        $cols = self::workAssetChangeLogColumns();
        if (!$cols) {
            return;
        }
        $detail = '';
        if ($extra) {
            $detail = (string)json_encode($extra, JSON_UNESCAPED_UNICODE);
        }
        $changeType = $type;
        if ($type === dcim_msg('workorder.asset_change_repair_create')) {
            $changeType = self::repairChangeType($extra['RepairType'] ?? '');
        }
        $changeDescribe = '';
        if ($type === dcim_msg('workorder.asset_change_repair_create')) {
            $changeDescribe = dcim_msg('assets.asset_change.describe.repair', null, [
                'asset_id' => $assetId,
                'change_type' => $changeType,
            ]);
        }
        $candidate = [
            'AssetsId' => $assetId,
            'type' => $type,
            'ChangeType' => $changeType,
            'ChangeWay' => $changeType,
            'ChangeDescribe' => $changeDescribe,
            'Content' => $detail,
            'Detail' => $detail,
            'EmpId' => $userId,
            'CreateEmpId' => $userId,
            'status' => 1,
        ];
        $payload = [];
        foreach ($candidate as $field => $value) {
            if (!isset($cols[$field])) {
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $payload[$field] = $value;
        }
        if (isset($cols['status']) && !isset($payload['status'])) {
            $payload['status'] = 1;
        }
        if (!$payload) {
            return;
        }
        try {
            self::crud('dcim-assetchangelog')->legacyInsert($payload);
        } catch (\Throwable $e) {
            error_log('[repair_asset_change_log] write failed: ' . $e->getMessage());
        }
    }

    private static function createNumber(): string
    {
        $code = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S'];
        $yearIndex = (int)date('Y') - 2011;
        $prefix = $code[$yearIndex] ?? 'A';
        $monthHex = strtoupper(dechex((int)date('m')));
        return $prefix . $monthHex . date('d') . substr((string)time(), -5) . substr((string)microtime(), 2, 5) . sprintf('%02d', rand(0, 99));
    }

    private static function buildCreateMsg(array $orderRow, array $personRow): string
    {
        $orderName = $orderRow['OrderName'] ?? '';
        $orderNumber = $orderRow['OrderNumber'] ?? '';
        $faultType = $orderRow['FaultTypeName'] ?? '';
        $faultSubType = $orderRow['FaultSubTypeName'] ?? '';
        $sla = $orderRow['SLA'] ?? '';
        $empName = $orderRow['PersonName'] ?? '';
        $endDate = $orderRow['CompleteEndDate'] ?? '';
        $creator = (string)($personRow['PersonName'] ?? '');
        return dcim_msg('workorder.msg_order_created', null, [
            'creator' => $creator,
            'order_no' => (string)$orderNumber,
            'order_name' => (string)$orderName,
            'fault_type' => (string)$faultType,
            'fault_sub_type' => (string)$faultSubType,
            'handler' => (string)$empName,
            'sla' => (string)$sla,
            'deadline' => (string)$endDate,
        ]);
    }

    private static function normalizeCompareToken($value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($text, 'UTF-8');
        }
        return strtolower($text);
    }

    private static function isPlaceholderLikeText(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return true;
        }
        if (preg_match('/^[\?\x{FF1F}\s]+$/u', $trimmed) === 1) {
            return true;
        }
        if (preg_match('/[\x{E000}-\x{F8FF}\x{FFFD}]/u', $trimmed) === 1) {
            return true;
        }
        if (strpos($trimmed, '?') !== false && preg_match('/[A-Za-z0-9]/', $trimmed) !== 1) {
            return true;
        }
        return false;
    }

    private static function orderStatusAliasMap(): array
    {
        return [
            'pending' => [
                dcim_msg('app.order_need_todo_1'),
                dcim_msg('app.order_need_todo_2'),
                dcim_msg('app.order_need_todo_3'),
                'pending',
                'todo',
                'new',
                'wait',
                'waiting',
            ],
            'processing' => [
                dcim_msg('workorder.order_status_processing'),
                dcim_msg('app.order_processing_1'),
                dcim_msg('app.order_processing_2'),
                'processing',
                'dealing',
                'in_progress',
                'doing',
            ],
            'need_check' => [
                dcim_msg('app.order_need_check_1'),
                dcim_msg('app.order_need_check_2'),
                'check',
                'checking',
                'to_check',
                'need_check',
                'pending_check',
            ],
            'completed' => [
                dcim_msg('workorder.order_status_completed'),
                'completed',
                'complete',
                'done',
                'finished',
                'resolved',
            ],
            'terminated' => [
                dcim_msg('workorder.order_status_terminated'),
                'terminated',
                'closed',
                'cancelled',
                'canceled',
                'stopped',
            ],
        ];
    }

    private static function canonicalOrderStatusLabel(string $bucket): string
    {
        if ($bucket === 'processing') {
            return dcim_msg('workorder.order_status_processing');
        }
        if ($bucket === 'need_check') {
            return dcim_msg('app.order_need_check_1');
        }
        if ($bucket === 'completed') {
            return dcim_msg('workorder.order_status_completed');
        }
        if ($bucket === 'terminated') {
            return dcim_msg('workorder.order_status_terminated');
        }
        return dcim_msg('app.order_need_todo_1');
    }

    private static function detectOrderStatusBucket(string $status): string
    {
        $needle = self::normalizeCompareToken($status);
        if ($needle === '') {
            return '';
        }
        foreach (self::orderStatusAliasMap() as $bucket => $aliases) {
            foreach ($aliases as $alias) {
                if (self::normalizeCompareToken((string)$alias) === $needle) {
                    return (string)$bucket;
                }
            }
        }
        return '';
    }

    private static function inferOrderStatusByLifecycle(array $row): string
    {
        $checkTime = trim((string)($row['CheckTime'] ?? ''));
        $dealTime = trim((string)($row['DealTime'] ?? ''));
        $receiveTime = trim((string)($row['ReceiveTime'] ?? ''));
        if ($checkTime !== '') {
            $fromCheckResult = self::inferOrderStatusByCheckResult((string)($row['CheckResult'] ?? ''));
            if ($fromCheckResult !== '') {
                return $fromCheckResult;
            }
            return dcim_msg('workorder.order_status_completed');
        }
        if ($dealTime !== '') {
            return dcim_msg('app.order_need_check_1');
        }
        if ($receiveTime !== '') {
            return dcim_msg('workorder.order_status_processing');
        }
        return dcim_msg('app.order_need_todo_1');
    }

    private static function inferOrderStatusByCheckResult(string $checkResult): string
    {
        $needle = self::normalizeCompareToken($checkResult);
        if ($needle === '') {
            return '';
        }

        $completedAliases = [
            dcim_msg('workorder.check_passed'),
            dcim_msg('workorder.check_passed_alt1'),
            dcim_msg('workorder.check_passed_alt2'),
            'passed',
            'approved',
            'ok',
            'success',
        ];
        foreach ($completedAliases as $alias) {
            if (self::normalizeCompareToken($alias) === $needle) {
                return dcim_msg('workorder.order_status_completed');
            }
        }

        $processingAliases = [
            dcim_msg('workorder.check_rejected'),
            dcim_msg('workorder.check_rejected_alt'),
            'rejected',
            'reject',
            'back',
            'returned',
        ];
        foreach ($processingAliases as $alias) {
            if (self::normalizeCompareToken($alias) === $needle) {
                return dcim_msg('workorder.order_status_processing');
            }
        }

        $terminatedAliases = [
            dcim_msg('workorder.check_terminated'),
            dcim_msg('workorder.check_closed'),
            'terminated',
            'closed',
            'cancelled',
            'canceled',
        ];
        foreach ($terminatedAliases as $alias) {
            if (self::normalizeCompareToken($alias) === $needle) {
                return dcim_msg('workorder.order_status_terminated');
            }
        }

        return '';
    }

    private static function normalizeOrderStatusForOutput(array $row): string
    {
        $raw = trim((string)($row['OrderStatus'] ?? ''));
        $bucket = self::detectOrderStatusBucket($raw);
        if ($bucket !== '') {
            return self::canonicalOrderStatusLabel($bucket);
        }
        if (!self::isPlaceholderLikeText($raw)) {
            return $raw;
        }
        return self::inferOrderStatusByLifecycle($row);
    }

    private static function detectOrderTypeBucket(string $orderType): string
    {
        $needle = self::normalizeCompareToken($orderType);
        if ($needle === '') {
            return '';
        }
        $manualAliases = [
            dcim_msg('workorder.order_type_manual'),
            'manual',
            'manually',
            'human',
        ];
        foreach ($manualAliases as $alias) {
            if (self::normalizeCompareToken($alias) === $needle) {
                return 'manual';
            }
        }
        $autoAliases = [
            dcim_msg('workorder.order_type_auto'),
            'auto',
            'automatic',
            'system',
            'triggered',
        ];
        foreach ($autoAliases as $alias) {
            if (self::normalizeCompareToken($alias) === $needle) {
                return 'auto';
            }
        }
        $contains = static function (string $haystack, string $fragment): bool {
            if ($haystack === '' || $fragment === '') {
                return false;
            }
            if (function_exists('mb_strpos')) {
                return mb_strpos($haystack, $fragment, 0, 'UTF-8') !== false;
            }
            return strpos($haystack, $fragment) !== false;
        };
        if (
            $contains($needle, self::normalizeCompareToken(dcim_msg('workorder.order_type_manual'))) ||
            $contains($needle, '手动') ||
            $contains($needle, 'manual')
        ) {
            return 'manual';
        }
        if (
            $contains($needle, self::normalizeCompareToken(dcim_msg('workorder.order_type_auto'))) ||
            $contains($needle, '自动') ||
            $contains($needle, 'auto')
        ) {
            return 'auto';
        }
        return '';
    }

    private static function inferOrderTypeByRow(array $row): string
    {
        foreach (['AlarmId', 'AlarmID', 'alarmid', 'AlarmListId', 'AlarmLsh'] as $alarmField) {
            if (!array_key_exists($alarmField, $row)) {
                continue;
            }
            $raw = trim((string)($row[$alarmField] ?? ''));
            if ($raw !== '' && $raw !== '0') {
                return dcim_msg('workorder.order_type_auto');
            }
        }
        $createEmpId = (int)($row['CreateEmpId'] ?? 0);
        if ($createEmpId <= 0) {
            return dcim_msg('workorder.order_type_auto');
        }
        return dcim_msg('workorder.order_type_manual');
    }

    private static function normalizeOrderTypeForOutput(array $row): string
    {
        $raw = trim((string)($row['OrderType'] ?? ''));
        $bucket = self::detectOrderTypeBucket($raw);
        if ($bucket === 'manual') {
            return dcim_msg('workorder.order_type_manual');
        }
        if ($bucket === 'auto') {
            return dcim_msg('workorder.order_type_auto');
        }
        if (!self::isPlaceholderLikeText($raw)) {
            return $raw;
        }
        return self::inferOrderTypeByRow($row);
    }

    public static function infoAdd()
    {
        $data = self::requestData();
        $user = self::requireAuth($data);
        unset($data['token'], $data['FaultTypeId'], $data['PersonName']);
        $crud = self::crud('dcim-order');
        if (!isset($data['status'])) {
            $data['status'] = 1;
        }
        if (empty($data['OrderNumber'])) {
            $data['OrderNumber'] = 'GD' . self::createNumber();
        }
        if (empty($data['CreateEmpId'])) {
            $fallbackCreateEmp = $data['UserId'] ?? ($data['UserLsh'] ?? ($user['id'] ?? null));
            if (empty($fallbackCreateEmp)) {
                $fallbackCreateEmp = $data['EmpId'] ?? ($data['ExecuteEmpId'] ?? ($data['NoticeEmpId'] ?? 1));
            }
            $data['CreateEmpId'] = (int)$fallbackCreateEmp > 0 ? (int)$fallbackCreateEmp : 1;
        }
        if (empty($data['OrderType'])) {
            $data['OrderType'] = dcim_msg('workorder.order_type_manual');
        }
        if (empty($data['OrderStatus'])) {
            $data['OrderStatus'] = dcim_msg('app.order_need_todo_1');
        }
        if (empty($data['creator']) && empty($data['Creator'])) {
            $creatorName = (string)($user['PersonName'] ?? '');
            if ($creatorName === '' && !empty($data['CreateEmpId'])) {
                $person = self::crud('dcim-person')->findOne([['id', '=', $data['CreateEmpId']], ['status', '=', 1]]) ?: [];
                $creatorName = (string)($person['PersonName'] ?? '');
            }
            if ($creatorName === '') {
                $creatorName = dcim_msg('workorder.creator_system');
            }
            $data['creator'] = $creatorName;
            $data['Creator'] = $creatorName;
        }
        if (empty($data['CreateEmpName'])) {
            $createEmpName = (string)($data['creator'] ?? $data['Creator'] ?? '');
            if ($createEmpName === '') {
                $createEmpName = (string)($user['PersonName'] ?? '');
            }
            if ($createEmpName === '') {
                $createEmpName = dcim_msg('workorder.creator_system');
            }
            $data['CreateEmpName'] = $createEmpName;
        }
        // Keep creator aliases in sync for schema variants.
        $orderCols = [];
        try {
            $stmt = Flight::db()->prepare('SHOW COLUMNS FROM `dcim-order`');
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $field = (string)($col['Field'] ?? '');
                if ($field !== '') {
                    $orderCols[$field] = true;
                }
            }
        } catch (\Throwable $ignore) {
            $orderCols = [];
        }
        $creatorName = (string)($data['creator'] ?? $data['Creator'] ?? $data['CreateEmpName'] ?? '');
        if ($creatorName !== '') {
            foreach (['creator', 'Creator', 'CreateUser', 'CreateUserName', 'CreateName'] as $creatorField) {
                if (!$orderCols || isset($orderCols[$creatorField])) {
                    $data[$creatorField] = $creatorName;
                }
            }
        }
        $id = $crud->legacyInsert($data);
        try {
            $orderRow = [];
            try {
                $orderRow = self::crud('vw_order_create_context')->findOne([
                    ['id', '=', $id],
                ]) ?: [];
            } catch (\Throwable $e) {
                error_log('[CreateWorkOrderKey] view query failed, fallback to legacy SQL: ' . $e->getMessage());
                $baseOrder = self::crud('dcim-order')->findOne([
                    ['id', '=', $id],
                    ['status', '=', 1],
                ]) ?: [];
                if ($baseOrder) {
                    $orderRow = $baseOrder;
                    $faultSubTypeName = '';
                    $faultTypeName = '';
                    if (!empty($baseOrder['FaultSubTypeLsh'])) {
                        $subRow = self::crud('dcim-faultsubtype')->findOne([
                            ['id', '=', $baseOrder['FaultSubTypeLsh']],
                        ]) ?: [];
                        $faultSubTypeName = $subRow['FaultSubTypeName'] ?? '';
                        if (!empty($subRow['FaultTypeId'])) {
                            $typeRow = self::crud('dcim-faulttype')->findOne([
                                ['id', '=', $subRow['FaultTypeId']],
                            ]) ?: [];
                            $faultTypeName = (string)($typeRow['FaultTypeName'] ?? '');
                        }
                    }
                    $personName = '';
                    if (!empty($baseOrder['EmpId'])) {
                        $empRow = self::crud('dcim-person')->findOne([
                            ['id', '=', $baseOrder['EmpId']],
                        ]) ?: [];
                        $personName = (string)($empRow['PersonName'] ?? '');
                    }
                    $orderRow['FaultSubTypeName'] = $faultSubTypeName;
                    $orderRow['FaultTypeName'] = $faultTypeName;
                    $orderRow['PersonName'] = $personName;
                }
            }
            $personCrud = self::crud('dcim-person');
            $personRow = $personCrud->findOne([['id', '=', $user['id'] ?? 0], ['status', '=', 1]]) ?: [];
            if ($orderRow) {
                self::crud('dcim-orderrecord')->legacyInsert([
                    'OrderId' => $id,
                    'MsgCon' => self::buildCreateMsg($orderRow, $personRow),
                ]);
            }
            if (!empty($data['alarmid'])) {
                self::crud('dcim-alarmlist')->legacyUpdateWhere([
                    ['id', '=', $data['alarmid']],
                    ['status', '=', 1],
                ], [
                    'OrderNumber' => $data['OrderNumber'],
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[CreateWorkOrderKey] post insert failed: ' . $e->getMessage());
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    private static function workOrderEnrichRows(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $personIds = [];
        $faultSubTypeIds = [];
        $serverIds = [];
        $deviceIds = [];

        foreach ($rows as $row) {
            foreach (['EmpId', 'CreateEmpId', 'NoticeEmpId', 'ZhuanExecuteEmpId', 'CheckEmpId'] as $field) {
                $id = trim((string)($row[$field] ?? ''));
                if ($id !== '') {
                    $personIds[$id] = true;
                }
            }
            $faultSubTypeId = trim((string)($row['FaultSubTypeLsh'] ?? ($row['FaultSubTypeId'] ?? '')));
            if ($faultSubTypeId !== '') {
                $faultSubTypeIds[$faultSubTypeId] = true;
            }
            $serverId = trim((string)($row['ServerCode'] ?? ($row['ServerID'] ?? ($row['ServerId'] ?? ''))));
            if ($serverId !== '') {
                $serverIds[$serverId] = true;
            }
            foreach (['DeviceId', 'DevId', 'DevID'] as $devField) {
                $raw = trim((string)($row[$devField] ?? ''));
                if ($raw === '') {
                    continue;
                }
                foreach (array_filter(array_map('trim', explode(',', $raw)), static function ($v) {
                    return $v !== '';
                }) as $did) {
                    $deviceIds[$did] = true;
                }
            }
        }

        $personMap = [];
        foreach (self::crud('dcim-person')->selectByIds(array_keys($personIds), ['id', 'PersonName']) as $item) {
            $key = trim((string)($item['id'] ?? ''));
            if ($key !== '') {
                $personMap[$key] = (string)($item['PersonName'] ?? '');
            }
        }

        $faultSubTypeMap = [];
        $faultTypeIds = [];
        foreach (self::crud('dcim-faultsubtype')->selectByIds(array_keys($faultSubTypeIds), ['id', 'FaultSubTypeName', 'FaultTypeId']) as $item) {
            $sid = trim((string)($item['id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            $fid = trim((string)($item['FaultTypeId'] ?? ''));
            $faultSubTypeMap[$sid] = [
                'FaultSubTypeName' => (string)($item['FaultSubTypeName'] ?? ''),
                'FaultTypeId' => $fid,
            ];
            if ($fid !== '') {
                $faultTypeIds[$fid] = true;
            }
        }

        $faultTypeMap = [];
        foreach (self::crud('dcim-faulttype')->selectByIds(array_keys($faultTypeIds), ['id', 'FaultTypeName']) as $item) {
            $fid = trim((string)($item['id'] ?? ''));
            if ($fid !== '') {
                $faultTypeMap[$fid] = (string)($item['FaultTypeName'] ?? '');
            }
        }

        $serverMap = [];
        foreach (self::crud('dcim-server')->selectByIds(array_keys($serverIds), ['id', 'ServerCode', 'ServerName']) as $item) {
            $idKey = trim((string)($item['id'] ?? ''));
            $codeKey = trim((string)($item['ServerCode'] ?? ''));
            if ($idKey !== '') {
                $serverMap[$idKey] = (string)($item['ServerName'] ?? '');
            }
            if ($codeKey !== '') {
                $serverMap[$codeKey] = (string)($item['ServerName'] ?? '');
            }
        }

        $deviceMap = [];
        foreach (self::crud('dcim-device')->selectByIds(array_keys($deviceIds), ['id', 'DeviceName']) as $item) {
            $did = trim((string)($item['id'] ?? ''));
            if ($did !== '') {
                $deviceMap[$did] = (string)($item['DeviceName'] ?? '');
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $faultSubTypeId = trim((string)($row['FaultSubTypeLsh'] ?? ($row['FaultSubTypeId'] ?? '')));
            $faultSubType = $faultSubTypeMap[$faultSubTypeId] ?? ['FaultSubTypeName' => '', 'FaultTypeId' => ''];
            $faultTypeId = trim((string)($faultSubType['FaultTypeId'] ?? ''));
            $serverKey = trim((string)($row['ServerCode'] ?? ($row['ServerID'] ?? ($row['ServerId'] ?? ''))));
            $deviceNames = [];
            foreach (['DeviceId', 'DevId', 'DevID'] as $devField) {
                $raw = trim((string)($row[$devField] ?? ''));
                if ($raw === '') {
                    continue;
                }
                foreach (array_filter(array_map('trim', explode(',', $raw)), static function ($v) {
                    return $v !== '';
                }) as $did) {
                    if (isset($deviceMap[$did]) && $deviceMap[$did] !== '') {
                        $deviceNames[] = $deviceMap[$did];
                    }
                }
            }
            $out[] = [
                'id' => isset($row['id']) ? (int)$row['id'] : 0,
                'OrderNumber' => (string)($row['OrderNumber'] ?? ''),
                'OrderName' => (string)($row['OrderName'] ?? ''),
                'create_time' => (string)($row['create_time'] ?? ($row['CreateTime'] ?? '')),
                'OrderType' => self::normalizeOrderTypeForOutput($row),
                'OrderDescript' => (string)($row['OrderDescript'] ?? ($row['Content'] ?? ($row['Describe'] ?? ''))),
                'SLA' => (string)($row['SLA'] ?? ($row['Sla'] ?? ($row['ServiceLevel'] ?? ''))),
                'OrderStatus' => self::normalizeOrderStatusForOutput($row),
                'FaultSubTypeName' => (string)($faultSubType['FaultSubTypeName'] ?? ''),
                'FaultTypeId' => $faultTypeId !== '' ? (int)$faultTypeId : 0,
                'FaultTypeName' => (string)($faultTypeMap[$faultTypeId] ?? ''),
                'EmpName' => (string)($personMap[trim((string)($row['EmpId'] ?? ''))] ?? ''),
                'CreateEmpName' => (string)($personMap[trim((string)($row['CreateEmpId'] ?? ''))] ?? ($row['CreateEmpName'] ?? '')),
                'NoticeEmpName' => (string)($personMap[trim((string)($row['NoticeEmpId'] ?? ''))] ?? ''),
                'ZhuanExecuteEmpName' => (string)($personMap[trim((string)($row['ZhuanExecuteEmpId'] ?? ''))] ?? ''),
                'CheckEmpName' => (string)($personMap[trim((string)($row['CheckEmpId'] ?? ''))] ?? ''),
                'ServerName' => (string)($serverMap[$serverKey] ?? ''),
                'DeviceName' => $deviceNames ? implode(',', array_values(array_unique($deviceNames))) : '',
            ];
        }
        return $out;
    }

    public static function getList()
    {
        $data = self::requestData();
        self::requireAuth($data);

        $conditions = ['status = 1'];
        $params = [];
        if (!empty($data['search'])) {
            $conditions[] = '(OrderName LIKE :search OR OrderNumber LIKE :search)';
            $params[':search'] = '%' . $data['search'] . '%';
        }
        if (isset($data['OrderStatus']) && trim((string)$data['OrderStatus']) !== '') {
            $conditions[] = 'OrderStatus = :orderStatus';
            $params[':orderStatus'] = trim((string)$data['OrderStatus']);
        }
        $orderTypeBucket = self::detectOrderTypeBucket(trim((string)($data['OrderType'] ?? '')));
        $faultSubTypeId = trim((string)($data['FaultSubTypeId'] ?? ($data['FaultSubTypeLsh'] ?? '')));
        $faultTypeId = trim((string)($data['FaultTypeId'] ?? ''));
        $userId = trim((string)($data['UserLsh'] ?? ($data['UserId'] ?? '')));
        if ($userId !== '') {
            $conditions[] = '(EmpId = :uid1 OR ZhuanExecuteEmpId = :uid2)';
            $params[':uid1'] = $userId;
            $params[':uid2'] = $userId;
        }

        $page = isset($data['pageNo']) ? (int)$data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int)$data['pageSize'] : 15;
        $where = implode(' AND ', $conditions);
        if ($faultTypeId !== '' || $faultSubTypeId !== '' || $orderTypeBucket !== '') {
            $pickField = static function (array $row, array $keys): string {
                if (!$row) {
                    return '';
                }
                foreach ($keys as $k) {
                    if (array_key_exists($k, $row)) {
                        return trim((string)$row[$k]);
                    }
                }
                $lowerMap = [];
                foreach ($row as $k => $v) {
                    $lowerMap[strtolower((string)$k)] = $v;
                }
                foreach ($keys as $k) {
                    $lk = strtolower((string)$k);
                    if (array_key_exists($lk, $lowerMap)) {
                        return trim((string)$lowerMap[$lk]);
                    }
                }
                return '';
            };
            $normalizeId = static function (string $raw): string {
                $raw = trim($raw);
                if ($raw === '') {
                    return '';
                }
                if (preg_match('/^-?\d+(\.\d+)?$/', $raw) === 1) {
                    return (string)((int)$raw);
                }
                return $raw;
            };
            $allRows = self::crud('dcim-order')->selectByRawCondition($where, 'ORDER BY id DESC', $params);
            $allRows = is_array($allRows) ? $allRows : [];
            $enrichedRows = self::workOrderEnrichRows($allRows);
            $filtered = [];
            foreach ($allRows as $idx => $rawRow) {
                $rawRow = (array)$rawRow;
                $enriched = (isset($enrichedRows[$idx]) && is_array($enrichedRows[$idx])) ? $enrichedRows[$idx] : [];
                if ($faultSubTypeId !== '') {
                    $subTypeVal = $pickField($rawRow, ['FaultSubTypeLsh', 'FaultSubTypeId', 'FaultSubTypeID']);
                    if ($subTypeVal === '' && isset($enriched['FaultSubTypeId'])) {
                        $subTypeVal = trim((string)$enriched['FaultSubTypeId']);
                    }
                    if ($normalizeId($subTypeVal) !== $normalizeId($faultSubTypeId)) {
                        continue;
                    }
                }
                if ($faultTypeId !== '') {
                    $typeVal = trim((string)($enriched['FaultTypeId'] ?? ''));
                    if ($typeVal === '') {
                        $typeVal = $pickField($rawRow, ['FaultTypeId', 'FaultTypeID']);
                    }
                    if ($normalizeId($typeVal) !== $normalizeId($faultTypeId)) {
                        continue;
                    }
                }
                if ($orderTypeBucket !== '') {
                    $normalizedOrderType = self::normalizeOrderTypeForOutput($enriched ?: $rawRow);
                    $rowOrderTypeBucket = self::detectOrderTypeBucket($normalizedOrderType);
                    if ($rowOrderTypeBucket === '') {
                        $rowOrderTypeBucket = self::detectOrderTypeBucket(trim((string)($rawRow['OrderType'] ?? '')));
                    }
                    if ($rowOrderTypeBucket !== $orderTypeBucket) {
                        continue;
                    }
                }
                $filtered[] = $enriched ?: $rawRow;
            }
            $total = count($filtered);
            if ($page <= 0) {
                $page = 1;
            }
            if ($pageSize <= 0) {
                $pageSize = 15;
            }
            $offset = ($page - 1) * $pageSize;
            $pageRows = array_slice($filtered, $offset, $pageSize);
            O_E([
                'info' => $pageRows,
                'page' => [
                    'total' => $total,
                    'p_n' => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0,
                    'p' => $page,
                ],
            ], tp_msg_success(), 100, 0);
            return;
        }
        $result = self::crud('dcim-order')->selectWithPagination($where, $params, 'ORDER BY id DESC', $page, $pageSize);
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $result['info'] = self::workOrderEnrichRows($rows);
        O_E($result, tp_msg_success(), 100, 0);
    }

    public static function getInfo()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $id = $data['id'] ?? 0;
        $crud = self::crud('dcim-order');
        $rows = $crud->selectByRawCondition(
            'id = :id AND (status <> -1 OR status IS NULL)',
            'LIMIT 1',
            [':id' => $id]
        );
        $info = $rows ? ($rows[0] ?? null) : null;
        if (is_array($info)) {
            $enrichedRows = self::workOrderEnrichRows([$info]);
            $enriched = (is_array($enrichedRows) && isset($enrichedRows[0]) && is_array($enrichedRows[0])) ? $enrichedRows[0] : [];
            if ($enriched) {
                foreach ([
                    'FaultTypeId',
                    'FaultTypeName',
                    'FaultSubTypeName',
                    'EmpName',
                    'CreateEmpName',
                    'NoticeEmpName',
                    'ZhuanExecuteEmpName',
                    'CheckEmpName',
                    'ServerName',
                    'DeviceName',
                ] as $field) {
                    if (array_key_exists($field, $enriched)) {
                        $info[$field] = $enriched[$field];
                    }
                }
            }
            if (!isset($info['FaultTypeName'])) {
                $info['FaultTypeName'] = '';
            }
            if (!isset($info['FaultSubTypeName'])) {
                $info['FaultSubTypeName'] = '';
            }
            if (!isset($info['ZhuanExecuteEmpName'])) {
                $info['ZhuanExecuteEmpName'] = '';
            }
            $info['OrderStatus'] = self::normalizeOrderStatusForOutput($info);
            $info['OrderType'] = self::normalizeOrderTypeForOutput($info);
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function infoUpdate()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        $res = self::crud('dcim-order')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'drop_fields' => ['FaultTypeId', 'PersonName'],
        ]);
        if ($res === null) {
            return;
        }
        try {
            $orderId = $data['id'] ?? null;
            if (!empty($orderId)) {
                $order = self::crud('dcim-order')->findOne([['id', '=', $orderId], ['status', '=', 1]]) ?: [];
                $operator = self::crud('dcim-person')->findOne([['id', '=', $user['id'] ?? 0], ['status', '=', 1]]) ?: [];
                self::crud('dcim-orderrecord')->legacyInsert([
                    'OrderId' => $orderId,
                    'MsgCon' => dcim_msg('workorder.msg_order_updated', null, [
                        'order_no' => (string)($order['OrderNumber'] ?? ''),
                        'order_name' => (string)($order['OrderName'] ?? ''),
                        'operator' => (string)($operator['PersonName'] ?? ''),
                    ]),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[ChangetYWWorkOrderKey] post update record insert failed: ' . $e->getMessage());
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function TransferEmp()
    {
        $data = self::requestData();
        $user = self::softRequireAuth($data);
        $orderId = $data['id'] ?? ($data['Lsh'] ?? null);
        $executeEmpId = $data['ExecuteEmpId'] ?? ($data['EmpId'] ?? null);
        if (empty($orderId) || empty($executeEmpId)) {
            $personRows = self::crud('dcim-person')->selectByRawCondition(
                'status = 1',
                'ORDER BY id DESC'
            );
            $list = [];
            foreach ($personRows as $row) {
                $list[] = [
                    'id' => $row['id'] ?? '',
                    'PersonName' => $row['PersonName'] ?? '',
                    'DeptId' => $row['DeptId'] ?? '',
                    'Tel' => $row['Tel'] ?? '',
                ];
            }
            O_E($list, tp_msg_success(), 100, $list ? count($list) : 0);
            return;
        }

        $res = self::crud('dcim-order')->legacyUpdate([
            'id' => $orderId,
            'EmpId' => $executeEmpId,
            'ZhuanExecuteEmpId' => $executeEmpId,
            'ZhuanTime' => date('Y-m-d H:i:s'),
        ], [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['EmpId', 'ZhuanExecuteEmpId', 'ZhuanTime'],
        ]);
        if ($res === null) {
            return;
        }

        try {
            $order = self::crud('dcim-order')->findOne([
                ['id', '=', $orderId],
                ['status', '=', 1],
            ]) ?: [];
            $target = self::crud('dcim-person')->findOne([
                ['id', '=', $executeEmpId],
                ['status', '=', 1],
            ]) ?: [];
            $operator = self::crud('dcim-person')->findOne([
                ['id', '=', $user['id'] ?? 0],
                ['status', '=', 1],
            ]) ?: [];
            $operatorName = (string)($operator['PersonName'] ?? '');
            if ($operatorName === '') {
                $operatorName = (string)($user['PersonName'] ?? dcim_msg('workorder.creator_system'));
            }
            self::crud('dcim-orderrecord')->legacyInsert([
                'OrderId' => $orderId,
                'MsgCon' => dcim_msg('workorder.msg_order_transferred', null, [
                    'order_no' => (string)($order['OrderNumber'] ?? ''),
                    'order_name' => (string)($order['OrderName'] ?? ''),
                    'operator' => $operatorName,
                    'target' => (string)($target['PersonName'] ?? ''),
                    'time' => (string)($order['ZhuanTime'] ?? date('Y-m-d H:i:s')),
                ]),
            ]);
        } catch (\Throwable $e) {
            error_log('[GetTransferEmpKey] post update record insert failed: ' . $e->getMessage());
        }

        if (function_exists('addLog')) {
            @addLog(dcim_msg('workorder.log_transfer'));
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function ReceiveYWWorkOrder()
    {
        $data = self::requestData();
        $user = self::softRequireAuth($data);
        $orderId = $data['id'] ?? ($data['Lsh'] ?? null);
        if (empty($orderId)) {
            O_E(false, dcim_msg('common.id_required'), 100, 0);
            return;
        }
        $res = self::crud('dcim-order')->legacyUpdate([
            'id' => $orderId,
            'OrderStatus' => ($data['OrderStatus'] ?? '') !== '' ? $data['OrderStatus'] : dcim_msg('workorder.order_status_processing'),
            'ReceiveTime' => date('Y-m-d H:i:s'),
        ], [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['OrderStatus', 'ReceiveTime'],
        ]);
        if ($res === null) {
            return;
        }
        try {
            $order = self::crud('dcim-order')->findOne([['id', '=', $orderId], ['status', '=', 1]]) ?: [];
            $operator = self::crud('dcim-person')->findOne([['id', '=', $user['id'] ?? 0], ['status', '=', 1]]) ?: [];
            $operatorName = (string)($operator['PersonName'] ?? '');
            if ($operatorName === '') {
                $operatorName = (string)($user['PersonName'] ?? dcim_msg('workorder.creator_system'));
            }
            self::crud('dcim-orderrecord')->legacyInsert([
                'OrderId' => $orderId,
                'MsgCon' => dcim_msg('workorder.msg_order_received', null, [
                    'order_no' => (string)($order['OrderNumber'] ?? ''),
                    'order_name' => (string)($order['OrderName'] ?? ''),
                    'operator' => $operatorName,
                    'time' => (string)($order['ReceiveTime'] ?? date('Y-m-d H:i:s')),
                ]),
            ]);
        } catch (\Throwable $e) {
            error_log('[ReceiveYWWorkOrderKey] post update record insert failed: ' . $e->getMessage());
        }
        if (function_exists('addLog')) {
            @addLog(dcim_msg('workorder.log_receive'));
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function SubmitYWWorkOrder()
    {
        $data = self::requestData();
        $user = self::softRequireAuth($data);
        $id = $data['id'] ?? ($data['Lsh'] ?? null);
        if (empty($id)) {
            P_E(dcim_msg('common.id_required'));
        }
        $updateData = [
            'id' => $id,
            'OrderStatus' => $data['OrderStatus'] ?? null,
            'DealSituation' => $data['DealSituation'] ?? null,
            'DealTime' => date('Y-m-d H:i:s'),
            'OrderImg' => $data['OrderImg'] ?? null,
        ];
        $res = self::crud('dcim-order')->legacyUpdate($updateData, [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['OrderStatus', 'DealSituation', 'DealTime', 'OrderImg'],
        ]);
        if ($res === null) {
            return;
        }
        try {
            $order = self::crud('dcim-order')->findOne([['id', '=', $id], ['status', '=', 1]]) ?: [];
            $operator = self::crud('dcim-person')->findOne([['id', '=', $user['id'] ?? 0], ['status', '=', 1]]) ?: [];
            $operatorName = (string)($operator['PersonName'] ?? '');
            if ($operatorName === '') {
                $operatorName = (string)($user['PersonName'] ?? dcim_msg('workorder.creator_system'));
            }
            self::crud('dcim-orderrecord')->legacyInsert([
                'OrderId' => $id,
                'MsgCon' => dcim_msg('workorder.msg_order_submitted', null, [
                    'order_no' => (string)($order['OrderNumber'] ?? ''),
                    'order_name' => (string)($order['OrderName'] ?? ''),
                    'operator' => $operatorName,
                    'time' => (string)($order['DealTime'] ?? date('Y-m-d H:i:s')),
                    'result' => (string)($order['DealSituation'] ?? ''),
                ]),
            ]);
        } catch (\Throwable $e) {
            error_log('[SubmitYWWorkOrderKey] post update record insert failed: ' . $e->getMessage());
        }
        if (function_exists('addLog')) {
            @addLog(dcim_msg('workorder.log_submit'));
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function CheckYWWorkOrder()
    {
        $data = self::requestData();
        $user = self::softRequireAuth($data);
        $orderId = $data['id'] ?? ($data['Lsh'] ?? null);
        if (empty($orderId)) {
            O_E(false, dcim_msg('common.id_required'), 400, 0);
            return;
        }
        $checkResult = (string)($data['CheckResult'] ?? '');
        $orderStatus = (string)($data['OrderStatus'] ?? '');
        if ($orderStatus === '') {
            $orderStatus = dcim_msg('workorder.order_status_completed');
        }
        if (in_array($checkResult, [
            dcim_msg('workorder.check_passed'),
            dcim_msg('workorder.check_passed_alt1'),
            dcim_msg('workorder.check_passed_alt2'),
        ], true)) {
            $orderStatus = dcim_msg('workorder.order_status_completed');
        } elseif (in_array($checkResult, [
            dcim_msg('workorder.check_rejected'),
            dcim_msg('workorder.check_rejected_alt'),
        ], true)) {
            $orderStatus = dcim_msg('workorder.order_status_processing');
        } elseif (in_array($checkResult, [
            dcim_msg('workorder.check_terminated'),
            dcim_msg('workorder.check_closed'),
        ], true)) {
            $orderStatus = dcim_msg('workorder.order_status_terminated');
        }
        $res = self::crud('dcim-order')->legacyUpdate([
            'id' => $orderId,
            'CheckResult' => $checkResult,
            'CheckEmpId' => $user['id'] ?? 0,
            'CheckTime' => date('Y-m-d H:i:s'),
            'OrderStatus' => $orderStatus,
        ], [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['CheckResult', 'CheckEmpId', 'CheckTime', 'OrderStatus'],
        ]);
        if ($res === null) {
            return;
        }
        try {
            $order = self::crud('dcim-order')->findOne([['id', '=', $orderId], ['status', '=', 1]]) ?: [];
            $operator = self::crud('dcim-person')->findOne([['id', '=', $user['id'] ?? 0], ['status', '=', 1]]) ?: [];
            $operatorName = (string)($operator['PersonName'] ?? '');
            if ($operatorName === '') {
                $operatorName = (string)($user['PersonName'] ?? dcim_msg('workorder.creator_system'));
            }
            self::crud('dcim-orderrecord')->legacyInsert([
                'OrderId' => $orderId,
                'MsgCon' => dcim_msg('workorder.msg_order_checked', null, [
                    'order_no' => (string)($order['OrderNumber'] ?? ''),
                    'order_name' => (string)($order['OrderName'] ?? ''),
                    'operator' => $operatorName,
                    'time' => (string)($order['CheckTime'] ?? date('Y-m-d H:i:s')),
                    'result' => (string)$checkResult,
                ]),
            ]);
        } catch (\Throwable $e) {
            error_log('[CheckYWWorkOrderKey] post update record insert failed: ' . $e->getMessage());
        }
        if (function_exists('addLog')) {
            @addLog(dcim_msg('workorder.log_check'));
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    private static function xjTryProxyLegacy(string $url, array $data): bool
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
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function xjPointCreateImg(string $text)
    {
        $libPath = dirname(__DIR__, 2) . '/extend/phpqrcode/phpqrcode.php';
        if (!file_exists($libPath)) {
            return '';
        }
        require_once $libPath;
        $relativeDir = './qrcode';
        $absDir = dirname(__DIR__, 2) . '/qrcode';
        if (!is_dir($absDir)) {
            mkdir($absDir, 0777, true);
        }
        $filename = $absDir . '/' . time() . rand(10000, 9999999) . '.png';
        \QRcode::png($text, $filename, 'L', 5, 2);
        return $relativeDir . '/' . basename($filename);
    }

    private static function xjTaskNormalizeRow(array $row, bool $withEndEmpName): array
    {
        $normalized = [
            'id' => isset($row['id']) ? (int)$row['id'] : 0,
            'XJTaskNumber' => (string)($row['XJTaskNumber'] ?? ''),
            'XJTaskName' => (string)($row['XJTaskName'] ?? ''),
            'XJModelId' => (string)($row['XJModelId'] ?? ''),
            'XJModelName' => (string)($row['XJModelName'] ?? ''),
            'XJEmpId' => (string)($row['XJEmpId'] ?? ''),
            'XJEmpName' => (string)($row['XJEmpName'] ?? ''),
            'CreateEmpId' => (string)($row['CreateEmpId'] ?? ''),
            'CreateEmpName' => (string)($row['CreateEmpName'] ?? ''),
            'XJPlanComplateTime' => (string)($row['XJPlanComplateTime'] ?? ''),
            'XJDescribe' => (string)($row['XJDescribe'] ?? ''),
            'XJStatus' => (string)($row['XJStatus'] ?? ''),
            'EndEmpId' => ($row['EndEmpId'] ?? null) === null ? null : (string)($row['EndEmpId'] ?? ''),
            'EndRemark' => (string)($row['EndRemark'] ?? ''),
            'AnalysisResult' => (string)($row['AnalysisResult'] ?? ''),
            'EndTime' => ($row['EndTime'] ?? null) === null ? null : (string)($row['EndTime'] ?? ''),
            'system' => (string)($row['system'] ?? ($row['System'] ?? ($row['ServerName'] ?? ''))),
            'status' => isset($row['status']) ? (int)$row['status'] : 1,
            'create_time' => (string)($row['create_time'] ?? ''),
        ];
        if ($withEndEmpName) {
            $normalized['EndEmpName'] = (string)($row['EndEmpName'] ?? '');
        }
        return $normalized;
    }

    private static function xjTaskParseDeviceIdsByModel($modelId): array
    {
        $modelId = trim((string)$modelId);
        if ($modelId === '') {
            return [];
        }
        $model = self::crud('dcim-xjmodel')->findOne([['id', '=', $modelId]]) ?: [];
        if (!$model) {
            return [];
        }

        $rawParam = trim((string)($model['XJPointParamId'] ?? ''));
        if ($rawParam === '') {
            $pointId = trim((string)($model['XJPointId'] ?? ''));
            if ($pointId !== '') {
                $point = self::crud('dcim-xjpoint')->findOne([['id', '=', $pointId]]) ?: [];
                $rawParam = trim((string)($point['XJPointParamId'] ?? ''));
            }
        }
        if ($rawParam === '') {
            return [];
        }
        $items = array_values(array_filter(array_map('trim', explode(',', $rawParam)), static function ($v) {
            return $v !== '';
        }));
        if (!$items) {
            $items = [$rawParam];
        }
        return array_values(array_unique($items));
    }

    private static function xjTaskFetchCommandRowsByDevice(string $deviceId): array
    {
        $deviceId = trim($deviceId);
        if ($deviceId === '') {
            return [];
        }
        $crud = self::crud('dcim-devicecommand');
        $devFields = ['DevID', 'DevId', 'DeviceID', 'DeviceId'];
        $whereCandidates = [];
        foreach ($devFields as $devField) {
            $whereCandidates[] = $devField . ' = :did AND (CommandType <> 2 OR CommandType IS NULL) AND (status <> -1 OR status IS NULL)';
            $whereCandidates[] = $devField . ' = :did AND (CommandType <> 2 OR CommandType IS NULL)';
            $whereCandidates[] = $devField . ' = :did';
        }
        foreach ($whereCandidates as $where) {
            try {
                $rows = $crud->selectByRawCondition($where, '', [':did' => $deviceId]);
                if (is_array($rows) && !empty($rows)) {
                    return $rows;
                }
            } catch (\Throwable $e) {
            }
        }
        return [];
    }

    private static function xjTaskCollectParamNames(array $commandRows): array
    {
        $names = [];
        foreach ($commandRows as $row) {
            $raw = trim((string)($row['LastReceiveData'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $decoded = json_decode(str_replace("'", '"', $raw), true);
            }
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $k => $_v) {
                $name = trim((string)$k);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }
        return array_keys($names);
    }

    public static function xjTaskInfoAdd()
    {
        $data = self::requestData();
        $user = self::requireAuth($data);
        $modelId = trim((string)($data['XJModelId'] ?? ''));
        if ($modelId === '') {
            P_E(dcim_msg('common.param_missing'));
        }

        $taskPayload = [
            'XJTaskNumber' => trim((string)($data['XJTaskNumber'] ?? '')) !== '' ? trim((string)$data['XJTaskNumber']) : ('XJ' . self::createNumber()),
            'XJTaskName' => (string)($data['XJTaskName'] ?? ''),
            'XJModelId' => $modelId,
            'XJEmpId' => (string)($data['XJEmpId'] ?? ''),
            'XJPlanComplateTime' => (string)($data['XJPlanComplateTime'] ?? ''),
            'XJDescribe' => (string)($data['XJDescribe'] ?? ''),
            'CreateEmpId' => (string)($user['id'] ?? ($data['CreateEmpId'] ?? 1)),
            'status' => 1,
        ];
        if (isset($data['XJStatus']) && trim((string)$data['XJStatus']) !== '') {
            $taskPayload['XJStatus'] = (string)$data['XJStatus'];
        }

        $deviceIds = self::xjTaskParseDeviceIdsByModel($modelId);
        if (!$deviceIds) {
            P_E(dcim_msg('common.request_failed'));
        }

        $db = Flight::db();
        $lastDetailInsert = false;
        try {
            if (method_exists($db, 'beginTransaction')) {
                $db->beginTransaction();
            }
            $taskId = self::crud('dcim-xjtask')->legacyInsert($taskPayload);
            if (!$taskId) {
                throw new \RuntimeException('xj task create failed');
            }

            foreach ($deviceIds as $deviceId) {
                $commandRows = self::xjTaskFetchCommandRowsByDevice((string)$deviceId);
                $paramNames = self::xjTaskCollectParamNames($commandRows);
                foreach ($paramNames as $paramName) {
                    $insertId = self::crud('dcim-xjtaskdetail')->legacyInsert([
                        'TaskId' => $taskId,
                        'DeviceId' => (string)$deviceId,
                        'ParamName' => (string)$paramName,
                        'status' => 1,
                    ]);
                    if (!$insertId) {
                        throw new \RuntimeException('xj task detail create failed');
                    }
                    $lastDetailInsert = $insertId;
                }
            }

            if ($lastDetailInsert === false) {
                throw new \RuntimeException('xj task detail empty');
            }

            if (method_exists($db, 'commit')) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if (method_exists($db, 'inTransaction') && $db->inTransaction()) {
                $db->rollBack();
            }
            P_E(dcim_msg('common.request_failed'));
        }

        // Compatibility: return the last inserted detail result.
        O_E($lastDetailInsert, tp_msg_success(), 100, 0);
    }

    public static function xjTaskGetList()
    {
        $data = self::requestData();
        self::requireAuth($data);
        $conditions = ['(status <> -1 OR status IS NULL)'];
        $params = [];
        if (!empty($data['search'])) {
            $conditions[] = 'XJTaskName LIKE :search';
            $params[':search'] = '%' . $data['search'] . '%';
        }
        foreach (['XJStatus', 'XJModelId', 'XJEmpId'] as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $ph = ':' . $key;
                $conditions[] = "{$key} = {$ph}";
                $params[$ph] = $data[$key];
            }
        }
        $where = implode(' AND ', $conditions);
        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;
        $result = self::crud('dcim-xjtask')->selectWithPagination($where, $params, 'ORDER BY id DESC', $page, $pageSize);
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $enrichedRows = self::xjTaskEnrichRows($rows);
        $result['info'] = array_map(static function ($row) {
            return self::xjTaskNormalizeRow(is_array($row) ? $row : [], true);
        }, $enrichedRows);
        O_E($result, tp_msg_success(), 100, 0);
    }

    public static function xjTaskGetInfo()
    {
        $data = self::requestData();
        self::requireAuth($data);
        $id = $data['id'] ?? ($data['PlanId'] ?? ($data['PlanLsh'] ?? 0));
        if (!$id) {
            O_E(null, tp_msg_success(), 100, 0);
            return;
        }
        $infoRows = self::crud('dcim-xjtask')->selectByRawCondition(
            'id = :id AND (status <> -1 OR status IS NULL)',
            'LIMIT 1',
            [':id' => $id]
        );
        $info = $infoRows ? ($infoRows[0] ?? null) : null;
        if (!$info) {
            O_E(null, tp_msg_success(), 100, 0);
            return;
        }
        $enriched = self::xjTaskEnrichRows([$info]);
        $info = $enriched ? $enriched[0] : $info;
        O_E(self::xjTaskNormalizeRow($info, false), tp_msg_success(), 100, 0);
    }

    private static function xjTaskEnrichRows(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $modelIds = [];
        $personIds = [];
        foreach ($rows as $row) {
            if (!empty($row['XJModelId'])) {
                $modelIds[] = $row['XJModelId'];
            }
            foreach (['XJEmpId', 'CreateEmpId', 'EndEmpId'] as $k) {
                if (!empty($row[$k])) {
                    $personIds[] = $row[$k];
                }
            }
        }
        $modelMap = [];
        foreach (self::crud('dcim-xjmodel')->selectByIds($modelIds, ['id', 'XJModelName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $modelMap[$key] = $item['XJModelName'] ?? '';
            }
        }
        $personMap = [];
        foreach (self::crud('dcim-person')->selectByIds($personIds, ['id', 'PersonName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $personMap[$key] = $item['PersonName'] ?? '';
            }
        }
        foreach ($rows as &$row) {
            $defaults = [
                'XJTaskNumber' => '',
                'XJTaskName' => '',
                'XJModelId' => '',
                'XJModelName' => '',
                'XJEmpId' => '',
                'XJEmpName' => '',
                'CreateEmpId' => '',
                'CreateEmpName' => '',
                'XJPlanComplateTime' => '',
                'XJDescribe' => '',
                'XJStatus' => '',
                'EndEmpId' => '',
                'EndEmpName' => '',
                'EndRemark' => '',
                'AnalysisResult' => '',
                'EndTime' => '',
                'status' => 1,
                'create_time' => '',
                'update_time' => '',
            ];
            foreach ($defaults as $k => $v) {
                if (!array_key_exists($k, $row) || $row[$k] === null) {
                    $row[$k] = $v;
                }
            }
            $row['XJModelName'] = $modelMap[(string)($row['XJModelId'] ?? '')] ?? ($row['XJModelName'] ?? '');
            $row['XJEmpName'] = $personMap[(string)($row['XJEmpId'] ?? '')] ?? ($row['XJEmpName'] ?? '');
            $row['CreateEmpName'] = $personMap[(string)($row['CreateEmpId'] ?? '')] ?? ($row['CreateEmpName'] ?? '');
            $row['EndEmpName'] = $personMap[(string)($row['EndEmpId'] ?? '')] ?? ($row['EndEmpName'] ?? '');
        }
        unset($row);
        return $rows;
    }

    public static function xjTaskInfoUpdate()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $res = self::crud('dcim-xjtask')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function xjTaskInfoDel()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $res = self::crud('dcim-xjtask')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function xjTaskStop()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $data['XJStatus'] = 'stopped';
        $res = self::crud('dcim-xjtask')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['XJStatus'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function xjTaskConfirm()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $taskId = $data['TaskId'] ?? ($data['id'] ?? '');
        if ($taskId === '' || $taskId === null) {
            O_E(false, dcim_msg('common.id_required'), 400, 0);
            return;
        }
        $updateData = $data;
        $updateData['id'] = $taskId;
        $updateData['XJStatus'] = $data['Status'] ?? '';
        $res = self::crud('dcim-xjtask')->legacyUpdate($updateData, [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['XJStatus'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function xjTaskRecordDetail()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (!empty($data['DetailId'])) {
            $updateData = $data;
            $updateData['id'] = $data['DetailId'];
            $updateData['CheckResult'] = $data['CheckResult'] ?? null;
            $updateData['Remark'] = $data['Remark'] ?? null;
            $res = self::crud('dcim-xjtaskdetail')->legacyUpdate($updateData, [
                'id_required_message' => dcim_msg('common.id_required'),
                'only_fields' => ['CheckResult', 'Remark'],
            ]);
            if ($res === null) {
                return;
            }
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function xjTaskDetailPara()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $taskId = $data['TaskId'] ?? ($data['id'] ?? 0);
        if (!$taskId) {
            O_E(false, dcim_msg('common.id_required'), 400, 0);
            return;
        }
        $details = self::crud('dcim-xjtaskdetail')->selectByRawCondition('status = 1 AND TaskId = :tid', '', [':tid' => $taskId]);
        $num = $details ? count($details) : false;
        O_E($details, tp_msg_success(), 100, $num);
    }

    public static function xjPointInfoAdd()
    {
        $data = Flight::request_data();
        $id = self::crud('dcim-xjpoint')->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function xjPointGetList()
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-xjpoint')->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => ['XJPointName'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }

        $combo = $data['ComboBox'] ?? null;
        if ($combo !== null && $combo !== '') {
            $rows = [];
            foreach (($result['info'] ?? []) as $row) {
                $rows[] = [
                    'id' => $row['id'] ?? null,
                    'XJPointName' => $row['XJPointName'] ?? '',
                ];
            }
            O_E($rows, tp_msg_success(), 100, $rows ? count($rows) : false);
            return;
        }

        $page = (int)($result['page']['p'] ?? ($data['pageNo'] ?? 1));
        $pageSize = isset($data['pageSize']) ? (int)$data['pageSize'] : 15;
        if ($pageSize <= 0) {
            $pageSize = 15;
        }
        $total = (int)($result['page']['total'] ?? 0);

        $rows = [];
        foreach (($result['info'] ?? []) as $row) {
            $rows[] = [
                'id' => $row['id'] ?? null,
                'XJPointName' => $row['XJPointName'] ?? '',
                'XJPointType' => $row['XJPointType'] ?? '',
                'XJPointInfo' => $row['XJPointInfo'] ?? '',
                'XJPointParamId' => $row['XJPointParamId'] ?? '',
                'remark' => $row['remark'] ?? '',
            ];
        }

        if ($rows) {
            $qrType = "\xE4\xBA\x8C\xE7\xBB\xB4\xE7\xA0\x81";
            $qrText = "\xE5\x90\x8D\xE7\xA7\xB0:\n";
            foreach ($rows as &$row) {
                if (($row['XJPointType'] ?? '') === $qrType) {
                    $row['ewm'] = self::xjPointCreateImg($qrText . ($row['XJPointName'] ?? ''));
                } else {
                    $row['ewm'] = '';
                }
            }
            unset($row);
        }

        $payload = [
            'info' => $rows,
            'page' => [
                'total' => $total,
                'p_n' => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0,
                'p' => $page,
            ],
        ];
        O_E($payload, tp_msg_success(), 100, $rows ? count($rows) : false);
    }

    public static function xjPointGetInfo()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-xjpoint')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function xjPointInfoUpdate()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-xjpoint')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function xjPointInfoDel()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-xjpoint')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    private static function whTryProxyLegacy(string $url, array $data): bool
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
            $obj = json_decode($raw, true);
            if (!is_array($obj) || !array_key_exists('code', $obj)) {
                return false;
            }
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            echo $raw;
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function whPlanInfoAdd()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $id = self::crud('dcim-whplan')->legacyCreate($data, [
            'drop_fields' => ['WHEmpName'],
            'only_fields' => [
                'WHPlanName',
                'WHEmpId',
                'WHComplateDays',
                'WHCycle',
                'DistributeCycle',
                'DistributeTime',
                'WHContent',
                'DeviceId',
                'remark',
                'status',
            ],
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function whPlanGetList()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $viewWhereParts = ['1=1'];
        $whereParts = ['a.status = 1'];
        $params = [];
        if (!empty($data['search'])) {
            $viewWhereParts[] = 'WHPlanName LIKE :search';
            $whereParts[] = 'a.WHPlanName LIKE :search';
            $params[':search'] = '%' . $data['search'] . '%';
        }
        $viewWhereSql = implode(' AND ', $viewWhereParts);
        $whereSql = implode(' AND ', $whereParts);

        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;

        try {
            $result = self::crud('vw_wh_plan')->selectWithPagination(
                $viewWhereSql,
                $params,
                '',
                $page,
                $pageSize
            );
            $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
            O_E($result, tp_msg_success(), 100, $rows ? count($rows) : false);
            return;
        } catch (\Throwable $e) {
            error_log('[GetWHPlanListKey] view query failed, fallback to legacy SQL: ' . $e->getMessage());
        }

        $fallbackWhereSql = str_replace('a.', '', $whereSql);
        $fallbackResult = self::crud('dcim-whplan')->selectByRawConditionWithPagination(
            $fallbackWhereSql,
            $params,
            '',
            $page,
            $pageSize
        );
        $total = (int) ($fallbackResult['page']['total'] ?? 0);
        $rows = is_array($fallbackResult['info'] ?? null) ? $fallbackResult['info'] : [];
        $empIds = [];
        foreach ($rows as $row) {
            if (isset($row['WHEmpId']) && $row['WHEmpId'] !== null && $row['WHEmpId'] !== '') {
                $empIds[(string) $row['WHEmpId']] = true;
            }
        }
        $empMap = [];
        if (!empty($empIds)) {
            $personRows = self::crud('dcim-person')->selectByIds(array_keys($empIds), ['id', 'PersonName']);
            foreach ($personRows as $item) {
                $empMap[(string) $item['id']] = $item['PersonName'] ?? '';
            }
        }
        foreach ($rows as &$row) {
            $row['WHEmpName'] = $empMap[(string) ($row['WHEmpId'] ?? '')] ?? '';
        }
        unset($row);

        $result = [
            'info' => $rows,
            'page' => [
                'total' => $total,
                'p_n' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
                'p' => $page,
            ],
        ];
        O_E($result, tp_msg_success(), 100, $rows ? count($rows) : false);
    }

    public static function whPlanGetInfo()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $id = $data['id'] ?? 0;
        try {
            $info = self::crud('vw_wh_plan')->findOne([
                ['id', '=', $id],
            ]);
            O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
            return;
        } catch (\Throwable $e) {
            error_log('[GetWHPlanDetailKey] view query failed, fallback to legacy SQL: ' . $e->getMessage());
        }

        $info = self::crud('dcim-whplan')->findOne([
            ['id', '=', $id],
            ['status', '=', 1],
        ]);
        if ($info && !empty($info['WHEmpId'])) {
            $pname = self::crud('dcim-person')->findValue([['id', '=', $info['WHEmpId']]], 'PersonName');
            $info['WHEmpName'] = $pname !== null ? $pname : '';
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function whPlanInfoUpdate()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $res = self::crud('dcim-whplan')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => [
                'WHPlanName',
                'WHEmpId',
                'WHComplateDays',
                'WHCycle',
                'DistributeCycle',
                'DistributeTime',
                'WHContent',
                'DeviceId',
                'remark',
            ],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function whPlanInfoDel()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $res = self::crud('dcim-whplan')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function whTaskInfoAdd()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        if (empty($data['WHTaskNumber'])) {
            $data['WHTaskNumber'] = 'WH' . self::createNumber();
        }
        if (empty($data['CreateEmpId'])) {
            $fallbackCreateEmp = $data['UserId'] ?? ($data['UserLsh'] ?? ($data['EmpId'] ?? ($user['id'] ?? 0)));
            $data['CreateEmpId'] = (int)$fallbackCreateEmp > 0 ? (int)$fallbackCreateEmp : 1;
        }
        if (!isset($data['WHStatus']) || $data['WHStatus'] === '') {
            $data['WHStatus'] = dcim_msg('workorder.wh_status_pending');
        }
        $id = self::crud('dcim-whtask')->legacyCreate($data, [
            'drop_fields' => ['token', 'WHEmpName'],
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function whTaskGetList()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $conditions = ['(status <> -1 OR status IS NULL)'];
        $params = [];
        if (!empty($data['search'])) {
            $conditions[] = '(WHTaskName LIKE :search OR WHTaskNumber LIKE :search)';
            $params[':search'] = '%' . $data['search'] . '%';
        }
        $userFilterId = $data['UserId'] ?? ($data['UserLsh'] ?? null);
        if (!empty($userFilterId)) {
            $conditions[] = 'WHEmpId = :uid';
            $params[':uid'] = $userFilterId;
        }
        if (!empty($data['Status'])) {
            $conditions[] = 'WHStatus = :st';
            $params[':st'] = $data['Status'];
        }
        if (!empty($data['startDateTime']) && !empty($data['endDateTime'])) {
            $conditions[] = 'PlanComplateDate BETWEEN :st AND :et';
            $params[':st'] = $data['startDateTime'];
            $params[':et'] = $data['endDateTime'];
        }
        $where = implode(' AND ', $conditions);
        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;
        $result = self::crud('dcim-whtask')->selectWithPagination($where, $params, 'ORDER BY id DESC', $page, $pageSize);
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $result['info'] = self::whTaskEnrichRows($rows);
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function whTaskGetInfo()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $id = $data['id'] ?? 0;
        $infoRows = self::crud('dcim-whtask')->selectByRawCondition(
            'id = :id AND (status <> -1 OR status IS NULL)',
            'LIMIT 1',
            [':id' => $id]
        );
        $info = $infoRows ? ($infoRows[0] ?? null) : null;
        if ($info) {
            $enriched = self::whTaskEnrichRows([$info]);
            $info = $enriched[0] ?? $info;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    private static function whTaskEnrichRows(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $personIds = [];
        $deviceIds = [];
        foreach ($rows as $row) {
            foreach (['WHEmpId', 'CreateEmpId', 'EndEmpId'] as $field) {
                $id = trim((string)($row[$field] ?? ''));
                if ($id !== '') {
                    $personIds[] = $id;
                }
            }
            foreach (explode(',', (string)($row['WHDeviceId'] ?? '')) as $did) {
                $did = trim((string)$did);
                if ($did !== '') {
                    $deviceIds[] = $did;
                }
            }
        }

        $personMap = [];
        foreach (self::crud('dcim-person')->selectByIds(array_values(array_unique($personIds)), ['id', 'PersonName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $personMap[$key] = (string)($item['PersonName'] ?? '');
            }
        }

        $deviceMap = [];
        foreach (self::crud('dcim-device')->selectByIds(array_values(array_unique($deviceIds)), ['id', 'DeviceName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $deviceMap[$key] = (string)($item['DeviceName'] ?? '');
            }
        }

        foreach ($rows as &$row) {
            $defaults = [
                'WHTaskNumber' => '',
                'WHTaskName' => '',
                'WHTaskCon' => '',
                'WHEmpId' => '',
                'WHEmpName' => '',
                'CreateEmpId' => '',
                'CreateEmpName' => '',
                'WHDeviceId' => '',
                'WHDeviceName' => '',
                'WHStatus' => '',
                'PlanComplateDate' => '',
                'WHPeople' => '',
                'WHSituation' => '',
                'WHImg' => '',
                'SubmitTime' => '',
                'EndTime' => '',
                'EndRemark' => '',
                'EndEmpId' => '',
                'EndEmpName' => '',
                'status' => 1,
                'create_time' => '',
                'update_time' => '',
            ];
            foreach ($defaults as $k => $v) {
                if (!array_key_exists($k, $row) || $row[$k] === null) {
                    $row[$k] = $v;
                }
            }
            $row['WHEmpName'] = $personMap[(string)($row['WHEmpId'] ?? '')] ?? ($row['WHEmpName'] ?? '');
            $row['CreateEmpName'] = $personMap[(string)($row['CreateEmpId'] ?? '')] ?? ($row['CreateEmpName'] ?? '');
            $row['EndEmpName'] = $personMap[(string)($row['EndEmpId'] ?? '')] ?? ($row['EndEmpName'] ?? '');

            $deviceNames = [];
            foreach (explode(',', (string)($row['WHDeviceId'] ?? '')) as $did) {
                $did = trim((string)$did);
                if ($did === '') {
                    continue;
                }
                if (isset($deviceMap[$did]) && $deviceMap[$did] !== '') {
                    $deviceNames[] = $deviceMap[$did];
                }
            }
            $row['WHDeviceName'] = $deviceNames ? implode(',', $deviceNames) : ($row['WHDeviceName'] ?? '');
        }
        unset($row);
        return $rows;
    }

    public static function whTaskInfoUpdate()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $res = self::crud('dcim-whtask')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'drop_fields' => ['WHEmpName'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function whTaskInfoDel()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $task = null;
        if (!empty($data['id'])) {
            $task = self::crud('dcim-whtask')->findOne([['id', '=', $data['id']], ['status', '=', 1]]);
        }
        if (!$task || !in_array((string)($task['WHStatus'] ?? ''), [dcim_msg('workorder.wh_status_pending'), dcim_msg('app.task_pending_2'), 'pending'], true)) {
            result_json(400, dcim_msg('error.task_status_not_allowed_delete'), false, false);
        }
        $res = self::crud('dcim-whtask')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function whTaskPerform()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (empty($data['id']) || empty($data['Status'])) {
            P_E(dcim_msg('common.param_missing'));
        }
        $updateData = $data;
        $updateData['WHPeople'] = $data['WHPeople'] ?? null;
        $updateData['WHStatus'] = $data['Status'];
        $updateData['SubmitTime'] = $data['SubmitTime'] ?? null;
        $updateData['WHSituation'] = $data['WHSituation'] ?? '';
        $updateData['WHImg'] = $data['WHImg'] ?? '';
        $res = self::crud('dcim-whtask')->legacyUpdate($updateData, [
            'id_required_message' => dcim_msg('common.param_missing'),
            'only_fields' => ['WHPeople', 'WHStatus', 'SubmitTime', 'WHSituation', 'WHImg'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function whTaskPerformKey()
    {
        self::whTaskPerform();
    }

    public static function whTaskStop()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        if (empty($data['id']) || empty($data['Status'])) {
            P_E(dcim_msg('common.param_missing'));
        }
        $updateData = $data;
        $updateData['EndTime'] = date('Y-m-d H:i:s');
        $updateData['WHStatus'] = $data['Status'];
        $updateData['EndRemark'] = $data['Remark'] ?? '';
        $updateData['EndEmpId'] = $user['id'] ?? 0;
        $res = self::crud('dcim-whtask')->legacyUpdate($updateData, [
            'id_required_message' => dcim_msg('common.param_missing'),
            'only_fields' => ['EndTime', 'WHStatus', 'EndRemark', 'EndEmpId'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function whTaskConfirm()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        $taskId = $data['TaskId'] ?? ($data['id'] ?? '');
        if ($taskId === '' || $taskId === null) {
            O_E(false, dcim_msg('common.id_required'), 400, false);
            return;
        }
        $updateData = $data;
        $updateData['id'] = $taskId;
        $updateData['EndTime'] = date('Y-m-d H:i:s');
        $updateData['WHStatus'] = $data['Status'] ?? '';
        $updateData['EndRemark'] = $data['Remark'] ?? '';
        $updateData['EndEmpId'] = $user['id'] ?? 0;
        $res = self::crud('dcim-whtask')->legacyUpdate($updateData, [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['EndTime', 'WHStatus', 'EndRemark', 'EndEmpId'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function repairGetList()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $conditions = ['status = 1'];
        $params = [];
        if (!empty($data['search'])) {
            $kw = '%' . $data['search'] . '%';
            $assetRows = self::crud('dcim-asset')->selectByRawCondition(
                'status = 1 AND AssetsNumber LIKE :kw',
                '',
                [':kw' => $kw]
            );
            $assetIds = array_values(array_filter(array_map(static function ($r) {
                return isset($r['id']) ? (string)$r['id'] : '';
            }, $assetRows), static function ($v) {
                return $v !== '';
            }));
            if ($assetIds) {
                $phs = [];
                foreach ($assetIds as $idx => $id) {
                    $ph = ':asset_search_' . $idx;
                    $phs[] = $ph;
                    $params[$ph] = $id;
                }
                $conditions[] = '(RepairNumber LIKE :search OR AssetsId IN (' . implode(',', $phs) . '))';
            } else {
                $conditions[] = 'RepairNumber LIKE :search';
            }
            $params[':search'] = $kw;
        }
        foreach (['RepairStatus', 'RepairType', 'AssetsId'] as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $ph = ':' . $key;
                $conditions[] = "{$key} = {$ph}";
                $params[$ph] = $data[$key];
            }
        }
        if (isset($data['ServerCode']) && $data['ServerCode'] !== '' && $data['ServerCode'] !== null) {
            $deptRows = self::crud('dcim-department')->selectByRawCondition(
                'status = 1 AND ServerCode = :sc',
                '',
                [':sc' => $data['ServerCode']]
            );
            $deptIds = array_values(array_filter(array_map(static function ($r) {
                return isset($r['id']) ? (string)$r['id'] : '';
            }, $deptRows), static function ($v) {
                return $v !== '';
            }));
            if ($deptIds) {
                $deptPhs = [];
                $personParams = [];
                foreach ($deptIds as $idx => $deptId) {
                    $ph = ':dept_' . $idx;
                    $deptPhs[] = $ph;
                    $personParams[$ph] = $deptId;
                }
                $personRows = self::crud('dcim-person')->selectByRawCondition(
                    'status = 1 AND DeptId IN (' . implode(',', $deptPhs) . ')',
                    '',
                    $personParams
                );
                $personIds = array_values(array_filter(array_map(static function ($r) {
                    return isset($r['id']) ? (string)$r['id'] : '';
                }, $personRows), static function ($v) {
                    return $v !== '';
                }));
                if ($personIds) {
                    $personPhs = [];
                    foreach ($personIds as $idx => $pid) {
                        $ph = ':emp_' . $idx;
                        $personPhs[] = $ph;
                        $params[$ph] = $pid;
                    }
                    $conditions[] = 'EmpId IN (' . implode(',', $personPhs) . ')';
                } else {
                    $conditions[] = '1 = 0';
                }
            } else {
                $conditions[] = '1 = 0';
            }
        }
        if (!empty($data['startDateTime']) && !empty($data['endDateTime'])) {
            $conditions[] = 'create_time BETWEEN :st AND :et';
            $params[':st'] = $data['startDateTime'];
            $params[':et'] = $data['endDateTime'];
        }
        $where = implode(' AND ', $conditions);
        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;
        $crud = self::crud('dcim-assetrepair');
        $result = $crud->selectWithPagination($where, $params, 'ORDER BY id DESC', $page, $pageSize);
        $result = self::repairEnrichRows($result);
        $num = $result['info'] ? count($result['info']) : false;
        O_E($result, tp_msg_success(), 100, $num);
    }

    public static function repairGetInfo()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $id = $data['id'] ?? 0;
        $row = self::crud('dcim-assetrepair')->findOne([['id', '=', $id], ['status', '=', 1]]);
        if (!$row) {
            O_E([], tp_msg_success(), 100, false);
            return;
        }
        $payload = ['info' => [$row], 'page' => ['total' => 1, 'p_n' => 1, 'p' => 1]];
        $result = self::repairEnrichRows($payload);
        $info = is_array($result['info'] ?? null) && !empty($result['info']) ? $result['info'][0] : [];
        O_E($info, tp_msg_success(), 100, $info ? 1 : false);
    }

    private static function repairEnrichRows(array $result): array
    {
        $assetCrud = self::crud('dcim-asset');
        $brandCrud = self::crud('dcim-brandmodel');
        $typeCrud = self::crud('dcim-assettype');
        $personCrud = self::crud('dcim-person');
        $deptCrud = self::crud('dcim-department');
        $serverCrud = self::crud('dcim-server');
        foreach ($result['info'] as &$row) {
            $asset = $assetCrud->findOne([['id', '=', $row['AssetsId'] ?? 0]]);
            $brand = $asset ? $brandCrud->findOne([['id', '=', $asset['ModelId'] ?? 0]]) : null;
            $type = $brand ? $typeCrud->findOne([['id', '=', $brand['AssetsTypeId'] ?? 0]]) : null;
            $emp = $personCrud->findOne([['id', '=', $row['EmpId'] ?? 0]]);
            $dept = $emp ? $deptCrud->findOne([['id', '=', $emp['DeptId'] ?? 0]]) : null;
            $server = $dept ? $serverCrud->findOne([['id', '=', $dept['ServerCode'] ?? 0]]) : null;

            $row['AssetsNumber'] = $asset['AssetsNumber'] ?? '';
            $row['AssetsDescribe'] = $asset['AssetsDescribe'] ?? '';
            $row['ModelId'] = $asset['ModelId'] ?? null;
            $row['BrandModel'] = $brand['BrandModel'] ?? '';
            $row['AssetsTypeId'] = $brand['AssetsTypeId'] ?? null;
            $row['AssetsTypeName'] = $type['AssetsTypeName'] ?? '';
            $row['AssetsTypeNumber'] = $type['AssetsTypeNumber'] ?? '';
            $row['PersonName'] = $emp['PersonName'] ?? '';
            $row['DeptName'] = $dept['DeptName'] ?? '';
            $row['ServerCode'] = $dept['ServerCode'] ?? null;
            $row['ServerName'] = $server['ServerName'] ?? '';
        }
        unset($row);
        return $result;
    }

    public static function repairAssetsRepair()
    {
        $data = Flight::request_data();
        $rawToken = Flight::request_token();
        if ($rawToken === '') {
            L_E();
        }
        $user = self::requireAuth($data);
        if (empty($data['AssetsId']) || empty($data['RepairType'])) {
            P_E(dcim_msg('error.missing_required_params'));
        }
        if (empty($data['RepairNumber'])) {
            $data['RepairNumber'] = 'WX' . date('YmdHis');
        }
        if (!isset($data['RepairTime']) || $data['RepairTime'] === '') {
            $data['RepairTime'] = date('Y-m-d H:i:s');
        }

        $result = self::crud('dcim-assetrepair')->legacyUpdateAssetStatusWithRecord($data, [
            'asset_table' => 'dcim-asset',
            'record_table' => 'dcim-assetrepair',
            'id_param' => 'AssetsId',
            'allow_csv_ids' => false,
            'status_param' => 'RepairType',
            'asset_status_field' => 'AssetStatus',
            'record_fk_field' => 'AssetsId',
            'record_mode_field' => 'RepairType',
            'record_extra_fields' => [
                'RepairNumber' => 'RepairNumber',
                'RepairTime' => 'RepairTime',
                'RepairEmp' => 'RepairEmp',
                'EmpId' => 'EmpId',
                'FaultDescribe' => 'FaultDescribe',
                'FaultImg' => 'FaultImg',
            ],
            'record_default_fields' => [
                'RepairTime' => date('Y-m-d H:i:s'),
                'RepairEmp' => '',
                'EmpId' => null,
                'FaultDescribe' => '',
                'FaultImg' => '',
            ],
            'record_null_if_empty_fields' => ['EmpId'],
            'id_required_message' => dcim_msg('common.param_missing'),
            'status_required_message' => dcim_msg('common.param_missing'),
            'not_found_message' => dcim_msg('error.asset_not_found'),
            'record_insert_failed_message' => dcim_msg('error.repair_record_insert_failed'),
        ]);
        if ($result === null) {
            return;
        }

        if (in_array($data['RepairType'], ['FR', 'DF'], true)) {
            self::crud('dcim-cabinetu')->legacyUpdateWhere(
                [
                    ['AssetsId', '=', $data['AssetsId']],
                    ['status', '=', 1],
                ],
                ['UStatus' => dcim_msg('workorder.cabinet_u_repairing')]
            );
        }

        foreach (($result['asset_ids'] ?? []) as $assetId) {
            self::writeAssetChangeLog($assetId, dcim_msg('workorder.asset_change_repair_create'), [
                'RepairType' => $data['RepairType'] ?? '',
                'RepairNumber' => $data['RepairNumber'] ?? '',
                'FaultDescribe' => $data['FaultDescribe'] ?? '',
            ], $user['id'] ?? null);
        }

        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function repairFinishRepair()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (empty($data['RepairId'])) {
            O_E(false, dcim_msg('error.repair_id_required'), 400, 0);
            return;
        }
        $repairCrud = self::crud('dcim-assetrepair');
        $old = $repairCrud->findOne([['id', '=', $data['RepairId']], ['status', '=', 1]]);
        if (!$old) {
            P_E(dcim_msg('error.repair_record_not_found'));
        }
        $update = [
            'RepairStatus' => $data['RepairStatus'] ?? ($old['RepairStatus'] ?? ''),
            'RepairResult' => $data['Result'] ?? ($data['RepairResult'] ?? ''),
            'FinishTime' => $data['FinishDate'] ?? ($data['FinishTime'] ?? date('Y-m-d H:i:s')),
        ];
        $updateData = $data;
        $updateData['id'] = $data['RepairId'];
        foreach ($update as $k => $v) {
            $updateData[$k] = $v;
        }
        $res = $repairCrud->legacyUpdate($updateData, [
            'id_required_message' => dcim_msg('error.repair_id_required'),
            'only_fields' => ['RepairStatus', 'RepairResult', 'FinishTime'],
        ]);
        if ($res === null) {
            return;
        }

        $assetStatus = 'I';
        if (in_array($old['RepairType'] ?? '', ['DF', 'FR'], true)) {
            $assetStatus = 'F';
            self::crud('dcim-cabinetu')->legacyUpdateWhere(
                [
                    ['AssetsId', '=', $old['AssetsId'] ?? 0],
                    ['status', '=', 1],
                ],
                ['UStatus' => dcim_msg('workorder.cabinet_u_idle')]
            );
        }
        self::crud('dcim-assetrepair')->legacySyncAssetAndPutout([
            'AssetsId' => $old['AssetsId'] ?? 0,
        ], [
            'asset_id_param' => 'AssetsId',
            'asset_status_value' => $assetStatus,
        ]);
        O_E(true, tp_msg_success(), 100, 1);
    }

private static function ondutyTryProxyLegacy(string $url, array $data): bool
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
            $obj = json_decode($raw, true);
            if (!is_array($obj) || !array_key_exists('code', $obj)) {
                return false;
            }
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            echo $raw;
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function ondutyRequireAuth(array $data = [])
    {
        $user = (new CrudController('dcim-person'))->legacyEnsureAuth($data);
        if (!$user) {
            L_E();
        }
        return $user;
    }

    private static function ondutyIsLegacyFalseLike($value): bool
    {
        if (is_bool($value)) {
            return $value === false;
        }
        if (is_null($value)) {
            return true;
        }
        if (is_string($value)) {
            $v = trim($value);
            return $v === '' || $v === '0';
        }
        if (is_numeric($value)) {
            return ((float) $value) == 0.0;
        }
        return false;
    }

    private static function ondutyIsInTimeRange(string $start, string $end, string $now): bool
    {
        // Keep exactly the same behavior as legacy `inTime($start,$end)` in 8080.
        $date = date('Y-m-d ') ;
        $begin = strtotime($date . $start . ':00');
        $finish = strtotime($date . $end . ':00');
        $curr = time();
        if ($begin === false || $finish === false) {
            return false;
        }
        return $curr >= $begin && $curr <= $finish;
    }

    private static function ondutyShiftByName(array $rows, string $name)
    {
        foreach ($rows as $row) {
            if (($row['BCName'] ?? '') === $name) {
                return $row;
            }
        }
        return null;
    }

    private static function ondutyPickNextShiftName(string $current, array $names): string
    {
        $names = array_values(array_filter(array_map(static function ($v) {
            return trim((string)$v);
        }, $names), static function ($v) {
            return $v !== '';
        }));
        if (!$names) {
            return $current;
        }
        $idx = array_search($current, $names, true);
        if ($idx === false) {
            return $names[0];
        }
        $next = ($idx + 1) % count($names);
        return $names[$next];
    }

    private static function ondutyPickPrevShiftName(string $current, array $names): string
    {
        $names = array_values(array_filter(array_map(static function ($v) {
            return trim((string)$v);
        }, $names), static function ($v) {
            return $v !== '';
        }));
        if (!$names) {
            return $current;
        }
        $idx = array_search($current, $names, true);
        if ($idx === false) {
            return $names[count($names) - 1];
        }
        $prev = ($idx - 1 + count($names)) % count($names);
        return $names[$prev];
    }

    private static function ondutyGetPbByDate(string $ymd): ?array
    {
        $ts = strtotime($ymd);
        if ($ts === false) {
            return null;
        }
        $ym = date('Y-m', $ts);
        $day = date('d', $ts);
        $rows = self::crud('dcim-onduty')->selectByRawCondition(
            'status = 1 AND OndutyYm = :ym AND OndutyDay = :day',
            'ORDER BY id DESC LIMIT 1',
            [':ym' => $ym, ':day' => $day]
        );
        return $rows ? ($rows[0] ?? null) : null;
    }

    private static function ondutyShiftEmpField(array $shift): string
    {
        $name = (string)($shift['BCName'] ?? '');
        $id = trim((string)($shift['id'] ?? ''));
        if ($id === '1' || strpos($name, dcim_msg('workorder.shift_morning_keyword')) !== false) {
            return 'MorningEmpId';
        }
        if ($id === '2' || strpos($name, dcim_msg('workorder.shift_noon_keyword')) !== false) {
            return 'MiddleEmpId';
        }
        if ($id === '3' || strpos($name, dcim_msg('workorder.shift_evening_keyword')) !== false) {
            return 'EveningEmpId';
        }
        return '';
    }

    private static function ondutyResolveDutyInfo(?array $pbRow, array $shift): array
    {
        if (!$pbRow) {
            return ['group' => '', 'emps' => ''];
        }
        $empField = self::ondutyShiftEmpField($shift);
        if ($empField === '') {
            return ['group' => '', 'emps' => ''];
        }
        $empId = trim((string)($pbRow[$empField] ?? ''));
        if ($empId === '') {
            return ['group' => '', 'emps' => ''];
        }
        $emp = self::crud('dcim-person')->findOne([['id', '=', $empId], ['status', '=', 1]]) ?: [];
        $group = self::crud('dcim-bcground')->findOne([['ZBZEmpId', '=', $empId], ['status', '=', 1]]) ?: [];
        return [
            'group' => (string)($group['ZBZGround'] ?? ''),
            'emps' => (string)($emp['PersonName'] ?? ''),
        ];
    }

    public static function ondutyGetList()
    {
        $data = Flight::request_data();
        self::ondutyRequireAuth($data);
        $conditions = ['status = 1'];
        $params = [];
        if (!empty($data['search'])) {
            $conditions[] = 'OnDutyEmps LIKE :search';
            $params[':search'] = '%' . $data['search'] . '%';
        }
        if (!empty($data['startDateTime']) && !empty($data['endDateTime'])) {
            $conditions[] = 'create_time BETWEEN :st AND :et';
            $params[':st'] = $data['startDateTime'];
            $params[':et'] = $data['endDateTime'];
        }
        $where = implode(' AND ', $conditions);
        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;
        $crud = self::crud('dcim-ondutylog');
        $result = $crud->selectWithPagination($where, $params, 'ORDER BY id DESC', $page, $pageSize);
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function ondutyGetOndutyLog()
    {
        $data = Flight::request_data();
        self::ondutyRequireAuth($data);

        $baseInfo = [
            'BCName' => null,
            'btn_jiaojieban' => false,
            'JiaoBanEmps' => null,
            'JiaoBanGroup' => null,
            'JiaoBanTime' => null,
            'JieBanBCName' => null,
            'JieBanEmps' => null,
            'JieBanGroup' => null,
            'JieBanTime' => null,
            'LogContext' => null,
            'Lsh' => 0,
            'OnDutyEmps' => null,
            'onDutyEndTime' => '',
            'OnDutyGroup' => null,
            'onDutyStartTime' => '',
            'PBDate' => '',
            'txt_dutyEmps' => false,
            'txt_jiebanEmps' => false,
        ];

        $rows = [];
        try {
            $rows = self::crud('dcim-bc')->selectByRawCondition(
                'status = 1 AND BCDisable = 1',
                'ORDER BY id ASC'
            );
        } catch (Throwable $e) {
        }
        if (!$rows) {
            O_E($baseInfo, tp_msg_success(), 100, false);
            return;
        }

        $now = date('H:i');
        $current = null;
        $names = [];
        foreach ($rows as $row) {
            $name = (string) ($row['BCName'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
            $start = (string) ($row['BCstart'] ?? '');
            $end = (string) ($row['BCend'] ?? '');
            if ($start !== '' && $end !== '' && self::ondutyIsInTimeRange($start, $end, $now)) {
                $current = $row;
            }
        }
        if ($current === null) {
            O_E($baseInfo, tp_msg_success(), 100, false);
            return;
        }

        // Match legacy condition: if ($data['Key'] == false) { current } else { previous }
        $needPrev = !self::ondutyIsLegacyFalseLike($data['Key'] ?? null);
        $currentName = (string) ($current['BCName'] ?? '');
        $targetName = $needPrev ? self::ondutyPickPrevShiftName($currentName, $names) : $currentName;
        $targetShift = self::ondutyShiftByName($rows, $targetName) ?: $current;
        $nextName = self::ondutyPickNextShiftName($targetName, $names);
        $nextShift = self::ondutyShiftByName($rows, $nextName) ?: $targetShift;

        $today = date('Y-m-d');
        $draftDate = $today;
        $currIdx = array_search($currentName, $names, true);
        if ($needPrev && $currIdx === 0) {
            $draftDate = date('Y-m-d', strtotime('-1 day'));
        }
        $draftName = $needPrev ? $targetName : $currentName;
        $targetPbDate = $draftDate;
        $nextPbDate = $targetPbDate;
        $targetIdx = array_search($targetName, $names, true);
        $nextIdx = array_search($nextName, $names, true);
        if ($targetIdx !== false && $nextIdx !== false && $nextIdx <= $targetIdx) {
            $nextPbDate = date('Y-m-d', strtotime($targetPbDate . ' +1 day'));
        }
        $targetDuty = self::ondutyResolveDutyInfo(self::ondutyGetPbByDate($targetPbDate), $targetShift);
        $nextDuty = self::ondutyResolveDutyInfo(self::ondutyGetPbByDate($nextPbDate), $nextShift);
        try {
            $rows = self::crud('dcim-ondutylog')->selectByRawCondition(
                'BCName = :name AND PBDate = :pb AND OnDutyLogStatus = :status',
                'ORDER BY id DESC LIMIT 1',
                [
                ':name' => $draftName,
                ':pb' => $draftDate,
                ':status' => dcim_msg(self::ONDUTY_LOG_STATUS_DRAFT),
                ]
            );
            $sqllog = $rows ? $rows[0] : null;
            if ($sqllog) {
                $onDutyEmps = trim((string)($sqllog['OnDutyEmps'] ?? ''));
                $onDutyGroup = trim((string)($sqllog['OnDutyGroup'] ?? ''));
                $jieBanEmps = trim((string)($sqllog['JieBanEmps'] ?? ''));
                $jieBanGroup = trim((string)($sqllog['JieBanGroup'] ?? ''));
                if ($onDutyEmps === '') {
                    $onDutyEmps = (string)($targetDuty['emps'] ?? '');
                }
                if ($onDutyGroup === '') {
                    $onDutyGroup = (string)($targetDuty['group'] ?? '');
                }
                if ($jieBanEmps === '') {
                    $jieBanEmps = (string)($nextDuty['emps'] ?? '');
                }
                if ($jieBanGroup === '') {
                    $jieBanGroup = (string)($nextDuty['group'] ?? '');
                }
                $payload = [
                    'BCName' => $sqllog['BCName'] ?? null,
                    'btn_jiaojieban' => false,
                    'JiaoBanEmps' => $sqllog['JiaoBanEmps'] ?? null,
                    'JiaoBanGroup' => $sqllog['JiaoBanGroup'] ?? null,
                    'JiaoBanTime' => $sqllog['JiaoBanTime'] ?? null,
                    'JieBanBCName' => $sqllog['JieBanBCName'] ?? null,
                    'JieBanEmps' => $jieBanEmps !== '' ? $jieBanEmps : null,
                    'JieBanGroup' => $jieBanGroup !== '' ? $jieBanGroup : null,
                    'JieBanTime' => $sqllog['JieBanTime'] ?? null,
                    'LogContext' => $sqllog['LogContext'] ?? null,
                    'Lsh' => isset($sqllog['id']) ? (int) $sqllog['id'] : 0,
                    'OnDutyEmps' => $onDutyEmps !== '' ? $onDutyEmps : null,
                    'onDutyEndTime' => $sqllog['onDutyEndTime'] ?? '',
                    'OnDutyGroup' => $onDutyGroup !== '' ? $onDutyGroup : null,
                    'onDutyStartTime' => $sqllog['onDutyStartTime'] ?? '',
                    'PBDate' => $sqllog['PBDate'] ?? '',
                    'txt_dutyEmps' => false,
                    'txt_jiebanEmps' => false,
                ];
                O_E($payload, tp_msg_success(), 100, false);
                return;
            }
        } catch (Throwable $e) {
        }

        $newLsh = 1;
        try {
            $rows = self::crud('dcim-ondutylog')->selectByRawCondition('1 = 1', 'ORDER BY id DESC LIMIT 1');
            $last = $rows ? $rows[0] : null;
            $newLsh = isset($last['id']) ? ((int) $last['id'] + 1) : 1;
        } catch (Throwable $e) {
        }

        $targetStart = (string) ($targetShift['BCstart'] ?? '');
        $targetEnd = (string) ($targetShift['BCend'] ?? '');
        $nextStart = (string) ($nextShift['BCstart'] ?? '');
        $payload = [
            'BCName' => (string) ($targetShift['BCName'] ?? ''),
            'PBDate' => $targetPbDate,
            'onDutyStartTime' => $targetStart !== '' ? ($targetPbDate . ' ' . $targetStart) : '',
            'onDutyEndTime' => $targetEnd !== '' ? ($targetPbDate . ' ' . $targetEnd) : '',
            'OnDutyGroup' => (string)($targetDuty['group'] ?? ''),
            'OnDutyEmps' => (string)($targetDuty['emps'] ?? ''),
            'JieBanGroup' => (string)($nextDuty['group'] ?? ''),
            'JieBanEmps' => (string)($nextDuty['emps'] ?? ''),
            'JieBanBCName' => (string) ($nextShift['BCName'] ?? $targetName),
            'JieBanTime' => $nextStart !== '' ? ($nextPbDate . ' ' . $nextStart) : '',
            'Lsh' => $newLsh,
            'btn_jiaojieban' => false,
            'JiaoBanEmps' => null,
            'JiaoBanGroup' => (string)($targetDuty['group'] ?? ''),
            'JiaoBanTime' => date('Y-m-d H:i:s'),
            'LogContext' => null,
            'txt_dutyEmps' => null,
            'txt_jiebanEmps' => null,
        ];
        O_E($payload, tp_msg_success(), 100, false);
    }

    private static function ondutyUpsertLog(array $data, string $statusKey)
    {
        $crud = self::crud('dcim-ondutylog');
        $existing = null;
        if (!empty($data['BCName']) && !empty($data['PBDate'])) {
            $existing = $crud->findOne([
                ['BCName', '=', $data['BCName']],
                ['PBDate', '=', $data['PBDate']],
            ]);
        }
        $payload = [
            'BCName' => $data['BCName'] ?? '',
            'PBDate' => $data['PBDate'] ?? '',
            'OnDutyGroup' => $data['OnDutyGroup'] ?? '',
            'OnDutyEmps' => $data['OnDutyEmps'] ?? '',
            'LogContext' => $data['LogContext'] ?? '',
            'JieBanGroup' => $data['JieBanGroup'] ?? '',
            'JieBanEmps' => $data['JieBanEmps'] ?? '',
            'JieBanBCName' => $data['JieBanBCName'] ?? '',
            'JieBanTime' => $data['JieBanTime'] ?? '',
            'onDutyStartTime' => $data['onDutyStartTime'] ?? '',
            'onDutyEndTime' => $data['onDutyEndTime'] ?? '',
            'OnDutyLogStatus' => dcim_msg($statusKey),
            'status' => 1,
        ];
        if ($existing) {
            $updateData = $payload;
            $updateData['id'] = $existing['id'];
            $crud->legacyUpdate($updateData, [
                'skip_auth' => true,
                'id_required_message' => dcim_msg('common.id_required'),
            ]);
        } else {
            $crud->legacyInsert($payload);
        }
    }

    public static function ondutyJiaoJieBan()
    {
        $data = Flight::request_data();
        self::ondutyRequireAuth($data);
        self::ondutyUpsertLog($data, self::ONDUTY_LOG_STATUS_HANDOVER);
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function ondutySaveLog()
    {
        $data = Flight::request_data();
        self::ondutyRequireAuth($data);
        self::ondutyUpsertLog($data, self::ONDUTY_LOG_STATUS_DRAFT);
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function ondutyCreatePB()
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-onduty');
        if (!$crud->legacyEnsureAuth($data)) {
            return;
        }
        if (empty($data['OndutyYm'])) {
            result_json(100, tp_msg_success(), dcim_msg('common.onduty_ym_required'), 0);
        }
        $id = $crud->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E($id ? true : false, tp_msg_success(), 100, $id ? 1 : false);
    }

    public static function ondutyGetPBList()
    {
        $data = Flight::request_data();
        self::ondutyRequireAuth($data);
        $crud = self::crud('dcim-onduty');
        $ym = trim((string)($data['OndutyYm'] ?? ''));
        if ($ym !== '') {
            self::ondutyEnsureMonthRows($ym);
        }
        $result = $crud->legacyList($data, [
            'base_where' => ['status = 1'],
            'exact_filters' => ['OndutyYm' => 'OndutyYm'],
            'order_by' => 'ORDER BY OndutyDay ASC, id ASC',
        ]);
        if ($result === null) {
            return;
        }
        $result['info'] = self::ondutyEnrichPbRows(is_array($result['info'] ?? null) ? $result['info'] : []);
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    private static function ondutyEnsureMonthRows(string $ym): void
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            return;
        }
        $exists = self::crud('dcim-onduty')->selectByRawCondition(
            'status = 1 AND OndutyYm = :ym',
            'LIMIT 1',
            [':ym' => $ym]
        );
        if ($exists) {
            return;
        }
        $ts = strtotime($ym . '-01');
        if ($ts === false) {
            return;
        }
        $days = (int)date('t', $ts);
        for ($i = 1; $i <= $days; $i++) {
            self::crud('dcim-onduty')->legacyInsert([
                'OndutyYm' => $ym,
                'OndutyDay' => str_pad((string)$i, 2, '0', STR_PAD_LEFT),
                'status' => 1,
            ]);
        }
    }

    private static function ondutyEnrichPbRows(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $personIds = [];
        foreach ($rows as $row) {
            foreach (['MorningEmpId', 'MiddleEmpId', 'EveningEmpId'] as $k) {
                if (!empty($row[$k])) {
                    $personIds[] = $row[$k];
                }
            }
        }
        $personMap = [];
        foreach (self::crud('dcim-person')->selectByIds($personIds, ['id', 'PersonName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $personMap[$key] = $item['PersonName'] ?? '';
            }
        }
        foreach ($rows as &$row) {
            $row['MorningEmpName'] = $personMap[(string)($row['MorningEmpId'] ?? '')] ?? '';
            $row['MiddleEmpName'] = $personMap[(string)($row['MiddleEmpId'] ?? '')] ?? '';
            $row['EveningEmpName'] = $personMap[(string)($row['EveningEmpId'] ?? '')] ?? '';
        }
        unset($row);
        return $rows;
    }

    public static function ondutyGetPBDetail()
    {
        $data = self::requestData();
        self::ondutyRequireAuth($data);
        $crud = self::crud('dcim-onduty');
        $info = $crud->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        $payload = $info ?: [];
        if ($payload) {
            $rows = self::ondutyEnrichPbRows([$payload]);
            $payload = $rows ? ($rows[0] ?? $payload) : $payload;
        }
        $payload = [
            'id' => isset($payload['id']) ? (int)$payload['id'] : 0,
            'OndutyYm' => (string)($payload['OndutyYm'] ?? ''),
            'OndutyDay' => (string)($payload['OndutyDay'] ?? ''),
            'MorningEmpId' => isset($payload['MorningEmpId']) && $payload['MorningEmpId'] !== '' ? (int)$payload['MorningEmpId'] : 0,
            'MiddleEmpId' => isset($payload['MiddleEmpId']) && $payload['MiddleEmpId'] !== '' ? (int)$payload['MiddleEmpId'] : 0,
            'EveningEmpId' => isset($payload['EveningEmpId']) && $payload['EveningEmpId'] !== '' ? (int)$payload['EveningEmpId'] : 0,
            'MorningEmpName' => (string)($payload['MorningEmpName'] ?? ''),
            'MiddleEmpName' => (string)($payload['MiddleEmpName'] ?? ''),
            'EveningEmpName' => (string)($payload['EveningEmpName'] ?? ''),
        ];
        O_E($payload, tp_msg_success(), 100, 0);
    }

    public static function ondutyChangePB()
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-onduty');
        $res = $crud->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function ondutyCreateZBZ()
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-bcground');
        $id = $crud->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function ondutyGetZBZList()
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-bcground');
        $result = $crud->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => ['ZBZGround'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $result['info'] = self::ondutyEnrichZbzRows(is_array($result['info'] ?? null) ? $result['info'] : []);
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function ondutyGetZBZDetail()
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-bcground');
        $info = $crud->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        $payload = $info ?: null;
        if (is_array($payload)) {
            $rows = self::ondutyEnrichZbzRows([$payload]);
            $payload = $rows ? $rows[0] : $payload;
        }
        result_json(100, tp_msg_success(), $payload, 0);
    }

    private static function ondutyEnrichZbzRows(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $personIds = [];
        foreach ($rows as $row) {
            if (!empty($row['ZBZEmpId'])) {
                foreach (explode(',', (string)$row['ZBZEmpId']) as $pid) {
                    $pid = trim($pid);
                    if ($pid !== '') {
                        $personIds[] = $pid;
                    }
                }
            }
        }
        $personMap = [];
        foreach (self::crud('dcim-person')->selectByIds($personIds, ['id', 'PersonName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $personMap[$key] = $item['PersonName'] ?? '';
            }
        }
        foreach ($rows as &$row) {
            $names = [];
            foreach (explode(',', (string)($row['ZBZEmpId'] ?? '')) as $pid) {
                $pid = trim($pid);
                if ($pid !== '' && isset($personMap[$pid])) {
                    $names[] = (string)$personMap[$pid];
                }
            }
            if ($names) {
                $row['PersonName'] = implode(',', array_values(array_unique($names)));
            } else {
                $row['PersonName'] = $personMap[(string)($row['ZBZEmpId'] ?? '')] ?? ($row['PersonName'] ?? '');
            }
        }
        unset($row);
        return $rows;
    }

    public static function ondutyChangeZBZ()
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-bcground');
        if (!$crud->legacyEnsureAuth($data)) {
            return;
        }
        if (empty($data['ZBZGround'])) {
            result_json(400, dcim_msg('error.zbz_ground_required'), false, 0);
        }
        $res = $crud->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function ondutyDelZBZ()
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-bcground');
        if (!$crud->legacyEnsureAuth($data)) {
            return;
        }
        if (empty($data['id'])) {
            result_json(100, tp_msg_success(), 0, 0);
        }
        $crud->legacySoftDelete($data, [
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        result_json(100, tp_msg_success(), 0, 0);
    }
}




