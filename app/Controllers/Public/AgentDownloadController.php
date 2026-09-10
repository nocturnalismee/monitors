<?php
declare(strict_types=1);

namespace App\Controllers\Public;

use App\Http\Request;
use App\Http\Response;

final class AgentDownloadController
{
    private const ALLOWED_ROOT = [
        'monitoring-agent.sh',
        'monitoring-agent-cpanel-mail.sh',
        'agent-disk-health.sh',
    ];

    private const ALLOWED_SYSTEMD = [
        'monitoring-agent.service',
        'monitoring-agent.timer',
        'monitoring-agent-cpanel-email.service',
        'monitoring-agent-cpanel-email.timer',
        'monitoring-agent.conf.example',
        'monitoring-agent-cpanel-mail.conf.example',
    ];

    public function download(Request $request): Response
    {
        $name = basename((string) ($request->params['file'] ?? ''));
        if (!in_array($name, self::ALLOWED_SYSTEMD, true)) {
            return Response::text('Not Found', 404);
        }

        return $this->serveFile(SERVMON_BASE_DIR . '/agents/systemd/' . $name);
    }

    public function downloadRoot(Request $request): Response
    {
        $name = basename((string) ($request->params['file'] ?? ''));
        if (!in_array($name, self::ALLOWED_ROOT, true)) {
            return Response::text('Not Found', 404);
        }

        return $this->serveFile(SERVMON_BASE_DIR . '/agents/' . $name);
    }

    private function serveFile(string $path): Response
    {
        if (!is_file($path) || !is_readable($path)) {
            return Response::text('Not Found', 404);
        }

        return Response::text((string) file_get_contents($path))
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"');
    }
}
