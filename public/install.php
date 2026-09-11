<?php
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

// Kill-switch: create config/.installer-locked to disable this installer entirely,
// even if config/local.php is deleted. Prefer removing this file after setup.
if (is_file(SERVMON_BASE_DIR . '/config/.installer-locked')) {
    http_response_code(403);
    echo 'Installer disabled (config/.installer-locked is present). Delete the lock file to re-enable.';
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function splitSqlStatements(string $sql): array
{
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    $lines = preg_split('/\R/', $sql) ?: [];
    $buffer = '';
    $statements = [];
    $insideBlockComment = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($insideBlockComment) {
            if (str_contains($trimmed, '*/')) {
                $insideBlockComment = false;
            }
            continue;
        }

        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }

        if (str_starts_with($trimmed, '/*')) {
            if (!str_contains($trimmed, '*/')) {
                $insideBlockComment = true;
            }
            continue;
        }

        if (preg_match('/^(CREATE DATABASE|USE)\s+/i', $trimmed) === 1) {
            continue;
        }

        $buffer .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function isAlreadyInstalled(): bool
{
    return is_file(SERVMON_BASE_DIR . '/config/local.php');
}

function executeSqlFile(PDO $pdo, string $filePath): int
{
    if (!is_file($filePath)) {
        throw new RuntimeException('SQL file not found: ' . basename($filePath));
    }

    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException('Failed to read SQL file: ' . basename($filePath));
    }

    $statements = splitSqlStatements($sql);
    $executed = 0;
    foreach ($statements as $statement) {
        executeSqlStatement($pdo, $statement);
        $executed++;
    }

    return $executed;
}

function executeSqlStatement(PDO $pdo, string $statement): void
{
    $stmt = $pdo->prepare($statement);
    $stmt->execute();

    // Drain all possible rowsets to avoid "unbuffered queries are active"
    // on migration patterns that use PREPARE/EXECUTE/DEALLOCATE.
    do {
        try {
            $stmt->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable) {
            // Statement may not return a rowset.
        }
    } while ($stmt->nextRowset());

    $stmt->closeCursor();
}

function discoverMigrations(string $dir): array
{
    if (!is_dir($dir)) {
        throw new RuntimeException('Migration folder not found: database/migrations');
    }

    $files = glob($dir . '/*.sql');
    if ($files === false) {
        return [];
    }

    natsort($files);
    return array_values($files);
}

function ensureMigrationsTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            checksum_sha256 CHAR(64) NOT NULL
        ) ENGINE=InnoDB'
    );
}

function getAppliedMigrations(PDO $pdo): array
{
    $rows = $pdo->query('SELECT migration_name, checksum_sha256 FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $name = (string) ($row['migration_name'] ?? '');
        if ($name === '') {
            continue;
        }
        $result[$name] = (string) ($row['checksum_sha256'] ?? '');
    }
    return $result;
}

function safeStatementPreview(string $statement): string
{
    $singleLine = preg_replace('/\s+/', ' ', trim($statement)) ?? '';
    if ($singleLine === '') {
        return '';
    }
    return substr($singleLine, 0, 160);
}

function applyPendingMigrations(PDO $pdo, array $files): array
{
    ensureMigrationsTable($pdo);
    $appliedMap = getAppliedMigrations($pdo);
    $alreadyApplied = [];
    $appliedNow = [];

    foreach ($files as $file) {
        $migrationName = basename($file);
        $checksum = hash_file('sha256', $file);
        if ($checksum === false) {
            throw new RuntimeException('Failed to read migration checksum: ' . $migrationName);
        }

        if (isset($appliedMap[$migrationName])) {
            if ($appliedMap[$migrationName] !== $checksum) {
                throw new RuntimeException('Migration checksum changed after being applied: ' . $migrationName);
            }
            $alreadyApplied[] = $migrationName;
            continue;
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Failed to read migration: ' . $migrationName);
        }

        $statements = splitSqlStatements($sql);
        foreach ($statements as $statement) {
            try {
                executeSqlStatement($pdo, $statement);
            } catch (Throwable $e) {
                $preview = safeStatementPreview($statement);
                throw new RuntimeException(
                    'Migration failed [' . $migrationName . ']: ' . $e->getMessage() . ($preview !== '' ? ' | SQL: ' . $preview : '')
                );
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO schema_migrations (migration_name, checksum_sha256, applied_at)
             VALUES (:name, :checksum, NOW())'
        );
        $stmt->execute([
            ':name' => $migrationName,
            ':checksum' => $checksum,
        ]);
        $appliedNow[] = $migrationName;
    }

    return [
        'total_discovered' => count($files),
        'already_applied' => $alreadyApplied,
        'applied_now' => $appliedNow,
    ];
}

function markMigrationsAsApplied(PDO $pdo, array $files): array
{
    ensureMigrationsTable($pdo);
    $appliedMap = getAppliedMigrations($pdo);
    $alreadyApplied = [];
    $markedNow = [];

    foreach ($files as $file) {
        $migrationName = basename($file);
        $checksum = hash_file('sha256', $file);
        if ($checksum === false) {
            throw new RuntimeException('Failed to read migration checksum: ' . $migrationName);
        }

        if (isset($appliedMap[$migrationName])) {
            if ($appliedMap[$migrationName] !== $checksum) {
                throw new RuntimeException('Migration checksum changed after being applied: ' . $migrationName);
            }
            $alreadyApplied[] = $migrationName;
            continue;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO schema_migrations (migration_name, checksum_sha256, applied_at)
             VALUES (:name, :checksum, NOW())'
        );
        $stmt->execute([
            ':name' => $migrationName,
            ':checksum' => $checksum,
        ]);
        $markedNow[] = $migrationName;
    }

    return [
        'total_discovered' => count($files),
        'already_applied' => $alreadyApplied,
        'marked_now' => $markedNow,
    ];
}

function defaultAppSettings(): array
{
    return [
        'branding_logo_url' => '',
        'branding_favicon_url' => '',
        'alert_down_minutes' => '5',
        'alert_cooldown_minutes' => '30',
        'alert_service_status_enabled' => '1',
        'alert_ping_enabled' => '1',
        'threshold_mail_queue' => '50',
        'threshold_mail_queue_critical' => '100',
        'threshold_cpu_load' => '2.00',
        'threshold_cpu_load_critical' => '4.00',
        'threshold_ram_pct' => '85',
        'threshold_ram_pct_critical' => '95',
        'threshold_disk_pct' => '90',
        'threshold_disk_pct_critical' => '97',
        'alert_service_flap_suppress_minutes' => '5',
        'cache_ttl_status_list' => '15',
        'cache_ttl_status_single' => '15',
        'cache_ttl_history_5m' => '5',
        'cache_ttl_history_30m' => '10',
        'cache_ttl_history_24h' => '30',
        'cache_ttl_history_7d' => '120',
        'cache_ttl_history_30d' => '180',
        'cache_ttl_alert_logs' => '20',
        'cache_ttl_disk_health_list' => '15',
        'disk_rollup_days' => '2',
        'disk_push_max_body_bytes' => '1048576',
        'disk_push_max_items' => '64',
        'public_alerts_redact_message' => '0',
        'channel_email_enabled' => '0',
        'channel_telegram_enabled' => '0',
        'session_idle_timeout_minutes' => '60',
        'session_absolute_timeout_minutes' => '480',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_secure' => 'tls',
        'smtp_from_email' => '',
        'smtp_from_name' => 'servmon',
        'smtp_to_email' => '',
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'telegram_thread_id' => '',
        'retention_days' => '30',
        'disk_retention_days' => '90',
        'backup_retention_days' => '30',
        'agent_push_signature_required' => '1',
        'service_metrics_store_all' => '0',
        'push_api_rate_per_minute' => '600',
        'metrics_raw_hours' => '24',
        'metrics_5m_days' => '14',
        'metrics_1h_days' => '90',
        'metrics_1d_days' => '730',
        'ip_rep_check_interval_hours' => '6',
        'ip_rep_alert_enabled' => '1',
        'ip_rep_abuseipdb_key' => '',
        'ip_rep_virustotal_key' => '',
        'ip_rep_ipinfo_key' => '',
        'cache_ttl_ip_rep_list' => '30',
        'cache_ttl_ip_rep_detail' => '15',
    ];
}

function verifyFreshInstallSchema(PDO $pdo): array
{
    $checks = [
        'worker_health table' => static function (PDO $db): bool {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'worker_health'"
            );
            $stmt->execute();
            return ((int) $stmt->fetchColumn()) > 0;
        },
        'servers.latest_metric_id column' => static function (PDO $db): bool {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'servers' AND column_name = 'latest_metric_id'"
            );
            $stmt->execute();
            return ((int) $stmt->fetchColumn()) > 0;
        },
        'idx_servers_latest_metric index' => static function (PDO $db): bool {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'servers' AND index_name = 'idx_servers_latest_metric'"
            );
            $stmt->execute();
            return ((int) $stmt->fetchColumn()) > 0;
        },
        'metrics pmin partition' => static function (PDO $db): bool {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.partitions
                 WHERE table_schema = DATABASE() AND table_name = 'metrics' AND partition_name = 'pmin'"
            );
            $stmt->execute();
            return ((int) $stmt->fetchColumn()) > 0;
        },
    ];

    $missing = [];
    foreach ($checks as $label => $check) {
        if ($check($pdo) !== true) {
            $missing[] = $label;
        }
    }

    if (!empty($missing)) {
        throw new RuntimeException(
            'Schema parity check failed after import. Missing: '
            . implode(', ', $missing)
            . '. Ensure database/schema.sql includes required migration structures before install.'
        );
    }

    return array_keys($checks);
}

function ensureBaseRecordsWithoutSeed(PDO $pdo): void
{
    $pdo->exec(
        "INSERT INTO users (username, password_hash, role)
         VALUES ('admin', '', 'admin')
         ON DUPLICATE KEY UPDATE role = VALUES(role)"
    );

    $insert = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value)
         VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach (defaultAppSettings() as $key => $value) {
        $insert->execute([
            ':key' => $key,
            ':value' => $value,
        ]);
    }
}

function writeLocalConfig(array $config): void
{
    $localPath = SERVMON_BASE_DIR . '/config/local.php';
    $localContent = "<?php\nreturn " . var_export($config, true) . ";\n";
    $bytes = file_put_contents($localPath, $localContent);
    if ($bytes === false) {
        throw new RuntimeException('Failed to write config/local.php (check permissions).');
    }
    // Use 0640 so web server user and group can read, others cannot.
    // On AaPanel/BT, ensure web user (www) is in the group owning config/local.php.
    @chmod($localPath, 0640);
    if (!is_readable($localPath)) {
        throw new RuntimeException('config/local.php written but not readable (permission denied). Run: chmod 640 ' . $localPath . ' and verify web server user is in the group that owns the file.');
    }
}

function loadLocalConfig(): array
{
    $path = SERVMON_BASE_DIR . '/config/local.php';
    if (!is_file($path)) {
        throw new RuntimeException('config/local.php not found.');
    }
    if (!is_readable($path)) {
        throw new RuntimeException('config/local.php exists but is not readable (permission denied). Fix with: chmod 644 ' . $path);
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Invalid config/local.php format.');
    }
    return array_map(static fn ($v): string => (string) $v, $config);
}

function makePdo(string $host, string $port, string $dbName, string $user, string $pass): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
    }
    return new PDO($dsn, $user, $pass, $options);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$csrfToken = $_SESSION['_install_csrf'] ?? '';
if ($csrfToken === '') {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['_install_csrf'] = $csrfToken;
}

$errors = [];
$success = false;
$mode = 'install';
$lastOperation = '';
$summary = [];
$stepStatus = [
    'db' => 'pending',
    'schema' => 'pending',
    'seed' => 'pending',
    'migrations' => 'pending',
    'config' => 'pending',
];

$defaultValues = [
    'app_name' => 'monitors',
    'app_env' => 'development',
    'app_url' => '',
    'app_tz' => 'Asia/Jakarta',
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'servmon',
    'db_user' => 'root',
    'db_pass' => '',
    'redis_enabled' => '0',
    'redis_host' => '127.0.0.1',
    'redis_port' => '6379',
    'redis_password' => '',
    'redis_db' => '0',
    'redis_prefix' => 'servmon:',
    'trust_proxy_headers' => '0',
    'trusted_proxies' => '127.0.0.1,::1',
    'turnstile_site_key' => '',
    'turnstile_secret_key' => '',
    'admin_password' => '',
    'import_seed' => '0',
];

$data = $defaultValues;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['_install_csrf'] ?? '');
    if (!hash_equals($csrfToken, $submittedToken)) {
        $errors[] = 'Invalid session token. Please reload the page and try again.';
    }
    foreach (array_keys($defaultValues) as $key) {
        if ($key === 'import_seed') {
            $data[$key] = isset($_POST[$key]) ? '1' : '0';
            continue;
        }
        $data[$key] = trim((string) ($_POST[$key] ?? $defaultValues[$key]));
    }
}

$installed = isAlreadyInstalled();
if ($installed) {
    $mode = 'upgrade';
    $stepStatus['schema'] = 'skipped';
    $stepStatus['seed'] = 'skipped';
    $stepStatus['config'] = 'skipped';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    if ($data['db_name'] === '' || $data['db_user'] === '' || $data['admin_password'] === '') {
        $errors[] = 'Database name, database user, and admin password are required.';
    }
    if (strlen($data['admin_password']) < 8) {
        $errors[] = 'Admin password must be at least 8 characters.';
    }
    if (($data['turnstile_site_key'] === '') !== ($data['turnstile_secret_key'] === '')) {
        $errors[] = 'Turnstile keys: provide both Site Key and Secret Key (or leave both empty).';
    }
    $appUrl = rtrim(trim((string)($data['app_url'] ?? '')), '/');
    if ($appUrl !== '' && (!filter_var($appUrl, FILTER_VALIDATE_URL) || parse_url($appUrl, PHP_URL_HOST) === null || parse_url($appUrl, PHP_URL_HOST) === '')) {
        $errors[] = 'APP_URL invalid';
    }
    $data['app_url'] = $appUrl;

    if (empty($errors)) {
        try {
            $schemaPath = SERVMON_BASE_DIR . '/database/schema.sql';
            $seedPath = SERVMON_BASE_DIR . '/database/seed.sql';
            $migrationDir = SERVMON_BASE_DIR . '/database/migrations';
            if (!is_file($schemaPath)) {
                throw new RuntimeException('database/schema.sql not found.');
            }
            if ($data['import_seed'] === '1' && !is_file($seedPath)) {
                throw new RuntimeException('database/seed.sql not found.');
            }

            $dbNameSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $data['db_name']);
            if ($dbNameSafe === null || $dbNameSafe === '') {
                throw new RuntimeException('Invalid database name.');
            }

            $rootDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $data['db_host'], $data['db_port']);
            $rootOptions = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                $rootOptions[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
            }
            $pdoRoot = new PDO($rootDsn, $data['db_user'], $data['db_pass'], $rootOptions);
            $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameSafe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $summary[] = ['step' => 'db', 'message' => 'Database connection successful and DB is ready.'];
            $stepStatus['db'] = 'success';

            $pdo = makePdo($data['db_host'], $data['db_port'], $dbNameSafe, $data['db_user'], $data['db_pass']);
            $schemaCount = executeSqlFile($pdo, $schemaPath);
            $summary[] = ['step' => 'schema', 'message' => 'Schema diimport: ' . $schemaCount . ' statement.'];
            $verifiedSchemaItems = verifyFreshInstallSchema($pdo);
            $summary[] = ['step' => 'schema', 'message' => 'Schema parity check passed: ' . implode(', ', $verifiedSchemaItems) . '.'];
            $stepStatus['schema'] = 'success';

            if ($data['import_seed'] === '1') {
                $seedCount = executeSqlFile($pdo, $seedPath);
                $summary[] = ['step' => 'seed', 'message' => 'Seed diimport: ' . $seedCount . ' statement.'];
                $stepStatus['seed'] = 'success';
            } else {
                ensureBaseRecordsWithoutSeed($pdo);
                $summary[] = ['step' => 'seed', 'message' => 'Seed sample dilewati. Admin + app_settings default dibuat.'];
                $stepStatus['seed'] = 'skipped';
            }

            $passwordHash = password_hash($data['admin_password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->exec(
                "INSERT INTO users (username, password_hash, role)
                 VALUES ('admin', '', 'admin')
                 ON DUPLICATE KEY UPDATE role = VALUES(role)"
            );
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE username = :username');
            $stmt->execute([':hash' => $passwordHash, ':username' => 'admin']);

            $migrationFiles = discoverMigrations($migrationDir);
            $migrationResult = markMigrationsAsApplied($pdo, $migrationFiles);
            $summary[] = [
                'step' => 'migrations',
                'message' => sprintf(
                    'Fresh install migrations: total %d, already applied %d, marked as applied %d.',
                    $migrationResult['total_discovered'],
                    count($migrationResult['already_applied']),
                    count($migrationResult['marked_now'])
                ),
            ];
            if (!empty($migrationResult['marked_now'])) {
                foreach ($migrationResult['marked_now'] as $name) {
                    $summary[] = ['step' => 'migrations', 'message' => 'Marked migration as applied: ' . $name];
                }
            } else {
                $summary[] = ['step' => 'migrations', 'message' => 'No pending migrations.'];
            }
            $stepStatus['migrations'] = 'success';

            $localConfig = [
                'APP_NAME' => $data['app_name'],
                'APP_ENV' => $data['app_env'],
                'APP_URL' => rtrim(trim((string)($data['app_url'] ?? '')), '/'),
                'APP_TZ' => $data['app_tz'],
                'APP_KEY' => bin2hex(random_bytes(32)),
                'DB_HOST' => $data['db_host'],
                'DB_PORT' => $data['db_port'],
                'DB_NAME' => $dbNameSafe,
                'DB_USER' => $data['db_user'],
                'DB_PASS' => $data['db_pass'],
                'REDIS_ENABLED' => ($data['redis_enabled'] === '1') ? '1' : '0',
                'REDIS_HOST' => $data['redis_host'],
                'REDIS_PORT' => $data['redis_port'],
                'REDIS_PASSWORD' => $data['redis_password'],
                'REDIS_DB' => $data['redis_db'],
                'REDIS_PREFIX' => $data['redis_prefix'],
                'TRUST_PROXY_HEADERS' => ($data['trust_proxy_headers'] === '1') ? '1' : '0',
                'TRUSTED_PROXIES' => trim((string)($data['trusted_proxies'] ?? '127.0.0.1,::1')),
                'TURNSTILE_SITE_KEY' => $data['turnstile_site_key'],
                'TURNSTILE_SECRET_KEY' => $data['turnstile_secret_key'],
            ];
            writeLocalConfig($localConfig);
            $summary[] = ['step' => 'config', 'message' => 'Local configuration written to config/local.php successfully.'];
            $stepStatus['config'] = 'success';

            $success = true;
            $installed = true;
            $lastOperation = 'install';
            // Auto-remove installer on successful fresh install for security
            if ($success && $lastOperation === 'install') {
                $installPath = __FILE__;
                if (is_file($installPath)) {
                    @unlink($installPath);
                    $summary[] = ['step' => 'cleanup', 'message' => 'Installer auto-deleted for security.'];
                }
            }
            $mode = 'upgrade';
        } catch (Throwable $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
            foreach ($stepStatus as $step => $status) {
                if ($status === 'pending') {
                    $stepStatus[$step] = 'error';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $installed && ($_POST['action'] ?? '') === 'upgrade') {
    $errors[] = 'Web upgrade is disabled on installed instances. Please run upgrade via CLI: php migrate.php';
    $stepStatus['db'] = 'skipped';
    $stepStatus['migrations'] = 'skipped';
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>monitors Installer</title>
    <link href="<?= e(asset_url('assets/css/fonts.css')) ?>" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.34.1/dist/tabler-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset_url('assets/css/tokens.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('assets/css/install.css')) ?>" rel="stylesheet">
    <script<?= csp_nonce_attr() ?>>
      (function () {
        try {
          var stored = localStorage.getItem('servmon_theme');
          var systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
          var theme = stored === 'dark' || stored === 'light' ? stored : (systemDark ? 'dark' : 'light');
          document.documentElement.setAttribute('data-bs-theme', theme);
        } catch (e) {}
      })();
    </script>
</head>
<body class="servmon-install">
<a href="#install-main" class="visually-hidden-focusable">Skip to main content</a>
<div class="container install-shell py-4 py-lg-5">
    <header class="install-topbar">
        <div class="install-brand">
            <span class="install-brand-mark" aria-hidden="true"><i class="ti ti-activity-heartbeat"></i></span>
            <div>
                <div class="install-brand-name">monitors</div>
                <span class="install-brand-note">Instance installer</span>
            </div>
        </div>
        <div class="install-topbar-actions">
            <a class="btn btn-outline-secondary btn-sm" href="/">Public dashboard</a>
            <a class="btn btn-outline-secondary btn-sm" href="/login">Admin login</a>
            <button type="button" class="install-theme-toggle" id="install-theme-toggle" aria-label="Toggle color theme" title="Toggle theme">
                <i class="ti ti-sun" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <?php
    $installAttempted = $_SERVER['REQUEST_METHOD'] === 'POST';
    $stepperSteps = [
        ['label' => 'Configure', 'state' => ($success || $mode !== 'install') ? 'is-done' : ($installAttempted ? 'is-done' : 'is-current')],
        ['label' => 'Install', 'state' => $success ? 'is-done' : ($installAttempted && $mode === 'install' ? 'is-current' : '')],
        ['label' => 'Done', 'state' => $success ? 'is-current' : ''],
    ];
    ?>
    <ol class="install-stepper" aria-label="Installation progress">
        <?php foreach ($stepperSteps as $index => $step): ?>
            <li class="install-step <?= h($step['state']) ?>"<?= $step['state'] === 'is-current' ? ' aria-current="step"' : '' ?>>
                <span class="install-step-num" aria-hidden="true"><?= $step['state'] === 'is-done' ? '✓' : (string) ($index + 1) ?></span>
                <span class="install-step-label"><?= h($step['label']) ?></span>
                <?php if ($index < count($stepperSteps) - 1): ?>
                    <span class="install-step-connector" aria-hidden="true"></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>

    <main id="install-main" class="row g-4 mt-1">
        <div class="col-lg-7">
            <div class="install-card h-100">
                <div class="p-4 p-md-5">
                    <h1 class="h3 install-title"><?= $mode === 'install' ? 'Set up your instance' : 'Instance already configured' ?></h1>
                    <p class="install-lead">
                        <?= $mode === 'install'
                            ? 'Configure the database, import the schema, apply migrations, and write the local configuration.'
                            : 'This application is already installed. Web upgrade is disabled; run pending migrations via CLI.' ?>
                    </p>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger install-errors" role="alert" tabindex="-1" id="install-errors">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= h($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success" role="status">
                            <?= $lastOperation === 'install' ? 'Installation successful.' : 'Migration upgrade successful (CLI).' ?>
                            <div class="mt-2">Installer has been auto-deleted. Please verify `config/local.php` is not world-readable.</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($mode === 'install'): ?>
                        <form method="post" novalidate class="install-form">
                            <input type="hidden" name="_install_csrf" value="<?= h($csrfToken) ?>">
                            <fieldset class="install-group">
                                <legend><span class="install-group-num" aria-hidden="true">1</span>Application</legend>
                                <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="install-app-name">Application name</label>
                                    <input class="form-control" id="install-app-name" name="app_name" value="<?= h($data['app_name']) ?>" required autocomplete="off">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="install-app-env">Environment</label>
                                    <select class="form-select" id="install-app-env" name="app_env">
                                        <option value="development"<?= $data['app_env'] === 'development' ? ' selected' : '' ?>>Development</option>
                                        <option value="production"<?= $data['app_env'] === 'production' ? ' selected' : '' ?>>Production</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="install-app-url">Public URL <span class="text-muted fw-normal">(optional)</span></label>
                                    <input class="form-control" id="install-app-url" name="app_url" inputmode="url" placeholder="https://status.example.com" value="<?= h($data['app_url']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="install-app-tz">Timezone</label>
                                    <input class="form-control" id="install-app-tz" name="app_tz" value="<?= h($data['app_tz']) ?>">
                                </div>
                                </div>
                            </fieldset>
                            <fieldset class="install-group">
                                <legend><span class="install-group-num" aria-hidden="true">2</span>Database</legend>
                                <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="install-db-host">Host</label>
                                    <input class="form-control" id="install-db-host" name="db_host" value="<?= h($data['db_host']) ?>" required autocomplete="off">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="install-db-port">Port</label>
                                    <input class="form-control" id="install-db-port" name="db_port" inputmode="numeric" value="<?= h($data['db_port']) ?>" required autocomplete="off">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="install-db-name">Database name</label>
                                    <input class="form-control" id="install-db-name" name="db_name" value="<?= h($data['db_name']) ?>" required autocomplete="off">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="install-db-user">Username</label>
                                    <input class="form-control" id="install-db-user" name="db_user" value="<?= h($data['db_user']) ?>" required autocomplete="off">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="install-db-pass">Password</label>
                                    <input class="form-control" id="install-db-pass" type="password" name="db_pass" autocomplete="off">
                                </div>
                                </div>
                            </fieldset>
                            <fieldset class="install-group">
                                <legend><span class="install-group-num" aria-hidden="true">3</span>Cache &amp; network</legend>
                                <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label" for="install-redis-enabled">Redis cache</label>
                                    <select class="form-select" id="install-redis-enabled" name="redis_enabled">
                                        <option value="0"<?= $data['redis_enabled'] === '0' ? ' selected' : '' ?>>Disabled</option>
                                        <option value="1"<?= $data['redis_enabled'] === '1' ? ' selected' : '' ?>>Enabled</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="install-redis-host">Redis host</label>
                                    <input class="form-control" id="install-redis-host" name="redis_host" value="<?= h($data['redis_host']) ?>" autocomplete="off">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="install-redis-port">Redis port</label>
                                    <input class="form-control" id="install-redis-port" name="redis_port" inputmode="numeric" value="<?= h($data['redis_port']) ?>" autocomplete="off">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="install-redis-db">Redis database</label>
                                    <input class="form-control" id="install-redis-db" name="redis_db" inputmode="numeric" value="<?= h($data['redis_db']) ?>" autocomplete="off">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="install-redis-password">Redis password</label>
                                    <input class="form-control" id="install-redis-password" type="password" name="redis_password" autocomplete="off">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="install-redis-prefix">Redis key prefix</label>
                                    <input class="form-control" id="install-redis-prefix" name="redis_prefix" value="<?= h($data['redis_prefix']) ?>" autocomplete="off">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="install-trust-proxy">Trust proxy headers</label>
                                    <select class="form-select" id="install-trust-proxy" name="trust_proxy_headers">
                                        <option value="0"<?= $data['trust_proxy_headers'] === '0' ? ' selected' : '' ?>>Off (default)</option>
                                        <option value="1"<?= $data['trust_proxy_headers'] === '1' ? ' selected' : '' ?>>On (Cloudflare / reverse proxy)</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="install-trusted-proxies">Trusted proxies (comma-separated)</label>
                                    <input class="form-control" id="install-trusted-proxies" name="trusted_proxies" placeholder="127.0.0.1, ::1" value="<?= h($data['trusted_proxies']) ?>" autocomplete="off">
                                    <div class="form-text" id="install-trusted-proxies-help">Trusted proxy IPs used for forwarded host and protocol headers. Default: 127.0.0.1, ::1.</div>
                                </div>
                                </div>
                            </fieldset>
                            <fieldset class="install-group">
                                <legend><span class="install-group-num" aria-hidden="true">4</span>Bot protection <span class="text-muted fw-normal">(optional)</span></legend>
                                <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="install-turnstile-site">Turnstile site key</label>
                                    <input class="form-control" id="install-turnstile-site" name="turnstile_site_key" placeholder="0x4AAAA..." value="<?= h($data['turnstile_site_key']) ?>" autocomplete="off">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="install-turnstile-secret">Turnstile secret key</label>
                                    <input class="form-control" id="install-turnstile-secret" type="password" name="turnstile_secret_key" autocomplete="off" placeholder="0x4BBBB...">
                                </div>
                                </div>
                            </fieldset>
                            <fieldset class="install-group">
                                <legend><span class="install-group-num" aria-hidden="true">5</span>Administrator</legend>
                                <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label" for="install-admin-password">Initial admin password <span class="text-muted fw-normal">(user: admin, at least 8 characters)</span></label>
                                    <input class="form-control" id="install-admin-password" type="password" name="admin_password" autocomplete="new-password" required>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="import_seed" name="import_seed" value="1"<?= $data['import_seed'] === '1' ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="import_seed">Import sample seed data (for demos and testing)</label>
                                    </div>
                                </div>
                                </div>
                            </fieldset>
                            <div class="install-submit-row">
                                <button class="install-submit" type="submit">Install now <span aria-hidden="true">→</span></button>
                                <p class="install-submit-hint">Writes <code>config/local.php</code> and imports the schema.</p>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning" role="status">
                            Install mode is locked because <code>config/local.php</code> already exists.
                            Web upgrade is disabled. Run pending migrations from the CLI:
                            <code>php migrate.php</code>
                        </div>
                    <?php endif; ?>

                    <div class="install-help">
                        Migrations are scanned automatically from <code>database/migrations</code>; only pending files run.
                        For uptime checks, schedule <code>alert-check.php</code>, <code>ping-check.php</code>, <code>disk-rollup.php</code>, and <code>cleanup.php</code> via cron.
                        After setup, remove or rename <code>public/install.php</code>.
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <aside class="install-card h-100" aria-label="Installation status">
                <div class="p-4 p-md-4">
                    <?php if ($mode === 'install'): ?>
                    <?php
                    $requirements = [
                        'PHP 8.2 or newer' => version_compare(PHP_VERSION, '8.2.0', '>='),
                        'PDO MySQL driver' => extension_loaded('pdo_mysql'),
                        'Schema file present' => is_file(SERVMON_BASE_DIR . '/database/schema.sql'),
                        'config/ directory writable' => is_writable(SERVMON_BASE_DIR . '/config'),
                    ];
                    ?>
                    <section class="install-aside-section" aria-labelledby="install-req-title">
                        <h2 class="install-aside-title" id="install-req-title">Requirements</h2>
                        <p class="install-aside-desc">Checked in your browser before anything is installed.</p>
                        <ul class="install-check-list">
                            <?php foreach ($requirements as $label => $passed): ?>
                                <li class="install-check-row">
                                    <span class="install-check-label"><?= h($label) ?></span>
                                    <?php if ($passed): ?>
                                        <span class="install-check-state is-pass"><i class="ti ti-check" aria-hidden="true"></i>Ready</span>
                                    <?php else: ?>
                                        <span class="install-check-state is-fail"><i class="ti ti-x" aria-hidden="true"></i>Missing</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                    <?php endif; ?>

                    <section class="install-aside-section" aria-labelledby="install-progress-title">
                        <h2 class="install-aside-title" id="install-progress-title">Installation progress</h2>
                        <p class="install-aside-desc">The installer prepares the database, applies the schema, and writes the local configuration.</p>
                        <?php
                        $labels = [
                            'db' => 'Database connection',
                            'schema' => 'Schema import',
                            'seed' => 'Seed data',
                            'migrations' => 'Migrations',
                            'config' => 'Config file',
                        ];
                        ?>
                        <ul class="install-status-list">
                        <?php foreach ($labels as $key => $label):
                            $status = $stepStatus[$key] ?? 'pending';
                            $class = match ($status) {
                                'success' => 'is-success',
                                'error' => 'is-error',
                                'skipped' => 'is-skipped',
                                default => '',
                            };
                        ?>
                            <li class="install-status-row">
                                <span><?= h($label) ?></span>
                                <span class="install-pill <?= h($class) ?>"><?= h($status) ?></span>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </section>

                    <section class="install-aside-section" aria-labelledby="install-log-title">
                        <div class="install-log-head">
                            <h2 class="install-aside-title" id="install-log-title">Execution log</h2>
                            <span class="install-aside-desc">Live result</span>
                        </div>
                        <div class="install-log-list" role="log" aria-label="Installation execution log">
                            <?php if (empty($summary)): ?>
                                <div class="install-log-row install-aside-desc">No execution yet. Submit the form to see step results.</div>
                            <?php else: ?>
                                <?php foreach ($summary as $row): ?>
                                    <div class="install-log-row">
                                        <div class="install-log-step"><?= h((string) ($row['step'] ?? 'info')) ?></div>
                                        <div><?= h((string) ($row['message'] ?? '')) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </aside>
        </div>
    </main>
    <p class="install-footer-note">monitors installer &middot; after setup, remove or rename <code>public/install.php</code>.</p>
</div>
<script<?= csp_nonce_attr() ?>>
(function () {
    var button = document.getElementById('install-theme-toggle');
    if (!button) return;
    function syncIcon() {
        var theme = document.documentElement.getAttribute('data-bs-theme') === 'light' ? 'light' : 'dark';
        var icon = button.querySelector('i');
        if (icon) icon.className = theme === 'light' ? 'ti ti-moon' : 'ti ti-sun';
        button.setAttribute('aria-label', theme === 'light' ? 'Switch to dark theme' : 'Switch to light theme');
    }
    button.addEventListener('click', function () {
        var current = document.documentElement.getAttribute('data-bs-theme') === 'light' ? 'light' : 'dark';
        var next = current === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-bs-theme', next);
        try { localStorage.setItem('servmon_theme', next); } catch (e) {}
        syncIcon();
    });
    syncIcon();
})();
</script>
</body>
</html>
