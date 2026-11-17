<?php

declare(strict_types=1);

namespace App\Command;

use App\Pilot\HumanApproval;
use App\Pilot\PromotionGate;
use App\Scribase\BranchService;
use App\Scribase\ScribaseTarget;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('pilot:promote', 'Human-only: review the merge plan of a branch and promote it after confirmation.')]
final class PromoteCommand
{
    public function __construct(
        private readonly PromotionGate $gate,
        private readonly ScribaseTarget $target,
    ) {
    }

    public function __invoke(SymfonyStyle $io, InputInterface $input, #[Argument('Branch to promote')] string $branch, #[Option('Base environment')] string $base = BranchService::PRODUCTION): int
    {
        $target = $this->target->resolve();
        if (isset($target['error'])) {
            $io->error($target['error']);

            return 1;
        }

        $approval = new class($io, $input->isInteractive()) implements HumanApproval {
            public function __construct(private readonly SymfonyStyle $io, private readonly bool $interactive)
            {
            }

            public function confirm(string $question): ?bool
            {
                if (!$this->interactive) {
                    return null;
                }

                $this->io->writeln($question);

                return $this->io->confirm('Promote?', false);
            }
        };

        $result = $this->gate->promote($target[0], $target[1], $branch, $base, $approval);
        $io->writeln((string) json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        return isset($result['error']) ? 1 : 0;
    }
}
