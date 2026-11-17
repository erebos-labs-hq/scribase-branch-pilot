<?php

declare(strict_types=1);

namespace App\Scribase;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one Scribase organization/project this app is bound to. Tools never take
 * these ids as arguments: a model cannot point the server at another project,
 * and small models cannot get opaque ids wrong.
 */
final class ScribaseTarget
{
    public function __construct(
        #[Autowire(env: 'SCRIBASE_ORG')]
        private readonly string $organizationId = '',
        #[Autowire(env: 'SCRIBASE_PROJECT')]
        private readonly string $projectId = '',
    ) {
    }

    /**
     * @return array{0: string, 1: string}|array{error: string}
     */
    public function resolve(): array
    {
        if ('' === trim($this->organizationId) || '' === trim($this->projectId)) {
            return ['error' => 'No Scribase project configured. Set SCRIBASE_ORG and SCRIBASE_PROJECT, or run "bin/console pilot:bootstrap".'];
        }

        return [trim($this->organizationId), trim($this->projectId)];
    }

    public static function validId(string $id): bool
    {
        return 1 === preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $id);
    }
}
