<?php
ob_start(function ($buffer) {
    // Some included PHP files are saved with UTF-8 BOM, which pollutes JSON output.
    return preg_replace('/^(?:\xEF\xBB\xBF|\xE2\x80\x8B|\xC2\xA0)+/', '', $buffer, 1);
});

$__dcimLogFile = __DIR__ . '/logfile.log';
@file_put_contents(
    $__dcimLogFile,
    '[' . date('Y-m-d H:i:s') . '] [ENTRY] ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . PHP_EOL,
    FILE_APPEND
);

function applyCorsHeaders()
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, Auth, Origin');
    header('Access-Control-Allow-Credentials: true');
}

applyCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

// Compatibility shim: normalize JSON body into $_POST/$_REQUEST so legacy
// handlers can read fields the same way as form-encoded payloads.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        $rawBody = @file_get_contents('php://input');
        if (is_string($rawBody) && trim($rawBody) !== '') {
            $GLOBALS['__dcim_raw_json_body'] = $rawBody;
            $_SERVER['DCIM_RAW_JSON_BODY'] = $rawBody;
            $jsonData = json_decode($rawBody, true);
            if (is_array($jsonData)) {
                $_POST = array_merge($_POST, $jsonData);
                $_REQUEST = array_merge($_REQUEST, $jsonData);
            }
        }
    }
}

error_reporting(E_ALL);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Log file path
ini_set('log_errors', 1);
ini_set('error_log', './logfile.log');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function requireController($path)
{
    if (is_file($path)) {
        require_once $path;
        return;
    }
    error_log('Missing controller: ' . $path);
}

$controllerDir = dirname(__DIR__) . '/src/controllers';
if (!is_dir($controllerDir)) {
    error_log('Missing controller directory: ' . $controllerDir);
} else {
    spl_autoload_register(function ($class) use ($controllerDir) {
        if (!is_string($class) || $class === '') {
            return;
        }

        $directPath = $controllerDir . DIRECTORY_SEPARATOR . $class . '.php';
        if (is_file($directPath)) {
            requireController($directPath);
            return;
        }

        static $caseInsensitiveMap = null;
        if ($caseInsensitiveMap === null) {
            $caseInsensitiveMap = [];
            foreach (scandir($controllerDir) as $file) {
                if (substr($file, -4) !== '.php') {
                    continue;
                }
                $caseInsensitiveMap[strtolower(pathinfo($file, PATHINFO_FILENAME))] = $controllerDir . DIRECTORY_SEPARATOR . $file;
            }
        }

        $matchedPath = $caseInsensitiveMap[strtolower($class)] ?? null;
        if ($matchedPath !== null) {
            requireController($matchedPath);
        }
    });
}

require dirname(__DIR__) . '/src/config.php';
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/routes.php';

// Ensure CORS headers are present for all routed responses.
Flight::before('start', function (&$params, &$output) {
    applyCorsHeaders();
    // $request = Flight::request();
    // error_log("Request: " . print_r($request, true));
    // error_log("Request Method: " . $request->method);
    // error_log("Request URL: " . $request->url);
    // error_log("Request Body: " . json_encode($request->data->getData()));
});

Flight::start();
