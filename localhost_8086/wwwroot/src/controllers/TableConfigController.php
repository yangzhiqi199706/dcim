<?php

class TableConfigController
{
    private static function pickFirstField(array $row, array $fields)
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $val = $row[$field];
            if ($val !== null && $val !== '') {
                return $val;
            }
        }
        return null;
    }

    private static function pickFirstFieldInsensitive(array $row, array $fields, $default = null)
    {
        $value = self::pickFirstField($row, $fields);
        if ($value !== null && $value !== '') {
            return $value;
        }
        $lowerMap = [];
        foreach ($row as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $lowerMap[strtolower($k)] = $v;
        }
        foreach ($fields as $field) {
            $lookup = strtolower((string)$field);
            if ($lookup === '') {
                continue;
            }
            if (!array_key_exists($lookup, $lowerMap)) {
                continue;
            }
            $v = $lowerMap[$lookup];
            if ($v !== null && $v !== '') {
                return $v;
            }
        }
        return $default;
    }

    private static function crud(string $table): CrudController
    {
        return new CrudController($table);
    }

    private static function ensureAuth(array $data = [])
    {
        $user = self::crud('dcim-person')->legacyEnsureAuth($data);
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

    private static function create(string $table, array $data)
    {
        $id = self::crud($table)->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    private static function list(string $table, array $data, array $searchFields = [])
    {
        $result = self::crud($table)->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => $searchFields,
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    private static function info(string $table, array $data)
    {
        $info = self::crud($table)->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    private static function routeBuildInCondition(string $field, array $ids, string $prefix, array &$params): ?string
    {
        $uniq = [];
        foreach ($ids as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $uniq[$id] = true;
            }
        }
        $keys = array_keys($uniq);
        if (!$keys) {
            return null;
        }
        $holders = [];
        $i = 0;
        foreach ($keys as $id) {
            $ph = ':' . $prefix . $i;
            $holders[] = $ph;
            $params[$ph] = $id;
            $i++;
        }
        return $field . ' IN (' . implode(',', $holders) . ')';
    }

    private static function enrichRouteRows(array $rows): array
    {
        if (!$rows) {
            return [];
        }

        $routeAssetId = static function (array $row, array $fields): string {
            foreach ($fields as $field) {
                $val = trim((string)($row[$field] ?? ''));
                if ($val !== '') {
                    return $val;
                }
            }
            return '';
        };

        $assetIds = [];
        foreach ($rows as $row) {
            $aId = $routeAssetId((array)$row, ['A', 'AId', 'AAssetsId', 'AssetAId']);
            $bId = $routeAssetId((array)$row, ['B', 'BId', 'BAssetsId', 'AssetBId']);
            if ($aId !== '') {
                $assetIds[] = $aId;
            }
            if ($bId !== '') {
                $assetIds[] = $bId;
            }
        }
        $assetMap = self::idMap('dcim-asset', $assetIds, ['id', 'ModelId', 'AssetsNumber', 'AssetsDescribe']);

        $typeIds = [];
        $modelIds = [];
        foreach ($assetMap as $asset) {
            if (!empty($asset['ModelId'])) {
                $modelIds[] = $asset['ModelId'];
            }
        }
        $modelMap = self::idMap('dcim-brandmodel', $modelIds, ['*']);
        foreach ($modelMap as $model) {
            $typeId = self::pickFirstField($model, ['AssetsTypeId', 'AssetsTypeID', 'TypeId', 'TypeID']);
            if (!empty($typeId)) {
                $typeIds[] = $typeId;
            }
        }
        $typeMap = self::idMap('dcim-assettype', $typeIds, ['id', 'AssetsTypeName']);

        $cabinetMapByAsset = [];
        $uLocationByAsset = [];
        $areaIds = [];

        $params = [];
        $inCond = self::routeBuildInCondition('AssetsId', $assetIds, 'aid_', $params);
        if ($inCond !== null) {
            $cabinetRows = self::crud('dcim-cabinet')->selectByRawCondition(
                'status = 1 AND ' . $inCond,
                '',
                $params
            );
            foreach ($cabinetRows as $cab) {
                $aid = (string)($cab['AssetsId'] ?? '');
                if ($aid === '') {
                    continue;
                }
                if (!isset($cabinetMapByAsset[$aid])) {
                    $cabinetMapByAsset[$aid] = $cab;
                }
                if (!empty($cab['AreaId'])) {
                    $areaIds[] = $cab['AreaId'];
                }
            }

            $uParams = [];
            $uInCond = self::routeBuildInCondition('AssetsId', $assetIds, 'uid_', $uParams);
            if ($uInCond !== null) {
                $uRows = self::crud('dcim-cabinetu')->selectByRawCondition(
                    'status = 1 AND ' . $uInCond,
                    'ORDER BY id ASC',
                    $uParams
                );
                foreach ($uRows as $uRow) {
                    $aid = (string)($uRow['AssetsId'] ?? '');
                    if ($aid === '' || isset($uLocationByAsset[$aid])) {
                        continue;
                    }
                    $uLocationByAsset[$aid] = $uRow['ULocation'] ?? '';
                }
            }
        }

        $areaMap = self::idMap('dcim-area', $areaIds, ['id', 'AreaName']);

        foreach ($rows as &$row) {
            $aId = $routeAssetId((array)$row, ['A', 'AId', 'AAssetsId', 'AssetAId']);
            $bId = $routeAssetId((array)$row, ['B', 'BId', 'BAssetsId', 'AssetBId']);
            if ($aId !== '') {
                $row['A'] = $aId;
            }
            if ($bId !== '') {
                $row['B'] = $bId;
            }

            $aAsset = $assetMap[$aId] ?? [];
            $bAsset = $assetMap[$bId] ?? [];
            $aCab = $cabinetMapByAsset[$aId] ?? [];
            $bCab = $cabinetMapByAsset[$bId] ?? [];

            $row['AreaName'] = $areaMap[(string)($aCab['AreaId'] ?? '')]['AreaName'] ?? '';
            $row['column'] = $aCab['column'] ?? '';
            $row['position'] = $aCab['position'] ?? '';
            $row['ULocation'] = $uLocationByAsset[$aId] ?? ($aCab['ULocation'] ?? ($aCab['UHigh'] ?? ''));
            $aModel = $modelMap[(string)($aAsset['ModelId'] ?? '')] ?? [];
            $bModel = $modelMap[(string)($bAsset['ModelId'] ?? '')] ?? [];
            $aTypeId = (string)(self::pickFirstField($aModel, ['AssetsTypeId', 'AssetsTypeID', 'TypeId', 'TypeID']) ?? '');
            $bTypeId = (string)(self::pickFirstField($bModel, ['AssetsTypeId', 'AssetsTypeID', 'TypeId', 'TypeID']) ?? '');
            $row['AssetsTypeName'] = $typeMap[$aTypeId]['AssetsTypeName'] ?? '';
            $row['BrandModel'] = $aModel['BrandModel'] ?? '';
            $row['AssetsNumber'] = $aAsset['AssetsNumber'] ?? '';
            $row['AssetsDescribe'] = $aAsset['AssetsDescribe'] ?? '';

            $row['bAreaName'] = $areaMap[(string)($bCab['AreaId'] ?? '')]['AreaName'] ?? '';
            $row['bcolumn'] = $bCab['column'] ?? '';
            $row['bposition'] = $bCab['position'] ?? '';
            $row['bULocation'] = $uLocationByAsset[$bId] ?? ($bCab['ULocation'] ?? ($bCab['UHigh'] ?? ''));
            $row['bAssetsTypeName'] = $typeMap[$bTypeId]['AssetsTypeName'] ?? '';
            $row['bBrandModel'] = $bModel['BrandModel'] ?? '';
            $row['bAssetsNumber'] = $bAsset['AssetsNumber'] ?? '';
            $row['bAssetsDescribe'] = $bAsset['AssetsDescribe'] ?? '';
        }
        unset($row);

        return $rows;
    }

    private static function update(string $table, array $data)
    {
        $res = self::crud($table)->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    private static function del(string $table, array $data)
    {
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

    // Light CRUD compatible behavior (keep legacy response shape).
    private static function lightList(string $table, array $data, array $searchFields = [])
    {
        $result = self::crud($table)->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => $searchFields,
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    private static function lightInfo(string $table, array $data)
    {
        $info = self::crud($table)->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    private static function lightUpdate(string $table, array $data)
    {
        $res = self::crud($table)->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    private static function lightCreate(string $table, array $data)
    {
        $id = self::crud($table)->legacyCreate($data);
        if ($id === null) {
            return;
        }
        O_E($id ? true : false, tp_msg_success(), 100, $id ? 1 : false);
    }

    private static function lightDel(string $table, array $data)
    {
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

    public static function createGeneralTable()
    {
        self::create('dcim-tablegeneral', Flight::request_data());
    }

    public static function getGeneralTableList()
    {
        self::list('dcim-tablegeneral', Flight::request_data());
    }

    public static function getGeneralTableDetail()
    {
        self::info('dcim-tablegeneral', Flight::request_data());
    }

    public static function changeGeneralTable()
    {
        self::update('dcim-tablegeneral', Flight::request_data());
    }

    public static function delGeneralTable()
    {
        self::del('dcim-tablegeneral', Flight::request_data());
    }

    public static function createWSDTable()
    {
        self::create('dcim-tablewsd', Flight::request_data());
    }

    public static function getWSDTableList()
    {
        self::list('dcim-tablewsd', Flight::request_data(), ['WSDTableName']);
    }

    public static function getWSDTableDetail()
    {
        self::info('dcim-tablewsd', Flight::request_data());
    }

    public static function changeWSDTable()
    {
        self::update('dcim-tablewsd', Flight::request_data());
    }

    public static function delWSDTable()
    {
        self::del('dcim-tablewsd', Flight::request_data());
    }

    public static function createAirTable()
    {
        self::create('dcim-tableair', Flight::request_data());
    }

    public static function getAirTableList()
    {
        self::list('dcim-tableair', Flight::request_data(), ['AirTableName']);
    }

    public static function getAirTableDetail()
    {
        self::info('dcim-tableair', Flight::request_data());
    }

    public static function changeAirTable()
    {
        self::update('dcim-tableair', Flight::request_data());
    }

    public static function delAirTable()
    {
        self::del('dcim-tableair', Flight::request_data());
    }

    public static function createPowerTable()
    {
        self::create('dcim-tablepower', Flight::request_data());
    }

    public static function getPowerTableList()
    {
        self::list('dcim-tablepower', Flight::request_data(), ['PowerTableName']);
    }

    public static function getPowerTableDetail()
    {
        self::info('dcim-tablepower', Flight::request_data());
    }

    public static function changePowerTable()
    {
        self::update('dcim-tablepower', Flight::request_data());
    }

    public static function delPowerTable()
    {
        self::del('dcim-tablepower', Flight::request_data());
    }

    public static function createUPSTable()
    {
        self::create('dcim-tableups', Flight::request_data());
    }

    public static function getUPSTableList()
    {
        self::list('dcim-tableups', Flight::request_data(), ['UPSTableName']);
    }

    public static function getUPSTableDetail()
    {
        self::info('dcim-tableups', Flight::request_data());
    }

    public static function changeUPSTable()
    {
        self::update('dcim-tableups', Flight::request_data());
    }

    public static function delUPSTable()
    {
        self::del('dcim-tableups', Flight::request_data());
    }

    public static function createRoute()
    {
        self::create('dcim-route', Flight::request_data());
    }

    public static function getRouteList()
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-route')->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => ['A'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $result['info'] = self::enrichRouteRows($rows);
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function getRouteDetail()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-route')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if ($info) {
            $rows = self::enrichRouteRows([$info]);
            $info = $rows ? $rows[0] : $info;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function changeRoute()
    {
        self::update('dcim-route', Flight::request_data());
    }

    public static function delRoute()
    {
        self::del('dcim-route', Flight::request_data());
    }

    public static function createCapacityModel()
    {
        self::create('dcim-capacitymodel', Flight::request_data());
    }

    public static function getCapacityModelList()
    {
        self::list('dcim-capacitymodel', Flight::request_data(), ['CapacityModelName']);
    }

    public static function getCapacityModelDetail()
    {
        self::info('dcim-capacitymodel', Flight::request_data());
    }

    public static function changeCapacityModel()
    {
        self::update('dcim-capacitymodel', Flight::request_data());
    }

    public static function delCapacityModel()
    {
        self::del('dcim-capacitymodel', Flight::request_data());
    }

    public static function createNYClass()
    {
        self::create('dcim-nyclass', Flight::request_data());
    }

    public static function getNYClassList()
    {
        $data = Flight::request_data();
        $cols = self::tcGetTableColumns('dcim-nyclass');
        $searchFields = ['NYClassName'];
        foreach (['ServerCode', 'ServerId', 'ServerID', 'AreaId', 'AreaID'] as $field) {
            if (isset($cols[$field])) {
                $searchFields[] = $field;
            }
        }
        $exactFilters = [];
        foreach (['ServerCode', 'ServerId', 'ServerID', 'AreaId', 'AreaID'] as $field) {
            if (isset($cols[$field])) {
                $exactFilters[$field] = $field;
            }
        }
        $result = self::crud('dcim-nyclass')->legacyList($data, [
            'base_where' => [isset($cols['status']) ? 'status = 1' : '1=1'],
            'search_fields' => array_values(array_unique($searchFields)),
            'exact_filters' => $exactFilters,
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $result['info'] = self::enrichNYClassRows(is_array($result['info'] ?? null) ? $result['info'] : []);
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function getNYClassDetail()
    {
        $info = self::crud('dcim-nyclass')->legacyInfo(Flight::request_data(), [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if ($info) {
            $rows = self::enrichNYClassRows([$info]);
            $info = $rows ? $rows[0] : $info;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    private static function enrichNYClassRows(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $serverKeys = [];
        $areaIds = [];
        foreach ($rows as $row) {
            $serverKey = self::pickFirstField((array)$row, ['ServerCode', 'ServerId', 'ServerID', 'serverCode', 'server_id']);
            if ($serverKey !== null && $serverKey !== '') {
                $serverKeys[] = $serverKey;
            }
            $areaId = self::pickFirstField((array)$row, ['AreaId', 'AreaID', 'areaId', 'area_id']);
            if ($areaId !== null && $areaId !== '') {
                $areaIds[] = $areaId;
            }
        }
        $serverMap = self::serverMapByAnyKeys($serverKeys, ['id', 'ServerCode', 'ServerName']);
        $areaMap = self::idMap('dcim-area', $areaIds, ['id', 'AreaName']);
        foreach ($rows as &$row) {
            $serverKey = (string)(self::pickFirstField((array)$row, ['ServerCode', 'ServerId', 'ServerID', 'serverCode', 'server_id']) ?? '');
            $areaId = (string)(self::pickFirstField((array)$row, ['AreaId', 'AreaID', 'areaId', 'area_id']) ?? '');
            $server = $serverKey !== '' ? ($serverMap[$serverKey] ?? []) : [];
            $area = $areaId !== '' ? ($areaMap[$areaId] ?? []) : [];
            $row['ServerName'] = (string)($server['ServerName'] ?? ($row['ServerName'] ?? ''));
            $row['AreaName'] = (string)($area['AreaName'] ?? ($row['AreaName'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    public static function changeNYClass()
    {
        self::update('dcim-nyclass', Flight::request_data());
    }

    public static function delNYClass()
    {
        self::del('dcim-nyclass', Flight::request_data());
    }

    public static function createElectric()
    {
        self::create('dcim-electric', Flight::request_data());
    }

    public static function getElectricList()
    {
        self::list('dcim-electric', Flight::request_data(), ['MeterName']);
    }

    public static function getElectricDetail()
    {
        self::info('dcim-electric', Flight::request_data());
    }

    public static function changeElectric()
    {
        self::update('dcim-electric', Flight::request_data());
    }

    public static function delElectric()
    {
        self::del('dcim-electric', Flight::request_data());
    }

    public static function createElectricPrice()
    {
        self::create('dcim-electricprice', Flight::request_data());
    }

    public static function getElectricPriceList()
    {
        $data = Flight::request_data();
        $serverFilterInputs = [];
        $serverFilterIds = [];
        $serverFilterCodes = [];
        $page = isset($data['pageNo']) ? max((int)$data['pageNo'], 1) : 1;
        $pageSize = isset($data['pageSize']) ? max((int)$data['pageSize'], 1) : 15;
        $tableColumns = self::tcGetTableColumns('dcim-electricprice');
        $serverColumnCandidates = ['ServerCode', 'ServerID', 'ServerId', 'serverCode', 'server_id'];
        $serverColumns = [];
        $tableNormMap = [];
        foreach ($tableColumns as $colName => $_exists) {
            $norm = strtolower(str_replace(['_', '-'], '', (string)$colName));
            if ($norm !== '' && !isset($tableNormMap[$norm])) {
                $tableNormMap[$norm] = (string)$colName;
            }
        }
        foreach ($serverColumnCandidates as $col) {
            if (isset($tableColumns[$col])) {
                $serverColumns[] = $col;
                continue;
            }
            $norm = strtolower(str_replace(['_', '-'], '', (string)$col));
            if ($norm !== '' && isset($tableNormMap[$norm])) {
                $serverColumns[] = $tableNormMap[$norm];
            }
        }
        $serverColumns = array_values(array_unique($serverColumns));
        $whereParts = [isset($tableColumns['status']) ? 'status <> -1' : '1=1'];
        $params = [];

        if (isset($data['search']) && $data['search'] !== '' && $data['search'] !== null) {
            $searchVal = trim((string)$data['search']);
            if ($searchVal !== '') {
                $searchParts = [];
                foreach (['id', 'PriceName', 'remark', 'create_time', 'ServerCode', 'AreaId'] as $field) {
                    if (isset($tableColumns[$field])) {
                        $searchParts[] = $field . ' LIKE :kw';
                    }
                }
                foreach ($serverColumns as $serverColumn) {
                    if (!in_array($serverColumn, ['id', 'PriceName', 'remark', 'create_time', 'ServerCode', 'AreaId'], true)) {
                        $searchParts[] = $serverColumn . ' LIKE :kw';
                    }
                }
                if ($searchParts) {
                    $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';
                    $params[':kw'] = '%' . $searchVal . '%';
                }
            }
        }

        if (isset($data['AreaId']) && $data['AreaId'] !== '' && $data['AreaId'] !== null) {
            $areaId = trim((string)$data['AreaId']);
            if ($areaId !== '' && isset($tableColumns['AreaId'])) {
                $whereParts[] = '(AreaId = :areaId OR FIND_IN_SET(:areaIdSet, REPLACE(AreaId, \' \', \'\')) > 0)';
                $params[':areaId'] = $areaId;
                $params[':areaIdSet'] = $areaId;
            }
        }

        if (isset($data['ServerCode']) && $data['ServerCode'] !== '' && $data['ServerCode'] !== null) {
            $serverRaw = trim((string)$data['ServerCode']);
            $serverInputs = array_values(array_filter(array_map('trim', explode(',', $serverRaw)), static function ($v) {
                return $v !== '';
            }));
            if (!$serverInputs) {
                $serverInputs = [$serverRaw];
            }
            $serverFilterInputs = $serverInputs;
            $serverIds = [];
            $serverCodes = [];
            foreach ($serverInputs as $oneInput) {
                try {
                    $serverRows = self::crud('dcim-server')->selectByRawCondition(
                        '(id = :sid OR ServerCode = :scode OR ServerName = :sname) AND status <> -1',
                        '',
                        [':sid' => $oneInput, ':scode' => $oneInput, ':sname' => $oneInput]
                    );
                    foreach ($serverRows as $srv) {
                        $sid = isset($srv['id']) ? trim((string)$srv['id']) : '';
                        $scode = isset($srv['ServerCode']) ? trim((string)$srv['ServerCode']) : '';
                        if ($sid !== '') {
                            $serverIds[$sid] = true;
                        }
                        if ($scode !== '') {
                            $serverCodes[$scode] = true;
                        }
                    }
                } catch (\Throwable $e) {
                    try {
                        $serverRows = self::crud('dcim-server')->selectByRawCondition(
                            '(id = :sid OR ServerCode = :scode OR ServerName = :sname)',
                            '',
                            [':sid' => $oneInput, ':scode' => $oneInput, ':sname' => $oneInput]
                        );
                        foreach ($serverRows as $srv) {
                            $sid = isset($srv['id']) ? trim((string)$srv['id']) : '';
                            $scode = isset($srv['ServerCode']) ? trim((string)$srv['ServerCode']) : '';
                            if ($sid !== '') {
                                $serverIds[$sid] = true;
                            }
                            if ($scode !== '') {
                                $serverCodes[$scode] = true;
                            }
                        }
                    } catch (\Throwable $e2) {
                    }
                }
                if (empty($serverRows)) {
                    try {
                        $serverRows = self::crud('dcim-server')->selectByRawCondition(
                            '(ServerCode LIKE :slike OR ServerName LIKE :nlike) AND status <> -1',
                            '',
                            [':slike' => '%' . $oneInput . '%', ':nlike' => '%' . $oneInput . '%']
                        );
                    } catch (\Throwable $ignore) {
                        try {
                            $serverRows = self::crud('dcim-server')->selectByRawCondition(
                                '(ServerCode LIKE :slike OR ServerName LIKE :nlike)',
                                '',
                                [':slike' => '%' . $oneInput . '%', ':nlike' => '%' . $oneInput . '%']
                            );
                        } catch (\Throwable $ignore2) {
                            $serverRows = [];
                        }
                    }
                    foreach ($serverRows as $srv) {
                        $sid = isset($srv['id']) ? trim((string)$srv['id']) : '';
                        $scode = isset($srv['ServerCode']) ? trim((string)$srv['ServerCode']) : '';
                        if ($sid !== '') {
                            $serverIds[$sid] = true;
                        }
                        if ($scode !== '') {
                            $serverCodes[$scode] = true;
                        }
                    }
                }
            }
            $serverFilterInputs = array_values(array_unique(array_merge($serverFilterInputs, array_keys($serverIds), array_keys($serverCodes))));
            $serverCondParts = [];
            $rawPhs = [];
            foreach ($serverInputs as $idx => $inputVal) {
                $ph = ':server_raw_' . $idx;
                $rawPhs[] = $ph;
                $params[$ph] = $inputVal;
                $params[':server_like_' . $idx] = '%' . $inputVal . '%';
            }
            if ($rawPhs) {
                foreach ($serverColumns as $serverColumn) {
                    $serverCondParts[] = $serverColumn . ' IN (' . implode(',', $rawPhs) . ')';
                }
                $likeParts = [];
                foreach (array_keys($serverInputs) as $idx) {
                    foreach ($serverColumns as $serverColumn) {
                        $likeParts[] = $serverColumn . ' LIKE :server_like_' . $idx;
                    }
                }
                if ($likeParts) {
                    $serverCondParts[] = '(' . implode(' OR ', $likeParts) . ')';
                }
            }
            if ($serverIds) {
                $idPhs = [];
                $idx = 0;
                foreach (array_keys($serverIds) as $sid) {
                    $ph = ':server_id_' . $idx++;
                    $idPhs[] = $ph;
                    $params[$ph] = $sid;
                }
                if ($idPhs) {
                    foreach ($serverColumns as $serverColumn) {
                        $serverCondParts[] = $serverColumn . ' IN (' . implode(',', $idPhs) . ')';
                    }
                }
            }
            if ($serverCodes) {
                $codePhs = [];
                $idx = 0;
                foreach (array_keys($serverCodes) as $scode) {
                    $ph = ':server_code_' . $idx++;
                    $codePhs[] = $ph;
                    $params[$ph] = $scode;
                }
                if ($codePhs) {
                    foreach ($serverColumns as $serverColumn) {
                        $serverCondParts[] = $serverColumn . ' IN (' . implode(',', $codePhs) . ')';
                    }
                }
            }
            if ($serverCondParts) {
                $whereParts[] = '(' . implode(' OR ', array_values(array_unique($serverCondParts))) . ')';
            }
            $serverFilterIds = array_keys($serverIds);
            $serverFilterCodes = array_keys($serverCodes);
        }

        try {
            $result = self::crud('dcim-electricprice')->selectWithPagination(
                implode(' AND ', $whereParts),
                $params,
                'ORDER BY id DESC',
                $page,
                $pageSize
            );
        } catch (\Throwable $e) {
            $result = [
                'info' => [],
                'page' => [
                    'total' => 0,
                    'p_n' => 0,
                    'p' => $page,
                ],
            ];
        }

        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $serverIds = [];
        $areaIds = [];
            foreach ($rows as $row) {
                foreach ($serverColumns as $serverColumn) {
                    $serverCodeVal = $row[$serverColumn] ?? null;
                    if ($serverCodeVal !== '' && $serverCodeVal !== null) {
                        $serverIds[] = $serverCodeVal;
                    }
                }
                foreach (['ServerCode', 'serverCode', 'ServerID', 'ServerId', 'server_id'] as $serverColumn) {
                    $serverCodeVal = $row[$serverColumn] ?? null;
                    if ($serverCodeVal !== '' && $serverCodeVal !== null) {
                        $serverIds[] = $serverCodeVal;
                    }
                }
            if (isset($row['AreaId']) && $row['AreaId'] !== '' && $row['AreaId'] !== null) {
                foreach (explode(',', (string)$row['AreaId']) as $aid) {
                    $aid = trim($aid);
                    if ($aid !== '') {
                        $areaIds[] = $aid;
                    }
                }
            }
        }
        $serverMap = self::serverMapByAnyKeys($serverIds, ['id', 'ServerCode', 'ServerName']);
        $areaMap = self::idMap('dcim-area', $areaIds, ['id', 'AreaName']);
        foreach ($rows as &$row) {
            $serverCodeVal = '';
            foreach ($serverColumns as $serverColumn) {
                $tmpVal = trim((string)($row[$serverColumn] ?? ''));
                if ($tmpVal !== '') {
                    $serverCodeVal = $tmpVal;
                    break;
                }
            }
            if ($serverCodeVal === '') {
                foreach (['ServerCode', 'serverCode', 'ServerID', 'ServerId', 'server_id'] as $serverColumn) {
                    $tmpVal = trim((string)($row[$serverColumn] ?? ''));
                    if ($tmpVal !== '') {
                        $serverCodeVal = $tmpVal;
                        break;
                    }
                }
            }
            $row['ServerName'] = $serverMap[$serverCodeVal]['ServerName'] ?? ($row['ServerName'] ?? '');
            $row['AreaName'] = self::buildAreaNames($areaMap, $row['AreaId'] ?? '');
        }
        unset($row);
        if ($serverFilterInputs || $serverFilterIds || $serverFilterCodes) {
            $rowsBeforeStrictFilter = $rows;
            $allow = [];
            $allowLower = [];
            $allowRawList = [];
            foreach (array_merge($serverFilterInputs, $serverFilterIds, $serverFilterCodes) as $inputVal) {
                $v = trim((string)$inputVal);
                if ($v !== '') {
                    $allow[$v] = true;
                    $allowLower[strtolower($v)] = true;
                    $allowRawList[] = $v;
                }
            }
            $rows = array_values(array_filter($rows, static function ($row) use ($allow, $allowLower, $allowRawList, $serverMap, $serverColumns) {
                $keys = [];
                foreach ($serverColumns as $serverColumn) {
                    $key = trim((string)($row[$serverColumn] ?? ''));
                    if ($key !== '') {
                        $keys[$key] = true;
                    }
                }
                foreach (['ServerCode', 'serverCode', 'ServerID', 'ServerId', 'server_id'] as $serverColumn) {
                    $key = trim((string)($row[$serverColumn] ?? ''));
                    if ($key !== '') {
                        $keys[$key] = true;
                    }
                }
                foreach (array_keys($keys) as $key) {
                    $server = $serverMap[$key] ?? [];
                    $serverId = trim((string)($server['id'] ?? ''));
                    $serverCode = trim((string)($server['ServerCode'] ?? ''));
                    $serverName = trim((string)($server['ServerName'] ?? ($row['ServerName'] ?? $row['serverName'] ?? '')));
                    $containsMatched = false;
                    foreach ($allowRawList as $needle) {
                        if (
                            ($key !== '' && stripos($key, $needle) !== false) ||
                            ($serverId !== '' && stripos($serverId, $needle) !== false) ||
                            ($serverCode !== '' && stripos($serverCode, $needle) !== false) ||
                            ($serverName !== '' && stripos($serverName, $needle) !== false)
                        ) {
                            $containsMatched = true;
                            break;
                        }
                    }
                    if (
                        isset($allow[$key]) ||
                        isset($allowLower[strtolower($key)]) ||
                        ($serverId !== '' && isset($allow[$serverId])) ||
                        ($serverId !== '' && isset($allowLower[strtolower($serverId)])) ||
                        ($serverCode !== '' && isset($allow[$serverCode])) ||
                        ($serverCode !== '' && isset($allowLower[strtolower($serverCode)])) ||
                        ($serverName !== '' && isset($allow[$serverName])) ||
                        ($serverName !== '' && isset($allowLower[strtolower($serverName)])) ||
                        $containsMatched
                    ) {
                        return true;
                    }
                }
                return false;
            }));
            if (!$rows && $rowsBeforeStrictFilter) {
                // Compatibility fallback: when strict post-filter cannot map server keys
                // (schema variant), keep SQL-filtered rows instead of returning empty.
                $rows = $rowsBeforeStrictFilter;
            }
        }
        if (isset($data['search']) && $data['search'] !== '' && $data['search'] !== null) {
            $kw = trim((string)$data['search']);
            if ($kw !== '') {
                $rows = array_values(array_filter($rows, static function ($row) use ($kw) {
                    foreach (['id', 'PriceName', 'remark', 'create_time', 'ServerCode', 'AreaId', 'ServerName', 'AreaName'] as $field) {
                        $val = (string)($row[$field] ?? '');
                        if ($val !== '' && stripos($val, $kw) !== false) {
                            return true;
                        }
                    }
                    return false;
                }));
            }
        }
        $result['info'] = $rows;
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function getElectricPriceDetail()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-electricprice')->legacyInfo($data, [
            'extra_conditions' => [['status', '<>', -1]],
        ]);
        if ($info === null) {
            return;
        }
        if ($info) {
            $serverMap = self::serverMapByAnyKeys([$info['ServerCode'] ?? null], ['id', 'ServerCode', 'ServerName']);
            $areaIds = [];
            foreach (explode(',', (string)($info['AreaId'] ?? '')) as $aid) {
                $aid = trim($aid);
                if ($aid !== '') {
                    $areaIds[] = $aid;
                }
            }
            $areaMap = self::idMap('dcim-area', $areaIds, ['id', 'AreaName']);
            $info['ServerName'] = $serverMap[(string)($info['ServerCode'] ?? '')]['ServerName'] ?? '';
            $info['AreaName'] = self::buildAreaNames($areaMap, $info['AreaId'] ?? '');
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function changeElectricPrice()
    {
        self::update('dcim-electricprice', Flight::request_data());
    }

    public static function delElectricPrice()
    {
        self::del('dcim-electricprice', Flight::request_data());
    }

    public static function createFaultType()
    {
        self::create('dcim-faulttype', Flight::request_data());
    }

    public static function getFaultTypeList()
    {
        self::list('dcim-faulttype', Flight::request_data(), ['FaultTypeName']);
    }

    public static function getFaultTypeDetail()
    {
        self::info('dcim-faulttype', Flight::request_data());
    }

    public static function changeFaultType()
    {
        self::update('dcim-faulttype', Flight::request_data());
    }

    public static function delFaultType()
    {
        self::del('dcim-faulttype', Flight::request_data());
    }

    public static function getCapacityLevelList()
    {
        self::lightList('dcim-capacitylevel', Flight::request_data(), ['CapacityLevel']);
    }

    public static function getCapacityLevelDetail()
    {
        self::lightInfo('dcim-capacitylevel', Flight::request_data());
    }

    public static function changeCapacityLevel()
    {
        self::lightUpdate('dcim-capacitylevel', Flight::request_data());
    }

    public static function getBCList()
    {
        self::lightList('dcim-bc', Flight::request_data(), ['BCName']);
    }

    public static function getBCDetail()
    {
        self::lightInfo('dcim-bc', Flight::request_data());
    }

    public static function changeBC()
    {
        self::lightUpdate('dcim-bc', Flight::request_data());
    }

    public static function createPersonGroup()
    {
        self::lightCreate('dcim-persongroup', Flight::request_data());
    }

    public static function getPersonGroupList()
    {
        self::lightList('dcim-persongroup', Flight::request_data(), ['GroupName']);
    }

    public static function getPersonGroupDetail()
    {
        self::lightInfo('dcim-persongroup', Flight::request_data());
    }

    public static function changePersonGroup()
    {
        self::lightUpdate('dcim-persongroup', Flight::request_data());
    }

    public static function delPersonGroup()
    {
        self::lightDel('dcim-persongroup', Flight::request_data());
    }

    public static function createCameraSetting()
    {
        self::lightCreate('dcim-camerasetting', Flight::request_data());
    }

    public static function getCameraSettingList()
    {
        self::lightList('dcim-camerasetting', Flight::request_data());
    }

    public static function getCameraSettingDetail()
    {
        self::lightInfo('dcim-camerasetting', Flight::request_data());
    }

    public static function changeCameraSetting()
    {
        self::lightUpdate('dcim-camerasetting', Flight::request_data());
    }

    public static function delCameraSetting()
    {
        self::lightDel('dcim-camerasetting', Flight::request_data());
    }

    private static function faultSubTypeImport(array $data, bool $withUpsertKeys)
    {
        if (!self::crud('dcim-faultsubtype')->legacyEnsureAuth($data)) {
            return;
        }
        foreach (['token', 'auth', 'Auth', 'authorization', 'Authorization'] as $key) {
            if (array_key_exists($key, $data)) {
                unset($data[$key]);
            }
        }

        $hasInput = false;
        if (isset($_FILES['file']['tmp_name']) && is_string($_FILES['file']['tmp_name']) && $_FILES['file']['tmp_name'] !== '') {
            $hasInput = true;
        }
        foreach (['rows', 'file_path', 'path'] as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if ((is_array($value) && !empty($value)) || (is_string($value) && trim($value) !== '')) {
                $hasInput = true;
                break;
            }
        }
        if (!$hasInput) {
            O_E(true, tp_msg_success(), 100, 1);
            return;
        }

        if ($withUpsertKeys && !isset($data['upsert_keys'])) {
            $data['upsert_keys'] = ['FaultTypeId', 'FaultSubTypeName'];
        }

        try {
            $result = self::crud('dcim-faultsubtype')->importMappedData($data, [
                'table' => 'dcim-faultsubtype',
            ]);
            O_E($result, tp_msg_success(), 100, isset($result['processed']) ? (int) $result['processed'] : false);
        } catch (\Throwable $e) {
            P_E(str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')));
        }
    }

    private static function orgNormalizeAreaNumericFields(array $data): array
    {
        $numericFields = [
            'EmpId',
            'AreaWidth',
            'AreaHeight',
            'ECabinet',
            'EPower',
            'ECold',
            'UPSECapacity',
            'AreaArea',
            'AreaWeight',
            'PUEId',
            'PUEID',
            'DegreeId',
            'DegreeID',
            'PowerId',
            'PowerID',
            'UPSPowerId',
            'UPSPowerID',
            'ColdPowerId',
            'ColdPowerID',
            'ColdRatioId',
            'ColdRatioID',
            'PowerSupplyId',
            'PowerSupplyID',
        ];

        foreach ($numericFields as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    private static function orgCreate(string $table): void
    {
        $data = Flight::request_data();
        $id = self::crud($table)->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id]);
    }

    private static function tcGetTableColumns(string $table): array
    {
        static $cache = [];
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $table);
        if ($safe === '') {
            return [];
        }
        $driver = '';
        try {
            $driver = strtolower((string)Flight::db()->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            $driver = '';
        }
        $cacheKey = ($driver !== '' ? $driver : 'unknown') . '|' . $safe;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        $cols = [];
        try {
            if ($driver === 'dm') {
                $stmt = Flight::db()->prepare('SELECT COLUMN_NAME FROM USER_TAB_COLUMNS WHERE UPPER(TABLE_NAME)=UPPER(:table_name) ORDER BY COLUMN_ID');
                $stmt->bindValue(':table_name', $safe);
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $field = trim((string)($row['COLUMN_NAME'] ?? ''));
                    if ($field !== '') {
                        $cols[$field] = true;
                    }
                }
                if (!$cols) {
                    $stmt = Flight::db()->prepare('SELECT COLUMN_NAME FROM ALL_TAB_COLUMNS WHERE UPPER(TABLE_NAME)=UPPER(:table_name) ORDER BY COLUMN_ID');
                    $stmt->bindValue(':table_name', $safe);
                    $stmt->execute();
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $field = trim((string)($row['COLUMN_NAME'] ?? ''));
                        if ($field !== '') {
                            $cols[$field] = true;
                        }
                    }
                }
            } else {
                $stmt = Flight::db()->prepare('SHOW COLUMNS FROM `' . $safe . '`');
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $field = trim((string)($row['Field'] ?? ''));
                    if ($field !== '') {
                        $cols[$field] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            $cols = [];
        }
        $cache[$cacheKey] = $cols;
        return $cache[$cacheKey];
    }

    private static function tcIsDmDriver(): bool
    {
        try {
            return strtolower((string)Flight::db()->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'dm';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function tcPickColumn(array $columnMap, array $candidates): string
    {
        if (!$columnMap || !$candidates) {
            return '';
        }
        $direct = [];
        $normalized = [];
        foreach ($columnMap as $column => $_exists) {
            $name = trim((string)$column);
            if ($name === '') {
                continue;
            }
            $lower = strtolower($name);
            if (!isset($direct[$lower])) {
                $direct[$lower] = $name;
            }
            $norm = strtolower(str_replace(['_', '-'], '', $name));
            if ($norm !== '' && !isset($normalized[$norm])) {
                $normalized[$norm] = $name;
            }
        }
        foreach ($candidates as $candidate) {
            $cand = trim((string)$candidate);
            if ($cand === '') {
                continue;
            }
            $lower = strtolower($cand);
            if (isset($direct[$lower])) {
                return $direct[$lower];
            }
            $norm = strtolower(str_replace(['_', '-'], '', $cand));
            if ($norm !== '' && isset($normalized[$norm])) {
                return $normalized[$norm];
            }
        }
        return '';
    }

    private static function tcFilterExistingFields(string $table, array $fields): array
    {
        $columns = self::tcGetTableColumns($table);
        if (!$columns) {
            return ['id'];
        }
        foreach ($fields as $field) {
            if ($field === '*') {
                return array_values(array_keys($columns));
            }
        }
        $out = ['id'];
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '' || $field === '*') {
                continue;
            }
            if (isset($columns[$field])) {
                $out[] = $field;
            }
        }
        return array_values(array_unique($out));
    }

    private static function idMap(string $table, array $ids, array $fields = ['id']): array
    {
        $cleanIds = array_values(array_filter(array_map(static function ($id) {
            $v = trim((string)$id);
            return $v === '' ? null : $v;
        }, $ids)));
        if (!$cleanIds) {
            return [];
        }
        $safeFields = self::tcFilterExistingFields($table, $fields);
        try {
            $rows = self::crud($table)->selectByIds($cleanIds, $safeFields);
        } catch (\Throwable $e) {
            try {
                $rows = self::crud($table)->selectByIds($cleanIds, ['id']);
            } catch (\Throwable $e2) {
                $rows = [];
            }
        }
        $map = [];
        foreach ($rows as $row) {
            $key = (string)($row['id'] ?? '');
            if ($key === '') {
                continue;
            }
            $map[$key] = $row;
        }
        return $map;
    }

    private static function tcBuildNameMapByAnyKey(string $table, array $keys, array $keyCandidates, array $nameCandidates): array
    {
        $cleanKeys = array_values(array_unique(array_filter(array_map(static function ($v) {
            $s = trim((string)$v);
            return $s === '' ? null : $s;
        }, $keys))));
        if (!$cleanKeys) {
            return [];
        }

        $cols = self::tcGetTableColumns($table);
        if (!$cols) {
            return [];
        }

        $nameField = self::tcPickColumn($cols, $nameCandidates);
        if ($nameField === '') {
            return [];
        }

        $keyFields = [];
        foreach ($keyCandidates as $candidate) {
            $field = self::tcPickColumn($cols, [$candidate]);
            if ($field !== '' && !in_array($field, $keyFields, true)) {
                $keyFields[] = $field;
            }
        }
        if (!$keyFields) {
            return [];
        }

        $idField = self::tcPickColumn($cols, ['id']);
        $statusField = self::tcPickColumn($cols, ['status']);
        $selectFields = self::tcFilterExistingFields($table, array_merge($keyFields, [$nameField, $statusField]));

        $map = [];
        $register = static function (array $row) use (&$map, $keyFields, $nameField): void {
            $name = trim((string)($row[$nameField] ?? ''));
            if ($name === '') {
                return;
            }
            foreach ($keyFields as $keyField) {
                $key = trim((string)($row[$keyField] ?? ''));
                if ($key !== '' && !isset($map[$key])) {
                    $map[$key] = $name;
                }
            }
        };

        if ($idField !== '') {
            try {
                $rows = self::crud($table)->selectByIds($cleanKeys, $selectFields);
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $register($row);
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        $missing = array_values(array_filter($cleanKeys, static function ($k) use (&$map) {
            return !isset($map[$k]);
        }));
        if (!$missing) {
            return $map;
        }

        foreach ($keyFields as $idx => $keyField) {
            if ($idField !== '' && $keyField === $idField) {
                continue;
            }
            if (!$missing) {
                break;
            }
            $params = [];
            $inCond = self::routeBuildInCondition($keyField, $missing, 'kb_name_' . $idx . '_', $params);
            if ($inCond === null) {
                continue;
            }
            $where = $inCond;
            if ($statusField !== '') {
                $where = '(' . $statusField . ' <> -1 OR ' . $statusField . ' IS NULL) AND ' . $where;
            }
            try {
                $rows = self::crud($table)->selectByRawCondition($where, '', $params);
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $register($row);
                    }
                }
            } catch (\Throwable $e) {
            }
            $missing = array_values(array_filter($missing, static function ($k) use (&$map) {
                return !isset($map[$k]);
            }));
        }

        return $map;
    }

    private static function serverMapByAnyKeys(array $keys, array $fields = ['id', 'ServerCode', 'ServerName']): array
    {
        $clean = array_values(array_unique(array_filter(array_map(static function ($v) {
            $s = trim((string)$v);
            return $s === '' ? null : $s;
        }, $keys))));
        if (!$clean) {
            return [];
        }

        $safeFields = self::tcFilterExistingFields('dcim-server', $fields);
        $map = [];
        try {
            $rows = self::crud('dcim-server')->selectByIds($clean, $safeFields);
            foreach ($rows as $row) {
                $idKey = trim((string)($row['id'] ?? ''));
                $codeKey = trim((string)($row['ServerCode'] ?? ''));
                if ($idKey !== '') {
                    $map[$idKey] = $row;
                }
                if ($codeKey !== '') {
                    $map[$codeKey] = $row;
                }
            }
        } catch (\Throwable $e) {
        }

        $missing = [];
        foreach ($clean as $k) {
            if (!isset($map[$k])) {
                $missing[] = $k;
            }
        }
        if ($missing) {
            $params = [];
            $phs = [];
            foreach ($missing as $idx => $k) {
                $ph = ':srvk_' . $idx;
                $phs[] = $ph;
                $params[$ph] = $k;
            }
            if ($phs) {
                try {
                    try {
                        $rows = self::crud('dcim-server')->selectByRawCondition(
                            '(id IN (' . implode(',', $phs) . ') OR ServerCode IN (' . implode(',', $phs) . ')) AND status <> -1',
                            '',
                            $params
                        );
                    } catch (\Throwable $e) {
                        $rows = self::crud('dcim-server')->selectByRawCondition(
                            '(id IN (' . implode(',', $phs) . ') OR ServerCode IN (' . implode(',', $phs) . '))',
                            '',
                            $params
                        );
                    }
                    foreach ($rows as $row) {
                        $idKey = trim((string)($row['id'] ?? ''));
                        $codeKey = trim((string)($row['ServerCode'] ?? ''));
                        if ($idKey !== '') {
                            $map[$idKey] = $row;
                        }
                        if ($codeKey !== '') {
                            $map[$codeKey] = $row;
                        }
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        return $map;
    }

    private static function buildAreaNames(array $areaMap, $areaIdRaw): string
    {
        $parts = [];
        foreach (explode(',', (string)$areaIdRaw) as $id) {
            $id = trim($id);
            if ($id === '') {
                continue;
            }
            $name = $areaMap[$id]['AreaName'] ?? '';
            if ($name !== '') {
                $parts[] = $name;
            }
        }
        return implode(',', $parts);
    }

    private static function orgList(string $table, array $searchFields): void
    {
        $data = Flight::request_data();
        $result = self::crud($table)->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => $searchFields,
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, false);
    }

    private static function orgDetail(string $table): void
    {
        $data = Flight::request_data();
        $info = self::crud($table)->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: []);
    }

    private static function orgUpdate(string $table): void
    {
        $data = Flight::request_data();
        $res = self::crud($table)->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res);
    }

    private static function orgDel(string $table): void
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
        O_E(true);
    }

    public static function createDept(): void
    {
        self::orgCreate('dcim-department');
    }

    public static function getDeptList(): void
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-department')->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => ['DeptName'],
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $serverIds = [];
        $areaIds = [];
        foreach ($rows as $row) {
            if (isset($row['ServerCode']) && $row['ServerCode'] !== '' && $row['ServerCode'] !== null) {
                $serverIds[] = $row['ServerCode'];
            }
            if (isset($row['AreaId']) && $row['AreaId'] !== '' && $row['AreaId'] !== null) {
                foreach (explode(',', (string)$row['AreaId']) as $aid) {
                    $aid = trim($aid);
                    if ($aid !== '') {
                        $areaIds[] = $aid;
                    }
                }
            }
        }
        $serverMap = self::serverMapByAnyKeys($serverIds, ['id', 'ServerCode', 'ServerName']);
        $areaMap = self::idMap('dcim-area', $areaIds, ['id', 'AreaName']);
        foreach ($rows as &$row) {
            $row['ServerName'] = $serverMap[(string)($row['ServerCode'] ?? '')]['ServerName'] ?? '';
            $row['AreaName'] = self::buildAreaNames($areaMap, $row['AreaId'] ?? '');
        }
        unset($row);
        $result['info'] = $rows;
        O_E($result, tp_msg_success(), 100, false);
    }

    public static function getDeptDetail(): void
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-department')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if ($info) {
            $serverMap = self::serverMapByAnyKeys([$info['ServerCode'] ?? null], ['id', 'ServerCode', 'ServerName']);
            $areaIds = [];
            foreach (explode(',', (string)($info['AreaId'] ?? '')) as $aid) {
                $aid = trim($aid);
                if ($aid !== '') {
                    $areaIds[] = $aid;
                }
            }
            $areaMap = self::idMap('dcim-area', $areaIds, ['id', 'AreaName']);
            $info['ServerName'] = $serverMap[(string)($info['ServerCode'] ?? '')]['ServerName'] ?? '';
            $info['AreaName'] = self::buildAreaNames($areaMap, $info['AreaId'] ?? '');
        }
        O_E($info ?: []);
    }

    public static function changeDept(): void
    {
        self::orgUpdate('dcim-department');
    }

    public static function delDept(): void
    {
        self::orgDel('dcim-department');
    }

    public static function createStoreLocation(): void
    {
        self::orgCreate('dcim-store');
    }

    public static function getStoreLocationList(): void
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-store')->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => ['StoreLocationName'],
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $serverIds = [];
        $areaIds = [];
        foreach ($rows as $row) {
            if (isset($row['ServerCode']) && $row['ServerCode'] !== '' && $row['ServerCode'] !== null) {
                $serverIds[] = $row['ServerCode'];
            }
            if (isset($row['AreaId']) && $row['AreaId'] !== '' && $row['AreaId'] !== null) {
                $areaIds[] = $row['AreaId'];
            }
        }
        $serverMap = self::serverMapByAnyKeys($serverIds, ['id', 'ServerCode', 'ServerName']);
        $areaMap = self::idMap('dcim-area', $areaIds, ['id', 'AreaName']);
        foreach ($rows as &$row) {
            $row['ServerName'] = $serverMap[(string)($row['ServerCode'] ?? '')]['ServerName'] ?? '';
            $row['AreaName'] = $areaMap[(string)($row['AreaId'] ?? '')]['AreaName'] ?? '';
        }
        unset($row);
        $result['info'] = $rows;
        O_E($result, tp_msg_success(), 100, false);
    }

    public static function getStoreLocationDetail(): void
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-store')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if ($info) {
            $serverMap = self::serverMapByAnyKeys([$info['ServerCode'] ?? null], ['id', 'ServerCode', 'ServerName']);
            $areaMap = self::idMap('dcim-area', [$info['AreaId'] ?? null], ['id', 'AreaName']);
            $info['ServerName'] = $serverMap[(string)($info['ServerCode'] ?? '')]['ServerName'] ?? '';
            $info['AreaName'] = $areaMap[(string)($info['AreaId'] ?? '')]['AreaName'] ?? '';
        }
        O_E($info ?: []);
    }

    public static function changeStoreLocation(): void
    {
        self::orgUpdate('dcim-store');
    }

    public static function delStoreLocation(): void
    {
        self::orgDel('dcim-store');
    }

    public static function createSupplier(): void
    {
        self::orgCreate('dcim-supplier');
    }

    public static function getSupplierList(): void
    {
        self::orgList('dcim-supplier', ['SupplierName']);
    }

    public static function getSupplierDetail(): void
    {
        self::orgDetail('dcim-supplier');
    }

    public static function changeSupplier(): void
    {
        self::orgUpdate('dcim-supplier');
    }

    public static function delSupplier(): void
    {
        self::orgDel('dcim-supplier');
    }

    public static function createArea(): void
    {
        $data = Flight::request_data();
        $data = self::orgNormalizeAreaNumericFields(is_array($data) ? $data : []);
        $id = self::crud('dcim-area')->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id]);
    }

    public static function getAreaList(): void
    {
        $data = Flight::request_data();
        $page = isset($data['pageNo']) ? (int)$data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int)$data['pageSize'] : 15;
        $conds = ['status = 1'];
        $params = [];
        if (!empty($data['search'])) {
            $conds[] = 'AreaName LIKE :kw';
            $params[':kw'] = '%' . $data['search'] . '%';
        }
        if (isset($data['ServerCode']) && $data['ServerCode'] !== '' && $data['ServerCode'] !== null) {
            $serverCodeVal = (string)$data['ServerCode'];
            $serverIds = [$serverCodeVal];
            $serverRows = self::crud('dcim-server')->selectByRawCondition(
                'status = 1 AND ServerCode = :sc',
                '',
                [':sc' => $serverCodeVal]
            );
            foreach ($serverRows as $row) {
                if (!empty($row['id'])) {
                    $serverIds[] = (string)$row['id'];
                }
            }
            $serverIds = array_values(array_unique(array_filter($serverIds, static function ($v) {
                return $v !== '';
            })));
            if ($serverIds) {
                $phs = [];
                foreach ($serverIds as $idx => $sid) {
                    $ph = ':sc_' . $idx;
                    $phs[] = $ph;
                    $params[$ph] = $sid;
                }
                $conds[] = 'ServerCode IN (' . implode(',', $phs) . ')';
            }
        }
        $where = implode(' AND ', $conds);
        $result = self::crud('dcim-area')->selectWithPagination($where, $params, 'ORDER BY id DESC', $page, $pageSize);
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $serverIds = [];
        $personIds = [];
        foreach ($rows as $row) {
            if (isset($row['ServerCode']) && $row['ServerCode'] !== '' && $row['ServerCode'] !== null) {
                $serverIds[] = $row['ServerCode'];
            }
            if (isset($row['EmpId']) && $row['EmpId'] !== '' && $row['EmpId'] !== null) {
                $personIds[] = $row['EmpId'];
            }
        }
        $serverMap = self::serverMapByAnyKeys($serverIds, ['id', 'ServerCode', 'ServerName']);
        $personMap = self::idMap('dcim-person', $personIds, ['id', 'PersonName']);
        foreach ($rows as &$row) {
            $row['ServerName'] = $serverMap[(string)($row['ServerCode'] ?? '')]['ServerName'] ?? '';
            $row['PersonName'] = $personMap[(string)($row['EmpId'] ?? '')]['PersonName'] ?? '';
        }
        unset($row);
        $result['info'] = $rows;
        O_E($result, tp_msg_success(), 100, false);
    }

    public static function getAreaDetail(): void
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-area')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if ($info) {
            $serverMap = self::serverMapByAnyKeys([$info['ServerCode'] ?? null], ['id', 'ServerCode', 'ServerName']);
            $personMap = self::idMap('dcim-person', [$info['EmpId'] ?? null], ['id', 'PersonName']);
            $info['ServerName'] = $serverMap[(string)($info['ServerCode'] ?? '')]['ServerName'] ?? '';
            $info['PersonName'] = $personMap[(string)($info['EmpId'] ?? '')]['PersonName'] ?? '';
        }
        O_E($info ?: []);
    }

    public static function changeArea(): void
    {
        $data = Flight::request_data();
        $data = self::orgNormalizeAreaNumericFields(is_array($data) ? $data : []);
        $res = self::crud('dcim-area')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res);
    }

    public static function delArea(): void
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-area')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E(true);
    }

    public static function createServer(): void
    {
        self::orgCreate('dcim-server');
    }

    public static function getServerList(): void
    {
        $data = Flight::request_data();
        if (!is_array($data)) {
            $data = [];
        }
        $data['pageSize'] = 15;

        $result = self::crud('dcim-server')->legacyList($data, [
            'skip_auth' => true,
            'base_where' => ['status <> -1'],
            'search_fields' => ['ServerName'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }

        $fields = ['id', 'ServerCode', 'ServerName', 'ServerIP', 'ServerAddress'];
        $result['info'] = array_map(function ($row) use ($fields) {
            $item = [];
            foreach ($fields as $field) {
                if (array_key_exists($field, $row)) {
                    $item[$field] = $row[$field];
                }
            }
            return $item;
        }, $result['info'] ?? []);

        O_E($result);
    }

    public static function getServerDetail(): void
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-server')->legacyInfo($data, [
            'extra_conditions' => [],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: []);
    }

    public static function changeServer(): void
    {
        self::orgUpdate('dcim-server');
    }

    public static function delServer(): void
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-server')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }

        $id = $data['id'];
        self::crud('dcim-area')->legacyUpdateWhere([['ServerCode', '=', $id], ['status', '=', 1]], ['status' => -1]);
            addLog(dcim_msg('log.server_delete_sync_area_status'));
        O_E(true);
    }

    public static function createCompany(): void
    {
        $data = Flight::request_data();
        $id = self::crud('dcim-company')->legacyCreate($data, [
            'required_fields' => [
                'CompanyName' => dcim_msg('error.company_name_required'),
            ],
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function getCompanyList(): void
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-company')->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => ['CompanyName'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }

        $page = (int)($result['page']['p'] ?? ($data['pageNo'] ?? 1));
        $rows = [];
        foreach (($result['info'] ?? []) as $row) {
            $rows[] = [
                'id' => $row['id'] ?? null,
                'CompanyName' => $row['CompanyName'] ?? '',
            ];
        }
        $total = (int)($result['page']['total'] ?? 0);
        O_E([
            'info' => $rows,
            'page' => [
                'total' => $total,
                'p_n' => $rows ? $page : 0,
                'p' => (string)$page,
            ],
        ], tp_msg_success(), 100, 0);
    }

    public static function getCompanyDetail(): void
    {
        $data = Flight::request_data();
        $row = self::crud('dcim-company')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($row === null) {
            return;
        }

        $info = [];
        if (!empty($row)) {
            $info = [
                'id' => $row['id'] ?? null,
                'CompanyName' => $row['CompanyName'] ?? '',
            ];
        }
        result_json(100, tp_msg_success(), $info, 0);
    }

    public static function changeCompany(): void
    {
        $data = Flight::request_data();
        if (empty($data['CompanyName'])) {
            P_E(dcim_msg('error.company_name_required'));
        }

        $res = self::crud('dcim-company')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, false);
    }

    public static function delCompany(): void
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-company');
        if (!$crud->legacyEnsureAuth($data)) {
            return;
        }
        if (empty($data['id'])) {
            result_json(100, tp_msg_success(), 0, false);
        }

        $id = $data['id'];
        $res = $crud->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        result_json(100, tp_msg_success(), 0, false);
    }

    public static function faultSubTypeInfoAdd()
    {
        $data = Flight::request_data();
        $id = self::crud('dcim-faultsubtype')->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function faultSubTypeGetList()
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-faultsubtype')->legacyList($data, [
            'base_where' => ['status = 1'],
            'exact_filters' => ['FaultTypeId' => 'FaultTypeId'],
            'search_fields' => ['FaultSubTypeName'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $result['info'] = self::faultSubTypeEnrichRows($rows);
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function faultSubTypeGetInfo()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-faultsubtype')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if ($info) {
            $rows = self::faultSubTypeEnrichRows([$info]);
            $info = $rows ? $rows[0] : $info;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    private static function faultSubTypeEnrichRows(array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $faultTypeIds = [];
        $personIds = [];
        foreach ($rows as $row) {
            if (!empty($row['FaultTypeId'])) {
                $faultTypeIds[] = $row['FaultTypeId'];
            }
            foreach (['ExecutorEmpId', 'ExamineEmpId', 'OtherExecutorEmpId'] as $k) {
                if (!empty($row[$k])) {
                    $personIds[] = $row[$k];
                }
            }
        }
        $faultTypeMap = [];
        foreach (self::crud('dcim-faulttype')->selectByIds($faultTypeIds, ['id', 'FaultTypeName']) as $item) {
            $key = (string)($item['id'] ?? '');
            if ($key !== '') {
                $faultTypeMap[$key] = $item['FaultTypeName'] ?? '';
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
            $row['FaultTypeName'] = $faultTypeMap[(string)($row['FaultTypeId'] ?? '')] ?? ($row['FaultTypeName'] ?? '');
            $row['ExecutorEmpName'] = $personMap[(string)($row['ExecutorEmpId'] ?? '')] ?? ($row['ExecutorEmpName'] ?? '');
            $row['ExamineEmpName'] = $personMap[(string)($row['ExamineEmpId'] ?? '')] ?? ($row['ExamineEmpName'] ?? '');
            $row['OtherExecutorEmpName'] = $personMap[(string)($row['OtherExecutorEmpId'] ?? '')] ?? ($row['OtherExecutorEmpName'] ?? '');
        }
        unset($row);
        return $rows;
    }

    public static function faultSubTypeInfoUpdate()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-faultsubtype')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function faultSubTypeInfoDel()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-faultsubtype')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function faultSubTypeCoverImport()
    {
        self::faultSubTypeImport(Flight::request_data(), true);
    }

    public static function faultSubTypeNewImport()
    {
        self::faultSubTypeImport(Flight::request_data(), false);
    }

    public static function faultSubTypeCreateKnowledgeBase()
    {
        $data = Flight::request_data();
        $id = self::crud('dcim-knowledge')->legacyCreate($data, [
            'defaults' => ['status' => 1],
            'drop_fields' => ['FaultTypeId'],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function faultSubTypeGetKnowledgeBaseList()
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-knowledge')->legacyList($data, [
            'base_where' => ['(status <> -1 OR status IS NULL)'],
            'exact_filters' => [
                'EventName' => 'EventName',
                'FaultTypeLsh' => 'FaultTypeLsh',
                'FaultSubTypeLsh' => 'FaultSubTypeLsh',
                'Flag' => 'Flag',
            ],
            'search_fields' => ['KeyWord', 'EventName'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        if ($rows) {
            $faultTypeIds = [];
            $faultSubTypeIds = [];
            $personIds = [];
            foreach ($rows as $row) {
                $ft = trim((string)(self::pickFirstField((array)$row, ['FaultTypeLsh', 'FaultTypeId', 'FaultTypeID', 'FaultType', 'TypeId']) ?? ''));
                $fst = trim((string)(self::pickFirstField((array)$row, ['FaultSubTypeLsh', 'FaultSubTypeId', 'FaultSubTypeID', 'FaultSubType', 'SubTypeId']) ?? ''));
                $ue = trim((string)(self::pickFirstField((array)$row, ['UpdateEmpId', 'UpdateUserId', 'UpdateEmpLsh', 'UpdateUserLsh', 'UpdateById', 'UpdatePersonId', 'EmpId']) ?? ''));
                if ($ft !== '') {
                    $faultTypeIds[] = $ft;
                }
                if ($fst !== '') {
                    $faultSubTypeIds[] = $fst;
                }
                if ($ue !== '') {
                    $personIds[] = $ue;
                }
            }
            $faultTypeMap = self::tcBuildNameMapByAnyKey(
                'dcim-faulttype',
                $faultTypeIds,
                ['id', 'Lsh', 'FaultTypeLsh', 'TypeId'],
                ['FaultTypeName', 'TypeName', 'AlarmName']
            );
            $faultSubTypeMap = self::tcBuildNameMapByAnyKey(
                'dcim-faultsubtype',
                $faultSubTypeIds,
                ['id', 'Lsh', 'FaultSubTypeLsh', 'SubTypeId'],
                ['FaultSubTypeName', 'SubTypeName']
            );
            $personMap = self::tcBuildNameMapByAnyKey(
                'dcim-person',
                $personIds,
                ['id', 'Lsh', 'EmpLsh', 'UserId'],
                ['PersonName', 'UserName', 'EmpName', 'Name']
            );
            foreach ($rows as &$row) {
                $ft = trim((string)(self::pickFirstField((array)$row, ['FaultTypeLsh', 'FaultTypeId', 'FaultTypeID', 'FaultType', 'TypeId']) ?? ''));
                $fst = trim((string)(self::pickFirstField((array)$row, ['FaultSubTypeLsh', 'FaultSubTypeId', 'FaultSubTypeID', 'FaultSubType', 'SubTypeId']) ?? ''));
                $ue = trim((string)(self::pickFirstField((array)$row, ['UpdateEmpId', 'UpdateUserId', 'UpdateEmpLsh', 'UpdateUserLsh', 'UpdateById', 'UpdatePersonId', 'EmpId']) ?? ''));
                $row['FaultTypeName'] = $faultTypeMap[$ft] ?? (self::pickFirstField((array)$row, ['FaultTypeName', 'FaultType', 'TypeName']) ?? '');
                $row['FaultSubTypeName'] = $faultSubTypeMap[$fst] ?? ($row['FaultSubTypeName'] ?? '');
                $row['UpdateEmpName'] = $personMap[$ue] ?? (self::pickFirstField((array)$row, ['UpdateEmpName', 'UpdateUserName', 'UpdateByName', 'EmpName']) ?? '');
                if (!isset($row['FaultTypeName'])) {
                    $row['FaultTypeName'] = '';
                }
                if (!isset($row['UpdateEmpName'])) {
                    $row['UpdateEmpName'] = '';
                }
            }
            unset($row);
            $result['info'] = $rows;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function faultSubTypeGetKnowledgeBaseDetail()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-knowledge')->legacyInfo($data, [
            'extra_conditions' => [['status', '<>', -1]],
        ]);
        if (!$info && !empty($data['id'])) {
            $rows = self::crud('dcim-knowledge')->selectByRawCondition(
                'id = :id AND (status <> -1 OR status IS NULL)',
                'LIMIT 1',
                [':id' => $data['id']]
            );
            $info = $rows ? ($rows[0] ?? null) : null;
        }
        if ($info === null) {
            return;
        }
        if ($info) {
            $faultTypeId = trim((string)(self::pickFirstField((array)$info, ['FaultTypeLsh', 'FaultTypeId', 'FaultTypeID', 'FaultType', 'TypeId']) ?? ''));
            $faultSubTypeId = trim((string)(self::pickFirstField((array)$info, ['FaultSubTypeLsh', 'FaultSubTypeId', 'FaultSubTypeID', 'FaultSubType', 'SubTypeId']) ?? ''));
            $updateEmpId = trim((string)(self::pickFirstField((array)$info, ['UpdateEmpId', 'UpdateUserId', 'UpdateEmpLsh', 'UpdateUserLsh', 'UpdateById', 'UpdatePersonId', 'EmpId']) ?? ''));
            if ($faultTypeId !== '') {
                $faultTypeMap = self::tcBuildNameMapByAnyKey(
                    'dcim-faulttype',
                    [$faultTypeId],
                    ['id', 'Lsh', 'FaultTypeLsh', 'TypeId'],
                    ['FaultTypeName', 'TypeName', 'AlarmName']
                );
                $info['FaultTypeName'] = $faultTypeMap[$faultTypeId] ?? (self::pickFirstField((array)$info, ['FaultTypeName', 'FaultType', 'TypeName']) ?? '');
            }
            if ($faultSubTypeId !== '') {
                $faultSubTypeMap = self::tcBuildNameMapByAnyKey(
                    'dcim-faultsubtype',
                    [$faultSubTypeId],
                    ['id', 'Lsh', 'FaultSubTypeLsh', 'SubTypeId'],
                    ['FaultSubTypeName', 'SubTypeName']
                );
                $info['FaultSubTypeName'] = $faultSubTypeMap[$faultSubTypeId] ?? ($info['FaultSubTypeName'] ?? '');
            }
            if ($updateEmpId !== '') {
                $personMap = self::tcBuildNameMapByAnyKey(
                    'dcim-person',
                    [$updateEmpId],
                    ['id', 'Lsh', 'EmpLsh', 'UserId'],
                    ['PersonName', 'UserName', 'EmpName', 'Name']
                );
                $info['UpdateEmpName'] = (string)($personMap[$updateEmpId] ?? (self::pickFirstField((array)$info, ['UpdateEmpName', 'UpdateUserName', 'UpdateByName', 'EmpName']) ?? ''));
            }
            if (!isset($info['FaultTypeName'])) {
                $info['FaultTypeName'] = '';
            }
            if (!isset($info['UpdateEmpName'])) {
                $info['UpdateEmpName'] = '';
            }
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function faultSubTypeChangeKnowledgeBase()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-knowledge')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'drop_fields' => ['FaultTypeId'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function faultSubTypeDelKnowledgeBase()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-knowledge')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function faultSubTypeUpdateKnowledgeReadCount()
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-knowledge');
        if (!$crud->legacyEnsureAuth($data)) {
            return;
        }
        $id = $data['id'] ?? ($data['Lsh'] ?? null);
        if (empty($id)) {
            P_E(dcim_msg('common.id_required'));
        }
        $row = $crud->findOne([['id', '=', $id], ['status', '=', 1]]);
        if (!$row) {
            P_E(dcim_msg('error.record_not_found'));
        }
        $count = (int) ($row['KnowledgeRead'] ?? 0) + 1;
        $crud->legacyUpdate([
            'id' => $row['id'],
            'KnowledgeRead' => $count,
            'ReadLastTime' => date('Y-m-d H:i:s'),
        ], [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['KnowledgeRead', 'ReadLastTime'],
        ]);
        O_E(true, tp_msg_success(), 100, 1);
    }

private static function assetDictAuthCrud(): CrudController
    {
        return new CrudController('dcim-person');
    }

    private static function assetDictRequireAuth(array $data = [])
    {
        $user = self::assetDictAuthCrud()->legacyEnsureAuth($data);
        if (!$user) {
            L_E(tp_msg_login());
        }
        return $user;
    }

    private static function assetDictCreate(string $table): void
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

    private static function assetDictList(string $table, array $options): void
    {
        $data = Flight::request_data();
        $result = self::crud($table)->legacyList($data, $options + [
            'base_where' => ['status = 1'],
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, false);
    }

    private static function assetDictInfo(string $table): void
    {
        $data = Flight::request_data();
        $info = self::crud($table)->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, false);
    }

    private static function assetDictUpdate(string $table): void
    {
        $data = Flight::request_data();
        $res = self::crud($table)->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, false);
    }

    private static function assetDictDel(string $table): void
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
        O_E(true, tp_msg_success(), 100, false);
    }

    public static function createClass(): void
    {
        self::assetDictCreate('dcim-deviceclass');
    }

    public static function getClassList(): void
    {
        self::assetDictList('dcim-deviceclass', [
            'search_fields' => ['ClassName'],
        ]);
    }

    public static function getClassDetail(): void
    {
        self::assetDictInfo('dcim-deviceclass');
    }

    public static function changeClass(): void
    {
        self::assetDictUpdate('dcim-deviceclass');
    }

    public static function delClass(): void
    {
        self::assetDictDel('dcim-deviceclass');
    }

    public static function createAssetsAttr(): void
    {
        self::assetDictCreate('dcim-assetattr');
    }

    public static function getAssetsAttrList(): void
    {
        self::assetDictList('dcim-assetattr', [
            'search_fields' => ['AttrName'],
            'exact_filters' => ['AttrClass' => 'AttrClass'],
        ]);
    }

    public static function getAssetsAttrDetail(): void
    {
        self::assetDictInfo('dcim-assetattr');
    }

    public static function changeAssetsAttr(): void
    {
        self::assetDictUpdate('dcim-assetattr');
    }

    public static function delAssetsAttr(): void
    {
        self::assetDictDel('dcim-assetattr');
    }

    public static function createAssetsType(): void
    {
        self::assetDictCreate('dcim-assettype');
    }

    public static function getAssetsTypeList(): void
    {
        self::assetDictList('dcim-assettype', [
            'search_fields' => ['TypeName'],
        ]);
    }

    public static function getAssetsTypeDetail(): void
    {
        self::assetDictInfo('dcim-assettype');
    }

    public static function changeAssetsType(): void
    {
        self::assetDictUpdate('dcim-assettype');
    }

    public static function delAssetsType(): void
    {
        self::assetDictDel('dcim-assettype');
    }

    public static function createBrand(): void
    {
        self::assetDictCreate('dcim-brand');
    }

    public static function getBrandList(): void
    {
        self::assetDictList('dcim-brand', [
            'search_fields' => ['BrandName'],
            'exact_filters' => ['CompanyId' => 'CompanyId'],
        ]);
    }

    public static function getBrandDetail(): void
    {
        self::assetDictInfo('dcim-brand');
    }

    public static function changeBrand(): void
    {
        self::assetDictUpdate('dcim-brand');
    }

    public static function delBrand(): void
    {
        self::assetDictDel('dcim-brand');
    }

    public static function createBrandModel(): void
    {
        $data = Flight::request_data();
        if (array_key_exists('ThrDModelId', $data) && ($data['ThrDModelId'] === '' || $data['ThrDModelId'] === null)) {
            unset($data['ThrDModelId']);
        }
        $id = self::crud('dcim-brandmodel')->legacyCreate($data, [
            'defaults' => ['status' => 1],
            'drop_fields' => ['CompanyId', 'CompanyName'],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function getBrandModelList(): void
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-brandmodel');
        if (!$crud->legacyEnsureAuth($data)) {
            return;
        }

        try {
            $tableColumns = self::tcGetTableColumns('dcim-brandmodel');
            $brandTableColumns = self::tcGetTableColumns('dcim-brand');
            $comboAll = strtolower(trim((string)($data['ComboBox'] ?? ''))) === 'all';

            $conditions = [];
            $params = [];
            $statusField = self::tcPickColumn($tableColumns, ['status']);
            if ($statusField !== '') {
                $conditions[] = $statusField . ' = 1';
            } else {
                $conditions[] = '1=1';
            }
            if (!empty($data['BrandId'])) {
                $conditions[] = 'BrandId = :brandId';
                $params[':brandId'] = $data['BrandId'];
            }
            if (!empty($data['AssetsTypeId'])) {
                $conditions[] = 'AssetsTypeId = :assetsTypeId';
                $params[':assetsTypeId'] = $data['AssetsTypeId'];
            }
            if (!empty($data['CompanyId'])) {
                $brandWhere = ['CompanyId = :cid'];
                $brandParams = [':cid' => $data['CompanyId']];
                $brandStatusField = self::tcPickColumn($brandTableColumns, ['status']);
                if ($brandStatusField !== '') {
                    $brandWhere[] = $brandStatusField . ' = 1';
                }
                $brandRows = self::crud('dcim-brand')->selectByRawCondition(
                    implode(' AND ', $brandWhere),
                    '',
                    $brandParams
                );
                $brandIds = array_values(array_filter(array_map(static function ($row) {
                    return isset($row['id']) ? (string)$row['id'] : '';
                }, $brandRows), static function ($v) {
                    return $v !== '';
                }));
                if ($brandIds) {
                    $placeholders = [];
                    foreach ($brandIds as $idx => $bid) {
                        $ph = ':bid' . $idx;
                        $placeholders[] = $ph;
                        $params[$ph] = $bid;
                    }
                    $conditions[] = 'BrandId IN (' . implode(', ', $placeholders) . ')';
                } else {
                    $conditions[] = '1 = 0';
                }
            }
            if (!empty($data['search'])) {
                $conditions[] = 'BrandModel LIKE :search';
                $params[':search'] = '%' . $data['search'] . '%';
            }

            $where = implode(' AND ', $conditions);
            $page = isset($data['pageNo']) ? (int)$data['pageNo'] : 1;
            $pageSize = isset($data['pageSize']) ? (int)$data['pageSize'] : 15;
            if ($comboAll) {
                $allRows = $crud->selectByRawCondition($where, 'ORDER BY id DESC', $params);
                $result = [
                    'info' => is_array($allRows) ? $allRows : [],
                    'page' => [
                        'total' => is_array($allRows) ? count($allRows) : 0,
                        'p_n' => 1,
                        'p' => 1,
                    ],
                ];
            } else {
                $result = $crud->selectWithPagination($where, $params, 'ORDER BY id DESC', $page, $pageSize);
            }
            $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        } catch (\Throwable $e) {
            O_E(['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => 1]], tp_msg_success(), 100, 0);
            return;
        }

        $brandIds = [];
        $typeIds = [];
        foreach ($rows as $row) {
            if (isset($row['BrandId']) && $row['BrandId'] !== '' && $row['BrandId'] !== null) {
                $brandIds[] = $row['BrandId'];
            }
            if (isset($row['AssetsTypeId']) && $row['AssetsTypeId'] !== '' && $row['AssetsTypeId'] !== null) {
                $typeIds[] = $row['AssetsTypeId'];
            }
        }
        $brandMap = self::idMap('dcim-brand', $brandIds, ['id', 'BrandName', 'CompanyId']);
        $typeMap = self::idMap('dcim-assettype', $typeIds, ['id', 'AssetsTypeName']);
        $companyIds = [];
        foreach ($brandMap as $brand) {
            if (!empty($brand['CompanyId'])) {
                $companyIds[] = $brand['CompanyId'];
            }
        }
        $companyMap = self::idMap('dcim-company', $companyIds, ['id', 'CompanyName']);

        foreach ($rows as &$row) {
            $brand = $brandMap[(string)($row['BrandId'] ?? '')] ?? [];
            $row['BrandName'] = $brand['BrandName'] ?? '';
            $row['CompanyName'] = $companyMap[(string)($brand['CompanyId'] ?? '')]['CompanyName'] ?? '';
            $row['AssetsTypeName'] = $typeMap[(string)($row['AssetsTypeId'] ?? '')]['AssetsTypeName'] ?? '';
        }
        unset($row);
        $result['info'] = $rows;
        O_E($result, tp_msg_success(), 100, false);
    }

    public static function getBrandModelDetail(): void
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-brandmodel')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if ($info) {
            $brandMap = self::idMap('dcim-brand', [$info['BrandId'] ?? null], ['id', 'BrandName', 'CompanyId']);
            $typeMap = self::idMap('dcim-assettype', [$info['AssetsTypeId'] ?? null], ['id', 'AssetsTypeName']);
            $brand = $brandMap[(string)($info['BrandId'] ?? '')] ?? [];
            $companyMap = self::idMap('dcim-company', [$brand['CompanyId'] ?? null], ['id', 'CompanyName']);
            $info['BrandName'] = $brand['BrandName'] ?? '';
            $info['CompanyName'] = $companyMap[(string)($brand['CompanyId'] ?? '')]['CompanyName'] ?? '';
            $info['AssetsTypeName'] = $typeMap[(string)($info['AssetsTypeId'] ?? '')]['AssetsTypeName'] ?? '';
        }
        O_E($info ?: [], tp_msg_success(), 100, false);
    }

    public static function changeBrandModel(): void
    {
        $data = Flight::request_data();
        if (array_key_exists('ThrDModelId', $data) && ($data['ThrDModelId'] === '' || $data['ThrDModelId'] === null)) {
            unset($data['ThrDModelId']);
        }
        $res = self::crud('dcim-brandmodel')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'drop_fields' => ['CompanyId', 'CompanyName'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, false);
    }

    public static function delBrandModel(): void
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-brandmodel')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E(true, tp_msg_success(), 100, false);
    }
    public static function createAssetsTypeAttr(): void
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-assettypeattr');
        $result = $crud->legacyReplaceAttrMappings($data, [
            'owner_field' => 'AssetsTypeId',
            'owner_param' => 'AssetsTypeId',
            'attr_key' => 'Attr',
            'owner_required_message' => dcim_msg('error.assets_type_id_required'),
            'attr_required_message' => dcim_msg('error.attr_required'),
            'valid_attr_required_message' => dcim_msg('error.valid_attr_required'),
        ]);
        if ($result === null) {
            return;
        }
        $inserted = (int) ($result['inserted'] ?? 0);
        O_E(true, tp_msg_success(), 100, $inserted);
    }

    public static function getAssetsTypeAttr(): void
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-assettypeattr');
        $result = $crud->legacyList($data, [
            'base_where' => ['status = 1'],
            'exact_filters' => ['AssetsTypeId' => 'AssetsTypeId'],
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $attrIds = [];
        foreach ($rows as $row) {
            if (isset($row['AttributeId']) && $row['AttributeId'] !== '' && $row['AttributeId'] !== null) {
                $attrIds[] = $row['AttributeId'];
            }
        }
        $attrMap = self::idMap('dcim-assetattr', $attrIds, ['id', 'AttrNumber', 'AttrName', 'DataType', 'AttrUnit']);
        foreach ($rows as &$row) {
            $attr = $attrMap[(string)($row['AttributeId'] ?? '')] ?? [];
            $row['AttrNumber'] = $attr['AttrNumber'] ?? '';
            $row['AttrName'] = $attr['AttrName'] ?? '';
            $row['DataType'] = $attr['DataType'] ?? '';
            $row['AttrUnit'] = $attr['AttrUnit'] ?? '';
        }
        unset($row);
        $result['info'] = $rows;
        O_E($result, tp_msg_success(), 100, false);
    }

    public static function createBrandModelAttr(): void
    {
        $data = Flight::request_data();
        $crud = self::crud('dcim-brandmodelattr');
        $result = $crud->legacyReplaceAttrMappings($data, [
            'owner_field' => 'ModelId',
            'owner_param' => 'ModelId',
            'attr_key' => 'Attr',
            'extra_field_map' => [
                'ModelAttrDisable' => 'ModelAttrDisable',
            ],
            'default_fields' => [
                'ModelAttrDisable' => 1,
            ],
            'owner_required_message' => dcim_msg('common.param_missing'),
            'valid_attr_required_message' => dcim_msg('common.param_missing'),
            'attr_required_message' => dcim_msg('common.param_missing'),
        ]);
        if ($result === null) {
            return;
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function getBrandModelAttr(): void
    {
        $data = Flight::request_data();
        self::assetDictRequireAuth($data);
        $modelId = $data['ModelId'] ?? ($data['BrandModelId'] ?? null);

        $viewWhereParts = ['status = 1'];
        $params = [];
        if (!empty($modelId)) {
            $viewWhereParts[] = 'ModelId = :modelId';
            $params[':modelId'] = $modelId;
        }
        $viewWhereSql = implode(' AND ', $viewWhereParts);

        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;
        try {
            $result = self::crud('vw_brand_model_attr')->selectWithPagination(
                $viewWhereSql,
                $params,
                '',
                $page,
                $pageSize
            );
            O_E($result, tp_msg_success(), 100, false);
            return;
        } catch (Throwable $e) {
            error_log('[GetBrandModelAttrKey] view query failed, fallback to legacy SQL: ' . $e->getMessage());
        }

        $baseWhereSql = implode(' AND ', $viewWhereParts);
        $baseResult = self::crud('dcim-brandmodelattr')->selectByRawConditionWithPagination(
            $baseWhereSql,
            $params,
            '',
            $page,
            $pageSize
        );
        $total = (int) ($baseResult['page']['total'] ?? 0);
        $baseRows = is_array($baseResult['info'] ?? null) ? $baseResult['info'] : [];

        $modelIds = [];
        $attrIds = [];
        foreach ($baseRows as $row) {
            if (isset($row['ModelId'])) {
                $modelIds[(string) $row['ModelId']] = true;
            }
            if (isset($row['AttributeId'])) {
                $attrIds[(string) $row['AttributeId']] = true;
            }
        }

        $brandMap = [];
        $brandRows = self::crud('dcim-brandmodel')->selectByIds(
            array_keys($modelIds),
            ['id', 'BrandModel']
        );
        foreach ($brandRows as $item) {
            $idKey = (string) ($item['id'] ?? '');
            if ($idKey !== '') {
                $brandMap[$idKey] = $item;
            }
        }

        $attrMap = [];
        $attrRows = self::crud('dcim-assetattr')->selectByIds(
            array_keys($attrIds),
            ['id', 'AttrName', 'AttrUnit', 'AttrNumber', 'DataType']
        );
        foreach ($attrRows as $item) {
            $idKey = (string) ($item['id'] ?? '');
            if ($idKey !== '') {
                $attrMap[$idKey] = $item;
            }
        }

        $rows = [];
        foreach ($baseRows as $row) {
            $m = $brandMap[(string) ($row['ModelId'] ?? '')] ?? [];
            $a = $attrMap[(string) ($row['AttributeId'] ?? '')] ?? [];
            $rows[] = [
                'id' => $row['id'] ?? null,
                'BrandModel' => $m['BrandModel'] ?? '',
                'AttrName' => $a['AttrName'] ?? '',
                'AttrUnit' => $a['AttrUnit'] ?? '',
                'AttrNumber' => $a['AttrNumber'] ?? '',
                'DataType' => $a['DataType'] ?? '',
                'ModelId' => $row['ModelId'] ?? null,
                'AttributeId' => $row['AttributeId'] ?? null,
                'AttributeVal' => $row['AttributeVal'] ?? '',
            ];
        }

        $result = [
            'info' => $rows,
            'page' => [
                'total' => $total,
                'p_n' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
                'p' => $page,
            ],
        ];
        O_E($result, tp_msg_success(), 100, false);
    }


    public static function getAlarmParamInfo()
    {
        $data = Flight::request_data();
        if (empty($data['id'])) {
            $data['id'] = 1;
        }
        $info = self::crud('dcim-alarmparam')->legacyInfo($data, [
            'extra_conditions' => [],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, false);
    }

    public static function changeAlarmParam()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-alarmparam')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, false);
    }

    public static function getAlarmTypeList()
    {
        $data = Flight::request_data();
        $rows = [];
        foreach (['dcim-alarmnotifymode', 'dcim-alarmtype'] as $tableName) {
            $cols = self::tcGetTableColumns($tableName);
            if (!$cols) {
                continue;
            }
            $where = isset($cols['status']) ? '(status <> -1 OR status IS NULL)' : '1=1';
            try {
                $rows = self::crud($tableName)->selectByRawCondition($where, 'ORDER BY id DESC', []);
            } catch (\Throwable $e) {
                $rows = [];
            }
            if ($rows) {
                break;
            }
        }
        $enriched = self::alarmTypeEnrichRows($rows, $data);

        if (isset($data['ComboBox']) && $data['ComboBox'] !== '') {
            $hasPageArgs = isset($data['pageNo']) || isset($data['pageSize']);
            if ($hasPageArgs) {
                $page = isset($data['pageNo']) ? max((int)$data['pageNo'], 1) : 1;
                $pageSize = isset($data['pageSize']) ? max((int)$data['pageSize'], 1) : 15;
                $offset = ($page - 1) * $pageSize;
                $enriched = array_values(array_slice($enriched, $offset, $pageSize));
            }
            O_E($enriched, tp_msg_success(), 100, $enriched ? count($enriched) : false);
            return;
        }

        $page = isset($data['pageNo']) ? max((int)$data['pageNo'], 1) : 1;
        $pageSize = isset($data['pageSize']) ? max((int)$data['pageSize'], 1) : 15;
        $total = count($enriched);
        $offset = ($page - 1) * $pageSize;
        $slice = array_slice($enriched, $offset, $pageSize);
        $result = [
            'info' => array_values($slice),
            'page' => [
                'total' => $total,
                'p_n' => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0,
                'p' => $page,
            ],
        ];
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function getAlarmTypeDetail()
    {
        $data = Flight::request_data();
        if (empty($data['id']) && !empty($data['Lsh'])) {
            $data['id'] = $data['Lsh'];
        }
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }
        $info = null;
        foreach (['dcim-alarmnotifymode', 'dcim-alarmtype'] as $tableName) {
            $cols = self::tcGetTableColumns($tableName);
            if (!$cols) {
                continue;
            }
            if (isset($cols['status'])) {
                $info = self::crud($tableName)->findOne([['id', '=', $data['id']], ['status', '<>', -1]]);
                if (!$info) {
                    try {
                        $rows = self::crud($tableName)->selectByRawCondition('id = :id', '', [':id' => $data['id']]);
                        foreach ($rows as $r) {
                            $st = (string)($r['status'] ?? '');
                            if ($st === '' || $st !== '-1') {
                                $info = $r;
                                break;
                            }
                        }
                    } catch (\Throwable $ignore) {
                    }
                }
            } else {
                $info = self::crud($tableName)->findOne([['id', '=', $data['id']]]);
            }
            if ($info) {
                break;
            }
        }
        if (!$info) {
            O_E([], tp_msg_success(), 100, false);
            return;
        }
        $rows = self::alarmTypeEnrichRows([$info], $data);
        $row = $rows ? $rows[0] : [];
        if (is_array($row) && !array_key_exists('NotifyWindowID', $row)) {
            $row['NotifyWindowID'] = (string)(
                $info['NotifyWindowID']
                ?? $info['NotifyWindowId']
                ?? $info['NotifyWindow']
                ?? $info['WindowID']
                ?? $info['WindowId']
                ?? ''
            );
        }
        O_E($row, tp_msg_success(), 100, $row ? 1 : false);
    }

    private static function alarmTypeGroupNames($groupIds): string
    {
        $ids = [];
        foreach (explode(',', (string)$groupIds) as $id) {
            $id = trim($id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        if (!$ids) {
            return '';
        }
        $map = self::idMap('dcim-persongroup', $ids, ['id', 'GroupName']);
        $names = [];
        foreach ($ids as $id) {
            $name = $map[(string)$id]['GroupName'] ?? '';
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return implode(',', $names);
    }

    private static function alarmTypeEnrichRows(array $rows, array $filters): array
    {
        if (!$rows) {
            return [];
        }

        $search = trim((string)($filters['search'] ?? ''));
        $devIds = [];
        foreach ($rows as $row) {
            $devId = isset($row['DevId']) ? $row['DevId'] : ($row['DevID'] ?? '');
            if ($devId !== null && $devId !== '') {
                $devIds[] = $devId;
            }
        }
        $deviceMap = self::idMap('dcim-device', $devIds, ['id', 'status', 'DeviceStatus', 'DeviceName', 'ServerCode', 'AreaId', 'DeviceClass']);

        $serverIds = [];
        $areaIds = [];
        $classIds = [];
        $levelIds = [];
        foreach ($deviceMap as $dev) {
            if (!empty($dev['ServerCode'])) {
                $serverIds[] = $dev['ServerCode'];
            }
            if (!empty($dev['AreaId'])) {
                $areaIds[] = $dev['AreaId'];
            }
            if (!empty($dev['DeviceClass'])) {
                $classIds[] = $dev['DeviceClass'];
            }
        }
        foreach ($rows as $row) {
            if (!empty($row['AlarmLevel'])) {
                $levelIds[] = $row['AlarmLevel'];
            }
        }
        $serverMap = self::serverMapByAnyKeys($serverIds, ['id', 'ServerCode', 'ServerName']);
        $areaMap = self::idMap('dcim-area', $areaIds, ['id', 'AreaName']);
        $classMap = self::idMap('dcim-deviceclass', $classIds, ['id', 'ClassName']);
        $levelMap = self::idMap('dcim-alarmlevellist', $levelIds, ['id', 'LevelName']);

        $serverCodeFilter = trim((string)($filters['ServerCode'] ?? ''));
        $deviceStatusFilter = (string)($filters['DeviceStatus'] ?? '');
        $devIdFilter = (string)($filters['DevId'] ?? '');
        $alarmLevelFilter = (string)($filters['AlarmLevel'] ?? '');
        $alarmTypeFilter = (string)($filters['AlarmType'] ?? '');
        $classNameFilter = trim((string)($filters['ClassName'] ?? ''));
        $areaIdFilter = (string)($filters['AreaId'] ?? '');
        $combo = isset($filters['ComboBox']) && $filters['ComboBox'] !== '';

        $result = [];
        foreach ($rows as $row) {
            $rowDevId = (string)($row['DevId'] ?? ($row['DevID'] ?? ''));
            if ($devIdFilter !== '' && $rowDevId !== $devIdFilter) {
                continue;
            }
            if ($alarmLevelFilter !== '' && (string)($row['AlarmLevel'] ?? '') !== $alarmLevelFilter) {
                continue;
            }
            if ($alarmTypeFilter !== '' && (string)($row['AlarmType'] ?? '') !== $alarmTypeFilter) {
                continue;
            }
            if ($search !== '') {
                $alarmName = (string)($row['AlarmName'] ?? '');
                $alarmKey = (string)($row['AlarmKey'] ?? '');
                if (stripos($alarmName, $search) === false && stripos($alarmKey, $search) === false) {
                    continue;
                }
            }

            $dev = $deviceMap[$rowDevId] ?? [];
            $devStatusRaw = trim((string)($dev['status'] ?? ''));
            $devBizStatusRaw = trim((string)($dev['DeviceStatus'] ?? ''));
            if (
                !$dev ||
                $devStatusRaw === '-1' ||
                $devBizStatusRaw === '-1' ||
                $devBizStatusRaw === 'deleted'
            ) {
                continue;
            }
            if ($deviceStatusFilter !== '' && (string)($dev['DeviceStatus'] ?? '') !== $deviceStatusFilter) {
                continue;
            }
            if ($areaIdFilter !== '' && (string)($dev['AreaId'] ?? '') !== $areaIdFilter) {
                continue;
            }
            $className = $classMap[(string)($dev['DeviceClass'] ?? '')]['ClassName'] ?? '';
            if ($classNameFilter !== '' && $className !== $classNameFilter) {
                continue;
            }

            $server = $serverMap[(string)($dev['ServerCode'] ?? '')] ?? [];
            if ($serverCodeFilter !== '') {
                $devServerCode = (string)($dev['ServerCode'] ?? '');
                $serverId = (string)($server['id'] ?? '');
                $serverCode = (string)($server['ServerCode'] ?? '');
                if ($devServerCode !== $serverCodeFilter && $serverCode !== $serverCodeFilter && $serverId !== $serverCodeFilter) {
                    continue;
                }
            }

            $item = $row;
            $item['DeviceStatus'] = $dev['DeviceStatus'] ?? '';
            $item['DeviceName'] = $dev['DeviceName'] ?? '';
            $item['ServerName'] = $server['ServerName'] ?? '';
            $item['AreaName'] = $areaMap[(string)($dev['AreaId'] ?? '')]['AreaName'] ?? '';
            $item['ClassName'] = $className;
            $item['PersonName'] = self::alarmTypeGroupNames($row['UserID'] ?? '');
            $item['UpgradeUserName'] = self::alarmTypeGroupNames($row['UpgradeUser'] ?? '');
            $item['LevelName'] = $levelMap[(string)($row['AlarmLevel'] ?? '')]['LevelName'] ?? '';
            $item['UpgradeReason'] = '';
            $item['NotifyWindowID'] = (string)(
                $row['NotifyWindowID']
                ?? $row['NotifyWindowId']
                ?? $row['NotifyWindow']
                ?? $row['WindowID']
                ?? $row['WindowId']
                ?? ''
            );
            $result[] = $item;
        }

        if (!$combo) {
            return $result;
        }

        $drop = [];
        foreach ($result as $row) {
            $masterId = (string)($row['MasterID'] ?? '');
            if ($masterId !== '' && $masterId !== '0') {
                $drop[(string)($row['id'] ?? '')] = true;
                $drop[$masterId] = true;
            }
        }
        if (!$drop) {
            return array_values($result);
        }
        $filtered = [];
        foreach ($result as $row) {
            $id = (string)($row['id'] ?? '');
            if ($id !== '' && isset($drop[$id])) {
                continue;
            }
            $filtered[] = $row;
        }
        return array_values($filtered);
    }

    public static function changeAlarmType()
    {
        try {
            error_log('[ChangeAlarmTypeKey][step0] enter');
            $db = null;
            $driver = '';
            $isDmLockTimeout = static function (\Throwable $ex): bool {
                $msg = strtolower((string)$ex->getMessage());
                if ($msg === '') {
                    return false;
                }
                return (strpos($msg, 'lock timeout') !== false) || (strpos($msg, '-6407') !== false);
            };
            $loadDmLockContext = static function (\PDO $pdo): array {
                $ctx = [
                    'trxwait' => [],
                    'sessions' => [],
                    'suspect_blocker_sess_ids' => [],
                    'high_risk_sess_ids' => [],
                    'review_sess_ids' => [],
                    'ignore_sess_ids' => [],
                    'action_sql' => [],
                    'generated_at' => date('Y-m-d H:i:s'),
                ];
                $toTs = static function ($raw): int {
                    $txt = trim((string)$raw);
                    if ($txt === '') {
                        return 0;
                    }
                    $ts = strtotime($txt);
                    return $ts === false ? 0 : (int)$ts;
                };
                try {
                    $stmt = $pdo->query('SELECT ID, WAIT_FOR_ID, WAIT_TIME FROM V$TRXWAIT');
                    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                    if (is_array($rows)) {
                        $ctx['trxwait'] = array_slice($rows, 0, 20);
                    }
                } catch (\Throwable $ignore) {
                    $ctx['trxwait'] = [];
                }
                try {
                    $stmt = $pdo->query('SELECT SESS_ID, TRX_ID, STATE, CLNT_IP, CREATE_TIME, LAST_RECV_TIME, SQL_TEXT FROM V$SESSIONS WHERE TRX_ID > 0 ORDER BY LAST_RECV_TIME ASC');
                    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                    if (is_array($rows)) {
                        $nowTs = time();
                        $normalized = [];
                        foreach (array_slice($rows, 0, 50) as $r) {
                            $sid = isset($r['SESS_ID']) ? trim((string)$r['SESS_ID']) : '';
                            $trxId = isset($r['TRX_ID']) ? trim((string)$r['TRX_ID']) : '';
                            $state = strtoupper(trim((string)($r['STATE'] ?? '')));
                            $sqlText = trim((string)($r['SQL_TEXT'] ?? ''));
                            $lastRecv = trim((string)($r['LAST_RECV_TIME'] ?? ''));
                            $lastRecvTs = $toTs($lastRecv);
                            $idleSeconds = $lastRecvTs > 0 ? max(0, $nowTs - $lastRecvTs) : -1;
                            $isObserver = stripos($sqlText, 'FROM V$SESSIONS WHERE TRX_ID > 0') !== false
                                || stripos($sqlText, 'FROM V$TRXWAIT') !== false;
                            $isManagerMeta = stripos($sqlText, 'SELECT NAME FROM SYSOBJECTS') !== false
                                && stripos($sqlText, "TYPE$='SCH'") !== false;
                            $riskLevel = 'medium';
                            $riskReason = 'open_transaction_session';
                            $recommendedAction = 'review_then_close_if_needed';
                            if ($isObserver) {
                                $riskLevel = 'low';
                                $riskReason = 'diagnostic_observer_session';
                                $recommendedAction = 'ignore';
                            } elseif ($state !== 'ACTIVE' && $idleSeconds >= 60) {
                                $riskLevel = 'high';
                                $riskReason = 'idle_transaction_possible_blocker';
                                $recommendedAction = 'close_session_first';
                            } elseif ($isManagerMeta && $state !== 'ACTIVE') {
                                $riskLevel = 'high';
                                $riskReason = 'manager_metadata_session_with_open_trx';
                                $recommendedAction = 'close_session_first';
                            } elseif ($state === 'ACTIVE') {
                                $riskLevel = 'medium';
                                $riskReason = 'active_transaction_session';
                                $recommendedAction = 'wait_or_review';
                            }
                            if ($sid !== '') {
                                if ($riskLevel === 'high') {
                                    $ctx['high_risk_sess_ids'][] = $sid;
                                } elseif ($riskLevel === 'low') {
                                    $ctx['ignore_sess_ids'][] = $sid;
                                } else {
                                    $ctx['review_sess_ids'][] = $sid;
                                }
                            }
                            $normalized[] = [
                                'SESS_ID' => $sid,
                                'TRX_ID' => $trxId,
                                'STATE' => $state,
                                'CLNT_IP' => (string)($r['CLNT_IP'] ?? ''),
                                'CREATE_TIME' => (string)($r['CREATE_TIME'] ?? ''),
                                'LAST_RECV_TIME' => $lastRecv,
                                'IDLE_SECONDS' => $idleSeconds >= 0 ? $idleSeconds : null,
                                'SQL_TEXT' => $sqlText,
                                'RISK_LEVEL' => $riskLevel,
                                'RISK_REASON' => $riskReason,
                                'RECOMMENDED_ACTION' => $recommendedAction,
                            ];
                        }
                        $riskOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
                        usort($normalized, static function (array $a, array $b) use ($riskOrder): int {
                            $ra = $riskOrder[strtolower((string)($a['RISK_LEVEL'] ?? 'low'))] ?? 0;
                            $rb = $riskOrder[strtolower((string)($b['RISK_LEVEL'] ?? 'low'))] ?? 0;
                            if ($ra !== $rb) {
                                return $rb <=> $ra;
                            }
                            $ia = isset($a['IDLE_SECONDS']) && is_numeric($a['IDLE_SECONDS']) ? (int)$a['IDLE_SECONDS'] : -1;
                            $ib = isset($b['IDLE_SECONDS']) && is_numeric($b['IDLE_SECONDS']) ? (int)$b['IDLE_SECONDS'] : -1;
                            return $ib <=> $ia;
                        });
                        $ctx['sessions'] = $normalized;
                        $ctx['high_risk_sess_ids'] = array_values(array_unique($ctx['high_risk_sess_ids']));
                        $ctx['review_sess_ids'] = array_values(array_unique($ctx['review_sess_ids']));
                        $ctx['ignore_sess_ids'] = array_values(array_unique($ctx['ignore_sess_ids']));
                        $ctx['suspect_blocker_sess_ids'] = array_values(array_unique(array_merge($ctx['high_risk_sess_ids'], $ctx['review_sess_ids'])));
                        foreach ($ctx['high_risk_sess_ids'] as $sid) {
                            $ctx['action_sql'][] = 'SP_CLOSE_SESSION(' . $sid . ');';
                        }
                    }
                } catch (\Throwable $ignore) {
                    $ctx['sessions'] = [];
                    $ctx['suspect_blocker_sess_ids'] = [];
                    $ctx['high_risk_sess_ids'] = [];
                    $ctx['review_sess_ids'] = [];
                    $ctx['ignore_sess_ids'] = [];
                    $ctx['action_sql'] = [];
                }
                return $ctx;
            };
            $closeDmSessions = static function (\PDO $pdo, array $sessionIds, int $maxCount = 5): array {
                $report = [
                    'attempted' => [],
                    'closed' => [],
                    'failed' => [],
                    'skipped' => [],
                ];
                if ($maxCount <= 0) {
                    $maxCount = 5;
                }
                $ids = [];
                foreach ($sessionIds as $sidRaw) {
                    $sid = trim((string)$sidRaw);
                    if ($sid === '') {
                        continue;
                    }
                    if (!preg_match('/^\d+$/', $sid)) {
                        $report['skipped'][] = ['sess_id' => $sid, 'reason' => 'not_numeric'];
                        continue;
                    }
                    $ids[] = $sid;
                }
                $ids = array_values(array_unique($ids));
                if (!$ids) {
                    return $report;
                }
                if (count($ids) > $maxCount) {
                    $ids = array_slice($ids, 0, $maxCount);
                }
                foreach ($ids as $sid) {
                    $report['attempted'][] = $sid;
                    $sql = 'SP_CLOSE_SESSION(' . $sid . ')';
                    try {
                        $pdo->query($sql);
                        $report['closed'][] = $sid;
                    } catch (\Throwable $closeEx) {
                        $report['failed'][] = [
                            'sess_id' => $sid,
                            'error' => (string)$closeEx->getMessage(),
                        ];
                    }
                }
                return $report;
            };
            $data = Flight::request_data();
            if (!is_array($data)) {
                $data = [];
            }
            if (is_array($_REQUEST) && $_REQUEST) {
                foreach ($_REQUEST as $k => $v) {
                    if (!is_string($k) || array_key_exists($k, $data)) {
                        continue;
                    }
                    $data[$k] = $v;
                }
            }
            $autoUnlockRaw = strtolower(trim((string)($data['auto_unlock'] ?? ($data['AutoUnlock'] ?? ''))));
            $autoUnlock = in_array($autoUnlockRaw, ['1', 'true', 'yes', 'on'], true);
            self::ensureAuth($data);
            error_log('[ChangeAlarmTypeKey][step1] auth ok');

            if (!isset($data['id']) && isset($data['Lsh'])) {
                $data['id'] = $data['Lsh'];
            }
            $idRaw = trim((string)($data['id'] ?? ''));
            if ($idRaw === '') {
                result_json(400, dcim_msg('common.id_required'), false, false);
            }
            $idParts = preg_split('/[,\x{FF0C}\s]+/u', $idRaw);
            if (!is_array($idParts)) {
                $idParts = [];
            }
            $idList = [];
            foreach ($idParts as $oneIdRaw) {
                $oneId = trim((string)$oneIdRaw);
                if ($oneId === '' || !ctype_digit($oneId)) {
                    continue;
                }
                $oneIdInt = (int)$oneId;
                if ($oneIdInt > 0) {
                    $idList[] = $oneIdInt;
                }
            }
            $idList = array_values(array_unique($idList));
            if (!$idList) {
                result_json(400, dcim_msg('common.id_required'), false, false);
            }

            $fieldMap = [
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
                'UpgradeUser' => 'UpgradeUser',
                'AlarmUpLimit' => 'AlarmUpLimit',
                'AlarmDownLimit' => 'AlarmDownLimit',
                'AlarmValue' => 'AlarmValue',
                'LinkVideoChannel' => 'LinkVideoChannel',
                'Linkage' => 'Linkage',
                'AlarmLinkage' => 'Linkage',
                'CancelLinkage' => 'CancelLinkage',
            ];
            $intFields = array_fill_keys([
                'PhoneNotify',
                'SMSNotify',
                'EmailNotify',
                'NoiseNotify',
                'WeixinNotify',
                'WeComNotify',
                'DingdingNotify',
                'ConfirmNum',
                'NotifyNum',
                'IntervalTime',
                'AlarmLevel',
                'NotifyWindowID',
                'UpgradeTime',
            ], true);
            $allowEmptyStringFields = array_fill_keys([
                'UserID',
                'UpgradeUser',
                'AlarmValue',
                'LinkVideoChannel',
                'Linkage',
                'AlarmLinkage',
                'CancelLinkage',
            ], true);

            $updateData = [];
            foreach ($fieldMap as $reqField => $dbField) {
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
                if ($txt === '' && !isset($allowEmptyStringFields[$reqField])) {
                    continue;
                }
                $updateData[$dbField] = $txt;
            }
            if (!$updateData) {
                result_json(400, dcim_msg('common.no_update_data'), false, false);
            }
            $updateData['update_time'] = date('Y-m-d H:i:s');

            $db = Flight::db();
            try {
                $driver = strtolower((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME));
            } catch (\Throwable $ignore) {
                $driver = '';
            }
            $q = ($driver === 'dm') ? '"' : '`';
            $quoteIdent = static function (string $name) use ($q): string {
                $clean = str_replace(['`', '"'], '', $name);
                if ($q === '"') {
                    return '"' . str_replace('"', '""', $clean) . '"';
                }
                return '`' . $clean . '`';
            };
            $setParts = [];
            $binds = [];
            $bindTypes = [];
            $idPlaceholders = [];
            foreach ($idList as $idIdx => $idOne) {
                $idPh = ':id_' . $idIdx;
                $idPlaceholders[] = $idPh;
                $binds[$idPh] = (int)$idOne;
                $bindTypes[$idPh] = PDO::PARAM_INT;
            }
            if (!$idPlaceholders) {
                result_json(400, dcim_msg('common.id_required'), false, false);
            }
            $setIdx = 0;
            foreach ($updateData as $field => $val) {
                if (!is_string($field) || $field === '' || !preg_match('/^[A-Za-z0-9_]+$/', $field)) {
                    continue;
                }
                $ph = ':f_' . $setIdx++;
                $setParts[] = $quoteIdent((string)$field) . ' = ' . $ph;
                $binds[$ph] = $val;
                $bindTypes[$ph] = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
            }
            if (!$setParts) {
                result_json(400, dcim_msg('common.no_update_data'), false, false);
            }
            $whereActive = '(' . $quoteIdent('status') . ' <> -1 OR ' . $quoteIdent('status') . ' IS NULL)';
            $whereIds = $quoteIdent('id') . ' IN (' . implode(', ', $idPlaceholders) . ')';
            $dmTxStarted = false;
            if ($driver === 'dm') {
                try {
                    if (!$db->inTransaction()) {
                        $db->beginTransaction();
                        $dmTxStarted = true;
                    }
                    $lockSql = 'SELECT ' . $quoteIdent('id')
                        . ' FROM ' . $quoteIdent('dcim-alarmnotifymode')
                        . ' WHERE ' . $whereIds
                        . ' AND ' . $whereActive
                        . ' FOR UPDATE WAIT 1';
                    error_log('[ChangeAlarmTypeKey][lock1] before lock ids=' . implode(',', $idList));
                    $lockStmt = $db->prepare($lockSql);
                    foreach ($binds as $ph => $val) {
                        if (strpos($ph, ':id_') !== 0) {
                            continue;
                        }
                        $lockStmt->bindValue($ph, $val, PDO::PARAM_INT);
                    }
                    $lockStmt->execute();
                    $lockedRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$lockedRow) {
                        if ($dmTxStarted && $db->inTransaction()) {
                            $db->rollBack();
                        }
                        result_json(400, dcim_msg('error.record_not_found'), false, false);
                    }
                    error_log('[ChangeAlarmTypeKey][lock2] lock acquired ids=' . implode(',', $idList));
                } catch (\Throwable $lockEx) {
                    if ($dmTxStarted && $db->inTransaction()) {
                        $db->rollBack();
                    }
                    error_log('[ChangeAlarmTypeKey][lockE] ' . $lockEx->getMessage());
                    if ($driver === 'dm' && $isDmLockTimeout($lockEx)) {
                        $lockContext = $loadDmLockContext($db);
                        $autoUnlockReport = null;
                        if ($autoUnlock) {
                            $targets = [];
                            if (!empty($lockContext['high_risk_sess_ids']) && is_array($lockContext['high_risk_sess_ids'])) {
                                $targets = $lockContext['high_risk_sess_ids'];
                            } elseif (!empty($lockContext['suspect_blocker_sess_ids']) && is_array($lockContext['suspect_blocker_sess_ids'])) {
                                $targets = $lockContext['suspect_blocker_sess_ids'];
                            }
                            $autoUnlockReport = $closeDmSessions($db, $targets, 5);
                            $lockContext['auto_unlock_executed'] = true;
                        } else {
                            $lockContext['auto_unlock_executed'] = false;
                        }
                        result_json(400, dcim_msg('common.operation_failed'), [
                            'reason' => 'db_lock_timeout',
                            'db_code' => -6407,
                            'detail' => (string)$lockEx->getMessage(),
                            'lock_context' => $lockContext,
                            'auto_unlock_requested' => $autoUnlock,
                            'auto_unlock_report' => $autoUnlockReport,
                        ], false);
                    }
                    result_json(400, dcim_msg('common.operation_failed'), false, false);
                }
            }

            $sql = 'UPDATE ' . $quoteIdent('dcim-alarmnotifymode')
                . ' SET ' . implode(', ', $setParts)
                . ' WHERE ' . $whereIds
                . ' AND ' . $whereActive;
            error_log('[ChangeAlarmTypeKey][step2] before exec ids=' . implode(',', $idList));
            $stmt = $db->prepare($sql);
            foreach ($binds as $ph => $val) {
                $type = $bindTypes[$ph] ?? PDO::PARAM_STR;
                $stmt->bindValue($ph, $val, $type);
            }
            $ok = $stmt->execute();
            $changed = $ok ? $stmt->rowCount() : false;
            error_log('[ChangeAlarmTypeKey][step3] after exec changed=' . (string)$changed);
            if ($ok === false) {
                if ($dmTxStarted && $db->inTransaction()) {
                    $db->rollBack();
                }
                result_json(400, dcim_msg('common.operation_failed'), false, false);
            }
            if ($dmTxStarted && $db->inTransaction()) {
                $db->commit();
            }
            O_E(true, tp_msg_success(), 100, 1);
        } catch (\Throwable $e) {
            try {
                if (isset($dmTxStarted) && $dmTxStarted && isset($db) && $db instanceof PDO && $db->inTransaction()) {
                    $db->rollBack();
                }
            } catch (\Throwable $ignoreRollback) {
            }
            error_log('[ChangeAlarmTypeKey][stepE] ' . $e->getMessage());
            if (isset($driver) && $driver === 'dm' && isset($isDmLockTimeout) && is_callable($isDmLockTimeout) && $isDmLockTimeout($e)) {
                $lockContext = [];
                $autoUnlockReport = null;
                if (isset($db) && $db instanceof PDO && isset($loadDmLockContext) && is_callable($loadDmLockContext)) {
                    $lockContext = $loadDmLockContext($db);
                    if (isset($autoUnlock) && $autoUnlock && isset($closeDmSessions) && is_callable($closeDmSessions)) {
                        $targets = [];
                        if (!empty($lockContext['high_risk_sess_ids']) && is_array($lockContext['high_risk_sess_ids'])) {
                            $targets = $lockContext['high_risk_sess_ids'];
                        } elseif (!empty($lockContext['suspect_blocker_sess_ids']) && is_array($lockContext['suspect_blocker_sess_ids'])) {
                            $targets = $lockContext['suspect_blocker_sess_ids'];
                        }
                        $autoUnlockReport = $closeDmSessions($db, $targets, 5);
                        $lockContext['auto_unlock_executed'] = true;
                    } else {
                        $lockContext['auto_unlock_executed'] = false;
                    }
                }
                result_json(400, dcim_msg('common.operation_failed'), [
                    'reason' => 'db_lock_timeout',
                    'db_code' => -6407,
                    'detail' => (string)$e->getMessage(),
                    'lock_context' => $lockContext,
                    'auto_unlock_requested' => isset($autoUnlock) ? $autoUnlock : false,
                    'auto_unlock_report' => $autoUnlockReport,
                ], false);
            }
            result_json(400, dcim_msg('common.operation_failed'), false, false);
        }
    }

    public static function getAlarmLevelList()
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-alarmlevellist')->legacyList($data, [
            'base_where' => ['1=1'],
            'order_by' => 'ORDER BY id ASC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function getAlarmLevelDetail()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-alarmlevellist')->legacyInfo($data, [
            'extra_conditions' => [],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function changeAlarmLevel()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-alarmlevellist')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function createAlarmNotify()
    {
        $data = Flight::request_data();
        $id = self::crud('dcim-alarmnotifylist')->legacyCreate($data);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function getAlarmNotifyList()
    {
        $data = Flight::request_data();
        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;
        $crud = self::crud('dcim-alarmnotifylist');
        $columns = self::tcGetTableColumns('dcim-alarmnotifylist');
        $pickColumn = static function (array $candidates) use ($columns): string {
            foreach ($candidates as $field) {
                if (isset($columns[$field])) {
                    return $field;
                }
            }
            return '';
        };
        $searchFields = [];
        foreach (['NotifyContent', 'Content', 'AlarmName', 'EventName', 'DeviceName', 'ServerName', 'NotifyUser', 'MsgCon'] as $candidate) {
            $field = $pickColumn([$candidate]);
            if ($field !== '' && !in_array($field, $searchFields, true)) {
                $searchFields[] = $field;
            }
        }
        $exactFilters = [];
        $serverCodeField = $pickColumn(['ServerCode', 'ServerId', 'ServerID']);
        if ($serverCodeField !== '') {
            $exactFilters['ServerCode'] = $serverCodeField;
        }
        $notifyTypeField = $pickColumn(['NotifyType', 'Type', 'AlarmType']);
        if ($notifyTypeField !== '') {
            $exactFilters['NotifyType'] = $notifyTypeField;
        }
        $betweenFilters = [];
        $timeField = $pickColumn(['create_time', 'CreateTime', 'NotifyTime', 'AlarmTime', 'RecordTime', 'update_time']);
        if ($timeField !== '') {
            $betweenFilters[] = ['field' => $timeField, 'start_key' => 'startDateTime', 'end_key' => 'endDateTime'];
        }
        try {
            $result = $crud->legacyList($data, [
                'base_where' => ['1=1'],
                'search_fields' => $searchFields,
                'exact_filters' => $exactFilters,
                'between_filters' => $betweenFilters,
                'order_by' => 'ORDER BY id DESC',
            ]);
            if ($result === null) {
                return;
            }
        } catch (\Throwable $e) {
            $fallbackWhere = ['1=1'];
            $fallbackParams = [];
            if ($serverCodeField !== '' && isset($data['ServerCode']) && trim((string)$data['ServerCode']) !== '') {
                $fallbackWhere[] = $serverCodeField . ' = :fb_server_code';
                $fallbackParams[':fb_server_code'] = trim((string)$data['ServerCode']);
            }
            if ($notifyTypeField !== '' && isset($data['NotifyType']) && trim((string)$data['NotifyType']) !== '') {
                $fallbackWhere[] = $notifyTypeField . ' = :fb_notify_type';
                $fallbackParams[':fb_notify_type'] = trim((string)$data['NotifyType']);
            }
            $search = trim((string)($data['search'] ?? ''));
            if ($search !== '' && $searchFields) {
                $searchConds = [];
                foreach ($searchFields as $idx => $field) {
                    $ph = ':fb_search_' . $idx;
                    $searchConds[] = $field . ' LIKE ' . $ph;
                    $fallbackParams[$ph] = '%' . $search . '%';
                }
                if ($searchConds) {
                    $fallbackWhere[] = '(' . implode(' OR ', $searchConds) . ')';
                }
            }
            try {
                $rows = $crud->selectByRawCondition(implode(' AND ', $fallbackWhere), 'ORDER BY id DESC', $fallbackParams);
            } catch (\Throwable $ignore) {
                $rows = $crud->selectByRawCondition('1=1', 'ORDER BY id DESC');
            }
            $result = [
                'info' => array_slice($rows, ($page - 1) * $pageSize, $pageSize),
                'page' => [
                    'total' => count($rows),
                    'p_n'   => $pageSize > 0 ? (int) ceil(count($rows) / $pageSize) : 0,
                    'p'     => $page,
                ],
            ];
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function createAlarmMasterSlave()
    {
        $data = Flight::request_data();
        $id = self::crud('dcim-alarmmasterslave')->legacyCreate($data, [
            'defaults' => ['status' => 1],
            'required_fields' => ['StrategyName' => dcim_msg('error.strategy_name_required')],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function getAlarmMasterSlaveList()
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-alarmmasterslave')->legacyList($data, [
            'base_where' => ['status = 1'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        if ($rows) {
            $parseModeIds = static function (string $raw): array {
                $raw = trim($raw);
                if ($raw === '') {
                    return [];
                }
                $raw = str_replace(["\r", "\n", '[', ']', '"', "'"], '', $raw);
                $parts = preg_split('/[,\|;锛岋紱\s]+/u', $raw);
                if (!is_array($parts)) {
                    return [];
                }
                $ids = [];
                foreach ($parts as $part) {
                    $part = trim((string)$part);
                    if ($part !== '' && !in_array($part, $ids, true)) {
                        $ids[] = $part;
                    }
                }
                return $ids;
            };
            $modeIds = [];
            foreach ($rows as $row) {
                $master = trim((string)(self::pickFirstField((array)$row, ['MasterNotifyMode', 'MasterAlarmId', 'MasterAlarmID', 'MasterEventId', 'MasterEventID', 'MasterId']) ?? ''));
                foreach ($parseModeIds($master) as $mid) {
                    $modeIds[$mid] = true;
                }
                $slave = trim((string)(self::pickFirstField((array)$row, ['SlaveNotifyMode', 'SlaveAlarmId', 'SlaveAlarmID', 'SlaveEventId', 'SlaveEventID', 'SlaveId']) ?? ''));
                foreach ($parseModeIds($slave) as $sid) {
                    $modeIds[$sid] = true;
                }
            }
            $modeMap = [];
            $alarmTypeMap = [];
            $devIds = [];
            if ($modeIds) {
                foreach (self::crud('dcim-alarmnotifymode')->selectByIds(array_keys($modeIds), self::tcFilterExistingFields('dcim-alarmnotifymode', ['id', 'AlarmName', 'EventName', 'NotifyName', 'DevId', 'DevID', 'DeviceId', 'DeviceID'])) as $mode) {
                    $mid = (string)($mode['id'] ?? '');
                    if ($mid === '') {
                        continue;
                    }
                    $modeMap[$mid] = $mode;
                    $devId = trim((string)(self::pickFirstField((array)$mode, ['DevId', 'DevID', 'DeviceId', 'DeviceID']) ?? ''));
                    if ($devId !== '') {
                        $devIds[$devId] = true;
                    }
                }
                foreach (self::crud('dcim-alarmtype')->selectByIds(array_keys($modeIds), self::tcFilterExistingFields('dcim-alarmtype', ['id', 'TypeName', 'AlarmName', 'EventName', 'NotifyName'])) as $typeRow) {
                    $tid = trim((string)($typeRow['id'] ?? ''));
                    if ($tid === '') {
                        continue;
                    }
                    $alarmTypeMap[$tid] = trim((string)(self::pickFirstField((array)$typeRow, ['TypeName', 'AlarmName', 'EventName', 'NotifyName']) ?? ''));
                }
            }
            $devMap = [];
            if ($devIds) {
                foreach (self::crud('dcim-device')->selectByIds(array_keys($devIds), ['id', 'DeviceName']) as $dev) {
                    $did = (string)($dev['id'] ?? '');
                    if ($did !== '') {
                        $devMap[$did] = (string)($dev['DeviceName'] ?? '');
                    }
                }
            }

            foreach ($rows as &$row) {
                $master = trim((string)(self::pickFirstField((array)$row, ['MasterNotifyMode', 'MasterAlarmId', 'MasterAlarmID', 'MasterEventId', 'MasterEventID', 'MasterId']) ?? ''));
                $masterIds = $parseModeIds($master);
                $slave = trim((string)(self::pickFirstField((array)$row, ['SlaveNotifyMode', 'SlaveAlarmId', 'SlaveAlarmID', 'SlaveEventId', 'SlaveEventID', 'SlaveId']) ?? ''));
                $slaveIds = $parseModeIds($slave);
                $collectAlarmNames = static function (array $ids) use ($modeMap, $alarmTypeMap): array {
                    $names = [];
                    foreach ($ids as $oneId) {
                        $mode = $modeMap[$oneId] ?? [];
                        $name = trim((string)(self::pickFirstField((array)$mode, ['AlarmName', 'EventName', 'NotifyName']) ?? ''));
                        if ($name === '' && isset($alarmTypeMap[$oneId])) {
                            $name = trim((string)$alarmTypeMap[$oneId]);
                        }
                        if ($name !== '' && !in_array($name, $names, true)) {
                            $names[] = $name;
                        }
                    }
                    return $names;
                };
                $masterAlarmNames = $collectAlarmNames($masterIds);
                $slaveAlarmNames = $collectAlarmNames($slaveIds);
                $row['MasterAlarmName'] = implode(',', $masterAlarmNames);
                $row['SlaveAlarmName'] = implode(',', $slaveAlarmNames);
                $allAlarmNames = [];
                foreach (array_merge($masterAlarmNames, $slaveAlarmNames) as $name) {
                    if ($name !== '' && !in_array($name, $allAlarmNames, true)) {
                        $allAlarmNames[] = $name;
                    }
                }
                $row['AlarmName'] = $allAlarmNames ? implode(',', $allAlarmNames) : (string)($row['AlarmName'] ?? '');
                $pickModeId = $masterIds ? $masterIds[0] : ($slaveIds ? $slaveIds[0] : '');
                $mode = $pickModeId !== '' ? ($modeMap[$pickModeId] ?? []) : [];
                $did = trim((string)(self::pickFirstField((array)$mode, ['DevId', 'DevID', 'DeviceId', 'DeviceID']) ?? ''));
                $row['DeviceName'] = $did !== '' ? ($devMap[$did] ?? ($row['DeviceName'] ?? '')) : (string)($row['DeviceName'] ?? '');
            }
            unset($row);
            $result['info'] = $rows;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function getAlarmMasterSlaveDetail()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-alarmmasterslave')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function changeAlarmMasterSlave()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-alarmmasterslave')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function delAlarmMasterSlave()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-alarmmasterslave')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function createAlarmUpgrade()
    {
        $data = Flight::request_data();
        $id = self::crud('dcim-alarmupgrade')->legacyCreate($data, [
            'defaults' => ['status' => 1],
            'required_fields' => ['UpgradeReason' => dcim_msg('error.upgrade_reason_required')],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function getAlarmUpgradeList()
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-alarmupgrade')->legacyList($data, [
            'base_where' => ['status = 1'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function getAlarmUpgradeDetail()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-alarmupgrade')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function changeAlarmUpgrade()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-alarmupgrade')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function delAlarmUpgrade()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-alarmupgrade')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function createAlarmSmsSearch()
    {
        $data = Flight::request_data();
        $id = self::crud('dcim-alarmsmssearch')->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function getAlarmSmsSearchList()
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-alarmsmssearch')->legacyList($data, [
            'base_where' => ['status = 1'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function getAlarmSmsSearchDetail()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-alarmsmssearch')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function changeAlarmSmsSearch()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-alarmsmssearch')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function delAlarmSmsSearch()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-alarmsmssearch')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function createAlarmSmsControl()
    {
        $data = Flight::request_data();
        $id = self::crud('dcim-alarmsmscontrol')->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function getAlarmSmsControlList()
    {
        $data = Flight::request_data();
        $result = self::crud('dcim-alarmsmscontrol')->legacyList($data, [
            'base_where' => ['status = 1'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function getAlarmSmsControlDetail()
    {
        $data = Flight::request_data();
        $info = self::crud('dcim-alarmsmscontrol')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function changeAlarmSmsControl()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-alarmsmscontrol')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function delAlarmSmsControl()
    {
        $data = Flight::request_data();
        $res = self::crud('dcim-alarmsmscontrol')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function getAlarmSmsParamDetail()
    {
        $data = Flight::request_data();
        self::ensureAuth($data);
        O_E([], tp_msg_success(), 100, false);
    }

    public static function changeAlarmSmsParam()
    {
        $data = Flight::request_data();
        self::ensureAuth($data);
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function getList(bool $skipAuth = false)
    {
        $data = Flight::request_data();
        if (!$skipAuth) {
            self::ensureAuth($data);
        }

        $tableColumns = self::tcGetTableColumns('dcim-alarmlist');
        $statusField = self::tcPickColumn($tableColumns, ['status']);
        $conditions = [$statusField !== '' ? ($statusField . ' <> -1') : '1=1'];
        $params = [];
        $parseList = static function ($raw): array {
            $raw = trim((string)$raw);
            if ($raw === '') {
                return [];
            }
            $vals = array_values(array_filter(array_map('trim', explode(',', $raw)), static function ($v) {
                return $v !== '';
            }));
            if (!$vals) {
                $vals = [$raw];
            }
            return array_values(array_unique($vals));
        };
        $normalizeDateTime = static function ($raw, bool $isEnd = false): string {
            $raw = trim((string)$raw);
            if ($raw === '') {
                return '';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
                return $raw . ($isEnd ? ' 23:59:59' : ' 00:00:00');
            }
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/', $raw, $m) === 1) {
                $h = max(0, min(23, (int)$m[2]));
                $i = max(0, min(59, (int)$m[3]));
                $s = isset($m[4]) ? max(0, min(59, (int)$m[4])) : 0;
                if ($isEnd && $h === 0 && $i === 0 && $s === 0) {
                    $h = 23;
                    $i = 59;
                    $s = 59;
                }
                return sprintf('%s %02d:%02d:%02d', $m[1], $h, $i, $s);
            }
            $ts = strtotime($raw);
            return $ts ? date('Y-m-d H:i:s', $ts) : $raw;
        };

        $timeField = self::tcPickColumn($tableColumns, ['create_time', 'CreateTime']);
        $startDateTime = $normalizeDateTime($data['startDateTime'] ?? '', false);
        $endDateTime = $normalizeDateTime($data['endDateTime'] ?? '', true);
        if ($timeField !== '') {
            if ($startDateTime !== '' && $endDateTime !== '') {
                $conditions[] = $timeField . ' BETWEEN :start AND :end';
                $params[':start'] = $startDateTime;
                $params[':end'] = $endDateTime;
            } elseif ($startDateTime !== '') {
                $conditions[] = $timeField . ' >= :start';
                $params[':start'] = $startDateTime;
            } elseif ($endDateTime !== '') {
                $conditions[] = $timeField . ' <= :end';
                $params[':end'] = $endDateTime;
            }
        }

        $rawMsgStatus = trim((string)($data['MsgStatus'] ?? ($data['msgStatus'] ?? '')));
        $resolveAlarmStatusRequest = static function (array $payload, bool $typeProvided, string $typeRaw): ?string {
            if ($typeProvided && $typeRaw !== '') {
                return $typeRaw;
            }
            $msgStatus = trim((string)($payload['MsgStatus'] ?? ($payload['msgStatus'] ?? '')));
            if ($msgStatus === '') {
                return null;
            }
            if (is_numeric($msgStatus)) {
                return (string)$msgStatus;
            }
            $statusMap = [
                'pending' => '1',
                'processing' => '1',
                'active' => '1',
                'done' => '0',
                'resolved' => '0',
                'closed' => '0',
            ];
            return $statusMap[$msgStatus] ?? null;
        };

        $alarmStatusField = self::tcPickColumn($tableColumns, ['AlarmStatus']);
        $typeProvided = array_key_exists('type', $data);
        $typeRaw = $typeProvided ? trim((string)$data['type']) : '';
        $requestedAlarmStatus = $resolveAlarmStatusRequest($data, $typeProvided, $typeRaw);
        $hasExplicitStatusFilter = (($typeProvided && $typeRaw !== '') || $rawMsgStatus !== '');
        if ($alarmStatusField !== '') {
            if ($typeProvided && $typeRaw === '0') {
                $conditions[] = $alarmStatusField . ' = :type_zero';
                $params[':type_zero'] = '0';
            } elseif ($requestedAlarmStatus !== null) {
                $conditions[] = $alarmStatusField . ' = :type';
                $params[':type'] = $requestedAlarmStatus;
            } else {
                // Keep 8080 behavior for real alarms: when type is omitted, default to active alarms.
                $conditions[] = $alarmStatusField . ' <> :type_zero_default';
                $params[':type_zero_default'] = '0';
            }
        }
        $alarmLevelField = self::tcPickColumn($tableColumns, ['AlarmLevel']);
        $requestedAlarmLevelRaw = trim((string)($data['AlarmLevel'] ?? ''));
        if (isset($data['AlarmLevel']) && $data['AlarmLevel'] !== '' && $alarmLevelField !== '') {
            $conditions[] = $alarmLevelField . ' = :alarm_level';
            $params[':alarm_level'] = $data['AlarmLevel'];
        }
        $devClassField = self::tcPickColumn($tableColumns, ['DevClass']);
        $requestedDevClassList = $parseList($data['DevClass'] ?? '');

        $devField = self::tcPickColumn($tableColumns, ['DevId', 'DevID', 'DeviceId', 'DeviceID']);
        if ($devField !== '') {
            $devIds = $parseList($data['DevId'] ?? ($data['DevID'] ?? ''));
            if ($devIds) {
                $devPlaceholders = [];
                foreach ($devIds as $idx => $devId) {
                    $ph = ':dev_id_' . $idx;
                    $devPlaceholders[] = $ph;
                    $params[$ph] = $devId;
                }
                $conditions[] = $devField . ' IN (' . implode(', ', $devPlaceholders) . ')';
            }
        }

        $deviceColumns = self::tcGetTableColumns('dcim-device');
        $deviceIdField = self::tcPickColumn($deviceColumns, ['id']);
        $deviceStatusField = self::tcPickColumn($deviceColumns, ['status']);
        $deviceServerFields = [];
        foreach (['ServerCode', 'ServerID', 'ServerId'] as $serverFieldCandidate) {
            $resolvedServerField = self::tcPickColumn($deviceColumns, [$serverFieldCandidate]);
            if ($resolvedServerField !== '' && !in_array($resolvedServerField, $deviceServerFields, true)) {
                $deviceServerFields[] = $resolvedServerField;
            }
        }
        $deviceAreaField = self::tcPickColumn($deviceColumns, ['AreaId', 'AreaID']);
        $deviceClassField = self::tcPickColumn($deviceColumns, ['DeviceClass', 'DevClass', 'ClassId']);
        $deviceNameField = self::tcPickColumn($deviceColumns, ['DeviceName']);
        $serverCodes = $parseList($data['ServerCode'] ?? '');
        if ($serverCodes) {
            $expandedServerCodes = [];
            foreach ($serverCodes as $sv) {
                $expandedServerCodes[$sv] = true;
            }
            $numericServerIds = [];
            foreach ($serverCodes as $sv) {
                if (ctype_digit((string)$sv)) {
                    $numericServerIds[] = (string)$sv;
                }
            }
            if ($numericServerIds) {
                $serverCols = self::tcGetTableColumns('dcim-server');
                $serverIdField = self::tcPickColumn($serverCols, ['id']);
                $serverCodeField = self::tcPickColumn($serverCols, ['ServerCode']);
                if ($serverIdField !== '' && $serverCodeField !== '') {
                    try {
                        $serverRows = self::crud('dcim-server')->selectByIds($numericServerIds, [$serverIdField, $serverCodeField]);
                        foreach ($serverRows as $svr) {
                            $code = trim((string)self::pickFirstFieldInsensitive($svr, [$serverCodeField], ''));
                            $idv = trim((string)self::pickFirstFieldInsensitive($svr, [$serverIdField], ''));
                            if ($code !== '') {
                                $expandedServerCodes[$code] = true;
                            }
                            if ($idv !== '') {
                                $expandedServerCodes[$idv] = true;
                            }
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
            $serverCodes = array_values(array_keys($expandedServerCodes));
        }
        $areaIds = $parseList($data['AreaId'] ?? '');

        // Apply ServerCode/AreaId filters by resolving device ids first; avoids fragile EXISTS alias fields on DM.
        if ($devField !== '' && ($serverCodes || $areaIds || $requestedDevClassList)) {
            $deviceFilterWhere = [];
            $deviceFilterParams = [];
            if ($deviceStatusField !== '') {
                $deviceFilterWhere[] = '(' . $deviceStatusField . ' <> -1 OR ' . $deviceStatusField . ' IS NULL)';
            }
            if ($serverCodes && $deviceServerFields) {
                $serverFieldConds = [];
                foreach ($deviceServerFields as $fieldIdx => $deviceServerField) {
                    $serverPhs = [];
                    foreach ($serverCodes as $idx => $serverCode) {
                        $ph = ':flt_server_' . $fieldIdx . '_' . $idx;
                        $serverPhs[] = $ph;
                        $deviceFilterParams[$ph] = $serverCode;
                    }
                    if ($serverPhs) {
                        $serverFieldConds[] = $deviceServerField . ' IN (' . implode(', ', $serverPhs) . ')';
                    }
                }
                if ($serverFieldConds) {
                    $deviceFilterWhere[] = '(' . implode(' OR ', $serverFieldConds) . ')';
                }
            }
            if ($areaIds && $deviceAreaField !== '') {
                $areaPhs = [];
                foreach ($areaIds as $idx => $areaId) {
                    $ph = ':flt_area_' . $idx;
                    $areaPhs[] = $ph;
                    $deviceFilterParams[$ph] = $areaId;
                }
                if ($areaPhs) {
                    $deviceFilterWhere[] = $deviceAreaField . ' IN (' . implode(', ', $areaPhs) . ')';
                }
            }
            if ($requestedDevClassList && $deviceClassField !== '') {
                $devClassPhs = [];
                foreach ($requestedDevClassList as $idx => $devClass) {
                    $ph = ':flt_dev_class_' . $idx;
                    $devClassPhs[] = $ph;
                    $deviceFilterParams[$ph] = $devClass;
                }
                if ($devClassPhs) {
                    $deviceFilterWhere[] = $deviceClassField . ' IN (' . implode(', ', $devClassPhs) . ')';
                }
            }
            if (!$deviceFilterWhere) {
                $deviceFilterWhere[] = '1=1';
            }

            $deviceIdList = [];
            $deviceFilterRows = [];
            try {
                $deviceFilterRows = self::crud('dcim-device')->selectByRawCondition(
                    implode(' AND ', $deviceFilterWhere),
                    '',
                    $deviceFilterParams
                );
            } catch (\Throwable $e) {
                $deviceFilterWhereNoStatus = array_values(array_filter($deviceFilterWhere, static function ($cond) use ($deviceStatusField) {
                    return $deviceStatusField === '' || strpos($cond, $deviceStatusField . ' <> -1') === false;
                }));
                if (!$deviceFilterWhereNoStatus) {
                    $deviceFilterWhereNoStatus[] = '1=1';
                }
                $deviceFilterRows = self::crud('dcim-device')->selectByRawCondition(
                    implode(' AND ', $deviceFilterWhereNoStatus),
                    '',
                    $deviceFilterParams
                );
            }
            if (!$deviceFilterRows && $serverCodes && $deviceServerFields) {
                $retryWhere = [];
                foreach ($deviceFilterWhere as $cond) {
                    $hasServerField = false;
                    foreach ($deviceServerFields as $sf) {
                        if (strpos($cond, $sf . ' IN (') !== false) {
                            $hasServerField = true;
                            break;
                        }
                    }
                    if ($hasServerField) {
                        continue;
                    }
                    $retryWhere[] = $cond;
                }
                if (!$retryWhere) {
                    $retryWhere[] = '1=1';
                }
                $retryParams = [];
                foreach ($deviceFilterParams as $k => $v) {
                    if (strpos((string)$k, ':flt_server_') === 0) {
                        continue;
                    }
                    $retryParams[$k] = $v;
                }
                try {
                    $deviceFilterRows = self::crud('dcim-device')->selectByRawCondition(
                        implode(' AND ', $retryWhere),
                        '',
                        $retryParams
                    );
                } catch (\Throwable $e) {
                }
            }
            foreach ($deviceFilterRows as $item) {
                $idVal = trim((string)self::pickFirstFieldInsensitive($item, ['id', 'ID'], ''));
                if ($idVal !== '') {
                    $deviceIdList[$idVal] = true;
                }
            }
            $deviceIdList = array_values(array_keys($deviceIdList));
            if (!$deviceIdList) {
                $conditions[] = '1=0';
            } else {
                $mainDevPhs = [];
                foreach ($deviceIdList as $idx => $idVal) {
                    $ph = ':flt_main_dev_' . $idx;
                    $mainDevPhs[] = $ph;
                    $params[$ph] = $idVal;
                }
                $conditions[] = $devField . ' IN (' . implode(', ', $mainDevPhs) . ')';
            }
        } elseif ($requestedDevClassList && $devClassField !== '') {
            $devClassPhs = [];
            foreach ($requestedDevClassList as $idx => $devClass) {
                $ph = ':dev_class_' . $idx;
                $devClassPhs[] = $ph;
                $params[$ph] = $devClass;
            }
            if ($devClassPhs) {
                $conditions[] = $devClassField . ' IN (' . implode(', ', $devClassPhs) . ')';
            }
        }

        $deviceExistsCondition = '';
        if ($devField !== '' && $deviceIdField !== '') {
            $conditions[] = '(' . $devField . " IS NOT NULL AND " . $devField . " <> '')";
            $deviceExistsConds = ['d.' . $deviceIdField . ' = ' . $devField];
            if ($deviceStatusField !== '') {
                $deviceExistsConds[] = '(d.' . $deviceStatusField . ' <> -1 OR d.' . $deviceStatusField . ' IS NULL)';
            }
            $deviceExistsCondition = 'EXISTS (SELECT 1 FROM `dcim-device` d WHERE ' . implode(' AND ', $deviceExistsConds) . ')';
            $conditions[] = $deviceExistsCondition;
        }

        $search = trim((string)($data['search'] ?? ''));
        $hasStrongFilters = (
            $startDateTime !== ''
            || $endDateTime !== ''
            || !empty($serverCodes)
            || !empty($areaIds)
            || !empty($requestedDevClassList)
            || trim((string)($data['AlarmLevel'] ?? '')) !== ''
            || trim((string)($data['DevId'] ?? ($data['DevID'] ?? ''))) !== ''
            || $search !== ''
        );
        if ($search !== '') {
            $searchConds = [];
            $searchFields = ['TextMessage', 'Solution', 'OrderNumber', 'NotifyMode', 'ParamValue'];
            foreach ($searchFields as $idx => $searchField) {
                $resolvedField = self::tcPickColumn($tableColumns, [$searchField]);
                if ($resolvedField === '') {
                    continue;
                }
                $ph = ':search_alarm_' . $idx;
                $searchConds[] = $resolvedField . ' LIKE ' . $ph;
                $params[$ph] = '%' . $search . '%';
            }
            if ($devField !== '') {
                $searchConds[] = $devField . ' LIKE :search_dev_id_like';
                $params[':search_dev_id_like'] = '%' . $search . '%';
            }
            $alarmTypeField = self::tcPickColumn($tableColumns, ['AlarmType']);
            $alarmTypeCols = self::tcGetTableColumns('dcim-alarmtype');
            $alarmTypeIdField = self::tcPickColumn($alarmTypeCols, ['id']);
            $alarmTypeNameField = self::tcPickColumn($alarmTypeCols, ['TypeName', 'AlarmName', 'Name']);
            if ($alarmTypeField !== '' && $alarmTypeIdField !== '' && $alarmTypeNameField !== '') {
                try {
                    $typeRows = self::crud('dcim-alarmtype')->selectByRawCondition(
                        $alarmTypeNameField . ' LIKE :search_type_name',
                        '',
                        [':search_type_name' => '%' . $search . '%']
                    );
                } catch (\Throwable $e) {
                    $typeRows = [];
                }
                $typeIds = [];
                foreach ($typeRows as $typeRow) {
                    $typeIdVal = trim((string)self::pickFirstFieldInsensitive($typeRow, ['id', 'ID'], ''));
                    if ($typeIdVal !== '') {
                        $typeIds[$typeIdVal] = true;
                    }
                }
                $typeIds = array_values(array_keys($typeIds));
                if ($typeIds) {
                    $phs = [];
                    foreach ($typeIds as $idx => $typeId) {
                        $ph = ':search_type_id_' . $idx;
                        $phs[] = $ph;
                        $params[$ph] = $typeId;
                    }
                    $searchConds[] = $alarmTypeField . ' IN (' . implode(', ', $phs) . ')';
                }
            }
            $searchLower = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
            $disconnectWord = dcim_msg('app.alarm_disconnected');
            $disconnectWordLower = function_exists('mb_strtolower') ? mb_strtolower((string)$disconnectWord, 'UTF-8') : strtolower((string)$disconnectWord);
            if (
                $alarmTypeField !== ''
                && (
                    strpos($searchLower, 'disconnect') !== false
                    || ($disconnectWordLower !== '' && strpos($searchLower, $disconnectWordLower) !== false)
                )
            ) {
                $searchConds[] = $alarmTypeField . ' = :search_disconnect_alarm_type';
                $params[':search_disconnect_alarm_type'] = 5;
            }
            $notifyModeField = self::tcPickColumn($tableColumns, ['NotifyModeID', 'NotifyModeId']);
            $notifyCols = self::tcGetTableColumns('dcim-alarmnotifymode');
            $notifyIdField = self::tcPickColumn($notifyCols, ['id']);
            $notifyNameField = self::tcPickColumn($notifyCols, ['AlarmName']);
            if ($notifyModeField !== '' && $notifyIdField !== '' && $notifyNameField !== '') {
                try {
                    $notifyRows = self::crud('dcim-alarmnotifymode')->selectByRawCondition(
                        $notifyNameField . ' LIKE :search_notify_name',
                        '',
                        [':search_notify_name' => '%' . $search . '%']
                    );
                } catch (\Throwable $e) {
                    $notifyRows = [];
                }
                $notifyIds = [];
                foreach ($notifyRows as $notifyRow) {
                    $notifyIdVal = trim((string)self::pickFirstFieldInsensitive($notifyRow, ['id', 'ID'], ''));
                    if ($notifyIdVal !== '') {
                        $notifyIds[$notifyIdVal] = true;
                    }
                }
                $notifyIds = array_values(array_keys($notifyIds));
                if ($notifyIds) {
                    $phs = [];
                    foreach ($notifyIds as $idx => $notifyId) {
                        $ph = ':search_notify_id_' . $idx;
                        $phs[] = $ph;
                        $params[$ph] = $notifyId;
                    }
                    $searchConds[] = $notifyModeField . ' IN (' . implode(', ', $phs) . ')';
                }
            }
            if ($searchConds) {
                $conditions[] = '(' . implode(' OR ', $searchConds) . ')';
            }
        }

        $where = implode(' AND ', $conditions);
        $page = isset($data['pageNo']) ? (int)$data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int)$data['pageSize'] : 15;
        $orderField = $timeField !== '' ? $timeField : 'id';
        $isDmDriver = self::tcIsDmDriver();
        $result = null;
        $primaryQueryError = null;
        try {
            $result = self::crud('dcim-alarmlist')->selectWithPagination($where, $params, 'ORDER BY ' . $orderField . ' DESC', $page, $pageSize);
        } catch (\Throwable $e) {
            $primaryQueryError = $e;
            error_log('[GetRealAlarmsKey] primary query failed: ' . $e->getMessage());
        }
        if ($result === null && $deviceExistsCondition !== '') {
            $fallbackConditions = [];
            foreach ($conditions as $cond) {
                if ($cond === $deviceExistsCondition) {
                    continue;
                }
                $fallbackConditions[] = $cond;
            }
            if (!$fallbackConditions) {
                $fallbackConditions[] = '1=1';
            }
            $fallbackWhere = implode(' AND ', $fallbackConditions);
            try {
                $result = self::crud('dcim-alarmlist')->selectWithPagination(
                    $fallbackWhere,
                    $params,
                    'ORDER BY ' . $orderField . ' DESC',
                    $page,
                    $pageSize
                );
            } catch (\Throwable $fallbackError) {
                $primaryQueryError = $fallbackError;
                error_log('[GetRealAlarmsKey] fallback query failed: ' . $fallbackError->getMessage());
            }
        }
        if ($result === null) {
            throw $primaryQueryError;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $rawCount = count($rows);
        $filterDeletedDevice = true;
        $result['info'] = self::alarmEnrichRows($rows, $filterDeletedDevice);
        $filteredCount = count($result['info']);
        if ($filteredCount !== $rawCount && isset($result['page']) && is_array($result['page']) && isset($result['page']['total'])) {
            $total = max(0, (int)$result['page']['total'] - max(0, $rawCount - $filteredCount));
            $result['page']['total'] = $total;
            $result['page']['p_n'] = $pageSize > 0 ? (int)ceil($total / $pageSize) : 0;
        }

        // Fallback: if strict device EXISTS filter yields empty, retry without it.
        if (
            $deviceExistsCondition !== ''
            && empty($result['info'])
        ) {
            $fallbackConditions = [];
            foreach ($conditions as $cond) {
                if ($cond === $deviceExistsCondition) {
                    continue;
                }
                $fallbackConditions[] = $cond;
            }
            if (!$fallbackConditions) {
                $fallbackConditions[] = '1=1';
            }
            $fallbackWhere = implode(' AND ', $fallbackConditions);
            $fallbackResult = self::crud('dcim-alarmlist')->selectWithPagination(
                $fallbackWhere,
                $params,
                'ORDER BY ' . $orderField . ' DESC',
                $page,
                $pageSize
            );
            $fallbackRows = is_array($fallbackResult['info'] ?? null) ? $fallbackResult['info'] : [];
            if ($fallbackRows) {
                $fallbackResult['info'] = self::alarmEnrichRows($fallbackRows, true);
                $result = $fallbackResult;
            }
        }

        // Final fallback for DM/legacy payloads: query base alarm rows with minimal constraints.
        if (empty($result['info'])) {
            $minimalConditions = [];
            $minimalParams = [];
            if ($statusField !== '') {
                $minimalConditions[] = $statusField . ' <> -1';
            } else {
                $minimalConditions[] = '1=1';
            }
            if ($alarmStatusField !== '') {
                if ($requestedAlarmStatus !== null) {
                    $minimalConditions[] = $alarmStatusField . ' = :minimal_alarm_status';
                    $minimalParams[':minimal_alarm_status'] = $requestedAlarmStatus;
                } else {
                    $minimalConditions[] = $alarmStatusField . ' <> :minimal_alarm_status_zero';
                    $minimalParams[':minimal_alarm_status_zero'] = '0';
                }
            }
            if ($devField !== '') {
                $minimalDevIds = $parseList($data['DevId'] ?? ($data['DevID'] ?? ''));
                if ($minimalDevIds) {
                    $minimalDevPhs = [];
                    foreach ($minimalDevIds as $idx => $minimalDevId) {
                        $ph = ':minimal_dev_id_' . $idx;
                        $minimalDevPhs[] = $ph;
                        $minimalParams[$ph] = $minimalDevId;
                    }
                    $minimalConditions[] = $devField . ' IN (' . implode(', ', $minimalDevPhs) . ')';
                }
            }
            $minimalWhere = implode(' AND ', $minimalConditions);
            $minimalResult = self::crud('dcim-alarmlist')->selectWithPagination(
                $minimalWhere,
                $minimalParams,
                'ORDER BY ' . $orderField . ' DESC',
                $page,
                $pageSize
            );
            $minimalRows = is_array($minimalResult['info'] ?? null) ? $minimalResult['info'] : [];
            if (!$minimalRows && $alarmStatusField !== '' && !$hasExplicitStatusFilter) {
                $minimalConditionsNoStatus = [];
                foreach ($minimalConditions as $cond) {
                    if (strpos($cond, $alarmStatusField . ' =') === 0 || strpos($cond, $alarmStatusField . ' <>') === 0) {
                        continue;
                    }
                    $minimalConditionsNoStatus[] = $cond;
                }
                if (!$minimalConditionsNoStatus) {
                    $minimalConditionsNoStatus[] = '1=1';
                }
                $minimalParamsNoStatus = [];
                foreach ($minimalParams as $key => $value) {
                    if ($key === ':minimal_alarm_status' || $key === ':minimal_alarm_status_zero') {
                        continue;
                    }
                    $minimalParamsNoStatus[$key] = $value;
                }
                $minimalResult = self::crud('dcim-alarmlist')->selectWithPagination(
                    implode(' AND ', $minimalConditionsNoStatus),
                    $minimalParamsNoStatus,
                    'ORDER BY ' . $orderField . ' DESC',
                    $page,
                    $pageSize
                );
                $minimalRows = is_array($minimalResult['info'] ?? null) ? $minimalResult['info'] : [];
            }
            if ($minimalRows) {
                $minimalResult['info'] = self::alarmEnrichRows($minimalRows, true);
                $result = $minimalResult;
            }
        }

        // Strong-filter path for DM/legacy edge cases:
        // when filters are provided, enforce exact filtering in PHP after enrichment.
        if ($hasStrongFilters) {
            try {
                $baseConditions = [];
                $baseParams = [];
                if ($statusField !== '') {
                    $baseConditions[] = $statusField . ' <> -1';
                } else {
                    $baseConditions[] = '1=1';
                }
                if ($alarmStatusField !== '') {
                    if ($requestedAlarmStatus !== null) {
                        $baseConditions[] = $alarmStatusField . ' = :fb_alarm_status';
                        $baseParams[':fb_alarm_status'] = $requestedAlarmStatus;
                    } else {
                        $baseConditions[] = $alarmStatusField . ' <> :fb_alarm_status_zero';
                        $baseParams[':fb_alarm_status_zero'] = '0';
                    }
                }
                $baseRows = self::crud('dcim-alarmlist')->selectByRawCondition(
                    implode(' AND ', $baseConditions),
                    'ORDER BY ' . $orderField . ' DESC',
                    $baseParams
                );
                $baseRows = is_array($baseRows) ? $baseRows : [];
                $enrichedRows = self::alarmEnrichRows($baseRows, true);

                $devIdFilterList = $parseList($data['DevId'] ?? ($data['DevID'] ?? ''));
                $alarmLevelFilter = trim((string)($data['AlarmLevel'] ?? ''));
                $devClassFilterList = $requestedDevClassList;
                $searchText = trim((string)($data['search'] ?? ''));
                $searchNeedle = function_exists('mb_strtolower') ? mb_strtolower($searchText, 'UTF-8') : strtolower($searchText);
                $disconnectNeedle = function_exists('mb_strtolower') ? mb_strtolower((string)dcim_msg('app.alarm_disconnected'), 'UTF-8') : strtolower((string)dcim_msg('app.alarm_disconnected'));
                $startTs = $startDateTime !== '' ? strtotime($startDateTime) : false;
                $endTs = $endDateTime !== '' ? strtotime($endDateTime) : false;
                $serverFilterMap = [];
                foreach ($serverCodes as $sv) {
                    $serverFilterMap[(string)$sv] = true;
                }
                $areaFilterMap = [];
                foreach ($areaIds as $av) {
                    $areaFilterMap[(string)$av] = true;
                }
                $devIdFilterMap = [];
                foreach ($devIdFilterList as $dv) {
                    $devIdFilterMap[(string)$dv] = true;
                }
                $devClassFilterMap = [];
                foreach ($devClassFilterList as $dc) {
                    $devClassFilterMap[(string)$dc] = true;
                }

                $filtered = [];
                foreach ($enrichedRows as $row) {
                    $rowDevId = trim((string)($row['DevId'] ?? ($row['DevID'] ?? '')));
                    if ($devIdFilterMap && !isset($devIdFilterMap[$rowDevId])) {
                        continue;
                    }
                    if ($alarmLevelFilter !== '') {
                        $rowLevel = trim((string)($row['AlarmLevel'] ?? ''));
                        if ($rowLevel !== $alarmLevelFilter) {
                            continue;
                        }
                    }
                    if ($devClassFilterMap) {
                        $rowDevClass = trim((string)($row['DevClass'] ?? ''));
                        if (!isset($devClassFilterMap[$rowDevClass])) {
                            continue;
                        }
                    }
                    if ($serverFilterMap) {
                        $rowServerCode = trim((string)($row['ServerCode'] ?? ''));
                        if (!isset($serverFilterMap[$rowServerCode])) {
                            continue;
                        }
                    }
                    if ($areaFilterMap) {
                        $rowAreaId = trim((string)($row['AreaId'] ?? ''));
                        if (!isset($areaFilterMap[$rowAreaId])) {
                            continue;
                        }
                    }
                    if ($startTs !== false || $endTs !== false) {
                        $rowTimeRaw = trim((string)($row[$timeField] ?? ($row['create_time'] ?? '')));
                        $rowTs = $rowTimeRaw !== '' ? strtotime($rowTimeRaw) : false;
                        if ($rowTs === false) {
                            continue;
                        }
                        if ($startTs !== false && $rowTs < $startTs) {
                            continue;
                        }
                        if ($endTs !== false && $rowTs > $endTs) {
                            continue;
                        }
                    }
                    if ($searchNeedle !== '') {
                        $haystackParts = [
                            (string)($row['TextMessage'] ?? ''),
                            (string)($row['Solution'] ?? ''),
                            (string)($row['OrderNumber'] ?? ''),
                            (string)($row['NotifyMode'] ?? ''),
                            (string)($row['ParamValue'] ?? ''),
                            (string)($row['DeviceName'] ?? ''),
                            (string)($row['TypeName'] ?? ''),
                        ];
                        $haystack = implode(' ', $haystackParts);
                        $haystackLower = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
                        $searchMatched = strpos($haystackLower, $searchNeedle) !== false;
                        if (!$searchMatched && (strpos($searchNeedle, 'disconnect') !== false || ($disconnectNeedle !== '' && strpos($searchNeedle, $disconnectNeedle) !== false))) {
                            $searchMatched = ((string)($row['AlarmType'] ?? '') === '5');
                        }
                        if (!$searchMatched) {
                            continue;
                        }
                    }
                    $filtered[] = $row;
                }

                $total = count($filtered);
                $p = $page > 0 ? $page : 1;
                $pn = $pageSize > 0 ? (int)ceil($total / $pageSize) : 0;
                $offset = max(0, ($p - 1) * $pageSize);
                $pageRows = $pageSize > 0 ? array_slice($filtered, $offset, $pageSize) : $filtered;
                $result = [
                    'info' => array_values($pageRows),
                    'page' => [
                        'total' => $total,
                        'p_n' => $pn,
                        'p' => $p,
                    ],
                ];
            } catch (\Throwable $e) {
            }
        }

        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    private static function alarmEnrichRows(array $rows, bool $filterDeletedDevice): array
    {
        if (!$rows) {
            return $rows;
        }
        $normalizeId = static function ($raw): string {
            $txt = trim((string)$raw);
            if ($txt === '') {
                return '';
            }
            if (preg_match('/^\d+(\.0+)?$/', $txt) === 1) {
                return (string)(int)$txt;
            }
            return $txt;
        };
        $devIds = [];
        $typeIds = [];
        $classIds = [];
        $levelIds = [];
        $notifyModeIds = [];
        $confirmIds = [];
        $xjEmpIds = [];
        $xjPointIds = [];
        foreach ($rows as $row) {
            $devId = trim((string)($row['DevId'] ?? ($row['DevID'] ?? '')));
            if ($devId !== '') {
                $devIds[] = $devId;
                $normalizedDevId = $normalizeId($devId);
                if ($normalizedDevId !== '' && $normalizedDevId !== $devId) {
                    $devIds[] = $normalizedDevId;
                }
            }
            if (!empty($row['AlarmType'])) {
                $typeIds[] = $row['AlarmType'];
            }
            if (!empty($row['DevClass'])) {
                $classIds[] = $row['DevClass'];
            }
            if (!empty($row['AlarmLevel'])) {
                $levelIds[] = $row['AlarmLevel'];
            }
            if (!empty($row['NotifyModeID'])) {
                $notifyModeIds[] = $row['NotifyModeID'];
            }
            if (!empty($row['ConfirmUserId'])) {
                $confirmIds[] = $row['ConfirmUserId'];
            }
            if (!empty($row['XJEmpId'])) {
                foreach (explode(',', (string)$row['XJEmpId']) as $eid) {
                    $eid = trim($eid);
                    if ($eid !== '') {
                        $xjEmpIds[] = $eid;
                    }
                }
            }
            if (!empty($row['XJPointId'])) {
                foreach (explode(',', (string)$row['XJPointId']) as $pid) {
                    $pid = trim($pid);
                    if ($pid !== '') {
                        $xjPointIds[] = $pid;
                    }
                }
            }
        }
        $deviceMap = [];
        $areaIds = [];
        $deviceCols = self::tcGetTableColumns('dcim-device');
        $deviceIpField = self::tcPickColumn($deviceCols, ['DeviceIP', 'DevIP', 'IP', 'Host', 'Address']);
        $devicePortField = self::tcPickColumn($deviceCols, ['DevicePort', 'DevPort', 'Port', 'CollectPort', 'ComPort']);
        $deviceSelectFields = ['id', 'DeviceName', 'ServerCode', 'AreaId', 'status'];
        if ($deviceIpField !== '' && !in_array($deviceIpField, $deviceSelectFields, true)) {
            $deviceSelectFields[] = $deviceIpField;
        }
        if ($devicePortField !== '' && !in_array($devicePortField, $deviceSelectFields, true)) {
            $deviceSelectFields[] = $devicePortField;
        }
        foreach (self::crud('dcim-device')->selectByIds($devIds, $deviceSelectFields) as $item) {
            $key = trim((string)self::pickFirstFieldInsensitive($item, ['id', 'ID'], ''));
            if ($key !== '') {
                $normalizedKey = $normalizeId($key);
                $deviceRecord = [
                    'id' => $key,
                    'DeviceName' => (string)self::pickFirstFieldInsensitive($item, ['DeviceName', 'DEVICENAME', 'Name', 'NAME'], ''),
                    'ServerCode' => (string)self::pickFirstFieldInsensitive($item, ['ServerCode', 'SERVERCODE', 'ServerID', 'ServerId'], ''),
                    'AreaId' => (string)self::pickFirstFieldInsensitive($item, ['AreaId', 'AREAID'], ''),
                    'status' => self::pickFirstFieldInsensitive($item, ['status', 'Status', 'STATUS'], null),
                    'DeviceIP' => (string)self::pickFirstFieldInsensitive($item, array_values(array_unique(array_filter([$deviceIpField, 'DeviceIP', 'DevIP', 'IP', 'Host', 'Address']))), ''),
                    'DevicePort' => (string)self::pickFirstFieldInsensitive($item, array_values(array_unique(array_filter([$devicePortField, 'DevicePort', 'DevPort', 'Port', 'CollectPort', 'ComPort']))), ''),
                ];
                $deviceMap[$key] = $deviceRecord;
                if ($normalizedKey !== '' && $normalizedKey !== $key) {
                    $deviceMap[$normalizedKey] = $deviceRecord;
                }
                if (!empty($deviceRecord['AreaId'])) {
                    $areaIds[] = $deviceRecord['AreaId'];
                }
            }
        }
        $areaMap = [];
        foreach (self::crud('dcim-area')->selectByIds($areaIds, ['id', 'AreaName']) as $item) {
            $key = trim((string)self::pickFirstFieldInsensitive($item, ['id', 'ID'], ''));
            if ($key !== '') {
                $areaMap[$key] = (string)self::pickFirstFieldInsensitive($item, ['AreaName', 'AREANAME', 'Name', 'NAME'], '');
            }
        }
        $serverMap = [];
        $serverIds = [];
        foreach ($deviceMap as $device) {
            if (!empty($device['ServerCode'])) {
                $serverIds[] = $device['ServerCode'];
            }
        }
        foreach (self::crud('dcim-server')->selectByIds($serverIds, ['id', 'ServerCode', 'ServerName']) as $item) {
            $idKey = trim((string)self::pickFirstFieldInsensitive($item, ['id', 'ID'], ''));
            if ($idKey !== '') {
                $serverMap[$idKey] = (string)self::pickFirstFieldInsensitive($item, ['ServerName', 'SERVERNAME', 'Name', 'NAME'], '');
            }
            $codeKey = trim((string)self::pickFirstFieldInsensitive($item, ['ServerCode', 'SERVERCODE'], ''));
            if ($codeKey !== '' && !isset($serverMap[$codeKey])) {
                $serverMap[$codeKey] = (string)self::pickFirstFieldInsensitive($item, ['ServerName', 'SERVERNAME', 'Name', 'NAME'], '');
            }
        }
        $typeMap = [];
        foreach (self::crud('dcim-alarmtype')->selectByIds($typeIds, ['id', 'TypeName']) as $item) {
            $key = trim((string)self::pickFirstFieldInsensitive($item, ['id', 'ID'], ''));
            if ($key !== '') {
                $typeMap[$key] = (string)self::pickFirstFieldInsensitive($item, ['TypeName', 'TYPENAME', 'Name', 'NAME'], '');
            }
        }
        $classMap = [];
        foreach (self::crud('dcim-deviceclass')->selectByIds($classIds, ['id', 'ClassName']) as $item) {
            $key = trim((string)self::pickFirstFieldInsensitive($item, ['id', 'ID'], ''));
            if ($key !== '') {
                $classMap[$key] = (string)self::pickFirstFieldInsensitive($item, ['ClassName', 'CLASSNAME', 'Name', 'NAME'], '');
            }
        }
        $levelMap = [];
        foreach (self::crud('dcim-alarmlevellist')->selectByIds($levelIds, ['id', 'LevelName']) as $item) {
            $key = trim((string)self::pickFirstFieldInsensitive($item, ['id', 'ID'], ''));
            if ($key !== '') {
                $levelMap[$key] = (string)self::pickFirstFieldInsensitive($item, ['LevelName', 'LEVELNAME', 'Name', 'NAME'], '');
            }
        }
        $notifyMap = [];
        foreach (self::crud('dcim-alarmnotifymode')->selectByIds($notifyModeIds, ['id', 'AlarmName']) as $item) {
            $key = trim((string)self::pickFirstFieldInsensitive($item, ['id', 'ID'], ''));
            if ($key !== '') {
                $notifyMap[$key] = (string)self::pickFirstFieldInsensitive($item, ['AlarmName', 'ALARMNAME', 'Name', 'NAME'], '');
            }
        }
        $confirmMap = [];
        foreach (self::crud('dcim-person')->selectByIds($confirmIds, ['id', 'PersonName']) as $item) {
            $key = trim((string)self::pickFirstFieldInsensitive($item, ['id', 'ID'], ''));
            if ($key !== '') {
                $confirmMap[$key] = (string)self::pickFirstFieldInsensitive($item, ['PersonName', 'PERSONNAME', 'Name', 'NAME'], '');
            }
        }
        $xjEmpMap = [];
        foreach (self::crud('dcim-person')->selectByIds(array_values(array_unique($xjEmpIds)), ['id', 'PersonName']) as $item) {
            $key = trim((string)self::pickFirstFieldInsensitive($item, ['id', 'ID'], ''));
            if ($key !== '') {
                $xjEmpMap[$key] = (string)self::pickFirstFieldInsensitive($item, ['PersonName', 'PERSONNAME', 'Name', 'NAME'], '');
            }
        }
        $xjPointMap = [];
        foreach (self::crud('dcim-xjpoint')->selectByIds(array_values(array_unique($xjPointIds)), ['id', 'XJPointName']) as $item) {
            $key = trim((string)self::pickFirstFieldInsensitive($item, ['id', 'ID'], ''));
            if ($key !== '') {
                $xjPointMap[$key] = (string)self::pickFirstFieldInsensitive($item, ['XJPointName', 'XJPOINTNAME', 'Name', 'NAME'], '');
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $devId = (string)($row['DevId'] ?? ($row['DevID'] ?? ''));
            $devIdNormalized = $normalizeId($devId);
            $device = $deviceMap[$devId] ?? [];
            if (!$device && $devIdNormalized !== '' && isset($deviceMap[$devIdNormalized])) {
                $device = $deviceMap[$devIdNormalized];
                if (!isset($row['DevId']) || trim((string)$row['DevId']) === '') {
                    $row['DevId'] = $devIdNormalized;
                }
                if (!isset($row['DevID']) || trim((string)$row['DevID']) === '') {
                    $row['DevID'] = $devIdNormalized;
                }
            }
            if ($filterDeletedDevice) {
                // Real alarm list must not include alarms whose device has been deleted
                // or whose device record no longer exists.
                if (!$device) {
                    continue;
                }
                $deviceStatusRaw = isset($device['status']) ? trim((string)$device['status']) : '';
                if ($deviceStatusRaw === '-1') {
                    continue;
                }
            }
            if (!isset($row['DevId']) && $devId !== '') {
                $row['DevId'] = $devId;
            }
            if (!isset($row['DevID']) && $devId !== '') {
                $row['DevID'] = $devId;
            }
            $row['DeviceName'] = $device['DeviceName'] ?? ($row['DeviceName'] ?? '');
            $row['AreaId'] = $device['AreaId'] ?? ($row['AreaId'] ?? '');
            $row['AreaName'] = $areaMap[(string)($row['AreaId'] ?? '')] ?? ($row['AreaName'] ?? '');
            $row['ServerCode'] = $device['ServerCode'] ?? ($row['ServerCode'] ?? '');
            $row['ServerName'] = $serverMap[(string)($row['ServerCode'] ?? '')] ?? ($row['ServerName'] ?? '');
            $row['DeviceIP'] = $device['DeviceIP'] ?? ($row['DeviceIP'] ?? '');
            $row['DevicePort'] = $device['DevicePort'] ?? ($row['DevicePort'] ?? '');
            $row['TypeName'] = $typeMap[(string)($row['AlarmType'] ?? '')] ?? ($row['TypeName'] ?? '');
            $row['ClassName'] = $classMap[(string)($row['DevClass'] ?? '')] ?? ($row['ClassName'] ?? '');
            $row['LevelName'] = $levelMap[(string)($row['AlarmLevel'] ?? '')] ?? ($row['LevelName'] ?? '');
            $row['AlarmName'] = $notifyMap[(string)($row['NotifyModeID'] ?? '')] ?? ($row['AlarmName'] ?? '');
            $row['ConfirmUserName'] = $confirmMap[(string)($row['ConfirmUserId'] ?? '')] ?? ($row['ConfirmUserName'] ?? '');
            $empNames = [];
            foreach (explode(',', (string)($row['XJEmpId'] ?? '')) as $eid) {
                $eid = trim($eid);
                if ($eid !== '' && isset($xjEmpMap[$eid])) {
                    $empNames[] = (string)$xjEmpMap[$eid];
                }
            }
            $pointNames = [];
            foreach (explode(',', (string)($row['XJPointId'] ?? '')) as $pid) {
                $pid = trim($pid);
                if ($pid !== '' && isset($xjPointMap[$pid])) {
                    $pointNames[] = (string)$xjPointMap[$pid];
                }
            }
            $row['XJEmpName'] = $empNames ? implode(',', array_values(array_unique($empNames))) : ($row['XJEmpName'] ?? '');
            $row['XJPointName'] = $pointNames ? implode(',', array_values(array_unique($pointNames))) : ($row['XJPointName'] ?? '');
            $out[] = $row;
        }
        return $out;
    }

    private static function normalizeRealAlarmDetailRows(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $devId = trim((string)($row['DevID'] ?? ($row['DevId'] ?? ($row['DeviceId'] ?? ($row['DeviceID'] ?? '')))));
            if ($devId !== '') {
                $row['DevID'] = $devId;
                if (!isset($row['DevId'])) {
                    $row['DevId'] = $devId;
                }
            } else {
                $row['DevID'] = '';
            }
            if (!isset($row['CommType'])) {
                $row['CommType'] = '';
            }
            if (!isset($row['Data'])) {
                $row['Data'] = '';
            }
            if (!isset($row['create_time']) || trim((string)$row['create_time']) === '') {
                $row['create_time'] = (string)($row['CreateTime'] ?? ($row['AlarmTime'] ?? ''));
            }
            if (!isset($row['DeviceName'])) {
                $row['DeviceName'] = '';
            }
            if (!isset($row['ServerCode'])) {
                $row['ServerCode'] = '';
            }
            if (!isset($row['ServerName'])) {
                $row['ServerName'] = '';
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function enrichCollectorHistoryRows(array $rows, bool $filterDeletedDevice = true): array
    {
        if (!$rows) {
            return [];
        }

        $rawDevKeys = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $devKey = trim((string)(self::pickFirstField($row, ['DevID', 'DevId', 'DeviceId', 'DeviceID', 'DevCode', 'DeviceCode', 'DeviceNo', 'DeviceNumber']) ?? ''));
            if ($devKey !== '') {
                $rawDevKeys[$devKey] = true;
            }
        }
        $rawDevKeys = array_values(array_keys($rawDevKeys));
        if (!$rawDevKeys) {
            return [];
        }

        $deviceCols = self::tcGetTableColumns('dcim-device');
        if (!$deviceCols) {
            return [];
        }

        $deviceIdField = self::tcPickColumn($deviceCols, ['id', 'ID']);
        $deviceNameField = self::tcPickColumn($deviceCols, ['DeviceName', 'Name']);
        $deviceServerField = self::tcPickColumn($deviceCols, ['ServerCode', 'ServerId', 'ServerID']);
        $deviceStatusField = self::tcPickColumn($deviceCols, ['status', 'Status']);

        $deviceKeyFields = [];
        foreach (['id', 'DeviceCode', 'DevCode', 'DeviceNumber', 'DeviceNo', 'DeviceID'] as $candidateField) {
            $picked = self::tcPickColumn($deviceCols, [$candidateField]);
            if ($picked !== '' && !in_array($picked, $deviceKeyFields, true)) {
                $deviceKeyFields[] = $picked;
            }
        }
        if (!$deviceKeyFields && $deviceIdField !== '') {
            $deviceKeyFields[] = $deviceIdField;
        }

        $deviceRows = [];
        $deviceSeen = [];
        foreach ($deviceKeyFields as $fieldIdx => $keyField) {
            $holders = [];
            $params = [];
            foreach ($rawDevKeys as $keyIdx => $keyVal) {
                $ph = ':dv_' . $fieldIdx . '_' . $keyIdx;
                $holders[] = $ph;
                $params[$ph] = $keyVal;
            }
            if (!$holders) {
                continue;
            }
            try {
                $partRows = self::crud('dcim-device')->selectByRawCondition(
                    $keyField . ' IN (' . implode(',', $holders) . ')',
                    '',
                    $params
                );
            } catch (\Throwable $e) {
                $partRows = [];
            }
            foreach ($partRows as $deviceRow) {
                if (!is_array($deviceRow)) {
                    continue;
                }
                $uniqKey = trim((string)(
                    ($deviceIdField !== '' ? ($deviceRow[$deviceIdField] ?? '') : '') !== ''
                        ? ($deviceRow[$deviceIdField] ?? '')
                        : self::pickFirstField($deviceRow, ['id', 'ID'])
                ));
                if ($uniqKey === '') {
                    $uniqKey = md5(json_encode($deviceRow, JSON_UNESCAPED_UNICODE));
                }
                if (isset($deviceSeen[$uniqKey])) {
                    continue;
                }
                $deviceSeen[$uniqKey] = true;
                $deviceRows[] = $deviceRow;
            }
        }

        $deviceByAnyKey = [];
        $serverCodes = [];
        foreach ($deviceRows as $deviceRow) {
            foreach ($deviceKeyFields as $keyField) {
                $keyVal = trim((string)($deviceRow[$keyField] ?? ''));
                if ($keyVal !== '' && !isset($deviceByAnyKey[$keyVal])) {
                    $deviceByAnyKey[$keyVal] = $deviceRow;
                }
            }
            if ($deviceIdField !== '') {
                $idVal = trim((string)($deviceRow[$deviceIdField] ?? ''));
                if ($idVal !== '' && !isset($deviceByAnyKey[$idVal])) {
                    $deviceByAnyKey[$idVal] = $deviceRow;
                }
            }
            if ($deviceServerField !== '') {
                $serverCode = trim((string)($deviceRow[$deviceServerField] ?? ''));
                if ($serverCode !== '') {
                    $serverCodes[$serverCode] = true;
                }
            }
        }

        $serverNameMap = [];
        if ($serverCodes) {
            try {
                $serverRows = self::crud('dcim-server')->selectByIds(
                    array_keys($serverCodes),
                    ['id', 'ServerCode', 'ServerName']
                );
            } catch (\Throwable $e) {
                $serverRows = [];
            }
            foreach ($serverRows as $serverRow) {
                $sid = trim((string)($serverRow['id'] ?? ''));
                if ($sid !== '' && !isset($serverNameMap[$sid])) {
                    $serverNameMap[$sid] = (string)($serverRow['ServerName'] ?? '');
                }
                $scode = trim((string)($serverRow['ServerCode'] ?? ''));
                if ($scode !== '' && !isset($serverNameMap[$scode])) {
                    $serverNameMap[$scode] = (string)($serverRow['ServerName'] ?? '');
                }
            }
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawDevKey = trim((string)(self::pickFirstField($row, ['DevID', 'DevId', 'DeviceId', 'DeviceID', 'DevCode', 'DeviceCode', 'DeviceNo', 'DeviceNumber']) ?? ''));
            $device = $rawDevKey !== '' ? ($deviceByAnyKey[$rawDevKey] ?? []) : [];

            if ($filterDeletedDevice) {
                if (!$device) {
                    continue;
                }
                $statusVal = $deviceStatusField !== ''
                    ? ($device[$deviceStatusField] ?? null)
                    : ($device['status'] ?? null);
                if ((int)$statusVal !== 1) {
                    continue;
                }
            }

            $resolvedDevId = '';
            if ($deviceIdField !== '') {
                $resolvedDevId = trim((string)($device[$deviceIdField] ?? ''));
            }
            if ($resolvedDevId === '') {
                $resolvedDevId = $rawDevKey;
            }
            $row['DevID'] = $resolvedDevId;

            $row['DeviceName'] = $deviceNameField !== ''
                ? (string)($device[$deviceNameField] ?? ($row['DeviceName'] ?? ''))
                : (string)($row['DeviceName'] ?? '');

            $serverCode = $deviceServerField !== ''
                ? trim((string)($device[$deviceServerField] ?? ''))
                : '';
            if ($serverCode === '') {
                $serverCode = trim((string)($row['ServerCode'] ?? ''));
            }
            $row['ServerCode'] = $serverCode;
            $row['ServerName'] = $serverNameMap[$serverCode] ?? (string)($row['ServerName'] ?? '');

            $out[] = $row;
        }
        return $out;
    }

    private static function normalizeHistoryAlarmRows(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $devId = trim((string)(self::pickFirstField($row, ['DevID', 'DevId', 'DeviceId', 'DeviceID']) ?? ''));
            $dataVal = self::pickFirstField($row, ['Data', 'AlarmData', 'ReceiveData', 'LastReceiveData', 'data']);
            if (is_array($dataVal) || is_object($dataVal)) {
                $encoded = json_encode($dataVal, JSON_UNESCAPED_UNICODE);
                $dataVal = is_string($encoded) ? $encoded : '';
            }
            $commType = (string)(self::pickFirstField($row, ['CommType', 'CommandType', 'CollectType']) ?? '');
            $createTime = (string)(self::pickFirstField($row, ['create_time', 'CreateTime', 'AlarmTime', 'CollectTime', 'RecordTime', 'update_time']) ?? '');
            $deviceName = (string)(self::pickFirstField($row, ['DeviceName']) ?? '');
            $serverCode = (string)(self::pickFirstField($row, ['ServerCode']) ?? '');
            $serverName = (string)(self::pickFirstField($row, ['ServerName']) ?? '');
            $idVal = self::pickFirstField($row, ['id', 'ID', 'Lsh']);
            $id = is_numeric($idVal) ? (int)$idVal : (int)trim((string)$idVal);
            $out[] = [
                'id' => $id,
                'DevID' => $devId,
                'Data' => is_string($dataVal) ? $dataVal : (string)$dataVal,
                'CommType' => $commType,
                'create_time' => $createTime,
                'DeviceName' => $deviceName,
                'ServerCode' => $serverCode,
                'ServerName' => $serverName,
            ];
        }
        return $out;
    }

    public static function getHistoryList(bool $skipAuth = true)
    {
        $data = Flight::request_data();
        if (!$skipAuth) {
            self::ensureAuth($data);
        }

        $page = isset($data['pageNo']) ? max((int)$data['pageNo'], 1) : 1;
        $pageSize = isset($data['pageSize']) ? max((int)$data['pageSize'], 1) : 15;
        $comboAll = strtolower(trim((string)($data['ComboBox'] ?? ''))) === 'all';

        $tableName = 'dcim-collectordata';
        $tableColumns = self::tcGetTableColumns($tableName);
        if (!$tableColumns) {
            if ($comboAll) {
                O_E([], tp_msg_success(), 100, 0);
                return;
            }
            O_E(['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => $page]], tp_msg_success(), 100, 0);
            return;
        }

        $start = trim((string)($data['startDateTime'] ?? ''));
        $end = trim((string)($data['endDateTime'] ?? ''));
        $search = trim((string)($data['search'] ?? ''));
        $devRaw = trim((string)($data['DevID'] ?? ($data['DevId'] ?? ($data['DeviceId'] ?? ''))));

        $devInputList = [];
        if ($devRaw !== '') {
            $devInputList = array_values(array_unique(array_filter(array_map('trim', explode(',', $devRaw)), static function ($v) {
                return $v !== '';
            })));
        }

        $devCandidates = [];
        $normalizeDevToken = static function (string $raw): string {
            $txt = trim($raw);
            if ($txt === '') {
                return '';
            }
            if (preg_match('/^\d+(\.0+)?$/', $txt) === 1) {
                return (string)(int)$txt;
            }
            return $txt;
        };
        foreach ($devInputList as $devInput) {
            $devCandidates[$devInput] = true;
            $normDevInput = $normalizeDevToken($devInput);
            if ($normDevInput !== '') {
                $devCandidates[$normDevInput] = true;
            }
        }
        $devCandidates = array_values(array_keys($devCandidates));

        $conditions = ['1=1'];
        $params = [];

        $statusField = self::tcPickColumn($tableColumns, ['status', 'Status']);
        if ($statusField !== '') {
            $conditions[] = '(' . $statusField . ' <> -1 OR ' . $statusField . ' IS NULL)';
        }

        $timeField = self::tcPickColumn($tableColumns, [
            'create_time',
            'CollectTime',
            'collect_time',
            'CreateTime',
            'ReceiveTime',
            'CollectDateTime',
            'CollectDate',
            'update_time',
            'Time',
        ]);
        if ($timeField !== '') {
            if ($start !== '' && $end !== '') {
                $conditions[] = $timeField . ' BETWEEN :start_time AND :end_time';
                $params[':start_time'] = $start;
                $params[':end_time'] = $end;
            } elseif ($start !== '') {
                $conditions[] = $timeField . ' >= :start_time';
                $params[':start_time'] = $start;
            } elseif ($end !== '') {
                $conditions[] = $timeField . ' <= :end_time';
                $params[':end_time'] = $end;
            }
        }

        $devFields = [];
        foreach (['DevID', 'DevId', 'DeviceId', 'DeviceID', 'DevCode', 'DeviceCode', 'DeviceNo', 'DeviceNumber'] as $candidateDevField) {
            $picked = self::tcPickColumn($tableColumns, [$candidateDevField]);
            if ($picked !== '' && !in_array($picked, $devFields, true)) {
                $devFields[] = $picked;
            }
        }
        if ($devCandidates && $devFields) {
            $devConds = [];
            foreach ($devFields as $fieldIdx => $devField) {
                $holders = [];
                foreach ($devCandidates as $idx => $devVal) {
                    $ph = ':dev_' . $fieldIdx . '_' . $idx;
                    $holders[] = $ph;
                    $params[$ph] = $devVal;
                }
                if ($holders) {
                    $devConds[] = $devField . ' IN (' . implode(',', $holders) . ')';
                }
            }
            if ($devConds) {
                $conditions[] = '(' . implode(' OR ', $devConds) . ')';
            }
        }

        $dataField = self::tcPickColumn($tableColumns, ['Data', 'LastReceiveData', 'CollectData', 'DataJson', 'RawData', 'AlarmData', 'ReceiveData']);
        $commField = self::tcPickColumn($tableColumns, ['CommType', 'CommandType', 'CollectType']);
        if ($search !== '') {
            $searchConds = [];
            $searchFields = [];
            if ($dataField !== '') {
                $searchFields[] = $dataField;
            }
            if ($commField !== '') {
                $searchFields[] = $commField;
            }
            foreach ($devFields as $devField) {
                if (!in_array($devField, $searchFields, true)) {
                    $searchFields[] = $devField;
                }
            }
            foreach ($searchFields as $idx => $fieldName) {
                $ph = ':search_' . $idx;
                $searchConds[] = $fieldName . ' LIKE ' . $ph;
                $params[$ph] = '%' . $search . '%';
            }
            if ($searchConds) {
                $conditions[] = '(' . implode(' OR ', $searchConds) . ')';
            }
        }

        $idField = self::tcPickColumn($tableColumns, ['id', 'ID']);
        $orderField = $timeField !== '' ? $timeField : ($idField !== '' ? $idField : 'id');
        $whereSql = implode(' AND ', $conditions);
        $orderBy = 'ORDER BY ' . $orderField . ' DESC';

        try {
            if ($comboAll) {
                $rows = self::crud($tableName)->selectByRawCondition($whereSql, $orderBy, $params);
                $result = [
                    'info' => is_array($rows) ? $rows : [],
                    'page' => [
                        'total' => is_array($rows) ? count($rows) : 0,
                        'p_n' => 1,
                        'p' => 1,
                    ],
                ];
            } else {
                $result = self::crud($tableName)->selectWithPagination(
                    $whereSql,
                    $params,
                    $orderBy,
                    $page,
                    $pageSize
                );
            }
        } catch (\Throwable $e) {
            $result = [
                'info' => [],
                'page' => [
                    'total' => 0,
                    'p_n' => 0,
                    'p' => $page,
                ],
            ];
        }

        $rawRows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $rawCount = count($rawRows);
        $normalizedRows = self::normalizeHistoryAlarmRows(
            self::enrichCollectorHistoryRows($rawRows, true)
        );
        $filteredCount = count($normalizedRows);

        if (!$comboAll && $filteredCount !== $rawCount && isset($result['page']) && is_array($result['page']) && isset($result['page']['total'])) {
            $total = max(0, (int)$result['page']['total'] - max(0, $rawCount - $filteredCount));
            $result['page']['total'] = $total;
            $result['page']['p_n'] = $pageSize > 0 ? (int)ceil($total / $pageSize) : 0;
        }

        if (!isset($result['page']) || !is_array($result['page'])) {
            $result['page'] = ['total' => 0, 'p_n' => 0, 'p' => $page];
        }
        $result['page']['p'] = max(1, (int)($result['page']['p'] ?? $page));
        $result['page']['total'] = max(0, (int)($result['page']['total'] ?? 0));
        $result['page']['p_n'] = max(0, (int)($result['page']['p_n'] ?? ($pageSize > 0 ? (int)ceil($result['page']['total'] / $pageSize) : 0)));
        $result['info'] = $normalizedRows;

        if ($comboAll) {
            O_E($result['info'], tp_msg_success(), 100, 0);
            return;
        }
        O_E($result, tp_msg_success(), 100, 0);
    }

    public static function getInfo()
    {
        $data = Flight::request_data();
        self::ensureAuth($data);
        $idRaw = trim((string)($data['id'] ?? ''));
        if ($idRaw === '') {
            O_E((object)[], tp_msg_success(), 100, 0);
            return;
        }
        $idList = array_values(array_filter(array_map('trim', explode(',', $idRaw)), static function ($v) {
            return $v !== '';
        }));
        if (!$idList) {
            $idList = [$idRaw];
        }
        $id = $idList[0];
        $info = self::crud('dcim-alarmlist')->legacyInfo(['id' => $id, 'token' => $data['token'] ?? ''], [
            'skip_auth' => true,
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if (!$info) {
            $rows = self::crud('dcim-alarmlist')->selectByRawCondition('id = :id', 'LIMIT 1', [':id' => $id]);
            $info = $rows ? ($rows[0] ?? []) : [];
        }
        $rows = self::alarmEnrichRows(is_array($info) && $info ? [$info] : [], true);
        $rows = self::normalizeRealAlarmDetailRows($rows);
        $detail = (is_array($rows) && $rows) ? (array)$rows[0] : [];
        O_E($detail ? (object)$detail : (object)[], tp_msg_success(), 100, 0);
    }

    public static function CheckAlarm()
    {
        $data = Flight::request_data();
        $user = self::ensureAuth($data);
        $ids = isset($data['eventId']) ? explode(',', $data['eventId']) : [];
        $ids = array_filter(array_map('trim', $ids));
        if (empty($ids)) {
            O_E(true, tp_msg_success(), 100, false);
            return;
        }
        $dealWay = $data['dealWay'] ?? 0;
        $fields = [
            'AlarmStatus'   => $dealWay,
            'NotifyState'   => $data['NotifyState'] ?? 0,
            'NotifyMode'    => $data['NotifyMode'] ?? '',
            'Solution'      => $data['Solution'] ?? '',
            'ConfirmUserId' => $user['id'],
            'ConfirmTime'   => date('Y-m-d H:i:s'),
        ];
        foreach ($ids as $id) {
            self::crud('dcim-alarmlist')->legacyUpdateWhere([['id', '=', $id], ['status', '=', 1]], $fields);
        }
        addLog(count($ids) > 1 ? dcim_msg('log.batch_confirm_alarm') : dcim_msg('log.confirm_alarm'));
        O_E(true, tp_msg_success(), 100, false);
    }

    public static function BackCheckAlarm()
    {
        $data = Flight::request_data();
        self::ensureAuth($data);
        $ids = isset($data['eventId']) ? explode(',', $data['eventId']) : [];
        $ids = array_filter(array_map('trim', $ids));
        if (empty($ids)) {
            P_E(dcim_msg('error.event_id_required'));
        }
        $fields = [
            'AlarmStatus' => $data['dealWay'] ?? 0,
            'ConfirmTime' => null,
            'NotifyMode'  => $data['NotifyMode'] ?? '',
            'NotifyState' => $data['NotifyState'] ?? 0,
        ];
        foreach ($ids as $id) {
            self::crud('dcim-alarmlist')->legacyUpdateWhere([['id', '=', $id], ['status', '=', 1]], $fields);
        }
        addLog(count($ids) > 1 ? dcim_msg('log.batch_back_confirm_alarm') : dcim_msg('log.back_confirm_alarm'));
        O_E(true, tp_msg_success(), 100, false);
    }

    public static function CloseAlarm()
    {
        $data = Flight::request_data();
        self::ensureAuth($data);
        $ids = isset($data['id']) ? explode(',', $data['id']) : [];
        $ids = array_filter(array_map('trim', $ids));
        if (empty($ids)) {
            P_E(dcim_msg('common.id_required'));
        }
        foreach ($ids as $id) {
            self::crud('dcim-alarmlist')->legacyUpdateWhere([['id', '=', $id], ['status', '=', 1]], ['NotifyState' => 0]);
        }
        addLog(count($ids) > 1 ? dcim_msg('log.batch_close_alarm') : dcim_msg('log.close_alarm'));
        O_E(true, tp_msg_success(), 100, false);
    }
}



