# Builds the installer and update package for one version.
#   powershell -ExecutionPolicy Bypass -File desktop\build-release.ps1 -Version 1.0.0
# Upload both files from desktop\releases at /admin/desktop-releases.
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d{1,5}\.\d{1,5}\.\d{1,5}(-[0-9A-Za-z.]{1,16})?$')]
    [string]$Version
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$project = Join-Path $root 'EduTaskNotifier\EduTaskNotifier.csproj'
$publish = Join-Path $root 'publish'
$releases = Join-Path $root 'releases'

$dotnet = (Get-Command dotnet -ErrorAction SilentlyContinue).Source
if (-not $dotnet) { $dotnet = 'C:\Program Files\dotnet\dotnet.exe' }
$vpk = (Get-Command vpk -ErrorAction SilentlyContinue).Source
if (-not $vpk) { $vpk = Join-Path $env:LOCALAPPDATA 'EduTaskTools\vpk.exe' }
if (-not (Test-Path $vpk)) {
    & $dotnet tool install --tool-path (Split-Path $vpk) vpk --version 1.2.161
    if ($LASTEXITCODE -ne 0) { throw 'Could not install vpk' }
}

if (Test-Path $publish) { Remove-Item -LiteralPath $publish -Recurse -Force }
& $dotnet publish $project -c Release -r win-x64 --self-contained true -p:Version=$Version -o $publish -nologo
if ($LASTEXITCODE -ne 0) { throw 'dotnet publish failed' }

& $vpk pack --packId EduTaskNotifier --packVersion $Version --packDir $publish `
    --mainExe EduTaskNotifier.exe --packTitle 'EduTask Notifier' --packAuthors 'RVC' `
    --delta None --outputDir $releases
if ($LASTEXITCODE -ne 0) { throw 'vpk pack failed' }

Get-ChildItem $releases -File | Where-Object { $_.Name -like "*$Version*" -or $_.Name -like '*Setup.exe' } |
    Select-Object Name, @{ n = 'MB'; e = { [math]::Round($_.Length / 1MB, 1) } }
