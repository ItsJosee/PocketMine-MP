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

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\command\utils\SelectorParser;
use pocketmine\entity\Attribute;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Limits;
use pocketmine\utils\TextFormat;
use function abs;
use function count;
use function str_ends_with;
use function substr;

class XpCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"xp",
			KnownTranslationFactory::pocketmine_command_xp_description(),
			KnownTranslationFactory::pocketmine_command_xp_usage()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_XP_SELF,
			DefaultPermissionNames::COMMAND_XP_OTHER
		]);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1){
			throw new InvalidCommandSyntaxException();
		}

		$xpArg = $args[0];
		$targetArg = $args[1] ?? null;

		if(SelectorParser::isSelector($xpArg)){
			if(count($args) < 2){
				throw new InvalidCommandSyntaxException();
			}
			$targetArg = $xpArg;
			$xpArg = $args[1];
		}

		if(SelectorParser::isSelector($targetArg ?? "")){
			$senderPlayer = $sender instanceof Player ? $sender : null;
			$entities = SelectorParser::parse($targetArg, $senderPlayer);
			$players = [];
			foreach($entities as $entity){
				if($entity instanceof Player){
					$players[] = $entity;
				}
			}
			if(count($players) === 0){
				$sender->sendMessage(TextFormat::RED . "No matching players found.");
				return true;
			}
		}else{
			$player = $this->fetchPermittedPlayerTarget($sender, $targetArg, DefaultPermissionNames::COMMAND_XP_SELF, DefaultPermissionNames::COMMAND_XP_OTHER);
			if($player === null){
				return true;
			}
			$players = [$player];
		}

		$count = 0;
		foreach($players as $p){
			$xpManager = $p->getXpManager();
			if(str_ends_with($xpArg, "L")){
				$xpLevelAttr = $p->getAttributeMap()->get(Attribute::EXPERIENCE_LEVEL) ?? throw new AssumptionFailedError();
				$maxXpLevel = (int) $xpLevelAttr->getMaxValue();
				$currentXpLevel = $xpManager->getXpLevel();
				$xpLevels = $this->getInteger($sender, substr($xpArg, 0, -1), -$currentXpLevel, $maxXpLevel - $currentXpLevel);
				if($xpLevels >= 0){
					$xpManager->addXpLevels($xpLevels, false);
				}else{
					$xpLevels = abs($xpLevels);
					$xpManager->subtractXpLevels($xpLevels);
				}
			}else{
				$xp = $this->getInteger($sender, $xpArg, max: Limits::INT32_MAX);
				if($xp >= 0){
					$xpManager->addXp($xp, false);
				}
			}
			$count++;
		}

		if($count === 1){
			if(str_ends_with($xpArg, "L")){
				$sender->sendMessage(KnownTranslationFactory::commands_xp_success_levels(substr($xpArg, 0, -1), $players[0]->getName()));
			}else{
				$sender->sendMessage(KnownTranslationFactory::commands_xp_success($xpArg, $players[0]->getName()));
			}
		}else{
			$sender->sendMessage("Given " . $xpArg . " to " . $count . " players.");
		}

		return true;
	}
}
