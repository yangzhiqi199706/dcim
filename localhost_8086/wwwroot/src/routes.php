<?php
Flight::route('POST /login', array('AppController', 'authLoginCompat'));
Flight::route('POST /logout', array('AppController', 'authLogoutCompat'));

$commonCrudRoute = function (string $method, bool $useRequestTable = false) {
    return function (...$args) use ($method, $useRequestTable) {
        $table = 'dcim-person';
        if ($useRequestTable) {
            $reqTable = Flight::request_data('table', 'dcim-person');
            if (is_string($reqTable) && trim($reqTable) !== '') {
                $table = $reqTable;
            } elseif (is_object($reqTable) && method_exists($reqTable, '__toString')) {
                $tmp = trim((string)$reqTable);
                if ($tmp !== '') {
                    $table = $tmp;
                }
            }
        }
        if (!is_string($table) || trim($table) === '') {
            $table = 'dcim-person';
        }
        $controller = new CrudController($table);
        return $controller->{$method}();
    };
};

Flight::route('POST /upload', $commonCrudRoute('uploads'));
Flight::route('GET /backup', $commonCrudRoute('backup'));

Flight::route('POST /_internal/applyViews', array('AppController', 'applyViews'));
Flight::route('POST /import', $commonCrudRoute('importExcel', true));
Flight::route('POST /import-mapped', $commonCrudRoute('importMapped', true));
Flight::route('POST /export-filtered-zip', $commonCrudRoute('exportByFilter', true));
Flight::route('POST /php/@script', array('PagePhpController', 'dispatchPlaceholder'));
Flight::route('POST /public/php/@script', array('PagePhpController', 'dispatchPlaceholder'));

// Legacy route registry is centralized in route_overrides.php.
$legacyGetMap = [];
$legacyRouteKeys = [];

// Prefer explicit legacy override mapping when provided.
$legacyOverridesLoaded = 0;
$legacyOverridesRegistered = 0;
$legacyOverridesFile = __DIR__ . '/route_overrides.php';
if (is_file($legacyOverridesFile)) {
    $legacyOverrides = require $legacyOverridesFile;
    if (is_array($legacyOverrides)) {
        foreach ($legacyOverrides as $routeKey => $target) {
            if (!is_string($routeKey) || strpos($routeKey, ' ') === false) {
                continue;
            }
            if (!is_array($target) || count($target) !== 2) {
                continue;
            }
            $legacyOverridesLoaded++;
            if (isset($legacyRouteKeys[$routeKey])) {
                continue;
            }
            $className = (string)$target[0];
            $func = (string)$target[1];
            if ($className === '' || $func === '' || !class_exists($className)) {
                continue;
            }
            try {
                $methodRef = new ReflectionMethod($className, $func);
            } catch (\Throwable $e) {
                continue;
            }
            if ($methodRef->isStatic()) {
                $handler = [$className, $func];
            } else {
                $handler = function (...$args) use ($className, $func) {
                    $controller = new $className();
                    return $controller->{$func}(...$args);
                };
            }
            Flight::route($routeKey, $handler);
            $legacyRouteKeys[$routeKey] = true;
            $legacyOverridesRegistered++;
            [$method, $path] = explode(' ', $routeKey, 2);
            if ($method === 'GET') {
                $legacyGetMap[$path] = $handler;
            }
        }
    }
}
Flight::map('notFound', function () use ($legacyGetMap) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $parsedPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $path = rawurldecode(is_string($parsedPath) ? $parsedPath : '');
    if ($path !== '/' && substr($path, -1) === '/') {
        $path = rtrim($path, '/');
    }
    if (strpos($path, '/index.php/') === 0) {
        $path = substr($path, strlen('/index.php'));
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }
    }
    if ($method === 'GET' && isset($legacyGetMap[$path])) {
        call_user_func($legacyGetMap[$path]);
        return;
    }
    $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
    header($protocol . ' 404 Not Found');
    echo '<h1>404 Not Found</h1><h3>The page you have requested could not be found.</h3>';
});

$tables = [
    'dcim-alarmlevellist', 
    'dcim-alarmlist', 
    'dcim-alarmmasterslave', 
    'dcim-alarmnotifylist', 
    'dcim-alarmnotifymode', 
    'dcim-alarmparam', 
    'dcim-alarmsmscontrol', 
    'dcim-alarmsmssearch', 
    'dcim-alarmtype', 
    'dcim-alarmupgrade', 
    'dcim-area', 
    'dcim-asset', 
    'dcim-assetattr', 
    'dcim-assetchangelog', 
    'dcim-assetcheckplan', 
    'dcim-assetcheckplanmodel', 
    'dcim-assetcheckresult', 
    'dcim-assetdeal', 
    'dcim-assetgrounding', 
    'dcim-assetinstall', 
    'dcim-assetmsg', 
    'dcim-assetprivate', 
    'dcim-assetputout', 
    'dcim-assetrepair', 
    'dcim-assettype', 
    'dcim-assettypeattr', 
    'dcim-bc', 
    'dcim-bcground', 
    'dcim-brand', 
    'dcim-brandmodel', 
    'dcim-brandmodelattr', 
    'dcim-cabinet', 
    'dcim-cabinetu', 
    'dcim-camera', 
    'dcim-camerasetting', 
    'dcim-capacityday', 
    'dcim-capacitylevel', 
    'dcim-capacitymodel', 
    'dcim-capacityplan', 
    'dcim-collectordata', 
    'dcim-company', 
    'dcim-consumablerecord', 
    'dcim-consumables', 
    'dcim-department', 
    'dcim-devcommondsendlist', 
    'dcim-device', 
    'dcim-deviceclass', 
    'dcim-devicecommand', 
    'dcim-devicectrlrecord', 
    'dcim-deviceparam', 
    'dcim-deviceprotocol', 
    'dcim-deviceprotocolctrl', 
    'dcim-devicesnmp', 
    'dcim-devicesnmpalarm', 
    'dcim-dmpage', 
    'dcim-doorattlog', 
    'dcim-doorcurstate', 
    'dcim-doorerrorlog', 
    'dcim-dooroperlog', 
    'dcim-doorparam', 
    'dcim-doorpower', 
    'dcim-doorrecordadk', 
    'dcim-dooruser', 
    'dcim-electric', 
    'dcim-electricprice', 
    'dcim-faultsubtype', 
    'dcim-faulttype', 
    'dcim-key', 
    'dcim-keyrecord', 
    'dcim-knowledge', 
    'dcim-maintenance', 
    'dcim-menu', 
    'dcim-nyclass', 
    'dcim-nydnrecord', 
    'dcim-nydnrecordday', 
    'dcim-nyrecord', 
    'dcim-nyrecordday', 
    'dcim-onduty', 
    'dcim-ondutylog', 
    'dcim-order', 
    'dcim-orderrecord', 
    'dcim-param', 
    'dcim-paramday', 
    'dcim-person', 
    'dcim-persongroup', 
    'dcim-preemption', 
    'dcim-role', 
    'dcim-route', 
    'dcim-server', 
    'dcim-setting', 
    'dcim-smsrecv', 
    'dcim-spareparts', 
    'dcim-spareuserecord', 
    'dcim-store', 
    'dcim-supplier', 
    'dcim-syslog', 
    'dcim-tableair', 
    'dcim-tablecapacity', 
    'dcim-tablegeneral', 
    'dcim-tablepower', 
    'dcim-tableups', 
    'dcim-tablewsd', 
    'dcim-tenant', 
    'dcim-tenantu', 
    'dcim-tenanturecord', 
    'dcim-tool', 
    'dcim-toolrecord', 
    'dcim-udevice', 
    'dcim-udevicestatus', 
    'dcim-wbrecord', 
    'dcim-whplan', 
    'dcim-whtask', 
    'dcim-xjmodel', 
    'dcim-xjpoint', 
    'dcim-xjtasksign',
    'dcim-xjtasksign-device',
    'dcim-xjtask', 
    'dcim-xjtaskdetail',
    'dcim-alarmnotifywindow',
    'dcim-alarmnotifywindow3'
];

$views = [
    'dcim-alarmnotifymode-deviceview', 
    'dcim-alarmnotifymode-ipmonitorview', 
    'dcim-assetputout-areaview', 
    'dcim-command-deviceinfoview', 
    'dcim-command-deviceview', 
    'dcim-paramcollectvalview'
];

$storedprocedures = [];

$tableAndViews = array_merge($tables, $views, $storedprocedures);

// Build handlers lazily to avoid instantiating every CrudController at bootstrap time.
$crudHandler = function (string $table, string $method) {
    return function (...$args) use ($table, $method) {
        $controller = new CrudController($table);
        return $controller->{$method}(...$args);
    };
};

foreach ($tableAndViews as $table) {
    Flight::route("POST /$table/update/@id", $crudHandler($table, 'update'));
    Flight::route("GET /$table/delete/@id", $crudHandler($table, 'delete'));
    Flight::route("POST /$table/save", $crudHandler($table, 'save'));
    Flight::route("GET /$table/filter", $crudHandler($table, 'getFiltered'));  // Add this line for multi-condition query
    Flight::route("GET /$table/@id", $crudHandler($table, 'getById'));
    Flight::route("GET /$table", $crudHandler($table, 'getAll'));
    Flight::route("POST /$table", $crudHandler($table, 'create'));
}

// Legacy ThinkPHP route compatibility layer (inline, no external file dependency).
$legacyControllerClasses = [
    'AppController',
    'AssetsController',
    'TableConfigController',
    'WorkOrderController',
    'CrudController',
];
$legacySeen = [];
$legacyRegistered = 0;
$legacySkipped = 0;
foreach ($legacyControllerClasses as $className) {
    if (!class_exists($className)) {
        $legacySkipped++;
        continue;
    }
    try {
        $ref = new ReflectionClass($className);
        $file = $ref->getFileName();
    } catch (\Throwable $e) {
        $legacySkipped++;
        continue;
    }
    if (!is_string($file) || $file === '' || !is_file($file)) {
        $legacySkipped++;
        continue;
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines) || $lines === []) {
        $legacySkipped++;
        continue;
    }
    $pendingRoutes = [];
    foreach ($lines as $line) {
        $trimmed = trim((string)$line);
        if ($trimmed === '') {
            continue;
        }
        if (preg_match('/^\/\/\s*(GET|POST|PUT|DELETE|PATCH|OPTIONS)\s+(\S+)/i', $trimmed, $m) === 1) {
            $method = strtoupper((string)$m[1]);
            $path = trim((string)$m[2]);
            if ($path !== '' && strpos($path, '/') === 0) {
                $pendingRoutes[] = [$method, $path];
            }
            continue;
        }
        if (preg_match('/^public\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $trimmed, $m) !== 1) {
            continue;
        }
        $func = (string)$m[1];
        if (!$pendingRoutes) {
            continue;
        }
        $handler = null;
        try {
            $methodRef = $ref->getMethod($func);
            if ($methodRef->isStatic()) {
                $handler = [$className, $func];
            } else {
                $handler = function (...$args) use ($className, $func) {
                    $controller = new $className();
                    return $controller->{$func}(...$args);
                };
            }
        } catch (\Throwable $e) {
            $pendingRoutes = [];
            $legacySkipped++;
            continue;
        }
        foreach ($pendingRoutes as $routeDef) {
            [$method, $path] = $routeDef;
            $routeKey = $method . ' ' . $path;
            if (isset($legacySeen[$routeKey]) || isset($legacyRouteKeys[$routeKey])) {
                $legacySkipped++;
                continue;
            }
            Flight::route($routeKey, $handler);
            $legacySeen[$routeKey] = true;
            $legacyRegistered++;
        }
        $pendingRoutes = [];
    }
}
$routeDebug = filter_var((string) getenv('DCIM_ROUTE_DEBUG'), FILTER_VALIDATE_BOOLEAN);
if ($routeDebug) {
    $msg = sprintf(
        '[routes.inline_legacy] overrides_loaded=%d overrides_registered=%d registered=%d skipped=%d',
        $legacyOverridesLoaded,
        $legacyOverridesRegistered,
        $legacyRegistered,
        $legacySkipped
    );
    if (function_exists('dcim_debug_log')) {
        dcim_debug_log($msg);
    } else {
        error_log($msg);
    }
}






