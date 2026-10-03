<?php

namespace App\Entity;

use App\Repository\BiasPairRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paire "hawkish contre dovish" de la page /biais, avec le rang choisi par
 * l'utilisateur. Les lignes sont créées et supprimées par BiasPairList au gré
 * des biais des banques centrales ; seul l'ordre appartient à l'utilisateur.
 */
#[ORM\Entity(repositoryClass: BiasPairRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_bias_pair_user_pair', columns: ['user_id', 'pair'])]
class BiasPair
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    // Même graphie que Trade::ALLOWED_ASSETS ("EUR/USD")
    #[ORM\Column(length: 7)]
    private ?string $pair = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position = 0;

    public function __construct(User $user, string $pair, int $position)
    {
        $this->user = $user;
        $this->pair = $pair;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getPair(): ?string
    {
        return $this->pair;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }
}
