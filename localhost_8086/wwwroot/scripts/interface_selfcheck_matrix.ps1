param(
    [string]$WorkspaceRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path,
    [string]$OutputPath = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Test-PatternHit {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Pattern
    )
    if (-not (Test-Path -LiteralPath $Path)) {
        return $false
    }
    return [bool](Select-String -Path $Path -SimpleMatch -Pattern $Pattern -ErrorAction SilentlyContinue)
}

function Resolve-ItemStatus {
    param(
        [Parameter(Mandatory = $true)][array]$Checks,
        [Parameter(Mandatory = $true)][string]$StatusIfMatched,
        [Parameter(Mandatory = $true)][string]$WorkspaceRoot
    )
    foreach ($check in $Checks) {
        $target = Join-Path $WorkspaceRoot $check.file
        foreach ($pattern in $check.patterns) {
            if (-not (Test-PatternHit -Path $target -Pattern $pattern)) {
                return '待补丁'
            }
        }
    }
    return $StatusIfMatched
}

$items = @(
    @{
        endpoint = '/GetHistoryAlarmsKey'
        issue = 'ComboBox=all returns array; no ComboBox returns page object'
        status_if_matched = '待验证'
        checks = @(
            @{ file = 'src/controllers/TableConfigController.php'; patterns = @('public static function getHistoryList()', '$comboAll = strtolower(trim((string)($data[''ComboBox''] ?? ''''))) === ''all'';', 'if ($comboAll) {', 'O_E($result[''info''], tp_msg_success(), 100, 0);', 'O_E($result, tp_msg_success(), 100, $result[''info''] ? count($result[''info'']) : false);') }
        )
    },
    @{
        endpoint = '/GetAlarmMasterSlaveListKey'
        issue = 'Secondary alarm names must include multi-id cases'
        status_if_matched = '待验证'
        checks = @(
            @{ file = 'src/controllers/TableConfigController.php'; patterns = @('public static function getAlarmMasterSlaveList()', 'MasterAlarmName', 'SlaveAlarmName', 'array_merge($masterAlarmNames, $slaveAlarmNames)') }
        )
    },
    @{
        endpoint = '/GetRealAlarmsDetailKey'
        issue = 'Response shape should be {code,msg,data:{},num:0}'
        status_if_matched = '已覆盖'
        checks = @(
            @{ file = 'src/controllers/TableConfigController.php'; patterns = @('public static function getInfo()', 'O_E($detail ?: (object)[], tp_msg_success(), 100, 0);') }
        )
    },
    @{
        endpoint = '/ExportParamValDataKey'
        issue = 'Auto-generate OId for collect and control export rows'
        status_if_matched = '待验证'
        checks = @(
            @{ file = 'src/controllers/AppController.php'; patterns = @('private static function northResolveExportOid(array $row): string', 'if ($exportType === ''oidctl'') {', '''OId'' => self::northResolveExportOid((array)$row),', 'if ($col === ''OId'') {', '$line[$col] = self::northResolveExportOid((array)$row);') }
        )
    },
    @{
        endpoint = '/ExportParamValDataKey'
        issue = 'Control export should include control commands only'
        status_if_matched = '已覆盖'
        checks = @(
            @{ file = 'src/controllers/AppController.php'; patterns = @('if ($exportType === ''oidctl'') {', '$cmdType = trim((string)($data[''commandType''] ?? ($data[''CommandType''] ?? ''2'')));', 'if ($cmdType !== ''2'') {', '$cmdType = ''2'';', '$whereParts[] = ''CommandType = :command_type'';') }
        )
    },
    @{
        endpoint = '/GetKnowledgeBaseListKey,/GetKnowledgeBaseDetailKey'
        issue = 'Missing FaultTypeName and UpdateEmpName'
        status_if_matched = '已覆盖'
        checks = @(
            @{ file = 'src/controllers/TableConfigController.php'; patterns = @('public static function faultSubTypeGetKnowledgeBaseList()', 'FaultTypeName', 'UpdateEmpName', 'public static function faultSubTypeGetKnowledgeBaseDetail()') }
        )
    },
    @{
        endpoint = '/GetAlarmNotifyListKey'
        issue = 'search filter invalid'
        status_if_matched = '待验证'
        checks = @(
            @{ file = 'src/controllers/TableConfigController.php'; patterns = @('public static function getAlarmNotifyList()', '''search_fields'' => $searchFields', '''exact_filters'' => $exactFilters', '''between_filters'' => $betweenFilters') }
        )
    },
    @{
        endpoint = '/GetDeviceProtocolListKey'
        issue = 'Support no-login'
        status_if_matched = '已覆盖'
        checks = @(
            @{ file = 'src/controllers/AppController.php'; patterns = @('public static function getDeviceProtocolList()', '''skip_auth'' => true,') }
        )
    },
    @{
        endpoint = '/GetAssetsSurplusStatisticKey'
        issue = 'Result data empty'
        status_if_matched = '待验证'
        checks = @(
            @{ file = 'src/controllers/AppController.php'; patterns = @('public static function statsGetAssetsSurplusStatistic()', 'if (!$rows) {', '$brandModelCols = self::statsTableColumns(''dcim-brandmodel'');', 'GROUP BY bm.') }
        )
    },
    @{
        endpoint = '/GetSparepartsStatisticKey'
        issue = 'Result data empty'
        status_if_matched = '待验证'
        checks = @(
            @{ file = 'src/controllers/AppController.php'; patterns = @('public static function statsGetSparepartsStatistic()', 'if (!$rows && $statusField !== '''') {', '''SparepartsNumber'' => (int)($row[''scount''] ?? 0)') }
        )
    },
    @{
        endpoint = '/ReleasePreemptionKey'
        issue = 'Set PreStatus to 已释放'
        status_if_matched = '已覆盖'
        checks = @(
            @{ file = 'src/controllers/AssetsController.php'; patterns = @('public static function tenantUReleasePreemption(): void', '$updateData[''PreStatus''] = ''已释放'';') }
        )
    },
    @{
        endpoint = '/CreatePreemptionKey'
        issue = 'Set dcim-cabinetu.UStatus to 预占位'
        status_if_matched = '已覆盖'
        checks = @(
            @{ file = 'src/controllers/AssetsController.php'; patterns = @('public static function tenantUCreatePreemption(): void', '[''UStatus'' => ''预占位'']') }
        )
    },
    @{
        endpoint = '/GetAssetVisualizationKey'
        issue = 'Wrong asset type shown for cabinet details'
        status_if_matched = '待验证'
        checks = @(
            @{ file = 'src/controllers/AssetsController.php'; patterns = @('public static function cabinetAssetVisualization()', 'if (!empty($cabinet[''AssetsId''])) {', 'if (!$asset) {', 'status = 1 AND CabinetId = :cid AND AssetsId IS NOT NULL AND AssetsId <> 0') }
        )
    },
    @{
        endpoint = '/CreateAssetsStorePrivateKey'
        issue = '500 caused by null-like id input'
        status_if_matched = '待验证'
        checks = @(
            @{ file = 'src/controllers/AssetsController.php'; patterns = @('public static function AssetsPrivate()', 'strtolower(trim($data[''id''])) === ''null''', '$data[''id''] = '''';', 'P_E(dcim_msg(''common.id_required''));') }
        )
    },
    @{
        endpoint = '/GetAssetsAllDetailKey'
        issue = 'Most detail fields empty'
        status_if_matched = '待验证'
        checks = @(
            @{ file = 'src/controllers/AssetsController.php'; patterns = @('public static function AssetsAllDetail()', '''AssetsTypeName'' => (string)($assetType[''AssetsTypeName''] ?? '''')', '''SupplierName'' => (string)($supplier[''SupplierName''] ?? '''')', '''ServerName'' => (string)($server[''ServerName''] ?? '''')') }
        )
    }
)

$rows = @()
foreach ($item in $items) {
    $status = Resolve-ItemStatus -Checks $item.checks -StatusIfMatched $item.status_if_matched -WorkspaceRoot $WorkspaceRoot
    $rows += [PSCustomObject]@{
        Endpoint = $item.endpoint
        Issue = $item.issue
        Status = $status
    }
}

$statusOrder = @{ '待补丁' = 0; '待验证' = 1; '已覆盖' = 2 }
$rows = $rows | Sort-Object @{ Expression = { $statusOrder[$_.Status] } }, Endpoint

if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $WorkspaceRoot ('docs\interface_selfcheck_{0}.md' -f (Get-Date -Format 'yyyy-MM-dd'))
}

$outDir = Split-Path -Path $OutputPath -Parent
if ($outDir -and -not (Test-Path -LiteralPath $outDir)) {
    New-Item -ItemType Directory -Path $outDir -Force | Out-Null
}

$total = $rows.Count
$covered = ($rows | Where-Object { $_.Status -eq '已覆盖' } | Measure-Object).Count
$verify = ($rows | Where-Object { $_.Status -eq '待验证' } | Measure-Object).Count
$patch = ($rows | Where-Object { $_.Status -eq '待补丁' } | Measure-Object).Count

$md = @()
$md += '# 接口逐项自检清单（脚本生成）'
$md += ''
$md += ('- 生成时间: {0}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'))
$md += ('- 总数: {0}' -f $total)
$md += ('- 已覆盖: {0}' -f $covered)
$md += ('- 待验证: {0}' -f $verify)
$md += ('- 待补丁: {0}' -f $patch)
$md += ''
$md += '| 接口 | 问题项 | 结论 |'
$md += '|---|---|---|'
foreach ($r in $rows) {
    $md += ('| {0} | {1} | {2} |' -f $r.Endpoint, $r.Issue, $r.Status)
}

Set-Content -LiteralPath $OutputPath -Value ($md -join "`r`n") -Encoding UTF8

Write-Output ('REPORT={0}' -f $OutputPath)
Write-Output ('SUMMARY total={0} covered={1} verify={2} patch={3}' -f $total, $covered, $verify, $patch)
