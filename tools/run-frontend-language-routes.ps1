param(
    [Parameter(Mandatory = $true)]
    [string] $Root
)

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $Root

& node tests/e2e/run-local.js tests/e2e/frontend-language-prefix.spec.js --project=desktop-1440
exit $LASTEXITCODE
