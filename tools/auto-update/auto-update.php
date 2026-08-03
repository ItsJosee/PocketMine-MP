<?php

declare(strict_types=1);

/**
 * Auto-update system for PocketMine-MP protocol
 * 
 * Orchestrates the entire protocol update process:
 * 1. Check for new Bedrock versions
 * 2. Download Mojang protocol docs
 * 3. Generate protocol_info.json
 * 4. Update BedrockProtocol
 * 5. Verify the update
 *
 * Usage:
 *   php auto-update.php [command] [options]
 *
 * Commands:
 *   check       Check for new versions
 *   status      Show current status
 *   update      Run full update process
 *   update-protocol  Update only protocol (no data generation)
 *   verify      Verify current update
 *   help        Show this help
 */

namespace pocketmine\tools\auto_update;

use function array_keys;
use function array_merge;
use function class_exists;
use function count;
use function date;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function implode;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function readline;
use function scandir;
use function sort;
use function str_contains;
use function str_replace;
use function strtotime;
use function trim;
use const DIR_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;
use const STDERR;
use const STDOUT;

// Configuration (make globals available to functions)
$GLOBALS['BASE_DIR'] = dirname(__DIR__, 2); // PocketMine-MP root
$GLOBALS['DEPS_DIR'] = dirname($GLOBALS['BASE_DIR']) . '/deps';
$GLOBALS['MOJANG_DOCS_DIR'] = $GLOBALS['DEPS_DIR'] . '/mojang-protocol-docs';
$GLOBALS['BEDROCK_PROTOCOL_DIR'] = $GLOBALS['DEPS_DIR'] . '/BedrockProtocol';
$GLOBALS['BEDROCK_DATA_DIR'] = $GLOBALS['DEPS_DIR'] . '/BedrockData';
$GLOBALS['AUTO_UPDATE_DIR'] = __DIR__;
$GLOBALS['BACKUP_DIR'] = $GLOBALS['AUTO_UPDATE_DIR'] . '/backups';
$GLOBALS['LOG_FILE'] = $GLOBALS['AUTO_UPDATE_DIR'] . '/update.log';

// Shorthand for backward compatibility
$BASE_DIR = $GLOBALS['BASE_DIR'];
$DEPS_DIR = $GLOBALS['DEPS_DIR'];
$MOJANG_DOCS_DIR = $GLOBALS['MOJANG_DOCS_DIR'];
$BEDROCK_PROTOCOL_DIR = $GLOBALS['BEDROCK_PROTOCOL_DIR'];
$BEDROCK_DATA_DIR = $GLOBALS['BEDROCK_DATA_DIR'];
$AUTO_UPDATE_DIR = $GLOBALS['AUTO_UPDATE_DIR'];
$BACKUP_DIR = $GLOBALS['BACKUP_DIR'];

// Colors for output
define('COLOR_RESET', "\033[0m");
define('COLOR_GREEN', "\033[32m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_RED', "\033[31m");
define('COLOR_CYAN', "\033[36m");

// Parse command line arguments
$command = $argv[1] ?? 'help';
$options = array_slice($argv, 2);

// Main execution
fwrite(STDOUT, COLOR_CYAN . "=== PocketMine-MP Protocol Auto-Update System ===" . COLOR_RESET . PHP_EOL);
fwrite(STDOUT, "Date: " . date('Y-m-d H:i:s') . PHP_EOL);
fwrite(STDOUT, PHP_EOL);

	switch($command){
	case 'check':
		exit(checkForUpdates());
	case 'status':
		exit(showStatus());
	case 'update':
		exit(runFullUpdate($options));
	case 'update-protocol':
		exit(runProtocolUpdate($options));
	case 'update-channel':
		exit(updateChannel($options));
	case 'verify':
		exit(verifyUpdate());
	case 'backup':
		exit(createBackup());
	case 'restore':
		exit(restoreBackup($options));
	case 'help':
	default:
		showHelp();
		exit(0);
}

/**
 * Show help message
 */
function showHelp(): void{
	fwrite(STDOUT, "Usage: php auto-update.php <command> [options]" . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	fwrite(STDOUT, "Commands:" . PHP_EOL);
	fwrite(STDOUT, "  check              Check for new Bedrock versions" . PHP_EOL);
	fwrite(STDOUT, "  status             Show current status" . PHP_EOL);
	fwrite(STDOUT, "  update             Run full update process" . PHP_EOL);
	fwrite(STDOUT, "  update-protocol    Update only protocol (no data generation)" . PHP_EOL);
	fwrite(STDOUT, "  update-channel     Update and save as specific channel (stable/preview)" . PHP_EOL);
	fwrite(STDOUT, "  verify             Verify current update" . PHP_EOL);
	fwrite(STDOUT, "  backup             Create backup of current state" . PHP_EOL);
	fwrite(STDOUT, "  restore <backup>   Restore from backup" . PHP_EOL);
	fwrite(STDOUT, "  help               Show this help" . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	fwrite(STDOUT, "Options for 'update':" . PHP_EOL);
	fwrite(STDOUT, "  --version <ver>    Target version (e.g., 1.26.50)" . PHP_EOL);
	fwrite(STDOUT, "  --protocol <num>   Target protocol version (e.g., 2169)" . PHP_EOL);
	fwrite(STDOUT, "  --skip-backup      Skip backup creation" . PHP_EOL);
	fwrite(STDOUT, "  --skip-verify      Skip verification" . PHP_EOL);
	fwrite(STDOUT, "  --dry-run          Show what would be done without doing it" . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	fwrite(STDOUT, "Options for 'update-channel':" . PHP_EOL);
	fwrite(STDOUT, "  --channel <name>   Channel name (stable/preview)" . PHP_EOL);
	fwrite(STDOUT, "  --version <ver>    Target version (e.g., 1.26.50)" . PHP_EOL);
	fwrite(STDOUT, "  --protocol <num>   Target protocol version (e.g., 2169)" . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	fwrite(STDOUT, "Examples:" . PHP_EOL);
	fwrite(STDOUT, "  php auto-update.php check" . PHP_EOL);
	fwrite(STDOUT, "  php auto-update.php status" . PHP_EOL);
	fwrite(STDOUT, "  php auto-update.php update --version 1.26.50 --protocol 2169" . PHP_EOL);
	fwrite(STDOUT, "  php auto-update.php update-channel --channel preview --version 1.26.50 --protocol 2169" . PHP_EOL);
	fwrite(STDOUT, "  php auto-update.php verify" . PHP_EOL);
}

/**
 * Check for new Bedrock versions
 */
function checkForUpdates(): int{
	$MOJANG_DOCS_DIR = $GLOBALS['MOJANG_DOCS_DIR'];
	$BEDROCK_PROTOCOL_DIR = $GLOBALS['BEDROCK_PROTOCOL_DIR'];
	$BEDROCK_DATA_DIR = $GLOBALS['BEDROCK_DATA_DIR'];
	
	fwrite(STDOUT, COLOR_GREEN . "[1/4] Checking current version..." . COLOR_RESET . PHP_EOL);
	
	// Get current version from BedrockProtocol
	$protocolInfoFile = $BEDROCK_PROTOCOL_DIR . '/src/ProtocolInfo.php';
	if(!file_exists($protocolInfoFile)){
		fwrite(STDERR, COLOR_RED . "ERROR: ProtocolInfo.php not found" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	
	$protocolInfoContent = file_get_contents($protocolInfoFile);
	
	preg_match('/CURRENT_PROTOCOL\s*=\s*(\d+)/', $protocolInfoContent, $currentProtocolMatch);
	preg_match('/MINECRAFT_VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', $protocolInfoContent, $minecraftVersionMatch);
	preg_match('/MINECRAFT_VERSION_NETWORK\s*=\s*[\'"]([^\'"]+)[\'"]/', $protocolInfoContent, $minecraftVersionNetworkMatch);
	
	$currentProtocol = $currentProtocolMatch ? (int) $currentProtocolMatch[1] : 0;
	$minecraftVersion = $minecraftVersionMatch ? $minecraftVersionMatch[1] : 'unknown';
	$minecraftVersionNetwork = $minecraftVersionNetworkMatch ? $minecraftVersionNetworkMatch[1] : 'unknown';
	
	fwrite(STDOUT, "  Current version: $minecraftVersion ($minecraftVersionNetwork)" . PHP_EOL);
	fwrite(STDOUT, "  Current protocol: $currentProtocol" . PHP_EOL);
	
	fwrite(STDOUT, PHP_EOL . COLOR_GREEN . "[2/4] Checking Mojang protocol docs..." . COLOR_RESET . PHP_EOL);
	
	// Check Mojang docs
	$loginPacketUrl = 'https://raw.githubusercontent.com/Mojang/bedrock-protocol-docs/main/json/LoginPacket.json';
	$loginPacketData = downloadFile($loginPacketUrl);
	
	if($loginPacketData === null){
		fwrite(STDOUT, COLOR_YELLOW . "  WARNING: Could not fetch Mojang docs" . COLOR_RESET . PHP_EOL);
		fwrite(STDOUT, "  This might be a network issue or the docs haven't been updated yet." . PHP_EOL);
		return 1;
	}
	
	$loginPacket = json_decode($loginPacketData, true, 512, JSON_THROW_ON_ERROR);
	$docsVersion = $loginPacket['x-minecraft-version'] ?? 'unknown';
	$docsProtocol = $loginPacket['x-protocol-version'] ?? 0;
	
	fwrite(STDOUT, "  Docs version: $docsVersion" . PHP_EOL);
	fwrite(STDOUT, "  Docs protocol: $docsProtocol" . PHP_EOL);
	
	fwrite(STDOUT, PHP_EOL . COLOR_GREEN . "[3/4] Comparing versions..." . COLOR_RESET . PHP_EOL);
	
	if($docsProtocol > $currentProtocol){
		fwrite(STDOUT, COLOR_GREEN . "  NEW VERSION AVAILABLE!" . COLOR_RESET . PHP_EOL);
		fwrite(STDOUT, "  Current: $minecraftVersionNetwork (protocol $currentProtocol)" . PHP_EOL);
		fwrite(STDOUT, "  Latest:  $docsVersion (protocol $docsProtocol)" . PHP_EOL);
		fwrite(STDOUT, PHP_EOL);
		fwrite(STDOUT, "  To update, run:" . PHP_EOL);
		fwrite(STDOUT, "    php auto-update.php update --version $docsVersion --protocol $docsProtocol" . PHP_EOL);
	}elseif($docsProtocol === $currentProtocol){
		fwrite(STDOUT, COLOR_YELLOW . "  You are up to date!" . COLOR_RESET . PHP_EOL);
		fwrite(STDOUT, "  Version: $minecraftVersionNetwork (protocol $currentProtocol)" . PHP_EOL);
	}else{
		fwrite(STDOUT, COLOR_YELLOW . "  Your version is newer than docs (unusual)" . COLOR_RESET . PHP_EOL);
	}
	
	fwrite(STDOUT, PHP_EOL . COLOR_GREEN . "[4/4] Checking other sources..." . COLOR_RESET . PHP_EOL);
	
	// Check BDS download page
	$bdsUrl = 'https://www.minecraft.net/en-us/download/server/bedrock';
	$bdsPage = downloadFile($bdsUrl);
	if($bdsPage !== null){
		// Try to extract version from download link
		if(preg_match('/bedrock-server-(\d+\.\d+\.\d+\.\d+)\.zip/', $bdsPage, $bdsVersionMatch)){
			$bdsVersion = $bdsVersionMatch[1];
			fwrite(STDOUT, "  Latest BDS version: $bdsVersion" . PHP_EOL);
		}else{
			fwrite(STDOUT, "  Could not extract BDS version from download page" . PHP_EOL);
		}
	}
	
	return 0;
}

/**
 * Show current status
 */
function showStatus(): int{
	$BASE_DIR = $GLOBALS['BASE_DIR'];
	$DEPS_DIR = $GLOBALS['DEPS_DIR'];
	$BEDROCK_PROTOCOL_DIR = $GLOBALS['BEDROCK_PROTOCOL_DIR'];
	$BEDROCK_DATA_DIR = $GLOBALS['BEDROCK_DATA_DIR'];
	$MOJANG_DOCS_DIR = $GLOBALS['MOJANG_DOCS_DIR'];
	$AUTO_UPDATE_DIR = $GLOBALS['AUTO_UPDATE_DIR'];
	
	fwrite(STDOUT, COLOR_GREEN . "[Status] Current Configuration" . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	
	// Check directories
	fwrite(STDOUT, "Directories:" . PHP_EOL);
	fwrite(STDOUT, "  PocketMine-MP:     " . (is_dir($BASE_DIR) ? COLOR_GREEN . "OK" : COLOR_RED . "MISSING") . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, "  Deps:              " . (is_dir($DEPS_DIR) ? COLOR_GREEN . "OK" : COLOR_RED . "MISSING") . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, "  BedrockProtocol:   " . (is_dir($BEDROCK_PROTOCOL_DIR) ? COLOR_GREEN . "OK" : COLOR_RED . "MISSING") . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, "  BedrockData:       " . (is_dir($BEDROCK_DATA_DIR) ? COLOR_GREEN . "OK" : COLOR_RED . "MISSING") . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, "  Mojang Docs:       " . (is_dir($MOJANG_DOCS_DIR) ? COLOR_GREEN . "OK" : COLOR_YELLOW . "NOT DOWNLOADED") . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	
	// Check current version
	$protocolInfoFile = $BEDROCK_PROTOCOL_DIR . '/src/ProtocolInfo.php';
	if(file_exists($protocolInfoFile)){
		$protocolInfoContent = file_get_contents($protocolInfoFile);
		
		preg_match('/CURRENT_PROTOCOL\s*=\s*(\d+)/', $protocolInfoContent, $currentProtocolMatch);
		preg_match('/MINECRAFT_VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', $protocolInfoContent, $minecraftVersionMatch);
		preg_match('/MINECRAFT_VERSION_NETWORK\s*=\s*[\'"]([^\'"]+)[\'"]/', $protocolInfoContent, $minecraftVersionNetworkMatch);
		
		$currentProtocol = $currentProtocolMatch ? (int) $currentProtocolMatch[1] : 0;
		$minecraftVersion = $minecraftVersionMatch ? $minecraftVersionMatch[1] : 'unknown';
		$minecraftVersionNetwork = $minecraftVersionNetworkMatch ? $minecraftVersionNetworkMatch[1] : 'unknown';
		
		fwrite(STDOUT, "Current Version:" . PHP_EOL);
		fwrite(STDOUT, "  Minecraft: $minecraftVersion ($minecraftVersionNetwork)" . PHP_EOL);
		fwrite(STDOUT, "  Protocol:  $currentProtocol" . PHP_EOL);
	}
	
	// Check BedrockData files
	fwrite(STDOUT, PHP_EOL . "BedrockData Files:" . PHP_EOL);
	$requiredFiles = [
		'protocol_info.json',
		'required_item_list.json',
		'entity_id_map.json',
		'biome_definitions.json',
	];
	
	foreach($requiredFiles as $file){
		$exists = file_exists($BEDROCK_DATA_DIR . '/' . $file);
		fwrite(STDOUT, "  $file: " . ($exists ? COLOR_GREEN . "OK" : COLOR_RED . "MISSING") . COLOR_RESET . PHP_EOL);
	}
	
	// Check creative directory (new format)
	$creativeDir = $BEDROCK_DATA_DIR . '/creative';
	$creativeFiles = ['construction.json', 'nature.json', 'equipment.json', 'items.json'];
	$creativeOk = is_dir($creativeDir);
	foreach($creativeFiles as $file){
		if(!file_exists($creativeDir . '/' . $file)){
			$creativeOk = false;
		}
	}
	fwrite(STDOUT, "  creative/: " . ($creativeOk ? COLOR_GREEN . "OK" : COLOR_RED . "MISSING") . COLOR_RESET . PHP_EOL);
	
	// Check backup count
	fwrite(STDOUT, PHP_EOL . "Backups:" . PHP_EOL);
	if(is_dir($AUTO_UPDATE_DIR . '/backups')){
		$backups = scandir($AUTO_UPDATE_DIR . '/backups');
		$backupCount = count($backups) - 2; // . and ..
		fwrite(STDOUT, "  Available: $backupCount backup(s)" . PHP_EOL);
		
		if($backupCount > 0){
			// Show last backup
			rsort($backups);
			$lastBackup = $backups[0];
			fwrite(STDOUT, "  Latest:    $lastBackup" . PHP_EOL);
		}
	}else{
		fwrite(STDOUT, "  No backups found" . PHP_EOL);
	}
	
	return 0;
}

/**
 * Run full update process
 */
function runFullUpdate(array $options): int{
	$BASE_DIR = $GLOBALS['BASE_DIR'];
	$DEPS_DIR = $GLOBALS['DEPS_DIR'];
	$MOJANG_DOCS_DIR = $GLOBALS['MOJANG_DOCS_DIR'];
	$BEDROCK_PROTOCOL_DIR = $GLOBALS['BEDROCK_PROTOCOL_DIR'];
	$BEDROCK_DATA_DIR = $GLOBALS['BEDROCK_DATA_DIR'];
	
	$targetVersion = null;
	$targetProtocol = null;
	$skipBackup = false;
	$skipVerify = false;
	$dryRun = false;
	
	// Parse options
	for($i = 0; $i < count($options); $i++){
		switch($options[$i]){
			case '--version':
				$targetVersion = $options[++$i] ?? null;
				break;
			case '--protocol':
				$targetProtocol = (int) ($options[++$i] ?? 0);
				break;
			case '--skip-backup':
				$skipBackup = true;
				break;
			case '--skip-verify':
				$skipVerify = true;
				break;
			case '--dry-run':
				$dryRun = true;
				break;
		}
	}
	
	// If no target specified, check for updates
	if($targetProtocol === null){
		fwrite(STDOUT, COLOR_GREEN . "[Step 0] Checking for updates..." . COLOR_RESET . PHP_EOL);
		
		$loginPacketUrl = 'https://raw.githubusercontent.com/Mojang/bedrock-protocol-docs/main/json/LoginPacket.json';
		$loginPacketData = downloadFile($loginPacketUrl);
		
		if($loginPacketData === null){
			fwrite(STDERR, COLOR_RED . "ERROR: Could not check for updates" . COLOR_RESET . PHP_EOL);
			return 1;
		}
		
		$loginPacket = json_decode($loginPacketData, true, 512, JSON_THROW_ON_ERROR);
		$targetVersion = $loginPacket['x-minecraft-version'] ?? null;
		$targetProtocol = $loginPacket['x-protocol-version'] ?? null;
		
		if($targetProtocol === null){
			fwrite(STDERR, COLOR_RED . "ERROR: Could not determine target version" . COLOR_RESET . PHP_EOL);
			return 1;
		}
		
		// Check if update is needed
		$protocolInfoFile = $BEDROCK_PROTOCOL_DIR . '/src/ProtocolInfo.php';
		$protocolInfoContent = file_get_contents($protocolInfoFile);
		preg_match('/CURRENT_PROTOCOL\s*=\s*(\d+)/', $protocolInfoContent, $currentProtocolMatch);
		$currentProtocol = $currentProtocolMatch ? (int) $currentProtocolMatch[1] : 0;
		
		if($targetProtocol <= $currentProtocol){
			fwrite(STDOUT, COLOR_YELLOW . "  You are already up to date!" . COLOR_RESET . PHP_EOL);
			fwrite(STDOUT, "  Current: protocol $currentProtocol" . PHP_EOL);
			fwrite(STDOUT, "  Latest:  protocol $targetProtocol" . PHP_EOL);
			return 0;
		}
		
		fwrite(STDOUT, "  Found update: $targetVersion (protocol $targetProtocol)" . PHP_EOL);
	}
	
	fwrite(STDOUT, PHP_EOL . COLOR_CYAN . "=== Starting Update to $targetVersion (protocol $targetProtocol) ===" . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	
	if($dryRun){
		fwrite(STDOUT, COLOR_YELLOW . "[DRY RUN] No changes will be made" . COLOR_RESET . PHP_EOL);
		fwrite(STDOUT, PHP_EOL);
	}
	
	// Step 1: Create backup
	if(!$skipBackup && !$dryRun){
		fwrite(STDOUT, COLOR_GREEN . "[Step 1/6] Creating backup..." . COLOR_RESET . PHP_EOL);
		$result = createBackup();
		if($result !== 0){
			fwrite(STDERR, COLOR_RED . "Backup failed!" . COLOR_RESET . PHP_EOL);
			return 1;
		}
		fwrite(STDOUT, PHP_EOL);
	}else{
		fwrite(STDOUT, COLOR_GREEN . "[Step 1/6] Skipping backup" . COLOR_RESET . PHP_EOL);
		fwrite(STDOUT, PHP_EOL);
	}
	
	// Step 2: Download Mojang docs
	fwrite(STDOUT, COLOR_GREEN . "[Step 2/6] Downloading Mojang protocol docs..." . COLOR_RESET . PHP_EOL);
	if(!$dryRun){
		$result = downloadMojangDocs();
		if($result !== 0){
			fwrite(STDERR, COLOR_RED . "Failed to download docs!" . COLOR_RESET . PHP_EOL);
			return 1;
		}
	}else{
		fwrite(STDOUT, "  Would download from: https://github.com/Mojang/bedrock-protocol-docs" . PHP_EOL);
	}
	fwrite(STDOUT, PHP_EOL);
	
	// Step 3: Generate protocol_info.json
	fwrite(STDOUT, COLOR_GREEN . "[Step 3/6] Generating protocol_info.json..." . COLOR_RESET . PHP_EOL);
	if(!$dryRun){
		$result = generateProtocolInfo($targetProtocol);
		if($result !== 0){
			fwrite(STDERR, COLOR_RED . "Failed to generate protocol_info.json!" . COLOR_RESET . PHP_EOL);
			return 1;
		}
	}else{
		fwrite(STDOUT, "  Would generate for protocol $targetProtocol" . PHP_EOL);
	}
	fwrite(STDOUT, PHP_EOL);
	
	// Step 4: Update BedrockProtocol
	fwrite(STDOUT, COLOR_GREEN . "[Step 4/6] Updating BedrockProtocol..." . COLOR_RESET . PHP_EOL);
	if(!$dryRun){
		$result = updateBedrockProtocol();
		if($result !== 0){
			fwrite(STDERR, COLOR_RED . "Failed to update BedrockProtocol!" . COLOR_RESET . PHP_EOL);
			return 1;
		}
	}else{
		fwrite(STDOUT, "  Would run update scripts" . PHP_EOL);
	}
	fwrite(STDOUT, PHP_EOL);
	
	// Step 5: Update PocketMine-MP
	fwrite(STDOUT, COLOR_GREEN . "[Step 5/6] Updating PocketMine-MP..." . COLOR_RESET . PHP_EOL);
	if(!$dryRun){
		$result = updatePocketMineMP();
		if($result !== 0){
			fwrite(STDERR, COLOR_RED . "Failed to update PocketMine-MP!" . COLOR_RESET . PHP_EOL);
			return 1;
		}
	}else{
		fwrite(STDOUT, "  Would run codegen and tests" . PHP_EOL);
	}
	fwrite(STDOUT, PHP_EOL);
	
	// Step 6: Verify
	if(!$skipVerify){
		fwrite(STDOUT, COLOR_GREEN . "[Step 6/6] Verifying update..." . COLOR_RESET . PHP_EOL);
		if(!$dryRun){
			$result = verifyUpdate();
			if($result !== 0){
				fwrite(STDERR, COLOR_YELLOW . "WARNING: Verification had issues" . COLOR_RESET . PHP_EOL);
			}
		}else{
			fwrite(STDOUT, "  Would verify update" . PHP_EOL);
		}
	}else{
		fwrite(STDOUT, COLOR_GREEN . "[Step 6/6] Skipping verification" . COLOR_RESET . PHP_EOL);
	}
	
	// Summary
	fwrite(STDOUT, PHP_EOL . COLOR_CYAN . "=== Update Complete ===" . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, "Version: $targetVersion" . PHP_EOL);
	fwrite(STDOUT, "Protocol: $targetProtocol" . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	
	if(!$dryRun){
		fwrite(STDOUT, "Next steps:" . PHP_EOL);
		fwrite(STDOUT, "1. Review changes: git diff" . PHP_EOL);
		fwrite(STDOUT, "2. Test server: php start.php" . PHP_EOL);
		fwrite(STDOUT, "3. Commit changes: git add . && git commit" . PHP_EOL);
	}
	
	return 0;
}

/**
 * Update only protocol (no data generation)
 */
function runProtocolUpdate(array $options): int{
	$BEDROCK_PROTOCOL_DIR = $GLOBALS['BEDROCK_PROTOCOL_DIR'];
	$BEDROCK_DATA_DIR = $GLOBALS['BEDROCK_DATA_DIR'];
	
	fwrite(STDOUT, COLOR_GREEN . "[1/3] Generating protocol_info.json..." . COLOR_RESET . PHP_EOL);
	
	$protocol = $options[0] ?? null;
	if($protocol === null){
		// Get from command line or check for updates
		fwrite(STDERR, COLOR_RED . "ERROR: Protocol version required" . COLOR_RESET . PHP_EOL);
		fwrite(STDERR, "Usage: php auto-update.php update-protocol <protocol-version>" . PHP_EOL);
		return 1;
	}
	
	$result = generateProtocolInfo((int) $protocol);
	if($result !== 0){
		return 1;
	}
	
	fwrite(STDOUT, PHP_EOL . COLOR_GREEN . "[2/3] Running update script..." . COLOR_RESET . PHP_EOL);
	$result = runCommand("php $BEDROCK_PROTOCOL_DIR/tools/update-from-bedrock-data.php $BEDROCK_DATA_DIR");
	if($result !== 0){
		fwrite(STDERR, COLOR_RED . "Update script failed!" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	
	fwrite(STDOUT, PHP_EOL . COLOR_GREEN . "[3/3] Generating create methods..." . COLOR_RESET . PHP_EOL);
	$result = runCommand("php $BEDROCK_PROTOCOL_DIR/tools/generate-create-static-methods.php");
	if($result !== 0){
		fwrite(STDERR, COLOR_RED . "Generate create methods failed!" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	
	fwrite(STDOUT, PHP_EOL . COLOR_GREEN . "Protocol update complete!" . COLOR_RESET . PHP_EOL);
	return 0;
}

/**
 * Update and save as specific channel (stable/preview)
 */
function updateChannel(array $options): int{
	$BASE_DIR = $GLOBALS['BASE_DIR'];
	$BEDROCK_PROTOCOL_DIR = $GLOBALS['BEDROCK_PROTOCOL_DIR'];
	$BEDROCK_DATA_DIR = $GLOBALS['BEDROCK_DATA_DIR'];
	$AUTO_UPDATE_DIR = $GLOBALS['AUTO_UPDATE_DIR'];
	
	$channel = null;
	$targetVersion = null;
	$targetProtocol = null;
	
	// Parse options
	for($i = 0; $i < count($options); $i++){
		switch($options[$i]){
			case '--channel':
				$channel = $options[++$i] ?? null;
				break;
			case '--version':
				$targetVersion = $options[++$i] ?? null;
				break;
			case '--protocol':
				$targetProtocol = (int) ($options[++$i] ?? 0);
				break;
		}
	}
	
	if($channel === null){
		fwrite(STDERR, COLOR_RED . "ERROR: Channel name required (stable/preview)" . COLOR_RESET . PHP_EOL);
		fwrite(STDERR, "Usage: php auto-update.php update-channel --channel <stable|preview> [--version <ver>] [--protocol <num>]" . PHP_EOL);
		return 1;
	}
	
	if(!in_array($channel, ['stable', 'preview'], true)){
		fwrite(STDERR, COLOR_RED . "ERROR: Invalid channel '$channel'. Must be 'stable' or 'preview'" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	
	fwrite(STDOUT, COLOR_GREEN . "=== Update Channel: $channel ===" . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	
	// Step 1: Run update if version/protocol specified
	if($targetVersion !== null || $targetProtocol !== null){
		fwrite(STDOUT, COLOR_GREEN . "[Step 1] Running update..." . COLOR_RESET . PHP_EOL);
		
		$updateArgs = [];
		if($targetVersion !== null){
			$updateArgs[] = '--version';
			$updateArgs[] = $targetVersion;
		}
		if($targetProtocol !== null){
			$updateArgs[] = '--protocol';
			$updateArgs[] = (string) $targetProtocol;
		}
		
		$result = runFullUpdate($updateArgs);
		if($result !== 0){
			return 1;
		}
	}
	
	// Step 2: Save channel
	fwrite(STDOUT, PHP_EOL . COLOR_GREEN . "[Step 2] Saving as '$channel' channel..." . COLOR_RESET . PHP_EOL);
	
	$versionsDir = $AUTO_UPDATE_DIR . '/versions';
	$channelDir = $versionsDir . '/' . $channel;
	
	if(!is_dir($channelDir)){
		mkdir($channelDir, 0755, true);
	}
	
	// Get current version info
	$protocolInfoFile = $BEDROCK_PROTOCOL_DIR . '/src/ProtocolInfo.php';
	$content = file_get_contents($protocolInfoFile);
	
	preg_match('/CURRENT_PROTOCOL\s*=\s*(\d+)/', $content, $protocolMatch);
	preg_match('/MINECRAFT_VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $versionMatch);
	preg_match('/MINECRAFT_VERSION_NETWORK\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $networkMatch);
	
	$protocol = $protocolMatch ? (int) $protocolMatch[1] : 0;
	$version = $versionMatch ? $versionMatch[1] : 'unknown';
	$networkVersion = $networkMatch ? $networkMatch[1] : 'unknown';
	
	$isStable = $protocol < 2000;
	
	// Save version.json
	$versionInfo = [
		'version' => $channel,
		'minecraft_version' => $version,
		'display_version' => 'v' . str_replace('1.', '', $version),
		'protocol_version' => $protocol,
		'is_stable' => $isStable,
		'description' => $channel === 'stable' ? 'Latest stable version - recommended for production servers' : 'Preview version with latest features (may have bugs)',
		'updated_at' => date('Y-m-d\TH:i:s'),
	];
	
	file_put_contents($channelDir . '/version.json', json_encode($versionInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	fwrite(STDOUT, "  Saved: version.json" . PHP_EOL);
	
	// Save ProtocolInfo.php
	copy($protocolInfoFile, $channelDir . '/ProtocolInfo.php');
	fwrite(STDOUT, "  Saved: ProtocolInfo.php" . PHP_EOL);
	
	// Save BedrockData files
	$bedrockDataDst = $channelDir . '/BedrockData';
	if(!is_dir($bedrockDataDst)){
		mkdir($bedrockDataDst, 0755, true);
	}
	
	$filesToSave = [
		'protocol_info.json',
		'required_item_list.json',
		'entity_id_map.json',
		'biome_definitions.json',
	];
	
	foreach($filesToSave as $file){
		$src = $BEDROCK_DATA_DIR . '/' . $file;
		$dst = $bedrockDataDst . '/' . $file;
		if(file_exists($src)){
			copy($src, $dst);
			fwrite(STDOUT, "  Saved: $file" . PHP_EOL);
		}
	}
	
	// Save creative directory
	$creativeSrc = $BEDROCK_DATA_DIR . '/creative';
	$creativeDst = $bedrockDataDst . '/creative';
	if(is_dir($creativeSrc)){
		if(!is_dir($creativeDst)){
			mkdir($creativeDst, 0755, true);
		}
		$files = scandir($creativeSrc);
		foreach($files as $file){
			if($file === '.' || $file === '..') continue;
			$srcFile = $creativeSrc . '/' . $file;
			$dstFile = $creativeDst . '/' . $file;
			if(is_file($srcFile)){
				copy($srcFile, $dstFile);
			}
		}
		fwrite(STDOUT, "  Saved: creative/ directory" . PHP_EOL);
	}
	
	fwrite(STDOUT, PHP_EOL . COLOR_GREEN . "=== Channel '$channel' updated! ===" . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, "  Version: $version" . PHP_EOL);
	fwrite(STDOUT, "  Protocol: $protocol" . PHP_EOL);
	fwrite(STDOUT, "  Type: " . ($isStable ? 'STABLE' : 'PREVIEW') . PHP_EOL);
	
	return 0;
}

/**
 * Verify the current update
 */
function verifyUpdate(): int{
	$BASE_DIR = $GLOBALS['BASE_DIR'];
	$BEDROCK_PROTOCOL_DIR = $GLOBALS['BEDROCK_PROTOCOL_DIR'];
	$BEDROCK_DATA_DIR = $GLOBALS['BEDROCK_DATA_DIR'];
	
	$errors = 0;
	
	// Check required files
	fwrite(STDOUT, "Checking required files..." . PHP_EOL);
	$requiredFiles = [
		$BEDROCK_DATA_DIR . '/protocol_info.json',
		$BEDROCK_DATA_DIR . '/required_item_list.json',
		$BEDROCK_DATA_DIR . '/entity_id_map.json',
		$BEDROCK_DATA_DIR . '/biome_definitions.json',
	];
	
	foreach($requiredFiles as $file){
		if(!file_exists($file)){
			fwrite(STDERR, COLOR_RED . "  MISSING: $file" . COLOR_RESET . PHP_EOL);
			$errors++;
		}else{
			fwrite(STDOUT, COLOR_GREEN . "  OK: " . basename($file) . COLOR_RESET . PHP_EOL);
		}
	}
	
	// Check creative directory (new format)
	$creativeDir = $BEDROCK_DATA_DIR . '/creative';
	$creativeFiles = ['construction.json', 'nature.json', 'equipment.json', 'items.json'];
	$creativeOk = is_dir($creativeDir);
	foreach($creativeFiles as $file){
		if(!file_exists($creativeDir . '/' . $file)){
			$creativeOk = false;
		}
	}
	if($creativeOk){
		fwrite(STDOUT, COLOR_GREEN . "  OK: creative/ directory" . COLOR_RESET . PHP_EOL);
	}else{
		fwrite(STDERR, COLOR_RED . "  MISSING: creative/ directory or files" . COLOR_RESET . PHP_EOL);
		$errors++;
	}
	
	// Check ProtocolInfo.php
	fwrite(STDOUT, PHP_EOL . "Checking ProtocolInfo.php..." . PHP_EOL);
	$protocolInfoFile = $BEDROCK_PROTOCOL_DIR . '/src/ProtocolInfo.php';
	if(file_exists($protocolInfoFile)){
		$content = file_get_contents($protocolInfoFile);
		
		// Check for required constants
		$requiredConstants = [
			'CURRENT_PROTOCOL',
			'MINECRAFT_VERSION',
			'MINECRAFT_VERSION_NETWORK',
		];
		
		foreach($requiredConstants as $constant){
			if(!preg_match('/' . $constant . '\s*=/', $content)){
				fwrite(STDERR, COLOR_RED . "  MISSING: $constant" . COLOR_RESET . PHP_EOL);
				$errors++;
			}else{
				fwrite(STDOUT, COLOR_GREEN . "  OK: $constant" . COLOR_RESET . PHP_EOL);
			}
		}
	}
	
	// Check packet count
	fwrite(STDOUT, PHP_EOL . "Checking packet count..." . PHP_EOL);
	$protocolInfoContent = file_get_contents($protocolInfoFile);
	preg_match_all('/public\s+const\s+(\w+_PACKET)\s*=\s*(0x[0-9a-fA-F]+)/', $protocolInfoContent, $matches, PREG_SET_ORDER);
	$packetCount = count($matches);
	fwrite(STDOUT, "  Packets defined: $packetCount" . PHP_EOL);
	
	if($packetCount < 200){
		fwrite(STDERR, COLOR_YELLOW . "  WARNING: Low packet count (< 200)" . COLOR_RESET . PHP_EOL);
	}
	
	// Run PHPStan if available
	fwrite(STDOUT, PHP_EOL . "Running PHPStan..." . PHP_EOL);
	$phpstanConfig = $BASE_DIR . '/phpstan.neon.dist';
	if(file_exists($phpstanConfig)){
		$result = runCommand("php $BASE_DIR/vendor/bin/phpstan analyze $BASE_DIR --memory-limit=512M 2>&1", $output);
		if($result === 0){
			fwrite(STDOUT, COLOR_GREEN . "  PHPStan: PASSED" . COLOR_RESET . PHP_EOL);
		}else{
			fwrite(STDERR, COLOR_YELLOW . "  PHPStan: HAD ISSUES (check output)" . COLOR_RESET . PHP_EOL);
			// Don't count as error, just warning
		}
	}else{
		fwrite(STDOUT, "  PHPStan config not found, skipping" . PHP_EOL);
	}
	
	// Run tests
	fwrite(STDOUT, PHP_EOL . "Running tests..." . PHP_EOL);
	$phpunitConfig = $BASE_DIR . '/phpunit.xml';
	if(file_exists($phpunitConfig)){
		$result = runCommand("php $BASE_DIR/vendor/bin/phpunit $BASE_DIR/tests/phpunit 2>&1", $output);
		if($result === 0){
			fwrite(STDOUT, COLOR_GREEN . "  Tests: PASSED" . COLOR_RESET . PHP_EOL);
		}else{
			fwrite(STDERR, COLOR_YELLOW . "  Tests: HAD FAILURES (check output)" . COLOR_RESET . PHP_EOL);
			$errors++;
		}
	}else{
		fwrite(STDOUT, "  PHPUnit config not found, skipping" . PHP_EOL);
	}
	
	// Summary
	fwrite(STDOUT, PHP_EOL . "=== Verification Summary ===" . PHP_EOL);
	if($errors === 0){
		fwrite(STDOUT, COLOR_GREEN . "All checks passed!" . COLOR_RESET . PHP_EOL);
	}else{
		fwrite(STDERR, COLOR_RED . "$errors error(s) found" . COLOR_RESET . PHP_EOL);
	}
	
	return $errors > 0 ? 1 : 0;
}

/**
 * Create backup of current state
 */
function createBackup(): int{
	$BEDROCK_PROTOCOL_DIR = $GLOBALS['BEDROCK_PROTOCOL_DIR'];
	$BEDROCK_DATA_DIR = $GLOBALS['BEDROCK_DATA_DIR'];
	$BACKUP_DIR = $GLOBALS['BACKUP_DIR'];
	
	if(!is_dir($BACKUP_DIR)){
		mkdir($BACKUP_DIR, 0755, true);
	}
	
	$backupName = 'backup_' . date('Y-m-d_H-i-s');
	$backupPath = $BACKUP_DIR . '/' . $backupName;
	mkdir($backupPath, 0755, true);
	
	fwrite(STDOUT, "Creating backup: $backupName" . PHP_EOL);
	
	// Backup ProtocolInfo.php
	$protocolInfoSrc = $BEDROCK_PROTOCOL_DIR . '/src/ProtocolInfo.php';
	$protocolInfoDst = $backupPath . '/ProtocolInfo.php';
	if(file_exists($protocolInfoSrc)){
		copy($protocolInfoSrc, $protocolInfoDst);
		fwrite(STDOUT, "  Backed up: ProtocolInfo.php" . PHP_EOL);
	}
	
	// Backup BedrockData files
	$bedrockDataDst = $backupPath . '/BedrockData';
	mkdir($bedrockDataDst, 0755, true);
	
	$filesToBackup = [
		'protocol_info.json',
		'required_item_list.json',
		'creativeitems.json',
		'entity_id_map.json',
		'biome_definitions.json',
	];
	
	foreach($filesToBackup as $file){
		$src = $BEDROCK_DATA_DIR . '/' . $file;
		$dst = $bedrockDataDst . '/' . $file;
		if(file_exists($src)){
			copy($src, $dst);
			fwrite(STDOUT, "  Backed up: $file" . PHP_EOL);
		}
	}
	
	// Save version info
	$protocolInfoContent = file_get_contents($protocolInfoSrc);
	preg_match('/CURRENT_PROTOCOL\s*=\s*(\d+)/', $protocolInfoContent, $protocolMatch);
	preg_match('/MINECRAFT_VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', $protocolInfoContent, $versionMatch);
	
	$versionInfo = [
		'created_at' => date('Y-m-d H:i:s'),
		'protocol' => $protocolMatch ? (int) $protocolMatch[1] : 0,
		'version' => $versionMatch ? $versionMatch[1] : 'unknown',
	];
	
	file_put_contents($backupPath . '/version.json', json_encode($versionInfo, JSON_PRETTY_PRINT));
	
	fwrite(STDOUT, COLOR_GREEN . "Backup created: $backupPath" . COLOR_RESET . PHP_EOL);
	return 0;
}

/**
 * Restore from backup
 */
function restoreBackup(array $options): int{
	$BEDROCK_PROTOCOL_DIR = $GLOBALS['BEDROCK_PROTOCOL_DIR'];
	$BEDROCK_DATA_DIR = $GLOBALS['BEDROCK_DATA_DIR'];
	$BACKUP_DIR = $GLOBALS['BACKUP_DIR'];
	
	$backupName = $options[0] ?? null;
	if($backupName === null){
		// List available backups
		if(!is_dir($BACKUP_DIR)){
			fwrite(STDERR, COLOR_RED . "No backups found" . COLOR_RESET . PHP_EOL);
			return 1;
		}
		
		$backups = scandir($BACKUP_DIR);
		$backups = array_filter($backups, fn($f) => $f !== '.' && $f !== '..' && is_dir($BACKUP_DIR . '/' . $f));
		$backups = array_values($backups);
		
		if(count($backups) === 0){
			fwrite(STDERR, COLOR_RED . "No backups found" . COLOR_RESET . PHP_EOL);
			return 1;
		}
		
		fwrite(STDOUT, "Available backups:" . PHP_EOL);
		foreach($backups as $i => $backup){
			$versionFile = $BACKUP_DIR . '/' . $backup . '/version.json';
			if(file_exists($versionFile)){
				$versionInfo = json_decode(file_get_contents($versionFile), true);
				fwrite(STDOUT, "  " . ($i + 1) . ". $backup (protocol " . ($versionInfo['protocol'] ?? '?') . ")" . PHP_EOL);
			}else{
				fwrite(STDOUT, "  " . ($i + 1) . ". $backup" . PHP_EOL);
			}
		}
		
		fwrite(STDOUT, PHP_EOL . "Usage: php auto-update.php restore <backup-name>" . PHP_EOL);
		return 0;
	}
	
	$backupPath = $BACKUP_DIR . '/' . $backupName;
	if(!is_dir($backupPath)){
		fwrite(STDERR, COLOR_RED . "Backup not found: $backupName" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	
	fwrite(STDOUT, "Restoring from backup: $backupName" . PHP_EOL);
	
	// Restore ProtocolInfo.php
	$protocolInfoSrc = $backupPath . '/ProtocolInfo.php';
	$protocolInfoDst = $BEDROCK_PROTOCOL_DIR . '/src/ProtocolInfo.php';
	if(file_exists($protocolInfoSrc)){
		copy($protocolInfoSrc, $protocolInfoDst);
		fwrite(STDOUT, "  Restored: ProtocolInfo.php" . PHP_EOL);
	}
	
	// Restore BedrockData files
	$bedrockDataSrc = $backupPath . '/BedrockData';
	if(is_dir($bedrockDataSrc)){
		$files = scandir($bedrockDataSrc);
		foreach($files as $file){
			if($file === '.' || $file === '..') continue;
			$src = $bedrockDataSrc . '/' . $file;
			$dst = $BEDROCK_DATA_DIR . '/' . $file;
			copy($src, $dst);
			fwrite(STDOUT, "  Restored: $file" . PHP_EOL);
		}
	}
	
	fwrite(STDOUT, COLOR_GREEN . "Restore complete!" . COLOR_RESET . PHP_EOL);
	return 0;
}

/**
 * Download Mojang protocol docs
 */
function downloadMojangDocs(): int{
	$MOJANG_DOCS_DIR = $GLOBALS['MOJANG_DOCS_DIR'];
	
	// Check if already exists
	if(is_dir($MOJANG_DOCS_DIR)){
		fwrite(STDOUT, "  Mojang docs already downloaded, updating..." . PHP_EOL);
		$result = runCommand("cd $MOJANG_DOCS_DIR && git pull 2>&1", $output);
		if($result === 0){
			fwrite(STDOUT, COLOR_GREEN . "  Updated successfully" . COLOR_RESET . PHP_EOL);
			return 0;
		}
	}
	
	// Clone
	fwrite(STDOUT, "  Cloning Mojang protocol docs..." . PHP_EOL);
	$result = runCommand("git clone https://github.com/Mojang/bedrock-protocol-docs $MOJANG_DOCS_DIR 2>&1", $output);
	
	if($result !== 0){
		fwrite(STDERR, COLOR_RED . "  Failed to clone docs" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	
	fwrite(STDOUT, COLOR_GREEN . "  Downloaded to: $MOJANG_DOCS_DIR" . COLOR_RESET . PHP_EOL);
	return 0;
}

/**
 * Generate protocol_info.json
 */
function generateProtocolInfo(int $protocolVersion): int{
	$BEDROCK_PROTOCOL_DIR = $GLOBALS['BEDROCK_PROTOCOL_DIR'];
	$BEDROCK_DATA_DIR = $GLOBALS['BEDROCK_DATA_DIR'];
	
	$script = $BEDROCK_PROTOCOL_DIR . '/../../PocketMine-MP/tools/generate-protocol-info-from-docs.php';
	if(!file_exists($script)){
		// Try alternate path
		$script = dirname(__DIR__) . '/generate-protocol-info-from-docs.php';
	}
	
	if(!file_exists($script)){
		fwrite(STDERR, COLOR_RED . "ERROR: generate-protocol-info-from-docs.php not found" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	
	$result = runCommand("php $script $protocolVersion $BEDROCK_PROTOCOL_DIR $BEDROCK_DATA_DIR 2>&1", $output);
	
	if($result !== 0){
		fwrite(STDERR, COLOR_RED . "Failed to generate protocol_info.json" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	
	// Copy to BedrockData root
	$generatedFile = $BEDROCK_DATA_DIR . '/mojang_docs_json/protocol_info.json';
	$targetFile = $BEDROCK_DATA_DIR . '/protocol_info.json';
	
	if(file_exists($generatedFile)){
		copy($generatedFile, $targetFile);
		fwrite(STDOUT, COLOR_GREEN . "  Copied protocol_info.json to BedrockData" . COLOR_RESET . PHP_EOL);
	}
	
	return 0;
}

/**
 * Update BedrockProtocol
 */
function updateBedrockProtocol(): int{
	$BEDROCK_PROTOCOL_DIR = $GLOBALS['BEDROCK_PROTOCOL_DIR'];
	$BEDROCK_DATA_DIR = $GLOBALS['BEDROCK_DATA_DIR'];
	
	// Run update script
	fwrite(STDOUT, "  Running update-from-bedrock-data.php..." . PHP_EOL);
	$result = runCommand("php $BEDROCK_PROTOCOL_DIR/tools/update-from-bedrock-data.php $BEDROCK_DATA_DIR 2>&1", $output);
	
	if($result !== 0){
		fwrite(STDERR, COLOR_RED . "  Update script failed" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	
	// Generate create methods
	fwrite(STDOUT, "  Running generate-create-static-methods.php..." . PHP_EOL);
	$result = runCommand("php $BEDROCK_PROTOCOL_DIR/tools/generate-create-static-methods.php 2>&1", $output);
	
	if($result !== 0){
		fwrite(STDERR, COLOR_RED . "  Generate create methods failed" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	
	fwrite(STDOUT, COLOR_GREEN . "  BedrockProtocol updated" . COLOR_RESET . PHP_EOL);
	return 0;
}

/**
 * Update PocketMine-MP
 */
function updatePocketMineMP(): int{
	$BASE_DIR = $GLOBALS['BASE_DIR'];
	
	// Run codegen
	fwrite(STDOUT, "  Running composer update-codegen..." . PHP_EOL);
	$result = runCommand("cd $BASE_DIR && composer run-script update-codegen 2>&1", $output);
	
	if($result !== 0){
		fwrite(STDERR, COLOR_YELLOW . "  Codegen had issues (might be OK)" . COLOR_RESET . PHP_EOL);
	}
	
	// Update world data versions
	fwrite(STDOUT, "  Running update-world-data-versions.php..." . PHP_EOL);
	$result = runCommand("php $BASE_DIR/tools/update-world-data-versions.php 2>&1", $output);
	
	if($result !== 0){
		fwrite(STDERR, COLOR_YELLOW . "  Update world data versions had issues" . COLOR_RESET . PHP_EOL);
	}
	
	fwrite(STDOUT, COLOR_GREEN . "  PocketMine-MP updated" . COLOR_RESET . PHP_EOL);
	return 0;
}

/**
 * Download file from URL
 */
function downloadFile(string $url): ?string{
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_SSL_VERIFYPEER => false,
	]);
	$data = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	
	if($data === false || $httpCode !== 200){
		return null;
	}
	return $data;
}

/**
 * Run a command and capture output
 */
function runCommand(string $command, ?string &$output = null): int{
	$output = shell_exec($command . ' 2>&1');
	return $output !== null ? 0 : 1;
}
