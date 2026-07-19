<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_MODERATOR = 'ROLE_MODERATOR';
    public const ROLE_TRADER = 'ROLE_TRADER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    private ?string $plainPassword = null;

    #[ORM\OneToMany(targetEntity: Trade::class, mappedBy: 'user')]
    private Collection $trades;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $apiToken = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 30, unique: true, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $shareEnabled = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $shareStats = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $shareOpenTrades = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $shareClosedTrades = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $shareCurrentMonthOnly = false;

    public function __construct()
    {
        $this->trades = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->apiToken = bin2hex(random_bytes(32));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    public function getTrades(): Collection
    {
        return $this->trades;
    }

    public function addTrade(Trade $trade): self
    {
        if (!$this->trades->contains($trade)) {
            $this->trades->add($trade);
            $trade->setUser($this);
        }
        return $this;
    }

    public function removeTrade(Trade $trade): self
    {
        if ($this->trades->removeElement($trade)) {
            if ($trade->getUser() === $this) {
                $trade->setUser(null);
            }
        }
        return $this;
    }

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    public function setApiToken(?string $apiToken): self
    {
        $this->apiToken = $apiToken;
        return $this;
    }

    public function regenerateApiToken(): self
    {
        $this->apiToken = bin2hex(random_bytes(32));
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): self
    {
        $this->plainPassword = $plainPassword;
        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->displayName = $displayName;
        return $this;
    }

    public function isShareEnabled(): bool
    {
        return $this->shareEnabled;
    }

    public function setShareEnabled(bool $shareEnabled): self
    {
        $this->shareEnabled = $shareEnabled;
        return $this;
    }

    public function isShareStats(): bool
    {
        return $this->shareStats;
    }

    public function setShareStats(bool $shareStats): self
    {
        $this->shareStats = $shareStats;
        return $this;
    }

    public function isShareOpenTrades(): bool
    {
        return $this->shareOpenTrades;
    }

    public function setShareOpenTrades(bool $shareOpenTrades): self
    {
        $this->shareOpenTrades = $shareOpenTrades;
        return $this;
    }

    public function isShareClosedTrades(): bool
    {
        return $this->shareClosedTrades;
    }

    public function setShareClosedTrades(bool $shareClosedTrades): self
    {
        $this->shareClosedTrades = $shareClosedTrades;
        return $this;
    }

    public function isShareCurrentMonthOnly(): bool
    {
        return $this->shareCurrentMonthOnly;
    }

    public function setShareCurrentMonthOnly(bool $shareCurrentMonthOnly): self
    {
        $this->shareCurrentMonthOnly = $shareCurrentMonthOnly;
        return $this;
    }

    public function isProfilePublic(): bool
    {
        return $this->shareEnabled && $this->displayName !== null;
    }

    public function isPremium(): bool
    {
        return $this->isAdmin();
    }

    public function isAdmin(): bool
    {
        return in_array(self::ROLE_ADMIN, $this->getRoles());
    }
}
