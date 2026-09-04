<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Services\ExportService;

final class ExportDownloadController
{
    public function index(Request $request): Response
    {
        require_role('admin');

        $id = (int) ($request->params['id'] ?? 0);
        $job = db_one(
            'SELECT file_path, file_name, status FROM export_jobs WHERE id = :id AND user_id = :uid LIMIT 1',
            [':id' => $id, ':uid' => (int) (current_user()['id'] ?? 0)]
        );
        if ($job === null || $job['status'] !== 'completed') {
            return Response::text('Export is not available.', 404);
        }

        $exportPath = realpath(ExportService::export_job_dir());
        $filePath = realpath((string) ($job['file_path'] ?? ''));
        if ($exportPath === false || $filePath === false || !is_file($filePath)) {
            return Response::text('Export is not available.', 404);
        }

        $exportPrefix = rtrim($exportPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($filePath, $exportPrefix)) {
            error_log('Blocked export path outside export directory for job_id=' . $id);
            return Response::text('Export is not available.', 404);
        }

        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $job['file_name']) ?: 'export.dat';
        $this->streamFile($filePath, $name);
    }

    private function streamFile(string $filePath, string $name): never
    {
        $size = (int) filesize($filePath);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) $size);
        readfile($filePath);
        exit;
    }
}
