<?php

namespace App\Controller;

use App\Entity\Trend;
use App\Form\TrendType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/trend')]
final class TrendController extends AbstractController
{
    #[Route(name: 'app_trend_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $trends = $entityManager
            ->getRepository(Trend::class)
            ->findAll();

        return $this->render('trend/index.html.twig', [
            'trends' => $trends,
        ]);
    }

    #[Route('/new', name: 'app_trend_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $trend = new Trend();
        $form = $this->createForm(TrendType::class, $trend);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($trend);
            $entityManager->flush();

            return $this->redirectToRoute('app_trend_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('trend/new.html.twig', [
            'trend' => $trend,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_trend_show', methods: ['GET'])]
    public function show(Trend $trend): Response
    {
        return $this->render('trend/show.html.twig', [
            'trend' => $trend,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_trend_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Trend $trend, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(TrendType::class, $trend);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_trend_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('trend/edit.html.twig', [
            'trend' => $trend,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_trend_delete', methods: ['POST'])]
    public function delete(Request $request, Trend $trend, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$trend->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($trend);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_trend_index', [], Response::HTTP_SEE_OTHER);
    }
}
