# PocketMine-MP Version Switcher
# Quick switch between stable and preview versions
#
# Usage:
#   .\switch-version.ps1              # Show menu
#   .\switch-version.ps1 stable       # Switch to stable
#   .\switch-version.ps1 preview      # Switch to preview
#   .\switch-version.ps1 current      # Show current version

param(
    [string]$Version = ""
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$PHPMPDir = Split-Path -Parent (Split-Path -Parent $ScriptDir)
$VersionManagerPHP = "$ScriptDir\version-manager.php"

# Colors
function Write-Color {
    param([string]$Text, [string]$Color = "White")
    Write-Host $Text -ForegroundColor $Color
}

function Show-Banner {
    Write-Color "======================================" Cyan
    Write-Color "  PocketMine-MP Version Switcher" Cyan
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

# Main script
Show-Banner

if ($Version -eq "") {
    # Interactive mode
    Write-Color "Available versions:" Yellow
    Write-Host ""
    Write-Color "  1. stable   - Latest stable version (recommended for production)" Green
    Write-Color "  2. preview  - Latest preview version (may have new features/bugs)" Yellow
    Write-Color "  3. current  - Show current version" Green
    Write-Color "  4. list     - List all saved versions" Green
    Write-Color "  5. exit     - Exit" Green
    Write-Host ""
    
    $choice = Read-Host "Enter your choice (1-5)"
    
    switch ($choice) {
        "1" { $Version = "stable" }
        "2" { $Version = "preview" }
        "3" { 
            Write-Host ""
            Invoke-PHP $VersionManagerPHP "current"
            exit 0
        }
        "4" { 
            Write-Host ""
            Invoke-PHP $VersionManagerPHP "list"
            exit 0
        }
        "5" { 
            Write-Color "Exiting..." Yellow
            exit 0
        }
        default {
            Write-Color "Invalid choice!" Red
            exit 1
        }
    }
}

# Switch version
Write-Host ""
Write-Color "Switching to $Version..." Cyan
Write-Host ""

Invoke-PHP $VersionManagerPHP "switch" $Version

if ($LASTEXITCODE -eq 0) {
    Write-Host ""
    Write-Color "======================================" Green
    Write-Color "  Switch complete!" Green
    Write-Color "======================================" Green
    Write-Host ""
    Write-Color "To start the server:" Yellow
    Write-Host "  cd $PHPMPDir"
    Write-Host "  php start.php"
} else {
    Write-Host ""
    Write-Color "Switch failed!" Red
}
