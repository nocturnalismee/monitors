<?php
declare(strict_types=1);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function normalizedSql(string $path): string
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Failed to read: ' . $path);
    }
    $content = strtolower($content);
    return preg_replace('/\s+/', ' ', $content) ?? $content;
}

$schemaPath = __DIR__ . '/../database/schema.sql';
$schemaSql = normalizedSql($schemaPath);

// Migration parity: 20260225_add_latest_metric_id.sql
assertTrue(
    str_contains($schemaSql, 'latest_metric_id bigint default null'),
    'schema.sql must include servers.latest_metric_id column'
);
assertTrue(
    str_contains($schemaSql, 'index idx_servers_latest_metric (latest_metric_id)'),
    'schema.sql must include idx_servers_latest_metric index'
);

// Migration parity: 20260225_add_worker_health_table.sql
assertTrue(
    str_contains($schemaSql, 'create table if not exists worker_health'),
    'schema.sql must include worker_health table'
);

assertTrue(
    str_contains($schemaSql, 'token_hash char(64) default null'),
    'schema.sql must include servers.token_hash for hashed agent tokens'
);
assertTrue(
    str_contains($schemaSql, 'create table if not exists alert_delivery_queue'),
    'schema.sql must include alert delivery queue'
);
assertTrue(
    str_contains($schemaSql, "status enum('active','acknowledged','resolved','silenced') not null default 'active'"),
    'schema.sql must include alert lifecycle status'
);
assertTrue(
    str_contains($schemaSql, 'worker_name varchar(50) not null primary key'),
    'schema.sql must include worker_health.worker_name primary key'
);
assertTrue(
    str_contains($schemaSql, "last_state enum('running','ok','error') not null default 'running'"),
    'schema.sql must include worker_health.last_state enum'
);

// Migration parity: 20260304_add_disk_health_tables.sql
assertTrue(
    str_contains($schemaSql, 'create table if not exists disk_health_states'),
    'schema.sql must include disk_health_states table'
);
assertTrue(
    str_contains($schemaSql, "health_status enum('ok','warning','critical','unknown') not null default 'unknown'"),
    'schema.sql must include disk_health health_status enum'
);
assertTrue(
    str_contains($schemaSql, 'create table if not exists disk_health_metrics'),
    'schema.sql must include disk_health_metrics table'
);

// Migration parity: 20260316_add_ip_reputation_tables.sql
assertTrue(
    str_contains($schemaSql, 'create table if not exists ip_reputation_targets'),
    'schema.sql must include ip_reputation_targets table'
);
assertTrue(
    str_contains($schemaSql, 'create table if not exists ip_reputation_states'),
    'schema.sql must include ip_reputation_states table'
);
assertTrue(
    str_contains($schemaSql, 'create table if not exists ip_reputation_checks'),
    'schema.sql must include ip_reputation_checks table'
);

// Migration parity: 20260530_add_retention_indexes.sql
assertTrue(
    str_contains($schemaSql, 'index idx_metrics_recorded_at (recorded_at)'),
    'schema.sql must include metrics.idx_metrics_recorded_at'
);
assertTrue(
    str_contains($schemaSql, 'index idx_service_metrics_recorded_at (recorded_at)'),
    'schema.sql must include service_metrics.idx_service_metrics_recorded_at'
);
assertTrue(
    str_contains($schemaSql, 'index idx_alert_logs_created_at (created_at)'),
    'schema.sql must include alert_logs.idx_alert_logs_created_at'
);
assertTrue(
    str_contains($schemaSql, 'index idx_alert_type_server_time (alert_type, server_id, created_at)'),
    'schema.sql must include alert_logs.idx_alert_type_server_time'
);
assertTrue(
    str_contains($schemaSql, 'index idx_admin_audit_logs_created_at (created_at)'),
    'schema.sql must include admin_audit_logs.idx_admin_audit_logs_created_at'
);
assertTrue(
    str_contains($schemaSql, 'index idx_login_attempts_attempted_at (attempted_at)'),
    'schema.sql must include login_attempts.idx_login_attempts_attempted_at'
);
assertTrue(
    str_contains($schemaSql, 'index idx_server_service_states_status (last_status)'),
    'schema.sql must include server_service_states.idx_server_service_states_status'
);

// Migration parity: 20260905_add_metrics_ingest_lag.sql
assertTrue(
    str_contains($schemaSql, 'ingest_lag_ms int default null'),
    'schema.sql must include metrics.ingest_lag_ms for persisted ingest lag'
);

// Migration parity: 20260811_realtime_partitioning.sql
assertTrue(
    str_contains($schemaSql, "partition pmin values less than ('2026-08-01')"),
    'schema.sql must include metrics pmin partition before 2026-08-01'
);
assertTrue(
    str_contains($schemaSql, 'primary key (id, recorded_at)'),
    'schema.sql must include metrics composite primary key (id, recorded_at)'
);

echo "schema_consistency_test passed\n";
