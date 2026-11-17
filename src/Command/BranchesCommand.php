<?php

declare(strict_types=1);

namespace App\Command;

use App\Scribase\BranchService;
use App\Scribase\ScribaseTarget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pilot:branches', 'List the open branches of the project, optionally discarding them.')]
final class BranchesCommand
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly ScribaseTarget $target,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Discard every open branch')] bool $discard = false): int
    {
        $target = $this->target->resolve();
        if (isset($target['error'])) {
            $io->error($target['error']);

            return 1;
        }

        $branches = $this->branches->list($target[0], $target[1]);
        if (isset($branches['error'])) {
            $io->error((string) json_encode($branches));

            return 1;
        }
        if ([] === $branches) {
            $io->success('No open branches.');

            return 0;
        }

        $io->table(['branch', 'base', 'created', 'merged'], array_map(static fn (array $b): array => [
            $b['branch_id'], $b['base'], null !== $b['created_at'] ? date('Y-m-d H:i', $b['created_at']) : '?', $b['merged'] ? 'yes' : 'no',
        ], $branches));

        if ($discard) {
            foreach ($branches as $branch) {
                $result = $this->branches->discard($target[0], $target[1], $branch['branch_id']);
                $io->writeln(\sprintf('%s: %s', $branch['branch_id'], \is_string($result['status'] ?? null) ? $result['status'] : (string) json_encode($result)));
            }
        }

        return 0;
    }
}
