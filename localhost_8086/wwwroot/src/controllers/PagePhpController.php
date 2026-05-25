<?php

class PagePhpController
{
    private const ICON_BASE64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAAXNSR0IArs4c6QAAAQlJREFUWEdjZBhgwDjA9jNgOMDVM9aegYlBjNoOY2Rk/M/I8O/2zi1LLiKbjeIAN5/YPEYGhonUthzJvL//GBmcd29efBAmhuIAd5+Y2QwMjCk0dADD//+M2bu2LpqG1QEeHgkK/1j+TGP4zyhK0BGMDNqMDAycUHWv//9neEhQD8P/G7++/sw8cGD1F6wOIGwAQoWbT+w1RgYGTajI7J1bFqeRon/UAaMhMBoCoyEwGgKjITAaAqMhMBoCoyFAcQi4+8SCOhh6IIP+//8/bdfWJdn0bZR6xRYyMP7vZWRg/Paf4b/brq1LjtHVASDL3HxjFZn//Piyffvq1+RYDtIz+Dqn5PqEXH0A9hGnIbhiy9wAAAAASUVORK5CYII=';

    private const ZIP_MIME_TYPES = [
        'application/zip',
        'application/x-zip-compressed',
        'multipart/x-zip',
    ];

    private const PAGE_IMPORT_MIME_TYPES = [
        'text/plain',
    ];

    private const IMAGE_MIME_TYPES = [
        'image/jpg',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/bmp',
        'image/svg',
    ];

    private const PROTECTED_TPL_NAMES = [
        'UPS',
    ];

    private const MAX_UPLOAD_BYTES = 20971520;

    private static function crud(string $table): CrudController
    {
        return new CrudController($table);
    }

    private static function publicPath(string $suffix = ''): string
    {
        $base = rtrim(dirname(__DIR__, 2) . '/public', '/\\');
        $trimmed = ltrim($suffix, '/\\');
        return $trimmed === '' ? $base : ($base . '/' . $trimmed);
    }

    private static function resolvePublicDir(array $candidates, bool $createIfMissing = false): array
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
            self::ensureDir($primaryPath, 'error.create_dir_failed_with_path');
            return [
                'path' => $primaryPath,
                'url' => (string)($primary['url'] ?? ''),
            ];
        }

        self::respondError('error.directory_not_found');
        return [
            'path' => $primaryPath,
            'url' => (string)($primary['url'] ?? ''),
        ];
    }

    private static function pageDir(bool $createIfMissing = false): string
    {
        $resolved = self::resolvePublicDir([
            ['suffix' => '/images/page', 'url' => '../images/page/'],
            ['suffix' => '/Images/page', 'url' => '../Images/page/'],
        ], $createIfMissing);
        return (string)$resolved['path'];
    }

    private static function uploadDirWithUrl(bool $createIfMissing = false): array
    {
        return self::resolvePublicDir([
            ['suffix' => '/images/uploads', 'url' => '../images/uploads/'],
            ['suffix' => '/Images/uploads', 'url' => '../Images/uploads/'],
            ['suffix' => '/uploads', 'url' => '../uploads/'],
        ], $createIfMissing);
    }

    private static function systemImgDirWithUrl(): array
    {
        return self::resolvePublicDir([
            ['suffix' => '/images/dcim', 'url' => '../images/dcim/'],
            ['suffix' => '/Images/dcim', 'url' => '../Images/dcim/'],
        ], true);
    }

    private static function tplDir(bool $createIfMissing = false): string
    {
        $resolved = self::resolvePublicDir([
            ['suffix' => '/images/pagetpl', 'url' => '../images/pagetpl/'],
            ['suffix' => '/Images/pagetpl', 'url' => '../Images/pagetpl/'],
        ], $createIfMissing);
        return (string)$resolved['path'];
    }

    private static function requestData(): array
    {
        $data = Flight::request_data();
        return is_array($data) ? $data : [];
    }

    private static function requestValue(string $key, $default = '')
    {
        $data = self::requestData();
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
        if (isset($_POST) && is_array($_POST) && array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }
        return $default;
    }

    private static function respondSuccess($data = null, string $msgKey = 'common.success', array $vars = []): void
    {
        json_string_response([
            'code' => 100,
            'msg' => dcim_msg($msgKey, null, $vars),
            'data' => $data,
        ]);
    }

    private static function respondError(string $msgKey, array $vars = [], int $httpCode = 400): void
    {
        json_string_response([
            'code' => $httpCode,
            'msg' => dcim_msg($msgKey, null, $vars),
        ], $httpCode);
    }

    private static function ensureDir(string $dir, string $msgKey): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0755, true)) {
            self::respondError($msgKey, ['path' => $dir]);
        }
    }

    private static function deleteFolder(string $folderPath): void
    {
        if (!is_dir($folderPath)) {
            return;
        }
        $files = glob($folderPath . '/*');
        if (!is_array($files)) {
            return;
        }
        foreach ($files as $file) {
            if (is_dir($file)) {
                self::deleteFolder($file);
                continue;
            }
            @unlink($file);
        }
        @rmdir($folderPath);
    }

    private static function zipFolder(string $source, string $destination): bool
    {
        if (!extension_loaded('zip') || !file_exists($source)) {
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($destination, ZipArchive::CREATE) !== true) {
            return false;
        }
        $sourceReal = realpath($source);
        if ($sourceReal === false) {
            $zip->close();
            return false;
        }
        $sourceReal = str_replace('\\', '/', $sourceReal);
        if (is_dir($sourceReal)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceReal),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($files as $file) {
                $filePath = str_replace('\\', '/', (string)$file);
                if (in_array(substr($filePath, strrpos($filePath, '/') + 1), ['.', '..'], true)) {
                    continue;
                }
                $real = realpath($filePath);
                if ($real === false) {
                    continue;
                }
                $real = str_replace('\\', '/', $real);
                if (is_dir($real)) {
                    $zip->addEmptyDir(str_replace($sourceReal . '/', '', $real . '/'));
                } elseif (is_file($real)) {
                    $zip->addFromString(str_replace($sourceReal . '/', '', $real), (string)file_get_contents($real));
                }
            }
        } elseif (is_file($sourceReal)) {
            $zip->addFromString(basename($sourceReal), (string)file_get_contents($sourceReal));
        }
        return $zip->close();
    }

    private static function decodePageJson(string $raw): array
    {
        $first = json_decode($raw, true);
        if (is_array($first)) {
            return $first;
        }
        if (!is_string($first)) {
            self::respondError('error.json_parse_failed');
        }
        $second = json_decode($first, true);
        if (!is_array($second)) {
            self::respondError('error.json_parse_failed');
        }
        return $second;
    }

    private static function collectPageImages(array $data): array
    {
        $result = [];
        $children = $data['children'][0]['children'] ?? [];
        if (!is_array($children)) {
            return $result;
        }
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            if (($child['attrs']['id'] ?? '') === 'canvasBackground' && !empty($child['attrs']['fillPatternImage'])) {
                $result[] = (string)$child['attrs']['fillPatternImage'];
            }
            $moduleChildren = $child['attrs']['moduleJson']['children'] ?? [];
            if (!is_array($moduleChildren)) {
                continue;
            }
            foreach ($moduleChildren as $moduleChild) {
                if (!is_array($moduleChild) || ($moduleChild['className'] ?? '') !== 'Image') {
                    continue;
                }
                $where = $child['attrs']['moduleJson']['attrs']['where'] ?? [];
                if (is_array($where)) {
                    foreach ($where as $item) {
                        $statusColor = (string)($item['statusSelectColor'] ?? '');
                        if (
                            $statusColor !== '' &&
                            strpos($statusColor, 'data:image') === false &&
                            strpos($statusColor, 'Images/dcim/') === false &&
                            strpos($statusColor, 'images/dcim/') === false
                        ) {
                            $result[] = $statusColor;
                        }
                    }
                }
                $img = (string)($moduleChild['attrs']['image'] ?? '');
                if (
                    $img !== '' &&
                    strpos($img, 'data:image') === false &&
                    strpos($img, 'Images/dcim/') === false &&
                    strpos($img, 'images/dcim/') === false
                ) {
                    $result[] = $img;
                }
            }
        }
        return $result;
    }

    public static function dispatchPlaceholder(string $script): void
    {
        $normalized = strtolower(trim((string)$script));
        $normalized = preg_replace('/\.php$/i', '', $normalized);
        switch ($normalized) {
            case 'export':
                self::exportPage();
                return;
            case 'exportimport':
                self::importZip();
                return;
            case 'imgdata':
                self::imgData();
                return;
            case 'import':
                self::importPageFile();
                return;
            case 'savepage':
                self::savePage();
                return;
            case 'savetpl':
                self::saveTpl();
                return;
            case 'upload':
                self::uploadImage();
                return;
            default:
                self::respondError('error.unsupported_php_interface', ['script' => (string)$script]);
        }
    }

    public static function exportPage(): void
    {
        $data = self::requestData();
        $pageTxt = trim((string)($data['pageTxt'] ?? ''));
        if ($pageTxt === '') {
            self::respondError('error.page_name_required');
        }

        $pageDir = self::pageDir(false);
        $imgRoot = self::publicPath('/');
        $txtFile = $pageDir . '/' . $pageTxt . '.txt';
        if (!is_file($txtFile)) {
            self::respondError('error.target_file_not_found', ['target' => $pageTxt . '.txt']);
        }

        $workDir = $pageDir . '/' . $pageTxt;
        if (is_dir($workDir)) {
            self::deleteFolder($workDir);
        }
        self::ensureDir($workDir, 'error.create_folder_failed_with_path');

        $imgDir = $workDir . '/img';
        self::ensureDir($imgDir, 'error.create_image_folder_failed');

        $raw = @file_get_contents($txtFile);
        if ($raw === false) {
            self::respondError('error.failed_read_file');
        }
        $decoded = self::decodePageJson((string)$raw);
        $images = self::collectPageImages($decoded);

        foreach ($images as $imgRef) {
            $relative = ltrim(str_replace(['../', '..\\'], '', (string)$imgRef), '/\\');
            $source = rtrim($imgRoot, '/\\') . '/' . $relative;
            if (!is_file($source)) {
                $relativeAlt = str_replace('Images/', 'images/', $relative);
                $sourceAlt = rtrim($imgRoot, '/\\') . '/' . $relativeAlt;
                if (is_file($sourceAlt)) {
                    $source = $sourceAlt;
                }
            }
            $destination = $imgDir . '/' . basename($relative);
            if (!is_file($source)) {
                self::respondError('error.target_file_not_found', ['target' => (string)$imgRef]);
            }
            if (!@copy($source, $destination)) {
                self::respondError('error.copy_failed_with_target', ['target' => (string)$imgRef]);
            }
        }

        if (!@copy($txtFile, $workDir . '/' . $pageTxt . '.txt')) {
            self::respondError('error.copy_failed_with_target', ['target' => $pageTxt . '.txt']);
        }

        $destinationZip = $workDir . '.zip';
        if (is_file($destinationZip)) {
            @unlink($destinationZip);
        }
        if (!self::zipFolder($workDir, $destinationZip)) {
            self::respondError('error.zip_failed_with_target', ['target' => $destinationZip]);
        }

        self::deleteFolder($workDir);
        self::respondSuccess($destinationZip);
    }

    public static function importZip(): void
    {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            self::respondError('error.file_not_found');
        }
        if (!is_uploaded_file((string)($_FILES['file']['tmp_name'] ?? ''))) {
            self::respondError('error.file_not_found');
        }

        $file = $_FILES['file'];
        $originName = basename((string)($file['name'] ?? ''));
        if ($originName === '') {
            self::respondError('error.file_not_found');
        }

        $uploadDir = self::pageDir(true);
        $uploadResolved = self::uploadDirWithUrl(true);
        $imgDir = (string)$uploadResolved['path'];

        $zipPath = $uploadDir . '/' . $originName;
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        $ext = strtolower((string)pathinfo($originName, PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            self::respondError('error.only_zip_allowed');
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string)finfo_file($finfo, (string)$file['tmp_name']);
                finfo_close($finfo);
                if ($mime !== '' && !in_array($mime, self::ZIP_MIME_TYPES, true)) {
                    self::respondError('error.invalid_file_mime');
                }
            }
        }

        if (!@move_uploaded_file((string)$file['tmp_name'], $zipPath)) {
            self::respondError('error.upload_file_failed');
        }

        $extractPath = $uploadDir . '/_import_' . uniqid('', true);
        self::ensureDir($extractPath, 'error.extract_folder_create_failed_with_path');

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            self::deleteFolder($extractPath);
            @unlink($zipPath);
            self::respondError('error.zip_parse_failed_with_target', ['target' => $originName]);
        }

        try {
            if (!$zip->extractTo($extractPath)) {
                self::respondError('error.zip_parse_failed_with_target', ['target' => $originName]);
            }
        } finally {
            $zip->close();
        }

        $fileList = scandir($extractPath);
        if ($fileList === false) {
            self::deleteFolder($extractPath);
            @unlink($zipPath);
            self::respondError('error.read_template_dir_failed');
        }

        $pageTxt = '';
        foreach ($fileList as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $sourcePath = $extractPath . '/' . $entry;
            if (is_file($sourcePath)) {
                $entryExt = strtolower((string)pathinfo($entry, PATHINFO_EXTENSION));
                if ($entryExt !== 'txt') {
                    continue;
                }
                $targetPath = $uploadDir . '/' . $entry;
                if (is_file($targetPath)) {
                    self::deleteFolder($extractPath);
                    @unlink($zipPath);
                    self::respondError('error.txt_file_already_exists', ['file' => $entry]);
                }
                if (!@copy($sourcePath, $targetPath)) {
                    self::deleteFolder($extractPath);
                    @unlink($zipPath);
                    self::respondError('error.copy_failed_with_target', ['target' => $entry]);
                }
                $pageTxt = (string)pathinfo($entry, PATHINFO_FILENAME);
                continue;
            }

            if (is_dir($sourcePath) && strtolower($entry) === 'img') {
                $imgList = scandir($sourcePath);
                if ($imgList === false) {
                    self::deleteFolder($extractPath);
                    @unlink($zipPath);
                    self::respondError('error.directory_not_found');
                }
                foreach ($imgList as $imgName) {
                    if ($imgName === '.' || $imgName === '..') {
                        continue;
                    }
                    $imgSource = $sourcePath . '/' . $imgName;
                    if (!is_file($imgSource)) {
                        continue;
                    }
                    $imgTarget = $imgDir . '/' . $imgName;
                    if (!@copy($imgSource, $imgTarget)) {
                        self::deleteFolder($extractPath);
                        @unlink($zipPath);
                        self::respondError('error.copy_failed_with_target', ['target' => $imgName]);
                    }
                }
            }
        }

        if ($pageTxt === '') {
            self::deleteFolder($extractPath);
            @unlink($zipPath);
            self::respondError('error.missing_required_params');
        }

        $nameParts = explode('[', $originName, 2);
        $pageName = trim((string)($nameParts[0] ?? $pageTxt));
        if ($pageName === '') {
            $pageName = $pageTxt;
        }
        $pageIndex = '1';
        if (isset($nameParts[1])) {
            $tail = explode(']', $nameParts[1], 2);
            $pageIndexCandidate = trim((string)($tail[0] ?? ''));
            if ($pageIndexCandidate !== '') {
                $pageIndex = $pageIndexCandidate;
            }
        }

        self::crud('dcim-dmpage')->legacyInsert([
            'PageName' => $pageName,
            'PageIndex' => $pageIndex,
            'PageType' => 1,
            'PageTxt' => $pageTxt,
        ]);

        self::deleteFolder($extractPath);
        @unlink($zipPath);
        self::respondSuccess(null, 'error.import_success');
    }

    public static function imgData(): void
    {
        $action = trim((string)self::requestValue('action', ''));
        $name = trim((string)self::requestValue('name', ''));
        switch ($action) {
            case 'system':
                self::getSystemImg();
                return;
            case 'upload':
                self::getUploadImg();
                return;
            case 'del':
                self::delUploadImg();
                return;
            case 'tpl':
                self::getTpl();
                return;
            case 'deltpl':
                self::delTpl();
                return;
            case 'page':
                self::getPage($name);
                return;
            case 'delpage':
                self::delPage();
                return;
            default:
                self::respondError('error.invalid_action');
        }
    }

    private static function getSystemImg(): void
    {
        $resolved = self::systemImgDirWithUrl();
        $dir = (string)$resolved['path'];
        $imgBaseUrl = (string)$resolved['url'];
        if (!is_dir($dir)) {
            self::respondSuccess([]);
        }
        $fileList = scandir($dir);
        if ($fileList === false) {
            self::respondSuccess([]);
        }
        $data = [];
        foreach ($fileList as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (is_file($dir . '/' . $file)) {
                $data[] = ['imgUrl' => $imgBaseUrl . $file];
            }
        }
        self::respondSuccess($data);
    }

    private static function getUploadImg(): void
    {
        $resolved = self::uploadDirWithUrl(true);
        $dir = (string)$resolved['path'];
        $imgBaseUrl = (string)$resolved['url'];
        if (!is_dir($dir)) {
            self::respondSuccess([]);
        }
        $fileList = scandir($dir);
        if ($fileList === false) {
            self::respondSuccess([]);
        }
        $data = [];
        foreach ($fileList as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (is_file($dir . '/' . $file)) {
                $data[] = ['imgUrl' => $imgBaseUrl . $file];
            }
        }
        self::respondSuccess($data);
    }

    private static function delUploadImg(): void
    {
        $img = trim((string)self::requestValue('img', ''));
        $resolved = self::uploadDirWithUrl(false);
        $dir = (string)$resolved['path'];
        $segments = $img === '' ? [] : explode('/', str_replace('\\', '/', $img));
        $targetName = $segments ? (string)end($segments) : '';
        if ($targetName === '') {
            self::respondError('error.file_not_found');
        }
        $targetPath = $dir . '/' . $targetName;
        if (!is_file($targetPath)) {
            self::respondError('error.file_not_found');
        }
        if (!@unlink($targetPath)) {
            self::respondError('error.delete_file_failed');
        }
        self::respondSuccess(1);
    }

    private static function getTpl(): void
    {
        $dir = self::tplDir(true);
        $fileList = scandir($dir);
        if ($fileList === false) {
            self::respondError('error.read_template_dir_failed');
        }
        $data = [];
        foreach ($fileList as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (!mb_check_encoding($file, 'UTF-8')) {
                continue;
            }
            if (preg_match('/[<>:"\/\\\\|?*]/', $file) === 1) {
                continue;
            }
            $fullPath = $dir . '/' . $file;
            if (!is_file($fullPath)) {
                continue;
            }
            $moduleJson = @file_get_contents($fullPath);
            if ($moduleJson === false) {
                continue;
            }
            $data[] = [
                'moduleName' => (string)pathinfo($file, PATHINFO_FILENAME),
                'iconBase64' => self::ICON_BASE64,
                'moduleJson' => $moduleJson,
            ];
        }
        self::respondSuccess($data);
    }

    private static function delTpl(): void
    {
        $name = trim((string)self::requestValue('name', ''));
        if ($name === '') {
            self::respondError('error.page_name_required');
        }
        if (in_array($name, self::PROTECTED_TPL_NAMES, true)) {
            self::respondError('error.builtin_template_cannot_delete');
        }
        $filePath = self::tplDir(false) . '/' . $name . '.txt';
        if (!is_file($filePath)) {
            self::respondError('error.file_not_found');
        }
        if (!@unlink($filePath)) {
            self::respondError('error.delete_file_failed');
        }
        self::respondSuccess(1);
    }

    private static function getPage(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            self::respondError('error.page_name_required');
        }
        $filePath = self::pageDir(false) . '/' . $name . '.txt';
        if (!is_file($filePath)) {
            self::respondSuccess(null, 'error.file_not_found');
        }
        $moduleJson = @file_get_contents($filePath);
        if ($moduleJson === false) {
            self::respondError('error.failed_read_file');
        }
        self::respondSuccess([[
            'moduleName' => $name,
            'iconBase64' => self::ICON_BASE64,
            'moduleJson' => $moduleJson,
        ]]);
    }

    private static function delPage(): void
    {
        $name = trim((string)self::requestValue('name', ''));
        if ($name === '') {
            self::respondError('error.page_name_required');
        }
        $pageDir = self::pageDir(true);
        $filePath = $pageDir . '/' . $name . '.txt';
        $backupPath = $pageDir . '/backup';
        self::ensureDir($backupPath, 'error.create_backup_folder_failed_with_path');

        if (!is_file($filePath)) {
            self::respondSuccess(1);
        }
        if (!@copy($filePath, $backupPath . '/' . $name . '.txt')) {
            self::respondError('error.copy_failed_with_target', ['target' => $name . '.txt']);
        }
        if (!@unlink($filePath)) {
            self::respondError('error.delete_file_failed');
        }
        self::respondSuccess(1);
    }

    public static function importPageFile(): void
    {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            self::respondError('error.file_not_found');
        }
        if (!is_uploaded_file((string)($_FILES['file']['tmp_name'] ?? ''))) {
            self::respondError('error.file_not_found');
        }

        $file = $_FILES['file'];
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            self::respondError('error.file_upload_failed');
        }

        $mime = (string)($file['type'] ?? '');
        if (!in_array($mime, self::PAGE_IMPORT_MIME_TYPES, true)) {
            self::respondError('error.invalid_file_type_with_type', ['type' => $mime]);
        }

        $destinationFolder = self::pageDir(true);

        $fileName = basename((string)($file['name'] ?? ''));
        $pinfo = pathinfo($fileName);
        $ext = (string)($pinfo['extension'] ?? 'txt');
        $destination = $destinationFolder . '/' . time() . '.' . $ext;

        if (is_file($destination)) {
            self::respondError('error.file_already_exists');
        }
        if (!@move_uploaded_file((string)$file['tmp_name'], $destination)) {
            self::respondError('error.upload_file_failed');
        }

        $newInfo = pathinfo($destination);
        $newName = (string)($newInfo['filename'] ?? '');
        $pageName = (string)($pinfo['filename'] ?? '');
        if ($pageName === '') {
            $pageName = $newName;
        }

        $crud = self::crud('dcim-dmpage');
        $row = $crud->findOne([
            ['PageName', '=', $pageName],
            ['status', '=', 1],
        ]);

        if ($row) {
            $crud->legacyUpdate([
                'id' => $row['id'],
                'PageTxt' => $newName,
            ], [
                'skip_auth' => true,
                'id_required_message' => dcim_msg('common.id_required'),
                'only_fields' => ['PageTxt'],
            ]);
        } else {
            $crud->legacyInsert([
                'PageName' => $pageName,
                'PageIndex' => 1,
                'PageType' => 1,
                'PageTxt' => $newName,
            ]);
        }

        self::respondSuccess($newName);
    }

    public static function uploadImage(): void
    {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            self::respondError('error.file_not_found');
        }
        if (!is_uploaded_file((string)($_FILES['file']['tmp_name'] ?? ''))) {
            self::respondError('error.file_not_found');
        }

        $file = $_FILES['file'];
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            self::respondError('error.file_upload_failed');
        }
        $mime = (string)($file['type'] ?? '');
        if (!in_array($mime, self::IMAGE_MIME_TYPES, true)) {
            self::respondError('error.invalid_file_type_with_type', ['type' => $mime]);
        }

        $uploadResolved = self::uploadDirWithUrl(true);
        $destinationFolder = (string)$uploadResolved['path'];

        $originName = basename((string)($file['name'] ?? ''));
        $pinfo = pathinfo($originName);
        $ext = (string)($pinfo['extension'] ?? 'png');
        $destination = $destinationFolder . '/' . time() . '.' . $ext;
        if (is_file($destination)) {
            self::respondError('error.file_already_exists');
        }
        if (!@move_uploaded_file((string)$file['tmp_name'], $destination)) {
            self::respondError('error.upload_file_failed');
        }

        $nameInfo = pathinfo($destination);
        self::respondSuccess((string)($nameInfo['basename'] ?? ''));
    }

    public static function savePage(): void
    {
        $name = trim((string)self::requestValue('name', ''));
        if ($name === '') {
            self::respondError('error.page_name_required');
        }
        $content = (string)self::requestValue('pagecon', '');
        $destinationFolder = self::pageDir(true);

        $filePath = $destinationFolder . '/' . $name . '.txt';
        if (is_file($filePath) && !@unlink($filePath)) {
            self::respondError('error.delete_file_failed');
        }
        if (@file_put_contents($filePath, $content) === false) {
            self::respondError('common.operation_failed');
        }
        self::respondSuccess(null);
    }

    public static function saveTpl(): void
    {
        $name = trim((string)self::requestValue('name', ''));
        if ($name === '') {
            self::respondError('error.page_name_required');
        }
        $content = (string)self::requestValue('tplcon', '');
        $destinationFolder = self::tplDir(true);

        $filePath = $destinationFolder . '/' . $name . '.txt';
        if (is_file($filePath)) {
            self::respondError('error.file_already_exists');
        }
        if (@file_put_contents($filePath, $content) === false) {
            self::respondError('common.operation_failed');
        }
        self::respondSuccess(null);
    }
}


