<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;

return [
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/dashboard.php', 'handler' => 'redirect:dashboard'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/servers.php', 'handler' => 'redirect:servers'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/server-add.php', 'handler' => 'redirect:servers/add'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/server-detail.php', 'handler' => static function (Request $r): Response {
        return Response::redirect(app_url('servers/' . (int) $r->query('id', 0)), 301);
    }],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/server-edit.php', 'handler' => static function (Request $r): Response {
        return Response::redirect(app_url('servers/' . (int) $r->query('id', 0) . '/edit'), 301);
    }],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/server-setup.php', 'handler' => static function (Request $r): Response {
        return Response::redirect(app_url('servers/' . (int) $r->query('id', 0) . '/setup'), 301);
    }],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/ping-monitors.php', 'handler' => 'redirect:ping'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/ping-add.php', 'handler' => 'redirect:ping/add'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/ping-detail.php', 'handler' => static function (Request $r): Response {
        return Response::redirect(app_url('ping/' . (int) $r->query('id', 0)), 301);
    }],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/ping-edit.php', 'handler' => static function (Request $r): Response {
        return Response::redirect(app_url('ping/' . (int) $r->query('id', 0) . '/edit'), 301);
    }],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/disk-health.php', 'handler' => 'redirect:disk-health'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/disk-health-detail.php', 'handler' => static function (Request $r): Response {
        return Response::redirect(app_url('disk-health/' . (int) $r->query('id', 0)), 301);
    }],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/ip-reputation.php', 'handler' => 'redirect:ip-reputation'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/ip-reputation-add.php', 'handler' => 'redirect:ip-reputation/add'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/ip-reputation-detail.php', 'handler' => static function (Request $r): Response {
        return Response::redirect(app_url('ip-reputation/' . (int) $r->query('id', 0)), 301);
    }],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/ip-reputation-edit.php', 'handler' => static function (Request $r): Response {
        return Response::redirect(app_url('ip-reputation/' . (int) $r->query('id', 0) . '/edit'), 301);
    }],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/alert-logs.php', 'handler' => 'redirect:alerts'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/audit-logs.php', 'handler' => 'redirect:audit-logs'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/export.php', 'handler' => 'redirect:export'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/export-download.php', 'handler' => static function (Request $r): Response {
        return Response::redirect(app_url('export/download/' . (int) $r->query('id', 0)), 301);
    }],
    ['methods' => ['GET', 'POST'], 'pattern' => '/admin/settings.php', 'handler' => 'redirect:settings'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/auth/login.php', 'handler' => 'redirect:login'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/auth/logout.php', 'handler' => 'redirect307:logout'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/public.php', 'handler' => 'redirect:status'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/index.php', 'handler' => 'redirect:/'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/api/push.php', 'handler' => 'redirect308:api/push'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/api/push-disk.php', 'handler' => 'redirect308:api/push-disk'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/api/status.php', 'handler' => 'redirect:api/status'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/api/alerts.php', 'handler' => 'redirect308:api/alerts'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/api/events.php', 'handler' => 'redirect:api/events'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/api/health.php', 'handler' => 'redirect:api/health'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/api/time.php', 'handler' => 'redirect:api/time'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/api/public_alerts.php', 'handler' => 'redirect:api/public-alerts'],
    ['methods' => ['GET', 'POST'], 'pattern' => '/api/ip-reputation.php', 'handler' => 'redirect308:api/ip-reputation'],
];
