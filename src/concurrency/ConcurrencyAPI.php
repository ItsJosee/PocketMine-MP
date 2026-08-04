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

namespace pocketmine\concurrency;

use pmmp\thread\ThreadSafe;
use pocketmine\Server;
use function igbinary_serialize;
use function igbinary_unserialize;

/**
 * A snapshot of an arbitrary value, captured at construction time and made safe to read from any thread.
 *
 * The value is igbinary-serialized on capture (main thread) and deserialized fresh on each {@link self::get()} call
 * (worker thread), so workers never share mutable references with the main thread. This mirrors the established
 * {@link \pocketmine\thread\NonThreadSafeValue} pattern but offers a simpler read API for plugin-facing code.
 *
 * Intended for passing small, immutable snapshots to async work — e.g. a list of entity positions, a chunk's
 * block-state summary, configuration data. Avoid using it for large or frequently-changing state, since each
 * {@link self::get()} pays a deserialization cost.
 */
final class ThreadSafeSnapshot extends ThreadSafe{

	private string $serialized;

	public function __construct(mixed $value){
		$this->serialized = igbinary_serialize($value);
	}

	public function get() : mixed{
		return igbinary_unserialize($this->serialized);
	}
}

/**
 * Schedules a task to run on the main thread for one or more world chunks ("region") only when those chunks are
 * loaded, deferring work that touches chunk-local game state until it is safe to do so.
 *
 * A RegionScheduler callback runs ON THE MAIN THREAD inside the server's tick loop, and is only invoked once the
 * target chunk is loaded. This is the safe channel for work that must mutate World/Entity/Block state but should
 * wait for chunk availability (e.g. post-generation hooks, deferred block placement). It complements async work:
 * heavy CPU runs on a worker via a subclass of {@link \pocketmine\scheduler\AsyncTask}, then the result is applied
 * to the world via a RegionScheduler callback.
 */
final class RegionScheduler{

	private function __construct(
		private Server $server
	){}

	public static function forServer(Server $server) : self{
		return new self($server);
	}

	/**
	 * Runs $callback on the main thread as soon as the chunk at ($chunkX, $chunkZ) is loaded, or immediately if it
	 * is already loaded. The callback receives the chunk's World and coordinates. If the world is unloaded before
	 * the chunk loads, the callback is never invoked.
	 *
	 * @param \Closure(\pocketmine\world\World, int, int): void $callback
	 */
	public function runOnChunkLoaded(string $worldFolderName, int $chunkX, int $chunkZ, \Closure $callback) : void{
		$world = $this->server->getWorldManager()->getWorldByName($worldFolderName);
		if($world === null){
			return;
		}
		if($world->isChunkLoaded($chunkX, $chunkZ)){
			$callback($world, $chunkX, $chunkZ);
			return;
		}
		$world->orderChunkPopulation($chunkX, $chunkZ, null)->onCompletion(
			static function() use ($callback, $world, $chunkX, $chunkZ) : void{
				if(!$world->isLoaded()){
					return;
				}
				$callback($world, $chunkX, $chunkZ);
			},
			static function() : void{
				//NOOP: world unloaded or generation failed
			}
		);
	}
}
