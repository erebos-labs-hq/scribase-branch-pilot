<?php

declare(strict_types=1);

namespace App\Command;

use App\Mcp\Prompt\ReviewMigrationPrompt;
use App\Pilot\ToolTrace;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[AsCommand('pilot:review', 'Let the Symfony AI agent rehearse a migration on a throwaway branch through MCP; you approve any promotion.')]
final class ReviewCommand
{
    public function __construct(
        #[Target('pilot_hf')]
        private readonly AgentInterface $huggingFace,
        #[Target('pilot_ollama')]
        private readonly AgentInterface $ollama,
        #[Autowire(env: 'PILOT_PLATFORM')]
        private readonly string $defaultPlatform,
        private readonly ToolTrace $trace,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path to a .sql migration')] string $file,
        #[Option('What the change is for')] string $goal = 'not specified',
        #[Option('huggingface or ollama (defaults to PILOT_PLATFORM)')] ?string $platform = null,
    ): int {
        $sql = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if ('' === $sql) {
            $io->error("Cannot read SQL from {$file}.");

            return 1;
        }

        $platform ??= $this->defaultPlatform;
        $agent = match ($platform) {
            'huggingface', 'hf' => $this->huggingFace,
            'ollama' => $this->ollama,
            default => null,
        };
        if (null === $agent) {
            $io->error('Unknown platform "'.$platform.'". Use huggingface or ollama.');

            return 1;
        }

        $io->title(\sprintf('Branch Pilot agent (%s) reviewing %s', $platform, basename($file)));
        $this->trace->attach($io);
        try {
            $result = $agent->call(new MessageBag(Message::ofUser(ReviewMigrationPrompt::text($sql, $goal))));
            // Results are lazy: transport and API errors surface when the content is read.
            $content = $result->getContent();
        } catch (\Throwable $e) {
            $io->error([
                'The model run failed: '.$e->getMessage(),
                'Nothing was promoted: only a human "yes" at the promotion gate can merge. Run pilot:branches to see or discard a leftover branch.',
                'Hugging Face: the token needs the "Make calls to Inference Providers" permission and the account needs credits (HTTP 402 otherwise). Small local models often emit broken tool calls; use a larger model.',
            ]);

            return 1;
        }

        $io->section('Agent report');
        $io->writeln(\is_string($content) ? $content : (string) json_encode($content, \JSON_PRETTY_PRINT));

        return 0;
    }
}
