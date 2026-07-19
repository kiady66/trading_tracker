<?php

namespace App\Command;

use App\Entity\DailyNews;
use App\Repository\DailyNewsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:news:generate',
    description: 'Génère le wrap quotidien des news de marché via claude -p et l\'enregistre en base'
)]
class GenerateDailyNewsCommand extends Command
{
    private const CLAUDE_TIMEOUT = 900;

    private const PROMPT_TEMPLATE = <<<'PROMPT'
Tu es un rédacteur financier factuel. Rédige le wrap des news de marché du %DATE_LONG% (date du jour : %DATE%).

SOURCES : recherche les news de cette journée en priorité sur https://investinglive.com/ et https://www.forexlive.com/, complète avec d'autres sources reconnues (Reuters, CNBC, Investing.com, Bloomberg). Ne couvre que la journée du %DATE%.

PÉRIMÈTRE — uniquement les NEWS qui peuvent faire bouger ces marchés :
- Forex : paires majeures (EUR/USD, GBP/USD, USD/JPY, etc.) et dollar index
- Or (XAU/USD)
- Pétrole (WTI, Brent)
- Indices boursiers américains (S&P 500, Nasdaq, Dow Jones)
- Calendrier économique du jour : chiffres publiés vs consensus vs précédent
- Banques centrales : décisions, discours, minutes
- Géopolitique / politique seulement si impact marché

RÈGLES ÉDITORIALES STRICTES :
- AUCUNE opinion personnelle, AUCUNE prévision, AUCUN conseil de trading, AUCUNE anticipation.
- Uniquement les faits : ce qui a été publié, dit, décidé.
- Le rapport est organisé PAR NEWS / PAR THÈME, jamais par actif.
- NE FAIS PAS de résumé de la performance journalière des actifs (pas de « le S&P 500 a perdu 1 % », « EUR/USD a gagné 0,3 % » isolés) : le lecteur est un trader qui suit déjà les prix en temps réel. Ne mentionne un mouvement de prix QUE s'il est la réaction directe à une news décrite dans le rapport (ex : « après la publication du CPI, l'or a gagné 1,2 % à 2 450 $ »), en l'intégrant dans la card de cette news.
- Chiffres précis dès que possible (valeurs publiées, consensus, niveaux de prix lors des réactions).
- Rédaction en français ; les termes de marché usuels restent en anglais (NFP, CPI, hawkish, dovish, risk-on…).

FORMAT DE SORTIE — IMPORTANT :
- Réponds UNIQUEMENT avec un fragment HTML. Pas de markdown, pas de fence ```, pas de <html>, <head>, <body>, <style> ni <script>. Aucun texte avant ou après le HTML.
- Structure attendue :
  - Commence par <section class="news-callout"> : « L'essentiel du jour » en 3 à 5 bullet points (<ul><li>) des infos les plus importantes.
  - Ensuite une <section> avec un <h2> par news ou thème majeur de la journée (ex : « CPI US », « Réunion FOMC », « Tensions au Moyen-Orient »), de la plus importante à la moins importante. Une section Calendrier économique ferme le rapport.
  - Le calendrier économique est un <table> avec les colonnes : Heure (ET), Événement, Publié, Consensus, Précédent.
  - Dans chaque section, utilise des <div class="news-card"> avec un <h3> par sous-sujet si nécessaire ; la réaction du marché à la news est décrite dans la card de la news, pas dans une section à part.
  - Utilise des <ul><li> pour les points secondaires, <strong> pour les chiffres et infos clés.
  - Entoure les variations avec <span class="up"> (hausse) ou <span class="down"> (baisse), ex : <span class="up">+1,2 %</span>.
  - N'utilise AUCUNE autre classe CSS que : news-callout, news-card, up, down.
- Si une information n'est pas disponible dans tes recherches, ne l'invente pas.
PROMPT;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DailyNewsRepository $dailyNewsRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Date du wrap (YYYY-MM-DD), défaut : aujourd\'hui');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $date = new \DateTimeImmutable($input->getOption('date') ?? 'today');
        } catch (\Exception) {
            $io->error(sprintf('Date invalide : "%s" (format attendu : YYYY-MM-DD)', $input->getOption('date')));

            return Command::INVALID;
        }
        $date = $date->setTime(0, 0);

        $claudeBinary = (new ExecutableFinder())->find('claude');
        if ($claudeBinary === null) {
            $io->error('Binaire "claude" introuvable dans le PATH.');

            return Command::FAILURE;
        }

        $io->title(sprintf('Génération du wrap news du %s', $date->format('d/m/Y')));
        $io->text('Exécution de claude -p (peut prendre plusieurs minutes)...');

        $prompt = strtr(self::PROMPT_TEMPLATE, [
            '%DATE%' => $date->format('Y-m-d'),
            '%DATE_LONG%' => $this->formatDateLongFr($date),
        ]);

        $process = new Process(
            [$claudeBinary, '-p', $prompt, '--allowedTools', 'WebSearch,WebFetch', '--output-format', 'text'],
            sys_get_temp_dir(),
            timeout: self::CLAUDE_TIMEOUT
        );
        $process->run();

        if (!$process->isSuccessful()) {
            $io->error('claude -p a échoué : '.trim($process->getErrorOutput() ?: $process->getOutput()));

            return Command::FAILURE;
        }

        $html = $this->cleanOutput($process->getOutput());

        if ($html === '' || !str_contains($html, '<')) {
            $io->error('La sortie de claude ne contient pas de HTML exploitable.');
            $io->section('Sortie brute');
            $io->writeln(substr($process->getOutput(), 0, 2000));

            return Command::FAILURE;
        }

        $news = $this->dailyNewsRepository->findOneByDate($date);
        $isNew = $news === null;

        if ($isNew) {
            $news = new DailyNews();
            $news->setDate($date);
            $this->entityManager->persist($news);
        }

        $news->setContentHtml($html);
        $news->setGeneratedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $io->success(sprintf(
            'Wrap du %s %s (%d caractères).',
            $date->format('d/m/Y'),
            $isNew ? 'créé' : 'remplacé',
            mb_strlen($html)
        ));

        return Command::SUCCESS;
    }

    /**
     * Retire les éventuelles fences markdown et le texte hors HTML autour du fragment.
     */
    private function cleanOutput(string $output): string
    {
        $output = trim($output);

        if (preg_match('/^```(?:html)?\s*(.*?)\s*```$/s', $output, $matches)) {
            $output = trim($matches[1]);
        }

        $firstTag = strpos($output, '<');
        $lastClose = strrpos($output, '>');
        if ($firstTag !== false && $lastClose !== false && $lastClose > $firstTag) {
            $output = substr($output, $firstTag, $lastClose - $firstTag + 1);
        }

        return trim($output);
    }

    private function formatDateLongFr(\DateTimeImmutable $date): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE);

        return $formatter->format($date);
    }
}