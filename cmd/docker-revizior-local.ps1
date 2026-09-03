# Starts ReviziOR Fakturace from this checkout and joins the running ReviziOR
# backend network under the stable hostname `revizior-invoice.local`.
[CmdletBinding()]
param(
    [string]$Network = $(if ($env:REVIZIOR_DOCKER_NETWORK) { $env:REVIZIOR_DOCKER_NETWORK } else { 'backend_revizor' }),
    [string]$AppPort = $(if ($env:APP_PORT) { $env:APP_PORT } else { '8082' })
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

$env:REVIZIOR_DOCKER_NETWORK = $Network
$env:APP_PORT = $AppPort
$env:MYINVOICE_INSTALL_MODE = 'source'

& docker network inspect $Network *> $null
if ($LASTEXITCODE -ne 0) {
    Write-Error "Docker network '$Network' does not exist. Start ../backend/docker-compose.yml first."
}

& (Join-Path $PSScriptRoot 'docker-install.ps1') -Build
if ($LASTEXITCODE -ne 0) { Write-Error 'MyInvoice Docker installation failed.' }

$compose = @('-f', 'docker-compose.yml', '-f', 'docker-compose.revizior-local.yml')
& docker compose @compose up -d db app
if ($LASTEXITCODE -ne 0) { Write-Error 'Docker Compose failed to attach the app to the ReviziOR network.' }

$backendContainer = & docker ps --filter "network=$Network" --filter 'label=com.docker.compose.service=php' --format '{{.Names}}' | Select-Object -First 1
if ($backendContainer) {
    & docker exec $backendContainer php -r '$url="http://revizior-invoice.local/api/health"; $body=@file_get_contents($url); if ($body === false) { fwrite(STDERR, "Backend cannot reach $url\n"); exit(1); } $health=json_decode($body, true); if (($health["status"] ?? null) !== "ok" || !($health["db"] ?? false)) { fwrite(STDERR, "ReviziOR Fakturace health check is not ready\n"); exit(1); } echo "Backend -> ReviziOR Fakturace: OK\n";'
    if ($LASTEXITCODE -ne 0) { Write-Error 'Backend-to-provider health probe failed.' }
} else {
    Write-Warning "No running backend php service found on '$Network'; network alias is configured but not probed."
}

Write-Host ""
Write-Host "ReviziOR Fakturace: http://localhost:$AppPort"
Write-Host 'Backend container URL: http://revizior-invoice.local'
Write-Host 'Compose: docker compose -f docker-compose.yml -f docker-compose.revizior-local.yml'
