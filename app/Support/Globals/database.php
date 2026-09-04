<?php
declare(strict_types=1);

use App\Repositories\Database;

function db(): PDO
{
    return Database::connection();
}

function db_one(string $sql, array $params = []): ?array
{
    return Database::one($sql, $params);
}

function db_all(string $sql, array $params = []): array
{
    return Database::all($sql, $params);
}

function db_exec(string $sql, array $params = []): bool
{
    return Database::exec($sql, $params);
}

function db_column_exists(string $table, string $column): bool
{
    return Database::columnExists($table, $column);
}

function latest_metric_join_sql(string $serverAlias = 's', string $metricAlias = 'm'): string
{
    return Database::latestMetricJoinSql($serverAlias, $metricAlias);
}
