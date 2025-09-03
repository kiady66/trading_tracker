<?php

namespace App\Controller;

use App\Entity\TradeType;
use App\Form\TradeTypeForm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/trade/type')]
final class TradeTypeController extends AbstractController
{
    #[Route(name: 'app_trade_type_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $tradeTypes = $entityManager
            ->getRepository(TradeType::class)
            ->findAll();

        return $this->render('trade_type/index.html.twig', [
            'trade_types' => $tradeTypes,
        ]);
    }

    #[Route('/new', name: 'app_trade_type_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $tradeType = new TradeType();
        $form = $this->createForm(TradeTypeForm::class, $tradeType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($tradeType);
            $entityManager->flush();

            return $this->redirectToRoute('app_trade_type_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('trade_type/new.html.twig', [
            'trade_type' => $tradeType,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_trade_type_show', methods: ['GET'])]
    public function show(TradeType $tradeType): Response
    {
        return $this->render('trade_type/show.html.twig', [
            'trade_type' => $tradeType,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_trade_type_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, TradeType $tradeType, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(TradeType::class, $tradeType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_trade_type_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('trade_type/edit.html.twig', [
            'trade_type' => $tradeType,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_trade_type_delete', methods: ['POST'])]
    public function delete(Request $request, TradeType $tradeType, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$tradeType->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($tradeType);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_trade_type_index', [], Response::HTTP_SEE_OTHER);
    }
}
