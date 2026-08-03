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
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\world\sound\CrossbowLoadSound;
use pocketmine\world\sound\CrossbowShootSound;
use function intdiv;
use function max;

/**
 * Crossbow is a ranged weapon similar to a bow that uses arrows as ammunition. It must be charged (loaded) before it
 * can be fired: press and hold the use button to load it, then press the use button again to fire the loaded arrow.
 *
 * This implementation is based on the Crossbow from Dragonfly (df-mc/dragonfly).
 */
class Crossbow extends Tool implements Releasable{

	private const CHARGE_DURATION = 25;
	private const TAG_CHARGED_ITEM = "chargedItem"; //TAG_Compound

	private ?Item $chargedProjectile = null;

	public function getFuelTime() : int{
		return 300;
	}

	public function getMaxDurability() : int{
		return 464;
	}

	private function getChargeDuration() : int{
		$quickChargeLevel = $this->getEnchantmentLevel(VanillaEnchantments::QUICK_CHARGE());
		return max(5, self::CHARGE_DURATION - (5 * $quickChargeLevel));
	}

	public function canStartUsingItem(Player $player) : bool{
		if($this->isCharged()){
			return true;
		}
		if(!$player->hasFiniteResources()){
			return true; //creative players can charge the crossbow without having arrows in their inventory
		}
		$arrow = VanillaItems::ARROW();
		return $player->getOffHandInventory()->contains($arrow) || $player->getInventory()->contains($arrow);
	}

	public function onReleaseUsing(Player $player, array &$returnedItems) : ItemUseResult{
		if($this->isCharged()){
			return $this->fire($player);
		}

		$diff = $player->getItemUseDuration();
		if($diff < $this->getChargeDuration()){
			return ItemUseResult::FAIL;
		}

		$projectile = $this->findProjectile($player);
		if($projectile === null){
			return ItemUseResult::FAIL;
		}

		$this->chargedProjectile = $projectile;

		if($player->hasFiniteResources()){
			$inventory = $player->getOffHandInventory()->contains($projectile) ? $player->getOffHandInventory() : $player->getInventory();
			$inventory->removeItem($projectile);
		}

		$location = $player->getLocation();
		$location->getWorld()->addSound($location, new CrossbowLoadSound());

		return ItemUseResult::SUCCESS;
	}

	private function findProjectile(Player $player) : ?Item{
		$arrow = VanillaItems::ARROW();
		if($player->getOffHandInventory()->contains($arrow)){
			return $arrow;
		}
		if($player->getInventory()->contains($arrow)){
			return $arrow;
		}
		if(!$player->hasFiniteResources()){
			return $arrow; //creative mode uses a virtual arrow
		}
		return null;
	}

	private function fire(Player $player) : ItemUseResult{
		$location = $player->getLocation();

		$entity = new ArrowEntity(Location::fromObject(
			$player->getEyePos(),
			$player->getWorld(),
			($location->yaw > 180 ? 360 : 0) - $location->yaw,
			-$location->pitch
		), $player, true);
		$entity->setMotion($player->getDirectionVector()->multiply(5.15));

		$infinity = $this->hasEnchantment(VanillaEnchantments::INFINITY());
		if($infinity){
			$entity->setPickupMode(ArrowEntity::PICKUP_CREATIVE);
		}
		if(($powerLevel = $this->getEnchantmentLevel(VanillaEnchantments::POWER())) > 0){
			$entity->setBaseDamage($entity->getBaseDamage() + (($powerLevel + 1) / 2));
		}
		if(($piercingLevel = $this->getEnchantmentLevel(VanillaEnchantments::PIERCING())) > 0){
			$entity->setPiercingLevel($piercingLevel);
		}
		if($this->hasEnchantment(VanillaEnchantments::FLAME())){
			$entity->setOnFire(intdiv($entity->getFireTicks(), 20) + 100);
		}

		if($this->hasEnchantment(VanillaEnchantments::MULTISHOT())){
			for($i = -1; $i <= 1; $i += 2){
				$extraArrow = new ArrowEntity(Location::fromObject(
					$player->getEyePos(),
					$player->getWorld(),
					($location->yaw > 180 ? 360 : 0) - $location->yaw + ($i * 10),
					-$location->pitch
				), $player, true);
				$extraArrow->setMotion($player->getDirectionVector()->multiply(5.15));
				$extraArrow->setPickupMode(ArrowEntity::PICKUP_NONE);

				$extraEv = new ProjectileLaunchEvent($extraArrow);
				$extraEv->call();
				if(!$extraEv->isCancelled()){
					$extraEv->getProjectile()->spawnToAll();
				}
			}
		}

		$ev = new EntityShootBowEvent($player, $this, $entity, 1.0);
		$ev->call();

		$entity = $ev->getProjectile(); //this might have been changed by plugins

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

		$this->chargedProjectile = null;

		if($player->hasFiniteResources()){
			$this->applyDamage(1);
		}

		return ItemUseResult::SUCCESS;
	}

	public function isCharged() : bool{
		return $this->chargedProjectile !== null;
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	protected function deserializeCompoundTag(CompoundTag $tag) : void{
		parent::deserializeCompoundTag($tag);
		$chargedItem = $tag->getCompoundTag(self::TAG_CHARGED_ITEM);
		if($chargedItem !== null){
			$this->chargedProjectile = Item::safeNbtDeserialize($chargedItem, "Crossbow charged item");
		}
	}

	protected function serializeCompoundTag(CompoundTag $tag) : void{
		parent::serializeCompoundTag($tag);
		if($this->chargedProjectile !== null){
			$tag->setTag(self::TAG_CHARGED_ITEM, $this->chargedProjectile->nbtSerialize());
		}else{
			$tag->removeTag(self::TAG_CHARGED_ITEM);
		}
	}
}
