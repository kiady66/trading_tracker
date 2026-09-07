<?php

namespace App\Controller;

use App\Entity\CentralBank;
use App\Repository\CentralBankRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/cycles', name: 'app_cycles_')]
#[IsGranted('ROLE_USER')]
class CyclesController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        CentralBankRepository $centralBankRepository,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): Response {
        $banks = array_map(self::serialize(...), $centralBankRepository->findAllOrdered());

        return $this->render('cycles/index.html.twig', [
            'banks' => $banks,
            'csrf_token' => $csrfTokenManager->getToken('cycles')->getValue(),
        ]);
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(
        Request $request,
        CentralBankRepository $centralBankRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $payload = $request->toArray();

        if (!$this->isCsrfTokenValid('cycles', $payload['_token'] ?? '')) {
            return $this->json(['error' => 'Jeton CSRF invalide.'], 419);
        }

        $items = $payload['banks'] ?? null;
        if (!is_array($items)) {
            return $this->json(['error' => 'Aucune donnée à enregistrer.'], 422);
        }

        $now = new \DateTimeImmutable();
        $updated = 0;

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['code'])) {
                continue;
            }

            $bank = $centralBankRepository->findOneBy(['code' => (string) $item['code']]);
            if ($bank === null) {
                continue;
            }

            $rate = trim((string) ($item['rate'] ?? $bank->getRate()));
            $bias = (string) ($item['bias'] ?? $bank->getBias());
            $angle = $item['angle'] ?? $bank->getAngle();

            if ($rate === '' || mb_strlen($rate) > 30 || !is_numeric($angle)
                || !in_array($bias, CentralBank::BIASES, true)) {
                return $this->json(['error' => sprintf('Données invalides pour %s.', $bank->getCode())], 422);
            }

            $bank->setRate($rate)
                ->setAngle((float) $angle)
                ->setBias($bias)
                ->setRetracement((bool) ($item['retracement'] ?? $bank->isRetracement()))
                ->setUpdatedAt($now);
            $updated++;
        }

        $entityManager->flush();

        return $this->json(['saved' => true, 'updated' => $updated]);
    }

    private static function serialize(CentralBank $bank): array
    {
        return [
            'code' => $bank->getCode(),
            'rate' => $bank->getRate(),
            'angle' => $bank->getAngle(),
            'bias' => $bank->getBias(),
            'retracement' => $bank->isRetracement(),
        ];
    }
}
