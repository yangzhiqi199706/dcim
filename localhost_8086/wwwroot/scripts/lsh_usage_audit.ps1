param(
    [string]$Root = ".\\src\\controllers",
    [int]$ContextWindow = 20,
    [switch]$ShowInventory,
    [switch]$FailOnFinding
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Add-Finding {
    param(
        [System.Collections.ArrayList]$Bag,
        [string]$File,
        [int]$Line,
        [string]$Code,
        [string]$Hint
    )
    [void]$Bag.Add([PSCustomObject]@{
        file = $File
        line = $Line
        code = $Code.Trim()
        hint = $Hint
    })
}

function Add-Inventory {
    param(
        [System.Collections.ArrayList]$Bag,
        [string]$File,
        [int]$Line,
        [string]$Category,
        [string]$Code
    )
    [void]$Bag.Add([PSCustomObject]@{
        file = $File
        line = $Line
        category = $Category
        code = $Code.Trim()
    })
}

if (-not (Test-Path -LiteralPath $Root)) {
    Write-Error "Path not found: $Root"
    exit 2
}

$files = Get-ChildItem -Path $Root -Recurse -File -Filter *.php
$findings = New-Object System.Collections.ArrayList
$inventory = New-Object System.Collections.ArrayList

foreach ($file in $files) {
    $lines = Get-Content -LiteralPath $file.FullName
    for ($i = 0; $i -lt $lines.Count; $i++) {
        $line = [string]$lines[$i]
        if ($line -match '(?i)\bLsh\b') {
            $category = 'other'
            if ($line -match "(?i)\[\[\s*['""]Lsh['""]\s*,\s*['""]=") {
                $category = 'where-condition'
            } elseif ($line -match '\$data\[''Lsh''\]') {
                $category = 'id-alias-input'
            } elseif ($line -match "(?i)'Lsh'\s*=>") {
                $category = 'payload-field'
            } elseif ($line -match 'hasLsh|hasTableColumnInsensitive\(''Lsh''\)|tcGetTableColumns|isMissingColumnErrorForField\(\$e,\s*''Lsh''\)') {
                $category = 'column-guard'
            }
            Add-Inventory -Bag $inventory -File $file.FullName -Line ($i + 1) -Category $category -Code $line
        }

        if ($line -notmatch "(?i)\[\[\s*['""]Lsh['""]\s*,\s*['""]=") {
            continue
        }

        $start = [Math]::Max(0, $i - $ContextWindow)
        $end = [Math]::Min($lines.Count - 1, $i + $ContextWindow)
        $context = ($lines[$start..$end] -join "`n")

        $guarded = $false
        if ($context -match "hasLsh") { $guarded = $true }
        if ($context -match "hasTableColumnInsensitive\('Lsh'\)") { $guarded = $true }
        if ($context -match "tcGetTableColumns") { $guarded = $true }
        if ($context -match "SHOW COLUMNS") { $guarded = $true }
        if ($context -match 'isMissingColumnErrorForField\(\$e,\s*''Lsh''\)') { $guarded = $true }

        if (-not $guarded) {
            Add-Finding -Bag $findings -File $file.FullName -Line ($i + 1) -Code $line -Hint "Lsh condition without nearby column-guard heuristic"
        }
    }
}

if ($ShowInventory) {
    Write-Output "=== Lsh Inventory Summary ==="
    if ($inventory.Count -eq 0) {
        Write-Output "No Lsh references found."
    } else {
        $inventory |
            Group-Object category |
            Sort-Object Name |
            Select-Object @{Name = 'category'; Expression = { $_.Name } }, @{Name = 'count'; Expression = { $_.Count } } |
            Format-Table -AutoSize
        Write-Output ""
        Write-Output "=== Lsh Inventory Details ==="
        $inventory | Sort-Object file, line | Format-Table -AutoSize
    }
    Write-Output ""
}

if ($findings.Count -eq 0) {
    Write-Output "No suspicious unsafe Lsh-query usage found."
    exit 0
}

$findings | Sort-Object file, line | Format-Table -AutoSize
Write-Output ("findings_count={0}" -f $findings.Count)

if ($FailOnFinding) {
    exit 1
}
exit 0
