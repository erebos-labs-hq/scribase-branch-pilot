<?php

declare(strict_types=1);

namespace App\Pilot;

use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Prints every tool call the agent makes (through MCP) while `pilot:review` runs,
 * so you can watch what the model actually does instead of trusting its summary.
 */
final class ToolTrace
{
    private ?OutputInterface $output = null;

    public function attach(OutputInterface $output): void
    {
        $this->output = $output;
    }

    #[AsEventListener]
    public function onSuccess(ToolCallSucceeded $event): void
    {
        $result = $event->getResult()->getResult();
        $status = \is_array($result) && \is_string($result['status'] ?? null) ? $result['status'] : 'ok';
        if (\is_array($result) && \is_string($result['branch_id'] ?? null)) {
            $status .= ' (branch '.$result['branch_id'].')';
        }
        $this->line('<info>✔</info>', $event->getDefinition()->getName(), $event->getArguments(), $status);
    }

    #[AsEventListener]
    public function onFailure(ToolCallFailed $event): void
    {
        $this->line('<error>✘</error>', $event->getDefinition()->getName(), $event->getArguments(), self::short($event->getException()->getMessage(), 220));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function line(string $mark, string $tool, array $arguments, string $outcome): void
    {
        $this->output?->writeln(\sprintf('%s <comment>%s</comment>(%s) -> %s', $mark, $tool, self::short((string) json_encode($arguments, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 140), $outcome));
    }

    private static function short(string $text, int $max): string
    {
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
