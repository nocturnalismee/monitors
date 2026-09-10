<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Shared server-list presentation logic used by the admin dashboard,
 * the public status page, and the /api/status endpoint.
 */
final class ServerListService
{
    /** @return array{onlineMinutes: int, cpuWarn: float, cpuCritical: float} */
    public static function thresholds(): array
    {
        $cpuWarn = max(0.0, (float) setting_get('threshold_cpu_load'));
        return [
            'onlineMinutes' => max(1, (int) setting_get('alert_down_minutes')),
            'cpuWarn' => $cpuWarn,
            'cpuCritical' => max($cpuWarn, (float) setting_get('threshold_cpu_load_critical')),
        ];
    }

    public static function cpuSeverityRank(float $cpuLoad, float $warn, float $critical): int
    {
        if ($cpuLoad > $critical) {
            return 2;
        }
        if ($cpuLoad > $warn) {
            return 1;
        }
        return 0;
    }

    /** Sort rows worst-CPU-severity first, then by name (case-insensitive). */
    public static function sortBySeverity(array $rows, float $warn, float $critical): array
    {
        usort(
            $rows,
            static function (array $a, array $b) use ($warn, $critical): int {
                $ra = self::cpuSeverityRank((float) ($a['cpu_load'] ?? 0), $warn, $critical);
                $rb = self::cpuSeverityRank((float) ($b['cpu_load'] ?? 0), $warn, $critical);
                if ($ra !== $rb) {
                    return $rb <=> $ra;
                }
                return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            }
        );
        return $rows;
    }

    /** @return array<int, array{up: int, down: int, unknown: int}> keyed by server id. */
    public static function serviceSummaryMap(array $serverIds): array
    {
        $safeIds = array_values(array_filter(array_map('intval', $serverIds), static fn (int $id): bool => $id > 0));
        if (empty($safeIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($safeIds), '?'));
        $stmt = db()->prepare(
            "SELECT server_id,
                    SUM(last_status = 'up') AS up_count,
                    SUM(last_status = 'down') AS down_count,
                    SUM(last_status = 'unknown') AS unknown_count
             FROM server_service_states
             WHERE server_id IN ({$placeholders})
             GROUP BY server_id"
        );
        $stmt->execute($safeIds);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $sid = (int) ($row['server_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $map[$sid] = [
                'up' => (int) ($row['up_count'] ?? 0),
                'down' => (int) ($row['down_count'] ?? 0),
                'unknown' => (int) ($row['unknown_count'] ?? 0),
            ];
        }
        return $map;
    }

    /** @return array{online: int, down: int, pending: int} */
    public static function countByStatus(array $rows, int $onlineMinutes): array
    {
        $counts = ['online' => 0, 'down' => 0, 'pending' => 0];
        foreach ($rows as $row) {
            $st = serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $onlineMinutes);
            if ($st === 'online') {
                $counts['online']++;
            } elseif ($st === 'down') {
                $counts['down']++;
            } else {
                $counts['pending']++;
            }
        }
        return $counts;
    }
}
