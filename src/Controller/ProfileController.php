<?php

namespace App\Controller;

use App\Form\ProfileVisibilityType;
use App\Repository\UserRepository;
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
        $visibilityForm = $this->createForm(ProfileVisibilityType::class, $this->getUser(), [
            'action' => $this->generateUrl('app_profile_visibility'),
        ]);

        return $this->render('profile/index.html.twig', [
            'visibility_form' => $visibilityForm,
        ]);
    }

    #[Route('/visibility', name: 'visibility', methods: ['POST'])]
    public function visibility(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository
    ): Response {
        $user = $this->getUser();
        $hadDisplayName = $user->getDisplayName() !== null;

        $form = $this->createForm(ProfileVisibilityType::class, $user, [
            'action' => $this->generateUrl('app_profile_visibility'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Unicité insensible à la casse, seulement quand le pseudo vient d'être défini
            if (!$hadDisplayName && $user->getDisplayName() !== null) {
                $existing = $userRepository->findOneByDisplayNameInsensitive($user->getDisplayName());
                if ($existing !== null && $existing->getId() !== $user->getId()) {
                    $em->refresh($user);
                    $this->addFlash('error', 'Ce pseudo est déjà pris.');

                    return $this->redirectToRoute('app_profile_index');
                }
            }

            $em->flush();
            $this->addFlash('success', 'Paramètres de visibilité enregistrés.');

            return $this->redirectToRoute('app_profile_index');
        }

        $em->refresh($user);

        return $this->render('profile/index.html.twig', [
            'visibility_form' => $form,
        ]);
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
