<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class FirebaseAuthController extends AbstractController
{
    #[Route('/auth/firebase', name: 'app_auth_firebase', methods: ['POST'])]
    public function check(): void
    {
        throw new \LogicException('Cette méthode est interceptée par FirebaseAuthenticator.');
    }
}
