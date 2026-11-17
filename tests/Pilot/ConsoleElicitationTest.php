<?php

declare(strict_types=1);

namespace App\Tests\Pilot;

use App\Pilot\ConsoleElicitation;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsoleElicitationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ElicitAction}>
     */
    public static function answers(): iterable
    {
        yield 'yes' => ["y\n", ElicitAction::Accept];
        yield 'full yes' => ["YES\n", ElicitAction::Accept];
        yield 'no' => ["n\n", ElicitAction::Decline];
        yield 'enter defaults to no' => ["\n", ElicitAction::Decline];
        yield 'closed input' => ['', ElicitAction::Decline];
    }

    #[DataProvider('answers')]
    public function testTheTypedAnswerDecides(string $typed, ElicitAction $expected): void
    {
        $input = fopen('php://memory', 'r+');
        $output = fopen('php://memory', 'r+');
        self::assertIsResource($input);
        self::assertIsResource($output);
        fwrite($input, $typed);
        rewind($input);

        $request = ElicitRequest::forForm('Promote branch feature?', new ElicitationSchema(['approve' => new BooleanSchemaDefinition('Promote?')], ['approve']));
        $result = (new ConsoleElicitation($input, $output))($request);

        self::assertSame($expected, $result->action);
        if (ElicitAction::Accept === $expected) {
            self::assertSame(['approve' => true], $result->content);
        }
        rewind($output);
        self::assertStringContainsString('Promote branch feature?', (string) stream_get_contents($output));
    }
}
