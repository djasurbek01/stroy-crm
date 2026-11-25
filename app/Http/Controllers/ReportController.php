<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\SiteReport;
use App\Models\WorkStage;
use App\Support\Activity;
use App\Support\ApiError;
use App\Support\Files;
use App\Support\RateLimit;
use App\Support\Site;
use Illuminate\Http\Request;

/** Фото- и видеоотчёты по этапам. Файл проверяется по содержимому, а не по имени. */
class ReportController extends Controller
{
    private const MAX_PHOTO_BYTES = 8 * 1024 * 1024;

    private const MAX_VIDEO_BYTES = 25 * 1024 * 1024;

    private const ORG_LIMIT_BYTES = 1536 * 1024 * 1024;  // 1,5 ГБ отчётов на организацию

    /** По первым байтам определяет [вид, расширение, mime]. Подделать имя файла проще, чем содержимое. */
    public static function sniff(string $data): ?array
    {
        if (str_starts_with($data, "\xFF\xD8\xFF")) {
            return ['photo', '.jpg', 'image/jpeg'];
        }
        if (str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            return ['photo', '.png', 'image/png'];
        }
        if (substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
            return ['photo', '.webp', 'image/webp'];
        }
        if (substr($data, 4, 4) === 'ftyp') {
            return ['video', '.mp4', 'video/mp4'];
        }
        if (str_starts_with($data, "\x1a\x45\xdf\xa3")) {
            return ['video', '.webm', 'video/webm'];
        }

        return null;
    }

    private function report($id): SiteReport
    {
        return $this->own(SiteReport::class, $id, 'report_not_found');
    }

    private static function serve(SiteReport $report)
    {
        $path = Files::fullPath($report->path);
        if (! is_file($path)) {
            ApiError::abort(404, 'report_not_found');
        }

        return response()->file($path, ['Content-Type' => $report->mime, 'Content-Disposition' => 'inline', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function index(Request $request, $projectId)
    {
        $this->ownProject($projectId);

        return Site::projectReports((int) $projectId, $this->qInt($request, 'stage_id'))->map(fn ($r) => Site::reportOut($r));
    }

    public function store(Request $request, $projectId)
    {
        $project = $this->ownProject($projectId);
        $data = $request->validate(['file' => 'required|file', 'stage_id' => 'sometimes|nullable|integer', 'caption' => 'sometimes|nullable|string|max:300']);
        $stageId = isset($data['stage_id']) && $data['stage_id'] !== '' ? (int) $data['stage_id'] : null;
        if ($stageId !== null) {
            $stage = WorkStage::find($stageId);
            if ($stage === null || $stage->project_id !== $project->id) {
                ApiError::abort(404, 'stage_not_found');
            }
        }
        $bytes = Files::read($request->file('file'), self::MAX_VIDEO_BYTES, 'video_too_large');
        if ($bytes === '') {
            ApiError::abort(422, 'file_empty');
        }
        $found = self::sniff(substr($bytes, 0, 16));
        if ($found === null) {
            ApiError::abort(422, 'report_type_not_allowed');
        }
        [$kind, $extension, $mime] = $found;
        if ($kind === 'photo' && strlen($bytes) > self::MAX_PHOTO_BYTES) {
            ApiError::abort(413, 'photo_too_large');
        }
        $used = (int) SiteReport::where('organization_id', $project->organization_id)->sum('size');
        if ($used + strlen($bytes) > self::ORG_LIMIT_BYTES) {
            ApiError::abort(507, 'reports_storage_full');
        }
        $path = Files::saveBytes($project->organization_id, $extension, $bytes);
        $user = $this->user();
        $report = SiteReport::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'stage_id' => $stageId, 'kind' => $kind,
            'path' => $path, 'mime' => $mime, 'size' => strlen($bytes), 'caption' => trim($data['caption'] ?? ''), 'author' => $user->name,
        ]);
        $what = $kind === 'photo' ? 'фото' : 'видео';
        Activity::log($project->organization_id, $project->id, 'report', "Добавлено $what".($report->caption ? ": {$report->caption}" : ''), $user->name);

        return response()->json(Site::reportOut($report->fresh()), 201);
    }

    public function file($id)
    {
        return self::serve($this->report($id));
    }

    public function destroy($id)
    {
        $report = $this->report($id);
        $path = $report->path;
        $report->delete();
        Files::delete($path);

        return $this->noContent();
    }

    public function customerFile(Request $request, string $token, $reportId)
    {
        RateLimit::take($request, 'customer:file', 1200, 3600);
        $project = $token && strlen($token) <= 60 ? Project::where('customer_token', $token)->first() : null;
        $report = $project && is_numeric($reportId) ? SiteReport::find((int) $reportId) : null;
        if ($project === null || $report === null || $report->project_id !== $project->id) {
            ApiError::abort(404, 'report_not_found');
        }

        return self::serve($report);
    }
}
