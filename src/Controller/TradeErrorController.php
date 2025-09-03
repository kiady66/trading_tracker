<?php

namespace App\Controller;

use App\Entity\TradeError;
use App\Form\TradeErrorType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/trade/error')]
final class TradeErrorController extends AbstractController
{
    #[Route(name: 'app_trade_error_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $tradeErrors = $entityManager
            ->getRepository(TradeError::class)
            ->findAll();

        return $this->render('trade_error/index.html.twig', [
            'trade_errors' => $tradeErrors,
        ]);
    }

    #[Route('/new', name: 'app_trade_error_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $tradeError = new TradeError();
        $form = $this->createForm(TradeErrorType::class, $tradeError);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($tradeError);
            $entityManager->flush();

            return $this->redirectToRoute('app_trade_error_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('trade_error/new.html.twig', [
            'trade_error' => $tradeError,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_trade_error_show', methods: ['GET'])]
    public function show(TradeError $tradeError): Response
    {
        return $this->render('trade_error/show.html.twig', [
            'trade_error' => $tradeError,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_trade_error_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, TradeError $tradeError, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(TradeErrorType::class, $tradeError);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_trade_error_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('trade_error/edit.html.twig', [
            'trade_error' => $tradeError,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_trade_error_delete', methods: ['POST'])]
    public function delete(Request $request, TradeError $tradeError, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$tradeError->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($tradeError);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_trade_error_index', [], Response::HTTP_SEE_OTHER);
    }
}
