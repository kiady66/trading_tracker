<?php

namespace App\Controller;

use App\Entity\BiasPair;
use App\Entity\CentralBank;
use App\Entity\CurrencyNote;
use App\Entity\User;
use App\Repository\CentralBankRepository;
use App\Repository\CurrencyNoteRepository;
use App\Service\BiasPairList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page /biais : une carte de notes par devise, le biais de sa banque centrale
 * (partagé avec /cycles) et la liste ordonnable des paires hawkish/dovish.
 */
#[Route('/biais', name: 'app_bias_')]
#[IsGranted('ROLE_USER')]
class BiasController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'bias';
    private const NOTE_MAX_LENGTH = 100000;

    public function __construct(
        private readonly BiasPairList $pairList,
        private readonly CentralBankRepository $centralBanks,
        private readonly CurrencyNoteRepository $notes,
        private readonly EntityManagerInterface $entityManager,
        // Alias "$currencyNote" généré par framework.html_sanitizer.sanitizers.currency_note
        private readonly HtmlSanitizerInterface $currencyNote,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $banks = [];
        foreach ($this->centralBanks->findAll() as $bank) {
            $banks[$bank->getCode()] = $bank;
        }
        $notes = $this->notes->findByUserIndexedByCurrency($user);

        $cards = [];
        foreach (BiasPairList::CURRENCY_BANKS as $currency => $bankCode) {
            $bank = $banks[$bankCode] ?? null;
            $note = $notes[$currency] ?? null;
            $cards[] = [
                'currency' => $currency,
                'bank' => $bankCode,
                'missing' => $bank === null,
                'rate' => $bank?->getRate(),
                'bias' => $bank?->getBias() ?? 'neutre',
                'biasUpdatedAt' => $bank?->getUpdatedAt(),
                'note' => $note?->getContent() ?? '',
                'noteUpdatedAt' => $note?->getUpdatedAt(),
            ];
        }

        return $this->render('bias/index.html.twig', [
            'cards' => $cards,
            'biases' => CentralBank::BIASES,
            'pairs' => $this->serializePairs($this->pairList->sync($user)),
            'csrf_token' => $csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]);
    }

    /**
     * Change le biais de la banque centrale de la devise (donc aussi sur
     * /cycles) et renvoie la liste des paires recalculée.
     */
    #[Route('/{currency}/biais', name: 'bias', requirements: ['currency' => '[A-Z]{3}'], methods: ['POST'])]
    public function bias(string $currency, Request $request): JsonResponse
    {
        $payload = $request->toArray();
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $payload['_token'] ?? '')) {
            return $this->json(['error' => 'Jeton CSRF invalide.'], 419);
        }

        $bankCode = BiasPairList::CURRENCY_BANKS[$currency] ?? null;
        $bank = $bankCode === null ? null : $this->centralBanks->findOneBy(['code' => $bankCode]);
        if ($bank === null) {
            return $this->json(['error' => sprintf('Aucune banque centrale en base pour %s.', $currency)], 404);
        }

        $bias = (string) ($payload['bias'] ?? '');
        if (!in_array($bias, CentralBank::BIASES, true)) {
            return $this->json(['error' => 'Biais invalide.'], 422);
        }

        $bank->setBias($bias)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        /** @var User $user */
        $user = $this->getUser();

        return $this->json([
            'bias' => $bias,
            'updatedAt' => $bank->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'pairs' => $this->serializePairs($this->pairList->sync($user)),
        ]);
    }

    #[Route('/{currency}/note', name: 'note', requirements: ['currency' => '[A-Z]{3}'], methods: ['POST'])]
    public function note(string $currency, Request $request): JsonResponse
    {
        $payload = $request->toArray();
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $payload['_token'] ?? '')) {
            return $this->json(['error' => 'Jeton CSRF invalide.'], 419);
        }
        if (!isset(BiasPairList::CURRENCY_BANKS[$currency])) {
            return $this->json(['error' => 'Devise inconnue.'], 404);
        }

        $raw = $payload['note'] ?? '';
        if (!is_string($raw) || mb_strlen($raw) > self::NOTE_MAX_LENGTH) {
            return $this->json(['error' => 'Note trop longue.'], 422);
        }

        $content = $this->currencyNote->sanitize($raw);
        // L'éditeur vide renvoie "<p><br></p>" : on ne garde pas de coquille
        if (trim(strip_tags($content)) === '') {
            $content = '';
        }

        /** @var User $user */
        $user = $this->getUser();
        $note = $this->notes->findOneBy(['user' => $user, 'currency' => $currency])
            ?? new CurrencyNote($user, $currency);
        $note->setContent($content);
        $this->entityManager->persist($note);
        $this->entityManager->flush();

        return $this->json([
            'note' => $content,
            'updatedAt' => $note->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Ordre choisi par glisser-déposer. 409 avec la liste courante si les
     * paires ont changé entre-temps (biais modifié dans un autre onglet).
     */
    #[Route('/paires/ordre', name: 'pairs_order', methods: ['POST'])]
    public function pairsOrder(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $payload['_token'] ?? '')) {
            return $this->json(['error' => 'Jeton CSRF invalide.'], 419);
        }
        if (!is_array($payload['pairs'] ?? null)) {
            return $this->json(['error' => 'Liste de paires manquante.'], 422);
        }

        /** @var User $user */
        $user = $this->getUser();

        try {
            $pairs = $this->pairList->reorder($user, array_values($payload['pairs']));
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'pairs' => $this->serializePairs($this->pairList->sync($user)),
            ], 409);
        }

        return $this->json(['saved' => true, 'pairs' => $this->serializePairs($pairs)]);
    }

    /**
     * @param list<BiasPair> $pairs
     * @return list<array{pair: string, base: string, quote: string, baseBias: string, quoteBias: string}>
     */
    private function serializePairs(array $pairs): array
    {
        $biases = $this->pairList->biases();

        return array_map(static function (BiasPair $biasPair) use ($biases): array {
            [$base, $quote] = explode('/', (string) $biasPair->getPair());

            return [
                'pair' => $biasPair->getPair(),
                'base' => $base,
                'quote' => $quote,
                'baseBias' => $biases[$base],
                'quoteBias' => $biases[$quote],
            ];
        }, $pairs);
    }
}
