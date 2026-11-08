# Disposable Windows runner only: exercise the production guard on real IIS/PHP.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if ($env:GITHUB_ACTIONS -ne 'true') { throw 'Use an isolated GitHub Actions Windows runner.' }
Import-Module ServerManager
$features = @('Web-Server','Web-Static-Content','Web-Default-Doc','Web-Dir-Browsing','Web-Filtering','Web-Url-Auth','Web-CGI','Web-Scripting-Tools')
$missing = @(Get-WindowsFeature -Name $features | Where-Object { -not $_.Installed } | ForEach-Object { $_.Name })
if ($missing.Count) {
    $installation = Install-WindowsFeature -Name $missing -IncludeManagementTools
    if (-not $installation.Success -or $installation.RestartNeeded -eq 'Yes') { throw 'IIS installation requires runner recovery/restart.' }
}
Import-Module WebAdministration
Add-Type -AssemblyName System.Net.Http
$site = 'CRMP-Guard-Test'
$fixture = Join-Path $env:RUNNER_TEMP 'crmp-iis'
$web = Join-Path $fixture 'site'
$uploads = Join-Path $web 'wp-content\uploads'
$core = Join-Path $env:RUNNER_TEMP 'crmp-iis-core'
$php = (Get-Command php).Source
$phpDirectory = Split-Path $php
$phpCgi = Join-Path $phpDirectory 'php-cgi.exe'
$phpLog = Join-Path $fixture 'php-errors.log'
$appcmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'
if (-not (Test-Path $phpCgi)) { throw 'PHP FastCGI binary is missing.' }
New-Item -ItemType Directory -Force -Path $uploads | Out-Null
[IO.File]::WriteAllText($phpLog,'')
Add-Content (Join-Path $phpDirectory 'php.ini') "`ncgi.force_redirect=0`nfastcgi.impersonate=1`nlog_errors=On`ndisplay_errors=Off`nerror_reporting=E_ALL`nerror_log=`"$phpLog`""
Copy-Item (Join-Path $env:GITHUB_WORKSPACE 'tests\iis-guard-runtime.php') (Join-Path $web 'generate-guards.php')
Copy-Item (Join-Path $env:GITHUB_WORKSPACE 'includes\gateways.php') (Join-Path $fixture 'gateways.php')
$marker = 'CRMP-IIS-PRIVATE-SENTINEL'
$names = @('cardraabta-manual-payments-for-woocommerce')
foreach ($name in $names) {
    $directory = Join-Path $uploads $name
    New-Item -ItemType Directory -Force -Path $directory | Out-Null
    [IO.File]::WriteAllText((Join-Path $directory 'evidence.crmp'),$marker)
    [IO.File]::WriteAllText((Join-Path $directory 'plaintext.png'),$marker)
}
[IO.File]::WriteAllText((Join-Path $uploads 'public-control.crmp'),'PUBLIC-CONTROL')
$assets = Join-Path $web 'wp-content\plugins\cardraabta-manual-payments-for-woocommerce\assets'
New-Item -ItemType Directory -Force -Path $assets | Out-Null
Copy-Item (Join-Path $env:GITHUB_WORKSPACE 'assets\checkout.css') $assets
[IO.File]::WriteAllText((Join-Path $web 'web.config'),'<configuration><system.webServer><staticContent><mimeMap fileExtension=".crmp" mimeType="application/octet-stream" /></staticContent><directoryBrowse enabled="true" /></system.webServer></configuration>')
New-WebAppPool -Name $site | Out-Null
Set-ItemProperty "IIS:\AppPools\$site" -Name managedRuntimeVersion -Value ''
New-Website -Name $site -IPAddress '127.0.0.1' -Port 8089 -PhysicalPath $web -ApplicationPool $site | Out-Null
& icacls $fixture /grant 'IIS_IUSRS:(OI)(CI)RX' 'IUSR:(OI)(CI)RX' /T | Out-Null
if ($LASTEXITCODE) { throw 'Could not grant fixture read access.' }
& icacls $uploads /grant 'IIS_IUSRS:(OI)(CI)M' 'IUSR:(OI)(CI)M' /T | Out-Null
if ($LASTEXITCODE) { throw 'Could not grant uploads write access.' }
& icacls $phpLog /grant 'IIS_IUSRS:M' 'IUSR:M' | Out-Null
& icacls $core /grant 'IIS_IUSRS:(OI)(CI)RX' 'IUSR:(OI)(CI)RX' /T | Out-Null
if ($LASTEXITCODE) { throw 'Could not grant WordPress helper read access.' }
& $appcmd unlock config /section:system.webServer/security/authorization | Out-Null
if ($LASTEXITCODE) { throw 'URL authorization delegation failed.' }
& $appcmd set config /section:system.webServer/fastCgi "/+[fullPath='$phpCgi']" /commit:apphost | Out-Null
if ($LASTEXITCODE) { throw 'FastCGI registration failed.' }
& $appcmd set config $site /section:system.webServer/handlers "/+[name='CRMP-PHP',path='*.php',verb='*',modules='FastCgiModule',scriptProcessor='$phpCgi',resourceType='Either',requireAccess='Script']" /commit:apphost | Out-Null
if ($LASTEXITCODE) { throw 'PHP handler registration failed.' }
$environment = @{
    'CRMP_IIS_CORE' = $core
    'CRMP_IIS_GATEWAYS' = (Join-Path $fixture 'gateways.php')
    'CRMP_IIS_UPLOADS' = $uploads
}
foreach ($entry in $environment.GetEnumerator()) {
    & $appcmd set config /section:system.webServer/fastCgi "/+[fullPath='$phpCgi'].environmentVariables.[name='$($entry.Key)',value='$($entry.Value)']" /commit:apphost | Out-Null
    if ($LASTEXITCODE) { throw 'FastCGI environment registration failed.' }
}
Start-Service W3SVC
Start-WebSite $site
$handler = New-Object System.Net.Http.HttpClientHandler
$handler.UseProxy = $false # Loopback fixture only, never an outbound network bypass.
$client = New-Object System.Net.Http.HttpClient($handler)
function Probe([string]$path) {
    $response = $client.GetAsync('http://127.0.0.1:8089'+$path).GetAwaiter().GetResult()
    return @{ Status = [int]$response.StatusCode; Body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult() }
}
try {
    $control = Probe '/wp-content/uploads/public-control.crmp'
    if ($control.Status -ne 200 -or $control.Body -ne 'PUBLIC-CONTROL') { throw 'Public MIME/ACL control failed.' }
    foreach ($name in $names) {
        $before = Probe "/wp-content/uploads/$name/evidence.crmp"
        if ($before.Status -ne 200 -or $before.Body -ne $marker) { throw 'Pre-guard positive control failed.' }
    }
    Write-Output 'PASS IIS controls: private test payloads are readable before guard installation'
    $created = Probe '/generate-guards.php'
    if ($created.Status -ne 200) { throw "FastCGI guard creation failed: $($created.Status) $($created.Body)" }
    $runtime = $created.Body | ConvertFrom-Json
    if ($runtime.sapi -ne 'cgi-fcgi' -or $runtime.server -notmatch 'Microsoft-IIS') { throw 'Not actual IIS/FastCGI execution.' }
    foreach ($name in $names) {
        if ($runtime.guards.$name.index_bytes -ne 0) { throw 'The generated index is not empty.' }
        foreach ($suffix in @('/evidence.crmp','/evidence.crmp?cache=1','/plaintext.png','/index.php','/')) {
            $path = "/wp-content/uploads/$name$suffix"
            foreach ($variant in @($path,$path.Replace('cardraabta-manual','cardraabta%2dmanual'))) {
                $denied = Probe $variant
                if ($denied.Status -notin @(401,403,404) -or $denied.Body.Contains($marker)) { throw "Private path not safely denied: $variant HTTP $($denied.Status)" }
            }
        }
        Write-Output "PASS IIS: $name direct/query/encoded/directory access denied; index is empty"
    }
    $public = Probe '/wp-content/plugins/cardraabta-manual-payments-for-woocommerce/assets/checkout.css'
    if ($public.Status -ne 200 -or -not $public.Body.Contains('.crmp-')) { throw 'Public plugin assets were blocked.' }
    $again = Probe '/generate-guards.php'
    if ($again.Status -ne 200) { throw 'Repeated guard creation failed.' }
    $repeated = $again.Body | ConvertFrom-Json
    foreach ($name in $names) {
        if ($runtime.guards.$name.config_sha256 -ne $repeated.guards.$name.config_sha256) { throw 'Existing guard changed on repeat.' }
    }
    if ((Get-Item $phpLog).Length -ne 0) { throw ('PHP diagnostics: '+[IO.File]::ReadAllText($phpLog)) }
    $runtime | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $fixture 'runtime.json')
    Write-Output 'PASS native IIS/FastCGI guard runtime; public assets preserved; PHP error log empty'
} finally {
    $client.Dispose()
    Stop-WebSite $site
}
