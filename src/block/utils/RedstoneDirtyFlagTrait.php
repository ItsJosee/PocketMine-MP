<?php

declare(strict_types=1);

namespace pocketmine\block\utils;

trait RedstoneDirtyFlagTrait{
	private bool $redstoneDirty = true;
	private int $lastRedstoneUpdateTick = 0;

	public function isRedstoneDirty() : bool{
		return $this->redstoneDirty;
	}

	public function markRedstoneDirty(int $currentTick) : void{
		if(!$this->redstoneDirty){
			$this->redstoneDirty = true;
			$this->lastRedstoneUpdateTick = $currentTick;
		}
	}

	public function clearRedstoneDirty() : void{
		$this->redstoneDirty = false;
	}

	public function getLastRedstoneUpdateTick() : int{
		return $this->lastRedstoneUpdateTick;
	}

	public function shouldUpdateRedstone(int $currentTick) : bool{
		return $this->redstoneDirty && ($currentTick - $this->lastRedstoneUpdateTick) >= 1;
	}
}
