<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\Database;

final class MaintenanceService
{
    /**
     * Per-process memo. Maintenance toggles are rare and workers are short
     * lived, so one SELECT per server per request/run is enough; push ingest
     * and per-row worker loops previously hit the DB on every call.
     */
    private static array $memo = [];

    public static function isServerInMaintenance(int $serverId): bool
    {
        if ($serverId <= 0) {
            return false;
        }
        if (array_key_exists($serverId, self::$memo)) {
            return self::$memo[$serverId];
        }

        $row = Database::one(
            'SELECT maintenance_mode, maintenance_until
             FROM servers
             WHERE id = :id
             LIMIT 1',
            [':id' => $serverId]
        );
        $inMaintenance = false;
        if ($row !== null && (int) ($row['maintenance_mode'] ?? 0) === 1) {
            $until = (string) ($row['maintenance_until'] ?? '');
            $untilTs = $until === '' ? false : strtotime($until);
            // Empty/unparseable "until" = open-ended maintenance window.
            $inMaintenance = $untilTs === false || time() <= $untilTs;
        }

        self::$memo[$serverId] = $inMaintenance;
        return $inMaintenance;
    }

    /** Test hook: clears the per-process memo. */
    public static function resetMemo(): void
    {
        self::$memo = [];
    }

    public static function displayText(array $server): string
    {
        $enabled = (int) ($server['maintenance_mode'] ?? 0) === 1;
        if (!$enabled) {
            return '-';
        }

        $until = (string) ($server['maintenance_until'] ?? '');
        if ($until === '') {
            return 'ON';
        }
        return 'Until ' . $until;
    }
}
