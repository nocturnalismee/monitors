<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

// C1: single source of truth — default threshold follows alert_down_minutes.
$downMinutes = 5;
try {
    $downMinutes = max(1, (int) setting_get('alert_down_minutes'));
} catch (\Throwable $e) {
    $downMinutes = STATUS_ONLINE_MINUTES;
}

$now = new DateTimeImmutable('now');
// Just inside the threshold -> online (default, no explicit threshold).
$seen = $now->modify('-' . ($downMinutes - 1) . ' minutes')->format('Y-m-d H:i:s');
if (serverStatusFromLastSeen($seen, true, null) !== 'online') {
    echo "FAIL default threshold should be online within setting window\n";
    exit(1);
}
echo "PASS default follows setting\n";

// Beyond the setting window -> down.
$seen = $now->modify('-' . ($downMinutes + 2) . ' minutes')->format('Y-m-d H:i:s');
if (serverStatusFromLastSeen($seen, true, null) !== 'down') {
    echo "FAIL default threshold should be down beyond setting window\n";
    exit(1);
}
echo "PASS default down beyond setting\n";

// Explicit threshold still wins over the setting.
if (serverStatusFromLastSeen($seen, true, 9999) !== 'online') {
    echo "FAIL explicit threshold must win\n";
    exit(1);
}
echo "PASS explicit threshold wins\n";

// Inactive / empty stay pending regardless of threshold source.
if (serverStatusFromLastSeen($seen, false, null) !== 'pending') {
    echo "FAIL inactive must be pending\n";
    exit(1);
}
if (serverStatusFromLastSeen(null, true, null) !== 'pending') {
    echo "FAIL null last_seen must be pending\n";
    exit(1);
}
echo "PASS pending cases\n";
echo "ALL PASS status_threshold\n";
