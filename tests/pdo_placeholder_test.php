<?php
declare(strict_types=1);

// Regression test: MySQL native prepares (PDO::ATTR_EMULATE_PREPARES => false,
// see app/Repositories/Database.php) reject a named placeholder that appears
// more than once in a single statement (SQLSTATE[HY093]). Every $where[]
// fragment must therefore use each placeholder at most once — repeat the
// value under distinct names instead (e.g. :search0, :search1).

function collect_where_fragments(string $content): array
{
    $fragments = [];
    foreach (["'", '"'] as $quote) {
        $pattern = '/\$where\[\]\s*=\s*' . preg_quote($quote, '/') . '((?:[^' . $quote . '\\\\]|\\\\.)*)' . preg_quote($quote, '/') . '/';
        if (preg_match_all($pattern, $content, $m)) {
            foreach ($m[1] as $fragment) {
                $fragments[] = $fragment;
            }
        }
    }
    return $fragments;
}

$failures = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../app/Controllers'));
foreach ($iterator as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') {
        continue;
    }
    $content = file_get_contents($file->getPathname());
    if ($content === false) {
        throw new RuntimeException('Failed to read ' . $file->getPathname());
    }
    foreach (collect_where_fragments($content) as $fragment) {
        preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $fragment, $pm);
        foreach (array_count_values($pm[0]) as $name => $count) {
            if ($count > 1) {
                $failures[] = $file->getFilename() . ': placeholder ' . $name . ' used ' . $count . 'x in one WHERE fragment';
            }
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Duplicate PDO placeholders (HY093 under native prepares):\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "pdo_placeholder_test passed\n";
