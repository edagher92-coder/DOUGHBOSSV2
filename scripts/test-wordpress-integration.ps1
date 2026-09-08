[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $PhpPath,

    [Parameter(Mandatory = $true)]
    [string] $WpCliPath,

    [Parameter(Mandatory = $true)]
    [string] $WordPressPath,

    [string] $PhpIniPath = ''
)

$ErrorActionPreference = 'Stop'

$php = (Resolve-Path -LiteralPath $PhpPath).Path
$wpCli = (Resolve-Path -LiteralPath $WpCliPath).Path
$wordpress = (Resolve-Path -LiteralPath $WordPressPath).Path
$testFile = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\tests\integration\wordpress-mysql.php')).Path

if (-not (Test-Path -LiteralPath (Join-Path $wordpress 'wp-config.php'))) {
    throw "WordPressPath is not an installed WordPress root: $wordpress"
}

$arguments = @()
if ($PhpIniPath) {
    $arguments += '-c'
    $arguments += (Resolve-Path -LiteralPath $PhpIniPath).Path
}
$arguments += $wpCli
$arguments += 'eval-file'
$arguments += $testFile
$arguments += "--path=$wordpress"
$arguments += '--skip-themes'

& $php @arguments
if ($LASTEXITCODE -ne 0) {
    throw "WordPress/MySQL integration tests failed with exit code $LASTEXITCODE."
}

$raceWorker = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\tests\integration\square-race-worker.php')).Path
$scratch = Join-Path ([System.IO.Path]::GetTempPath()) ("doughboss-square-race-" + [System.Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $scratch | Out-Null
$raceLog = Join-Path $scratch 'provider-posts.log'
$oldRaceLog = $env:DOUGHBOSS_RACE_LOG
$env:DOUGHBOSS_RACE_LOG = $raceLog

try {
    $workerArguments = @()
    if ($PhpIniPath) {
        $workerArguments += '-c'
        $workerArguments += (Resolve-Path -LiteralPath $PhpIniPath).Path
    }
    $workerArguments += $wpCli
    $workerArguments += 'eval-file'
    $workerArguments += $raceWorker
    $workerArguments += "--path=$wordpress"
    $workerArguments += '--skip-themes'

    $workers = @()
    foreach ($workerNumber in 1..2) {
        $stdout = Join-Path $scratch "worker-$workerNumber.stdout.log"
        $stderr = Join-Path $scratch "worker-$workerNumber.stderr.log"
        $process = Start-Process -FilePath $php -ArgumentList $workerArguments -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
        $workers += [PSCustomObject]@{ Process = $process; Stdout = $stdout; Stderr = $stderr }
    }
    foreach ($worker in $workers) {
        $worker.Process.WaitForExit()
        $worker.Process.Refresh()
    }
    foreach ($worker in $workers) {
        $workerOutput = if (Test-Path -LiteralPath $worker.Stdout) { Get-Content -LiteralPath $worker.Stdout -Raw } else { '' }
        if ($workerOutput -notmatch '(?m)^WORKER_OK$') {
            if (Test-Path -LiteralPath $worker.Stderr) {
                Get-Content -LiteralPath $worker.Stderr
            }
            throw 'Square race worker did not complete successfully.'
        }
    }

    $providerPosts = if (Test-Path -LiteralPath $raceLog) { @(Get-Content -LiteralPath $raceLog | Where-Object { $_ -eq 'POST' }).Count } else { 0 }
    if ($providerPosts -ne 1) {
        throw "Expected exactly one provider POST across two workers; observed $providerPosts."
    }
    Write-Output '2-process Square race: 1 provider POST, 0 duplicate charges'
}
finally {
    $env:DOUGHBOSS_RACE_LOG = $oldRaceLog
    if (Test-Path -LiteralPath $scratch) {
        $resolvedScratch = (Resolve-Path -LiteralPath $scratch).Path
        $expectedParent = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath()).TrimEnd('\')
        if ([System.IO.Path]::GetDirectoryName($resolvedScratch).TrimEnd('\') -eq $expectedParent -and [System.IO.Path]::GetFileName($resolvedScratch) -like 'doughboss-square-race-*') {
            Remove-Item -LiteralPath $resolvedScratch -Recurse -Force
        }
    }
}
