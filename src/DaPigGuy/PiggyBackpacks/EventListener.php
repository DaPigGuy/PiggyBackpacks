<?php

declare(strict_types=1);

namespace DaPigGuy\PiggyBackpacks;

use muqsit\invmenu\inventory\InvMenuInventory;
use muqsit\invmenu\InvMenu;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\Player;
use pocketmine\utils\TextFormat;
use pocketmine\utils\UUID;

class EventListener implements Listener
{
    /** @var PiggyBackpacks */
    public $plugin;

    public function __construct(PiggyBackpacks $plugin)
    {
        $this->plugin = $plugin;
    }

    public function onInteract(PlayerInteractEvent $event): void
    {
        $player = $event->getPlayer();
        $item = $player->getInventory()->getItemInHand();
        if ($item->getId() === Item::CHEST && ($size = $item->getNamedTagEntry("Size")) !== null) {
            $event->setCancelled();
            if ($item->getCount() > 1) {
                $player->sendTip(TextFormat::RED . "Backpacks can not be opened while stacked.");
                return;
            }
            if ($item->getNamedTagEntry("Contents") === null) $item->setNamedTagEntry(new ListTag("Contents"));
            if ($item->getNamedTagEntry("UUID") === null) {
                $item->setNamedTagEntry(new StringTag("UUID", UUID::fromRandom()->toString()));
                $item->setNamedTagEntry(new ListTag("Creator", [
                    new StringTag("Name", $player->getName()),
                    new StringTag("XUID", $player->getXuid())
                ]));
                $item->setNamedTagEntry(new IntTag("Timestamp", time()));
            }

            $backpack = InvMenu::create($size->getValue() > 27 ? InvMenu::TYPE_DOUBLE_CHEST : InvMenu::TYPE_CHEST);
            $backpack->setName($item->getName());
            $backpack->getInventory()->setContents(array_map(function (CompoundTag $serializedItem): Item {
                return Item::nbtDeserialize($serializedItem);
            }, ($item->getNamedTagEntry("Contents") ?? new ListTag())->getValue()));
            for ($i = $backpack->getInventory()->getSize() - 1; $i >= $size->getValue(); $i--) {
                $slot = Item::get(Item::INVISIBLE_BEDROCK)->setCustomName(" ");
                $slot->setNamedTagEntry(new ByteTag("BackpackSlot", 1));
                $backpack->getInventory()->setItem($i, $slot);
            }

            $updateClosure = (function (Player $player, $secondArgument) use ($backpack) {
                $backpackItem = $player->getInventory()->getItemInHand();
                if ($secondArgument instanceof InvMenuInventory) $backpackItem->removeNamedTagEntry("Opened");
                $backpackItem->setNamedTagEntry(new ListTag("Contents", array_map(function (Item $item) {
                    return $item->nbtSerialize();
                }, array_filter($backpack->getInventory()->getContents(true), function (Item $item) {
                    return $item->getId() !== Item::INVISIBLE_BEDROCK || $item->getNamedTagEntry("BackpackSlot") === null;
                }))));
                $player->getInventory()->setItemInHand($backpackItem);
                return true;
            });

            $backpack->setListener($updateClosure);
            $backpack->setInventoryCloseListener($updateClosure);

            $item->setNamedTagEntry(new ByteTag("Opened", 1));
            $player->getInventory()->setItemInHand($item);

            $backpack->send($player);
        }
    }

    public function onInventoryTransaction(InventoryTransactionEvent $event): void
    {
        $transaction = $event->getTransaction();
        $actions = $transaction->getActions();
        foreach ($actions as $action) {
            if (
                ($action->getSourceItem()->getId() === Item::CHEST && $action->getSourceItem()->getNamedTagEntry("Opened") !== null) ||
                ($action->getTargetItem()->getId() === Item::INVISIBLE_BEDROCK && $action->getTargetItem()->getNamedTagEntry("BackpackSlot") !== null)
            ) {
                $event->setCancelled();
            }
        }
    }
}