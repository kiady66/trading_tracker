<?php

namespace App\Controller;

use App\Entity\Confluence;
use App\Form\ConfluenceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/confluence')]
final class ConfluenceController extends AbstractController
{
    #[Route(name: 'app_confluence_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $confluences = $entityManager
            ->getRepository(Confluence::class)
            ->findAll();

        return $this->render('confluence/index.html.twig', [
            'confluences' => $confluences,
        ]);
    }

    #[Route('/new', name: 'app_confluence_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $confluence = new Confluence();
        $form = $this->createForm(ConfluenceType::class, $confluence);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($confluence);
            $entityManager->flush();

            return $this->redirectToRoute('app_confluence_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('confluence/new.html.twig', [
            'confluence' => $confluence,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_confluence_show', methods: ['GET'])]
    public function show(Confluence $confluence): Response
    {
        return $this->render('confluence/show.html.twig', [
            'confluence' => $confluence,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_confluence_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Confluence $confluence, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ConfluenceType::class, $confluence);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_confluence_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('confluence/edit.html.twig', [
            'confluence' => $confluence,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_confluence_delete', methods: ['POST'])]
    public function delete(Request $request, Confluence $confluence, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$confluence->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($confluence);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_confluence_index', [], Response::HTTP_SEE_OTHER);
    }
}
