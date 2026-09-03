# First-time install for the Docker stack - smart: preferuje GHCR pull, build jen na vyzadani.
#
#   1. Generates .env with random DB password (if missing)
#   2. Generates cfg.docker.php from cfg.sample.php with Docker-friendly defaults (if missing)
#   3. Zvoli rezim a ziska image:
#        registry (default, je-li docker-compose.production.yml) -> docker compose pull z GHCR
#        source (-Build / MYINVOICE_INSTALL_MODE=source / chybi production.yml) -> local build
#        registry pull selze + je Dockerfile -> automaticky fallback na build
#   4. Brings the stack up
#   5. Waits for DB health and runs migrations (entrypoint je spusti sam)
#   6. Prints the URL where the setup wizard is available
#
# Prebiti: -Build  nebo  $env:MYINVOICE_INSTALL_MODE=registry|source.  Idempotent.
[CmdletBinding()]
param([switch]$Build)

$ErrorActionPreference = 'Stop'
# Invoke-WebRequest / curl.exe na Windows zobrazuje progress bar, ktery v
# pollovacim loopu dramaticky zpomaluje kazde volani.
$ProgressPreference = 'SilentlyContinue'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "docker not found in PATH"
}
& docker compose version > $null 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Error "'docker compose' (v2) plugin required — install Docker Desktop"
}

function New-RandomToken([int]$Bytes = 24) {
    $buf = New-Object byte[] $Bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buf)
    return ([Convert]::ToBase64String($buf) -replace '[+/=]', '').Substring(0, [math]::Min($Bytes + 4, 32))
}

# --- 1. .env --------------------------------------------------------------
if (-not (Test-Path .env)) {
    Write-Host "==> Generating .env with random DB passwords…"
    $rootPass = New-RandomToken 24
    $userPass = New-RandomToken 24
    $localAppPort = if ($env:APP_PORT) { $env:APP_PORT } else { '8080' }
    $localDbPort = if ($env:DB_PORT) { $env:DB_PORT } else { '3307' }
    @"
# MyInvoice.cz - Docker compose env (gitignored)
APP_PORT=$localAppPort
DB_PORT=$localDbPort
DB_NAME=myinvoice
DB_USER=myinvoice
DB_ROOT_PASSWORD=$rootPass
DB_PASSWORD=$userPass
"@ | Set-Content -Encoding UTF8 -NoNewline .env
    Write-Host "    .env written (passwords randomised)"
} else {
    Write-Host "==> .env already exists (skipping)"
}

# Load .env into local hashtable
$envVars = @{}
Get-Content .env | ForEach-Object {
    if ($_ -match '^\s*([A-Z_]+)\s*=\s*(.*)\s*$') { $envVars[$Matches[1]] = $Matches[2] }
}

# --- 2. cfg.docker.php ----------------------------------------------------
# Separate from cfg.php so the same checkout can run both native dev (`php -S`)
# and the Docker stack without one clobbering the other. compose mounts this
# file as /var/www/html/cfg.php inside the container.
if (-not (Test-Path cfg.docker.php)) {
    Write-Host "==> Generating cfg.docker.php from cfg.sample.php with Docker defaults…"
    $pepper = [Convert]::ToBase64String((1..32 | ForEach-Object { Get-Random -Maximum 256 }))
    $encKey = [Convert]::ToBase64String((1..32 | ForEach-Object { Get-Random -Maximum 256 }))
    $cfg = Get-Content cfg.sample.php -Raw
    # cfg.sample.php has TWO `'host' => '127.0.0.1',` lines (db then redis).
    # Replace first occurrence -> db, then first remaining occurrence -> redis.
    $appUrl = "http://localhost:$($envVars.APP_PORT)"
    $cfg = [regex]::Replace($cfg, "'host'    => '127\.0\.0\.1',", "'host'    => 'db',",    1)
    $cfg = [regex]::Replace($cfg, "'host'    => '127\.0\.0\.1',", "'host'    => 'redis',", 1)
    $cfg = $cfg -replace "'name'    => 'myinvoice',", "'name'    => '$($envVars.DB_NAME)',"
    $cfg = $cfg -replace "'user'    => 'root',",      "'user'    => '$($envVars.DB_USER)',"
    $cfg = $cfg -replace "'pass'    => 'CHANGE-ME',", "'pass'    => '$($envVars.DB_PASSWORD)',"
    $cfg = $cfg -replace "'pepper' => 'CHANGE-ME',",  "'pepper' => '$pepper',"
    $cfg = $cfg -replace "'secret_encryption_key' => '',", "'secret_encryption_key' => '$encKey',"
    $cfg = $cfg -replace "'env'    => 'production',",      "'env'    => 'development',"
    $cfg = $cfg -replace "'url'    => 'https://dev\.example\.com',", "'url'    => '$appUrl',"
    $cfg = $cfg -replace "'cookie_name'   => '__Host-myinvoice_session',", "'cookie_name'   => 'myinvoice_session',"
    $cfg = $cfg -replace "'cookie_secure' => true,", "'cookie_secure' => false,"
    Set-Content -Encoding UTF8 -NoNewline cfg.docker.php -Value $cfg
    Write-Host "    cfg.docker.php written"
    Write-Host ""
    Write-Host "    !!  Edit cfg.docker.php to fill in SMTP, Cloudflare Turnstile, IP allowlist  !!" -ForegroundColor Yellow
    Write-Host ""
} else {
    Write-Host "==> cfg.docker.php already exists (skipping)"
}

# --- 3. zvolit rezim + ziskat image ---------------------------------------
# Default = registry (GHCR pull) - rychlejsi a setri RAM/disk/CPU build. Lokalni build
# jen na vyzadani (-Build / MYINVOICE_INSTALL_MODE=source) nebo kdyz neni production.yml.
$runningImage = (& docker ps --filter 'label=com.docker.compose.service=app' --format '{{.Image}}' 2>$null |
    Where-Object { $_ -match 'myinvoice' } | Select-Object -First 1)
if ($runningImage) {
    Write-Host "==> Pozn.: app uz bezi (image '$runningImage'). Pro aktualizaci pouzij cmd\docker-update.ps1."
}

$mode = if ($Build) { 'source' }
        elseif ($env:MYINVOICE_INSTALL_MODE) { $env:MYINVOICE_INSTALL_MODE }
        elseif (Test-Path docker-compose.production.yml) { 'registry' }
        else { 'source' }

$composeArgs = @()
if ($mode -eq 'registry' -and (Test-Path docker-compose.production.yml)) {
    $composeArgs = @('-f', 'docker-compose.production.yml')
}
$composeHint = if ($composeArgs.Count -gt 0) { " (compose: $($composeArgs[1]))" } else { '' }
Write-Host "==> Rezim instalace: $mode$composeHint"
Write-Host "    (registry = GHCR pull; prebij pres -Build nebo MYINVOICE_INSTALL_MODE=registry|source)"

if ($mode -eq 'registry') {
    Write-Host "==> Pulling image from GHCR…"
    & docker compose @composeArgs pull app
    if ($LASTEXITCODE -ne 0) {
        if (Test-Path Dockerfile) {
            Write-Warning "GHCR pull selhal -> fallback na lokalni build."
            $mode = 'source'; $composeArgs = @()
        } else {
            Write-Error "GHCR pull selhal a neni Dockerfile pro build."
        }
    }
}

if ($mode -eq 'source') {
    & docker image inspect myinvoice:latest 2>$null | Out-Null
    if ($Build -or $LASTEXITCODE -ne 0) {
        Write-Host "==> Building image…"
        & docker compose @composeArgs build app
        if ($LASTEXITCODE -ne 0) { Write-Error "docker compose build failed" }
    }
}

# --- 4. up -----------------------------------------------------------------
Write-Host "==> Starting stack…"
& docker compose @composeArgs up -d db app
if ($LASTEXITCODE -ne 0) { Write-Error "docker compose up failed" }

# --- 5. wait for DB + migrate ---------------------------------------------
Write-Host "==> Waiting for database to become healthy…"
$ready = $false
for ($i = 1; $i -le 30; $i++) {
    $json = & docker compose @composeArgs ps --format json db 2>$null
    if ($json -match '"Health":"healthy"') { $ready = $true; Write-Host "    DB ready."; break }
    Start-Sleep -Seconds 2
}
if (-not $ready) {
    Write-Error "DB failed to become healthy in 60s. Check 'docker compose logs db'."
}

# Migrace se spousti automaticky z docker-entrypoint.sh pred apache2-foreground.
# Misto druheho explicitniho migrate (= race condition s entrypointem na nekterych
# migracich, napr. 0015 FK rename — errno 121 duplicate key) jen cekame, az app
# odpovi na HTTP. /api/health je v ALLOWED_PATHS pro FirstRunLockMiddleware,
# takze vraci 200 i ve fresh-install state.
$curl = (Get-Command curl.exe -ErrorAction SilentlyContinue)?.Source
if (-not $curl) { $curl = 'C:\Windows\System32\curl.exe' }
if (-not (Test-Path $curl)) {
    Write-Error "curl.exe nenalezen (potreba na Win 10/11+). Updatuj OS nebo doinstaluj curl."
}

Write-Host "==> Waiting for app to become available (entrypoint runs migrations)…"
$appReady = $false
$lastErr = ''
for ($i = 1; $i -le 60; $i++) {
    $out = & $curl -fsS -m 3 -o NUL "http://localhost:$($envVars.APP_PORT)/api/health" 2>&1
    if ($LASTEXITCODE -eq 0) { $appReady = $true; Write-Host "    App ready."; break }
    $lastErr = ($out | Out-String).Trim()
    Start-Sleep -Seconds 2
}
if (-not $appReady) {
    Write-Host "    Last curl error: $lastErr" -ForegroundColor Yellow
    Write-Error "App failed to respond in 120s. Check 'docker compose logs app'."
}

# --- 6. report -------------------------------------------------------------
$port = $envVars.APP_PORT
if (-not $port) { $port = '8080' }
Write-Host ""
Write-Host "============================================================"
Write-Host " MyInvoice.cz is up at:  http://localhost:$port"
Write-Host ""
Write-Host " The browser will land on the setup wizard:"
Write-Host "   1. Admin user (name, email, password >= 12 chars)"
Write-Host "   2. Supplier (IC -> Nacist z ARES -> bank account)"
Write-Host "   3. Optional sample data"
Write-Host ""
$cf = if ($composeArgs.Count -gt 0) { " $($composeArgs -join ' ')" } else { '' }
Write-Host " Useful:"
Write-Host "   docker compose$cf logs -f app    # tail app logs"
Write-Host "   docker compose$cf down           # stop stack (data persists)"
Write-Host "   docker compose$cf down -v        # stop + WIPE volumes (destroys DB)"
Write-Host "============================================================"
