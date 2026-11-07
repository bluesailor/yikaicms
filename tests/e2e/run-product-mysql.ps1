param(
    [string]$MysqlHome = 'D:\yikai\soft\mysql\8.0',
    [string]$Php = 'D:\yikai\soft\php\8.2\php.exe',
    [int]$Port = 33117,
    [Parameter(Mandatory = $true)][string]$OutDir
)
$ErrorActionPreference = 'Stop'
$taskRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$mysqld = Join-Path $MysqlHome 'bin/mysqld.exe'
$mysql = Join-Path $MysqlHome 'bin/mysql.exe'
foreach ($file in @($mysqld, $mysql, $Php)) { if (!(Test-Path -LiteralPath $file)) { throw "Missing $file" } }
if ($Port -lt 33080 -or (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)) { throw 'Test port unavailable' }
$stamp = [Guid]::NewGuid().ToString('N')
$dataDir = Join-Path $env:TEMP "yk-product-mysql-$stamp"
$database = "s103_product_$stamp"
New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
$OutDir = (Resolve-Path -LiteralPath $OutDir).Path
$version = & $mysqld --version
$version | Set-Content -LiteralPath (Join-Path $OutDir 'mysql-version.txt')
$init = Start-Process -FilePath $mysqld -ArgumentList @('--no-defaults','--initialize-insecure',"--datadir=$dataDir",'--console') -Wait -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $OutDir 'initialize.log')
if ($init.ExitCode -ne 0) { throw 'Temporary MySQL initialization failed' }
$serverArgs = @('--no-defaults',"--datadir=$dataDir","--port=$Port",'--bind-address=127.0.0.1','--innodb-buffer-pool-size=67108864','--max-connections=24','--console')
if ($version -notmatch 'Ver 5\.') { $serverArgs += '--mysqlx=OFF' }
$server = Start-Process -FilePath $mysqld -ArgumentList $serverArgs -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $OutDir 'server.log')
$owned = $false
$result = 1
$cleanup = @{ datadirVerified = $false; stopped = $false; datadirRemoved = $false; process = $server.Id; port = $Port }
try {
    $deadline = (Get-Date).AddSeconds(55)
    do {
        if ($server.HasExited) { throw 'Temporary server exited before startup' }
        $actual = & $mysql --protocol=TCP -h 127.0.0.1 -P $Port -u root -N -B -e 'SELECT @@datadir' 2>$null
        if ($LASTEXITCODE -eq 0) {
            $expectedPath = [IO.Path]::GetFullPath($dataDir).TrimEnd('\','/').Replace('/','\')
            $actualPath = [IO.Path]::GetFullPath([string]$actual).TrimEnd('\','/').Replace('/','\')
            if ($actualPath -ne $expectedPath) { throw 'Connected server is not the owned temporary instance' }
            $owned = $true
            $cleanup.datadirVerified = $true
            break
        }
        Start-Sleep -Milliseconds 300
    } while ((Get-Date) -lt $deadline)
    if (!$owned) { throw 'Temporary MySQL startup deadline exceeded' }
    & $mysql --protocol=TCP -h 127.0.0.1 -P $Port -u root -e "CREATE DATABASE $database CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
    if ($LASTEXITCODE -ne 0) { throw 'Cannot create isolated database' }
    $env:PHP_BINARY = $Php
    $env:YK_PRODUCT_MYSQL_DATADIR = $dataDir
    $env:YK_PRODUCT_MYSQL_DATABASE = $database
    $env:YK_PRODUCT_MYSQL_PORT = [string]$Port
    $env:YK_PRODUCT_MYSQL_OUTPUT = $OutDir
    & node (Join-Path $PSScriptRoot 'run-product-mysql.js')
    $result = $LASTEXITCODE
} finally {
    if ($owned) {
        $stillOwned = & $mysql --protocol=TCP -h 127.0.0.1 -P $Port -u root -N -B -e 'SELECT @@datadir' 2>$null
        if ($LASTEXITCODE -eq 0 -and [IO.Path]::GetFullPath([string]$stillOwned).TrimEnd('\','/').Replace('/','\') -eq [IO.Path]::GetFullPath($dataDir).TrimEnd('\','/').Replace('/','\')) {
            & $mysql --protocol=TCP -h 127.0.0.1 -P $Port -u root -e 'SHUTDOWN' 2>$null
        }
    }
    if (!$server.WaitForExit(15000)) { Stop-Process -Id $server.Id -Force }
    $cleanup.stopped = $server.HasExited
    $cleanupPath = [IO.Path]::GetFullPath($dataDir)
    $temporaryRoot = [IO.Path]::GetFullPath($env:TEMP).TrimEnd('\') + '\'
    if (!$cleanupPath.StartsWith($temporaryRoot,[StringComparison]::OrdinalIgnoreCase) -or [IO.Path]::GetFileName($cleanupPath) -notlike 'yk-product-mysql-*') { throw 'Unsafe cleanup target' }
    if ($cleanup.stopped) {
        Remove-Item -LiteralPath $cleanupPath -Recurse -Force
        $cleanup.datadirRemoved = !(Test-Path -LiteralPath $cleanupPath)
    }
    $cleanup | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $OutDir 'instance-cleanup.json')
}
exit $result
