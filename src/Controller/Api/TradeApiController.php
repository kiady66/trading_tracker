<?php

namespace App\Controller\Api;

use App\Entity\Confluence;
use App\Entity\Timeframe;
use App\Entity\Trade;
use App\Entity\TradeError;
use App\Entity\TradeType;
use App\Entity\Trend;
use App\Repository\TradeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/trades', name: 'api_trade_')]
class TradeApiController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request, TradeRepository $tradeRepository): JsonResponse
    {
        $criteria = ['user' => $this->getUser()];

        if ($status = $request->query->get('status')) {
            $criteria['status'] = $status;
        }

        if ($asset = $request->query->get('asset')) {
            $criteria['asset'] = $asset;
        }

        if ($ctraderPositionId = $request->query->get('ctraderPositionId')) {
            $criteria['ctraderPositionId'] = (int) $ctraderPositionId;
        }

        $trades = $tradeRepository->findBy($criteria, ['watchlistDate' => 'DESC']);

        return $this->json(array_map($this->serializeTrade(...), $trades));
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Trade $trade): JsonResponse
    {
        if ($trade->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->serializeTrade($trade));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if ($data === null) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $trade = new Trade();
        $trade->setUser($this->getUser());

        $errors = $this->hydrateTrade($trade, $data, $em, false);
        if ($errors) {
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $trade->calculateStatus();
        $trade->calculateDay();
        $trade->calculateGainRR();
        $trade->calculateGainEuro();

        $em->persist($trade);
        $em->flush();

        return $this->json($this->serializeTrade($trade), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(Request $request, Trade $trade, EntityManagerInterface $em): JsonResponse
    {
        if ($trade->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if ($data === null) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $errors = $this->hydrateTrade($trade, $data, $em, $request->getMethod() === 'PATCH');
        if ($errors) {
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $trade->calculateStatus();
        $trade->calculateDay();
        $trade->calculateGainRR();
        $trade->calculateGainEuro();

        $em->flush();

        return $this->json($this->serializeTrade($trade));
    }

    private function hydrateTrade(Trade $trade, array $data, EntityManagerInterface $em, bool $partial): array
    {
        $errors = [];

        if (!$partial || array_key_exists('asset', $data)) {
            if (!isset($data['asset'])) {
                $errors['asset'] = 'Asset is required';
            } else {
                try {
                    $trade->setAsset($data['asset']);
                } catch (\InvalidArgumentException $e) {
                    $errors['asset'] = $e->getMessage();
                }
            }
        }

        $allowedOrderTypes = ['buy market', 'sell market', 'buy limit', 'sell limit', 'buy stop', 'sell stop'];
        if (!$partial || array_key_exists('orderType', $data)) {
            if (!isset($data['orderType'])) {
                $errors['orderType'] = 'Order type is required';
            } elseif (!in_array($data['orderType'], $allowedOrderTypes, true)) {
                $errors['orderType'] = 'Invalid order type. Allowed: ' . implode(', ', $allowedOrderTypes);
            } else {
                $trade->setOrderType($data['orderType']);
            }
        }

        if (!$partial || array_key_exists('riskPercentage', $data)) {
            if (!isset($data['riskPercentage'])) {
                $errors['riskPercentage'] = 'Risk percentage is required';
            } elseif (!is_numeric($data['riskPercentage'])) {
                $errors['riskPercentage'] = 'Risk percentage must be numeric';
            } else {
                $trade->setRiskPercentage((float) $data['riskPercentage']);
            }
        }

        if (!$partial || array_key_exists('maxRiskEuro', $data)) {
            if (!isset($data['maxRiskEuro'])) {
                $errors['maxRiskEuro'] = 'Max risk in euros is required';
            } elseif (!is_numeric($data['maxRiskEuro'])) {
                $errors['maxRiskEuro'] = 'Max risk in euros must be numeric';
            } else {
                $trade->setMaxRiskEuro((float) $data['maxRiskEuro']);
            }
        }

        if (array_key_exists('entryDate', $data)) {
            try {
                $trade->setEntryDate($data['entryDate'] ? new \DateTime($data['entryDate']) : null);
            } catch (\Exception) {
                $errors['entryDate'] = 'Invalid date format';
            }
        }

        if (array_key_exists('exitDate', $data)) {
            try {
                $trade->setExitDate($data['exitDate'] ? new \DateTime($data['exitDate']) : null);
            } catch (\Exception) {
                $errors['exitDate'] = 'Invalid date format';
            }
        }

        if (array_key_exists('watchlistDate', $data) && $data['watchlistDate']) {
            try {
                $trade->setWatchlistDate(new \DateTime($data['watchlistDate']));
            } catch (\Exception) {
                $errors['watchlistDate'] = 'Invalid date format';
            }
        }

        if (array_key_exists('initialRR', $data)) {
            $trade->setInitialRR($data['initialRR'] !== null ? (float) $data['initialRR'] : null);
        }

        if (array_key_exists('finalRR', $data)) {
            $trade->setFinalRR($data['finalRR'] !== null ? (float) $data['finalRR'] : null);
        }

        if (array_key_exists('tradeManagement', $data)) {
            $trade->setTradeManagement((bool) $data['tradeManagement']);
        }

        if (array_key_exists('goodTrade', $data)) {
            $trade->setGoodTrade($data['goodTrade'] !== null ? (bool) $data['goodTrade'] : null);
        }

        if (array_key_exists('tradeQuality', $data)) {
            $trade->setTradeQuality($data['tradeQuality'] !== null ? (int) $data['tradeQuality'] : null);
        }

        if (array_key_exists('executionReason', $data)) {
            $trade->setExecutionReason($data['executionReason']);
        }

        if (array_key_exists('noteErrors', $data)) {
            $trade->setNoteErrors($data['noteErrors']);
        }

        if (array_key_exists('ctraderPositionId', $data)) {
            $trade->setCtraderPositionId($data['ctraderPositionId'] !== null ? (int) $data['ctraderPositionId'] : null);
        }

        if (array_key_exists('tradeTypeId', $data)) {
            $tradeType = $data['tradeTypeId'] ? $em->find(TradeType::class, $data['tradeTypeId']) : null;
            if ($data['tradeTypeId'] && !$tradeType) {
                $errors['tradeTypeId'] = 'Trade type not found';
            } else {
                $trade->setTradeType($tradeType);
            }
        }

        if (array_key_exists('trendId', $data)) {
            $trend = $data['trendId'] ? $em->find(Trend::class, $data['trendId']) : null;
            if ($data['trendId'] && !$trend) {
                $errors['trendId'] = 'Trend not found';
            } else {
                $trade->setTrend($trend);
            }
        }

        if (array_key_exists('errorId', $data)) {
            $tradeError = $data['errorId'] ? $em->find(TradeError::class, $data['errorId']) : null;
            if ($data['errorId'] && !$tradeError) {
                $errors['errorId'] = 'Trade error not found';
            } else {
                $trade->setError($tradeError);
            }
        }

        if (array_key_exists('timeframeIds', $data)) {
            foreach ($trade->getTimeframes()->toArray() as $tf) {
                $trade->removeTimeframe($tf);
            }
            foreach ((array) $data['timeframeIds'] as $tfId) {
                $tf = $em->find(Timeframe::class, $tfId);
                if ($tf) {
                    $trade->addTimeframe($tf);
                }
            }
        }

        if (array_key_exists('confluenceIds', $data)) {
            foreach ($trade->getConfluences()->toArray() as $c) {
                $trade->removeConfluence($c);
            }
            foreach ((array) $data['confluenceIds'] as $cId) {
                $c = $em->find(Confluence::class, $cId);
                if ($c) {
                    $trade->addConfluence($c);
                }
            }
        }

        return $errors;
    }

    private function serializeTrade(Trade $trade): array
    {
        return [
            'id' => $trade->getId(),
            'asset' => $trade->getAsset(),
            'status' => $trade->getStatus(),
            'entryDate' => $trade->getEntryDate()?->format(\DateTime::ATOM),
            'exitDate' => $trade->getExitDate()?->format(\DateTime::ATOM),
            'watchlistDate' => $trade->getWatchlistDate()?->format(\DateTime::ATOM),
            'day' => $trade->getDay(),
            'orderType' => $trade->getOrderType(),
            'riskPercentage' => $trade->getRiskPercentage(),
            'initialRR' => $trade->getInitialRR(),
            'finalRR' => $trade->getFinalRR(),
            'gainRR' => $trade->getGainRR(),
            'gainEuro' => $trade->getGainEuro(),
            'maxRiskEuro' => $trade->getMaxRiskEuro(),
            'tradeManagement' => $trade->isTradeManagement(),
            'goodTrade' => $trade->isGoodTrade(),
            'tradeQuality' => $trade->getTradeQuality(),
            'executionReason' => $trade->getExecutionReason(),
            'noteErrors' => $trade->getNoteErrors(),
            'ctraderPositionId' => $trade->getCtraderPositionId(),
            'tradeType' => $trade->getTradeType() ? [
                'id' => $trade->getTradeType()->getId(),
                'name' => $trade->getTradeType()->getName(),
            ] : null,
            'trend' => $trade->getTrend() ? [
                'id' => $trade->getTrend()->getId(),
                'name' => $trade->getTrend()->getName(),
            ] : null,
            'error' => $trade->getError() ? [
                'id' => $trade->getError()->getId(),
                'name' => $trade->getError()->getName(),
            ] : null,
            'timeframes' => $trade->getTimeframes()->map(fn($tf) => [
                'id' => $tf->getId(),
                'name' => $tf->getName(),
            ])->toArray(),
            'confluences' => $trade->getConfluences()->map(fn($c) => [
                'id' => $c->getId(),
                'name' => $c->getName(),
            ])->toArray(),
        ];
    }
}