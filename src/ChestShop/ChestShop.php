<?php
declare(strict_types=1);
namespace ChestShop;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\ItemIds;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;

class ChestShop extends PluginBase
{
	public function onEnable()
	{
		$this->getServer()->getPluginManager()->registerEvents(new EventListener($this, new DatabaseManager($this->getDataFolder() . 'ChestShop.sqlite3')), $this);
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
	{
		switch ($command->getName()) {
			case "id":
				$name = array_shift($args);
				if(empty($name))
					return false;
				$constants = array_keys((new \ReflectionClass(ItemIds::class))->getConstants());
				foreach ($constants as $constant) {
			                if (stripos($constant, (string)$name) !== false) {
						$id = constant(ItemIds::class."::$constant");
						$constant = str_replace("_", " ", $constant);
						$sender->sendMessage("ID:$id $constant");
					}
				}
				return true;

			default:
				return false;
		}
	}

	/**
	 * Get the maximum number of shops a player can create
	 *
	 * @param Player $player
	 *
	 * @return int
	 */
	public function getMaxPlayerShops(Player $player) : int {
		if($player->hasPermission("chestshop.makeshop.unlimited"))
			return PHP_INT_MAX;
		/** @var Permission[] $perms */
		$perms = array_merge(PermissionManager::getInstance()->getDefaultPermissions($player->isOp()), $player->getEffectivePermissions());
		$perms = array_filter($perms, function($name) {
			return (substr($name, 0, 19) === "chestshop.makeshop.");
		}, ARRAY_FILTER_USE_KEY);
		if(count($perms) === 0)
			return 0;
		krsort($perms, SORT_FLAG_CASE | SORT_NATURAL);
		foreach($perms as $name => $perm) {
			$maxPlots = substr($name, 19);
			if(is_numeric($maxPlots)) {
				return (int) $maxPlots;
			}
		}
		return 0;
	}
}
