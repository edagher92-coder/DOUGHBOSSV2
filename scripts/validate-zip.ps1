[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$ArchivePath)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
if (-not (Test-Path -LiteralPath $ArchivePath -PathType Leaf)) { throw "ERROR: archive is missing: $ArchivePath" }
$archive = [IO.Compression.ZipFile]::OpenRead([IO.Path]::GetFullPath($ArchivePath))
try {
    $seen = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
    $entries = @()
    foreach ($entry in $archive.Entries) {
        $name = $entry.FullName
        if ([string]::IsNullOrWhiteSpace($name) -or $name.Contains('\') -or $name -match '(^|/)\.\.(/|$)' -or $name -match '^[\\/]' -or $name -notmatch '^doughboss/') { throw "ERROR: unsafe or invalid archive entry $name" }
        if (-not $seen.Add($name)) { throw "ERROR: duplicate archive entry $name" }
        $entries += $name
    }
} finally { $archive.Dispose() }
if (-not ($entries -contains 'doughboss/doughboss.php') -or -not ($entries -contains 'doughboss/includes/class-doughboss.php') -or -not ($entries -contains 'doughboss/public/js/doughboss.js')) {
    throw 'ERROR: one or more required archive entries are missing'
}
$forbidden = '(^|/)docs/|(^|/)tests/|(^|/)output/|(^|/)dist/|(^|/)\.git/|(^|/)(\.env|wp-config\.php|composer\.(json|lock)|package(-lock)?\.json|node_modules/)|(^|/)(credentials?|secrets?|\.npmrc|\.pypirc)(/|$)'
foreach ($name in $entries) { if ($name -match $forbidden) { throw "ERROR: forbidden archive entry $name" } }
Write-Output "Archive layout is valid: $ArchivePath ($($entries.Count) files)"
