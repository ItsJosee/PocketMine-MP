# PocketMine-MP Protocol Auto-Update System

Automated system for updating Minecraft Bedrock protocol in PocketMine-MP with support for multiple versions (stable + preview).

## Quick Start

### Windows (PowerShell)
```powershell
# Interactive menu
.\tools\auto-update\run-update.ps1

# Version switcher
.\tools\auto-update\switch-version.ps1              # Show menu
.\tools\auto-update\switch-version.ps1 stable       # Switch to stable
.\tools\auto-update\switch-version.ps1 preview      # Switch to preview
```

### Command Line (PHP)
```bash
# Version manager
php tools/auto-update/version-manager.php list           # List versions
php tools/auto-update/version-manager.php current        # Show current
php tools/auto-update/version-manager.php switch stable  # Switch to stable
php tools/auto-update/version-manager.php switch preview # Switch to preview

# Auto-update
php tools/auto-update/auto-update.php check             # Check for updates
php tools/auto-update/auto-update.php update-channel --channel stable  # Update stable
php tools/auto-update/auto-update.php update-channel --channel preview # Update preview
```

## Version Management

### Supported Versions
- **stable**: Latest stable version (recommended for production)
- **preview**: Latest preview version (may have new features/bugs)

### Switching Versions
```powershell
# Interactive
.\tools\auto-update\switch-version.ps1

# Direct
.\tools\auto-update\switch-version.ps1 stable
.\tools\auto-update\switch-version.ps1 preview
```

### Listing Versions
```bash
php tools/auto-update/version-manager.php list
```

### Saving Current State
```bash
php tools/auto-update/version-manager.php save my-version
```

### Comparing Versions
```bash
php tools/auto-update/version-manager.php diff stable preview
```

## Features

- **Multi-version support**: Maintain stable and preview versions simultaneously
- **Automatic version detection**: Checks Mojang's protocol docs for new versions
- **Backup system**: Creates backups before updates, can restore if needed
- **Verification**: Runs PHPStan and tests after update
- **Dry run mode**: Preview changes without applying them

## How It Works

1. **Check**: Queries Mojang's protocol docs for latest version
2. **Download**: Fetches protocol documentation from GitHub
3. **Generate**: Creates protocol_info.json from docs
4. **Update**: Runs BedrockProtocol update scripts
5. **Verify**: Runs static analysis and tests
6. **Save**: Stores version snapshot for future switching

## Requirements

- PHP 8.1+ with curl extension
- Git
- Composer

## Commands

| Command | Description |
|---------|-------------|
| `check` | Check for new Bedrock versions |
| `status` | Show current version and file status |
| `update` | Run full update process |
| `update-protocol` | Update only protocol (no data generation) |
| `update-channel` | Update and save as specific channel |
| `verify` | Verify current update |
| `backup` | Create backup of current state |
| `restore` | Restore from backup |

## Version Manager Commands

| Command | Description |
|---------|-------------|
| `list` | List all available versions |
| `current` | Show current active version |
| `switch <version>` | Switch to a version |
| `save <version>` | Save current state as a version |
| `diff <v1> <v2>` | Compare two versions |

## Options

### Auto-Update Options
| Option | Description |
|--------|-------------|
| `--version <ver>` | Target version (e.g., 1.26.50) |
| `--protocol <num>` | Target protocol version (e.g., 2169) |
| `--skip-backup` | Skip backup creation |
| `--skip-verify` | Skip verification |
| `--dry-run` | Show what would be done without doing it |

### Update-Channel Options
| Option | Description |
|--------|-------------|
| `--channel <name>` | Channel name (stable/preview) |
| `--version <ver>` | Target version (e.g., 1.26.50) |
| `--protocol <num>` | Target protocol version (e.g., 2169) |

## Backup System

Backups are stored in `tools/auto-update/backups/` and include:
- ProtocolInfo.php
- BedrockData files (protocol_info.json, required_item_list.json, etc.)
- Version information

To list backups:
```powershell
.\tools\auto-update\run-update.ps1 restore
```

To restore a backup:
```powershell
.\tools\auto-update\run-update.ps1 restore backup_2026-08-03_15-30-00
```

## File Structure

```
tools/auto-update/
├── auto-update.php          # Main orchestration script
├── version-manager.php      # Version management
├── run-update.ps1           # PowerShell wrapper
├── switch-version.ps1       # Version switcher
├── README.md                # This file
├── versions/                # Version snapshots
│   ├── stable/              # Stable version
│   │   ├── version.json
│   │   ├── ProtocolInfo.php
│   │   └── BedrockData/
│   └── preview/             # Preview version
│       ├── version.json
│       ├── ProtocolInfo.php
│       └── BedrockData/
├── backups/                 # Backup storage
│   └── backup_YYYY-MM-DD/
│       ├── ProtocolInfo.php
│       ├── BedrockData/
│       └── version.json
└── update.log               # Update history
```

## Troubleshooting

### "Could not fetch Mojang docs"
- Check internet connection
- Try again later (GitHub might be rate-limited)

### "PHP not found"
- Ensure PHP is in your PATH
- Or edit `run-update.ps1` to set the PHP path

### "Update script failed"
- Check the error output
- Try running the scripts manually
- See `GUIA_ACTUALIZACION_PROTOCOLO.txt` for manual steps

## Manual Updates

If the automated system fails, see `GUIA_ACTUALIZACION_PROTOCOLO.txt` for the manual update process.
