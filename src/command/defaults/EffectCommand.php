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
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\StringToEffectParser;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\utils\Limits;
use pocketmine\utils\TextFormat;
use function count;
use function strtolower;

class EffectCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"effect",
			KnownTranslationFactory::pocketmine_command_effect_description(),
			KnownTranslationFactory::commands_effect_usage()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_EFFECT_SELF,
			DefaultPermissionNames::COMMAND_EFFECT_OTHER
		]);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}

		if(SelectorParser::isSelector($args[0])){
			$senderPlayer = $sender instanceof Player ? $sender : null;
			$entities = SelectorParser::parse($args[0], $senderPlayer);
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
			$player = $this->fetchPermittedPlayerTarget($sender, $args[0], DefaultPermissionNames::COMMAND_EFFECT_SELF, DefaultPermissionNames::COMMAND_EFFECT_OTHER);
			if($player === null){
				return true;
			}
			$players = [$player];
		}

		if(strtolower($args[1]) === "clear"){
			if(isset($args[2]) && strtolower($args[2]) === "all"){
				$count = 0;
				foreach($sender->getServer()->getOnlinePlayers() as $p){
					$p->getEffects()->clear();
					$count++;
				}
				$sender->sendMessage("Cleared effects from " . $count . " players.");
				return true;
			}

			foreach($players as $p){
				$p->getEffects()->clear();
			}
			if(count($players) === 1){
				$sender->sendMessage(KnownTranslationFactory::commands_effect_success_removed_all($players[0]->getDisplayName()));
			}else{
				$sender->sendMessage("Cleared effects from " . count($players) . " players.");
			}
			return true;
		}

		$effect = StringToEffectParser::getInstance()->parse($args[1]);
		if($effect === null){
			$sender->sendMessage(KnownTranslationFactory::commands_effect_notFound($args[1])->prefix(TextFormat::RED));
			return true;
		}

		$amplification = 0;
		$infinite = false;

		if(count($args) >= 3){
			if(strtolower($args[2]) === "infinite"){
				$duration = null;
				$infinite = true;
			}else{
				if(($d = $this->getBoundedInt($sender, $args[2], 0, (int) (Limits::INT32_MAX / 20))) === null){
					return false;
				}
				$duration = $d * 20; // ticks
			}
		}else{
			$duration = null;
		}

		if(count($args) >= 4){
			$amplification = $this->getBoundedInt($sender, $args[3], 0, 255);
			if($amplification === null){
				return false;
			}
		}

		$visible = true;
		if(count($args) >= 5){
			$v = strtolower($args[4]);
			if($v === "on" || $v === "true" || $v === "t" || $v === "1"){
				$visible = false;
			}
		}

		foreach($players as $p){
			$effectManager = $p->getEffects();
			if($duration === 0){
				if(!$effectManager->has($effect)){
					continue;
				}
				$effectManager->remove($effect);
			}else{
				$instance = new EffectInstance($effect, $duration, $amplification, $visible, infinite: $infinite);
				$effectManager->add($instance);
			}
		}

		if(count($players) === 1){
			if($duration === 0){
				$sender->sendMessage(KnownTranslationFactory::commands_effect_success_removed($effect->getName(), $players[0]->getDisplayName()));
			}elseif($infinite){
				self::broadcastCommandMessage($sender, KnownTranslationFactory::commands_effect_success_infinite($effect->getName(), (string) $amplification, $players[0]->getDisplayName()));
			}else{
				self::broadcastCommandMessage($sender, KnownTranslationFactory::commands_effect_success($effect->getName(), (string) $amplification, $players[0]->getDisplayName(), (string) ($duration / 20)));
			}
		}else{
			$sender->sendMessage("Applied effect to " . count($players) . " players.");
		}

		return true;
	}
}
