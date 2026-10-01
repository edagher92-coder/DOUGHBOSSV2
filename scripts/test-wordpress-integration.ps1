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
# Block outbound HTTP before WordPress loads; synthetic pre_http_request
# responses still run, but an unhandled fixture cannot reach a provider.
$arguments += "--exec=define('WP_HTTP_BLOCK_EXTERNAL',true);"
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
$oldSquareRaceMode = $env:DOUGHBOSS_SQUARE_RACE_MODE
$oldSquareCrashStage = $env:DOUGHBOSS_SQUARE_CRASH_STAGE
$oldSquareCrashReplay = $env:DOUGHBOSS_SQUARE_CRASH_REPLAY
$env:DOUGHBOSS_RACE_LOG = $raceLog
$env:DOUGHBOSS_SQUARE_CRASH_STAGE = $null
$env:DOUGHBOSS_SQUARE_CRASH_REPLAY = $null
$squareCrashWorkers = New-Object 'System.Collections.Generic.List[System.Diagnostics.Process]'

try {
    $workerArguments = @()
    if ($PhpIniPath) {
        $workerArguments += '-c'
        $workerArguments += (Resolve-Path -LiteralPath $PhpIniPath).Path
    }
    $workerArguments += $wpCli
    $workerArguments += "--exec=define('WP_HTTP_BLOCK_EXTERNAL',true);"
    $workerArguments += 'eval-file'
    $workerArguments += $raceWorker
    $workerArguments += "--path=$wordpress"
    $workerArguments += '--skip-themes'

    function Start-DoughBossSquareCrashWorker {
        param([string] $Prefix)
        $stdout = Join-Path $scratch "$Prefix.stdout.log"
        $stderr = Join-Path $scratch "$Prefix.stderr.log"
        $process = Start-Process -FilePath $php -ArgumentList $workerArguments -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
        $null = $process.Handle
        $squareCrashWorkers.Add($process)
        return [PSCustomObject]@{ Process = $process; Stdout = $stdout; Stderr = $stderr }
    }

    function Wait-DoughBossSquareCrashWorker {
        param([object] $Worker, [int] $ExpectedExit, [string] $Marker)
        if (-not $Worker.Process.WaitForExit(30000)) {
            $Worker.Process.Kill()
            $Worker.Process.WaitForExit()
            throw 'Disposable Square crash-test worker exceeded its 30-second limit.'
        }
        $output = if (Test-Path -LiteralPath $Worker.Stdout) { Get-Content -LiteralPath $Worker.Stdout -Raw } else { '' }
        if ($Worker.Process.ExitCode -ne $ExpectedExit -or $output -notmatch ("(?m)^" + [regex]::Escape($Marker) + '$')) {
            Write-Output "Square crash-test worker exit: $($Worker.Process.ExitCode); expected $ExpectedExit"
            Write-Output $output
            if (Test-Path -LiteralPath $Worker.Stderr) { Get-Content -LiteralPath $Worker.Stderr }
            throw 'Square crash boundary or recovery worker failed.'
        }
    }

    foreach ($squareRaceMode in @('web', 'approved_preorder')) {
        $env:DOUGHBOSS_SQUARE_RACE_MODE = $squareRaceMode
        $raceLog = Join-Path $scratch "provider-posts-$squareRaceMode.log"
        $env:DOUGHBOSS_RACE_LOG = $raceLog
        $workers = @()
        foreach ($workerNumber in 1..2) {
            $stdout = Join-Path $scratch "worker-$squareRaceMode-$workerNumber.stdout.log"
            $stderr = Join-Path $scratch "worker-$squareRaceMode-$workerNumber.stderr.log"
            $process = Start-Process -FilePath $php -ArgumentList $workerArguments -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
            # Retain the process handle before a short-lived PHP worker exits;
            # Windows PowerShell otherwise loses its exit-code evidence.
            $null = $process.Handle
            $workers += [PSCustomObject]@{ Process = $process; Stdout = $stdout; Stderr = $stderr }
        }
        foreach ($worker in $workers) {
            $worker.Process.WaitForExit()
        }
        foreach ($worker in $workers) {
            $workerOutput = if (Test-Path -LiteralPath $worker.Stdout) { Get-Content -LiteralPath $worker.Stdout -Raw } else { '' }
            if ($worker.Process.ExitCode -ne 0 -or $workerOutput -notmatch '(?m)^WORKER_OK$') {
                Write-Output "Square $squareRaceMode worker exit: $($worker.Process.ExitCode)"
                Write-Output $workerOutput
                if (Test-Path -LiteralPath $worker.Stderr) {
                    Get-Content -LiteralPath $worker.Stderr
                }
                throw 'Square race worker did not complete successfully.'
            }
        }

        $providerPosts = if (Test-Path -LiteralPath $raceLog) { @(Get-Content -LiteralPath $raceLog | Where-Object { $_ -eq 'PAYMENT_POST' }).Count } else { 0 }
        if ($providerPosts -ne 1) {
            throw "Expected exactly one provider POST across two workers; observed $providerPosts."
        }
        $orderPosts = if (Test-Path -LiteralPath $raceLog) { @(Get-Content -LiteralPath $raceLog | Where-Object { $_ -eq 'ORDER_POST' }).Count } else { 0 }
        if ($orderPosts -ne 1) {
            throw "Expected exactly one Square Order POST across two workers; observed $orderPosts."
        }
        Write-Output "2-process Square $squareRaceMode race: 1 Order POST, 1 Payment POST, 0 duplicate dispatches"
        if ($squareRaceMode -eq 'approved_preorder') {
            $fulfillments = @(Get-Content -LiteralPath $raceLog | Where-Object { $_ -eq 'FULFILL' }).Count
            if ($fulfillments -ne 1) {
                throw "Expected exactly one approved Square fulfillment across two workers; observed $fulfillments."
            }
            Write-Output '2-process approved Square release: 1 fulfillment, 0 duplicate kitchen hooks'
        }
    }

    foreach ($squareRaceMode in @('web', 'approved_preorder')) {
        $env:DOUGHBOSS_SQUARE_RACE_MODE = $squareRaceMode
        foreach ($crashStage in @('before_order_post', 'after_order_post', 'after_order_bind', 'after_payment_post', 'after_payment_bind')) {
            $raceLog = Join-Path $scratch "crash-$squareRaceMode-$crashStage.log"
            $env:DOUGHBOSS_RACE_LOG = $raceLog
            $env:DOUGHBOSS_SQUARE_CRASH_STAGE = $crashStage
            $env:DOUGHBOSS_SQUARE_CRASH_REPLAY = $null
            $crashed = Start-DoughBossSquareCrashWorker -Prefix "crash-$squareRaceMode-$crashStage"
            Wait-DoughBossSquareCrashWorker -Worker $crashed -ExpectedExit 86 -Marker "CRASH_POINT $crashStage"

            $initial = @(Get-Content -LiteralPath $raceLog)
            if (@($initial | Where-Object { $_ -eq 'FULFILL' }).Count -ne 0) {
                throw 'Crash before paid verification emitted a premature kitchen hook.'
            }
            $env:DOUGHBOSS_SQUARE_CRASH_REPLAY = '1'
            $replays = @()
            foreach ($workerNumber in 1..2) {
                $replays += Start-DoughBossSquareCrashWorker -Prefix "replay-$squareRaceMode-$crashStage-$workerNumber"
            }
            foreach ($replay in $replays) {
                Wait-DoughBossSquareCrashWorker -Worker $replay -ExpectedExit 0 -Marker 'WORKER_OK'
            }
            $events = @(Get-Content -LiteralPath $raceLog)
            $expectedOrderPosts = if ($crashStage -eq 'before_order_post') { 0 } else { 1 }
            $expectedPaymentPosts = if ($crashStage -in @('after_payment_post', 'after_payment_bind')) { 1 } else { 0 }
            $expectedFulfillments = if ($squareRaceMode -eq 'approved_preorder' -and $crashStage -eq 'after_payment_bind') { 1 } else { 0 }
            if (@($events | Where-Object { $_ -eq 'ORDER_POST' }).Count -ne $expectedOrderPosts -or
                @($events | Where-Object { $_ -eq 'PAYMENT_POST' }).Count -ne $expectedPaymentPosts -or
                @($events | Where-Object { $_ -eq 'FULFILL' }).Count -ne $expectedFulfillments -or
                @($events | Where-Object { $_ -eq "CRASH:$crashStage" }).Count -ne 1) {
                throw "Square $squareRaceMode crash/replay $crashStage changed dispatch or kitchen counts."
            }
            Write-Output "Square $squareRaceMode crash/replay $crashStage`: $expectedOrderPosts Order POST, $expectedPaymentPosts Payment POST, $expectedFulfillments kitchen hook; original identities retained"
        }
    }
}
finally {
    # Stop only exact disposable workers started by this invocation before
    # removing their logs, including the second replay if its peer fails.
    foreach ($process in $squareCrashWorkers) {
        if (-not $process.HasExited) {
            $process.Kill()
            $process.WaitForExit()
        }
    }
    $env:DOUGHBOSS_RACE_LOG = $oldRaceLog
    $env:DOUGHBOSS_SQUARE_RACE_MODE = $oldSquareRaceMode
    $env:DOUGHBOSS_SQUARE_CRASH_STAGE = $oldSquareCrashStage
    $env:DOUGHBOSS_SQUARE_CRASH_REPLAY = $oldSquareCrashReplay
    if (Test-Path -LiteralPath $scratch) {
        $resolvedScratch = (Resolve-Path -LiteralPath $scratch).Path
        $expectedParent = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath()).TrimEnd('\')
        if ([System.IO.Path]::GetDirectoryName($resolvedScratch).TrimEnd('\') -eq $expectedParent -and [System.IO.Path]::GetFileName($resolvedScratch) -like 'doughboss-square-race-*') {
            Remove-Item -LiteralPath $resolvedScratch -Recurse -Force
        }
    }
}

$preorderRaceWorker = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\tests\integration\preorder-race-worker.php')).Path
$preorderScratch = Join-Path ([System.IO.Path]::GetTempPath()) ("doughboss-preorder-race-" + [System.Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $preorderScratch | Out-Null
$preorderEnvironment = @{
    DOUGHBOSS_PREORDER_RACE_SCRATCH = $env:DOUGHBOSS_PREORDER_RACE_SCRATCH
    DOUGHBOSS_PREORDER_RACE_MODE    = $env:DOUGHBOSS_PREORDER_RACE_MODE
}

function Restore-DoughBossPreorderRaceEnvironment {
    foreach ($name in $preorderEnvironment.Keys) {
        $prior = $preorderEnvironment[$name]
        if ($null -eq $prior) {
            Remove-Item -LiteralPath ("Env:" + $name) -ErrorAction SilentlyContinue
        }
        else {
            Set-Item -LiteralPath ("Env:" + $name) -Value $prior
        }
    }
}

function Wait-DoughBossPreorderRaceReady {
    param(
        [Parameter(Mandatory = $true)] [string] $Mode,
        [Parameter(Mandatory = $true)] [array] $Workers,
        [Parameter(Mandatory = $true)] [string] $Scratch
    )

    $expectedPids = @($Workers | ForEach-Object { [int] $_.Process.Id } | Sort-Object)
    $deadline = (Get-Date).AddSeconds(20)
    do {
        $signals = @(Get-ChildItem -LiteralPath $Scratch -Filter ("ready-" + $Mode + "-*.json") -File -ErrorAction SilentlyContinue)
        $observedPids = @()
        foreach ($signal in $signals) {
            try {
                $body = Get-Content -LiteralPath $signal.FullName -Raw | ConvertFrom-Json
                if ($body.mode -eq $Mode -and [int] $body.pid -gt 0) {
                    $observedPids += [int] $body.pid
                }
            }
            catch {
                throw "Pre-order race readiness signal is malformed."
            }
        }
        $observedKey = (@($observedPids | Sort-Object -Unique) -join ',')
        $expectedKey = ($expectedPids -join ',')
        if ($observedKey -eq $expectedKey) {
            return
        }
        Start-Sleep -Milliseconds 100
    } while ((Get-Date) -lt $deadline)

    throw "Pre-order race workers did not reach the explicit $Mode barrier."
}

function Complete-DoughBossPreorderRaceWorkers {
    param(
        [Parameter(Mandatory = $true)] [string] $ExpectedMarker,
        [Parameter(Mandatory = $true)] [array] $Workers
    )

    $failures = @()
    foreach ($worker in $Workers) {
        $timedOut = $false
        if (-not $worker.Process.WaitForExit(60000)) {
            Stop-Process -Id $worker.Process.Id -Force -ErrorAction SilentlyContinue
            $worker.Process.WaitForExit(5000) | Out-Null
            $timedOut = $true
        }
        $worker.Process.Refresh()
        $stdout = if (Test-Path -LiteralPath $worker.Stdout) { Get-Content -LiteralPath $worker.Stdout -Raw } else { '' }
        $stderr = if (Test-Path -LiteralPath $worker.Stderr) { Get-Content -LiteralPath $worker.Stderr -Raw } else { '' }
        $exitCode = if ($timedOut) { 'timeout' } else { [int] $worker.Process.ExitCode }
        $worker.Process.Dispose()
        if ($timedOut -or $exitCode -ne 0 -or $stdout -notmatch $ExpectedMarker -or -not [string]::IsNullOrWhiteSpace($stderr)) {
            $failures += "exit=$exitCode; stdout=$stdout; stderr=$stderr"
        }
    }
    if ($failures.Count -gt 0) {
        throw "Pre-order race worker failed: $($failures -join ' | ')"
    }
}

try {
    $env:DOUGHBOSS_PREORDER_RACE_SCRATCH = $preorderScratch

    $preorderWorkerArguments = @()
    if ($PhpIniPath) {
        $preorderWorkerArguments += '-c'
        $preorderWorkerArguments += (Resolve-Path -LiteralPath $PhpIniPath).Path
    }
    $preorderWorkerArguments += $wpCli
    $preorderWorkerArguments += "--exec=define('WP_HTTP_BLOCK_EXTERNAL',true);"
    $preorderWorkerArguments += 'eval-file'
    $preorderWorkerArguments += $preorderRaceWorker
    $preorderWorkerArguments += "--path=$wordpress"
    $preorderWorkerArguments += '--skip-themes'

    $env:DOUGHBOSS_PREORDER_RACE_MODE = 'prepare'
    $prepareStdout = Join-Path $preorderScratch 'prepare.stdout.log'
    $prepareStderr = Join-Path $preorderScratch 'prepare.stderr.log'
    $prepare = Start-Process -FilePath $php -ArgumentList $preorderWorkerArguments -WindowStyle Hidden -PassThru -Wait -RedirectStandardOutput $prepareStdout -RedirectStandardError $prepareStderr
    $prepareOutput = if (Test-Path -LiteralPath $prepareStdout) { Get-Content -LiteralPath $prepareStdout -Raw } else { '' }
    $prepareError = if (Test-Path -LiteralPath $prepareStderr) { Get-Content -LiteralPath $prepareStderr -Raw } else { '' }
    $prepareExitCode = [int] $prepare.ExitCode
    $prepare.Dispose()
    if ($prepareExitCode -ne 0 -or $prepareOutput -notmatch '(?m)^WORKER_OK prepare' -or -not [string]::IsNullOrWhiteSpace($prepareError)) {
        throw "Pre-order race preparation failed: exit=$prepareExitCode; stdout=$prepareOutput; stderr=$prepareError"
    }

    foreach ($mode in @('start', 'complete')) {
        $env:DOUGHBOSS_PREORDER_RACE_MODE = $mode
        $workers = @()
        foreach ($workerNumber in 1..2) {
            $stdout = Join-Path $preorderScratch "$mode-worker-$workerNumber.stdout.log"
            $stderr = Join-Path $preorderScratch "$mode-worker-$workerNumber.stderr.log"
            $process = Start-Process -FilePath $php -ArgumentList $preorderWorkerArguments -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
            $workers += [PSCustomObject]@{ Process = $process; Stdout = $stdout; Stderr = $stderr }
        }

        Wait-DoughBossPreorderRaceReady -Mode $mode -Workers $workers -Scratch $preorderScratch
        Set-Content -LiteralPath (Join-Path $preorderScratch ("go-" + $mode)) -Value 'go' -NoNewline
        Complete-DoughBossPreorderRaceWorkers -ExpectedMarker ("(?m)^WORKER_OK " + $mode) -Workers $workers

        $env:DOUGHBOSS_PREORDER_RACE_MODE = "verify-$mode"
        $verifyStdout = Join-Path $preorderScratch "verify-$mode.stdout.log"
        $verifyStderr = Join-Path $preorderScratch "verify-$mode.stderr.log"
        $verify = Start-Process -FilePath $php -ArgumentList $preorderWorkerArguments -WindowStyle Hidden -PassThru -Wait -RedirectStandardOutput $verifyStdout -RedirectStandardError $verifyStderr
        $verifyOutput = if (Test-Path -LiteralPath $verifyStdout) { Get-Content -LiteralPath $verifyStdout -Raw } else { '' }
        $verifyError = if (Test-Path -LiteralPath $verifyStderr) { Get-Content -LiteralPath $verifyStderr -Raw } else { '' }
        $verifyExitCode = [int] $verify.ExitCode
        $verify.Dispose()
        if ($verifyExitCode -ne 0 -or $verifyOutput -notmatch ("(?m)^WORKER_OK verify-" + $mode) -or -not [string]::IsNullOrWhiteSpace($verifyError)) {
            throw "Pre-order race durable-state verification failed: exit=$verifyExitCode; stdout=$verifyOutput; stderr=$verifyError"
        }
    }

    $preorderEvents = if (Test-Path -LiteralPath (Join-Path $preorderScratch 'events.log')) { Get-Content -LiteralPath (Join-Path $preorderScratch 'events.log') } else { @() }
    $startReady = @($preorderEvents | Where-Object { $_ -match '^worker_ready\|start\|' }).Count
    $startGo = @($preorderEvents | Where-Object { $_ -match '^worker_go\|start\|' }).Count
    $completeReady = @($preorderEvents | Where-Object { $_ -match '^worker_ready\|complete\|' }).Count
    $completeGo = @($preorderEvents | Where-Object { $_ -match '^worker_go\|complete\|' }).Count
    $providerPosts = @($preorderEvents | Where-Object { $_ -match '^stripe_checkout_post\|start\|' }).Count
    $createdOrders = @($preorderEvents | Where-Object { $_ -match '^order_created\|complete\|' }).Count
    if ($startReady -ne 2 -or $startGo -ne 2 -or $completeReady -ne 2 -or $completeGo -ne 2) {
        throw "Pre-order race barrier evidence mismatch: startReady=$startReady startGo=$startGo completeReady=$completeReady completeGo=$completeGo providerPosts=$providerPosts createdOrders=$createdOrders."
    }
    if ($providerPosts -ne 1) {
        throw "Expected exactly one synthetic Stripe Checkout POST across concurrent pre-order starts; observed $providerPosts."
    }
    if ($createdOrders -ne 1) {
        throw "Expected exactly one fulfillment order-created side effect across concurrent paid completions; observed $createdOrders."
    }
    Write-Output '2-process approved-preorder races: barriers crossed, 1 Stripe POST, 1 fulfillment creation'
}
finally {
    Restore-DoughBossPreorderRaceEnvironment
    if (Test-Path -LiteralPath $preorderScratch) {
        $resolvedScratch = (Resolve-Path -LiteralPath $preorderScratch).Path
        $expectedParent = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath()).TrimEnd('\')
        if ([System.IO.Path]::GetDirectoryName($resolvedScratch).TrimEnd('\') -eq $expectedParent -and [System.IO.Path]::GetFileName($resolvedScratch) -like 'doughboss-preorder-race-*') {
            Remove-Item -LiteralPath $resolvedScratch -Recurse -Force
        }
    }
}
