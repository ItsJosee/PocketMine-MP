<?php

declare(strict_types=1);

namespace pocketmine\tools\verify_protocol_update;

use function array_diff;
use function count;
use function dirname;
use function file_exists;
use function file_get_contents;
use function fwrite;
use function glob;
use function json_decode;
use function preg_match;
use function scandir;
use function str_ends_with;
use function substr;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;
use const STDERR;

$pmmpRoot = dirname(__DIR__);
$bedrockDataPath = $pmmpRoot . '/vendor/pocketmine/bedrock-data';
$protocolInfoPath = $pmmpRoot . '/vendor/pocketmine/bedrock-protocol/src/ProtocolInfo.php';
$worldVersionsPath = $pmmpRoot . '/src/data/bedrock/WorldDataVersions.php';

$errors = [];
$warnings = [];
$ok = [];

echo "=== PocketMine-MP Protocol Update Verification ===" . PHP_EOL . PHP_EOL;

echo "--- Checking BedrockData files ---" . PHP_EOL;
$requiredFiles = [
	'protocol_info.json',
	'canonical_block_states.nbt',
	'required_item_list.json',
	'entity_id_map.json',
	'biome_definitions.json',
	'r16_to_current_item_map.json',
	'command_arg_types.json',
	'level_sound_id_map.json',
];

$optionalFiles = [
	'creativeitems.json',
	'construction.json',
	'nature.json',
	'equipment.json',
	'items.json',
];

foreach($requiredFiles as $file){
	$path = $bedrockDataPath . '/' . $file;
	if(file_exists($path)){
		$ok[] = "BedrockData/$file exists";
		echo "  [OK] $file" . PHP_EOL;
	}else{
		$errors[] = "BedrockData/$file MISSING";
		echo "  [ERR] $file MISSING" . PHP_EOL;
	}
}

foreach($optionalFiles as $file){
	$path = $bedrockDataPath . '/' . $file;
	if(file_exists($path)){
		echo "  [OK] $file" . PHP_EOL;
	}else{
		$warnings[] = "BedrockData/$file not found (optional)";
		echo "  [WARN] $file not found" . PHP_EOL;
	}
}

$recipeDir = $bedrockDataPath . '/recipes';
if(is_dir($recipeDir)){
	$recipeFiles = glob($recipeDir . '/*.json');
	echo "  [OK] recipes/ directory (" . count($recipeFiles) . " files)" . PHP_EOL;
}else{
	$warnings[] = "BedrockData/recipes/ directory not found";
	echo "  [WARN] recipes/ directory not found" . PHP_EOL;
}

echo PHP_EOL . "--- Checking ProtocolInfo ---" . PHP_EOL;
if(file_exists($protocolInfoPath)){
	$content = file_get_contents($protocolInfoPath);
	if($content !== false){
		preg_match('/CURRENT_PROTOCOL\s*=\s*(\d+)/', $content, $protocolMatch);
		preg_match("/MINECRAFT_VERSION\s*=\s*'([^']+)'/", $content, $versionMatch);
		preg_match("/MINECRAFT_VERSION_NETWORK\s*=\s*'([^']+)'/", $content, $networkMatch);

		$protocol = $protocolMatch[1] ?? '?';
		$version = $versionMatch[1] ?? '?';
		$network = $networkMatch[1] ?? '?';

		echo "  Protocol: $protocol" . PHP_EOL;
		echo "  Version:  $version" . PHP_EOL;
		echo "  Network:  $network" . PHP_EOL;
		$ok[] = "ProtocolInfo readable (protocol=$protocol, version=$version)";
	}
}else{
	$errors[] = "ProtocolInfo.php not found";
	echo "  [ERR] ProtocolInfo.php not found" . PHP_EOL;
}

echo PHP_EOL . "--- Checking WorldDataVersions ---" . PHP_EOL;
if(file_exists($worldVersionsPath)){
	$content = file_get_contents($worldVersionsPath);
	if($content !== false){
		preg_match('/NETWORK\s*=\s*(\d+)/', $content, $networkMatch);
		preg_match('/LAST_OPENED_IN\s*=\s*\[([^\]]+)\]/', $content, $lastOpenedMatch);

		$worldNetwork = $networkMatch[1] ?? '?';
		$lastOpened = $lastOpenedMatch[1] ?? '?';

		echo "  NETWORK:       $worldNetwork" . PHP_EOL;
		echo "  LAST_OPENED_IN: [$lastOpened]" . PHP_EOL;

		if(isset($protocolMatch) && (int)$worldNetwork > (int)$protocolMatch[1]){
			$errors[] = "WorldDataVersions::NETWORK ($worldNetwork) > ProtocolInfo::CURRENT_PROTOCOL ($protocol)";
			echo "  [ERR] NETWORK > CURRENT_PROTOCOL" . PHP_EOL;
		}else{
			$ok[] = "WorldDataVersions consistent";
		}
	}
}else{
	$errors[] = "WorldDataVersions.php not found";
	echo "  [ERR] WorldDataVersions.php not found" . PHP_EOL;
}

echo PHP_EOL . "--- Checking Packet Stubs ---" . PHP_EOL;
$packetsDir = dirname($protocolInfoPath);
$packetFiles = scandir($packetsDir);
$stubCount = 0;
$stubNames = [];

if($packetFiles !== false){
	$ignoredFiles = [
		'ProtocolInfo.php', 'PacketPool.php', 'Packet.php', 'DataPacket.php',
		'PacketDecodeException.php', 'PacketHandlerInterface.php',
		'PacketHandlerDefaultImplTrait.php', 'ClientboundPacket.php',
		'ServerboundPacket.php',
	];

	foreach($packetFiles as $file){
		if(!str_ends_with($file, '.php') || in_array($file, $ignoredFiles, true)){
			continue;
		}
		$packetName = substr($file, 0, -4);
		if(!str_ends_with($packetName, 'Packet')){
			continue;
		}

		$content = file_get_contents($packetsDir . '/' . $file);
		if($content !== false && preg_match('/\/\/TODO/', $content)){
			if(preg_match('/protected function decodePayload.*?\{[\s]*\/\/TODO[\s]*\}/s', $content) ||
			   preg_match('/protected function encodePayload.*?\{[\s]*\/\/TODO[\s]*\}/s', $content)){
				$stubCount++;
				$stubNames[] = $packetName;
			}
		}
	}
}

if($stubCount > 0){
	$warnings[] = "$stubCount packet(s) still have stub implementations";
	echo "  [WARN] $stubCount stub packet(s) found:" . PHP_EOL;
	foreach($stubNames as $name){
		echo "    - $name" . PHP_EOL;
	}
}else{
	$ok[] = "No stub packets found";
	echo "  [OK] No stub packets" . PHP_EOL;
}

echo PHP_EOL . "--- Checking Upgrade Schemas ---" . PHP_EOL;
$blockSchemaDir = $pmmpRoot . '/vendor/pocketmine/bedrock-block-upgrade-schema/nbt_upgrade_schema';
$itemSchemaDir = $pmmpRoot . '/vendor/pocketmine/bedrock-item-upgrade-schema/id_meta_upgrade_schema';

if(is_dir($blockSchemaDir)){
	$schemas = glob($blockSchemaDir . '/*.json');
	echo "  [OK] Block upgrade schemas: " . count($schemas) . " files" . PHP_EOL;
}else{
	$warnings[] = "Block upgrade schema directory not found";
	echo "  [WARN] Block upgrade schema directory not found" . PHP_EOL;
}

if(is_dir($itemSchemaDir)){
	$schemas = glob($itemSchemaDir . '/*.json');
	echo "  [OK] Item upgrade schemas: " . count($schemas) . " files" . PHP_EOL;
}else{
	$warnings[] = "Item upgrade schema directory not found";
	echo "  [WARN] Item upgrade schema directory not found" . PHP_EOL;
}

echo PHP_EOL . "=== SUMMARY ===" . PHP_EOL;
echo "  OK:       " . count($ok) . PHP_EOL;
echo "  Warnings: " . count($warnings) . PHP_EOL;
echo "  Errors:   " . count($errors) . PHP_EOL;
echo PHP_EOL;

if(count($warnings) > 0){
	echo "--- Warnings ---" . PHP_EOL;
	foreach($warnings as $w){
		echo "  [!] $w" . PHP_EOL;
	}
	echo PHP_EOL;
}

if(count($errors) > 0){
	echo "--- Errors ---" . PHP_EOL;
	foreach($errors as $e){
		echo "  [X] $e" . PHP_EOL;
	}
	echo PHP_EOL;
	exit(1);
}

echo "All checks passed!" . PHP_EOL;
exit(0);
