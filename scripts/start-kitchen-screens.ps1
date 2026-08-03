<#
 .SYNOPSIS
 Starts the DoughBoss MAKE and CATERING boards on an extended dual-screen
 Windows workstation.

 .DESCRIPTION
 This helper intentionally does not store a WordPress password or Order Board
 access key. Sign in to the low-privilege Kitchen account once in Chrome, then
 run this script. If an owner has enabled a Board access key, use the normal
 bookmarked URL as the BaseUrl parameter.

 The physical screens must be set to "Extend these displays" in Windows and
 arranged left-to-right before use. Adjust the width/height parameters only if
 the installed monitors use a different native resolution.
#>
[CmdletBinding()]
param(
	[string]$BaseUrl = 'https://doughboss.com.au/kitchen/',
	[int]$PrimaryScreenWidth = 1920,
	[int]$PrimaryScreenHeight = 1080,
	[int]$CateringScreenWidth = 1366,
	[int]$CateringScreenHeight = 768
)

$chromeCandidates = @(
	"$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
	"${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe"
) | Where-Object { Test-Path -LiteralPath $_ }

if ( $chromeCandidates.Count -eq 0 ) {
	throw 'Google Chrome was not found. Install Chrome or update the launcher for the approved browser.'
}

$chrome = $chromeCandidates[0]
$separator = if ( $BaseUrl.Contains('?') ) { '&' } else { '?' }
$makeUrl = "$BaseUrl${separator}screen=make"
$cateringUrl = "$BaseUrl${separator}screen=catering"

# --kiosk removes browser chrome, while the two distinct URLs retain the
# existing WordPress login, capability and optional Board-key gates.
Start-Process -FilePath $chrome -ArgumentList @(
	'--kiosk', '--new-window', "--window-position=0,0",
	"--window-size=$PrimaryScreenWidth,$PrimaryScreenHeight", $makeUrl
)

Start-Sleep -Seconds 2

Start-Process -FilePath $chrome -ArgumentList @(
	'--kiosk', '--new-window',
	"--window-position=$PrimaryScreenWidth,0",
	"--window-size=$CateringScreenWidth,$CateringScreenHeight", $cateringUrl
)

Write-Host 'DoughBoss MAKE and CATERING screens started.'
