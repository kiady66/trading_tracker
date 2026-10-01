<?php

namespace App\Service;

use App\Entity\CentralBankRefresh;
use App\Repository\CentralBankRefreshRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * État du rafraîchissement des taux, partagé entre la commande (qui l'écrit)
 * et la page /cycles (qui l'affiche et le sonde), stocké en base dans
 * CentralBankRefresh — une ligne par exécution.
 */
class CentralBankRefreshStatus
{
    // Au-delà, un statut "running" est considéré comme un processus mort
    private const STALE_AFTER_SECONDS = 20 * 60;

    public function __construct(
        private readonly CentralBankRefreshRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function markRunning(): CentralBankRefresh
    {
        $refresh = new CentralBankRefresh();
        $this->entityManager->persist($refresh);
        $this->entityManager->flush();

        return $refresh;
    }

    /**
     * Reprend la ligne "running" créée par la requête HTTP du bouton, ou en
     * crée une si la commande est lancée à la main en console.
     */
    public function ensureRunning(): CentralBankRefresh
    {
        $latest = $this->repository->findLatest();

        return $latest !== null && $latest->isRunning() ? $latest : $this->markRunning();
    }

    /** @param list<string> $changes */
    public function markDone(array $changes): void
    {
        $this->ensureRunning()->finish($changes);
        $this->entityManager->flush();
    }

    public function markError(string $message): void
    {
        $this->ensureRunning()->fail($message);
        $this->entityManager->flush();
    }

    public function isRunning(): bool
    {
        return ($this->read()['status'] ?? null) === CentralBankRefresh::RUNNING;
    }

    /**
     * Dernière exécution, ou [] s'il n'y en a jamais eu. Un "running" trop
     * ancien est renvoyé comme une erreur pour ne pas bloquer le bouton.
     *
     * @return array{}|array{status: string, startedAt: string, finishedAt: ?string, changes: list<string>, message: ?string}
     */
    public function read(): array
    {
        $latest = $this->repository->findLatest();
        if ($latest === null) {
            return [];
        }

        $data = $latest->toArray();
        if ($latest->isRunning() && time() - $latest->getStartedAt()->getTimestamp() > self::STALE_AFTER_SECONDS) {
            $data['status'] = CentralBankRefresh::ERROR;
            $data['message'] = 'Le rafraîchissement précédent ne s\'est pas terminé.';
        }

        return $data;
    }
}
