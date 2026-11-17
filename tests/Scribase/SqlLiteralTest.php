<?php

declare(strict_types=1);

namespace App\Tests\Scribase;

use App\Scribase\SqlLiteral;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlLiteralTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileValues(): iterable
    {
        yield 'quote' => ["it's"];
        yield 'injection' => ["x'); drop table users; --"];
        yield 'backslash' => ['C:\\path\\to\\file'];
        yield 'comment' => ['/* hi */ -- there'];
        yield 'dollar quote' => ['$$ $tag$ body'];
        yield 'non ascii' => ['café, Türkçe, 日本語'];
    }

    #[DataProvider('hostileValues')]
    public function testEncodedValueOnlyContainsHexInsideTheLiteral(string $value): void
    {
        $encoded = SqlLiteral::encode($value);

        self::assertMatchesRegularExpression("/^convert_from\\(decode\\('[0-9a-f]*', 'hex'\\), 'UTF8'\\)$/", $encoded);
        self::assertSame(1, preg_match("/decode\\('([0-9a-f]*)'/", $encoded, $m));
        self::assertSame($value, hex2bin($m[1] ?? ''));
    }

    public function testNulBytesAreStripped(): void
    {
        self::assertSame(SqlLiteral::encode('ab'), SqlLiteral::encode("a\x00b"));
    }
}
