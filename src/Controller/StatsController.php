<?php

namespace App\Controller;

use App\Repository\TradeRepository;
use App\Repository\ConfluenceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/stats')]
class StatsController extends AbstractController
{
    #[Route('/', name: 'app_stats')]
    public function index(
        Request $request,
        TradeRepository $tradeRepository,
        ConfluenceRepository $confluenceRepository
    ): Response {
        $filters = [
            'start_date' => $request->query->get('start_date'),
            'end_date' => $request->query->get('end_date'),
            'confluences' => $request->query->all('confluences'),
        ];

        $stats = $tradeRepository->getStatistics($filters);
        $chartData = $tradeRepository->getChartData($filters);
        $confluenceStats = $tradeRepository->getConfluenceStats($filters);

        dump($confluenceStats); // Debugging line to inspect the data structure

        $allConfluences = $confluenceRepository->findAll();

        return $this->render('stats/index.html.twig', [
            'stats' => $stats,
            'chart_data' => $chartData,
            'confluence_stats' => $confluenceStats,
            'all_confluences' => $allConfluences,
            'filters' => $filters,
        ]);
    }
}
