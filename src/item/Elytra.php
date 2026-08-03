<?php

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\inventory\ArmorInventory;
use pocketmine\item\enchantment\ItemEnchantmentTags as EnchantmentTags;

class Elytra extends Armor{

	public const MAX_DURABILITY = 432;

	public function __construct(ItemIdentifier $identifier, string $name){
		parent::__construct(
			$identifier,
			$name,
			new ArmorTypeInfo(0, self::MAX_DURABILITY, ArmorInventory::SLOT_CHEST),
			[EnchantmentTags::ELYTRA]
		);
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	public function isFireProof() : bool{
		return false;
	}
}
