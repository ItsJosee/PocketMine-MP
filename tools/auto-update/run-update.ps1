# PocketMine-MP Protocol Auto-Update System
# PowerShell wrapper for Windows users
#
# Usage:
#   .\run-update.ps1                    # Show menu
#   .\run-update.ps1 check              # Check for updates
#   .\run-update.ps1 status             # Show status
#   .\run-update.ps1 update             # Run full update
#   .\run-update.ps1 update-protocol 1001  # Update protocol only
#   .\run-update.ps1 verify             # Verify update
#   .\run-update.ps1 backup             # Create backup
#   .\run-update.ps1 restore            # Restore from backup

param(
    [string]$Command = "",
    [string]$Version = "",
    [int]$Protocol = 0,
    [switch]$SkipBackup,
    [switch]$SkipVerify,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$PHPMPDir = Split-Path -Parent (Split-Path -Parent $ScriptDir)
$AutoUpdatePHP = "$ScriptDir\auto-update.php"

# Colors
function Write-Color {
    param([string]$Text, [string]$Color = "White")
    Write-Host $Text -ForegroundColor $Color
}

function Show-Banner {
    Write-Color "======================================" Cyan
    Write-Color "  PocketMine-MP Protocol Auto-Update" Cyan
    Write-Color "======================================" Cyan
    Write-Host ""
}

function Show-Menu {
    Write-Color "Available commands:" Yellow
    Write-Host ""
    Write-Color "  1. Check for updates" Green
    Write-Color "  2. Show status" Green
    Write-Color "  3. Run full update" Green
    Write-Color "  4. Update protocol only" Green
    Write-Color "  5. Verify update" Green
    Write-Color "  6. Create backup" Green
    Write-Color "  7. Restore from backup" Green
    Write-Color "  8. Exit" Green
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

if ($Command -eq "") {
    # Interactive mode
    do {
        Show-Menu
        $choice = Read-Host "Enter your choice (1-8)"
        
        switch ($choice) {
            "1" {
                Write-Host ""
                Invoke-PHP $AutoUpdatePHP "check"
            }
            "2" {
                Write-Host ""
                Invoke-PHP $AutoUpdatePHP "status"
            }
            "3" {
                Write-Host ""
                $targetVersion = Read-Host "Target version (e.g., 1.26.50) or press Enter to auto-detect"
                $targetProtocol = Read-Host "Target protocol (e.g., 2169) or press Enter to auto-detect"
                
                $args = @("update")
                if ($targetVersion) { $args += "--version"; $args += $targetVersion }
                if ($targetProtocol) { $args += "--protocol"; $args += $targetProtocol }
                if ($SkipBackup) { $args += "--skip-backup" }
                if ($SkipVerify) { $args += "--skip-verify" }
                if ($DryRun) { $args += "--dry-run" }
                
                Invoke-PHP $AutoUpdatePHP $args
            }
            "4" {
                Write-Host ""
                $protocol = Read-Host "Protocol version (e.g., 1001)"
                if ($protocol) {
                    Invoke-PHP $AutoUpdatePHP "update-protocol" $protocol
                } else {
                    Write-Color "Protocol version required!" Red
                }
            }
            "5" {
                Write-Host ""
                Invoke-PHP $AutoUpdatePHP "verify"
            }
            "6" {
                Write-Host ""
                Invoke-PHP $AutoUpdatePHP "backup"
            }
            "7" {
                Write-Host ""
                Invoke-PHP $AutoUpdatePHP "restore"
            }
            "8" {
                Write-Color "Exiting..." Yellow
                break
            }
            default {
                Write-Color "Invalid choice!" Red
            }
        }
        
        Write-Host ""
        Write-Color "Press any key to continue..." DarkGray
        $null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
        Write-Host ""
    } while ($choice -ne "8")
} else {
    # Direct command mode
    $args = @($Command)
    if ($Version) { $args += "--version"; $args += $Version }
    if ($Protocol -gt 0) { $args += "--protocol"; $args += $Protocol.ToString() }
    if ($SkipBackup) { $args += "--skip-backup" }
    if ($SkipVerify) { $args += "--skip-verify" }
    if ($DryRun) { $args += "--dry-run" }
    
    Invoke-PHP $AutoUpdatePHP $args
}
