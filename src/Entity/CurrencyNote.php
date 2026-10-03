<?php

namespace App\Entity;

use App\Repository\CurrencyNoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Note fondamentale d'un utilisateur sur une devise (page /biais). Le biais
 * lui-même n'est pas stocké ici : il vit sur CentralBank, partagé avec /cycles.
 */
#[ORM\Entity(repositoryClass: CurrencyNoteRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_currency_note_user_currency', columns: ['user_id', 'currency'])]
class CurrencyNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 3)]
    private ?string $currency = null;

    // HTML produit par l'éditeur et nettoyé par le HtmlSanitizer "currency_note"
    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $currency)
    {
        $this->user = $user;
        $this->currency = $currency;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
