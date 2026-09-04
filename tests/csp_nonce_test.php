<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
function ae($a, $b, $m) { if ($a !== $b) { echo "FAIL $m ".var_export($a, true)." vs ".var_export($b, true)."\n"; exit(1); } echo "PASS $m\n"; }
ae(defined('SERVMON_CSP_NONCE'), true, 'nonce constant defined');
ae(csp_nonce_attr(), '', 'cli returns empty attr');
$views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../app/Views', FilesystemIterator::SKIP_DOTS));
$bare = 0; $nonce = 0;
foreach ($views as $f) {
    if ($f->getExtension() !== 'php') continue;
    foreach (file($f->getPathname()) as $line) {
        if (!str_contains($line, '<script')) continue;
        if (str_contains($line, 'src=')) continue;
        if (str_contains($line, 'csp_nonce_attr')) { $nonce++; continue; }
        echo "FAIL bare inline script in ".$f->getPathname().": ".trim($line)."\n"; exit(1);
    }
}
ae($bare, 0, 'no bare inline scripts');
if ($nonce < 21) { echo "FAIL expected >=21 nonce scripts got $nonce\n"; exit(1); }
echo "PASS nonce coverage $nonce\n";
$boot = file_get_contents(__DIR__.'/../config/bootstrap.php');
if (!str_contains($boot, "'nonce-")) { echo "FAIL csp header missing nonce\n"; exit(1); }
echo "PASS csp header nonce\n";
