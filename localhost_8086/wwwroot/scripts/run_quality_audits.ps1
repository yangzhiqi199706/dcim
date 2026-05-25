param(
    [switch]$IncludeRemoteApi,
    [switch]$ShowLshInventory,
    [string]$BaseUrl = "http://192.168.55.201:8068",
    [string]$Username = "admin",
    [string]$Password = "Smt@123"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$summary = New-Object System.Collections.ArrayList

function Run-Step {
    param(
        [string]$Name,
        [string]$ScriptPath,
        [string[]]$StepArgs = @()
    )

    Write-Host "==> $Name"
    $cmd = @("-NoProfile", "-ExecutionPolicy", "Bypass", "-File", $ScriptPath) + $StepArgs
    & powershell.exe @cmd
    $code = $LASTEXITCODE
    [void]$summary.Add([PSCustomObject]@{
        step = $Name
        exit = $code
    })
    if ($code -ne 0) {
        Write-Host "Step failed: $Name (exit=$code)"
    } else {
        Write-Host "Step passed: $Name"
    }
}

Run-Step -Name "response semantic audit" -ScriptPath (Join-Path $scriptRoot "response_semantic_audit.ps1")
$lshArgs = @()
if ($ShowLshInventory) {
    $lshArgs += "-ShowInventory"
}
Run-Step -Name "lsh usage safety audit" -ScriptPath (Join-Path $scriptRoot "lsh_usage_audit.ps1") -StepArgs $lshArgs

if ($IncludeRemoteApi) {
    $apiArgs = @(
        "-BaseUrl", $BaseUrl,
        "-Username", $Username,
        "-Password", $Password
    )
    Run-Step -Name "remote api regression" -ScriptPath (Join-Path $scriptRoot "api_regression_8068.ps1") -StepArgs $apiArgs
} else {
    [void]$summary.Add([PSCustomObject]@{
        step = "remote api regression"
        exit = -1
    })
    Write-Host "Skip remote api regression (use -IncludeRemoteApi to enable)."
}

Write-Host ""
Write-Host "=== Audit Summary ==="
$summary | Format-Table -AutoSize

$failed = @($summary | Where-Object { $_.exit -gt 0 }).Count
if ($failed -gt 0) {
    exit 1
}
exit 0
