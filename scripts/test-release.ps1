[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Leaf })]
    [string] $PhpPath,

    [Parameter(Mandatory = $true)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Container })]
    [string] $ExtensionDir,

    [string] $OutputPath = (Join-Path $PSScriptRoot '..\dist\doughboss-review-candidate.zip'),

    [string] $ThemeOutputPath = (Join-Path $PSScriptRoot '..\dist\doughboss-final-review-candidate.zip')
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path

function Invoke-DoughBossPhp {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]] $Arguments)

    & $PhpPath -n -d "extension_dir=$ExtensionDir" -d extension=zip @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "PHP command failed with exit code $LASTEXITCODE."
    }
}

Push-Location $root
try {
    $phpFiles = Get-ChildItem -Recurse -File -Filter '*.php' |
        Where-Object { $_.FullName -notmatch '[\\/](\.git|dist|vendor|node_modules)[\\/]' }
    foreach ($file in $phpFiles) {
        Invoke-DoughBossPhp -l $file.FullName
    }
    Write-Host "PHP syntax: $($phpFiles.Count) files passed"

    Invoke-DoughBossPhp 'tests/run.php'

    $node = Get-Command node -ErrorAction Stop
    $jsFiles = Get-ChildItem -Recurse -File -Filter '*.js' |
        Where-Object { $_.FullName -notmatch '[\\/](\.git|dist|vendor|node_modules)[\\/]' }
    foreach ($file in $jsFiles) {
        & $node.Source --check $file.FullName
        if ($LASTEXITCODE -ne 0) { throw "Node syntax check failed: $($file.FullName)" }
    }
    Write-Host "Node syntax: $($jsFiles.Count) files passed"

    $nodeTests = Get-ChildItem -Recurse -File -Filter '*.test.js' |
        Where-Object { $_.FullName -notmatch '[\\/](\.git|dist|vendor|node_modules)[\\/]' }
    foreach ($test in $nodeTests) {
        & $node.Source --test $test.FullName
        if ($LASTEXITCODE -ne 0) { throw "Node test failed: $($test.FullName)" }
    }
    Write-Host "Node tests: $($nodeTests.Count) dependency-free test files passed"

    Invoke-DoughBossPhp 'scripts/build-zip.php' $OutputPath
    Invoke-DoughBossPhp 'scripts/validate-zip.php' $OutputPath
    Invoke-DoughBossPhp 'scripts/build-theme-zip.php' $ThemeOutputPath
    Invoke-DoughBossPhp 'scripts/validate-theme-zip.php' $ThemeOutputPath
}
finally {
    Pop-Location
}
