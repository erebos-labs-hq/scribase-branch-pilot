<?php

declare(strict_types=1);

namespace App\Command;

use App\Scribase\TemporaryProjectProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('pilot:bootstrap', 'Mint a free, no-login Scribase temporary project (72h) and write its credentials to .env.local.')]
final class BootstrapCommand
{
    public function __construct(
        private readonly TemporaryProjectProvisioner $provisioner,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Project name')] string $name = 'branch-pilot', #[Option('Overwrite existing SCRIBASE_* values')] bool $force = false): int
    {
        $envFile = $this->projectDir.'/.env.local';
        $existing = is_file($envFile) ? (string) file_get_contents($envFile) : '';
        if (!$force && preg_match('/^SCRIBASE_API_KEY=.+$/m', $existing)) {
            $io->warning('.env.local already has SCRIBASE_API_KEY. Use --force to mint a new temporary project.');

            return 0;
        }

        $io->title('Provisioning a free Scribase temporary project');
        try {
            $project = $this->provisioner->provision($name, static fn (string $line) => $io->writeln('  '.$line));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $values = [
            'SCRIBASE_API_KEY' => $project['agent_token'],
            'SCRIBASE_ORG' => $project['organization_id'],
            'SCRIBASE_PROJECT' => $project['project_id'],
        ];
        foreach ($values as $key => $value) {
            $line = $key.'='.$value;
            $existing = preg_match("/^{$key}=.*$/m", $existing)
                ? (string) preg_replace("/^{$key}=.*$/m", $line, $existing)
                : rtrim($existing)."\n".$line."\n";
        }
        file_put_contents($envFile, ltrim($existing));
        @chmod($envFile, 0600);

        $io->success(\sprintf('Ready: %s/%s (environment "%s"). Credentials written to .env.local (never commit it). The project expires in 72h unless claimed.', $project['organization_id'], $project['project_id'], $project['environment_id']));

        return 0;
    }
}
