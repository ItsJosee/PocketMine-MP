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

namespace pocketmine\item;

use pocketmine\entity\Location;
use pocketmine\entity\projectile\Arrow as ArrowEntity;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\world\sound\CrossbowShootSound;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use function intdiv;
use function min;
use function json_encode;

/**
 * Represents a crossbow weapon.
 * Implements the charge-and-release mechanic from Dragonfly.
 */
class Crossbow extends Tool implements Releasable{

	private const CHARGE_DURATION = 25; // 1.25 seconds in ticks (20 tps)

	/** @var array|null The charged projectile data */
	private ?array $chargedProjectile = null;

	public function getFuelTime() : int{
		return 300;
	}

	public function getMaxDurability() : int{
		return 464;
	}

	/**
	 * Returns the charge duration, factoring in Quick Charge enchantment.
	 */
	private function getChargeDuration() : int{
		$quickChargeLevel = $this->getEnchantmentLevel(VanillaEnchantments::QUICK_CHARGE());
		// Quick Charge reduces duration by 0.25s (5 ticks) per level
		// Base is 25 ticks (1.25s), so level 3 = 25 - 15 = 10 ticks (0.5s)
		return \max(5, self::CHARGE_DURATION - (5 * $quickChargeLevel));
	}

	public function canStartUsingItem(Player $player) : bool{
		// If already charged, can fire immediately
		if($this->isCharged()){
			return true;
		}
		// Otherwise, need a projectile to load
		return $this->findProjectile($player) !== null;
	}

	/**
	 * Finds a valid projectile in the player's inventory.
	 * Returns the arrow item or null if none found.
	 */
	private function findProjectile(Player $player) : ?Arrow{
		$arrow = VanillaItems::ARROW();

		// Check off-hand first
		if($player->getOffHandInventory()->contains($arrow)){
			return $player->getOffHandInventory()->getItem($player->getOffHandInventory()->first($arrow)) instanceof Arrow ? $player->getOffHandInventory()->getItem($player->getOffHandInventory()->first($arrow)) : $arrow;
		}

		// Check main inventory
		if($player->getInventory()->contains($arrow)){
			return $player->getInventory()->getItem($player->getInventory()->first($arrow)) instanceof Arrow ? $player->getInventory()->getItem($player->getInventory()->first($arrow)) : $arrow;
		}

		return null;
	}

	public function onReleaseUsing(Player $player, array &$returnedItems) : ItemUseResult{
		// If already charged, fire the projectile
		if($this->isCharged()){
			return $this->fire($player);
		}

		// Check if we have a projectile to load
		$projectile = $this->findProjectile($player);
		if($projectile === null){
			$player->sendMessage(TextFormat::RED . "No arrows to load!");
			return ItemUseResult::FAIL;
		}

		// Check charge duration
		$diff = $player->getItemUseDuration();
		if($diff < $this->getChargeDuration()){
			return ItemUseResult::FAIL;
		}

		// Load the crossbow
		$this->chargedProjectile = [
			'type' => 'arrow',
			'enchanted' => $projectile->hasEnchantments(),
		];

		// Consume one arrow from inventory
		if($player->hasFiniteResources()){
			$inventory = $player->getOffHandInventory()->contains($projectile) ? $player->getOffHandInventory() : $player->getInventory();
			$inventory->removeItem($projectile);
		}

		// Play loading sound
		$location = $player->getLocation();
		$location->getWorld()->addSound($location, new CrossbowLoadSound());

		return ItemUseResult::SUCCESS;
	}

	/**
	 * Fires the charged projectile.
	 */
	private function fire(Player $player) : ItemUseResult{
		if(!$this->isCharged()){
			return ItemUseResult::FAIL;
		}

		$location = $player->getLocation();
		$entity = new ArrowEntity(Location::fromObject(
			$player->getEyePos(),
			$player->getWorld(),
			($location->yaw > 180 ? 360 : 0) - $location->yaw,
			-$location->pitch
		), $player, true); // Crossbow arrows are always critical

		$entity->setMotion($player->getDirectionVector()->multiply(5.15)); // Crossbow velocity

		// Apply enchantments
		$infinity = $this->hasEnchantment(VanillaEnchantments::INFINITY());
		if($infinity){
			$entity->setPickupMode(ArrowEntity::PICKUP_CREATIVE);
		}

		// Power enchantment
		if(($powerLevel = $this->getEnchantmentLevel(VanillaEnchantments::POWER())) > 0){
			$entity->setBaseDamage($entity->getBaseDamage() + (($powerLevel + 1) / 2));
		}

		// Flame enchantment
		if($this->hasEnchantment(VanillaEnchantments::FLAME())){
			$entity->setOnFire(intdiv($entity->getFireTicks(), 20) + 100);
		}

		// Multishot: fire 3 projectiles
		$hasMultishot = $this->hasEnchantment(VanillaEnchantments::MULTISHOT());
		if($hasMultishot){
			// Fire two additional arrows at +/-10 degrees offset
			for($i = -1; $i <= 1; $i += 2){
				$extraArrow = new ArrowEntity(Location::fromObject(
					$player->getEyePos(),
					$player->getWorld(),
					($location->yaw > 180 ? 360 : 0) - $location->yaw + ($i * 10),
					-$location->pitch
				), $player, true);
				$extraArrow->setMotion($player->getDirectionVector()->multiply(5.15));
				$extraArrow->setPickupMode(ArrowEntity::PICKUP_NONE); // Extra arrows can't be picked up

				$extraEv = new ProjectileLaunchEvent($extraArrow);
				$extraEv->call();
				if(!$extraEv->isCancelled()){
					$extraEv->getProjectile()->spawnToAll();
				}
			}
		}

		$ev = new EntityShootBowEvent($player, $this, $entity, 1.0);
		$ev->call();

		$entity = $ev->getProjectile();

		if($ev->isCancelled()){
			$entity->flagForDespawn();
			return ItemUseResult::FAIL;
		}

		$entity->setMotion($entity->getMotion()->multiply($ev->getForce()));

		if($entity instanceof Projectile){
			$projectileEv = new ProjectileLaunchEvent($entity);
			$projectileEv->call();
			if($projectileEv->isCancelled()){
				$ev->getProjectile()->flagForDespawn();
				return ItemUseResult::FAIL;
			}

			$ev->getProjectile()->spawnToAll();
			$location->getWorld()->addSound($location, new CrossbowShootSound());
		}else{
			$entity->spawnToAll();
		}

		// Clear charged state
		$this->chargedProjectile = null;

		// Apply durability damage
		if($player->hasFiniteResources()){
			$this->applyDamage(1);
		}

		return ItemUseResult::SUCCESS;
	}

	/**
	 * Returns whether the crossbow is currently charged.
	 */
	public function isCharged() : bool{
		return $this->chargedProjectile !== null;
	}

	/**
	 * Returns the charged projectile data.
	 *
	 * @return array|null
	 */
	public function getChargedProjectile() : ?array{
		return $this->chargedProjectile;
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	protected function getSerializeNbtTags() : array{
		$tags = parent::getSerializeNbtTags();
		if($this->chargedProjectile !== null){
			$tags['ChargedProjectile'] = $this->chargedProjectile;
		}
		return $tags;
	}

	protected function deserializeInternal(array $tag) : void{
		parent::deserializeInternal($tag);
		if(isset($tag['ChargedProjectile'])){
			$this->chargedProjectile = $tag['ChargedProjectile'];
		}
	}
}
