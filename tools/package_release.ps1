<#
.SYNOPSIS
    Builds the deployment zip for IONOS, with the exclusions that matter.

.DESCRIPTION
    Compress-Archive has no exclude flag, so this stages a filtered copy with
    robocopy first and zips that.

    The exclusion that matters most is config/config.php. It holds the
    environment's own database credentials and master encryption key, so the
    copy on the host must never be replaced by whichever copy happens to be in
    the working tree - a localhost config deployed over the live one points the
    site at a database that is not there, and its master key cannot decrypt
    anything already stored under the real one.

    Config is therefore deployed by hand, once, and never by this script.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\package_release.ps1
#>

[CmdletBinding()]
param(
    [string] $OutputPath = '..\diary-app.zip',
    [string] $StagePath = '..\deploy-stage'
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
Push-Location $repoRoot

try {
    if (-not (Test-Path 'public\index.php')) {
        throw "This does not look like the repository root: public\index.php not found."
    }

    if (Test-Path $StagePath) {
        Remove-Item $StagePath -Recurse -Force
    }

    # Directories that have no business on a web host: history, specs, test and
    # dev-only artefacts, the offline database, and runtime logs.
    #
    # A bare name matches a directory of that name at any depth, which is what
    # is wanted for these. A nested one has to be an absolute path for the same
    # reason the file exclusions below do.
    $excludeDirs = @(
        '.git', '.kiro', '.cursor', '.phpunit.cache',
        '.local-mariadb', 'logs',
        (Join-Path $repoRoot 'public\preview')
    )

    # Every real config and every backup of one. Secrets stay on the host.
    #
    # These must be absolute paths: robocopy's /XF matches a relative path like
    # "config\config.php" against nothing, so the file is copied regardless and
    # the exclusion silently does not happen.
    $excludeFiles = @(
        (Join-Path $repoRoot 'config\config.php'),
        (Join-Path $repoRoot 'config\config.production.php.bak'),
        (Join-Path $repoRoot 'config\config.local.php.bak'),
        '*.log'
    )

    Write-Host 'Staging a filtered copy...'
    robocopy . $StagePath /E /NFL /NDL /NJH /NJS /NP /XD $excludeDirs /XF $excludeFiles | Out-Null

    # robocopy exit codes below 8 are success; 8 and above are real failures.
    if ($LASTEXITCODE -ge 8) {
        throw "robocopy failed with exit code $LASTEXITCODE."
    }

    # A staged config would be a live-credential leak into a zip that gets
    # unpacked over the host's own. Fail loudly rather than ship it.
    $leaked = Get-ChildItem -Path $StagePath -Recurse -Filter 'config.*php*' -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -ne 'config.example.php' }

    if ($leaked) {
        throw "Refusing to package: real config file(s) reached the staging copy - $($leaked.Name -join ', ')"
    }

    if (Test-Path $OutputPath) {
        Remove-Item $OutputPath -Force
    }

    # Not Compress-Archive: on Windows PowerShell 5.1 it writes entry names
    # with backslashes, which is not what the zip format specifies. Linux
    # unzip then treats "public\index.php" as a single flat filename rather
    # than a path, and the extracted tree has no directories at all. Writing
    # the entries directly lets the separators be normalised to "/".
    Add-Type -AssemblyName System.IO.Compression | Out-Null
    Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null

    $stageFull = (Resolve-Path $StagePath).Path
    $outputFull = [System.IO.Path]::GetFullPath((Join-Path $repoRoot $OutputPath))

    Write-Host 'Compressing...'
    $archive = [System.IO.Compression.ZipFile]::Open($outputFull, 'Create')
    try {
        foreach ($file in Get-ChildItem -Path $stageFull -Recurse -File -Force) {
            $relative = $file.FullName.Substring($stageFull.Length + 1).Replace('\', '/')
            [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive, $file.FullName, $relative, 'Optimal'
            )
        }
    }
    finally {
        $archive.Dispose()
    }

    Remove-Item $StagePath -Recurse -Force

    $zip = Get-Item $outputFull
    Write-Host ''
    Write-Host "Built $($zip.FullName) ($([math]::Round($zip.Length / 1MB, 1)) MB)"
    Write-Host 'config/config.php is NOT in this zip. The host keeps its own.'
}
finally {
    Pop-Location
}
