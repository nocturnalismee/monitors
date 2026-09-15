<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Services\PingService;

final class PingTerminalController
{
    public function stream(Request $request): Response
    {
        if (strtoupper($request->method) !== 'GET') {
            return Response::text('405 Method Not Allowed', 405)->withHeader('Allow', 'GET');
        }

        require_role('admin');

        $token = (string) ($request->query('token') ?? '');
        if (!csrf_validate($token)) {
            return Response::text('419 Invalid security token.', 419);
        }

        // Release the session file before any potentially slow probe so the
        // SSE stream does not block parallel requests in the same browser.
        session_write_close();

        if (!api_rate_check('ping_terminal', get_client_ip(), 12)) {
            return Response::text('429 Too many requests. Try again later.', 429)->withHeader('Retry-After', '60');
        }

        $cmd = trim((string) ($request->query('cmd') ?? ''));
        $parsed = $this->parsePingCommand($cmd);
        if (is_string($parsed)) {
            $this->emitEnd($parsed, 2);
            exit;
        }

        $this->streamPing($parsed['target'], $parsed['count'], $parsed['timeout']);
        exit;
    }

    private function parsePingCommand(string $input): array|string
    {
        $tokens = array_values(array_filter(
            preg_split('/\s+/', trim($input)) ?: [],
            static fn ($token): bool => $token !== ''
        ));

        if ($tokens === [] || strtolower((string) $tokens[0]) !== 'ping') {
            return 'Usage: ping [-c count] [-t timeout] <host>';
        }
        array_shift($tokens);

        $count = 5;
        $timeout = 2;
        $target = null;

        while ($tokens !== []) {
            $token = (string) array_shift($tokens);

            if ($token === '-c' || $token === '--count') {
                $value = (string) (array_shift($tokens) ?? '');
                if (!preg_match('/^\d{1,2}$/', $value)) {
                    return 'Invalid packet count. Usage: ping -c <1-10> <host>';
                }
                $count = (int) $value;
                if ($count < 1 || $count > 10) {
                    return 'Packet count must be between 1 and 10.';
                }
                continue;
            }

            if ($token === '-t' || $token === '--timeout') {
                $value = (string) (array_shift($tokens) ?? '');
                if (!preg_match('/^\d{1,2}$/', $value)) {
                    return 'Invalid timeout. Usage: ping -t <1-10> <host>';
                }
                $timeout = (int) $value;
                if ($timeout < 1 || $timeout > 10) {
                    return 'Timeout must be between 1 and 10 seconds.';
                }
                continue;
            }

            if ($token !== '' && $token[0] === '-') {
                return 'Unknown option "' . $token . '". Usage: ping [-c count] [-t timeout] <host>';
            }

            if ($target !== null) {
                return 'Too many arguments. Usage: ping [-c count] [-t timeout] <host>';
            }
            $target = $token;
        }

        if ($target === null || $target === '') {
            return 'Missing host. Usage: ping [-c count] [-t timeout] <host>';
        }

        $target = trim($target);
        if (strlen($target) > 253) {
            return 'Invalid target "' . $target . '". Host name too long.';
        }

        $isIp = filter_var($target, FILTER_VALIDATE_IP) !== false;
        $isHost = preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/i',
            $target
        ) === 1;

        if (!$isIp && !$isHost) {
            return 'Invalid target "' . $target . '". Enter a valid IP or host name.';
        }

        return [
            'target' => $target,
            'count' => $count,
            'timeout' => $timeout,
        ];
    }

    private function streamPing(string $target, int $count, int $timeout): void
    {
        session_write_close();
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        header('Connection: close');

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        if (!function_exists('proc_open')) {
            $this->emitEnd('Error: proc_open() is disabled on this server.', 1);
            return;
        }

        $command = PingService::ping_terminal_command($target, $count, $timeout);
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            $this->emitEnd('Error: failed to start ping process.', 1);
            return;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        set_time_limit(0);
        ignore_user_abort(true);

        $buffer = '';
        $deadline = time() + 120;

        while (true) {
            foreach ([1, 2] as $pipeNo) {
                $chunk = fread($pipes[$pipeNo], 4096);
                if (is_string($chunk) && $chunk !== '') {
                    $buffer .= $chunk;
                    $this->flushLines($buffer);
                }
            }

            if (connection_aborted()) {
                proc_terminate($process);
                break;
            }

            $status = proc_get_status($process);
            if (!$status['running'] && $buffer === '') {
                break;
            }
            if (!$status['running'] && !feof($pipes[1]) && !feof($pipes[2])) {
                continue;
            }
            if (!$status['running']) {
                $rest = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                if (is_string($rest) && $rest !== '') {
                    $buffer .= $rest;
                    $this->flushLines($buffer);
                }
                break;
            }
            if (time() >= $deadline) {
                proc_terminate($process);
                break;
            }

            usleep(120000);
        }

        if ($buffer !== '') {
            $this->emitLine($buffer);
            $buffer = '';
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->emitEnd('', $exitCode);
    }

    private function flushLines(string &$buffer): void
    {
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            $this->emitLine($line);
        }
    }

    private function emitLine(string $line): void
    {
        $line = rtrim($line, "\r");
        echo 'data: ' . json_encode(['line' => $line], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush();
        flush();
    }

    private function emitEnd(string $message, int $exitCode): void
    {
        if ($message !== '') {
            echo 'data: ' . json_encode(['line' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            @ob_flush();
            flush();
        }
        echo "event: end\n";
        echo 'data: ' . json_encode(['exit_code' => $exitCode]) . "\n\n";
        @ob_flush();
        flush();
    }
}
