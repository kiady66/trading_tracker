<?php

namespace App\Controller;

use App\Entity\Trade;
use App\Entity\TradeScreenshot;
use App\Form\TradeTypeForm;
use App\Repository\TradeRepository;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/trade')]
class TradeController extends AbstractController
{
    #[Route('/', name: 'app_trade_index', methods: ['GET'])]
    public function index(TradeRepository $tradeRepository): Response
    {
        $trades = $tradeRepository->findBy(['user' => $this->getUser()], ['entryDate' => 'DESC']);

        return $this->render('trade/index.html.twig', [
            'trades' => $trades,
        ]);
    }


    #[Route('/new', name: 'app_trade_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, FileUploader $fileUploader): Response
    {
        $trade = new Trade();

        // Ajoutez cette ligne pour assigner l'utilisateur connecté
        $trade->setUser($this->getUser());

        $form = $this->createForm(TradeTypeForm::class, $trade);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleFileUploads($form, $trade, $fileUploader);

            $trade->calculateStatus();
            $trade->calculateDay();
            $trade->calculateGainRR();
            $trade->calculateGainEuro();

            $entityManager->persist($trade);
            $entityManager->flush();

            $this->addFlash('success', 'Trade créé avec succès!');

            return $this->redirectToRoute('app_trade_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('trade/new.html.twig', [
            'trade' => $trade,
            'form' => $form,
        ]);
    }


    #[Route('/{id}', name: 'app_trade_show', methods: ['GET'])]
    public function show(Trade $trade): Response
    {
        // Vérifiez que l'utilisateur peut voir ce trade
        if ($trade->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à ce trade.');
        }

        // Récupérez les screenshots organisés par catégorie
        $screenshotsByCategory = [
            'execution' => [],
            'management' => [],
            'closing' => []
        ];

        foreach ($trade->getScreenshots() as $screenshot) {
            $screenshotsByCategory[$screenshot->getCategory()][] = $screenshot;
        }

        // Triez chaque catégorie par position
        foreach ($screenshotsByCategory as $category => $screenshots) {
            usort($screenshotsByCategory[$category], function($a, $b) {
                return $a->getPosition() <=> $b->getPosition();
            });
        }

        return $this->render('trade/show.html.twig', [
            'trade' => $trade,
            'screenshotsByCategory' => $screenshotsByCategory
        ]);
    }

    #[Route('/{id}/edit', name: 'app_trade_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Trade $trade, EntityManagerInterface $entityManager, FileUploader $fileUploader): Response
    {
        $form = $this->createForm(TradeTypeForm::class, $trade);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $this->handleFileUploads($form, $trade, $fileUploader);

            $trade->calculateStatus();
            $trade->calculateDay();
            $trade->calculateGainRR();
            $trade->calculateGainEuro();

            $entityManager->flush();

            $this->addFlash('success', 'Trade modifié avec succès!');

            return $this->redirectToRoute('app_trade_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('trade/edit.html.twig', [
            'trade' => $trade,
            'form' => $form,
        ]);
    }

    // Ajoutez ces nouvelles méthodes pour l'API :
    #[Route('/{id}/screenshots/reorder', name: 'app_trade_reorder_screenshots', methods: ['POST'])]
    public function reorderScreenshots(Request $request, Trade $trade, EntityManagerInterface $entityManager): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        foreach ($data['order'] as $position => $screenshotId) {
            $screenshot = $entityManager->getRepository(TradeScreenshot::class)->find($screenshotId);
            if ($screenshot && $screenshot->getTrade() === $trade) {
                $screenshot->setPosition($position);
            }
        }

        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/screenshot/{id}/delete', name: 'app_trade_delete_screenshot', methods: ['DELETE'])]
    public function deleteScreenshot(TradeScreenshot $screenshot, EntityManagerInterface $entityManager, FileUploader $fileUploader): \Symfony\Component\HttpFoundation\JsonResponse
    {
        if ($screenshot->getTrade()->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        // Supprimer le fichier physique
        $fileUploader->remove($screenshot->getFilename());

        // Supprimer l'entité
        $entityManager->remove($screenshot);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/{id}', name: 'app_trade_delete', methods: ['POST'])]
    public function delete(Request $request, Trade $trade, EntityManagerInterface $entityManager, FileUploader $fileUploader): Response
    {
        if ($this->isCsrfTokenValid('delete' . $trade->getId(), $request->request->get('_token'))) {
            // Vérifier que l'utilisateur peut supprimer ce trade
            if ($trade->getUser() !== $this->getUser()) {
                throw $this->createAccessDeniedException('Vous n\'avez pas le droit de supprimer ce trade.');
            }

            // Supprimer tous les fichiers screenshots associés
            foreach ($trade->getScreenshots() as $screenshot) {
                $fileUploader->remove($screenshot->getFilename());
            }

            // Supprimer le trade de la base de données
            $entityManager->remove($trade);
            $entityManager->flush();

            $this->addFlash('success', 'Trade et ses screenshots supprimés avec succès!');
        }

        return $this->redirectToRoute('app_trade_index', [], Response::HTTP_SEE_OTHER);
    }

    private function handleFileUploads($form, $trade, $fileUploader): void
    {
        $screenshotTypes = [
            'executionScreenshots' => 'execution',
            'managementScreenshots' => 'management',
            'closingScreenshots' => 'closing'
        ];

        foreach ($screenshotTypes as $formField => $category) {
            $files = $form->get($formField)->getData();

            if ($files) {
                foreach ($files as $file) {
                    if ($file instanceof UploadedFile) {
                        $filename = $fileUploader->upload($file);
                        $fileUploader->compressImage(
                            $fileUploader->getTargetDirectory() . '/' . $filename,
                            0
                        );

                        $screenshot = new TradeScreenshot();
                        $screenshot->setFilename($filename);
                        $screenshot->setCategory($category);
                        $screenshot->setPosition(count($trade->getScreenshotsByCategory($category)));

                        $trade->addScreenshot($screenshot);
                    }
                }
            }
        }
    }
}
