<?php

namespace App\Controller;

use App\Repository\ConfluenceRepository;
use App\Service\StatsProvider;
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
        StatsProvider $statsProvider,
        ConfluenceRepository $confluenceRepository
    ): Response {
        $filters = [
            'start_date' => $request->query->get('start_date'),
            'end_date' => $request->query->get('end_date'),
            'confluences' => $request->query->all('confluences'),
        ];

        $unit = StatsProvider::normalizeUnit($request->query->get('unit'));

        $statsView = $statsProvider->build($filters + ['user' => $this->getUser()], $unit);

        return $this->render('stats/index.html.twig', $statsView + [
            'all_confluences' => $confluenceRepository->findAll(),
            'filters' => $filters,
        ]);
    }
}
