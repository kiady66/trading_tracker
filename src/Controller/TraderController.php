<?php

namespace App\Controller;

use App\Dto\PublicTradeView;
use App\Entity\User;
use App\Repository\ConfluenceRepository;
use App\Repository\TradeRepository;
use App\Repository\UserRepository;
use App\Service\StatsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/traders', name: 'app_traders_')]
class TraderController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TradeRepository $tradeRepository,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $sort = $request->query->get('sort', 'recent');
        if (!in_array($sort, ['recent', 'performance'], true)) {
            $sort = 'recent';
        }

        $traders = [];
        foreach ($this->userRepository->findPublicProfiles() as $owner) {
            if ($owner === $this->getUser()) {
                continue;
            }

            $summary = $this->tradeRepository->getPublicSummary(
                $owner,
                self::visibleStatuses($owner),
                $owner->isShareCurrentMonthOnly()
            );

            // Aucun trade visible → le trader n'apparaît pas dans la liste
            if ($summary === null) {
                continue;
            }

            $traders[] = [
                'owner' => $owner,
                'summary' => $owner->isShareStats() ? $summary : null,
                'last_activity' => $summary['last_activity'],
            ];
        }

        if ($sort === 'performance') {
            // Les traders qui ne partagent pas leurs stats sont relégués en fin de liste
            usort($traders, static function (array $a, array $b): int {
                if (($a['summary'] === null) !== ($b['summary'] === null)) {
                    return $a['summary'] === null ? 1 : -1;
                }
                if ($a['summary'] !== null) {
                    return $b['summary']['total_rr'] <=> $a['summary']['total_rr'];
                }

                return $b['last_activity'] <=> $a['last_activity'];
            });
        } else {
            usort($traders, static fn (array $a, array $b): int => $b['last_activity'] <=> $a['last_activity']);
        }

        return $this->render('traders/index.html.twig', [
            'traders' => $traders,
            'sort' => $sort,
        ]);
    }

    #[Route('/{pseudo}', name: 'profile', methods: ['GET'])]
    public function profile(
        string $pseudo,
        Request $request,
        ConfluenceRepository $confluenceRepository,
        StatsProvider $statsProvider
    ): Response {
        $owner = $this->resolveOwner($pseudo);

        $availableTabs = [];
        if ($owner->isShareStats()) {
            $availableTabs[] = 'stats';
        }
        if ($owner->isShareOpenTrades()) {
            $availableTabs[] = 'open';
        }
        if ($owner->isShareClosedTrades()) {
            $availableTabs[] = 'closed';
        }

        $tab = $request->query->get('tab');
        if (!in_array($tab, $availableTabs, true)) {
            $tab = $availableTabs[0] ?? null;
        }

        $filters = [
            'start_date' => $request->query->get('start_date'),
            'end_date' => $request->query->get('end_date'),
            'confluences' => $request->query->all('confluences'),
        ];

        $openTrades = [];
        $closedTrades = [];
        $statsView = null;

        if ($tab === 'stats') {
            // Stats publiques : toujours en R, jamais de valeur en euro.
            // La borne du mois est ajoutée en filtre SQL : forcer un
            // start_date antérieur dans l'URL ne fait rien fuiter.
            $statsFilters = $filters + ['user' => $owner];
            if ($owner->isShareCurrentMonthOnly()) {
                $statsFilters['month_start'] = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0);
            }
            $statsView = $statsProvider->build($statsFilters, StatsProvider::UNIT_RR);
        } elseif ($tab === 'open') {
            $openTrades = array_map(
                PublicTradeView::fromTrade(...),
                $this->tradeRepository->findPublicTrades(
                    $owner,
                    ['open'],
                    $owner->isShareCurrentMonthOnly(),
                    ['confluences' => $filters['confluences']]
                )
            );
        } elseif ($tab === 'closed') {
            // La règle du mois est appliquée en SQL en plus des filtres
            // visiteur : forcer un start_date antérieur dans l'URL ne
            // fait rien fuiter (le AND des deux bornes = max des deux)
            $closedTrades = array_map(
                PublicTradeView::fromTrade(...),
                $this->tradeRepository->findPublicTrades(
                    $owner,
                    ['closed'],
                    $owner->isShareCurrentMonthOnly(),
                    $filters,
                    50
                )
            );
        }

        return $this->render('traders/profile.html.twig', [
            'owner' => $owner,
            'pseudo' => $owner->getDisplayName(),
            'available_tabs' => $availableTabs,
            'tab' => $tab,
            'open_trades' => $openTrades,
            'closed_trades' => $closedTrades,
            'stats_view' => $statsView,
            'filters' => $filters,
            'all_confluences' => $confluenceRepository->findAll(),
        ]);
    }

    #[Route('/{pseudo}/trades/{tradeId}', name: 'trade_show', requirements: ['tradeId' => '\d+'], methods: ['GET'])]
    public function tradeShow(string $pseudo, int $tradeId): Response
    {
        $owner = $this->resolveOwner($pseudo);

        $trade = $this->tradeRepository->findOneBy(['id' => $tradeId, 'user' => $owner]);
        if ($trade === null) {
            throw $this->createNotFoundException();
        }

        $status = $trade->getStatus();
        $allowed = match ($status) {
            'open' => $owner->isShareOpenTrades(),
            'closed' => $owner->isShareClosedTrades(),
            default => false, // watching : jamais exposé
        };

        if ($allowed && $status === 'closed' && $owner->isShareCurrentMonthOnly()) {
            $monthStart = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0);
            $allowed = $trade->getExitDate() >= $monthStart;
        }

        if (!$allowed) {
            throw $this->createNotFoundException();
        }

        return $this->render('traders/trade_show.html.twig', [
            'owner' => $owner,
            'pseudo' => $owner->getDisplayName(),
            'trade' => PublicTradeView::fromTrade($trade),
        ]);
    }

    private function resolveOwner(string $pseudo): User
    {
        $owner = $this->userRepository->findOneByDisplayNameInsensitive($pseudo);

        // 404 plutôt que 403 pour ne pas révéler l'existence d'un profil privé
        if ($owner === null || !$owner->isProfilePublic()) {
            throw $this->createNotFoundException();
        }

        return $owner;
    }

    /**
     * @return string[]
     */
    private static function visibleStatuses(User $owner): array
    {
        $statuses = [];
        if ($owner->isShareOpenTrades()) {
            $statuses[] = 'open';
        }
        if ($owner->isShareClosedTrades()) {
            $statuses[] = 'closed';
        }

        return $statuses;
    }
}
