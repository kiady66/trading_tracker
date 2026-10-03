<?php

namespace App\Service;

use App\Entity\BiasPair;
use App\Entity\Trade;
use App\Entity\User;
use App\Repository\BiasPairRepository;
use App\Repository\CentralBankRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Liste ordonnée des paires "hawkish contre dovish" d'un utilisateur (page
 * /biais). Le biais d'une devise est celui de sa banque centrale (CentralBank,
 * partagé avec /cycles) ; la liste en découle et seul son ordre est stocké.
 */
class BiasPairList
{
    /** Devise → code CentralBank, dans l'ordre d'affichage des cartes. */
    public const CURRENCY_BANKS = [
        'USD' => 'Fed',
        'EUR' => 'BCE',
        'GBP' => 'BoE',
        'CAD' => 'BoC',
        'AUD' => 'RBA',
        'NZD' => 'RBNZ',
        'CHF' => 'SNB',
        'JPY' => 'BoJ',
    ];

    public function __construct(
        private readonly CentralBankRepository $centralBanks,
        private readonly BiasPairRepository $biasPairs,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, string> devise → biais ("neutre" si la banque manque en base)
     */
    public function biases(): array
    {
        $byCode = [];
        foreach ($this->centralBanks->findAll() as $bank) {
            $byCode[$bank->getCode()] = $bank->getBias();
        }

        $biases = [];
        foreach (self::CURRENCY_BANKS as $currency => $bankCode) {
            $biases[$currency] = $byCode[$bankCode] ?? 'neutre';
        }

        return $biases;
    }

    /**
     * Paires devise/devise de Trade::ALLOWED_ASSETS dont les deux devises ont
     * une banque centrale suivie (exclut métaux, crypto, indices).
     *
     * @return list<string>
     */
    public static function fxPairs(): array
    {
        return array_values(array_filter(
            Trade::ALLOWED_ASSETS,
            static fn (string $asset) => preg_match('#^([A-Z]{3})/([A-Z]{3})$#', $asset, $m)
                && isset(self::CURRENCY_BANKS[$m[1]], self::CURRENCY_BANKS[$m[2]]),
        ));
    }

    /**
     * Paires opposant une devise hawkish à une devise dovish, dans l'ordre de
     * Trade::ALLOWED_ASSETS. Les devises neutres n'entrent dans aucune paire.
     *
     * @param array<string, string> $biases
     * @return list<string>
     */
    public static function divergentPairs(array $biases): array
    {
        return array_values(array_filter(self::fxPairs(), static function (string $pair) use ($biases) {
            [$base, $quote] = explode('/', $pair);
            $pairBiases = [$biases[$base] ?? 'neutre', $biases[$quote] ?? 'neutre'];
            sort($pairBiases);

            return $pairBiases === ['dovish', 'hawkish'];
        }));
    }

    /**
     * Aligne la liste stockée sur les biais actuels : retire les paires qui ne
     * sont plus divergentes, ajoute les nouvelles en fin de liste, et ne touche
     * jamais au rang des paires déjà présentes.
     *
     * @return list<BiasPair>
     */
    public function sync(User $user): array
    {
        $expected = self::divergentPairs($this->biases());
        $stored = $this->biasPairs->findByUserOrdered($user);

        $kept = [];
        $maxPosition = -1;
        foreach ($stored as $biasPair) {
            if (!in_array($biasPair->getPair(), $expected, true)) {
                $this->entityManager->remove($biasPair);
                continue;
            }
            $kept[$biasPair->getPair()] = $biasPair;
            $maxPosition = max($maxPosition, $biasPair->getPosition());
        }

        foreach ($expected as $pair) {
            if (!isset($kept[$pair])) {
                $kept[$pair] = new BiasPair($user, $pair, ++$maxPosition);
                $this->entityManager->persist($kept[$pair]);
            }
        }

        $this->entityManager->flush();

        $list = array_values($kept);
        usort($list, static fn (BiasPair $a, BiasPair $b) => $a->getPosition() <=> $b->getPosition());

        return $list;
    }

    /**
     * Applique l'ordre choisi par glisser-déposer. Refuse une liste qui ne
     * contient pas exactement les paires actuelles (biais changé entre-temps).
     *
     * @param list<string> $orderedPairs
     * @return list<BiasPair>
     */
    public function reorder(User $user, array $orderedPairs): array
    {
        $current = $this->sync($user);
        $byPair = [];
        foreach ($current as $biasPair) {
            $byPair[$biasPair->getPair()] = $biasPair;
        }

        $expectedKeys = array_keys($byPair);
        sort($expectedKeys);
        $givenKeys = array_values(array_unique(array_map('strval', $orderedPairs)));
        sort($givenKeys);
        if ($expectedKeys !== $givenKeys) {
            throw new \InvalidArgumentException('La liste des paires a changé, elle a été rechargée.');
        }

        $list = [];
        foreach ($orderedPairs as $position => $pair) {
            $list[] = $byPair[$pair]->setPosition($position);
        }
        $this->entityManager->flush();

        return $list;
    }
}
