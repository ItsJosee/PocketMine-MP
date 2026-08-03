<?php

declare(strict_types=1);

/**
 * Version Manager for PocketMine-MP Protocol
 * 
 * Manages multiple protocol versions (stable + preview)
 * Allows switching between versions easily
 *
 * Usage:
 *   php version-manager.php <command> [options]
 *
 * Commands:
 *   list              List all available versions
 *   current           Show current active version
 *   switch <version>  Switch to a version (stable/preview)
 *   save <version>    Save current state as a version
 *   diff <v1> <v2>    Compare two versions
 */

namespace pocketmine\tools\version_manager;

use function array_keys;
use function array_merge;
use function count;
use function date;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function readln;
use function scandir;
use function sort;
use function str_contains;
use function str_replace;
use function trim;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;
use const STDERR;
use const STDOUT;

// Configuration
$BASE_DIR = dirname(__DIR__, 2); // PocketMine-MP root
$DEPS_DIR = dirname($BASE_DIR) . '/deps';
$BEDROCK_PROTOCOL_DIR = $DEPS_DIR . '/BedrockProtocol';
$BEDROCK_DATA_DIR = $DEPS_DIR . '/BedrockData';
$VERSIONS_DIR = __DIR__ . '/versions';

// Colors
define('COLOR_RESET', "\033[0m");
define('COLOR_GREEN', "\033[32m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_RED', "\033[31m");
define('COLOR_CYAN', "\033[36m");
define('COLOR_MAGENTA', "\033[35m");

// Parse arguments
$command = $argv[1] ?? 'help';
$options = array_slice($argv, 2);

// Main execution
fwrite(STDOUT, COLOR_CYAN . "=== PocketMine-MP Version Manager ===" . COLOR_RESET . PHP_EOL);
fwrite(STDOUT, PHP_EOL);

switch($command){
	case 'list':
		exit(listVersions());
	case 'current':
		exit(showCurrentVersion());
	case 'switch':
		exit(switchVersion($options));
	case 'save':
		exit(saveVersion($options));
	case 'diff':
		exit(diffVersions($options));
	case 'help':
	default:
		showHelp();
		exit(0);
}

/**
 * Show help
 */
function showHelp(): void{
	fwrite(STDOUT, "Usage: php version-manager.php <command> [options]" . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	fwrite(STDOUT, "Commands:" . PHP_EOL);
	fwrite(STDOUT, "  list              List all available versions" . PHP_EOL);
	fwrite(STDOUT, "  current           Show current active version" . PHP_EOL);
	fwrite(STDOUT, "  switch <version>  Switch to a version (stable/preview)" . PHP_EOL);
	fwrite(STDOUT, "  save <version>    Save current state as a version" . PHP_EOL);
	fwrite(STDOUT, "  diff <v1> <v2>    Compare two versions" . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	fwrite(STDOUT, "Examples:" . PHP_EOL);
	fwrite(STDOUT, "  php version-manager.php list" . PHP_EOL);
	fwrite(STDOUT, "  php version-manager.php current" . PHP_EOL);
	fwrite(STDOUT, "  php version-manager.php switch stable" . PHP_EOL);
	fwrite(STDOUT, "  php version-manager.php switch preview" . PHP_EOL);
	fwrite(STDOUT, "  php version-manager.php save stable" . PHP_EOL);
	fwrite(STDOUT, "  php version-manager.php diff stable preview" . PHP_EOL);
}

/**
 * List all available versions
 */
function listVersions(): int{
	global $VERSIONS_DIR;
	
	fwrite(STDOUT, COLOR_GREEN . "Available Versions:" . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	
	if(!is_dir($VERSIONS_DIR)){
		mkdir($VERSIONS_DIR, 0755, true);
	}
	
	$versions = scandir($VERSIONS_DIR);
	$versions = array_filter($versions, fn($v) => $v !== '.' && $v !== '..' && is_dir($VERSIONS_DIR . '/' . $v));
	$versions = array_values($versions);
	
	if(count($versions) === 0){
		fwrite(STDOUT, "  No versions saved yet." . PHP_EOL);
		fwrite(STDOUT, "  Use 'php version-manager.php save <name>' to save current state." . PHP_EOL);
		return 0;
	}
	
	// Get current version
	$currentVersion = getCurrentVersionInfo();
	
	foreach($versions as $version){
		$versionFile = $VERSIONS_DIR . '/' . $version . '/version.json';
		if(file_exists($versionFile)){
			$versionInfo = json_decode(file_get_contents($versionFile), true, 512, JSON_THROW_ON_ERROR);
			
			$isCurrent = ($currentVersion['protocol_version'] ?? 0) === ($versionInfo['protocol_version'] ?? 0);
			$marker = $isCurrent ? COLOR_GREEN . ' [CURRENT]' : '';
			
			$stable = ($versionInfo['is_stable'] ?? false) ? COLOR_GREEN . 'STABLE' : COLOR_YELLOW . 'PREVIEW';
			
			fwrite(STDOUT, "  " . COLOR_CYAN . $version . COLOR_RESET . " - " . $stable . COLOR_RESET . PHP_EOL);
			fwrite(STDOUT, "    Version: " . ($versionInfo['minecraft_version'] ?? '?') . " (" . ($versionInfo['display_version'] ?? '?') . ")" . $marker . COLOR_RESET . PHP_EOL);
			fwrite(STDOUT, "    Protocol: " . ($versionInfo['protocol_version'] ?? '?') . PHP_EOL);
			fwrite(STDOUT, "    " . ($versionInfo['description'] ?? '') . PHP_EOL);
			fwrite(STDOUT, PHP_EOL);
		}
	}
	
	return 0;
}

/**
 * Show current version
 */
function showCurrentVersion(): int{
	$versionInfo = getCurrentVersionInfo();
	
	fwrite(STDOUT, COLOR_GREEN . "Current Active Version:" . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	
	fwrite(STDOUT, "  Minecraft: " . ($versionInfo['minecraft_version'] ?? '?') . " (" . ($versionInfo['display_version'] ?? '?') . ")" . PHP_EOL);
	fwrite(STDOUT, "  Protocol:  " . ($versionInfo['protocol_version'] ?? '?') . PHP_EOL);
	fwrite(STDOUT, "  Network:   " . ($versionInfo['minecraft_version_network'] ?? '?') . PHP_EOL);
	
	// Determine if stable or preview
	$protocol = $versionInfo['protocol_version'] ?? 0;
	if($protocol >= 2000){
		fwrite(STDOUT, "  Type:      " . COLOR_YELLOW . "PREVIEW" . COLOR_RESET . PHP_EOL);
	}else{
		fwrite(STDOUT, "  Type:      " . COLOR_GREEN . "STABLE" . COLOR_RESET . PHP_EOL);
	}
	
	return 0;
}

/**
 * Switch to a version
 */
function switchVersion(array $options): int{
	global $VERSIONS_DIR, $BEDROCK_PROTOCOL_DIR, $BEDROCK_DATA_DIR;
	
	$targetVersion = $options[0] ?? null;
	if($targetVersion === null){
		fwrite(STDERR, COLOR_RED . "ERROR: Version name required" . COLOR_RESET . PHP_EOL);
		fwrite(STDERR, "Usage: php version-manager.php switch <version>" . PHP_EOL);
		fwrite(STDERR, "Available versions: stable, preview" . PHP_EOL);
		return 1;
	}
	
	$versionDir = $VERSIONS_DIR . '/' . $targetVersion;
	if(!is_dir($versionDir)){
		fwrite(STDERR, COLOR_RED . "ERROR: Version '$targetVersion' not found" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	
	$versionFile = $versionDir . '/version.json';
	if(!file_exists($versionFile)){
		fwrite(STDERR, COLOR_RED . "ERROR: version.json not found for '$targetVersion'" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	
	$versionInfo = json_decode(file_get_contents($versionFile), true, 512, JSON_THROW_ON_ERROR);
	
	fwrite(STDOUT, COLOR_GREEN . "Switching to $targetVersion..." . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, "  Version: " . ($versionInfo['minecraft_version'] ?? '?') . PHP_EOL);
	fwrite(STDOUT, "  Protocol: " . ($versionInfo['protocol_version'] ?? '?') . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	
	// Backup current state
	fwrite(STDOUT, "  Backing up current state..." . PHP_EOL);
	$currentVersion = getCurrentVersionInfo();
	$currentProtocol = $currentVersion['protocol_version'] ?? 0;
	
	// Save current to a temporary backup
	$backupDir = $VERSIONS_DIR . '/_backup_before_switch';
	if(!is_dir($backupDir)){
		mkdir($backupDir, 0755, true);
	}
	
	$protocolInfoSrc = $BEDROCK_PROTOCOL_DIR . '/src/ProtocolInfo.php';
	$protocolInfoDst = $backupDir . '/ProtocolInfo.php';
	if(file_exists($protocolInfoSrc)){
		copy($protocolInfoSrc, $protocolInfoDst);
	}
	
	// Switch ProtocolInfo.php
	$targetProtocolInfo = $versionDir . '/ProtocolInfo.php';
	if(file_exists($targetProtocolInfo)){
		copy($targetProtocolInfo, $protocolInfoSrc);
		fwrite(STDOUT, COLOR_GREEN . "  Switched ProtocolInfo.php" . COLOR_RESET . PHP_EOL);
	}else{
		fwrite(STDERR, COLOR_YELLOW . "  WARNING: ProtocolInfo.php not found in version" . COLOR_RESET . PHP_EOL);
	}
	
	// Switch BedrockData if available
	$targetBedrockData = $versionDir . '/BedrockData';
	if(is_dir($targetBedrockData)){
		// Copy BedrockData files
		$files = scandir($targetBedrockData);
		foreach($files as $file){
			if($file === '.' || $file === '..') continue;
			$src = $targetBedrockData . '/' . $file;
			$dst = $BEDROCK_DATA_DIR . '/' . $file;
			if(is_file($src)){
				copy($src, $dst);
			}
		}
		fwrite(STDOUT, COLOR_GREEN . "  Switched BedrockData files" . COLOR_RESET . PHP_EOL);
	}
	
	fwrite(STDOUT, PHP_EOL . COLOR_GREEN . "Switched to $targetVersion successfully!" . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, "  Previous version (backup): protocol $currentProtocol" . PHP_EOL);
	fwrite(STDOUT, "  New version: protocol " . ($versionInfo['protocol_version'] ?? '?') . PHP_EOL);
	
	return 0;
}

/**
 * Save current state as a version
 */
function saveVersion(array $options): int{
	global $VERSIONS_DIR, $BEDROCK_PROTOCOL_DIR, $BEDROCK_DATA_DIR;
	
	$versionName = $options[0] ?? null;
	if($versionName === null){
		fwrite(STDERR, COLOR_RED . "ERROR: Version name required" . COLOR_RESET . PHP_EOL);
		fwrite(STDERR, "Usage: php version-manager.php save <version>" . PHP_EOL);
		return 1;
	}
	
	$versionDir = $VERSIONS_DIR . '/' . $versionName;
	if(!is_dir($versionDir)){
		mkdir($versionDir, 0755, true);
	}
	
	fwrite(STDOUT, COLOR_GREEN . "Saving current state as '$versionName'..." . COLOR_RESET . PHP_EOL);
	
	// Get current version info
	$currentVersion = getCurrentVersionInfo();
	$protocol = $currentVersion['protocol_version'] ?? 0;
	$version = $currentVersion['minecraft_version'] ?? 'unknown';
	$displayVersion = $currentVersion['display_version'] ?? 'unknown';
	
	// Determine if stable or preview
	$isStable = $protocol < 2000;
	$type = $isStable ? 'stable' : 'preview';
	
	// Save version.json
	$versionInfo = [
		'version' => $versionName,
		'minecraft_version' => $version,
		'display_version' => $displayVersion,
		'protocol_version' => $protocol,
		'is_stable' => $isStable,
		'description' => ucfirst($type) . " version",
		'saved_at' => date('Y-m-d\TH:i:s'),
	];
	
	file_put_contents($versionDir . '/version.json', json_encode($versionInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	fwrite(STDOUT, "  Saved: version.json" . PHP_EOL);
	
	// Save ProtocolInfo.php
	$protocolInfoSrc = $BEDROCK_PROTOCOL_DIR . '/src/ProtocolInfo.php';
	$protocolInfoDst = $versionDir . '/ProtocolInfo.php';
	if(file_exists($protocolInfoSrc)){
		copy($protocolInfoSrc, $protocolInfoDst);
		fwrite(STDOUT, "  Saved: ProtocolInfo.php" . PHP_EOL);
	}
	
	// Save BedrockData files
	$bedrockDataDst = $versionDir . '/BedrockData';
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
	
	fwrite(STDOUT, PHP_EOL . COLOR_GREEN . "Version '$versionName' saved successfully!" . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, "  Location: $versionDir" . PHP_EOL);
	
	return 0;
}

/**
 * Compare two versions
 */
function diffVersions(array $options): int{
	global $VERSIONS_DIR;
	
	$v1 = $options[0] ?? null;
	$v2 = $options[1] ?? null;
	
	if($v1 === null || $v2 === null){
		fwrite(STDERR, COLOR_RED . "ERROR: Two version names required" . COLOR_RESET . PHP_EOL);
		fwrite(STDERR, "Usage: php version-manager.php diff <v1> <v2>" . PHP_EOL);
		return 1;
	}
	
	$v1Dir = $VERSIONS_DIR . '/' . $v1;
	$v2Dir = $VERSIONS_DIR . '/' . $v2;
	
	if(!is_dir($v1Dir)){
		fwrite(STDERR, COLOR_RED . "ERROR: Version '$v1' not found" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	if(!is_dir($v2Dir)){
		fwrite(STDERR, COLOR_RED . "ERROR: Version '$v2' not found" . COLOR_RESET . PHP_EOL);
		return 1;
	}
	
	$v1Info = getVersionInfo($v1Dir);
	$v2Info = getVersionInfo($v2Dir);
	
	fwrite(STDOUT, COLOR_GREEN . "Comparing versions:" . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	
	fwrite(STDOUT, "  " . COLOR_CYAN . $v1 . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, "    Version: " . ($v1Info['minecraft_version'] ?? '?') . PHP_EOL);
	fwrite(STDOUT, "    Protocol: " . ($v1Info['protocol_version'] ?? '?') . PHP_EOL);
	fwrite(STDOUT, "    Stable: " . (($v1Info['is_stable'] ?? false) ? 'Yes' : 'No') . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	
	fwrite(STDOUT, "  " . COLOR_CYAN . $v2 . COLOR_RESET . PHP_EOL);
	fwrite(STDOUT, "    Version: " . ($v2Info['minecraft_version'] ?? '?') . PHP_EOL);
	fwrite(STDOUT, "    Protocol: " . ($v2Info['protocol_version'] ?? '?') . PHP_EOL);
	fwrite(STDOUT, "    Stable: " . (($v2Info['is_stable'] ?? false) ? 'Yes' : 'No') . PHP_EOL);
	fwrite(STDOUT, PHP_EOL);
	
	// Compare protocol versions
	$p1 = $v1Info['protocol_version'] ?? 0;
	$p2 = $v2Info['protocol_version'] ?? 0;
	
	if($p1 === $p2){
		fwrite(STDOUT, "  " . COLOR_GREEN . "Same protocol version ($p1)" . COLOR_RESET . PHP_EOL);
	}else{
		fwrite(STDOUT, "  " . COLOR_YELLOW . "Different protocol versions: $p1 vs $p2" . COLOR_RESET . PHP_EOL);
		
		if($p2 > $p1){
			fwrite(STDOUT, "  " . COLOR_GREEN . $v2 . " is newer" . COLOR_RESET . PHP_EOL);
		}else{
			fwrite(STDOUT, "  " . COLOR_GREEN . $v1 . " is newer" . COLOR_RESET . PHP_EOL);
		}
	}
	
	// Compare ProtocolInfo.php if both exist
	$v1ProtocolInfo = $v1Dir . '/ProtocolInfo.php';
	$v2ProtocolInfo = $v2Dir . '/ProtocolInfo.php';
	
	if(file_exists($v1ProtocolInfo) && file_exists($v2ProtocolInfo)){
		$content1 = file_get_contents($v1ProtocolInfo);
		$content2 = file_get_contents($v2ProtocolInfo);
		
		// Extract packet counts
		preg_match_all('/const\s+\w+_PACKET\s*=\s*0x/', $content1, $matches1);
		preg_match_all('/const\s+\w+_PACKET\s*=\s*0x/', $content2, $matches2);
		
		$count1 = count($matches1[0]);
		$count2 = count($matches2[0]);
		
		fwrite(STDOUT, PHP_EOL . "  Packet count: $count1 vs $count2" . PHP_EOL);
		
		if($count1 !== $count2){
			$diff = $count2 - $count1;
			$sign = $diff > 0 ? '+' : '';
			fwrite(STDOUT, "  " . COLOR_YELLOW . "Difference: $sign$diff packets" . COLOR_RESET . PHP_EOL);
		}
	}
	
	return 0;
}

/**
 * Get current version info from ProtocolInfo.php
 */
function getCurrentVersionInfo(): array{
	global $BEDROCK_PROTOCOL_DIR;
	
	$protocolInfoFile = $BEDROCK_PROTOCOL_DIR . '/src/ProtocolInfo.php';
	if(!file_exists($protocolInfoFile)){
		return [];
	}
	
	$content = file_get_contents($protocolInfoFile);
	
	preg_match('/CURRENT_PROTOCOL\s*=\s*(\d+)/', $content, $protocolMatch);
	preg_match('/MINECRAFT_VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $versionMatch);
	preg_match('/MINECRAFT_VERSION_NETWORK\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $networkMatch);
	
	return [
		'protocol_version' => $protocolMatch ? (int) $protocolMatch[1] : 0,
		'minecraft_version' => $versionMatch ? $versionMatch[1] : 'unknown',
		'minecraft_version_network' => $networkMatch ? $networkMatch[1] : 'unknown',
		'display_version' => $versionMatch ? 'v' . str_replace('1.', '', $versionMatch[1]) : 'unknown',
	];
}

/**
 * Get version info from a version directory
 */
function getVersionInfo(string $versionDir): array{
	$versionFile = $versionDir . '/version.json';
	if(!file_exists($versionFile)){
		return [];
	}
	
	return json_decode(file_get_contents($versionFile), true, 512, JSON_THROW_ON_ERROR);
}
