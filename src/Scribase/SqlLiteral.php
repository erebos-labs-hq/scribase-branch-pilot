<?php

declare(strict_types=1);

namespace App\Scribase;

/**
 * Encodes a PHP string as a Postgres text expression built from a hex literal.
 *
 * A hex literal can only contain `0-9a-f` inside a plain single-quoted string, so
 * no quote, backslash or comment sequence is ever representable, independent of
 * the server's `standard_conforming_strings` setting. It also avoids the control
 * plane's backslash guard tripping on ordinary content (Windows paths, regexes).
 */
final class SqlLiteral
{
    public static function encode(string $value): string
    {
        return \sprintf("convert_from(decode('%s', 'hex'), 'UTF8')", bin2hex(str_replace("\x00", '', $value)));
    }
}
