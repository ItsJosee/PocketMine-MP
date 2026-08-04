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

namespace pocketmine\command\utils;

use pocketmine\entity\Entity;
use pocketmine\player\Player;
use function array_slice;
use function count;
use function explode;
use function mt_rand;
use function str_contains;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use const PHP_INT_MAX;

final class SelectorParser{

	public static function isSelector(string $input) : bool{
		return $input !== "" && $input[0] === "@";
	}

	/**
	 * Parses a selector string and returns matching entities.
	 *
	 * Supported selectors:
	 * - @a: all online players
	 * - @p: nearest player to the sender
	 * - @r: random player
	 * - @e: all entities (players + living entities)
	 * - @s: the sender themselves (only works if sender is a Player)
	 *
	 * Filters (in brackets):
	 * - [type=player|zombie|skeleton|...]
	 * - [name=PlayerName]
	 * - [limit=N]
	 * - [distance=MaxDistance] (from sender)
	 *
	 * @return Entity[]
	 */
	public static function parse(string $input, ?Player $sender = null) : array{
		$selector = strtolower(substr($input, 1, 1));
		$filters = [];

		$bracketPos = strpos($input, "[");
		if($bracketPos !== false){
			$filterStr = substr($input, $bracketPos + 1, -1);
			$filters = self::parseFilters($filterStr);
		}

		$server = $sender?->getServer();
		if($server === null){
			return [];
		}

		switch($selector){
			case "a":
				$candidates = $server->getOnlinePlayers();
				break;
			case "p":
				if($sender === null){
					return [];
				}
				$nearest = null;
				$nearestDist = PHP_INT_MAX;
				foreach($server->getOnlinePlayers() as $player){
					if($player === $sender){
						continue;
					}
					$dist = $player->getPosition()->distanceSquared($sender->getPosition());
					if($dist < $nearestDist){
						$nearestDist = $dist;
						$nearest = $player;
					}
				}
				$candidates = $nearest !== null ? [$nearest] : [];
				break;
			case "r":
				$players = $server->getOnlinePlayers();
				if(count($players) === 0){
					return [];
				}
				$index = mt_rand(0, count($players) - 1);
				$candidates = [$players[$index]];
				break;
			case "e":
				$candidates = [];
				foreach($server->getWorldManager()->getWorlds() as $world){
					foreach($world->getEntities() as $entity){
						$candidates[] = $entity;
					}
				}
				break;
			case "s":
				if($sender !== null){
					$candidates = [$sender];
				}else{
					$candidates = [];
				}
				break;
			default:
				return [];
		}

		$result = [];
		foreach($candidates as $entity){
			if(self::matchesFilters($entity, $filters, $sender)){
				$result[] = $entity;
			}
		}

		if(isset($filters["limit"])){
			$result = array_slice($result, 0, $filters["limit"]);
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function parseFilters(string $filterStr) : array{
		$filters = [];
		$pairs = explode(",", $filterStr);
		foreach($pairs as $pair){
			$eqPos = strpos($pair, "=");
			if($eqPos === false){
				continue;
			}
			$key = strtolower(trim(substr($pair, 0, $eqPos)));
			$value = trim(substr($pair, $eqPos + 1));
			switch($key){
				case "type":
				case "name":
					$filters[$key] = strtolower($value);
					break;
				case "limit":
				case "distance":
					$filters[$key] = (int) $value;
					break;
			}
		}
		return $filters;
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	private static function matchesFilters(Entity $entity, array $filters, ?Player $sender) : bool{
		if(isset($filters["type"])){
			$type = $filters["type"];
			if($type === "player"){
				if(!($entity instanceof Player)){
					return false;
				}
			}else{
				$entityClass = strtolower($entity::class);
				if(!str_contains($entityClass, $type)){
					return false;
				}
			}
		}

		if(isset($filters["name"])){
			$name = $filters["name"];
			$entityName = strtolower($entity instanceof Player ? $entity->getDisplayName() : $entity->getName());
			if($entityName !== $name){
				return false;
			}
		}

		if(isset($filters["distance"]) && $sender !== null){
			$dist = $entity->getPosition()->distanceSquared($sender->getPosition());
			if($dist > $filters["distance"] * $filters["distance"]){
				return false;
			}
		}

		return true;
	}
}
