<?php

namespace App\Repository;

use App\Entity\CurrencyNote;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CurrencyNote>
 */
class CurrencyNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CurrencyNote::class);
    }

    /**
     * @return array<string, CurrencyNote> indexé par devise
     */
    public function findByUserIndexedByCurrency(User $user): array
    {
        $notes = [];
        foreach ($this->findBy(['user' => $user]) as $note) {
            $notes[$note->getCurrency()] = $note;
        }

        return $notes;
    }
}
