<?php

namespace App\Service;

use App\Entity\CentralBank;
use App\Repository\CentralBankRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Actualise les taux directeurs de l'horloge des cycles via `claude -p` :
 * demande un JSON strict (taux et biais) pour chaque banque en base, le valide,
 * puis applique les changements. La position sur le cadran (angle, retracement)
 * n'est jamais modifiée : c'est la lecture du cycle par l'utilisateur.
 */
class CentralBankRateRefresher
{
    private const CLAUDE_TIMEOUT = 900;

    private const BANK_NAMES = [
        'Fed' => 'Federal Reserve (États-Unis)',
        'BCE' => 'Banque centrale européenne',
        'BoE' => 'Bank of England',
        'BoJ' => 'Bank of Japan',
        'SNB' => 'Banque nationale suisse',
        'BoC' => 'Bank of Canada',
        'RBA' => 'Reserve Bank of Australia',
        'RBNZ' => 'Reserve Bank of New Zealand',
    ];

    private const PROMPT_TEMPLATE = <<<'PROMPT'
Tu es un analyste macro factuel. Date du jour : %DATE%.

Pour chacune des banques centrales suivantes, trouve sur le web (sites officiels des banques centrales en priorité, puis Reuters, Bloomberg, forexlive, investinglive) :
%BANKS%

Pour chaque banque, renseigne :
- "rate" : le taux directeur principal EN VIGUEUR aujourd'hui, au format français avec virgule et espace avant le % : "4,25 %". Si la banque cible une fourchette (Fed), écris "4,00-4,25 %". Taux principal : Fed = fed funds target range ; BCE = taux de la facilité de dépôt ; BoE = Bank Rate ; BoJ = uncollateralized overnight call rate target ; SNB = SNB policy rate ; BoC = overnight rate target ; RBA = cash rate target ; RBNZ = OCR.
- "bias" : le ton actuel de la banque, exactement une valeur parmi "hawkish", "dovish", "neutre".
- "last_decision" : date de la dernière décision de politique monétaire au format YYYY-MM-DD.
- "note" : une phrase factuelle en français (≤ 140 caractères) résumant la dernière décision.

RÈGLES :
- Vérifie chaque taux sur une source datée de moins de 3 mois. Si tu n'arrives pas à confirmer un taux, mets "rate": null plutôt que d'inventer.
- Aucune prévision, aucune opinion : uniquement les faits publiés.

FORMAT DE SORTIE — IMPORTANT :
Réponds UNIQUEMENT avec un objet JSON valide, sans markdown, sans fence ```, sans texte avant ou après, exactement de cette forme :
{"banks":[{"code":"Fed","rate":"4,00-4,25 %","bias":"dovish","last_decision":"2026-09-17","note":"Baisse de 25 pb, deux autres baisses envisagées d'ici fin d'année."}]}
Les "code" doivent reprendre exactement ceux de la liste ci-dessus.
PROMPT;

    public function __construct(
        private readonly CentralBankRepository $centralBankRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Interroge claude -p et retourne sa sortie brute.
     *
     * @throws \RuntimeException si le binaire est absent ou si l'appel échoue
     */
    public function fetch(): string
    {
        $claudeBinary = (new ExecutableFinder())->find('claude');
        if ($claudeBinary === null) {
            throw new \RuntimeException('Binaire "claude" introuvable dans le PATH.');
        }

        $process = new Process(
            [$claudeBinary, '-p', $this->buildPrompt(), '--allowedTools', 'WebSearch,WebFetch', '--output-format', 'text'],
            sys_get_temp_dir(),
            timeout: self::CLAUDE_TIMEOUT
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('claude -p a échoué : '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $process->getOutput();
    }

    public function buildPrompt(): string
    {
        $lines = [];
        foreach ($this->centralBankRepository->findAllOrdered() as $bank) {
            $lines[] = sprintf('- "%s" : %s', $bank->getCode(), self::BANK_NAMES[$bank->getCode()] ?? $bank->getCode());
        }

        return strtr(self::PROMPT_TEMPLATE, [
            '%DATE%' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            '%BANKS%' => implode("\n", $lines),
        ]);
    }

    /**
     * Extrait et valide le JSON renvoyé par l'IA.
     *
     * @return array<string, array{rate: ?string, bias: ?string, last_decision: ?string, note: ?string}> indexé par code
     *
     * @throws \InvalidArgumentException si la sortie n'est pas exploitable
     */
    public function parse(string $output): array
    {
        $output = trim($output);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $output, $matches)) {
            $output = trim($matches[1]);
        }
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new \InvalidArgumentException('La sortie de claude ne contient pas de JSON.');
        }

        try {
            $data = json_decode(substr($output, $start, $end - $start + 1), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('JSON invalide : '.$e->getMessage(), 0, $e);
        }

        if (!isset($data['banks']) || !is_array($data['banks']) || $data['banks'] === []) {
            throw new \InvalidArgumentException('Le JSON ne contient pas de liste "banks".');
        }

        $parsed = [];
        foreach ($data['banks'] as $item) {
            if (!is_array($item) || !isset($item['code']) || !is_string($item['code'])) {
                continue;
            }

            $rate = isset($item['rate']) && is_string($item['rate']) ? self::normalizeRate($item['rate']) : null;
            $bias = isset($item['bias']) && in_array($item['bias'], CentralBank::BIASES, true) ? $item['bias'] : null;

            $parsed[$item['code']] = [
                'rate' => $rate,
                'bias' => $bias,
                'last_decision' => isset($item['last_decision']) && is_string($item['last_decision']) ? $item['last_decision'] : null,
                'note' => isset($item['note']) && is_string($item['note']) ? mb_substr($item['note'], 0, 200) : null,
            ];
        }

        if ($parsed === []) {
            throw new \InvalidArgumentException('Aucune banque exploitable dans le JSON.');
        }

        return $parsed;
    }

    /**
     * Applique les valeurs parsées aux banques en base et retourne la liste des
     * changements, sous la forme "Fed : taux 3,50-3,75 % → 4,00-4,25 %".
     *
     * @param array<string, array<string, ?string>> $parsed résultat de parse()
     * @param bool $dryRun n'écrit rien en base
     *
     * @return list<string>
     */
    public function apply(array $parsed, bool $dryRun = false): array
    {
        $changes = [];
        $now = new \DateTimeImmutable();

        foreach ($this->centralBankRepository->findAllOrdered() as $bank) {
            $code = $bank->getCode();
            if (!isset($parsed[$code])) {
                $changes[] = sprintf('%s : absent de la réponse, inchangé', $code);
                continue;
            }
            $values = $parsed[$code];
            $touched = false;

            if ($values['rate'] !== null && $values['rate'] !== $bank->getRate()) {
                $changes[] = sprintf('%s : taux %s → %s', $code, $bank->getRate(), $values['rate']);
                $bank->setRate($values['rate']);
                $touched = true;
            }

            if ($values['bias'] !== null && $values['bias'] !== $bank->getBias()) {
                $changes[] = sprintf('%s : biais %s → %s', $code, $bank->getBias(), $values['bias']);
                $bank->setBias($values['bias']);
                $touched = true;
            }

            if ($touched) {
                $bank->setUpdatedAt($now);
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $changes;
    }

    /**
     * Ramène "4.25%", "4,25%" ou "4.00–4.25 %" au format stocké "4,25 %" / "4,00-4,25 %".
     */
    private static function normalizeRate(string $rate): ?string
    {
        $rate = trim($rate);
        if ($rate === '') {
            return null;
        }
        $rate = str_replace(['–', '—', ' à ', ' - '], '-', $rate);
        $rate = preg_replace('/\s*%\s*$/', '', $rate) ?? $rate;
        $rate = str_replace(['.', ' '], [',', ''], $rate);

        if (!preg_match('/^-?\d+(,\d+)?(-\d+(,\d+)?)?$/', $rate)) {
            return null;
        }

        return $rate.' %';
    }
}
