# S1-03 local verification: PHP 8.2 + a throwaway MySQL 8.0 (or 5.7) instance (Windows PowerShell 5+).
#
# Starts a SEPARATE mysqld from the given MySQL install with its own temporary data directory,
# port and root account, runs the proposal transaction suite on SQLite and MySQL, writes two JSON
# reports, then shuts that instance down. It never connects to or changes an existing MySQL
# service, data directory or database, and never touches site content.
#
#   powershell -ExecutionPolicy Bypass -File tests\ai-access\run-local-mysql8.ps1
#   (optional) -Php D:\yikai\soft\php\8.2\php.exe -MysqlHome D:\yikai\soft\mysql\8.0 -Port 33080 -OutDir <dir>

param(
    [string]$Php = 'D:\yikai\soft\php\8.2\php.exe',
    [string]$MysqlHome = 'D:\yikai\soft\mysql\8.0',
    [int]$Port = 33080,
    [string]$OutDir = ''
)

# Windows PowerShell 5.1 turns native stderr into terminating errors under 'Stop'; mysqld writes its log to
# stderr, so keep 'Continue' and check exit codes / throw explicitly instead.
$ErrorActionPreference = 'Continue'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runner = Join-Path $root 'tests\ai-access\proposal-txn-run.php'
$throttle = Join-Path $root 'tests\ai-access\throttle-run.php'
$assistant = Join-Path $root 'tests\ai-access\assistant-txn-run.php'
$upgrade = Join-Path $root 'tests\ai-access\upgrade-run.php'
$pages = Join-Path $root 'tests\ai-access\page-proposal-run.php'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
if ($OutDir -eq '') { $OutDir = Join-Path $env:TEMP "s103-local-$stamp" }
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

$mysqld = Join-Path $MysqlHome 'bin\mysqld.exe'
$mysql = Join-Path $MysqlHome 'bin\mysql.exe'
foreach ($file in @($Php, $mysqld, $mysql, $runner)) {
    if (-not (Test-Path $file)) { throw "Missing: $file" }
}
$modules = & $Php -m
foreach ($ext in @('pdo_sqlite', 'pdo_mysql', 'mbstring')) {
    if ($modules -notcontains $ext) { throw "PHP extension '$ext' is not enabled for $Php (check its php.ini)" }
}
$busy = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
if ($busy) { throw "Port $Port is already in use; pass -Port with a free port" }

& $Php -v | Select-Object -First 1 | Tee-Object -FilePath (Join-Path $OutDir 'php-version.txt')
& $mysqld --version | Tee-Object -FilePath (Join-Path $OutDir 'mysqld-version.txt')

# 1) SQLite with the local PHP.
$sqlitePath = Join-Path $env:TEMP "s103-local-$stamp.sqlite"
$env:YK_S103_DRIVER = 'sqlite'
$env:YK_S103_SQLITE_PATH = $sqlitePath
& $Php $runner "--out=$(Join-Path $OutDir 'report-sqlite.json')" | Out-Null
$sqliteExit = $LASTEXITCODE
# S2: rate-limit counter concurrency
& $Php $throttle | Tee-Object -FilePath (Join-Path $OutDir 'throttle-sqlite.txt')
if ($LASTEXITCODE -ne 0) { $sqliteExit = 1 }
# S3: admin AI assistant apply/undo transactions
& $Php $assistant | Tee-Object -FilePath (Join-Path $OutDir 'assistant-sqlite.txt')
if ($LASTEXITCODE -ne 0) { $sqliteExit = 1 }
& $Php $upgrade | Tee-Object -FilePath (Join-Path $OutDir 'upgrade-sqlite.txt')
if ($LASTEXITCODE -ne 0) { $sqliteExit = 1 }
# S5-03: single-page content proposals
& $Php $pages | Out-File -Encoding utf8 -FilePath (Join-Path $OutDir 'page-proposals-sqlite.json')
if ($LASTEXITCODE -ne 0) { $sqliteExit = 1 }
Remove-Item -Force -ErrorAction SilentlyContinue $sqlitePath, "$sqlitePath-journal"

# 2) Throwaway MySQL instance: own datadir, loopback only, X protocol off.
$dataDir = Join-Path $env:TEMP "s103-mysql-data-$stamp"
$init = Start-Process -FilePath $mysqld -Wait -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $OutDir 'mysqld-init.log') -ArgumentList @(
    '--no-defaults', '--initialize-insecure', "--datadir=$dataDir", '--console')
if ($init.ExitCode -ne 0) { throw 'mysqld --initialize-insecure failed; see mysqld-init.log in the output folder' }
$serverArgs = @('--no-defaults', "--datadir=$dataDir", "--port=$Port", '--bind-address=127.0.0.1',
    '--innodb-lock-wait-timeout=10', '--character-set-server=utf8mb4', '--collation-server=utf8mb4_general_ci', '--console')
# MySQL 5.7 has no X plugin built in and rejects --mysqlx as an unknown option.
if ((& $mysqld --version) -notmatch 'Ver 5\.') { $serverArgs += '--mysqlx=OFF' }
$server = Start-Process -FilePath $mysqld -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $OutDir 'mysqld.err') -ArgumentList $serverArgs
$mysqlExit = 99
$ownsConnection = $false
try {
    $ready = $false
    for ($i = 0; $i -lt 60; $i++) {
        if ($server.HasExited) { throw 'The temporary mysqld exited before becoming ready' }
        $actualDataDir = & $mysql --protocol=TCP -h 127.0.0.1 -P $Port -u root -N -B -e 'SELECT @@datadir' 2>$null
        if ($LASTEXITCODE -eq 0) {
            $expectedDataDir = [IO.Path]::GetFullPath($dataDir).TrimEnd('\','/').Replace('/','\')
            $connectedDataDir = [IO.Path]::GetFullPath([string]$actualDataDir).TrimEnd('\','/').Replace('/','\')
            if ($connectedDataDir -ne $expectedDataDir) { throw 'Port belongs to another MySQL instance; refusing to write or shut it down' }
            $ownsConnection = $true
            $ready = $true
            break
        }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) { throw 'Throwaway mysqld did not start; see mysqld.err in the output folder' }
    & $mysql --protocol=TCP -h 127.0.0.1 -P $Port -u root -e 'CREATE DATABASE s103_txn_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; SELECT VERSION(), @@transaction_isolation;' |
        Tee-Object -FilePath (Join-Path $OutDir 'mysql-server.txt')
    $env:YK_S103_DRIVER = 'mysql'
    $env:YK_S103_MYSQL_HOST = '127.0.0.1'
    $env:YK_S103_MYSQL_PORT = "$Port"
    $env:YK_S103_MYSQL_NAME = 's103_txn_test'
    $env:YK_S103_MYSQL_USER = 'root'
    $env:YK_S103_MYSQL_PASSWORD = ''
    & $Php $runner "--out=$(Join-Path $OutDir 'report-mysql.json')" | Out-Null
    $mysqlExit = $LASTEXITCODE
    & $Php $throttle | Tee-Object -FilePath (Join-Path $OutDir 'throttle-mysql.txt')
    if ($LASTEXITCODE -ne 0) { $mysqlExit = 1 }
    & $Php $assistant | Tee-Object -FilePath (Join-Path $OutDir 'assistant-mysql.txt')
    if ($LASTEXITCODE -ne 0) { $mysqlExit = 1 }
    & $Php $upgrade | Tee-Object -FilePath (Join-Path $OutDir 'upgrade-mysql.txt')
    if ($LASTEXITCODE -ne 0) { $mysqlExit = 1 }
    & $Php $pages | Out-File -Encoding utf8 -FilePath (Join-Path $OutDir 'page-proposals-mysql.json')
    if ($LASTEXITCODE -ne 0) { $mysqlExit = 1 }
} finally {
    if ($ownsConnection) { & $mysql --protocol=TCP -h 127.0.0.1 -P $Port -u root -e 'SHUTDOWN' 2>$null | Out-Null }
    if (-not $server.WaitForExit(30000)) { Stop-Process -Id $server.Id -Force }
    $cleanupPath = [IO.Path]::GetFullPath($dataDir)
    $temporaryRoot = [IO.Path]::GetFullPath($env:TEMP).TrimEnd('\') + '\'
    if (-not $cleanupPath.StartsWith($temporaryRoot, [StringComparison]::OrdinalIgnoreCase) -or [IO.Path]::GetFileName($cleanupPath) -notlike 's103-mysql-data-*') { throw 'Refusing cleanup outside the disposable MySQL directory' }
    Remove-Item -LiteralPath $cleanupPath -Recurse -Force -ErrorAction SilentlyContinue
    foreach ($name in @('YK_S103_DRIVER', 'YK_S103_SQLITE_PATH', 'YK_S103_MYSQL_HOST', 'YK_S103_MYSQL_PORT', 'YK_S103_MYSQL_NAME', 'YK_S103_MYSQL_USER', 'YK_S103_MYSQL_PASSWORD')) {
        Remove-Item "Env:$name" -ErrorAction SilentlyContinue
    }
}

foreach ($name in @('report-sqlite.json', 'report-mysql.json')) {
    $path = Join-Path $OutDir $name
    if (Test-Path $path) {
        $report = Get-Content -Raw $path | ConvertFrom-Json
        Write-Host ("{0}: driver={1} server={2} php={3} assertions={4} failed={5}" -f $name, $report.driver, $report.server_version, $report.php, $report.assertions, $report.failed)
    } else {
        Write-Host "${name}: missing"
    }
}
Write-Host "SQLite exit=$sqliteExit  MySQL exit=$mysqlExit  reports: $OutDir"
if ($sqliteExit -ne 0 -or $mysqlExit -ne 0) { exit 1 }
