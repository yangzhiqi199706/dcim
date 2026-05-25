<?php

class AssetsController
{
    private static function crud(string $table)
    {
        return new CrudController($table);
    }

    private static function authCrud()
    {
        return new CrudController('dcim-person');
    }

    private static function requireAuth(array $data = [])
    {
        $user = self::authCrud()->legacyEnsureAuth($data);
        if ($user) {
            return $user;
        }
        $token = function_exists('dcim_extract_token') ? dcim_extract_token($data) : '';
        if ($token === '') {
            L_E();
        }
        $user = function_exists('dcim_auth_user_by_token') ? dcim_auth_user_by_token($token) : null;
        if (!$user) {
            L_E();
        }
        return $user;
    }

    private static function assetChangeLogColumns(): array
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

    private static function assetChangeTypeByDealWay($dealWay): string
    {
        $dealWay = strtoupper(trim((string)$dealWay));
        if ($dealWay === 'G') {
            return dcim_msg('assets.asset_change.type.gift');
        }
        if ($dealWay === 'Y') {
            return dcim_msg('assets.asset_change.type.lost');
        }
        if ($dealWay === 'R') {
            return dcim_msg('assets.asset_change.type.scrap');
        }
        return dcim_msg('assets.asset_change.type.dispose');
    }

    private static function assetChangeTypeByReturnType($type): string
    {
        $type = strtolower(trim((string)$type));
        if ($type === 'offback') {
            return dcim_msg('assets.asset_change.type.offback');
        }
        if ($type === 'viewback') {
            return dcim_msg('assets.asset_change.type.viewback');
        }
        if ($type === 'remove') {
            return dcim_msg('assets.asset_change.type.remove');
        }
        return dcim_msg('assets.asset_change.type.back');
    }

    private static function assetChangeLogType(string $opType, array $extra): string
    {
        if ($opType === 'inbound') {
            return dcim_msg('assets.asset_change.type.inbound');
        }
        if ($opType === 'dispose') {
            return self::assetChangeTypeByDealWay($extra['DealWay'] ?? '');
        }
        if ($opType === 'outbound' || $opType === dcim_msg('assets.change_log_outbound')) {
            return dcim_msg('assets.asset_change.type.outbound');
        }
        if ($opType === 'location_owner_change') {
            return dcim_msg('assets.asset_change.type.change');
        }
        if ($opType === 'return') {
            return self::assetChangeTypeByReturnType($extra['type'] ?? 'back');
        }
        if ($opType === 'cabinet_remove') {
            return dcim_msg('assets.asset_change.type.uninstall');
        }
        if (in_array($opType, ['cabinet_install', 'install', 'install_change'], true)) {
            return dcim_msg('assets.asset_change.type.install');
        }
        if (in_array($opType, ['grounding', 'grounding_change'], true)) {
            return dcim_msg('assets.asset_change.type.grounding');
        }
        return $opType;
    }

    private static function assetChangeLogDescribe(string $assetId, string $opType, string $changeType, array $extra): string
    {
        if ($opType === 'inbound') {
            return dcim_msg('assets.asset_change.describe.inbound', null, ['asset_id' => $assetId]);
        }
        if ($opType === 'dispose') {
            return dcim_msg('assets.asset_change.describe.dispose', null, [
                'asset_id' => $assetId,
                'change_type' => $changeType,
            ]);
        }
        if ($opType === 'outbound' || $opType === dcim_msg('assets.change_log_outbound')) {
            return dcim_msg('assets.asset_change.describe.outbound', null, [
                'asset_id' => $assetId,
                'putout_way' => (string)($extra['PutoutWay'] ?? ''),
            ]);
        }
        if ($opType === 'location_owner_change') {
            $oldCabinetId = trim((string)($extra['OldCabinetId'] ?? ''));
            $newCabinetId = trim((string)($extra['CabinetId'] ?? ''));
            $oldULocation = trim((string)($extra['OldULocation'] ?? ''));
            $newULocation = trim((string)($extra['ULocation'] ?? ''));
            $oldEmpId = trim((string)($extra['OldEmpId'] ?? ''));
            $newEmpId = trim((string)($extra['EmpId'] ?? ''));
            $hasLocationChange = ($oldCabinetId !== $newCabinetId) || ($oldULocation !== $newULocation);
            $hasOwnerChange = ($oldEmpId !== $newEmpId);
            if ($hasLocationChange) {
                $desc = dcim_msg('assets.asset_change.describe.location_change', null, [
                    'asset_id' => $assetId,
                    'old_cabinet_id' => $oldCabinetId,
                    'new_cabinet_id' => $newCabinetId,
                    'old_u_location' => $oldULocation,
                    'new_u_location' => $newULocation,
                ]);
                if ($hasOwnerChange) {
                    $desc .= dcim_msg('assets.asset_change.describe.owner_append', null, [
                        'old_emp_id' => $oldEmpId,
                        'new_emp_id' => $newEmpId,
                    ]);
                }
                return $desc;
            }
            if ($hasOwnerChange) {
                return dcim_msg('assets.asset_change.describe.owner_only', null, [
                    'asset_id' => $assetId,
                    'old_emp_id' => $oldEmpId,
                    'new_emp_id' => $newEmpId,
                ]);
            }
            return '';
        }
        if ($opType === 'return') {
            return dcim_msg('assets.asset_change.describe.return', null, [
                'asset_id' => $assetId,
                'change_type' => $changeType,
                'store_location_id' => (string)($extra['StoreLocationId'] ?? ''),
                'emp_id' => (string)($extra['EmpId'] ?? ''),
            ]);
        }
        if (in_array($opType, ['cabinet_remove'], true)) {
            return dcim_msg('assets.asset_change.describe.uninstall', null, ['asset_id' => $assetId]);
        }
        if (in_array($opType, ['cabinet_install', 'install', 'install_change'], true)) {
            return dcim_msg('assets.asset_change.describe.install', null, ['asset_id' => $assetId]);
        }
        if (in_array($opType, ['grounding', 'grounding_change'], true)) {
            return dcim_msg('assets.asset_change.describe.grounding', null, ['asset_id' => $assetId]);
        }
        return '';
    }

    private static function currentAssetCabinetLocation($assetId): array
    {
        $assetId = trim((string)$assetId);
        if ($assetId === '') {
            return ['CabinetId' => '', 'ULocation' => ''];
        }
        $uRows = self::crud('dcim-cabinetu')->selectByRawCondition(
            'status = 1 AND AssetsId = :aid',
            'ORDER BY id ASC LIMIT 1',
            [':aid' => $assetId]
        );
        if ($uRows) {
            return [
                'CabinetId' => (string)($uRows[0]['CabinetId'] ?? ''),
                'ULocation' => (string)($uRows[0]['ULocation'] ?? ''),
            ];
        }
        $cabinet = self::crud('dcim-cabinet')->findOne([['AssetsId', '=', $assetId], ['status', '=', 1]]);
        if ($cabinet) {
            return [
                'CabinetId' => (string)($cabinet['id'] ?? ''),
                'ULocation' => (string)($cabinet['ULocation'] ?? ''),
            ];
        }
        return ['CabinetId' => '', 'ULocation' => ''];
    }

    private static function assetChangeLogWrite($assetId, string $opType, array $extra = [], $userId = null): void
    {
        $assetId = trim((string)$assetId);
        if ($assetId === '') {
            return;
        }
        $columns = self::assetChangeLogColumns();
        if (!$columns) {
            return;
        }
        $remark = (string)($extra['remark'] ?? ($extra['Reason'] ?? ($extra['DealReason'] ?? '')));
        $detail = '';
        if (!empty($extra)) {
            $json = json_encode($extra, JSON_UNESCAPED_UNICODE);
            if (is_string($json) && $json !== '') {
                $detail = $json;
            }
        }
        $changeType = self::assetChangeLogType($opType, $extra);
        $changeDescribe = self::assetChangeLogDescribe($assetId, $opType, $changeType, $extra);
        $candidate = [
            'AssetsId' => $assetId,
            'type' => $opType,
            'ChangeType' => $changeType,
            'ChangeWay' => $changeType,
            'ChangeDescribe' => $changeDescribe,
            'Remark' => $remark,
            'ChangeReason' => $remark,
            'Content' => $detail,
            'Detail' => $detail,
            'EmpId' => $userId,
            'CreateEmpId' => $userId,
            'status' => 1,
        ];
        $payload = [];
        foreach ($candidate as $field => $value) {
            if (!isset($columns[$field])) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $payload[$field] = $value;
        }
        if (isset($columns['status']) && !isset($payload['status'])) {
            $payload['status'] = 1;
        }
        if (!$payload) {
            return;
        }
        try {
            self::crud('dcim-assetchangelog')->legacyInsert($payload);
        } catch (\Throwable $e) {
            error_log('[asset_change_log] write failed: ' . $e->getMessage());
        }
    }

    private static function buildInCondition(string $field, array $ids, string $prefix, array &$params): ?string
    {
        $holders = [];
        $i = 0;
        foreach ($ids as $id) {
            $id = (string)$id;
            if ($id === '') {
                continue;
            }
            $ph = ':' . $prefix . $i;
            $holders[] = $ph;
            $params[$ph] = $id;
            $i++;
        }
        if (!$holders) {
            return null;
        }
        return $field . ' IN (' . implode(',', $holders) . ')';
    }

    private static function brandModelTypeField(): string
    {
        static $resolved = null;
        if (is_string($resolved) && $resolved !== '') {
            return $resolved;
        }
        $resolved = 'AssetsTypeId';
        try {
            $stmt = Flight::db()->prepare('SHOW COLUMNS FROM `dcim-brandmodel`');
            $stmt->execute();
            $columns = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '') {
                    $columns[$field] = true;
                }
            }
            foreach (['AssetsTypeId', 'AssetsTypeID', 'TypeId', 'TypeID'] as $candidate) {
                if (isset($columns[$candidate])) {
                    $resolved = $candidate;
                    break;
                }
            }
        } catch (\Throwable $e) {
        }
        return $resolved;
    }

    private static function brandModelTypeId(array $model): string
    {
        foreach (['AssetsTypeId', 'AssetsTypeID', 'TypeId', 'TypeID'] as $field) {
            if (!array_key_exists($field, $model)) {
                continue;
            }
            $value = trim((string)$model[$field]);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private static function enrichAssetRows(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $typeIds = [];
        $modelIds = [];
        $storeIds = [];
        $empIds = [];
        $supplierIds = [];
        $createEmpIds = [];
        $assetIds = [];
        $rentIds = [];
        foreach ($rows as $row) {
            $assetIds[] = $row['id'] ?? null;
            if (!empty($row['AssetsTypeId'])) {
                $typeIds[] = $row['AssetsTypeId'];
            }
            if (!empty($row['ModelId'])) {
                $modelIds[] = $row['ModelId'];
            }
            if (!empty($row['StoreLocationId'])) {
                $storeIds[] = $row['StoreLocationId'];
            }
            if (!empty($row['EmpId'])) {
                $empIds[] = $row['EmpId'];
            }
            if (!empty($row['SupplierId'])) {
                $supplierIds[] = $row['SupplierId'];
            }
            if (!empty($row['CreateEmpId'])) {
                $createEmpIds[] = $row['CreateEmpId'];
            }
            if (!empty($row['RentId'])) {
                $rentIds[] = $row['RentId'];
            }
        }

        $typeMap = [];
        foreach (self::crud('dcim-assettype')->selectByIds($typeIds, ['id', 'AssetsTypeName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $typeMap[$key] = $item;
            }
        }
        $modelMap = [];
        $typeField = self::brandModelTypeField();
        foreach (self::crud('dcim-brandmodel')->selectByIds($modelIds, ['id', 'BrandModel', $typeField]) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $modelMap[$key] = $item;
            }
        }
        $storeMap = [];
        foreach (self::crud('dcim-store')->selectByIds($storeIds, ['id', 'StoreLocationName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $storeMap[$key] = $item;
            }
        }
        $personMap = [];
        foreach (self::crud('dcim-person')->selectByIds(array_merge($empIds, $createEmpIds), ['id', 'PersonName', 'DeptId']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $personMap[$key] = $item;
            }
        }
        $deptIds = [];
        foreach ($personMap as $item) {
            if (!empty($item['DeptId'])) {
                $deptIds[] = $item['DeptId'];
            }
        }
        $deptMap = [];
        foreach (self::crud('dcim-department')->selectByIds($deptIds, ['id', 'DeptName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $deptMap[$key] = $item;
            }
        }
        $supplierMap = [];
        foreach (self::crud('dcim-supplier')->selectByIds($supplierIds, ['id', 'SupplierName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $supplierMap[$key] = $item;
            }
        }
        $tenantMap = [];
        foreach (self::crud('dcim-tenant')->selectByIds($rentIds, ['id', 'TenantName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $tenantMap[$key] = $item;
            }
        }

        $cabinetByAsset = [];
        $areaIds = [];
        $serverIds = [];
        foreach (self::crud('dcim-cabinet')->selectByRawCondition('status = 1 AND AssetsId IS NOT NULL AND AssetsId <> 0', '', []) as $cabinet) {
            $aid = (string)($cabinet['AssetsId'] ?? '');
            if ($aid !== '' && !isset($cabinetByAsset[$aid])) {
                $cabinetByAsset[$aid] = $cabinet;
            }
            if (!empty($cabinet['AreaId'])) {
                $areaIds[] = $cabinet['AreaId'];
            }
            if (!empty($cabinet['ServerCode'])) {
                $serverIds[] = $cabinet['ServerCode'];
            }
        }
        $areaMap = [];
        foreach (self::crud('dcim-area')->selectByIds($areaIds, ['id', 'AreaName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $areaMap[$key] = $item;
            }
        }
        $serverMap = [];
        foreach (self::crud('dcim-server')->selectByIds($serverIds, ['id', 'ServerName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $serverMap[$key] = $item;
            }
        }
        $cabinetUCounts = [];
        foreach (self::crud('dcim-cabinetu')->selectByRawCondition('status = 1', '', []) as $uRow) {
            $cid = (string)($uRow['CabinetId'] ?? '');
            if ($cid === '') {
                continue;
            }
            if (!isset($cabinetUCounts[$cid])) {
                $cabinetUCounts[$cid] = 0;
            }
            $cabinetUCounts[$cid]++;
        }

        foreach ($rows as &$row) {
            $model = $modelMap[(string)($row['ModelId'] ?? '')] ?? [];
            $typeName = $typeMap[(string)($row['AssetsTypeId'] ?? '')]['AssetsTypeName'] ?? '';
            if ($typeName === '') {
                $modelTypeId = self::brandModelTypeId($model);
                if ($modelTypeId !== '') {
                    $typeName = $typeMap[$modelTypeId]['AssetsTypeName'] ?? '';
                }
            }
            $person = $personMap[(string)($row['EmpId'] ?? '')] ?? [];
            $createEmp = $personMap[(string)($row['CreateEmpId'] ?? '')] ?? [];
            $deptId = $person['DeptId'] ?? ($row['DeptId'] ?? null);
            $cabinet = $cabinetByAsset[(string)($row['id'] ?? '')] ?? null;
            $row['AssetsTypeName'] = $typeName;
            $row['BrandModel'] = $model['BrandModel'] ?? '';
            $store = $storeMap[(string)($row['StoreLocationId'] ?? '')] ?? [];
            $row['StoreLocationName'] = $store['StoreLocationName'] ?? '';
            $row['DeptId'] = $deptId;
            $row['DeptName'] = $deptMap[(string)($deptId ?? '')]['DeptName'] ?? '';
            $row['PersonName'] = $person['PersonName'] ?? '';
            $row['SupplierName'] = $supplierMap[(string)($row['SupplierId'] ?? '')]['SupplierName'] ?? '';
            $row['CreateEmpName'] = $createEmp['PersonName'] ?? '';
            $row['TenantName'] = $tenantMap[(string)($row['RentId'] ?? '')]['TenantName'] ?? '';
            $row['ServerName'] = $serverMap[(string)($cabinet['ServerCode'] ?? '')]['ServerName'] ?? '';
            $row['AreaName'] = $areaMap[(string)($cabinet['AreaId'] ?? '')]['AreaName'] ?? '';
            $row['column'] = $cabinet['column'] ?? '';
            $row['position'] = $cabinet['position'] ?? '';
            if (!isset($row['UStatus']) || $row['UStatus'] === null) {
                $row['UStatus'] = $cabinet['UStatus'] ?? null;
            }
            if (!isset($row['UdeviceStatus']) || $row['UdeviceStatus'] === null) {
                $row['UdeviceStatus'] = $cabinet['UdeviceStatus'] ?? null;
            }
            if (!isset($row['UTag']) || $row['UTag'] === null) {
                $row['UTag'] = $cabinet['UTag'] ?? null;
            }
            if (!isset($row['UHigh']) || $row['UHigh'] === '' || $row['UHigh'] === null) {
                $row['UHigh'] = $cabinet['UHigh'] ?? ($cabinet['ULocation'] ?? 0);
            }
            if (!isset($row['UNum']) || $row['UNum'] === '' || $row['UNum'] === null) {
                $cid = (string)($cabinet['id'] ?? '');
                $row['UNum'] = $cabinetUCounts[$cid] ?? 0;
            }
        }
        unset($row);
        return $rows;
    }

    public static function infoAdd()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        $count = (int)($data['number'] ?? 1);
        if ($count <= 0) {
            $count = 1;
        }
        $createData = $data;
        unset($createData['number']);

        $firstId = null;
        $createdIds = [];
        for ($i = 0; $i < $count; $i++) {
            $id = self::crud('dcim-asset')->legacyCreate($createData, [
                'drop_fields' => [
                    'AssetsTypeId',
                    'AssetsTypeName',
                    'AssetsTypeNumber',
                    'BrandModel',
                    'StoreLocationName',
                    'PersonName',
                    'DeptName',
                    'SupplierName',
                    'DeptId',
                ],
                'null_if_empty_fields' => [
                    'RentId',
                    'EmpId',
                    'StoreLocationId',
                    'MaintenanceId',
                    'ModelId',
                    'SupplierId',
                ],
                'defaults' => [
                    'status' => 1,
                    'AssetStatus' => 'I',
                ],
            ]);
            if ($id === null) {
                return;
            }
            if ($firstId === null) {
                $firstId = $id;
            }
            $createdIds[] = $id;
        }
        foreach ($createdIds as $assetId) {
            self::assetChangeLogWrite($assetId, 'inbound', [
                'number' => $count,
                'StoreLocationId' => $data['StoreLocationId'] ?? null,
                'EmpId' => $data['EmpId'] ?? null,
            ], $user['id'] ?? null);
        }
        O_E(['id' => $firstId], tp_msg_success(), 100, false);
    }

    public static function getList()
    {
        $data = Flight::request_data();
        self::requireAuth($data);

        $assetsTypeId = isset($data['AssetsTypeId']) ? trim((string)$data['AssetsTypeId']) : '';
        if ($assetsTypeId !== '') {
            $typeField = self::brandModelTypeField();
            $modelRows = self::crud('dcim-brandmodel')->selectByRawCondition(
                'status = 1 AND ' . $typeField . ' = :tid',
                '',
                [':tid' => $assetsTypeId]
            );
            $modelIds = [];
            foreach ($modelRows as $modelRow) {
                $mid = (string)($modelRow['id'] ?? '');
                if ($mid !== '') {
                    $modelIds[] = $mid;
                }
            }
            if (!$modelIds) {
                O_E(['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => 1]], tp_msg_success(), 100, false);
                return;
            }

            $conditions = ['status = 1'];
            $params = [];
            $search = trim((string)($data['search'] ?? ($data['key'] ?? '')));
            if ($search !== '') {
                $conditions[] = '(AssetsNumber LIKE :search OR AssetsDescribe LIKE :search OR UId LIKE :search)';
                $params[':search'] = '%' . $search . '%';
            }
            $exactMap = [
                'RentId' => 'RentId',
                'AssetStatus' => 'AssetStatus',
                'DeptId' => 'DeptId',
                'EmpId' => 'EmpId',
                'MaintenanceId' => 'MaintenanceId',
                'StoreLocationId' => 'StoreLocationId',
                'ModelId' => 'ModelId',
            ];
            foreach ($exactMap as $requestKey => $dbField) {
                if (!array_key_exists($requestKey, $data)) {
                    continue;
                }
                $value = $data[$requestKey];
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }
                $ph = ':eq_' . $requestKey;
                $conditions[] = $dbField . ' = ' . $ph;
                $params[$ph] = $value;
            }

            $modelCond = self::buildInCondition('ModelId', $modelIds, 'mid_', $params);
            if ($modelCond === null) {
                O_E(['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => 1]], tp_msg_success(), 100, false);
                return;
            }
            $conditions[] = $modelCond;

            $page = max(1, (int)($data['pageNo'] ?? ($data['page'] ?? 1)));
            $pageSize = (int)($data['pageSize'] ?? ($data['limit'] ?? 15));
            if ($pageSize <= 0) {
                $pageSize = 15;
            }
            $list = self::crud('dcim-asset')->selectWithPagination(
                implode(' AND ', $conditions),
                $params,
                'ORDER BY id DESC',
                $page,
                $pageSize
            );
        } else {
            $list = self::crud('dcim-asset')->legacyList($data, [
                'base_where' => ['status = 1'],
                'search_fields' => ['AssetsNumber', 'AssetsDescribe', 'UId'],
                'exact_filters' => [
                    'RentId' => 'RentId',
                    'AssetStatus' => 'AssetStatus',
                    'DeptId' => 'DeptId',
                    'EmpId' => 'EmpId',
                    'MaintenanceId' => 'MaintenanceId',
                    'StoreLocationId' => 'StoreLocationId',
                    'ModelId' => 'ModelId',
                ],
                'order_by' => 'ORDER BY id DESC',
            ]);
        }
        if ($list === null) {
            return;
        }
        $list['info'] = self::enrichAssetRows(is_array($list['info'] ?? null) ? $list['info'] : []);
        $num = !empty($list['info']) ? count($list['info']) : false;
        O_E($list, tp_msg_success(), 100, $num);
    }

    public static function getInfo()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $info = self::crud('dcim-asset')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if ($info) {
            $enriched = self::enrichAssetRows([$info]);
            $info = $enriched ? $enriched[0] : $info;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function infoUpdate()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $res = self::crud('dcim-asset')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'drop_fields' => [
                'AssetsTypeId',
                'AssetsTypeName',
                'AssetsTypeNumber',
                'BrandModel',
                'StoreLocationName',
                'PersonName',
                'DeptName',
                'SupplierName',
                'DeptId',
            ],
            'null_if_empty_fields' => [
                'RentId',
                'EmpId',
                'StoreLocationId',
                'MaintenanceId',
                'ModelId',
                'SupplierId',
            ],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function infoDel()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $res = self::crud('dcim-asset')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function AssetsChangeND()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (empty($data['id'])) {
            result_json(400, dcim_msg('common.id_required'), false, 0);
        }
        $hasUpdateField = false;
        if (array_key_exists('AssetsNumber', $data)) {
            $hasUpdateField = true;
        }
        if (array_key_exists('AssetsDescribe', $data)) {
            $hasUpdateField = true;
        }
        if ($hasUpdateField) {
            $res = self::crud('dcim-asset')->legacyUpdate($data, [
                'id_required_message' => dcim_msg('common.id_required'),
                'only_fields' => ['AssetsNumber', 'AssetsDescribe'],
            ]);
            if ($res === null) {
                return;
            }
        }
        O_E(true, tp_msg_success(), 100, 0);
    }

    public static function AssetsDeal()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        if (empty($data['AssetsId']) || empty($data['DealWay'])) {
            P_E(dcim_msg('common.param_missing'));
        }
        $result = self::crud('dcim-assetdeal')->legacyUpdateAssetStatusWithRecord($data, [
            'asset_table' => 'dcim-asset',
            'record_table' => 'dcim-assetdeal',
            'id_param' => 'AssetsId',
            'allow_csv_ids' => false,
            'status_param' => 'DealWay',
            'asset_status_field' => 'AssetStatus',
            'record_fk_field' => 'AssetsId',
            'record_mode_field' => 'DealWay',
            'record_extra_fields' => [
                'DealReason' => 'DealReason',
            ],
            'record_default_fields' => [
                'DealReason' => '',
            ],
            'id_required_message' => dcim_msg('common.param_missing'),
            'status_required_message' => dcim_msg('common.param_missing'),
            'not_found_message' => dcim_msg('error.asset_not_found'),
        ]);
        if ($result === null) {
            return;
        }
        foreach (($result['asset_ids'] ?? [(string)($data['AssetsId'] ?? '')]) as $assetId) {
            $assetId = trim((string)$assetId);
            if ($assetId === '') {
                continue;
            }
            self::assetChangeLogWrite($assetId, 'dispose', [
                'DealWay' => $data['DealWay'] ?? '',
                'DealReason' => $data['DealReason'] ?? '',
            ], $user['id'] ?? null);
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function AssetsPutout()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        if (empty($data['id']) || empty($data['PutoutWay'])) {
            P_E(dcim_msg('common.param_missing'));
        }
        if (!isset($data['PutoutTime']) || $data['PutoutTime'] === '') {
            $data['PutoutTime'] = date('Y-m-d H:i:s');
        }
        if (!isset($data['PutoutStatus']) || $data['PutoutStatus'] === '') {
            $data['PutoutStatus'] = 'false';
        }
        $result = self::crud('dcim-assetputout')->legacyUpdateAssetStatusWithRecord($data, [
            'asset_table' => 'dcim-asset',
            'record_table' => 'dcim-assetputout',
            'id_param' => 'id',
            'allow_csv_ids' => true,
            'status_param' => 'PutoutWay',
            'asset_status_field' => 'AssetStatus',
            'record_fk_field' => 'AssetsId',
            'record_mode_field' => 'PutoutWay',
            'record_extra_fields' => [
                'PutoutTime' => 'PutoutTime',
                'AreaId' => 'AreaId',
                'ServerCode' => 'ServerCode',
                'DeptId' => 'DeptId',
                'EmpId' => 'EmpId',
                'PlanReturnTime' => 'PlanReturnTime',
                'PutoutStatus' => 'PutoutStatus',
            ],
            'record_default_fields' => [
                'PutoutStatus' => 'false',
            ],
            'record_null_if_empty_fields' => ['AreaId', 'ServerCode', 'DeptId', 'EmpId', 'PlanReturnTime'],
            'id_required_message' => dcim_msg('common.param_missing'),
            'status_required_message' => dcim_msg('common.param_missing'),
            'not_found_message' => dcim_msg('error.asset_not_found'),
            'record_insert_failed_message' => dcim_msg('error.putout_record_insert_failed'),
        ]);
        if ($result === null) {
            return;
        }
        if (($result['record_count'] ?? 0) <= 0) {
            S_E(dcim_msg('error.putout_record_insert_failed'));
        }
        foreach (($result['asset_ids'] ?? []) as $assetId) {
            self::assetChangeLogWrite($assetId, dcim_msg('assets.change_log_outbound'), [
                'PutoutWay' => $data['PutoutWay'] ?? '',
                'PutoutTime' => $data['PutoutTime'] ?? '',
                'PutoutStatus' => $data['PutoutStatus'] ?? 'false',
                'AreaId' => $data['AreaId'] ?? null,
                'ServerCode' => $data['ServerCode'] ?? null,
                'DeptId' => $data['DeptId'] ?? null,
                'EmpId' => $data['EmpId'] ?? null,
            ], $user['id'] ?? null);
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function AssetsPrivate()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        if (!array_key_exists('id', $data)) {
            foreach (['AssetsId', 'AssetId', 'assetsId', 'assetId'] as $idKey) {
                if (array_key_exists($idKey, $data)) {
                    $data['id'] = $data[$idKey];
                    break;
                }
            }
        }
        if (array_key_exists('id', $data) && is_string($data['id']) && strtolower(trim($data['id'])) === 'null') {
            $data['id'] = '';
        }
        if (!isset($data['id']) || trim((string)$data['id']) === '') {
            P_E(dcim_msg('common.id_required'));
        }
        $normalizeAttrVal = static function ($value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed === '' || strtolower($trimmed) === 'null' || strtolower($trimmed) === 'undefined') {
                    return null;
                }
                return $value;
            }
            return $value;
        };
        if (array_key_exists('Attr', $data)) {
            if (is_string($data['Attr'])) {
                $decoded = json_decode($data['Attr'], true);
                if (is_array($decoded)) {
                    $data['Attr'] = $decoded;
                }
            }
            if (is_array($data['Attr'])) {
                foreach ($data['Attr'] as $idx => $attrItem) {
                    if (!is_array($attrItem)) {
                        continue;
                    }
                    foreach (['AttributeVal', 'AttrValue', 'Value', 'DataVal', 'DataValue'] as $valKey) {
                        if (array_key_exists($valKey, $attrItem)) {
                            $attrItem[$valKey] = $normalizeAttrVal($attrItem[$valKey]);
                        }
                    }
                    $data['Attr'][$idx] = $attrItem;
                }
            }
        }
        $result = self::crud('dcim-assetprivate')->legacyReplaceAttrMappings($data, [
            'owner_field' => 'AssetsId',
            'owner_param' => 'id',
            'attr_key' => 'Attr',
            'owner_required_message' => dcim_msg('common.id_required'),
            'attr_required_message' => dcim_msg('error.attr_required'),
            'valid_attr_required_message' => dcim_msg('error.valid_attr_required'),
        ]);
        if ($result === null) {
            return;
        }
        foreach (($result['asset_ids'] ?? []) as $assetId) {
            self::assetChangeLogWrite($assetId, 'dispose', [
                'DealWay' => $data['DealWay'] ?? '',
                'DealReason' => $data['DealReason'] ?? '',
            ], $user['id'] ?? null);
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function GetAssetsPrivate()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }
        $rows = self::crud('dcim-assetprivate')->selectByRawCondition(
            'status = 1 AND AssetsId = :aid',
            'ORDER BY id DESC',
            [':aid' => $data['id']]
        );
        $attrIds = [];
        foreach ($rows as $row) {
            if (!empty($row['AttributeId'])) {
                $attrIds[] = $row['AttributeId'];
            }
        }
        $attrMap = [];
        foreach (self::crud('dcim-assetattr')->selectByIds($attrIds, ['id', 'AttrNumber', 'AttrName', 'DataType', 'AttrUnit']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $attrMap[$key] = $item;
            }
        }
        $result = [];
        foreach ($rows as $row) {
            $attr = $attrMap[(string)($row['AttributeId'] ?? '')] ?? [];
            $result[] = [
                'id' => $row['id'] ?? null,
                'AttributeId' => $row['AttributeId'] ?? null,
                'AttributeVal' => $row['AttributeVal'] ?? '',
                'AttrNumber' => $attr['AttrNumber'] ?? '',
                'AttrName' => $attr['AttrName'] ?? '',
                'DataType' => $attr['DataType'] ?? '',
                'AttrUnit' => $attr['AttrUnit'] ?? '',
            ];
        }
        O_E($result, tp_msg_success(), 100, $result ? count($result) : false);
    }

    public static function GetAssetsAttr()
    {
        self::requireAuth(Flight::request_data());
        O_E([], tp_msg_success(), 100, false);
    }

    public static function RelationMaintenance()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (empty($data['id']) || empty($data['AssetsId'])) {
            P_E(dcim_msg('common.param_missing'));
        }
        $assetIds = strpos((string) $data['AssetsId'], ',') !== false ? explode(',', $data['AssetsId']) : [$data['AssetsId']];
        $assetCrud = self::crud('dcim-asset');
        foreach ($assetIds as $aid) {
            $aid = trim((string) $aid);
            if ($aid === '') {
                continue;
            }
            $existing = $assetCrud->findOne([['id', '=', $aid], ['status', '=', 1]]);
            if (!$existing) {
                P_E(dcim_msg('error.asset_missing'));
            }
            if (!empty($existing['MaintenanceId']) && $existing['MaintenanceId'] != 0) {
                P_E(dcim_msg('error.asset_already_related_maintenance'));
            }
            $updateData = $data;
            $updateData['id'] = $aid;
            $updateData['MaintenanceId'] = $data['id'];
            $res = $assetCrud->legacyUpdate($updateData, [
                'id_required_message' => dcim_msg('common.id_required'),
                'only_fields' => ['MaintenanceId'],
            ]);
            if ($res === null) {
                return;
            }
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function CancelRelationMaintenance()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (empty($data['id']) || empty($data['MaintenanceId'])) {
            P_E(dcim_msg('common.param_missing'));
        }
        $res = self::crud('dcim-asset')->legacyUpdateWhere([
            ['id', '=', $data['id']],
            ['MaintenanceId', '=', $data['MaintenanceId']],
            ['status', '=', 1],
        ], ['MaintenanceId' => 0]);
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function AssetsLocation()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $assetId = $data['id'] ?? 0;
        $asset = self::crud('dcim-asset')->findOne([['id', '=', $assetId], ['status', '=', 1]]);
        if (!$asset) {
            O_E([], tp_msg_success(), 100, false);
            return;
        }
        O_E([
            'PersonName' => '',
            'DeptName' => '',
            'StoreLocationName' => '',
        ], tp_msg_success(), 100, 1);
    }

    public static function ChangeAssetsLocation()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }
        $hasUpdateField = isset($data['StoreLocationId']) || isset($data['EmpId']);
        if (!$hasUpdateField) {
            O_E(true, tp_msg_success(), 100, 1);
            return;
        }
        $oldAsset = self::crud('dcim-asset')->findOne([['id', '=', $data['id']], ['status', '=', 1]]) ?: [];
        $oldLocation = self::currentAssetCabinetLocation($data['id']);
        $oldCabinetId = (string)($oldLocation['CabinetId'] !== '' ? $oldLocation['CabinetId'] : ($oldAsset['StoreLocationId'] ?? ''));
        $newCabinetId = $oldCabinetId;
        if (array_key_exists('CabinetId', $data)) {
            $newCabinetId = (string)$data['CabinetId'];
        } elseif (array_key_exists('StoreLocationId', $data)) {
            $newCabinetId = (string)$data['StoreLocationId'];
        }
        $oldULocation = (string)($oldLocation['ULocation'] ?? '');
        $newULocation = array_key_exists('ULocation', $data) ? (string)$data['ULocation'] : $oldULocation;
        $oldEmpId = (string)($oldAsset['EmpId'] ?? '');
        $newEmpId = array_key_exists('EmpId', $data) ? (string)$data['EmpId'] : $oldEmpId;
        $res = self::crud('dcim-asset')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['StoreLocationId', 'EmpId'],
        ]);
        if ($res === null) {
            return;
        }
        self::assetChangeLogWrite($data['id'], 'location_owner_change', [
            'StoreLocationId' => $data['StoreLocationId'] ?? null,
            'OldCabinetId' => $oldCabinetId,
            'CabinetId' => $newCabinetId,
            'OldULocation' => $oldULocation,
            'ULocation' => $newULocation,
            'OldEmpId' => $oldEmpId,
            'EmpId' => $data['EmpId'] ?? null,
        ], $user['id'] ?? null);
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function ReturnAsset()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        if (isset($data['data']) && $data['data'] !== '' && $data['data'] !== null && !is_array($data['data'])) {
            P_E(dcim_msg('error.data_must_array'));
        }
        if (empty($data['data'])) {
            $hasAssetsId = isset($data['AssetsId']) && $data['AssetsId'] !== '';
            $hasDetailId = isset($data['DetailID']) && $data['DetailID'] !== '';
            if (!$hasAssetsId && !$hasDetailId) {
                P_E(dcim_msg('error.assets_or_detail_required'));
            }
        }
        $assetIds = [];
        if (!empty($data['AssetsId'])) {
            foreach (explode(',', (string)$data['AssetsId']) as $idItem) {
                $idItem = trim($idItem);
                if ($idItem !== '') {
                    $assetIds[] = $idItem;
                }
            }
        } elseif (!empty($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $aid = trim((string)($item['AssetsId'] ?? ''));
                if ($aid !== '') {
                    $assetIds[] = $aid;
                }
            }
        }
        $assetIds = array_values(array_unique($assetIds));
        if (!$assetIds) {
            O_E(true, tp_msg_success(), 100, 1);
            return;
        }

        $assetUpdate = ['AssetStatus' => 'I'];
        if (array_key_exists('StoreLocationId', $data)) {
            $assetUpdate['StoreLocationId'] = ($data['StoreLocationId'] === '' ? null : $data['StoreLocationId']);
        }
        if (array_key_exists('EmpId', $data)) {
            $assetUpdate['EmpId'] = ($data['EmpId'] === '' ? null : $data['EmpId']);
        }
        foreach ($assetIds as $assetId) {
            self::crud('dcim-asset')->legacyUpdateWhere(
                [
                    ['id', '=', $assetId],
                    ['status', '=', 1],
                ],
                $assetUpdate,
                [
                    'skip_auth' => true,
                ]
            );
            self::crud('dcim-cabinetu')->legacyUpdateWhere(
                [
                    ['AssetsId', '=', $assetId],
                    ['status', '=', 1],
                ],
                [
                    'AssetsId' => null,
                    'UStatus' => null,
                ],
                [
                    'skip_auth' => true,
                ]
            );
            self::assetChangeLogWrite($assetId, 'return', [
                'StoreLocationId' => $data['StoreLocationId'] ?? null,
                'EmpId' => $data['EmpId'] ?? null,
                'type' => $data['type'] ?? 'back',
            ], $user['id'] ?? null);
        }
        if (!empty($data['id'])) {
            self::crud('dcim-assetputout')->legacyUpdate(
                [
                    'id' => $data['id'],
                    'PutoutStatus' => 'true',
                    'status' => -1,
                ],
                [
                    'skip_auth' => true,
                    'id_required_message' => dcim_msg('common.id_required'),
                    'only_fields' => ['PutoutStatus', 'status'],
                ]
            );
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function AssetsAllDetail()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $assetId = trim((string)($data['id'] ?? ($data['AssetsId'] ?? ($data['AssetId'] ?? ($data['Lsh'] ?? '')))));
        if ($assetId === '') {
            O_E([], tp_msg_success(), 100, false);
            return;
        }
        $asset = self::crud('dcim-asset')->findOne([['id', '=', $assetId], ['status', '=', 1]]);
        if (!$asset) {
            $assetNumber = trim((string)($data['AssetsNumber'] ?? ($data['AssetNumber'] ?? '')));
            if ($assetNumber !== '') {
                $assetRows = self::crud('dcim-asset')->selectByRawCondition(
                    'status = 1 AND AssetsNumber = :assets_no',
                    'LIMIT 1',
                    [':assets_no' => $assetNumber]
                );
                $asset = $assetRows ? ($assetRows[0] ?? []) : [];
            }
        }
        if (!$asset) {
            O_E([], tp_msg_success(), 100, false);
            return;
        }
        $assetId = (string)($asset['id'] ?? $assetId);
        $model = [];
        if (!empty($asset['ModelId'])) {
            $model = self::crud('dcim-brandmodel')->findOne([['id', '=', $asset['ModelId']], ['status', '=', 1]]) ?: [];
        }
        $assetType = [];
        $typeId = '';
        if (!empty($asset['AssetsTypeId'])) {
            $typeId = (string)$asset['AssetsTypeId'];
        }
        if ($typeId === '' && $model) {
            $typeId = self::brandModelTypeId($model);
        }
        if ($typeId !== '') {
            $assetType = self::crud('dcim-assettype')->findOne([['id', '=', $typeId], ['status', '=', 1]]) ?: [];
        }
        $supplier = [];
        if (!empty($asset['SupplierId'])) {
            $supplier = self::crud('dcim-supplier')->findOne([['id', '=', $asset['SupplierId']], ['status', '=', 1]]) ?: [];
        }
        $person = [];
        if (!empty($asset['EmpId'])) {
            $person = self::crud('dcim-person')->findOne([['id', '=', $asset['EmpId']], ['status', '=', 1]]) ?: [];
        }
        $dept = [];
        $deptId = (string)($person['DeptId'] ?? ($asset['DeptId'] ?? ''));
        if ($deptId !== '') {
            $dept = self::crud('dcim-department')->findOne([['id', '=', $deptId], ['status', '=', 1]]) ?: [];
        }
        $groundingPerson = [];
        $groundingEmpId = (string)($asset['GroundingEmpId'] ?? ($asset['GroundingPersonId'] ?? ''));
        if ($groundingEmpId !== '') {
            $groundingPerson = self::crud('dcim-person')->findOne([['id', '=', $groundingEmpId], ['status', '=', 1]]) ?: [];
        }
        $area = [];
        if (!empty($asset['AreaId'])) {
            $area = self::crud('dcim-area')->findOne([['id', '=', $asset['AreaId']], ['status', '=', 1]]) ?: [];
        }
        $server = [];
        $serverCode = trim((string)($asset['ServerCode'] ?? ''));
        if ($serverCode !== '') {
            $server = self::crud('dcim-server')->findOne([['id', '=', $serverCode], ['status', '=', 1]]) ?: [];
            if (!$server) {
                $serverRows = self::crud('dcim-server')->selectByRawCondition('status = 1 AND ServerCode = :code', 'LIMIT 1', [':code' => $serverCode]);
                $server = $serverRows ? ($serverRows[0] ?? []) : [];
            }
        }
        $uRows = self::crud('dcim-cabinetu')->selectByRawCondition(
            'status = 1 AND AssetsId = :aid',
            'ORDER BY ULocation ASC LIMIT 1',
            [':aid' => $assetId]
        );
        $uInfo = $uRows ? ($uRows[0] ?? []) : [];
        $cabinetInfo = [];
        if (!empty($uInfo['CabinetId'])) {
            $cabinetInfo = self::crud('dcim-cabinet')->findOne([['id', '=', $uInfo['CabinetId']], ['status', '=', 1]]) ?: [];
        } elseif (!empty($asset['CabinetId'])) {
            $cabinetInfo = self::crud('dcim-cabinet')->findOne([['id', '=', $asset['CabinetId']], ['status', '=', 1]]) ?: [];
        } elseif (!empty($assetId)) {
            $cabinetInfo = self::crud('dcim-cabinet')->findOne([['AssetsId', '=', $assetId], ['status', '=', 1]]) ?: [];
        }
        if (!$area && !empty($cabinetInfo['AreaId'])) {
            $area = self::crud('dcim-area')->findOne([['id', '=', $cabinetInfo['AreaId']], ['status', '=', 1]]) ?: [];
        }
        if ($serverCode === '' && !empty($cabinetInfo['ServerCode'])) {
            $serverCode = trim((string)$cabinetInfo['ServerCode']);
        }
        if (!$server && $serverCode !== '') {
            $server = self::crud('dcim-server')->findOne([['id', '=', $serverCode], ['status', '=', 1]]) ?: [];
            if (!$server) {
                $serverRows = self::crud('dcim-server')->selectByRawCondition('status = 1 AND ServerCode = :code', 'LIMIT 1', [':code' => $serverCode]);
                $server = $serverRows ? ($serverRows[0] ?? []) : [];
            }
        }
        $brandModelName = trim((string)($model['BrandModel'] ?? ''));
        if ($brandModelName === '') {
            $brandModelName = trim((string)($asset['BrandModel'] ?? ''));
        }
        $personName = (string)($person['PersonName'] ?? ($person['UserName'] ?? ($person['EmpName'] ?? '')));
        $groundingPersonName = (string)($groundingPerson['PersonName'] ?? ($groundingPerson['UserName'] ?? ($groundingPerson['EmpName'] ?? '')));
        $deptName = (string)($dept['DeptName'] ?? ($asset['DeptName'] ?? ''));

        O_E([
            'AssetsNumber' => $asset['AssetsNumber'] ?? '',
            'AssetsTypeName' => (string)($assetType['AssetsTypeName'] ?? ''),
            'AssetsDescribe' => $asset['AssetsDescribe'] ?? '',
            'BrandModel' => $brandModelName,
            'SupplierName' => (string)($supplier['SupplierName'] ?? ''),
            'BuyTime' => $asset['BuyTime'] ?? '',
            'PersonName' => $personName,
            'DeptName' => $deptName,
            'ModelFrontImg' => (string)($model['ModelFrontImg'] ?? ($asset['ModelFrontImg'] ?? '')),
            'ModelAfterImg' => (string)($model['ModelAfterImg'] ?? ($asset['ModelAfterImg'] ?? '')),
            'ModelAllImg' => (string)($model['ModelAllImg'] ?? ($asset['ModelAllImg'] ?? '')),
            'GroundingTime' => (string)($asset['GroundingTime'] ?? ''),
            'GroundingPersonName' => $groundingPersonName,
            'ULocation' => (string)($uInfo['ULocation'] ?? ($asset['ULocation'] ?? '')),
            'UTag' => (string)($uInfo['UTag'] ?? ($asset['UTag'] ?? '')),
            'AreaName' => (string)($area['AreaName'] ?? ''),
            'ServerName' => (string)($server['ServerName'] ?? ''),
        ], tp_msg_success(), 100, 1);
    }

    public static function createAssetMsg()
    {
        $data = Flight::request_data();
        $id = self::crud('dcim-assetmsg')->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function getAssetMsgList()
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-assetmsg')->legacyList($data, [
            'base_where' => ['status = 1'],
            'exact_filters' => ['AssetsId' => 'AssetsId'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $num = $result['info'] ? count($result['info']) : false;
        O_E($result, tp_msg_success(), 100, $num);
    }

    public static function getAssetMsgDetail()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-assetmsg')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function changeAssetMsg()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-assetmsg')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function delAssetMsg()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-assetmsg')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function checkAssetMsg()
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-assetmsg');
        if (!$crud->legacyEnsureAuth($data)) {
            return;
        }
        $data['CheckStatus'] = 1;
        $res = $crud->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['CheckStatus'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    private static function assetsInstallFilterAssetIds(array $data): ?array
    {
        $needFilter = false;
        $conditions = ['status = 1'];
        $params = [];
        if (!empty($data['search'])) {
            $needFilter = true;
            $conditions[] = '(AssetsNumber LIKE :search OR AssetsDescribe LIKE :search)';
            $params[':search'] = '%' . $data['search'] . '%';
        }
        if (!$needFilter) {
            return null;
        }
        $rows = self::crud('dcim-asset')->selectByRawCondition(implode(' AND ', $conditions), '', $params);
        return array_column($rows, 'id');
    }

    private static function assetsInstallEnrichRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $asset = self::crud('dcim-asset')->findOne([['id', '=', $row['AssetsId']], ['status', '=', 1]]);
            $brand = $asset ? self::crud('dcim-brandmodel')->findOne([['id', '=', $asset['ModelId'] ?? 0]]) : null;
            $brandTypeId = self::brandModelTypeId(is_array($brand) ? $brand : []);
            $type = $brandTypeId !== '' ? self::crud('dcim-assettype')->findOne([['id', '=', $brandTypeId]]) : null;
            $person = $asset ? self::crud('dcim-person')->findOne([['id', '=', $asset['EmpId'] ?? 0]]) : null;
            $dept = $person ? self::crud('dcim-department')->findOne([['id', '=', $person['DeptId'] ?? 0]]) : null;
            $area = self::crud('dcim-area')->findOne([['id', '=', $row['AreaId'] ?? 0]]);
            $server = self::crud('dcim-server')->findOne([['id', '=', $row['ServerCode'] ?? 0]]);

            $row['AssetsNumber'] = $asset['AssetsNumber'] ?? '';
            $row['AssetsDescribe'] = $asset['AssetsDescribe'] ?? '';
            $row['UId'] = $asset['UId'] ?? ($row['UId'] ?? '');
            $row['GatewayId'] = $asset['GatewayId'] ?? ($row['GatewayId'] ?? '');
            $row['BrandModel'] = $brand['BrandModel'] ?? '';
            $row['AssetsTypeName'] = $type['AssetsTypeName'] ?? '';
            $row['EmpId'] = $asset['EmpId'] ?? null;
            $row['PersonName'] = $person['PersonName'] ?? '';
            $row['DeptId'] = $dept['id'] ?? ($person['DeptId'] ?? null);
            $row['DeptName'] = $dept['DeptName'] ?? '';
            $row['AreaName'] = $area['AreaName'] ?? '';
            $row['ServerName'] = $server['ServerName'] ?? '';
            $row['position'] = '';
            $row['RepairTime'] = '';
            $row['UTag'] = $row['UTag'] ?? '';

            if (!empty($row['CabinetId'])) {
                $cabinet = self::crud('dcim-cabinet')->findOne([['id', '=', $row['CabinetId']], ['status', '=', 1]]);
                if ($cabinet) {
                    $row['position'] = $cabinet['position'] ?? '';
                    $row['GatewayId'] = $row['GatewayId'] !== '' ? $row['GatewayId'] : ($cabinet['GatewayId'] ?? '');
                    $row['UTag'] = $row['UTag'] !== '' ? $row['UTag'] : ($cabinet['UTag'] ?? '');
                }
            }
            if (!empty($row['AssetsId'])) {
                $uRows = self::crud('dcim-cabinetu')->selectByRawCondition(
                    'status = 1 AND AssetsId = :aid',
                    'ORDER BY ULocation ASC LIMIT 1',
                    [':aid' => $row['AssetsId']]
                );
                if ($uRows) {
                    $uRow = $uRows[0];
                    $row['UId'] = $row['UId'] !== '' ? $row['UId'] : ($uRow['UId'] ?? ($uRow['id'] ?? ''));
                    $row['GatewayId'] = $row['GatewayId'] !== '' ? $row['GatewayId'] : ($uRow['GatewayId'] ?? '');
                    $row['UTag'] = $row['UTag'] !== '' ? $row['UTag'] : ($uRow['UTag'] ?? '');
                }
                $repairs = self::crud('dcim-assetrepair')->selectByRawCondition(
                    'status = 1 AND AssetsId = :aid',
                    'ORDER BY create_time DESC',
                    [':aid' => $row['AssetsId']]
                );
                if ($repairs) {
                    $row['RepairTime'] = $repairs[0]['create_time'] ?? '';
                }
            }
        }
        unset($row);
        return $rows;
    }

    public static function assetsInstallInfoAdd()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);

        if (empty($data['AssetsId'])) {
            P_E(dcim_msg('error.assets_id_required'));
        }
        $asset = self::crud('dcim-asset')->findOne([['id', '=', $data['AssetsId']], ['status', '=', 1]]);
        if (!$asset || ($asset['AssetStatus'] ?? '') !== 'O') {
            P_E(dcim_msg('error.asset_status_invalid'));
        }

        $syncData = [
            'AssetsId' => $data['AssetsId'],
            'PutoutId' => $data['PutoutId'] ?? null,
            'AssetsNumber' => array_key_exists('AssetsNumber', $data) ? $data['AssetsNumber'] : ($asset['AssetsNumber'] ?? ''),
            'AssetsDescribe' => array_key_exists('AssetsDescribe', $data) ? $data['AssetsDescribe'] : ($asset['AssetsDescribe'] ?? ''),
        ];
        if (array_key_exists('EmpId', $data)) {
            $syncData['EmpId'] = $data['EmpId'];
        }
        self::crud('dcim-assetinstall')->legacySyncAssetAndPutout($syncData, [
            'asset_id_param' => 'AssetsId',
            'asset_status_value' => 'T',
            'asset_update_field_map' => [
                'AssetsNumber' => 'AssetsNumber',
                'AssetsDescribe' => 'AssetsDescribe',
                'EmpId' => 'EmpId',
            ],
            'putout_id_param' => 'PutoutId',
            'putout_status_field' => 'PutoutStatus',
            'putout_status_value' => 'true',
        ]);

        $payload = [
            'AssetsId' => $data['AssetsId'],
            'AreaId' => $data['AreaId'] ?? null,
            'ServerCode' => $data['ServerCode'] ?? null,
            'InstallLocation' => $data['InstallLocation'] ?? null,
            'CabinetId' => $data['CabinetId'] ?? null,
            'status' => $data['status'] ?? 1,
        ];
        foreach (['token', 'auth', 'Auth', 'authorization', 'Authorization'] as $authKey) {
            if (array_key_exists($authKey, $data)) {
                $payload[$authKey] = $data[$authKey];
            }
        }
        $id = self::crud('dcim-assetinstall')->legacyCreate($payload, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        self::assetChangeLogWrite($data['AssetsId'] ?? null, 'install', [
            'AreaId' => $data['AreaId'] ?? null,
            'ServerCode' => $data['ServerCode'] ?? null,
            'InstallLocation' => $data['InstallLocation'] ?? null,
            'CabinetId' => $data['CabinetId'] ?? null,
        ], $user['id'] ?? null);
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function assetsInstallGetList()
    {
        $data = Flight::request_data();
        self::requireAuth($data);

        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;
        $conditions = ['status = 1'];
        $params = [];

        $assetIds = self::assetsInstallFilterAssetIds($data);
        if (is_array($assetIds)) {
            if (!$assetIds) {
                O_E(['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => $page]], tp_msg_success(), 100, false);
                return;
            }
            $phs = [];
            foreach ($assetIds as $idx => $aid) {
                $ph = ':aid' . $idx;
                $phs[] = $ph;
                $params[$ph] = $aid;
            }
            $conditions[] = 'AssetsId IN (' . implode(',', $phs) . ')';
        }

        foreach (['CabinetId', 'AssetsId', 'AreaId', 'ServerCode'] as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $ph = ':' . $key;
                $conditions[] = $key . ' = ' . $ph;
                $params[$ph] = $data[$key];
            }
        }

        $result = self::crud('dcim-assetinstall')->selectWithPagination(
            implode(' AND ', $conditions),
            $params,
            'ORDER BY id DESC',
            $page,
            $pageSize
        );
        $result['info'] = self::assetsInstallEnrichRows($result['info'] ?? []);
        $num = !empty($result['info']) ? count($result['info']) : false;
        O_E($result, tp_msg_success(), 100, $num);
    }

    public static function assetsInstallGetInfo()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-assetinstall')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if (!$info) {
            O_E([], tp_msg_success(), 100, false);
            return;
        }
        $rows = self::assetsInstallEnrichRows([$info]);
        O_E(['info' => $rows, 'page' => ['total' => 1, 'p_n' => 1, 'p' => 1]], tp_msg_success(), 100, 1);
    }

    public static function assetsInstallInfoUpdate()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }
        $id = $data['id'];
        unset($data['id']);

        $existing = self::crud('dcim-assetinstall')->findOne([['id', '=', $id], ['status', '=', 1]]);
        if (!$existing) {
            P_E(dcim_msg('error.install_record_not_found'));
        }

        if (!empty($existing['AssetsId'])) {
            self::crud('dcim-assetinstall')->legacySyncAssetAndPutout([
                'AssetsId' => $existing['AssetsId'],
            ], [
                'asset_id_param' => 'AssetsId',
                'asset_status_value' => 'O',
            ]);
        }

        $targetAssetId = $data['AssetsId'] ?? ($existing['AssetsId'] ?? null);
        if ($targetAssetId) {
            $syncData = [
                'AssetsId' => $targetAssetId,
                'AssetsNumber' => $data['AssetsNumber'] ?? '',
                'AssetsDescribe' => $data['AssetsDescribe'] ?? '',
            ];
            if (array_key_exists('EmpId', $data)) {
                $syncData['EmpId'] = $data['EmpId'];
            }
            self::crud('dcim-assetinstall')->legacySyncAssetAndPutout($syncData, [
                'asset_id_param' => 'AssetsId',
                'asset_status_value' => 'T',
                'asset_update_field_map' => [
                    'AssetsNumber' => 'AssetsNumber',
                    'AssetsDescribe' => 'AssetsDescribe',
                    'EmpId' => 'EmpId',
                ],
            ]);
        }

        unset($data['AssetsNumber'], $data['AssetsDescribe'], $data['EmpId']);
        $updatePayload = ['id' => $id];
        foreach (['AssetsId', 'AreaId', 'ServerCode', 'InstallLocation', 'CabinetId', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $updatePayload[$field] = $data[$field];
            }
        }
        foreach (['token', 'auth', 'Auth', 'authorization', 'Authorization'] as $authKey) {
            if (array_key_exists($authKey, $data)) {
                $updatePayload[$authKey] = $data[$authKey];
            }
        }

        $res = self::crud('dcim-assetinstall')->legacyUpdate($updatePayload, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        self::assetChangeLogWrite($targetAssetId ?: ($existing['AssetsId'] ?? null), 'install_change', [
            'AreaId' => $updatePayload['AreaId'] ?? null,
            'ServerCode' => $updatePayload['ServerCode'] ?? null,
            'InstallLocation' => $updatePayload['InstallLocation'] ?? null,
            'CabinetId' => $updatePayload['CabinetId'] ?? null,
        ], $user['id'] ?? null);
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function assetsInstallCreateGroundingAssets()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        $createData = $data;
        unset($createData['AssetsDescribe'], $createData['AssetsNumber'], $createData['PutoutId']);
        if (empty($createData['CreateEmpId'])) {
            $createData['CreateEmpId'] = $user['id'] ?? 0;
        }

        $cabinetId = (int)($createData['CabinetId'] ?? 0);
        $assetId = (int)($createData['AssetsId'] ?? 0);
        $uLocation = (int)($createData['ULocation'] ?? 0);
        $uHigh = (int)($createData['UHigh'] ?? 1);
        if ($uHigh <= 0) {
            $uHigh = 1;
        }
        if ($cabinetId > 0 && $uLocation <= 0) {
            P_E(dcim_msg('error.u_location_required'));
        }
        $slotsToOccupy = [];
        if ($cabinetId > 0 && $uLocation > 0) {
            $uRows = self::crud('dcim-cabinetu')->selectByRawCondition(
                'status = 1 AND CabinetId = :cid',
                'ORDER BY ULocation ASC',
                [':cid' => $cabinetId]
            );
            $uMap = [];
            foreach ($uRows as $uRow) {
                $loc = (int)($uRow['ULocation'] ?? 0);
                if ($loc > 0) {
                    $uMap[$loc] = $uRow;
                }
            }
            for ($i = 0; $i < $uHigh; $i++) {
                $loc = $uLocation + $i;
                $slot = $uMap[$loc] ?? null;
                if (!$slot) {
                    P_E(dcim_msg('error.insufficient_u_capacity'));
                }
                $uStatus = trim((string)($slot['UStatus'] ?? ''));
                $slotAssetId = trim((string)($slot['AssetsId'] ?? ''));
                if ($uStatus !== '' || $slotAssetId !== '') {
                    P_E(dcim_msg('error.u_location_occupied'));
                }
                $slotsToOccupy[] = (int)$slot['id'];
            }
            if (!$slotsToOccupy) {
                P_E(dcim_msg('error.insufficient_u_capacity'));
            }
        }

        self::crud('dcim-assetgrounding')->legacySyncAssetAndPutout($data, [
            'asset_id_param' => 'AssetsId',
            'asset_status_value' => 'F',
            'status_requires_field_changes' => true,
            'asset_update_field_map' => [
                'AssetsNumber' => 'AssetsNumber',
                'AssetsDescribe' => 'AssetsDescribe',
            ],
            'putout_id_param' => 'PutoutId',
            'putout_status_field' => 'PutoutStatus',
            'putout_status_value' => 'true',
        ]);

        $id = self::crud('dcim-assetgrounding')->legacyCreate($createData, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        if ($cabinetId > 0 && $assetId > 0) {
            self::crud('dcim-cabinet')->legacyUpdate(
                [
                    'id' => $cabinetId,
                    'AssetsId' => $assetId,
                    'ULocation' => ($uLocation > 0 ? $uLocation : null),
                    'UHigh' => $uHigh,
                ],
                [
                    'skip_auth' => true,
                    'id_required_message' => dcim_msg('common.id_required'),
                    'only_fields' => ['AssetsId', 'ULocation', 'UHigh'],
                ]
            );
            foreach ($slotsToOccupy as $slotId) {
                self::crud('dcim-cabinetu')->legacyUpdate(
                    [
                        'id' => $slotId,
                        'AssetsId' => $assetId,
                        'UStatus' => 'occupied',
                    ],
                    [
                        'skip_auth' => true,
                        'id_required_message' => dcim_msg('common.id_required'),
                        'only_fields' => ['AssetsId', 'UStatus'],
                    ]
                );
            }
        }
        self::assetChangeLogWrite($assetId, 'grounding', [
            'CabinetId' => $cabinetId,
            'ULocation' => $uLocation,
            'UHigh' => $uHigh,
            'PutoutId' => $data['PutoutId'] ?? null,
        ], $user['id'] ?? null);
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function assetsInstallGetGroundingAssetsList()
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-assetgrounding')->legacyList($data, [
            'base_where' => ['status = 1'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $result['info'] = self::assetsInstallEnrichRows(is_array($result['info'] ?? null) ? $result['info'] : []);
        O_E($result, tp_msg_success(), 100, !empty($result['info']) ? count($result['info']) : false);
    }

    public static function assetsInstallGetGroundingAssetsDetail()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-assetgrounding')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if ($info) {
            $rows = self::assetsInstallEnrichRows([$info]);
            $info = $rows ? $rows[0] : $info;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function assetsInstallChangeGroundingAssets()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }
        $updateData = $data;
        unset($updateData['AssetsDescribe'], $updateData['AssetsNumber'], $updateData['PutoutId']);

        self::crud('dcim-assetgrounding')->legacySyncAssetAndPutout($data, [
            'asset_id_param' => 'AssetsId',
            'asset_status_value' => 'F',
            'status_requires_field_changes' => true,
            'asset_update_field_map' => [
                'AssetsNumber' => 'AssetsNumber',
                'AssetsDescribe' => 'AssetsDescribe',
            ],
            'putout_id_param' => 'PutoutId',
            'putout_status_field' => 'PutoutStatus',
            'putout_status_value' => 'true',
        ]);

        $res = self::crud('dcim-assetgrounding')->legacyUpdate($updateData, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        self::assetChangeLogWrite($data['AssetsId'] ?? null, 'grounding_change', [
            'CabinetId' => $updateData['CabinetId'] ?? null,
            'ULocation' => $updateData['ULocation'] ?? null,
            'UHigh' => $updateData['UHigh'] ?? null,
            'PutoutId' => $data['PutoutId'] ?? null,
        ], $user['id'] ?? null);
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function assetsInstallAndCabinet()
    {
        $data = self::cabinetRequestDataCompat();
        self::requireAuth($data);
        if (empty($data['CabinetId']) || empty($data['id'])) {
            P_E(dcim_msg('error.cabinet_and_id_required'));
        }
        $cabinet = self::crud('dcim-cabinet')->findOne([['id', '=', $data['CabinetId']], ['status', '=', 1]]);
        if ($cabinet && !empty($cabinet['AssetsId'])) {
            P_E(dcim_msg('error.cabinet_already_occupied'));
        }
        $cabinetRes = self::crud('dcim-cabinet')->legacyUpdate([
            'id' => $data['CabinetId'],
            'AssetsId' => $data['id'],
        ], [
            'id_required_message' => dcim_msg('error.cabinet_id_required'),
            'only_fields' => ['AssetsId'],
        ]);
        if ($cabinetRes === null) {
            return;
        }
        $res = self::crud('dcim-assetinstall')->legacyUpdate([
            'id' => $data['id'],
            'CabinetId' => $data['CabinetId'],
        ], [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['CabinetId'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    private static function cabinetAttrMetricKey(string $attrName): ?string
    {
        if (mb_strpos($attrName, 'power') !== false || mb_strpos($attrName, dcim_msg('assets.attr_power_cn')) !== false) {
            return 'RatedPower';
        }
        if (
            (mb_strpos($attrName, 'power') !== false && mb_strpos($attrName, 'port') !== false) ||
            (mb_strpos($attrName, dcim_msg('assets.attr_power_socket_cn_1')) !== false && mb_strpos($attrName, dcim_msg('assets.attr_power_socket_cn_2')) !== false)
        ) {
            return 'RatedJackPort';
        }
        if (mb_strpos($attrName, 'network') !== false || mb_strpos($attrName, dcim_msg('assets.attr_network_port_cn_1')) !== false || mb_strpos($attrName, dcim_msg('assets.attr_network_port_cn_2')) !== false) {
            return 'RatedNetworkPort';
        }
        if (mb_strpos($attrName, 'optical') !== false || mb_strpos($attrName, dcim_msg('assets.attr_optical_port_cn')) !== false) {
            return 'RatedSmoothPort';
        }
        if (
            mb_strpos($attrName, 'capacity') !== false ||
            mb_strpos($attrName, dcim_msg('assets.attr_capacity_cn_1')) !== false ||
            mb_strpos($attrName, dcim_msg('assets.attr_capacity_cn_2')) !== false ||
            mb_strpos($attrName, dcim_msg('assets.attr_capacity_cn_3')) !== false ||
            mb_strpos($attrName, 'U') !== false
        ) {
            return 'RatedU';
        }
        if (mb_strpos($attrName, 'weight') !== false || mb_strpos($attrName, dcim_msg('assets.attr_weight_cn_1')) !== false || mb_strpos($attrName, dcim_msg('assets.attr_weight_cn_2')) !== false) {
            return 'RatedWeight';
        }
        return null;
    }

    private static function cabinetLoadModelMetrics($modelId): array
    {
        if (empty($modelId)) {
            return [];
        }
        $rows = self::crud('dcim-brandmodelattr')->selectByRawCondition(
            'status = 1 AND ModelId = :mid',
            '',
            [':mid' => $modelId]
        );
        if (!$rows) {
            return [];
        }
        $attrIds = [];
        foreach ($rows as $row) {
            if (!empty($row['AttributeId'])) {
                $attrIds[] = $row['AttributeId'];
            }
        }
        $attrMap = [];
        foreach (self::crud('dcim-assetattr')->selectByIds($attrIds, ['id', 'AttrName']) as $attr) {
            $key = (string)($attr['id'] ?? '');
            if ($key !== '') {
                $attrMap[$key] = (string)($attr['AttrName'] ?? '');
            }
        }
        $metrics = [];
        foreach ($rows as $row) {
            $attrName = $attrMap[(string)($row['AttributeId'] ?? '')] ?? '';
            if ($attrName === '') {
                continue;
            }
            $metricKey = self::cabinetAttrMetricKey($attrName);
            if ($metricKey === null) {
                continue;
            }
            $metrics[$metricKey] = (float)($row['AttributeVal'] ?? 0);
        }
        return $metrics;
    }

    private static function cabinetLoadTypeMetrics($assetsTypeId): array
    {
        if (empty($assetsTypeId)) {
            return [];
        }
        $rows = self::crud('dcim-assettypeattr')->selectByRawCondition(
            'status = 1 AND AssetsTypeId = :atid',
            '',
            [':atid' => $assetsTypeId]
        );
        if (!$rows) {
            return [];
        }
        $attrIds = [];
        foreach ($rows as $row) {
            if (!empty($row['AttributeId'])) {
                $attrIds[] = $row['AttributeId'];
            }
        }
        $attrMap = [];
        foreach (self::crud('dcim-assetattr')->selectByIds($attrIds, ['id', 'AttrName']) as $attr) {
            $key = (string)($attr['id'] ?? '');
            if ($key !== '') {
                $attrMap[$key] = (string)($attr['AttrName'] ?? '');
            }
        }
        $metrics = [];
        foreach ($rows as $row) {
            $attrName = $attrMap[(string)($row['AttributeId'] ?? '')] ?? '';
            if ($attrName === '') {
                continue;
            }
            $metricKey = self::cabinetAttrMetricKey($attrName);
            if ($metricKey === null) {
                continue;
            }
            $metrics[$metricKey] = (float)($row['AttributeVal'] ?? 0);
        }
        return $metrics;
    }

    private static function cabinetRequestDataCompat(): array
    {
        $data = Flight::request_data();
        if (!is_array($data)) {
            $data = [];
        }
        if (function_exists('dcim_extract_token')) {
            $token = (string)dcim_extract_token($data);
            if ($token !== '') {
                $data['token'] = $token;
            }
        } elseif (isset($data['Token']) && !isset($data['token'])) {
            $data['token'] = $data['Token'];
        }
        if (!empty($data['token'])) {
            return $data;
        }
        $request = Flight::request();
        $raw = method_exists($request, 'getBody') ? (string) $request->getBody() : (string) @file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return $data;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return $data;
        }
        // Keep JSON token/fields when Flight parser returns empty placeholders.
        $data = array_merge($data, $json);
        if (function_exists('dcim_extract_token')) {
            $token = (string)dcim_extract_token($data);
            if ($token !== '') {
                $data['token'] = $token;
            }
        } elseif (isset($data['Token']) && !isset($data['token'])) {
            $data['token'] = $data['Token'];
        }
        return $data;
    }

    private static function cabinetEnsureCabinetUSeed($cabinetId)
    {
        if (!$cabinetId) {
            return;
        }
        $rows = self::crud('dcim-cabinetu')->selectByRawCondition(
            'status = 1 AND CabinetId = :cid',
            'LIMIT 1',
            [':cid' => $cabinetId]
        );
        if ($rows) {
            return;
        }
        self::crud('dcim-cabinetu')->legacyInsert([
            'CabinetId' => $cabinetId,
            'ULocation' => 1,
            'UStatus' => null,
            'status' => 1,
        ]);
    }

    private static function cabinetCapacity($modelId, $assetsTypeId)
    {
        $attrCrud = self::crud('dcim-assetattr');
        $bmaCrud = self::crud('dcim-brandmodelattr');
        $ataCrud = self::crud('dcim-assettypeattr');
        $allAttrRows = $attrCrud->selectByRawCondition('status = 1', '');
        $candidateAttrIds = [];
        foreach ($allAttrRows as $attrRow) {
            $name = (string)($attrRow['AttrName'] ?? '');
            if ($name === '') {
                continue;
            }
            $isCapacity = (
                mb_strpos($name, 'capacity') !== false ||
                mb_strpos($name, dcim_msg('assets.attr_capacity_cn_1')) !== false ||
                mb_strpos($name, dcim_msg('assets.attr_capacity_cn_2')) !== false ||
                mb_strpos($name, dcim_msg('assets.attr_capacity_cn_3')) !== false ||
                mb_strpos($name, 'U') !== false
            );
            if ($isCapacity && !empty($attrRow['id'])) {
                $candidateAttrIds[] = $attrRow['id'];
            }
        }
        $candidateAttrIds = array_values(array_unique($candidateAttrIds));
        if (!$candidateAttrIds) {
            return 0;
        }
        foreach ($candidateAttrIds as $attrId) {
            if (!empty($modelId)) {
                $row = $bmaCrud->findOne([['ModelId', '=', $modelId], ['AttributeId', '=', $attrId], ['status', '=', 1]]);
                if ($row && isset($row['AttributeVal']) && (int)$row['AttributeVal'] > 0) {
                    return (int)$row['AttributeVal'];
                }
            }
            if (!empty($assetsTypeId)) {
                $row = $ataCrud->findOne([['AssetsTypeId', '=', $assetsTypeId], ['AttributeId', '=', $attrId], ['status', '=', 1]]);
                if ($row && isset($row['AttributeVal']) && (int)$row['AttributeVal'] > 0) {
                    return (int)$row['AttributeVal'];
                }
            }
        }
        return 0;
    }

    public static function cabinetInfoAdd()
    {
        $data = self::cabinetRequestDataCompat();
        $id = self::crud('dcim-cabinet')->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function cabinetGetList()
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-cabinet')->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => ['CabinetName', 'CabinetNumber'],
            'exact_filters' => [
                'AreaId' => 'AreaId',
                'ServerCode' => 'ServerCode',
            ],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $areaIds = [];
        $serverIds = [];
        foreach ($rows as $row) {
            if (!empty($row['AreaId'])) {
                $areaIds[] = $row['AreaId'];
            }
            if (!empty($row['ServerCode'])) {
                $serverIds[] = $row['ServerCode'];
            }
        }
        $areaMap = [];
        foreach (self::crud('dcim-area')->selectByIds($areaIds, ['id', 'AreaName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $areaMap[$key] = $item;
            }
        }
        $serverMap = [];
        foreach (self::crud('dcim-server')->selectByIds($serverIds, ['id', 'ServerName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $serverMap[$key] = $item;
            }
        }
        foreach ($rows as &$row) {
            $row['AreaName'] = $areaMap[(string)($row['AreaId'] ?? '')]['AreaName'] ?? '';
            $row['ServerName'] = $serverMap[(string)($row['ServerCode'] ?? '')]['ServerName'] ?? '';
        }
        unset($row);
        $result['info'] = $rows;
        $num = $result['info'] ? count($result['info']) : false;
        O_E($result, tp_msg_success(), 100, $num);
    }

    public static function cabinetGetInfo()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-cabinet')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if ($info) {
            $area = self::crud('dcim-area')->findOne([['id', '=', $info['AreaId'] ?? 0]]);
            $server = self::crud('dcim-server')->findOne([['id', '=', $info['ServerCode'] ?? 0]]);
            $info['AreaName'] = $area['AreaName'] ?? '';
            $info['ServerName'] = $server['ServerName'] ?? '';
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function cabinetInfoUpdate()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-cabinet')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function cabinetInfoDel()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-cabinet')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function cabinetGetArrange()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $cabinetId = $data['CabinetId'] ?? 0;
        if ($cabinetId) {
            $rows = self::crud('dcim-cabinetu')->legacySelectByFilters($data + ['CabinetId' => $cabinetId], [
                'skip_auth' => true,
                'base_where' => ['status = 1'],
                'exact_filters' => [
                    'CabinetId' => 'CabinetId',
                ],
                'order_by' => 'ORDER BY ULocation ASC',
            ]);
            $num = $rows ? count($rows) : false;
            O_E($rows, tp_msg_success(), 100, $num);
            return;
        }

        $areaId = $data['AreaId'] ?? ($data['RoomId'] ?? '');
        $serverCode = $data['ServerCode'] ?? '';
        if ($areaId === '' || $areaId === null) {
            $rows = self::crud('dcim-cabinet')->legacySelectByFilters($data, [
                'skip_auth' => true,
                'base_where' => ['status = 1'],
                'exact_filters' => [
                    'ServerCode' => 'ServerCode',
                ],
                'order_by' => 'ORDER BY `column` ASC',
            ]);
        } else {
            $filterData = $data + ['AreaId' => $areaId];
            if ($serverCode !== '' && $serverCode !== null) {
                $filterData['ServerCode'] = $serverCode;
            }
            $rows = self::crud('dcim-cabinet')->legacySelectByFilters($filterData, [
                'skip_auth' => true,
                'base_where' => ['status = 1'],
                'exact_filters' => [
                    'AreaId' => 'AreaId',
                    'ServerCode' => 'ServerCode',
                ],
                'order_by' => 'ORDER BY `column` ASC',
            ]);
        }
        $columns = [];
        $seen = [];
        if ($rows) {
            foreach ($rows as $row) {
                $column = array_key_exists('column', $row) ? $row['column'] : null;
                $key = is_null($column) ? '__NULL__' : (string)$column;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $columns[] = ['column' => $column];
            }
        }
        O_E($columns, tp_msg_success(), 100, $columns ? count($columns) : false);
    }

    public static function cabinetGetAssetCabinetList()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $rows = self::crud('dcim-cabinet')->legacySelectByFilters($data, [
            'skip_auth' => true,
            'base_where' => ['status = 1', 'AssetsId IS NOT NULL', 'AssetsId <> 0'],
            'exact_filters' => [
                'column' => 'column',
                'ServerCode' => 'ServerCode',
                'AreaId' => 'AreaId',
            ],
        ]);
        O_E($rows, tp_msg_success(), 100, $rows ? count($rows) : false);
    }

    public static function cabinetCreateCabinetU()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        if (empty($data['id']) || empty($data['AssetsId'])) {
            P_E(dcim_msg('error.missing_parameters'));
        }
        $cabinetCrud = self::crud('dcim-cabinet');
        $cabinet = $cabinetCrud->findOne([['id', '=', $data['id']], ['status', '=', 1]]);
        if (!$cabinet) {
            P_E(dcim_msg('error.cabinet_not_found'));
        }
        $assetCrud = self::crud('dcim-asset');
        $asset = $assetCrud->findOne([['id', '=', $data['AssetsId']], ['status', '=', 1]]);
        if (!$asset) {
            P_E(dcim_msg('error.asset_not_found'));
        }
        self::crud('dcim-cabinetu')->legacyUpdateWhere(
            [
                ['CabinetId', '=', $data['id']],
                ['status', '=', 1],
            ],
            ['status' => -1]
        );
        $capacity = self::cabinetCapacity($asset['ModelId'] ?? 0, $asset['AssetsTypeId'] ?? 0);
        if ($capacity <= 0) {
            $capacity = 1;
        }
        $cabinetuCrud = self::crud('dcim-cabinetu');
        $oldAssetsId = trim((string)($cabinet['AssetsId'] ?? ''));
        if ($oldAssetsId !== '' && $oldAssetsId !== trim((string)$data['AssetsId'])) {
            self::assetChangeLogWrite($oldAssetsId, 'cabinet_remove', [
                'CabinetId' => $data['id'],
            ], $user['id'] ?? null);
        }
        for ($i = 1; $i <= $capacity; $i++) {
            $cabinetuCrud->legacyInsert([
                'CabinetId' => $data['id'],
                'ULocation' => $i,
                'status' => 1,
            ]);
        }
        $updateData = $data;
        $updateData['AssetsId'] = $data['AssetsId'];
        $res = $cabinetCrud->legacyUpdate($updateData, [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['AssetsId'],
        ]);
        if ($res === null) {
            return;
        }
        $syncData = [
            'AssetsId' => $data['AssetsId'],
            'PutoutId' => $data['PutoutId'] ?? null,
        ];
        self::crud('dcim-cabinet')->legacySyncAssetAndPutout($syncData, [
            'asset_id_param' => 'AssetsId',
            'asset_status_value' => 'T',
            'putout_id_param' => 'PutoutId',
            'putout_status_field' => 'PutoutStatus',
            'putout_status_value' => 'true',
        ]);
        self::assetChangeLogWrite($data['AssetsId'], 'cabinet_install', [
            'CabinetId' => $data['id'],
            'capacity' => $capacity,
            'PutoutId' => $data['PutoutId'] ?? null,
        ], $user['id'] ?? null);
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function cabinetRemoveCabinetU()
    {
        $data = Flight::request_data();
        $user = self::requireAuth($data);
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }
        $cabinetCrud = self::crud('dcim-cabinet');
        $cabinet = $cabinetCrud->findOne([['id', '=', $data['id']], ['status', '=', 1]]);
        if ($cabinet && !empty($cabinet['AssetsId'])) {
            self::crud('dcim-cabinet')->legacySyncAssetAndPutout([
                'AssetsId' => $cabinet['AssetsId'],
            ], [
                'asset_id_param' => 'AssetsId',
                'asset_status_value' => 'O',
            ]);
            self::crud('dcim-cabinet')->legacyUpdatePutoutStatusByAsset([
                'AssetsId' => $cabinet['AssetsId'],
            ], [
                'asset_id_param' => 'AssetsId',
                'putout_status_field' => 'PutoutStatus',
                'from_status' => 'true',
                'to_status' => 'false',
            ]);
        }
        self::crud('dcim-cabinetu')->legacyUpdateWhere(
            [
                ['CabinetId', '=', $data['id']],
                ['status', '=', 1],
            ],
            ['status' => -1]
        );
        $updateData = $data;
        $updateData['AssetsId'] = 0;
        $res = $cabinetCrud->legacyUpdate($updateData, [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['AssetsId'],
        ]);
        if ($res === null) {
            return;
        }
        self::assetChangeLogWrite($cabinet['AssetsId'] ?? null, 'cabinet_remove', [
            'CabinetId' => $data['id'],
        ], $user['id'] ?? null);
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function cabinetGetCabinetU()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }
        self::cabinetEnsureCabinetUSeed($data['id']);
        $rows = self::crud('dcim-cabinetu')->selectByRawCondition(
            'status = 1 AND CabinetId = :cid',
            'ORDER BY ULocation ASC',
            [':cid' => $data['id']]
        );
        $cabinet = self::crud('dcim-cabinet')->findOne([['id', '=', $data['id']], ['status', '=', 1]]);
        $area = $cabinet ? self::crud('dcim-area')->findOne([['id', '=', $cabinet['AreaId'] ?? 0]]) : null;
        $server = $cabinet ? self::crud('dcim-server')->findOne([['id', '=', $cabinet['ServerCode'] ?? 0]]) : null;
        foreach ($rows as &$row) {
            $row['ServerName'] = $server['ServerName'] ?? '';
            $row['AreaName'] = $area['AreaName'] ?? '';
            $row['column'] = $cabinet['column'] ?? '';
            $row['position'] = $cabinet['position'] ?? '';
        }
        unset($row);
        O_E($rows, tp_msg_success(), 100, $rows ? count($rows) : false);
    }

    public static function cabinetChangeUStatus()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $data['UStatus'] = $data['UStatus'] ?? null;
        $res = self::crud('dcim-cabinetu')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['UStatus'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function cabinetChangeUDevStatus()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (empty($data['CabinetId']) || empty($data['ULocation'])) {
            P_E(dcim_msg('error.missing_parameters'));
        }
        $res = self::crud('dcim-cabinetu')->legacyUpdateWhere(
            [
                ['CabinetId', '=', $data['CabinetId']],
                ['ULocation', '=', $data['ULocation']],
                ['status', '=', 1],
            ],
            ['UdeviceStatus' => $data['UdeviceStatus'] ?? null]
        );
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function cabinetGetUDevStatus()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (empty($data['GatewayId'])) {
            P_E(dcim_msg('error.gateway_id_required'));
        }
        $rows = self::crud('dcim-cabinetu')->selectByRawCondition('status = 1 AND GatewayId = :gid', 'LIMIT 1', [':gid' => $data['GatewayId']]);
        O_E($rows, tp_msg_success(), 100, $rows ? count($rows) : false);
    }

    public static function cabinetGetSearchCabinet()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (empty($data['AreaId'])) {
            P_E(dcim_msg('error.area_id_required'));
        }
        $rows = self::crud('dcim-cabinet')->legacySelectByFilters($data, [
            'skip_auth' => true,
            'base_where' => ['status = 1'],
            'exact_filters' => [
                'AreaId' => 'AreaId',
            ],
        ]);
        if (is_array($rows)) {
            foreach ($rows as &$row) {
                $row['RatedPower'] = $row['RatedPower'] ?? 0;
                $row['RatedJackPort'] = $row['RatedJackPort'] ?? 0;
                $row['RatedNetworkPort'] = $row['RatedNetworkPort'] ?? 0;
                $row['RatedSmoothPort'] = $row['RatedSmoothPort'] ?? 0;
                $row['RatedU'] = $row['RatedU'] ?? 0;
                $row['RatedWeight'] = $row['RatedWeight'] ?? 0;
                $row['UsedPower'] = $row['UsedPower'] ?? 0;
                $row['UsedJackPort'] = $row['UsedJackPort'] ?? 0;
                $row['UsedNetworkPort'] = $row['UsedNetworkPort'] ?? 0;
                $row['UsedSmoothPort'] = $row['UsedSmoothPort'] ?? 0;
                $row['UsedU'] = $row['UsedU'] ?? 0;
                $row['UsedWeight'] = $row['UsedWeight'] ?? 0;
            }
            unset($row);
        }
        O_E($rows, tp_msg_success(), 100, $rows ? count($rows) : false);
    }

    public static function cabinetAssetVisualization()
    {
        $data = self::cabinetRequestDataCompat();
        self::requireAuth($data);
        if (empty($data['CabinetId'])) {
            O_E(false, dcim_msg('error.cabinet_id_required'), 400, 0);
            return;
        }
        $cabinet = self::crud('dcim-cabinet')->findOne([['id', '=', $data['CabinetId']], ['status', '=', 1]]);
        if (!$cabinet) {
            O_E([], tp_msg_success(), 100, false);
            return;
        }
        $asset = null;
        if (!empty($cabinet['AssetsId'])) {
            $asset = self::crud('dcim-asset')->findOne([['id', '=', $cabinet['AssetsId']], ['status', '=', 1]]);
        }
        if (!$asset) {
            $uAssetRows = self::crud('dcim-cabinetu')->selectByRawCondition(
                'status = 1 AND CabinetId = :cid AND AssetsId IS NOT NULL AND AssetsId <> 0',
                'ORDER BY id ASC LIMIT 1',
                [':cid' => $cabinet['id']]
            );
            if ($uAssetRows) {
                $aid = $uAssetRows[0]['AssetsId'] ?? null;
                if (!empty($aid)) {
                    $asset = self::crud('dcim-asset')->findOne([['id', '=', $aid], ['status', '=', 1]]);
                }
            }
        }
        $model = null;
        $assetType = null;
        if (!empty($asset['ModelId'])) {
            $model = self::crud('dcim-brandmodel')->findOne([['id', '=', $asset['ModelId']], ['status', '=', 1]]);
        }
        $modelTypeId = self::brandModelTypeId(is_array($model) ? $model : []);
        $assetTypeId = '';
        foreach (['AssetsTypeId', 'AssetsTypeID', 'TypeId', 'TypeID'] as $typeField) {
            $typeVal = trim((string)($asset[$typeField] ?? ''));
            if ($typeVal !== '') {
                $assetTypeId = $typeVal;
                break;
            }
        }
        if ($assetTypeId === '') {
            $assetTypeId = $modelTypeId;
        }
        if ($assetTypeId !== '') {
            $assetType = self::crud('dcim-assettype')->findOne([['id', '=', $assetTypeId], ['status', '=', 1]]);
        }
        $person = null;
        $dept = null;
        if (!empty($asset['EmpId'])) {
            $person = self::crud('dcim-person')->findOne([['id', '=', $asset['EmpId']], ['status', '=', 1]]);
        }
        if (!empty($person['DeptId'])) {
            $dept = self::crud('dcim-department')->findOne([['id', '=', $person['DeptId']], ['status', '=', 1]]);
        }
        $info = [
            'BuyTime' => $asset['BuyTime'] ?? '',
            'UId' => $asset['UId'] ?? '',
            'GatewayId' => $asset['GatewayId'] ?? '',
            'position' => $cabinet['position'] ?? '',
            'AssetsTypeName' => $assetType['AssetsTypeName'] ?? '',
            'PersonName' => $person['PersonName'] ?? '',
            'DeptName' => $dept['DeptName'] ?? '',
            'BrandModel' => $model['BrandModel'] ?? '',
            'RatedPower' => 0,
            'RatedJackPort' => 0,
            'RatedNetworkPort' => 0,
            'RatedSmoothPort' => 0,
            'RatedU' => 0,
            'RatedWeight' => 0,
            'UsedPower' => 0,
            'UsedJackPort' => 0,
            'UsedNetworkPort' => 0,
            'UsedSmoothPort' => 0,
            'UsedU' => 0,
            'UsedWeight' => 0,
            'PreUsedPower' => 0,
            'PreUsedJackPort' => 0,
            'PreUsedNetworkPort' => 0,
            'PreUsedSmoothPort' => 0,
            'PreUsedU' => 0,
            'PreUsedWeight' => 0,
        ];

        $typeMetrics = self::cabinetLoadTypeMetrics($assetTypeId !== '' ? $assetTypeId : null);
        foreach ($typeMetrics as $k => $v) {
            $info[$k] = $v;
        }
        $modelMetrics = self::cabinetLoadModelMetrics($asset['ModelId'] ?? null);
        foreach ($modelMetrics as $k => $v) {
            $info[$k] = $v;
        }

        $uRows = self::crud('dcim-cabinetu')->selectByRawCondition(
            'status = 1 AND CabinetId = :cid',
            'ORDER BY ULocation ASC',
            [':cid' => $cabinet['id']]
        );
        $assetIds = [];
        foreach ($uRows as $uRow) {
            if (!empty($uRow['AssetsId'])) {
                $assetIds[] = $uRow['AssetsId'];
            }
        }
        $assetMap = [];
        foreach (self::crud('dcim-asset')->selectByIds($assetIds, ['id', 'ModelId']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $assetMap[$key] = $item;
            }
        }
        $metricCache = [];
        $usedAssets = [];
        $preAssets = [];
        foreach ($uRows as $uRow) {
            $aid = (string)($uRow['AssetsId'] ?? '');
            if ($aid === '' || isset($usedAssets[$aid]) || isset($preAssets[$aid])) {
                continue;
            }
            $modelId = $assetMap[$aid]['ModelId'] ?? null;
            if ($modelId === null) {
                continue;
            }
            if (!isset($metricCache[(string)$modelId])) {
                $metricCache[(string)$modelId] = self::cabinetLoadModelMetrics($modelId);
            }
            $uStatus = (string)($uRow['UStatus'] ?? '');
            $isPre = (
                mb_strpos($uStatus, 'pre') !== false
                || mb_strpos($uStatus, dcim_msg('assets.status_preemption')) !== false
            );
            if ($isPre) {
                $preAssets[$aid] = true;
            } else {
                $usedAssets[$aid] = true;
            }
            $metrics = $metricCache[(string)$modelId];
            $targetPrefix = $isPre ? 'PreUsed' : 'Used';
            $info[$targetPrefix . 'Power'] += (float)($metrics['RatedPower'] ?? 0);
            $info[$targetPrefix . 'JackPort'] += (float)($metrics['RatedJackPort'] ?? 0);
            $info[$targetPrefix . 'NetworkPort'] += (float)($metrics['RatedNetworkPort'] ?? 0);
            $info[$targetPrefix . 'SmoothPort'] += (float)($metrics['RatedSmoothPort'] ?? 0);
            $info[$targetPrefix . 'U'] += (float)($metrics['RatedU'] ?? 1);
            $info[$targetPrefix . 'Weight'] += (float)($metrics['RatedWeight'] ?? 0);
        }

        O_E($info, tp_msg_success(), 100, 1);
    }


    private static function createSupply(string $table): void
    {
        $data = Flight::request_data();
        $id = self::crud($table)->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    private static function listSupply(string $table, string $searchField): void
    {
        $data = Flight::request_data();
        $result = self::crud($table)->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => [$searchField],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        if ($table === 'dcim-spareparts') {
            $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
            $result['info'] = self::enrichSparePartsRows($rows);
        } elseif ($table === 'dcim-consumables') {
            $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
            $result['info'] = self::enrichConsumablesRows($rows);
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    private static function infoSupply(string $table): void
    {
        $data = Flight::request_data();
        $info = self::crud($table)->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if ($table === 'dcim-spareparts' && $info) {
            $rows = self::enrichSparePartsRows([$info]);
            $info = $rows ? $rows[0] : $info;
        } elseif ($table === 'dcim-consumables' && $info) {
            $rows = self::enrichConsumablesRows([$info]);
            $info = $rows ? $rows[0] : $info;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    private static function enrichConsumablesRows(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $storeIds = [];
        $personIds = [];
        foreach ($rows as $row) {
            $storeId = $row['StoreLocationId'] ?? ($row['BackupsStoreLocationId'] ?? null);
            if (!empty($storeId)) {
                $storeIds[] = $storeId;
            }
            if (!empty($row['EmpId'])) {
                $personIds[] = $row['EmpId'];
            }
        }
        $storeMap = [];
        foreach (self::crud('dcim-store')->selectByIds($storeIds, ['id', 'StoreLocationName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $storeMap[$key] = $item;
            }
        }
        $personMap = [];
        $deptIds = [];
        foreach (self::crud('dcim-person')->selectByIds($personIds, ['id', 'PersonName', 'DeptId']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $personMap[$key] = $item;
                if (!empty($item['DeptId'])) {
                    $deptIds[] = $item['DeptId'];
                }
            }
        }
        $deptMap = [];
        foreach (self::crud('dcim-department')->selectByIds($deptIds, ['id', 'DeptName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $deptMap[$key] = $item;
            }
        }
        foreach ($rows as &$row) {
            $storeId = $row['StoreLocationId'] ?? ($row['BackupsStoreLocationId'] ?? '');
            $row['StoreLocationName'] = $storeMap[(string)$storeId]['StoreLocationName'] ?? '';
            $person = $personMap[(string)($row['EmpId'] ?? '')] ?? [];
            $row['PersonName'] = $person['PersonName'] ?? '';
            $row['DeptId'] = $person['DeptId'] ?? ($row['DeptId'] ?? '');
            $row['DeptName'] = $deptMap[(string)($row['DeptId'] ?? '')]['DeptName'] ?? '';
        }
        unset($row);
        return $rows;
    }

    private static function enrichSparePartsRows(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $typeIds = [];
        $storeIds = [];
        $personIds = [];
        foreach ($rows as $row) {
            if (!empty($row['AssetsTypeId'])) {
                $typeIds[] = $row['AssetsTypeId'];
            }
            if (!empty($row['BackupsStoreLocationId'])) {
                $storeIds[] = $row['BackupsStoreLocationId'];
            }
            if (!empty($row['EmpId'])) {
                $personIds[] = $row['EmpId'];
            }
        }
        $typeMap = [];
        foreach (self::crud('dcim-assettype')->selectByIds($typeIds, ['id', 'AssetsTypeName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $typeMap[$key] = $item;
            }
        }
        $storeMap = [];
        foreach (self::crud('dcim-store')->selectByIds($storeIds, ['id', 'StoreLocationName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $storeMap[$key] = $item;
            }
        }
        $personMap = [];
        $deptIds = [];
        foreach (self::crud('dcim-person')->selectByIds($personIds, ['id', 'PersonName', 'DeptId']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $personMap[$key] = $item;
                if (!empty($item['DeptId'])) {
                    $deptIds[] = $item['DeptId'];
                }
            }
        }
        $deptMap = [];
        foreach (self::crud('dcim-department')->selectByIds($deptIds, ['id', 'DeptName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $deptMap[$key] = $item;
            }
        }
        foreach ($rows as &$row) {
            $row['AssetsTypeName'] = $typeMap[(string)($row['AssetsTypeId'] ?? '')]['AssetsTypeName'] ?? '';
            $row['StoreLocationName'] = $storeMap[(string)($row['BackupsStoreLocationId'] ?? '')]['StoreLocationName'] ?? '';
            $person = $personMap[(string)($row['EmpId'] ?? '')] ?? [];
            $row['PersonName'] = $person['PersonName'] ?? '';
            $row['DeptId'] = $person['DeptId'] ?? ($row['DeptId'] ?? '');
            $row['DeptName'] = $deptMap[(string)($row['DeptId'] ?? '')]['DeptName'] ?? '';
        }
        unset($row);
        return $rows;
    }

    private static function updateSupply(string $table): void
    {
        $data = Flight::request_data();
        $res = self::crud($table)->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    private static function createResource(string $table): void
    {
        $data = Flight::request_data();
        $id = self::crud($table)->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    private static function listResource(string $table, array $listOptions): void
    {
        $data = Flight::request_data();
        $result = self::crud($table)->legacyList($data, $listOptions);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    private static function infoResource(string $table): void
    {
        $data = Flight::request_data();
        $info = self::crud($table)->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    private static function updateResource(string $table): void
    {
        $data = Flight::request_data();
        $res = self::crud($table)->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    private static function delResource(string $table): void
    {
        $data = Flight::request_data();
        $res = self::crud($table)->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    private static function tenantUNormalizeLocations($locations): array
    {
        if (is_array($locations)) {
            $parts = $locations;
        } else {
            $parts = strpos((string) $locations, ',') !== false ? explode(',', (string) $locations) : [(string) $locations];
        }
        $clean = [];
        foreach ($parts as $part) {
            $loc = trim((string) $part);
            if ($loc === '' || !is_numeric($loc)) {
                continue;
            }
            $clean[] = (int) $loc;
        }
        return array_values(array_unique($clean));
    }

    private static function tenantUUpdateCabinetULocations($cabinetId, string $locations, $statusValue): void
    {
        $crud = self::crud('dcim-cabinetu');
        foreach (self::tenantUNormalizeLocations($locations) as $loc) {
            $rows = $crud->selectByRawCondition(
                'CabinetId = :cid AND ULocation = :uloc AND status = 1',
                'LIMIT 1',
                [':cid' => $cabinetId, ':uloc' => $loc]
            );
            if (!$rows) {
                $crud->legacyInsert([
                    'CabinetId' => $cabinetId,
                    'ULocation' => $loc,
                    'UStatus' => null,
                    'status' => 1,
                ]);
            }
            $crud->legacyUpdateWhere(
                [
                    ['CabinetId', '=', $cabinetId],
                    ['ULocation', '=', $loc],
                    ['status', '=', 1],
                ],
                ['UStatus' => $statusValue]
            );
        }
    }

    private static function getLendListByTable(string $recordTable, ?array $dataOverride = null): void
    {
        $data = is_array($dataOverride) ? $dataOverride : Flight::request_data();
        if (isset($data['type'])) {
            $type = trim((string)$data['type']);
            if ($type === 'lend') {
                $data['type'] = dcim_msg('assets.lend');
            } elseif ($type === 'return') {
                $data['type'] = dcim_msg('assets.return');
            }
        }
        $result = self::crud($recordTable)->legacyList($data, [
            'base_where' => ['(status <> -1 OR status IS NULL)'],
            'exact_filters' => [
                'type' => 'type',
                'LendPerson' => 'LendPerson',
            ],
            'between_filters' => [
                ['field' => 'LendTime', 'start_key' => 'startDateTime', 'end_key' => 'endDateTime'],
            ],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        if ($rows) {
            $idField = $recordTable === 'dcim-keyrecord' ? 'KeyId' : 'ToolId';
            $nameField = $recordTable === 'dcim-keyrecord' ? 'KeyName' : 'ToolName';
            $numberField = $recordTable === 'dcim-keyrecord' ? 'KeyNumber' : 'ToolNumber';
            $idAliases = $recordTable === 'dcim-keyrecord'
                ? ['KeyId', 'KeyID', 'key_id', 'ResourceId']
                : ['ToolId', 'ToolID', 'tool_id', 'ResourceId'];
            $resourceTable = $recordTable === 'dcim-keyrecord' ? 'dcim-key' : 'dcim-tool';
            $resourceIds = [];
            foreach ($rows as $row) {
                $rid = '';
                foreach ($idAliases as $idAlias) {
                    $tmp = trim((string)($row[$idAlias] ?? ''));
                    if ($tmp !== '') {
                        $rid = $tmp;
                        break;
                    }
                }
                if ($rid !== '') {
                    $resourceIds[] = $rid;
                }
            }
            $resourceMap = [];
            if ($resourceIds) {
                $selectCols = ['id', $nameField, $numberField];
                if ($recordTable === 'dcim-keyrecord') {
                    $selectCols = array_values(array_unique(array_merge($selectCols, ['KeyName', 'KeyNumber', 'KeyNO'])));
                } else {
                    $selectCols = array_values(array_unique(array_merge($selectCols, ['ToolName', 'ToolNumber', 'ToolNO'])));
                }
                foreach (self::crud($resourceTable)->selectByIds(array_values(array_unique($resourceIds)), $selectCols) as $item) {
                    $key = (string)($item['id'] ?? '');
                    if ($key !== '') {
                        $resourceMap[$key] = $item;
                    }
                }
            }
            foreach ($rows as &$row) {
                $rid = '';
                foreach ($idAliases as $idAlias) {
                    $tmp = trim((string)($row[$idAlias] ?? ''));
                    if ($tmp !== '') {
                        $rid = $tmp;
                        break;
                    }
                }
                $resource = $resourceMap[$rid] ?? [];
                if (($row[$idField] ?? '') === '' && $rid !== '') {
                    $row[$idField] = $rid;
                }
                $row[$nameField] = $resource[$nameField]
                    ?? $resource['KeyName']
                    ?? $resource['ToolName']
                    ?? ($row[$nameField] ?? '');
                $row[$numberField] = $resource[$numberField]
                    ?? $resource['KeyNumber']
                    ?? $resource['KeyNO']
                    ?? $resource['ToolNumber']
                    ?? $resource['ToolNO']
                    ?? ($row[$numberField] ?? '');
                $row['LendPerson'] = (string)($row['LendPerson'] ?? '');
                $row['LendTel'] = (string)($row['LendTel'] ?? '');
                $row['LendTime'] = (string)($row['LendTime'] ?? '');
                $row['ReturnTime'] = (string)($row['ReturnTime'] ?? '');
                $typeVal = trim((string)($row['type'] ?? ''));
                if ($typeVal === 'lend') {
                    $typeVal = dcim_msg('assets.lend');
                } elseif ($typeVal === 'return') {
                    $typeVal = dcim_msg('assets.return');
                } elseif ($typeVal === 'back') {
                    $typeVal = dcim_msg('assets.back_to_store');
                }
                $row['type'] = $typeVal;
                $row['remark'] = (string)($row['remark'] ?? '');
            }
            unset($row);
            $result['info'] = $rows;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    private static function lendResource(string $recordTable, string $recordIdField, string $resourceTable, string $statusField, string $recordRefField): void
    {
        $data = Flight::request_data();
        $recordCrud = self::crud($recordTable);
        if (!$recordCrud->legacyEnsureAuth($data)) {
            return;
        }
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }

        $insertData = [
            $recordIdField => $data['id'],
            'type' => dcim_msg('assets.lend'),
            'LendPerson' => $data['LendPerson'] ?? '',
            'LendTel' => $data['LendTel'] ?? '',
            'LendTime' => $data['LendTime'] ?? null,
            'remark' => $data['remark'] ?? '',
            'status' => 1,
        ];
        $id = $recordCrud->legacyInsert($insertData);

        $updateData = $data;
        $updateData[$statusField] = dcim_msg('assets.lend');
        $updateData[$recordRefField] = $id;
        $res = self::crud($resourceTable)->legacyUpdate($updateData, [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => [$statusField, $recordRefField],
        ]);
        if ($res === null) {
            return;
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    private static function returnResource(string $recordTable, string $recordIdField, string $resourceTable, string $statusField, string $recordRefField): void
    {
        $data = Flight::request_data();
        $recordCrud = self::crud($recordTable);
        if (!$recordCrud->legacyEnsureAuth($data)) {
            return;
        }
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }

        $insertData = [
            $recordIdField => $data['id'],
            'type' => dcim_msg('assets.return'),
            'ReturnTime' => $data['ReturnTime'] ?? null,
            'remark' => $data['remark'] ?? '',
            'status' => 1,
        ];
        $id = $recordCrud->legacyInsert($insertData);

        $updateData = $data;
        $updateData[$statusField] = dcim_msg('assets.not_lent');
        $updateData[$recordRefField] = $id;
        $res = self::crud($resourceTable)->legacyUpdate($updateData, [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => [$statusField, $recordRefField],
        ]);
        if ($res === null) {
            return;
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function getSparePartsChangeDetail(): void
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-spareuserecord')->legacyList($data, [
            'base_where' => ['status = 1'],
            'exact_filters' => [
                'SpareId' => 'SpareId',
                'type' => 'type',
                'PutoutType' => 'PutoutType',
            ],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        if ($rows) {
            $spareIds = [];
            $useEmpIds = [];
            foreach ($rows as $row) {
                if (!empty($row['SpareId'])) {
                    $spareIds[] = $row['SpareId'];
                }
                if (!empty($row['UseEmpId'])) {
                    $useEmpIds[] = $row['UseEmpId'];
                }
            }
            $spareMap = [];
            $typeIds = [];
            $storeIds = [];
            foreach (self::crud('dcim-spareparts')->selectByIds($spareIds, [
                'id',
                'BackupsName',
                'BackupsCompany',
                'BackupsSpec',
                'BackupsNumber',
                'BackupsModel',
                'AssetsTypeId',
                'BackupsStoreLocationId',
                'EmpId',
            ]) as $item) {
                $key = (string)($item['id'] ?? '');
                if ($key === '') {
                    continue;
                }
                $spareMap[$key] = $item;
                if (!empty($item['AssetsTypeId'])) {
                    $typeIds[] = $item['AssetsTypeId'];
                }
                if (!empty($item['BackupsStoreLocationId'])) {
                    $storeIds[] = $item['BackupsStoreLocationId'];
                }
            }
            $typeMap = [];
            foreach (self::crud('dcim-assettype')->selectByIds($typeIds, ['id', 'AssetsTypeName']) as $item) {
                $key = (string)($item['id'] ?? '');
                if ($key !== '') {
                    $typeMap[$key] = $item['AssetsTypeName'] ?? '';
                }
            }
            $storeMap = [];
            foreach (self::crud('dcim-store')->selectByIds($storeIds, ['id', 'StoreLocationName']) as $item) {
                $key = (string)($item['id'] ?? '');
                if ($key !== '') {
                    $storeMap[$key] = $item['StoreLocationName'] ?? '';
                }
            }
            $ownerEmpIds = [];
            foreach ($spareMap as $spareItem) {
                if (!empty($spareItem['EmpId'])) {
                    $ownerEmpIds[] = $spareItem['EmpId'];
                }
            }
            $personMap = [];
            foreach (self::crud('dcim-person')->selectByIds(array_merge($useEmpIds, $ownerEmpIds), ['id', 'PersonName']) as $item) {
                $key = (string)($item['id'] ?? '');
                if ($key !== '') {
                    $personMap[$key] = $item['PersonName'] ?? '';
                }
            }
            foreach ($rows as &$row) {
                $spare = $spareMap[(string)($row['SpareId'] ?? '')] ?? [];
                $row['BackupsName'] = $spare['BackupsName'] ?? '';
                $row['BackupsCompany'] = $spare['BackupsCompany'] ?? '';
                $row['BackupsSpec'] = $spare['BackupsSpec'] ?? '';
                $row['BackupsNumber'] = $spare['BackupsNumber'] ?? '';
                $row['BackupsModel'] = $spare['BackupsModel'] ?? '';
                $row['AssetsTypeId'] = $spare['AssetsTypeId'] ?? ($row['AssetsTypeId'] ?? '');
                $row['AssetsTypeName'] = $typeMap[(string)($row['AssetsTypeId'] ?? '')] ?? '';
                $row['BackupsStoreLocationId'] = $spare['BackupsStoreLocationId'] ?? ($row['BackupsStoreLocationId'] ?? '');
                $row['StoreLocationName'] = $storeMap[(string)($row['BackupsStoreLocationId'] ?? '')] ?? '';
                $row['PersonName'] = $personMap[(string)($spare['EmpId'] ?? '')] ?? '';
                $row['UsePersonName'] = $personMap[(string)($row['UseEmpId'] ?? '')] ?? '';
            }
            unset($row);
            $result['info'] = $rows;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function enterSpareParts(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);

        $result = self::crud('dcim-spareuserecord')->legacyAdjustStockAndRecord($data, [
            'stock_table' => 'dcim-spareparts',
            'record_table' => 'dcim-spareuserecord',
            'id_param' => 'id',
            'number_param' => 'number',
            'record_fk_field' => 'SpareId',
            'available_field' => 'SurplusBackupsNumber',
            'total_field' => 'AllBackupsNumber',
            'mode' => 'in',
            'record_constant_fields' => [
                'type' => dcim_msg('assets.inbound'),
                'source' => '',
                'supplier' => '',
                'person' => '',
                'tel' => '',
                'remark' => '',
            ],
            'record_extra_fields' => [
                'source' => 'source',
                'BuyTime' => 'BuyTime',
                'price' => 'price',
                'supplier' => 'supplier',
                'person' => 'person',
                'tel' => 'tel',
                'remark' => 'remark',
            ],
            'id_required_message' => dcim_msg('common.id_required'),
            'not_found_message' => dcim_msg('error.spare_part_not_found'),
        ]);

        if ($result === null) {
            return;
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function exportSpareParts(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (array_key_exists('PlanReturnTime', $data) && ($data['PlanReturnTime'] === '' || $data['PlanReturnTime'] === null)) {
            unset($data['PlanReturnTime']);
        }

        $result = self::crud('dcim-spareuserecord')->legacyAdjustStockAndRecord($data, [
            'stock_table' => 'dcim-spareparts',
            'record_table' => 'dcim-spareuserecord',
            'id_param' => 'id',
            'number_param' => 'number',
            'record_fk_field' => 'SpareId',
            'available_field' => 'SurplusBackupsNumber',
            'mode' => 'out',
            'record_constant_fields' => [
                'type' => dcim_msg('assets.outbound'),
                'remark' => '',
            ],
            'record_extra_fields' => [
                'PutoutType' => 'PutoutType',
                'UseTime' => 'UseTime',
                'UseEmpId' => 'UseEmpId',
                'PlanReturnTime' => 'PlanReturnTime',
                'remark' => 'remark',
            ],
            'id_required_message' => dcim_msg('common.id_required'),
            'not_found_message' => dcim_msg('error.spare_part_not_found'),
            'insufficient_message' => dcim_msg('error.insufficient_spare_part_stock'),
        ]);

        if ($result === null) {
            return;
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function returnSpareParts(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);

        if (empty($data['id']) || empty($data['SpareId'])) {
            P_E(dcim_msg('error.id_and_spare_id_required'));
        }

        $result = self::crud('dcim-spareuserecord')->legacyAdjustStockAndRecord($data, [
            'stock_table' => 'dcim-spareparts',
            'record_table' => 'dcim-spareuserecord',
            'id_param' => 'SpareId',
            'number_param' => 'number',
            'record_fk_field' => 'SpareId',
            'available_field' => 'SurplusBackupsNumber',
            'mode' => 'return',
            'record_constant_fields' => [
                'type' => dcim_msg('assets.back_to_store'),
            ],
            'id_required_message' => dcim_msg('error.spare_id_required'),
            'not_found_message' => dcim_msg('error.spare_part_not_found'),
        ]);

        if ($result === null) {
            return;
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function getConsumablesChangeDetail(): void
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-consumablerecord')->legacyList($data, [
            'base_where' => ['status = 1'],
            'exact_filters' => [
                'ConsumableId' => 'ConsumableId',
                'type' => 'type',
            ],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        if ($rows) {
            $consumableIds = [];
            $useEmpIds = [];
            foreach ($rows as $row) {
                if (!empty($row['ConsumableId'])) {
                    $consumableIds[] = $row['ConsumableId'];
                }
                if (!empty($row['UseEmpId'])) {
                    $useEmpIds[] = $row['UseEmpId'];
                }
            }
            $consumableMap = [];
            $storeIds = [];
            $ownerEmpIds = [];
            foreach (self::crud('dcim-consumables')->selectByIds($consumableIds, [
                'id',
                'ConsumablesName',
                'ConsumablesNumber',
                'ConsumableBrand',
                'ConsumableSpec',
                'ConsumableUse',
                'StoreLocationId',
                'EmpId',
            ]) as $item) {
                $key = (string)($item['id'] ?? '');
                if ($key === '') {
                    continue;
                }
                $consumableMap[$key] = $item;
                if (!empty($item['StoreLocationId'])) {
                    $storeIds[] = $item['StoreLocationId'];
                }
                if (!empty($item['EmpId'])) {
                    $ownerEmpIds[] = $item['EmpId'];
                }
            }
            $storeMap = [];
            foreach (self::crud('dcim-store')->selectByIds($storeIds, ['id', 'StoreLocationName']) as $item) {
                $key = (string)($item['id'] ?? '');
                if ($key !== '') {
                    $storeMap[$key] = $item['StoreLocationName'] ?? '';
                }
            }
            $allPersonIds = array_values(array_unique(array_merge($ownerEmpIds, $useEmpIds)));
            $personMap = [];
            $deptIds = [];
            foreach (self::crud('dcim-person')->selectByIds($allPersonIds, ['id', 'PersonName', 'DeptId']) as $item) {
                $key = (string)($item['id'] ?? '');
                if ($key === '') {
                    continue;
                }
                $personMap[$key] = $item;
                if (!empty($item['DeptId'])) {
                    $deptIds[] = $item['DeptId'];
                }
            }
            $deptMap = [];
            foreach (self::crud('dcim-department')->selectByIds($deptIds, ['id', 'DeptName']) as $item) {
                $key = (string)($item['id'] ?? '');
                if ($key !== '') {
                    $deptMap[$key] = $item['DeptName'] ?? '';
                }
            }
            foreach ($rows as &$row) {
                $consumable = $consumableMap[(string)($row['ConsumableId'] ?? '')] ?? [];
                $row['ConsumablesName'] = $consumable['ConsumablesName'] ?? '';
                $row['ConsumablesNumber'] = $consumable['ConsumablesNumber'] ?? '';
                $row['ConsumableBrand'] = $consumable['ConsumableBrand'] ?? '';
                $row['ConsumableSpec'] = $consumable['ConsumableSpec'] ?? '';
                $row['ConsumableUse'] = $consumable['ConsumableUse'] ?? '';
                $row['StoreLocationId'] = $consumable['StoreLocationId'] ?? ($row['StoreLocationId'] ?? '');
                $row['StoreLocationName'] = $storeMap[(string)($row['StoreLocationId'] ?? '')] ?? '';
                $row['EmpId'] = $consumable['EmpId'] ?? ($row['EmpId'] ?? '');
                $owner = $personMap[(string)($row['EmpId'] ?? '')] ?? [];
                $row['PersonName'] = $owner['PersonName'] ?? '';
                $row['DeptId'] = $owner['DeptId'] ?? ($row['DeptId'] ?? '');
                $row['DeptName'] = $deptMap[(string)($row['DeptId'] ?? '')] ?? '';
                $row['UsePersonName'] = $personMap[(string)($row['UseEmpId'] ?? '')]['PersonName'] ?? '';
            }
            unset($row);
            $result['info'] = $rows;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function enterConsumables(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);

        $result = self::crud('dcim-consumablerecord')->legacyAdjustStockAndRecord($data, [
            'stock_table' => 'dcim-consumables',
            'record_table' => 'dcim-consumablerecord',
            'id_param' => 'id',
            'number_param' => 'number',
            'record_fk_field' => 'ConsumableId',
            'available_field' => 'SurplusConsumablesNumber',
            'mode' => 'in',
            'record_constant_fields' => [
                'type' => dcim_msg('assets.inbound'),
                'remark' => '',
            ],
            'record_extra_fields' => [
                'remark' => 'remark',
            ],
            'id_required_message' => dcim_msg('common.id_required'),
            'not_found_message' => dcim_msg('error.consumable_not_found'),
        ]);

        if ($result === null) {
            return;
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function exportConsumables(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);

        $result = self::crud('dcim-consumablerecord')->legacyAdjustStockAndRecord($data, [
            'stock_table' => 'dcim-consumables',
            'record_table' => 'dcim-consumablerecord',
            'id_param' => 'id',
            'number_param' => 'number',
            'record_fk_field' => 'ConsumableId',
            'available_field' => 'SurplusConsumablesNumber',
            'mode' => 'out',
            'record_constant_fields' => [
                'type' => dcim_msg('assets.outbound'),
                'remark' => '',
            ],
            'record_extra_fields' => [
                'UseEmpId' => 'UseEmpId',
                'remark' => 'remark',
            ],
            'id_required_message' => dcim_msg('common.id_required'),
            'not_found_message' => dcim_msg('error.consumable_not_found'),
            'insufficient_message' => dcim_msg('error.insufficient_consumable_stock'),
        ]);

        if ($result === null) {
            return;
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function lendTool(): void
    {
        self::lendResource('dcim-toolrecord', 'ToolId', 'dcim-tool', 'ToolRecordStatus', 'ToolRecordId');
    }

    public static function returnTool(): void
    {
        self::returnResource('dcim-toolrecord', 'ToolId', 'dcim-tool', 'ToolRecordStatus', 'ToolRecordId');
    }

    public static function getToolUse(): void
    {
        $data = Flight::request_data();
        $resourceType = strtolower(trim((string)($data['ResourceType'] ?? $data['resourceType'] ?? $data['recordType'] ?? $data['RecordType'] ?? '')));
        $preferKey = in_array($resourceType, ['key', 'keys', 'dcim-keyrecord'], true)
            || !empty($data['KeyId'])
            || !empty($data['KeyName'])
            || !empty($data['KeyNumber']);
        self::getLendListByTable($preferKey ? 'dcim-keyrecord' : 'dcim-toolrecord', $data);
    }

    public static function lendKey(): void
    {
        self::lendResource('dcim-keyrecord', 'KeyId', 'dcim-key', 'KeyRecordStatus', 'KeyRecordId');
    }

    public static function returnKey(): void
    {
        self::returnResource('dcim-keyrecord', 'KeyId', 'dcim-key', 'KeyRecordStatus', 'KeyRecordId');
    }

    public static function getKeyUse(): void
    {
        self::getLendListByTable('dcim-keyrecord');
    }

    public static function createTenant(): void
    {
        self::createResource('dcim-tenant');
    }

    public static function getTenantList(): void
    {
        self::listResource('dcim-tenant', [
            'base_where' => ['status = 1'],
            'search_fields' => ['TenantName'],
            'order_by' => 'ORDER BY id DESC',
        ]);
    }

    public static function getTenantDetail(): void
    {
        self::infoResource('dcim-tenant');
    }

    public static function changeTenant(): void
    {
        self::updateResource('dcim-tenant');
    }

    public static function delTenant(): void
    {
        self::delResource('dcim-tenant');
    }

    public static function createTool(): void
    {
        self::createResource('dcim-tool');
    }

    public static function getToolList(): void
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-tool')->legacyList($data, [
            'base_where' => ['status = 1'],
            'exact_filters' => ['ToolStatus' => 'ToolStatus'],
            'between_filters' => [
                ['field' => 'PutinTime', 'start_key' => 'startDateTime', 'end_key' => 'endDateTime'],
            ],
            'search_fields' => ['ToolName'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        if ($rows) {
            $recordIds = [];
            foreach ($rows as $row) {
                $rid = trim((string)($row['ToolRecordId'] ?? ''));
                if ($rid !== '') {
                    $recordIds[] = $rid;
                }
            }
            $recordMap = [];
            if ($recordIds) {
                foreach (self::crud('dcim-toolrecord')->selectByIds(array_values(array_unique($recordIds)), ['id', 'LendPerson', 'LendTel', 'LendTime', 'ReturnTime', 'type']) as $rec) {
                    $key = (string)($rec['id'] ?? '');
                    if ($key !== '') {
                        $recordMap[$key] = $rec;
                    }
                }
            }
            foreach ($rows as &$row) {
                $record = $recordMap[(string)($row['ToolRecordId'] ?? '')] ?? [];
                $row['LendPerson'] = $record['LendPerson'] ?? ($row['LendPerson'] ?? '');
                $row['LendTel'] = $record['LendTel'] ?? ($row['LendTel'] ?? '');
                $row['LendTime'] = $record['LendTime'] ?? ($row['LendTime'] ?? '');
                $row['ReturnTime'] = $record['ReturnTime'] ?? ($row['ReturnTime'] ?? '');
                if (($row['ToolRecordStatus'] ?? '') === 'lend') {
                    $row['ToolRecordStatus'] = dcim_msg('assets.lend');
                } elseif (($row['ToolRecordStatus'] ?? '') === 'available') {
                    $row['ToolRecordStatus'] = dcim_msg('assets.not_lent');
                } elseif (($row['ToolRecordStatus'] ?? '') === '') {
                    $row['ToolRecordStatus'] = in_array((string)($record['type'] ?? ''), [dcim_msg('assets.lend')], true) ? dcim_msg('assets.lend') : dcim_msg('assets.not_lent');
                }
            }
            unset($row);
            $result['info'] = $rows;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function getToolDetail(): void
    {
        self::infoResource('dcim-tool');
    }

    public static function changeTool(): void
    {
        self::updateResource('dcim-tool');
    }

    public static function delTool(): void
    {
        self::delResource('dcim-tool');
    }

    public static function createKey(): void
    {
        self::createResource('dcim-key');
    }

    public static function getKeyList(): void
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-key')->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => ['KeyName'],
            'exact_filters' => ['KeyStatus' => 'KeyStatus'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        if ($rows) {
            $recordIds = [];
            foreach ($rows as $row) {
                $rid = trim((string)($row['KeyRecordId'] ?? ''));
                if ($rid !== '') {
                    $recordIds[] = $rid;
                }
            }
            $recordMap = [];
            if ($recordIds) {
                foreach (self::crud('dcim-keyrecord')->selectByIds(array_values(array_unique($recordIds)), ['id', 'LendPerson', 'LendTel', 'LendTime', 'ReturnTime', 'type']) as $rec) {
                    $key = (string)($rec['id'] ?? '');
                    if ($key !== '') {
                        $recordMap[$key] = $rec;
                    }
                }
            }
            foreach ($rows as &$row) {
                $record = $recordMap[(string)($row['KeyRecordId'] ?? '')] ?? [];
                $row['LendPerson'] = $record['LendPerson'] ?? ($row['LendPerson'] ?? '');
                $row['LendTel'] = $record['LendTel'] ?? ($row['LendTel'] ?? '');
                $row['LendTime'] = $record['LendTime'] ?? ($row['LendTime'] ?? '');
                $row['ReturnTime'] = $record['ReturnTime'] ?? ($row['ReturnTime'] ?? '');
                if (($row['KeyRecordStatus'] ?? '') === 'lend') {
                    $row['KeyRecordStatus'] = dcim_msg('assets.lend');
                } elseif (($row['KeyRecordStatus'] ?? '') === 'available') {
                    $row['KeyRecordStatus'] = dcim_msg('assets.not_lent');
                } elseif (($row['KeyRecordStatus'] ?? '') === '') {
                    $row['KeyRecordStatus'] = in_array((string)($record['type'] ?? ''), [dcim_msg('assets.lend')], true) ? dcim_msg('assets.lend') : dcim_msg('assets.not_lent');
                }
            }
            unset($row);
            $result['info'] = $rows;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function getKeyDetail(): void
    {
        self::infoResource('dcim-key');
    }

    public static function changeKey(): void
    {
        self::updateResource('dcim-key');
    }

    public static function delKey(): void
    {
        self::delResource('dcim-key');
    }

    public static function tenantUInfoAdd(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $tenantuCrud = self::crud('dcim-tenantu');

        $locations = self::tenantUNormalizeLocations($data['TenantUlocation'] ?? '');
        if (!$locations) {
            P_E(dcim_msg('error.tenant_ulocation_required'));
        }
        $data['TenantUlocation'] = implode(',', $locations);

        $id = $tenantuCrud->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }

        if (!empty($data['CabinetId']) && !empty($data['TenantUlocation'])) {
            self::tenantUUpdateCabinetULocations($data['CabinetId'], $data['TenantUlocation'], 'occupied');
        }

        self::crud('dcim-tenanturecord')->legacyInsert([
            'RentId' => $id,
            'RentType' => 'create',
            'duration' => $data['TenantDuration'] ?? null,
            'status' => 1,
        ]);

        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function tenantUGetList(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);

        $tenantuCrud = self::crud('dcim-tenantu');
        $conditions = ['status = 1'];
        $params = [];

        if (!empty($data['TenantStatus'])) {
            $conditions[] = 'TenantStatus = :tenantStatus';
            $params[':tenantStatus'] = $data['TenantStatus'];
        }

        if (!empty($data['search'])) {
            $tenantRows = self::crud('dcim-tenant')->selectByRawCondition(
                'status = 1 AND TenantName LIKE :tn',
                '',
                [':tn' => '%' . $data['search'] . '%']
            );
            $tenantIds = array_column($tenantRows, 'id');
            if (!$tenantIds) {
                O_E(['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => 1]], tp_msg_success(), 100, false);
                return;
            }
            $phs = [];
            foreach ($tenantIds as $idx => $tid) {
                $ph = ':tid' . $idx;
                $phs[] = $ph;
                $params[$ph] = $tid;
            }
            $conditions[] = 'TenantId IN (' . implode(',', $phs) . ')';
        }

        if (!empty($data['AreaId'])) {
            $cabinetRows = self::crud('dcim-cabinet')->selectByRawCondition(
                'status = 1 AND AreaId = :aid',
                '',
                [':aid' => $data['AreaId']]
            );
            $cabinetIds = array_column($cabinetRows, 'id');
            if (!$cabinetIds) {
                O_E(['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => 1]], tp_msg_success(), 100, false);
                return;
            }
            $phs = [];
            foreach ($cabinetIds as $idx => $cid) {
                $ph = ':cid' . $idx;
                $phs[] = $ph;
                $params[$ph] = $cid;
            }
            $conditions[] = 'CabinetId IN (' . implode(',', $phs) . ')';
        }

        if (!empty($data['CabinetId'])) {
            $conditions[] = 'CabinetId = :cabinetId';
            $params[':cabinetId'] = $data['CabinetId'];
        }

        $where = implode(' AND ', $conditions);
        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;
        $list = $tenantuCrud->selectWithPagination($where, $params, 'ORDER BY id DESC', $page, $pageSize);

        $info = [];
        $tenantCrud = self::crud('dcim-tenant');
        $cabinetCrud = self::crud('dcim-cabinet');
        $areaCrud = self::crud('dcim-area');
        $serverCrud = self::crud('dcim-server');
        foreach ($list['info'] as $row) {
            $cabinet = $cabinetCrud->findOne([['id', '=', $row['CabinetId']]]);
            $tenant = $tenantCrud->findOne([['id', '=', $row['TenantId']]]);
            $area = $cabinet ? $areaCrud->findOne([['id', '=', $cabinet['AreaId']]]) : null;
            $server = $area ? $serverCrud->findOne([['id', '=', $area['ServerCode']]]) : null;

            $tenantStart = $row['TenantStartTime'] ?? '';
            $tenantDuration = (int) ($row['TenantDuration'] ?? 0);
            $computedStatus = $row['TenantStatus'] ?? '';
            if ($computedStatus === '' && $tenantStart !== '') {
                $startTs = strtotime($tenantStart);
                $days = $startTs ? floor((time() - $startTs) / 86400) : 0;
                $remain = $days - $tenantDuration;
                $computedStatus = $remain > 0 ? 'expired' : 'active';
            }

            $info[] = [
                'id' => $row['id'],
                'TenantId' => $row['TenantId'],
                'TenantName' => $tenant['TenantName'] ?? '',
                'CabinetId' => $row['CabinetId'],
                'TenantUlocation' => $row['TenantUlocation'] ?? '',
                'TenantStartTime' => $row['TenantStartTime'] ?? '',
                'TenantDuration' => $row['TenantDuration'] ?? '',
                'TenantStatus' => $computedStatus,
                'AreaId' => $cabinet['AreaId'] ?? '',
                'AreaName' => $area['AreaName'] ?? '',
                'ServerName' => $server['ServerName'] ?? '',
                'column' => $cabinet['column'] ?? '',
                'position' => $cabinet['position'] ?? '',
            ];
        }

        $list['info'] = $info;
        $num = $info ? count($info) : false;
        O_E($list, tp_msg_success(), 100, $num);
    }

    public static function tenantUInfoUpdate(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }

        $crud = self::crud('dcim-tenantu');
        $current = $crud->findOne([['id', '=', $data['id']], ['status', '=', 1]]);
        if (!$current) {
            P_E(dcim_msg('error.record_not_found'));
        }

        if (isset($data['TenantDuration'])) {
            $data['TenantDuration'] = (int) $data['TenantDuration'] + (int) ($current['TenantDuration'] ?? 0);
        }

        $res = $crud->legacyUpdate($data);
        if ($res === null) {
            return;
        }

        if ($res && isset($data['TenantDuration'])) {
            self::crud('dcim-tenanturecord')->legacyInsert([
                'RentId' => $data['id'],
                'RentType' => 'extend',
                'duration' => $data['TenantDuration'],
                'status' => 1,
            ]);
        }

        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function tenantUReleaseU(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }

        $crud = self::crud('dcim-tenantu');
        $tenantu = $crud->findOne([['id', '=', $data['id']], ['status', '=', 1]]);
        if (!$tenantu) {
            P_E(dcim_msg('error.record_not_found'));
        }

        $data['TenantStatus'] = 'released';
        $res = $crud->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['TenantStatus'],
        ]);
        if ($res === null) {
            return;
        }
        if (!empty($tenantu['CabinetId']) && !empty($tenantu['TenantUlocation'])) {
            self::tenantUUpdateCabinetULocations($tenantu['CabinetId'], $tenantu['TenantUlocation'], null);
        }

        self::crud('dcim-tenanturecord')->legacyInsert([
            'RentId' => $data['id'],
            'RentType' => 'release',
            'status' => 1,
        ]);

        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function tenantUCreatePreemption(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        foreach (['PreCapacityModelId', 'PreDescribe', 'UPosition', 'PreUPosition'] as $nullableField) {
            if (array_key_exists($nullableField, $data)) {
                if ($data[$nullableField] === null || (is_string($data[$nullableField]) && trim($data[$nullableField]) === '')) {
                    $data[$nullableField] = null;
                }
            }
        }
        $crud = self::crud('dcim-preemption');
        $id = $crud->legacyCreate($data, [
            'defaults' => ['status' => 1],
            'null_if_empty_fields' => ['PreCapacityModelId', 'PreDescribe', 'UPosition', 'PreUPosition'],
        ]);
        if ($id === null) {
            return;
        }
        $cabinetId = trim((string)($data['PreCabinetId'] ?? ($data['CabinetId'] ?? '')));
        $prePosRaw = trim((string)($data['PreUPosition'] ?? ($data['UPosition'] ?? '')));
        if ($cabinetId !== '' && $prePosRaw !== '') {
            $positions = array_values(array_filter(array_map('trim', explode(',', $prePosRaw)), static function ($v) {
                return $v !== '';
            }));
            if ($positions) {
                foreach ($positions as $pos) {
                    self::crud('dcim-cabinetu')->legacyUpdateWhere(
                        [
                            ['CabinetId', '=', $cabinetId],
                            ['ULocation', '=', $pos],
                            ['status', '=', 1],
                        ],
                        ['UStatus' => dcim_msg('assets.status_preemption_position')]
                    );
                }
            }
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function tenantUGetPreemptionList(): void
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-preemption');
        $result = $crud->legacyList($data, [
            'base_where' => ['status = 1'],
            'exact_filters' => [
                'CabinetId' => 'PreCabinetId',
                'column' => 'column',
            ],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function tenantUReleasePreemption(): void
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }
        $crud = self::crud('dcim-preemption');
        $pre = $crud->findOne([['id', '=', $data['id']], ['status', '=', 1]]);
        if (!$pre) {
            P_E(dcim_msg('error.record_not_found'));
        }
        $positions = $pre['PreUPosition'] ?? '';
        $toRelease = isset($data['UPosition']) ? array_values(array_filter(array_map('trim', explode(',', (string)$data['UPosition'])), static function ($v) {
            return $v !== '';
        })) : [];
        $remaining = [];
        $cabinetuCrud = self::crud('dcim-cabinetu');
        foreach (explode(',', (string) $positions) as $pos) {
            $pos = trim($pos);
            if ($pos === '') {
                continue;
            }
            if (in_array($pos, $toRelease, true)) {
                $cabinetuCrud->legacyUpdateWhere(
                    [
                        ['CabinetId', '=', $pre['PreCabinetId']],
                        ['ULocation', '=', $pos],
                        ['status', '=', 1],
                    ],
                    ['UStatus' => null]
                );
            } else {
                $remaining[] = $pos;
            }
        }
        if ($remaining) {
            $updateData = $data;
            $updateData['PreUPosition'] = implode(',', $remaining);
            $res = $crud->legacyUpdate($updateData, [
                'id_required_message' => dcim_msg('common.id_required'),
                'only_fields' => ['PreUPosition'],
            ]);
            if ($res === null) {
                return;
            }
        } else {
            $updateData = $data;
            $updateData['PreStatus'] = dcim_msg('assets.status_released');
            $updateData['PreUPosition'] = '';
            $res = $crud->legacyUpdate($updateData, [
                'id_required_message' => dcim_msg('common.id_required'),
                'only_fields' => ['PreStatus', 'PreUPosition'],
            ]);
            if ($res === null) {
                return;
            }
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function createSpareParts(): void
    {
        self::createSupply('dcim-spareparts');
    }

    public static function getSparePartsList(): void
    {
        self::listSupply('dcim-spareparts', 'BackupsName');
    }

    public static function getSparePartsDetail(): void
    {
        self::infoSupply('dcim-spareparts');
    }

    public static function changeSpareParts(): void
    {
        self::updateSupply('dcim-spareparts');
    }

    public static function delSpareParts(): void
    {
        self::delResource('dcim-spareparts');
    }

    public static function createConsumables(): void
    {
        self::createSupply('dcim-consumables');
    }

    public static function getConsumablesList(): void
    {
        self::listSupply('dcim-consumables', 'ConsumablesName');
    }

    public static function getConsumablesDetail(): void
    {
        self::infoSupply('dcim-consumables');
    }

    public static function changeConsumables(): void
    {
        self::updateSupply('dcim-consumables');
    }
}




