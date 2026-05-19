# Clielo — Build script
# Usage:
#   .\build.ps1              → zip WP.org (version gratuite)
#   .\build.ps1 -Premium     → zip Freemius premium (clielo-1.0.0-premium.zip)

param(
    [switch]$Premium
)

$pluginSlug    = "clielo"
$pluginVersion = "1.0.0"
$rootDir       = $PSScriptRoot
$distDir       = Join-Path $rootDir "dist"

# Le dossier racine dans le zip doit correspondre au dossier WordPress attendu :
# - version gratuite  → clielo/
# - version premium   → clielo-premium/  (Freemius l'installe dans ce dossier)
if ($Premium) {
    $zipSlug  = "$pluginSlug-premium"
    $zipPath  = Join-Path $distDir "$pluginSlug-$pluginVersion-premium.zip"
} else {
    $zipSlug  = $pluginSlug
    $zipPath  = Join-Path $distDir "$pluginSlug.zip"
}
$buildDir = Join-Path $distDir $zipSlug

# Fichiers/dossiers à exclure
$exclude = @(
    ".git",
    ".gitignore",
    ".distignore",
    ".claude",
    ".mcp.json",
    "build.ps1",
    "list-zip.ps1",
    "dist",
    "svn",
    "sylvestre-site",
    "composer.json",
    "composer.lock",
    "phpcs.xml",
    "CLAUDE.md",
    "lib\.gitignore",
    "lib\CHANGELOG.md",
    "lib\CONTRIBUTING.md",
    "lib\README.md",
    "lib\justfile",
    "lib\OPENAPI_VERSION",
    "lib\composer.json"
)

# lib/ (Stripe PHP SDK) inclus dans les deux builds.
# La protection premium est gérée au runtime par clielo_is_premium() — pas par l'absence du fichier.

# Nettoyer (dossier de build temporaire uniquement, pas les autres zips)
if (Test-Path $buildDir) { Remove-Item $buildDir -Recurse -Force }
if (Test-Path $zipPath)  { Remove-Item $zipPath -Force }
if (-not (Test-Path $distDir)) { New-Item -ItemType Directory -Path $distDir | Out-Null }
New-Item -ItemType Directory -Path $buildDir | Out-Null

Write-Host "Copying plugin files..." -ForegroundColor Cyan

# Copier tout sauf les exclusions
Get-ChildItem -Path $rootDir -Force | Where-Object {
    $_.Name -notin $exclude
} | ForEach-Object {
    $dest = Join-Path $buildDir $_.Name
    if ($_.PSIsContainer) {
        Copy-Item $_.FullName $dest -Recurse -Force
    } else {
        Copy-Item $_.FullName $dest -Force
    }
}

# Supprimer les fichiers exclus qui sont dans des sous-dossiers
foreach ($exc in $exclude) {
    $target = Join-Path $buildDir $exc
    if (Test-Path $target) {
        Remove-Item $target -Recurse -Force
    }
}

# Pour le zip premium : activer is_premium dans clielo.php
$mainFile = Join-Path $buildDir "clielo.php"
if ($Premium -and (Test-Path $mainFile)) {
    (Get-Content $mainFile -Raw) `
        -replace "'is_premium'\s+=>\s+false,", "'is_premium'          => true," |
        Set-Content $mainFile -NoNewline
    Write-Host "Premium mode applied to clielo.php" -ForegroundColor Yellow
}

# Créer le zip avec forward slashes (compatibilité Linux/serveur)
Write-Host "Creating zip: $zipPath" -ForegroundColor Cyan
Add-Type -AssemblyName System.IO.Compression

$zipStream = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::Create)
$archive   = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)

Get-ChildItem -Path $buildDir -Recurse -File | ForEach-Object {
    $rel   = $_.FullName.Substring($buildDir.Length).TrimStart('\', '/').Replace('\', '/')
    $entry = $archive.CreateEntry("$zipSlug/$rel", [System.IO.Compression.CompressionLevel]::Optimal)
    $dst   = $entry.Open()
    $src   = [System.IO.File]::OpenRead($_.FullName)
    $src.CopyTo($dst)
    $src.Close()
    $dst.Close()
}

$archive.Dispose()
$zipStream.Close()

# Stats
$zipSize  = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
$zipLabel = if ($Premium) { "$pluginSlug-$pluginVersion-premium.zip" } else { "$pluginSlug.zip" }
Write-Host ""
Write-Host "Done! $zipLabel ($zipSize MB)" -ForegroundColor Green
Write-Host "Path: $zipPath" -ForegroundColor Green
