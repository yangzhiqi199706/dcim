param(
    [string]$Root = ".\\src\\controllers",
    [switch]$FailOnFinding
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Add-Finding {
    param(
        [System.Collections.ArrayList]$Bag,
        [string]$Rule,
        [string]$File,
        [int]$Line,
        [string]$Code
    )
    [void]$Bag.Add([PSCustomObject]@{
        rule = $Rule
        file = $File
        line = $Line
        code = $Code.Trim()
    })
}

if (-not (Test-Path -LiteralPath $Root)) {
    Write-Error "Path not found: $Root"
    exit 2
}

$files = Get-ChildItem -Path $Root -Recurse -File -Filter *.php
$findings = New-Object System.Collections.ArrayList

foreach ($file in $files) {
    $fileLines = Get-Content -LiteralPath $file.FullName

    $matches = Select-String -Path $file.FullName -Pattern "json_string_response(['status' => 'error'" -SimpleMatch
    foreach ($m in $matches) {
        if ($m.Line -notmatch "\],\s*\d+\s*\)") {
            Add-Finding -Bag $findings -Rule "error_without_http_status" -File $file.FullName -Line $m.LineNumber -Code $m.Line
        }
    }

    $matches = Select-String -Path $file.FullName -Pattern "json_string_response(['status' => 'ok'" -SimpleMatch
    foreach ($m in $matches) {
        if ($m.Line -match "\],\s*4\d\d\s*\)") {
            Add-Finding -Bag $findings -Rule "ok_with_4xx_http_status" -File $file.FullName -Line $m.LineNumber -Code $m.Line
        }
    }

    $matches = Select-String -Path $file.FullName -Pattern "result_json\(\s*100\s*," -SimpleMatch:$false
    foreach ($m in $matches) {
        if ($m.Line -match "request_failed|error\.|失败|not_found") {
            Add-Finding -Bag $findings -Rule "success_code_with_error_message_hint" -File $file.FullName -Line $m.LineNumber -Code $m.Line
        }
    }

    $matches = Select-String -Path $file.FullName -Pattern "result_json\(\s*4\d\d\s*," -SimpleMatch:$false
    foreach ($m in $matches) {
        if ($m.Line -match "tp_msg_success\(|common\.success") {
            Add-Finding -Bag $findings -Rule "error_code_with_success_message_hint" -File $file.FullName -Line $m.LineNumber -Code $m.Line
        }
    }

    $matches = Select-String -Path $file.FullName -Pattern "json_string_response\(\['status' => 'error', 'message' => tp_msg_login\(\)\], 401\);" -SimpleMatch:$false
    foreach ($m in $matches) {
        $nextLine = ""
        $cursor = [int]$m.LineNumber
        while ($cursor -lt $fileLines.Count) {
            $candidate = [string]$fileLines[$cursor]
            $trimmed = $candidate.Trim()
            if ($trimmed -eq "" -or $trimmed.StartsWith("//")) {
                $cursor++
                continue
            }
            $nextLine = $trimmed
            break
        }
        if ($nextLine -ne "return;" -and $nextLine -ne "return false;") {
            Add-Finding -Bag $findings -Rule "auth_401_without_explicit_return" -File $file.FullName -Line $m.LineNumber -Code $m.Line
        }
    }
}

if ($findings.Count -eq 0) {
    Write-Output "No suspicious response semantic mismatches found."
    exit 0
}

$findings | Sort-Object file, line | Format-Table -AutoSize
Write-Output ("findings_count={0}" -f $findings.Count)

if ($FailOnFinding) {
    exit 1
}
exit 0
