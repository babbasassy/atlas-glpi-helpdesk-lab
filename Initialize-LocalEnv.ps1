$ErrorActionPreference = 'Stop'
$path = Join-Path $PSScriptRoot '.env'
if (Test-Path -LiteralPath $path) { throw 'Existing .env preserved. Refusing overwrite.' }
function New-LabSecret {
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes)
}
$content = @(
    'GLPI_DB_HOST=db', 'GLPI_DB_PORT=3306', 'GLPI_DB_NAME=atlas_helpdesk',
    'GLPI_DB_USER=atlas_glpi', ('GLPI_DB_PASSWORD=' + (New-LabSecret)),
    ('LAB_DB_ROOT_PASSWORD=' + (New-LabSecret))
)
[IO.File]::WriteAllLines($path, $content, (New-Object Text.UTF8Encoding($false)))
Write-Output 'Created private local .env; values are not printed.'
