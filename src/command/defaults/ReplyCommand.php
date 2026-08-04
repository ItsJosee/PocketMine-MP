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
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function count;
use function implode;

class ReplyCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"r",
			"Reply to the last private message",
			"/r <message>"
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_TELL);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0){
			throw new InvalidCommandSyntaxException();
		}

		if(!($sender instanceof Player)){
			$sender->sendMessage(TextFormat::RED . "This command can only be used by players.");
			return true;
		}

		$target = $sender->getLastMessagedFrom();
		if($target === null || !$target->isOnline()){
			$sender->sendMessage(TextFormat::RED . "No player to reply to.");
			return true;
		}

		$message = implode(" ", $args);
		$sender->sendMessage(KnownTranslationFactory::commands_message_display_outgoing($target->getDisplayName(), $message)->prefix(TextFormat::GRAY . TextFormat::ITALIC));
		$target->sendMessage(KnownTranslationFactory::commands_message_display_incoming($sender->getDisplayName(), $message)->prefix(TextFormat::GRAY . TextFormat::ITALIC));
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_message_display_outgoing($target->getDisplayName(), $message), false);

		$target->setLastMessagedFrom($sender);

		return true;
	}
}
