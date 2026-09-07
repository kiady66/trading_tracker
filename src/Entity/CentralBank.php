<?php

namespace App\Entity;

use App\Repository\CentralBankRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CentralBankRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_central_bank_code', columns: ['code'])]
class CentralBank
{
    public const BIASES = ['neutre', 'hawkish', 'dovish'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $code = null;

    // Chaîne libre : certains taux sont des fourchettes ("3,50-3,75 %")
    #[ORM\Column(length: 30)]
    private ?string $rate = null;

    // Position sur le cadran, en degrés [0, 360)
    #[ORM\Column(type: Types::FLOAT)]
    private float $angle = 0.0;

    #[ORM\Column(length: 20)]
    private string $bias = 'neutre';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $retracement = false;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getRate(): ?string
    {
        return $this->rate;
    }

    public function setRate(string $rate): self
    {
        $this->rate = $rate;

        return $this;
    }

    public function getAngle(): float
    {
        return $this->angle;
    }

    public function setAngle(float $angle): self
    {
        $this->angle = fmod(fmod($angle, 360.0) + 360.0, 360.0);

        return $this;
    }

    public function getBias(): string
    {
        return $this->bias;
    }

    public function setBias(string $bias): self
    {
        $this->bias = $bias;

        return $this;
    }

    public function isRetracement(): bool
    {
        return $this->retracement;
    }

    public function setRetracement(bool $retracement): self
    {
        $this->retracement = $retracement;

        return $this;
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->code;
    }
}
