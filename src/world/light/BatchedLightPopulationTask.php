<?php

declare(strict_types=1);

namespace pocketmine\world\light;

use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\scheduler\AsyncTask;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\World;
use function igbinary_serialize;
use function igbinary_unserialize;

class BatchedLightPopulationTask extends AsyncTask{
	private const TLS_KEY_COMPLETION_CALLBACK = "onCompletion";

	/** @var string */
	public string $serializedChunks;

	/** @var string */
	private string $result = "";

	/**
	 * @param array<int, Chunk> $chunks
	 * @phpstan-param \Closure(array<int, array{heightMap: list<int>, blockLight: array<int, \pocketmine\world\format\LightArray>, skyLight: array<int, \pocketmine\world\format\LightArray>}> $results) : void $onCompletion
	 */
	public function __construct(array $chunks, \Closure $onCompletion){
		$serialized = [];
		foreach($chunks as $chunkHash => $chunk){
			$serialized[$chunkHash] = FastChunkSerializer::serializeTerrain($chunk);
		}
		$this->serializedChunks = igbinary_serialize($serialized);
		$this->storeLocal(self::TLS_KEY_COMPLETION_CALLBACK, $onCompletion);
	}

	public function onRun() : void{
		/** @var array<int, string> $chunksData */
		$chunksData = igbinary_unserialize($this->serializedChunks);

		$blockFactory = RuntimeBlockStateRegistry::getInstance();
		$manager = new SimpleChunkManager(World::Y_MIN, World::Y_MAX);
		$results = [];

		foreach($chunksData as $chunkHash => $serializedChunk){
			$chunk = FastChunkSerializer::deserializeTerrain($serializedChunk);
			$manager->setChunk(0, 0, $chunk);

			foreach([
				new BlockLightUpdate(new SubChunkExplorer($manager), $blockFactory->lightFilter, $blockFactory->light),
				new SkyLightUpdate(new SubChunkExplorer($manager), $blockFactory->lightFilter, $blockFactory->blocksDirectSkyLight),
			] as $update){
				$update->recalculateChunk(0, 0);
				$update->execute();
			}

			$chunk->setLightPopulated();

			$skyLightArrays = [];
			$blockLightArrays = [];
			foreach($chunk->getSubChunks() as $y => $subChunk){
				$skyLightArrays[$y] = $subChunk->getBlockSkyLightArray();
				$blockLightArrays[$y] = $subChunk->getBlockLightArray();
			}
			$results[$chunkHash] = [
				'heightMap' => $chunk->getHeightMapArray(),
				'blockLight' => $blockLightArrays,
				'skyLight' => $skyLightArrays,
			];
		}

		$this->result = igbinary_serialize($results);
	}

	public function onCompletion() : void{
		/** @var array<int, array{heightMap: list<int>, blockLight: array<int, \pocketmine\world\format\LightArray>, skyLight: array<int, \pocketmine\world\format\LightArray>}> $results */
		$results = igbinary_unserialize($this->result);

		/** @var \Closure $callback */
		$callback = $this->fetchLocal(self::TLS_KEY_COMPLETION_CALLBACK);
		$callback($results);
	}
}
