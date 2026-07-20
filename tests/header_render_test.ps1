# Arranca app server y corre el test PHP del header.
$ErrorActionPreference = 'Stop'
$root = 'C:\xampp\htdocs\agenduy.uy'
$php  = 'C:\xampp\php\php.exe'
$appPort = 8890
$logFile = Join-Path $root 'tests\header_app.log'
$errFile = Join-Path $root 'tests\header_app_err.log'
Remove-Item $logFile, $errFile -ErrorAction SilentlyContinue

$proc = $null
try {
    Write-Host "Arranco app server en :$appPort ..." -ForegroundColor Cyan
    $proc = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$appPort", '-t', $root) `
        -PassThru -NoNewWindow -RedirectStandardOutput $logFile -RedirectStandardError $errFile
    Start-Sleep -Seconds 2

    Write-Host "Corro test del header ..." -ForegroundColor Cyan
    & $php (Join-Path $root 'tests\header_render_test.php')
    $rc = $LASTEXITCODE
    Write-Host "Test exit code: $rc" -ForegroundColor $(if ($rc -eq 0) {'Green'} else {'Red'})
    exit $rc
} catch {
    Write-Host "Error: $_" -ForegroundColor Red
    exit 1
} finally {
    if ($proc -and -not $proc.HasExited) { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue }
}
