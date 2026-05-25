<?php
    // 调试开关：需要在页面直接看到日志时设为 true
    $PAGE_DEBUG = true;
    if ($PAGE_DEBUG) {
        error_reporting(E_ERROR);
        ini_set('display_errors', '1');
    }

    // 可选加载 Composer 自动加载（若存在）
    $vendorAutoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($vendorAutoload)) {
        require_once $vendorAutoload;
    }

    /**
     * 创建 PDO 连接，兼容 MySQL / openGauss / PG
     */
    function create_pdo($cfg) {
        $type = strtolower($cfg['type'] ?? 'dameng');
        $host = $cfg['host'] ?? 'localhost';
        $port = $cfg['port'] ?? ($type === 'opengauss' || $type === 'pgsql' ? 5432 : (($type === 'dameng' || $type === 'dm') ? 5236 : 3306));
        $db   = $cfg['name'] ?? '';
        $user = $cfg['user'] ?? '';
        $pass = $cfg['password'] ?? '';
        $charset = $cfg['charset'] ?? 'UTF8';
        if (($type === 'dameng' || $type === 'dm') && strcasecmp((string)$user, 'dcim') === 0) {
            $user = 'DCIM';
        }
        if ($type === 'opengauss' || $type === 'pgsql') {
            $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
        } elseif ($type === 'dameng' || $type === 'dm') {
            $schema = $cfg['schema'] ?? $db;
            $dsn = "dm:host={$host};port={$port};schema={$schema}";
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        return new PDO($dsn, $user, $pass, $options);
    }

    /**
     * 从 dbconfig.json 读取数据库配置
     */
    function loadDbConfig() {
        $openBaseDir = (string) ini_get('open_basedir');
        $candidates = [
            __DIR__ . '/../dbconfig.json',
            __DIR__ . '/../../dbconfig.json',
            __DIR__ . '/../../../dbconfig.json',
            __DIR__ . '/../../../../www/dbconfig.json',
            __DIR__ . '/../../../../dbconfig.json',
            '/www/wwwroot/localhost_8080/wwwroot/dbconfig.json',
            '/www/dbconfig.json',
        ];

        $normalizePath = function ($path) {
            $path = str_replace('\\', '/', (string) $path);
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

        $isAllowedByOpenBaseDir = function ($path) use ($openBaseDir, $normalizePath) {
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
                if ($candidate === $allowed || $allowed === '/') {
                    return true;
                }
                if (strncmp($candidate, $allowed . '/', strlen($allowed) + 1) === 0) {
                    return true;
                }
            }
            return false;
        };

        foreach ($candidates as $file) {
            if (!$isAllowedByOpenBaseDir($file)) {
                if (!empty($GLOBALS['PAGE_DEBUG'])) {
                    error_log("<pre>dbconfig skipped by open_basedir: " . htmlspecialchars($file) . "</pre>");
                }
                continue;
            }
            if (file_exists($file)) {
                $content = file_get_contents($file);
                $cfg = json_decode($content, true);
                if (is_array($cfg)) {
                    if (!empty($GLOBALS['PAGE_DEBUG'])) {
                        error_log("<pre>dbconfig loaded from {$file}: " . htmlspecialchars(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . "</pre>");
                    }
                    return $cfg;
                }
            }
        }
        if (!empty($GLOBALS['PAGE_DEBUG'])) {
            error_log("<pre>dbconfig not found in candidates: " . htmlspecialchars(implode(',', $candidates)) . "</pre>");
        }
        return [];
    }

    $dbcfg = loadDbConfig();
    if ($PAGE_DEBUG) {
        error_log("<pre>dbconfig decoded: " . htmlspecialchars(json_encode($dbcfg, JSON_UNESCAPED_UNICODE)) . "</pre>");
    }
    if (empty($dbcfg)) {
        // 未找到配置文件，使用默认 openGauss 配置作为兜底
        $dbcfg = [
            'type'     => 'dameng',
            'host'     => '127.0.0.1',
            'port'     => 5236,
            'name'     => 'dcim',
            'user'     => 'DCIM',
            'password' => '3seckmG7eKstTCRz5',
            'schema'   => 'DCIM',
        ];
        if ($PAGE_DEBUG) {
            error_log("<pre>dbconfig fallback: " . htmlspecialchars(json_encode($dbcfg, JSON_UNESCAPED_UNICODE)) . "</pre>");
        }
    }

    // 解决跨域问题
    header("Access-Control-Allow-Origin:*");
    header("Access-Control-Allow-Methods:GET, POST, DELETE, PUT");
    header("Access-Control-Allow-Headers:DNT,X-Mx-ReqToken,Keep-Alive,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type, Accept-Language, Origin, Accept-Encoding");
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        header('Access-Control-Allow-Headers:x-requested-with,content-type,token');
        exit("ok");
    }

    // 成功返回
    function setJson($txt,$data){
        header('Content-Type:application/json');
        $json = json_encode(array(
            "code"=>100,
            "msg"=>$txt,
            "data"=>$data
        ),JSON_UNESCAPED_UNICODE);
        echo($json);
    }
    // 错误返回
    function errorJson($txt){
        header('Content-Type:application/json');
        $json = json_encode(array(
            "code"=>400,
            "msg"=>$txt
        ),JSON_UNESCAPED_UNICODE);
        echo($json);
    }
    // 捕获异常错误
    function getError($opType){
        $errTxt = '';
        $error = error_get_last();
        if ($error && $error['type'] === E_WARNING) { // 检查错误类型是否为警告
            $errTxt = $opType . '失败:' . $error['message'];
        } else {
            $errTxt = $opType . '失败';
        }
        return $errTxt;
    }

?>
