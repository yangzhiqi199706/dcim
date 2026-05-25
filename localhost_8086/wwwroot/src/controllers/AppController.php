<?php

class AppController
{
    // Aggregated endpoints from legacy controllers are centralized here.
    private static $dvUnavailableViews = [];
    private static $dvViewFallbackLogged = [];

    private static function dmpageCrud(): CrudController
    {
        return new CrudController('dcim-dmpage');
    }

    private static function dmpageTryProxyLegacy(string $url, array $data): bool
    {
        try {
            $postBody = http_build_query($data);
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $postBody,
                    'timeout' => 5,
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($url, false, $ctx);
        } catch (\Throwable $e) {
            return false;
        }
        if ($raw === false || trim((string) $raw) === '') {
            return false;
        }

        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $hdr) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', $hdr, $m)) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }
        if ($status < 200 || $status >= 300) {
            return false;
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || !array_key_exists('code', $decoded)) {
            return false;
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $raw;
        return true;
    }

    private static function requireAuth(array $data = [])
    {
        $token = function_exists('dcim_extract_token') ? dcim_extract_token($data) : '';
        if (!is_string($token) || trim($token) === '') {
            $token = Flight::request_token();
        }
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            L_E(tp_msg_login());
        }
        $user = function_exists('dcim_auth_user_by_token') ? dcim_auth_user_by_token($token) : null;
        if (!$user) {
            $user = (new CrudController('dcim-person'))->findOne([['token', '=', $token], ['status', '=', 1]]);
        }
        if (!$user && $token !== '') {
            return ['id' => 0];
        } elseif (!$user) {
            L_E(tp_msg_login());
        }
        return $user;
    }

    private static function requireAuthStrict(array $data = [])
    {
        $token = function_exists('dcim_extract_token') ? dcim_extract_token($data) : '';
        if (!is_string($token) || trim($token) === '') {
            $token = Flight::request_token();
        }
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            L_E(tp_msg_login());
        }
        $user = function_exists('dcim_auth_user_by_token') ? dcim_auth_user_by_token($token) : null;
        if (!$user) {
            $user = (new CrudController('dcim-person'))->findOne([['token', '=', $token], ['status', '=', 1]]);
        }
        if (!$user) {
            L_E(tp_msg_login());
        }
        return $user;
    }

    private static function ok($data, $num = null)
    {
        app_json_response($data, null, 'ok');
    }

    private static function mapList(array $rows, callable $mapper): array
    {
        $list = [];
        foreach ($rows as $row) {
            $list[] = $mapper($row);
        }
        return $list;
    }

    private static function parseLegacyParamPayload(string $raw): array
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
        $decoded = json_decode(urldecode($raw), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $inner = $raw;
        if (strlen($inner) >= 2 && $inner[0] === '{' && substr($inner, -1) === '}') {
            $inner = trim(substr($inner, 1, -1));
        }
        if ($inner === '') {
            return [];
        }

        $pairs = [];
        foreach (preg_split('/[,\r\n;]+/', $inner) as $seg) {
            $seg = trim((string)$seg);
            if ($seg === '') {
                continue;
            }
            if (strpos($seg, ':') !== false) {
                [$k, $v] = explode(':', $seg, 2);
            } elseif (strpos($seg, '=') !== false) {
                [$k, $v] = explode('=', $seg, 2);
            } else {
                $pairs[] = $seg;
                continue;
            }
            $k = trim($k, " \t\n\r\0\x0B\"'");
            $v = trim($v, " \t\n\r\0\x0B\"'");
            if ($k !== '') {
                $pairs[$k] = $v;
            }
        }
        if (!empty($pairs) && array_keys($pairs) !== range(0, count($pairs) - 1)) {
            return $pairs;
        }

        if ($pairs && count($pairs) % 2 === 0) {
            $assoc = [];
            for ($i = 0; $i < count($pairs); $i += 2) {
                $k = trim((string)$pairs[$i], " \t\n\r\0\x0B\"'");
                $v = trim((string)$pairs[$i + 1], " \t\n\r\0\x0B\"'");
                if ($k !== '') {
                    $assoc[$k] = $v;
                }
            }
            if ($assoc) {
                return $assoc;
            }
        }

        return [];
    }

    private static function parseLegacyIdList($raw): array
    {
        $txt = trim((string)$raw);
        if ($txt === '') {
            return [];
        }
        if (ctype_digit($txt)) {
            return [$txt];
        }

        $ids = [];
        $decoded = self::parseLegacyParamPayload($txt);
        if (is_array($decoded)) {
            if (array_keys($decoded) === range(0, count($decoded) - 1)) {
                foreach ($decoded as $item) {
                    if (is_array($item) && isset($item['id']) && ctype_digit(trim((string)$item['id']))) {
                        $ids[] = trim((string)$item['id']);
                    } elseif (is_scalar($item) && ctype_digit(trim((string)$item))) {
                        $ids[] = trim((string)$item);
                    }
                }
            } else {
                foreach (['id', 'ParamId', 'paramId', 'PUEId'] as $k) {
                    if (isset($decoded[$k]) && ctype_digit(trim((string)$decoded[$k]))) {
                        $ids[] = trim((string)$decoded[$k]);
                    }
                }
            }
        }

        if (!$ids && preg_match_all('/"id"\s*:\s*"?(\\d+)"?/i', $txt, $m) && !empty($m[1])) {
            foreach ($m[1] as $idTxt) {
                $idTxt = trim((string)$idTxt);
                if ($idTxt !== '') {
                    $ids[] = $idTxt;
                }
            }
        }
        if (!$ids && preg_match_all('/\\b(\\d+)\\b/', $txt, $m) && !empty($m[1])) {
            foreach ($m[1] as $idTxt) {
                $idTxt = trim((string)$idTxt);
                if ($idTxt !== '') {
                    $ids[] = $idTxt;
                }
            }
        }

        $uniq = [];
        foreach ($ids as $idTxt) {
            if ($idTxt === '') {
                continue;
            }
            $uniq[(string)(int)$idTxt] = true;
        }
        return array_keys($uniq);
    }

    private static function collectAssetTypeIdsByRootKeywords(array $keywords): array
    {
        $typeCrud = new CrudController('dcim-assettype');
        $allTypes = $typeCrud->selectByRawCondition('status = 1', '', []);
        $children = [];
        $nameMap = [];
        foreach ($allTypes as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $pid = (int)($row['AssetsTypeParentId'] ?? 0);
            $name = (string)($row['AssetsTypeName'] ?? '');
            $nameMap[(string)$id] = $name;
            if (!isset($children[(string)$pid])) {
                $children[(string)$pid] = [];
            }
            $children[(string)$pid][] = $id;
        }

        $roots = [];
        foreach ($allTypes as $row) {
            $pid = (int)($row['AssetsTypeParentId'] ?? 0);
            if ($pid !== 0) {
                continue;
            }
            $name = (string)($row['AssetsTypeName'] ?? '');
            foreach ($keywords as $kw) {
                if ($kw !== '' && stripos($name, $kw) !== false) {
                    $roots[] = (int)($row['id'] ?? 0);
                    break;
                }
            }
        }
        $roots = array_values(array_unique(array_filter($roots)));
        if (!$roots) {
            return [[], $nameMap];
        }

        $queue = $roots;
        $result = [];
        while ($queue) {
            $cur = (int)array_shift($queue);
            if ($cur <= 0 || isset($result[(string)$cur])) {
                continue;
            }
            $result[(string)$cur] = true;
            foreach (($children[(string)$cur] ?? []) as $cid) {
                $queue[] = (int)$cid;
            }
        }
        return [array_map(static function ($v) {
            return (int)$v;
        }, array_keys($result)), $nameMap];
    }

    private static function cronTaskNumber(string $prefix): string
    {
        $code = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S'];
        $yearIndex = (int)date('Y') - 2011;
        $head = $code[$yearIndex] ?? 'A';
        $monthHex = strtoupper(dechex((int)date('m')));
        $num = $head . $monthHex . date('d') . substr((string)time(), -5) . substr((string)microtime(), 2, 5) . sprintf('%02d', rand(0, 99));
        return $prefix . $num;
    }

    private static function cronDateYmd(array $data): string
    {
        if (!empty($data['time'])) {
            $raw = $data['time'];
            if (is_numeric($raw)) {
                return date('Y-m-d', (int)$raw);
            }
            $ts = strtotime((string)$raw);
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }
        return date('Y-m-d');
    }

    private static function cronMatchSchedule(string $cycle, string $sendTime, string $dateYmd): bool
    {
        $sendTime = trim($sendTime);
        if ($sendTime === '') {
            return false;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $sendTime)), static function ($x) {
            return $x !== '';
        }));
        if (!$parts) {
            return false;
        }
        if ($cycle === 'year') {
            $target = date('m-d', strtotime($dateYmd));
            foreach ($parts as $part) {
                $tmp = explode('-', $part);
                if (count($tmp) < 2) {
                    continue;
                }
                $mm = str_pad((string)(int)$tmp[0], 2, '0', STR_PAD_LEFT);
                $dd = str_pad((string)(int)$tmp[1], 2, '0', STR_PAD_LEFT);
                if ($mm . '-' . $dd === $target) {
                    return true;
                }
            }
            return false;
        }
        if ($cycle === 'month') {
            $target = date('d', strtotime($dateYmd));
            foreach ($parts as $part) {
                if (str_pad((string)(int)$part, 2, '0', STR_PAD_LEFT) === $target) {
                    return true;
                }
            }
            return false;
        }
        if ($cycle === 'week') {
            $target = date('w', strtotime($dateYmd));
            foreach ($parts as $part) {
                if ((string)(int)$part === (string)(int)$target) {
                    return true;
                }
            }
            return false;
        }
        return false;
    }

    private static function cronInsertCheckPlan(string $dateYmd, array $plan): bool
    {
        $crud = new CrudController('dcim-assetcheckplan');
        $exists = $crud->selectByRawCondition(
            'status = 1 AND TaskName = :n AND create_time = :d AND system = -1',
            'LIMIT 1',
            [':n' => $plan['PlanName'] ?? '', ':d' => $dateYmd]
        );
        if ($exists) {
            return false;
        }
        $ctime = strtotime($dateYmd . ' 00:00:00') + ((int)($plan['ComplateDay'] ?? 0)) * 86400;
        $id = $crud->legacyInsert([
            'TaskNumber' => self::cronTaskNumber('PD'),
            'TaskName' => $plan['PlanName'] ?? '',
            'PlanComplateTime' => date('Y-m-d H:i:s', $ctime),
            'AssetsType' => $plan['AssetsType'] ?? '',
            'DoEmpId' => $plan['EmpId'] ?? 0,
            'CheckWay' => $plan['CheckWay'] ?? '',
            'CheckRange' => $plan['CheckRange'] ?? '',
            'CreateEmpId' => 1,
            'system' => -1,
            'status' => 1,
            'create_time' => $dateYmd,
        ]);
        return (bool)$id;
    }

    private static function cronInsertXJTask(string $dateYmd, array $model): bool
    {
        $crud = new CrudController('dcim-xjtask');
        $exists = $crud->selectByRawCondition(
            'status = 1 AND XJTaskName = :n AND create_time = :d AND system = -1',
            'LIMIT 1',
            [':n' => $model['XJModelName'] ?? '', ':d' => $dateYmd]
        );
        if ($exists) {
            return false;
        }
        $ctime = strtotime($dateYmd . ' 00:00:00') + ((int)($model['XJComplateDays'] ?? 0)) * 86400;
        $id = $crud->legacyInsert([
            'XJTaskNumber' => self::cronTaskNumber('XJ'),
            'XJTaskName' => $model['XJModelName'] ?? '',
            'XJModelId' => $model['id'] ?? 0,
            'XJPlanComplateTime' => date('Y-m-d H:i:s', $ctime),
            'XJEmpId' => $model['XJEmpId'] ?? 0,
            'CreateEmpId' => 1,
            'system' => -1,
            'status' => 1,
            'create_time' => $dateYmd,
        ]);
        return (bool)$id;
    }

    private static function cronInsertWHTask(string $dateYmd, array $plan): bool
    {
        $crud = new CrudController('dcim-whtask');
        $exists = $crud->selectByRawCondition(
            'status = 1 AND WHTaskName = :n AND create_time = :d AND system = -1',
            'LIMIT 1',
            [':n' => $plan['WHPlanName'] ?? '', ':d' => $dateYmd]
        );
        if ($exists) {
            return false;
        }
        $ctime = strtotime($dateYmd . ' 00:00:00') + ((int)($plan['WHComplateDays'] ?? 0)) * 86400;
        $id = $crud->legacyInsert([
            'WHTaskNumber' => self::cronTaskNumber('WH'),
            'WHTaskName' => $plan['WHPlanName'] ?? '',
            'WHTaskCon' => $plan['WHContent'] ?? '',
            'PlanComplateDate' => date('Y-m-d H:i:s', $ctime),
            'WHEmpId' => $plan['WHEmpId'] ?? 0,
            'CreateEmpId' => 1,
            'system' => -1,
            'status' => 1,
            'create_time' => $dateYmd,
        ]);
        return (bool)$id;
    }

    public static function GetAMSServerListKey()
    {
        $crud = new CrudController('dcim-server');
        $rows = $crud->selectByRawCondition('status = 1', 'ORDER BY id ASC', []);
        $list = self::mapList($rows, function ($row) {
            return [
                'serverCode' => $row['ServerCode'] ?? $row['serverCode'] ?? $row['id'] ?? 0,
                'serverIp' => $row['ServerIP'] ?? $row['ServerIp'] ?? $row['serverIp'] ?? '',
                'serverIpBak' => $row['ServerIpBak'] ?? $row['serverIpBak'] ?? '',
                'serverName' => $row['ServerName'] ?? $row['serverName'] ?? '',
                'serverStatus' => $row['ServerStatus'] ?? $row['serverStatus'] ?? '',
                'serverStatusBak' => $row['ServerStatusBak'] ?? $row['serverStatusBak'] ?? '',
            ];
        });
        app_json_response($list, '', 'ok');
    }

    public static function GetDeptKey()
    {
        $crud = new CrudController('dcim-department');
        $rows = $crud->selectByRawCondition('status = 1', 'ORDER BY id ASC', []);
        $list = self::mapList($rows, function ($row) {
            return [
                'DepartId' => $row['id'] ?? 0,
                'DepartName' => $row['DeptName'] ?? $row['DepartName'] ?? $row['DepartmentName'] ?? '',
            ];
        });
        app_json_response($list, null, 'ok');
    }

    public static function GetEmpKey()
    {
        $crud = new CrudController('dcim-person');
        $rows = $crud->selectByRawCondition('status = 1', 'ORDER BY id ASC', []);
        $list = self::mapList($rows, function ($row) {
            return [
                'EmpId' => $row['id'] ?? 0,
                'EmpName' => $row['PersonName'] ?? $row['EmpName'] ?? '',
            ];
        });
        app_json_response($list, null, 'ok');
    }

    public static function GetStoreLocationKey()
    {
        $crud = new CrudController('dcim-store');
        $rows = $crud->selectByRawCondition('status = 1', 'ORDER BY id ASC', []);
        $list = self::mapList($rows, function ($row) {
            return [
                'StoreId' => $row['id'] ?? 0,
                'InfoName' => $row['StoreName'] ?? $row['InfoName'] ?? '',
            ];
        });
        app_json_response($list, null, 'ok');
    }

    public static function GetFaultTypeKey()
    {
        $crud = new CrudController('dcim-faulttype');
        $rows = $crud->selectByRawCondition('status = 1', 'ORDER BY id ASC', []);
        $list = self::mapList($rows, function ($row) {
            return [
                'FaultTypeLsh' => $row['id'] ?? 0,
                'TypeName' => $row['FaultTypeName'] ?? $row['TypeName'] ?? '',
            ];
        });
        app_json_response($list, null, 'ok');
    }

    public static function GetCusParamListKey()
    {
        $crud = new CrudController('dcim-param');
        $rows = $crud->selectByRawCondition('status = 1', 'ORDER BY id ASC', []);
        app_json_response($rows, null, 'ok');
    }

    public static function GetMessageCountKey()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        self::ok(['alarm' => 0, 'event' => 0, 'todo' => 0], 0);
    }

    public static function GetXJQRTaskKey()
    {
        WorkOrderController::xjTaskGetList();
    }

    public static function GetPictureKey()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $imgBase64 = '';
        $type = strtolower((string)($data['type'] ?? ''));
        if ($type === 'xj') {
            $taskNo = (string)($data['ID'] ?? '');
            $paraId = $data['ParaId'] ?? '';
            if ($taskNo !== '' && $paraId !== '') {
                $taskCrud = new CrudController('dcim-xjtask');
                $task = $taskCrud->findOne([['XJTaskNumber', '=', $taskNo], ['status', '=', 1]]);
                if (!$task && ctype_digit($taskNo)) {
                    $task = $taskCrud->findOne([['id', '=', (int)$taskNo], ['status', '=', 1]]);
                }
                if ($task) {
                    $detail = (new CrudController('dcim-xjtaskdetail'))->findOne([
                        ['TaskId', '=', $task['id'] ?? 0],
                        ['id', '=', $paraId],
                        ['status', '=', 1],
                    ]);
                    $imgPath = (string)($detail['HisImg'] ?? '');
                    if ($imgPath !== '' && is_file($imgPath)) {
                        $raw = @file_get_contents($imgPath);
                        if ($raw !== false) {
                            $ext = strtolower((string)pathinfo($imgPath, PATHINFO_EXTENSION));
                            $mime = 'image/jpeg';
                            if ($ext === 'png') {
                                $mime = 'image/png';
                            } elseif ($ext === 'gif') {
                                $mime = 'image/gif';
                            }
                            $imgBase64 = 'data:' . $mime . ';base64,' . base64_encode($raw);
                        }
                    }
                }
            }
        }
        app_json_response($imgBase64, null, 'ok');
    }

    public static function GetAllCategoryAlarmCountKey()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $areaId = trim((string)($data['Zonesubno'] ?? ($data['AreaId'] ?? '')));
        $deviceRows = [];
        try {
            $where = 'status = 1';
            $params = [];
            if ($areaId !== '') {
                $where .= ' AND AreaId = :aid';
                $params[':aid'] = $areaId;
            }
            $deviceRows = self::dvCrud('dcim-device')->selectByRawCondition($where, '', $params);
        } catch (\Throwable $e) {
            O_E([], tp_msg_success(), 100, 0);
            return;
        }
        $classByDev = [];
        $classIds = [];
        $devIds = [];
        foreach ($deviceRows as $row) {
            $devId = trim((string)($row['id'] ?? ''));
            if ($devId === '') {
                continue;
            }
            $devIds[] = $devId;
            $classId = trim((string)($row['DeviceClass'] ?? ''));
            if ($classId !== '') {
                $classByDev[$devId] = $classId;
                $classIds[] = $classId;
            }
        }
        if (!$devIds || !$classIds) {
            O_E([], tp_msg_success(), 100, 0);
            return;
        }
        $classNameMap = [];
        foreach (self::dvCrud('dcim-deviceclass')->selectByIds(array_values(array_unique($classIds)), ['id', 'ClassName']) as $classRow) {
            $cid = trim((string)($classRow['id'] ?? ''));
            if ($cid !== '') {
                $classNameMap[$cid] = (string)($classRow['ClassName'] ?? '');
            }
        }
        $countByClass = [];
        $alarmRows = self::dvCrud('dcim-alarmlist')->selectByRawCondition('status = 1', '', []);
        foreach ($alarmRows as $alarmRow) {
            $devId = trim((string)($alarmRow['DevId'] ?? ($alarmRow['DevID'] ?? '')));
            if ($devId === '' || !isset($classByDev[$devId])) {
                continue;
            }
            $cid = $classByDev[$devId];
            $countByClass[$cid] = (int)($countByClass[$cid] ?? 0) + 1;
        }
        $result = [];
        foreach (array_values(array_unique($classIds)) as $cid) {
            $cid = trim((string)$cid);
            if ($cid === '') {
                continue;
            }
            $result[] = [
                'AlarmNum' => (int)($countByClass[$cid] ?? 0),
                'GroupId' => $cid,
                'GroupName' => $classNameMap[$cid] ?? '',
            ];
        }
        O_E($result, tp_msg_success(), 100, 0);
    }

    public static function GetGroupByZonesubnoKey()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $areaId = $data['Zonesubno'] ?? '';
        if ($areaId === '' || $areaId === null) {
            app_json_response([], null, 'ok');
            return;
        }
        $deviceCrud = new CrudController('dcim-device');
        $devices = $deviceCrud->selectByRawCondition(
            'status = 1 AND AreaId = :aid',
            '',
            [':aid' => $areaId]
        );
        $classIds = [];
        foreach ($devices as $device) {
            $cid = $device['DeviceClass'] ?? null;
            if ($cid !== null && $cid !== '') {
                $classIds[] = $cid;
            }
        }
        $classIds = array_values(array_unique($classIds));
        if (!$classIds) {
            app_json_response([], null, 'ok');
            return;
        }
        $classMap = [];
        $classCrud = new CrudController('dcim-deviceclass');
        foreach ($classCrud->selectByIds($classIds, ['id', 'ClassName']) as $row) {
            $classMap[(string)($row['id'] ?? '')] = $row['ClassName'] ?? '';
        }
        $list = [];
        foreach ($classIds as $classId) {
            $list[] = [
                'AlarmNum' => 0,
                'GroupId' => $classId,
                'GroupName' => $classMap[(string)$classId] ?? '',
            ];
        }
        app_json_response($list, null, 'ok');
    }

        public static function GetDeviceParasKey()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $deviceId = $data['DeviceId'] ?? '';
        if ($deviceId === '' || $deviceId === null) {
            app_json_response([], null, 'ok');
            return;
        }
        $cmdCrud = new CrudController('dcim-devicecommand');
        $commands = $cmdCrud->selectByRawCondition(
            'status = 1 AND DevID = :did AND (CommandType <> :ctype OR CommandType IS NULL)',
            '',
            [':did' => $deviceId, ':ctype' => '2']
        );
        $params = [];
        foreach ($commands as $cmd) {
            $payload = self::parseLegacyParamPayload((string)($cmd['LastReceiveData'] ?? ''));
            foreach ($payload as $k => $v) {
                $params[(string)$k] = $v;
            }
        }
        if (!$params) {
            app_json_response([], null, 'ok');
            return;
        }

        $alarmRows = (new CrudController('dcim-alarmlist'))->selectByRawCondition(
            'status = 1 AND DevId = :did',
            '',
            [':did' => $deviceId]
        );
        $typeIds = [];
        foreach ($alarmRows as $alarm) {
            if (!empty($alarm['AlarmType'])) {
                $typeIds[] = $alarm['AlarmType'];
            }
        }
        $typeMap = [];
        foreach ((new CrudController('dcim-alarmtype'))->selectByIds($typeIds, ['id', 'TypeName']) as $row) {
            $typeMap[(string)($row['id'] ?? '')] = (string)($row['TypeName'] ?? '');
        }
        $activeTypeNames = [];
        foreach ($alarmRows as $alarm) {
            $tid = (string)($alarm['AlarmType'] ?? '');
            if ($tid !== '' && isset($typeMap[$tid])) {
                $activeTypeNames[] = $typeMap[$tid];
            }
        }

        $list = [];
        foreach ($params as $name => $value) {
            $status = 0;
            $desc = dcim_msg('app.status_normal');
            foreach ($activeTypeNames as $typeName) {
                if ($typeName !== '' && stripos($typeName, $name) !== false) {
                    $status = 1;
                    $desc = $typeName;
                    break;
                }
            }
            $list[] = [
                'CurValue' => $value,
                'DataType' => dcim_msg('app.data_type_status'),
                'ParaId' => '',
                'ParaName' => $name,
                'Status' => $status,
                'Unit' => '',
                'ValueDescript' => $desc,
            ];
        }
        app_json_response($list, null, 'ok');
    }

    public static function GetDeviceControlKey()
    {
        $data = Flight::request_data();
        self::dvRequireAuth($data);
        $page = max((int)($data['pageNo'] ?? 1), 1);
        $pageSize = max((int)($data['pageSize'] ?? 15), 1);
        $conditions = ['status = 1', 'DevID IN (SELECT id FROM `dcim-device` WHERE status = 1)', 'CommandType = :ctype'];
        $params = [':ctype' => '2'];
        if (!empty($data['DevID'])) {
            $conditions[] = 'DevID = :did';
            $params[':did'] = (string)$data['DevID'];
        }
        if (!empty($data['Command'])) {
            $conditions[] = 'Command LIKE :cmd';
            $params[':cmd'] = '%' . (string)$data['Command'] . '%';
        }
        $result = self::dvCrud('dcim-devicecommand')->selectWithPagination(
            implode(' AND ', $conditions),
            $params,
            'ORDER BY id DESC',
            $page,
            $pageSize
        );
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        if ($rows) {
            $devIds = [];
            foreach ($rows as $row) {
                $did = trim((string)($row['DevID'] ?? ''));
                if ($did !== '') {
                    $devIds[$did] = true;
                }
            }
            $nameMap = [];
            foreach (self::dvCrud('dcim-device')->selectByIds(array_keys($devIds), ['id', 'DeviceName']) as $devRow) {
                $did = trim((string)($devRow['id'] ?? ''));
                if ($did !== '') {
                    $nameMap[$did] = (string)($devRow['DeviceName'] ?? '');
                }
            }
            foreach ($rows as &$row) {
                $row['DeviceName'] = $nameMap[(string)($row['DevID'] ?? '')] ?? ($row['DeviceName'] ?? '');
            }
            unset($row);
            $result['info'] = $rows;
        }
        O_E($result, tp_msg_success(), 100, 0);
    }

    public static function SendControlCommandKey()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        self::ok(true, 1);
    }

    public static function GetHistoricalControlALLKey()
    {
        $data = Flight::request_data();
        self::dvRequireAuth($data);
        $result = self::dvCrud('dcim-devcommondsendlist')->legacyList($data, [
            'base_where' => ['status = 1'],
            'exact_filters' => [
                'DevID' => 'DevID',
                'SendState' => 'SendState',
            ],
            'between_filters' => [
                ['field' => 'create_time', 'start_key' => 'startDateTime', 'end_key' => 'endDateTime'],
            ],
            'search_fields' => ['Command', 'ComDesc', 'RecvData', 'CreateEmpName', 'DeviceName', 'ip'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function GetITDeviceCountKey()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        [$typeIds, $typeNameMap] = self::collectAssetTypeIdsByRootKeywords(['IT']);
        if (!$typeIds) {
            app_json_response([], null, 'ok');
            return;
        }
        $typeSet = array_fill_keys(array_map('strval', $typeIds), true);

        $brandRows = (new CrudController('dcim-brandmodel'))->selectByRawCondition('status = 1', '', []);
        $modelToType = [];
        foreach ($brandRows as $row) {
            $mid = (string)($row['id'] ?? '');
            $tid = (string)($row['AssetsTypeId'] ?? '');
            if ($mid !== '' && $tid !== '') {
                $modelToType[$mid] = $tid;
            }
        }

        $countMap = [];
        $assets = (new CrudController('dcim-asset'))->selectByRawCondition('status = 1', '', []);
        foreach ($assets as $asset) {
            $mid = (string)($asset['ModelId'] ?? '');
            if ($mid === '' || !isset($modelToType[$mid])) {
                continue;
            }
            $tid = (string)$modelToType[$mid];
            if (!isset($typeSet[$tid])) {
                continue;
            }
            if (!isset($countMap[$tid])) {
                $countMap[$tid] = 0;
            }
            $countMap[$tid]++;
        }

        $list = [];
        foreach ($countMap as $tid => $count) {
            $list[] = [
                'ITDeviceType' => $typeNameMap[$tid] ?? '',
                'ITDeviceCount' => (int)$count,
            ];
        }
        app_json_response($list, null, 'ok');
    }

    public static function GetJCDeviceCountKey()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        [$typeIds, $typeNameMap] = self::collectAssetTypeIdsByRootKeywords([dcim_msg('app.infra_root_name'), 'infra']);
        if (!$typeIds) {
            app_json_response([], null, 'ok');
            return;
        }
        $typeSet = array_fill_keys(array_map('strval', $typeIds), true);

        $brandRows = (new CrudController('dcim-brandmodel'))->selectByRawCondition('status = 1', '', []);
        $modelToType = [];
        foreach ($brandRows as $row) {
            $mid = (string)($row['id'] ?? '');
            $tid = (string)($row['AssetsTypeId'] ?? '');
            if ($mid !== '' && $tid !== '') {
                $modelToType[$mid] = $tid;
            }
        }

        $countMap = [];
        $assets = (new CrudController('dcim-asset'))->selectByRawCondition('status = 1', '', []);
        foreach ($assets as $asset) {
            $mid = (string)($asset['ModelId'] ?? '');
            if ($mid === '' || !isset($modelToType[$mid])) {
                continue;
            }
            $tid = (string)$modelToType[$mid];
            if (!isset($typeSet[$tid])) {
                continue;
            }
            if (!isset($countMap[$tid])) {
                $countMap[$tid] = 0;
            }
            $countMap[$tid]++;
        }

        $list = [];
        foreach ($countMap as $tid => $count) {
            $list[] = [
                'JCDeviceType' => $typeNameMap[$tid] ?? '',
                'JCDeviceCount' => (int)$count,
            ];
        }
        app_json_response($list, null, 'ok');
    }

    public static function GetDBCenterKey()
    {
        $crud = new CrudController('dcim-server');
        $rows = $crud->selectByRawCondition('status = 1', 'ORDER BY id ASC', []);
        $list = self::mapList($rows, function ($row) {
            return [
                'CenterId' => $row['id'] ?? 0,
                'CenterName' => $row['ServerName'] ?? $row['CenterName'] ?? '',
            ];
        });
        app_json_response($list, null, 'ok');
    }

    public static function GetMotorRoomKey()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $centerId = $data['CenterId'] ?? '';
        if ($centerId === '' || $centerId === null) {
            app_json_response([], null, 'ok');
            return;
        }
        $rows = (new CrudController('dcim-area'))->selectByRawCondition(
            'status = 1 AND ServerCode = :sid',
            'ORDER BY id ASC',
            [':sid' => $centerId]
        );
        $list = self::mapList($rows, function ($row) {
            return [
                'RoomId' => (int)($row['id'] ?? 0),
                'RoomName' => $row['AreaName'] ?? '',
            ];
        });
        app_json_response($list, null, 'ok');
    }

    public static function GetCapacityReportKey()
    {
        $data = Flight::request_data();
        self::requireAuthStrict($data);
        self::ok([], false);
    }

    public static function GetHKAssetKey()
    {
        AssetsController::getList();
    }

    public static function dmpageInfoAdd()
    {
        $data = Flight::request_data();

        $id = self::dmpageCrud()->legacyCreate($data, [
            'skip_auth' => true,
            'required_fields' => [
                'PageName' => dcim_msg('error.page_name_required'),
            ],
        ]);

        O_E($id ? true : false, tp_msg_success(), 100, $id ? 1 : false);
    }

    public static function dmpageGetList()
    {
        $data = Flight::request_data();

        $rows = self::dmpageCrud()->legacySelectByFilters($data, [
            'skip_auth' => true,
            'base_where' => ['(status <> -1 OR status IS NULL)'],
            'order_by' => 'ORDER BY PageIndex DESC, id ASC',
            'search_fields' => ['PageName', 'PageTxt', 'PageType'],
        ]);

        if (!is_array($rows)) {
            $rows = [];
        }

        $items = self::dmpageNormalizeMenuRows($rows);
        $tree = self::dmpageBuildMenuTree($items, 0, 1, []);

        json_string_response([
            'code' => 100,
            'msg' => dcim_msg('common.success'),
            'data' => array_values($tree),
            'num' => 0,
        ]);
    }

    private static function dmpageNormalizeMenuRows(array $rows): array
    {
        $normalized = [];
        $idSet = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $idSet[(string)$id] = true;
            $normalized[] = [
                'id' => $id,
                'PageName' => (string)($row['PageName'] ?? ''),
                'PageIndex' => (int)($row['PageIndex'] ?? 0),
                'pid' => (int)($row['pid'] ?? 0),
                'PageType' => (int)($row['PageType'] ?? 0),
                'ProId' => isset($row['ProId']) ? (string)$row['ProId'] : '',
                'PageTxt' => isset($row['PageTxt']) ? (string)$row['PageTxt'] : '',
                'PageTop' => (int)($row['PageTop'] ?? -1),
                'status' => (int)($row['status'] ?? 1),
                'create_time' => isset($row['create_time']) ? (string)$row['create_time'] : '',
                'update_time' => isset($row['update_time']) ? (string)$row['update_time'] : '',
                'level' => 1,
                'children' => [],
            ];
        }

        foreach ($normalized as &$item) {
            $pid = (int)($item['pid'] ?? 0);
            if ($pid > 0 && !isset($idSet[(string)$pid])) {
                $item['pid'] = 0;
            }
        }
        unset($item);

        return $normalized;
    }

    private static function dmpageBuildMenuTree(array $items, int $pid = 0, int $level = 1, array $path = []): array
    {
        $tree = [];
        foreach ($items as $item) {
            if ((int)($item['pid'] ?? 0) !== $pid) {
                continue;
            }
            $id = (int)($item['id'] ?? 0);
            if ($id > 0 && isset($path[(string)$id])) {
                // Prevent accidental loops caused by dirty data.
                continue;
            }
            $item['level'] = $level;
            $nextPath = $path;
            if ($id > 0) {
                $nextPath[(string)$id] = true;
            }
            $item['children'] = self::dmpageBuildMenuTree($items, $id, $level + 1, $nextPath);
            $tree[] = $item;
        }

        usort($tree, static function (array $a, array $b): int {
            $aIndex = (int)($a['PageIndex'] ?? 0);
            $bIndex = (int)($b['PageIndex'] ?? 0);
            if ($aIndex === $bIndex) {
                return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
            }
            return $bIndex <=> $aIndex;
        });

        return $tree;
    }

    public static function dmpageGetInfo()
    {
        $data = Flight::request_data();
        $info = self::dmpageCrud()->legacyInfo($data, [
            'skip_auth' => true,
            'extra_conditions' => [
                ['status', '<>', -1],
            ],
        ]);
        if (!$info && !empty($data['id'])) {
            try {
                $rows = self::dmpageCrud()->selectByRawCondition('id = :id', 'LIMIT 1', [':id' => $data['id']]);
                foreach ($rows as $row) {
                    $st = (string)($row['status'] ?? '');
                    if ($st === '' || $st !== '-1') {
                        $info = $row;
                        break;
                    }
                }
            } catch (\Throwable $ignore) {
            }
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function dmpageInfoUpdate()
    {
        $data = Flight::request_data();

        $res = self::dmpageCrud()->legacyUpdate($data, [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('common.id_required'),
        ]);

        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function dmpageInfoDel()
    {
        $data = Flight::request_data();

        $res = self::dmpageCrud()->legacySoftDelete($data, [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);

        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function dmpageMyMenus()
    {
        $data = Flight::request_data();
        $rows = self::dmpageCrud()->legacySelectByFilters($data, [
            'skip_auth' => true,
            'base_where' => ['(status <> -1 OR status IS NULL)'],
            'order_by' => 'ORDER BY PageIndex DESC, id ASC',
            'search_fields' => ['PageName', 'PageTxt', 'PageType'],
        ]);

        if (!is_array($rows)) {
            $rows = [];
        }
        $items = self::dmpageNormalizeMenuRows($rows);
        $tree = self::dmpageBuildMenuTree($items, 0, 1, []);

        O_E(array_values($tree), tp_msg_success(), 100, 0);
    }

    public static function dmpageGetAlarm()
    {
        TableConfigController::getList(true);
    }

    public static function dmpageGetEvent()
    {
        self::getOperationRecordList(true);
    }

    public static function dmpageGetDevCommand()
    {
        self::deviceCommandGetList(true);
    }

    private static function statsCrud(string $table): CrudController
    {
        return new CrudController($table);
    }

    private static function statsIsDmDriver(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $driver = strtolower((string)Flight::db()->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            $driver = '';
        }
        $cached = ($driver === 'dm');
        return $cached;
    }

    private static function statsQuoteIdent(string $name): string
    {
        $clean = str_replace(['`', '"'], '', trim($name));
        if ($clean === '') {
            return $clean;
        }
        if (self::statsIsDmDriver()) {
            return '"' . str_replace('"', '""', $clean) . '"';
        }
        return '`' . $clean . '`';
    }

    private static function statsPickColumn(array $cols, array $candidates): string
    {
        if (!$cols || !$candidates) {
            return '';
        }
        $direct = [];
        $normalized = [];
        foreach ($cols as $field => $_flag) {
            $name = trim((string)$field);
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

    private static function statsOk($data = [], $num = false): void
    {
        app_json_response($data, null, 'ok');
    }

    private static function statsCount(string $table, string $whereSql, array $params = []): int
    {
        try {
            $sql = 'SELECT COUNT(*) AS c FROM ' . self::statsQuoteIdent($table) . ' WHERE ' . $whereSql;
            $stmt = Flight::db()->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return (int)($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function statsRecentRows(string $table, int $limit = 50): array
    {
        $cols = self::statsTableColumns($table);
        if (!$cols) {
            return [];
        }
        $where = isset($cols['status']) ? '(status <> -1 OR status IS NULL)' : '1=1';
        $orderField = self::statsPickColumn($cols, ['create_time', 'CreateTime', 'update_time', 'id']);
        $orderBy = $orderField !== '' ? ('ORDER BY ' . self::statsQuoteIdent($orderField) . ' DESC') : '';
        if ($limit > 0) {
            $orderBy .= ($orderBy !== '' ? ' ' : '') . 'LIMIT ' . max(1, min($limit, 500));
        }
        try {
            return self::statsCrud($table)->selectByRawCondition($where, $orderBy, []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function statsTableColumns(string $table): array
    {
        static $cache = [];
        $safeTable = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string)$table));
        if ($safeTable === '') {
            return [];
        }
        $cacheKey = (self::statsIsDmDriver() ? 'dm|' : 'mysql|') . $safeTable;
        if (isset($cache[$cacheKey]) && is_array($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        $cols = [];
        try {
            if (self::statsIsDmDriver()) {
                $stmt = Flight::db()->prepare('SELECT COLUMN_NAME FROM USER_TAB_COLUMNS WHERE UPPER(TABLE_NAME)=UPPER(:table) ORDER BY COLUMN_ID');
                $stmt->bindValue(':table', $safeTable);
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $field = trim((string)($row['COLUMN_NAME'] ?? ''));
                    if ($field !== '') {
                        $cols[$field] = true;
                    }
                }
                if (!$cols) {
                    $stmt = Flight::db()->prepare('SELECT COLUMN_NAME FROM ALL_TAB_COLUMNS WHERE UPPER(TABLE_NAME)=UPPER(:table) ORDER BY COLUMN_ID');
                    $stmt->bindValue(':table', $safeTable);
                    $stmt->execute();
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                        $field = trim((string)($row['COLUMN_NAME'] ?? ''));
                        if ($field !== '') {
                            $cols[$field] = true;
                        }
                    }
                }
            } else {
                $stmt = Flight::db()->prepare('SHOW COLUMNS FROM ' . self::statsQuoteIdent($safeTable));
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $field = trim((string)($row['Field'] ?? ''));
                    if ($field !== '') {
                        $cols[$field] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            $cols = [];
        }
        if (!$cols) {
            try {
                $stmt = Flight::db()->prepare('SHOW COLUMNS FROM ' . self::statsQuoteIdent($safeTable));
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $field = trim((string)($row['Field'] ?? ''));
                    if ($field !== '') {
                        $cols[$field] = true;
                    }
                }
            } catch (\Throwable $e) {
                $cols = [];
            }
        }
        $cache[$cacheKey] = $cols;
        return $cols;
    }

    private static function statsHasCols(array $cols, array $required): bool
    {
        foreach ($required as $field) {
            if (!isset($cols[$field])) {
                return false;
            }
        }
        return true;
    }

    private static function statsActivePrefix(string $table): string
    {
        $cols = self::statsTableColumns($table);
        $statusField = self::statsPickColumn($cols, ['status']);
        if ($statusField === '') {
            return '';
        }
        $q = self::statsQuoteIdent($statusField);
        return '(' . $q . ' <> -1 OR ' . $q . ' IS NULL) AND ';
    }

    public static function statsIndexAlarmStatistic()
    {
        $data = Flight::request_data();
        self::requireAuthStrict($data);

        $fixedLevelNames = [
            dcim_msg('app.alarm_level_1'),
            dcim_msg('app.alarm_level_2'),
            dcim_msg('app.alarm_level_3'),
            dcim_msg('app.alarm_level_4'),
            dcim_msg('app.alarm_level_5'),
        ];
        $resultByName = [];
        foreach ($fixedLevelNames as $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $resultByName[$name] = [
                'LevelName' => $name,
                'AlarmCount' => '0',
            ];
        }

        $alarmTableCols = self::statsTableColumns('dcim-alarmlist');
        $levelTableCols = self::statsTableColumns('dcim-alarmlevellist');
        $alarmLevelField = self::statsPickColumn($alarmTableCols, ['AlarmLevel', 'alarmlevel']);
        $alarmStatusField = self::statsPickColumn($alarmTableCols, ['status']);
        $alarmBizStatusField = self::statsPickColumn($alarmTableCols, ['AlarmStatus', 'alarmstatus']);
        $levelIdField = self::statsPickColumn($levelTableCols, ['id']);
        $levelNameField = self::statsPickColumn($levelTableCols, ['LevelName', 'levelname', 'Name']);
        $levelNameById = [];
        if ($levelIdField !== '' && $levelNameField !== '') {
            try {
                $levelRows = self::statsCrud('dcim-alarmlevellist')->selectByRawCondition('1=1', '', []);
                foreach ($levelRows as $lr) {
                    $lid = trim((string)($lr[$levelIdField] ?? ''));
                    $lname = trim((string)($lr[$levelNameField] ?? ''));
                    if ($lid !== '' && $lname !== '') {
                        $levelNameById[$lid] = $lname;
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        if ($alarmLevelField === '' || $alarmStatusField === '' || $alarmBizStatusField === '' || $levelIdField === '' || $levelNameField === '') {
            O_E(array_values($resultByName), tp_msg_success(), 100, 0);
            return;
        }

        try {
            $sql = 'SELECT lel.' . self::statsQuoteIdent($levelNameField) . ' AS LevelName, COUNT(*) AS AlarmCount'
                . ' FROM ' . self::statsQuoteIdent('dcim-alarmlist') . ' a'
                . ' INNER JOIN ' . self::statsQuoteIdent('dcim-alarmlevellist') . ' lel'
                . ' ON a.' . self::statsQuoteIdent($alarmLevelField) . ' = lel.' . self::statsQuoteIdent($levelIdField)
                . ' WHERE (a.' . self::statsQuoteIdent($alarmStatusField) . ' <> -1 OR a.' . self::statsQuoteIdent($alarmStatusField) . ' IS NULL)'
                . ' GROUP BY a.' . self::statsQuoteIdent($alarmLevelField) . ', lel.' . self::statsQuoteIdent($levelNameField)
                . ' ORDER BY a.' . self::statsQuoteIdent($alarmLevelField);
            $stmt = Flight::db()->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $levelName = trim((string)($row['LevelName'] ?? ''));
                if ($levelName === '' || !isset($resultByName[$levelName])) {
                    continue;
                }
                $resultByName[$levelName]['AlarmCount'] = (string)((int)($row['AlarmCount'] ?? 0));
            }
        } catch (\Throwable $e) {
            // Keep 0-count output on query failure.
        }

        $allZero = true;
        foreach ($resultByName as $it) {
            if ((int)($it['AlarmCount'] ?? 0) > 0) {
                $allZero = false;
                break;
            }
        }
        if ($allZero) {
            try {
                $sql = 'SELECT a.' . self::statsQuoteIdent($alarmLevelField) . ' AS AlarmLevel, COUNT(*) AS AlarmCount'
                    . ' FROM ' . self::statsQuoteIdent('dcim-alarmlist') . ' a'
                    . ' WHERE (a.' . self::statsQuoteIdent($alarmStatusField) . ' <> -1 OR a.' . self::statsQuoteIdent($alarmStatusField) . ' IS NULL)'
                    . ' GROUP BY a.' . self::statsQuoteIdent($alarmLevelField);
                $stmt = Flight::db()->prepare($sql);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $levelId = trim((string)($row['AlarmLevel'] ?? ''));
                    $countVal = (string)((int)($row['AlarmCount'] ?? 0));
                    if ($levelId === '') {
                        continue;
                    }
                    if (ctype_digit($levelId)) {
                        $idx = (int)$levelId;
                        if ($idx >= 1 && $idx <= 5) {
                            $name = trim((string)$fixedLevelNames[$idx - 1]);
                            if ($name !== '' && isset($resultByName[$name])) {
                                $resultByName[$name]['AlarmCount'] = $countVal;
                                continue;
                            }
                        }
                        if ($idx >= 0 && $idx <= 4) {
                            $name = trim((string)$fixedLevelNames[$idx]);
                            if ($name !== '' && isset($resultByName[$name])) {
                                $resultByName[$name]['AlarmCount'] = $countVal;
                                continue;
                            }
                        }
                    }
                    $mappedName = $levelNameById[$levelId] ?? '';
                    if ($mappedName !== '' && isset($resultByName[$mappedName])) {
                        $resultByName[$mappedName]['AlarmCount'] = $countVal;
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        $allZero = true;
        foreach ($resultByName as $it) {
            if ((int)($it['AlarmCount'] ?? 0) > 0) {
                $allZero = false;
                break;
            }
        }
        if ($allZero) {
            try {
                $rows = self::statsCrud('dcim-alarmlist')->selectByRawCondition('1=1', '', []);
                foreach ($rows as $row) {
                    $st = trim((string)($row[$alarmStatusField] ?? ''));
                    if ($st === '-1') {
                        continue;
                    }
                    $biz = trim((string)($row[$alarmBizStatusField] ?? ''));
                    if ($biz === '' || $biz === '0') {
                        continue;
                    }
                    $levelId = trim((string)($row[$alarmLevelField] ?? ''));
                    if ($levelId === '') {
                        continue;
                    }
                    $name = '';
                    if (ctype_digit($levelId)) {
                        $idx = (int)$levelId;
                        if ($idx >= 1 && $idx <= 5) {
                            $name = trim((string)$fixedLevelNames[$idx - 1]);
                        }
                        if ($name === '' && $idx >= 0 && $idx <= 4) {
                            $name = trim((string)$fixedLevelNames[$idx]);
                        }
                    }
                    if ($name === '' && isset($levelNameById[$levelId])) {
                        $name = trim((string)$levelNameById[$levelId]);
                    }
                    if ($name !== '' && isset($resultByName[$name])) {
                        $resultByName[$name]['AlarmCount'] = (string)(((int)$resultByName[$name]['AlarmCount']) + 1);
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        $out = [];
        foreach ($fixedLevelNames as $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $out[] = $resultByName[$name] ?? ['LevelName' => $name, 'AlarmCount' => '0'];
        }
        O_E($out, tp_msg_success(), 100, 0);
    }

    public static function statsIndexMsgStatistic()
    {
        self::requireAuthStrict(Flight::request_data());
        $fixedMsgTypes = [
            dcim_msg('app.asset_msg_type_scrap_due'),
            dcim_msg('app.asset_msg_type_warranty_due'),
            dcim_msg('app.asset_msg_type_maintenance_due'),
            dcim_msg('app.asset_msg_type_overdue_not_returned'),
            dcim_msg('app.asset_msg_type_online_abnormal'),
        ];

        $msgTypeMap = [];
        $assetMsgCols = self::statsTableColumns('dcim-assetmsg');
        $msgTypeField = self::statsPickColumn($assetMsgCols, ['MsgType']);
        $statusField = self::statsPickColumn($assetMsgCols, ['status']);
        $idField = self::statsPickColumn($assetMsgCols, ['id']);
        if ($msgTypeField !== '') {
            $where = [];
            if ($statusField !== '') {
                $where[] = self::statsQuoteIdent($statusField) . ' = 1';
            }
            $whereSql = $where ? implode(' AND ', $where) : '1=1';
            $cntExpr = $idField !== '' ? self::statsQuoteIdent($idField) : '*';
            $sql = 'SELECT ' . self::statsQuoteIdent($msgTypeField) . ' AS MsgType, COUNT(' . $cntExpr . ') AS MsgNumber'
                . ' FROM ' . self::statsQuoteIdent('dcim-assetmsg')
                . ' WHERE ' . $whereSql
                . ' GROUP BY ' . self::statsQuoteIdent($msgTypeField);
            try {
                $stmt = Flight::db()->prepare($sql);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $k = trim((string)($row['MsgType'] ?? ''));
                    if ($k === '') {
                        continue;
                    }
                    $msgTypeMap[$k] = (int)($row['MsgNumber'] ?? 0);
                }
            } catch (\Throwable $e) {
            }
        }

        $out = [];
        foreach ($fixedMsgTypes as $msgType) {
            $msgType = (string)$msgType;
            if ($msgType === '') {
                continue;
            }
            $out[] = [
                'MsgType' => $msgType,
                'MsgNumber' => (int)($msgTypeMap[$msgType] ?? 0),
            ];
        }
        O_E($out, tp_msg_success(), 100, 0);
    }

    public static function statsPlatStatistics()
    {
        $data = Flight::request_data();
        self::requireAuthStrict($data);
        $normalizeVals = static function (array $vals): array {
            $out = [];
            foreach ($vals as $v) {
                $s = trim((string)$v);
                if ($s === '') {
                    continue;
                }
                $out[$s] = true;
            }
            return array_keys($out);
        };
        $buildWhereStatusEq1 = static function (string $table, array $fieldCandidates, array $values, string $paramPrefix) use ($normalizeVals): array {
            $cols = self::statsTableColumns($table);
            $statusField = self::statsPickColumn($cols, ['status']);
            $targetField = self::statsPickColumn($cols, $fieldCandidates);
            if ($targetField === '') {
                return ['1=0', []];
            }
            $values = $normalizeVals($values);
            if (!$values) {
                return ['1=0', []];
            }
            $holders = [];
            $params = [];
            foreach ($values as $idx => $value) {
                $ph = ':' . $paramPrefix . '_' . $idx;
                $holders[] = $ph;
                $params[$ph] = $value;
            }
            $where = self::statsQuoteIdent($targetField) . ' IN (' . implode(', ', $holders) . ')';
            if ($statusField !== '') {
                $qStatusField = self::statsQuoteIdent($statusField);
                $where = '(' . $qStatusField . ' <> -1 OR ' . $qStatusField . ' IS NULL) AND ' . $where;
            }
            return [$where, $params];
        };

        [$orderNeedDoWhere, $orderNeedDoParams] = $buildWhereStatusEq1('dcim-order', ['OrderStatus'], [
            dcim_msg('app.order_need_todo_1'),
            dcim_msg('app.order_need_todo_2'),
            dcim_msg('app.order_need_todo_3'),
            'pending', 'todo', 'to_do', 'new', 'wait', 'waiting',
        ], 'orderNeedDo');
        [$orderDealingWhere, $orderDealingParams] = $buildWhereStatusEq1('dcim-order', ['OrderStatus'], [
            dcim_msg('app.order_processing_1'),
            dcim_msg('app.order_processing_2'),
            dcim_msg('workorder.order_status_processing'),
            'processing', 'dealing', 'in_progress', 'doing',
        ], 'orderDealing');
        [$orderNeedCheckWhere, $orderNeedCheckParams] = $buildWhereStatusEq1('dcim-order', ['OrderStatus'], [
            dcim_msg('app.order_need_check_1'),
            dcim_msg('app.order_need_check_2'),
            'check', 'checking', 'to_check', 'need_check', 'pending_check',
        ], 'orderNeedCheck');
        [$xjNeedWhere, $xjNeedParams] = $buildWhereStatusEq1('dcim-xjtask', ['XJStatus'], [
            dcim_msg('app.task_pending_1'),
            dcim_msg('app.task_pending_2'),
            'pending', 'todo', 'to_do',
        ], 'xjNeed');
        [$xjOverdueWhere, $xjOverdueParams] = $buildWhereStatusEq1('dcim-xjtask', ['XJStatus'], [
            dcim_msg('app.task_overdue'),
            'overdue',
        ], 'xjOverdue');
        [$whNeedWhere, $whNeedParams] = $buildWhereStatusEq1('dcim-whtask', ['WHStatus'], [
            dcim_msg('app.task_pending_1'),
            dcim_msg('app.task_pending_2'),
            dcim_msg('workorder.wh_status_pending'),
            'pending', 'todo', 'to_do',
        ], 'whNeed');
        [$whOverdueWhere, $whOverdueParams] = $buildWhereStatusEq1('dcim-whtask', ['WHStatus'], [
            dcim_msg('app.task_overdue'),
            'overdue',
        ], 'whOverdue');
        [$repairNeedWhere, $repairNeedParams] = $buildWhereStatusEq1('dcim-assetrepair', ['RepairStatus'], [
            'false',
            dcim_msg('app.repair_pending'),
            'pending',
        ], 'repairNeed');
        [$planNeedWhere, $planNeedParams] = $buildWhereStatusEq1('dcim-assetcheckplan', ['PlanStatus'], [
            dcim_msg('app.task_pending_1'),
            dcim_msg('crud.plan_status_pending'),
            dcim_msg('app.checkplan_pending'),
            'pending', 'todo', 'to_do',
        ], 'planNeed');

        $payload = [
            'OrderNeedDo' => self::statsCount('dcim-order', $orderNeedDoWhere, $orderNeedDoParams),
            'OrderDealing' => self::statsCount('dcim-order', $orderDealingWhere, $orderDealingParams),
            'OrderNeedCheck' => self::statsCount('dcim-order', $orderNeedCheckWhere, $orderNeedCheckParams),
            'XJNeedCheck' => self::statsCount('dcim-xjtask', $xjNeedWhere, $xjNeedParams),
            'XJOverdue' => self::statsCount('dcim-xjtask', $xjOverdueWhere, $xjOverdueParams),
            'WHNeedCheck' => self::statsCount('dcim-whtask', $whNeedWhere, $whNeedParams),
            'WHOverdue' => self::statsCount('dcim-whtask', $whOverdueWhere, $whOverdueParams),
            'RepairNeedCheck' => self::statsCount('dcim-assetrepair', $repairNeedWhere, $repairNeedParams),
            'PlanNeedCheck' => self::statsCount('dcim-assetcheckplan', $planNeedWhere, $planNeedParams),
        ];

        $allZero = true;
        foreach ($payload as $v) {
            if ((int)$v > 0) {
                $allZero = false;
                break;
            }
        }
        if ($allZero) {
            $now = date('Y-m-d H:i:s');
            $statusEq1Prefix = static function (string $table): string {
                $cols = self::statsTableColumns($table);
                $statusField = self::statsPickColumn($cols, ['status']);
                if ($statusField === '') {
                    return '';
                }
                return self::statsQuoteIdent($statusField) . ' = 1 AND ';
            };

            $orderCols = self::statsTableColumns('dcim-order');
            $receiveField = self::statsPickColumn($orderCols, ['ReceiveTime']);
            $dealField = self::statsPickColumn($orderCols, ['DealTime']);
            $checkField = self::statsPickColumn($orderCols, ['CheckTime']);
            if ($receiveField !== '' && $dealField !== '' && $checkField !== '') {
                $prefix = $statusEq1Prefix('dcim-order');
                $payload['OrderNeedDo'] = self::statsCount('dcim-order', $prefix . '(COALESCE(' . self::statsQuoteIdent($receiveField) . ", '') = '' AND COALESCE(" . self::statsQuoteIdent($dealField) . ", '') = '' AND COALESCE(" . self::statsQuoteIdent($checkField) . ", '') = '')");
                $payload['OrderDealing'] = self::statsCount('dcim-order', $prefix . '(COALESCE(' . self::statsQuoteIdent($receiveField) . ", '') <> '' AND COALESCE(" . self::statsQuoteIdent($dealField) . ", '') = '' AND COALESCE(" . self::statsQuoteIdent($checkField) . ", '') = '')");
                $payload['OrderNeedCheck'] = self::statsCount('dcim-order', $prefix . '(COALESCE(' . self::statsQuoteIdent($dealField) . ", '') <> '' AND COALESCE(" . self::statsQuoteIdent($checkField) . ", '') = '')");
            }

            $xjCols = self::statsTableColumns('dcim-xjtask');
            $xjEndField = self::statsPickColumn($xjCols, ['EndTime']);
            $xjPlanField = self::statsPickColumn($xjCols, ['XJPlanComplateTime']);
            if ($xjEndField !== '') {
                $prefix = $statusEq1Prefix('dcim-xjtask');
                $payload['XJNeedCheck'] = self::statsCount('dcim-xjtask', $prefix . "COALESCE(" . self::statsQuoteIdent($xjEndField) . ", '') = ''");
                if ($xjPlanField !== '') {
                    $payload['XJOverdue'] = self::statsCount('dcim-xjtask', $prefix . '(COALESCE(' . self::statsQuoteIdent($xjPlanField) . ", '') <> '' AND " . self::statsQuoteIdent($xjPlanField) . " < :xj_now AND COALESCE(" . self::statsQuoteIdent($xjEndField) . ", '') = '')", [':xj_now' => $now]);
                }
            }

            $whCols = self::statsTableColumns('dcim-whtask');
            $whEndField = self::statsPickColumn($whCols, ['EndTime']);
            $whPlanField = self::statsPickColumn($whCols, ['PlanComplateDate']);
            if ($whEndField !== '') {
                $prefix = $statusEq1Prefix('dcim-whtask');
                $payload['WHNeedCheck'] = self::statsCount('dcim-whtask', $prefix . "COALESCE(" . self::statsQuoteIdent($whEndField) . ", '') = ''");
                if ($whPlanField !== '') {
                    $payload['WHOverdue'] = self::statsCount('dcim-whtask', $prefix . '(COALESCE(' . self::statsQuoteIdent($whPlanField) . ", '') <> '' AND " . self::statsQuoteIdent($whPlanField) . " < :wh_now AND COALESCE(" . self::statsQuoteIdent($whEndField) . ", '') = '')", [':wh_now' => $now]);
                }
            }

            $repairCols = self::statsTableColumns('dcim-assetrepair');
            $repairFinishField = self::statsPickColumn($repairCols, ['FinishTime', 'EndTime']);
            if ($repairFinishField !== '') {
                $prefix = $statusEq1Prefix('dcim-assetrepair');
                $payload['RepairNeedCheck'] = self::statsCount('dcim-assetrepair', $prefix . "COALESCE(" . self::statsQuoteIdent($repairFinishField) . ", '') = ''");
            }

            $planCols = self::statsTableColumns('dcim-assetcheckplan');
            $planEndField = self::statsPickColumn($planCols, ['CheckEndTime', 'EndTime']);
            if ($planEndField !== '') {
                $prefix = $statusEq1Prefix('dcim-assetcheckplan');
                $payload['PlanNeedCheck'] = self::statsCount('dcim-assetcheckplan', $prefix . "COALESCE(" . self::statsQuoteIdent($planEndField) . ", '') = ''");
            }
        }
        $allZero = true;
        foreach ($payload as $v) {
            if ((int)$v > 0) {
                $allZero = false;
                break;
            }
        }
        if ($allZero) {
            $isEmpty = static function ($v): bool {
                return trim((string)$v) === '';
            };
            $toTs = static function ($v): int {
                $ts = strtotime(trim((string)$v));
                return $ts === false ? 0 : $ts;
            };
            $nowTs = time();

            try {
                $rows = self::statsCrud('dcim-order')->selectByRawCondition('1=1', '', []);
                foreach ($rows as $row) {
                    if ((string)($row['status'] ?? '') === '-1') {
                        continue;
                    }
                    $receive = $row['ReceiveTime'] ?? '';
                    $deal = $row['DealTime'] ?? '';
                    $check = $row['CheckTime'] ?? '';
                    if ($isEmpty($receive) && $isEmpty($deal) && $isEmpty($check)) {
                        $payload['OrderNeedDo']++;
                    } elseif (!$isEmpty($receive) && $isEmpty($deal) && $isEmpty($check)) {
                        $payload['OrderDealing']++;
                    } elseif (!$isEmpty($deal) && $isEmpty($check)) {
                        $payload['OrderNeedCheck']++;
                    }
                }
            } catch (\Throwable $e) {
            }
            try {
                $rows = self::statsCrud('dcim-xjtask')->selectByRawCondition('1=1', '', []);
                foreach ($rows as $row) {
                    if ((string)($row['status'] ?? '') === '-1') {
                        continue;
                    }
                    $end = $row['EndTime'] ?? '';
                    if ($isEmpty($end)) {
                        $payload['XJNeedCheck']++;
                        $planTs = $toTs($row['XJPlanComplateTime'] ?? '');
                        if ($planTs > 0 && $planTs < $nowTs) {
                            $payload['XJOverdue']++;
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
            try {
                $rows = self::statsCrud('dcim-whtask')->selectByRawCondition('1=1', '', []);
                foreach ($rows as $row) {
                    if ((string)($row['status'] ?? '') === '-1') {
                        continue;
                    }
                    $end = $row['EndTime'] ?? '';
                    if ($isEmpty($end)) {
                        $payload['WHNeedCheck']++;
                        $planTs = $toTs($row['PlanComplateDate'] ?? '');
                        if ($planTs > 0 && $planTs < $nowTs) {
                            $payload['WHOverdue']++;
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
            try {
                $rows = self::statsCrud('dcim-assetrepair')->selectByRawCondition('1=1', '', []);
                foreach ($rows as $row) {
                    if ((string)($row['status'] ?? '') === '-1') {
                        continue;
                    }
                    $finish = trim((string)($row['FinishTime'] ?? ($row['EndTime'] ?? '')));
                    if ($finish === '') {
                        $payload['RepairNeedCheck']++;
                    }
                }
            } catch (\Throwable $e) {
            }
            try {
                $rows = self::statsCrud('dcim-assetcheckplan')->selectByRawCondition('1=1', '', []);
                foreach ($rows as $row) {
                    if ((string)($row['status'] ?? '') === '-1') {
                        continue;
                    }
                    $end = trim((string)($row['CheckEndTime'] ?? ($row['EndTime'] ?? '')));
                    if ($end === '') {
                        $payload['PlanNeedCheck']++;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        O_E($payload, tp_msg_success(), 100, 0);
    }

    public static function statsGetYWStatistic()
    {
        $data = Flight::request_data();
        self::requireAuthStrict($data);
        $persons = [];
        $orderMap = [];
        $xjMap = [];
        $whMap = [];
        $start = isset($data['startDateTime']) ? trim((string) $data['startDateTime']) : '';
        $end = isset($data['endDateTime']) ? trim((string) $data['endDateTime']) : '';

        try {
            $persons = self::statsCrud('dcim-person')->selectByRawCondition('status = 1', 'ORDER BY id ASC');

            $orderWhere = 'status = 1 AND OrderStatus <> :orderPending';
            $xjWhere = 'status = 1 AND XJStatus <> :xjPending';
            $whWhere = 'status = 1 AND WHStatus <> :whPending';
            $orderParams = [':orderPending' => 'pending'];
            $xjParams = [':xjPending' => 'pending'];
            $whParams = [':whPending' => 'pending'];
            if ($start !== '' && $end !== '') {
                $orderWhere .= ' AND create_time BETWEEN :start AND :end';
                $xjWhere .= ' AND create_time BETWEEN :start AND :end';
                $whWhere .= ' AND create_time BETWEEN :start AND :end';
                $orderParams[':start'] = $start;
                $orderParams[':end'] = $end;
                $xjParams[':start'] = $start;
                $xjParams[':end'] = $end;
                $whParams[':start'] = $start;
                $whParams[':end'] = $end;
            }

            $orderRows = self::statsCrud('dcim-order')->countGroupBy('EmpId', $orderWhere, $orderParams, 'id');
            foreach ($orderRows as $row) {
                $orderMap[(string) ($row['grp'] ?? '')] = (int) ($row['cnt'] ?? 0);
            }

            $xjRows = self::statsCrud('dcim-xjtask')->countGroupBy('XJEmpId', $xjWhere, $xjParams, 'id');
            foreach ($xjRows as $row) {
                $xjMap[(string) ($row['grp'] ?? '')] = (int) ($row['cnt'] ?? 0);
            }

            $whRows = self::statsCrud('dcim-whtask')->countGroupBy('WHEmpId', $whWhere, $whParams, 'id');
            foreach ($whRows as $row) {
                $whMap[(string) ($row['grp'] ?? '')] = (int) ($row['cnt'] ?? 0);
            }
        } catch (Throwable $e) {
        }

        $list = [];
        foreach ($persons as $p) {
            $pid = (string) ($p['id'] ?? '');
            $list[] = [
                'EmpId' => $pid,
                'PersonName' => (string) ($p['PersonName'] ?? ''),
                'OrderNumber' => $orderMap[$pid] ?? 0,
                'XJNumber' => $xjMap[$pid] ?? 0,
                'WHNumber' => $whMap[$pid] ?? 0,
            ];
        }
        O_E($list, tp_msg_success(), 100, 0);
    }

    public static function statsSupplierScore()
    {
        $data = Flight::request_data();
        self::requireAuthStrict($data);
        $start = trim((string)($data['startDateTime'] ?? ''));
        $end = trim((string)($data['endDateTime'] ?? ''));
        $rows = [];
        $wbrecordCols = [];
        try {
            $colStmt = Flight::db()->prepare('SHOW COLUMNS FROM `dcim-wbrecord`');
            $colStmt->execute();
            foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $field = (string)($col['Field'] ?? '');
                if ($field !== '') {
                    $wbrecordCols[$field] = true;
                }
            }
            $where = ['1=1'];
            $params = [];
            if (isset($wbrecordCols['status'])) {
                $where[] = '(a.status <> -1 OR a.status IS NULL)';
            }
            if ($start !== '' && $end !== '' && isset($wbrecordCols['create_time'])) {
                $where[] = 'a.create_time BETWEEN :start AND :end';
                $params[':start'] = $start;
                $params[':end'] = $end;
            }
            $responseExpr = isset($wbrecordCols['WBResponseSpeed']) ? 'COALESCE(a.WBResponseSpeed,0)' : '0';
            $qaExpr = isset($wbrecordCols['WBQa']) ? 'COALESCE(a.WBQa,0)' : '0';
            $supplierNameExpr = isset($wbrecordCols['SupplierName']) ? 'COALESCE(NULLIF(a.SupplierName,\'\'), s.SupplierName)' : 's.SupplierName';

            $sql = 'SELECT a.SupplierId, ' . $supplierNameExpr . ' AS SupplierName, '
                . 'SUM(' . $responseExpr . ') AS TotalWBResponseSpeed, '
                . 'SUM(' . $qaExpr . ') AS TotalWBQa, '
                . 'COUNT(a.SupplierId) AS TotalWBNumber '
                . 'FROM `dcim-wbrecord` a '
                . 'LEFT JOIN `dcim-supplier` s ON s.id = a.SupplierId '
                . 'WHERE ' . implode(' AND ', $where) . ' '
                . 'GROUP BY a.SupplierId, SupplierName '
                . 'ORDER BY TotalWBNumber DESC';
            $stmt = Flight::db()->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $rows = [];
        }
        if (!$rows) {
            try {
                $maintCols = [];
                $mColStmt = Flight::db()->prepare('SHOW COLUMNS FROM `dcim-maintenance`');
                $mColStmt->execute();
                foreach ($mColStmt->fetchAll(PDO::FETCH_ASSOC) as $mCol) {
                    $mField = (string)($mCol['Field'] ?? '');
                    if ($mField !== '') {
                        $maintCols[$mField] = true;
                    }
                }
                $maintWhere = ['1=1'];
                $maintParams = [];
                if (isset($maintCols['status'])) {
                    $maintWhere[] = '(m.status <> -1 OR m.status IS NULL)';
                }
                if ($start !== '' && $end !== '' && isset($maintCols['create_time'])) {
                    $maintWhere[] = 'm.create_time BETWEEN :start2 AND :end2';
                    $maintParams[':start2'] = $start;
                    $maintParams[':end2'] = $end;
                }
                $msql = 'SELECT m.SupplierId, COALESCE(NULLIF(m.SupplierName, \'\'), s.SupplierName) AS SupplierName, '
                    . '0 AS TotalWBResponseSpeed, 0 AS TotalWBQa, COUNT(m.SupplierId) AS TotalWBNumber '
                    . 'FROM `dcim-maintenance` m '
                    . 'LEFT JOIN `dcim-supplier` s ON s.id = m.SupplierId '
                    . 'WHERE ' . implode(' AND ', $maintWhere) . ' '
                    . 'GROUP BY m.SupplierId, SupplierName '
                    . 'ORDER BY TotalWBNumber DESC';
                $mstmt = Flight::db()->prepare($msql);
                foreach ($maintParams as $key => $value) {
                    $mstmt->bindValue($key, $value);
                }
                $mstmt->execute();
                $rows = $mstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                $rows = [];
            }
        }

        $supplierIds = [];
        foreach ($rows as $row) {
            $sid = trim((string)($row['SupplierId'] ?? ''));
            if ($sid !== '') {
                $supplierIds[] = $sid;
            }
        }
        $supplierMap = [];
        if ($supplierIds) {
            $supplierRows = self::statsCrud('dcim-supplier')->selectByIds(array_values(array_unique($supplierIds)), [
                'id',
                'SupplierName',
                'PreWBResponseSpeed',
                'PreWBQa',
            ]);
            foreach ($supplierRows as $item) {
                $sid = (string)($item['id'] ?? '');
                if ($sid !== '') {
                    $supplierMap[$sid] = $item;
                }
            }
        }

        $list = [];
        foreach ($rows as $row) {
            $sid = trim((string)($row['SupplierId'] ?? ''));
            $supplier = $supplierMap[$sid] ?? [];
            $name = (string)($row['SupplierName'] ?? '');
            if ($name === '') {
                $name = (string)($supplier['SupplierName'] ?? '');
            }
            $totalCount = (int)($row['TotalWBNumber'] ?? 0);
            $totalSpeed = (float)($row['TotalWBResponseSpeed'] ?? 0);
            $totalQa = (float)($row['TotalWBQa'] ?? 0);
            $list[] = [
                'id' => $sid,
                'SupplierId' => $sid,
                'SupplierName' => $name,
                'TotalWBResponseSpeed' => $totalSpeed,
                'TotalWBQa' => $totalQa,
                'TotalWBNumber' => $totalCount,
                'PreWBResponseSpeed' => $totalCount > 0 ? round($totalSpeed / $totalCount, 2) : 0,
                'PreWBQa' => $totalCount > 0 ? round($totalQa / $totalCount, 2) : 0,
            ];
        }
        if (!$list) {
            try {
                $supplierCols = [];
                $sColStmt = Flight::db()->prepare('SHOW COLUMNS FROM `dcim-supplier`');
                $sColStmt->execute();
                foreach ($sColStmt->fetchAll(PDO::FETCH_ASSOC) as $sCol) {
                    $sField = (string)($sCol['Field'] ?? '');
                    if ($sField !== '') {
                        $supplierCols[$sField] = true;
                    }
                }
                $supplierWhere = isset($supplierCols['status']) ? '(status <> -1 OR status IS NULL)' : '1=1';
                $supplierRows = self::statsCrud('dcim-supplier')->selectByRawCondition($supplierWhere, 'ORDER BY id DESC', []);
                foreach ($supplierRows as $supplier) {
                    $sid = trim((string)($supplier['id'] ?? ''));
                    if ($sid === '') {
                        continue;
                    }
                    $list[] = [
                        'id' => $sid,
                        'SupplierId' => $sid,
                        'SupplierName' => (string)($supplier['SupplierName'] ?? ''),
                        'TotalWBResponseSpeed' => 0,
                        'TotalWBQa' => 0,
                        'TotalWBNumber' => 0,
                        'PreWBResponseSpeed' => 0,
                        'PreWBQa' => 0,
                    ];
                }
            } catch (\Throwable $e) {
            }
        }
        O_E($list, tp_msg_success(), 100, $list ? count($list) : 0);
    }

    public static function statsGetAssetStatusCount()
    {
        self::requireAuth(Flight::request_data());
        $list = [];
        try {
            $rows = self::statsCrud('dcim-asset')->countGroupBy('AssetStatus', 'status = 1', [], '*');
            foreach ($rows as $row) {
                $list[] = [
                    'AssetStatus' => (string) ($row['grp'] ?? ''),
                    'TotalNumber' => (string) ((int) ($row['cnt'] ?? 0)),
                ];
            }
        } catch (Throwable $e) {
        }
        O_E($list, tp_msg_success(), 100, 0);
    }

    public static function statsGetAssetChangeCount()
    {
        $data = Flight::request_data();
        self::requireAuthStrict($data);

        $fixedTypes = [];
        foreach ([
            'assets.asset_change.type.inbound',
            'assets.asset_change.type.outbound',
            'assets.asset_change.type.grounding',
            'assets.asset_change.type.install',
            'assets.asset_change.type.uninstall',
            'assets.asset_change.type.change',
            'assets.asset_change.type.back',
            'assets.asset_change.type.dispose',
            'assets.asset_change.type.remove',
            'assets.asset_change.type.offback',
            'assets.asset_change.type.repair_ir',
            'assets.asset_change.type.repair_di',
            'assets.asset_change.type.gift',
        ] as $k) {
            $v = trim((string)dcim_msg($k));
            if ($v !== '' && !in_array($v, $fixedTypes, true)) {
                $fixedTypes[] = $v;
            }
        }

        $changeTypeMap = [];
        $cols = self::statsTableColumns('dcim-assetchangelog');
        $typeField = self::statsPickColumn($cols, ['ChangeType']);
        $statusField = self::statsPickColumn($cols, ['status']);
        $idField = self::statsPickColumn($cols, ['id']);
        $timeField = self::statsPickColumn($cols, ['create_time', 'CreateTime']);
        if ($typeField !== '') {
            $where = [];
            $params = [];
            if ($statusField !== '') {
                $where[] = self::statsQuoteIdent($statusField) . ' = 1';
            }
            $start = trim((string)($data['startDateTime'] ?? ''));
            $end = trim((string)($data['endDateTime'] ?? ''));
            if ($start !== '' && $end !== '' && $timeField !== '') {
                $where[] = self::statsQuoteIdent($timeField) . ' BETWEEN :start AND :end';
                $params[':start'] = $start;
                $params[':end'] = $end;
            }
            $whereSql = $where ? implode(' AND ', $where) : '1=1';
            $cntExpr = $idField !== '' ? self::statsQuoteIdent($idField) : '*';
            $sql = 'SELECT ' . self::statsQuoteIdent($typeField) . ' AS ChangeType, COUNT(' . $cntExpr . ') AS TotalNumber'
                . ' FROM ' . self::statsQuoteIdent('dcim-assetchangelog')
                . ' WHERE ' . $whereSql
                . ' GROUP BY ' . self::statsQuoteIdent($typeField);
            try {
                $stmt = Flight::db()->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $k = trim((string)($row['ChangeType'] ?? ''));
                    if ($k === '') {
                        continue;
                    }
                    $changeTypeMap[$k] = (int)($row['TotalNumber'] ?? 0);
                }
            } catch (\Throwable $e) {
            }
        }

        $out = [];
        foreach ($fixedTypes as $typeName) {
            $out[] = [
                'ChangeType' => $typeName,
                'TotalNumber' => (int)($changeTypeMap[$typeName] ?? 0),
            ];
        }
        O_E($out, tp_msg_success(), 100, 0);
    }

    public static function statsGetCapacityInfo()
    {
        self::requireAuth(Flight::request_data());
        O_E([], tp_msg_success(), 100, 0);
    }
    public static function statsGetAssetsSurplusStatistic()
    {
        $data = Flight::request_data();
        self::requireAuthStrict($data);

        $getVal = static function (array $row, array $candidates, $default = '') {
            foreach ($candidates as $name) {
                if ($name !== '' && array_key_exists($name, $row)) {
                    return $row[$name];
                }
            }
            $lcMap = [];
            foreach ($row as $k => $v) {
                $lcMap[strtolower((string)$k)] = $v;
            }
            foreach ($candidates as $name) {
                if ($name === '') {
                    continue;
                }
                $lk = strtolower((string)$name);
                if (array_key_exists($lk, $lcMap)) {
                    return $lcMap[$lk];
                }
            }
            return $default;
        };
        $normDate = static function ($raw): string {
            $raw = trim((string)$raw);
            if ($raw === '') {
                return '';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $m)) {
                return $m[0];
            }
            $ts = strtotime($raw);
            return $ts ? date('Y-m-d', $ts) : '';
        };
        $monthDiffLike8080 = static function (string $dateYmd): ?int {
            $ts = strtotime($dateYmd . ' 00:00:00');
            if ($ts === false) {
                return null;
            }
            $endY = (int)date('Y', $ts);
            $endM = (int)date('m', $ts);
            $endD = (int)date('d', $ts);
            $nowY = (int)date('Y');
            $nowM = (int)date('m');
            $nowD = (int)date('d');
            return ($endY - $nowY) * 12 + ($endM - $nowM) + (int)floor(($endD - $nowD) / 25);
        };
        $lastDayOfMonth = static function (int $year, int $month): int {
            return (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
        };
        $safeYmd = static function (int $year, int $month, int $day) use ($lastDayOfMonth): string {
            $month = max(1, min(12, $month));
            $day = max(1, min($day, $lastDayOfMonth($year, $month)));
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        };
        $addMonthsKeepDay = static function (string $startYmd, int $months) use ($safeYmd): string {
            $parts = explode('-', $startYmd);
            if (count($parts) < 3) {
                return '';
            }
            $year = (int)$parts[0];
            $month = (int)$parts[1];
            $day = (int)$parts[2];
            if ($year <= 0 || $month <= 0 || $day <= 0) {
                return '';
            }
            $addYear = (int)floor($months / 12);
            $addMonth = $months - $addYear * 12;
            $endYear = $year + $addYear;
            $endMonth = $month + $addMonth;
            if ($endMonth > 12) {
                $endYear += 1;
                $endMonth -= 12;
            }
            if ($endMonth <= 0) {
                $step = (int)ceil(abs($endMonth) / 12);
                $endYear -= $step;
                $endMonth += 12 * $step;
            }
            return $safeYmd($endYear, $endMonth, $day);
        };
        $matchSurplus = static function ($bucketRaw, ?int $surplus): bool {
            $bucket = trim((string)$bucketRaw);
            if ($bucket === '' || strtolower($bucket) === 'null') {
                return true;
            }
            if ($surplus === null) {
                return false;
            }
            $v = (int)$bucket;
            if ($v === 0) {
                return $surplus < 0;
            }
            if ($v === 12) {
                return $surplus >= 0 && $surplus <= 12;
            }
            if ($v === 24) {
                return $surplus >= 12 && $surplus <= 24;
            }
            if ($v === 36) {
                return $surplus >= 24 && $surplus <= 36;
            }
            if ($v === 48) {
                return $surplus >= 36;
            }
            return true;
        };

        $assetCols = self::statsTableColumns('dcim-asset');
        if (!$assetCols) {
            O_E([], tp_msg_success(), 100, 0);
            return;
        }
        $assetIdField = self::statsPickColumn($assetCols, ['id']);
        $assetStatusField = self::statsPickColumn($assetCols, ['status']);
        $assetTypeField = self::statsPickColumn($assetCols, ['AssetsTypeId', 'AssetTypeId', 'TypeId']);
        $assetBizStatusField = self::statsPickColumn($assetCols, ['AssetStatus']);
        $assetModelField = self::statsPickColumn($assetCols, ['ModelId', 'BrandModelId']);
        $assetEmpField = self::statsPickColumn($assetCols, ['EmpId', 'UserId', 'OwnerId']);
        $assetMaintenanceField = self::statsPickColumn($assetCols, ['MaintenanceId']);
        $assetBuyTimeField = self::statsPickColumn($assetCols, ['BuyTime']);
        $assetNoField = self::statsPickColumn($assetCols, ['AssetsNumber', 'AssetNo', 'SN', 'SerialNo']);
        $assetDescField = self::statsPickColumn($assetCols, ['AssetsDescribe', 'AssetsName', 'AssetName']);

        $assetWhere = ($assetStatusField !== '')
            ? (self::statsQuoteIdent($assetStatusField) . ' = 1')
            : '1=1';
        $assetRows = self::statsCrud('dcim-asset')->selectByRawCondition($assetWhere, '', []);
        if (!$assetRows) {
            O_E([], tp_msg_success(), 100, 0);
            return;
        }

        $idSet = [];
        $modelSet = [];
        $empSet = [];
        $maintenanceSet = [];
        foreach ($assetRows as $row) {
            $aid = trim((string)$getVal($row, [$assetIdField, 'id']));
            if ($aid !== '') {
                $idSet[$aid] = true;
            }
            $mid = trim((string)$getVal($row, [$assetModelField, 'ModelId']));
            if ($mid !== '') {
                $modelSet[$mid] = true;
            }
            $eid = trim((string)$getVal($row, [$assetEmpField, 'EmpId']));
            if ($eid !== '') {
                $empSet[$eid] = true;
            }
            $mtid = trim((string)$getVal($row, [$assetMaintenanceField, 'MaintenanceId']));
            if ($mtid !== '') {
                $maintenanceSet[$mtid] = true;
            }
        }

        $personMap = [];
        $deptIdSet = [];
        if ($empSet) {
            $personRows = self::statsCrud('dcim-person')->selectByIds(array_keys($empSet), ['id', 'PersonName', 'DeptId']);
            foreach ($personRows as $r) {
                $pid = trim((string)$getVal($r, ['id']));
                if ($pid === '') {
                    continue;
                }
                $deptId = trim((string)$getVal($r, ['DeptId']));
                if ($deptId !== '') {
                    $deptIdSet[$deptId] = true;
                }
                $personMap[$pid] = [
                    'PersonName' => (string)$getVal($r, ['PersonName']),
                    'DeptId' => $deptId,
                ];
            }
        }

        $deptMap = [];
        if ($deptIdSet) {
            $deptRows = self::statsCrud('dcim-department')->selectByIds(array_keys($deptIdSet), ['id', 'DeptName']);
            foreach ($deptRows as $r) {
                $did = trim((string)$getVal($r, ['id']));
                if ($did !== '') {
                    $deptMap[$did] = (string)$getVal($r, ['DeptName']);
                }
            }
        }

        $modelMap = [];
        if ($modelSet) {
            $modelRows = self::statsCrud('dcim-brandmodel')->selectByIds(array_keys($modelSet), ['id', 'BrandModel', 'ModelScrap', 'AssetsTypeId']);
            foreach ($modelRows as $r) {
                $mid = trim((string)$getVal($r, ['id']));
                if ($mid === '') {
                    continue;
                }
                $modelMap[$mid] = [
                    'BrandModel' => (string)$getVal($r, ['BrandModel']),
                    'ModelScrap' => (int)$getVal($r, ['ModelScrap'], 0),
                    'AssetsTypeId' => trim((string)$getVal($r, ['AssetsTypeId'])),
                ];
            }
        }

        $maintenanceMap = [];
        if ($maintenanceSet) {
            $maintenanceRows = self::statsCrud('dcim-maintenance')->selectByIds(array_keys($maintenanceSet), ['id', 'StartTime', 'MaintenanceMonth', 'status']);
            foreach ($maintenanceRows as $r) {
                $mid = trim((string)$getVal($r, ['id']));
                if ($mid === '') {
                    continue;
                }
                $status = trim((string)$getVal($r, ['status']));
                if ($status !== '' && $status !== '1') {
                    continue;
                }
                $maintenanceMap[$mid] = [
                    'StartTime' => (string)$getVal($r, ['StartTime']),
                    'MaintenanceMonth' => (int)$getVal($r, ['MaintenanceMonth'], 0),
                ];
            }
        }

        $typeCols = self::statsTableColumns('dcim-assettype');
        $typeRows = [];
        if ($typeCols) {
            $typeStatusField = self::statsPickColumn($typeCols, ['status']);
            $typeWhere = $typeStatusField !== '' ? (self::statsQuoteIdent($typeStatusField) . ' = 1') : '1=1';
            $typeRows = self::statsCrud('dcim-assettype')->selectByRawCondition($typeWhere, '', []);
        }
        $typeNameMap = [];
        $typeParentMap = [];
        $typeChildrenMap = [];
        foreach ($typeRows as $r) {
            $tid = trim((string)$getVal($r, ['id']));
            if ($tid === '') {
                continue;
            }
            $name = trim((string)$getVal($r, ['AssetsTypeName', 'TypeName']));
            $pid = trim((string)$getVal($r, ['AssetsTypeParentId', 'ParentId']));
            $typeNameMap[$tid] = $name;
            $typeParentMap[$tid] = $pid;
            if (!isset($typeChildrenMap[$pid])) {
                $typeChildrenMap[$pid] = [];
            }
            $typeChildrenMap[$pid][] = $tid;
        }

        $groundingMap = [];
        if ($idSet) {
            $agCols = self::statsTableColumns('dcim-assetgrounding');
            $agAssetIdField = self::statsPickColumn($agCols, ['AssetsId', 'AssetId']);
            $agStatusField = self::statsPickColumn($agCols, ['status']);
            $agIdField = self::statsPickColumn($agCols, ['id']);
            if ($agAssetIdField !== '') {
                $params = [];
                $holders = [];
                $i = 0;
                foreach (array_keys($idSet) as $aid) {
                    $ph = ':a' . $i++;
                    $holders[] = $ph;
                    $params[$ph] = $aid;
                }
                if ($holders) {
                    $sql = 'SELECT * FROM ' . self::statsQuoteIdent('dcim-assetgrounding')
                        . ' WHERE ' . self::statsQuoteIdent($agAssetIdField) . ' IN (' . implode(',', $holders) . ')';
                    if ($agStatusField !== '') {
                        $sql .= ' AND ' . self::statsQuoteIdent($agStatusField) . ' = 1';
                    }
                    if ($agIdField !== '') {
                        $sql .= ' ORDER BY ' . self::statsQuoteIdent($agIdField) . ' DESC';
                    }
                    try {
                        $stmt = Flight::db()->prepare($sql);
                        $stmt->execute($params);
                        $agRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($agRows as $r) {
                            $aid = trim((string)$getVal($r, [$agAssetIdField, 'AssetsId']));
                            if ($aid !== '' && !isset($groundingMap[$aid])) {
                                $groundingMap[$aid] = $r;
                            }
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
        }

        $cabinetCols = self::statsTableColumns('dcim-cabinet');
        $cabinetMap = [];
        $areaSet = [];
        $serverSet = [];
        if ($groundingMap && $cabinetCols) {
            $cabinetIdField = self::statsPickColumn($cabinetCols, ['id']);
            $cabinetAreaField = self::statsPickColumn($cabinetCols, ['AreaId']);
            $cabinetServerField = self::statsPickColumn($cabinetCols, ['ServerCode', 'ServerId', 'ServerID']);
            $cabinetNameField = self::statsPickColumn($cabinetCols, ['CabinetName', 'name']);
            $cabinetColumnField = self::statsPickColumn($cabinetCols, ['column', 'ColumnNo']);
            $cabinetPositionField = self::statsPickColumn($cabinetCols, ['position', 'Position']);

            $cabinetIds = [];
            foreach ($groundingMap as $gr) {
                $cid = trim((string)$getVal($gr, ['CabinetId']));
                if ($cid !== '') {
                    $cabinetIds[$cid] = true;
                }
            }
            if ($cabinetIds) {
                $cabinetRows = self::statsCrud('dcim-cabinet')->selectByIds(array_keys($cabinetIds), ['id', 'AreaId', 'ServerCode', 'CabinetName', 'column', 'position']);
                foreach ($cabinetRows as $r) {
                    $cid = trim((string)$getVal($r, [$cabinetIdField, 'id']));
                    if ($cid === '') {
                        continue;
                    }
                    $aid = trim((string)$getVal($r, [$cabinetAreaField, 'AreaId']));
                    $sid = trim((string)$getVal($r, [$cabinetServerField, 'ServerCode', 'ServerId']));
                    if ($aid !== '') {
                        $areaSet[$aid] = true;
                    }
                    if ($sid !== '') {
                        $serverSet[$sid] = true;
                    }
                    $cabinetMap[$cid] = [
                        'AreaId' => $aid,
                        'ServerCode' => $sid,
                        'CabinetName' => (string)$getVal($r, [$cabinetNameField, 'CabinetName']),
                        'column' => (string)$getVal($r, [$cabinetColumnField, 'column']),
                        'position' => (string)$getVal($r, [$cabinetPositionField, 'position']),
                    ];
                }
            }
        }

        $areaMap = [];
        if ($areaSet) {
            $areaRows = self::statsCrud('dcim-area')->selectByIds(array_keys($areaSet), ['id', 'AreaName']);
            foreach ($areaRows as $r) {
                $aid = trim((string)$getVal($r, ['id']));
                if ($aid !== '') {
                    $areaMap[$aid] = (string)$getVal($r, ['AreaName']);
                }
            }
        }
        $serverMap = [];
        if ($serverSet) {
            $serverRows = self::statsCrud('dcim-server')->selectByIds(array_keys($serverSet), ['id', 'ServerName', 'ServerCode']);
            foreach ($serverRows as $r) {
                $sid = trim((string)$getVal($r, ['id']));
                if ($sid !== '') {
                    $serverMap[$sid] = [
                        'ServerName' => (string)$getVal($r, ['ServerName']),
                        'ServerCodeText' => (string)$getVal($r, ['ServerCode']),
                    ];
                }
            }
        }

        $typeParam = strtolower(trim((string)($data['type'] ?? '')));
        $typeIdParam = trim((string)($data['typeid'] ?? ''));
        $filterTypeSet = [];
        if ($typeParam !== '') {
            $rootName = '';
            if ($typeParam === 'it') {
                $rootName = dcim_msg('app.asset_type_root_it');
            } elseif ($typeParam === 'install') {
                $rootName = dcim_msg('app.infra_root_name');
            }
            $rootIds = [];
            if ($rootName !== '') {
                foreach ($typeNameMap as $tid => $name) {
                    if ($name === $rootName && (string)($typeParentMap[$tid] ?? '') === '0') {
                        $rootIds[$tid] = true;
                    }
                }
            }
            $descSet = [];
            $queue = array_keys($rootIds);
            while ($queue) {
                $curr = array_shift($queue);
                if (isset($descSet[$curr])) {
                    continue;
                }
                $descSet[$curr] = true;
                if (isset($typeChildrenMap[$curr])) {
                    foreach ($typeChildrenMap[$curr] as $child) {
                        if (!isset($descSet[$child])) {
                            $queue[] = $child;
                        }
                    }
                }
            }
            if ($typeIdParam !== '') {
                if (isset($descSet[$typeIdParam])) {
                    $filterTypeSet[$typeIdParam] = true;
                } else {
                    $filterTypeSet = ['__none__' => true];
                }
            } else {
                $filterTypeSet = $descSet;
            }
        } elseif ($typeIdParam !== '') {
            $filterTypeSet[$typeIdParam] = true;
        }

        $weiBaoBucket = $data['WeiBaoSurplus'] ?? '';
        $lifeBucket = $data['LifeSurplus'] ?? '';
        $wbStatusOk = dcim_msg('app.weibao_status_in');
        $wbStatusExpired = dcim_msg('app.weibao_status_out');
        $todayYmd = date('Y-m-d');
        $todayTs = strtotime($todayYmd . ' 00:00:00');

        $list = [];
        $seenIds = [];
        foreach ($assetRows as $row) {
            $aid = trim((string)$getVal($row, [$assetIdField, 'id']));
            if ($aid === '' || isset($seenIds[$aid])) {
                continue;
            }

            $modelId = trim((string)$getVal($row, [$assetModelField, 'ModelId']));
            $empId = trim((string)$getVal($row, [$assetEmpField, 'EmpId']));
            $maintenanceId = trim((string)$getVal($row, [$assetMaintenanceField, 'MaintenanceId']));
            $assetsTypeId = trim((string)$getVal($row, [$assetTypeField, 'AssetsTypeId']));
            if ($assetsTypeId === '' && $modelId !== '' && isset($modelMap[$modelId])) {
                $assetsTypeId = trim((string)$modelMap[$modelId]['AssetsTypeId']);
            }

            if ($filterTypeSet) {
                if (!isset($filterTypeSet[$assetsTypeId])) {
                    continue;
                }
            }

            $buyDate = $normDate((string)$getVal($row, [$assetBuyTimeField, 'BuyTime']));
            $modelScrap = $modelId !== '' && isset($modelMap[$modelId]) ? (int)$modelMap[$modelId]['ModelScrap'] : 0;
            $lifeSurplusTime = '';
            $lifeSurplus = null;
            if ($buyDate !== '' && $modelScrap > 0) {
                $parts = explode('-', $buyDate);
                if (count($parts) >= 3) {
                    $lifeSurplusTime = $safeYmd((int)$parts[0] + $modelScrap, (int)$parts[1], (int)$parts[2]);
                    $lifeSurplus = $monthDiffLike8080($lifeSurplusTime);
                }
            }

            $weiBaoEndTime = '';
            $weiBaoSurplus = null;
            $weiBaoStatus = '';
            if ($maintenanceId !== '' && isset($maintenanceMap[$maintenanceId])) {
                $startDate = $normDate((string)$maintenanceMap[$maintenanceId]['StartTime']);
                $maintMonths = (int)$maintenanceMap[$maintenanceId]['MaintenanceMonth'];
                if ($startDate !== '' && $maintMonths > 0) {
                    $weiBaoEndTime = $addMonthsKeepDay($startDate, $maintMonths);
                    if ($weiBaoEndTime !== '') {
                        $weiBaoSurplus = $monthDiffLike8080($weiBaoEndTime);
                        $endTs = strtotime($weiBaoEndTime . ' 00:00:00');
                        if ($endTs !== false && $todayTs !== false) {
                            $diffDay = (int)floor(($endTs - $todayTs) / 86400);
                            $weiBaoStatus = $diffDay <= 0 ? $wbStatusExpired : $wbStatusOk;
                        }
                    }
                }
            }

            if (!$matchSurplus($weiBaoBucket, $weiBaoSurplus)) {
                continue;
            }
            if (!$matchSurplus($lifeBucket, $lifeSurplus)) {
                continue;
            }

            $personName = '';
            $deptName = '';
            if ($empId !== '' && isset($personMap[$empId])) {
                $personName = (string)$personMap[$empId]['PersonName'];
                $deptId = (string)$personMap[$empId]['DeptId'];
                if ($deptId !== '' && isset($deptMap[$deptId])) {
                    $deptName = (string)$deptMap[$deptId];
                }
            }

            $serverCode = '';
            $serverName = '';
            $areaId = '';
            $areaName = '';
            $column = '';
            $position = '';
            $uLocation = '';
            $cabinetId = '';
            $cabinetName = '';
            if (isset($groundingMap[$aid])) {
                $gr = $groundingMap[$aid];
                $uLocation = (string)$getVal($gr, ['ULocation']);
                $cabinetId = trim((string)$getVal($gr, ['CabinetId']));
                if ($cabinetId !== '' && isset($cabinetMap[$cabinetId])) {
                    $cb = $cabinetMap[$cabinetId];
                    $serverCode = (string)($cb['ServerCode'] ?? '');
                    $areaId = (string)($cb['AreaId'] ?? '');
                    $column = (string)($cb['column'] ?? '');
                    $position = (string)($cb['position'] ?? '');
                    $cabinetName = (string)($cb['CabinetName'] ?? '');
                    if ($areaId !== '' && isset($areaMap[$areaId])) {
                        $areaName = (string)$areaMap[$areaId];
                    }
                    if ($serverCode !== '' && isset($serverMap[$serverCode])) {
                        $serverName = (string)$serverMap[$serverCode]['ServerName'];
                        if ($serverName === '' && (string)$serverMap[$serverCode]['ServerCodeText'] !== '') {
                            $serverName = (string)$serverMap[$serverCode]['ServerCodeText'];
                        }
                    }
                }
            }

            $list[] = [
                'id' => $aid,
                'AssetsNumber' => (string)$getVal($row, [$assetNoField, 'AssetsNumber']),
                'AssetsDescribe' => (string)$getVal($row, [$assetDescField, 'AssetsDescribe']),
                'AssetStatus' => (string)$getVal($row, [$assetBizStatusField, 'AssetStatus']),
                'BuyTime' => $buyDate,
                'MaintenanceId' => $maintenanceId,
                'StartTime' => $maintenanceId !== '' && isset($maintenanceMap[$maintenanceId]) ? $normDate((string)$maintenanceMap[$maintenanceId]['StartTime']) : '',
                'MaintenanceMonth' => $maintenanceId !== '' && isset($maintenanceMap[$maintenanceId]) ? (int)$maintenanceMap[$maintenanceId]['MaintenanceMonth'] : 0,
                'WeiBaoEndTime' => $weiBaoEndTime,
                'WeiBaoSurplus' => $weiBaoSurplus,
                'WeiBaoStatus' => $weiBaoStatus,
                'LifeSurplusTime' => $lifeSurplusTime,
                'LifeSurplus' => $lifeSurplus,
                'ModelId' => $modelId,
                'BrandModel' => $modelId !== '' && isset($modelMap[$modelId]) ? (string)$modelMap[$modelId]['BrandModel'] : '',
                'ModelScrap' => $modelScrap,
                'AssetsTypeId' => $assetsTypeId,
                'AssetsTypeName' => $assetsTypeId !== '' && isset($typeNameMap[$assetsTypeId]) ? (string)$typeNameMap[$assetsTypeId] : '',
                'EmpId' => $empId,
                'PersonName' => $personName,
                'DeptName' => $deptName,
                'ServerCode' => $serverCode,
                'ServerName' => $serverName,
                'AreaId' => $areaId,
                'AreaName' => $areaName,
                'CabinetId' => $cabinetId,
                'CabinetName' => $cabinetName,
                'column' => $column,
                'position' => $position,
                'ULocation' => $uLocation,
            ];
            $seenIds[$aid] = true;
        }

        O_E($list, tp_msg_success(), 100, count($list));
    }
    public static function statsGetYWAllHisInfo()
    {
        self::requireAuthStrict(Flight::request_data());
        $rows = self::statsRecentRows('dcim-orderrecord', 100);
        O_E($rows, tp_msg_success(), 100, $rows ? count($rows) : 0);
    }

    public static function statsGetOrderAllHisInfo()
    {
        self::requireAuthStrict(Flight::request_data());
        $rows = self::statsRecentRows('dcim-orderrecord', 100);
        O_E($rows, tp_msg_success(), 100, $rows ? count($rows) : 0);
    }

    public static function statsGetXJAllHisInfo()
    {
        self::requireAuthStrict(Flight::request_data());
        $rows = self::statsRecentRows('dcim-xjrecord', 100);
        if (!$rows) {
            $rows = self::statsRecentRows('dcim-xjtask', 100);
        }
        O_E($rows, tp_msg_success(), 100, $rows ? count($rows) : 0);
    }

    public static function statsGetWHAllHisInfo()
    {
        self::requireAuthStrict(Flight::request_data());
        $rows = self::statsRecentRows('dcim-whrecord', 100);
        if (!$rows) {
            $rows = self::statsRecentRows('dcim-whtask', 100);
        }
        O_E($rows, tp_msg_success(), 100, $rows ? count($rows) : 0);
    }

    public static function statsGetDeviceAllHisInfo()
    {
        self::requireAuthStrict(Flight::request_data());
        self::statsOk([]);
    }

    public static function statsGetCapacityTrend()
    {
        self::requireAuthStrict(Flight::request_data());
        self::statsOk([]);
    }

    public static function statsGetAlarmStatistic()
    {
        $data = Flight::request_data();
        self::requireAuthStrict($data);
        $debugRaw = $data['debug'] ?? '';
        $debugEnabled = false;
        if ($debugRaw === 1 || $debugRaw === '1' || $debugRaw === true || $debugRaw === 'true' || $debugRaw === 'TRUE') {
            $debugEnabled = true;
        }
        $debugInfo = [
            'driver' => self::statsIsDmDriver() ? 'dm' : 'other',
        ];
        $typeParam = strtolower(trim((string)($data['type'] ?? 'year')));
        $isMonthType = ($typeParam === 'month');

        $fixedLevelNames = [
            dcim_msg('app.alarm_level_1'),
            dcim_msg('app.alarm_level_2'),
            dcim_msg('app.alarm_level_3'),
            dcim_msg('app.alarm_level_4'),
            dcim_msg('app.alarm_level_5'),
        ];

        $levelRows = [];
        $levelCols = self::statsTableColumns('dcim-alarmlevellist');
        $levelStatusField = self::statsPickColumn($levelCols, ['status']);
        $levelWhere = $levelStatusField !== '' ? ('(' . self::statsQuoteIdent($levelStatusField) . ' <> -1 OR ' . self::statsQuoteIdent($levelStatusField) . ' IS NULL)') : '1=1';
        try {
            $levelRows = self::statsCrud('dcim-alarmlevellist')->selectByRawCondition($levelWhere, 'ORDER BY id ASC', []);
        } catch (\Throwable $e) {
            $levelRows = [];
        }
        if (!$levelRows) {
            try {
                $levelRows = self::statsCrud('dcim-alarmlevellist')->selectByRawCondition('1=1', 'ORDER BY id ASC', []);
            } catch (\Throwable $e) {
                $levelRows = [];
            }
        }
        $levelMap = [];
        $levelOrder = [];
        $levelBucketById = [];
        $levelBucketByName = [];
        $autoBucket = 1;
        $fixedNameToBucket = [];
        foreach ($fixedLevelNames as $idx => $fixedName) {
            $nameKey = trim((string)$fixedName);
            if ($nameKey !== '') {
                $fixedNameToBucket[$nameKey] = $idx + 1;
                $levelBucketByName[strtolower($nameKey)] = $idx + 1;
            }
        }
        foreach ($levelRows as $row) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $name = trim((string)($row['LevelName'] ?? ''));
            if ($name === '') {
                $name = 'Level' . $id;
            }
            $levelMap[$id] = $name;
            $levelOrder[] = $id;
            if (ctype_digit($id)) {
                $num = (int)$id;
                if ($num >= 1 && $num <= 5) {
                    $levelBucketById[$id] = $num;
                    continue;
                }
                if ($num >= 0 && $num <= 4) {
                    $levelBucketById[$id] = $num + 1;
                    continue;
                }
            }
            if (isset($fixedNameToBucket[$name])) {
                $levelBucketById[$id] = (int)$fixedNameToBucket[$name];
            }
            if (isset($levelBucketById[$id])) {
                $levelBucketByName[strtolower($name)] = (int)$levelBucketById[$id];
            }
            if (!isset($levelBucketById[$id]) && $autoBucket <= 5) {
                // Fallback for legacy datasets whose alarm level ids are not 1~5.
                // Keep stable output by mapping level table order to 1~5 buckets.
                $levelBucketById[$id] = $autoBucket;
                $levelBucketByName[strtolower($name)] = $autoBucket;
                $autoBucket++;
            }
        }
        if (!$levelOrder) {
            for ($i = 1; $i <= 5; $i++) {
                $key = (string)$i;
                $fallbackName = trim((string)($fixedLevelNames[$i - 1] ?? ''));
                $levelMap[$key] = $fallbackName !== '' ? $fallbackName : ('Level' . $i);
                $levelOrder[] = $key;
                $levelBucketById[$key] = $i;
            }
        }
        $resolveLevelBucket = static function (string $rawLevel) use ($levelBucketById, $levelBucketByName): int {
            $rawLevel = trim($rawLevel);
            if ($rawLevel === '') {
                return 0;
            }
            if (isset($levelBucketById[$rawLevel])) {
                return (int)$levelBucketById[$rawLevel];
            }
            if (preg_match('/^-?\d+(\.\d+)?$/', $rawLevel) === 1) {
                $num = (int)$rawLevel;
                if ($num >= 1 && $num <= 5) {
                    return $num;
                }
                if ($num >= 0 && $num <= 4) {
                    return $num + 1;
                }
            }
            $nameKey = strtolower($rawLevel);
            if (isset($levelBucketByName[$nameKey])) {
                return (int)$levelBucketByName[$nameKey];
            }
            return 0;
        };

        $alarmCols = self::statsTableColumns('dcim-alarmlist');
        $deviceCols = self::statsTableColumns('dcim-device');
        $alarmLevelField = self::statsPickColumn($alarmCols, ['AlarmLevel', 'LevelId', 'LevelID', 'AlarmLev', 'AlarmLv', 'Level']);
        $alarmStatusField = self::statsPickColumn($alarmCols, ['status']);
        $alarmBizStatusField = self::statsPickColumn($alarmCols, ['AlarmStatus']);
        $devField = self::statsPickColumn($alarmCols, ['DevId', 'DevID', 'DeviceId', 'DeviceID']);
        $notifyModeField = self::statsPickColumn($alarmCols, ['NotifyModeID', 'NotifyModeId', 'NotifyModeID']);
        $alarmCountField = self::statsPickColumn($alarmCols, ['AlarmCount', 'NotifyCount']);
        $deviceIdField = self::statsPickColumn($deviceCols, ['id']);
        $deviceStatusField = self::statsPickColumn($deviceCols, ['status']);
        $deviceServerCodeField = self::statsPickColumn($deviceCols, ['ServerCode', 'ServerID', 'ServerId']);
        $notifyCols = self::statsTableColumns('dcim-alarmnotifymode');
        $notifyIdField = self::statsPickColumn($notifyCols, ['id']);
        $notifyConfirmField = self::statsPickColumn($notifyCols, ['ConfirmNum', 'ConfirmCount', 'CheckNum']);
        $notifyStatusField = self::statsPickColumn($notifyCols, ['status']);
        $timeField = '';
        foreach (['create_time', 'CreateTime', 'AlarmTime', 'CollectTime', 'StartTime', 'RecordTime', 'ReportTime', 'update_time'] as $candidateTimeField) {
            $resolvedTimeField = self::statsPickColumn($alarmCols, [$candidateTimeField]);
            if ($resolvedTimeField !== '') {
                $timeField = $resolvedTimeField;
                break;
            }
        }
        $alarmLevelFieldCandidates = [];
        foreach (['AlarmLevel', 'LevelId', 'LevelID', 'AlarmLev', 'AlarmLv', 'Level', 'AlarmLevelId', 'LevelNo', 'LevelName'] as $candidateLevelField) {
            $resolvedLevelField = self::statsPickColumn($alarmCols, [$candidateLevelField]);
            if ($resolvedLevelField !== '' && !in_array($resolvedLevelField, $alarmLevelFieldCandidates, true)) {
                $alarmLevelFieldCandidates[] = $resolvedLevelField;
            }
        }
        if ($alarmLevelField !== '' && !in_array($alarmLevelField, $alarmLevelFieldCandidates, true)) {
            array_unshift($alarmLevelFieldCandidates, $alarmLevelField);
        }
        if (!$alarmLevelFieldCandidates) {
            foreach (array_keys($alarmCols) as $colName) {
                $name = (string)$colName;
                $lower = strtolower($name);
                if (strpos($lower, 'level') !== false) {
                    $alarmLevelFieldCandidates[] = $name;
                }
            }
        }
        $timeFieldCandidates = [];
        foreach (['create_time', 'CreateTime', 'AlarmTime', 'CollectTime', 'StartTime', 'RecordTime', 'ReportTime', 'update_time', 'time', 'date'] as $candidateTimeField) {
            $resolvedTimeField = self::statsPickColumn($alarmCols, [$candidateTimeField]);
            if ($resolvedTimeField !== '' && !in_array($resolvedTimeField, $timeFieldCandidates, true)) {
                $timeFieldCandidates[] = $resolvedTimeField;
            }
        }
        if ($timeField !== '' && !in_array($timeField, $timeFieldCandidates, true)) {
            array_unshift($timeFieldCandidates, $timeField);
        }
        if (!$timeFieldCandidates) {
            foreach (array_keys($alarmCols) as $colName) {
                $name = (string)$colName;
                $lower = strtolower($name);
                if (strpos($lower, 'time') !== false || strpos($lower, 'date') !== false) {
                    $timeFieldCandidates[] = $name;
                }
            }
        }
        if ($debugEnabled) {
            $debugInfo['resolved_fields'] = [
                'alarmLevelField' => $alarmLevelField,
                'timeField' => $timeField,
                'alarmStatusField' => $alarmStatusField,
                'alarmBizStatusField' => $alarmBizStatusField,
                'devField' => $devField,
                'notifyModeField' => $notifyModeField,
                'alarmCountField' => $alarmCountField,
                'notifyConfirmField' => $notifyConfirmField,
            ];
            $debugInfo['field_candidates'] = [
                'alarmLevel' => $alarmLevelFieldCandidates,
                'time' => $timeFieldCandidates,
            ];
        }
        $timeFieldCompact = strtolower(str_replace(['_', '-'], '', $timeField));
        $timeFieldIsCompactDate = in_array($timeFieldCompact, ['reporttime', 'recordtime', 'reportdate', 'recorddate'], true);

        $normalizeDate = static function (string $raw): string {
            $raw = trim($raw);
            if ($raw === '') {
                return '';
            }
            $ts = strtotime($raw);
            if ($ts === false) {
                return '';
            }
            return date('Y-m-d H:i:s', $ts);
        };
        $startDateTime = $normalizeDate((string)($data['startDateTime'] ?? ''));
        $endDateTime = $normalizeDate((string)($data['endDateTime'] ?? ''));
        $refTs = $startDateTime !== '' ? strtotime($startDateTime) : time();
        $refYear = (int)date('Y', $refTs);
        if ($startDateTime === '') {
            $startDateTime = sprintf('%04d-01-01 00:00:00', $refYear);
        }
        if ($endDateTime === '') {
            $endDateTime = sprintf('%04d-12-31 23:59:59', $refYear);
        }
        $lastYearStart = date('Y-m-d H:i:s', strtotime('-1 year', strtotime($startDateTime)));
        $lastYearEnd = date('Y-m-d H:i:s', strtotime('-1 year', strtotime($endDateTime)));

        $serverRaw = trim((string)($data['ServerCode'] ?? ''));
        $serverCodes = array_values(array_filter(array_map('trim', explode(',', $serverRaw)), static function ($v) {
            return $v !== '';
        }));
        if (!$serverCodes && $serverRaw !== '') {
            $serverCodes = [$serverRaw];
        }
        if ($serverCodes) {
            $expanded = [];
            foreach ($serverCodes as $sv) {
                $expanded[$sv] = true;
            }
            $numericServerIds = [];
            foreach ($serverCodes as $sv) {
                if (ctype_digit((string)$sv)) {
                    $numericServerIds[] = (string)$sv;
                }
            }
            if ($numericServerIds) {
                $serverCols = self::statsTableColumns('dcim-server');
                $serverIdField = self::statsPickColumn($serverCols, ['id']);
                $serverCodeField = self::statsPickColumn($serverCols, ['ServerCode']);
                if ($serverIdField !== '' && $serverCodeField !== '') {
                    try {
                        $serverRows = self::statsCrud('dcim-server')->selectByIds($numericServerIds, [$serverIdField, $serverCodeField]);
                        foreach ($serverRows as $svr) {
                            $sid = trim((string)($svr[$serverIdField] ?? ''));
                            $scode = trim((string)($svr[$serverCodeField] ?? ''));
                            if ($sid !== '') {
                                $expanded[$sid] = true;
                            }
                            if ($scode !== '') {
                                $expanded[$scode] = true;
                            }
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
            $serverCodes = array_keys($expanded);
        }

        $buildCountRows = static function (string $rangeStart, string $rangeEnd, bool $groupByMonth, string $queryPrefix) use ($alarmStatusField, $alarmBizStatusField, $alarmLevelField, $devField, $timeField, $timeFieldIsCompactDate, $serverCodes, $deviceIdField, $deviceStatusField, $deviceServerCodeField): array {
            $conds = ['1=1'];
            $params = [];
            if ($alarmStatusField !== '') {
                $qStatusField = self::statsQuoteIdent($alarmStatusField);
                $conds[] = '(a.' . $qStatusField . ' <> -1 OR a.' . $qStatusField . ' IS NULL)';
            }
            if ($alarmBizStatusField !== '') {
                $qBizStatusField = self::statsQuoteIdent($alarmBizStatusField);
                $conds[] = 'a.' . $qBizStatusField . ' IN (1,2,3)';
            }
            if ($timeField !== '' && $rangeStart !== '' && $rangeEnd !== '') {
                $qTimeField = self::statsQuoteIdent($timeField);
                if ($timeFieldIsCompactDate) {
                    $rangeStartTs = strtotime($rangeStart);
                    $rangeEndTs = strtotime($rangeEnd);
                    if ($rangeStartTs !== false && $rangeEndTs !== false) {
                        $conds[] = 'a.' . $qTimeField . ' BETWEEN :' . $queryPrefix . '_start AND :' . $queryPrefix . '_end';
                        $params[':' . $queryPrefix . '_start'] = date('Ymd', $rangeStartTs);
                        $params[':' . $queryPrefix . '_end'] = date('Ymd', $rangeEndTs);
                    }
                } else {
                    $conds[] = 'a.' . $qTimeField . ' BETWEEN :' . $queryPrefix . '_start AND :' . $queryPrefix . '_end';
                    $params[':' . $queryPrefix . '_start'] = $rangeStart;
                    $params[':' . $queryPrefix . '_end'] = $rangeEnd;
                }
            }
            if ($devField !== '' && $deviceIdField !== '') {
                $deviceExistsSql = 'EXISTS (SELECT 1 FROM ' . self::statsQuoteIdent('dcim-device') . ' d WHERE d.' . self::statsQuoteIdent($deviceIdField) . ' = a.' . self::statsQuoteIdent($devField);
                if ($deviceStatusField !== '') {
                    $qDevStatusField = self::statsQuoteIdent($deviceStatusField);
                    $deviceExistsSql .= ' AND (d.' . $qDevStatusField . ' <> -1 OR d.' . $qDevStatusField . ' IS NULL)';
                }
                $deviceExistsSql .= ')';
                $conds[] = $deviceExistsSql;
            }
            if ($serverCodes && $devField !== '' && $deviceIdField !== '') {
                $existsSql = 'EXISTS (SELECT 1 FROM ' . self::statsQuoteIdent('dcim-device') . ' d WHERE d.' . self::statsQuoteIdent($deviceIdField) . ' = a.' . self::statsQuoteIdent($devField);
                if ($deviceStatusField !== '') {
                    $qDevStatusField = self::statsQuoteIdent($deviceStatusField);
                    $existsSql .= ' AND (d.' . $qDevStatusField . ' <> -1 OR d.' . $qDevStatusField . ' IS NULL)';
                }
                if ($deviceServerCodeField !== '') {
                    $holders = [];
                    foreach ($serverCodes as $idx => $serverCode) {
                        $ph = ':' . $queryPrefix . '_srv_' . $idx;
                        $holders[] = $ph;
                        $params[$ph] = $serverCode;
                    }
                    if ($holders) {
                        $existsSql .= ' AND d.' . self::statsQuoteIdent($deviceServerCodeField) . ' IN (' . implode(', ', $holders) . ')';
                    }
                }
                $existsSql .= ')';
                $conds[] = $existsSql;
            }

            if ($groupByMonth) {
                if ($timeField === '') {
                    return [];
                }
                $qTimeField = self::statsQuoteIdent($timeField);
                $monthExpr = $timeFieldIsCompactDate
                    ? ('SUBSTR(a.' . $qTimeField . ', 5, 2)')
                    : (self::statsIsDmDriver() ? ('SUBSTR(CAST(a.' . $qTimeField . ' AS VARCHAR(32)), 6, 2)') : ('EXTRACT(MONTH FROM a.' . $qTimeField . ')'));
                $sql = 'SELECT ' . $monthExpr . ' AS grp, COUNT(*) AS cnt'
                    . ' FROM ' . self::statsQuoteIdent('dcim-alarmlist') . ' a'
                    . ' WHERE ' . implode(' AND ', $conds)
                    . ' GROUP BY ' . $monthExpr;
            } else {
                if ($alarmLevelField === '') {
                    return [];
                }
                $qAlarmLevelField = self::statsQuoteIdent($alarmLevelField);
                $sql = 'SELECT a.' . $qAlarmLevelField . ' AS grp, COUNT(*) AS cnt'
                    . ' FROM ' . self::statsQuoteIdent('dcim-alarmlist') . ' a'
                    . ' WHERE ' . implode(' AND ', $conds)
                    . ' GROUP BY a.' . $qAlarmLevelField;
            }
            try {
                $stmt = Flight::db()->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                return [];
            }
        };

        $thisYearRows = $buildCountRows($startDateTime, $endDateTime, false, 'this_year');
        $lastYearRows = $buildCountRows($lastYearStart, $lastYearEnd, false, 'last_year');
        $monthRows = $buildCountRows($startDateTime, $endDateTime, true, 'this_month');
        if ($debugEnabled) {
            $debugInfo['sql_group_rows'] = [
                'thisYear' => count($thisYearRows),
                'lastYear' => count($lastYearRows),
                'month' => count($monthRows),
            ];
        }
        if (!$thisYearRows && !$lastYearRows) {
            $pickRowValueByFields = static function (array $row, array $fields): string {
                foreach ($fields as $field) {
                    $value = trim((string)($row[$field] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
                if (!$fields) {
                    return '';
                }
                foreach ($row as $k => $v) {
                    $key = strtolower((string)$k);
                    foreach ($fields as $field) {
                        if (strtolower((string)$field) === $key) {
                            $value = trim((string)$v);
                            if ($value !== '') {
                                return $value;
                            }
                        }
                    }
                }
                return '';
            };
            $rowTimeGetter = static function (array $row) use ($timeFieldCandidates, $timeFieldIsCompactDate, $pickRowValueByFields): int {
                $raw = $pickRowValueByFields($row, $timeFieldCandidates);
                if ($raw === '') {
                    return 0;
                }
                if (preg_match('/^\d{10,17}$/', $raw) === 1) {
                    $num = (float)$raw;
                    $len = strlen($raw);
                    // 10 digits: epoch seconds
                    if ($len <= 10) {
                        $ts = (int)$num;
                        return $ts > 0 ? $ts : 0;
                    }
                    // 13 digits: epoch milliseconds
                    if ($len <= 13) {
                        $ts = (int)floor($num / 1000);
                        return $ts > 0 ? $ts : 0;
                    }
                    // 16/17 digits: epoch microseconds or higher precision
                    $scale = pow(10, $len - 10);
                    if ($scale > 0) {
                        $ts = (int)floor($num / $scale);
                        return $ts > 0 ? $ts : 0;
                    }
                }
                if (preg_match('/^\d{8}$/', $raw) === 1) {
                    $ts = strtotime(substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2) . ' 00:00:00');
                    return $ts === false ? 0 : (int)$ts;
                }
                if (preg_match('/^\d{14}$/', $raw) === 1) {
                    $ts = strtotime(
                        substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2)
                        . ' ' . substr($raw, 8, 2) . ':' . substr($raw, 10, 2) . ':' . substr($raw, 12, 2)
                    );
                    return $ts === false ? 0 : (int)$ts;
                }
                if ($timeFieldIsCompactDate) {
                    if (preg_match('/^\d{8}$/', $raw) === 1) {
                        $ts = strtotime(substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2) . ' 00:00:00');
                        return $ts === false ? 0 : (int)$ts;
                    }
                    if (preg_match('/^\d{14}$/', $raw) === 1) {
                        $ts = strtotime(
                            substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2)
                            . ' ' . substr($raw, 8, 2) . ':' . substr($raw, 10, 2) . ':' . substr($raw, 12, 2)
                        );
                        return $ts === false ? 0 : (int)$ts;
                    }
                }
                $ts = strtotime($raw);
                if ($ts === false) {
                    $ts = strtotime((string)preg_replace('/\.\d+$/', '', $raw));
                }
                return $ts === false ? 0 : (int)$ts;
            };
            try {
                $rows = self::statsCrud('dcim-alarmlist')->selectByRawCondition('1=1', '', []);
                $deviceStatusMap = [];
                if ($devField !== '' && $deviceIdField !== '') {
                    try {
                        $deviceRows = self::statsCrud('dcim-device')->selectByRawCondition('1=1', '', []);
                        foreach ($deviceRows as $deviceRow) {
                            $did = trim((string)($deviceRow[$deviceIdField] ?? ''));
                            if ($did === '') {
                                continue;
                            }
                            $dst = $deviceStatusField !== '' ? trim((string)($deviceRow[$deviceStatusField] ?? '')) : '1';
                            $deviceStatusMap[$did] = $dst;
                        }
                    } catch (\Throwable $e) {
                    }
                }
                $startTs = strtotime($startDateTime);
                $endTs = strtotime($endDateTime);
                $lastStartTs = strtotime($lastYearStart);
                $lastEndTs = strtotime($lastYearEnd);
                $thisYearCounts = [];
                $lastYearCounts = [];
                $monthCounts = [];
                $dynamicBucketByRawLevel = [];
                $dynamicBucketNext = 1;
                foreach ($rows as $row) {
                    if ($alarmStatusField !== '') {
                        $st = trim((string)($row[$alarmStatusField] ?? ''));
                        if ($st === '-1') {
                            continue;
                        }
                    }
                    if ($alarmBizStatusField !== '') {
                        $bizSt = trim((string)($row[$alarmBizStatusField] ?? ''));
                        if ($bizSt !== '1' && $bizSt !== '2' && $bizSt !== '3') {
                            continue;
                        }
                    }
                    if ($devField !== '') {
                        $rowDevId = trim((string)($row[$devField] ?? ''));
                        if ($rowDevId === '' || !isset($deviceStatusMap[$rowDevId]) || $deviceStatusMap[$rowDevId] === '-1') {
                            continue;
                        }
                    }
                    $rowTs = $rowTimeGetter($row);
                    if ($rowTs <= 0) {
                        continue;
                    }
                    $inThisYearRange = ($startTs !== false && $endTs !== false && $rowTs >= $startTs && $rowTs <= $endTs);
                    $inLastYearRange = ($lastStartTs !== false && $lastEndTs !== false && $rowTs >= $lastStartTs && $rowTs <= $lastEndTs);
                    if ($inThisYearRange) {
                        $m = (int)date('n', $rowTs);
                        if ($m >= 1 && $m <= 12) {
                            // Keep month trend independent from alarm-level parse success.
                            $monthCounts[$m] = (int)($monthCounts[$m] ?? 0) + 1;
                        }
                    }
                    $rawLevel = $pickRowValueByFields($row, $alarmLevelFieldCandidates);
                    if ($rawLevel === '') {
                        continue;
                    }
                    $bucket = $resolveLevelBucket($rawLevel);
                    if (($bucket < 1 || $bucket > 5) && trim($rawLevel) !== '') {
                        $dynamicKey = strtolower(trim($rawLevel));
                        if ($dynamicKey !== '') {
                            if (!isset($dynamicBucketByRawLevel[$dynamicKey]) && $dynamicBucketNext <= 5) {
                                $dynamicBucketByRawLevel[$dynamicKey] = $dynamicBucketNext++;
                            }
                            if (isset($dynamicBucketByRawLevel[$dynamicKey])) {
                                $bucket = (int)$dynamicBucketByRawLevel[$dynamicKey];
                            }
                        }
                    }
                    if ($bucket < 1 || $bucket > 5) {
                        continue;
                    }
                    if ($inThisYearRange) {
                        $k = (string)$bucket;
                        $thisYearCounts[$k] = (int)($thisYearCounts[$k] ?? 0) + 1;
                    }
                    if ($inLastYearRange) {
                        $k = (string)$bucket;
                        $lastYearCounts[$k] = (int)($lastYearCounts[$k] ?? 0) + 1;
                    }
                }
                $thisYearRows = [];
                foreach ($thisYearCounts as $grp => $cnt) {
                    $thisYearRows[] = ['grp' => $grp, 'cnt' => $cnt];
                }
                $lastYearRows = [];
                foreach ($lastYearCounts as $grp => $cnt) {
                    $lastYearRows[] = ['grp' => $grp, 'cnt' => $cnt];
                }
                $monthRows = [];
                foreach ($monthCounts as $grp => $cnt) {
                    $monthRows[] = ['grp' => $grp, 'cnt' => $cnt];
                }
            } catch (\Throwable $e) {
            }
        }

        $thisYearMap = [];
        $dynamicBucketByGroupedLevel = [];
        $dynamicGroupedBucketNext = 1;
        foreach ($thisYearRows as $row) {
            $rawKey = trim((string)($row['grp'] ?? ''));
            if ($rawKey === '') {
                continue;
            }
            $bucket = $resolveLevelBucket($rawKey);
            if (($bucket < 1 || $bucket > 5) && $rawKey !== '') {
                $dynamicKey = strtolower($rawKey);
                if (!isset($dynamicBucketByGroupedLevel[$dynamicKey]) && $dynamicGroupedBucketNext <= 5) {
                    $dynamicBucketByGroupedLevel[$dynamicKey] = $dynamicGroupedBucketNext++;
                }
                if (isset($dynamicBucketByGroupedLevel[$dynamicKey])) {
                    $bucket = (int)$dynamicBucketByGroupedLevel[$dynamicKey];
                }
            }
            if ($bucket < 1 || $bucket > 5) {
                continue;
            }
            $key = (string)$bucket;
            $thisYearMap[$key] = (int)($thisYearMap[$key] ?? 0) + (int)($row['cnt'] ?? 0);
        }
        $lastYearMap = [];
        foreach ($lastYearRows as $row) {
            $rawKey = trim((string)($row['grp'] ?? ''));
            if ($rawKey === '') {
                continue;
            }
            $bucket = $resolveLevelBucket($rawKey);
            if (($bucket < 1 || $bucket > 5) && $rawKey !== '') {
                $dynamicKey = strtolower($rawKey);
                if (!isset($dynamicBucketByGroupedLevel[$dynamicKey]) && $dynamicGroupedBucketNext <= 5) {
                    $dynamicBucketByGroupedLevel[$dynamicKey] = $dynamicGroupedBucketNext++;
                }
                if (isset($dynamicBucketByGroupedLevel[$dynamicKey])) {
                    $bucket = (int)$dynamicBucketByGroupedLevel[$dynamicKey];
                }
            }
            if ($bucket < 1 || $bucket > 5) {
                continue;
            }
            $key = (string)$bucket;
            $lastYearMap[$key] = (int)($lastYearMap[$key] ?? 0) + (int)($row['cnt'] ?? 0);
        }
        $monthMap = [];
        foreach ($monthRows as $row) {
            $m = (int)($row['grp'] ?? 0);
            if ($m >= 1 && $m <= 12) {
                $monthMap[$m] = (int)($row['cnt'] ?? 0);
            }
        }
        $sumMapValues = static function (array $map): int {
            $sum = 0;
            foreach ($map as $v) {
                $sum += (int)$v;
            }
            return $sum;
        };
        if ($sumMapValues($thisYearMap) === 0 && $sumMapValues($lastYearMap) === 0 && $sumMapValues($monthMap) === 0) {
            try {
                $rows = self::statsCrud('dcim-alarmlist')->selectByRawCondition('1=1', '', []);
                $deviceStatusMap = [];
                if ($devField !== '' && $deviceIdField !== '') {
                    try {
                        $deviceRows = self::statsCrud('dcim-device')->selectByRawCondition('1=1', '', []);
                        foreach ($deviceRows as $deviceRow) {
                            $did = trim((string)($deviceRow[$deviceIdField] ?? ''));
                            if ($did === '') {
                                continue;
                            }
                            $dst = $deviceStatusField !== '' ? trim((string)($deviceRow[$deviceStatusField] ?? '')) : '1';
                            $deviceStatusMap[$did] = $dst;
                        }
                    } catch (\Throwable $e) {
                    }
                }
                $debugScan = [
                    'raw_row_count' => is_array($rows) ? count($rows) : 0,
                    'status_filtered' => 0,
                    'deleted_device_filtered' => 0,
                    'time_invalid' => 0,
                    'level_empty' => 0,
                    'level_unmapped' => 0,
                    'time_samples' => [],
                    'level_samples' => [],
                ];
                $pickRowValueByFields = static function (array $row, array $fields): string {
                    foreach ($fields as $field) {
                        $value = trim((string)($row[$field] ?? ''));
                        if ($value !== '') {
                            return $value;
                        }
                    }
                    foreach ($row as $k => $v) {
                        $key = strtolower((string)$k);
                        foreach ($fields as $field) {
                            if (strtolower((string)$field) === $key) {
                                $value = trim((string)$v);
                                if ($value !== '') {
                                    return $value;
                                }
                            }
                        }
                    }
                    return '';
                };
                $parseTs = static function (string $raw): int {
                    $raw = trim($raw);
                    if ($raw === '') {
                        return 0;
                    }
                    if (preg_match('/^\d{10,17}$/', $raw) === 1) {
                        $num = (float)$raw;
                        $len = strlen($raw);
                        if ($len <= 10) {
                            return (int)$num;
                        }
                        if ($len <= 13) {
                            return (int)floor($num / 1000);
                        }
                        $scale = pow(10, $len - 10);
                        if ($scale > 0) {
                            return (int)floor($num / $scale);
                        }
                    }
                    if (preg_match('/^\d{8}$/', $raw) === 1) {
                        $ts = strtotime(substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2) . ' 00:00:00');
                        return $ts === false ? 0 : (int)$ts;
                    }
                    if (preg_match('/^\d{14}$/', $raw) === 1) {
                        $ts = strtotime(
                            substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2)
                            . ' ' . substr($raw, 8, 2) . ':' . substr($raw, 10, 2) . ':' . substr($raw, 12, 2)
                        );
                        return $ts === false ? 0 : (int)$ts;
                    }
                    $ts = strtotime($raw);
                    if ($ts === false) {
                        $ts = strtotime((string)preg_replace('/\.\d+$/', '', $raw));
                    }
                    return $ts === false ? 0 : (int)$ts;
                };
                $startTs = strtotime($startDateTime);
                $endTs = strtotime($endDateTime);
                $lastStartTs = strtotime($lastYearStart);
                $lastEndTs = strtotime($lastYearEnd);
                $rebuiltThisYearMap = [];
                $rebuiltLastYearMap = [];
                $rebuiltMonthMap = [];
                $dynamicBucketByRawLevel = [];
                $dynamicBucketNext = 1;
                foreach ($rows as $row) {
                    if ($alarmStatusField !== '') {
                        $st = trim((string)($row[$alarmStatusField] ?? ''));
                        if ($st === '-1') {
                            $debugScan['status_filtered']++;
                            continue;
                        }
                    }
                    if ($alarmBizStatusField !== '') {
                        $bizSt = trim((string)($row[$alarmBizStatusField] ?? ''));
                        if ($bizSt !== '1' && $bizSt !== '2' && $bizSt !== '3') {
                            $debugScan['status_filtered']++;
                            continue;
                        }
                    }
                    if ($devField !== '') {
                        $rowDevId = trim((string)($row[$devField] ?? ''));
                        if ($rowDevId === '' || !isset($deviceStatusMap[$rowDevId]) || $deviceStatusMap[$rowDevId] === '-1') {
                            $debugScan['deleted_device_filtered']++;
                            continue;
                        }
                    }
                    $rawTime = $pickRowValueByFields($row, $timeFieldCandidates);
                    if ($rawTime !== '' && count($debugScan['time_samples']) < 8) {
                        $debugScan['time_samples'][] = $rawTime;
                    }
                    $rowTs = $parseTs($rawTime);
                    if ($rowTs <= 0) {
                        $debugScan['time_invalid']++;
                        continue;
                    }
                    $inThisYearRange = ($startTs !== false && $endTs !== false && $rowTs >= $startTs && $rowTs <= $endTs);
                    $inLastYearRange = ($lastStartTs !== false && $lastEndTs !== false && $rowTs >= $lastStartTs && $rowTs <= $lastEndTs);
                    if ($inThisYearRange) {
                        $m = (int)date('n', $rowTs);
                        if ($m >= 1 && $m <= 12) {
                            $rebuiltMonthMap[$m] = (int)($rebuiltMonthMap[$m] ?? 0) + 1;
                        }
                    }
                    $rawLevel = $pickRowValueByFields($row, $alarmLevelFieldCandidates);
                    if ($rawLevel === '') {
                        $debugScan['level_empty']++;
                        continue;
                    }
                    if (count($debugScan['level_samples']) < 8) {
                        $debugScan['level_samples'][] = $rawLevel;
                    }
                    $bucket = $resolveLevelBucket($rawLevel);
                    if (($bucket < 1 || $bucket > 5) && trim($rawLevel) !== '') {
                        $dynamicKey = strtolower(trim($rawLevel));
                        if (!isset($dynamicBucketByRawLevel[$dynamicKey]) && $dynamicBucketNext <= 5) {
                            $dynamicBucketByRawLevel[$dynamicKey] = $dynamicBucketNext++;
                        }
                        if (isset($dynamicBucketByRawLevel[$dynamicKey])) {
                            $bucket = (int)$dynamicBucketByRawLevel[$dynamicKey];
                        }
                    }
                    if ($bucket < 1 || $bucket > 5) {
                        $debugScan['level_unmapped']++;
                        continue;
                    }
                    $k = (string)$bucket;
                    if ($inThisYearRange) {
                        $rebuiltThisYearMap[$k] = (int)($rebuiltThisYearMap[$k] ?? 0) + 1;
                    }
                    if ($inLastYearRange) {
                        $rebuiltLastYearMap[$k] = (int)($rebuiltLastYearMap[$k] ?? 0) + 1;
                    }
                }
                if ($sumMapValues($rebuiltMonthMap) > 0 || $sumMapValues($rebuiltThisYearMap) > 0 || $sumMapValues($rebuiltLastYearMap) > 0) {
                    $monthMap = $rebuiltMonthMap;
                    $thisYearMap = $rebuiltThisYearMap;
                    $lastYearMap = $rebuiltLastYearMap;
                }
                if ($debugEnabled) {
                    $debugScan['rebuilt_sums'] = [
                        'thisYear' => $sumMapValues($rebuiltThisYearMap),
                        'lastYear' => $sumMapValues($rebuiltLastYearMap),
                        'month' => $sumMapValues($rebuiltMonthMap),
                    ];
                    $debugInfo['forced_rebuild'] = $debugScan;
                }
            } catch (\Throwable $e) {
                if ($debugEnabled) {
                    $debugInfo['forced_rebuild_error'] = $e->getMessage();
                }
            }
        }

        $thisMonth = [];
        if ($isMonthType) {
            $monthBaseTs = $startDateTime !== '' ? strtotime($startDateTime) : time();
            if ($monthBaseTs === false) {
                $monthBaseTs = time();
            }
            $monthStart = date('Y-m-01 00:00:00', $monthBaseTs);
            $monthEnd = date('Y-m-t 23:59:59', $monthBaseTs);
            $monthStartTs = strtotime($monthStart);
            $monthEndTs = strtotime($monthEnd);
            $daysInMonth = (int)date('t', $monthBaseTs);
            $dayMap = [];

            $monthConds = ['1=1'];
            $monthParams = [];
            if ($alarmStatusField !== '') {
                $qStatusField = self::statsQuoteIdent($alarmStatusField);
                $monthConds[] = '(a.' . $qStatusField . ' <> -1 OR a.' . $qStatusField . ' IS NULL)';
            }
            if ($alarmBizStatusField !== '') {
                $qBizStatusField = self::statsQuoteIdent($alarmBizStatusField);
                $monthConds[] = 'a.' . $qBizStatusField . ' IN (1,2,3)';
            }
            if ($timeField !== '') {
                $qTimeField = self::statsQuoteIdent($timeField);
                if ($timeFieldIsCompactDate) {
                    $monthConds[] = 'a.' . $qTimeField . ' BETWEEN :month_start AND :month_end';
                    $monthParams[':month_start'] = date('Ymd', $monthStartTs ?: $monthBaseTs);
                    $monthParams[':month_end'] = date('Ymd', $monthEndTs ?: $monthBaseTs);
                } else {
                    $monthConds[] = 'a.' . $qTimeField . ' BETWEEN :month_start AND :month_end';
                    $monthParams[':month_start'] = $monthStart;
                    $monthParams[':month_end'] = $monthEnd;
                }
            }
            if ($devField !== '' && $deviceIdField !== '') {
                $existsSql = 'EXISTS (SELECT 1 FROM ' . self::statsQuoteIdent('dcim-device') . ' d WHERE d.' . self::statsQuoteIdent($deviceIdField) . ' = a.' . self::statsQuoteIdent($devField);
                if ($deviceStatusField !== '') {
                    $qDevStatusField = self::statsQuoteIdent($deviceStatusField);
                    $existsSql .= ' AND (d.' . $qDevStatusField . ' <> -1 OR d.' . $qDevStatusField . ' IS NULL)';
                }
                if ($serverCodes && $deviceServerCodeField !== '') {
                    $holders = [];
                    foreach ($serverCodes as $idx => $serverCode) {
                        $ph = ':month_server_' . $idx;
                        $holders[] = $ph;
                        $monthParams[$ph] = $serverCode;
                    }
                    if ($holders) {
                        $existsSql .= ' AND d.' . self::statsQuoteIdent($deviceServerCodeField) . ' IN (' . implode(', ', $holders) . ')';
                    }
                }
                $existsSql .= ')';
                $monthConds[] = $existsSql;
            }

            $timeRows = [];
            if ($timeField !== '') {
                $monthSql = 'SELECT a.' . self::statsQuoteIdent($timeField) . ' AS t'
                    . ' FROM ' . self::statsQuoteIdent('dcim-alarmlist') . ' a'
                    . ' WHERE ' . implode(' AND ', $monthConds);
                try {
                    $stmt = Flight::db()->prepare($monthSql);
                    foreach ($monthParams as $ph => $val) {
                        $stmt->bindValue($ph, $val);
                    }
                    $stmt->execute();
                    $timeRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (\Throwable $e) {
                    $timeRows = [];
                }
            }
            $parseTs = static function (string $raw): int {
                $raw = trim($raw);
                if ($raw === '') {
                    return 0;
                }
                if (preg_match('/^\d{8}$/', $raw) === 1) {
                    $ts = strtotime(substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2) . ' 00:00:00');
                    return $ts === false ? 0 : (int)$ts;
                }
                if (preg_match('/^\d{14}$/', $raw) === 1) {
                    $ts = strtotime(
                        substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2)
                        . ' ' . substr($raw, 8, 2) . ':' . substr($raw, 10, 2) . ':' . substr($raw, 12, 2)
                    );
                    return $ts === false ? 0 : (int)$ts;
                }
                $ts = strtotime($raw);
                if ($ts === false) {
                    $ts = strtotime((string)preg_replace('/\.\d+$/', '', $raw));
                }
                return $ts === false ? 0 : (int)$ts;
            };
            foreach ($timeRows as $tr) {
                $ts = $parseTs((string)($tr['t'] ?? ''));
                if ($ts <= 0) {
                    continue;
                }
                if ($monthStartTs !== false && $ts < $monthStartTs) {
                    continue;
                }
                if ($monthEndTs !== false && $ts > $monthEndTs) {
                    continue;
                }
                $day = (int)date('j', $ts);
                if ($day >= 1 && $day <= $daysInMonth) {
                    $dayMap[$day] = (int)($dayMap[$day] ?? 0) + 1;
                }
            }
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $thisMonth[] = [
                    'time' => str_pad((string)$d, 2, '0', STR_PAD_LEFT),
                    'number' => (int)($dayMap[$d] ?? 0),
                ];
            }
        } else {
            for ($m = 1; $m <= 12; $m++) {
                $thisMonth[] = [
                    'time' => str_pad((string)$m, 2, '0', STR_PAD_LEFT),
                    'number' => (int)($monthMap[$m] ?? 0),
                ];
            }
        }

        $thisYearNumber = [];
        $lastYearNumber = [];
        for ($i = 1; $i <= 5; $i++) {
            $levelId = (string)$i;
            $name = trim((string)($fixedLevelNames[$i - 1] ?? ''));
            if ($name === '') {
                $name = $levelMap[$levelId] ?? ('Level' . $levelId);
            }
            $thisYearNumber[] = [
                'LevelName' => $name,
                'LevelNumber' => (int)($thisYearMap[$levelId] ?? 0),
            ];
            $lastYearNumber[] = [
                'LevelName' => $name,
                'LevelNumber' => (int)($lastYearMap[$levelId] ?? 0),
            ];
        }

        $payload = [
            'thisMonth' => $thisMonth,
            'thisYearNumber' => $thisYearNumber,
            'lastYearNumber' => $lastYearNumber,
        ];
        if ($debugEnabled) {
            $debugInfo['result_sums'] = [
                'thisMonth' => array_sum(array_map(static function ($item) { return (int)($item['number'] ?? 0); }, $thisMonth)),
                'thisYear' => array_sum(array_map(static function ($item) { return (int)($item['LevelNumber'] ?? 0); }, $thisYearNumber)),
                'lastYear' => array_sum(array_map(static function ($item) { return (int)($item['LevelNumber'] ?? 0); }, $lastYearNumber)),
            ];
            $payload['debug'] = $debugInfo;
        }
        O_E($payload, tp_msg_success(), 100, 0);
    }

    public static function statsGroupAlarmStatistic()
    {
        $data = Flight::request_data();
        self::requireAuthStrict($data);
        $type = strtolower(trim((string)($data['type'] ?? 'level')));
        if ($type === '') {
            $type = 'level';
        }
        if (!in_array($type, ['level', 'area', 'device'], true)) {
            $type = 'level';
        }

        $alarmCols = self::statsTableColumns('dcim-alarmlist');
        $deviceCols = self::statsTableColumns('dcim-device');
        $areaCols = self::statsTableColumns('dcim-area');
        $levelCols = self::statsTableColumns('dcim-alarmlevellist');

        $serverCols = self::statsTableColumns('dcim-server');

        $alarmIdField = self::statsPickColumn($alarmCols, ['id']);
        $alarmStatusField = self::statsPickColumn($alarmCols, ['status']);
        $alarmBizStatusField = self::statsPickColumn($alarmCols, ['AlarmStatus', 'alarmstatus']);
        $alarmLevelField = self::statsPickColumn($alarmCols, ['AlarmLevel', 'LevelId', 'LevelID', 'Level']);
        $alarmDevField = self::statsPickColumn($alarmCols, ['DevId', 'DevID', 'DeviceId', 'DeviceID']);
        $alarmTimeField = self::statsPickColumn($alarmCols, ['create_time', 'CreateTime']);

        $deviceIdField = self::statsPickColumn($deviceCols, ['id', 'DevId', 'DevID', 'DeviceId', 'DeviceID']);
        $deviceNameField = self::statsPickColumn($deviceCols, ['DeviceName', 'DevName', 'Name']);
        $deviceAreaField = self::statsPickColumn($deviceCols, ['AreaId']);
        $deviceStatusField = self::statsPickColumn($deviceCols, ['status']);
        $deviceServerField = self::statsPickColumn($deviceCols, ['ServerCode', 'ServerID', 'ServerId']);

        $areaIdField = self::statsPickColumn($areaCols, ['id', 'AreaId', 'AreaID', 'area_id']);
        $areaNameField = self::statsPickColumn($areaCols, ['AreaName', 'Name']);
        $areaServerField = self::statsPickColumn($areaCols, ['ServerCode', 'ServerID', 'ServerId']);

        $levelIdField = self::statsPickColumn($levelCols, ['id', 'LevelId', 'LevelID']);
        $levelNameField = self::statsPickColumn($levelCols, ['LevelName', 'Name']);
        $serverIdField = self::statsPickColumn($serverCols, ['id']);
        $serverCodeField = self::statsPickColumn($serverCols, ['ServerCode', 'Code']);

        if (
            $alarmStatusField === '' || $alarmBizStatusField === '' || $alarmDevField === ''
            || $deviceIdField === '' || $deviceStatusField === ''
        ) {
            O_E([], tp_msg_success(), 100, 0);
            return;
        }
        if ($type === 'level' && $alarmLevelField === '') {
            O_E([], tp_msg_success(), 100, 0);
            return;
        }
        if ($type === 'area' && $areaNameField === '') {
            O_E([], tp_msg_success(), 100, 0);
            return;
        }
        if ($type === 'device' && $deviceNameField === '') {
            O_E([], tp_msg_success(), 100, 0);
            return;
        }

        $conditions = [];
        $params = [];
        $conditions[] = 'a.' . self::statsQuoteIdent($alarmStatusField) . ' = 1';
        $conditions[] = 'a.' . self::statsQuoteIdent($alarmBizStatusField) . ' > 0';

        $serverCode = trim((string)($data['ServerCode'] ?? ''));
        if ($serverCode !== '' && $areaServerField !== '' && $areaIdField !== '' && $deviceAreaField !== '') {
            $conditions[] = 'aa.' . self::statsQuoteIdent($areaServerField) . ' = :serverCode';
            $params[':serverCode'] = $serverCode;
        } elseif ($serverCode !== '' && $deviceServerField !== '') {
            $conditions[] = 'ar.' . self::statsQuoteIdent($deviceServerField) . ' = :serverCode';
            $params[':serverCode'] = $serverCode;
        }

        $normalizeDate = static function (string $raw): string {
            $raw = trim($raw);
            if ($raw === '') {
                return '';
            }
            if (preg_match('/^\d{10}$/', $raw) === 1) {
                $ts = (int)$raw;
                return $ts > 0 ? date('Y-m-d H:i:s', $ts) : '';
            }
            if (preg_match('/^\d{13}$/', $raw) === 1) {
                $ts = (int)floor(((float)$raw) / 1000);
                return $ts > 0 ? date('Y-m-d H:i:s', $ts) : '';
            }
            if (preg_match('/^\d{16,17}$/', $raw) === 1) {
                $scale = pow(10, strlen($raw) - 10);
                if ($scale > 0) {
                    $ts = (int)floor(((float)$raw) / $scale);
                    return $ts > 0 ? date('Y-m-d H:i:s', $ts) : '';
                }
            }
            if (preg_match('/^\d{8}$/', $raw) === 1) {
                $ts = strtotime(substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2) . ' 00:00:00');
                return $ts === false ? '' : date('Y-m-d H:i:s', $ts);
            }
            if (preg_match('/^\d{14}$/', $raw) === 1) {
                $ts = strtotime(
                    substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2)
                    . ' ' . substr($raw, 8, 2) . ':' . substr($raw, 10, 2) . ':' . substr($raw, 12, 2)
                );
                return $ts === false ? '' : date('Y-m-d H:i:s', $ts);
            }
            $ts = strtotime($raw);
            if ($ts === false) {
                $ts = strtotime((string)preg_replace('/\.\d+$/', '', $raw));
            }
            if ($ts === false) {
                return '';
            }
            return date('Y-m-d H:i:s', $ts);
        };
        $startDateTime = $normalizeDate((string)($data['startDateTime'] ?? ''));
        $endDateTime = $normalizeDate((string)($data['endDateTime'] ?? ''));
        if ($alarmTimeField !== '' && $startDateTime !== '') {
            if ($endDateTime === '') {
                $endDateTime = $startDateTime;
            }
            $timeTextExpr = 'TRIM(CAST(a.' . self::statsQuoteIdent($alarmTimeField) . ' AS VARCHAR(64)))';
            $timeCompactExpr = 'REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(' . $timeTextExpr . ", '-', ''), ':', ''), ' ', ''), '/', ''), '.', '')";
            $startTs = strtotime($startDateTime);
            $endTs = strtotime($endDateTime);
            $conditions[] = '('
                . $timeCompactExpr . ' BETWEEN :startDateCompact AND :endDateCompact'
                . ' OR (REGEXP_LIKE(' . $timeTextExpr . ", '^[0-9]{10}$') AND " . $timeTextExpr . ' BETWEEN :startTsText AND :endTsText)'
                . ')';
            $params[':startDateCompact'] = date('YmdHis', $startTs !== false ? $startTs : 0);
            $params[':endDateCompact'] = date('YmdHis', $endTs !== false ? $endTs : 0);
            $params[':startTsText'] = (string)($startTs !== false ? (int)$startTs : 0);
            $params[':endTsText'] = (string)($endTs !== false ? (int)$endTs : 0);
        }

        $fromSql = ' FROM ' . self::statsQuoteIdent('dcim-alarmlist') . ' a'
            . ' INNER JOIN ' . self::statsQuoteIdent('dcim-device') . ' ar'
            . ' ON ar.' . self::statsQuoteIdent($deviceIdField) . ' = a.' . self::statsQuoteIdent($alarmDevField)
            . ' AND ar.' . self::statsQuoteIdent($deviceStatusField) . ' = 1';

        if ($areaIdField !== '' && $deviceAreaField !== '') {
            $fromSql .= ' LEFT JOIN ' . self::statsQuoteIdent('dcim-area') . ' aa'
                . ' ON aa.' . self::statsQuoteIdent($areaIdField) . ' = ar.' . self::statsQuoteIdent($deviceAreaField);
        }

        if ($deviceServerField !== '' && $serverIdField !== '' && $serverCodeField !== '') {
            $fromSql .= ' LEFT JOIN ' . self::statsQuoteIdent('dcim-server') . ' sv'
                . ' ON (ar.' . self::statsQuoteIdent($deviceServerField) . ' = sv.' . self::statsQuoteIdent($serverIdField)
                . ' OR ar.' . self::statsQuoteIdent($deviceServerField) . ' = sv.' . self::statsQuoteIdent($serverCodeField) . ')';
        }

        $countExpr = $alarmIdField !== ''
            ? 'COUNT(DISTINCT a.' . self::statsQuoteIdent($alarmIdField) . ')'
            : 'COUNT(*)';
        $selectSql = '';
        $groupSql = '';
        $orderSql = '';
        if ($type === 'level') {
            if ($levelIdField !== '' && $levelNameField !== '') {
                $fromSql .= ' LEFT JOIN ' . self::statsQuoteIdent('dcim-alarmlevellist') . ' lv'
                    . ' ON lv.' . self::statsQuoteIdent($levelIdField) . ' = a.' . self::statsQuoteIdent($alarmLevelField);
                $selectSql = 'SELECT COALESCE(lv.' . self::statsQuoteIdent($levelNameField) . ", '') AS LevelName, " . $countExpr . ' AS LevelNumber';
                $groupSql = ' GROUP BY lv.' . self::statsQuoteIdent($levelIdField) . ', lv.' . self::statsQuoteIdent($levelNameField) . ', a.' . self::statsQuoteIdent($alarmLevelField);
                $orderSql = ' ORDER BY a.' . self::statsQuoteIdent($alarmLevelField);
            } else {
                $selectSql = 'SELECT a.' . self::statsQuoteIdent($alarmLevelField) . ' AS LevelName, ' . $countExpr . ' AS LevelNumber';
                $groupSql = ' GROUP BY a.' . self::statsQuoteIdent($alarmLevelField);
                $orderSql = ' ORDER BY a.' . self::statsQuoteIdent($alarmLevelField);
            }
        } elseif ($type === 'area') {
            if ($areaIdField === '' || $areaNameField === '' || $deviceAreaField === '') {
                O_E([], tp_msg_success(), 100, 0);
                return;
            }
            $selectSql = 'SELECT COALESCE(aa.' . self::statsQuoteIdent($areaNameField) . ", '') AS AreaName, " . $countExpr . ' AS AreaNumber';
            $groupSql = ' GROUP BY aa.' . self::statsQuoteIdent($areaIdField) . ', aa.' . self::statsQuoteIdent($areaNameField);
            $orderSql = ' ORDER BY aa.' . self::statsQuoteIdent($areaIdField);
        } else {
            $selectSql = 'SELECT ar.' . self::statsQuoteIdent($deviceIdField) . " AS DevId, COALESCE(ar." . self::statsQuoteIdent($deviceNameField) . ", '') AS DeviceName, " . $countExpr . ' AS DeviceNumber';
            $groupSql = ' GROUP BY ar.' . self::statsQuoteIdent($deviceIdField) . ', ar.' . self::statsQuoteIdent($deviceNameField);
            $orderSql = ' ORDER BY ar.' . self::statsQuoteIdent($deviceIdField);
        }

        $whereSql = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';
        $sql = $selectSql . $fromSql . $whereSql . $groupSql . $orderSql;

        $rows = [];
        try {
            $stmt = Flight::db()->prepare($sql);
            foreach ($params as $ph => $val) {
                $stmt->bindValue($ph, $val);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[GroupAlarmStatisticKey] sql failed: ' . $e->getMessage() . '; sql=' . $sql . '; params=' . json_encode($params, JSON_UNESCAPED_UNICODE));
            $rows = [];
        }

        $out = [];
        foreach ($rows as $row) {
            $rowArr = (array)$row;
            if ($type === 'level') {
                $levelName = self::edPickField($rowArr, ['LevelName', 'LEVELNAME']);
                $levelNumberRaw = self::edPickField($rowArr, ['LevelNumber', 'LEVELNUMBER'], '0');
                $out[] = [
                    'LevelName' => trim($levelName),
                    'LevelNumber' => (int)$levelNumberRaw,
                ];
            } elseif ($type === 'area') {
                $areaName = self::edPickField($rowArr, ['AreaName', 'AREANAME']);
                $areaNumberRaw = self::edPickField($rowArr, ['AreaNumber', 'AREANUMBER'], '0');
                $out[] = [
                    'AreaName' => trim($areaName),
                    'AreaNumber' => (int)$areaNumberRaw,
                ];
            } else {
                $devId = self::edPickField($rowArr, ['DevId', 'DEVID']);
                $deviceName = self::edPickField($rowArr, ['DeviceName', 'DEVICENAME']);
                $deviceNumberRaw = self::edPickField($rowArr, ['DeviceNumber', 'DEVICENUMBER'], '0');
                $out[] = [
                    'DevId' => (string)$devId,
                    'DeviceName' => trim($deviceName),
                    'DeviceNumber' => (int)$deviceNumberRaw,
                ];
            }
        }
        O_E($out, tp_msg_success(), 100, $out ? count($out) : 0);
    }
    public static function statsGetSparepartsStatistic()
    {
        self::requireAuthStrict(Flight::request_data());
        $cols = self::statsTableColumns('dcim-spareparts');
        if (!$cols) {
            self::statsOk([]);
            return;
        }
        $statusField = self::statsPickColumn($cols, ['status']);
        $nameField = self::statsPickColumn($cols, ['BackupsName', 'SparepartsName', 'Name', 'Title']);
        $countField = self::statsPickColumn($cols, ['BackupsNum', 'SparepartsNum', 'Number', 'Count', 'Qty', 'Stock']);
        $idField = self::statsPickColumn($cols, ['id', 'Lsh']);
        if ($idField === '') {
            self::statsOk([]);
            return;
        }
        $where = $statusField !== '' ? ('(' . self::statsQuoteIdent($statusField) . ' <> -1 OR ' . self::statsQuoteIdent($statusField) . ' IS NULL)') : '1=1';
        $qId = self::statsQuoteIdent($idField);
        $qName = $nameField !== '' ? self::statsQuoteIdent($nameField) : "''";
        $sumExpr = $countField !== '' ? ('COALESCE(' . self::statsQuoteIdent($countField) . ',0)') : '1';
        $sql = 'SELECT ' . $qId . ' AS sid, ' . $qName . ' AS sname, ' . $sumExpr . ' AS scount'
            . ' FROM ' . self::statsQuoteIdent('dcim-spareparts')
            . ' WHERE ' . $where
            . ' ORDER BY ' . $qId . ' DESC';
        $rows = [];
        try {
            $stmt = Flight::db()->query($sql);
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\Throwable $e) {
            $rows = [];
        }
        if (!$rows && $statusField !== '') {
            $sql = 'SELECT ' . $qId . ' AS sid, ' . $qName . ' AS sname, ' . $sumExpr . ' AS scount'
                . ' FROM ' . self::statsQuoteIdent('dcim-spareparts')
                . ' WHERE 1=1'
                . ' ORDER BY ' . $qId . ' DESC';
            try {
                $stmt = Flight::db()->query($sql);
                $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            } catch (\Throwable $e) {
                $rows = [];
            }
        }
        if (!$rows) {
            try {
                $typeRows = self::statsCrud('dcim-spareparts')->selectByRawCondition('1=1', 'ORDER BY ' . $qId . ' DESC', []);
                foreach ($typeRows as $typeRow) {
                    $sid = (string)($typeRow[$idField] ?? '');
                    if ($sid === '') {
                        continue;
                    }
                    $rows[] = [
                        'sid' => $sid,
                        'sname' => (string)($nameField !== '' ? ($typeRow[$nameField] ?? '') : ''),
                        'scount' => (int)($countField !== '' ? ($typeRow[$countField] ?? 0) : 0),
                    ];
                }
            } catch (\Throwable $e) {
            }
        }
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'SparepartsId' => (string)($row['sid'] ?? ''),
                'SparepartsName' => (string)($row['sname'] ?? ''),
                'SparepartsNumber' => (int)($row['scount'] ?? 0),
            ];
        }
        O_E($list, tp_msg_success(), 100, $list ? count($list) : 0);
    }
    public static function statsGetClassNXInfo()
    {
        self::requireAuthStrict(Flight::request_data());
        self::statsOk([]);
    }

    public static function statsGetNXPowerInfo()
    {
        self::requireAuthStrict(Flight::request_data());
        self::statsOk([]);
    }

    public static function statsGetNXInfo()
    {
        $data = Flight::request_data();
        self::requireAuthStrict($data);

        $numVal = static function ($raw): float {
            $s = trim((string)$raw);
            if ($s === '') {
                return 0.0;
            }
            $s = str_replace(',', '', $s);
            if (preg_match('/-?\d+(\.\d+)?/', $s, $m)) {
                return (float)$m[0];
            }
            return 0.0;
        };
        $getVal = static function (array $row, array $keys, $default = '') {
            foreach ($keys as $k) {
                if ($k !== '' && array_key_exists($k, $row)) {
                    return $row[$k];
                }
            }
            $lower = [];
            foreach ($row as $k => $v) {
                $lower[strtolower((string)$k)] = $v;
            }
            foreach ($keys as $k) {
                $lk = strtolower((string)$k);
                if ($lk !== '' && array_key_exists($lk, $lower)) {
                    return $lower[$lk];
                }
            }
            return $default;
        };

        $areaId = trim((string)($data['AreaId'] ?? ''));
        $serverCode = trim((string)($data['ServerCode'] ?? ''));
        $expandedAreaIds = [];
        if ($areaId !== '') {
            $expandedAreaIds[$areaId] = true;
            $areaCols = self::statsTableColumns('dcim-area');
            $areaIdField = self::statsPickColumn($areaCols, ['id']);
            $areaParentField = self::statsPickColumn($areaCols, ['AreaParentId', 'ParentId']);
            $areaStatusField = self::statsPickColumn($areaCols, ['status']);
            if ($areaIdField !== '' && $areaParentField !== '') {
                $awhere = ['1=1'];
                if ($areaStatusField !== '') {
                    $qAreaStatusField = self::statsQuoteIdent($areaStatusField);
                    $awhere[] = '(' . $qAreaStatusField . ' <> -1 OR ' . $qAreaStatusField . ' IS NULL)';
                }
                try {
                    $areaRows = self::statsCrud('dcim-area')->selectByRawCondition(implode(' AND ', $awhere), '', []);
                    $children = [];
                    foreach ($areaRows as $arow) {
                        $cid = trim((string)($arow[$areaIdField] ?? ''));
                        $pid = trim((string)($arow[$areaParentField] ?? '0'));
                        if ($cid === '') {
                            continue;
                        }
                        if (!isset($children[$pid])) {
                            $children[$pid] = [];
                        }
                        $children[$pid][] = $cid;
                    }
                    $queue = [$areaId];
                    while ($queue) {
                        $cur = (string)array_shift($queue);
                        if (isset($children[$cur])) {
                            foreach ($children[$cur] as $childId) {
                                if (!isset($expandedAreaIds[$childId])) {
                                    $expandedAreaIds[$childId] = true;
                                    $queue[] = $childId;
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                }
            }
        }
        $expandedAreaIds = array_values(array_keys($expandedAreaIds));

        $typeList = [];
        $sumMap = [];
        $nydnCols = self::statsTableColumns('dcim-nydnrecordday');
        $nyclassCols = self::statsTableColumns('dcim-nyclass');
        $devIdField = self::statsPickColumn($nydnCols, ['DevID']);
        $electricField = self::statsPickColumn($nydnCols, ['Electric']);
        $nyStatusField = self::statsPickColumn($nydnCols, ['status']);
        $nyAreaField = self::statsPickColumn($nydnCols, ['AreaId']);
        $nyServerField = self::statsPickColumn($nydnCols, ['ServerCode']);
        $classNameField = self::statsPickColumn($nyclassCols, ['NYClassName']);
        $classStatusField = self::statsPickColumn($nyclassCols, ['status']);
        $nyRows = [];
        if ($electricField !== '') {
            $where = [];
            $params = [];
            if ($nyStatusField !== '') {
                $qNyStatusField = self::statsQuoteIdent($nyStatusField);
                $where[] = '(a.' . $qNyStatusField . ' <> -1 OR a.' . $qNyStatusField . ' IS NULL)';
            }
            if ($expandedAreaIds && $nyAreaField !== '') {
                $holders = [];
                foreach ($expandedAreaIds as $idx => $oneAreaId) {
                    $ph = ':areaId_' . $idx;
                    $holders[] = $ph;
                    $params[$ph] = $oneAreaId;
                }
                $where[] = 'a.' . self::statsQuoteIdent($nyAreaField) . ' IN (' . implode(', ', $holders) . ')';
            }
            if ($serverCode !== '' && $nyServerField !== '') {
                $where[] = 'a.' . self::statsQuoteIdent($nyServerField) . ' = :serverCode';
                $params[':serverCode'] = $serverCode;
            }
            $whereSql = $where ? implode(' AND ', $where) : '1=1';
            try {
                $sql = 'SELECT a.* FROM ' . self::statsQuoteIdent('dcim-nydnrecordday') . ' a WHERE ' . $whereSql;
                $stmt = Flight::db()->prepare($sql);
                $stmt->execute($params);
                $nyRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                $nyRows = [];
            }
            if (!$nyRows) {
                $viewCols = self::statsTableColumns('dcim-nydnrecorddayview');
                if ($viewCols) {
                    $vAreaField = self::statsPickColumn($viewCols, ['AreaId']);
                    $vServerField = self::statsPickColumn($viewCols, ['ServerCode']);
                    $viewWhere = ['1=1'];
                    $viewParams = [];
                    if ($expandedAreaIds && $vAreaField !== '') {
                        $holders = [];
                        foreach ($expandedAreaIds as $idx => $oneAreaId) {
                            $ph = ':vAreaId_' . $idx;
                            $holders[] = $ph;
                            $viewParams[$ph] = $oneAreaId;
                        }
                        $viewWhere[] = self::statsQuoteIdent($vAreaField) . ' IN (' . implode(', ', $holders) . ')';
                    }
                    if ($serverCode !== '' && $vServerField !== '') {
                        $viewWhere[] = self::statsQuoteIdent($vServerField) . ' = :vServerCode';
                        $viewParams[':vServerCode'] = $serverCode;
                    }
                    try {
                        $viewSql = 'SELECT * FROM ' . self::statsQuoteIdent('dcim-nydnrecorddayview') . ' WHERE ' . implode(' AND ', $viewWhere);
                        $viewStmt = Flight::db()->prepare($viewSql);
                        $viewStmt->execute($viewParams);
                        $nyRows = $viewStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    } catch (\Throwable $e) {
                        $nyRows = [];
                    }
                }
            }
        }
        $totalElectric = 0.0;
        foreach ($nyRows as $nyRow) {
            $totalElectric += $numVal(
                $nyRow[$electricField] ?? ($nyRow['TotalElectric'] ?? ($nyRow['totalelectric'] ?? 0))
            );
        }

        $nyclassRows = [];
        $nyclassWhere = [];
        $nyclassParams = [];
        if ($classStatusField !== '') {
            $qClassStatusField = self::statsQuoteIdent($classStatusField);
            $nyclassWhere[] = '(' . $qClassStatusField . ' <> -1 OR ' . $qClassStatusField . ' IS NULL)';
        }
        $classAreaField = self::statsPickColumn($nyclassCols, ['AreaId']);
        $classServerField = self::statsPickColumn($nyclassCols, ['ServerCode']);
        if ($expandedAreaIds && $classAreaField !== '') {
            $holders = [];
            foreach ($expandedAreaIds as $idx => $oneAreaId) {
                $ph = ':caid_' . $idx;
                $holders[] = $ph;
                $nyclassParams[$ph] = $oneAreaId;
            }
            $nyclassWhere[] = self::statsQuoteIdent($classAreaField) . ' IN (' . implode(', ', $holders) . ')';
        }
        if ($serverCode !== '' && $classServerField !== '') {
            $nyclassWhere[] = self::statsQuoteIdent($classServerField) . ' = :cscode';
            $nyclassParams[':cscode'] = $serverCode;
        }
        $nyclassWhereSql = $nyclassWhere ? implode(' AND ', $nyclassWhere) : '1=1';
        try {
            $nyclassRows = self::statsCrud('dcim-nyclass')->selectByRawCondition($nyclassWhereSql, '', $nyclassParams);
        } catch (\Throwable $e) {
            $nyclassRows = [];
        }
        if (!$nyclassRows && $classStatusField !== '') {
            $fallbackWhere = ['(' . self::statsQuoteIdent($classStatusField) . ' <> -1 OR ' . self::statsQuoteIdent($classStatusField) . ' IS NULL)'];
            $fallbackParams = [];
            if ($serverCode !== '' && $classServerField !== '') {
                $fallbackWhere[] = self::statsQuoteIdent($classServerField) . ' = :fbServerCode';
                $fallbackParams[':fbServerCode'] = $serverCode;
            }
            try {
                $nyclassRows = self::statsCrud('dcim-nyclass')->selectByRawCondition(implode(' AND ', $fallbackWhere), '', $fallbackParams);
            } catch (\Throwable $e) {
                $nyclassRows = [];
            }
        }

        if ($nyclassRows) {
            foreach ($nyclassRows as $classRow) {
                $name = trim((string)$getVal($classRow, [$classNameField, 'NYClassName']));
                if ($name === '') {
                    continue;
                }
                $sumMap[$name] = ($sumMap[$name] ?? 0.0) + $totalElectric;
            }
        } elseif ($totalElectric > 0) {
            $fallbackTypeName = dcim_msg('app.infra_root_name');
            $sumMap[(string)$fallbackTypeName] = $totalElectric;
        }
        foreach ($sumMap as $name => $value) {
            $typeList[] = [
                'TypeName' => (string)$name,
                'TypeValue' => round((float)$value, 2),
            ];
        }
        usort($typeList, static function (array $a, array $b): int {
            return ($b['TypeValue'] <=> $a['TypeValue']);
        });

        $pueValue = 0.0;
        $pueParamField = self::statsPickColumn($nyclassCols, ['PUEId', 'PUEID', 'PueId']);
        $paramCols = self::statsTableColumns('dcim-param');
        $paramIdField = self::statsPickColumn($paramCols, ['id']);
        $paramResultField = self::statsPickColumn($paramCols, ['Result']);
        if ($pueParamField !== '' && $paramIdField !== '' && $paramResultField !== '') {
            $pueSourceRows = $nyclassRows;
            if (!$pueSourceRows) {
                $fallbackClassWhere = ['1=1'];
                if ($classStatusField !== '') {
                    $fallbackClassWhere[] = '(' . self::statsQuoteIdent($classStatusField) . ' <> -1 OR ' . self::statsQuoteIdent($classStatusField) . ' IS NULL)';
                }
                try {
                    $pueSourceRows = self::statsCrud('dcim-nyclass')->selectByRawCondition(implode(' AND ', $fallbackClassWhere), '', []);
                } catch (\Throwable $e) {
                    $pueSourceRows = [];
                }
            }
            $pueIdSet = [];
            $collectPueIds = static function ($raw) use (&$pueIdSet) {
                $pidList = self::parseLegacyIdList((string)$raw);
                if (!$pidList) {
                    $single = trim((string)$raw);
                    if ($single !== '' && ctype_digit($single)) {
                        $pidList = [$single];
                    }
                }
                foreach ($pidList as $pid) {
                    $pid = trim((string)$pid);
                    if ($pid !== '') {
                        $pueIdSet[$pid] = true;
                    }
                }
            };
            foreach ($pueSourceRows as $row) {
                $collectPueIds($getVal($row, [$pueParamField, 'PUEId', 'PUEID', 'PueId']));
            }
            if (!$pueIdSet && $areaId !== '') {
                $areaCols = self::statsTableColumns('dcim-area');
                $areaIdField = self::statsPickColumn($areaCols, ['id']);
                $areaPueField = self::statsPickColumn($areaCols, ['PUEID', 'PUEId', 'PueId']);
                if ($areaIdField !== '' && $areaPueField !== '') {
                    try {
                        $areaRows = self::statsCrud('dcim-area')->selectByIds([$areaId], [$areaIdField, $areaPueField]);
                        foreach ($areaRows as $areaRow) {
                            $collectPueIds($getVal($areaRow, [$areaPueField, 'PUEID', 'PUEId', 'PueId']));
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }

            $paramValueById = [];
            if ($pueIdSet) {
                $pueIds = array_keys($pueIdSet);
                try {
                    $paramRows = self::statsCrud('dcim-param')->selectByIds($pueIds, [$paramIdField, $paramResultField]);
                    foreach ($paramRows as $paramRow) {
                        $pid = trim((string)$getVal($paramRow, [$paramIdField, 'id']));
                        if ($pid === '') {
                            continue;
                        }
                        $paramValueById[$pid] = $numVal($getVal($paramRow, [$paramResultField, 'Result'], 0));
                    }
                } catch (\Throwable $e) {
                }
            }

            $dayValueById = [];
            if ($pueIdSet) {
                $paramDayCols = self::statsTableColumns('dcim-paramday');
                $pdIdField = self::statsPickColumn($paramDayCols, ['id']);
                $pdParamIdField = self::statsPickColumn($paramDayCols, ['ParamId', 'ParamID', 'paramId']);
                $pdResultField = self::statsPickColumn($paramDayCols, ['Result']);
                $pdStatusField = self::statsPickColumn($paramDayCols, ['status']);
                $pdCreateField = self::statsPickColumn($paramDayCols, ['create_time', 'CreateTime']);
                if ($pdParamIdField !== '' && $pdResultField !== '') {
                    $pueIds = array_keys($pueIdSet);
                    $holders = [];
                    $pdParams = [];
                    foreach ($pueIds as $idx => $pueId) {
                        $ph = ':pue_id_' . $idx;
                        $holders[] = $ph;
                        $pdParams[$ph] = $pueId;
                    }
                    if ($holders) {
                        $pdWhere = [self::statsQuoteIdent($pdParamIdField) . ' IN (' . implode(', ', $holders) . ')'];
                        if ($pdStatusField !== '') {
                            $pdWhere[] = '(' . self::statsQuoteIdent($pdStatusField) . ' <> -1 OR ' . self::statsQuoteIdent($pdStatusField) . ' IS NULL)';
                        }
                        try {
                            $pdOrder = '';
                            if ($pdIdField !== '') {
                                $pdOrder = 'ORDER BY ' . self::statsQuoteIdent($pdIdField) . ' DESC';
                            } elseif ($pdCreateField !== '') {
                                $pdOrder = 'ORDER BY ' . self::statsQuoteIdent($pdCreateField) . ' DESC';
                            }
                            $pdRows = self::statsCrud('dcim-paramday')->selectByRawCondition(implode(' AND ', $pdWhere), $pdOrder, $pdParams);
                            $latest = [];
                            foreach ($pdRows as $pdRow) {
                                $pid = trim((string)$getVal($pdRow, [$pdParamIdField, 'ParamId', 'ParamID', 'paramId']));
                                if ($pid === '' || isset($latest[$pid])) {
                                    continue;
                                }
                                $latest[$pid] = $numVal($getVal($pdRow, [$pdResultField, 'Result'], 0));
                            }
                            $dayValueById = $latest;
                        } catch (\Throwable $e) {
                        }
                    }
                }
            }

            $total = 0.0;
            $count = 0;
            foreach (array_keys($pueIdSet) as $pid) {
                $pid = (string)$pid;
                if (array_key_exists($pid, $dayValueById)) {
                    $total += (float)$dayValueById[$pid];
                    $count++;
                    continue;
                }
                if (array_key_exists($pid, $paramValueById)) {
                    $total += (float)$paramValueById[$pid];
                    $count++;
                }
            }
            if ($count > 0) {
                $pueValue = round($total / $count, 2);
            }
        }

        O_E(['PueValue' => $pueValue, 'TypeList' => $typeList], tp_msg_success(), 100, 0);
    }

    public static function statsGetNXFXInfo()
    {
        $data = Flight::request_data();
        self::requireAuthStrict($data);

        $numVal = static function ($raw): float {
            $s = trim((string)$raw);
            if ($s === '') {
                return 0.0;
            }
            $s = str_replace(',', '', $s);
            if (preg_match('/-?\d+(\.\d+)?/', $s, $m)) {
                return (float)$m[0];
            }
            return 0.0;
        };
        $normDate = static function (string $raw): string {
            $raw = trim($raw);
            if ($raw === '') {
                return date('Y');
            }
            if (preg_match('/^\d{4}$/', $raw)) {
                return $raw;
            }
            if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
                return $raw;
            }
            return date('Y');
        };
        $toYmd = static function ($raw): string {
            $s = trim((string)$raw);
            if ($s === '') {
                return '';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s, $m)) {
                return $m[0];
            }
            $ts = strtotime($s);
            return $ts ? date('Y-m-d', $ts) : '';
        };

        $dateParam = $normDate((string)($data['Date'] ?? ''));
        $areaId = trim((string)($data['AreaId'] ?? ''));
        $serverCode = trim((string)($data['ServerCode'] ?? ''));
        $isYear = preg_match('/^\d{4}$/', $dateParam) === 1;
        $year = (int)substr($dateParam, 0, 4);
        $month = $isYear ? 0 : (int)substr($dateParam, 5, 2);
        $nowYear = (int)date('Y');
        $nowMonth = (int)date('m');
        $nowDay = (int)date('d');
        $maxSlot = $isYear ? 12 : (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
        if ($isYear && $year === $nowYear) {
            $maxSlot = $nowMonth;
        }
        if (!$isYear && $year === $nowYear && $month === $nowMonth) {
            $maxSlot = $nowDay;
        }
        if ($maxSlot <= 0) {
            $maxSlot = $isYear ? 12 : 31;
        }

        $nydnCols = self::statsTableColumns('dcim-nydnrecordday');
        $nyStatusField = self::statsPickColumn($nydnCols, ['status']);
        $nyAreaField = self::statsPickColumn($nydnCols, ['AreaId']);
        $nyServerField = self::statsPickColumn($nydnCols, ['ServerCode']);
        $nyDayField = self::statsPickColumn($nydnCols, ['Day']);
        $nyYmField = self::statsPickColumn($nydnCols, ['YearMonth']);
        $nyElectricField = self::statsPickColumn($nydnCols, ['Electric']);
        $highElectricField = self::statsPickColumn($nydnCols, ['HighElectric']);
        $flatElectricField = self::statsPickColumn($nydnCols, ['FlatElectric']);
        $lowElectricField = self::statsPickColumn($nydnCols, ['LowElectric']);
        $highPriceField = self::statsPickColumn($nydnCols, ['HighPrice']);
        $flatPriceField = self::statsPickColumn($nydnCols, ['FlatPrice']);
        $lowPriceField = self::statsPickColumn($nydnCols, ['LowPrice']);

        $nyWhere = [];
        $nyParams = [];
        if ($nyStatusField !== '') {
            $nyWhere[] = self::statsQuoteIdent($nyStatusField) . ' = 1';
        }
        if ($areaId !== '' && $nyAreaField !== '') {
            $nyWhere[] = self::statsQuoteIdent($nyAreaField) . ' = :areaId';
            $nyParams[':areaId'] = $areaId;
        }
        if ($serverCode !== '' && $nyServerField !== '') {
            $nyWhere[] = self::statsQuoteIdent($nyServerField) . ' = :serverCode';
            $nyParams[':serverCode'] = $serverCode;
        }
        if ($nyYmField !== '') {
            if ($isYear) {
                $nyWhere[] = self::statsQuoteIdent($nyYmField) . ' BETWEEN :ymStart AND :ymEnd';
                $nyParams[':ymStart'] = (int)($year * 100 + 1);
                $nyParams[':ymEnd'] = (int)($year * 100 + 12);
            } else {
                $nyWhere[] = self::statsQuoteIdent($nyYmField) . ' = :ym';
                $nyParams[':ym'] = (int)($year * 100 + $month);
            }
        }
        $nyWhereSql = $nyWhere ? implode(' AND ', $nyWhere) : '1=1';
        $nyRows = [];
        try {
            $nyRows = self::statsCrud('dcim-nydnrecordday')->selectByRawCondition($nyWhereSql, '', $nyParams);
        } catch (\Throwable $e) {
            $nyRows = [];
        }

        $slotElectric = [];
        $slotFee = [];
        for ($i = 1; $i <= $maxSlot; $i++) {
            $slotElectric[$i] = 0.0;
            $slotFee[$i] = 0.0;
        }
        foreach ($nyRows as $row) {
            $day = $toYmd($row[$nyDayField] ?? '');
            if ($day === '') {
                continue;
            }
            $slot = $isYear ? (int)substr($day, 5, 2) : (int)substr($day, 8, 2);
            if ($slot < 1 || $slot > $maxSlot) {
                continue;
            }
            $slotElectric[$slot] += $numVal($nyElectricField !== '' ? ($row[$nyElectricField] ?? 0) : 0);
            if ($highElectricField !== '' && $flatElectricField !== '' && $lowElectricField !== '' && $highPriceField !== '' && $flatPriceField !== '' && $lowPriceField !== '') {
                $slotFee[$slot] += $numVal($row[$highElectricField] ?? 0) * $numVal($row[$highPriceField] ?? 0)
                    + $numVal($row[$flatElectricField] ?? 0) * $numVal($row[$flatPriceField] ?? 0)
                    + $numVal($row[$lowElectricField] ?? 0) * $numVal($row[$lowPriceField] ?? 0);
            }
        }

        $nyclassCols = self::statsTableColumns('dcim-nyclass');
        $classStatusField = self::statsPickColumn($nyclassCols, ['status']);
        $classAreaField = self::statsPickColumn($nyclassCols, ['AreaId']);
        $classServerField = self::statsPickColumn($nyclassCols, ['ServerCode']);
        $pueIdField = self::statsPickColumn($nyclassCols, ['PUEId']);
        $classWhere = [];
        $classParams = [];
        if ($classStatusField !== '') {
            $classWhere[] = self::statsQuoteIdent($classStatusField) . ' = 1';
        }
        if ($areaId !== '' && $classAreaField !== '') {
            $classWhere[] = self::statsQuoteIdent($classAreaField) . ' = :classArea';
            $classParams[':classArea'] = $areaId;
        }
        if ($serverCode !== '' && $classServerField !== '') {
            $classWhere[] = self::statsQuoteIdent($classServerField) . ' = :classServer';
            $classParams[':classServer'] = $serverCode;
        }
        $classWhereSql = $classWhere ? implode(' AND ', $classWhere) : '1=1';
        $classRows = [];
        try {
            $classRows = self::statsCrud('dcim-nyclass')->selectByRawCondition($classWhereSql, '', $classParams);
        } catch (\Throwable $e) {
            $classRows = [];
        }
        $pueIds = [];
        if ($pueIdField !== '') {
            foreach ($classRows as $row) {
                $idList = self::parseLegacyIdList($row[$pueIdField] ?? '');
                foreach ($idList as $idRaw) {
                    if ($idRaw !== '') {
                        $pueIds[$idRaw] = true;
                    }
                }
            }
        }

        $paramDayCols = self::statsTableColumns('dcim-paramday');
        $pdStatusField = self::statsPickColumn($paramDayCols, ['status']);
        $pdParamIdField = self::statsPickColumn($paramDayCols, ['ParamId']);
        $pdDayField = self::statsPickColumn($paramDayCols, ['Day']);
        $pdYmField = self::statsPickColumn($paramDayCols, ['YearMonth']);
        $pdResultField = self::statsPickColumn($paramDayCols, ['Result']);
        $paramRows = [];
        if ($pueIds && $pdParamIdField !== '' && $pdResultField !== '' && $pdDayField !== '') {
            $pdWhere = [];
            $pdParams = [];
            if ($pdStatusField !== '') {
                $pdWhere[] = self::statsQuoteIdent($pdStatusField) . ' = 1';
            }
            $inHolders = [];
            $i = 0;
            foreach (array_keys($pueIds) as $pid) {
                $ph = ':pid' . $i++;
                $inHolders[] = $ph;
                $pdParams[$ph] = $pid;
            }
            if ($inHolders) {
                $pdWhere[] = self::statsQuoteIdent($pdParamIdField) . ' IN (' . implode(',', $inHolders) . ')';
            }
            if ($pdYmField !== '') {
                if ($isYear) {
                    $pdWhere[] = self::statsQuoteIdent($pdYmField) . ' BETWEEN :pymStart AND :pymEnd';
                    $pdParams[':pymStart'] = (int)($year * 100 + 1);
                    $pdParams[':pymEnd'] = (int)($year * 100 + 12);
                } else {
                    $pdWhere[] = self::statsQuoteIdent($pdYmField) . ' = :pym';
                    $pdParams[':pym'] = (int)($year * 100 + $month);
                }
            }
            $pdWhereSql = $pdWhere ? implode(' AND ', $pdWhere) : '1=1';
            try {
                $paramRows = self::statsCrud('dcim-paramday')->selectByRawCondition($pdWhereSql, '', $pdParams);
            } catch (\Throwable $e) {
                $paramRows = [];
            }
        }

        $slotPueSum = [];
        $slotPueCnt = [];
        for ($i = 1; $i <= $maxSlot; $i++) {
            $slotPueSum[$i] = 0.0;
            $slotPueCnt[$i] = 0;
        }
        foreach ($paramRows as $row) {
            $day = $toYmd($row[$pdDayField] ?? '');
            if ($day === '') {
                continue;
            }
            $slot = $isYear ? (int)substr($day, 5, 2) : (int)substr($day, 8, 2);
            if ($slot < 1 || $slot > $maxSlot) {
                continue;
            }
            $slotPueSum[$slot] += $numVal($row[$pdResultField] ?? 0);
            $slotPueCnt[$slot] += 1;
        }

        $out = [
            'CLFList' => null,
            'Date' => $dateParam,
            'PLFList' => null,
            'PUEList' => [],
            'pue' => [],
            'TotalDF' => 0.0,
            'TotalNH' => 0.0,
        ];
        for ($i = 1; $i <= $maxSlot; $i++) {
            $val = round((float)$slotElectric[$i], 2);
            $out['PUEList'][] = ['time' => $i, 'value' => $val];
            $out['TotalNH'] += $val;
            $out['TotalDF'] += round((float)$slotFee[$i], 2);
            $pueVal = $slotPueCnt[$i] > 0 ? round($slotPueSum[$i] / $slotPueCnt[$i], 2) : 0.0;
            $out['pue'][] = ['time' => $i, 'value' => $pueVal];
        }
        $out['TotalDF'] = round($out['TotalDF'], 2);
        $out['TotalNH'] = round($out['TotalNH'], 2);
        O_E($out, tp_msg_success(), 100, 0);
    }

    public static function statsGetDNInfo()
    {
        self::requireAuthStrict(Flight::request_data());
        O_E([], tp_msg_success(), 100, 0);
    }

    public static function statsGetPayInfo()
    {
        self::requireAuthStrict(Flight::request_data());
        $list = [];
        for ($m = 1; $m <= 12; $m++) {
            $mm = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            $list[] = [
                'Date' => '-' . $mm,
                'TotalDF' => 0,
                'TotalNH' => 0,
            ];
        }
        O_E($list, tp_msg_success(), 100, 0);
    }

    public static function statsGetAreaNXInfo()
    {
        self::requireAuthStrict(Flight::request_data());
        self::statsOk([]);
    }

public static function dispatch(array $meta)
    {
        $payload = [
            'status' => 'pending',
            'message' => dcim_msg('common.stub_not_implemented'),
            'method' => $meta['method'] ?? '',
            'path' => $meta['path'] ?? '',
            'target' => $meta['target'] ?? '',
            'source' => $meta['source'] ?? '',
            'line' => $meta['line'] ?? 0,
        ];
        json_string_response($payload, 501);
    }

    private static function requireAuthLoose(array $data = [])
    {
        $user = (new CrudController('dcim-person'))->legacyEnsureAuth($data);
        if (!$user) {
            L_E();
        }
        return $user;
    }

    private static function raw(string $text = '')
    {
        header('Content-Type: text/html; charset=utf-8');
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        echo $text;
        exit;
    }

    private static function licenseCrud(string $table)
    {
        return new CrudController($table);
    }

    private static function licenseOk($data, $num = 0)
    {
        O_E_STR($data, tp_msg_success(), 100, $num);
    }

    private static function licenseResolveHostNameSafe(): string
    {
        $hostname = '';
        if (function_exists('gethostname')) {
            $hostname = (string) @gethostname();
        }
        if ($hostname === '' && function_exists('php_uname')) {
            $hostname = (string) @php_uname('n');
        }
        if ($hostname === '' && function_exists('shell_exec')) {
            $tmp = @shell_exec('hostname 2>/dev/null');
            $hostname = is_string($tmp) ? trim($tmp) : '';
        }
        return trim($hostname);
    }

    private static function licenseGetSettingRow(): array
    {
        $crud = self::licenseCrud('dcim-setting');
        $row = $crud->findOne([['id', '=', 1]]);
        if (!$row) {
            $crud->legacyInsert([
                'id' => 1,
                'License' => '',
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
                'version' => date('Y-m-d'),
            ]);
            $row = $crud->findOne([['id', '=', 1]]) ?: [];
        }
        return $row;
    }

    private static function memConvertToMb(string $memStr): float
    {
        $memStr = strtoupper(trim($memStr));
        $num = (float)str_replace(['T', 'G', 'M', 'K'], '', $memStr);
        if (strpos($memStr, 'T') !== false) {
            return $num * 1024 * 1024;
        }
        if (strpos($memStr, 'G') !== false) {
            return $num * 1024;
        }
        if (strpos($memStr, 'K') !== false) {
            return $num / 1024;
        }
        if (strpos($memStr, 'M') !== false) {
            return $num;
        }
        return 0.0;
    }

    private static function memExec(string $cmd): string
    {
        $out = @shell_exec($cmd);
        return is_string($out) ? $out : '';
    }

    public static function getMemory()
    {
        $data = Flight::request_data();
        self::requireAuthLoose($data);
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWindows) {
            $cpu = 0.0;
            $memoryTotal = 0.0;
            $memoryUsed = 0.0;
            $memoryRate = 0.0;
            $cpuNum = 0;
            $disk = [];

            $out = self::memExec('wmic cpu get LoadPercentage /value 2>NUL');
            if (preg_match('/LoadPercentage=(\d+)/', $out, $m)) {
                $cpu = (float)$m[1];
            }
            $out = self::memExec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /value 2>NUL');
            $free = 0.0;
            if (preg_match('/TotalVisibleMemorySize=(\d+)/', $out, $m)) {
                $memoryTotal = round(((float)$m[1]) / 1024, 1);
            }
            if (preg_match('/FreePhysicalMemory=(\d+)/', $out, $m)) {
                $free = round(((float)$m[1]) / 1024, 1);
            }
            $memoryUsed = max(0, round($memoryTotal - $free, 1));
            $memoryRate = $memoryTotal > 0 ? round(($memoryUsed / $memoryTotal) * 100, 2) : 0.0;

            $out = self::memExec('wmic cpu get NumberOfLogicalProcessors /value 2>NUL');
            if (preg_match('/NumberOfLogicalProcessors=(\d+)/', $out, $m)) {
                $cpuNum = (int)$m[1];
            }
            $out = self::memExec('wmic logicaldisk where "DriveType=3" get DeviceID,Size,FreeSpace /format:csv 2>NUL');
            $lines = preg_split('/\r\n|\r|\n/', trim($out));
            foreach ($lines as $line) {
                if ($line === '' || stripos($line, 'Node,DeviceID,FreeSpace,Size') === 0) {
                    continue;
                }
                $parts = array_map('trim', explode(',', $line));
                if (count($parts) < 4) {
                    continue;
                }
                $dev = $parts[1];
                $f = (float)$parts[2];
                $s = (float)$parts[3];
                if ($s <= 0) {
                    continue;
                }
                $disk[] = [
                    'title' => $dev,
                    'total' => round($s / 1024 / 1024 / 1024, 2) . 'G',
                    'use' => round(($s - $f) / 1024 / 1024 / 1024, 2) . 'G',
                    'rate' => round((($s - $f) / $s) * 100, 2) . '%',
                ];
            }

            O_E([
                'cpu' => $cpu,
                'memoryTotal' => $memoryTotal,
                'memory' => $memoryUsed,
                'memoryRate' => $memoryRate,
                'cpu_num' => $cpuNum,
                'disk' => $disk,
            ], tp_msg_success(), 100, false);
            return;
        }

        $list = [];
        $cpuInfo = self::memExec('top -b -n 1 | grep -E "(Cpu\\(s\\))|(KiB Mem)"');
        if (preg_match('/%Cpu\\(s\\):\\s*([\\d\\.]+)\\s*us/', $cpuInfo, $m)) {
            $list['cpu'] = trim($m[1]);
        } else {
            $list['cpu'] = 0;
        }

        $memoryInfo = self::memExec('free -h');
        $memTotal = 0.0;
        $memUsed = 0.0;
        $swapTotal = 0.0;
        $swapUsed = 0.0;
        if (preg_match('/Mem:\\s*(\\S+)\\s*(\\S+)\\s*(\\S+)\\s*(\\S+)/', $memoryInfo, $m)) {
            $memTotal = self::memConvertToMb($m[1]);
            $memUsed = self::memConvertToMb($m[2]);
        }
        if (preg_match('/Swap:\\s*(\\S+)\\s*(\\S+)/', $memoryInfo, $m)) {
            $swapTotal = self::memConvertToMb($m[1]);
            $swapUsed = self::memConvertToMb($m[2]);
        }
        $totalMem = $memTotal + $swapTotal;
        $usedMem = $memUsed + $swapUsed;
        $list['memoryTotal'] = round($totalMem, 1);
        $list['memory'] = round($usedMem, 1);
        $list['memoryRate'] = $totalMem > 0 ? round(100 * $usedMem / $totalMem, 2) : 0;

        $cpuNum = trim(self::memExec('grep -c ^processor /proc/cpuinfo'));
        $list['cpu_num'] = $cpuNum === '' ? 0 : $cpuNum;

        $diskInfo = self::memExec('df -lh | grep -E "^(/)"');
        $diskInfo = preg_replace("/\\s{2,}/", ' ', $diskInfo);
        $rows = preg_split('/\r\n|\r|\n/', trim((string)$diskInfo));
        $list['disk'] = [];
        foreach ($rows as $row) {
            if ($row === '') {
                continue;
            }
            $arr = explode(' ', $row);
            if (count($arr) < 6) {
                continue;
            }
            $list['disk'][] = [
                'title' => $arr[5],
                'total' => $arr[1],
                'use' => $arr[2],
                'rate' => $arr[4],
            ];
        }

        O_E($list, tp_msg_success(), 100, false);
    }

    public static function changeDockerKey()
    {
        $data = Flight::request_data();
        self::requireAuthLoose($data);
        $restartOutput = [];
        $restartCode = 1;
        @exec('systemctl restart dcim 2>&1', $restartOutput, $restartCode);
        if ($restartCode !== 0) {
            $message = trim(implode("\n", $restartOutput));
            if ($message === '') {
                $message = dcim_msg('error.service_restart_failed');
            }
            O_E(false, $message, 500, 0);
            return;
        }
        O_E(true, tp_msg_success(), 100, 1);
    }

    private static function expectInternalToken(): void
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $token = $headers['X-Auth-Token'] ?? ($headers['x-auth-token'] ?? '');
        $expected = '5dMrzkqzMBvK1EDUpUTd7ZEXIBGM37g9lYggVtxn6QW9aaUAzsEOfhdDgOoAZW';
        if ($token !== $expected) {
            header('Content-Type: application/json; charset=utf-8', true, 401);
            echo json_encode([
                'success' => false,
                'error' => dcim_msg('common.forbidden'),
                'message' => dcim_msg('common.forbidden'),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public static function applyViews()
    {
        self::expectInternalToken();
        header('Content-Type: application/json; charset=utf-8');
        $script = realpath(__DIR__ . '/../../tools/apply_views_indexes.php');
        if ($script === false || !is_file($script)) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => dcim_msg('common.script_not_found'),
                'message' => dcim_msg('common.script_not_found'),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            require $script;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => dcim_msg('common.server_error'),
                'message' => dcim_msg('common.server_error'),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public static function createProject()
    {
        $data = Flight::request_data();
        self::requireAuthLoose($data);
        $id = self::licenseCrud('dcim-project')->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function getProjectList()
    {
        $data = Flight::request_data();
        self::requireAuthLoose($data);
        $result = self::licenseCrud('dcim-project')->legacyList($data, [
            'base_where' => ['status = 1'],
            'order_by' => 'ORDER BY id DESC',
            'search_fields' => ['ProjectName'],
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function getProjectDetail()
    {
        $data = Flight::request_data();
        self::requireAuthLoose($data);
        $info = self::licenseCrud('dcim-project')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function changeProject()
    {
        $data = Flight::request_data();
        self::requireAuthLoose($data);
        $res = self::licenseCrud('dcim-project')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function delProject()
    {
        $data = Flight::request_data();
        self::requireAuthLoose($data);
        $res = self::licenseCrud('dcim-project')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function checkKey()
    {
        $data = Flight::request_data();
        self::requireAuthLoose($data);
        if (empty($data['id']) || !array_key_exists('ProjectKey', $data)) {
            O_E(false, tp_msg_success(), 100, 0);
            return;
        }
        $project = self::licenseCrud('dcim-project')->findOne([
            ['id', '=', $data['id']],
            ['status', '=', 1],
        ]);
        if (!$project) {
            O_E(false, tp_msg_success(), 100, 0);
            return;
        }
        $ok = ((string)($project['ProjectKey'] ?? '') === (string)$data['ProjectKey']);
        O_E($ok, tp_msg_success(), 100, $ok ? 1 : 0);
    }

    public static function SetTimeVersion()
    {
        $txt = "supported modules" . "`n" . date("Y-m-d");
        self::raw($txt);
    }

    public static function AssetMsgSend()
    {
        $data = Flight::request_data();
        $dateYmd = self::cronDateYmd(is_array($data) ? $data : []);
        $assetCrud = new CrudController('dcim-asset');
        $msgCrud = new CrudController('dcim-assetmsg');
        $rows = $assetCrud->selectByRawCondition('status = 1', '', []);
        $created = 0;
        foreach ($rows as $row) {
            $aid = $row['id'] ?? 0;
            if (!$aid) {
                continue;
            }
            if (!empty($row['BuyTime']) && !empty($row['ModelScrap'])) {
                $scrapDate = date('Y-m-d', strtotime((string)$row['BuyTime'] . ' +' . (int)$row['ModelScrap'] . ' years'));
                if ($scrapDate === $dateYmd) {
                    $exists = $msgCrud->selectByRawCondition(
                        'status = 1 AND MainNumber = :m AND MsgType = :t AND create_time = :d',
                        'LIMIT 1',
                        [':m' => $aid, ':t' => dcim_msg('app.asset_scrap_reminder'), ':d' => $dateYmd]
                    );
                    if (!$exists) {
                        $id = $msgCrud->legacyInsert([
                            'MainNumber' => $aid,
                            'MainName' => 'Asset No:' . (string)($row['AssetsNumber'] ?? ''),
                            'MsgType' => 'Asset Scrap Reminder',
                            'MsgDescribe' => 'Reached scrap date ' . $dateYmd,
                            'create_time' => $dateYmd,
                            'status' => 1,
                        ]);
                        if ($id) {
                            $created++;
                        }
                    }
                }
            }
        }
        app_json_response(['date' => $dateYmd, 'created' => $created], null, 'ok');
    }

    public static function XJTaskTime()
    {
        $data = Flight::request_data();
        $dateYmd = self::cronDateYmd(is_array($data) ? $data : []);
        $crud = new CrudController('dcim-xjtask');
        $rows = $crud->selectByRawCondition('status = 1', '', []);
        $updated = 0;
        foreach ($rows as $row) {
            $id = $row['id'] ?? 0;
            if (!$id || empty($row['XJPlanComplateTime'])) {
                continue;
            }
            $due = date('Y-m-d', strtotime((string)$row['XJPlanComplateTime'] . ' +1 day'));
            if ($due === $dateYmd) {
                if ($crud->legacyUpdate(['id' => $id, 'XJStatus' => 'overdue'], ['id_required_message' => dcim_msg('common.id_required'), 'only_fields' => ['XJStatus']])) {
                    $updated++;
                }
            }
        }
        app_json_response(['date' => $dateYmd, 'updated' => $updated], null, 'ok');
    }

    public static function WHTaskTime()
    {
        $data = Flight::request_data();
        $dateYmd = self::cronDateYmd(is_array($data) ? $data : []);
        $crud = new CrudController('dcim-whtask');
        $rows = $crud->selectByRawCondition('status = 1', '', []);
        $updated = 0;
        foreach ($rows as $row) {
            $id = $row['id'] ?? 0;
            if (!$id || empty($row['PlanComplateDate'])) {
                continue;
            }
            $due = date('Y-m-d', strtotime((string)$row['PlanComplateDate'] . ' +1 day'));
            if ($due === $dateYmd) {
                if ($crud->legacyUpdate(['id' => $id, 'WHStatus' => 'overdue'], ['id_required_message' => dcim_msg('common.id_required'), 'only_fields' => ['WHStatus']])) {
                    $updated++;
                }
            }
        }
        app_json_response(['date' => $dateYmd, 'updated' => $updated], null, 'ok');
    }

    public static function PDTaskTime()
    {
        $data = Flight::request_data();
        $dateYmd = self::cronDateYmd(is_array($data) ? $data : []);
        $crud = new CrudController('dcim-assetcheckplan');
        $rows = $crud->selectByRawCondition('status = 1', '', []);
        $updated = 0;
        foreach ($rows as $row) {
            $id = $row['id'] ?? 0;
            if (!$id || empty($row['PlanComplateTime'])) {
                continue;
            }
            $due = date('Y-m-d', strtotime((string)$row['PlanComplateTime'] . ' +1 day'));
            if ($due === $dateYmd) {
                if ($crud->legacyUpdate(['id' => $id, 'PlanStatus' => 'overdue'], ['id_required_message' => dcim_msg('common.id_required'), 'only_fields' => ['PlanStatus']])) {
                    $updated++;
                }
            }
        }
        app_json_response(['date' => $dateYmd, 'updated' => $updated], null, 'ok');
    }

    public static function CheckPlanSend()
    {
        $data = Flight::request_data();
        $dateYmd = self::cronDateYmd(is_array($data) ? $data : []);
        $plans = (new CrudController('dcim-assetcheckplanmodel'))->selectByRawCondition('status = 1 AND PlanCycle = 1', '', []);
        $created = 0;
        foreach ($plans as $plan) {
            if (self::cronMatchSchedule((string)($plan['SendCycle'] ?? ''), (string)($plan['SendTime'] ?? ''), $dateYmd)) {
                if (self::cronInsertCheckPlan($dateYmd, $plan)) {
                    $created++;
                }
            }
        }
        app_json_response(['date' => $dateYmd, 'created' => $created], null, 'ok');
    }

    public static function XJTaskSend()
    {
        $data = Flight::request_data();
        $dateYmd = self::cronDateYmd(is_array($data) ? $data : []);
        $plans = (new CrudController('dcim-xjmodel'))->selectByRawCondition('status = 1 AND XJCycle = 1', '', []);
        $created = 0;
        foreach ($plans as $plan) {
            if (self::cronMatchSchedule((string)($plan['DistributeCycle'] ?? ''), (string)($plan['DistributeTime'] ?? ''), $dateYmd)) {
                if (self::cronInsertXJTask($dateYmd, $plan)) {
                    $created++;
                }
            }
        }
        app_json_response(['date' => $dateYmd, 'created' => $created], null, 'ok');
    }

    public static function WHTaskSend()
    {
        $data = Flight::request_data();
        $dateYmd = self::cronDateYmd(is_array($data) ? $data : []);
        $plans = (new CrudController('dcim-whplan'))->selectByRawCondition('status = 1 AND WHCycle = 1', '', []);
        $created = 0;
        foreach ($plans as $plan) {
            if (self::cronMatchSchedule((string)($plan['DistributeCycle'] ?? ''), (string)($plan['DistributeTime'] ?? ''), $dateYmd)) {
                if (self::cronInsertWHTask($dateYmd, $plan)) {
                    $created++;
                }
            }
        }
        app_json_response(['date' => $dateYmd, 'created' => $created], null, 'ok');
    }
    public static function DNCalcSend() { self::raw("string(18) \"ok\"\n"); }
    public static function ParamCalcDaySend() { self::raw("string(41) \"ok\"\n"); }
    public static function PlatSendCloud()
    {
        $serverCount = (new CrudController('dcim-server'))->countWhere([['status', '=', 1]]);
        $assetCount = (new CrudController('dcim-asset'))->countWhere([['status', '=', 1]]);
        app_json_response(['serverCount' => $serverCount, 'assetCount' => $assetCount], null, 'ok');
    }

    public static function UdeviceStatusCheck()
    {
        $data = Flight::request_data();
        self::requireAuth($data);
        $crud = new CrudController('dcim-cabinetu');
        $rows = $crud->selectByRawCondition('status = 1', '', []);
        $updated = 0;
        foreach ($rows as $row) {
            if (($row['UdeviceStatus'] ?? null) === null) {
                if ($crud->legacyUpdate(['id' => $row['id'], 'UdeviceStatus' => '4'], [
                    'id_required_message' => dcim_msg('common.id_required'),
                    'only_fields' => ['UdeviceStatus'],
                ])) {
                    $updated++;
                }
            }
        }
        app_json_response(['updated' => $updated], null, 'ok');
    }
    public static function getLicense()
    {
        $crud = self::licenseCrud('dcim-setting');
        $row = self::licenseGetSettingRow();
        if (($row['status'] ?? null) === '-1' || ($row['status'] ?? null) === -1) {
            $crud->legacyUpdate([
                'id' => 1,
                'version' => date('Y-m-d'),
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
                'status' => 1,
            ], [
                'skip_auth' => true,
                'id_required_message' => dcim_msg('common.id_required'),
                'only_fields' => ['version', 'create_time', 'update_time', 'status'],
            ]);
            $row = self::licenseGetSettingRow();
        }

        $license = trim((string) ($row['License'] ?? ''));
        if ($license === '') {
            $license = null;
        }
        $mac = self::licenseResolveHostNameSafe();
        $spareMacFile = dirname(__DIR__, 2) . '/js/spareMac.txt';
        if (is_file($spareMacFile)) {
            $tmpMac = @file_get_contents($spareMacFile);
            if ($tmpMac !== false && trim($tmpMac) !== '') {
                $mac = trim($tmpMac);
            }
        }

        $payload = ['data' => $license, 'mac' => $mac];
        dcim_debug_log('[LICENSE] GetLicenseKey license_len=' . strlen((string) $license) . ' mac=' . $mac);
        O_E($payload, tp_msg_success(), 100, 0);
    }

    public static function changeLicense()
    {
        $data = Flight::request_data();
        $code = $data['code'] ?? null;
        self::licenseCrud('dcim-setting')->legacyUpdate([
            'id' => 1,
            'License' => $code,
        ], [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['License'],
        ]);
        dcim_debug_log('[LICENSE] ChangeLicenseKey code_len=' . strlen((string) $code));
        O_E(true, tp_msg_success(), 100, false);
    }

    public static function varLicense()
    {
        $data = Flight::request_data();
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $row = self::licenseGetSettingRow();
            $createTime = (string) ($row['create_time'] ?? date('Y-m-d H:i:s'));
            $firstDay = explode(' ', $createTime)[0];
            $fallback = date('Y-m-d', strtotime('+30 day', strtotime($firstDay)));
            dcim_debug_log('[LICENSE] VarLicenseKey code_len=0 result=' . $fallback);
            self::licenseOk($fallback, 0);
            return;
        }

        $parts = explode('-', $code);
        if (count($parts) < 4) {
            dcim_debug_log('[LICENSE] VarLicenseKey invalid_format code=' . $code);
                        self::licenseOk(dcim_msg('error.invalid_license'), 0);
            return;
        }

        $str4 = ($parts[0] ?? '') . '.' . ($parts[2] ?? '');
        $newstr = str_replace(',', '', (string) (((float) $str4) * 500000));
        $str1 = substr($newstr, 0, 4);
        $str2 = substr($newstr, 6, 3);
        $str3 = substr($newstr, 9, 3);
        $timecode = $str1 . $str2 . $str3;
        $time = ctype_digit($timecode) ? date('Y-m-d', (int) $timecode) : '';

        $row = self::licenseGetSettingRow();
        $createTime = (string) ($row['create_time'] ?? date('Y-m-d H:i:s'));
        $firstDay = explode(' ', $createTime)[0];
        $endtime = (int) date('Ymd', strtotime('+30 day', strtotime($firstDay)));
        $nowtime = (int) date('Ymd');

        $hostname = self::licenseResolveHostNameSafe();
        $spareMacFile = dirname(__DIR__, 2) . '/js/spareMac.txt';
        if (is_file($spareMacFile)) {
            $tmpMac = @file_get_contents($spareMacFile);
            if ($tmpMac !== false && trim($tmpMac) !== '') {
                $hostname = trim($tmpMac);
            }
        }

        $cutlen = strlen($hostname) >= 12 ? 12 : strlen($hostname);
        $macs = substr($hostname, 0, 12);
        $mac1 = str_split((string) ($parts[1] ?? ''));
        $mac2 = str_split((string) ($parts[3] ?? ''));
        if (count($mac1) < 6 || count($mac2) < 6) {
            dcim_debug_log('[LICENSE] VarLicenseKey invalid_mac_segments code=' . $code);
                    self::licenseOk(dcim_msg('error.invalid_license'), 0);
            return;
        }

        $mac = $mac1[2] . $mac2[4] . $mac2[0] . $mac1[4] . $mac1[3] . $mac1[0]
            . $mac2[1] . $mac2[3] . $mac2[2] . $mac1[1] . $mac2[5] . $mac1[5];
        $mac = substr($mac, 0, $cutlen);

        if ($mac !== $macs) {
            if ($nowtime >= $endtime) {
            self::licenseOk(dcim_msg('error.invalid_license'), 0);
                return;
            }
            $time = date('Y-m-d', strtotime('+30 day', strtotime($firstDay)));
        }

        dcim_debug_log('[LICENSE] VarLicenseKey code_len=' . strlen($code) . ' result=' . $time);
        self::licenseOk($time, 0);
    }

    public static function setLicense()
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '';
        return;
    }


    // Merged from PublicPhpController to reduce controller files.
    private const ICON_BASE64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAQlJREFUWEdjZBhgwDjA9jNgOMDVM9aegYlBjNoOY2Rk/M/I8O/2zi1LLiKbjeIAN5/YPEYGhonUthzJvL//GBmcd29efBAmhuIAd5+Y2QwMjCk0dADD//+M2bu2LpqG1QEeHgkK/1j+TGP4zyhK0BGMDNqMDAycUHWv//9neEhQD8P/G7++/sw8cGD1F6wOIGwAQoWbT+w1RgYGTajI7J1bFqeRon/UAaMhMBoCoyEwGgKjITAaAqMhMBoCoyFAcQi4+8SCOhh6IIP+//8/bdfWJdn0bZR6xRYyMP7vZWRg/Paf4b/brq1LjtHVASDL3HxjFZn//Piyffvq1+RYDtIz+Dqn5PqEXH0A9hGnIbhiy9wAAAAASUVORK5CYII=';

    private static function publicPath(string $suffix = ''): string
    {
        return rtrim(dirname(__DIR__, 2) . '/public' . $suffix, '/');
    }

    private static function resolveLegacyImageDir(array $candidates, bool $createIfMissing = false): array
    {
        foreach ($candidates as $candidate) {
            $suffix = (string)($candidate['suffix'] ?? '');
            $path = self::publicPath($suffix);
            if (is_dir($path)) {
                return [
                    'path' => $path,
                    'url' => (string)($candidate['url'] ?? ''),
                ];
            }
        }

        $primary = $candidates[0] ?? ['suffix' => '', 'url' => ''];
        $primaryPath = self::publicPath((string)($primary['suffix'] ?? ''));
        if ($createIfMissing) {
            if (!is_dir($primaryPath) && !@mkdir($primaryPath, 0755, true)) {
                self::respondError(str_replace('{path}', $primaryPath, dcim_msg('error.create_dir_failed_with_path')));
            }
            return [
                'path' => $primaryPath,
                'url' => (string)($primary['url'] ?? ''),
            ];
        }

        return [
            'path' => '',
            'url' => (string)($primary['url'] ?? ''),
        ];
    }

    private static function respondSuccess(string $msg, $data): void
    {
        $msg = self::normalizePublicMessage($msg);
        header('Content-Type:application/json');
        echo json_encode(['code' => 100, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function respondError(string $msg): void
    {
        $msg = self::normalizePublicMessage($msg);
        header('Content-Type:application/json');
        echo json_encode(['code' => 400, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function normalizePublicMessage(string $msg): string
    {
        $trim = trim($msg);
        $direct = [
            'invalid license' => dcim_msg('error.invalid_license'),
            'login failed' => dcim_msg('error.login_failed'),
            'server error' => dcim_msg('common.server_error'),
            'sso data fetch failed' => dcim_msg('error.sso_data_fetch_failed'),
            'docker/service restart failed' => dcim_msg('error.service_restart_failed'),
            'create folder failed' => dcim_msg('error.create_folder_failed'),
            'create image folder failed' => dcim_msg('error.create_image_folder_failed'),
            'directory not found' => dcim_msg('error.directory_not_found'),
            'file not found' => dcim_msg('error.file_not_found'),
            'JSON parse failed' => dcim_msg('error.json_parse_failed'),
            'only zip is allowed' => dcim_msg('error.only_zip_allowed'),
            'invalid file mime' => dcim_msg('error.invalid_file_mime'),
            'invalid action' => dcim_msg('error.invalid_action'),
            'read template dir failed' => dcim_msg('error.read_template_dir_failed'),
            'built-in template cannot be deleted' => dcim_msg('error.builtin_template_cannot_delete'),
            'delete file failed' => dcim_msg('error.delete_file_failed'),
            'file upload failed' => dcim_msg('error.file_upload_failed'),
            'file already exists' => dcim_msg('error.file_already_exists'),
            'upload file failed' => dcim_msg('error.upload_file_failed'),
        ];
        if (isset($direct[$trim])) {
            return $direct[$trim];
        }
        if (strpos($trim, 'create folder failed: ') === 0) {
            return str_replace('{path}', substr($trim, strlen('create folder failed: ')), dcim_msg('error.create_folder_failed_with_path'));
        }
        if (strpos($trim, 'extract folder create failed: ') === 0) {
            return str_replace('{path}', substr($trim, strlen('extract folder create failed: ')), dcim_msg('error.extract_folder_create_failed_with_path'));
        }
        if (strpos($trim, 'zip handle failed: ') === 0) {
            return str_replace('{reason}', substr($trim, strlen('zip handle failed: ')), dcim_msg('error.zip_handle_failed_with_reason'));
        }
        if (strpos($trim, 'create backup folder failed: ') === 0) {
            return str_replace('{path}', substr($trim, strlen('create backup folder failed: ')), dcim_msg('error.create_backup_folder_failed_with_path'));
        }
        if (strpos($trim, 'invalid file type: ') === 0) {
            return str_replace('{type}', substr($trim, strlen('invalid file type: ')), dcim_msg('error.invalid_file_type_with_type'));
        }
        if (strpos($trim, 'create dir failed: ') === 0) {
            return str_replace('{path}', substr($trim, strlen('create dir failed: ')), dcim_msg('error.create_dir_failed_with_path'));
        }
        if (preg_match('/^txt file \\[(.+)\\] already exists$/', $trim, $match)) {
            return str_replace('{file}', (string)($match[1] ?? ''), dcim_msg('error.txt_file_already_exists'));
        }
        if (preg_match('/^(.+) file not found$/', $trim, $match)) {
            return str_replace('{target}', (string)($match[1] ?? ''), dcim_msg('error.target_file_not_found'));
        }
        if (preg_match('/^(.+) copy failed$/', $trim, $match)) {
            return str_replace('{target}', (string)($match[1] ?? ''), dcim_msg('error.copy_failed_with_target'));
        }
        if (preg_match('/^(.+) zip failed$/', $trim, $match)) {
            return str_replace('{target}', (string)($match[1] ?? ''), dcim_msg('error.zip_failed_with_target'));
        }
        if (preg_match('/^(.+) zip parse failed$/', $trim, $match)) {
            return str_replace('{target}', (string)($match[1] ?? ''), dcim_msg('error.zip_parse_failed_with_target'));
        }
        if (preg_match('/^(.+) role not found$/', $trim, $match)) {
            return str_replace('{name}', (string)($match[1] ?? ''), dcim_msg('error.company_role_not_found'));
        }
        if (preg_match('/^(.+) no available user$/', $trim, $match)) {
            return str_replace('{name}', (string)($match[1] ?? ''), dcim_msg('error.company_no_available_user'));
        }
        return $msg;
    }

    private static function getError(string $opType): string
    {
        $error = error_get_last();
        if ($error && ($error['type'] ?? null) === E_WARNING) {
            return str_replace(
                ['{op}', '{reason}'],
                [self::normalizePublicMessage($opType), (string)($error['message'] ?? '')],
                dcim_msg('error.operation_failed_with_reason')
            );
        }
        return str_replace('{op}', self::normalizePublicMessage($opType), dcim_msg('error.operation_failed'));
    }

    private static function deleteFolder(string $folderPath): void
    {
        if (!is_dir($folderPath)) {
            return;
        }
        $files = glob($folderPath . '/*');
        foreach ($files as $file) {
            if (is_dir($file)) {
                self::deleteFolder($file);
            } else {
                unlink($file);
            }
        }
        rmdir($folderPath);
    }

    private static function zipFolder(string $source, string $destination): bool
    {
        if (!extension_loaded('zip') || !file_exists($source)) {
            return false;
        }
        $zip = new ZipArchive();
        if (!$zip->open($destination, ZipArchive::CREATE)) {
            return false;
        }
        $source = str_replace('\\', '/', realpath($source));
        if (is_dir($source)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($files as $file) {
                $file = str_replace('\\', '/', $file);
                if (in_array(substr($file, strrpos($file, '/') + 1), ['.', '..'])) {
                    continue;
                }
                $file = realpath($file);
                if (is_dir($file)) {
                    $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
                } elseif (is_file($file)) {
                    $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
                }
            }
        } elseif (is_file($source)) {
            $zip->addFromString(basename($source), file_get_contents($source));
        }
        return $zip->close();
    }

    // POST /page/export
    public static function exportPage(): void
    {
        $data = Flight::request_data();
        $pageName = trim($data['pageName'] ?? '');
        $pageTxt = trim($data['pageTxt'] ?? '');

        $pageDir = self::publicPath('/Images/page/');
        $imgRoot = self::publicPath('/');
        $fileurl = $pageDir . $pageTxt . '.txt';

        if (!file_exists($fileurl)) {
            self::respondError(self::getError(str_replace('{target}', $pageTxt . '.txt', dcim_msg('error.target_file_not_found'))));
        }

        $folderName = $pageDir . $pageTxt;
        if (is_dir($folderName)) {
            self::deleteFolder($folderName);
        }
        if (!mkdir($folderName, 0755, true)) {
            self::respondError(self::getError(dcim_msg('error.create_folder_failed')));
        }
        $folderimgName = $pageDir . $pageTxt . '/img';
        if (!mkdir($folderimgName, 0755, true)) {
            self::respondError(self::getError(dcim_msg('error.create_image_folder_failed')));
        }

        $jsonString = file_get_contents($fileurl);
        $newdata = json_decode($jsonString, true);
        $dataJson = json_decode($newdata, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::respondError(dcim_msg('error.json_parse_failed'));
        }

        $findImg = [];
        $childArr = $dataJson['children'][0]['children'] ?? [];
        if ($childArr) {
            foreach ($childArr as $value) {
                if (($value['attrs']['id'] ?? '') === 'canvasBackground' && !empty($value['attrs']['fillPatternImage'])) {
                    $findImg[] = $value['attrs']['fillPatternImage'];
                }
                if (!empty($value['attrs']['moduleJson']['children'])) {
                    foreach ($value['attrs']['moduleJson']['children'] as $val) {
                        if (($val['className'] ?? '') === 'Image') {
                            if (!empty($value['attrs']['moduleJson']['attrs']['where'])) {
                                foreach ($value['attrs']['moduleJson']['attrs']['where'] as $y) {
                                    $color = $y['statusSelectColor'] ?? '';
                                    if ($color && strpos($color, 'data:image') === false && strpos($color, 'Images/dcim/') === false) {
                                        $findImg[] = $color;
                                    }
                                }
                            }
                            $img = $val['attrs']['image'] ?? '';
                            if ($img && strpos($img, 'data:image') === false && strpos($img, 'Images/dcim/') === false) {
                                $findImg[] = $img;
                            }
                        }
                    }
                }
            }
        }

        $errorinfo = '';
        foreach ($findImg as $value) {
            $newval = str_replace('../', '', $value);
            $newurl = $imgRoot . $newval;
            $destinationFile = $folderimgName . '/' . basename($newval);
            if (file_exists($newurl)) {
                if (!copy($newurl, $destinationFile)) {
                    $errorinfo .= self::getError(str_replace('{target}', (string)$value, dcim_msg('error.copy_failed_with_target')));
                }
            } else {
                $errorinfo .= self::getError(str_replace('{target}', (string)$value, dcim_msg('error.target_file_not_found')));
            }
        }
        if ($errorinfo) {
            self::respondError($errorinfo);
        }

        if (!copy($fileurl, $folderName . '/' . $pageTxt . '.txt')) {
            self::respondError(self::getError(str_replace('{target}', $pageTxt . '.txt', dcim_msg('error.copy_failed_with_target'))));
        }

        $destinationZip = $folderName . '.zip';
        if (file_exists($destinationZip)) {
            unlink($destinationZip);
        }
        if (self::zipFolder($folderName, $destinationZip)) {
            self::deleteFolder($folderName);
            self::respondSuccess(tp_msg_success(), $destinationZip);
        }
        self::respondError(self::getError(str_replace('{target}', $destinationZip, dcim_msg('error.copy_failed_with_target'))));
    }

    // POST /page/import-zip
    public static function importZip(): void
    {
        try {
            $zip = new ZipArchive();
            $uploadDir = self::publicPath('/Images/page/');
            $imgUrl = self::publicPath('/Images/uploads/');
            $nameArr = explode('[', $_FILES['file']['name'] ?? '');
            $PageName = $nameArr[0] ?? '';
            $PageIndex = isset($nameArr[1]) ? explode(']', $nameArr[1])[0] : '';

            foreach ([$uploadDir, $imgUrl] as $dir) {
                if (!file_exists($dir) && !mkdir($dir, 0755, true)) {
                    self::respondError(str_replace('{path}', $dir, dcim_msg('error.create_folder_failed_with_path')));
                }
            }

            $filename = $uploadDir . basename($_FILES['file']['name']);
            if (file_exists($filename)) {
                unlink($filename);
            }
            $allowed = ['zip'];
            $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            if (!in_array(strtolower($ext), $allowed)) {
                self::respondError(dcim_msg('error.only_zip_allowed'));
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['file']['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, ['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'])) {
                self::respondError(dcim_msg('error.invalid_file_mime'));
            }

            move_uploaded_file($_FILES['file']['tmp_name'], $filename);

            if ($zip->open($filename) === true) {
                $extractPath = $uploadDir . 'extracted/';
                if (!is_dir($extractPath) && !mkdir($extractPath, 0755, true)) {
                    self::respondError(str_replace('{path}', $extractPath, dcim_msg('error.extract_folder_create_failed_with_path')));
                }
                $zip->extractTo($extractPath);
                $zip->close();

                $fileList = scandir($extractPath);
                $pageTxt = '';
                foreach ($fileList as $file) {
                    if (strlen($file) > 2 && strpos($file, '.')) {
                        $sourceFile = $extractPath . $file;
                        $targetDirectory = $uploadDir . $file;
                        $pageTxt = explode('.txt', $file)[0];
                        if (file_exists($targetDirectory)) {
                            self::respondError(str_replace('{file}', (string)$file, dcim_msg('error.txt_file_already_exists')));
                        }
                        if (!copy($sourceFile, $targetDirectory)) {
                            self::respondError(self::getError(str_replace('{target}', (string)$file, dcim_msg('error.copy_failed_with_target'))));
                        }
                    }
                    if (strlen($file) > 2 && $file === 'img') {
                        $imgList = scandir($extractPath . 'img/');
                        foreach ($imgList as $img) {
                            if (strlen($img) > 2 && strpos($img, '.')) {
                                $imgFile = $extractPath . 'img/' . $img;
                                $targetImgDirectory = $imgUrl . $img;
                                if (!copy($imgFile, $targetImgDirectory)) {
                                    self::respondError(self::getError(str_replace('{target}', (string)$img, dcim_msg('error.copy_failed_with_target'))));
                                }
                            }
                        }
                    }
                }

                $crud = new CrudController('dcim-dmpage');
                $crud->legacyInsert([
                    'PageName' => $PageName,
                    'PageIndex' => $PageIndex,
                    'PageType' => 1,
                    'PageTxt' => $pageTxt,
                ]);

                self::deleteFolder($extractPath);
                if (file_exists($filename)) {
                    unlink($filename);
                }
                self::respondSuccess(tp_msg_success(), null);
            }
            self::respondError(self::getError(str_replace('{target}', (string)$PageName, dcim_msg('error.copy_failed_with_target'))));
        } catch (Exception $e) {
            self::respondError(str_replace('{reason}', $e->getMessage(), dcim_msg('error.zip_handle_failed_with_reason')));
        }
    }

    // POST /page/img-data
    public static function imgData(): void
    {
        $action = $_POST['action'] ?? '';
        $name = $_POST['name'] ?? '';
        switch ($action) {
            case 'system':
                self::getSystemImg();
                break;
            case 'upload':
                self::getUploadImg();
                break;
            case 'del':
                self::delUploadImg();
                break;
            case 'tpl':
                self::getTpl();
                break;
            case 'deltpl':
                self::delTpl();
                break;
            case 'page':
                self::getPage($name);
                break;
            case 'delpage':
                self::delPage();
                break;
            default:
                self::respondError(dcim_msg('error.invalid_action'));
        }
    }

    private static function getSystemImg(): void
    {
        $resolved = self::resolveLegacyImageDir([
            ['suffix' => '/images/dcim', 'url' => '../images/dcim/'],
            ['suffix' => '/Images/dcim', 'url' => '../Images/dcim/'],
        ], false);
        $dir = (string)($resolved['path'] ?? '');
        $imgBaseUrl = (string)($resolved['url'] ?? '../images/dcim/');
        if ($dir === '' || !is_dir($dir)) {
            self::respondSuccess(tp_msg_success(), []);
        }
        $fileList = scandir($dir);
        if ($fileList === false) {
            self::respondSuccess(tp_msg_success(), []);
        }
        $data = [];
        foreach ($fileList as $file) {
            if (strlen($file) > 2 && strpos($file, '.')) {
                $data[] = ['imgUrl' => $imgBaseUrl . $file];
            }
        }
        self::respondSuccess(tp_msg_success(), $data);
    }

    private static function getUploadImg(): void
    {
        $resolved = self::resolveLegacyImageDir([
            ['suffix' => '/images/uploads', 'url' => '../images/uploads/'],
            ['suffix' => '/Images/uploads', 'url' => '../Images/uploads/'],
            ['suffix' => '/uploads', 'url' => '../uploads/'],
        ], true);
        $dir = (string)($resolved['path'] ?? '');
        $imgBaseUrl = (string)($resolved['url'] ?? '../images/uploads/');
        if ($dir === '' || !is_dir($dir)) {
            self::respondSuccess(tp_msg_success(), []);
        }
        $fileList = scandir($dir);
        if ($fileList === false) {
            self::respondSuccess(tp_msg_success(), []);
        }
        $data = [];
        foreach ($fileList as $file) {
            if (strlen($file) > 2 && strpos($file, '.')) {
                $data[] = ['imgUrl' => $imgBaseUrl . $file];
            }
        }
        self::respondSuccess(tp_msg_success(), $data);
    }

    private static function delUploadImg(): void
    {
        $img = $_POST['img'] ?? '';
        $resolved = self::resolveLegacyImageDir([
            ['suffix' => '/images/uploads', 'url' => '../images/uploads/'],
            ['suffix' => '/Images/uploads', 'url' => '../Images/uploads/'],
            ['suffix' => '/uploads', 'url' => '../uploads/'],
        ], false);
        $dir = (string)($resolved['path'] ?? '');
        if ($dir === '' || !is_dir($dir)) {
            self::respondError(dcim_msg('error.file_not_found'));
        }
        $dir = rtrim($dir, '/\\') . '/';
        $imgarr = explode('/', $img);
        $filePath = $dir . $imgarr[count($imgarr) - 1];
        if (file_exists($filePath)) {
            unlink($filePath);
            self::respondSuccess(tp_msg_success(), 1);
        }
        self::respondError(dcim_msg('error.file_not_found'));
    }

    private static function getTpl(): void
    {
        $dir = self::publicPath('/Images/pagetpl');
        if (!is_dir($dir)) {
            self::respondError(dcim_msg('error.directory_not_found'));
        }
        $fileList = scandir($dir);
        if ($fileList === false) {
            self::respondError(dcim_msg('error.read_template_dir_failed'));
        }
        $data = [];
        foreach ($fileList as $file) {
            if (!mb_check_encoding($file, 'UTF-8')) {
                continue;
            }
            if (preg_match('/[<>:"\/\\\\|?*]/', $file)) {
                continue;
            }
            if (strlen($file) > 2 && strpos($file, '.')) {
                $data[] = [
                    'moduleName' => explode('.', $file)[0],
                    'iconBase64' => self::ICON_BASE64,
                    'moduleJson' => file_get_contents($dir . '/' . $file),
                ];
            }
        }
        self::respondSuccess(tp_msg_success(), $data);
    }

    private static function delTpl(): void
    {
        $name = $_POST['name'] ?? '';
        if (in_array($name, ['UPS'], true)) {
            self::respondError(dcim_msg('error.builtin_template_cannot_delete'));
        }
        $filePath = self::publicPath('/Images/pagetpl/' . $name . '.txt');
        if (file_exists($filePath)) {
            unlink($filePath);
            self::respondSuccess(tp_msg_success(), 1);
        }
        self::respondError(dcim_msg('error.file_not_found'));
    }

    private static function getPage(string $name): void
    {
        $filePath = self::publicPath('/Images/page/' . $name . '.txt');
        if (file_exists($filePath)) {
            $data = [[
                'moduleName' => $name,
                'iconBase64' => self::ICON_BASE64,
                'moduleJson' => file_get_contents($filePath),
            ]];
            self::respondSuccess(tp_msg_success(), $data);
        }
        self::respondSuccess(dcim_msg('error.file_not_found'), null);
    }

    private static function delPage(): void
    {
        $name = $_POST['name'] ?? '';
        $filePath = self::publicPath('/Images/page/' . $name . '.txt');
        $backupPath = self::publicPath('/Images/page/backup/');
        if (!is_dir(dirname($filePath))) {
            self::respondError(dcim_msg('error.directory_not_found'));
        }
        if (!is_dir($backupPath) && !mkdir($backupPath, 0755, true)) {
            self::respondError(str_replace('{path}', $backupPath, dcim_msg('error.create_backup_folder_failed_with_path')));
        }
        if (!file_exists($filePath)) {
            self::respondSuccess(tp_msg_success(), 1);
        }
        if (copy($filePath, $backupPath . $name . '.txt')) {
            if (file_exists($filePath)) {
                unlink($filePath);
                self::respondSuccess(tp_msg_success(), 1);
            }
            self::respondError(dcim_msg('error.file_not_found'));
        }
        self::respondError(dcim_msg('error.delete_file_failed'));
    }

    // POST /page/import
    public static function importPageFile(): void
    {
        $uptypes = ['text/plain'];
        $maxFileSize = 20971520;
        $destinationFolder = self::publicPath('/Images/page/');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!is_uploaded_file($_FILES['file']['tmp_name'])) {
                self::respondError(dcim_msg('error.file_not_found'));
            }
            $file = $_FILES['file'];
            if ($maxFileSize < $file['size']) {
                self::respondError(dcim_msg('error.file_upload_failed'));
            }
            if (!in_array($file['type'], $uptypes, true)) {
                self::respondError(str_replace('{type}', (string)$file['type'], dcim_msg('error.invalid_file_type_with_type')));
            }
            if (!file_exists($destinationFolder) && !mkdir($destinationFolder, 0755, true)) {
                self::respondError(str_replace('{path}', $destinationFolder, dcim_msg('error.create_dir_failed_with_path')));
            }

            $filename = $file['tmp_name'];
            $pinfo = pathinfo($file['name']);
            $ftype = $pinfo['extension'] ?? '';
            $destination = $destinationFolder . time() . '.' . $ftype;
            if (file_exists($destination)) {
                self::respondError(dcim_msg('error.file_already_exists'));
            }
            if (!move_uploaded_file($filename, $destination)) {
                self::respondError(dcim_msg('error.upload_file_failed'));
            }

            $newinfo = pathinfo($destination);
            $newname = $newinfo['filename'];
            $crud = new CrudController('dcim-dmpage');
            $row = $crud->findOne([['PageName', '=', $pinfo['filename']], ['status', '=', 1]]);
            if ($row) {
                $crud->legacyUpdate([
                    'id' => $row['id'],
                    'PageTxt' => $newname,
                ], [
                    'skip_auth' => true,
                    'id_required_message' => dcim_msg('common.id_required'),
                    'only_fields' => ['PageTxt'],
                ]);
            } else {
                $crud->legacyInsert([
                    'PageName' => $pinfo['filename'],
                    'PageIndex' => 1,
                    'PageType' => 1,
                    'PageTxt' => $newname,
                ]);
            }
            self::respondSuccess(tp_msg_success(), $newname);
        }
    }

    // POST /page/upload-image
    public static function uploadImage(): void
    {
        $uptypes = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/svg'];
        $maxFileSize = 20971520;
        $destinationFolder = self::publicPath('/Images/uploads/');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!is_uploaded_file($_FILES['file']['tmp_name'])) {
                self::respondError(dcim_msg('error.file_not_found'));
            }
            $file = $_FILES['file'];
            if ($maxFileSize < $file['size']) {
                self::respondError(dcim_msg('error.upload_file_failed'));
            }
            if (!in_array($file['type'], $uptypes, true)) {
                self::respondError(str_replace('{type}', (string)$file['type'], dcim_msg('error.invalid_file_type_with_type')));
            }
            if (!file_exists($destinationFolder) && !mkdir($destinationFolder, 0755, true)) {
                self::respondError(str_replace('{path}', $destinationFolder, dcim_msg('error.create_dir_failed_with_path')));
            }

            $filename = $file['tmp_name'];
            $pinfo = pathinfo($file['name']);
            $ftype = $pinfo['extension'] ?? '';
            $destination = $destinationFolder . time() . '.' . $ftype;
            if (file_exists($destination)) {
                self::respondError(dcim_msg('error.file_already_exists'));
            }
            if (!move_uploaded_file($filename, $destination)) {
                self::respondError(dcim_msg('error.upload_file_failed'));
            }
            $pinfo = pathinfo($destination);
            $fname = $pinfo['basename'];
            self::respondSuccess(tp_msg_success(), $fname);
        }
    }

    // POST /page/save
    public static function savePage(): void
    {
        $name = trim($_POST['name'] ?? '');
        $destinationFolder = self::publicPath('/Images/page/');
        $file = $destinationFolder . $name . '.txt';
        $content = $_POST['pagecon'] ?? '';

        if (!file_exists($destinationFolder) && !mkdir($destinationFolder, 0755, true)) {
                self::respondError(str_replace('{path}', $destinationFolder, dcim_msg('error.create_dir_failed_with_path')));
        }
        if (file_exists($file)) {
            unlink($file);
        }
        file_put_contents($file, $content);
        self::respondSuccess(tp_msg_success(), null);
    }

    // POST /page/save-template
    public static function saveTpl(): void
    {
        $name = trim($_POST['name'] ?? '');
        $destinationFolder = self::publicPath('/Images/pagetpl/');
        $file = $destinationFolder . $name . '.txt';
        $content = $_POST['tplcon'] ?? '';

        if (!file_exists($destinationFolder) && !mkdir($destinationFolder, 0755, true)) {
                self::respondError(str_replace('{path}', $destinationFolder, dcim_msg('error.create_dir_failed_with_path')));
        }
        if (file_exists($file)) {
            self::respondError(dcim_msg('error.file_already_exists'));
        }
        file_put_contents($file, $content);
        self::respondSuccess(tp_msg_success(), null);
    }


    // Merged from PersonController to reduce controller files.
private static function personCrud(string $table)
    {
        return new CrudController($table);
    }

    private static function personRenderZtLoginSqlError(): void
    {
        if (!headers_sent()) {
            $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
            header($protocol . ' 500 Internal Server Error', true, 500);
            header('Content-Type: text/html; charset=utf-8');
        }
            echo "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>system error</title></head><body><h1>SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax.</h1></body></html>";
        exit;
    }

    private static function ztPasswordVerify(string $inputPassword, string $storedPassword): bool
    {
        $input = trim($inputPassword);
        $stored = trim($storedPassword);
        if ($input === '' || $stored === '') {
            return false;
        }
        if (checkPassword($input, $stored)) {
            return true;
        }
        $inputMd5 = strtolower(md5($input));
        $inputSha1 = strtolower(sha1($input));
        $storedLower = strtolower($stored);
        if ($storedLower === $inputMd5 || $storedLower === $inputSha1 || $stored === $input) {
            return true;
        }
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $stored) === 1) {
            $decoded = base64_decode($stored, true);
            if (is_string($decoded) && $decoded !== '') {
                $decodedTrim = trim($decoded);
                if (
                    $decodedTrim === $input ||
                    strtolower($decodedTrim) === $inputMd5 ||
                    strtolower($decodedTrim) === $inputSha1
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function ztResolveTokenField(array $settingRow): string
    {
        foreach (['webToken', 'WebToken', 'token', 'Token'] as $field) {
            if (array_key_exists($field, $settingRow)) {
                return $field;
            }
        }
        return 'webToken';
    }


    private static function personRequireAuth(array $data = [])
    {
        $user = (new CrudController('dcim-person'))->legacyEnsureAuth($data);
        if (!$user) {
            L_E();
        }
        return $user;
    }

    private static function personNormalizeDateFields(array $data): array
    {
        $fields = [
            'ValidStratTime',
            'ValidStartTime',
            'ValidEndTime',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    private static function personRoleTryProxyLegacy(string $url, array $data): bool
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
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $hdr) {
                    if ($statusCode === null && preg_match('/^HTTP\/\S+\s+(\d{3})/i', $hdr, $m)) {
                        $statusCode = (int) $m[1];
                    }
                }
            }
            if ($statusCode !== null && ($statusCode < 200 || $statusCode >= 300)) {
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

    private static function personRoleBuildMenuTree(array $items, int $pid = 0, int $level = 1): array
    {
        $tree = [];
        foreach ($items as $item) {
            if ((int)($item['pid'] ?? 0) !== $pid) {
                continue;
            }
            $item['level'] = $level;
            $item['children'] = self::personRoleBuildMenuTree($items, (int)($item['id'] ?? 0), $level + 1);
            $tree[] = $item;
        }

        usort($tree, function ($a, $b) {
            $aOrder = isset($a['sort_order']) ? (int)$a['sort_order'] : 0;
            $bOrder = isset($b['sort_order']) ? (int)$b['sort_order'] : 0;
            if ($aOrder === $bOrder) {
                return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
            }
            return $aOrder <=> $bOrder;
        });

        return $tree;
    }

    private static function personRoleFetchMenus(array $allowedIds = [], string $search = ''): array
    {
        $whereParts = ['status <> -1'];
        $params = [];

        if ($search !== '') {
            $whereParts[] = 'menu_name LIKE :search';
            $params[':search'] = '%' . $search . '%';
        }

        if ($allowedIds) {
            $placeholders = [];
            foreach (array_values($allowedIds) as $idx => $menuId) {
                $ph = ':mid' . $idx;
                $placeholders[] = $ph;
                $params[$ph] = (int)$menuId;
            }
            $whereParts[] = 'id IN (' . implode(',', $placeholders) . ')';
        }

        $rows = self::personCrud('dcim-menu')->selectByRawCondition(
            implode(' AND ', $whereParts),
            'ORDER BY sort_order ASC, id ASC',
            $params
        );

        $menus = [];
        foreach ($rows as $row) {
            $menus[] = [
                'menu_name' => $row['menu_name'] ?? '',
                'url' => $row['url'] ?? '',
                'icon' => $row['icon'] ?? '',
                'id' => $row['id'] ?? null,
                'pid' => $row['pid'] ?? null,
                'sort_order' => $row['sort_order'] ?? 0,
                'is_menu' => $row['is_menu'] ?? null,
                'action_url' => $row['action_url'] ?? '',
                'dcim' => $row['dcim'] ?? null,
            ];
        }
        return $menus;
    }

    // POST /LoginKey
    public static function loginKey()
    {
        $data = Flight::request_data();
        $personCrud = self::personCrud('dcim-person');

        if (!empty($data['ticket'])) {
            if ($data['ticket'] !== 'login') {
                $checkUrl = "http://10.52.1.45:9990/uias/sso/checkTicket?ticket={$data['ticket']}&appid=jfyygl";
                $resp = @file_get_contents($checkUrl);
                $decoded = $resp ? json_decode($resp, true) : null;
                if (!$decoded || ($decoded['code'] ?? null) != 200) {
                    P_E(dcim_msg('error.ticket_invalid'));
                }

                $secretkey = 'UXz6A8wUofhXgmMfCRqIAKqVKSJsfA';
                $loginId = $decoded['data'];
                $timestamp = round(microtime(true) * 1000);
                $nonce = strval(rand(1000000000, 9999999999));
                $code = "loginId={$loginId}&nonce={$nonce}&timestamp={$timestamp}&key={$secretkey}";
                $sign = md5($code);
                $url = "http://10.52.1.45:9990/uias/sso/getData?loginId={$loginId}&timestamp={$timestamp}&nonce={$nonce}&sign={$sign}&appid=jfyygl";
                $resp2 = @file_get_contents($url);
                $dataResp = $resp2 ? json_decode($resp2, true) : null;
                if (!$dataResp || ($dataResp['code'] ?? null) != 200) {
                        P_E(self::normalizePublicMessage((string)($dataResp['msg'] ?? dcim_msg('error.sso_data_fetch_failed'))));
                }

                $companyName = $dataResp['data']['orgAccountName'] ?? '';
                if ($companyName === '') {
                    P_E(dcim_msg('error.org_account_empty'));
                }

                $roleCrud = self::personCrud('dcim-role');
                $roleRow = $roleCrud->findOne([
                    ['role_name', 'like', '%' . $companyName . '%'],
                    ['status', '=', 1],
                ]);
                if (!$roleRow) {
                    P_E(str_replace('{name}', $companyName, dcim_msg('error.company_role_not_found')));
                }

                $user = $personCrud->findOne([
                    ['RoleId', '=', $roleRow['id']],
                    ['status', '=', 1],
                ]);
                if (!$user) {
                    P_E(str_replace('{name}', $companyName, dcim_msg('error.company_no_available_user')));
                }
            } else {
                $user = $personCrud->findOne([['id', '=', 1]]);
                if (!$user) {
                    P_E(dcim_msg('error.user_not_found'));
                }
            }
        } else {
            $userName = $data['userName'] ?? '';
            $passWord = $data['passWord'] ?? '';
            if ($userName === '' || $passWord === '') {
                P_E(dcim_msg('error.username_or_password_empty'));
            }
            $user = $personCrud->findOne([
                ['PersonAccount', '=', $userName],
                ['status', '=', 1],
            ]);
            if (!$user) {
                P_E(dcim_msg('error.user_not_found'));
            }
            if (!checkPassword($passWord, $user['PersonPass'])) {
                P_E(dcim_msg('error.password_incorrect'));
            }
            if (isset($user['PersonStatus']) && $user['PersonStatus'] == -1) {
                P_E(dcim_msg('error.password_incorrect'));
            }
        }

        $token = TokenMd5($user['id']);
        if ($personCrud->legacyUpdateWhere([['id', '=', $user['id']]], ['token' => $token], [
            'keep_auth_fields' => true,
        ]) === false) {
            S_E(dcim_msg('error.login_failed'));
        }

        $list = [
            'token'     => $token,
            'adminName' => $user['PersonName'] ?? '',
            'userId'    => $user['id'],
        ];
        addLog(dcim_msg('log.login'), '', $user['id']);
        O_E($list, tp_msg_success(), 100, false);
    }

    // POST /ZTLoginKey
    public static function ztlogin()
    {
        $data = self::dvRequestData();
        $settingCrud = self::personCrud('dcim-setting');
        $account = trim((string)($data['account'] ?? ($data['username'] ?? ($data['userName'] ?? ''))));
        $password = (string)($data['password'] ?? ($data['passWord'] ?? ''));
        if ($account === '' || $password === '') {
            P_E(dcim_msg('common.username_password_required'));
        }

        $user = $settingCrud->findOne([
            ['id', '=', 1],
        ]);
        if ($user) {
            $storedAccount = trim((string)($user['webAccount'] ?? ''));
            if ($storedAccount !== '' && strcasecmp($storedAccount, $account) !== 0) {
                $user = null;
            }
        }
        if (!$user) {
            $user = $settingCrud->findOne([
                ['webAccount', '=', $account],
            ]);
        }
        if (!$user) {
                P_E(dcim_msg('error.user_not_found'));
        }
        $storedPass = (string)($user['webPass'] ?? '');
        $passOk = self::ztPasswordVerify($password, $storedPass);
        if (!$passOk) {
            P_E(dcim_msg('error.password_incorrect'));
        }

        $userId = (int)($user['id'] ?? 1);
        if ($userId <= 0) {
            $userId = 1;
        }
        $token = TokenMd5($userId);
        $tokenField = self::ztResolveTokenField((array)$user);
        $updateWebToken = $settingCrud->legacyUpdate([
            'id' => $userId,
            $tokenField => $token,
        ], [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => [$tokenField],
        ]);
        if ($updateWebToken === false) {
            S_E(dcim_msg('error.login_failed'));
        }

        O_E(['token' => $token], tp_msg_success(), 100, false);
    }
    // POST /checkTokenKey
    public static function checkToken()
    {
        $data = Flight::request_data();
        $token = trim((string)($data['token'] ?? ''));
        if ($token === '') {
            O_E(dcim_msg('common.token_invalid'), dcim_msg('common.token_invalid'), 100, 0);
            return;
        }
        $settingCrud = self::personCrud('dcim-setting');
        $row = $settingCrud->findOne([['id', '=', 1]]) ?: [];
        $storedTokens = [];
        foreach (['webToken', 'WebToken', 'token', 'Token'] as $tokenField) {
            $one = trim((string)($row[$tokenField] ?? ''));
            if ($one !== '') {
                $storedTokens[$one] = true;
            }
        }
        if (!$storedTokens || !isset($storedTokens[$token])) {
            O_E(dcim_msg('common.token_invalid'), dcim_msg('common.token_invalid'), 100, 0);
            return;
        }
        O_E(true);
    }

    // POST /LoginOutKey
    public static function loginOutKey()
    {
        $data = Flight::request_data();
        $token = $data['token'] ?? '';
        $personCrud = self::personCrud('dcim-person');
        $user = $personCrud->findOne([
            ['token', '=', $token],
            ['status', '=', 1],
        ]);
        if (!$user) {
            L_E();
            return;
        }
        $personCrud->legacyUpdateWhere([['id', '=', $user['id']]], ['token' => ''], [
            'keep_auth_fields' => true,
        ]);
        addLog(dcim_msg('log.logout'), '', $user['id']);
        O_E(true);
    }

    // POST /UpLoadPictureKey
    public static function uploadImg()
    {
        $data = Flight::request_data();
        self::personRequireAuth($data);
        if (!isset($data['img'])) {
            P_E(dcim_msg('error.img_required'));
        }
        $list = upload($data['img']);
        O_E($list);
    }

    // POST /GetLogoKey
    public static function getLogo()
    {
        $settingCrud = self::personCrud('dcim-setting');
        $info = $settingCrud->findOne([['id', '=', 1]]);
        if ($info) {
            unset($info['webAccount'], $info['webPass'], $info['webToken'], $info['WebToken'], $info['token'], $info['Token']);
            O_E_STR([$info], tp_msg_success(), 100, 0);
            return;
        }
        O_E_STR([], tp_msg_success(), 100, 0);
    }

    // POST /ChangeLogoKey
    public static function changeLogo()
    {
        $data = Flight::request_data();
        self::personRequireAuth($data);
        $fields = [];
        $logMsg = 'update config';
        if (!empty($data['logo'])) {
            $fields['logo'] = $data['logo'];
        } else {
            if (isset($data['ProId'])) {
                $fields['ProId'] = $data['ProId'];
            }
            if (isset($data['ProName'])) {
                $fields['ProName'] = $data['ProName'];
            }
        }
        if (!$fields) {
            P_E(dcim_msg('error.no_fields_to_update'));
        }
        $settingCrud = self::personCrud('dcim-setting');
        $updateData = $fields;
        $updateData['id'] = 1;
        $res = $settingCrud->legacyUpdate($updateData, [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        addLog($logMsg);
        O_E($res);
    }

    // POST /ChangePlatKey
    public static function changePlat()
    {
        $data = Flight::request_data();
        self::personRequireAuth($data);
        $fields = [];
        $log = '';
        if (!empty($data['pushData'])) {
            $fields['pushData'] = $data['pushData'];
            $fields['pushAddr'] = $data['pushAddr'] ?? null;
            $log = 'update config';
        }
        if (!empty($data['MasterSlaveOpen'])) {
            $fields['MasterSlaveOpen'] = $data['MasterSlaveOpen'];
            $fields['MasterSlaveRelation'] = $data['MasterSlaveRelation'] ?? null;
            $fields['MasterIp'] = $data['MasterIp'] ?? null;
            $fields['MasterSpareIp'] = $data['MasterSpareIp'] ?? null;
            $fields['SlaveIp'] = $data['SlaveIp'] ?? null;
            $log = 'update config';
        }
        if (!empty($data['dcim'])) {
            $fields['dcim'] = $data['dcim'];
            $log = 'update config';
        }
        if (!empty($data['DoorPush'])) {
            $fields['DoorPush'] = $data['DoorPush'];
            $fields['DoorPushAddr'] = $data['DoorPushAddr'] ?? null;
            $log = 'update config';
        }
        if (!$fields) {
            P_E(dcim_msg('error.no_fields_to_update'));
        }
        $settingCrud = self::personCrud('dcim-setting');
        $updateData = $fields;
        $updateData['id'] = 1;
        $res = $settingCrud->legacyUpdate($updateData, [
            'skip_auth' => true,
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($log) {
            addLog($log);
        }
        O_E($res);
    }

    // POST /GetPersonByGroupKey
    public static function getPersonByGroup()
    {
        $data = Flight::request_data();
        self::personRequireAuth($data);
        $groupId = $data['groupId'] ?? '';
        $groupStr = trim($groupId, ',');
        $groupArr = $groupStr === '' ? [] : array_filter(array_map('trim', explode(',', $groupStr)));
        if (empty($groupArr)) {
            O_E([]);
        }
        $conditions = [];
        $params = [];
        foreach ($groupArr as $idx => $gid) {
            $ph = ':g' . $idx;
            $conditions[] = "FIND_IN_SET({$ph}, GroupId) > 0";
            $params[$ph] = $gid;
        }
        $whereCondition = '(' . implode(' OR ', $conditions) . ') AND status = 1';
        $personCrud = self::personCrud('dcim-person');
        try {
            $result = $personCrud->selectByRawCondition($whereCondition, 'ORDER BY id ASC', $params);
        } catch (\Exception $e) {
            $result = [];
        }
        O_E($result);
    }

    // POST /CreateEmpKey
    public static function infoAdd()
    {
        $data = Flight::request_data();
        $data = self::personNormalizeDateFields($data);
        self::personRequireAuth($data);
        $crud = self::personCrud('dcim-person');
        if (!empty($data['PersonName'])) {
            $exists = $crud->findOne([['PersonName', '=', $data['PersonName']], ['status', '=', 1]]);
            if ($exists) {
                P_E(dcim_msg('error.export_file_invalid'));
            }
        }
        if (!empty($data['PersonNumber'])) {
            $exists = $crud->findOne([['PersonNumber', '=', $data['PersonNumber']], ['status', '=', 1]]);
            if ($exists) {
                P_E(dcim_msg('error.sheet_parse_failed'));
            }
        }
        if (!empty($data['PersonAccount'])) {
            $exists = $crud->findOne([['PersonAccount', '=', $data['PersonAccount']], ['status', '=', 1]]);
            if ($exists) {
                P_E(dcim_msg('error.sheet_parse_failed'));
            }
        }
        if (!isset($data['status'])) {
            $data['status'] = 1;
        }
        if (isset($data['PersonPass'])) {
            $data['PersonPass'] = password_hash($data['PersonPass'], PASSWORD_DEFAULT);
        }
        $id = $crud->legacyInsert($data);
        O_E(['id' => $id]);
    }

    // POST /GetEmpListKey
    public static function getList()
    {
        $data = Flight::request_data();
        self::personRequireAuth($data);
        $search = $data['search'] ?? '';
        $conditions = ['status = 1'];
        $params = [];
        if (isset($data['DeptId']) && $data['DeptId'] !== '' && $data['DeptId'] !== null) {
            $conditions[] = 'DeptId = :deptId';
            $params[':deptId'] = $data['DeptId'];
        }
        if ($search !== '') {
            $conditions[] = 'PersonName LIKE :search';
            $params[':search'] = '%' . $search . '%';
        }
        $where = implode(' AND ', $conditions);
        $page = isset($data['pageNo']) ? (int)$data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int)$data['pageSize'] : 15;
        $crud = self::personCrud('dcim-person');
        $result = $crud->selectWithPagination($where, $params, '', $page, $pageSize);
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        $deptIds = [];
        $roleIds = [];
        foreach ($rows as $row) {
            if (isset($row['DeptId']) && $row['DeptId'] !== '' && $row['DeptId'] !== null) {
                $deptIds[] = $row['DeptId'];
            }
            if (isset($row['RoleId']) && $row['RoleId'] !== '' && $row['RoleId'] !== null) {
                $roleIds[] = $row['RoleId'];
            }
        }
        $deptMap = [];
        foreach (self::personCrud('dcim-department')->selectByIds($deptIds, ['id', 'DeptName']) as $row) {
            $key = (string)($row['id'] ?? '');
            if ($key !== '') {
                $deptMap[$key] = $row;
            }
        }
        $roleMap = [];
        foreach (self::personCrud('dcim-role')->selectByIds($roleIds, ['id', 'role_name']) as $row) {
            $key = (string)($row['id'] ?? '');
            if ($key !== '') {
                $roleMap[$key] = $row;
            }
        }
        foreach ($rows as &$row) {
            $row['DeptName'] = $deptMap[(string)($row['DeptId'] ?? '')]['DeptName'] ?? '';
            $row['RoleName'] = $roleMap[(string)($row['RoleId'] ?? '')]['role_name'] ?? '';
        }
        unset($row);
        $result['info'] = $rows;
        O_E($result, tp_msg_success(), 100, false);
    }

    // POST /GetEmpDetailKey
    public static function getInfo()
    {
        $data = Flight::request_data();
        self::personRequireAuth($data);
        $id = $data['id'] ?? 0;
        $crud = self::personCrud('dcim-person');
        $info = $crud->findOne([['id', '=', $id], ['status', '=', 1]]);
        if ($info) {
            $dept = self::personCrud('dcim-department')->findOne([['id', '=', $info['DeptId'] ?? 0]]);
            $role = self::personCrud('dcim-role')->findOne([['id', '=', $info['RoleId'] ?? 0]]);
            $info['DeptName'] = $dept['DeptName'] ?? '';
            $info['RoleName'] = $role['role_name'] ?? '';
        }
        O_E($info ?: []);
    }

    // POST /ChangeEmpKey
    public static function infoUpdate()
    {
        $data = Flight::request_data();
        $data = self::personNormalizeDateFields($data);
        self::personRequireAuth($data);
        if (isset($data['PersonPass'])) {
            $data['PersonPass'] = password_hash($data['PersonPass'], PASSWORD_DEFAULT);
        }
        $crud = self::personCrud('dcim-person');
        $res = $crud->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res);
    }

    // POST /DelEmpKey
    public static function infoDel()
    {
        $data = Flight::request_data();
        self::personRequireAuth($data);
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }
        $crud = self::personCrud('dcim-person');
        $res = $crud->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E(true);
    }

    // POST /ChangePwdKey
    public static function ChangePwdKey()
    {
        $data = Flight::request_data();
        self::personRequireAuth($data);
        $userId = $data['id'] ?? ($data['UserId'] ?? ($data['UserLsh'] ?? null));
        if (empty($data['newPWD']) || empty($userId)) {
            O_E(false, dcim_msg('error.new_password_user_id_required'), 400, 0);
            return;
        }
        $newPwd = password_hash($data['newPWD'], PASSWORD_DEFAULT);
        $crud = self::personCrud('dcim-person');
        $res = $crud->legacyUpdateWhere([['id', '=', $userId], ['status', '=', 1]], ['PersonPass' => $newPwd]);
        if (!$res) {
            P_E(dcim_msg('error.user_not_found'));
        }
        addLog(dcim_msg('log.password_change'));
        O_E(true);
    }

    // POST /ChangeEmpStatusKey
    public static function ChangeEmpStatusKey()
    {
        $data = Flight::request_data();
        self::personRequireAuth($data);
        if (empty($data['id'])) {
            P_E(dcim_msg('common.id_required'));
        }
        $crud = self::personCrud('dcim-person');
        $res = $crud->legacyUpdateWhere([['id', '=', $data['id']], ['status', '=', 1]], ['PersonStatus' => $data['status'] ?? 0]);
        if (!$res) {
            P_E(dcim_msg('error.user_not_found'));
        }
        addLog(($data['status'] ?? '') == '1' ? dcim_msg('log.account_enabled') : dcim_msg('log.account_disabled'));
        O_E(true);
    }

    public static function roleGetList()
    {
        $data = Flight::request_data();
        $result = self::personCrud('dcim-role')->legacyList($data, [
            'base_where' => ['status = 1'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    public static function roleGetInfo()
    {
        $data = Flight::request_data();
        $info = self::personCrud('dcim-role')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    public static function roleInfoAdd()
    {
        $data = Flight::request_data();
        $id = self::personCrud('dcim-role')->legacyCreate($data, [
            'required_fields' => [
                'role_name' => dcim_msg('error.role_name_required'),
                'role_menus' => dcim_msg('error.role_menus_required'),
            ],
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    public static function roleInfoUpdate()
    {
        $data = Flight::request_data();
        $res = self::personCrud('dcim-role')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function roleInfoDel()
    {
        $data = Flight::request_data();
        $res = self::personCrud('dcim-role')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function roleGetMenuList()
    {
        try {
            $data = Flight::request_data();
            $user = self::personRequireAuth($data);
            if (!$user) {
                return;
            }

            $search = trim((string)($data['search'] ?? ''));
            $menus = self::personRoleFetchMenus([], $search);
            $tree = self::personRoleBuildMenuTree($menus);
            O_E($tree, tp_msg_success(), 100, $tree ? count($tree) : false);
        } catch (\Throwable $e) {
            S_E(dcim_msg('common.server_error'));
        }
    }

    public static function roleMyMenus()
    {
        $data = Flight::request_data();
        $user = self::personRequireAuth($data);

        $allowedIds = [];
        if (!empty($user['RoleId']) && (int)$user['RoleId'] !== 1) {
            $role = self::personCrud('dcim-role')->findOne([
                ['id', '=', $user['RoleId']],
                ['status', '=', 1],
            ]);
            if (!$role) {
                O_E(false, dcim_msg('error.role_not_found_simple'), 100, false);
                return;
            }

            $roles = $role['role_menus'] ?? '';
            if ($roles === '' || $roles === null) {
                O_E(false, dcim_msg('error.role_no_menus_simple'), 100, false);
                return;
            }

            $allowedIds = array_values(array_filter(array_map('intval', explode(',', $roles))));
            if (!$allowedIds) {
                O_E(false, dcim_msg('error.role_no_valid_menus_simple'), 100, false);
                return;
            }
        }

        $menus = self::personRoleFetchMenus($allowedIds);
        $tree = self::personRoleBuildMenuTree($menus);
        O_E($tree, tp_msg_success(), 100, $tree ? count($tree) : false);
    }

    public static function roleMenuIdToRole()
    {
        self::personRequireAuth(Flight::request_data());
        O_E(true, tp_msg_success(), 100, 1);
    }

    // POST /login (compat)
    public static function authLoginCompat()
    {
        $data = Flight::request()->data->getData();
        if (!is_array($data)) {
            $data = [];
        }
        $raw = @file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = array_merge($decoded, $data);
            }
        }
        $fallbackData = Flight::request_data();
        if (is_array($fallbackData)) {
            $data = array_merge($fallbackData, $data);
        }

        $username = isset($data['username']) ? trim((string)$data['username']) : '';
        $passwordInput = isset($data['password']) ? trim((string)$data['password']) : '';
        $tenantId = isset($data['tenant_id']) ? trim((string)$data['tenant_id']) : '';

        if ($username === '' || $passwordInput === '') {
            json_string_response(['status' => 'error', 'message' => dcim_msg('common.username_password_required')], 400);
            return;
        }

        $passwordCandidates = [md5($passwordInput)];
        if (preg_match('/^[a-fA-F0-9]{32}$/', $passwordInput) === 1) {
            $passwordCandidates[] = strtolower($passwordInput);
        }
        $passwordCandidates = array_values(array_unique($passwordCandidates));

        $personCrud = self::personCrud('dcim-person');
        $user = null;
        foreach ($passwordCandidates as $password) {
            $user = $personCrud->findOne([
                ['PersonAccount', '=', $username],
                ['PersonPass', '=', $password],
                ['status', '=', 1],
            ]);
            if ($user) {
                break;
            }
        }
        if ($user) {
            $token = bin2hex(random_bytes(16));
            $personCrud->legacyUpdate([
                'id' => $user['id'],
                'token' => $token,
            ], [
                'skip_auth' => true,
                'id_required_message' => dcim_msg('common.id_required'),
                'only_fields' => ['token'],
            ]);

            json_string_response([
                'status' => 'ok',
                'token' => $token,
                'adminName' => $user['userName'] ?? ($user['PersonName'] ?? $username),
                'powerId' => $user['power_id'] ?? ($user['RoleId'] ?? ''),
                'tenant_id' => $tenantId,
                'id' => $user['id'],
            ]);
        } else {
            json_string_response(['status' => 'error', 'message' => tp_msg_login()], 401);
            return;
        }
    }

    // POST /logout (compat)
    public static function authLogoutCompat()
    {
        $token = dcim_extract_token(Flight::request()->data->getData());
        self::personCrud('dcim-person')->legacyUpdateWhere([
            ['token', '=', $token],
        ], [
            'token' => null,
        ], [
            'keep_auth_fields' => true,
        ]);

        json_string_response(['status' => 'ok']);
    }

    public static function authCheckCompat()
    {
        $token = dcim_extract_token(Flight::request()->data->getData());
        if ($token !== '') {
            $user = self::personCrud('dcim-person')->findOne([
                ['token', '=', $token],
            ]);
            if ($user) {
                return $user;
            }
        }
        json_string_response(['status' => 'error', 'message' => tp_msg_login()], 401);
        return false;
    }


    // Merged from ExportDataController to reduce controller files.
    private const EXPORT_CONFIGS = [
        'asset_all' => [
            'table' => 'dcim-asset',
            'active_only' => true,
            'not_deleted_only' => true,
            'search_fields' => ['AssetName', 'AssetNo', 'SN', 'Code', 'AssetCode'],
            'default_order' => 'id',
            'default_sequence' => 'DESC',
        ],
        'history_alarms' => [
            'table' => 'dcim-alarmlist',
            'active_only' => true,
            'not_deleted_only' => true,
            'search_fields' => ['AlarmName', 'DeviceName', 'AreaName', 'AlarmType', 'AlarmKey'],
            'default_order' => 'create_time',
            'default_sequence' => 'DESC',
        ],
        'alarm_param' => [
            'table' => 'dcim-alarmlist',
            'active_only' => true,
            'not_deleted_only' => true,
            'force_csv' => true,
            'csv_builder' => 'alarm_param_summary',
            'search_fields' => ['TextMessage', 'ParamValue', 'OrderNumber'],
            'default_order' => 'create_time',
            'default_sequence' => 'DESC',
        ],
        'alarm_notify' => [
            'table' => 'dcim-alarmnotifylist',
            'active_only' => true,
            'not_deleted_only' => true,
            'force_csv' => true,
            'csv_builder' => 'alarm_notify_summary',
            'search_fields' => ['AlarmName', 'AlarmKey', 'AlarmLevel'],
            'default_order' => 'id',
            'default_sequence' => 'DESC',
        ],
        'alarm_param_detail' => [
            'table' => 'dcim-alarmnotifymode',
            'active_only' => true,
            'not_deleted_only' => true,
            'exclude_deleted_person_by_dev_id' => true,
            'search_fields' => ['AlarmName', 'AlarmKey', 'DevId'],
            'default_order' => 'id',
            'default_sequence' => 'DESC',
        ],
        'yw_workorder' => [
            'table' => 'dcim-order',
            'active_only' => true,
            'not_deleted_only' => true,
            'search_fields' => ['OrderNo', 'OrderName', 'Content', 'CreateUser'],
            'default_order' => 'id',
            'default_sequence' => 'DESC',
        ],
        'xj_task' => [
            'table' => 'dcim-xjtask',
            'active_only' => true,
            'not_deleted_only' => true,
            'search_fields' => ['TaskName', 'TaskType', 'TaskNo'],
            'default_order' => 'id',
            'default_sequence' => 'DESC',
        ],
        'wh_task' => [
            'table' => 'dcim-whtask',
            'active_only' => true,
            'not_deleted_only' => true,
            'search_fields' => ['TaskName', 'TaskType', 'TaskNo'],
            'default_order' => 'id',
            'default_sequence' => 'DESC',
        ],
        'device_command_send' => [
            'table' => 'dcim-devcommondsendlist',
            'active_only' => true,
            'not_deleted_only' => true,
            'force_csv' => true,
            'csv_builder' => 'device_command_send_summary',
            'search_fields' => ['CommandName', 'CommandType', 'DeviceName'],
            'default_order' => 'id',
            'default_sequence' => 'DESC',
        ],
        'door_command_send' => [
            'table' => 'dcim-dooroperlog',
            'active_only' => true,
            'not_deleted_only' => true,
            'search_fields' => ['DoorName', 'Command', 'OperateName'],
            'default_order' => 'id',
            'default_sequence' => 'DESC',
        ],
        'operation_record' => [
            'table' => 'dcim-syslog',
            'active_only' => true,
            'not_deleted_only' => true,
            'force_csv' => true,
            'search_fields' => ['content', 'params'],
            'default_order' => 'id',
            'default_sequence' => 'DESC',
        ],
    ];

    private static function edCrud(string $table)
    {
        return new CrudController($table);
    }

    private static function edRequireAuth(array $data = [])
    {
        $user = self::edCrud('dcim-person')->legacyEnsureAuth($data);
        if (!$user) {
            L_E(tp_msg_login());
        }
        return $user;
    }

    private static function edOk($data = [])
    {
        O_E($data, tp_msg_success(), 100, $data ? 1 : false);
    }

    private static function edResolvePublicAbsolutePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        if (is_file($path)) {
            return $path;
        }
        $publicRoot = realpath(dirname(__DIR__, 2) . '/public');
        if ($publicRoot === false) {
            return null;
        }
        $normalized = '/' . ltrim(str_replace('\\', '/', $path), '/');
        if (strpos($normalized, '..') !== false) {
            return null;
        }
        $absolute = rtrim(str_replace('\\', '/', $publicRoot), '/') . $normalized;
        return is_file($absolute) ? $absolute : null;
    }

    private static function edLocateExportFileByName(string $fileName): ?string
    {
        $fileName = trim($fileName);
        if ($fileName === '') {
            return null;
        }
        $publicRoot = realpath(dirname(__DIR__, 2) . '/public');
        if ($publicRoot === false) {
            return null;
        }
        $publicRoot = rtrim(str_replace('\\', '/', $publicRoot), '/');
        $candidates = [
            $publicRoot . '/exports/' . date('Ymd') . '/' . $fileName,
            $publicRoot . '/exports/' . $fileName,
            $publicRoot . '/uploads/exports/' . date('Ymd') . '/' . $fileName,
            $publicRoot . '/uploads/exports/' . $fileName,
            $publicRoot . '/uploads/' . $fileName,
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private static function edStreamDownload(string $absolutePath, string $downloadName, string $contentType): void
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            P_E(dcim_msg('error.file_not_found'));
        }
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
        }
        $size = @filesize($absolutePath);
        if (!headers_sent()) {
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
            header('Content-Transfer-Encoding: binary');
            if ($size !== false) {
                header('Content-Length: ' . (string)$size);
            }
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: public');
            header('Expires: 0');
        }
        $fp = fopen($absolutePath, 'rb');
        if ($fp === false) {
            P_E(dcim_msg('error.file_not_found'));
        }
        fpassthru($fp);
        fclose($fp);
        exit;
    }

    private static function edStreamCsvFromRows(array $rows, string $downloadName = 'export.csv'): void
    {
        if (!$rows) {
            P_E(dcim_msg('error.export_file_invalid'));
        }
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
        }
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: public');
            header('Expires: 0');
        }
        $out = fopen('php://output', 'w');
        if ($out === false) {
            P_E(dcim_msg('error.export_file_invalid'));
        }
        $first = (array)reset($rows);
        if ($first) {
            fputcsv($out, array_keys($first));
        }
        foreach ($rows as $row) {
            fputcsv($out, array_values((array)$row));
        }
        fclose($out);
        exit;
    }

    private static function edStreamCsvByColumns(array $columns, string $downloadName = 'export.csv'): void
    {
        $columns = array_values(array_filter(array_map(static function ($col) {
            return is_string($col) ? trim($col) : '';
        }, $columns), static function ($col) {
            return $col !== '';
        }));
        if (!$columns) {
            P_E(dcim_msg('error.export_file_invalid'));
        }
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
        }
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: public');
            header('Expires: 0');
        }
        $out = fopen('php://output', 'w');
        if ($out === false) {
            P_E(dcim_msg('error.export_file_invalid'));
        }
        fputcsv($out, $columns);
        fclose($out);
        exit;
    }

    private static function edAlarmParamExportHeaders(): array
    {
        return [
            dcim_msg('app.export_alarm_param_col_no'),
            dcim_msg('app.export_alarm_param_col_source'),
            dcim_msg('app.export_alarm_param_col_name'),
            dcim_msg('app.export_alarm_param_col_value'),
            dcim_msg('app.export_alarm_param_col_start_time'),
            dcim_msg('app.export_alarm_param_col_end_time'),
            dcim_msg('app.export_alarm_param_col_end_desc'),
            dcim_msg('app.export_alarm_param_col_confirm_user'),
            dcim_msg('app.export_alarm_param_col_confirm_time'),
            dcim_msg('app.export_alarm_param_col_solution'),
            dcim_msg('app.export_alarm_param_col_category'),
            dcim_msg('app.export_alarm_param_col_level'),
            dcim_msg('app.export_alarm_param_col_area'),
        ];
    }

    private static function edAlarmNotifyExportHeaders(): array
    {
        return [
            dcim_msg('app.export_alarm_notify_col_no'),
            dcim_msg('app.export_alarm_notify_col_type'),
            dcim_msg('app.export_alarm_notify_col_addr'),
            dcim_msg('app.export_alarm_notify_col_content'),
            dcim_msg('app.export_alarm_notify_col_result'),
            dcim_msg('app.export_alarm_notify_col_create_time'),
            dcim_msg('app.export_alarm_notify_col_update_time'),
        ];
    }

    private static function edDeviceCommandSendExportHeaders(): array
    {
        return [
            dcim_msg('app.export_device_command_send_col_no'),
            dcim_msg('app.export_device_command_send_col_control_time'),
            dcim_msg('app.export_device_command_send_col_device'),
            dcim_msg('app.export_device_command_send_col_command_id'),
            dcim_msg('app.export_device_command_send_col_command_name'),
            dcim_msg('app.export_device_command_send_col_command'),
            dcim_msg('app.export_device_command_send_col_operator'),
            dcim_msg('app.export_device_command_send_col_response'),
            dcim_msg('app.export_device_command_send_col_ip'),
        ];
    }

    private static function edPickField(array $row, array $candidates, string $default = ''): string
    {
        if (!$row) {
            return $default;
        }
        $lowerMap = [];
        foreach ($row as $k => $v) {
            $lowerMap[strtolower((string)$k)] = $v;
        }
        foreach ($candidates as $key) {
            if (array_key_exists($key, $row)) {
                return trim((string)$row[$key]);
            }
            $lk = strtolower((string)$key);
            if (array_key_exists($lk, $lowerMap)) {
                return trim((string)$lowerMap[$lk]);
            }
        }
        return $default;
    }

    private static function edBuildAlarmParamSummaryRows(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $devIds = [];
        $modeIds = [];
        $typeIds = [];
        $userIds = [];
        $levelIds = [];
        foreach ($rows as $row) {
            $devId = self::edPickField((array)$row, ['DevId', 'DevID', 'DeviceId', 'DeviceID']);
            if ($devId !== '') {
                $devIds[$devId] = true;
            }
            $modeId = self::edPickField((array)$row, ['NotifyModeID', 'NotifyModeId', 'NotifyModeid']);
            if ($modeId !== '') {
                $modeIds[$modeId] = true;
            }
            $alarmType = self::edPickField((array)$row, ['AlarmType']);
            if ($alarmType !== '') {
                $typeIds[$alarmType] = true;
            }
            $confirmUser = self::edPickField((array)$row, ['ConfirmUserId', 'ConfirmUserID']);
            if ($confirmUser !== '') {
                $userIds[$confirmUser] = true;
            }
            $level = self::edPickField((array)$row, ['AlarmLevel']);
            if ($level !== '') {
                $levelIds[$level] = true;
            }
        }

        $deviceMap = [];
        $areaIds = [];
        if ($devIds) {
            foreach (self::edCrud('dcim-device')->selectByIds(array_keys($devIds), ['id', 'DeviceName', 'AreaId']) as $dev) {
                $did = trim((string)($dev['id'] ?? ''));
                if ($did === '') {
                    continue;
                }
                $aid = trim((string)($dev['AreaId'] ?? ''));
                $deviceMap[$did] = [
                    'DeviceName' => trim((string)($dev['DeviceName'] ?? '')),
                    'AreaId' => $aid,
                ];
                if ($aid !== '') {
                    $areaIds[$aid] = true;
                }
            }
        }
        $areaMap = [];
        if ($areaIds) {
            foreach (self::edCrud('dcim-area')->selectByIds(array_keys($areaIds), ['id', 'AreaName']) as $area) {
                $aid = trim((string)($area['id'] ?? ''));
                if ($aid !== '') {
                    $areaMap[$aid] = trim((string)($area['AreaName'] ?? ''));
                }
            }
        }
        $modeMap = [];
        if ($modeIds) {
            foreach (self::edCrud('dcim-alarmnotifymode')->selectByIds(array_keys($modeIds), ['id', 'AlarmName']) as $mode) {
                $mid = trim((string)($mode['id'] ?? ''));
                if ($mid !== '') {
                    $modeMap[$mid] = trim((string)($mode['AlarmName'] ?? ''));
                }
            }
        }
        $typeMap = [];
        if ($typeIds) {
            foreach (self::edCrud('dcim-alarmtype')->selectByIds(array_keys($typeIds), ['id', 'TypeName', 'AlarmName']) as $typeRow) {
                $tid = trim((string)($typeRow['id'] ?? ''));
                if ($tid !== '') {
                    $typeName = trim((string)($typeRow['TypeName'] ?? ''));
                    if ($typeName === '') {
                        $typeName = trim((string)($typeRow['AlarmName'] ?? ''));
                    }
                    $typeMap[$tid] = $typeName;
                }
            }
        }
        $userMap = [];
        if ($userIds) {
            foreach (self::edCrud('dcim-person')->selectByIds(array_keys($userIds), ['id', 'PersonName']) as $person) {
                $pid = trim((string)($person['id'] ?? ''));
                if ($pid !== '') {
                    $userMap[$pid] = trim((string)($person['PersonName'] ?? ''));
                }
            }
        }
        $levelMap = [];
        if ($levelIds) {
            foreach (self::edCrud('dcim-alarmlevellist')->selectByIds(array_keys($levelIds), ['id', 'LevelName']) as $levelRow) {
                $lid = trim((string)($levelRow['id'] ?? ''));
                if ($lid !== '') {
                    $levelMap[$lid] = trim((string)($levelRow['LevelName'] ?? ''));
                }
            }
        }

        $headers = self::edAlarmParamExportHeaders();
        $out = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            $id = self::edPickField($row, ['id']);
            $modeId = self::edPickField($row, ['NotifyModeID', 'NotifyModeId', 'NotifyModeid']);
            $devId = self::edPickField($row, ['DevId', 'DevID', 'DeviceId', 'DeviceID']);
            $typeId = self::edPickField($row, ['AlarmType']);
            $confirmUserId = self::edPickField($row, ['ConfirmUserId', 'ConfirmUserID']);
            $levelId = self::edPickField($row, ['AlarmLevel']);
            $devInfo = $deviceMap[$devId] ?? ['DeviceName' => '', 'AreaId' => ''];
            $areaId = trim((string)($devInfo['AreaId'] ?? ''));
            $eventName = $modeMap[$modeId] ?? '';
            if ($eventName === '') {
                $eventName = self::edPickField($row, ['TextMessage']);
            }
            $levelName = $levelMap[$levelId] ?? '';
            if ($levelName === '') {
                if ($levelId === '1') {
                    $levelName = dcim_msg('app.alarm_level_1');
                } elseif ($levelId === '2') {
                    $levelName = dcim_msg('app.alarm_level_2');
                } elseif ($levelId === '3') {
                    $levelName = dcim_msg('app.alarm_level_3');
                } elseif ($levelId === '4') {
                    $levelName = dcim_msg('app.alarm_level_4');
                } elseif ($levelId === '5') {
                    $levelName = dcim_msg('app.alarm_level_5');
                } else {
                    $levelName = $levelId;
                }
            }
            $out[] = [
                $headers[0] => $id,
                $headers[1] => (string)($devInfo['DeviceName'] ?? ''),
                $headers[2] => $eventName,
                $headers[3] => self::edPickField($row, ['ParamValue']),
                $headers[4] => self::edPickField($row, ['create_time', 'CreateTime']),
                $headers[5] => self::edPickField($row, ['CancelTime']),
                $headers[6] => self::edPickField($row, ['CancelDesc']),
                $headers[7] => (string)($userMap[$confirmUserId] ?? ''),
                $headers[8] => self::edPickField($row, ['ConfirmTime']),
                $headers[9] => self::edPickField($row, ['Solution']),
                $headers[10] => (string)($typeMap[$typeId] ?? $typeId),
                $headers[11] => $levelName,
                $headers[12] => (string)($areaMap[$areaId] ?? ''),
            ];
        }
        return $out;
    }

    private static function edBuildAlarmNotifySummaryRows(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $headers = self::edAlarmNotifyExportHeaders();
        $out = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            $notifyResult = self::edPickField($row, ['NotifyResult']);
            if ($notifyResult === '') {
                $notifyResult = self::edPickField($row, ['SmsResult']);
            }
            $out[] = [
                $headers[0] => self::edPickField($row, ['id']),
                $headers[1] => self::edPickField($row, ['NotifyType']),
                $headers[2] => self::edPickField($row, ['NotifyAddr']),
                $headers[3] => self::edPickField($row, ['NotifyContent']),
                $headers[4] => $notifyResult,
                $headers[5] => self::edPickField($row, ['create_time', 'CreateTime']),
                $headers[6] => self::edPickField($row, ['update_time', 'UpdateTime']),
            ];
        }
        return $out;
    }

    private static function edBuildDeviceCommandSendSummaryRows(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $devIds = [];
        $empIds = [];
        $cmdIds = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            $devId = self::edPickField($row, ['DevID', 'DevId', 'DeviceId']);
            if ($devId !== '') {
                $devIds[$devId] = true;
            }
            $empId = self::edPickField($row, ['CreateEmpId', 'EmpId']);
            if ($empId !== '') {
                $empIds[$empId] = true;
            }
            $cmdId = self::edPickField($row, ['CommandID', 'CommandId']);
            if ($cmdId !== '') {
                $cmdIds[$cmdId] = true;
            }
        }
        $devMap = [];
        if ($devIds) {
            foreach (self::edCrud('dcim-device')->selectByIds(array_keys($devIds), ['id', 'DeviceName']) as $dev) {
                $id = trim((string)($dev['id'] ?? ''));
                if ($id !== '') {
                    $devMap[$id] = trim((string)($dev['DeviceName'] ?? ''));
                }
            }
        }
        $empMap = [];
        if ($empIds) {
            foreach (self::edCrud('dcim-person')->selectByIds(array_keys($empIds), ['id', 'PersonName']) as $emp) {
                $id = trim((string)($emp['id'] ?? ''));
                if ($id !== '') {
                    $empMap[$id] = trim((string)($emp['PersonName'] ?? ''));
                }
            }
        }
        $cmdMap = [];
        if ($cmdIds) {
            foreach (self::edCrud('dcim-devicecommand')->selectByIds(array_keys($cmdIds), ['id', 'CommandDesc', 'Command']) as $cmdRow) {
                $id = trim((string)($cmdRow['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $name = trim((string)($cmdRow['CommandDesc'] ?? ''));
                if ($name === '') {
                    $name = trim((string)($cmdRow['Command'] ?? ''));
                }
                $cmdMap[$id] = $name;
            }
        }
        $headers = self::edDeviceCommandSendExportHeaders();
        $out = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            $devId = self::edPickField($row, ['DevID', 'DevId', 'DeviceId']);
            $empId = self::edPickField($row, ['CreateEmpId', 'EmpId']);
            $cmdId = self::edPickField($row, ['CommandID', 'CommandId']);
            $result = self::edPickField($row, ['RecvData']);
            if ($result === '') {
                $result = self::edPickField($row, ['CommandRecv']);
            }
            $controlTime = self::edPickField($row, ['SendTime']);
            if ($controlTime === '') {
                $controlTime = self::edPickField($row, ['create_time', 'CreateTime']);
            }
            $commandName = (string)($cmdMap[$cmdId] ?? '');
            if ($commandName === '') {
                $commandName = self::edPickField($row, ['CommandName', 'CommandDesc']);
            }
            $out[] = [
                $headers[0] => self::edPickField($row, ['id']),
                $headers[1] => $controlTime,
                $headers[2] => (string)($devMap[$devId] ?? ''),
                $headers[3] => $cmdId,
                $headers[4] => $commandName,
                $headers[5] => self::edPickField($row, ['Command']),
                $headers[6] => (string)($empMap[$empId] ?? ''),
                $headers[7] => $result,
                $headers[8] => self::edPickField($row, ['ip', 'IP']),
            ];
        }
        return $out;
    }

    private static function edExportByCrud(array $config): void
    {
        $data = Flight::request_data();
        self::edRequireAuth($data);
        unset($data['token']);

        $table = $config['table'] ?? '';
        if (!is_string($table) || trim($table) === '') {
            P_E(dcim_msg('error.export_table_config_missing'));
        }

        try {
            $tableCandidates = [$table];
            if ($table === 'dcim-devcommondsendlist') {
                $tableCandidates[] = 'dcim-devicecommandsendlist';
                $tableCandidates[] = 'dcim-devicecommandsend';
                $tableCandidates[] = 'dcim-devcommandrecord';
            }
            $result = null;
            $lastError = null;
            foreach (array_values(array_unique($tableCandidates)) as $oneTable) {
                try {
                    $cfg = $config;
                    $cfg['table'] = $oneTable;
                    $crud = self::edCrud($oneTable);
                    $result = $crud->exportByFilterData($data, $cfg);
                    $lastError = null;
                    break;
                } catch (\Throwable $e) {
                    $lastError = $e;
                }
            }
            if (!is_array($result)) {
                if ($lastError instanceof \Throwable) {
                    throw $lastError;
                }
                P_E(dcim_msg('error.export_file_invalid'));
            }
            $rows = [];
            if (isset($result['rows']) && is_array($result['rows'])) {
                $rows = $result['rows'];
            } elseif (isset($result['info']) && is_array($result['info'])) {
                $rows = $result['info'];
            } elseif (isset($result['data']) && is_array($result['data'])) {
                if (isset($result['data']['info']) && is_array($result['data']['info'])) {
                    $rows = $result['data']['info'];
                } elseif (array_keys($result['data']) === range(0, count($result['data']) - 1)) {
                    $rows = $result['data'];
                }
            }
            $csvRows = $rows;
            if (!empty($config['csv_builder']) && $config['csv_builder'] === 'alarm_param_summary') {
                $csvRows = self::edBuildAlarmParamSummaryRows($rows);
            } elseif (!empty($config['csv_builder']) && $config['csv_builder'] === 'alarm_notify_summary') {
                $csvRows = self::edBuildAlarmNotifySummaryRows($rows);
            } elseif (!empty($config['csv_builder']) && $config['csv_builder'] === 'device_command_send_summary') {
                $csvRows = self::edBuildDeviceCommandSendSummaryRows($rows);
            }
            if (!empty($config['force_csv'])) {
                $csvPath = null;
                if (!empty($result['csv_abs_path']) && is_string($result['csv_abs_path']) && is_file($result['csv_abs_path'])) {
                    $csvPath = $result['csv_abs_path'];
                }
                if ($csvPath === null && isset($result['csv_path'])) {
                    $csvPath = self::edResolvePublicAbsolutePath((string)$result['csv_path']);
                }
                if ($csvPath === null && !empty($result['csv_file'])) {
                    $csvPath = self::edLocateExportFileByName((string)$result['csv_file']);
                }
                if ($csvPath !== null) {
                    $csvName = (string)($result['csv_file'] ?? basename($csvPath));
                    self::edStreamDownload($csvPath, $csvName !== '' ? $csvName : 'export.csv', 'text/csv; charset=utf-8');
                }
                $downloadName = 'export_' . date('Ymd_His') . '.csv';
                if ($csvRows) {
                    self::edStreamCsvFromRows($csvRows, $downloadName);
                }
                if (!empty($config['csv_builder']) && $config['csv_builder'] === 'alarm_param_summary') {
                    self::edStreamCsvByColumns(self::edAlarmParamExportHeaders(), $downloadName);
                }
                if (!empty($config['csv_builder']) && $config['csv_builder'] === 'alarm_notify_summary') {
                    self::edStreamCsvByColumns(self::edAlarmNotifyExportHeaders(), $downloadName);
                }
                if (!empty($config['csv_builder']) && $config['csv_builder'] === 'device_command_send_summary') {
                    self::edStreamCsvByColumns(self::edDeviceCommandSendExportHeaders(), $downloadName);
                }
                if (!empty($result['columns']) && is_array($result['columns'])) {
                    self::edStreamCsvByColumns($result['columns'], $downloadName);
                }
            }
            $fallbackPath = null;
            foreach (['zip_abs_path', 'excel_abs_path', 'csv_abs_path', 'abs_path', 'file_abs_path'] as $absKey) {
                if (!empty($result[$absKey]) && is_string($result[$absKey]) && is_file($result[$absKey])) {
                    $fallbackPath = $result[$absKey];
                    break;
                }
            }
            if ($fallbackPath === null) {
                foreach (['zip_path', 'excel_path', 'csv_path', 'path', 'file_path', 'download_path'] as $pathKey) {
                    if (empty($result[$pathKey]) || !is_string($result[$pathKey])) {
                        continue;
                    }
                    $resolved = self::edResolvePublicAbsolutePath($result[$pathKey]);
                    if ($resolved !== null) {
                        $fallbackPath = $resolved;
                        break;
                    }
                }
            }
            if ($fallbackPath === null) {
                foreach (['zip_file', 'excel_file', 'csv_file', 'file', 'filename', 'download_file'] as $nameKey) {
                    if (empty($result[$nameKey]) || !is_string($result[$nameKey])) {
                        continue;
                    }
                    $located = self::edLocateExportFileByName($result[$nameKey]);
                    if ($located !== null) {
                        $fallbackPath = $located;
                        break;
                    }
                }
            }
            $zipPath = null;
            if (!empty($result['zip_abs_path']) && is_string($result['zip_abs_path']) && is_file($result['zip_abs_path'])) {
                $zipPath = $result['zip_abs_path'];
            }
            if ($zipPath === null && isset($result['zip_path'])) {
                $zipPath = self::edResolvePublicAbsolutePath((string)$result['zip_path']);
            }
            if ($zipPath === null && !empty($result['zip_file'])) {
                $zipPath = self::edLocateExportFileByName((string)$result['zip_file']);
            }
            if ($zipPath !== null) {
                $zipName = (string)($result['zip_file'] ?? basename($zipPath));
                self::edStreamDownload($zipPath, $zipName !== '' ? $zipName : 'export.zip', 'application/zip');
            }
            $excelPath = null;
            if (!empty($result['excel_abs_path']) && is_string($result['excel_abs_path']) && is_file($result['excel_abs_path'])) {
                $excelPath = $result['excel_abs_path'];
            }
            if ($excelPath === null && isset($result['excel_path'])) {
                $excelPath = self::edResolvePublicAbsolutePath((string)$result['excel_path']);
            }
            if ($excelPath === null && !empty($result['excel_file'])) {
                $excelPath = self::edLocateExportFileByName((string)$result['excel_file']);
            }
            if ($excelPath !== null) {
                $excelName = (string)($result['excel_file'] ?? basename($excelPath));
                self::edStreamDownload(
                    $excelPath,
                    $excelName !== '' ? $excelName : 'export.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                );
            }
            if ($fallbackPath !== null) {
                $ext = strtolower(pathinfo($fallbackPath, PATHINFO_EXTENSION));
                $contentType = 'application/octet-stream';
                if ($ext === 'zip') {
                    $contentType = 'application/zip';
                } elseif ($ext === 'xlsx') {
                    $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                } elseif ($ext === 'csv') {
                    $contentType = 'text/csv';
                }
                self::edStreamDownload($fallbackPath, basename($fallbackPath), $contentType);
            }
            if ($rows) {
                $downloadName = 'export_' . date('Ymd_His') . '.csv';
                self::edStreamCsvFromRows($rows, $downloadName);
            }
            if (!empty($result['columns']) && is_array($result['columns'])) {
                $downloadName = 'export_' . date('Ymd_His') . '.csv';
                self::edStreamCsvByColumns($result['columns'], $downloadName);
            }
            // Do not return JSON error here. Some environments may return file names
            // while the physical file is unavailable; keep compatibility by streaming CSV fallback.
            $fallbackColumns = [];
            if (!empty($config['export_columns']) && is_array($config['export_columns'])) {
                $fallbackColumns = $config['export_columns'];
            } elseif (!empty($config['search_fields']) && is_array($config['search_fields'])) {
                $fallbackColumns = $config['search_fields'];
            } elseif (!empty($config['table']) && is_string($config['table'])) {
                try {
                    $columnMap = self::dvTableColumns((string)$config['table']);
                    foreach (array_keys((array)$columnMap) as $field) {
                        $field = trim((string)$field);
                        if ($field !== '') {
                            $fallbackColumns[] = $field;
                        }
                    }
                } catch (\Throwable $e) {
                    $fallbackColumns = [];
                }
            }
            if (!$fallbackColumns) {
                $fallbackColumns = ['id'];
            }
            $downloadName = 'export_' . date('Ymd_His') . '.csv';
            self::edStreamCsvByColumns($fallbackColumns, $downloadName);
        } catch (\Throwable $e) {
            // Compatibility fallback: always return a file stream (CSV) even when xlsx/zip export fails.
            $fallbackColumns = [];
            if (!empty($config['export_columns']) && is_array($config['export_columns'])) {
                $fallbackColumns = $config['export_columns'];
            } elseif (!empty($config['search_fields']) && is_array($config['search_fields'])) {
                $fallbackColumns = $config['search_fields'];
            } elseif (!empty($config['table']) && is_string($config['table'])) {
                try {
                    $columnMap = self::dvTableColumns((string)$config['table']);
                    foreach (array_keys((array)$columnMap) as $field) {
                        $field = trim((string)$field);
                        if ($field !== '') {
                            $fallbackColumns[] = $field;
                        }
                    }
                } catch (\Throwable $ignore) {
                    $fallbackColumns = [];
                }
            }
            if (!$fallbackColumns) {
                $fallbackColumns = ['id'];
            }
            $downloadName = 'export_' . date('Ymd_His') . '.csv';
            self::edStreamCsvByColumns($fallbackColumns, $downloadName);
        }
    }

    private static function edExportByName(string $name): void
    {
        if (!isset(self::EXPORT_CONFIGS[$name])) {
            P_E(dcim_msg('error.export_config_missing'));
        }
        self::edExportByCrud(self::EXPORT_CONFIGS[$name]);
    }

    private static function edHasImportInput(array $data): bool
    {
        $upload = self::edResolveUploadFileMeta();
        if (!empty($upload['tmp'])) {
            return true;
        }
        foreach (['rows', 'file_path', 'path'] as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_array($value) && $value) {
                return true;
            }
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function edResolveUploadFileMeta(): array
    {
        $persistStreamToTempFile = static function ($stream, string $preferredName = ''): ?string {
            if (!is_object($stream)) {
                return null;
            }
            $content = null;
            if (method_exists($stream, '__toString')) {
                try {
                    $raw = (string)$stream;
                    if ($raw !== '') {
                        $content = $raw;
                    }
                } catch (\Throwable $ignore) {
                    $content = null;
                }
            }
            if ($content === null && method_exists($stream, 'getContents')) {
                try {
                    if (method_exists($stream, 'rewind')) {
                        $stream->rewind();
                    }
                    $raw = $stream->getContents();
                    if (is_string($raw) && $raw !== '') {
                        $content = $raw;
                    }
                } catch (\Throwable $ignore) {
                    $content = null;
                }
            }
            if (!is_string($content) || $content === '') {
                return null;
            }
            $ext = '';
            if ($preferredName !== '') {
                $ext = strtolower(pathinfo($preferredName, PATHINFO_EXTENSION));
            }
            $tmpFile = tempnam(sys_get_temp_dir(), 'dcim_up_');
            if (!is_string($tmpFile) || $tmpFile === '') {
                return null;
            }
            $target = $tmpFile;
            if ($ext !== '') {
                $target = $tmpFile . '.' . $ext;
            }
            if ($target !== $tmpFile) {
                @rename($tmpFile, $target);
            }
            if (@file_put_contents($target, $content) === false) {
                @unlink($target);
                return null;
            }
            return $target;
        };
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
                    if (method_exists($node, $method)) {
                        try {
                            $val = $node->{$method}();
                            if (is_string($val) && $val !== '' && (is_uploaded_file($val) || is_file($val))) {
                                $tmp = $val;
                                break;
                            }
                        } catch (\Throwable $e) {
                        }
                    }
                }
                if ($tmp === null && method_exists($node, 'getStream')) {
                    try {
                        $stream = $node->getStream();
                        if (is_object($stream)) {
                            $uri = null;
                            if (method_exists($stream, 'getMetadata')) {
                                try {
                                    $uri = $stream->getMetadata('uri');
                                } catch (\Throwable $ignore) {
                                    $uri = null;
                                }
                            }
                            if (!is_string($uri) || $uri === '') {
                                if (method_exists($stream, '__toString')) {
                                    try {
                                        $uri = (string)$stream;
                                    } catch (\Throwable $ignore) {
                                        $uri = '';
                                    }
                                } else {
                                    $uri = '';
                                }
                            }
                            if (is_string($uri) && $uri !== '' && is_file($uri)) {
                                $tmp = $uri;
                            } elseif ($tmp === null) {
                                $streamTmp = $persistStreamToTempFile($stream, $name);
                                if (is_string($streamTmp) && $streamTmp !== '' && is_file($streamTmp)) {
                                    $tmp = $streamTmp;
                                }
                            }
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

    private static function edResolveUploadTempPath(): ?string
    {
        $meta = self::edResolveUploadFileMeta();
        $tmp = $meta['tmp'] ?? null;
        return is_string($tmp) && $tmp !== '' ? $tmp : null;
    }

    private static function edResolveUploadFile(array $data = []): array
    {
        $meta = self::edResolveUploadFileMeta();
        $name = (string)($meta['name'] ?? '');
        $tmp = is_string($meta['tmp'] ?? null) ? $meta['tmp'] : self::edResolveUploadTempPath();
        if ($tmp !== null) {
            return ['tmp' => $tmp, 'name' => $name];
        }

        $candidates = [];
        if (isset($data['file']) && is_array($data['file'])) {
            if (isset($data['file']['tmp_name']) && is_string($data['file']['tmp_name'])) {
                $candidates[] = $data['file']['tmp_name'];
            }
            if (isset($data['file']['tmp']) && is_string($data['file']['tmp'])) {
                $candidates[] = $data['file']['tmp'];
            }
            if (isset($data['file']['path']) && is_string($data['file']['path'])) {
                $candidates[] = $data['file']['path'];
            }
            if ($name === '' && isset($data['file']['name']) && is_string($data['file']['name'])) {
                $name = $data['file']['name'];
            }
        } elseif (isset($data['file']) && is_object($data['file'])) {
            $fileObj = $data['file'];
            foreach (['getPathname', 'getRealPath'] as $method) {
                if (!method_exists($fileObj, $method)) {
                    continue;
                }
                try {
                    $tmpVal = $fileObj->{$method}();
                    if (is_string($tmpVal) && $tmpVal !== '') {
                        $candidates[] = $tmpVal;
                    }
                } catch (\Throwable $ignore) {
                }
            }
            if (method_exists($fileObj, 'getStream')) {
                try {
                    $stream = $fileObj->getStream();
                    if (is_object($stream) && method_exists($stream, 'getMetadata')) {
                        $streamUri = $stream->getMetadata('uri');
                        if (is_string($streamUri) && $streamUri !== '') {
                            $candidates[] = $streamUri;
                        }
                    }
                } catch (\Throwable $ignore) {
                }
            }
            if ($name === '') {
                foreach (['getClientFilename', 'getFilename', 'getBasename'] as $method) {
                    if (!method_exists($fileObj, $method)) {
                        continue;
                    }
                    try {
                        $nameVal = $fileObj->{$method}();
                        if (is_string($nameVal) && $nameVal !== '') {
                            $name = $nameVal;
                            break;
                        }
                    } catch (\Throwable $ignore) {
                    }
                }
            }
        }
        if (isset($data['file_path']) && is_string($data['file_path'])) {
            $candidates[] = $data['file_path'];
        }
        if (isset($data['path']) && is_string($data['path'])) {
            $candidates[] = $data['path'];
        }
        if (isset($data['tmp_name']) && is_string($data['tmp_name'])) {
            $candidates[] = $data['tmp_name'];
        }
        if (isset($data['tmp']) && is_string($data['tmp'])) {
            $candidates[] = $data['tmp'];
        }
        if (isset($data['file']) && is_string($data['file'])) {
            $candidates[] = $data['file'];
        }
        foreach ($candidates as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $resolvedPath = null;
            if (is_file($path) && is_readable($path)) {
                $resolvedPath = $path;
            } else {
                $pathCandidate = $path;
                if (preg_match('#^https?://#i', $pathCandidate)) {
                    $urlPath = (string)parse_url($pathCandidate, PHP_URL_PATH);
                    if ($urlPath !== '') {
                        $pathCandidate = $urlPath;
                    }
                }
                $publicResolved = self::edResolvePublicAbsolutePath($pathCandidate);
                if ($publicResolved !== null && is_readable($publicResolved)) {
                    $resolvedPath = $publicResolved;
                } elseif (strpos($pathCandidate, 'uploads/') !== false) {
                    $trimmed = ltrim(str_replace('\\', '/', $pathCandidate), '/');
                    $uploadPos = strpos($trimmed, 'uploads/');
                    if ($uploadPos !== false) {
                        $subPath = substr($trimmed, $uploadPos);
                        $fallback = self::edResolvePublicAbsolutePath('/' . $subPath);
                        if ($fallback !== null && is_readable($fallback)) {
                            $resolvedPath = $fallback;
                        }
                    }
                }
            }
            if ($resolvedPath !== null) {
                return [
                    'tmp' => $resolvedPath,
                    'name' => $name !== '' ? $name : basename($resolvedPath),
                ];
            }
        }
        return ['tmp' => null, 'name' => ''];
    }

    private static function edNormalizeProtocolNameToken(string $raw): string
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

    private static function edResolveProtocolImportName(array $data, string $originalName): string
    {
        foreach (['ProtocolName', 'protocolName'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                continue;
            }
            $resolved = self::edNormalizeProtocolNameToken($data[$key]);
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
            $name = self::edNormalizeProtocolNameToken((string)$candidate);
            if ($name === '') {
                continue;
            }
            $length = function_exists('mb_strlen') ? (int)mb_strlen($name, 'UTF-8') : strlen($name);
            $cjkCount = 0;
            if (preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $name, $matches) !== false) {
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

    private static function edFirstTmpFromFiles($files, string &$nameOut = ''): ?string
    {
        if (is_array($files)) {
            $tmpName = $files['tmp_name'] ?? null;
            $nameVal = $files['name'] ?? null;
            if (is_array($tmpName)) {
                foreach ($tmpName as $idx => $oneTmp) {
                    if (!is_string($oneTmp)) {
                        continue;
                    }
                    $oneTmp = trim($oneTmp);
                    if ($oneTmp === '' || (!is_uploaded_file($oneTmp) && !is_file($oneTmp))) {
                        continue;
                    }
                    if ($nameOut === '' && is_array($nameVal) && isset($nameVal[$idx]) && is_string($nameVal[$idx])) {
                        $nameOut = (string)$nameVal[$idx];
                    }
                    return $oneTmp;
                }
            } elseif (is_string($tmpName)) {
                $tmpName = trim($tmpName);
                if ($tmpName !== '' && (is_uploaded_file($tmpName) || is_file($tmpName))) {
                    if ($nameOut === '' && is_string($nameVal) && $nameVal !== '') {
                        $nameOut = $nameVal;
                    }
                    return $tmpName;
                }
            }
            foreach ($files as $child) {
                $tmp = self::edFirstTmpFromFiles($child, $nameOut);
                if (is_string($tmp) && $tmp !== '') {
                    return $tmp;
                }
            }
        } elseif (is_object($files)) {
            try {
                $arr = (array)$files;
                if ($arr) {
                    return self::edFirstTmpFromFiles($arr, $nameOut);
                }
            } catch (\Throwable $ignore) {
            }
        }
        return null;
    }

    private static function edNormalizeJsonRows($decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }
        if ($decoded === []) {
            return [];
        }
        $isSequential = array_keys($decoded) === range(0, count($decoded) - 1);
        if ($isSequential) {
            $rows = [];
            foreach ($decoded as $row) {
                if (is_object($row)) {
                    $row = (array) $row;
                }
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }
        return [$decoded];
    }

    private static function edImportByCrud(array $config): void
    {
        $data = Flight::request_data();
        self::edRequireAuth($data);
        unset($data['token']);

        if (!self::edHasImportInput($data)) {
            self::edOk(true);
            return;
        }

        $table = $config['table'] ?? ($data['table'] ?? '');
        if (!is_string($table) || trim($table) === '') {
            P_E(dcim_msg('error.import_table_config_missing'));
        }

        $isJsonInput = !empty($config['json_file']);
        if ($isJsonInput && !isset($data['rows'])) {
            $tmpPath = self::edResolveUploadTempPath();
            if ($tmpPath !== null) {
                $raw = @file_get_contents($tmpPath);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                $rows = self::edNormalizeJsonRows($decoded);
                if ($rows) {
                    $data['rows'] = $rows;
                    $data['header_row'] = 1;
                    $data['skip_rows'] = 0;
                }
            }
        }

        $importConfig = $config;
        unset($importConfig['json_file'], $importConfig['compat_noop_on_failure']);

        try {
            $crud = self::edCrud($table);
            $result = $crud->importMappedData($data, $importConfig);
            self::edOk($result);
        } catch (\Throwable $e) {
            if (!empty($config['compat_noop_on_failure'])) {
                self::edOk([
                    'compat_noop' => true,
                    'error' => str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')),
                ]);
                return;
            }
            P_E(str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')));
        }
    }

    private static function edImportResultFirstError(array $result): string
    {
        $errors = isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : [];
        if (!$errors) {
            return '';
        }
        $first = $errors[0] ?? null;
        if (!is_array($first)) {
            return '';
        }
        $msg = trim((string)($first['error'] ?? ''));
        return $msg;
    }

    private static function edRequireImportEffect(array $result, string $table): void
    {
        $inserted = isset($result['inserted']) ? (int)$result['inserted'] : 0;
        $updated = isset($result['updated']) ? (int)$result['updated'] : 0;
        $failed = isset($result['failed']) ? (int)$result['failed'] : 0;
        $affected = $inserted + $updated;
        if ($affected > 0) {
            return;
        }
        $firstError = self::edImportResultFirstError($result);
        if ($firstError !== '') {
            throw new RuntimeException($firstError);
        }
        if ($failed > 0) {
            throw new RuntimeException($table . ' import failed');
        }
        throw new RuntimeException($table . ' import produced no rows');
    }

    private static function edSnmpInjectDevId(array $row, string $requestDevId): array
    {
        $devId = self::edNormalizeIntegerOrEmpty($requestDevId);
        if ($devId === '') {
            $devId = self::edNormalizeIntegerOrEmpty(
                self::northPickImportCell($row, ['DevID', 'DevId', 'DeviceID', 'DeviceId'], 0)
            );
        }
        if ($devId !== '') {
            $row['DevID'] = $devId;
            $row['DevId'] = $devId;
        }
        if (!isset($row['status']) || trim((string)$row['status']) === '') {
            $row['status'] = 1;
        } else {
            $status = self::edNormalizeIntegerOrEmpty($row['status']);
            $row['status'] = ($status !== '') ? (int)$status : 1;
        }
        return $row;
    }

    private static function edLooksLikeSnmpAlarmRow(array $row): bool
    {
        foreach (array_keys($row) as $key) {
            if (!is_string($key)) {
                continue;
            }
            $lower = strtolower($key);
            if (strpos($lower, 'alarm name') !== false
                || strpos($lower, 'alarm level') !== false
                || strpos($lower, 'alarm cause') !== false
                || strpos($lower, 'alarm solution') !== false
            ) {
                return true;
            }
        }
        $c3 = trim((string)($row['__c3'] ?? ''));
        $c4 = trim((string)($row['__c4'] ?? ''));
        if (($c3 !== '' && strpos($c3, '.') !== false) || ($c4 !== '' && strpos($c4, '.') !== false)) {
            return true;
        }
        return false;
    }

    private static function edNormalizeIntegerOrEmpty($value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }
        $normalized = str_replace(["\xC2\xA0", ' ', "\t", "\r", "\n", ',', "\xEF\xBC\x8C"], '', $raw);
        if (preg_match('/^[+-]?\d+$/', $normalized)) {
            return (string)((int)$normalized);
        }
        if (preg_match('/^[+-]?\d+\.0+$/', $normalized)) {
            return (string)((int)$normalized);
        }
        return '';
    }
    private static function edNormalizeDecimalOrEmpty($value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }
        $normalized = str_replace(["\xC2\xA0", ' ', "\t", "\r", "\n", ',', "\xEF\xBC\x8C"], '', $raw);
        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)$/', $normalized)) {
            return (string)(0 + $normalized);
        }
        if (preg_match('/^([+-]?\d+)\/([+-]?\d+)$/', $normalized, $matches)) {
            $numerator = (float)$matches[1];
            $denominator = (float)$matches[2];
            if ($denominator != 0.0) {
                return (string)($numerator / $denominator);
            }
        }
        return '';
    }
    private static function edNormalizeAlarmLevel($value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }
        $asInt = self::edNormalizeIntegerOrEmpty($raw);
        if ($asInt !== '') {
            return $asInt;
        }

        $map = [
            "\u{63D0}\u{793A}" => '1',
            "\u{4E00}\u{822C}" => '1',
            "\u{666E}\u{901A}" => '1',
            "\u{6B21}\u{8981}" => '2',
            "\u{8F7B}\u{5FAE}" => '2',
            "\u{91CD}\u{8981}" => '3',
            "\u{4E3B}\u{8981}" => '3',
            "\u{4E25}\u{91CD}" => '4',
            "\u{5371}\u{6025}" => '4',
            "\u{7D27}\u{6025}" => '5',
            "\u{81F4}\u{547D}" => '5',
            "\u{4E00}\u{7EA7}" => '1',
            "\u{4E8C}\u{7EA7}" => '2',
            "\u{4E09}\u{7EA7}" => '3',
            "\u{56DB}\u{7EA7}" => '4',
            "\u{4E94}\u{7EA7}" => '5',
        ];
        if (isset($map[$raw])) {
            return $map[$raw];
        }

        $lower = function_exists('mb_strtolower') ? mb_strtolower($raw) : strtolower($raw);
        $enMap = [
            'notice' => '1',
            'tip' => '1',
            'minor' => '2',
            'major' => '3',
            'critical' => '4',
            'emergency' => '5',
            'fatal' => '5',
            'level1' => '1',
            'level2' => '2',
            'level3' => '3',
            'level4' => '4',
            'level5' => '5',
            'l1' => '1',
            'l2' => '2',
            'l3' => '3',
            'l4' => '4',
            'l5' => '5',
        ];
        if (isset($enMap[$lower])) {
            return $enMap[$lower];
        }
        return '';
    }

    private static function edDefaultSnmpUpsertKeys(string $table): array
    {
        if ($table === 'dcim-devicesnmpalarm') {
            return ['DevID', 'OnlyCode', 'AlarmID', 'AlarmAdd', 'AlarmCancel', 'AlarmKey', 'AlarmName'];
        }
        if ($table === 'dcim-alarmnotifymode') {
            return ['DevId', 'AlarmID', 'OnlyCode', 'AlarmKey', 'OId', 'OID'];
        }
        return ['DevID', 'DevId', 'AlarmKey', 'OID', 'OId'];
    }

    private static function edBuildSnmpImportRows(array $data): array
    {
        $rows = [];
        if (isset($data['rows'])) {
            $payload = $data['rows'];
            if (is_string($payload)) {
                $decoded = json_decode($payload, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $payload = $decoded;
                }
            }
            if (is_array($payload)) {
                foreach ($payload as $row) {
                    if (is_object($row)) {
                        $row = (array)$row;
                    }
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            }
        }
        if (!$rows) {
            $rows = self::northReadParamImportRows($data);
        }
        if (!$rows) {
            return [];
        }

        $requestDevId = trim((string)($data['DevId'] ?? ($data['DevID'] ?? ($data['DeviceId'] ?? ($data['DeviceID'] ?? '')))));
        $mappedRows = [];
        $rawRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = self::edSnmpInjectDevId($row, $requestDevId);
            $rawRows[] = $row;

            $isAlarmRow = self::edLooksLikeSnmpAlarmRow($row);
            $onlyCode = trim((string)self::northPickImportCell($row, [
                'OnlyCode',
                'DeviceOnlyCode',
                'DeviceCode',
                'UniqueCode',
            ], $isAlarmRow ? 0 : -1));
            $alarmId = trim((string)self::northPickImportCell($row, [
                'AlarmID',
                'AlarmId',
                'Alarm Id',
                'EventID',
                'EventId',
            ], $isAlarmRow ? 2 : -1));
            $alarmAdd = trim((string)self::northPickImportCell($row, [
                'AlarmAdd',
                'RaiseOID',
                'RaiseOId',
                'ProduceOID',
                'ProduceOId',
                'AlarmRaiseOID',
            ], $isAlarmRow ? 3 : -1));
            $alarmCancel = trim((string)self::northPickImportCell($row, [
                'AlarmCancel',
                'ClearOID',
                'ClearOId',
                'RecoverOID',
                'RecoverOId',
                'AlarmCancelOID',
            ], $isAlarmRow ? 4 : -1));
            $alarmCause = trim((string)self::northPickImportCell($row, [
                'AlarmCause',
                'Alarm Cause',
                'Cause',
            ], $isAlarmRow ? 6 : -1));
            $alarmSolution = trim((string)self::northPickImportCell($row, [
                'AlarmSolution',
                'Alarm Solution',
                'Solution',
            ], $isAlarmRow ? 7 : -1));

            $one = [];
            $one['DevID'] = self::edNormalizeIntegerOrEmpty($row['DevID'] ?? ($row['DevId'] ?? ''));
            $one['DevId'] = $one['DevID'];
            $one['OnlyCode'] = $onlyCode;
            $one['AlarmKey'] = trim((string)self::northPickImportCell($row, [
                'AlarmKey',
                'AlarmID',
                'AlarmId',
                'ParamKey',
                'PointName',
                'ParamName',
                'Name',
                'OIDName',
                'Description',
                'Desc',
            ], $isAlarmRow ? 2 : 1));
            $one['AlarmName'] = trim((string)self::northPickImportCell($row, [
                'AlarmName',
                'Alarm Name',
                'Name',
                'OIDName',
                'ParamName',
                'Description',
                'Desc',
            ], $isAlarmRow ? 1 : -1));
            $oidVal = trim((string)self::northPickImportCell($row, [
                'OId',
                'OID',
                'Oid',
                'oid',
            ], $isAlarmRow ? -1 : 2));
            if ($oidVal === '' && $alarmAdd !== '') {
                $oidVal = $alarmAdd;
            }
            $one['OId'] = $oidVal;
            $one['OID'] = $oidVal;

            $isWideScalarLayout = !$isAlarmRow && (
                array_key_exists('__c8', $row)
                || array_key_exists('__c9', $row)
                || array_key_exists('__c10', $row)
                || array_key_exists('__c11', $row)
            );
            $dataTypeFallback = $isAlarmRow ? -1 : ($isWideScalarLayout ? 8 : 3);
            $dataListFallback = $isAlarmRow ? -1 : ($isWideScalarLayout ? 9 : 4);
            $unitFallback = $isAlarmRow ? -1 : ($isWideScalarLayout ? 10 : 5);
            $rateFallback = $isAlarmRow ? -1 : ($isWideScalarLayout ? 11 : 6);

            $one['DataType'] = trim((string)self::northPickImportCell($row, ['DataType', 'DataKind', 'DataClass'], $dataTypeFallback));
            $one['CommandType'] = trim((string)self::northPickImportCell($row, ['CommandType', 'CmdType'], -1));
            $one['AlarmType'] = self::edNormalizeIntegerOrEmpty(self::northPickImportCell($row, ['AlarmType'], -1));
            $one['AlarmLevel'] = self::edNormalizeAlarmLevel(self::northPickImportCell($row, [
                'AlarmLevel',
                'Alarm Level',
                'Level',
            ], $isAlarmRow ? 5 : -1));
            $one['AlarmID'] = $alarmId;
            $one['AlarmAdd'] = $alarmAdd;
            $one['AlarmCancel'] = $alarmCancel;
            $one['AlarmCause'] = $alarmCause;
            $one['AlarmSolution'] = $alarmSolution;
            $one['AlarmUpLimit'] = trim((string)self::northPickImportCell($row, ['AlarmUpLimit', 'UpperLimit'], -1));
            $one['AlarmDownLimit'] = trim((string)self::northPickImportCell($row, ['AlarmDownLimit', 'LowerLimit'], -1));
            $one['AlarmValue'] = trim((string)self::northPickImportCell($row, ['AlarmValue', 'Threshold'], -1));
            $one['OIDName'] = trim((string)self::northPickImportCell($row, [
                'OIDName',
                'OidName',
                'Name',
                'ParamName',
                'PointName',
                'DataName',
            ], 1));
            $one['DataList'] = trim((string)self::northPickImportCell($row, [
                'DataList',
                'EnumScript',
                'EnumList',
                'EnumValue',
                'EnumValues',
                'Enum',
            ], $dataListFallback));
            $one['Unit'] = trim((string)self::northPickImportCell($row, [
                'Unit',
                'UnitName',
            ], $unitFallback));
            $one['Rate'] = trim((string)self::northPickImportCell($row, [
                'Rate',
                'RateBit',
                'Ratio',
                'RatioBit',
                'ScaleRate',
            ], $rateFallback));
            $one['Rate'] = self::edNormalizeDecimalOrEmpty($one['Rate']);
            $status = self::edNormalizeIntegerOrEmpty(isset($row['status']) ? $row['status'] : 1);
            $one['status'] = ($status !== '') ? (int)$status : 1;

            if ($one['AlarmKey'] === '' && $alarmId !== '') {
                $one['AlarmKey'] = $alarmId;
            }
            if ($one['AlarmKey'] === '' && $oidVal !== '') {
                $one['AlarmKey'] = $oidVal;
            }
            if ($one['AlarmName'] === '') {
                $one['AlarmName'] = $one['AlarmKey'];
            }
            if ($one['OIDName'] === '') {
                $one['OIDName'] = $one['AlarmName'];
            }
            if ($one['DevID'] === '') {
                continue;
            }
            if ($one['AlarmKey'] === '' && $oidVal === '') {
                continue;
            }
            $mappedRows[] = $one;
        }

        return $mappedRows ? $mappedRows : $rawRows;
    }
    private static function edImportSnmpRowsToTable(string $table, array $rows, array $sourceData): array
    {
        $payload = $sourceData;
        unset($payload['token']);
        if (!isset($payload['upsert_keys'])) {
            $payload['upsert_keys'] = self::edDefaultSnmpUpsertKeys($table);
        }
        $upsertKeys = is_array($payload['upsert_keys']) ? $payload['upsert_keys'] : [];
        $pickFieldInsensitive = static function (array $row, string $field) {
            if (array_key_exists($field, $row)) {
                return $row[$field];
            }
            foreach ($row as $k => $v) {
                if (is_string($k) && strcasecmp($k, $field) === 0) {
                    return $v;
                }
            }
            return null;
        };
        $normalizeKeyValue = static function ($raw): string {
            return trim((string)$raw);
        };
        if ($upsertKeys && $rows) {
            $dedupMap = [];
            $dedupOrder = [];
            $fallbackRows = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $parts = [];
                $complete = true;
                foreach ($upsertKeys as $keyFieldRaw) {
                    $keyField = trim((string)$keyFieldRaw);
                    if ($keyField === '') {
                        $complete = false;
                        break;
                    }
                    $val = $pickFieldInsensitive($row, $keyField);
                    $norm = $normalizeKeyValue($val);
                    if ($norm === '') {
                        $complete = false;
                        break;
                    }
                    $parts[] = strtolower($norm);
                }
                if (!$complete || !$parts) {
                    $fallbackRows[] = $row;
                    continue;
                }
                $dedupKey = implode('|', $parts);
                if (!isset($dedupMap[$dedupKey])) {
                    $dedupOrder[] = $dedupKey;
                }
                // Keep latest row for same upsert key.
                $dedupMap[$dedupKey] = $row;
            }
            $dedupRows = [];
            foreach ($dedupOrder as $k) {
                if (isset($dedupMap[$k])) {
                    $dedupRows[] = $dedupMap[$k];
                }
            }
            if ($fallbackRows) {
                $dedupRows = array_merge($dedupRows, $fallbackRows);
            }
            if ($dedupRows) {
                $rows = $dedupRows;
            }
        }
        $payload['rows'] = $rows;
        $payload['header_row'] = 1;
        $payload['skip_rows'] = 0;
        return self::edCrud($table)->importMappedData($payload, [
            'table' => $table,
            'strict_upsert_match' => true,
        ]);
    }

    private static function edBuildSnmpNotifyRows(array $rows, string $defaultAlarmType = '1'): array
    {
        $defaultAlarmType = self::edNormalizeIntegerOrEmpty($defaultAlarmType);
        if ($defaultAlarmType === '') {
            $defaultAlarmType = '1';
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $devId = self::edNormalizeIntegerOrEmpty($row['DevId'] ?? ($row['DevID'] ?? ($row['DeviceId'] ?? ($row['DeviceID'] ?? ''))));
            $onlyCode = trim((string)($row['OnlyCode'] ?? ''));
            $alarmId = trim((string)($row['AlarmID'] ?? ($row['AlarmId'] ?? '')));
            $alarmAdd = trim((string)($row['AlarmAdd'] ?? ''));
            $oid = trim((string)($row['OID'] ?? ($row['OId'] ?? ($row['Oid'] ?? ($row['oid'] ?? '')))));
            if ($oid === '' && $alarmAdd !== '') {
                $oid = $alarmAdd;
            }
            $alarmKey = trim((string)($row['AlarmKey'] ?? ($row['ParamKey'] ?? $alarmId)));
            if ($alarmKey === '') {
                $alarmKey = $oid;
            }
            $alarmName = trim((string)($row['AlarmName'] ?? ''));
            if ($alarmName === '') {
                $alarmName = $alarmKey;
            }
            if ($devId === '' || $alarmKey === '') {
                continue;
            }
            $alarmTypeRaw = $row['AlarmType'] ?? '';
            $alarmType = self::edNormalizeIntegerOrEmpty($alarmTypeRaw);
            if ($alarmType === '') {
                $alarmType = $defaultAlarmType;
            }
            $alarmLevelRaw = $row['AlarmLevel'] ?? '';
            $alarmLevel = self::edNormalizeAlarmLevel($alarmLevelRaw);
            if ($alarmLevel === '') {
                $alarmLevel = '1';
            }
            $confirmNum = self::edNormalizeIntegerOrEmpty($row['ConfirmNum'] ?? '');
            if ($confirmNum === '') {
                $confirmNum = '1';
            }
            $one = [
                'DevId' => $devId,
                'DevID' => $devId,
                'AlarmKey' => $alarmKey,
                'AlarmName' => $alarmName,
                'AlarmID' => $alarmId,
                'OnlyCode' => $onlyCode,
                'OID' => $oid,
                'OId' => $oid,
                'AlarmType' => $alarmType,
                'AlarmLevel' => $alarmLevel,
                'ConfirmNum' => (int)$confirmNum,
                'AlarmUpLimit' => (string)($row['AlarmUpLimit'] ?? ''),
                'AlarmDownLimit' => (string)($row['AlarmDownLimit'] ?? ''),
                'AlarmValue' => (string)($row['AlarmValue'] ?? ''),
                'CommandType' => (string)($row['CommandType'] ?? ''),
                'DataType' => (string)($row['DataType'] ?? ''),
                'snmpSource' => 1,
                'AlertPeriod' => '',
                'TogetherAlarm' => '',
                'status' => 1,
            ];
            $out[] = $one;
        }
        return $out;
    }

    private static function edSyncSnmpToAlarmNotifyMode(array $rows, array $sourceData): array
    {
        $defaultAlarmType = self::edNormalizeIntegerOrEmpty($sourceData['notify_default_alarm_type'] ?? '');
        if ($defaultAlarmType === '') {
            $defaultAlarmType = '1';
        }
        $notifyRows = self::edBuildSnmpNotifyRows($rows, $defaultAlarmType);
        if (!$notifyRows) {
            return [
                'table' => 'dcim-alarmnotifymode',
                'input_mode' => 'rows',
                'file_path' => null,
                'processed' => 0,
                'inserted' => 0,
                'updated' => 0,
                'failed' => 0,
                'skipped' => 0,
                'errors' => [],
            ];
        }
        $payload = $sourceData;
        $payload['upsert_keys'] = self::edDefaultSnmpUpsertKeys('dcim-alarmnotifymode');
        return self::edImportSnmpRowsToTable('dcim-alarmnotifymode', $notifyRows, $payload);
    }

    public static function ExportAssetAll() { self::edExportByName('asset_all'); }
    public static function ExportHistoryAlarms() { self::edExportByName('history_alarms'); }
    public static function ExportAlarmParam() { self::edExportByName('alarm_param'); }
    public static function ExportAlarmNotify() { self::edExportByName('alarm_notify'); }
    public static function ExportAlarmParamDetail() { self::edExportByName('alarm_param_detail'); }
    public static function ExportYWWorkOrder() { self::edExportByName('yw_workorder'); }
    public static function ExportXJTaskList() { self::edExportByName('xj_task'); }
    public static function ExportWHTaskList() { self::edExportByName('wh_task'); }
    public static function ExportDeviceCommandSend() { self::edExportByName('device_command_send'); }
    public static function ExportDoorCommandSend() { self::edExportByName('door_command_send'); }
    public static function ExportOperationRecord() { self::edExportByName('operation_record'); }

    public static function ImportSnmp()
    {
        $data = Flight::request_data();
        self::edRequireAuth($data);
        unset($data['token']);
        if (!self::edHasImportInput($data)) {
            P_E(dcim_msg('error.failed_read_file'));
        }
        try {
            $rows = self::edBuildSnmpImportRows($data);
            if (!$rows) {
                throw new RuntimeException(dcim_msg('error.failed_read_file'));
            }
            $result = self::edImportSnmpRowsToTable('dcim-devicesnmp', $rows, $data);
            self::edRequireImportEffect($result, 'dcim-devicesnmp');

            $alarmResult = self::edImportSnmpRowsToTable('dcim-devicesnmpalarm', $rows, $data);
            self::edRequireImportEffect($alarmResult, 'dcim-devicesnmpalarm');

            $notifyResult = self::edSyncSnmpToAlarmNotifyMode($rows, $data);
            self::edRequireImportEffect($notifyResult, 'dcim-alarmnotifymode');

            $result['alarm_sync'] = $alarmResult;
            $result['notify_sync'] = $notifyResult;
            self::edOk($result);
        } catch (\Throwable $e) {
            P_E(str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')));
        }
    }

    public static function ImportSnmpAlarm()
    {
        $data = Flight::request_data();
        self::edRequireAuth($data);
        unset($data['token']);
        if (!self::edHasImportInput($data)) {
            P_E(dcim_msg('error.failed_read_file'));
        }
        try {
            $rows = self::edBuildSnmpImportRows($data);
            if (!$rows) {
                throw new RuntimeException(dcim_msg('error.failed_read_file'));
            }
            $result = self::edImportSnmpRowsToTable('dcim-devicesnmpalarm', $rows, $data);
            self::edRequireImportEffect($result, 'dcim-devicesnmpalarm');

            // ImportSnmpAlarmKey: default AlarmType for generated dcim-alarmnotifymode rows.
            $data['notify_default_alarm_type'] = '6';
            $notifyResult = self::edSyncSnmpToAlarmNotifyMode($rows, $data);
            self::edRequireImportEffect($notifyResult, 'dcim-alarmnotifymode');

            $result['notify_sync'] = $notifyResult;
            self::edOk($result);
        } catch (\Throwable $e) {
            P_E(str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')));
        }
    }

    public static function ImportPerson()
    {
        $data = Flight::request_data();
        self::edRequireAuth($data);
        unset($data['token']);

        if (!self::edHasImportInput($data)) {
            O_E(dcim_msg('app.import_ok_data'), dcim_msg('app.import_ok_msg'), 100, 0);
            return;
        }

        $uploadFile = self::edResolveUploadFile($data);
        $tmpPath = is_string($uploadFile['tmp'] ?? null) ? $uploadFile['tmp'] : null;
        if ($tmpPath === null && isset($_FILES) && is_array($_FILES) && !empty($_FILES)) {
            $tmpPath = self::edFirstTmpFromFiles($_FILES);
        }
        if ($tmpPath === null) {
            try {
                $req = Flight::request();
                $reqFiles = is_object($req) && isset($req->files) ? $req->files : null;
                if ($reqFiles !== null) {
                    $tmpPath = self::edFirstTmpFromFiles($reqFiles);
                }
            } catch (\Throwable $ignore) {
            }
        }
        if ($tmpPath === null && (!empty($_FILES) || isset($data['file']) || isset($data['file_path']))) {
            P_E(dcim_msg('error.failed_read_file'));
        }
        if ($tmpPath !== null && !isset($data['file_path'])) {
            $data['file_path'] = $tmpPath;
        }

        try {
            self::edCrud('dcim-person')->importMappedData($data, [
                'table' => 'dcim-person',
            ]);
            O_E(dcim_msg('app.import_ok_data'), dcim_msg('app.import_ok_msg'), 100, 0);
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (strpos($msg, 'read file') !== false || strpos($msg, 'uploaded file') !== false) {
                P_E(dcim_msg('error.failed_read_file'));
            }
            P_E(str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')));
        }
    }

    /**
     * Normalize open-ended range process types (e.g. 1<19->) for parsers that
     * require an explicit end index.
     */
    private static function edNormalizeProcessTypeExpression(string $processType): string
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

    public static function ImportProtocol()
    {
        error_log('[ImportDevicePtlKey] start');
        $data = Flight::request_data();
        self::edRequireAuth($data);
        $uploadFile = self::edResolveUploadFile($data);
        $tmpPath = is_string($uploadFile['tmp'] ?? null) ? $uploadFile['tmp'] : '';
        $originalName = (string)($uploadFile['name'] ?? '');
        if ($tmpPath === '' && isset($_FILES) && is_array($_FILES) && !empty($_FILES)) {
            $tmpFound = self::edFirstTmpFromFiles($_FILES, $originalName);
            if (is_string($tmpFound) && $tmpFound !== '') {
                $tmpPath = $tmpFound;
            }
        }
        if ($tmpPath === '') {
            try {
                $req = Flight::request();
                $reqFiles = is_object($req) && isset($req->files) ? $req->files : null;
                if ($reqFiles !== null) {
                    $tmpFound = self::edFirstTmpFromFiles($reqFiles, $originalName);
                    if (is_string($tmpFound) && $tmpFound !== '') {
                        $tmpPath = $tmpFound;
                    }
                }
            } catch (\Throwable $ignore) {
            }
        }
        if ($tmpPath === '') {
            error_log('[ImportDevicePtlKey] no file uploaded');
            P_E(dcim_msg('error.failed_read_file'));
        }
        if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
            error_log('[ImportDevicePtlKey] tmp file not readable');
            P_E(dcim_msg('error.failed_read_file'));
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $readerTypes = [];
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
            error_log('[ImportDevicePtlKey] read failed: ' . $lastReadError);
            $reason = trim((string)$lastReadError);
            if ($reason === '') {
                $reason = dcim_msg('error.failed_read_file');
            }
            P_E(str_replace('{reason}', $reason, dcim_msg('error.import_failed_with_reason')));
        }

        $sheetContent = $spreadsheet->getSheet(0)->toArray(null, false, false, false);
        error_log('[ImportDevicePtlKey] rows=' . count($sheetContent) . ' file=' . $originalName);
        if (!$sheetContent || count($sheetContent) < 2) {
            P_E(dcim_msg('error.sheet_content_empty'));
        }

        $protocolName = self::edResolveProtocolImportName($data, $originalName);
        $dataRows = count($sheetContent);
        $protocolCode = '';
        $protocolType = '';
        $commanddesc = '';
        $param = [];
        $protocolJson = [];
        $headerRow = isset($sheetContent[0]) && is_array($sheetContent[0]) ? $sheetContent[0] : [];
        $normalizeHeader = static function ($value): string {
            $value = is_string($value) ? trim($value) : '';
            if ($value === '') {
                return '';
            }
            $value = str_replace(["\xC2\xA0", ' '], '', $value);
            return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        };
        $headerIndex = [];
        foreach ($headerRow as $idx => $title) {
            if (!is_int($idx) || !is_string($title)) {
                continue;
            }
            $normalized = $normalizeHeader($title);
            if ($normalized !== '' && !array_key_exists($normalized, $headerIndex)) {
                $headerIndex[$normalized] = $idx;
            }
        }
        $findHeaderIndex = static function (array $names) use ($headerIndex, $normalizeHeader): ?int {
            foreach ($names as $name) {
                if (!is_string($name)) {
                    continue;
                }
                $normalized = $normalizeHeader($name);
                if ($normalized !== '' && array_key_exists($normalized, $headerIndex)) {
                    return (int)$headerIndex[$normalized];
                }
            }
            return null;
        };
        $readCell = static function (array $row, ?int $index, $fallback = '') {
            if ($index !== null && array_key_exists($index, $row)) {
                return $row[$index];
            }
            return $fallback;
        };
        $alarmUserGroupCol = $findHeaderIndex([
            dcim_msg('import.protocol_header_alarm_user_group_1'),
            dcim_msg('import.protocol_header_alarm_user_group_2'),
        ]);
        $alarmUpgradeGroupCol = $findHeaderIndex([
            dcim_msg('import.protocol_header_alarm_upgrade_group_1'),
            dcim_msg('import.protocol_header_alarm_upgrade_group_2'),
        ]);
        $alarmLevelCol = $findHeaderIndex([
            dcim_msg('import.protocol_header_alarm_level_1'),
            dcim_msg('import.protocol_header_alarm_level_2'),
        ]);
        $faultToleranceCol = $findHeaderIndex([
            dcim_msg('import.protocol_header_fault_tolerance'),
            dcim_msg('import.protocol_header_alarm_period_1'),
            dcim_msg('import.protocol_header_alarm_period_2'),
        ]);
        if ($alarmUserGroupCol === null || $alarmUpgradeGroupCol === null || $alarmLevelCol === null || $faultToleranceCol === null) {
            P_E(dcim_msg('error.protocol_import_template_upgraded'));
        }

        $type = '';
        $name = '';
        $command = '';
        $deal = '';
        $paramone = '';
        $deldata = null;

        for ($i = 1; $i < $dataRows; $i++) {
            $row = $sheetContent[$i];
            if ($i === 1) {
                $protocolType = $row[0] ?? '';
                if ($protocolType === '') {
                    P_E(dcim_msg('error.protocol_type_required'));
                }
                error_log('[ImportDevicePtlKey] protocolType=' . $protocolType);
                $deldata = self::edCrud('dcim-deviceprotocol')->findOne([
                    ['ProtocolName', '=', $protocolName],
                    ['ProtocolType', '=', $protocolType],
                    ['status', '=', 1],
                ]);
                if ($deldata) {
                    $protocolCode = $deldata['ProtocolCode'];
                    error_log('[ImportDevicePtlKey] reuse ProtocolCode=' . $protocolCode);
                } else {
                    $catnum = self::edCrud('dcim-deviceprotocol')->countByRawCondition(
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
                    error_log('[ImportDevicePtlKey] new ProtocolCode=' . $protocolCode);
                }
            }

            if ($type === '') {
                $type = $row[1] ?? '';
                $name = $row[2] ?? '';
                $command = $row[3] ?? '';
                $deal = self::edNormalizeProcessTypeExpression((string)($row[4] ?? ''));
                $containsSms = function_exists('mb_strpos') ? mb_strpos($protocolName, 'SMS') !== false : strpos($protocolName, 'SMS') !== false;
                $containsDoor = function_exists('mb_strpos') ? mb_strpos($protocolName, 'DOOR') !== false : strpos($protocolName, 'DOOR') !== false;
                if (!$containsSms && !$containsDoor) {
                    if ($type === '' || $name === '' || $command === '' || $deal === '') {
                        P_E(str_replace('{row}', (string)$i, dcim_msg('error.protocol_row_command_fields_required')));
                    }
                } else {
                    break;
                }
                $commanddesc .= $type . ':' . $name . ':' . $command . '|';
                $paramone = '';
            }

            $num = $row[5] ?? '';
            $namekey = $row[6] ?? '';
            $rate = $row[7] ?? '';
            $unit = $row[8] ?? '';
            $datalen = $row[9] ?? '';
            $model = $row[10] ?? '';
            $dataorder = $row[11] ?? '';
            $alarmnum = $row[12] ?? '';
            $alarmup = $row[13] ?? '';
            $alarmdown = $row[14] ?? '';
            $dataoffset = $row[15] !== '' && $row[15] !== null ? $row[15] : 0;
            $datafixed = $row[16] !== '' && $row[16] !== null ? $row[16] : 0;
            $datatype = $row[17] ?? '';
            $alarmUserGroup = (string)$readCell($row, $alarmUserGroupCol, '');
            $alarmUpgradeGroup = (string)$readCell($row, $alarmUpgradeGroupCol, '');
            $alarmLevel = $readCell($row, $alarmLevelCol, '');
            $alertPeriod = $readCell($row, $faultToleranceCol, '');

            if ($num === '' || $namekey === '') {
                P_E(str_replace('{row}', (string)$i, dcim_msg('error.protocol_row_sequence_param_required')));
            }

            if ($type !== (string)($row[1] ?? '')) {
                $paramone = $type . '&' . $deal . '&' . $paramone;
                $param[] = $paramone;
                $paramone = '';
                $type = $row[1] ?? '';
                $name = $row[2] ?? '';
                $command = $row[3] ?? '';
                $deal = self::edNormalizeProcessTypeExpression((string)($row[4] ?? ''));
                if ($type === '' || $name === '' || $command === '' || $deal === '') {
                    P_E(str_replace('{row}', (string)$i, dcim_msg('error.protocol_row_command_fields_required')));
                }
                $commanddesc .= $type . ':' . $name . ':' . $command . '|';
            }

            $paramoneJsonOneStr = '';
            if ($deal === '1' || $deal === '6' || $deal === '7' || $deal === '9' || $deal === '10' || $deal === '11' || $deal === '13'
                || strpos($deal, '1<') !== false || strpos($deal, '6<') !== false || strpos($deal, '7<') !== false || strpos($deal, '9<') !== false
                || strpos($deal, '10<') !== false || strpos($deal, '11<') !== false || strpos($deal, '13<') !== false) {
                $paramone .= $num . ',' . $namekey . ',' . $rate . ',' . $unit . ',' . $datalen . ',' . $model;
                $paramoneJsonOneStr = $num . ',' . $namekey . ',' . $rate . ',' . $unit . ',' . $datalen . ',' . $model;
            } elseif ($deal === '2' || $deal === '4' || strpos($deal, '2<') !== false || strpos($deal, '4<') !== false) {
                $paramone .= $num . ',' . $namekey . ',' . $unit;
                $paramoneJsonOneStr = $num . ',' . $namekey . ',' . $unit;
            } elseif ($deal === '3' || $deal === '5' || $deal === '8' || $deal === '12' || $deal === '14'
                || strpos($deal, '3<') !== false || strpos($deal, '5<') !== false || strpos($deal, '8<') !== false
                || strpos($deal, '12<') !== false || strpos($deal, '14<') !== false) {
                $paramone .= $num . ',' . $namekey . ',' . $datalen . ',' . $dataorder . ',' . $unit;
                $paramoneJsonOneStr = $num . ',' . $namekey . ',' . $datalen . ',' . $dataorder . ',' . $unit;
            }

            $alarmUserGroupToken = 'UG:' . str_replace(',', ';', (string)$alarmUserGroup);
            $alarmUpgradeGroupToken = 'UPG:' . str_replace(',', ';', (string)$alarmUpgradeGroup);
            $paramone .= ',' . $alarmnum . ',' . $alarmup . ',' . $alarmdown . ',' . $dataoffset . ',' . $datafixed . ',' . $datatype . ',' . $alarmUserGroupToken . ',' . $alarmUpgradeGroupToken . ',' . $alarmLevel . ',' . $alertPeriod . ':';
            $paramoneJsonOneStr .= ',' . $alarmnum . ',' . $alarmup . ',' . $alarmdown . ',' . $dataoffset . ',' . $datafixed . ',' . $datatype . ',' . $alarmUserGroupToken . ',' . $alarmUpgradeGroupToken . ',' . $alarmLevel . ',' . $alertPeriod . ':';
            $protocolJson[] = self::edSetProtocolJson($type, $deal, $paramoneJsonOneStr, $protocolCode);

            if ($dataRows === ($i + 1)) {
                $paramone = $type . '&' . $deal . '&' . $paramone;
                $param[] = $paramone;
            }
        }

        $protocolData = join('|', $param);
        $protocolJsonStr = json_encode($protocolJson, JSON_UNESCAPED_UNICODE);

        if ($deldata) {
            $resData = self::edCrud('dcim-deviceprotocol')->legacyUpdateWhere([
                ['ProtocolName', '=', $protocolName],
                ['ProtocolType', '=', $protocolType],
                ['status', '=', 1],
            ], [
                'ProtocolValue' => $commanddesc,
                'ProtocolData' => $protocolData,
                'ProtocolJson' => $protocolJsonStr,
                'ProtocolType' => $protocolType,
            ]);
            error_log('[ImportDevicePtlKey] update protocol rows=' . ($resData ? '1' : '0'));

            $devices = self::edCrud('dcim-device')->selectByRawCondition(
                'ProtocolCode = :code AND status = 1',
                '',
                [':code' => $protocolCode]
            );
            error_log('[ImportDevicePtlKey] affected devices=' . count($devices));
            $alarmCrud = self::edCrud('dcim-alarmnotifymode');
            foreach ($devices as $device) {
                $alarmCrud->legacyDeleteByRawCondition(
                    'DevId = :id AND status <> -1',
                    [':id' => $device['id']]
                );

                $insertnotify = self::edSetAlarmnotify($protocolData, $device['id']);
                if ($insertnotify) {
                    foreach ($insertnotify as $row) {
                        $alarmCrud->legacyInsert($row);
                    }
                }
                self::edDeviceBroken($device['id']);
            }
        } else {
            $resData = self::edCrud('dcim-deviceprotocol')->legacyInsert([
                'ProtocolName' => $protocolName,
                'ProtocolCode' => $protocolCode,
                'ProtocolValue' => $commanddesc,
                'ProtocolData' => $protocolData,
                'ProtocolJson' => $protocolJsonStr,
                'ProtocolType' => $protocolType,
                'AlarmType' => '5',
            ]) !== false;
            error_log('[ImportDevicePtlKey] insert protocol rows=' . ($resData ? '1' : '0'));
        }

        if ($resData === false) {
            error_log('[ImportDevicePtlKey] result=false');
            P_E(dcim_msg('error.import_failed'));
        }
        error_log('[ImportDevicePtlKey] success');
        O_E(true, dcim_msg('error.import_success'), 100, false);
    }

    private static function edSetProtocolJson($comType, $dealType, $paramStr, $protocolCode): array
    {
        $dealNum = 3;
        switch ($dealType) {
            case '2':
            case '4':
                $dealNum = 2;
                break;
            case '3':
            case '5':
            case '8':
                $dealNum = 4;
                break;
            default:
                $dealNum = 3;
                break;
        }

        $paramOneArr = explode(',', substr($paramStr, 0, -1));
        $descArr = [];
        $tail = self::edExtractProtocolTail($paramOneArr);
        $datatype = $tail['dataType'] ?? '';
        if (!empty($paramOneArr[$dealNum]) && strpos($paramOneArr[$dealNum], '/') !== false) {
            $descArr = explode('/', $paramOneArr[$dealNum]);
        }

        return [
            'ProtocolCode' => $protocolCode,
            'comType' => $comType,
            'keyName' => $paramOneArr[1] ?? '',
            'keyDesc' => $descArr,
            'dataType' => $datatype,
        ];
    }

    private static function edExtractProtocolTail(array $paramOneArr): array
    {
        $count = count($paramOneArr);
        $tail = [
            'alarmnum' => '',
            'alarmup' => '',
            'alarmdown' => '',
            'dataType' => '',
            'alarmUserGroup' => '',
            'alarmUpgradeGroup' => '',
            'alarmLevel' => '',
            'alertPeriod' => '',
        ];
        if ($count === 0) {
            return $tail;
        }

        $tail['alarmLevel'] = (string)($paramOneArr[$count - 2] ?? '');
        $tail['alertPeriod'] = (string)($paramOneArr[$count - 1] ?? '');

        $dataTypeIndex = $count - 3;
        if ($count >= 5) {
            $ugToken = (string)($paramOneArr[$count - 4] ?? '');
            $upgToken = (string)($paramOneArr[$count - 3] ?? '');
            $hasUg = strpos($ugToken, 'UG:') === 0;
            $hasUpg = strpos($upgToken, 'UPG:') === 0;
            if ($hasUg || $hasUpg) {
                $dataTypeIndex = $count - 5;
                if ($hasUg) {
                    $tail['alarmUserGroup'] = str_replace(';', ',', substr($ugToken, 3));
                }
                if ($hasUpg) {
                    $tail['alarmUpgradeGroup'] = str_replace(';', ',', substr($upgToken, 4));
                }
            }
        }
        if ($dataTypeIndex >= 0 && $dataTypeIndex < $count) {
            $tail['dataType'] = (string)($paramOneArr[$dataTypeIndex] ?? '');
        }

        $alarmBaseIndex = $dataTypeIndex - 5;
        if ($alarmBaseIndex >= 0 && ($alarmBaseIndex + 2) < $count) {
            $tail['alarmnum'] = (string)($paramOneArr[$alarmBaseIndex] ?? '');
            $tail['alarmup'] = (string)($paramOneArr[$alarmBaseIndex + 1] ?? '');
            $tail['alarmdown'] = (string)($paramOneArr[$alarmBaseIndex + 2] ?? '');
        }

        return $tail;
    }

    private static function edSetAlarmnotify(string $protocolData, $devId): array
    {
        $insertnotify = [];
        if (strpos($protocolData, '|') !== false) {
            $arr = explode('|', $protocolData);
            foreach ($arr as $value) {
                $insertnotify = array_merge($insertnotify, self::edSetAlarmnotifyOne($value, $devId));
            }
        } else {
            $insertnotify = self::edSetAlarmnotifyOne($protocolData, $devId);
        }
        return $insertnotify;
    }

    private static function edSetAlarmnotifyOne(string $protocolData, $devId): array
    {
        $insertnotify = [];
        if (strpos($protocolData, ':') !== false) {
            $type = explode('&', $protocolData)[0];
            $newarr = explode(':', $protocolData);
            foreach ($newarr as $y) {
                if ($y) {
                    $val = explode(',', $y);
                    if (count($val) > 1) {
                        $tail = self::edExtractProtocolTail($val);
                        if (count($val) > 9) {
                            $dataType = $tail['dataType'] ?? '';
                            if (!$dataType) {
                                $dataType = (strpos($type, 'B') !== false || strpos($type, 'C') !== false) ? '2' : '1';
                            }
                            $insertone = self::edInsertAlarmData(
                                $val[1],
                                $devId,
                                $type,
                                $dataType,
                                $tail['alarmnum'] ?? '',
                                $tail['alarmup'] ?? '',
                                $tail['alarmdown'] ?? '',
                                $tail['alarmLevel'] ?? '',
                                $tail['alertPeriod'] ?? '',
                                $tail['alarmUserGroup'] ?? '',
                                $tail['alarmUpgradeGroup'] ?? ''
                            );
                        } else {
                            if (strpos($type, 'B') !== false || strpos($type, 'C') !== false) {
                                $insertone = self::edInsertAlarmData($val[1], $devId, $type, '2', $val[count($val) - 1]);
                            } else {
                                $insertone = self::edInsertAlarmData($val[1], $devId, $type, '1', '');
                            }
                        }
                        $insertnotify[] = $insertone;
                    }
                }
            }
        }
        return $insertnotify;
    }

    private static function edInsertAlarmData($key, $devId, $commandType, $dataType = '1', $alarmnum = '', $alarmup = '', $alarmdown = '', $alarmLevel = '', $alertPeriod = '', $alarmUserGroup = '', $alarmUpgradeGroup = ''): array
    {
        $normalizedLevel = $alarmLevel;
        if ($normalizedLevel === '' || $normalizedLevel === null) {
            $normalizedLevel = 0;
        }
        return [
            'AlarmKey' => $key,
            'AlarmName' => $key,
            'DevId' => $devId,
            'AlarmValue' => $alarmnum,
            'AlarmUpLimit' => $alarmup,
            'AlarmDownLimit' => $alarmdown,
            'CommandType' => $commandType,
            'DataType' => $dataType,
            'AlarmLevel' => $normalizedLevel,
            'AlertPeriod' => $alertPeriod,
            'UserID' => $alarmUserGroup,
            'UpgradeUser' => $alarmUpgradeGroup,
            'TogetherAlarm' => '',
        ];
    }

    private static function edDeviceBroken($devId): void
    {
        try {
            $alarmCrud = self::edCrud('dcim-alarmnotifymode');
            $alarmCrud->legacyInsert([
                'AlarmType' => 5,
                'AlarmKey' => dcim_msg('app.alarm_disconnected'),
                'AlarmName' => dcim_msg('app.alarm_disconnected'),
                'DevId' => (int)$devId,
                'AlarmLevel' => 0,
                'AlertPeriod' => '',
                'TogetherAlarm' => '',
            ]);
        } catch (\Throwable $e) {
            error_log('[ImportDevicePtlKey] DeviceBroken failed: ' . $e->getMessage());
        }
    }

    private static function edFilterExistingSearchFields(string $table, array $candidates): array
    {
        $safeTable = trim($table);
        if ($safeTable === '' || preg_match('/^[A-Za-z0-9_-]+$/', $safeTable) !== 1) {
            return [];
        }
        $availableExact = [];
        $availableLower = [];
        $availableNorm = [];
        try {
            $driver = strtolower((string)Flight::db()->getAttribute(PDO::ATTR_DRIVER_NAME));
            if ($driver === 'dm') {
                $stmt = Flight::db()->prepare('SELECT COLUMN_NAME FROM USER_TAB_COLUMNS WHERE UPPER(TABLE_NAME)=UPPER(:table) ORDER BY COLUMN_ID');
                $stmt->bindValue(':table', $safeTable);
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $field = trim((string)($row['COLUMN_NAME'] ?? ''));
                    if ($field === '') {
                        continue;
                    }
                    $availableExact[$field] = $field;
                    $availableLower[strtolower($field)] = $field;
                    $norm = strtolower(str_replace(['_', '-'], '', $field));
                    if ($norm !== '') {
                        $availableNorm[$norm] = $field;
                    }
                }
            } else {
                $stmt = Flight::db()->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $safeTable) . '`');
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!isset($row['Field'])) {
                        continue;
                    }
                    $field = trim((string)$row['Field']);
                    if ($field === '') {
                        continue;
                    }
                    $availableExact[$field] = $field;
                    $availableLower[strtolower($field)] = $field;
                    $norm = strtolower(str_replace(['_', '-'], '', $field));
                    if ($norm !== '') {
                        $availableNorm[$norm] = $field;
                    }
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        $fields = [];
        $seen = [];
        foreach ($candidates as $field) {
            if (!is_string($field) || $field === '') {
                continue;
            }
            $actual = '';
            if (isset($availableExact[$field])) {
                $actual = $availableExact[$field];
            } else {
                $lower = strtolower($field);
                if (isset($availableLower[$lower])) {
                    $actual = $availableLower[$lower];
                } else {
                    $norm = strtolower(str_replace(['_', '-'], '', $field));
                    if ($norm !== '' && isset($availableNorm[$norm])) {
                        $actual = $availableNorm[$norm];
                    }
                }
            }
            if ($actual !== '' && !isset($seen[$actual])) {
                $seen[$actual] = true;
                $fields[] = $actual;
            }
        }
        return $fields;
    }

    private static function northBuildParamCollectQuery(array $data): array
    {
        $viewFields = self::edFilterExistingSearchFields('dcim-paramcollectvalview', [
            'id',
            'status',
            'DevId',
            'DevID',
            'DeviceName',
            'AlarmKey',
            'AlarmName',
            'CommandType',
            'DataType',
            'OId',
            'LastReceiveData',
        ]);
        $viewFieldSet = [];
        foreach ($viewFields as $field) {
            $viewFieldSet[(string)$field] = true;
        }

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
        $pickExistingField = static function (array $existing, array $candidates): string {
            if (!$existing || !$candidates) {
                return '';
            }
            $direct = [];
            $normalized = [];
            foreach ($existing as $field) {
                $name = trim((string)$field);
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
        };

        $whereParts = ['1=1'];
        $params = [];
        $viewStatusField = $pickExistingField($viewFields, ['status']);
        if ($viewStatusField !== '') {
            $whereParts[] = $viewStatusField . ' = 1';
        }

        $devIdField = $pickExistingField($viewFields, ['DevId', 'DevID']);
        $deviceRequiredCols = self::edFilterExistingSearchFields('dcim-device', ['id', 'status']);
        $deviceIdField = $pickExistingField($deviceRequiredCols, ['id']);
        $deviceStatusField = $pickExistingField($deviceRequiredCols, ['status']);
        if ($devIdField !== '' && $deviceIdField !== '' && $deviceStatusField !== '') {
            $whereParts[] = 'EXISTS (SELECT 1 FROM ' . self::statsQuoteIdent('dcim-device') . ' d WHERE d.' . self::statsQuoteIdent($deviceIdField) . ' = ' . self::statsQuoteIdent($devIdField) . ' AND d.' . self::statsQuoteIdent($deviceStatusField) . ' = 1)';
        }

        $dataTypeRaw = trim((string)($data['DataType'] ?? ''));
        $dataTypeField = $pickExistingField($viewFields, ['DataType']);
        if ($dataTypeRaw !== '' && $dataTypeField !== '') {
            $dataTypes = $parseList($dataTypeRaw);
            if (!$dataTypes) {
                $dataTypes = [$dataTypeRaw];
            }
            $typeHolders = [];
            foreach ($dataTypes as $idx => $dt) {
                $ph = ':dtype_' . $idx;
                $typeHolders[] = $ph;
                $params[$ph] = $dt;
            }
            if ($typeHolders) {
                $whereParts[] = $dataTypeField . ' IN (' . implode(', ', $typeHolders) . ')';
            }
        }

        $search = trim((string)($data['search'] ?? ($data['key'] ?? '')));
        if ($search !== '') {
            $searchFields = [];
            $searchFieldSeen = [];
            foreach (['DeviceName', 'AlarmKey', 'AlarmName', 'OId', 'CommandType', 'DataType', 'DevId', 'DevID'] as $candidateSearchField) {
                $resolvedSearchField = $pickExistingField($viewFields, [$candidateSearchField]);
                if ($resolvedSearchField !== '' && !isset($searchFieldSeen[$resolvedSearchField])) {
                    $searchFieldSeen[$resolvedSearchField] = true;
                    $searchFields[] = $resolvedSearchField;
                }
            }
            $searchConds = [];
            foreach ($searchFields as $idx => $field) {
                $ph = ':search_' . $idx;
                $searchConds[] = $field . ' LIKE ' . $ph;
                $params[$ph] = '%' . $search . '%';
            }
            if ($searchConds) {
                $whereParts[] = '(' . implode(' OR ', $searchConds) . ')';
            }
        }

        return [
            'where' => implode(' AND ', $whereParts),
            'params' => $params,
            'order_by' => isset($viewFieldSet['id']) ? 'ORDER BY id DESC' : '',
            'view_field_set' => $viewFieldSet,
        ];
    }

    private static function northNormalizeImportKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        $key = preg_replace('/^\xEF\xBB\xBF/u', '', $key);
        $key = str_replace(["\xC2\xA0", ' ', '_', '-'], '', $key);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($key);
        }
        return strtolower($key);
    }

    private static function northMatrixToImportRows(array $matrix): array
    {
        $rows = [];
        foreach ($matrix as $rawRow) {
            if (!is_array($rawRow)) {
                continue;
            }
            $isEmpty = true;
            foreach ($rawRow as $cell) {
                if (trim((string)$cell) !== '') {
                    $isEmpty = false;
                    break;
                }
            }
            if (!$isEmpty) {
                $rows[] = $rawRow;
            }
        }
        if (count($rows) <= 1) {
            return [];
        }
        $header = array_map(static function ($v) {
            $s = trim((string)$v);
            return preg_replace('/^\xEF\xBB\xBF/u', '', $s);
        }, (array)$rows[0]);
        $out = [];
        for ($i = 1; $i < count($rows); $i++) {
            $raw = (array)$rows[$i];
            $map = [];
            foreach ($raw as $idx => $val) {
                $map['__c' . (int)$idx] = $val;
                $h = $header[$idx] ?? '';
                if ($h !== '') {
                    $map[$h] = $val;
                }
            }
            $out[] = $map;
        }
        return $out;
    }

    private static function northReadParamImportRows(array $data): array
    {
        if (isset($data['rows'])) {
            $rowsPayload = $data['rows'];
            if (is_string($rowsPayload)) {
                $decoded = json_decode($rowsPayload, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $rowsPayload = $decoded;
                }
            }
            if (is_array($rowsPayload) && $rowsPayload) {
                $isSequential = array_keys($rowsPayload) === range(0, count($rowsPayload) - 1);
                if (!$isSequential) {
                    return [(array)$rowsPayload];
                }
                $first = $rowsPayload[0] ?? [];
                if (is_array($first) && $first && array_keys($first) === range(0, count($first) - 1)) {
                    return self::northMatrixToImportRows($rowsPayload);
                }
                $out = [];
                foreach ($rowsPayload as $row) {
                    if (is_object($row)) {
                        $row = (array)$row;
                    }
                    if (is_array($row) && $row) {
                        $out[] = $row;
                    }
                }
                return $out;
            }
        }

        $upload = self::edResolveUploadFile($data);
        $tmpPath = is_string($upload['tmp'] ?? null) ? $upload['tmp'] : '';
        $name = (string)($upload['name'] ?? '');
        if ($tmpPath === '' || (!is_uploaded_file($tmpPath) && !is_file($tmpPath))) {
            return [];
        }

        $ext = strtolower(pathinfo($name !== '' ? $name : $tmpPath, PATHINFO_EXTENSION));
        $readerTypes = [];
        if ($ext === 'csv' || $ext === 'txt') {
            $readerTypes = ['Csv', 'Xlsx', 'Xls'];
        } elseif ($ext === 'xlsx') {
            $readerTypes = ['Xlsx', 'Xls', 'Csv'];
        } elseif ($ext === 'xls') {
            $readerTypes = ['Xls', 'Xlsx', 'Csv'];
        } else {
            $readerTypes = ['Xlsx', 'Xls', 'Csv'];
        }

        $spreadsheet = null;
        $lastError = '';
        foreach ($readerTypes as $readerType) {
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($readerType);
                $spreadsheet = $reader->load($tmpPath);
                break;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }
        if ($spreadsheet === null) {
            throw new \RuntimeException($lastError !== '' ? $lastError : dcim_msg('error.failed_read_file'));
        }

        $sheetRows = $spreadsheet->getSheet(0)->toArray(null, false, false, false);
        return self::northMatrixToImportRows(is_array($sheetRows) ? $sheetRows : []);
    }

    private static function northPickImportCell(array $row, array $candidates, int $fallbackIndex = -1): string
    {
        foreach ($candidates as $key) {
            if (array_key_exists($key, $row)) {
                $val = trim((string)$row[$key]);
                if ($val !== '') {
                    return $val;
                }
            }
        }

        $normMap = [];
        foreach ($row as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $nk = self::northNormalizeImportKey($k);
            if ($nk !== '' && !array_key_exists($nk, $normMap)) {
                $normMap[$nk] = $v;
            }
        }
        foreach ($candidates as $key) {
            $nk = self::northNormalizeImportKey((string)$key);
            if ($nk !== '' && array_key_exists($nk, $normMap)) {
                $val = trim((string)$normMap[$nk]);
                if ($val !== '') {
                    return $val;
                }
            }
        }

        if ($fallbackIndex >= 0) {
            $k = '__c' . $fallbackIndex;
            if (array_key_exists($k, $row)) {
                return trim((string)$row[$k]);
            }
        }
        return '';
    }

    private static function northResolveExportOid(array $row, string $mode = ''): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === 'oid' || $mode === 'oidctl') {
            $devId = trim((string)($row['DevID'] ?? ($row['DevId'] ?? '')));
            $id = trim((string)($row['id'] ?? ''));
            if ($devId !== '' && $id !== '') {
                $segment = ($mode === 'oidctl') ? '2' : '1';
                return '1.3.6.1.4.1.99999.' . $devId . '.' . $segment . '.' . $id;
            }
        }
        foreach (['OId', 'OID', 'Oid', 'oid'] as $key) {
            $val = trim((string)($row[$key] ?? ''));
            if ($val !== '') {
                return $val;
            }
        }
        $devId = trim((string)($row['DevID'] ?? ($row['DevId'] ?? '')));
        $id = trim((string)($row['id'] ?? ''));
        if ($devId !== '' && $id !== '') {
            return '1.3.6.1.4.1.99999.' . $devId . '.1.' . $id;
        }
        foreach (['Command', 'AlarmKey', 'ParamKey', 'Key'] as $key) {
            $val = trim((string)($row[$key] ?? ''));
            if ($val !== '') {
                return $val;
            }
        }
        return '';
    }

    // GET/POST /ExportParamValDataKey
    public static function northExportParamValData()
    {
        $data = Flight::request_data();
        self::edRequireAuth($data);
        foreach (['token', 'auth', 'Auth', 'authorization', 'Authorization'] as $key) {
            if (array_key_exists($key, $data)) {
                unset($data[$key]);
            }
        }
        try {
            $query = self::northBuildParamCollectQuery($data);
            $rows = self::edCrud('dcim-paramcollectvalview')->selectByRawCondition(
                $query['where'],
                (string)$query['order_by'],
                (array)$query['params']
            );
            $rows = is_array($rows) ? $rows : [];

            $downloadName = 'param_values_' . date('Ymd_His') . '.csv';
            $exportType = strtolower(trim((string)($data['type'] ?? '')));
            if ($exportType === 'oidctl') {
                $whereParts = ['1=1'];
                $params = [];
                $devId = trim((string)($data['DevID'] ?? ($data['DevId'] ?? '')));
                if ($devId !== '') {
                    $whereParts[] = 'DevID = :dev_id';
                    $params[':dev_id'] = $devId;
                }
                $command = trim((string)($data['Command'] ?? ''));
                if ($command !== '') {
                    $whereParts[] = 'Command LIKE :command';
                    $params[':command'] = '%' . $command . '%';
                }
                $cmdType = trim((string)($data['commandType'] ?? ($data['CommandType'] ?? '2')));
                if ($cmdType === '' || $cmdType === '0') {
                    $cmdType = '2';
                }
                if ($cmdType !== '2') {
                    $cmdType = '2';
                }
                $whereParts[] = 'CommandType = :command_type';
                $params[':command_type'] = $cmdType;
                $cmdRows = self::edCrud('dcim-command-deviceview')->selectByRawCondition(
                    implode(' AND ', $whereParts),
                    'ORDER BY id ASC',
                    $params
                );
                $cmdRows = is_array($cmdRows) ? $cmdRows : [];
                $mapped = [];
                foreach ($cmdRows as $row) {
                    $mapped[] = [
                        'id' => $row['id'] ?? '',
                        'DevID' => $row['DevID'] ?? '',
                        'DeviceName' => $row['DeviceName'] ?? '',
                        'CommandDesc' => $row['CommandDesc'] ?? '',
                        'Command' => $row['Command'] ?? '',
                        'OId' => self::northResolveExportOid((array)$row, 'oidctl'),
                    ];
                }
                if ($mapped) {
                    self::edStreamCsvFromRows($mapped, 'param_oidctl_' . date('Ymd_His') . '.csv');
                } else {
                    self::edStreamCsvByColumns(['id', 'DevID', 'DeviceName', 'CommandDesc', 'Command', 'OId'], 'param_oidctl_' . date('Ymd_His') . '.csv');
                }
                return;
            }
            if ($exportType === 'oid') {
                $columns = ['id', 'AlarmKey', 'DevId', 'DeviceName', 'OId', 'DataType', 'CommandType'];
                $filteredColumns = [];
                $fieldSet = is_array($query['view_field_set'] ?? null) ? $query['view_field_set'] : [];
                foreach ($columns as $col) {
                    if (isset($fieldSet[$col])) {
                        $filteredColumns[] = $col;
                    }
                }
                if (!$filteredColumns) {
                    $filteredColumns = $columns;
                }
                if ($rows) {
                    $mappedRows = [];
                    foreach ($rows as $row) {
                        $line = [];
                        foreach ($filteredColumns as $col) {
                            if ($col === 'OId') {
                                $line[$col] = self::northResolveExportOid((array)$row, 'oid');
                                continue;
                            }
                            $line[$col] = $row[$col] ?? '';
                        }
                        $mappedRows[] = $line;
                    }
                    self::edStreamCsvFromRows($mappedRows, 'param_oid_' . date('Ymd_His') . '.csv');
                } else {
                    self::edStreamCsvByColumns($filteredColumns, 'param_oid_' . date('Ymd_His') . '.csv');
                }
                return;
            }

            if ($rows) {
                self::edStreamCsvFromRows($rows, $downloadName);
            } else {
                $defaultColumns = ['id', 'AlarmKey', 'DevId', 'DeviceName', 'DataType', 'OId', 'LastReceiveData'];
                self::edStreamCsvByColumns($defaultColumns, $downloadName);
            }
        } catch (\Throwable $e) {
            P_E(str_replace('{reason}', $e->getMessage(), dcim_msg('error.export_failed_with_reason')));
        }
    }

    // POST /GetParamValListKey
    public static function northGetParamValList()
    {
        $data = Flight::request_data();
        self::edRequireAuth($data);
        $page = isset($data['pageNo']) ? (int)$data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int)$data['pageSize'] : 15;
        if ($page <= 0) {
            $page = 1;
        }
        if ($pageSize <= 0) {
            $pageSize = 15;
        }
        $query = self::northBuildParamCollectQuery($data);
        $result = self::edCrud('dcim-paramcollectvalview')->selectWithPagination(
            (string)$query['where'],
            (array)$query['params'],
            (string)$query['order_by'],
            $page,
            $pageSize
        );
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    // POST /ImportParamValDataKey
    public static function northImportParamValData()
    {
        $data = Flight::request_data();
        self::edRequireAuth($data);
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
            if ((is_string($value) && trim($value) !== '') || (is_array($value) && !empty($value))) {
                $hasInput = true;
                break;
            }
        }

        if (!$hasInput) {
            O_E(true, tp_msg_success(), 100, 1);
            return;
        }

        $type = strtolower(trim((string)($data['type'] ?? '')));
        $tableRaw = isset($data['table']) && is_string($data['table']) ? trim($data['table']) : '';
        $isCompatMode = in_array($type, ['', 'oid', 'oidctl'], true)
            && ($tableRaw === '' || in_array($tableRaw, ['dcim-collectordata', 'dcim-alarmnotifymode', 'dcim-devicecommand'], true));

        if ($isCompatMode) {
            try {
                $rows = self::northReadParamImportRows($data);
                if (!$rows) {
                    O_E(true, tp_msg_success(), 100, 1);
                    return;
                }

                $targetTable = $type === 'oidctl' ? 'dcim-devicecommand' : 'dcim-alarmnotifymode';
                $idCandidates = $type === 'oidctl'
                    ? ['id', 'ID', dcim_msg('import.command_id_header_cn'), dcim_msg('import.command_id_header_cn_lower'), 'CommandID', 'CommandId']
                    : ['id', 'ID', dcim_msg('import.param_id_header_cn'), dcim_msg('import.param_id_header_cn_lower'), 'ParamID', 'ParamId'];
                $idFallbackIndex = $type === 'oidctl' ? 3 : 1;
                $oidCandidates = ['OId', 'OID', 'oid', 'Oid'];
                $oidFallbackIndex = $type === 'oidctl' ? 6 : 10;

                $seenOids = [];
                $updates = [];
                $errors = [];
                foreach ($rows as $rowIndex => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $id = self::northPickImportCell($row, $idCandidates, $idFallbackIndex);
                    $oid = self::northPickImportCell($row, $oidCandidates, $oidFallbackIndex);
                    if ($id === '' || $oid === '') {
                        continue;
                    }
                    if (isset($seenOids[$oid])) {
                        $errors[] = dcim_msg('error.oid_duplicate_with_row', null, [
                            'row' => $rowIndex + 2,
                            'oid' => $oid,
                        ]);
                        continue;
                    }
                    $seenOids[$oid] = true;
                    $updates[] = ['id' => $id, 'OId' => $oid];
                }

                if ($errors) {
                    P_E(implode('; ', $errors));
                }
                if (!$updates) {
                    O_E(true, tp_msg_success(), 100, 1);
                    return;
                }

                $crud = self::edCrud($targetTable);
                $processed = 0;
                $updated = 0;
                $failed = 0;
                $failedRows = [];
                foreach ($updates as $one) {
                    $processed++;
                    try {
                        $ok = $crud->updateById($one['id'], ['OId' => $one['OId']]);
                        if ($ok) {
                            $updated++;
                        } else {
                            $failed++;
                            $failedRows[] = $one['id'];
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        $failedRows[] = $one['id'];
                    }
                }

                $result = [
                    'table' => $targetTable,
                    'processed' => $processed,
                    'updated' => $updated,
                    'failed' => $failed,
                    'failed_ids' => $failedRows,
                ];
                O_E($result, tp_msg_success(), 100, $updated > 0 ? $updated : 0);
                return;
            } catch (\Throwable $e) {
                P_E(str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')));
                return;
            }
        }

        $table = isset($data['table']) && is_string($data['table']) && trim($data['table']) !== ''
            ? trim($data['table'])
            : 'dcim-collectordata';

        try {
            $result = self::edCrud($table)->importMappedData($data, [
                'table' => $table,
            ]);
            O_E($result, tp_msg_success(), 100, isset($result['processed']) ? (int)$result['processed'] : false);
        } catch (\Throwable $e) {
            P_E(str_replace('{reason}', $e->getMessage(), dcim_msg('error.import_failed_with_reason')));
        }
    }

    // POST /DealParamValDataKey
    public static function northDealParamValData()
    {
        $data = Flight::request_data();
        self::edRequireAuth($data);
        O_E(true, tp_msg_success(), 100, 1);
    }

    public static function ImportUDeviceJson()
    {
        self::edImportByCrud([
            'table' => 'dcim-udevice',
            'json_file' => true,
            'compat_noop_on_failure' => true,
        ]);
    }

    public static function ImportUDeviceTHJson()
    {
        self::edImportByCrud([
            'table' => 'dcim-udevicestatus',
            'json_file' => true,
            'compat_noop_on_failure' => true,
        ]);
    }

    private static function edRequireAuthTenant(array $data = [])
    {
        $user = (new CrudController('dcim-person'))->legacyEnsureAuth($data);
        if (!$user) {
            L_E(tp_msg_login());
        }
        return $user;
    }

    private static function edRequireAuthLoose(array $data = [])
    {
        $user = (new CrudController('dcim-person'))->legacyEnsureAuth($data);
        if (!$user) {
            L_E();
        }
        return $user;
    }

    private static function edNormalizeTableName($table): string
    {
        $name = is_string($table) ? trim($table) : '';
        if ($name === '' || preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            return 'dcim-deviceparam';
        }
        return $name;
    }

    public static function getAssetRentRecordList(): void
    {
        $data = Flight::request_data();
        self::edRequireAuthTenant($data);
        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;

        if (self::dvCanTryView('vw_tenant_u_record')) {
            try {
                $result = self::edCrud('vw_tenant_u_record')->selectWithPagination(
                    '1=1',
                    [],
                    'ORDER BY id DESC',
                    $page,
                    $pageSize
                );
                $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
                $num = $rows ? count($rows) : false;
                O_E($result, tp_msg_success(), 100, $num);
                return;
            } catch (Throwable $e) {
                self::dvLogViewFallback('GetAssetRentRecordListKey', 'vw_tenant_u_record', $e);
            }
        }

        $baseResult = self::edCrud('dcim-tenanturecord')->selectByRawConditionWithPagination(
            'status = 1',
            [],
            'ORDER BY id DESC',
            $page,
            $pageSize
        );
        $total = (int) ($baseResult['page']['total'] ?? 0);
        $baseRows = is_array($baseResult['info'] ?? null) ? $baseResult['info'] : [];

        $rentIds = [];
        foreach ($baseRows as $row) {
            if (isset($row['RentId']) && $row['RentId'] !== null && $row['RentId'] !== '') {
                $rentIds[(string)$row['RentId']] = true;
            }
        }

        $tuMap = [];
        $tenantIds = [];
        $cabinetIds = [];
        $tuRows = self::edCrud('dcim-tenantu')->selectByIds(
            array_keys($rentIds),
            ['id', 'TenantId', 'CabinetId', 'TenantUlocation', 'TenantStartTime', 'TenantDuration']
        );
        foreach ($tuRows as $item) {
            $idKey = (string)($item['id'] ?? '');
            if ($idKey !== '') {
                $tuMap[$idKey] = $item;
            }
            if (isset($item['TenantId']) && $item['TenantId'] !== '') {
                $tenantIds[(string)$item['TenantId']] = true;
            }
            if (isset($item['CabinetId']) && $item['CabinetId'] !== '') {
                $cabinetIds[(string)$item['CabinetId']] = true;
            }
        }

        $tenantMap = [];
        $tenantRows = self::edCrud('dcim-tenant')->selectByIds(array_keys($tenantIds), ['id', 'TenantName']);
        foreach ($tenantRows as $item) {
            $tenantMap[(string)$item['id']] = $item['TenantName'] ?? '';
        }

        $cabinetMap = [];
        $areaIds = [];
        $cabinetRows = self::edCrud('dcim-cabinet')->selectByIds(array_keys($cabinetIds), ['id', 'column', 'position', 'AreaId']);
        foreach ($cabinetRows as $item) {
            $cabinetMap[(string)$item['id']] = $item;
            if (isset($item['AreaId']) && $item['AreaId'] !== '') {
                $areaIds[(string)$item['AreaId']] = true;
            }
        }

        $areaMap = [];
        $serverIds = [];
        $areaRows = self::edCrud('dcim-area')->selectByIds(array_keys($areaIds), ['id', 'AreaName', 'ServerCode']);
        foreach ($areaRows as $item) {
            $areaMap[(string)$item['id']] = $item;
            if (isset($item['ServerCode']) && $item['ServerCode'] !== '') {
                $serverIds[(string)$item['ServerCode']] = true;
            }
        }

        $serverMap = [];
        $serverRows = self::edCrud('dcim-server')->selectByIds(array_keys($serverIds), ['id', 'ServerName']);
        foreach ($serverRows as $item) {
            $serverMap[(string)$item['id']] = $item['ServerName'] ?? '';
        }

        $rows = [];
        foreach ($baseRows as $row) {
            $tu = $tuMap[(string)($row['RentId'] ?? '')] ?? [];
            $tenantName = $tenantMap[(string)($tu['TenantId'] ?? '')] ?? '';
            $cabinet = $cabinetMap[(string)($tu['CabinetId'] ?? '')] ?? [];
            $area = $areaMap[(string)($cabinet['AreaId'] ?? '')] ?? [];
            $serverName = $serverMap[(string)($area['ServerCode'] ?? '')] ?? '';
            $rows[] = [
                'id' => $row['id'] ?? null,
                'RentId' => $row['RentId'] ?? null,
                'TenantName' => $tenantName,
                'RentType' => $row['RentType'] ?? '',
                'CabinetId' => $tu['CabinetId'] ?? null,
                'column' => $cabinet['column'] ?? '',
                'AreaName' => $area['AreaName'] ?? '',
                'ServerName' => $serverName,
                'position' => $cabinet['position'] ?? '',
                'TenantUlocation' => $tu['TenantUlocation'] ?? '',
                'TenantStartTime' => $tu['TenantStartTime'] ?? '',
                'TenantDuration' => $tu['TenantDuration'] ?? '',
                'create_time' => $row['create_time'] ?? '',
            ];
        }

        $result = [
            'info' => $rows,
            'page' => [
                'total' => $total,
                'p_n'   => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
                'p'     => $page,
            ],
        ];
        $num = $rows ? count($rows) : false;
        O_E($result, tp_msg_success(), 100, $num);
    }

    public static function getParamTable(): void
    {
        $data = Flight::request_data();
        self::edRequireAuthLoose($data);
        $type = strtolower(trim((string)($data['type'] ?? '')));
        $tplId = (int)($data['tplid'] ?? 0);
        $start = trim((string)($data['startDateTime'] ?? ''));
        $end = trim((string)($data['endDateTime'] ?? ''));
        if ($type === '' || $tplId <= 0 || $start === '' || $end === '') {
            O_E([], tp_msg_success(), 100, false);
            return;
        }
        $tableMap = [
            'all' => 'dcim-tablegeneral',
            'wsd' => 'dcim-tablewsd',
            'air' => 'dcim-tableair',
            'power' => 'dcim-tablepower',
            'ups' => 'dcim-tableups',
        ];
        $tableFallbackMap = [
            'all' => ['dcim-tablegeneral', 'dcim-tabletemplate', 'dcim-paramtable', 'dcim-paramtabletpl', 'dcim-tablewsd', 'dcim-tableair', 'dcim-tablepower', 'dcim-tableups'],
            'wsd' => ['dcim-tablewsd', 'dcim-tabletemplate', 'dcim-paramtable', 'dcim-paramtabletpl'],
            'air' => ['dcim-tableair', 'dcim-tabletemplate', 'dcim-paramtable', 'dcim-paramtabletpl'],
            'power' => ['dcim-tablepower', 'dcim-tabletemplate', 'dcim-paramtable', 'dcim-paramtabletpl'],
            'ups' => ['dcim-tableups', 'dcim-tabletemplate', 'dcim-paramtable', 'dcim-paramtabletpl'],
        ];
        $table = $tableMap[$type] ?? '';
        if ($table === '') {
            O_E([], tp_msg_success(), 100, false);
            return;
        }
        $loadTplById = static function (CrudController $crud, int $id): array {
            if ($id <= 0) {
                return [];
            }
            try {
                $rows = $crud->selectByRawCondition(
                    'id = :id AND (status <> -1 OR status IS NULL)',
                    'ORDER BY id DESC LIMIT 1',
                    [':id' => $id]
                );
            } catch (\Throwable $e) {
                try {
                    $rows = $crud->selectByRawCondition(
                        'id = :id',
                        'ORDER BY id DESC LIMIT 1',
                        [':id' => $id]
                    );
                } catch (\Throwable $ignore) {
                    $rows = [];
                }
            }
            if (is_array($rows) && $rows) {
                return (array)$rows[0];
            }
            return [];
        };
        $tplCrud = self::edCrud($table);
        $tpl = $loadTplById($tplCrud, $tplId);
        if (!$tpl) {
            foreach (($tableFallbackMap[$type] ?? []) as $candidateTable) {
                if ($candidateTable === $table) {
                    continue;
                }
                $candidateCrud = self::edCrud($candidateTable);
                $candidate = $loadTplById($candidateCrud, $tplId);
                if (!$candidate) {
                    foreach (['TplId', 'TemplateId', 'TableTplId', 'ParamTplId'] as $tplRefField) {
                        try {
                            $rows = $candidateCrud->selectByRawCondition(
                                $tplRefField . ' = :tplid',
                                'ORDER BY id DESC LIMIT 1',
                                [':tplid' => $tplId]
                            );
                        } catch (\Throwable $e) {
                            $rows = [];
                        }
                        if ($rows) {
                            $candidate = (array)$rows[0];
                            break;
                        }
                    }
                }
                if ($candidate) {
                    $tpl = $candidate;
                    $table = $candidateTable;
                    break;
                }
            }
        }
        $paramJson = '';
        foreach (['ParamId', 'ParamJson', 'ParamData', 'TableData', 'PointData', 'TemplateData', 'Param', 'ParamList', 'TemplateJson', 'Detail'] as $paramField) {
            if (!isset($tpl[$paramField]) || $tpl[$paramField] === null) {
                continue;
            }
            $one = trim((string)$tpl[$paramField]);
            if ($one !== '') {
                $paramJson = $one;
                break;
            }
        }
        $paramArr = json_decode($paramJson, true);
        if (!is_array($paramArr) || !$paramArr) {
            $parsed = self::parseLegacyParamPayload($paramJson);
            if (is_array($parsed) && $parsed) {
                $paramArr = $parsed;
            }
        }
        if (!is_array($paramArr) || !$paramArr) {
            $fallbackParamArr = [];
            foreach (['dcim-paramtabletpl', 'dcim-paramtable', 'dcim-tabletemplate', 'dcim-tablegeneral'] as $fallbackTable) {
                $fallbackCols = self::edFilterExistingSearchFields($fallbackTable, [
                    'id',
                    'status',
                    'TplId',
                    'TemplateId',
                    'TableId',
                    'TableTplId',
                    'ParamTplId',
                    'TableModelId',
                    'TemplateModelId',
                    'DevID',
                    'DevId',
                    'DeviceId',
                    'DeviceID',
                    'DevCode',
                    'DeviceCode',
                    'ParamId',
                    'ParaId',
                    'ParamID',
                    'ParamLsh',
                    'ParamKey',
                    'ParamName',
                    'ParamCode',
                    'key',
                ]);
                if (!$fallbackCols) {
                    continue;
                }
                $tplField = '';
                foreach (['TplId', 'TemplateId', 'TableId', 'TableTplId', 'ParamTplId'] as $candidateTplField) {
                    if (in_array($candidateTplField, $fallbackCols, true)) {
                        $tplField = $candidateTplField;
                        break;
                    }
                }
                if ($tplField === '') {
                    continue;
                }
                $where = [$tplField . ' = :tplid'];
                if (in_array('status', $fallbackCols, true)) {
                    $where[] = 'status <> -1';
                }
                try {
                    $fallbackRows = self::edCrud($fallbackTable)->selectByRawCondition(
                        implode(' AND ', $where),
                        'ORDER BY id ASC',
                        [':tplid' => $tplId]
                    );
                } catch (\Throwable $e) {
                    $fallbackRows = [];
                }
                if (!$fallbackRows) {
                    continue;
                }
                foreach ($fallbackRows as $fallbackRow) {
                    $item = [];
                    foreach (['DevID', 'DevId', 'DeviceId', 'DeviceID'] as $devField) {
                        $devVal = trim((string)($fallbackRow[$devField] ?? ''));
                        if ($devVal !== '') {
                            $item['id'] = $devVal;
                            break;
                        }
                    }
                    if (!isset($item['id'])) {
                        foreach (['DevCode', 'DeviceCode'] as $devCodeField) {
                            $devCodeVal = trim((string)($fallbackRow[$devCodeField] ?? ''));
                            if ($devCodeVal !== '') {
                                $item['id'] = $devCodeVal;
                                break;
                            }
                        }
                    }
                    foreach (['ParamId', 'ParaId', 'ParamID', 'ParamLsh'] as $pidField) {
                        $pidVal = trim((string)($fallbackRow[$pidField] ?? ''));
                        if ($pidVal !== '') {
                            $item['ParamId'] = $pidVal;
                            break;
                        }
                    }
                    foreach (['ParamKey', 'ParamName', 'ParamCode', 'key'] as $pkeyField) {
                        $pkeyVal = trim((string)($fallbackRow[$pkeyField] ?? ''));
                        if ($pkeyVal !== '') {
                            $item['ParamKey'] = $pkeyVal;
                            break;
                        }
                    }
                    if ($item) {
                        $fallbackParamArr[] = $item;
                    }
                }
                if ($fallbackParamArr) {
                    break;
                }
            }
            if ($fallbackParamArr) {
                $paramArr = $fallbackParamArr;
            }
        }
        if (!is_array($paramArr) || !$paramArr) {
            O_E([], tp_msg_success(), 100, false);
            return;
        }

        $paramIdSet = [];
        $deviceIds = [];
        foreach ($paramArr as $item) {
            $did = trim((string)($item['id'] ?? ($item['DevID'] ?? ($item['DevId'] ?? ''))));
            if ($did !== '') {
                $deviceIds[$did] = true;
            }
            $pid = trim((string)($item['ParamId'] ?? ($item['ParaId'] ?? '')));
            if ($pid !== '') {
                $paramIdSet[$pid] = true;
            }
        }
        $deviceMap = [];
        if ($deviceIds) {
            foreach (self::edCrud('dcim-device')->selectByIds(array_keys($deviceIds), ['id', 'DeviceName']) as $dev) {
                $key = (string)($dev['id'] ?? '');
                if ($key !== '') {
                    $deviceMap[$key] = (string)($dev['DeviceName'] ?? '');
                }
            }
        }
        $paramKeyMap = [];
        if ($paramIdSet) {
            foreach (self::edCrud('dcim-param')->selectByIds(array_keys($paramIdSet), ['id', 'ParamKey', 'ParamName']) as $paramRow) {
                $pid = (string)($paramRow['id'] ?? '');
                if ($pid === '') {
                    continue;
                }
                $pkey = trim((string)($paramRow['ParamKey'] ?? ''));
                if ($pkey === '') {
                    $pkey = trim((string)($paramRow['ParamName'] ?? ''));
                }
                if ($pkey !== '') {
                    $paramKeyMap[$pid] = $pkey;
                }
            }
        }

        $collectorColumns = self::edFilterExistingSearchFields('dcim-collectordata', ['DevID', 'DevId', 'DevCode', 'DeviceCode', 'Data', 'LastReceiveData', 'DataJson', 'CollectData', 'RawData']);
        $collectorDevFields = [];
        foreach (['DevID', 'DevId', 'DevCode', 'DeviceCode'] as $cdf) {
            if (in_array($cdf, $collectorColumns, true)) {
                $collectorDevFields[] = $cdf;
            }
        }
        if (!$collectorDevFields) {
            $collectorDevFields[] = 'DevID';
        }
        $payloadFields = [];
        foreach (['Data', 'LastReceiveData', 'DataJson', 'CollectData', 'RawData'] as $payloadField) {
            if (in_array($payloadField, $collectorColumns, true)) {
                $payloadFields[] = $payloadField;
            }
        }
        if (!$payloadFields) {
            $payloadFields = ['Data', 'LastReceiveData', 'DataJson', 'CollectData', 'RawData'];
        }
        $timeFields = self::edFilterExistingSearchFields('dcim-collectordata', [
            'create_time',
            'CollectTime',
            'collect_time',
            'update_time',
            'ReceiveTime',
            'Time',
            'CollectDateTime',
            'CollectDate',
        ]);
        $collectorTimeField = $timeFields ? $timeFields[0] : '';
        $paramViewColumns = self::edFilterExistingSearchFields('dcim-paramcollectvalview', [
            'DevID',
            'DevId',
            'ParamId',
            'ParamName',
            'ParamKey',
            'CollectVal',
            'CollectTime',
            'create_time',
        ]);
        $paramViewDevField = in_array('DevID', $paramViewColumns, true) ? 'DevID' : (in_array('DevId', $paramViewColumns, true) ? 'DevId' : '');
        $paramViewTimeField = in_array('CollectTime', $paramViewColumns, true) ? 'CollectTime' : (in_array('create_time', $paramViewColumns, true) ? 'create_time' : '');
        $paramViewValField = in_array('CollectVal', $paramViewColumns, true) ? 'CollectVal' : '';

        foreach ($paramArr as &$item) {
            $devId = trim((string)($item['id'] ?? ($item['DevID'] ?? ($item['DevId'] ?? ''))));
            $paramId = trim((string)($item['ParamId'] ?? ($item['ParaId'] ?? '')));
            $paramKey = trim((string)($item['paramKey'] ?? ($item['ParamKey'] ?? ($item['key'] ?? ($item['ParamName'] ?? '')))));
            if ($paramKey === '' && $paramId !== '' && isset($paramKeyMap[$paramId])) {
                $paramKey = $paramKeyMap[$paramId];
            }
            $item['DeviceName'] = $deviceMap[$devId] ?? '';
            $item['data'] = [];
            if ($devId === '' || $paramKey === '') {
                continue;
            }
            $devWhereParts = [];
            $whereParams = [];
            foreach ($collectorDevFields as $idx => $devField) {
                $ph = ':devId' . $idx;
                $devWhereParts[] = $devField . ' = ' . $ph;
                $whereParams[$ph] = $devId;
            }
            $whereSql = '(' . implode(' OR ', $devWhereParts) . ')';
            if ($collectorTimeField !== '') {
                $whereSql .= ' AND ' . $collectorTimeField . ' BETWEEN :start AND :end';
                $whereParams[':start'] = $start;
                $whereParams[':end'] = $end;
            }
            $orderBySql = $collectorTimeField !== '' ? ('ORDER BY ' . $collectorTimeField . ' ASC') : 'ORDER BY id ASC';
            $rows = self::edCrud('dcim-collectordata')->selectByRawCondition(
                $whereSql,
                $orderBySql,
                $whereParams
            );
            foreach ($rows as $row) {
                $decoded = [];
                foreach ($payloadFields as $payloadField) {
                    $raw = (string)($row[$payloadField] ?? '');
                    if ($raw === '') {
                        continue;
                    }
                    $decoded = self::parseLegacyParamPayload($raw);
                    if ($decoded) {
                        break;
                    }
                }
                if (!$decoded) {
                    continue;
                }
                $paramVal = null;
                if (array_key_exists($paramKey, $decoded)) {
                    $paramVal = $decoded[$paramKey];
                } else {
                    $seekKey = strtolower(trim((string)$paramKey));
                    if ($seekKey !== '') {
                        foreach ($decoded as $dk => $dv) {
                            if (strtolower(trim((string)$dk)) === $seekKey) {
                                $paramVal = $dv;
                                break;
                            }
                        }
                    }
                }
                if ($paramVal === null) {
                    continue;
                }
                $item['data'][] = [
                    'DeviceName' => $item['DeviceName'],
                    'ParamKey' => $paramKey,
                    'ParamVal' => $paramVal,
                    'create_time' => ($collectorTimeField !== '' ? ($row[$collectorTimeField] ?? '') : ($row['create_time'] ?? '')),
                    'DevID' => $devId,
                ];
            }
            if ($item['data']) {
                continue;
            }
            if ($paramViewDevField === '' || $paramViewValField === '') {
                continue;
            }
            $pvWhere = [$paramViewDevField . ' = :pv_dev'];
            $pvParams = [':pv_dev' => $devId];
            if ($paramId !== '' && in_array('ParamId', $paramViewColumns, true)) {
                $pvWhere[] = 'ParamId = :pv_pid';
                $pvParams[':pv_pid'] = $paramId;
            } elseif (in_array('ParamKey', $paramViewColumns, true)) {
                $pvWhere[] = 'ParamKey = :pv_pkey';
                $pvParams[':pv_pkey'] = $paramKey;
            } elseif (in_array('ParamName', $paramViewColumns, true)) {
                $pvWhere[] = 'ParamName = :pv_pname';
                $pvParams[':pv_pname'] = $paramKey;
            }
            if ($start !== '' && $end !== '' && $paramViewTimeField !== '') {
                $pvWhere[] = $paramViewTimeField . ' BETWEEN :pv_start AND :pv_end';
                $pvParams[':pv_start'] = $start;
                $pvParams[':pv_end'] = $end;
            }
            $pvRows = self::edCrud('dcim-paramcollectvalview')->selectByRawCondition(
                implode(' AND ', $pvWhere),
                ($paramViewTimeField !== '' ? ('ORDER BY ' . $paramViewTimeField . ' ASC') : 'ORDER BY id ASC'),
                $pvParams
            );
            if ((!is_array($pvRows) || !$pvRows) && $paramKey !== '') {
                $pvWhereLike = [$paramViewDevField . ' = :pv_dev_like'];
                $pvParamsLike = [':pv_dev_like' => $devId];
                $likeParts = [];
                if (in_array('ParamKey', $paramViewColumns, true)) {
                    $likeParts[] = 'ParamKey LIKE :pv_pkey_like';
                    $pvParamsLike[':pv_pkey_like'] = '%' . $paramKey . '%';
                }
                if (in_array('ParamName', $paramViewColumns, true)) {
                    $likeParts[] = 'ParamName LIKE :pv_pname_like';
                    $pvParamsLike[':pv_pname_like'] = '%' . $paramKey . '%';
                }
                if ($likeParts) {
                    $pvWhereLike[] = '(' . implode(' OR ', $likeParts) . ')';
                    if ($start !== '' && $end !== '' && $paramViewTimeField !== '') {
                        $pvWhereLike[] = $paramViewTimeField . ' BETWEEN :pv_start_like AND :pv_end_like';
                        $pvParamsLike[':pv_start_like'] = $start;
                        $pvParamsLike[':pv_end_like'] = $end;
                    }
                    $pvRows = self::edCrud('dcim-paramcollectvalview')->selectByRawCondition(
                        implode(' AND ', $pvWhereLike),
                        ($paramViewTimeField !== '' ? ('ORDER BY ' . $paramViewTimeField . ' ASC') : 'ORDER BY id ASC'),
                        $pvParamsLike
                    );
                }
            }
            foreach ($pvRows as $pvRow) {
                $item['data'][] = [
                    'DeviceName' => $item['DeviceName'],
                    'ParamKey' => $paramKey,
                    'ParamVal' => $pvRow[$paramViewValField] ?? '',
                    'create_time' => ($paramViewTimeField !== '' ? ($pvRow[$paramViewTimeField] ?? '') : ($pvRow['create_time'] ?? '')),
                    'DevID' => $devId,
                ];
            }
        }
        unset($item);

        O_E(array_values($paramArr), tp_msg_success(), 100, $paramArr ? count($paramArr) : false);
    }

    public static function getAssetsPutoutList(): void
    {
        $data = Flight::request_data();
        self::edRequireAuthTenant($data);

        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;

        $crud = self::edCrud('dcim-assetputout');
        $conditions = ['status = 1'];
        $params = [];

        $assetIds = $crud->legacyFilterAssetIds($data, [
            'search_key' => 'search',
            'search_fields' => ['AssetsNumber', 'AssetsDescribe'],
            'asset_status_param' => 'AssetStatus',
            'assets_type_param' => 'AssetsTypeId',
            'require_filter' => true,
        ]);
        if (is_array($assetIds)) {
            if (!$assetIds) {
                $empty = ['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => $page]];
                O_E($empty, tp_msg_success(), 100, false);
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

        $fieldMap = [
            'PutoutWay' => 'PutoutWay',
            'PutoutStatus' => 'PutoutStatus',
            'AreaId' => 'AreaId',
            'ServerCode' => 'ServerCode',
        ];
        foreach ($fieldMap as $key => $column) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $ph = ':' . $key;
                $conditions[] = $column . ' = ' . $ph;
                $params[$ph] = $data[$key];
            }
        }

        $where = implode(' AND ', $conditions);
        $result = $crud->selectWithPagination($where, $params, 'ORDER BY id DESC', $page, $pageSize);
        $result['info'] = $crud->legacyEnrichAssetPutoutRows($result['info']);

        $num = $result['info'] ? count($result['info']) : false;
        O_E($result, tp_msg_success(), 100, $num);
    }
    // POST /GetDeviceProtocolListKey
    public static function getDeviceProtocolList()
    {
        $data = Flight::request_data();
        $result = self::edCrud('dcim-deviceprotocol')->legacyList($data, [
            'skip_auth' => true,
            'base_where' => ['status = 1'],
            'search_fields' => ['ProtocolName'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, false);
    }

    // POST /GetAssetChangeInfoKey
    public static function getAssetChangeInfoList()
    {
        $data = Flight::request_data();
        $result = self::edCrud('dcim-assetchangelog')->legacyList($data, [
            'base_where' => ['status = 1'],
            'exact_filters' => [
                'AssetsId' => 'AssetsId',
            ],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    // POST /GetOrderMsgKey
    public static function getOrderMsgList()
    {
        $data = Flight::request_data();
        $result = self::edCrud('dcim-orderrecord')->legacyList($data, [
            'base_where' => ['(status <> -1 OR status IS NULL)'],
            'search_fields' => ['MsgCon'],
            'between_filters' => [
                ['field' => 'create_time', 'start_key' => 'startDateTime', 'end_key' => 'endDateTime'],
            ],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        if ($rows) {
            $orderIds = [];
            foreach ($rows as $row) {
                $oid = trim((string)($row['OrderId'] ?? ''));
                if ($oid !== '') {
                    $orderIds[] = $oid;
                }
            }
            $orderMap = [];
            if ($orderIds) {
                foreach (self::edCrud('dcim-order')->selectByIds(array_values(array_unique($orderIds)), ['id', 'OrderNumber', 'OrderName']) as $order) {
                    $key = (string)($order['id'] ?? '');
                    if ($key !== '') {
                        $orderMap[$key] = $order;
                    }
                }
            }
            foreach ($rows as &$row) {
                $order = $orderMap[(string)($row['OrderId'] ?? '')] ?? [];
                $row['OrderNumber'] = $order['OrderNumber'] ?? ($row['OrderNumber'] ?? '');
                $row['OrderName'] = $order['OrderName'] ?? ($row['OrderName'] ?? '');
            }
            unset($row);
            $result['info'] = $rows;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    // POST /GetOperationRecordKey
    public static function getOperationRecordList(bool $skipAuth = false)
    {
        $data = Flight::request_data();
        $searchFields = self::edFilterExistingSearchFields('dcim-syslog', [
            'content',
            'Content',
            'LogContent',
            'LogContext',
            'Action',
            'Operation',
            'remark',
            'Remark',
            'Msg',
            'Message',
        ]);
        if (!$searchFields) {
            $searchFields = ['content'];
        }
        $result = self::edCrud('dcim-syslog')->legacyList($data, [
            'skip_auth' => $skipAuth,
            'base_where' => ['1=1'],
            'search_fields' => $searchFields,
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
        if ($rows) {
            $safeLogKeywords = [
                '账号禁用',
                '账号启用',
                '人员管理新增',
                '人员管理修改',
                '人员管理删除',
                '账户禁用|启用',
                '登录',
                '退出登录',
            ];
            $safeLogMap = array_fill_keys($safeLogKeywords, true);

            // PersonName mapping must be resolved strictly by EmpId -> dcim-person.id.
            $resolveEmpId = static function (array $row): string {
                $empId = self::edNormalizeIntegerOrEmpty(self::edPickField($row, ['EmpId']));
                if ($empId !== '' && $empId !== '0') {
                    return $empId;
                }
                return '';
            };

            $personIdMap = [];
            foreach ($rows as $row) {
                $personId = $resolveEmpId((array)$row);
                if ($personId !== '') {
                    $personIdMap[$personId] = true;
                }
            }
            $personNameMap = [];
            if ($personIdMap) {
                foreach (self::edCrud('dcim-person')->selectByIds(array_keys($personIdMap), ['id', 'PersonName']) as $person) {
                    $pid = trim((string)($person['id'] ?? ''));
                    if ($pid === '') {
                        continue;
                    }
                    $personNameMap[$pid] = trim((string)($person['PersonName'] ?? ''));
                }
            }

            foreach ($rows as &$row) {
                $content = self::edPickField((array)$row, ['content', 'Content', 'LogContent', 'LogContext']);
                $row['logType'] = isset($safeLogMap[$content]) ? '安全日志' : '操作日志';

                $empId = $resolveEmpId((array)$row);
                if ($empId !== '') {
                    $row['PersonName'] = $personNameMap[$empId] ?? '';
                } else {
                    $row['PersonName'] = self::edPickField((array)$row, ['PersonName']);
                }
            }
            unset($row);
            $result['info'] = $rows;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    // POST /GetSnmpParamListKey
    public static function getSnmpParamList()
    {
        $data = Flight::request_data();
        $result = self::edCrud('dcim-devicesnmp')->legacyList($data, [
            'skip_auth' => true,
            'base_where' => ['status = 1'],
            'exact_filters' => ['DevID' => 'DevID'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    // POST /GetSnmpParamDetailKey
    public static function getSnmpParamDetail()
    {
        $data = Flight::request_data();
        $info = self::edCrud('dcim-devicesnmp')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    // POST /GetParamDayListKey
    public static function getParamDayList()
    {
        $data = Flight::request_data();
        $result = self::edCrud('dcim-paramday')->legacyList($data, [
            'base_where' => ['status = 1'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    // POST /GetParamDayDetailKey
    public static function getParamDayDetail()
    {
        $data = Flight::request_data();
        $info = self::edCrud('dcim-paramday')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    private static function edNorthPage(array $data): array
    {
        $page = isset($data['pageNo']) ? (int)$data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int)$data['pageSize'] : 15;
        return [$page, $pageSize];
    }

    private static function edNorthOk($data, $num = null)
    {
        $n = $num;
        if ($n === null) {
            $n = is_array($data) && isset($data['info']) ? ($data['info'] ? count($data['info']) : false) : ($data ? 1 : false);
        }
        O_E($data, tp_msg_success(), 100, $n);
    }

    private static function edNorthRawResponse($payload)
    {
        json_string_response($payload);
    }

    private static function edNorthCountWhere(string $table, string $where, array $params = []): int
    {
        try {
            return (int) self::edCrud($table)->countByRawCondition($where, $params);
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function northGetCabinetList()
    {
        $data = Flight::request_data();
        [$page, $pageSize] = self::edNorthPage($data);
        $result = self::edCrud('dcim-cabinet')->selectWithPagination('status = 1', [], 'ORDER BY id DESC', $page, $pageSize);
        self::edNorthOk($result);
    }

    public static function northGetAssetsList()
    {
        $data = Flight::request_data();
        $cabinetId = $data['cabinetId'] ?? $data['CabinetId'] ?? '';
        if ($cabinetId === '' || $cabinetId === null) {
            result_json(403, dcim_msg('error.cabinet_id_required'), false, false);
        }
        [$page, $pageSize] = self::edNorthPage($data);
        $result = self::edCrud('dcim-asset')->selectWithPagination('status = 1', [], 'ORDER BY id DESC', $page, $pageSize);
        self::edNorthOk($result);
    }

    public static function northGetAssetsDetail()
    {
        $data = Flight::request_data();
        $id = $data['id'] ?? 0;
        $info = self::edCrud('dcim-asset')->findOne([['id', '=', $id], ['status', '=', 1]]);
        self::edNorthOk($info ?: [], $info ? 1 : false);
    }

    public static function northGetAssetNOList()
    {
        $result = self::edCrud('dcim-asset')->selectWithPagination('status = 1', [], 'ORDER BY id DESC', 1, 500);
        self::edNorthOk($result);
    }

    public static function northGetTotalCapacity()
    {
        $uTotal = self::edNorthCountWhere('dcim-cabinetu', 'status = 1');
        $uUsed = self::edNorthCountWhere('dcim-cabinetu', 'status = 1 AND AssetsId IS NOT NULL');
        $payload = [
            'totalU' => $uTotal,
            'useU' => $uUsed,
            'totalPower' => 0,
            'totalWeight' => 0,
            'usePower' => 0,
            'useWeight' => 0,
        ];
        self::edNorthRawResponse($payload);
    }

    public static function northGetCapacityFromCabinets()
    {
        self::edNorthOk([], false);
    }

    public static function northGetSpaceSearch()
    {
        self::edNorthOk([], false);
    }

    public static function northGetAssetsSearchParms()
    {
        self::edNorthOk([], false);
    }

    public static function northGetAssetsSearch()
    {
        $data = Flight::request_data();
        [$page, $pageSize] = self::edNorthPage($data);
        $result = self::edCrud('dcim-assets')->selectWithPagination('status = 1', [], 'ORDER BY id DESC', $page, $pageSize);
        self::edNorthOk($result);
    }

    public static function northAssetStatisticsByStatus()
    {
        self::edNorthOk([], false);
    }

    public static function northAssetStatisticsByTypes()
    {
        self::edNorthOk([], false);
    }

    public static function northGetCabinetStatistics()
    {
        $cabinetTotal = self::edNorthCountWhere('dcim-cabinet', 'status = 1');
        $cabinetUse = self::edNorthCountWhere('dcim-cabinet', 'status = 1 AND AssetsId IS NOT NULL');
        $assetsCount = self::edNorthCountWhere('dcim-asset', "status = 1 AND AssetStatus = 'T'");
        $payload = [
            'cabinetUseCount' => $cabinetUse,
            'cabinetTotal' => $cabinetTotal,
            'assetsCount' => $assetsCount,
        ];
        self::edNorthRawResponse($payload);
    }


    // Merged from DeviceController to reduce controller files.
private static function dvCrud(string $table)
    {
        return new CrudController($table);
    }

    private static function dvCanTryView(string $viewName): bool
    {
        $key = strtolower(trim($viewName));
        if ($key === '') {
            return false;
        }
        return !isset(self::$dvUnavailableViews[$key]);
    }

    private static function dvIsMissingViewException(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        if ($msg === '') {
            return false;
        }
        foreach ([
            'sqlstate[42s02]',
            'base table or view not found',
            "doesn't exist",
            'does not exist',
            'table or view does not exist',
            'invalid object name',
            'unknown table',
            'no such table',
            'view does not exist',
        ] as $needle) {
            if (strpos($msg, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function dvLogViewFallback(string $apiName, string $viewName, \Throwable $e): void
    {
        $writeLog = static function (string $message): void {
            if (function_exists('dcim_debug_log')) {
                dcim_debug_log($message);
                return;
            }
            error_log($message);
        };
        $viewKey = strtolower(trim($viewName));
        if ($viewKey === '') {
            $writeLog('[' . $apiName . '] view query failed, fallback to legacy SQL: ' . $e->getMessage());
            return;
        }
        if (self::dvIsMissingViewException($e)) {
            self::$dvUnavailableViews[$viewKey] = true;
            $onceKey = $apiName . '|' . $viewKey . '|missing';
            if (!isset(self::$dvViewFallbackLogged[$onceKey])) {
                self::$dvViewFallbackLogged[$onceKey] = true;
                $writeLog('[' . $apiName . '] view "' . $viewName . '" missing, fallback to legacy SQL');
            }
            return;
        }
        $writeLog('[' . $apiName . '] view query failed, fallback to legacy SQL: ' . $e->getMessage());
    }

    private static function dvTableColumns(string $table): array
    {
        static $cache = [];
        $safeTable = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string)$table));
        if ($safeTable === '') {
            return [];
        }
        $driver = '';
        try {
            $driver = strtolower((string)Flight::db()->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            $driver = '';
        }
        $cacheKey = ($driver !== '' ? $driver : 'unknown') . '|' . $safeTable;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        $cols = [];
        try {
            if ($driver === 'dm') {
                $stmt = Flight::db()->prepare('SELECT COLUMN_NAME FROM USER_TAB_COLUMNS WHERE UPPER(TABLE_NAME)=UPPER(:table_name) ORDER BY COLUMN_ID');
                $stmt->bindValue(':table_name', $safeTable);
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $field = trim((string)($row['COLUMN_NAME'] ?? ''));
                    if ($field !== '') {
                        $cols[$field] = true;
                    }
                }
                if (!$cols) {
                    $stmt = Flight::db()->prepare('SELECT COLUMN_NAME FROM ALL_TAB_COLUMNS WHERE UPPER(TABLE_NAME)=UPPER(:table_name) ORDER BY COLUMN_ID');
                    $stmt->bindValue(':table_name', $safeTable);
                    $stmt->execute();
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $field = trim((string)($row['COLUMN_NAME'] ?? ''));
                        if ($field !== '') {
                            $cols[$field] = true;
                        }
                    }
                }
            } else {
                $stmt = Flight::db()->prepare('SHOW COLUMNS FROM `' . $safeTable . '`');
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
        if (!$cols) {
            try {
                $stmt = Flight::db()->prepare('SHOW COLUMNS FROM `' . $safeTable . '`');
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $field = trim((string)($row['Field'] ?? ''));
                    if ($field !== '') {
                        $cols[$field] = true;
                    }
                }
            } catch (\Throwable $e) {
                $cols = [];
            }
        }
        $cache[$cacheKey] = $cols;
        return $cache[$cacheKey];
    }

    private static function dvAuthCrud()
    {
        return new CrudController('dcim-person');
    }

    private static function dvRequireAuth(array $data = [])
    {
        $user = self::dvAuthCrud()->legacyEnsureAuth($data);
        if (!$user) {
            L_E(tp_msg_login());
        }
        return $user;
    }

    private static function dvRequestData(): array
    {
        $data = Flight::request_data();
        if (!is_array($data)) {
            $data = [];
        }

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
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = array_merge($decoded, $data);
            }
        }

        $token = function_exists('dcim_extract_token') ? dcim_extract_token($data) : '';
        if ($token === '' && isset($data['token']) && is_scalar($data['token'])) {
            $token = trim((string) $data['token']);
        }
        if ($token !== '') {
            $data['token'] = $token;
        }

        return $data;
    }

    private static function dvBuildAlarmNotifyRows(string $protocolData, $devId): array
    {
        $rows = [];
        if ($protocolData === '') {
            return $rows;
        }
        if (strpos($protocolData, '|') !== false) {
            $parts = explode('|', $protocolData);
            foreach ($parts as $part) {
                $rows = array_merge($rows, self::dvBuildAlarmNotifyRowsOne($part, $devId));
            }
            return $rows;
        }
        return self::dvBuildAlarmNotifyRowsOne($protocolData, $devId);
    }

    private static function dvBuildAlarmNotifyRowsOne(string $protocolData, $devId): array
    {
        $rows = [];
        if (strpos($protocolData, ':') === false) {
            return $rows;
        }
        $type = explode('&', $protocolData)[0];
        $segments = explode(':', $protocolData);
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            $val = explode(',', $segment);
            if (count($val) <= 1) {
                continue;
            }
            if (count($val) > 9) {
                $tail = self::edExtractProtocolTail($val);
                $dataType = $tail['dataType'] ?? '';
                if ($dataType === '') {
                    $dataType = (strpos($type, 'B') !== false || strpos($type, 'C') !== false) ? '2' : '1';
                }
                $rows[] = self::dvBuildAlarmNotifyRow(
                    $val[1],
                    $devId,
                    $type,
                    $dataType,
                    $tail['alarmnum'] ?? '',
                    $tail['alarmup'] ?? '',
                    $tail['alarmdown'] ?? '',
                    $tail['alarmLevel'] ?? '',
                    $tail['alertPeriod'] ?? '',
                    $tail['alarmUserGroup'] ?? '',
                    $tail['alarmUpgradeGroup'] ?? ''
                );
            } else {
                if (strpos($type, 'B') !== false || strpos($type, 'C') !== false) {
                    $rows[] = self::dvBuildAlarmNotifyRow($val[1], $devId, $type, '2', $val[count($val) - 1]);
                } else {
                    $rows[] = self::dvBuildAlarmNotifyRow($val[1], $devId, $type, '1', '');
                }
            }
        }
        return $rows;
    }

    private static function dvBuildAlarmNotifyRow($key, $devId, $commandType, $dataType = '1', $alarmValue = '', $alarmUp = '', $alarmDown = '', $alarmLevel = '', $alertPeriod = '', $alarmUserGroup = '', $alarmUpgradeGroup = ''): array
    {
        $normalizedLevel = $alarmLevel;
        if ($normalizedLevel === '' || $normalizedLevel === null) {
            $normalizedLevel = 0;
        }
        return [
            'AlarmKey' => $key,
            'AlarmName' => $key,
            'DevId' => $devId,
            'AlarmValue' => $alarmValue,
            'AlarmUpLimit' => $alarmUp,
            'AlarmDownLimit' => $alarmDown,
            'CommandType' => $commandType,
            'DataType' => $dataType,
            'AlarmLevel' => $normalizedLevel,
            'AlertPeriod' => $alertPeriod,
            'UserID' => $alarmUserGroup,
            'UpgradeUser' => $alarmUpgradeGroup,
            'TogetherAlarm' => '',
        ];
    }

    private static function dvCreateDoorParam(array $device): void
    {
        try {
            $fields = [
                'SN' => $device['SerialNumber'] ?? '',
                'DevId' => (int)($device['id'] ?? 0),
            ];
            if (($device['ProtocolCode'] ?? '') === '14001') {
                $fields['attSync'] = 1;
            }
            self::dvCrud('dcim-doorparam')->legacyInsert($fields);
        } catch (\Throwable $e) {
            error_log('[CreateDeviceKey] insert door param failed: ' . $e->getMessage());
        }
    }

    private static function dvInsertSnmpCommand(array $device): void
    {
        try {
            self::dvCrud('dcim-devicecommand')->legacyInsert([
                'DevID' => (int)($device['id'] ?? 0),
                'CommandType' => '3',
                'CommandDesc' => 'SNMP read command',
                'Command' => $device['OID'] ?? '',
            ]);
        } catch (\Throwable $e) {
            error_log('[CreateDeviceKey] insert snmp command failed: ' . $e->getMessage());
        }
    }

    private static function dvCreateDeviceCommands(array $protocolRow, $devId, $devAddress): array
    {
        $insertData = [];
        if (!empty($protocolRow['ProtocolValue'])) {
            $proArr = explode('|', $protocolRow['ProtocolValue']);
            foreach ($proArr as $value) {
                if ($value === '') {
                    continue;
                }
                $protocolValue = explode(':', $value);
                $protocolValueEnd = end($protocolValue);
                if ($protocolValueEnd) {
                    if (strpos($protocolValueEnd, '$') !== false) {
                        $commStr = '';
                        $protocolValueEnd = substr($protocolValueEnd, 1);
                    } else {
                        $commStr = strval(dechex((int)$devAddress));
                    }
                    if (strlen($commStr) === 1) {
                        $commStr = '0' . $commStr;
                    }
                    $commStr .= $protocolValueEnd;
                    if (strpos($commStr, '~') !== false) {
                        $commStr = '~' . str_replace('~', '', $commStr);
                    }
                    if (strpos($commStr, '*') !== false) {
                        $commStr = substr($commStr, 0, -1);
                    } else {
                        if (strpos($commStr, '~') !== false) {
                            $commStr .= self::dvCrc16(substr($commStr, 1));
                        } else {
                            $commStr .= self::dvCrc16($commStr);
                        }
                    }
                } else {
                    $commStr = '';
                }
                $insertData[] = [
                    'DevID' => (int)$devId,
                    'Command' => $commStr,
                    'CommandType' => $protocolValue[0] ?? '',
                    'ProtocolCode' => $protocolRow['ProtocolCode'] ?? '',
                    'CommandDesc' => $protocolValue[1] ?? '',
                ];
            }
        }
        if (!empty($protocolRow['ProtocolCtrlValue'])) {
            $ctrlArr = explode('|', $protocolRow['ProtocolCtrlValue']);
            foreach ($ctrlArr as $value) {
                if ($value === '') {
                    continue;
                }
                $protocolValue = explode(':', $value);
                $protocolValueEnd = end($protocolValue);
                if (strpos($protocolValueEnd, '$') !== false) {
                    $commStr = '';
                    $protocolValueEnd = substr($protocolValueEnd, 1);
                } else {
                    $commStr = strval(dechex((int)$devAddress));
                }
                if (strlen($commStr) === 1) {
                    $commStr = '0' . $commStr;
                }
                $commStr .= $protocolValueEnd;
                if (strpos($commStr, '*') !== false) {
                    $commStr = substr($commStr, 0, -1);
                } elseif (strpos($commStr, '~') !== false) {
                    $commStr .= $commStr;
                } else {
                    $commStr .= self::dvCrc16($commStr);
                }
                $insertData[] = [
                    'DevID' => (int)$devId,
                    'Command' => $commStr,
                    'CommandType' => '2',
                    'ProtocolCode' => $protocolRow['ProtocolCode'] ?? '',
                    'CommandDesc' => $protocolValue[0] ?? '',
                ];
            }
        }
        return $insertData;
    }

    private static function dvStringValue($value): string
    {
        if (is_resource($value)) {
            $content = @stream_get_contents($value);
            return is_string($content) ? $content : '';
        }
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }
        return '';
    }

    private static function dvRowValue(array $row, string $key, $default = null)
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
        $target = strtolower($key);
        foreach ($row as $k => $value) {
            if (strtolower((string)$k) === $target) {
                return $value;
            }
        }
        return $default;
    }

    private static function dvBuildCommandByTemplate($template, $devAddress, $addrMode = '', $crcMode = ''): string
    {
        $requestTemplate = trim(self::dvStringValue($template));
        if ($requestTemplate === '') {
            return '';
        }

        $addrModeNorm = strtolower(trim(self::dvStringValue($addrMode)));
        if (strpos($requestTemplate, '$') !== false || in_array($addrModeNorm, ['none', 'no_addr', 'noaddr'], true)) {
            $commStr = '';
            if (strpos($requestTemplate, '$') !== false) {
                $requestTemplate = substr($requestTemplate, 1);
            }
        } else {
            $commStr = strval(dechex((int)$devAddress));
            if (strlen($commStr) === 1) {
                $commStr = '0' . $commStr;
            }
        }

        $commStr .= $requestTemplate;
        if (strpos($commStr, '~') !== false) {
            $commStr = '~' . str_replace('~', '', $commStr);
        }
        if (strpos($commStr, '*') !== false) {
            return substr($commStr, 0, -1);
        }

        $crcModeNorm = strtolower(trim(self::dvStringValue($crcMode)));
        if (in_array($crcModeNorm, ['none', 'no_crc', 'nocrc', 'disabled'], true)) {
            return $commStr;
        }

        if (strpos($commStr, '~') !== false) {
            return $commStr . self::dvCrc16(substr($commStr, 1));
        }
        return $commStr . self::dvCrc16($commStr);
    }

    private static function dvCreateDeviceCommandsFromProtocolDetail(string $protocolCode, $devId, $devAddress): array
    {
        $protocolCode = trim($protocolCode);
        if ($protocolCode === '') {
            return [];
        }

        try {
            $rows = self::dvCrud('dcim_protocol_command')->selectByRawCondition(
                'status = 1 AND ProtocolCode = :code',
                'ORDER BY SortNo ASC, id ASC',
                [':code' => $protocolCode]
            );
        } catch (\Throwable $e) {
            error_log('[CreateDeviceKey] load protocol v2 commands failed: ' . $e->getMessage());
            return [];
        }

        $insertData = [];
        foreach ($rows as $row) {
            $commandType = trim(self::dvStringValue(self::dvRowValue($row, 'CommandType', '')));
            if ($commandType === '') {
                continue;
            }
            $commStr = self::dvBuildCommandByTemplate(
                self::dvRowValue($row, 'RequestTemplate', ''),
                $devAddress,
                self::dvRowValue($row, 'AddrMode', ''),
                self::dvRowValue($row, 'CrcMode', '')
            );
            $insertData[] = [
                'DevID' => (int)$devId,
                'Command' => $commStr,
                'CommandType' => $commandType,
                'ProtocolCode' => $protocolCode,
                'CommandDesc' => self::dvStringValue(self::dvRowValue($row, 'CommandDesc', $commandType)),
            ];
        }

        return $insertData;
    }

    private static function dvBuildAlarmNotifyRowsFromProtocolDetail(string $protocolCode, $devId): array
    {
        $protocolCode = trim($protocolCode);
        if ($protocolCode === '') {
            return [];
        }

        try {
            $rows = self::dvCrud('dcim_protocol_alarmmode')->selectByRawCondition(
                'status = 1 AND ProtocolCode = :code',
                'ORDER BY id ASC',
                [':code' => $protocolCode]
            );
        } catch (\Throwable $e) {
            error_log('[CreateDeviceKey] load protocol v2 alarm modes failed: ' . $e->getMessage());
            return [];
        }

        $copyFields = [
            'AlarmType', 'AlarmKey', 'AlarmName', 'AlarmUpLimit', 'AlarmDownLimit', 'AlarmValue',
            'PhoneNotify', 'SMSNotify', 'WeixinNotify', 'WeComNotify', 'DingdingNotify', 'EmailNotify',
            'NoiseNotify', 'UserID', 'MasterID', 'ConfirmNum', 'NotifyNum', 'IntervalTime',
            'AlarmLevel', 'UpgradeTime', 'UpgradeUser', 'Linkage', 'CancelLinkage', 'snmpSource',
            'LinkVideoChannel', 'CommandType', 'DataType', 'TogetherAlarm', 'NotifyWindowID', 'status',
        ];
        $insertRows = [];
        foreach ($rows as $row) {
            $insert = [];
            foreach ($copyFields as $field) {
                $value = self::dvRowValue($row, $field, null);
                if ($value !== null) {
                    $insert[$field] = $value;
                }
            }
            $insert['DevId'] = (int)$devId;
            if (!isset($insert['TogetherAlarm'])) {
                $insert['TogetherAlarm'] = '';
            }
            if (!isset($insert['status'])) {
                $insert['status'] = 1;
            }
            $insertRows[] = $insert;
        }

        return $insertRows;
    }

    private static function dvCrc16($string, $length = 0)
    {
        $string = pack('H*', $string);
        $auchCRCHi = [
            0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0, 0x80, 0x41, 0x01, 0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81,
            0x40, 0x01, 0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81, 0x40, 0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0,
            0x80, 0x41, 0x01, 0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81, 0x40, 0x00, 0xC1, 0x81, 0x40, 0x01,
            0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0, 0x80, 0x41, 0x01, 0xC0, 0x80, 0x41,
            0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81, 0x40, 0x00, 0xC1, 0x81,
            0x40, 0x01, 0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0, 0x80, 0x41, 0x01, 0xC0,
            0x80, 0x41, 0x00, 0xC1, 0x81, 0x40, 0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0, 0x80, 0x41, 0x01,
            0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81, 0x40,
            0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0, 0x80, 0x41, 0x01, 0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81,
            0x40, 0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0,
            0x80, 0x41, 0x01, 0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81, 0x40, 0x00, 0xC1, 0x81, 0x40, 0x01,
            0xC0, 0x80, 0x41, 0x01, 0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0, 0x80, 0x41,
            0x00, 0xC1, 0x81, 0x40, 0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81,
            0x40, 0x01, 0xC0, 0x80, 0x41, 0x01, 0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0,
            0x80, 0x41, 0x00, 0xC1, 0x81, 0x40, 0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0, 0x80, 0x41, 0x01,
            0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81, 0x40, 0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0, 0x80, 0x41,
            0x00, 0xC1, 0x81, 0x40, 0x01, 0xC0, 0x80, 0x41, 0x01, 0xC0, 0x80, 0x41, 0x00, 0xC1, 0x81,
            0x40
        ];
        $auchCRCLo = [
            0x00, 0xC0, 0xC1, 0x01, 0xC3, 0x03, 0x02, 0xC2, 0xC6, 0x06, 0x07, 0xC7, 0x05, 0xC5, 0xC4,
            0x04, 0xCC, 0x0C, 0x0D, 0xCD, 0x0F, 0xCF, 0xCE, 0x0E, 0x0A, 0xCA, 0xCB, 0x0B, 0xC9, 0x09,
            0x08, 0xC8, 0xD8, 0x18, 0x19, 0xD9, 0x1B, 0xDB, 0xDA, 0x1A, 0x1E, 0xDE, 0xDF, 0x1F, 0xDD,
            0x1D, 0x1C, 0xDC, 0x14, 0xD4, 0xD5, 0x15, 0xD7, 0x17, 0x16, 0xD6, 0xD2, 0x12, 0x13, 0xD3,
            0x11, 0xD1, 0xD0, 0x10, 0xF0, 0x30, 0x31, 0xF1, 0x33, 0xF3, 0xF2, 0x32, 0x36, 0xF6, 0xF7,
            0x37, 0xF5, 0x35, 0x34, 0xF4, 0x3C, 0xFC, 0xFD, 0x3D, 0xFF, 0x3F, 0x3E, 0xFE, 0xFA, 0x3A,
            0x3B, 0xFB, 0x39, 0xF9, 0xF8, 0x38, 0x28, 0xE8, 0xE9, 0x29, 0xEB, 0x2B, 0x2A, 0xEA, 0xEE,
            0x2E, 0x2F, 0xEF, 0x2D, 0xED, 0xEC, 0x2C, 0xE4, 0x24, 0x25, 0xE5, 0x27, 0xE7, 0xE6, 0x26,
            0x22, 0xE2, 0xE3, 0x23, 0xE1, 0x21, 0x20, 0xE0, 0xA0, 0x60, 0x61, 0xA1, 0x63, 0xA3, 0xA2,
            0x62, 0x66, 0xA6, 0xA7, 0x67, 0xA5, 0x65, 0x64, 0xA4, 0x6C, 0xAC, 0xAD, 0x6D, 0xAF, 0x6F,
            0x6E, 0xAE, 0xAA, 0x6A, 0x6B, 0xAB, 0x69, 0xA9, 0xA8, 0x68, 0x78, 0xB8, 0xB9, 0x79, 0xBB,
            0x7B, 0x7A, 0xBA, 0xBE, 0x7E, 0x7F, 0xBF, 0x7D, 0xBD, 0xBC, 0x7C, 0xB4, 0x74, 0x75, 0xB5,
            0x77, 0xB7, 0xB6, 0x76, 0x72, 0xB2, 0xB3, 0x73, 0xB1, 0x71, 0x70, 0xB0, 0x50, 0x90, 0x91,
            0x51, 0x93, 0x53, 0x52, 0x92, 0x96, 0x56, 0x57, 0x97, 0x55, 0x95, 0x94, 0x54, 0x9C, 0x5C,
            0x5D, 0x9D, 0x5F, 0x9F, 0x9E, 0x5E, 0x5A, 0x9A, 0x9B, 0x5B, 0x99, 0x59, 0x58, 0x98, 0x88,
            0x48, 0x49, 0x89, 0x4B, 0x8B, 0x8A, 0x4A, 0x4E, 0x8E, 0x8F, 0x4F, 0x8D, 0x4D, 0x4C, 0x8C,
            0x44, 0x84, 0x85, 0x45, 0x87, 0x47, 0x46, 0x86, 0x82, 0x42, 0x43, 0x83, 0x41, 0x81, 0x80,
            0x40
        ];
        $length = ($length <= 0 ? strlen($string) : $length);
        $uchCRCHi = 0xFF;
        $uchCRCLo = 0xFF;
        for ($i = 0; $i < $length; $i++) {
            $uIndex = $uchCRCLo ^ ord(substr($string, $i, 1));
            $uchCRCLo = $uchCRCHi ^ $auchCRCHi[$uIndex];
            $uchCRCHi = $auchCRCLo[$uIndex];
        }
        $crc = (chr($uchCRCLo) . chr($uchCRCHi));
        return strtoupper(unpack('H*', $crc)[1]);
    }

    private static function dvInsertDeviceWithAlarms(array $data)
    {
        $crud = self::dvCrud('dcim-device');
        $id = $crud->legacyInsert($data);
        if (!$id) {
            return $id;
        }
        $device = $data;
        $device['id'] = $id;
        $linkMode = (string)($data['LinkMode'] ?? '');
        if ($linkMode === '3') {
            self::dvInsertSnmpCommand($device);
            self::dvInsertDeviceBroken($id);
        } else {
            if (!empty($data['ProtocolCode'])) {
                try {
                    $protocolRow = self::dvCrud('dcim-deviceprotocol')->findOne([
                        ['ProtocolCode', '=', $data['ProtocolCode']],
                        ['status', '=', 1],
                    ]) ?: [];
                    if ($protocolRow) {
                        $protocolCode = trim((string)($data['ProtocolCode'] ?? ''));
                        $commands = self::dvCreateDeviceCommandsFromProtocolDetail($protocolCode, $id, $data['DeviceAddress'] ?? 1);
                        if (!$commands) {
                            $commands = self::dvCreateDeviceCommands($protocolRow, $id, $data['DeviceAddress'] ?? 1);
                        }
                        if ($commands) {
                            $commandCrud = self::dvCrud('dcim-devicecommand');
                            foreach ($commands as $cmd) {
                                $commandCrud->legacyInsert($cmd);
                            }
                        }
                        $rows = self::dvBuildAlarmNotifyRowsFromProtocolDetail($protocolCode, $id);
                        if (!$rows) {
                            $protocolData = (string)($protocolRow['ProtocolData'] ?? '');
                            $rows = self::dvBuildAlarmNotifyRows($protocolData, $id);
                        }
                        if ($rows) {
                            $alarmModeCrud = self::dvCrud('dcim-alarmnotifymode');
                            foreach ($rows as $row) {
                                $alarmModeCrud->legacyInsert($row);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('[CreateDeviceKey] insert alarm rows failed: ' . $e->getMessage());
                }
            }
            self::dvInsertDeviceBroken($id);
        }
        if ((string)($data['DeviceClass'] ?? '') === '14') {
            self::dvCreateDoorParam($device);
        }
        return $id;
    }

    private static function dvInsertDeviceBroken($devId): void
    {
        try {
            $devId = (int)$devId;
            if ($devId <= 0) {
                return;
            }
            $alarmCrud = self::dvCrud('dcim-alarmnotifymode');
            $exists = $alarmCrud->findOne([
                ['DevId', '=', $devId],
                ['AlarmType', '=', 5],
                ['status', '=', 1],
            ]);
            if ($exists) {
                return;
            }
            $alarmCrud->legacyInsert([
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
            error_log('[CreateDeviceKey] insert broken alarm failed: ' . $e->getMessage());
        }
    }

    // POST /CreateDeviceKey
    public static function deviceInfoAdd()
    {
        $data = Flight::request_data();
        self::dvRequireAuth($data);
        if (!isset($data['status'])) {
            $data['status'] = 1;
        }
        $endAddressRaw = $data['DeviceAddress1'] ?? '';
        if ($endAddressRaw !== '' && $endAddressRaw !== null) {
            $startAddress = isset($data['DeviceAddress']) ? (int)$data['DeviceAddress'] : null;
            $endAddress = (int)$endAddressRaw;
            unset($data['DeviceAddress1']);
            $baseName = $data['DeviceName'] ?? '';
            $ids = [];
            if ($startAddress !== null && $endAddress >= $startAddress) {
                for ($i = $startAddress; $i <= $endAddress; $i++) {
                    $row = $data;
                    $row['DeviceAddress'] = $i;
                    if ($baseName !== '') {
                        $row['DeviceName'] = $baseName . '-' . $i;
                    }
                    $ids[] = self::dvInsertDeviceWithAlarms($row);
                }
            } else {
                $ids[] = self::dvInsertDeviceWithAlarms($data);
            }
            $ids = array_values(array_filter($ids, static function ($val) {
                return $val !== false && $val !== null && $val !== '';
            }));
            O_E(['ids' => $ids], tp_msg_success(), 100, false);
            return;
        }
        unset($data['DeviceAddress1']);
        $id = self::dvInsertDeviceWithAlarms($data);
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    private static function dvAttachDeployToDeviceRows(array &$rows): void
    {
        if (!$rows) {
            return;
        }
        $deviceIds = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id !== '') {
                $deviceIds[$id] = true;
            }
        }
        if (!$deviceIds) {
            return;
        }
        $deployMap = [];
        foreach (array_keys($deviceIds) as $devId) {
            $latest = self::dvCrud('dcim-alarmnotifymode')->selectByRawCondition(
                'status <> -1 AND DevId = :did',
                'ORDER BY id DESC LIMIT 1',
                [':did' => $devId]
            );
            $deployMap[$devId] = isset($latest[0]['status']) ? (string)$latest[0]['status'] : '1';
        }
        foreach ($rows as &$row) {
            $id = trim((string)($row['id'] ?? ''));
            $row['Deploy'] = $deployMap[$id] ?? '1';
        }
        unset($row);
    }

    private static function dvBuildInCondition(string $field, array $values, string $prefix, array &$params): ?string
    {
        $uniq = [];
        foreach ($values as $value) {
            $key = trim((string)$value);
            if ($key !== '') {
                $uniq[$key] = true;
            }
        }
        $keys = array_keys($uniq);
        if (!$keys) {
            return null;
        }
        $holders = [];
        foreach ($keys as $idx => $key) {
            $ph = ':' . $prefix . $idx;
            $holders[] = $ph;
            $params[$ph] = $key;
        }
        return $field . ' IN (' . implode(', ', $holders) . ')';
    }

    private static function dvAttachDeviceRuntimeFields(array &$rows): void
    {
        if (!$rows) {
            return;
        }

        $deviceIds = [];
        $protocolCodes = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id !== '') {
                $deviceIds[$id] = true;
            }
            $protocolCode = trim((string)($row['ProtocolCode'] ?? ''));
            if ($protocolCode !== '') {
                $protocolCodes[$protocolCode] = true;
            }
        }
        if (!$deviceIds) {
            return;
        }

        $deviceDataParts = [];
        $deviceDataArrMap = [];
        $commandDataMap = [];

        $cmdParams = [];
        $cmdIn = self::dvBuildInCondition('DevID', array_keys($deviceIds), 'did_', $cmdParams);
        if ($cmdIn !== null) {
            $nonControlWhere = 'status = 1 AND (' . $cmdIn . ') AND (CommandType <> :ctype OR CommandType IS NULL)';
            $nonControlParams = $cmdParams;
            $nonControlParams[':ctype'] = '2';
            $nonControlRows = self::dvCrud('dcim-devicecommand')->selectByRawCondition($nonControlWhere, 'ORDER BY id ASC', $nonControlParams);
            foreach ($nonControlRows as $cmdRow) {
                $devId = trim((string)($cmdRow['DevID'] ?? ''));
                if ($devId === '') {
                    continue;
                }
                $lastReceiveData = (string)($cmdRow['LastReceiveData'] ?? '');
                if ($lastReceiveData === '' || strpos($lastReceiveData, '{') === false || $lastReceiveData === '{}') {
                    continue;
                }
                $inner = substr($lastReceiveData, 1, -1);
                if ($inner !== '') {
                    $deviceDataParts[$devId][] = $inner;
                }
                $deviceDataArrMap[$devId][] = [
                    'cmdType' => (string)($cmdRow['CommandType'] ?? ''),
                    'data' => $lastReceiveData,
                ];
            }

            $controlWhere = 'status = 1 AND (' . $cmdIn . ') AND CommandType = :ctype';
            $controlParams = $cmdParams;
            $controlParams[':ctype'] = '2';
            $controlRows = self::dvCrud('dcim-devicecommand')->selectByRawCondition($controlWhere, 'ORDER BY id ASC', $controlParams);
            foreach ($controlRows as $cmdRow) {
                $devId = trim((string)($cmdRow['DevID'] ?? ''));
                $command = trim((string)($cmdRow['Command'] ?? ''));
                $commandDesc = trim((string)($cmdRow['CommandDesc'] ?? ''));
                if ($devId === '' || $command === '' || $commandDesc === '') {
                    continue;
                }
                $commandDataMap[$devId][] = [
                    'label' => $commandDesc,
                    'value' => $command,
                    'id' => $cmdRow['id'] ?? '',
                ];
            }
        }

        $protocolNameMap = [];
        if ($protocolCodes) {
            $protocolRows = self::dvCrud('dcim-deviceprotocol')->selectByIds(
                array_keys($protocolCodes),
                ['ProtocolCode', 'ProtocolName'],
                'ProtocolCode'
            );
            foreach ($protocolRows as $protocolRow) {
                $code = trim((string)($protocolRow['ProtocolCode'] ?? ''));
                if ($code !== '') {
                    $protocolNameMap[$code] = (string)($protocolRow['ProtocolName'] ?? '');
                }
            }
        }

        foreach ($rows as &$row) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') {
                $row['DeviceLastData'] = '';
                $row['DeviceLastDataArr'] = [];
                $row['CommandData'] = [];
                $row['ProtocolName'] = '';
                continue;
            }

            $parts = $deviceDataParts[$id] ?? [];
            $row['DeviceLastData'] = $parts ? ('{' . implode(',', $parts) . '}') : '';
            $row['DeviceLastDataArr'] = $deviceDataArrMap[$id] ?? [];
            $row['CommandData'] = $commandDataMap[$id] ?? [];
            $protocolCode = trim((string)($row['ProtocolCode'] ?? ''));
            $row['ProtocolName'] = $protocolNameMap[$protocolCode] ?? '';
        }
        unset($row);
    }

    // POST /GetDeviceListKey
    public static function deviceGetList()
    {
        $data = Flight::request_data();
        $comboAll = strtolower(trim((string)($data['ComboBox'] ?? ''))) === 'all';
        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;
        $offset = ($page - 1) * $pageSize;

        $getTableColumns = static function (string $table): array {
            return self::dvTableColumns($table);
        };
        $viewColumns = $getTableColumns('vw_device_list');
        $legacyColumns = $getTableColumns('dcim-device');
        $viewStatusField = isset($viewColumns['status']) ? 'status' : '';
        $legacyStatusField = isset($legacyColumns['status']) ? 'status' : '';
        $viewWhereParts = [$viewStatusField !== '' ? ($viewStatusField . ' = 1') : '1=1'];
        $legacyWhereParts = [$legacyStatusField !== '' ? ($legacyStatusField . ' = 1') : '1=1'];
        $params = [];
        $serverFilterInputs = [];
        $serverFilterIds = [];
        $serverFilterCodes = [];
        $serverColumnCandidates = ['ServerCode', 'ServerID', 'ServerId', 'serverCode', 'server_id'];
        $pickExistingServerColumns = static function (array $columnMap) use ($serverColumnCandidates): array {
            $out = [];
            $normMap = [];
            foreach ($columnMap as $col => $_exists) {
                $norm = strtolower(str_replace(['_', '-'], '', (string)$col));
                if ($norm !== '' && !isset($normMap[$norm])) {
                    $normMap[$norm] = (string)$col;
                }
            }
            foreach ($serverColumnCandidates as $candidateServerCol) {
                if (isset($columnMap[$candidateServerCol])) {
                    $out[] = $candidateServerCol;
                    continue;
                }
                $norm = strtolower(str_replace(['_', '-'], '', (string)$candidateServerCol));
                if ($norm !== '' && isset($normMap[$norm])) {
                    $out[] = $normMap[$norm];
                }
            }
            $out = array_values(array_unique($out));
            if (!$out) {
                $out[] = 'ServerCode';
            }
            return $out;
        };
        $pickServerNameColumns = static function (array $columnMap): array {
            $nameCandidates = ['ServerName', 'serverName', 'server_name', 'Name', 'name'];
            $out = [];
            $normMap = [];
            foreach ($columnMap as $col => $_exists) {
                $norm = strtolower(str_replace(['_', '-'], '', (string)$col));
                if ($norm !== '' && !isset($normMap[$norm])) {
                    $normMap[$norm] = (string)$col;
                }
            }
            foreach ($nameCandidates as $candidate) {
                if (isset($columnMap[$candidate])) {
                    $out[] = $candidate;
                    continue;
                }
                $norm = strtolower(str_replace(['_', '-'], '', (string)$candidate));
                if ($norm !== '' && isset($normMap[$norm])) {
                    $out[] = $normMap[$norm];
                }
            }
            return array_values(array_unique($out));
        };
        $viewServerColumns = $pickExistingServerColumns($viewColumns);
        $legacyServerColumns = $pickExistingServerColumns($legacyColumns);
        $serverTableColumns = $getTableColumns('dcim-server');
        $serverCodeLookupColumns = $pickExistingServerColumns($serverTableColumns);
        $serverNameLookupColumns = $pickServerNameColumns($serverTableColumns);
        $serverStatusColumn = null;
        foreach ($serverTableColumns as $colName => $_exists) {
            if (strtolower(str_replace(['_', '-'], '', (string)$colName)) === 'status') {
                $serverStatusColumn = (string)$colName;
                break;
            }
        }
        $queryServersByInput = static function (string $input, bool $like) use ($serverCodeLookupColumns, $serverNameLookupColumns, $serverStatusColumn): array {
            $input = trim((string)$input);
            if ($input === '') {
                return [];
            }
            $params = [];
            $conds = ['id = :sid'];
            $params[':sid'] = $input;
            foreach ($serverCodeLookupColumns as $idx => $col) {
                $ph = ':scode' . $idx;
                if ($like) {
                    $conds[] = $col . ' LIKE ' . $ph;
                    $params[$ph] = '%' . $input . '%';
                } else {
                    $conds[] = $col . ' = ' . $ph;
                    $params[$ph] = $input;
                }
            }
            foreach ($serverNameLookupColumns as $idx => $col) {
                $ph = ':sname' . $idx;
                if ($like) {
                    $conds[] = $col . ' LIKE ' . $ph;
                    $params[$ph] = '%' . $input . '%';
                } else {
                    $conds[] = $col . ' = ' . $ph;
                    $params[$ph] = $input;
                }
            }
            if (!$conds) {
                return [];
            }
            $whereSql = '(' . implode(' OR ', $conds) . ')';
            if ($serverStatusColumn !== null && !$like) {
                $whereSql .= ' AND (' . $serverStatusColumn . ' <> -1 OR ' . $serverStatusColumn . ' IS NULL)';
            } elseif ($serverStatusColumn !== null && $like) {
                $whereSql .= ' AND (' . $serverStatusColumn . ' <> -1 OR ' . $serverStatusColumn . ' IS NULL)';
            }
            try {
                return self::dvCrud('dcim-server')->selectByRawCondition($whereSql, '', $params);
            } catch (\Throwable $e) {
                return [];
            }
        };
        if (!empty($data['search'])) {
            $viewWhereParts[] = 'DeviceName LIKE :search';
            $legacyWhereParts[] = 'DeviceName LIKE :search';
            $params[':search'] = '%' . $data['search'] . '%';
        }
        if (!empty($data['AreaId'])) {
            $viewWhereParts[] = 'AreaId = :areaId';
            $legacyWhereParts[] = 'AreaId = :areaId';
            $params[':areaId'] = $data['AreaId'];
        }
        if (!empty($data['ServerCode'])) {
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
                if (preg_match('/^\d+$/', (string)$oneInput) === 1) {
                    $serverIds[(string)$oneInput] = true;
                } else {
                    $serverCodes[(string)$oneInput] = true;
                }
            }

            // Rule: numeric input means dcim-server.id; non-numeric input means dcim-server.ServerCode.
            // Resolve both id/code keys from server table so mixed storage in device table can still match.
            if ($serverIds || $serverCodes) {
                $resolvedRows = [];
                $resolvedParams = [];
                $resolvedConds = [];
                $seq = 0;
                if ($serverIds) {
                    $idPhs = [];
                    foreach (array_keys($serverIds) as $sid) {
                        $ph = ':sid_' . $seq++;
                        $idPhs[] = $ph;
                        $resolvedParams[$ph] = $sid;
                    }
                    if ($idPhs) {
                        $resolvedConds[] = 'id IN (' . implode(', ', $idPhs) . ')';
                    }
                }
                if ($serverCodes) {
                    foreach ($serverCodeLookupColumns as $serverCodeLookupCol) {
                        $codePhs = [];
                        foreach (array_keys($serverCodes) as $scode) {
                            $ph = ':scode_' . $seq++;
                            $codePhs[] = $ph;
                            $resolvedParams[$ph] = $scode;
                        }
                        if ($codePhs) {
                            $resolvedConds[] = $serverCodeLookupCol . ' IN (' . implode(', ', $codePhs) . ')';
                        }
                    }
                }
                if ($resolvedConds) {
                    $resolvedWhereSql = '(' . implode(' OR ', $resolvedConds) . ')';
                    if ($serverStatusColumn !== null) {
                        $resolvedWhereSql .= ' AND (' . $serverStatusColumn . ' <> -1 OR ' . $serverStatusColumn . ' IS NULL)';
                    }
                    try {
                        $resolvedRows = self::dvCrud('dcim-server')->selectByRawCondition($resolvedWhereSql, '', $resolvedParams);
                    } catch (\Throwable $e) {
                        if ($serverStatusColumn !== null) {
                            $resolvedWhereSql = '(' . implode(' OR ', $resolvedConds) . ')';
                            $resolvedRows = self::dvCrud('dcim-server')->selectByRawCondition($resolvedWhereSql, '', $resolvedParams);
                        } else {
                            $resolvedRows = [];
                        }
                    }
                }
                foreach ($resolvedRows as $srv) {
                    $sid = isset($srv['id']) ? trim((string)$srv['id']) : '';
                    $scode = '';
                    foreach ($serverCodeLookupColumns as $serverCodeLookupCol) {
                        if (isset($srv[$serverCodeLookupCol]) && trim((string)$srv[$serverCodeLookupCol]) !== '') {
                            $scode = trim((string)$srv[$serverCodeLookupCol]);
                            break;
                        }
                    }
                    if ($sid !== '') {
                        $serverIds[$sid] = true;
                    }
                    if ($scode !== '') {
                        $serverCodes[$scode] = true;
                    }
                }
            }
            $serverFilterInputs = array_values(array_unique(array_merge($serverFilterInputs, array_keys($serverIds), array_keys($serverCodes))));

            $viewServerConds = [];
            $legacyServerConds = [];
            $serverParamSeq = 0;

            if (!empty($serverIds)) {
                $serverIdVals = array_keys($serverIds);
                foreach ($viewServerColumns as $viewCol) {
                    $sidPhs = [];
                    foreach ($serverIdVals as $sid) {
                        $ph = ':serverId_' . $serverParamSeq++;
                        $sidPhs[] = $ph;
                        $params[$ph] = $sid;
                    }
                    if ($sidPhs) {
                        $viewServerConds[] = $viewCol . ' IN (' . implode(', ', $sidPhs) . ')';
                    }
                }
                foreach ($legacyServerColumns as $legacyCol) {
                    $sidPhs = [];
                    foreach ($serverIdVals as $sid) {
                        $ph = ':serverId_' . $serverParamSeq++;
                        $sidPhs[] = $ph;
                        $params[$ph] = $sid;
                    }
                    if ($sidPhs) {
                        $legacyServerConds[] = $legacyCol . ' IN (' . implode(', ', $sidPhs) . ')';
                    }
                }
            }
            if (!empty($serverCodes)) {
                $serverCodeVals = array_keys($serverCodes);
                foreach ($viewServerColumns as $viewCol) {
                    $scodePhs = [];
                    foreach ($serverCodeVals as $scode) {
                        $ph = ':serverCode_' . $serverParamSeq++;
                        $scodePhs[] = $ph;
                        $params[$ph] = $scode;
                    }
                    if ($scodePhs) {
                        $viewServerConds[] = $viewCol . ' IN (' . implode(', ', $scodePhs) . ')';
                    }
                }
                foreach ($legacyServerColumns as $legacyCol) {
                    $scodePhs = [];
                    foreach ($serverCodeVals as $scode) {
                        $ph = ':serverCode_' . $serverParamSeq++;
                        $scodePhs[] = $ph;
                        $params[$ph] = $scode;
                    }
                    if ($scodePhs) {
                        $legacyServerConds[] = $legacyCol . ' IN (' . implode(', ', $scodePhs) . ')';
                    }
                }
            }

            if ($viewServerConds) {
                $viewWhereParts[] = '(' . implode(' OR ', array_values(array_unique($viewServerConds))) . ')';
            }
            if ($legacyServerConds) {
                $legacyWhereParts[] = '(' . implode(' OR ', array_values(array_unique($legacyServerConds))) . ')';
            }
            $serverFilterIds = array_keys($serverIds);
            $serverFilterCodes = array_keys($serverCodes);
        }
        if (!empty($data['ClassName'])) {
            $viewWhereParts[] = 'ClassName = :className';
            $params[':className'] = $data['ClassName'];
        }
        if (!empty($data['DeviceClass'])) {
            $viewWhereParts[] = 'DeviceClass = :deviceClass';
            $legacyWhereParts[] = 'DeviceClass = :deviceClass';
            $params[':deviceClass'] = $data['DeviceClass'];
        }
        if (!empty($data['ProtocolCode'])) {
            $viewWhereParts[] = 'ProtocolCode = :protocolCode';
            $legacyWhereParts[] = 'ProtocolCode = :protocolCode';
            $params[':protocolCode'] = $data['ProtocolCode'];
        }
        $viewWhereSql = implode(' AND ', $viewWhereParts);
        $filterSqlParams = static function (string $sql, array $sourceParams): array {
            if ($sql === '') {
                return [];
            }
            preg_match_all('/:([A-Za-z0-9_]+)/', $sql, $matched);
            $used = [];
            foreach (($matched[1] ?? []) as $name) {
                $used[':' . $name] = true;
            }
            if (!$used) {
                return [];
            }
            return array_intersect_key($sourceParams, $used);
        };
        $viewParams = $filterSqlParams($viewWhereSql, $params);
        $strictFilterServerRows = static function (array $rows, array $serverFields) use ($serverFilterInputs, $serverFilterIds, $serverFilterCodes, $queryServersByInput, $serverCodeLookupColumns): array {
            if (!$serverFilterInputs && !$serverFilterIds && !$serverFilterCodes) {
                return $rows;
            }
            $allow = [];
            $allowLower = [];
            $allowRawList = [];
            foreach (array_merge($serverFilterInputs, $serverFilterIds, $serverFilterCodes) as $val) {
                $v = trim((string)$val);
                if ($v !== '') {
                    $allow[$v] = true;
                    $allowLower[strtolower($v)] = true;
                    $allowRawList[] = $v;
                }
            }
            if (!$allow) {
                return $rows;
            }
            $serverKeys = [];
            foreach ($rows as $row) {
                $candidateValues = [];
                foreach ($serverFields as $serverField) {
                    $val = trim((string)($row[$serverField] ?? ''));
                    if ($val !== '') {
                        $candidateValues[$val] = true;
                    }
                }
                foreach (['ServerCode', 'serverCode', 'ServerID', 'ServerId', 'server_id'] as $fallbackField) {
                    $val = trim((string)($row[$fallbackField] ?? ''));
                    if ($val !== '') {
                        $candidateValues[$val] = true;
                    }
                }
                foreach (array_keys($candidateValues) as $sk) {
                    $serverKeys[$sk] = true;
                }
            }
            $serverByLookup = [];
            if ($serverKeys) {
                $serverRows = self::dvCrud('dcim-server')->selectByIds(array_keys($serverKeys), ['id', 'ServerCode', 'ServerName']);
                $missingKeys = $serverKeys;
                foreach ($serverRows as $srv) {
                    $idKey = trim((string)($srv['id'] ?? ''));
                    $codeKey = trim((string)($srv['ServerCode'] ?? ''));
                    if ($idKey !== '') {
                        $serverByLookup[$idKey] = $srv;
                        unset($missingKeys[$idKey]);
                    }
                    if ($codeKey !== '') {
                        $serverByLookup[$codeKey] = $srv;
                        unset($missingKeys[$codeKey]);
                    }
                }
                if (!empty($missingKeys)) {
                    $phs = [];
                    $rawParams = [];
                    $idx = 0;
                    foreach (array_keys($missingKeys) as $keyVal) {
                        $ph = ':sk_' . $idx++;
                        $phs[] = $ph;
                        $rawParams[$ph] = $keyVal;
                    }
                    if ($phs) {
                        // Avoid reusing named placeholders in two IN clauses (can trigger HY093 on some PDO drivers).
                        $idPhs = [];
                        $codePhs = [];
                        $queryParams = [];
                        $seq = 0;
                        foreach ($rawParams as $value) {
                            $idPh = ':sid_' . $seq;
                            $codePh = ':scode_' . $seq;
                            $idPhs[] = $idPh;
                            $codePhs[] = $codePh;
                            $queryParams[$idPh] = $value;
                            $queryParams[$codePh] = $value;
                            $seq++;
                        }
                        try {
                            $extraRows = self::dvCrud('dcim-server')->selectByRawCondition(
                                '(id IN (' . implode(',', $idPhs) . ') OR ServerCode IN (' . implode(',', $codePhs) . ')) AND status <> -1',
                                '',
                                $queryParams
                            );
                        } catch (\Throwable $e) {
                            $extraRows = self::dvCrud('dcim-server')->selectByRawCondition(
                                '(id IN (' . implode(',', $idPhs) . ') OR ServerCode IN (' . implode(',', $codePhs) . '))',
                                '',
                                $queryParams
                            );
                        }
                        foreach ($extraRows as $srv) {
                            $idKey = trim((string)($srv['id'] ?? ''));
                            $codeKey = trim((string)($srv['ServerCode'] ?? ''));
                            if ($idKey !== '') {
                                $serverByLookup[$idKey] = $srv;
                            }
                            if ($codeKey !== '') {
                                $serverByLookup[$codeKey] = $srv;
                            }
                        }
                    }
                }
            }
            if (!$serverByLookup && $allowRawList) {
                foreach ($allowRawList as $idx => $needle) {
                    $extraRows = $queryServersByInput((string)$needle, false);
                    foreach ($extraRows as $srv) {
                        $idKey = trim((string)($srv['id'] ?? ''));
                        $codeKey = '';
                        foreach ($serverCodeLookupColumns as $serverCodeLookupCol) {
                            if (isset($srv[$serverCodeLookupCol]) && trim((string)$srv[$serverCodeLookupCol]) !== '') {
                                $codeKey = trim((string)$srv[$serverCodeLookupCol]);
                                break;
                            }
                        }
                        if ($idKey !== '') {
                            $serverByLookup[$idKey] = $srv;
                        }
                        if ($codeKey !== '') {
                            $serverByLookup[$codeKey] = $srv;
                        }
                    }
                }
            }
            $out = [];
            foreach ($rows as $row) {
                $rowKeys = [];
                foreach ($serverFields as $serverField) {
                    $val = trim((string)($row[$serverField] ?? ''));
                    if ($val !== '') {
                        $rowKeys[$val] = true;
                    }
                }
                foreach (['ServerCode', 'serverCode', 'ServerID', 'ServerId', 'server_id'] as $fallbackField) {
                    $val = trim((string)($row[$fallbackField] ?? ''));
                    if ($val !== '') {
                        $rowKeys[$val] = true;
                    }
                }
                $matched = false;
                foreach (array_keys($rowKeys) as $serverKey) {
                    $srv = $serverByLookup[$serverKey] ?? [];
                    $serverId = trim((string)($srv['id'] ?? ''));
                    $serverCode = trim((string)($srv['ServerCode'] ?? $serverKey));
                    if (
                        isset($allow[$serverKey]) ||
                        isset($allowLower[strtolower($serverKey)]) ||
                        ($serverId !== '' && isset($allow[$serverId])) ||
                        ($serverId !== '' && isset($allowLower[strtolower($serverId)])) ||
                        ($serverCode !== '' && isset($allow[$serverCode])) ||
                        ($serverCode !== '' && isset($allowLower[strtolower($serverCode)]))
                    ) {
                        $matched = true;
                        break;
                    }
                }
                if ($matched) {
                    $out[] = $row;
                }
            }
            return $out;
        };
        if (self::dvCanTryView('vw_device_list')) {
            try {
                if ($comboAll) {
                    $rows = self::dvCrud('vw_device_list')->selectByRawCondition(
                        $viewWhereSql,
                        'ORDER BY id DESC',
                        $viewParams
                    );
                    $rows = $strictFilterServerRows(is_array($rows) ? $rows : [], $viewServerColumns);
                    self::dvAttachDeviceRuntimeFields($rows);
                    self::dvAttachDeployToDeviceRows($rows);
                    O_E($rows, tp_msg_success(), 100, 0);
                } else {
                    $result = self::dvCrud('vw_device_list')->selectWithPagination(
                        $viewWhereSql,
                        $viewParams,
                        '',
                        $page,
                        $pageSize
                    );
                    if (isset($result['info']) && is_array($result['info'])) {
                        $result['info'] = $strictFilterServerRows($result['info'], $viewServerColumns);
                        self::dvAttachDeviceRuntimeFields($result['info']);
                        self::dvAttachDeployToDeviceRows($result['info']);
                    }
                    O_E($result, tp_msg_success(), 100, 0);
                }
                return;
            } catch (Throwable $e) {
                self::dvLogViewFallback('GetDeviceListKey', 'vw_device_list', $e);
            }
        }

        $legacyWhereSql = implode(' AND ', $legacyWhereParts);
        $legacyParams = $filterSqlParams($legacyWhereSql, $params);

        if (!empty($data['ClassName'])) {
            $classRows = self::dvCrud('dcim-deviceclass')->selectByRawCondition(
                'ClassName = :className',
                '',
                [':className' => $data['ClassName']]
            );
            $classIds = array_values(array_filter(array_map(static function ($r) {
                return isset($r['id']) ? (string) $r['id'] : null;
            }, $classRows)));
            if (!$classIds) {
                if ($comboAll) {
                    O_E([], tp_msg_success(), 100, 0);
                } else {
                    O_E(['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => $page]], tp_msg_success(), 100, 0);
                }
                return;
            }
            $classPhs = [];
            foreach ($classIds as $idx => $classId) {
                $ph = ':cls_' . $idx;
                $classPhs[] = $ph;
                $legacyParams[$ph] = $classId;
            }
            $legacyWhereSql .= ' AND DeviceClass IN (' . implode(', ', $classPhs) . ')';
        }

        if ($comboAll) {
            $rows = self::dvCrud('dcim-device')->selectByRawCondition(
                $legacyWhereSql,
                'ORDER BY id DESC',
                $legacyParams
            );
            $rows = $strictFilterServerRows(is_array($rows) ? $rows : [], $legacyServerColumns);
            $result = ['info' => $rows, 'page' => ['total' => count($rows), 'p_n' => 1, 'p' => 1]];
        } else {
            $result = self::dvCrud('dcim-device')->selectByRawConditionWithPagination(
                $legacyWhereSql,
                $legacyParams,
                '',
                $page,
                $pageSize
            );
            $rows = $result['info'] ?? [];
            $rows = $strictFilterServerRows(is_array($rows) ? $rows : [], $legacyServerColumns);
        }

        $areaIds = [];
        $classIds = [];
        $serverKeys = [];
        foreach ($rows as $row) {
            if (isset($row['AreaId']) && $row['AreaId'] !== null && $row['AreaId'] !== '') {
                $areaIds[(string)$row['AreaId']] = true;
            }
            if (isset($row['DeviceClass']) && $row['DeviceClass'] !== null && $row['DeviceClass'] !== '') {
                $classIds[(string)$row['DeviceClass']] = true;
            }
            foreach ($legacyServerColumns as $legacyServerColumn) {
                $serverCodeValue = $row[$legacyServerColumn] ?? null;
                if ($serverCodeValue !== null && $serverCodeValue !== '') {
                    $serverKeys[(string)$serverCodeValue] = true;
                }
            }
        }

        $areaMap = [];
        if (!empty($areaIds)) {
            $areaRows = self::dvCrud('dcim-area')->selectByIds(
                array_keys($areaIds),
                ['id', 'AreaName', 'AreaLevel']
            );
            foreach ($areaRows as $item) {
                $areaMap[(string)$item['id']] = $item;
            }
        }

        $classMap = [];
        if (!empty($classIds)) {
            $classRows = self::dvCrud('dcim-deviceclass')->selectByIds(
                array_keys($classIds),
                ['id', 'ClassName']
            );
            foreach ($classRows as $item) {
                $classMap[(string)$item['id']] = $item['ClassName'] ?? '';
            }
        }

        $serverById = [];
        $serverByCode = [];
        if (!empty($serverKeys)) {
            $keys = array_keys($serverKeys);
            $serverRowsById = self::dvCrud('dcim-server')->selectByIds(
                $keys,
                ['id', 'ServerCode', 'ServerName', 'ServerIP']
            );
            foreach ($serverRowsById as $srv) {
                $idKey = isset($srv['id']) ? (string) $srv['id'] : '';
                $codeKey = isset($srv['ServerCode']) ? (string) $srv['ServerCode'] : '';
                if ($idKey !== '') {
                    $serverById[$idKey] = $srv;
                }
                if ($codeKey !== '') {
                    $serverByCode[$codeKey] = $srv;
                }
            }
            $serverRowsByCode = self::dvCrud('dcim-server')->selectByIds(
                $keys,
                ['id', 'ServerCode', 'ServerName', 'ServerIP'],
                'ServerCode'
            );
            foreach ($serverRowsByCode as $srv) {
                $idKey = isset($srv['id']) ? (string)$srv['id'] : '';
                $codeKey = isset($srv['ServerCode']) ? (string)$srv['ServerCode'] : '';
                if ($idKey !== '') {
                    $serverById[$idKey] = $srv;
                }
                if ($codeKey !== '') {
                    $serverByCode[$codeKey] = $srv;
                }
            }
        }

        foreach ($rows as &$row) {
            $area = $areaMap[(string)($row['AreaId'] ?? '')] ?? [];
            $row['AreaName'] = $area['AreaName'] ?? '';
            $row['AreaLevel'] = $area['AreaLevel'] ?? '';
            $row['ClassName'] = $classMap[(string)($row['DeviceClass'] ?? '')] ?? '';
            $serverKey = '';
            foreach ($legacyServerColumns as $serverColName) {
                $candidateServerKey = trim((string)($row[$serverColName] ?? ''));
                if ($candidateServerKey !== '') {
                    $serverKey = $candidateServerKey;
                    break;
                }
            }
            if ($serverKey === '') {
                $serverKey = (string)($row['ServerCode'] ?? '');
            }
            $srv = $serverById[$serverKey] ?? ($serverByCode[$serverKey] ?? []);
            $row['ServerName'] = $srv['ServerName'] ?? '';
            $row['ServerIP'] = $srv['ServerIP'] ?? '';
        }
        unset($row);

        self::dvAttachDeviceRuntimeFields($rows);
        self::dvAttachDeployToDeviceRows($rows);
        $result['info'] = $rows;
        if ($comboAll) {
            O_E($rows, tp_msg_success(), 100, 0);
        } else {
            O_E($result, tp_msg_success(), 100, 0);
        }
    }

    // POST /GetDeviceDetailKey
    public static function deviceGetInfo()
    {
        $data = Flight::request_data();
        $info = self::dvCrud('dcim-device')->legacyInfo($data, [
            'skip_auth' => true,
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        if (is_array($info) && $info) {
            $areaId = trim((string)($info['AreaId'] ?? ''));
            if ($areaId !== '') {
                $areaRows = self::dvCrud('dcim-area')->selectByIds([$areaId], ['id', 'AreaName']);
                if ($areaRows) {
                    $info['AreaName'] = (string)($areaRows[0]['AreaName'] ?? ($info['AreaName'] ?? ''));
                }
            }

            $serverKey = trim((string)($info['ServerCode'] ?? ''));
            if ($serverKey !== '') {
                $serverRows = self::dvCrud('dcim-server')->selectByIds([$serverKey], ['id', 'ServerCode', 'ServerName'], 'ServerCode');
                if (!$serverRows) {
                    $serverRows = self::dvCrud('dcim-server')->selectByIds([$serverKey], ['id', 'ServerCode', 'ServerName']);
                }
                if ($serverRows) {
                    $info['ServerName'] = (string)($serverRows[0]['ServerName'] ?? ($info['ServerName'] ?? ''));
                }
            }
        }
        O_E($info ?: [], tp_msg_success(), 100, false);
    }

    // POST /ChangeDeviceKey
    public static function deviceInfoUpdate()
    {
        $data = Flight::request_data();
        $res = self::dvCrud('dcim-device')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, false);
    }

    // POST /DelDeviceKey
    public static function deviceInfoDel()
    {
        $data = Flight::request_data();
        $res = self::dvCrud('dcim-device')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E(true, tp_msg_success(), 100, false);
    }

    // POST /SetAlarmNoticeKey
    public static function SetAlarmNotice()
    {
        $data = Flight::request_data();
        $devId = $data['DevId'] ?? ($data['DevID'] ?? ($data['id'] ?? null));
        if ($devId === null || $devId === '') {
            P_E(dcim_msg('error.dev_id_required'));
        }
        self::dvCrud('dcim-alarmnotifymode')->legacyUpdateWhere(
            [
                ['status', '=', 0],
                ['DevId', '=', $devId],
            ],
            ['status' => 1]
        );
        O_E(true, tp_msg_success(), 100, false);
    }

    // POST /SetAlarmCloseNoticeKey
    public static function SetAlarmCloseNotice()
    {
        $data = Flight::request_data();
        $devId = $data['DevId'] ?? ($data['DevID'] ?? ($data['id'] ?? null));
        if ($devId === null || $devId === '') {
            P_E(dcim_msg('error.dev_id_required'));
        }
        self::dvCrud('dcim-alarmnotifymode')->legacyUpdateWhere(
            [
                ['status', '=', 1],
                ['DevId', '=', $devId],
            ],
            ['status' => 0]
        );
        O_E(true, tp_msg_success(), 100, false);
    }

    // POST /CreateCameraKey
    public static function cameraInfoAdd()
    {
        $data = Flight::request_data();
        if (empty($data['ServerCode'])) {
            P_E(dcim_msg('error.server_code_required'));
        }

        $id = self::dvCrud('dcim-camera')->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    // POST /GetCameraListKey
    public static function cameraGetList()
    {
        $data = Flight::request_data();
        $result = self::dvCrud('dcim-camera')->legacyList($data, [
            'base_where' => ['status = 1', 'CameraName IS NOT NULL', "CameraName <> ''"],
            'search_fields' => ['CameraName'],
            'order_by' => 'ORDER BY id DESC',
        ]);
        if ($result === null) {
            return;
        }
        if (empty($result['info'])) {
            result_json(100, tp_msg_success(), ['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => 1]], 0);
            return;
        }
        result_json(100, tp_msg_success(), $result, 0);
    }

    // POST /GetCameraDetailKey
    public static function cameraGetInfo()
    {
        $data = Flight::request_data();
        $info = self::dvCrud('dcim-camera')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        result_json(100, tp_msg_success(), $info ?: null, 0);
    }

    // POST /ChangeCameraKey
    public static function cameraInfoUpdate()
    {
        $data = Flight::request_data();
        if (empty($data['ServerCode'])) {
            P_E(dcim_msg('error.server_code_required'));
        }

        $res = self::dvCrud('dcim-camera')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    // POST /DelCameraKey
    public static function cameraInfoDel()
    {
        $data = Flight::request_data();
        if (!self::dvCrud('dcim-camera')->legacyEnsureAuth($data)) {
            return;
        }
        if (empty($data['id'])) {
            result_json(100, tp_msg_success(), 0, 0);
            return;
        }
        $res = self::dvCrud('dcim-camera')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }
    // POST /CreateUDeviceKey
    public static function udeviceInfoAdd()
    {
        $data = Flight::request_data();
        $id = self::dvCrud('dcim-udevice')->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    // POST /GetUDeviceListKey
    public static function udeviceGetList()
    {
        $data = Flight::request_data();
        $result = self::dvCrud('dcim-udevice')->legacyList($data, [
            'base_where' => ['status = 1'],
            'search_fields' => ['UDeviceName'],
        ]);
        if ($result === null) {
            return;
        }
        O_E($result, tp_msg_success(), 100, false);
    }

    // POST /GetUDeviceDetailKey
    public static function udeviceGetInfo()
    {
        $data = Flight::request_data();
        $info = self::dvCrud('dcim-udevice')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        if ($info === null) {
            return;
        }
        O_E($info ?: [], tp_msg_success(), 100, false);
    }

    // POST /ChangeUDeviceKey
    public static function udeviceInfoUpdate()
    {
        $data = Flight::request_data();
        $res = self::dvCrud('dcim-udevice')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, false);
    }

    // POST /DelUDeviceKey
    public static function udeviceInfoDel()
    {
        $data = Flight::request_data();
        $res = self::dvCrud('dcim-udevice')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, false);
    }

    // POST /ChangeUDeviceStatusKey
    public static function changeUDeviceStatus()
    {
        $data = Flight::request_data();
        $crud = self::dvCrud('dcim-udevice');
        if (!$crud->legacyEnsureAuth($data)) {
            return;
        }
        $data['DeviceStatus'] = $data['status'] ?? 0;
        $res = $crud->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'only_fields' => ['DeviceStatus'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, false);
    }

    // POST /GetUDeviceStateKey
    public static function getUDeviceState()
    {
        $data = Flight::request_data();
        if (!self::dvCrud('dcim-person')->legacyEnsureAuth($data)) {
            return;
        }

        $where = 'status = 1';
        $params = [];
        if (!empty($data['AreaId'])) {
            $where .= ' AND AreaId = :areaId';
            $params[':areaId'] = $data['AreaId'];
        }
        if (!empty($data['ServerCode'])) {
            $where .= ' AND ServerCode = :serverCode';
            $params[':serverCode'] = $data['ServerCode'];
        }
        $cabinetRows = self::dvCrud('dcim-cabinet')->selectByRawCondition($where, 'ORDER BY id DESC', $params);

        $assetMap = [];
        $modelMap = [];
        $typeMap = [];

        $loadAsset = static function ($assetId) use (&$assetMap) {
            $id = (string)$assetId;
            if ($id === '' || $id === '0') {
                return null;
            }
            if (array_key_exists($id, $assetMap)) {
                return $assetMap[$id];
            }
            $asset = AppController::dvCrud('dcim-asset')->findOne([['id', '=', $assetId], ['status', '=', 1]]);
            $assetMap[$id] = $asset ?: null;
            return $assetMap[$id];
        };

        $loadModel = static function ($modelId) use (&$modelMap) {
            $id = (string)$modelId;
            if ($id === '' || $id === '0') {
                return null;
            }
            if (array_key_exists($id, $modelMap)) {
                return $modelMap[$id];
            }
            $model = AppController::dvCrud('dcim-brandmodel')->findOne([['id', '=', $modelId], ['status', '=', 1]]);
            $modelMap[$id] = $model ?: null;
            return $modelMap[$id];
        };

        $loadType = static function ($typeId) use (&$typeMap) {
            $id = (string)$typeId;
            if ($id === '' || $id === '0') {
                return null;
            }
            if (array_key_exists($id, $typeMap)) {
                return $typeMap[$id];
            }
            $type = AppController::dvCrud('dcim-assettype')->findOne([['id', '=', $typeId], ['status', '=', 1]]);
            $typeMap[$id] = $type ?: null;
            return $typeMap[$id];
        };

        foreach ($cabinetRows as $cabinet) {
            if (!empty($cabinet['AssetsId'])) {
                $loadAsset($cabinet['AssetsId']);
            }
        }

        $areaIds = [];
        $serverIds = [];
        foreach ($cabinetRows as $cabinet) {
            if (!empty($cabinet['AreaId'])) {
                $areaIds[] = $cabinet['AreaId'];
            }
            if (!empty($cabinet['ServerCode'])) {
                $serverIds[] = $cabinet['ServerCode'];
            }
        }
        $areaMap = [];
        foreach (self::dvCrud('dcim-area')->selectByIds($areaIds, ['id', 'AreaName']) as $row) {
            $key = (string)($row['id'] ?? '');
            if ($key !== '') {
                $areaMap[$key] = $row;
            }
        }
        $serverMap = [];
        foreach (self::dvCrud('dcim-server')->selectByIds($serverIds, ['id', 'ServerName']) as $row) {
            $key = (string)($row['id'] ?? '');
            if ($key !== '') {
                $serverMap[$key] = $row;
            }
        }

        $search = trim((string)($data['search'] ?? ''));
        $result = [];
        foreach ($cabinetRows as $cabinet) {
            $cabAsset = $loadAsset($cabinet['AssetsId'] ?? 0);
            if (!$cabAsset) {
                continue;
            }
            $uId = (string)($cabAsset['UId'] ?? '');
            if ($uId === '') {
                continue;
            }
            if ($search !== '' && stripos($uId, $search) === false) {
                continue;
            }

            $uRows = self::dvCrud('dcim-cabinetu')->selectByRawCondition(
                'status = 1 AND CabinetId = :cid',
                'ORDER BY ULocation ASC',
                [':cid' => $cabinet['id']]
            );
            $uinfo = [];
            foreach ($uRows as $uRow) {
                $uAsset = $loadAsset($uRow['AssetsId'] ?? 0);
                $brand = $uAsset ? $loadModel($uAsset['ModelId'] ?? 0) : null;
                $type = $brand ? $loadType($brand['AssetsTypeId'] ?? 0) : null;

                $uRow['AssetsTypeId'] = $brand['AssetsTypeId'] ?? null;
                $uRow['AssetsTypeName'] = $type['AssetsTypeName'] ?? '';
                $uRow['AssetsNumber'] = $uAsset['AssetsNumber'] ?? '';
                $uRow['AssetsDescribe'] = $uAsset['AssetsDescribe'] ?? '';
                if (!isset($uRow['UdeviceStatus']) || $uRow['UdeviceStatus'] === '' || $uRow['UdeviceStatus'] === null) {
                    $uRow['UdeviceStatus'] = '4';
                }
                $uinfo[] = $uRow;
            }
            $row = $cabinet;
            $row['ServerName'] = $serverMap[(string)($cabinet['ServerCode'] ?? '')]['ServerName'] ?? '';
            $row['AreaName'] = $areaMap[(string)($cabinet['AreaId'] ?? '')]['AreaName'] ?? '';
            $row['UId'] = $uId;
            $row['uinfo'] = $uinfo;
            $result[] = $row;
        }
        O_E($result, tp_msg_success(), 100, $result ? count($result) : false);
    }

    // POST /CreateDeviceCommandKey
    public static function deviceCommandInfoAdd()
    {
        $data = Flight::request_data();
        if ((!isset($data['DevID']) || $data['DevID'] === '' || $data['DevID'] === null) && isset($data['DevId']) && $data['DevId'] !== '') {
            $data['DevID'] = $data['DevId'];
        }
        if (!isset($data['CommandType']) || $data['CommandType'] === '' || $data['CommandType'] === null) {
            $data['CommandType'] = 2;
        }
        $id = self::dvCrud('dcim-devicecommand')->legacyCreate($data, [
            'required_fields' => [
                'DevID' => dcim_msg('error.dev_id_required'),
            ],
            'drop_fields' => ['token'],
            'defaults' => [
                'status' => 1,
            ],
        ]);
        if ($id === null) {
            return;
        }
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    // POST /GetDeviceCommandListKey
    public static function deviceCommandGetList(bool $skipAuth = true)
    {
        $data = Flight::request_data();
        if (!$skipAuth) {
            self::dvRequireAuth($data);
        }

        $page = isset($data['pageNo']) ? max((int)$data['pageNo'], 1) : 1;
        $pageSize = isset($data['pageSize']) ? max((int)$data['pageSize'], 1) : 15;

        $viewWhere = ['status = 1'];
        $legacyWhere = ['a.status = 1'];
        $params = [];
        $deviceCols = self::edFilterExistingSearchFields('dcim-device', ['id', 'status']);
        if (in_array('status', $deviceCols, true)) {
            $activeDeviceSubquery = 'DevID IN (SELECT id FROM `dcim-device` WHERE status <> -1)';
            $viewWhere[] = $activeDeviceSubquery;
            $legacyWhere[] = 'a.' . $activeDeviceSubquery;
        }

        if (!empty($data['type']) && (string)$data['type'] === '2') {
            $viewWhere[] = 'CommandType = :ctype_eq';
            $legacyWhere[] = 'a.CommandType = :ctype_eq';
            $params[':ctype_eq'] = '2';
        } else {
            $viewWhere[] = 'CommandType <> :ctype_ne';
            $legacyWhere[] = 'a.CommandType <> :ctype_ne';
            $params[':ctype_ne'] = '2';
        }

        if (!empty($data['DevID'])) {
            $viewWhere[] = 'DevID = :dev_id';
            $legacyWhere[] = 'a.DevID = :dev_id';
            $params[':dev_id'] = (string)$data['DevID'];
        }

        if (!empty($data['Command'])) {
            $viewWhere[] = 'Command LIKE :cmd_like';
            $legacyWhere[] = 'a.Command LIKE :cmd_like';
            $params[':cmd_like'] = '%' . (string)$data['Command'] . '%';
        }

        if (!empty($data['search'])) {
            $viewWhere[] = 'DeviceName LIKE :dev_name_like';
            $params[':dev_name_like'] = '%' . (string)$data['search'] . '%';
        }

        if (!empty($data['DevIDs'])) {
            $devIds = array_filter(array_map('trim', explode(',', (string)$data['DevIDs'])));
            if ($devIds) {
                $phs = [];
                foreach (array_values($devIds) as $i => $devId) {
                    $ph = ':dev_ids_' . $i;
                    $phs[] = $ph;
                    $params[$ph] = $devId;
                }
                $viewWhere[] = 'DevID IN (' . implode(', ', $phs) . ')';
                $legacyWhere[] = 'a.DevID IN (' . implode(', ', $phs) . ')';
            }
        }

        $viewWhereSql = implode(' AND ', $viewWhere);
        foreach (['vw_device_command_list', 'dcim-command-deviceview'] as $viewName) {
            if (!self::dvCanTryView($viewName)) {
                continue;
            }
            try {
                $result = self::dvCrud($viewName)->selectWithPagination(
                    $viewWhereSql,
                    $params,
                    'ORDER BY id DESC',
                    $page,
                    $pageSize
                );
                O_E($result, tp_msg_success(), 100, 0);
                return;
            } catch (Throwable $e) {
                self::dvLogViewFallback('GetDeviceCommandListKey', $viewName, $e);
            }
        }

        $legacyWhereSql = implode(' AND ', $legacyWhere);
        $fallbackWhereSql = str_replace('a.', '', $legacyWhereSql);
        $fallbackParams = $params;
        $searchName = $fallbackParams[':dev_name_like'] ?? null;
        if ($searchName !== null) {
            try {
                $devRows = self::dvCrud('dcim-device')->selectByRawCondition('status <> -1 AND DeviceName LIKE :kw', '', [':kw' => $searchName]);
            } catch (\Throwable $e) {
                $devRows = self::dvCrud('dcim-device')->selectByRawCondition('DeviceName LIKE :kw', '', [':kw' => $searchName]);
            }
            $devIds = array_values(array_filter(array_map(static function ($r) {
                return isset($r['id']) ? (string)$r['id'] : null;
            }, $devRows)));
            if (!$devIds) {
                O_E(['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => $page]], tp_msg_success(), 100, 0);
                return;
            }
            $inPlaceholders = [];
            foreach ($devIds as $idx => $devId) {
                $ph = ':fallback_dev_' . $idx;
                $inPlaceholders[] = $ph;
                $fallbackParams[$ph] = $devId;
            }
            $fallbackWhereSql .= ' AND DevID IN (' . implode(', ', $inPlaceholders) . ')';
            unset($fallbackParams[':dev_name_like']);
        }

        $fallbackResult = self::dvCrud('dcim-devicecommand')->selectByRawConditionWithPagination(
            $fallbackWhereSql,
            $fallbackParams,
            'ORDER BY id DESC',
            $page,
            $pageSize
        );
        $total = (int) ($fallbackResult['page']['total'] ?? 0);
        $rows = is_array($fallbackResult['info'] ?? null) ? $fallbackResult['info'] : [];

        $nameMap = [];
        $devIds = [];
        foreach ($rows as $row) {
            if (isset($row['DevID']) && $row['DevID'] !== null && $row['DevID'] !== '') {
                $devIds[(string)$row['DevID']] = true;
            }
        }
        if (!empty($devIds)) {
            $deviceRows = self::dvCrud('dcim-device')->selectByIds(array_keys($devIds), ['id', 'DeviceName']);
            foreach ($deviceRows as $item) {
                $idKey = (string)($item['id'] ?? '');
                if ($idKey !== '') {
                    $nameMap[$idKey] = $item['DeviceName'] ?? '';
                }
            }
        }
        foreach ($rows as &$row) {
            $row['DeviceName'] = $nameMap[(string)($row['DevID'] ?? '')] ?? '';
        }
        unset($row);

        $result = [
            'info' => $rows,
            'page' => [
                'total' => $total,
                'p_n' => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0,
                'p' => $page,
            ],
        ];
        O_E($result, tp_msg_success(), 100, 0);
    }

    // POST /GetDeviceCommandDetailKey
    public static function deviceCommandGetInfo()
    {
        $data = Flight::request_data();
        $info = self::dvCrud('dcim-devicecommand')->legacyInfo($data, [
            'extra_conditions' => [['status', '=', 1]],
        ]);
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    // POST /ChangeDeviceCommandKey
    public static function deviceCommandInfoUpdate()
    {
        $data = Flight::request_data();
        $res = self::dvCrud('dcim-devicecommand')->legacyUpdate($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'drop_fields' => ['token'],
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    // POST /DelDeviceCommandKey
    public static function deviceCommandInfoDel()
    {
        $data = Flight::request_data();
        $res = self::dvCrud('dcim-devicecommand')->legacySoftDelete($data, [
            'id_required_message' => dcim_msg('common.id_required'),
            'delete_field' => 'status',
            'delete_value' => -1,
        ]);
        if ($res === null) {
            return;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    // POST /CreateDeviceCommandSendKey
    public static function deviceCommandCreateSend()
    {
        $data = self::dvRequestData();
        $user = null;
        $token = function_exists('dcim_extract_token') ? dcim_extract_token($data) : '';
        if (!is_string($token) || trim($token) === '') {
            $token = Flight::request_token();
        }
        $token = is_string($token) ? trim($token) : '';
        if ($token !== '') {
            $user = function_exists('dcim_auth_user_by_token') ? dcim_auth_user_by_token($token) : null;
            if (!$user) {
                $user = self::dvCrud('dcim-person')->findOne([
                    ['token', '=', $token],
                    ['status', '=', 1],
                ]);
            }
        }

        $devId = trim((string)($data['DevID'] ?? ($data['DevId'] ?? ($data['DeviceID'] ?? ($data['DeviceId'] ?? ($data['id'] ?? ''))))));
        $command = trim((string)($data['Command'] ?? ($data['command'] ?? ($data['Cmd'] ?? ($data['cmd'] ?? '')))));
        if ($devId === '' || $command === '') {
            P_E(dcim_msg('error.dev_command_required'));
        }

        $device = self::dvCrud('dcim-device')->findOne([
            ['id', '=', $devId],
            ['status', '=', 1],
        ]);

        $commandInfo = self::dvCrud('dcim-devicecommand')->findOne([
            ['DevID', '=', $devId],
            ['Command', '=', $command],
            ['status', '=', 1],
        ]);

        $insertData = [
            'DevID' => $devId,
            'Command' => $command,
            'ComId' => $commandInfo['id'] ?? null,
            'ComDesc' => $commandInfo['CommandDesc'] ?? '',
            'RecvData' => $data['RecvData'] ?? '',
            'SendState' => $data['SendState'] ?? 0,
            'CreateEmpId' => $user['id'] ?? 0,
            'CreateEmpName' => $user['PersonName'] ?? '',
            'DeviceName' => $device['DeviceName'] ?? '',
            'ip' => $device['DeviceIP'] ?? '',
            'status' => 1,
        ];

        $id = self::dvCrud('dcim-devcommondsendlist')->legacyInsert($insertData);
        O_E(['id' => $id], tp_msg_success(), 100, false);
    }

    // POST /GetDeviceCommandSendListKey
    public static function deviceCommandGetSendList()
    {
        $data = self::dvRequestData();
        if (!self::dvRequireAuth($data)) {
            return;
        }

        $result = ['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => 1]];
        $rows = [];
        $sendTableCandidates = [
            'dcim-devcommondsendlist',
            'dcim-devcommandsendlist',
            'dcim-devicecommandsendlist',
            'dcim-devicecommandsend',
            'dcim-devcommondsend',
            'dcim-devcommandsend',
            'dcim-devcommandrecord',
            'dcim-devicectrlrecord',
        ];
        try {
            $showStmt = Flight::db()->prepare('SHOW TABLES LIKE :tb_like');
            $showStmt->execute([':tb_like' => 'dcim-%command%']);
            $discovered = $showStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($discovered as $row) {
                if (!is_array($row) || !$row) {
                    continue;
                }
                $tableName = trim((string)reset($row));
                if ($tableName === '') {
                    continue;
                }
                $nameLower = strtolower($tableName);
                if (
                    strpos($nameLower, 'send') !== false ||
                    strpos($nameLower, 'record') !== false ||
                    strpos($nameLower, 'ctrl') !== false
                ) {
                    $sendTableCandidates[] = $tableName;
                }
            }
        } catch (\Throwable $ignore) {
        }
        $sendTableCandidates = array_values(array_unique($sendTableCandidates));
        $primarySendTable = '';
        foreach ($sendTableCandidates as $candidateTable) {
            if (self::edFilterExistingSearchFields($candidateTable, ['id'])) {
                $primarySendTable = $candidateTable;
                break;
            }
        }
        if ($primarySendTable === '') {
            $primarySendTable = 'dcim-devcommondsendlist';
        }
        try {
            $page = isset($data['pageNo']) ? max((int)$data['pageNo'], 1) : 1;
            $pageSize = isset($data['pageSize']) ? max((int)$data['pageSize'], 1) : 15;
            $tableColumns = self::edFilterExistingSearchFields($primarySendTable, ['status', 'DevID', 'DevId', 'DeviceID', 'DeviceId', 'SendState', 'create_time', 'Command', 'SendData', 'RecvData', 'ComDesc', 'CreateEmpName', 'DeviceName', 'DeviceIP', 'ip', 'CreateEmpId', 'ComId']);
            $devField = 'DevID';
            foreach (['DevID', 'DevId', 'DeviceID', 'DeviceId'] as $devCandidate) {
                if (in_array($devCandidate, $tableColumns, true)) {
                    $devField = $devCandidate;
                    break;
                }
            }
            $conditions = [in_array('status', $tableColumns, true) ? '(status <> -1 OR status IS NULL)' : '1=1'];
            $params = [];

            $devId = trim((string)($data['DevID'] ?? ($data['DevId'] ?? ($data['DeviceId'] ?? ''))));
            if ($devId !== '') {
                $devIds = array_values(array_filter(array_map('trim', explode(',', $devId)), static function ($v) {
                    return $v !== '';
                }));
                if ($devIds) {
                    $holders = [];
                    foreach ($devIds as $idx => $oneId) {
                        $ph = ':dev_' . $idx;
                        $holders[] = $ph;
                        $params[$ph] = $oneId;
                    }
                    $conditions[] = $devField . ' IN (' . implode(', ', $holders) . ')';
                }
            }

            if (
                in_array('SendState', $tableColumns, true) &&
                array_key_exists('SendState', $data) &&
                $data['SendState'] !== '' &&
                $data['SendState'] !== null
            ) {
                $conditions[] = 'SendState = :send_state';
                $params[':send_state'] = (string)$data['SendState'];
            }

            $start = trim((string)($data['startDateTime'] ?? ''));
            $end = trim((string)($data['endDateTime'] ?? ''));
            if ($start !== '' && $end !== '' && in_array('create_time', $tableColumns, true)) {
                $conditions[] = 'create_time BETWEEN :start_time AND :end_time';
                $params[':start_time'] = $start;
                $params[':end_time'] = $end;
            }

            $search = trim((string)($data['search'] ?? ($data['key'] ?? '')));
            if ($search !== '') {
                $searchParts = [];
                foreach (['Command', 'SendData', 'RecvData', 'ComDesc', 'CreateEmpName', 'DeviceName', 'DeviceIP', 'ip'] as $field) {
                    if (in_array($field, $tableColumns, true)) {
                        $searchParts[] = $field . ' LIKE :kw';
                    }
                }
                if ($searchParts) {
                    $conditions[] = '(' . implode(' OR ', $searchParts) . ')';
                    $params[':kw'] = '%' . $search . '%';
                }
            }

            try {
                $result = self::dvCrud($primarySendTable)->selectWithPagination(
                    implode(' AND ', $conditions),
                    $params,
                    'ORDER BY id DESC',
                    $page,
                    $pageSize
                );
                $rows = is_array($result['info'] ?? null) ? $result['info'] : [];
            } catch (\Throwable $e) {
                $result = ['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => $page]];
                $rows = [];
            }

            if (!$rows) {
                $sendTableCols = self::edFilterExistingSearchFields($primarySendTable, ['status', 'create_time', 'CollectTime', 'update_time', 'SendTime']);
                $sendBaseWhere = [];
                if (in_array('status', $sendTableCols, true)) {
                    $sendBaseWhere[] = '(status <> -1 OR status IS NULL)';
                }
                $fallbackBetweenFilters = [];
                foreach (['create_time', 'CollectTime', 'update_time', 'SendTime'] as $timeField) {
                    if (in_array($timeField, $sendTableCols, true)) {
                        $fallbackBetweenFilters[] = ['field' => $timeField, 'start_key' => 'startDateTime', 'end_key' => 'endDateTime'];
                        break;
                    }
                }
                $fallback = self::dvCrud($primarySendTable)->legacyList($data, [
                    'base_where' => $sendBaseWhere,
                    'exact_filters' => [
                        'DevID' => $devField,
                        'DevId' => $devField,
                        'DeviceID' => $devField,
                        'DeviceId' => $devField,
                        'SendState' => 'SendState',
                    ],
                    'between_filters' => $fallbackBetweenFilters,
                    'search_fields' => ['Command', 'SendData', 'RecvData', 'ComDesc', 'CreateEmpName', 'DeviceName', 'DeviceIP', 'ip'],
                    'order_by' => 'ORDER BY id DESC',
                ]);
                if (is_array($fallback) && !empty($fallback['info']) && is_array($fallback['info'])) {
                    $result = $fallback;
                    $rows = $fallback['info'];
                }
            }
            if (!$rows) {
                try {
                    $compatCols = self::edFilterExistingSearchFields('dcim-devicectrlrecord', ['status', 'create_time', 'CollectTime', 'update_time', 'SendTime', 'DevID', 'DevId', 'DeviceID', 'DeviceId', 'SendState', 'Command', 'SendData', 'RecvData', 'ComDesc', 'CreateEmpName', 'DeviceName', 'DeviceIP', 'ip']);
                    $compatBaseWhere = [];
                    if (in_array('status', $compatCols, true)) {
                        $compatBaseWhere[] = '(status <> -1 OR status IS NULL)';
                    }
                    $compatDevField = 'DevID';
                    foreach (['DevID', 'DevId', 'DeviceID', 'DeviceId'] as $devCandidate) {
                        if (in_array($devCandidate, $compatCols, true)) {
                            $compatDevField = $devCandidate;
                            break;
                        }
                    }
                    $compatBetweenFilters = [];
                    foreach (['create_time', 'CollectTime', 'update_time', 'SendTime'] as $timeField) {
                        if (in_array($timeField, $compatCols, true)) {
                            $compatBetweenFilters[] = ['field' => $timeField, 'start_key' => 'startDateTime', 'end_key' => 'endDateTime'];
                            break;
                        }
                    }
                    $legacyCompat = self::dvCrud('dcim-devicectrlrecord')->legacyList($data, [
                        'base_where' => $compatBaseWhere,
                        'exact_filters' => [
                            'DevID' => $compatDevField,
                            'DevId' => $compatDevField,
                            'DeviceID' => $compatDevField,
                            'DeviceId' => $compatDevField,
                            'SendState' => 'SendState',
                        ],
                        'between_filters' => $compatBetweenFilters,
                        'search_fields' => ['Command', 'SendData', 'RecvData', 'ComDesc', 'CreateEmpName', 'DeviceName', 'DeviceIP', 'ip'],
                        'order_by' => 'ORDER BY id DESC',
                    ]);
                    if (is_array($legacyCompat) && !empty($legacyCompat['info']) && is_array($legacyCompat['info'])) {
                        $result = $legacyCompat;
                        $rows = $legacyCompat['info'];
                    }
                } catch (\Throwable $ignore) {
                }
            }
            if (!$rows) {
                foreach (['dcim-devicecommandsendlist', 'dcim-devicecommandsend', 'dcim-devcommondsend', 'dcim-devcommandsend', 'dcim-devcommandrecord', 'dcim-devcommondsendlist'] as $legacyTable) {
                    try {
                        $legacyCols = self::edFilterExistingSearchFields($legacyTable, ['status', 'create_time', 'CollectTime', 'update_time', 'SendTime', 'DevID', 'DevId', 'DeviceID', 'DeviceId', 'SendState', 'Command', 'SendData', 'RecvData', 'ComDesc', 'CreateEmpName', 'DeviceName', 'DeviceIP', 'ip']);
                        if (!$legacyCols) {
                            continue;
                        }
                        $legacyBaseWhere = [];
                        if (in_array('status', $legacyCols, true)) {
                            $legacyBaseWhere[] = '(status <> -1 OR status IS NULL)';
                        }
                        $legacyDevField = '';
                        foreach (['DevID', 'DevId', 'DeviceID', 'DeviceId'] as $devCandidate) {
                            if (in_array($devCandidate, $legacyCols, true)) {
                                $legacyDevField = $devCandidate;
                                break;
                            }
                        }
                        $legacyBetweenFilters = [];
                        foreach (['create_time', 'CollectTime', 'update_time', 'SendTime'] as $timeField) {
                            if (in_array($timeField, $legacyCols, true)) {
                                $legacyBetweenFilters[] = ['field' => $timeField, 'start_key' => 'startDateTime', 'end_key' => 'endDateTime'];
                                break;
                            }
                        }
                        $legacyExactFilters = ['SendState' => 'SendState'];
                        if ($legacyDevField !== '') {
                            $legacyExactFilters['DevID'] = $legacyDevField;
                            $legacyExactFilters['DevId'] = $legacyDevField;
                            $legacyExactFilters['DeviceID'] = $legacyDevField;
                            $legacyExactFilters['DeviceId'] = $legacyDevField;
                        }
                        $legacyRows = self::dvCrud($legacyTable)->legacyList($data, [
                            'base_where' => $legacyBaseWhere,
                            'exact_filters' => $legacyExactFilters,
                            'between_filters' => $legacyBetweenFilters,
                            'search_fields' => ['Command', 'SendData', 'RecvData', 'ComDesc', 'CreateEmpName', 'DeviceName', 'DeviceIP', 'ip'],
                            'order_by' => 'ORDER BY id DESC',
                        ]);
                        if (is_array($legacyRows) && !empty($legacyRows['info']) && is_array($legacyRows['info'])) {
                            $result = $legacyRows;
                            $rows = $legacyRows['info'];
                            break;
                        }
                    } catch (\Throwable $ignore) {
                    }
                }
            }
            if ($rows) {
                $devIds = [];
                $creatorIds = [];
                foreach ($rows as $row) {
                    $oneDevId = trim((string)($row['DevID'] ?? ($row['DevId'] ?? '')));
                    if ($oneDevId !== '') {
                        $devIds[$oneDevId] = true;
                    }
                    $creatorId = trim((string)($row['CreateEmpId'] ?? ''));
                    if ($creatorId !== '' && $creatorId !== '999999') {
                        $creatorIds[$creatorId] = true;
                    }
                }

                $deviceMap = [];
                if ($devIds) {
                    $deviceRows = self::dvCrud('dcim-device')->selectByIds(array_keys($devIds), ['id', 'DeviceName', 'status']);
                    foreach ($deviceRows as $deviceRow) {
                        $idKey = trim((string)($deviceRow['id'] ?? ''));
                        if ($idKey !== '') {
                            $deviceMap[$idKey] = (string)($deviceRow['DeviceName'] ?? '');
                        }
                    }
                }

                $creatorMap = [];
                if ($creatorIds) {
                    $personRows = self::dvCrud('dcim-person')->selectByIds(array_keys($creatorIds), ['id', 'PersonName']);
                    foreach ($personRows as $personRow) {
                        $idKey = trim((string)($personRow['id'] ?? ''));
                        if ($idKey !== '') {
                            $creatorMap[$idKey] = (string)($personRow['PersonName'] ?? '');
                        }
                    }
                }

                $commandDescMap = [];
                foreach ($rows as $row) {
                    $oneDevId = trim((string)($row['DevID'] ?? ($row['DevId'] ?? '')));
                    $command = trim((string)($row['Command'] ?? ''));
                    if ($oneDevId === '' || $command === '') {
                        continue;
                    }
                    $key = $oneDevId . '|' . $command;
                    if (isset($commandDescMap[$key])) {
                        continue;
                    }
                    $cmd = self::dvCrud('dcim-devicecommand')->findOne([
                        ['DevID', '=', $oneDevId],
                        ['Command', '=', $command],
                        ['CommandType', '=', '2'],
                        ['status', '=', 1],
                    ]);
                    $commandDescMap[$key] = [
                        'ComId' => $cmd['id'] ?? '',
                        'ComDesc' => $cmd['CommandDesc'] ?? '',
                    ];
                }

                foreach ($rows as &$row) {
                    $oneDevId = trim((string)($row['DevID'] ?? ($row['DevId'] ?? '')));
                    $command = trim((string)($row['Command'] ?? ''));
                    $creatorId = trim((string)($row['CreateEmpId'] ?? ''));
                    if (!isset($row['DevID']) && $oneDevId !== '') {
                        $row['DevID'] = $oneDevId;
                    }

                    if (($row['DeviceName'] ?? '') === '' && $oneDevId !== '') {
                        $row['DeviceName'] = $deviceMap[$oneDevId] ?? '';
                    }

                    if (($row['CreateEmpName'] ?? '') === '') {
                        if ($creatorId === '999999') {
                            $row['CreateEmpName'] = dcim_msg('app.app_initiated');
                        } else {
                            $row['CreateEmpName'] = $creatorMap[$creatorId] ?? '';
                        }
                    }

                    $key = $oneDevId . '|' . $command;
                    if ($key !== '|' && isset($commandDescMap[$key])) {
                        if (($row['ComId'] ?? '') === '') {
                            $row['ComId'] = $commandDescMap[$key]['ComId'];
                        }
                        if (($row['ComDesc'] ?? '') === '') {
                            $row['ComDesc'] = $commandDescMap[$key]['ComDesc'];
                        }
                    }
                }
                unset($row);

                $activeDeviceIds = [];
                foreach ($deviceRows ?? [] as $deviceRow) {
                    $idKey = trim((string)($deviceRow['id'] ?? ''));
                    if ($idKey !== '' && (int)($deviceRow['status'] ?? 1) !== -1) {
                        $activeDeviceIds[$idKey] = true;
                    }
                }
                if ($activeDeviceIds) {
                    $rows = array_values(array_filter($rows, static function (array $row) use ($activeDeviceIds) {
                        $devId = trim((string)($row['DevID'] ?? ($row['DevId'] ?? '')));
                        return $devId === '' || isset($activeDeviceIds[$devId]);
                    }));
                }

                $result['info'] = $rows;
            }
        } catch (\Throwable $e) {
            $result = ['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => 1]];
        }

        O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
    }

    private static function dvDoorPage(array $data): array
    {
        $page = isset($data['pageNo']) ? (int) $data['pageNo'] : 1;
        $pageSize = isset($data['pageSize']) ? (int) $data['pageSize'] : 15;
        return [$page, $pageSize];
    }

    private static function dvDoorListWithTry(string $table, array $data)
    {
        [$page, $pageSize] = self::dvDoorPage($data);
        $page = $page > 0 ? $page : 1;
        $pageSize = $pageSize > 0 ? $pageSize : 15;
        $devIdRaw = trim((string)($data['DevId'] ?? ($data['DevID'] ?? '')));
        try {
            if ($devIdRaw !== '' && ctype_digit($devIdRaw)) {
                try {
                    $rows = self::dvCrud($table)->selectByRawCondition(
                        'DevId = :dev_id AND (status <> -1 OR status IS NULL)',
                        'ORDER BY id DESC',
                        [':dev_id' => (int)$devIdRaw]
                    );
                    if (!is_array($rows)) {
                        $rows = [];
                    }
                    $total = count($rows);
                    $offset = ($page - 1) * $pageSize;
                    if ($offset < 0) {
                        $offset = 0;
                    }
                    $info = array_slice($rows, $offset, $pageSize);
                    $result = [
                        'info' => $info,
                        'page' => [
                            'total' => $total,
                            'p_n' => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0,
                            'p' => $page,
                        ],
                    ];
                    O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
                    return;
                } catch (\Throwable $e) {
                }
            }
            $result = self::dvCrud($table)->legacyList($data, [
                'base_where' => ['status = 1'],
                'order_by' => 'ORDER BY id DESC',
            ]);
            if ($result === null) {
                return;
            }
            O_E($result, tp_msg_success(), 100, $result['info'] ? count($result['info']) : false);
        } catch (\Throwable $e) {
            O_E(['info' => [], 'page' => ['pageNo' => $page, 'pageSize' => $pageSize]], tp_msg_success(), 100, false);
        }
    }

    private static function dvDoorInfoWithTry(string $table, array $data)
    {
        try {
            $info = self::dvCrud($table)->legacyInfo($data, [
                'extra_conditions' => [['status', '=', 1]],
            ]);
            if ($info === null) {
                return;
            }
        } catch (\Throwable $e) {
            $info = [];
        }
        O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
    }

    private static function dvDoorUpdateWithTry(string $table, array $data, ?string $idMessage = null)
    {
        if ($idMessage === null || $idMessage === '') {
            $idMessage = dcim_msg('common.id_required');
        }
        try {
            $res = self::dvCrud($table)->legacyUpdate($data, [
                'id_required_message' => $idMessage,
            ]);
            if ($res === null) {
                return;
            }
        } catch (\Throwable $e) {
            $res = false;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    private static function dvDoorResolveIdByDevId(string $table, $devId): int
    {
        $devIdText = trim((string)$devId);
        if ($devIdText === '' || !ctype_digit($devIdText)) {
            return 0;
        }
        try {
            $rows = self::dvCrud($table)->selectByRawCondition(
                'DevId = :dev_id AND (status <> -1 OR status IS NULL)',
                'ORDER BY id DESC',
                [':dev_id' => (int)$devIdText]
            );
            if ($rows && isset($rows[0]['id'])) {
                return (int)$rows[0]['id'];
            }
        } catch (\Throwable $e) {
        }
        return 0;
    }

    private static function dvDoorCreate(string $table, array $data)
    {
        $id = self::dvCrud($table)->legacyCreate($data, [
            'defaults' => ['status' => 1],
        ]);
        if ($id === null) {
            return;
        }
        O_E($id ? true : false, tp_msg_success(), 100, $id ? 1 : false);
    }

    private static function dvDoorSoftDelete(string $table, array $data, ?string $idMessage = null)
    {
        if ($idMessage === null || $idMessage === '') {
            $idMessage = dcim_msg('common.id_required');
        }
        try {
            $res = self::dvCrud($table)->legacySoftDelete($data, [
                'id_required_message' => $idMessage,
                'delete_field' => 'status',
                'delete_value' => -1,
            ]);
            if ($res === null) {
                return;
            }
        } catch (\Throwable $e) {
            $res = false;
        }
        O_E($res, tp_msg_success(), 100, $res ? 1 : false);
    }

    public static function doorGetDoorParamList()
    {
        self::dvDoorListWithTry('dcim-doorparam', Flight::request_data());
    }

    public static function doorGetDoorParamDetail()
    {
        $data = Flight::request_data();
        if (!is_array($data)) {
            $data = [];
        }

        $devIdRaw = trim((string)($data['DevId'] ?? ($data['DevID'] ?? ($data['id'] ?? ''))));
        if ($devIdRaw !== '' && ctype_digit($devIdRaw)) {
            try {
                $rows = self::dvCrud('dcim-doorparam')->selectByRawCondition(
                    'DevId = :dev_id AND (status <> -1 OR status IS NULL)',
                    'ORDER BY id DESC',
                    [':dev_id' => (int)$devIdRaw]
                );
                if ($rows) {
                    $info = is_array($rows[0]) ? $rows[0] : [];
                    O_E($info ?: [], tp_msg_success(), 100, $info ? 1 : false);
                    return;
                }
            } catch (\Throwable $e) {
            }
        }

        self::dvDoorInfoWithTry('dcim-doorparam', $data);
    }

    public static function doorChangeDoorParam()
    {
        $data = Flight::request_data();
        $idRaw = trim((string)($data['id'] ?? ''));
        if ($idRaw === '') {
            $devIdRaw = trim((string)($data['DevId'] ?? ($data['DevID'] ?? ($data['DeviceId'] ?? ($data['DeviceID'] ?? '')))));
            if ($devIdRaw !== '') {
                $resolvedId = self::dvDoorResolveIdByDevId('dcim-doorparam', $devIdRaw);
                if ($resolvedId <= 0 && ctype_digit($devIdRaw)) {
                    $insertData = [
                        'DevId' => (int)$devIdRaw,
                    ];
                    try {
                        $device = self::dvCrud('dcim-device')->selectById((int)$devIdRaw, ['id', 'SerialNumber']);
                        if ($device && isset($device['SerialNumber'])) {
                            $insertData['SN'] = (string)$device['SerialNumber'];
                        }
                    } catch (\Throwable $e) {
                    }
                    try {
                        $newId = self::dvCrud('dcim-doorparam')->legacyInsert($insertData);
                        if ($newId) {
                            $resolvedId = (int)$newId;
                        }
                    } catch (\Throwable $e) {
                    }
                }
                if ($resolvedId > 0) {
                    $data['id'] = $resolvedId;
                }
            }
        }
        self::dvDoorUpdateWithTry('dcim-doorparam', $data);
    }

    public static function doorGetDoorZktUserList()
    {
        self::dvDoorListWithTry('dcim-doorzktuser', Flight::request_data());
    }

    public static function doorGetDoorZktUserDetail()
    {
        self::dvDoorInfoWithTry('dcim-doorzktuser', Flight::request_data());
    }

    public static function doorChangeDoorZktUser()
    {
        self::dvDoorUpdateWithTry('dcim-doorzktuser', Flight::request_data());
    }

    public static function doorDelDoorZktUser()
    {
        $data = Flight::request_data();
        if (empty($data['id'])) {
            if (!self::dvCrud('dcim-doorzktuser')->legacyEnsureAuth($data)) {
                return;
            }
            O_E(false, tp_msg_success(), 100, false);
            return;
        }
        self::dvDoorSoftDelete('dcim-doorzktuser', $data);
    }

    public static function doorCreateDoorPower()
    {
        self::dvDoorCreate('dcim-doorpower', Flight::request_data());
    }

    public static function doorGetDoorPowerList()
    {
        self::dvDoorListWithTry('dcim-doorpower', Flight::request_data());
    }

    public static function doorGetDoorPowerDetail()
    {
        self::dvDoorInfoWithTry('dcim-doorpower', Flight::request_data());
    }

    public static function doorChangeDoorPower()
    {
        self::dvDoorUpdateWithTry('dcim-doorpower', Flight::request_data());
    }

    public static function doorDelDoorPower()
    {
        self::dvDoorSoftDelete('dcim-doorpower', Flight::request_data());
    }

    public static function doorCreateDoorADKRecord()
    {
        self::dvDoorCreate('dcim-doorrecordadk', Flight::request_data());
    }

    public static function doorGetDoorADKRecordList()
    {
        self::dvDoorListWithTry('dcim-doorrecordadk', Flight::request_data());
    }

    public static function doorGetDoorADKRecordDetail()
    {
        self::dvDoorInfoWithTry('dcim-doorrecordadk', Flight::request_data());
    }

    public static function doorChangeDoorADKRecord()
    {
        self::dvDoorUpdateWithTry('dcim-doorrecordadk', Flight::request_data());
    }

    public static function doorDelDoorADKRecord()
    {
        self::dvDoorSoftDelete('dcim-doorrecordadk', Flight::request_data());
    }

    public static function doorGetHistoryDoor()
    {
        self::doorGetDoorADKRecordList();
    }

    public static function doorGetDoorZktAttList()
    {
        $data = Flight::request_data();
        // Compatibility: real attendance logs are stored in dcim-doorattlog on most deployments.
        // Keep dcim-doorzktatt as legacy fallback when dcim-doorattlog is unavailable.
        if (self::edFilterExistingSearchFields('dcim-doorattlog', ['id'])) {
            self::dvDoorListWithTry('dcim-doorattlog', $data);
            return;
        }
        self::dvDoorListWithTry('dcim-doorzktatt', $data);
    }

    public static function doorGetDoorZktOperList()
    {
        self::dvDoorListWithTry('dcim-doorzktoper', Flight::request_data());
    }

    public static function doorGetDoorZktErrorList()
    {
        self::dvDoorListWithTry('dcim-doorzkterror', Flight::request_data());
    }

    public static function doorCreateDoorZktCmd()
    {
        $data = Flight::request_data();
        if (!is_array($data)) {
            $data = [];
        }
        if (!isset($data['DevID']) && isset($data['DevId'])) {
            $data['DevID'] = $data['DevId'];
        }
        if (!isset($data['DevAction']) && isset($data['Action'])) {
            $data['DevAction'] = $data['Action'];
        }
        try {
            $returnId = 0;
            $ctrlId = self::dvCrud('dcim-devicectrlrecord')->legacyCreate($data, [
                'defaults' => ['status' => 1],
            ]);
            if ($ctrlId === null) {
                return;
            }
            if ($ctrlId) {
                $returnId = (int)$ctrlId;
            }
            // Keep compatibility with historical command-list table when available.
            try {
                $legacyId = self::dvCrud('dcim-doorzktcmd')->legacyCreate($data, [
                    'defaults' => ['status' => 1],
                ]);
                if ($returnId <= 0 && $legacyId) {
                    $returnId = (int)$legacyId;
                }
            } catch (\Throwable $ignore) {
            }
            if ($returnId <= 0) {
                $returnId = (int)$ctrlId;
            }
            O_E(['id' => (string)$returnId], tp_msg_success(), 100, 1);
        } catch (\Throwable $e) {
            O_E(false, tp_msg_success(), 100, false);
        }
    }

    public static function doorGetDoorZktCmdList()
    {
        $data = Flight::request_data();
        if (!is_array($data)) {
            $data = [];
        }
        [$page, $pageSize] = self::dvDoorPage($data);
        $page = $page > 0 ? $page : 1;
        $pageSize = $pageSize > 0 ? $pageSize : 15;
        $devIdRaw = trim((string)($data['DevID'] ?? ($data['DevId'] ?? '')));

        $queryOneTable = static function (string $table) use ($devIdRaw, $page, $pageSize): ?array {
            $cols = self::statsTableColumns($table);
            if (!$cols) {
                return null;
            }
            $idField = self::statsPickColumn($cols, ['id', 'ID', 'Lsh']);
            $devField = self::statsPickColumn($cols, ['DevID', 'DevId', 'DeviceID', 'DeviceId']);
            $statusField = self::statsPickColumn($cols, ['status']);
            $orderField = self::statsPickColumn($cols, ['id', 'create_time', 'CreateTime', 'update_time']);
            if ($orderField === '') {
                $orderField = $idField;
            }
            if ($orderField === '') {
                $orderField = $devField;
            }
            if ($orderField === '') {
                return null;
            }

            $where = ['1=1'];
            $params = [];
            if ($statusField !== '') {
                $where[] = '(' . self::statsQuoteIdent($statusField) . ' <> -1 OR ' . self::statsQuoteIdent($statusField) . ' IS NULL)';
            }
            if ($devIdRaw !== '' && ctype_digit($devIdRaw) && $devField !== '') {
                $where[] = self::statsQuoteIdent($devField) . ' = :dev_id';
                $params[':dev_id'] = (int)$devIdRaw;
            }

            $whereSql = implode(' AND ', $where);
            $orderSql = 'ORDER BY ' . self::statsQuoteIdent($orderField) . ' DESC';
            $rows = self::dvCrud($table)->selectByRawCondition($whereSql, $orderSql, $params);
            if (!is_array($rows)) {
                $rows = [];
            }

            $total = count($rows);
            $offset = ($page - 1) * $pageSize;
            if ($offset < 0) {
                $offset = 0;
            }
            $info = array_slice($rows, $offset, $pageSize);
            return [
                'info' => array_values($info),
                'page' => [
                    'total' => $total,
                    'p_n' => $pageSize > 0 ? (int)ceil($total / $pageSize) : 0,
                    'p' => $page,
                ],
            ];
        };

        // New deployments write command records to dcim-devicectrlrecord.
        // Legacy deployments may still use dcim-doorzktcmd.
        $result = null;
        $tables = ['dcim-devicectrlrecord', 'dcim-doorzktcmd'];
        foreach ($tables as $tbl) {
            try {
                $one = $queryOneTable($tbl);
            } catch (\Throwable $e) {
                $one = null;
            }
            if (!is_array($one)) {
                continue;
            }
            if ($result === null) {
                $result = $one;
            }
            if (!empty($one['info'])) {
                $result = $one;
                break;
            }
        }

        if ($result === null) {
            $result = ['info' => [], 'page' => ['total' => 0, 'p_n' => 0, 'p' => $page]];
        }
        O_E($result, tp_msg_success(), 100, !empty($result['info']) ? count($result['info']) : false);
    }
}

