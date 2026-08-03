<?php

declare(strict_types=1);

namespace pocketmine\scheduler;

use pocketmine\plugin\Plugin;

class AsyncPluginTickTask extends AsyncTask{
	private const TLS_KEY_CALLBACK = "callback";

	private string $pluginClass;
	private int $currentTick;

	public function __construct(Plugin $plugin, int $currentTick, \Closure $asyncWork, \Closure $onCompletion){
		$this->pluginClass = get_class($plugin);
		$this->currentTick = $currentTick;
		$this->storeLocal("asyncWork", $asyncWork);
		$this->storeLocal(self::TLS_KEY_CALLBACK, $onCompletion);
	}

	public function onRun() : void{
		$asyncWork = $this->fetchLocal("asyncWork");
		$result = $asyncWork($this->currentTick);
		$this->setResult($result);
	}

	public function onCompletion() : void{
		$callback = $this->fetchLocal(self::TLS_KEY_CALLBACK);
		$callback($this->getResult());
	}
}
