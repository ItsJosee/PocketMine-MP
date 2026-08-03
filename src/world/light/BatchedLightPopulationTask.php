<?php

declare(strict_types=1);

namespace pocketmine\world\light;

use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\scheduler\AsyncTask;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\format\LightArray;
use pocketmine\world\SimpleChunkManager;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\World;
use function igbinary_serialize;
use function igbinary_unserialize;

class BatchedLightPopulationTask extends AsyncTask{
	private const TLS_KEY_COMPLETION_CALLBACK = "onCompletion";

	private string $serializedChunks;

	public function __construct(array $chunks, \Closure $onCompletion){
		$serialized = [];
		foreach($chunks as $key => $chunk){
			$serialized[$key] = FastChunkSerializer::serializeTerrain($chunk);
		}
		$this->serializedChunks = igbinary_serialize($serialized) ?? "";
		$this->storeLocal(self::TLS_KEY_COMPLETION_CALLBACK, $onCompletion);
	}

	public function onRun() : void{
		$serialized = igbinary_unserialize($this->serializedChunks);
		$blockFactory = RuntimeBlockStateRegistry::getInstance();
		$results = [];

		foreach($serialized as $key => $chunkData){
			$chunk = FastChunkSerializer::deserializeTerrain($chunkData);
			$manager = new SimpleChunkManager(World::Y_MIN, World::Y_MAX);
			$manager->setChunk(0, 0, $chunk);

			foreach([
				"Block" => new BlockLightUpdate(new SubChunkExplorer($manager), $blockFactory->lightFilter, $blockFactory->light),
				"Sky" => new SkyLightUpdate(new SubChunkExplorer($manager), $blockFactory->lightFilter, $blockFactory->blocksDirectSkyLight),
			] as $update){
				$update->recalculateChunk(0, 0);
				$update->execute();
			}

			$chunk->setLightPopulated();

			$heightMap = $chunk->getHeightMapArray();
			$skyLightArrays = [];
			$blockLightArrays = [];
			foreach($chunk->getSubChunks() as $y => $subChunk){
				$skyLightArrays[$y] = $subChunk->getBlockSkyLightArray();
				$blockLightArrays[$y] = $subChunk->getBlockLightArray();
			}

			$results[$key] = igbinary_serialize([
				'heightMap' => $heightMap,
				'skyLight' => $skyLightArrays,
				'blockLight' => $blockLightArrays,
			]);
		}

		$this->setResult(igbinary_serialize($results));
	}

	public function onCompletion() : void{
		$results = igbinary_unserialize($this->getResult());
		$decoded = [];
		foreach($results as $key => $data){
			$decoded[$key] = igbinary_unserialize($data);
		}

		$callback = $this->fetchLocal(self::TLS_KEY_COMPLETION_CALLBACK);
		$callback($decoded);
	}
}
