$ErrorActionPreference = 'Stop'
$ProjectWindows = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
. (Join-Path $PSScriptRoot 'load-env.ps1')
$EnvConfig = Import-DotEnv (Join-Path $ProjectWindows '.env')
Assert-EnvKeys $EnvConfig @(
    'WSL_DISTRO', 'APP_PORT', 'DB_EXPOSE_PORT',
    'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD',
    'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'
)
$Distro = $EnvConfig.WSL_DISTRO
$ProjectWsl = (wsl -d $Distro -- wslpath -a $ProjectWindows).Trim()

if (-not $ProjectWsl) {
    throw 'Could not resolve the project path inside WSL.'
}

wsl -d $Distro -- podman build -t localhost/tpshop-php:7.0 "$ProjectWsl/containers/php70"
if ($LASTEXITCODE -ne 0) { throw 'PHP image build failed.' }

wsl -d $Distro -- podman pod exists tpshop
if ($LASTEXITCODE -eq 0) {
    Write-Host 'Recreating service containers so the current .env values are applied...'
    wsl -d $Distro -- podman pod rm -f tpshop
    if ($LASTEXITCODE -ne 0) { throw 'Could not recreate the existing pod.' }
}

wsl -d $Distro -- podman pod create --name tpshop -p "$($EnvConfig.APP_PORT):80" -p "$($EnvConfig.DB_EXPOSE_PORT):3306"
if ($LASTEXITCODE -ne 0) { throw 'Pod creation failed.' }

wsl -d $Distro -- podman run -d --pod tpshop --name tpshop-os `
    docker.io/library/centos:7 sleep infinity
wsl -d $Distro -- podman run -d --pod tpshop --name tpshop-db `
    --env-file "$ProjectWsl/.env" `
    -v tpshop-mysql:/var/lib/mysql `
    docker.io/library/mysql:5.7.36
wsl -d $Distro -- podman run -d --pod tpshop --name tpshop-php `
    --env-file "$ProjectWsl/.env" `
    -v "${ProjectWsl}/public:/var/www/html:ro" `
    localhost/tpshop-php:7.0
wsl -d $Distro -- podman run -d --pod tpshop --name tpshop-nginx `
    -v "${ProjectWsl}/public:/var/www/html:ro" `
    -v "${ProjectWsl}/config/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro" `
    docker.io/library/nginx:1.20.1

if ($LASTEXITCODE -ne 0) { throw 'A service failed to start.' }
Write-Host "TPshop environment is running at http://localhost:$($EnvConfig.APP_PORT)"

