<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Lance une commande `bin/console` détachée de la requête HTTP (nohup + &),
 * sa sortie allant dans var/log/<nom>.log. Sert aux traitements longs
 * (appels à claude -p) sans worker Messenger.
 */
class BackgroundConsoleLauncher
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public function launch(string $commandName, array $arguments = []): void
    {
        $php = (new PhpExecutableFinder())->find(false);
        if ($php === false) {
            throw new \RuntimeException('Binaire PHP introuvable.');
        }

        $logFile = sprintf('%s/var/log/%s.log', $this->projectDir, preg_replace('/[^a-z0-9]+/', '-', $commandName));
        $command = sprintf(
            'nohup %s >> %s 2>&1 &',
            implode(' ', array_map('escapeshellarg', [$php, 'bin/console', $commandName, ...$arguments])),
            escapeshellarg($logFile)
        );

        Process::fromShellCommandline($command, $this->projectDir, timeout: 10)->mustRun();
    }
}
