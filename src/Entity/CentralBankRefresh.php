<?php

namespace App\Entity;

use App\Repository\CentralBankRefreshRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une exécution de app:cycles:refresh (actualisation des taux par l'IA) :
 * son état, sondé par la page /cycles, et le compte rendu des changements.
 */
#[ORM\Entity(repositoryClass: CentralBankRefreshRepository::class)]
class CentralBankRefresh
{
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const ERROR = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $status = self::RUNNING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $changes = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    public function __construct()
    {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** @return list<string> */
    public function getChanges(): array
    {
        return $this->changes;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    /** @param list<string> $changes */
    public function finish(array $changes): self
    {
        $this->status = self::DONE;
        $this->changes = $changes;
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }

    public function fail(string $message): self
    {
        $this->status = self::ERROR;
        $this->message = $message;
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * @return array{status: string, startedAt: string, finishedAt: ?string, changes: list<string>, message: ?string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'startedAt' => $this->startedAt->format(\DateTimeInterface::ATOM),
            'finishedAt' => $this->finishedAt?->format(\DateTimeInterface::ATOM),
            'changes' => $this->changes,
            'message' => $this->message,
        ];
    }
}
