<?php

declare(strict_types=1);

namespace pocketmine\tools\analyze_protocol_changes;

use function array_diff;
use function array_keys;
use function count;
use function dirname;
use function file_get_contents;
use function fwrite;
use function json_decode;
use function preg_match;
use function scandir;
use function str_ends_with;
use function substr;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;
use const STDERR;

if(count($argv) < 2){
	fwrite(STDERR, "Usage: php analyze-protocol-changes.php <path-to-BedrockProtocol> [--verify] [--json <output-file>]" . PHP_EOL);
	fwrite(STDERR, "  Analyzes packet classes in BedrockProtocol and reports:" . PHP_EOL);
	fwrite(STDERR, "    - New packets (stub classes with TODO comments)" . PHP_EOL);
	fwrite(STDERR, "    - Packets with incomplete encode/decode" . PHP_EOL);
	fwrite(STDERR, "    - Protocol version info" . PHP_EOL);
	fwrite(STDERR, "  --verify : Also checks PMMP handler coverage" . PHP_EOL);
	fwrite(STDERR, "  --json   : Output report as JSON to file" . PHP_EOL);
	exit(1);
}

$bedrockProtocolPath = $argv[1];
$verifyMode = false;
$jsonOutput = null;

for($i = 2; $i < count($argv); $i++){
	if($argv[$i] === '--verify'){
		$verifyMode = true;
	}elseif($argv[$i] === '--json' && isset($argv[$i + 1])){
		$jsonOutput = $argv[++$i];
	}
}

$packetsDir = $bedrockProtocolPath . '/src';
$protocolInfoFile = $packetsDir . '/ProtocolInfo.php';

if(!file_exists($protocolInfoFile)){
	fwrite(STDERR, "ProtocolInfo.php not found at $protocolInfoFile" . PHP_EOL);
	exit(1);
}

$protocolInfoContent = file_get_contents($protocolInfoFile);
if($protocolInfoContent === false){
	fwrite(STDERR, "Could not read ProtocolInfo.php" . PHP_EOL);
	exit(1);
}

preg_match('/CURRENT_PROTOCOL\s*=\s*(\d+)/', $protocolInfoContent, $protocolMatch);
preg_match("/MINECRAFT_VERSION\s*=\s*'([^']+)'/", $protocolInfoContent, $versionMatch);
preg_match("/MINECRAFT_VERSION_NETWORK\s*=\s*'([^']+)'/", $protocolInfoContent, $networkMatch);

$currentProtocol = (int)($protocolMatch[1] ?? 0);
$minecraftVersion = $versionMatch[1] ?? 'unknown';
$minecraftVersionNetwork = $networkMatch[1] ?? 'unknown';

$packetFiles = scandir($packetsDir);
if($packetFiles === false){
	fwrite(STDERR, "Could not scan packets directory" . PHP_EOL);
	exit(1);
}

$ignoredFiles = [
	'ProtocolInfo.php', 'PacketPool.php', 'Packet.php', 'DataPacket.php',
	'PacketDecodeException.php', 'PacketHandlerInterface.php',
	'PacketHandlerDefaultImplTrait.php', 'ClientboundPacket.php',
	'ServerboundPacket.php',
];

$stubPackets = [];
$incompletePackets = [];
$completePackets = [];
$allPackets = [];

foreach($packetFiles as $file){
	if(!str_ends_with($file, '.php') || in_array($file, $ignoredFiles, true)){
		continue;
	}

	$packetName = substr($file, 0, -4);
	if(!str_ends_with($packetName, 'Packet')){
		continue;
	}

	$filePath = $packetsDir . '/' . $file;
	$content = file_get_contents($filePath);
	if($content === false){
		continue;
	}

	$allPackets[] = $packetName;

	$hasTodo = false;
	$hasStubDecode = false;
	$hasStubEncode = false;

	if(preg_match('/\/\/TODO/', $content)){
		$hasTodo = true;
	}

	if(preg_match('/protected function decodePayload.*?\{[\s]*\/\/TODO[\s]*\}/s', $content)){
		$hasStubDecode = true;
	}

	if(preg_match('/protected function encodePayload.*?\{[\s]*\/\/TODO[\s]*\}/s', $content)){
		$hasStubEncode = true;
	}

	if($hasTodo && ($hasStubDecode || $hasStubEncode)){
		$stubPackets[] = $packetName;
	}elseif($hasTodo){
		$incompletePackets[] = $packetName;
	}else{
		$completePackets[] = $packetName;
	}
}

$pmmpRoot = dirname(__DIR__);
$handlerCoverage = [];

if($verifyMode){
	$handlerDirs = [
		$pmmpRoot . '/src/network/mcpe/handler',
	];

	$handlerContent = '';
	foreach($handlerDirs as $dir){
		if(!is_dir($dir)){
			continue;
		}
		$files = scandir($dir);
		if($files === false){
			continue;
		}
		foreach($files as $file){
			if(!str_ends_with($file, '.php')){
				continue;
			}
			$content = file_get_contents($dir . '/' . $file);
			if($content !== false){
				$handlerContent .= $content;
			}
		}
	}

	foreach($allPackets as $packet){
		$handlerMethod = 'handle' . substr($packet, 0, -6);
		if(preg_match('/function\s+' . preg_quote($handlerMethod, '/') . '\s*\(/', $handlerContent)){
			$handlerCoverage[$packet] = true;
		}else{
			$handlerCoverage[$packet] = false;
		}
	}
}

$report = [
	'protocol' => [
		'version' => $currentProtocol,
		'minecraft_version' => $minecraftVersion,
		'minecraft_version_network' => $minecraftVersionNetwork,
	],
	'summary' => [
		'total_packets' => count($allPackets),
		'complete' => count($completePackets),
		'incomplete' => count($incompletePackets),
		'stubs' => count($stubPackets),
	],
	'stub_packets' => $stubPackets,
	'incomplete_packets' => $incompletePackets,
	'complete_packets' => $completePackets,
];

if($verifyMode){
	$handledPackets = [];
	$unhandledPackets = [];
	foreach($handlerCoverage as $packet => $handled){
		if($handled){
			$handledPackets[] = $packet;
		}else{
			$unhandledPackets[] = $packet;
		}
	}
	$report['handler_coverage'] = [
		'handled' => count($handledPackets),
		'unhandled' => count($unhandledPackets),
		'unhandled_packets' => $unhandledPackets,
	];
}

if($jsonOutput !== null){
	file_put_contents($jsonOutput, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
	echo "Report written to $jsonOutput" . PHP_EOL;
}

echo PHP_EOL;
echo "=== Protocol Analysis Report ===" . PHP_EOL;
echo PHP_EOL;
echo "Protocol version: $currentProtocol" . PHP_EOL;
echo "Minecraft version: $minecraftVersion" . PHP_EOL;
echo "Network version: $minecraftVersionNetwork" . PHP_EOL;
echo PHP_EOL;
echo "Total packets: " . count($allPackets) . PHP_EOL;
echo "  Complete:   " . count($completePackets) . PHP_EOL;
echo "  Incomplete: " . count($incompletePackets) . PHP_EOL;
echo "  Stubs:      " . count($stubPackets) . PHP_EOL;
echo PHP_EOL;

if(count($stubPackets) > 0){
	echo "--- STUB PACKETS (need full implementation) ---" . PHP_EOL;
	foreach($stubPackets as $p){
		echo "  [STUB] $p" . PHP_EOL;
	}
	echo PHP_EOL;
}

if(count($incompletePackets) > 0){
	echo "--- INCOMPLETE PACKETS (have TODO comments) ---" . PHP_EOL;
	foreach($incompletePackets as $p){
		echo "  [TODO] $p" . PHP_EOL;
	}
	echo PHP_EOL;
}

if($verifyMode){
	$unhandled = [];
	foreach($handlerCoverage as $packet => $handled){
		if(!$handled){
			$unhandled[] = $packet;
		}
	}

	echo "--- PMMP HANDLER COVERAGE ---" . PHP_EOL;
	echo "  Handled:   " . count($allPackets) - count($unhandled) . PHP_EOL;
	echo "  Unhandled: " . count($unhandled) . PHP_EOL;

	if(count($unhandled) > 0){
		echo PHP_EOL;
		echo "--- UNHANDLED PACKETS IN PMMP ---" . PHP_EOL;
		foreach($unhandled as $p){
			echo "  [MISSING] $p" . PHP_EOL;
		}
	}
	echo PHP_EOL;
}

if(count($stubPackets) > 0){
	exit(2);
}
exit(0);
