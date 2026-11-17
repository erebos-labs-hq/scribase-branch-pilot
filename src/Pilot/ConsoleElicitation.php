<?php

declare(strict_types=1);

namespace App\Pilot;

use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;

/**
 * Client side of the human-in-the-loop: when the MCP server sends an
 * `elicitation/create` request (for example before promoting a branch), the
 * question is printed on the terminal and only the human's typed answer goes
 * back. The LLM never sees or answers this request.
 */
final class ConsoleElicitation implements ElicitationCallbackInterface
{
    /**
     * @param resource|null $input
     * @param resource|null $output
     */
    public function __construct(private $input = null, private $output = null)
    {
    }

    public function __invoke(ElicitRequest $request): ElicitResult
    {
        $input = $this->input ?? \STDIN;
        $output = $this->output ?? \STDERR;

        $schema = $request->requestedSchema?->jsonSerialize() ?? [];
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        fwrite($output, "\n\033[1;33m[human approval requested by the MCP server]\033[0m\n".$request->message."\n");
        fwrite($output, 'Approve? [y/N] ');
        $answer = fgets($input);
        $yes = \is_string($answer) && \in_array(strtolower(trim($answer)), ['y', 'yes'], true);
        fwrite($output, $yes ? "-> approved\n\n" : "-> declined\n\n");

        if (!$yes) {
            return new ElicitResult(ElicitAction::Decline);
        }

        $content = [];
        foreach ($properties as $name => $definition) {
            if (\is_array($definition) && 'boolean' === ($definition['type'] ?? null)) {
                $content[$name] = true;
            }
        }

        return new ElicitResult(ElicitAction::Accept, $content);
    }
}
