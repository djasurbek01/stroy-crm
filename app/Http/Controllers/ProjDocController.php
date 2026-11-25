<?php

namespace App\Http\Controllers;

use App\Models\DocVisa;
use App\Models\Project;
use App\Models\ProjectDoc;
use App\Models\User;
use App\Support\Activity;
use App\Support\ApiError;
use App\Support\Files;
use App\Support\RateLimit;
use App\Support\Site;
use App\Support\Telegram;
use Illuminate\Http\Request;

/**
 * Проектная документация: файл, версии, цепочка виз по порядку, «передано в работу».
 * Виза заказчика ставится по его ссылке. Предыдущие версии файла не хранятся (экономим диск), а решения по визам остаются.
 */
class ProjDocController extends Controller
{
    private const MAX_STEPS = 10;

    private function doc($id): ProjectDoc
    {
        return $this->own(ProjectDoc::class, $id, 'pdoc_not_found');
    }

    private static function recompute(ProjectDoc $doc, $visas): void
    {
        if ($visas->contains(fn ($v) => $v->decision === 'rejected')) {
            $doc->status = 'rejected';
        } elseif ($visas->isNotEmpty() && $visas->every(fn ($v) => $v->decision === 'approved')) {
            $doc->status = $doc->released_at ? 'in_work' : 'approved';
        } else {
            $doc->status = 'on_review';
        }
    }

    /** Цепочка виз из JSON-строки формы. */
    private function parseChain(?string $raw): array
    {
        $data = json_decode($raw ?: '[]', true);
        if (! is_array($data) || ! array_is_list($data)) {
            ApiError::abort(422, 'chain_invalid');
        }
        $steps = [];
        foreach ($data as $item) {
            if (! is_array($item)) {
                ApiError::abort(422, 'chain_invalid');
            }
            $label = is_string($item['label'] ?? null) ? trim($item['label']) : '';
            $side = $item['side'] ?? 'staff';
            $userId = $item['user_id'] ?? null;
            if ($label === '' || mb_strlen($label) > 120 || ! in_array($side, ['staff', 'customer'], true) || ($userId !== null && ! is_int($userId))) {
                ApiError::abort(422, 'chain_invalid');
            }
            $steps[] = ['label' => $label, 'side' => $side, 'user_id' => $userId];
        }
        if (! $steps) {
            ApiError::abort(422, 'chain_required');
        }
        if (count($steps) > self::MAX_STEPS) {
            ApiError::abort(422, 'chain_too_long');
        }
        foreach ($steps as $step) {
            $this->checkUser($step['side'] === 'staff' ? $step['user_id'] : null);
        }

        return $steps;
    }

    private static function staffIds(int $org): array
    {
        return User::where('organization_id', $org)->whereIn('role', ['owner', 'foreman', 'pm'])->pluck('id')->all();
    }

    public function index($projectId)
    {
        $this->ownProject($projectId);

        return ProjectDoc::where('project_id', (int) $projectId)->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn ($d) => Site::docOut($d));
    }

    public function store(Request $request, $projectId)
    {
        $project = $this->ownProject($projectId);
        $data = $request->validate([
            'title' => 'required|string|min:1|max:300',
            'chain' => 'sometimes|nullable|string',
            'section' => 'sometimes|nullable|string|max:60',
            'file' => 'required|file',
        ]);
        if (trim($data['title']) === '') {
            ApiError::abort(422, 'title_required');
        }
        $steps = $this->parseChain($data['chain'] ?? '[]');
        [$filename, $path, $size] = Files::saveUpload($project->organization_id, $request->file('file'));
        $user = $this->user();
        $doc = ProjectDoc::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'title' => trim($data['title']),
            'section' => trim($data['section'] ?? ''), 'filename' => $filename, 'path' => $path, 'size' => $size, 'version' => 1,
            'status' => 'on_review', 'created_by' => $user->name,
        ]);
        foreach ($steps as $position => $step) {
            DocVisa::create([
                'organization_id' => $project->organization_id, 'doc_id' => $doc->id, 'version' => 1, 'position' => $position,
                'label' => $step['label'], 'side' => $step['side'], 'user_id' => $step['side'] === 'staff' ? $step['user_id'] : null,
            ]);
        }
        Activity::log($project->organization_id, $project->id, 'doc', "Документ на визировании: {$doc->title}", $user->name);

        return response()->json(Site::docOut($doc->fresh()), 201);
    }

    /** Новая версия файла: визы запускаются заново с теми же людьми. */
    public function newVersion(Request $request, $id)
    {
        $doc = $this->doc($id);
        $request->validate(['file' => 'required|file']);
        $previous = Site::visas($doc);
        [$filename, $path, $size] = Files::saveUpload($doc->organization_id, $request->file('file'));
        $oldPath = $doc->path;
        $doc->version++;
        $doc->fill(['filename' => $filename, 'path' => $path, 'size' => $size, 'status' => 'on_review', 'released_at' => null, 'released_by' => '']);
        $doc->save();
        foreach ($previous as $v) {
            DocVisa::create([
                'organization_id' => $doc->organization_id, 'doc_id' => $doc->id, 'version' => $doc->version, 'position' => $v->position,
                'label' => $v->label, 'side' => $v->side, 'user_id' => $v->user_id,
            ]);
        }
        Activity::log($doc->organization_id, $doc->project_id, 'doc', "Новая версия {$doc->version}: {$doc->title}", $this->user()->name);
        Files::delete($oldPath);

        return response()->json(Site::docOut($doc), 201);
    }

    private static function decideVisa(ProjectDoc $doc, DocVisa $visa, array $payload, string $who): void
    {
        $visas = Site::visas($doc);
        $active = Site::activeVisa($visas);
        if ($visa->version !== $doc->version || $active === null || $active->id !== $visa->id) {
            ApiError::abort(409, 'visa_not_active');
        }
        $active->decision = $payload['decision'];
        $active->comment = trim($payload['comment'] ?? '');
        $active->decided_by = $who;
        $active->decided_at = now();
        $active->save();
        self::recompute($doc, $visas);
        $doc->save();
        $verb = $payload['decision'] === 'approved' ? 'подписана' : 'отклонена';
        Activity::log($doc->organization_id, $doc->project_id, 'doc', "Виза «{$visa->label}» $verb: {$doc->title}", $who);
    }

    public function decide(Request $request, $docId, $visaId)
    {
        $payload = $request->validate(['decision' => 'required|in:approved,rejected', 'comment' => 'sometimes|string|max:1000']);
        $doc = $this->doc($docId);
        $visa = is_numeric($visaId) ? DocVisa::find((int) $visaId) : null;
        if ($visa === null || $visa->doc_id !== $doc->id) {
            ApiError::abort(404, 'visa_not_found');
        }
        $user = $this->user();
        if ($visa->side !== 'staff' || ! ($user->role === 'owner' || $user->id === $visa->user_id)) {
            ApiError::abort(403, 'not_your_visa');
        }
        self::decideVisa($doc, $visa, $payload, $user->name);

        return Site::docOut($doc);
    }

    /** «Передать в работу»: можно только когда все визы подписаны. */
    public function release($id)
    {
        $doc = $this->doc($id);
        if ($doc->status !== 'approved') {
            ApiError::abort(409, 'not_approved');
        }
        $user = $this->user();
        $doc->fill(['status' => 'in_work', 'released_at' => now(), 'released_by' => $user->name])->save();
        Activity::log($doc->organization_id, $doc->project_id, 'doc', "Передано в работу: {$doc->title}", $user->name);

        return Site::docOut($doc);
    }

    public function destroy($id)
    {
        $doc = $this->doc($id);
        $path = $doc->path;
        DocVisa::where('doc_id', $doc->id)->delete();
        $doc->delete();
        Files::delete($path);

        return $this->noContent();
    }

    public function file($id)
    {
        $doc = $this->doc($id);
        $path = Files::fullPath($doc->path);
        if (! is_file($path)) {
            ApiError::abort(404, 'pdoc_not_found');
        }

        return response()->download($path, $doc->filename);
    }

    // --- со стороны заказчика, по ссылке ---

    private static function publicDoc(Request $request, string $token, $docId, string $bucket, int $limit): array
    {
        RateLimit::take($request, $bucket, $limit, 3600);
        $project = $token && strlen($token) <= 60 ? Project::where('customer_token', $token)->first() : null;
        $doc = $project && is_numeric($docId) ? ProjectDoc::find((int) $docId) : null;
        if ($project === null || $doc === null || $doc->project_id !== $project->id) {
            ApiError::abort(404, 'pdoc_not_found');
        }

        return [$project, $doc];
    }

    public function customerFile(Request $request, string $token, $docId)
    {
        [, $doc] = self::publicDoc($request, $token, $docId, 'customer:docfile', 300);
        $path = Files::fullPath($doc->path);
        if (! is_file($path)) {
            ApiError::abort(404, 'pdoc_not_found');
        }

        return response()->download($path, $doc->filename);
    }

    /** Заказчик ставит свою визу, когда до неё дошла очередь. */
    public function customerDecide(Request $request, string $token, $docId, $visaId)
    {
        $payload = $request->validate([
            'decision' => 'required|in:approved,rejected', 'comment' => 'sometimes|string|max:1000', 'name' => 'sometimes|string|max:120',
        ]);
        [$project, $doc] = self::publicDoc($request, $token, $docId, 'customer:visa', 40);
        $visa = is_numeric($visaId) ? DocVisa::find((int) $visaId) : null;
        if ($visa === null || $visa->doc_id !== $doc->id) {
            ApiError::abort(404, 'visa_not_found');
        }
        if ($visa->side !== 'customer') {
            ApiError::abort(403, 'not_customer_visa');
        }
        $who = trim($payload['name'] ?? '') ?: 'Заказчик';
        self::decideVisa($doc, $visa, $payload, $who);
        $verb = $payload['decision'] === 'approved' ? 'согласовал' : 'отклонил';
        Telegram::notifyUsers(self::staffIds($project->organization_id), "$who (заказчик) $verb документ «{$doc->title}» по объекту «{$project->name}»");

        return Site::docOut($doc, "/public/customer/$token/docs");
    }
}
