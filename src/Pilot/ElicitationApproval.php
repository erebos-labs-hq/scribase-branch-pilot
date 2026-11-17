<?php

declare(strict_types=1);

namespace App\Pilot;

use Mcp\Exception\ClientException;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Server\ClientGateway;

/**
 * Asks the human sitting behind the MCP client, via MCP elicitation
 * (`elicitation/create`). The model cannot answer this request: it goes to the client UI.
 */
final class ElicitationApproval implements HumanApproval
{
    public function __construct(private readonly ClientGateway $gateway)
    {
    }

    public function confirm(string $question): ?bool
    {
        if (!$this->gateway->supportsElicitation()) {
            return null;
        }

        try {
            $result = $this->gateway->elicit($question, new ElicitationSchema(
                ['approve' => new BooleanSchemaDefinition('Promote this branch?', 'Tick to merge the branch into its base environment.', false)],
                ['approve'],
            ), 600);
        } catch (ClientException) {
            return null;
        }

        return $result->isAccepted() && true === ($result->content['approve'] ?? null);
    }
}
