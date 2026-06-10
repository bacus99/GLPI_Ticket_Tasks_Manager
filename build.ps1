# Build a release tarball for the GLPI plugin catalog.
# Output: dist/glpi-tasksmanager-<VERSION>.tar.bz2

[CmdletBinding()]
param(
    [string]$Version
)

$ErrorActionPreference = 'Stop'

# Resolve the version from setup.php if not provided
if (-not $Version) {
    $setup = Get-Content "$PSScriptRoot\setup.php" -Raw
    if ($setup -match "PLUGIN_TASKSMANAGER_VERSION',\s*'([^']+)'") {
        $Version = $Matches[1]
    } else {
        throw "Could not read PLUGIN_TASKSMANAGER_VERSION from setup.php"
    }
}

Write-Host "Building tasksmanager $Version"

$plugin = 'tasksmanager'
$work   = Join-Path $PSScriptRoot ".build"
$stage  = Join-Path $work $plugin
$dist   = Join-Path $PSScriptRoot "dist"
$out    = Join-Path $dist "glpi-$plugin-$Version.tar.bz2"

# Read .glpiignore patterns
$ignoreFile = Join-Path $PSScriptRoot ".glpiignore"
$ignore = @()
if (Test-Path $ignoreFile) {
    $ignore = Get-Content $ignoreFile | Where-Object {
        $_ -and -not $_.StartsWith('#')
    }
}

# Clean & stage
if (Test-Path $work) { Remove-Item -Recurse -Force $work }
New-Item -ItemType Directory -Path $stage | Out-Null
New-Item -ItemType Directory -Path $dist -Force | Out-Null

# Robocopy with exclusions. /XD excludes directories, /XF excludes files.
$xd = @()
$xf = @()
foreach ($p in $ignore) {
    if ($p -match '[\\/]') {
        $xd += $p.TrimEnd('/').Replace('/', '\')
    } elseif ($p -like '*.*' -or $p -like '*?*') {
        $xf += $p
    } else {
        $xd += $p
        $xf += $p
    }
}

# ALWAYS exclude staging + output dirs. .build lives inside $PSScriptRoot, so
# without this robocopy /E recurses into it (.build/<plugin>/.build/...) and hangs.
$xd += $work       # .build
$xd += $dist       # dist

$rcArgs = @(
    $PSScriptRoot, $stage, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP'
) + (@('/XD') + $xd) + (@('/XF') + $xf)

& robocopy @rcArgs | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy failed with code $LASTEXITCODE" }

# Produce the .tar.bz2 (requires tar.exe — bundled with Windows 10+ and Linux/macOS)
Push-Location $work
try {
    & tar -cjf $out $plugin
    if ($LASTEXITCODE -ne 0) { throw "tar failed with code $LASTEXITCODE" }
} finally {
    Pop-Location
}

Remove-Item -Recurse -Force $work
Write-Host "Done: $out"
