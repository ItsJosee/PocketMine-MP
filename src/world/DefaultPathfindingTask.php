<?php

declare(strict_types=1);

namespace pocketmine\world;

use pmmp\thread\ThreadSafeArray;
use pocketmine\block\RuntimeBlockStateRegistry;

class DefaultPathfindingTask extends PathfindingTask{
	private static ?array $blockedCache = null;

	protected function isBlocked(int $stateId) : bool{
		if(self::$blockedCache === null){
			self::$blockedCache = [];
			$registry = RuntimeBlockStateRegistry::getInstance();
			foreach($registry->getAllKnownStates() as $state){
				self::$blockedCache[$state->getStateId()] = $state->isSolid();
			}
		}
		return self::$blockedCache[$stateId] ?? true;
	}

	protected function getStepCost(int $fromX, int $fromY, int $fromZ, int $toX, int $toY, int $toZ) : float{
		if($toY !== $fromY){
			return 1.5;
		}
		return 1.0;
	}
}
