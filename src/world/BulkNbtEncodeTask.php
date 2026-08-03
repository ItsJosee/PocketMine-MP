<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\world;

use pmmp\thread\ThreadSafeArray;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\scheduler\AsyncTask;
use pocketmine\thread\NonThreadSafeValue;

/**
 * Offloads NBT encoding of a batch of pre-built {@link CompoundTag}s to a worker thread.
 *
 * Building the tags (e.g. via {@link \pocketmine\entity\Entity::saveNBT()}) must happen on the main thread because it
 * reads live game state, but the comparatively cheap step of encoding them to Bedrock NBT bytes can be parallelised.
 * This is useful for bulk exports, world backups and other scenarios where many tags are encoded at once.
 *
 * Tags are wrapped in {@link NonThreadSafeValue} (which igbinary-serializes them) so they cross thread boundaries
 * safely; the worker deserializes fresh copies and encodes them via {@link LittleEndianNbtSerializer::writeMultiple()}.
 *
 * The resulting batch bytes are returned via {@link self::getResult()} as an igbinary-serialized string.
 */
final class BulkNbtEncodeTask extends AsyncTask{

	/**
	 * @param CompoundTag[] $tags
	 *
	 * @phpstan-param list<CompoundTag> $tags
	 */
	public function __construct(
		private array $tags
	){
		$wrapped = new ThreadSafeArray();
		foreach($tags as $tag){
			$wrapped[] = new NonThreadSafeValue($tag);
		}
		$this->storeLocal(self::TLS_KEY_TAGS, $wrapped);
	}

	private const TLS_KEY_TAGS = "nbtTags";

	public function onRun() : void{
		/** @var ThreadSafeArray<int, NonThreadSafeValue<CompoundTag>> $wrapped */
		$wrapped = $this->fetchLocal(self::TLS_KEY_TAGS);
		$roots = [];
		foreach($wrapped as $value){
			$roots[] = new \pocketmine\nbt\TreeRoot($value->deserialize());
		}
		try{
			$encoded = (new LittleEndianNbtSerializer())->writeMultiple($roots);
			$this->setResult($encoded);
		}catch(NbtDataException $e){
			$this->setResult(null);
		}
	}
}
