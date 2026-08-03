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

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\timings\TimingsHandler;
use function count;
use function is_numeric;
use function max;

/**
 * Built-in profiler command: reports the top CPU and memory hotpaths from the current timings session.
 *
 * This complements {@see TimingsCommand} (which produces a full timings report file) by giving an immediate,
 * in-console summary of which subsystems, handlers or plugins are currently consuming the most CPU and allocating
 * the most memory, so operators can detect hotspots without parsing the full report.
 *
 * Requires timings to be enabled first via `/timings on`. An optional numeric argument sets the number of entries
 * per list (default 10).
 */
class ProfileCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"profile",
			KnownTranslationFactory::pocketmine_command_timings_description(),
			"/profile [topN]"
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_TIMINGS);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(!TimingsHandler::isEnabled()){
			$sender->sendMessage("Timings are disabled. Run /timings on first to start collecting profile data.");
			return true;
		}

		$limit = 10;
		if(count($args) >= 1 && is_numeric($args[0])){
			$limit = max(1, (int) $args[0]);
		}

		$hotpaths = TimingsHandler::getHotpaths($limit);

		$sender->sendMessage("---- Top CPU hotpaths ----");
		if(count($hotpaths["time"]) === 0){
			$sender->sendMessage("(no samples yet; wait a few seconds after enabling timings)");
		}else{
			foreach($hotpaths["time"] as $line){
				$sender->sendMessage("  " . $line);
			}
		}

		$sender->sendMessage("---- Top memory allocators ----");
		if(count($hotpaths["memory"]) === 0){
			$sender->sendMessage("(no samples yet)");
		}else{
			foreach($hotpaths["memory"] as $line){
				$sender->sendMessage("  " . $line);
			}
		}

		Command::broadcastCommandMessage($sender, "Profile summary displayed");

		return true;
	}
}
