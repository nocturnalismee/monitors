<?php
declare(strict_types=1);

namespace App\Services;

use Throwable;

/**
 * Shared per-server disk-health summary used by the admin disk-health page
 * and the public /api/status?disk_summary=1 endpoint. One query per call:
 * aggregate over disk_health_states + ROW_NUMBER() primary-disk pick.
 */
final class DiskHealthSummaryService
{
    /**
     * @return array<int, array<string, mixed>> Rows keyed with server_id,
     * server_name, disk_count, avg_health_score, avg_power_on_time,
     * avg_tbw_bytes, last_update, primary_disk_model, primary_disk_device.
     */
    public function summaryRows(bool $includeInactive): array
    {
        $whereClause = $includeInactive ? '' : 'WHERE s.active = 1';
        $powerOnColumn = $this->powerOnColumn();

        return db_all(
            'SELECT
                s.id AS server_id,
                s.name AS server_name,
                COUNT(dhs.disk_key) AS disk_count,
                ROUND(AVG(dhs.health_score), 2) AS avg_health_score,
                ROUND(AVG(dhs.' . $powerOnColumn . '), 2) AS avg_power_on_time,
                ROUND(AVG(dhs.total_written_bytes), 2) AS avg_tbw_bytes,
                DATE_FORMAT(MAX(dhs.updated_at), "%Y-%m-%d %H:%i:%s") AS last_update,
                pd.model AS primary_disk_model,
                pd.device_name AS primary_disk_device
             FROM servers s
             LEFT JOIN disk_health_states dhs
                ON dhs.server_id = s.id
             LEFT JOIN (
                SELECT ranked.server_id, ranked.model, ranked.device_name
                FROM (
                    SELECT
                        server_id,
                        model,
                        device_name,
                        ROW_NUMBER() OVER (
                            PARTITION BY server_id
                            ORDER BY
                                CASE health_status
                                    WHEN "critical" THEN 4
                                    WHEN "warning" THEN 3
                                    WHEN "ok" THEN 2
                                    ELSE 1
                                END DESC,
                                updated_at DESC,
                                disk_key ASC
                        ) AS rn
                    FROM disk_health_states
                ) ranked
                WHERE ranked.rn = 1
             ) pd
                ON pd.server_id = s.id
             ' . $whereClause . '
             GROUP BY
                s.id, s.name, pd.model, pd.device_name
             ORDER BY s.name ASC'
        );
    }

    /**
     * Schema drift tolerance: older installs use power_on_hours.
     */
    public function powerOnColumn(): string
    {
        static $column = null;
        if (is_string($column) && $column !== '') {
            return $column;
        }

        $column = db_column_exists('disk_health_states', 'power_on_time') ? 'power_on_time' : 'power_on_hours';

        return $column;
    }
}
