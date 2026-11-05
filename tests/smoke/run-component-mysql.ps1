# Dedicated local mysqld, random source/target databases, no panel/service configuration reads.
param(
    [Parameter(Mandatory=$true)][string]$MysqlHome,
    [Parameter(Mandatory=$true)][ValidateSet('5.7','8.0','8.4')][string]$Version,
    [Parameter(Mandatory=$true)][string]$OutDir,
    [string]$Php = 'D:\yikai\soft\php\8.2\php.exe',
    [int]$Port = 33087,
    [int]$HttpPort = 18917,
    [ValidateSet('builtin','nginx','apache')][string]$WebServer = 'builtin',
    [string]$ServerBinary = '',
    [string]$PhpCgi = '',
    [ValidateSet('component','runtime')][string]$Suite = 'component',
    [string]$FromPackage = '',
    [string]$ToPackage = '',
    [string]$FromVersion = '2.1.2',
    [string]$ToVersion = '2.1.3',
    [switch]$UpgradeOnly
)
$ErrorActionPreference = 'Continue'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$mysqld = Join-Path $MysqlHome 'bin\mysqld.exe'
$mysql = Join-Path $MysqlHome 'bin\mysql.exe'
foreach ($file in @($Php,$mysqld,$mysql)) { if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Missing binary: $file" } }
if ($Suite -eq 'runtime') {
    if ($WebServer -ne 'builtin') { throw 'The runtime suite currently supports only the built-in HTTP server' }
    foreach ($file in @($FromPackage,$ToPackage)) { if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Missing package: $file" } }
}
if ($UpgradeOnly -and $Suite -ne 'runtime') { throw '-UpgradeOnly requires -Suite runtime' }
if ($WebServer -ne 'builtin' -and -not (Test-Path -LiteralPath $ServerBinary -PathType Leaf)) { throw 'An existing web server binary is required' }
$webArgs=@("--server=$WebServer")
if ($ServerBinary -ne '') { $webArgs += "--server-bin=$ServerBinary" }
if ($PhpCgi -ne '') { $webArgs += "--php-cgi=$PhpCgi" }
if ($Port -lt 1024 -or $Port -gt 65535 -or $HttpPort -lt 1024 -or $HttpPort -gt 65535) { throw 'Invalid test port' }
foreach ($testPort in @($Port,$HttpPort)) {
    if (Get-NetTCPConnection -LocalPort $testPort -State Listen -ErrorAction SilentlyContinue) { throw "Port already occupied: $testPort" }
}
$binaryVersion = & $mysqld --no-defaults --version
if ($LASTEXITCODE -ne 0 -or $binaryVersion -notmatch ('Ver ' + [regex]::Escape($Version) + '\.')) { throw 'Unexpected mysqld binary version' }
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$OutDir = (Resolve-Path -LiteralPath $OutDir).Path
$nonce = [Guid]::NewGuid().ToString('N')
$dataDir = Join-Path $env:TEMP ('yk-component-mysql-' + $nonce)
$server = $null
$ownsConnection = $false
$exitCode = 1
$savedEnv = @{}
$envNames = @('YK_COMPONENT_MYSQL_PORT','YK_COMPONENT_MYSQL_USER','YK_COMPONENT_MYSQL_PASS','YK_COMPONENT_MYSQL_DATADIR','YK_COMPONENT_MYSQL_VERSION')
foreach ($name in $envNames) { $savedEnv[$name] = [Environment]::GetEnvironmentVariable($name,'Process') }
$result = @{ binary_version=[string]$binaryVersion; expected_version=$Version; port=$Port; http_port=$HttpPort; web_server=$WebServer; suite=$Suite; passed=$false }
$client = @('--no-defaults','--protocol=TCP','--host=127.0.0.1',"--port=$Port",'--user=root','--password=','--batch','--skip-column-names','--connect-timeout=1')
try {
    $init = Start-Process -FilePath $mysqld -WindowStyle Hidden -Wait -PassThru -RedirectStandardError (Join-Path $OutDir 'init.err') -RedirectStandardOutput (Join-Path $OutDir 'init.out') -ArgumentList @(
        '--no-defaults','--initialize-insecure',('--basedir="'+$MysqlHome+'"'),('--datadir="'+$dataDir+'"'),'--console')
    if ($init.ExitCode -ne 0) { throw 'Temporary mysqld initialization failed; see init.err' }
    $serverArgs = @('--no-defaults',('--basedir="'+$MysqlHome+'"'),('--datadir="'+$dataDir+'"'),"--port=$Port",'--bind-address=127.0.0.1',
        '--character-set-server=utf8mb4','--collation-server=utf8mb4_general_ci','--innodb-buffer-pool-size=64M','--max-connections=30','--skip-log-bin','--console')
    if ($Version -eq '8.4') { $serverArgs += '--innodb-redo-log-capacity=32M' }
    else { $serverArgs += '--innodb-log-file-size=16M' }
    if ($Version -in @('8.0','8.4')) { $serverArgs += '--mysqlx=OFF' }
    $server = Start-Process -FilePath $mysqld -WindowStyle Hidden -PassThru -RedirectStandardError (Join-Path $OutDir 'server.err') -RedirectStandardOutput (Join-Path $OutDir 'server.out') -ArgumentList $serverArgs
    $result['owned_pid'] = $server.Id
    $ready = $false
    for ($i=0;$i -lt 80;$i++) {
        $server.Refresh()
        if ($server.HasExited) { throw 'Owned mysqld exited before readiness; see server.err' }
        $actual = & $mysql @client --execute='SELECT @@datadir' 2>$null
        if ($LASTEXITCODE -eq 0) {
            $expected = [IO.Path]::GetFullPath($dataDir).TrimEnd('\','/').Replace('/','\')
            $connected = [IO.Path]::GetFullPath([string]$actual).TrimEnd('\','/').Replace('/','\')
            if ($connected -ne $expected) { throw 'MySQL datadir mismatch; refusing all writes and SQL shutdown' }
            $ownsConnection=$true; $ready=$true; break
        }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'Temporary MySQL readiness timed out' }
    $serverVersion = & $mysql @client --execute='SELECT VERSION()'
    if ($LASTEXITCODE -ne 0 -or -not ([string]$serverVersion).StartsWith($Version+'.')) { throw 'Connected server version mismatch' }
    $result['server_version']=[string]$serverVersion
    $result['datadir_verified']=$true
    Write-Output "Verified isolated MySQL $serverVersion on port $Port"

    # A separately named control database must survive the gate unchanged.
    $sentinel='yk_cmp_guard_'+$nonce
    $marker='owned-control-'+$nonce
    & $mysql @client --execute="CREATE DATABASE $sentinel; CREATE TABLE $sentinel.guard_marker (value varchar(100)); INSERT INTO $sentinel.guard_marker VALUES ('$marker');"
    if ($LASTEXITCODE -ne 0) { throw 'Could not establish owned database control' }
    $env:YK_COMPONENT_MYSQL_PORT=[string]$Port
    $env:YK_COMPONENT_MYSQL_USER='root'
    $env:YK_COMPONENT_MYSQL_PASS=''
    if ($Version -eq '8.4') {
        # A fresh, password-bearing default-auth account exercises mysqlnd's full
        # caching_sha2_password login (not merely an empty-password root login).
        # All grants are confined to this owned, disposable mysqld instance.
        $testPassword='Rt#'+[Guid]::NewGuid().ToString('N')
        "CREATE USER 'yk_component'@'127.0.0.1' IDENTIFIED BY '$testPassword'; GRANT CREATE, ALTER, DROP, INDEX, SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, LOCK TABLES, REFERENCES ON *.* TO 'yk_component'@'127.0.0.1';" | & $mysql @client
        if ($LASTEXITCODE -ne 0) { throw 'Could not establish temporary password-authenticated account' }
        $authPlugin=& $mysql @client --execute="SELECT plugin FROM mysql.user WHERE User='yk_component' AND Host='127.0.0.1'"
        $nativeActive=& $mysql @client --execute="SELECT COUNT(*) FROM information_schema.plugins WHERE PLUGIN_NAME='mysql_native_password' AND PLUGIN_STATUS='ACTIVE'"
        $fkRestricted=& $mysql @client --execute='SELECT @@restrict_fk_on_non_standard_key'
        if ($LASTEXITCODE -ne 0 -or [string]$authPlugin -ne 'caching_sha2_password' -or [string]$nativeActive -ne '0' -or [string]$fkRestricted -ne '1') { throw 'MySQL 8.4 default authentication or foreign-key restrictions were not preserved' }
        $result['authentication_plugin']=[string]$authPlugin
        $result['password_authentication']=$true
        $result['mysql_native_password_active']=$false
        $result['restrict_fk_on_non_standard_key']=$true
        $env:YK_COMPONENT_MYSQL_USER='yk_component'
        $env:YK_COMPONENT_MYSQL_PASS=$testPassword
    }
    $env:YK_COMPONENT_MYSQL_VERSION=$Version
    $env:YK_COMPONENT_MYSQL_DATADIR=$dataDir+'-wrong'
    $negativeReport=Join-Path $OutDir 'wrong-datadir.json'
    & $Php (Join-Path $root 'tools\component-site-roundtrip.php') '--db=mysql' @webArgs "--port=$HttpPort" "--report=$negativeReport" > (Join-Path $OutDir 'wrong-datadir.txt')
    $negativeExit=$LASTEXITCODE
    $negative=Get-Content -LiteralPath $negativeReport -Raw | ConvertFrom-Json
    if ($negativeExit -eq 0 -or $negative.passed -or $negative.database_cleanup -or $negative.error -notlike '*datadir/port mismatch*') { throw 'Wrong-datadir guard did not fail closed' }
    $result['wrong_datadir_rejected']=$true

    $env:YK_COMPONENT_MYSQL_DATADIR=$dataDir
    $reportName=if ($Suite -eq 'runtime') { 'runtime' } else { 'roundtrip' }
    $reportPath=Join-Path $OutDir ($reportName+'.json')
    if ($Suite -eq 'runtime') {
        $suiteArgs=@("--from-package=$FromPackage","--to-package=$ToPackage","--from=$FromVersion","--to=$ToVersion","--report=$reportPath")
        if ($UpgradeOnly) { $suiteArgs += '--upgrade-only' }
        & $Php (Join-Path $root 'tools\mysql-package-acceptance.php') @suiteArgs > (Join-Path $OutDir ($reportName+'.txt'))
    } else {
        & $Php (Join-Path $root 'tools\component-site-roundtrip.php') '--db=mysql' @webArgs "--port=$HttpPort" "--report=$reportPath" > (Join-Path $OutDir ($reportName+'.txt'))
    }
    $gateExit=$LASTEXITCODE
    Get-Content -LiteralPath (Join-Path $OutDir ($reportName+'.txt')) -Tail 4
    $gate=Get-Content -LiteralPath $reportPath -Raw | ConvertFrom-Json
    $result['gate_exit']=$gateExit; $result['assertions']=$gate.assertions; $result['failed']=$gate.failed
    $remaining = & $mysql @client --execute="SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name REGEXP '^yk_cmp_rt_[a-f0-9]{20}_(source|target)$' OR schema_name REGEXP '^yikaicms_upgrade_test_[a-f0-9]{8}$'"
    if ($LASTEXITCODE -ne 0 -or [int]$remaining -ne 0) { throw 'Temporary source/target database cleanup incomplete' }
    $control = & $mysql @client --execute="SELECT value FROM $sentinel.guard_marker"
    if ($LASTEXITCODE -ne 0 -or [string]$control -ne $marker) { throw 'Unrelated owned control database changed' }
    $result['control_database_unchanged']=$true; $result['temporary_databases_remaining']=0
    & $mysql @client --execute="DROP DATABASE $sentinel"
    if ($LASTEXITCODE -ne 0) { throw 'Control database cleanup failed' }
    if ($gateExit -ne 0 -or -not $gate.passed) { throw "MySQL $Suite acceptance failed; see $reportPath" }
    $exitCode=0
} catch {
    $result['error']=$_.Exception.Message
    Write-Output ('ERROR '+$result['error'])
} finally {
    if ($null -ne $server) {
        if ($ownsConnection) {
            $actual = & $mysql @client --execute='SELECT @@datadir' 2>$null
            if ($LASTEXITCODE -eq 0 -and [IO.Path]::GetFullPath([string]$actual).TrimEnd('\','/').Replace('/','\') -eq [IO.Path]::GetFullPath($dataDir).TrimEnd('\','/').Replace('/','\')) {
                & $mysql @client --execute='SHUTDOWN' 2>$null | Out-Null
            }
        }
        if (-not $server.WaitForExit(30000)) { $server.Refresh(); if (-not $server.HasExited) { Stop-Process -Id $server.Id -Force; $server.WaitForExit() } }
        $result['owned_server_stopped']=$server.HasExited
    }
    if ($exitCode -eq 0 -and (Test-Path -LiteralPath $dataDir)) {
        $resolved=(Resolve-Path -LiteralPath $dataDir).Path
        $tempRoot=[IO.Path]::GetFullPath($env:TEMP).TrimEnd('\')+'\'
        if (-not $resolved.StartsWith($tempRoot,[StringComparison]::OrdinalIgnoreCase) -or [IO.Path]::GetFileName($resolved) -ne ('yk-component-mysql-'+$nonce)) { throw 'Refusing cleanup outside the owned temporary data directory' }
        $links=Get-ChildItem -LiteralPath $resolved -Recurse -Force -Attributes ReparsePoint
        if ($links -or ((Get-Item -LiteralPath $resolved).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Refusing cleanup through a reparse point' }
        Remove-Item -LiteralPath $resolved -Recurse -Force
        $result['owned_datadir_removed']= -not (Test-Path -LiteralPath $resolved)
    } elseif (Test-Path -LiteralPath $dataDir) { $result['retained_datadir']=$dataDir }
    foreach ($name in $envNames) { [Environment]::SetEnvironmentVariable($name,$savedEnv[$name],'Process') }
    if ($exitCode -eq 0 -and (-not $result['owned_server_stopped'] -or -not $result['owned_datadir_removed'])) {
        $exitCode=1; $result['error']='Owned MySQL server or datadir cleanup incomplete'
    }
    $result['passed']=$exitCode -eq 0
    $result | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $OutDir 'local-instance.json') -Encoding UTF8
}
exit $exitCode
