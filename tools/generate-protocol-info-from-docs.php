<?php

declare(strict_types=1);

/**
 * Alternative to protocol_info_dumper.py for when BDS symbols are unavailable.
 * 
 * Generates protocol_info.json from Mojang's public protocol documentation.
 * This script:
 * 1. Downloads packet JSON files from Mojang's bedrock-protocol-docs
 * 2. Cross-references with existing BedrockProtocol for packet IDs
 * 3. Generates protocol_info.json compatible with BedrockData
 *
 * Usage:
 *   php generate-protocol-info-from-docs.php <protocol-version> <bedrock-protocol-path> [output-path]
 *
 * Example:
 *   php generate-protocol-info-from-docs.php 2169 ../deps/BedrockProtocol ../deps/BedrockData
 *
 * NOTE: This is a fallback method. The original protocol_info_dumper.py is preferred
 * when BDS with debug symbols is available.
 */

namespace pocketmine\tools\generate_protocol_info;

use function array_key_exists;
use function array_merge;
use function array_push;
use function array_search;
use function array_values;
use function chmod;
use function copy;
use function count;
use function curl_close;
use function curl_exec;
use function curl_init;
use function curl_setopt_array;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function preg_replace;
use function scandir;
use function sort;
use function str_contains;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function trim;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;
use const STDERR;
use const STDOUT;

if(count($argv) < 3){
	fwrite(STDERR, "Usage: php generate-protocol-info-from-docs.php <protocol-version> <bedrock-protocol-path> [output-path]" . PHP_EOL);
	fwrite(STDERR, PHP_EOL);
	fwrite(STDERR, "Generates protocol_info.json from Mojang's public protocol docs." . PHP_EOL);
	fwrite(STDERR, PHP_EOL);
	fwrite(STDERR, "Arguments:" . PHP_EOL);
	fwrite(STDERR, "  protocol-version      Target protocol version (e.g., 2169 for 1.26.50)" . PHP_EOL);
	fwrite(STDERR, "  bedrock-protocol-path Path to BedrockProtocol repository" . PHP_EOL);
	fwrite(STDERR, "  output-path           Optional output path (default: BedrockData/)" . PHP_EOL);
	fwrite(STDERR, PHP_EOL);
	fwrite(STDERR, "The script will:" . PHP_EOL);
	fwrite(STDERR, "  1. Fetch packet list from Mojang's protocol docs" . PHP_EOL);
	fwrite(STDERR, "  2. Cross-reference with existing BedrockProtocol for packet IDs" . PHP_EOL);
	fwrite(STDERR, "  3. Generate protocol_info.json" . PHP_EOL);
	fwrite(STDERR, PHP_EOL);
	fwrite(STDERR, "NOTE: Packet IDs from existing BedrockProtocol are used as reference." . PHP_EOL);
	fwrite(STDERR, "      If packets were added/removed, manual adjustment may be needed." . PHP_EOL);
	exit(1);
}

$protocolVersion = (int) $argv[1];
$bedrockProtocolPath = $argv[2];
$outputPath = isset($argv[3]) ? $argv[3] : dirname(__DIR__) . '/deps/BedrockData';

$docsBaseUrl = 'https://raw.githubusercontent.com/Mojang/bedrock-protocol-docs/main/json';

fwrite(STDOUT, "=== Protocol Info Generator (from Mojang Docs) ===" . PHP_EOL);
fwrite(STDOUT, "Target protocol version: $protocolVersion" . PHP_EOL);
fwrite(STDOUT, "BedrockProtocol path: $bedrockProtocolPath" . PHP_EOL);
fwrite(STDOUT, "Output path: $outputPath" . PHP_EOL);
fwrite(STDOUT, PHP_EOL);

// Step 1: Extract packet list from existing BedrockProtocol
fwrite(STDOUT, "[Step 1] Extracting packet list from BedrockProtocol..." . PHP_EOL);

$protocolInfoFile = $bedrockProtocolPath . '/src/ProtocolInfo.php';
if(!file_exists($protocolInfoFile)){
	fwrite(STDERR, "ERROR: ProtocolInfo.php not found at $protocolInfoFile" . PHP_EOL);
	exit(1);
}

$protocolInfoContent = file_get_contents($protocolInfoFile);

// Extract current protocol version and version strings
preg_match('/CURRENT_PROTOCOL\s*=\s*(\d+)/', $protocolInfoContent, $currentProtocolMatch);
preg_match('/MINECRAFT_VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', $protocolInfoContent, $minecraftVersionMatch);
preg_match('/MINECRAFT_VERSION_NETWORK\s*=\s*[\'"]([^\'"]+)[\'"]/', $protocolInfoContent, $minecraftVersionNetworkMatch);

$currentProtocol = $currentProtocolMatch ? (int) $currentProtocolMatch[1] : 0;
$minecraftVersion = $minecraftVersionMatch ? $minecraftVersionMatch[1] : 'unknown';
$minecraftVersionNetwork = $minecraftVersionNetworkMatch ? $minecraftVersionNetworkMatch[1] : 'unknown';

fwrite(STDOUT, "  Current protocol: $currentProtocol" . PHP_EOL);
fwrite(STDOUT, "  Current version: $minecraftVersion ($minecraftVersionNetwork)" . PHP_EOL);

// Extract packet constants
preg_match_all('/public\s+const\s+(\w+_PACKET)\s*=\s*(0x[0-9a-fA-F]+)/', $protocolInfoContent, $packetMatches, PREG_SET_ORDER);

$existingPackets = [];
foreach($packetMatches as $match){
	$name = $match[1];
	$id = hexdec($match[2]);
	$existingPackets[$name] = $id;
}

fwrite(STDOUT, "  Found " . count($existingPackets) . " packet constants" . PHP_EOL);

// Step 2: Get list of all packet JSON files from Mojang docs
fwrite(STDOUT, PHP_EOL . "[Step 2] Fetching packet list from Mojang protocol docs..." . PHP_EOL);

$packetJsonDir = $outputPath . '/mojang_docs_json';
if(!is_dir($packetJsonDir)){
	mkdir($packetJsonDir, 0755, true);
}

// Fetch the LoginPacket.json to verify docs version
$loginPacketUrl = $docsBaseUrl . '/LoginPacket.json';
$loginPacketData = downloadFile($loginPacketUrl);

if($loginPacketData === null){
	fwrite(STDERR, "WARNING: Could not fetch LoginPacket.json from Mojang docs" . PHP_EOL);
	fwrite(STDERR, "         Will use existing BedrockProtocol data as reference" . PHP_EOL);
	$docsVersion = 'unknown';
	$docsProtocol = 0;
}else{
	$loginPacket = json_decode($loginPacketData, true, 512, JSON_THROW_ON_ERROR);
	$docsVersion = $loginPacket['x-minecraft-version'] ?? 'unknown';
	$docsProtocol = $loginPacket['x-protocol-version'] ?? 0;
	fwrite(STDOUT, "  Docs version: $docsVersion (protocol $docsProtocol)" . PHP_EOL);
}

// Step 3: Generate protocol_info.json
fwrite(STDOUT, PHP_EOL . "[Step 3] Generating protocol_info.json..." . PHP_EOL);

// Determine target version info
if($protocolVersion > 0){
	// Use provided protocol version
	$targetProtocol = $protocolVersion;
	$targetVersion = $docsVersion;
	$targetVersionNetwork = $docsVersion;
}else{
	// Use current version
	$targetProtocol = $currentProtocol;
	$targetVersion = $minecraftVersion;
	$targetVersionNetwork = $minecraftVersionNetwork;
}

// Build version info
$versionInfo = [
	'major' => 0,
	'minor' => 0,
	'patch' => 0,
	'revision' => 0,
	'beta' => false,
	'protocol_version' => $targetProtocol,
];

// Try to parse version from docs version string
if($targetVersion !== 'unknown'){
	$versionParts = [];
	if(preg_match('/(\d+)\.(\d+)\.(\d+)/', $targetVersion, $versionParts)){
		$versionInfo['major'] = (int) ($versionParts[1] ?? 0);
		$versionInfo['minor'] = (int) ($versionParts[2] ?? 0);
		$versionInfo['patch'] = (int) ($versionParts[3] ?? 0);
	}
}

// Build packets list from existing BedrockProtocol
$packets = [];
foreach($existingPackets as $name => $id){
	// Convert packet constant name to class name
	$className = str_replace('_PACKET', 'Packet', $name);
	$className = str_replace('_', '', ucwords(strtolower($className), '_'));
	$className = str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($className))));
	
	// More accurate conversion
	$parts = explode('_', $name);
	$classNameParts = [];
	foreach($parts as $part){
		if($part === 'PACKET') continue;
		$classNameParts[] = ucfirst(strtolower($part));
	}
	$className = implode('', $classNameParts) . 'Packet';
	
	$packets[$className] = $id;
}

// Sort by ID
asort($packets);

// Build output
$output = [
	'version' => $versionInfo,
	'packets' => $packets,
];

$outputFile = $packetJsonDir . '/protocol_info.json';
file_put_contents($outputFile, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

fwrite(STDOUT, "  Generated: $outputFile" . PHP_EOL);
fwrite(STDOUT, "  Protocol version: $targetProtocol" . PHP_EOL);
fwrite(STDOUT, "  Packet count: " . count($packets) . PHP_EOL);

// Step 4: Generate report
fwrite(STDOUT, PHP_EOL . "[Step 4] Generating report..." . PHP_EOL);

$report = [
	'generated_at' => date('Y-m-d H:i:s'),
	'target_protocol' => $targetProtocol,
	'target_version' => $targetVersion,
	'docs_version' => $docsVersion,
	'docs_protocol' => $docsProtocol,
	'existing_packets' => count($existingPackets),
	'generated_packets' => count($packets),
	'notes' => [
		'This protocol_info.json was generated from existing BedrockProtocol data.',
		'Packet IDs are from the current BedrockProtocol implementation.',
		'For accurate packet IDs, use protocol_info_dumper.py with BDS symbols.',
		'The Mojang protocol docs provide packet structures but not IDs.',
	],
];

$reportFile = $packetJsonDir . '/generation_report.json';
file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

fwrite(STDOUT, "  Report: $reportFile" . PHP_EOL);

// Step 5: Instructions
fwrite(STDOUT, PHP_EOL . "=== Next Steps ===" . PHP_EOL);
fwrite(STDOUT, "1. Copy protocol_info.json to BedrockData:" . PHP_EOL);
fwrite(STDOUT, "   cp $packetJsonDir/protocol_info.json ../deps/BedrockData/" . PHP_EOL);
fwrite(STDOUT, PHP_EOL);
fwrite(STDOUT, "2. If packets were added/removed in the new version:" . PHP_EOL);
fwrite(STDOUT, "   - Manually add new packet constants to ProtocolInfo.php" . PHP_EOL);
fwrite(STDOUT, "   - Remove deleted packet constants" . PHP_EOL);
fwrite(STDOUT, "   - Update packet IDs if they changed" . PHP_EOL);
fwrite(STDOUT, PHP_EOL);
fwrite(STDOUT, "3. Run the update script:" . PHP_EOL);
fwrite(STDOUT, "   php deps/BedrockProtocol/tools/update-from-bedrock-data.php deps/BedrockData" . PHP_EOL);
fwrite(STDOUT, PHP_EOL);
fwrite(STDOUT, "4. For packet structures, reference:" . PHP_EOL);
fwrite(STDOUT, "   https://github.com/Mojang/bedrock-protocol-docs/tree/main/json" . PHP_EOL);
fwrite(STDOUT, PHP_EOL);
fwrite(STDOUT, "=== Done ===" . PHP_EOL);

/**
 * Download a file from URL
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
