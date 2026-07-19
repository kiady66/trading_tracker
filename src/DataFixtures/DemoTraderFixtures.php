<?php

namespace App\DataFixtures;

use App\Entity\Confluence;
use App\Entity\Timeframe;
use App\Entity\Trade;
use App\Entity\TradeError;
use App\Entity\TradeType;
use App\Entity\Trend;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Profils de traders de démo pour tester la page /traders.
 *
 * Idempotent : un profil n'est créé que si son email n'existe pas encore ;
 * les utilisateurs et trades existants ne sont jamais modifiés. À charger
 * avec : symfony console doctrine:fixtures:load --append
 *
 * Mot de passe commun : demo1234
 */
class DemoTraderFixtures extends Fixture implements DependentFixtureInterface
{
    private const PASSWORD = 'demo1234';

    private const ASSETS = ['EUR/USD', 'GBP/USD', 'USD/JPY', 'XAU/USD', 'BTC/USD', 'SP500', 'AUD/USD', 'EUR/JPY'];
    private const ORDER_TYPES = ['buy market', 'sell market', 'buy limit', 'sell limit', 'buy stop', 'sell stop'];
    private const EXECUTION_REASONS = [
        'Cassure de structure confirmée sur le timeframe supérieur.',
        'Rejet net de la zone de supply avec mèche de rejet.',
        'Retest du niveau après cassure, entrée sur confirmation.',
        'Divergence RSI + zone de demande hebdomadaire.',
        'Prise de liquidité sous le plus bas de la session asiatique.',
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $profiles = [
            // Partage tout, bon historique
            ['email' => 'demo.alex@example.com', 'displayName' => 'AlexSwing', 'winRate' => 62, 'closedCount' => 34, 'monthsBack' => 5, 'maxRiskEuro' => 150.0],
            // Partage tout, en perte
            ['email' => 'demo.nova@example.com', 'displayName' => 'NovaTrader', 'winRate' => 18, 'closedCount' => 28, 'monthsBack' => 4, 'maxRiskEuro' => 100.0],
            // Stats privées : seuls les trades sont visibles
            ['email' => 'demo.wolf@example.com', 'displayName' => 'MidnightWolf', 'shareStats' => false, 'winRate' => 55, 'closedCount' => 30, 'monthsBack' => 6, 'maxRiskEuro' => 200.0],
            // Mois en cours uniquement (avec de l'historique masqué + un trade à cheval)
            ['email' => 'demo.luna@example.com', 'displayName' => 'LunaFX', 'shareCurrentMonthOnly' => true, 'winRate' => 58, 'closedCount' => 32, 'monthsBack' => 4, 'maxRiskEuro' => 120.0],
            // Ne partage que les trades terminés
            ['email' => 'demo.rmaster@example.com', 'displayName' => 'R_Master', 'shareOpenTrades' => false, 'winRate' => 48, 'closedCount' => 26, 'monthsBack' => 8, 'maxRiskEuro' => 80.0],
            // Profil resté privé : ne doit jamais apparaître dans /traders
            ['email' => 'demo.ghost@example.com', 'displayName' => 'GhostTrader', 'shareEnabled' => false, 'winRate' => 50, 'closedCount' => 12, 'monthsBack' => 3, 'maxRiskEuro' => 100.0],
        ];

        $userRepository = $manager->getRepository(User::class);
        $refs = [
            'timeframes' => $manager->getRepository(Timeframe::class)->findAll(),
            'confluences' => $manager->getRepository(Confluence::class)->findAll(),
            'tradeTypes' => $manager->getRepository(TradeType::class)->findAll(),
            'trends' => $manager->getRepository(Trend::class)->findAll(),
            'errors' => $manager->getRepository(TradeError::class)->findAll(),
        ];

        foreach ($profiles as $index => $profile) {
            if ($userRepository->findOneBy(['email' => $profile['email']]) !== null) {
                continue;
            }

            // Graine fixe par profil : recharger produit les mêmes données
            mt_srand(20260720 + $index);

            $user = new User();
            $user->setEmail($profile['email']);
            $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));
            $user->setCreatedAt(new \DateTimeImmutable(sprintf('-%d months', $profile['monthsBack'])));
            $user->setDisplayName($profile['displayName']);
            $user->setShareEnabled($profile['shareEnabled'] ?? true);
            $user->setShareStats($profile['shareStats'] ?? true);
            $user->setShareOpenTrades($profile['shareOpenTrades'] ?? true);
            $user->setShareClosedTrades($profile['shareClosedTrades'] ?? true);
            $user->setShareCurrentMonthOnly($profile['shareCurrentMonthOnly'] ?? false);
            $manager->persist($user);

            $this->createTrades($manager, $user, $profile, $refs);
        }

        $manager->flush();
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, object[]> $refs
     */
    private function createTrades(ObjectManager $manager, User $user, array $profile, array $refs): void
    {
        $historyDays = min(150, $profile['monthsBack'] * 30 - 5);
        $currentMonthDays = max(1, (int) date('j') - 1);

        // Trades terminés : ~5 sur le mois en cours, le reste étalé sur l'historique
        for ($i = 0; $i < $profile['closedCount']; $i++) {
            if ($i < 5) {
                $entry = new \DateTime(sprintf('-%d days -%d hours', mt_rand(0, $currentMonthDays), mt_rand(1, 12)));
            } else {
                $entry = new \DateTime(sprintf('-%d days -%d hours', mt_rand($currentMonthDays + 1, $historyDays), mt_rand(0, 12)));
            }
            $exit = (clone $entry)->modify(sprintf('+%d hours', mt_rand(2, 96)));
            if ($exit > new \DateTime()) {
                $exit = new \DateTime('-1 hour');
            }

            $isWin = mt_rand(1, 100) <= $profile['winRate'];
            $finalRR = $isWin ? mt_rand(10, 45) / 10 : (mt_rand(1, 100) <= 20 ? -mt_rand(3, 7) / 10 : -1.0);

            $trade = $this->baseTrade($user, $profile, $refs);
            $trade->setEntryDate($entry);
            $trade->setExitDate($exit);
            $trade->setWatchlistDate((clone $entry)->modify(sprintf('-%d hours', mt_rand(1, 48))));
            $trade->setFinalRR($finalRR);
            $trade->setGoodTrade($isWin ? mt_rand(1, 100) <= 85 : mt_rand(1, 100) <= 40);
            $trade->setTradeQuality(mt_rand(2, 5));
            if (!$isWin && mt_rand(1, 100) <= 30 && $refs['errors'] !== []) {
                $trade->setError($this->pick($refs['errors']));
            }
            $manager->persist($trade);
        }

        // Trades en cours : 2 récents + 1 « à cheval » ouvert le mois dernier
        $openEntries = [
            new \DateTime(sprintf('-%d days -%d hours', mt_rand(0, 3), mt_rand(1, 10))),
            new \DateTime(sprintf('-%d days -%d hours', mt_rand(4, 9), mt_rand(1, 10))),
            new \DateTime(sprintf('-%d days -%d hours', $currentMonthDays + mt_rand(2, 10), mt_rand(1, 10))),
        ];
        foreach ($openEntries as $entry) {
            $trade = $this->baseTrade($user, $profile, $refs);
            $trade->setEntryDate($entry);
            $trade->setWatchlistDate((clone $entry)->modify(sprintf('-%d hours', mt_rand(1, 48))));
            $manager->persist($trade);
        }

        // Trades en watchlist : jamais visibles publiquement
        for ($i = 0; $i < 2; $i++) {
            $trade = $this->baseTrade($user, $profile, $refs);
            $trade->setWatchlistDate(new \DateTime(sprintf('-%d days', mt_rand(0, 5))));
            $manager->persist($trade);
        }
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, object[]> $refs
     */
    private function baseTrade(User $user, array $profile, array $refs): Trade
    {
        $trade = new Trade();
        $trade->setUser($user);
        $trade->setAsset($this->pick(self::ASSETS));
        $trade->setOrderType($this->pick(self::ORDER_TYPES));
        $trade->setRiskPercentage(mt_rand(1, 100) <= 75 ? 100.0 : 50.0);
        $trade->setMaxRiskEuro($profile['maxRiskEuro']);
        $trade->setInitialRR(mt_rand(15, 60) / 10);
        $trade->setTradeManagement(mt_rand(1, 100) <= 30);

        if ($refs['tradeTypes'] !== []) {
            $trade->setTradeType($this->pick($refs['tradeTypes']));
        }
        if ($refs['trends'] !== []) {
            $trade->setTrend($this->pick($refs['trends']));
        }
        foreach ($this->pickMany($refs['timeframes'], mt_rand(1, 2)) as $timeframe) {
            $trade->addTimeframe($timeframe);
        }
        foreach ($this->pickMany($refs['confluences'], mt_rand(1, 3)) as $confluence) {
            $trade->addConfluence($confluence);
        }
        if (mt_rand(1, 100) <= 70) {
            $trade->setExecutionReason($this->pick(self::EXECUTION_REASONS));
        }

        return $trade;
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function pickMany(array $items, int $count): array
    {
        if ($items === []) {
            return [];
        }

        shuffle($items);

        return array_slice($items, 0, min($count, count($items)));
    }
}
