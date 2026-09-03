[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$Email,

    [Parameter(Mandatory = $true)]
    [string]$Name,

    [switch]$Confirm
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$Arguments = @(
    (Join-Path $ProjectRoot 'api/bin/revizior-bootstrap-platform-admin.php'),
    "--email=$Email",
    "--name=$Name"
)
if ($Confirm) { $Arguments += '--confirm' }

& php @Arguments
exit $LASTEXITCODE
