param(
    [string]$BaseUrl = "http://192.168.55.201:8068",
    [string]$Username = "admin",
    [string]$Password = "Smt@123",
    [int]$TimeoutSec = 12,
    [int]$PreflightTimeoutSec = 4,
    [int]$DefaultXjTaskSignId = 8,
    [int]$AlarmTypeId = 2,
    [switch]$SkipPreflight,
    [switch]$EnableChangeAlarmType
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Add-Result {
    param(
        [System.Collections.ArrayList]$Bag,
        [string]$Name,
        [bool]$Passed,
        [string]$Detail
    )
    [void]$Bag.Add([PSCustomObject]@{
        check  = $Name
        passed = $Passed
        detail = $Detail
    })
}

function Invoke-JsonRequest {
    param(
        [string]$Method,
        [string]$Url,
        [hashtable]$Body = $null
    )

    $requestArgs = @{
        Method      = $Method
        Uri         = $Url
        TimeoutSec  = $TimeoutSec
        ErrorAction = "Stop"
    }
    if ($Body -ne $null) {
        $requestArgs["ContentType"] = "application/json"
        $requestArgs["Body"] = ($Body | ConvertTo-Json -Depth 20 -Compress)
    }
    return Invoke-RestMethod @requestArgs
}

function Get-TokenFromLogin {
    param([object]$LoginResponse)
    if ($null -eq $LoginResponse) { return "" }
    if ($LoginResponse.PSObject.Properties.Name -contains "token") {
        return [string]$LoginResponse.token
    }
    if ($LoginResponse.PSObject.Properties.Name -contains "data") {
        $data = $LoginResponse.data
        if ($null -ne $data -and $data.PSObject.Properties.Name -contains "token") {
            return [string]$data.token
        }
    }
    return ""
}

$results = New-Object System.Collections.ArrayList
$base = $BaseUrl.TrimEnd("/")
$token = ""

if (-not $SkipPreflight) {
    try {
        Invoke-WebRequest -Method Get -Uri ($base + "/") -TimeoutSec $PreflightTimeoutSec -UseBasicParsing -ErrorAction Stop | Out-Null
        Add-Result -Bag $results -Name "Connectivity preflight" -Passed $true -Detail "base endpoint reachable"
    }
    catch {
        $statusCode = $null
        try {
            if ($_.Exception.Response -and $_.Exception.Response.StatusCode) {
                $statusCode = [int]$_.Exception.Response.StatusCode
            }
        } catch {
            $statusCode = $null
        }

        if ($statusCode -ne $null) {
            Add-Result -Bag $results -Name "Connectivity preflight" -Passed $true -Detail ("base endpoint reachable (http {0})" -f $statusCode)
        } else {
            Add-Result -Bag $results -Name "Connectivity preflight" -Passed $false -Detail $_.Exception.Message
            $results | Format-Table -AutoSize
            exit 2
        }
    }
}

try {
    $loginUrl = "$base/LoginKey"
    $loginResp = Invoke-JsonRequest -Method "Post" -Url $loginUrl -Body @{
        userName = $Username
        passWord = $Password
    }
    $token = Get-TokenFromLogin -LoginResponse $loginResp
    if ([string]::IsNullOrWhiteSpace($token)) {
        Add-Result -Bag $results -Name "LoginKey" -Passed $false -Detail "login returned without token"
        throw "login token missing"
    }
    Add-Result -Bag $results -Name "LoginKey" -Passed $true -Detail "token acquired"
}
catch {
    Add-Result -Bag $results -Name "LoginKey" -Passed $false -Detail $_.Exception.Message
    $results | Format-Table -AutoSize
    exit 2
}

try {
    $dmpageUrl = "$base/GetDmpageListKey"
    $dmpageResp = Invoke-JsonRequest -Method "Post" -Url $dmpageUrl -Body @{ token = $token }
    $ok = $true
    $detail = ""

    if (($dmpageResp.PSObject.Properties.Name -notcontains "code") -or [int]$dmpageResp.code -ne 100) {
        $ok = $false
        $detail = "code is not 100"
    } elseif ($dmpageResp.PSObject.Properties.Name -notcontains "data") {
        $ok = $false
        $detail = "missing data field"
    } elseif ($dmpageResp.PSObject.Properties.Name -notcontains "num") {
        $ok = $false
        $detail = "missing num field"
    } else {
        $detail = "response format looks valid"
    }
    Add-Result -Bag $results -Name "GetDmpageListKey schema" -Passed $ok -Detail $detail
}
catch {
    Add-Result -Bag $results -Name "GetDmpageListKey schema" -Passed $false -Detail $_.Exception.Message
}

$xjRows = @()
try {
    $paramsJson = '{"create_time":{"operator":"between","value":"(''2025-01-01 00:00:00'',''2025-12-31 23:59:59'')"},"SignName":"%24%"}'
    $encodedParams = [uri]::EscapeDataString($paramsJson)
    $tokenQ = [uri]::EscapeDataString($token)
    $filterUrl = "$base/dcim-xjtasksign/filter?limit=15&offset=0&token=$tokenQ&params=$encodedParams"
    $filterResp = Invoke-JsonRequest -Method "Get" -Url $filterUrl

    $ok = $true
    $detail = ""
    if ($filterResp.PSObject.Properties.Name -notcontains "data") {
        $ok = $false
        $detail = "missing data field"
    } elseif ($filterResp.PSObject.Properties.Name -notcontains "total") {
        $ok = $false
        $detail = "missing total field"
    } else {
        $xjRows = @($filterResp.data)
        $detail = "total=$($filterResp.total)"
    }
    Add-Result -Bag $results -Name "xjtasksign filter schema" -Passed $ok -Detail $detail
}
catch {
    Add-Result -Bag $results -Name "xjtasksign filter schema" -Passed $false -Detail $_.Exception.Message
}

try {
    $targetId = $DefaultXjTaskSignId
    if ($xjRows.Count -gt 0 -and $xjRows[0].PSObject.Properties.Name -contains "id") {
        $candidate = [string]$xjRows[0].id
        if (-not [string]::IsNullOrWhiteSpace($candidate)) {
            $targetId = [int]$candidate
        }
    }
    $tokenQ = [uri]::EscapeDataString($token)
    $byIdUrl = "$base/dcim-xjtasksign/${targetId}?token=$tokenQ"
    $byIdResp = Invoke-JsonRequest -Method "Get" -Url $byIdUrl

    $ok = $true
    $detail = ""
    if ($byIdResp.PSObject.Properties.Name -contains "code") {
        if ([int]$byIdResp.code -ne 100) {
            $ok = $false
            $detail = "code=$($byIdResp.code), msg=$($byIdResp.msg)"
        } else {
            $detail = "code=100"
        }
    } else {
        $ok = $false
        $detail = "missing code field"
    }
    Add-Result -Bag $results -Name "xjtasksign by-id success-path" -Passed $ok -Detail $detail
}
catch {
    Add-Result -Bag $results -Name "xjtasksign by-id success-path" -Passed $false -Detail $_.Exception.Message
}

if ($EnableChangeAlarmType) {
    try {
        $alarmUrl = "$base/ChangeAlarmTypeKey"
        $alarmResp = Invoke-JsonRequest -Method "Post" -Url $alarmUrl -Body @{
            token          = $token
            id             = $AlarmTypeId
            AlarmName      = "湿度"
            PhoneNotify    = 0
            SMSNotify      = 0
            EmailNotify    = 0
            NoiseNotify    = 0
            WeixinNotify   = 0
            WeComNotify    = 0
            DingdingNotify = 0
            UserID         = ""
            ConfirmNum     = 3
            NotifyNum      = 1
            IntervalTime   = 1800
            AlarmLevel     = 1
        }

        $ok = $true
        $detail = ""
        if ($alarmResp.PSObject.Properties.Name -contains "code") {
            if ([int]$alarmResp.code -ne 100) {
                $ok = $false
                $detail = "code=$($alarmResp.code), msg=$($alarmResp.msg)"
            } else {
                $detail = "code=100"
            }
        } else {
            $detail = "non-standard response shape"
        }
        Add-Result -Bag $results -Name "ChangeAlarmTypeKey non-500 path" -Passed $ok -Detail $detail
    }
    catch {
        Add-Result -Bag $results -Name "ChangeAlarmTypeKey non-500 path" -Passed $false -Detail $_.Exception.Message
    }
}
else {
    Add-Result -Bag $results -Name "ChangeAlarmTypeKey non-500 path" -Passed $true -Detail "skipped (use -EnableChangeAlarmType to execute)"
}

$results | Format-Table -AutoSize

$failedCount = @($results | Where-Object { -not $_.passed }).Count
if ($failedCount -gt 0) {
    exit 1
}
exit 0
