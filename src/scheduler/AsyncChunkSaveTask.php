<?php

declare(strict_types=1);

namespace pocketmine\scheduler;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\thread\NonThreadSafeValue;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\ChunkData;
use pocketmine\world\format\io\FastChunkSerializer;
use function igbinary_serialize;
use function igbinary_unserialize;

class AsyncChunkSaveTask extends AsyncTask{
	private const TLS_KEY_CALLBACK = "callback";

	private string $serializedChunk;
	private string $serializedEntityNBT;
	private string $serializedTileNBT;
	private int $dirtyFlags;
	private bool $populated;

	public function __construct(
		Chunk $chunk,
		array $entityNBT,
		array $tileNBT,
		int $dirtyFlags,
		private NonThreadSafeValue $provider,
		private int $chunkX,
		private int $chunkZ,
		\Closure $onCompletion
	){
		$this->serializedChunk = FastChunkSerializer::serializeTerrain($chunk);
		$this->serializedEntityNBT = igbinary_serialize($entityNBT) ?? "";
		$this->serializedTileNBT = igbinary_serialize($tileNBT) ?? "";
		$this->dirtyFlags = $dirtyFlags;
		$this->populated = $chunk->isPopulated();
		$this->storeLocal(self::TLS_KEY_CALLBACK, $onCompletion);
	}

	public function onRun() : void{
		$chunk = FastChunkSerializer::deserializeTerrain($this->serializedChunk);
		$entityNBT = igbinary_unserialize($this->serializedEntityNBT);
		$tileNBT = igbinary_unserialize($this->serializedTileNBT);

		$chunkData = new ChunkData(
			$chunk->getSubChunks(),
			$this->populated,
			$entityNBT,
			$tileNBT,
		);

		$provider = $this->provider->deserialize();
		$provider->saveChunk($this->chunkX, $this->chunkZ, $chunkData, $this->dirtyFlags);

		$this->setResult(true);
	}

	public function onCompletion() : void{
		$callback = $this->fetchLocal(self::TLS_KEY_CALLBACK);
		$callback();
	}
}
