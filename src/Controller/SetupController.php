<?php

namespace App\Controller;

use App\Entity\Setup;
use App\Form\SetupType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/setup')]
final class SetupController extends AbstractController
{
    #[Route(name: 'app_setup_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $setups = $entityManager
            ->getRepository(Setup::class)
            ->findAll();

        return $this->render('setup/index.html.twig', [
            'setups' => $setups,
        ]);
    }

    #[Route('/new', name: 'app_setup_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $setup = new Setup();
        $form = $this->createForm(SetupType::class, $setup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($setup);
            $entityManager->flush();

            return $this->redirectToRoute('app_setup_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('setup/new.html.twig', [
            'setup' => $setup,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_setup_show', methods: ['GET'])]
    public function show(Setup $setup): Response
    {
        return $this->render('setup/show.html.twig', [
            'setup' => $setup,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_setup_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Setup $setup, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(SetupType::class, $setup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_setup_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('setup/edit.html.twig', [
            'setup' => $setup,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_setup_delete', methods: ['POST'])]
    public function delete(Request $request, Setup $setup, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$setup->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($setup);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_setup_index', [], Response::HTTP_SEE_OTHER);
    }
}
