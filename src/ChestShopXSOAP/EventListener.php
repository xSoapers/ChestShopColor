<?php
declare(strict_types=1);
namespace ChestShop;

use onebone\economyapi\EconomyAPI;
use pocketmine\block\Block;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\ItemFactory;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\tile\Chest;

class EventListener implements Listener
{
	private $plugin;
	private $databaseManager;

	public function __construct(ChestShop $plugin, DatabaseManager $databaseManager)
	{
		$this->plugin = $plugin;
		$this->databaseManager = $databaseManager;
	}

	public function onPlayerInteract(PlayerInteractEvent $event)
	{
		$block = $event->getBlock();
		$player = $event->getPlayer();

		switch ($block->getID()) {
			case Block::SIGN_POST:
			case Block::WALL_SIGN:
				if (($shopInfo = $this->databaseManager->selectByCondition([
						"signX" => $block->getX(),
						"signY" => $block->getY(),
						"signZ" => $block->getZ()
					])) === false) return;
				if ($shopInfo['shopOwner'] === $player->getName()) {
					$player->sendMessage("§cTidak Bisa Membeli Barang Anda Sendiri!");
					return;
				}else{
					$event->setCancelled();
				}
				$buyerMoney = EconomyAPI::getInstance()->myMoney($player->getName());
				if ($buyerMoney === false) {
					$player->sendMessage("§eData Anda Di Servee Ini Tidak Ada!");
					return;
				}
				if ($buyerMoney < $shopInfo['price']) {
					$player->sendMessage("§cUang Anda Tidak Cukup!");
					return;
				}
				/** @var Chest $chest */
				$chest = $player->getLevel()->getTile(new Vector3($shopInfo['chestX'], $shopInfo['chestY'], $shopInfo['chestZ']));
				$itemNum = 0;
				$pID = $shopInfo['productID'];
				$pMeta = $shopInfo['productMeta'];
				for ($i = 0; $i < $chest->getInventory()->getSize(); $i++) {
					$item = $chest->getInventory()->getItem($i);
					// use getDamage() method to get metadata of item
					if ($item->getID() === $pID and $item->getDamage() === $pMeta) $itemNum += $item->getCount();
				}
				if ($itemNum < $shopInfo['saleNum']) {
					$player->sendMessage("§aChest Ini Sudah Habis!");
					if (($p = $this->plugin->getServer()->getPlayer($shopInfo['shopOwner'])) !== null) {
						$p->sendMessage("§aChestShop Anda Telah Habis Segera Isiulang: ".ItemFactory::get($pID, $pMeta)->getName());
					}
					return;
				}

				$item = ItemFactory::get((int)$shopInfo['productID'], (int)$shopInfo['productMeta'], (int)$shopInfo['saleNum']);
				$chest->getInventory()->removeItem($item);
				$player->getInventory()->addItem($item);
				$sellerMoney = EconomyAPI::getInstance()->myMoney($shopInfo['shopOwner']);
				if(EconomyAPI::getInstance()->reduceMoney($player->getName(), $shopInfo['price'], false, "ChestShop") === EconomyAPI::RET_SUCCESS and EconomyAPI::getInstance()->addMoney($shopInfo['shopOwner'], $shopInfo['price'], false, "ChestShop") === EconomyAPI::RET_SUCCESS) {
					$player->sendMessage("§bTerima Kasih Telah Membeli {player}");
					if (($p = $this->plugin->getServer()->getPlayer($shopInfo['shopOwner'])) !== null) {
						$p->sendMessage("{$player->getName()} Terbeli ".ItemFactory::get($pID, $pMeta)->getName()." for ".EconomyAPI::getInstance()->getMonetaryUnit().$shopInfo['price']);
					}
				}else{
					$player->getInventory()->removeItem($item);
					$chest->getInventory()->addItem($item);
					EconomyAPI::getInstance()->setMoney($player->getName(), $buyerMoney);
					EconomyAPI::getInstance()->setMoney($shopInfo['shopOwner'], $sellerMoney);
					$player->sendMessage("§cPembelian Gagal!");
				}
				break;

			case Block::CHEST:
				$shopInfo = $this->databaseManager->selectByCondition([
					"chestX" => $block->getX(),
					"chestY" => $block->getY(),
					"chestZ" => $block->getZ()
				]);
				if ($shopInfo !== false and $shopInfo['shopOwner'] !== $player->getName()) {
					$player->sendMessage("Chest Ini Di Protect!");
					$event->setCancelled();
				}
				break;

			default:
				break;
		}
	}

	public function onPlayerBreakBlock(BlockBreakEvent $event)
	{
		$block = $event->getBlock();
		$player = $event->getPlayer();

		switch ($block->getID()) {
			case Block::SIGN_POST:
			case Block::WALL_SIGN:
				$condition = [
					"signX" => $block->getX(),
					"signY" => $block->getY(),
					"signZ" => $block->getZ()
				];
				$shopInfo = $this->databaseManager->selectByCondition($condition);
				if ($shopInfo !== false) {
					if ($shopInfo['shopOwner'] !== $player->getName() and !$player->hasPermission("chestshop.deleteshop")) {
						$player->sendMessage("§cSign Ini Telah Di protected!");
						$event->setCancelled();
					} else {
						$this->databaseManager->deleteByCondition($condition);
						$player->sendMessage("§aMenutup ChestShop Anda");
					}
				}
				break;

			case Block::CHEST:
				$condition = [
					"chestX" => $block->getX(),
					"chestY" => $block->getY(),
					"chestZ" => $block->getZ()
				];
				$shopInfo = $this->databaseManager->selectByCondition($condition);
				if ($shopInfo !== false) {
					if ($shopInfo['shopOwner'] !== $player->getName() and !$player->hasPermission("chestshop.deleteshop")) {
						$player->sendMessage("¶aChest Ini Sudah Di protected!");
						$event->setCancelled();
					} else {
						$this->databaseManager->deleteByCondition($condition);
						$player->sendMessage("§aMenutup ChestShop Anda");
					}
				}
				break;
		}
	}

	public function onSignChange(SignChangeEvent $event)
	{
		$shopOwner = $event->getPlayer()->getName();
		$saleNum = $event->getLine(1);
		$price = $event->getLine(2);
		$productData = explode(":", $event->getLine(3));
		/** @var int|bool $pID */
		$pID = $this->isItem($id = array_shift($productData)) ? (int)$id : false;
		$pMeta = ($meta = array_shift($productData)) ? (int)$meta : 0;

		$sign = $event->getBlock();

		// Check sign format...
		if ($event->getLine(0) !== "") return;
		if (!is_numeric($saleNum) or $saleNum <= 0) return;
		if (!is_numeric($price) or $price < 0) return;
		if ($pID === false) return;
		if (($chest = $this->getSideChest($sign)) === false) return;
		$shops = $this->databaseManager->selectByCondition(["shopOwner" => "'$shopOwner'"]);
		if(is_array($shops) and (count($shops) + 1 > $this->plugin->getMaxPlayerShops($event->getPlayer()))) return;

		$productName = ItemFactory::get($pID, $pMeta)->getName();
		$event->setLine(0, "§d$shopOwner");
		$event->setLine(1, "§bAmount:§a $saleNum");
		$event->setLine(2, "§4Price:§a ".EconomyAPI::getInstance()->getMonetaryUnit().$price);
		$event->setLine(3, "§f$productName");

		$this->databaseManager->registerShop($shopOwner, $saleNum, $price, $pID, $pMeta, $sign, $chest);
	}

	private function getSideChest(Position $pos)
	{
		$block = $pos->getLevel()->getBlock(new Vector3($pos->getX() + 1, $pos->getY(), $pos->getZ()));
		if ($block->getID() === Block::CHEST) return $block;
		$block = $pos->getLevel()->getBlock(new Vector3($pos->getX() - 1, $pos->getY(), $pos->getZ()));
		if ($block->getID() === Block::CHEST) return $block;
		$block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY() - 1, $pos->getZ()));
		if ($block->getID() === Block::CHEST) return $block;
		$block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() + 1));
		if ($block->getID() === Block::CHEST) return $block;
		$block = $pos->getLevel()->getBlock(new Vector3($pos->getX(), $pos->getY(), $pos->getZ() - 1));
		if ($block->getID() === Block::CHEST) return $block;
		return false;
	}

	private function isItem($id)
	{
		return ItemFactory::isRegistered((int) $id);
	}
} 
