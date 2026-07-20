# Arranca mock + app server, corre el test integral, mata los servers al final.
$ErrorActionPreference = 'Stop'
$root = 'C:\xampp\htdocs\agenduy.uy'
$php  = 'C:\xampp\php\php.exe'
$mockPort = 8889
$appPort  = 8888
$mockLog  = Join-Path $root 'tests\mock.log'
$mockErr  = Join-Path $root 'tests\mock_err.log'
$appLog   = Join-Path $root 'tests\app.log'
$appErr   = Join-Path $root 'tests\app_err.log'
$mockProc = $null
$appProc  = $null

function Cleanup {
    if ($script:mockProc -and -not $script:mockProc.HasExited) { Stop-Process -Id $script:mockProc.Id -Force -ErrorAction SilentlyContinue }
    if ($script:appProc  -and -not $script:appProc.HasExited)  { Stop-Process -Id $script:appProc.Id  -Force -ErrorAction SilentlyContinue }
}

try {
    Write-Host "Arranco mock server en :$mockPort ..." -ForegroundColor Cyan
    $env:MOCK_API_KEY       = 'shared_test_api_key'
    $env:MOCK_SECRET_KEY    = 'shared_test_secret_key'
    $env:MOCK_CHECKOUT_BASE = "http://127.0.0.1:$mockPort/checkout"
    $script:mockProc = Start-Process -FilePath $php -ArgumentList @(
        '-S', "127.0.0.1:$mockPort",
        (Join-Path $root 'tests\dlocal_mock.php')
    ) -PassThru -NoNewWindow -RedirectStandardOutput $mockLog -RedirectStandardError $mockErr
    Start-Sleep -Seconds 1

    Write-Host "Arranco app server en :$appPort ..." -ForegroundColor Cyan
    $script:appProc = Start-Process -FilePath $php -ArgumentList @(
        '-S', "127.0.0.1:$appPort", '-t', $root
    ) -PassThru -NoNewWindow -RedirectStandardOutput $appLog -RedirectStandardError $appErr
    Start-Sleep -Seconds 2

    # Limpio las env vars
    Remove-Item Env:MOCK_API_KEY       -ErrorAction SilentlyContinue
    Remove-Item Env:MOCK_SECRET_KEY    -ErrorAction SilentlyContinue
    Remove-Item Env:MOCK_CHECKOUT_BASE -ErrorAction SilentlyContinue

    # Verifico que el mock responde
    try {
        $r = Invoke-WebRequest -Uri "http://127.0.0.1:$mockPort/v1/subscription/plan" -Headers @{
            'Authorization' = 'Bearer shared_test_api_key:shared_test_secret_key'
        } -UseBasicParsing -ErrorAction Stop
        Write-Host "Mock OK: $($r.StatusCode) $($r.Content)" -ForegroundColor Green
    } catch {
        Write-Host "Mock fallo: $($_.Exception.Message)" -ForegroundColor Red
        throw
    }

    Write-Host ""
    Write-Host "Corro el test integral ..." -ForegroundColor Cyan
    & $php (Join-Path $root 'tests\dlocal_integration_test.php')
    $rc = $LASTEXITCODE
    Write-Host ""
    Write-Host "Test exit code: $rc" -ForegroundColor $(if ($rc -eq 0) {'Green'} else {'Red'})
    exit $rc
} catch {
    Write-Host "Error: $_" -ForegroundColor Red
    exit 1
} finally {
    Cleanup
}
