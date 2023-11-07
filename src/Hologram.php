<?php
/*
 * Copyright (C) PrimeGames - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */

declare(strict_types=1);

namespace nasiridrishi\primehologram;

use JsonException;
use pocketmine\entity\Entity;
use pocketmine\entity\Skin;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\types\AbilitiesData;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\FloatMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\UpdateAbilitiesPacket;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use pocketmine\world\World;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use function str_repeat;

class Hologram {

    /** @var SkinData|null */
    private static ?SkinData $skinData = null;

    protected int $entityId;
    protected UuidInterface $uuid;

    /** @var Player[] */
    private array $spawnedTo = [];

    /** @var RemoveActorPacket */
    private RemoveActorPacket $despawnPacket;

    /**
     * @throws JsonException
     */
    public static function new(Position $position, string $lines): self {
        return new self($position, $lines);
    }

    /**
     * @throws JsonException
     */
    public function __construct(private Position $position, private string $lines) {

        $this->entityId = Entity::nextRuntimeId();
        $this->uuid = Uuid::uuid4();

        $this->despawnPacket = new RemoveActorPacket();
        $this->despawnPacket->actorUniqueId = $this->entityId;

        if(self::$skinData === null) {
            self::$skinData = TypeConverter::getInstance()->getSkinAdapter()->toSkinData(new Skin("Standard_Custom", str_repeat("\x00", 8192)));
        }
    }

    public function getId(): int {
        return $this->entityId;
    }

    public function getWorld(): World {
        return $this->position->getWorld();
    }

    /**
     * @return Position
     */
    public function getPosition(): Position {
        return $this->position;
    }

    public function doUpdate(): void {
        foreach($this->spawnedTo as $player) {
            $this->updateFor($player);
        }
    }

    /**
     * Update the hologram for a player.
     *
     * @param Player $player
     */
    public function updateFor(Player $player): void {
        if(!isset($this->spawnedTo[spl_object_id($player)]) or !$player->isOnline()) {
            return;
        }
        $nameTag = TextFormat::colorize($this->lines);
        if(PrimeHologram::getInstance()->getPlaceHolderHook() != null){
            $nameTag = PrimeHologram::getInstance()->getPlaceHolderHook()->setPlaceHolders($nameTag, $player);
        }
        $nameTag = str_replace("%player%", $player->getName(), $nameTag);
        //split $nameTag lines into array and check if there is an empty line
        $pk = new SetActorDataPacket();
        $pk->syncedProperties = new PropertySyncData([], []);
        $pk->actorRuntimeId = $this->entityId;
        $pk->metadata = [
            EntityMetadataProperties::NAMETAG => new StringMetadataProperty($nameTag)];
        $player->getNetworkSession()->senddataPacket($pk);
    }

    /**
     * Spawn the hologram to a player.
     *
     * @param Player $player
     */
    public function spawnTo(Player $player): void {
        if(isset($this->spawnedTo[spl_object_id($player)]) or !$player->isOnline()) {
            return;
        }

        $text = TextFormat::colorize($this->lines);
        $text = str_replace("%player%", $player->getName(), $text);
        if(PrimeHologram::getInstance()->getPlaceHolderHook() !== null){
            $text = PrimeHologram::getInstance()->getPlaceHolderHook()->setPlaceHolders($text, $player);
        }
        $p = [];

        $p[] = $pk = new PlayerListPacket();
        $pk->type = PlayerListPacket::TYPE_ADD;
        $pk->entries = [
            PlayerListEntry::createAdditionEntry($this->uuid, $this->entityId, $text, self::$skinData)];

        $p[] = $pk = new AddPlayerPacket();
        $pk->uuid = $this->uuid;
        $pk->username = $text;
        $pk->syncedProperties = new PropertySyncData([], []);
        $pk->actorRuntimeId = $this->entityId;
        $pk->abilitiesPacket = UpdateAbilitiesPacket::create(new AbilitiesData(0, 0, $this->entityId, []));//UpdateAbilitiesPacket::create(0, 0, $this->entityId, []);
        $pk->position = $this->position;
        $pk->gameMode = 0;
        $pk->item = ItemStackWrapper::legacy(ItemStack::null());

        $flags = (1 << EntityMetadataFlags::IMMOBILE);
        $pk->metadata = [
            EntityMetadataProperties::FLAGS => new LongMetadataProperty($flags),
            EntityMetadataProperties::SCALE => new FloatMetadataProperty(0.01)
            //zero causes problems on debug builds
        ];

        $p[] = $pk = new PlayerListPacket();
        $pk->type = PlayerListPacket::TYPE_REMOVE;
        $pk->entries = [PlayerListEntry::createRemovalEntry($this->uuid)];

        foreach($p as $pk) {
            $player->getNetworkSession()->sendDataPacket($pk);
        }

        $this->spawnedTo[spl_object_id($player)] = $player;
    }

    /**
     * Despawn the hologram from a player.
     *
     * @param Player $player
     */
    public function despawnFrom(Player $player): void {
        if(!isset($this->spawnedTo[spl_object_id($player)]) or !$player->isOnline()) {
            return;
        }
        $player->getNetworkSession()->sendDataPacket(RemoveActorPacket::create($this->entityId));

        unset($this->spawnedTo[spl_object_id($player)]);
    }

    public function deSpawnFromAll(): void {
        foreach(Server::getInstance()->getOnlinePlayers() as $player) {
            $this->despawnFrom($player);
        }
    }

}
