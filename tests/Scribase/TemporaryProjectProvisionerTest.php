<?php

declare(strict_types=1);

namespace App\Tests\Scribase;

use App\Scribase\TemporaryProjectProvisioner;
use PHPUnit\Framework\TestCase;

final class TemporaryProjectProvisionerTest extends TestCase
{
    public function testSolvedNonceHasTheRequestedLeadingZeroBits(): void
    {
        $nonce = TemporaryProjectProvisioner::solve('challenge-abc', 12);
        $hash = hash('sha256', 'challenge-abc:'.$nonce, true);

        self::assertSame("\x00", $hash[0]);
        self::assertSame(0, \ord($hash[1]) >> 4);
    }

    public function testItIsTheSmallestNonce(): void
    {
        $nonce = TemporaryProjectProvisioner::solve('another', 8);
        for ($i = 0; $i < $nonce; ++$i) {
            self::assertNotSame("\x00", hash('sha256', 'another:'.$i, true)[0]);
        }
    }
}
