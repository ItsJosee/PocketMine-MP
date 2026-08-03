<?php

declare(strict_types=1);

namespace pocketmine\utils;

class ObjectPool{
	private array $pool = [];
	private int $poolSize = 0;
	private int $maxSize;
	private \Closure $factory;
	private \Closure $reset;

	public function __construct(\Closure $factory, \Closure $reset, int $maxSize = 1024){
		$this->factory = $factory;
		$this->reset = $reset;
		$this->maxSize = $maxSize;
	}

	public function get() : object{
		if($this->poolSize > 0){
			$this->poolSize--;
			return array_pop($this->pool);
		}
		return ($this->factory)();
	}

	public function release(object $obj) : void{
		if($this->poolSize < $this->maxSize){
			($this->reset)($obj);
			$this->pool[] = $obj;
			$this->poolSize++;
		}
	}

	public function getSize() : int{
		return $this->poolSize;
	}

	public function clear() : void{
		$this->pool = [];
		$this->poolSize = 0;
	}
}
