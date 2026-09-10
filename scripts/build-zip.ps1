[CmdletBinding()]
param(
    [string]$Root,
    [string]$OutputPath
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($Root)) { $Root = Split-Path -Parent $scriptRoot }
if ([string]::IsNullOrWhiteSpace($OutputPath)) { $OutputPath = Join-Path (Join-Path $Root 'dist') 'doughboss.zip' }

function Fail([string]$Message) { throw "ERROR: $Message" }
function Assert-SafePath([System.IO.FileSystemInfo]$Item) {
    if (($Item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        Fail "reparse point is not allowed: $($Item.FullName)"
    }
}
function Get-SafeFiles([string]$Directory) {
    $dir = Get-Item -LiteralPath $Directory -Force
    Assert-SafePath $dir
    foreach ($child in (Get-ChildItem -LiteralPath $dir.FullName -Force)) {
        Assert-SafePath $child
        if ($child.PSIsContainer) {
            foreach ($file in Get-SafeFiles $child.FullName) { $file }
        } elseif ($child.PSIsContainer -eq $false) {
            $child
        }
    }
}
function Copy-ReleaseFile([string]$Relative, [string]$Stage) {
    $source = Join-Path $Root $Relative
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) { Fail "required source is missing: $Relative" }
    $item = Get-Item -LiteralPath $source -Force
    Assert-SafePath $item
    $destination = Join-Path $Stage $Relative
    $parent = Split-Path -Parent $destination
    New-Item -ItemType Directory -Path $parent -Force | Out-Null
    Copy-Item -LiteralPath $source -Destination $destination -Force
}
function Should-SkipGenerated([string]$Directory, [string]$Relative) {
    if ($Directory -ne 'public') { return $false }
    $path = $Relative.Replace('\', '/')
    return $path -match '^images/(menu/[^/]+-v5\.webp|home-[^/]+-v5\.webp|hero-[^/]+-v[45]\.webp|catering-(cheese-cutout-v2|fresh-cutout-v2|menu-platter-v3|pies-v3|zaatar-cutout-v2)\.webp|doughboss-(catering-premium-v1|hero-premium-v1)\.webp)$'
}

$Root = [IO.Path]::GetFullPath($Root)
$rootItem = Get-Item -LiteralPath $Root -Force
Assert-SafePath $rootItem
$dist = Join-Path $Root 'dist'
$stage = Join-Path $dist 'doughboss'
$OutputPath = [IO.Path]::GetFullPath($OutputPath)
$distPath = [IO.Path]::GetFullPath($dist).TrimEnd('\')
if (-not [string]::Equals([IO.Path]::GetDirectoryName($OutputPath).TrimEnd('\'), $distPath, [StringComparison]::OrdinalIgnoreCase)) {
    Fail 'output archive must be a direct child of this repository''s dist directory'
}
$pluginText = Get-Content -LiteralPath (Join-Path $Root 'doughboss.php') -Raw
$readmeText = Get-Content -LiteralPath (Join-Path $Root 'readme.txt') -Raw
$versionMatch = [regex]::Match($pluginText, "define\(\s*'DOUGHBOSS_VERSION'\s*,\s*'([0-9.]+)'\s*\)")
$stableMatch = [regex]::Match($readmeText, '(?im)^Stable tag:\s*(\S+)\s*$')
$changelogMatch = [regex]::Match($readmeText, '(?ms)^== Changelog ==\s*$.*?^=\s*([^=\r\n]+?)\s*=$')
$version = $versionMatch.Groups[1].Value
$stable = $stableMatch.Groups[1].Value
$changelog = $changelogMatch.Groups[1].Value.Trim()
if ([string]::IsNullOrWhiteSpace($version) -or $version -ne $stable -or $version -ne $changelog) {
    Fail "release version mismatch (plugin=$version, stable=$stable, changelog=$changelog)"
}

if (Test-Path -LiteralPath $dist) { Assert-SafePath (Get-Item -LiteralPath $dist -Force) }
New-Item -ItemType Directory -Path $dist -Force | Out-Null
if (Test-Path -LiteralPath $stage) {
    Assert-SafePath (Get-Item -LiteralPath $stage -Force)
    Remove-Item -LiteralPath $stage -Recurse -Force
}
if (Test-Path -LiteralPath $OutputPath) {
    Assert-SafePath (Get-Item -LiteralPath $OutputPath -Force)
    Remove-Item -LiteralPath $OutputPath -Force
}
New-Item -ItemType Directory -Path $stage -Force | Out-Null

foreach ($file in @('doughboss.php','uninstall.php','readme.txt','README.md','THIRD_PARTY_NOTICES.md')) { Copy-ReleaseFile $file $stage }
foreach ($directory in @('includes','admin','public')) {
    $source = Join-Path $Root $directory
    if (-not (Test-Path -LiteralPath $source -PathType Container)) { Fail "required source directory is missing: $directory" }
    foreach ($item in Get-SafeFiles $source) {
        $relative = $item.FullName.Substring($source.Length + 1)
        if (-not (Should-SkipGenerated $directory $relative)) {
            Copy-ReleaseFile (Join-Path $directory $relative) $stage
        }
    }
}
$languages = Join-Path $Root 'languages'
if (Test-Path -LiteralPath $languages -PathType Container) {
    foreach ($item in Get-SafeFiles $languages) {
        $relative = $item.FullName.Substring($languages.Length + 1)
        Copy-ReleaseFile (Join-Path 'languages' $relative) $stage
    }
}
$seed = Join-Path $Root 'scripts\seed-menu.php'
if (Test-Path -LiteralPath $seed -PathType Leaf) { Copy-ReleaseFile 'scripts\seed-menu.php' $stage }

# Keep the paired production theme and plugin package in lockstep. Every
# literal DoughBoss image referenced by the theme must exist in the staged ZIP.
$themeRoot = Join-Path $Root 'themes\doughboss-final'
if (-not (Test-Path -LiteralPath $themeRoot -PathType Container)) { Fail 'paired DoughBoss Final theme source is missing' }
foreach ($themeFile in Get-SafeFiles $themeRoot) {
    if ($themeFile.Extension -ne '.php') { continue }
    $contents = Get-Content -LiteralPath $themeFile.FullName -Raw
    foreach ($match in [regex]::Matches($contents, "doughboss_final_asset_url\(\s*'([^']+)'")) {
        $asset = $match.Groups[1].Value.Replace('/', '\')
        $stagedAsset = Join-Path (Join-Path $stage 'public\images') $asset
        if (-not (Test-Path -LiteralPath $stagedAsset -PathType Leaf)) {
            Fail "paired theme references an image absent from the production package: $asset"
        }
        Assert-SafePath (Get-Item -LiteralPath $stagedAsset -Force)
    }
}

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    foreach ($phpFile in Get-SafeFiles $stage | Where-Object { $_.Extension -eq '.php' }) {
        & $php.Source -l $phpFile.FullName | Out-Null
        if ($LASTEXITCODE -ne 0) { Fail "PHP syntax check failed for $($phpFile.FullName)" }
    }
} else {
    Write-Warning 'PHP CLI was not found; local PHP lint is not run. CI must pass before release.'
}

$zip = [IO.Compression.ZipFile]::Open($OutputPath, [IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($item in Get-SafeFiles $stage) {
        $relative = $item.FullName.Substring($stage.Length + 1).Replace('\', '/')
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $item.FullName, "doughboss/$relative", [IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
} finally { $zip.Dispose() }
Assert-SafePath (Get-Item -LiteralPath $stage -Force)
Remove-Item -LiteralPath $stage -Recurse -Force
& (Join-Path $scriptRoot 'validate-zip.ps1') -ArchivePath $OutputPath
Write-Output "Built and validated $OutputPath"
