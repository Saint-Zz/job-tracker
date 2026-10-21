$ErrorActionPreference = 'Stop'
$ProjectWindows = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
. (Join-Path $PSScriptRoot 'load-env.ps1')
$EnvConfig = Import-DotEnv (Join-Path $ProjectWindows '.env')
Assert-EnvKeys $EnvConfig @('WSL_DISTRO', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'APP_PORT')
$Distro = $EnvConfig.WSL_DISTRO
$DatabaseArgs = @('-u', $EnvConfig.DB_USER, '-D', $EnvConfig.DB_NAME, '-N', '-B')

Write-Host 'Reading administrator accounts...'
$admins = @(wsl -d $Distro -- podman exec -e "MYSQL_PWD=$($EnvConfig.DB_PASSWORD)" tpshop-db `
    mysql @DatabaseArgs -e "SELECT username FROM app_users WHERE role='admin' ORDER BY id;")

if ($LASTEXITCODE -ne 0) {
    throw 'Cannot connect to the database. Run .\scripts\start.ps1 first.'
}

$admins = @($admins | Where-Object { $_ -and $_.Trim() })
if ($admins.Count -eq 0) {
    throw "No administrator account exists. Open http://localhost:$($EnvConfig.APP_PORT) to create the first administrator."
}

Write-Host 'Administrator accounts:'
$admins | ForEach-Object { Write-Host "  - $_" }

if ($admins.Count -eq 1) {
    $username = $admins[0]
    Write-Host "Administrator to reset: $username"
} else {
    $username = Read-Host 'Enter the administrator username to reset'
    if ($admins -notcontains $username) {
        throw 'The entered username is not an administrator account.'
    }
}

$securePassword = Read-Host 'Enter a new password (at least 8 characters; input is hidden)' -AsSecureString
$confirmPassword = Read-Host 'Enter the new password again' -AsSecureString
$passwordPtr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
$confirmPtr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($confirmPassword)

try {
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPtr)
    $plainConfirm = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($confirmPtr)
    if ($plainPassword.Length -lt 8) { throw 'The password must contain at least 8 characters.' }
    if ($plainPassword -cne $plainConfirm) { throw 'The two passwords do not match.' }

    $hash = $plainPassword | wsl -d $Distro -- podman exec -i tpshop-php php -r 'echo password_hash(rtrim(stream_get_contents(STDIN)), PASSWORD_DEFAULT);'
    if ($LASTEXITCODE -ne 0 -or -not $hash) { throw 'Failed to create the password hash.' }

    $usernameB64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($username))
    $hashB64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($hash.Trim()))
    $sql = "UPDATE app_users SET password_hash=CONVERT(FROM_BASE64('$hashB64') USING utf8mb4), is_active=1 WHERE username=CONVERT(FROM_BASE64('$usernameB64') USING utf8mb4) AND role='admin'; SELECT ROW_COUNT();"
    $updated = wsl -d $Distro -- podman exec -e "MYSQL_PWD=$($EnvConfig.DB_PASSWORD)" tpshop-db `
        mysql @DatabaseArgs -e $sql

    if ($LASTEXITCODE -ne 0 -or [int]($updated | Select-Object -Last 1) -ne 1) {
        throw 'Password reset failed.'
    }

    Write-Host "Administrator password reset completed. Sign in at http://localhost:$($EnvConfig.APP_PORT)." -ForegroundColor Green
} finally {
    $plainPassword = $null
    $plainConfirm = $null
    if ($passwordPtr -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPtr) }
    if ($confirmPtr -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($confirmPtr) }
}
