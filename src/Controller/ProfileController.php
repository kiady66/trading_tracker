<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/profile', name: 'app_profile_')]
class ProfileController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('profile/index.html.twig');
    }

    #[Route('/regenerate-token', name: 'regenerate_token', methods: ['POST'])]
    public function regenerateToken(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('regenerate_token', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_profile_index');
        }

        $user = $this->getUser();
        $user->regenerateApiToken();
        $em->flush();

        $this->addFlash('success', 'Nouveau token API généré. Pensez à le mettre à jour dans cTrader.');

        return $this->redirectToRoute('app_profile_index');
    }
}
