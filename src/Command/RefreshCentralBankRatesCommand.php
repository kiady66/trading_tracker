<?php

namespace App\Command;

use App\Service\CentralBankRateRefresher;
use App\Service\CentralBankRefreshStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cycles:refresh',
    description: 'Actualise les taux directeurs de l\'horloge des cycles via claude -p (recherche web)'
)]
class RefreshCentralBankRatesCommand extends Command
{
    public function __construct(
        private readonly CentralBankRateRefresher $refresher,
        private readonly CentralBankRefreshStatus $status,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les changements sans les enregistrer')
            ->addOption('from-file', null, InputOption::VALUE_REQUIRED, 'Lit la réponse JSON depuis un fichier au lieu d\'appeler claude');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $fromFile = $input->getOption('from-file');

        $io->title(sprintf('Actualisation des taux directeurs — %s', (new \DateTimeImmutable())->format('d/m/Y H:i')));

        if (!$dryRun) {
            $this->status->ensureRunning();
        }

        try {
            if ($fromFile !== null) {
                $raw = @file_get_contents($fromFile);
                if ($raw === false) {
                    throw new \RuntimeException(sprintf('Fichier illisible : %s', $fromFile));
                }
            } else {
                $io->text('Exécution de claude -p (peut prendre plusieurs minutes)...');
                $raw = $this->refresher->fetch();
            }

            $parsed = $this->refresher->parse($raw);
            $changes = $this->refresher->apply($parsed, $dryRun);
        } catch (\Throwable $e) {
            if (!$dryRun) {
                $this->status->markError($e->getMessage());
            }
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($parsed as $code => $values) {
            $io->text(sprintf('<info>%s</info>  %s · %s%s', $code, $values['rate'] ?? '?', $values['bias'] ?? '?', $values['note'] ? ' — '.$values['note'] : ''));
        }

        $io->section($dryRun ? 'Changements (non enregistrés)' : 'Changements enregistrés');
        $io->listing($changes ?: ['Aucun changement.']);

        if (!$dryRun) {
            $this->status->markDone($changes);
        }

        return Command::SUCCESS;
    }
}
