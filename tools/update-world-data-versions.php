<?php

declare(strict_types=1);

namespace pocketmine\tools\update_world_data_versions;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function preg_match;
use function preg_replace;
use function str_replace;
use const PHP_EOL;
use const STDERR;

$pmmpRoot = dirname(__DIR__);
$protocolInfoFile = $pmmpRoot . '/vendor/pocketmine/bedrock-protocol/src/ProtocolInfo.php';
$worldVersionsFile = $pmmpRoot . '/src/data/bedrock/WorldDataVersions.php';

if(!file_exists($protocolInfoFile)){
	fwrite(STDERR, "ProtocolInfo.php not found at $protocolInfoFile" . PHP_EOL);
	exit(1);
}
if(!file_exists($worldVersionsFile)){
	fwrite(STDERR, "WorldDataVersions.php not found at $worldVersionsFile" . PHP_EOL);
	exit(1);
}

$protocolContent = file_get_contents($protocolInfoFile);
if($protocolContent === false){
	fwrite(STDERR, "Could not read ProtocolInfo.php" . PHP_EOL);
	exit(1);
}

preg_match('/MINECRAFT_VERSION_NETWORK\s*=\s*\'([^\']+)\'/', $protocolContent, $networkMatch);
$networkVersion = $networkMatch[1] ?? '';

$parts = explode('.', $networkVersion);
$major = (int)($parts[0] ?? 0);
$minor = (int)($parts[1] ?? 0);
$patch = (int)($parts[2] ?? 0);
$revision = 0;
$isBeta = false;

if(isset($parts[3])){
	$revision = (int)$parts[3];
}
if(str_contains($networkVersion, 'beta')){
	$isBeta = true;
}

echo "Detected Minecraft version from ProtocolInfo:" . PHP_EOL;
echo "  Network version: $networkVersion" . PHP_EOL;
echo "  Major: $major, Minor: $minor, Patch: $patch, Revision: $revision, Beta: " . ($isBeta ? "yes" : "no") . PHP_EOL;
echo PHP_EOL;

$worldContent = file_get_contents($worldVersionsFile);
if($worldContent === false){
	fwrite(STDERR, "Could not read WorldDataVersions.php" . PHP_EOL);
	exit(1);
}

$lastOpenedPattern = '/(public const LAST_OPENED_IN = \[)\s*\d+,\s*\/\/major\s*\d+,\s*\/\/minor\s*\d+,\s*\/\/patch\s*\d+,\s*\/\/revision\s*\d+\s*\/\/is beta\s*(\];)/s';

$lastOpenedReplacement = '$1' . PHP_EOL .
	"\t\t$major, //major" . PHP_EOL .
	"\t\t$minor, //minor" . PHP_EOL .
	"\t\t$patch, //patch" . PHP_EOL .
	"\t\t$revision, //revision" . PHP_EOL .
	"\t\t" . ($isBeta ? "1" : "0") . " //is beta" . PHP_EOL .
	"\t" . '$2';

$newContent = preg_replace($lastOpenedPattern, $lastOpenedReplacement, $worldContent);

if($newContent === null || $newContent === $worldContent){
	echo "Could not update LAST_OPENED_IN automatically." . PHP_EOL;
	echo "Manual update needed in: $worldVersionsFile" . PHP_EOL;
	echo PHP_EOL;
	echo "Set LAST_OPENED_IN to:" . PHP_EOL;
	echo "  [$major, $minor, $patch, $revision, " . ($isBeta ? "1" : "0") . "]" . PHP_EOL;
}else{
	file_put_contents($worldVersionsFile, $newContent);
	echo "Updated LAST_OPENED_IN in WorldDataVersions.php" . PHP_EOL;
	echo "  New value: [$major, $minor, $patch, $revision, " . ($isBeta ? "1" : "0") . "]" . PHP_EOL;
}

echo PHP_EOL;
echo "=== MANUAL UPDATES NEEDED ===" . PHP_EOL;
echo PHP_EOL;
echo "The following constants may need manual updates in:" . PHP_EOL;
echo "  $worldVersionsFile" . PHP_EOL;
echo PHP_EOL;
echo "1. NETWORK - Update if world format support has changed" . PHP_EOL;
echo "   Current value needs to be checked against new Bedrock world format" . PHP_EOL;
echo PHP_EOL;
echo "2. BLOCK_STATES - Update if blockstate format changed" . PHP_EOL;
echo "   Should match the newest blockstate upgrade schema version" . PHP_EOL;
echo PHP_EOL;
echo "3. CHUNK - Update if chunk format changed (rare)" . PHP_EOL;
echo "   Check ChunkVersion.php for new version constants" . PHP_EOL;
echo PHP_EOL;
echo "4. SUBCHUNK - Update if subchunk format changed (very rare)" . PHP_EOL;
echo PHP_EOL;
echo "5. STORAGE - Update if storage format changed (very rare)" . PHP_EOL;
echo PHP_EOL;
