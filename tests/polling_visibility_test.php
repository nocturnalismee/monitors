<?php
declare(strict_types=1);
$detail = file_get_contents(__DIR__ . '/../app/Views/admin/server_detail.php');
$js = file_get_contents(__DIR__ . '/../public/assets/js/detail.js');
$ok = true;
if (strpos($detail, 'setInterval') !== false && strpos($detail, 'loadHistory') !== false) {
    echo "FAIL: server_detail.php still uses setInterval for loadHistory\n"; $ok = false;
} else { echo "PASS: no raw setInterval for history\n"; }
if (strpos($detail, 'ServMon.startPoller') === false) {
    echo "FAIL: server_detail.php must use ServMon.startPoller\n"; $ok = false;
} else { echo "PASS: uses startPoller\n"; }
if (strpos($js, 'document.hidden') === false) {
    echo "FAIL: detail.js loadHistory must guard document.hidden\n"; $ok = false;
} else { echo "PASS: detail.js guards hidden\n"; }
exit($ok ? 0 : 1);
