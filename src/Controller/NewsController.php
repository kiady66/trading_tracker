<?php

namespace App\Controller;

use App\Repository\DailyNewsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/news')]
class NewsController extends AbstractController
{
    #[Route('/', name: 'app_news')]
    public function index(Request $request, DailyNewsRepository $dailyNewsRepository): Response
    {
        $requestedDate = null;
        if ($request->query->has('date')) {
            try {
                $requestedDate = (new \DateTimeImmutable($request->query->get('date')))->setTime(0, 0);
            } catch (\Exception) {
                $requestedDate = null;
            }
        }

        $news = $requestedDate !== null
            ? $dailyNewsRepository->findOneByDate($requestedDate)
            : $dailyNewsRepository->findLatest();

        $currentDate = $news?->getDate() ?? $requestedDate ?? new \DateTimeImmutable('today');

        $availableDates = $dailyNewsRepository->findAvailableDates();

        $previousDate = null;
        $nextDate = null;
        foreach ($availableDates as $availableDate) {
            if ($availableDate < $currentDate) {
                $previousDate = $availableDate;
            } elseif ($availableDate > $currentDate && $nextDate === null) {
                $nextDate = $availableDate;
            }
        }

        return $this->render('news/index.html.twig', [
            'news' => $news,
            'current_date' => $currentDate,
            'previous_date' => $previousDate,
            'next_date' => $nextDate,
        ]);
    }
}