<?php

namespace App\Entity;

use App\Contract\LockableInterface;
use App\Enum\Platform;
use Bundles\PasswordLoginBundle\Entity\AbstractUser;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(
 *    indexes={
 *        @ORM\Index(name="platform_idx", columns={"platform"})
 *     }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\UserRepository")
 * @ORM\ChangeTrackingPolicy("DEFERRED_EXPLICIT")
 *
 * Parent class has callbacks
 * @ORM\HasLifecycleCallbacks()
 */
class User extends AbstractUser implements LockableInterface
{
    // Basically, cannot update these users
    const BUG_BOUNTY_USERS = [
        'root@example.com',
        'admin@example.com',
        'api@example.com',
        'user@example.com',
    ];

    /**
     * @ORM\Column(type="string", length=5)
     */
    private $platform;

    /**
     * @ORM\Column(type="string", length=10)
     */
    private $locale;

    /**
     * @ORM\Column(type="string", length=32)
     */
    private $timezone;

    /**
     * @ORM\Column(type="boolean", options={"default" : 0})
     */
    private $isDeveloper = false;

    /**
     * @ORM\Column(type="boolean", options={"default" : 0})
     */
    private $isRoot = false;

    /**
     * @ORM\Column(type="boolean", options={"default" : 0})
     */
    private $isPegassApi = false;

    /**
     * @var Volunteer|null
     *
     * @ORM\OneToOne(targetEntity="App\Entity\Volunteer", inversedBy="user")
     */
    private $volunteer;

    /**
     * @ORM\ManyToMany(targetEntity="App\Entity\Structure", inversedBy="users")
     * @ORM\OrderBy({"enabled" = "DESC", "externalId" = "ASC"})
     */
    private $structures;

    /**
     * @ORM\Column(type="boolean", options={"default" : 0})
     */
    private $locked = false;

    /**
     * @ORM\ManyToMany(targetEntity=Badge::class)
     */
    private $favoriteBadges;

    public function __construct()
    {
        parent::__construct();

        $this->structures     = new ArrayCollection();
        $this->favoriteBadges = new ArrayCollection();
    }

    public function getPlatform()
    {
        return $this->platform;
    }

    public function setPlatform($platform)
    {
        $this->platform = $platform;

        return $this;
    }

    public function getLocale() : ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale) : self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTimezone() : string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone)
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getExternalId() : ?string
    {
        return $this->volunteer ? $this->volunteer->getExternalId() : null;
    }

    public function isDeveloper() : bool
    {
        return $this->isDeveloper;
    }

    public function setIsDeveloper(bool $isDeveloper) : self
    {
        $this->isDeveloper = $isDeveloper;

        return $this;
    }

    public function isRoot() : bool
    {
        return $this->isRoot;
    }

    public function setIsRoot(bool $isRoot) : User
    {
        $this->isRoot = $isRoot;

        return $this;
    }

    public function isPegassApi() : bool
    {
        return Platform::FR === $this->platform && $this->isPegassApi;
    }

    public function setIsPegassApi(bool $isPegassApi) : User
    {
        $this->isPegassApi = $isPegassApi;

        return $this;
    }

    public function canGrantPegassApi() : bool
    {
        return $this->isAdmin && Platform::FR === $this->platform;
    }

    public function getVolunteer() : ?Volunteer
    {
        return $this->volunteer;
    }

    public function setVolunteer(?Volunteer $volunteer) : self
    {
        $this->volunteer = $volunteer;

        if ($volunteer) {
            $volunteer->setUser($this);
        }

        return $this;
    }

    public function getStructures(bool $onlyEnabled = true) : Collection
    {
        $structures = $this->structures;

        if ($onlyEnabled) {
            $structures = $this->getEnabledStructures();
        }

        return $structures->filter(function (Structure $structure) {
            return $structure->getPlatform() === $this->platform;
        });
    }

    public function getStructuresAsList() : array
    {
        $structures = [];
        foreach ($this->getStructures() as $structure) {
            /** @var Structure $structure */
            $structures[$structure->getExternalId()] = $structure->getName();
        }

        return $structures;
    }

    public function getStructuresShortcuts() : array
    {
        $shortcuts = [];
        foreach ($this->getStructures() as $structure) {
            /** @var Structure $structure */
            if ($structure->getShortcut()) {
                $shortcuts[$structure->getId()] = $structure->getShortcut();
            }
        }

        return $shortcuts;
    }

    public function getEnabledStructures() : Collection
    {
        return $this->structures->filter(function (Structure $structure) {
            return $structure->isEnabled();
        });
    }

    public function addStructure(Structure $structure) : self
    {
        if (!$this->structures->contains($structure)) {
            $this->structures[] = $structure;
        }

        return $this;
    }

    public function removeStructure(Structure $structure) : self
    {
        if ($this->structures->contains($structure)) {
            $this->structures->removeElement($structure);
        }

        return $this;
    }

    public function updateStructures(array $structures)
    {
        foreach ($structures as $structure) {
            $this->addStructure($structure);
        }
    }

    public function hasStructure(Structure $structure) : bool
    {
        return $this->structures->contains($structure);
    }

    public function hasCommonStructure($structures) : bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        foreach ($structures as $structure) {
            if ($this->hasStructure($structure)) {
                return true;
            }
        }

        return false;
    }

    public function getCommonStructures($structures) : array
    {
        if ($this->isAdmin()) {
            return $structures;
        }

        $common = [];

        foreach ($structures as $structure) {
            if ($this->hasStructure($structure)) {
                $common[] = $structure;
            }
        }

        return $common;
    }

    /**
     * @return Structure[]
     */
    public function getRootStructures() : array
    {
        $structures = $this->getStructures();
        $roots      = [];
        foreach ($structures as $structure) {
            /** @var Structure $structure */
            if (!$structure->getParentStructure()) {
                $roots[] = $structure;
                continue;
            }

            // Structure disappear if any of its ancestor is in the list
            foreach ($structure->getAncestors() as $ancestor) {
                if (!$structures->contains($ancestor)) {
                    $roots[] = $structure;
                }
            }
        }

        return $roots;
    }

    public function getMainStructure() : ?Structure
    {
        $root = $this->getRootStructures();

        if ($root) {
            return reset($root);
        }

        return null;
    }

    public function isLocked() : ?bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked) : self
    {
        $this->locked = $locked;

        return $this;
    }


    public function getRoles() : array
    {
        $roles = parent::getRoles();

        if ($this->isDeveloper) {
            $roles[] = 'ROLE_DEVELOPER';
        }

        if ($this->isRoot) {
            $roles[] = 'ROLE_ROOT';
        }

        if ($this->isPegassApi) {
            $roles[] = 'ROLE_PEGASS_API';
        }

        return $roles;
    }

    public function getDisplayName() : string
    {
        return $this->getUserIdentifier();
    }

    public function __clone()
    {
        $this->structures = clone $this->structures;
    }

    /**
     * @return Collection<int, Badge>
     */
    public function getFavoriteBadges() : Collection
    {
        return $this->favoriteBadges;
    }

    public function getSortedFavoriteBadges() : array
    {
        $badges =
            $this->getFavoriteBadges()
                 ->filter(function (Badge $badge) {
                     return $this->platform === $badge->getPlatform();
                 })
                 ->toArray();

        usort($badges, [Badge::class, 'sortBadges']);

        return $badges;
    }

    public function addFavoriteBadge(Badge $favoriteBadge) : self
    {
        if (!$this->favoriteBadges->contains($favoriteBadge)) {
            $this->favoriteBadges[] = $favoriteBadge;
        }

        return $this;
    }

    public function removeFavoriteBadge(Badge $favoriteBadge) : self
    {
        $this->favoriteBadges->removeElement($favoriteBadge);

        return $this;
    }
}