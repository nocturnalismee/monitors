<?php
declare(strict_types=1);

namespace App\Console;

use PDO;

final class ResetPasswordCommand
{
    public static function run(array $args = []): int
    {
        if (php_sapi_name() !== 'cli') {
            http_response_code(403);
            echo 'CLI only.';
            return 1;
        }

        $localPath = SERVMON_BASE_DIR . '/config/local.php';
        if (!is_file($localPath)) {
            fwrite(STDERR, "Error: config/local.php not found. Run install.php first.\n");
            return 1;
        }

        try {
            $pdo = \App\Repositories\Database::connection();
        } catch (\Throwable $e) {
            fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
            return 1;
        }

        $users = $pdo->query('SELECT id, username, role FROM users ORDER BY id')->fetchAll();
        if (empty($users)) {
            fwrite(STDERR, "No users found in database.\n");
            return 1;
        }

        echo "\nExisting users:\n";
        echo str_pad('ID', 5) . str_pad('Username', 25) . "Role\n";
        echo str_repeat('-', 45) . "\n";
        foreach ($users as $u) {
            echo str_pad((string) $u['id'], 5) . str_pad($u['username'], 25) . $u['role'] . "\n";
        }
        echo "\n";

        $username = $args[1] ?? null;
        if ($username === null) {
            echo "Enter username to reset: ";
            $username = trim((string) fgets(STDIN));
        }

        if ($username === '') {
            fwrite(STDERR, "Error: Username cannot be empty.\n");
            return 1;
        }

        $user = $pdo->prepare('SELECT id, username, role FROM users WHERE username = :u LIMIT 1');
        $user->execute([':u' => $username]);
        $row = $user->fetch();

        if ($row === false) {
            fwrite(STDERR, "Error: User '{$username}' not found.\n");
            return 1;
        }

        $newPassword = $args[2] ?? null;
        if ($newPassword === null) {
            echo "Enter new password (min 6 chars): ";
            $newPassword = trim((string) fgets(STDIN));
        }

        if (strlen($newPassword) < 6) {
            fwrite(STDERR, "Error: Password must be at least 6 characters.\n");
            return 1;
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => $hash, ':id' => $row['id']]);

        echo "\nPassword for '{$username}' (ID: {$row['id']}, Role: {$row['role']}) has been reset.\n";
        return 0;
    }
}
