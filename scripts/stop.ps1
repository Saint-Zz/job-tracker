$ErrorActionPreference = 'Stop'
$ProjectWindows = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
. (Join-Path $PSScriptRoot 'load-env.ps1')
$EnvConfig = Import-DotEnv (Join-Path $ProjectWindows '.env')
Assert-EnvKeys $EnvConfig @('WSL_DISTRO')
$Distro = $EnvConfig.WSL_DISTRO

wsl -d $Distro -- podman pod exists tpshop
if ($LASTEXITCODE -ne 0) {
    Write-Host 'TPshop environment does not exist.'
    exit 0
}

wsl -d $Distro -- podman pod stop tpshop
if ($LASTEXITCODE -ne 0) { throw 'Could not stop the TPshop environment.' }
Write-Host 'TPshop environment stopped.'

