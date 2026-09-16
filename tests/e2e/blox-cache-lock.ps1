$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
if ((Split-Path $root -Leaf) -notlike 'yikai-e2e-*' -or
    -not (Test-Path -LiteralPath (Join-Path $root 'storage/.smoke-state-backup/manifest.json'))) {
    throw 'Disposable smoke site required'
}
$dir = Join-Path $root 'storage/cache/html'
$files = @(Get-ChildItem -LiteralPath $dir -File -Filter '*.html')
if ($files.Count -ne 1) { throw 'Exactly one cached response required' }
$stream = [IO.File]::Open($files[0].FullName, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
try {
    [Console]::Out.WriteLine('LOCKED')
    [Console]::Out.Flush()
    [Console]::In.ReadLine() | Out-Null
} finally {
    $stream.Dispose()
}
