<?php

namespace App\Controller;

use App\Entity\Timeframe;
use App\Form\TimeframeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/timeframe')]
final class TimeframeController extends AbstractController
{
    #[Route(name: 'app_timeframe_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $timeframes = $entityManager
            ->getRepository(Timeframe::class)
            ->findAll();

        return $this->render('timeframe/index.html.twig', [
            'timeframes' => $timeframes,
        ]);
    }

    #[Route('/new', name: 'app_timeframe_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $timeframe = new Timeframe();
        $form = $this->createForm(TimeframeType::class, $timeframe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($timeframe);
            $entityManager->flush();

            return $this->redirectToRoute('app_timeframe_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('timeframe/new.html.twig', [
            'timeframe' => $timeframe,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_timeframe_show', methods: ['GET'])]
    public function show(Timeframe $timeframe): Response
    {
        return $this->render('timeframe/show.html.twig', [
            'timeframe' => $timeframe,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_timeframe_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Timeframe $timeframe, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(TimeframeType::class, $timeframe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_timeframe_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('timeframe/edit.html.twig', [
            'timeframe' => $timeframe,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_timeframe_delete', methods: ['POST'])]
    public function delete(Request $request, Timeframe $timeframe, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$timeframe->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($timeframe);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_timeframe_index', [], Response::HTTP_SEE_OTHER);
    }
}
