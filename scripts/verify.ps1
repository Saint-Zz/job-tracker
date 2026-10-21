$ErrorActionPreference = 'Stop'
$ProjectWindows = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
. (Join-Path $PSScriptRoot 'load-env.ps1')
$EnvConfig = Import-DotEnv (Join-Path $ProjectWindows '.env')
Assert-EnvKeys $EnvConfig @('WSL_DISTRO', 'APP_PORT')
$Distro = $EnvConfig.WSL_DISTRO

$CentOS = wsl -d $Distro -- podman exec tpshop-os cat /etc/centos-release
$MySQL = wsl -d $Distro -- podman exec tpshop-db mysqld --version
$PreviousErrorAction = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
$Nginx = wsl -d $Distro -- podman exec tpshop-nginx nginx -v 2>&1
$ErrorActionPreference = $PreviousErrorAction
$PHP = (wsl -d $Distro -- podman exec tpshop-php php --version)[0]
$Modules = wsl -d $Distro -- podman exec tpshop-php php -m
$ModuleStatus = "$([int]($Modules -contains 'mysqli')) $([int]($Modules -contains 'pdo_mysql'))"
$Web = Invoke-WebRequest -Uri "http://localhost:$($EnvConfig.APP_PORT)" -TimeoutSec 10
$AuthStatus = Invoke-RestMethod -Uri "http://localhost:$($EnvConfig.APP_PORT)/api.php?action=auth-status" -TimeoutSec 10

Write-Host "OS:       $CentOS"
Write-Host "Database: $MySQL"
Write-Host "Web:      $Nginx"
Write-Host "PHP:      $PHP"
Write-Host "DB ext:   mysqli/pdo_mysql = $ModuleStatus"
Write-Host "HTTP:     $($Web.StatusCode)"
Write-Host "API/DB:   connected"

if ($CentOS -notmatch '7\.9\.2009' -or
    $MySQL -notmatch '5\.7\.36' -or
    $Nginx -notmatch '1\.20\.1' -or
    $PHP -notmatch 'PHP 7\.0\.' -or
    $ModuleStatus -ne '1 1' -or
    $Web.StatusCode -ne 200 -or
    $null -eq $AuthStatus.setup_required) {
    throw 'Environment verification failed.'
}
