<#
.SYNOPSIS
    PocketMine-MP Protocol Update Automation Tool
.DESCRIPTION
    Automates the process of updating Minecraft Bedrock protocol support.
    Based on: https://doc.pmmp.io/en/rtfd/developers/internals-docs/updating-minecraft-protocol.html
.PARAMETER Phase
    Which phase to execute:
      1 - Setup & Data Collection (part 1)
      2 - Update BedrockProtocol code
      3 - Generate supporting data (part 2)
      4 - Complete PocketMine-MP changes
      5 - Verification & Testing
      all - Run all phases interactively
.PARAMETER TargetVersion
    Target Minecraft version (e.g., "1.26.40")
.PARAMETER DepsPath
    Path to deps directory containing BedrockProtocol, BedrockData, etc.
    Defaults to ../deps relative to this script.
.PARAMETER PacketTrace
    Path to packet trace file (for phase 3)
#>

param(
    [Parameter(Position=0)]
    [ValidateSet("1","2","3","4","5","all","status")]
    [string]$Phase = "all",

    [string]$TargetVersion = "",
    [string]$DepsPath = "",
    [string]$PacketTrace = ""
)

$ErrorActionPreference = "Stop"
$PMMP_ROOT = Split-Path -Parent $PSScriptRoot

if ($DepsPath -eq "") {
    $DepsPath = Join-Path (Split-Path -Parent $PMMP_ROOT) "deps"
}

$BEDROCK_PROTOCOL = Join-Path $DepsPath "BedrockProtocol"
$BEDROCK_DATA = Join-Path $DepsPath "BedrockData"
$BEDROCK_BLOCK_SCHEMA = Join-Path $DepsPath "BedrockBlockUpgradeSchema"
$BEDROCK_ITEM_SCHEMA = Join-Path $DepsPath "BedrockItemUpgradeSchema"
$BDS_MODDING_DEVKIT = Join-Path $DepsPath "bds-modding-devkit"

function Write-Header {
    param([string]$Text)
    Write-Host ""
    Write-Host ("=" * 70) -ForegroundColor Cyan
    Write-Host "  $Text" -ForegroundColor Cyan
    Write-Host ("=" * 70) -ForegroundColor Cyan
    Write-Host ""
}

function Write-Step {
    param([string]$Text)
    Write-Host "  [>] $Text" -ForegroundColor Yellow
}

function Write-OK {
    param([string]$Text)
    Write-Host "  [OK] $Text" -ForegroundColor Green
}

function Write-Warn {
    param([string]$Text)
    Write-Host "  [!] $Text" -ForegroundColor DarkYellow
}

function Write-Err {
    param([string]$Text)
    Write-Host "  [X] $Text" -ForegroundColor Red
}

function Write-Info {
    param([string]$Text)
    Write-Host "  $Text" -ForegroundColor Gray
}

function Test-Dependency {
    param([string]$Path, [string]$Name)
    if (Test-Path $Path) {
        Write-OK "$Name found at $Path"
        return $true
    } else {
        Write-Err "$Name NOT found at $Path"
        return $false
    }
}

function Get-CurrentProtocolInfo {
    $protocolInfoPath = Join-Path $PMMP_ROOT "vendor\pocketmine\bedrock-protocol\src\ProtocolInfo.php"
    if (-not (Test-Path $protocolInfoPath)) {
        return $null
    }
    $content = Get-Content $protocolInfoPath -Raw
    $protocol = if ($content -match "CURRENT_PROTOCOL\s*=\s*(\d+)") { $Matches[1] } else { "?" }
    $version = if ($content -match "MINECRAFT_VERSION\s*=\s*'([^']+)'") { $Matches[1] } else { "?" }
    $versionNetwork = if ($content -match "MINECRAFT_VERSION_NETWORK\s*=\s*'([^']+)'") { $Matches[1] } else { "?" }
    return @{
        Protocol = $protocol
        Version = $version
        VersionNetwork = $versionNetwork
    }
}

function Show-Status {
    Write-Header "Current Protocol Status"
    $info = Get-CurrentProtocolInfo
    if ($info) {
        Write-Host "  Protocol version:  $($info.Protocol)" -ForegroundColor White
        Write-Host "  Minecraft version: $($info.Version)" -ForegroundColor White
        Write-Host "  Network version:   $($info.VersionNetwork)" -ForegroundColor White
    }

    Write-Host ""
    Write-Host "  Dependencies:" -ForegroundColor White
    Test-Dependency $BEDROCK_PROTOCOL "BedrockProtocol" | Out-Null
    Test-Dependency $BEDROCK_DATA "BedrockData" | Out-Null
    Test-Dependency $BEDROCK_BLOCK_SCHEMA "BedrockBlockUpgradeSchema" | Out-Null
    Test-Dependency $BEDROCK_ITEM_SCHEMA "BedrockItemUpgradeSchema" | Out-Null
    Test-Dependency $BDS_MODDING_DEVKIT "bds-modding-devkit" | Out-Null

    Write-Host ""
    $worldVersions = Join-Path $PMMP_ROOT "src\data\bedrock\WorldDataVersions.php"
    if (Test-Path $worldVersions) {
        $content = Get-Content $worldVersions -Raw
        $network = if ($content -match "NETWORK\s*=\s*(\d+)") { $Matches[1] } else { "?" }
        Write-Host "  World NETWORK version: $network" -ForegroundColor White
    }
}

function Invoke-Phase1 {
    Write-Header "Phase 1: Setup & Data Collection (Part 1)"

    if ($TargetVersion -eq "") {
        $TargetVersion = Read-Host "  Enter target Minecraft version (e.g., 1.26.40)"
    }

    Write-Step "Checking prerequisites..."
    $allGood = $true
    $allGood = (Test-Dependency $BEDROCK_PROTOCOL "BedrockProtocol") -and $allGood
    $allGood = (Test-Dependency $BEDROCK_DATA "BedrockData") -and $allGood
    $allGood = (Test-Dependency $BDS_MODDING_DEVKIT "bds-modding-devkit") -and $allGood

    if (-not $allGood) {
        Write-Err "Missing dependencies. Clone them first:"
        Write-Info "git clone https://github.com/pmmp/BedrockProtocol $BEDROCK_PROTOCOL"
        Write-Info "git clone https://github.com/pmmp/BedrockData $BEDROCK_DATA"
        Write-Info "git clone https://github.com/pmmp/bds-modding-devkit $BDS_MODDING_DEVKIT"
        Write-Info "git clone https://github.com/pmmp/BedrockBlockUpgradeSchema $BEDROCK_BLOCK_SCHEMA"
        Write-Info "git clone https://github.com/pmmp/BedrockItemUpgradeSchema $BEDROCK_ITEM_SCHEMA"
        return
    }

    Write-Host ""
    Write-Step "Step 1a: Generate protocol_info.json from BDS"
    Write-Info "Run this command in WSL/Linux with BDS binary:"
    Write-Info "  cd $BDS_MODDING_DEVKIT"
    Write-Info "  python3 protocol_info_dumper.py ./bedrock_server_symbols.debug ./protocol_info.json"
    Write-Info ""
    Write-Info "Then copy protocol_info.json to: $BEDROCK_DATA"
    Write-Host ""

    $answer = Read-Host "  Have you generated protocol_info.json? (y/n)"
    if ($answer -eq "y") {
        $srcFile = Read-Host "  Path to generated protocol_info.json"
        if (Test-Path $srcFile) {
            Copy-Item $srcFile (Join-Path $BEDROCK_DATA "protocol_info.json") -Force
            Write-OK "Copied protocol_info.json to BedrockData"
        } else {
            Write-Err "File not found: $srcFile"
        }
    }

    Write-Host ""
    Write-Step "Step 1b: Get data from BDS via mods"
    Write-Info "Follow instructions in bds-modding-devkit README to run the data extraction mod."
    Write-Info "Then copy all FILES (not folders) from mapping_files/ to: $BEDROCK_DATA"
    Write-Host ""

    $answer = Read-Host "  Have you collected mod data? (y/n/skip)"
    if ($answer -eq "y") {
        $mappingDir = Read-Host "  Path to mapping_files/ directory"
        if (Test-Path $mappingDir) {
            Get-ChildItem -Path $mappingDir -File | ForEach-Object {
                Copy-Item $_.FullName (Join-Path $BEDROCK_DATA $_.Name) -Force
                Write-OK "Copied $($_.Name)"
            }
        } else {
            Write-Err "Directory not found: $mappingDir"
        }
    }

    Write-Host ""
    Write-Step "Step 1c: Collect packet traces (vanilla <-> vanilla)"
    Write-Info "1. Create a Minecraft world on target version (enable experiments)"
    Write-Info "2. Configure BDS server.properties to use that world"
    Write-Info "3. Start bedrock_server_symbols.debug directly"
    Write-Info "4. In another terminal: sudo python3 tracer.py rw bedrock_server_symbols.debug"
    Write-Info "5. Join the server with Minecraft and do in-game tests"
    Write-Info "6. Stop the server - note the trace filename"
    Write-Host ""

    $answer = Read-Host "  Have you collected a packet trace? (y/n/skip)"
    if ($answer -eq "y") {
        $script:PacketTrace = Read-Host "  Path to packet trace file"
        if (-not (Test-Path $script:PacketTrace)) {
            Write-Err "File not found: $script:PacketTrace"
        } else {
            Write-OK "Packet trace: $script:PacketTrace"
        }
    }

    Write-Host ""
    Write-OK "Phase 1 complete!"
}

function Invoke-Phase2 {
    Write-Header "Phase 2: Update BedrockProtocol Code"

    if (-not (Test-Path $BEDROCK_PROTOCOL)) {
        Write-Err "BedrockProtocol not found at $BEDROCK_PROTOCOL"
        return
    }
    if (-not (Test-Path $BEDROCK_DATA)) {
        Write-Err "BedrockData not found at $BEDROCK_DATA"
        return
    }

    Write-Step "Step 2a: Link local dependencies to PocketMine-MP..."
    Push-Location $PMMP_ROOT
    try {
        $composerLocal = Join-Path $PMMP_ROOT "composer-local-protocol.json"
        if (-not (Test-Path $composerLocal)) {
            Copy-Item "composer.json" "composer-local-protocol.json"
            Copy-Item "composer.lock" "composer-local-protocol.lock"
        }

        $env:COMPOSER = "composer-local-protocol.json"
        & composer config repositories.bedrock-protocol path $BEDROCK_PROTOCOL
        & composer config repositories.bedrock-data path $BEDROCK_DATA
        if (Test-Path $BEDROCK_BLOCK_SCHEMA) {
            & composer config repositories.bedrock-block-upgrade-schema path $BEDROCK_BLOCK_SCHEMA
        }
        if (Test-Path $BEDROCK_ITEM_SCHEMA) {
            & composer config repositories.bedrock-item-upgrade-schema path $BEDROCK_ITEM_SCHEMA
        }
        & composer require "pocketmine/bedrock-protocol:*@dev" "pocketmine/bedrock-data:*@dev" --no-interaction
        & composer install --no-interaction
        Remove-Item Env:COMPOSER -ErrorAction SilentlyContinue
        Write-OK "Local dependencies linked"
    } finally {
        Pop-Location
    }

    Write-Host ""
    Write-Step "Step 2b: Run BedrockProtocol code generators..."
    $updateTool = Join-Path $BEDROCK_PROTOCOL "tools\update-from-bedrock-data.php"
    if (Test-Path $updateTool) {
        & php $updateTool $BEDROCK_DATA
        Write-OK "BedrockProtocol code updated from BedrockData"
    } else {
        Write-Err "update-from-bedrock-data.php not found"
        return
    }

    Write-Host ""
    Write-Step "Step 2c: Generate ::create() static methods..."
    $createMethods = Join-Path $BEDROCK_PROTOCOL "tools\generate-create-static-methods.php"
    if (Test-Path $createMethods) {
        & php $createMethods
        Write-OK "Static create methods generated"
    }

    Write-Host ""
    Write-Step "Step 2d: Analyze protocol changes..."
    $analyzeScript = Join-Path $PSScriptRoot "analyze-protocol-changes.php"
    if (Test-Path $analyzeScript) {
        & php $analyzeScript $BEDROCK_PROTOCOL
    }

    Write-Host ""
    Write-Warn "MANUAL STEP REQUIRED: Review and update packet structures"
    Write-Info "- Check for new packets that need encode/decode implementation"
    Write-Info "- Check for modified packet structures"
    Write-Info "- Check for removed packets"
    Write-Info "- Reference: https://github.com/Mojang/bedrock-protocol-docs"
    Write-Host ""

    $answer = Read-Host "  Have you finished updating packet structures? (y/n)"
    if ($answer -ne "y") {
        Write-Info "Come back and re-run phase 2 when ready."
        return
    }

    Write-Host ""
    Write-Step "Step 2e: Re-generate ::create() methods after manual changes..."
    if (Test-Path $createMethods) {
        & php $createMethods
        Write-OK "Static create methods re-generated"
    }

    Write-Host ""
    Write-OK "Phase 2 complete!"
}

function Invoke-Phase3 {
    Write-Header "Phase 3: Generate Supporting Data (Part 2)"

    if ($PacketTrace -eq "") {
        $PacketTrace = Read-Host "  Path to packet trace file"
    }
    if (-not (Test-Path $PacketTrace)) {
        Write-Err "Packet trace not found: $PacketTrace"
        return
    }

    Write-Step "Step 3a: Generate BedrockData from packet trace..."
    $genScript = Join-Path $PMMP_ROOT "tools\generate-bedrock-data-from-packets.php"
    if (Test-Path $genScript) {
        & php $genScript $PacketTrace $BEDROCK_DATA
        Write-OK "BedrockData generated from packets"
    } else {
        Write-Err "generate-bedrock-data-from-packets.php not found"
    }

    Write-Host ""
    Write-Step "Step 3b: Generate blockstate upgrade schema..."
    $paletteMapping = Read-Host "  Path to palette mapping file (from BDS mod, in mapping_files/old_palette_mappings/) or 'skip'"
    if ($paletteMapping -ne "skip" -and (Test-Path $paletteMapping)) {
        $schemaUtils = Join-Path $PMMP_ROOT "tools\blockstate-upgrade-schema-utils.php"
        if (Test-Path $schemaUtils) {
            $outputFile = Read-Host "  Output schema filename (e.g., 0272_1.21.60.33_to_1.26.40.0.json)"
            $outputPath = Join-Path $BEDROCK_BLOCK_SCHEMA "nbt_upgrade_schema\$outputFile"
            & php $schemaUtils generate $paletteMapping $outputPath
            Write-OK "Blockstate upgrade schema generated"
        }
    } elseif ($paletteMapping -ne "skip") {
        Write-Warn "Skipping blockstate schema (file not found or skipped)"
    }

    Write-Host ""
    Write-Step "Step 3c: Generate item upgrade schema..."
    $itemMapping = Join-Path $BEDROCK_DATA "r16_to_current_item_map.json"
    if (Test-Path $itemMapping) {
        $itemSchemaDir = Join-Path $BEDROCK_ITEM_SCHEMA "id_meta_upgrade_schema"
        $genItemSchema = Join-Path $PMMP_ROOT "tools\generate-item-upgrade-schema.php"
        if (Test-Path $genItemSchema) {
            $outputFile = Read-Host "  Output item schema filename (e.g., 0182_1.26.30_to_1.26.40.json)"
            $outputPath = Join-Path $itemSchemaDir $outputFile
            & php $genItemSchema $itemMapping $itemSchemaDir $outputPath
            Write-OK "Item upgrade schema generated"
        }
    } else {
        Write-Warn "r16_to_current_item_map.json not found in BedrockData"
    }

    Write-Host ""
    Write-OK "Phase 3 complete!"
}

function Invoke-Phase4 {
    Write-Header "Phase 4: Complete PocketMine-MP Changes"

    Push-Location $PMMP_ROOT
    try {
        Write-Step "Step 4a: Run code generation..."
        & composer run-script update-codegen
        Write-OK "Code generation complete"

        Write-Host ""
        Write-Step "Step 4b: Update WorldDataVersions constants..."
        $updateVersionsScript = Join-Path $PSScriptRoot "update-world-data-versions.php"
        if (Test-Path $updateVersionsScript) {
            & php $updateVersionsScript
        } else {
            Write-Warn "update-world-data-versions.php not found, manual update needed"
            Write-Info "Edit: src/data/bedrock/WorldDataVersions.php"
            Write-Info "Update: LAST_OPENED_IN, NETWORK, BLOCK_STATES"
        }

        Write-Host ""
        Write-Step "Step 4c: Run PHPStan..."
        & php vendor\bin\phpstan analyze --no-progress 2>&1 | ForEach-Object { Write-Host $_ }
        Write-Host ""
        Write-Info "Fix all PHPStan errors before continuing."

        Write-Host ""
        $answer = Read-Host "  Have you fixed all PHPStan errors? (y/n)"
        if ($answer -ne "y") {
            Write-Info "Fix errors and re-run phase 4."
            return
        }

        Write-Step "Step 4d: Run PHPUnit tests..."
        & php vendor\bin\phpunit tests\phpunit 2>&1 | ForEach-Object { Write-Host $_ }
        Write-Host ""
        Write-OK "Phase 4 complete!"
    } finally {
        Pop-Location
    }
}

function Invoke-Phase5 {
    Write-Header "Phase 5: Verification & Testing"

    Push-Location $PMMP_ROOT
    try {
        Write-Step "Running PHPStan..."
        & php vendor\bin\phpstan analyze --no-progress 2>&1 | ForEach-Object { Write-Host $_ }

        Write-Host ""
        Write-Step "Running PHPUnit tests..."
        & php vendor\bin\phpunit tests\phpunit 2>&1 | ForEach-Object { Write-Host $_ }

        Write-Host ""
        Write-Step "Checking protocol consistency..."
        $analyzeScript = Join-Path $PSScriptRoot "analyze-protocol-changes.php"
        if (Test-Path $analyzeScript) {
            & php $analyzeScript $BEDROCK_PROTOCOL --verify
        }

        Write-Host ""
        Write-Warn "MANUAL: Start the server and playtest with Minecraft client"
        Write-Info "php start.php"
    } finally {
        Pop-Location
    }
}

switch ($Phase) {
    "status" { Show-Status }
    "1" { Invoke-Phase1 }
    "2" { Invoke-Phase2 }
    "3" { Invoke-Phase3 }
    "4" { Invoke-Phase4 }
    "5" { Invoke-Phase5 }
    "all" {
        Write-Header "PocketMine-MP Protocol Update Automation"
        Write-Info "Target: Minecraft Bedrock v$TargetVersion"
        Show-Status
        Write-Host ""

        $answer = Read-Host "  Start Phase 1? (y/n)"
        if ($answer -eq "y") { Invoke-Phase1 }

        $answer = Read-Host "  Continue to Phase 2? (y/n)"
        if ($answer -eq "y") { Invoke-Phase2 }

        $answer = Read-Host "  Continue to Phase 3? (y/n)"
        if ($answer -eq "y") { Invoke-Phase3 }

        $answer = Read-Host "  Continue to Phase 4? (y/n)"
        if ($answer -eq "y") { Invoke-Phase4 }

        $answer = Read-Host "  Continue to Phase 5? (y/n)"
        if ($answer -eq "y") { Invoke-Phase5 }

        Write-Host ""
        Write-OK "All phases complete!"
    }
}
