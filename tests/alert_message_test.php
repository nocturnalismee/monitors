<?php
declare(strict_types=1);

/**
 * Alert message formatting test.
 *
 * Mail queue depth and CPU load are absolute numbers, not percentages. The
 * threshold text used to append "%" to every resource except CPU, so a queue
 * threshold of 2600 was reported as "2600%" while the queue depth itself was
 * shown as a raw count.
 */

require_once __DIR__ . '/../config/bootstrap.php';

use App\Services\AlertService;

$failures = 0;

function check(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        echo "PASS {$message}\n";
    } else {
        $failures++;
        echo "FAIL {$message}\n";
    }
}

// Units follow the resource, not the alert branch: RAM and disk are
// percentages, CPU load and mail queue depth are absolute counts.
check(
    AlertService::thresholdUnitSuffix('mail_queue') === '',
    'mail queue threshold has no percent suffix'
);
check(
    AlertService::thresholdUnitSuffix('cpu') === '',
    'CPU load threshold has no percent suffix'
);
check(
    AlertService::thresholdUnitSuffix('ram') === '%',
    'RAM threshold keeps the percent suffix'
);
check(
    AlertService::thresholdUnitSuffix('disk') === '%',
    'disk threshold keeps the percent suffix'
);

check(
    AlertService::thresholdMessage('mail_queue', 2601, 2600) === 'Mail Queue 2601 exceeds threshold 2600.',
    'mail queue warning reports a raw count, not a percentage'
);
check(
    AlertService::thresholdMessage('mail_queue', 3100, 3000, true) === 'Mail Queue 3100 exceeds critical threshold 3000.',
    'mail queue critical alert reports a raw count'
);
check(
    AlertService::thresholdMessage('ram', 86.5, 85) === 'RAM Usage 86.5% exceeds threshold 85%.',
    'RAM warning keeps the percent suffix'
);
check(
    AlertService::thresholdMessage('cpu', 3.25, 2) === 'CPU Load 3.25 exceeds threshold 2.',
    'CPU warning stays unitless'
);

if ($failures > 0) {
    echo "\n{$failures} FAILURE(S)\n";
    exit(1);
}

echo "\nALL PASS alert_message_test\n";