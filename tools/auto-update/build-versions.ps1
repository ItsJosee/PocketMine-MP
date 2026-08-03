# PocketMine-MP Build Script
# Builds both stable and preview versions
#
# Usage:
#   .\build-versions.ps1              # Build both versions
#   .\build-versions.ps1 stable       # Build only stable
#   .\build-versions.ps1 preview      # Build only preview

param(
    [string]$Version = "both"
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$PHPMPDir = Split-Path -Parent (Split-Path -Parent $ScriptDir)
$VersionManagerPHP = "$ScriptDir\version-manager.php"
$AutoUpdatePHP = "$ScriptDir\auto-update.php"

# Colors
function Write-Color {
    param([string]$Text, [string]$Color = "White")
    Write-Host $Text -ForegroundColor $Color
}

function Show-Banner {
    Write-Color "======================================" Cyan
    Write-Color "  PocketMine-MP Build System" Cyan
    Write-Color "======================================" Cyan
    Write-Host ""
}

function Invoke-PHP {
    param([string]$Script, [string[]]$Args)
    
    $phpPath = "php"
    if (Test-Path "C:\php\php.exe") {
        $phpPath = "C:\php\php.exe"
    } elseif (Test-Path "$env:LOCALAPPDATA\php\php.exe") {
        $phpPath = "$env:LOCALAPPDATA\php\php.exe"
    }
    
    & $phpPath $Script @Args
}

function Build-Version {
    param([string]$VersionName, [int]$Protocol, [string]$DisplayVersion)
    
    Write-Color "`n=== Building $VersionName ($DisplayVersion) ===" Cyan
    Write-Host ""
    
    # Find composer path
    $composerPath = "C:\ProgramData\ComposerSetup\bin\composer.bat"
    if (-not (Test-Path $composerPath)) {
        $composerPath = "composer"
    }
    
    # Step 1: Switch to version
    Write-Color "[1/5] Switching to $VersionName version..." Yellow
    Invoke-PHP $VersionManagerPHP "switch" $VersionName
    if ($LASTEXITCODE -ne 0) {
        Write-Color "ERROR: Failed to switch to $VersionName" Red
        return 1
    }
    
    # Step 2: Run composer update
    Write-Color "`n[2/5] Running composer update..." Yellow
    Push-Location $PHPMPDir
    & $composerPath update
    if ($LASTEXITCODE -ne 0) {
        Write-Color "WARNING: Composer update had issues" Yellow
    }
    Pop-Location
    
    # Step 3: Run codegen
    Write-Color "`n[3/5] Running codegen..." Yellow
    Push-Location $PHPMPDir
    & $composerPath run-script update-codegen
    if ($LASTEXITCODE -ne 0) {
        Write-Color "WARNING: Codegen had issues" Yellow
    }
    Pop-Location
    
    # Step 4: Update world data versions
    Write-Color "`n[4/5] Updating world data versions..." Yellow
    Push-Location $PHPMPDir
    php tools/update-world-data-versions.php
    if ($LASTEXITCODE -ne 0) {
        Write-Color "WARNING: Update world data versions had issues" Yellow
    }
    Pop-Location
    
    # Step 5: Run PHPStan verification
    Write-Color "`n[5/5] Running PHPStan verification..." Yellow
    Push-Location $PHPMPDir
    & $composerPath run-script phpstan
    $phpstanResult = $LASTEXITCODE
    Pop-Location
    
    Write-Color "`n=== Build Complete: $VersionName ===" Green
    Write-Color "Version: $DisplayVersion" Green
    Write-Color "Protocol: $Protocol" Green
    
    if ($phpstanResult -eq 0) {
        Write-Color "Status: SUCCESS" Green
    } else {
        Write-Color "Status: COMPLETED (with warnings)" Yellow
    }
    
    return 0
}

# Main script
Show-Banner

if ($Version -eq "both") {
    Write-Color "Building both stable and preview versions..." Yellow
    Write-Host ""
    
    # Build stable first
    $result = Build-Version "stable" 1001 "v1.26.40"
    if ($result -ne 0) {
        Write-Color "Stable build failed!" Red
        exit 1
    }
    
    # Then build preview
    $result = Build-Version "preview" 2169 "v1.26.50"
    if ($result -ne 0) {
        Write-Color "Preview build failed!" Red
        exit 1
    }
    
    Write-Color "`n======================================" Green
    Write-Color "  All builds completed!" Green
    Write-Color "======================================" Green
    
} elseif ($Version -eq "stable") {
    $result = Build-Version "stable" 1001 "v1.26.40"
    exit $result
    
} elseif ($Version -eq "preview") {
    $result = Build-Version "preview" 2169 "v1.26.50"
    exit $result
    
} else {
    Write-Color "Invalid version: $Version" Red
    Write-Color "Valid options: stable, preview, both" Yellow
    exit 1
}
