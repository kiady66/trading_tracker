<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\OnboardingType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class OnboardingController extends AbstractController
{
    use TargetPathTrait;

    #[Route('/onboarding', name: 'app_onboarding', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Le pseudo est figé après création : repasser ici n'a aucun sens.
        if ($user->getDisplayName() !== null) {
            return $this->redirectToRoute('app_trade_index');
        }

        $form = $this->createForm(OnboardingType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Bienvenue ! Votre pseudo est enregistré.');

            $target = $this->getTargetPath($request->getSession(), 'main');
            $this->removeTargetPath($request->getSession(), 'main');

            return $target !== null
                ? $this->redirect($target)
                : $this->redirectToRoute('app_trade_index');
        }

        return $this->render('onboarding/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
