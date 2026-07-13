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
use pocketmine\scheduler\AsyncTask;
use pocketmine\world\format\Chunk;
use function array_reverse;
use function count;
use function igbinary_serialize;
use function igbinary_unserialize;
use function intdiv;
use function spl_object_id;

/**
 * Builds an immutable, thread-safe snapshot of block state IDs for a rectangular region of chunks.
 *
 * The snapshot is built on the main thread (where World/Chunk are accessible) and then passed to an AsyncTask,
 * where it can be queried without touching any non-thread-safe game state. This is the foundation for offloading
 * pathfinding, line-of-sight checks and similar CPU-bound spatial queries to worker threads.
 *
 * The snapshot is keyed by chunk hash and stores each chunk's block states as an igbinary-serialized flat array
 * indexed by SubChunk-relative Y packed coordinates. Querying happens via {@link PathfindingSnapshot::getStateId()}.
 */
final class PathfindingSnapshot{

	/**
	 * @param int[] $chunkHashes list of chunk hashes to include
	 * @param \Closure(int $chunkX, int $chunkZ): ?Chunk $chunkProvider returns the chunk or null if not loaded
	 *
	 * @phpstan-param list<int> $chunkHashes
	 * @phpstan-param \Closure(int, int): (?Chunk) $chunkProvider
	 */
	public static function buildFromChunks(array $chunkHashes, \Closure $chunkProvider) : ThreadSafeArray{
		$snapshot = new ThreadSafeArray();
		foreach($chunkHashes as $hash){
			World::getXZ($hash, $chunkX, $chunkZ);
			$chunk = $chunkProvider($chunkX, $chunkZ);
			if($chunk === null){
				continue;
			}
			$states = [];
			foreach($chunk->getSubChunks() as $subY => $subChunk){
				$baseY = ($subY + Chunk::MIN_SUBCHUNK_INDEX) << Chunk::COORD_BIT_SIZE;
				for($x = 0; $x < Chunk::EDGE_LENGTH; ++$x){
					for($z = 0; $z < Chunk::EDGE_LENGTH; ++$z){
						for($y = 0; $y < Chunk::EDGE_LENGTH; ++$y){
							$states[$baseY + $y][$x][$z] = $subChunk->getBlockStateId($x, $y, $z);
						}
					}
				}
			}
			$snapshot[$hash] = igbinary_serialize($states) ?? "";
		}
		return $snapshot;
	}
}

/**
 * Base AsyncTask for A* pathfinding over a {@link PathfindingSnapshot}.
 *
 * Subclasses define the cost model by overriding {@link self::isBlocked()} and optionally {@link self::getStepCost()}.
 * The computed path (list of [x, y, z] waypoints from start to target, inclusive) is stored via {@link AsyncTask::setResult()}
 * as an igbinary-serialized array, and surfaced to {@link self::onCompletion()} on the main thread where game state
 * may be mutated safely (e.g. to steer an entity along the path).
 *
 * The task never accesses World/Entity/Block during {@link self::onRun()} — only the immutable snapshot — so it is
 * safe to run on any worker thread.
 */
abstract class PathfindingTask extends AsyncTask{

	private int $startX;
	private int $startY;
	private int $startZ;
	private int $targetX;
	private int $targetY;
	private int $targetZ;
	private int $maxIterations;

	/** @phpstan-var ThreadSafeArray<int, string> */
	private ThreadSafeArray $snapshot;
	/** @phpstan-var WeakMap<object, PathfindingTask>|null */
	private static ?\WeakMap $completionMap = null;

	/**
	 * @phpstan-param ThreadSafeArray<int, string> $snapshot
	 */
	public function __construct(int $startX, int $startY, int $startZ, int $targetX, int $targetY, int $targetZ, ThreadSafeArray $snapshot, int $maxIterations = 20000){
		$this->startX = $startX;
		$this->startY = $startY;
		$this->startZ = $startZ;
		$this->targetX = $targetX;
		$this->targetY = $targetY;
		$this->targetZ = $targetZ;
		$this->snapshot = $snapshot;
		$this->maxIterations = $maxIterations;
	}

	/**
	 * Returns true if a block with the given state ID cannot be traversed (solid / obstruction).
	 * Override to customise the cost model. Called on the worker thread.
	 */
	abstract protected function isBlocked(int $stateId) : bool;

	/**
	 * Step cost between two adjacent cells. Default: 1 (orthogonal), sqrt(2)-ish for diagonal skipped here.
	 * Override for weighted pathfinding (e.g. avoid certain surfaces). Called on the worker thread.
	 */
	protected function getStepCost(int $fromX, int $fromY, int $fromZ, int $toX, int $toY, int $toZ) : float{
		return 1.0;
	}

	final public function onRun() : void{
		$path = $this->findPath();
		$this->setResult($path);
	}

	/**
	 * @return list<array{int, int, int}>|null
	 */
	private function findPath() : ?array{
		$this->decodedStates = [];
		foreach($this->snapshot as $chunkHash => $serialized){
			if($serialized !== ""){
				$this->decodedStates[$chunkHash] = igbinary_unserialize($serialized);
			}
		}

		$startKey = $this->key($this->startX, $this->startY, $this->startZ);
		$targetKey = $this->key($this->targetX, $this->targetY, $this->targetZ);

		/** @var array<int, array{int, int, int}> $cameFrom */
		$cameFrom = [];
		/** @var array<int, float> $gScore */
		$gScore = [$startKey => 0.0];
		/** @var array<int, float> $fScore */
		$fScore = [$startKey => $this->heuristic($this->startX, $this->startY, $this->startZ)];

		$open = $fScore;
		$iterations = 0;

		while(count($open) > 0){
			if(++$iterations > $this->maxIterations){
				return null;
			}
			$currentKey = null;
			$currentF = PHP_INT_MAX;
			foreach($open as $k => $f){
				if($f < $currentF){
					$currentF = $f;
					$currentKey = $k;
				}
			}
			if($currentKey === null){
				break;
			}
			if($currentKey === $targetKey){
				return $this->reconstruct($cameFrom, $currentKey);
			}
			unset($open[$currentKey]);

			$this->unpackKey($currentKey, $cx, $cy, $cz);

			foreach(self::NEIGHBOURS as [$dx, $dy, $dz]){
				$nx = $cx + $dx;
				$ny = $cy + $dy;
				$nz = $cz + $dz;
				$stateId = $this->getStateId($nx, $ny, $nz);
				if($stateId === null || $this->isBlocked($stateId)){
					continue;
				}
				$nKey = $this->key($nx, $ny, $nz);
				$tentativeG = $gScore[$currentKey] + $this->getStepCost($cx, $cy, $cz, $nx, $ny, $nz);
				if(!isset($gScore[$nKey]) || $tentativeG < $gScore[$nKey]){
					$cameFrom[$nKey] = [$cx, $cy, $cz];
					$gScore[$nKey] = $tentativeG;
					$f = $tentativeG + $this->heuristic($nx, $ny, $nz);
					$fScore[$nKey] = $f;
					$open[$nKey] = $f;
				}
			}
		}

		return null;
	}

	/**
	 * @var array<int, mixed>|null
	 * @phpstan-var array<int, array<int, array<int, array<int, int>>>>|null
	 */
	private ?array $decodedStates = null;

	private function getStateId(int $x, int $y, int $z) : ?int{
		$chunkHash = World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		$states = $this->decodedStates[$chunkHash] ?? null;
		if($states === null){
			return null;
		}
		$localX = $x & Chunk::COORD_MASK;
		$localZ = $z & Chunk::COORD_MASK;
		return $states[$y][$localX][$localZ] ?? null;
	}

	/** @var list<array{int, int, int}> */
	private const NEIGHBOURS = [
		[1, 0, 0], [-1, 0, 0],
		[0, 1, 0], [0, -1, 0],
		[0, 0, 1], [0, 0, -1],
	];

	private function heuristic(int $x, int $y, int $z) : float{
		$dx = abs($x - $this->targetX);
		$dy = abs($y - $this->targetY);
		$dz = abs($z - $this->targetZ);
		return (float) ($dx + $dy + $dz);
	}

	private function key(int $x, int $y, int $z) : int{
		return ($x & 0x3FFFFFF) | (($y & 0xFFF) << 26) | (($z & 0x3FFFFFF) << 38);
	}

	private function unpackKey(int $key, int &$x, int &$y, int &$z) : void{
		$x = $key & 0x3FFFFFF;
		$y = ($key >> 26) & 0xFFF;
		$z = ($key >> 38) & 0x3FFFFFF;
	}

	/**
	 * @param array<int, array{int, int, int}> $cameFrom
	 *
	 * @return list<array{int, int, int}>
	 */
	private function reconstruct(array $cameFrom, int $currentKey) : array{
		$path = [];
		while(isset($cameFrom[$currentKey])){
			$this->unpackKey($currentKey, $x, $y, $z);
			$path[] = [$x, $y, $z];
			$currentKey = $this->key($cameFrom[$currentKey][0], $cameFrom[$currentKey][1], $cameFrom[$currentKey][2]);
		}
		$this->unpackKey($currentKey, $x, $y, $z);
		$path[] = [$x, $y, $z];
		return array_reverse($path);
	}
}