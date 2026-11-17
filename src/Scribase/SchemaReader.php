<?php

declare(strict_types=1);

namespace App\Scribase;

/**
 * Renders the public schema of an environment (tables, columns, RLS, policies)
 * as Markdown, through the read-only query endpoint.
 */
class SchemaReader
{
    private const SQL = <<<'SQL'
        select c.relname as table_name,
               c.relrowsecurity as rls,
               (select string_agg(a.attname || ' ' || format_type(a.atttypid, a.atttypmod), ', ' order by a.attnum)
                  from pg_attribute a where a.attrelid = c.oid and a.attnum > 0 and not a.attisdropped) as columns,
               (select string_agg(p.polname, ', ' order by p.polname) from pg_policy p where p.polrelid = c.oid) as policies
          from pg_class c join pg_namespace n on n.oid = c.relnamespace
         where n.nspname = 'public' and c.relkind = 'r'
         order by 1
        SQL;

    public function __construct(private readonly ScribaseClient $client)
    {
    }

    public function markdown(string $org, string $project, string $environment): string
    {
        [$status, $body] = $this->client->request('POST', "/v1/organizations/{$org}/projects/{$project}/environments/{$environment}/database/query", ['query' => self::SQL, 'limit' => 500]);
        if (200 !== $status || !\is_array($body)) {
            return \sprintf("# Schema of `%s`\n\nCould not read the schema (HTTP %d): %s\n", $environment, $status, json_encode($body));
        }

        $rows = \is_array($body['rows'] ?? null) ? $body['rows'] : [];
        $out = \sprintf("# Schema of `%s` (public)\n\n", $environment);
        if ([] === $rows) {
            return $out."No tables yet.\n";
        }

        $out .= "| table | RLS | policies | columns |\n|---|---|---|---|\n";
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $out .= \sprintf(
                "| %s | %s | %s | %s |\n",
                self::cell($row['table_name'] ?? ''),
                true === ($row['rls'] ?? null) ? 'on' : '**off**',
                self::cell($row['policies'] ?? '') ?: '-',
                self::cell($row['columns'] ?? ''),
            );
        }

        return $out;
    }

    private static function cell(mixed $value): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], \is_scalar($value) ? (string) $value : '');
    }
}
