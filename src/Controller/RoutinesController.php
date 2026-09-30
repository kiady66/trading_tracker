<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Checklists de routine swing FX (week-end, journalière, alerte, annonce, mémo).
 * Page statique : l'état des cases vit uniquement dans le navigateur.
 */
#[Route('/routines', name: 'app_routines_')]
#[IsGranted('ROLE_USER')]
class RoutinesController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('routines/index.html.twig');
    }
}
