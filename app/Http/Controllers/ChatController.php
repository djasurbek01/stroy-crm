<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatSeen;
use App\Models\User;
use App\Support\Activity;
use App\Support\ApiError;
use App\Support\Fmt;
use App\Support\RateLimit;
use App\Support\Site;
use App\Support\Telegram;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Чат объекта (сотрудники и заказчик по ссылке), лента событий и камера на объекте. */
class ChatController extends Controller
{
    private const PAGE = 200;

    private static function out(ChatMessage $m): array
    {
        return ['id' => $m->id, 'side' => $m->side, 'author' => $m->author, 'body' => $m->body, 'created_at' => Fmt::dt($m->created_at)];
    }

    /** При первой загрузке хвост переписки, при опросе только новые. */
    private static function messages(int $projectId, int $after)
    {
        return ChatMessage::where('project_id', $projectId)->where('id', '>', $after)->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values();
    }

    private static function body($value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || mb_strlen($value) > 2000) {
            throw ValidationException::withMessages(['body' => 'body']);
        }

        return $value;
    }

    private static function staffIds(int $org): array
    {
        return User::where('organization_id', $org)->whereIn('role', ['owner', 'foreman', 'pm'])->pluck('id')->all();
    }

    public function index(Request $request, $projectId)
    {
        $this->ownProject($projectId);
        $user = $this->user();
        $seen = (int) (ChatSeen::where('user_id', $user->id)->where('project_id', (int) $projectId)->value('last_id') ?? 0);
        $unread = ChatMessage::where('project_id', (int) $projectId)->where('id', '>', $seen)->where('side', 'customer')->count();

        return [
            'messages' => self::messages((int) $projectId, $this->qInt($request, 'after', 0))->map(fn ($m) => self::out($m)),
            'last_seen_id' => $seen,
            'unread' => $unread,
        ];
    }

    public function send(Request $request, $projectId)
    {
        $project = $this->ownProject($projectId);
        $body = self::body($request->input('body'));
        $user = $this->user();
        $message = ChatMessage::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'side' => 'staff', 'user_id' => $user->id,
            'author' => $user->name, 'body' => $body,
        ]);

        return response()->json(self::out($message->fresh()), 201);
    }

    public function seen(Request $request, $projectId)
    {
        $project = $this->ownProject($projectId);
        $data = $request->validate(['last_id' => 'required|integer|min:0']);
        $user = $this->user();
        $row = ChatSeen::where('user_id', $user->id)->where('project_id', $project->id)->first();
        if ($row === null) {
            ChatSeen::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'user_id' => $user->id, 'last_id' => $data['last_id']]);
        } elseif ($data['last_id'] > $row->last_id) {
            $row->last_id = $data['last_id'];
            $row->save();
        }

        return $this->noContent();
    }

    public function customerIndex(Request $request, string $token)
    {
        RateLimit::take($request, 'customer:chatpoll', 1500, 3600);
        $project = Site::projectByToken($token);

        return ['messages' => self::messages($project->id, $this->qInt($request, 'after', 0))->map(fn ($m) => self::out($m))];
    }

    public function customerSend(Request $request, string $token)
    {
        $data = $request->validate(['body' => 'required', 'name' => 'sometimes|string|max:120']);
        $body = self::body($data['body']);
        RateLimit::take($request, 'customer:chatsend', 60, 3600);
        $project = Site::projectByToken($token);
        $who = trim($data['name'] ?? '') ?: 'Заказчик';
        $message = ChatMessage::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'side' => 'customer', 'author' => $who, 'body' => $body]);
        $preview = mb_strlen($body) <= 120 ? $body : mb_substr($body, 0, 117).'...';
        Telegram::notifyUsers(self::staffIds($project->organization_id), "$who (заказчик) пишет в чате объекта «{$project->name}»: $preview");

        return response()->json(self::out($message->fresh()), 201);
    }

    // --- лента и камера ---

    public function activity($projectId)
    {
        $this->ownProject($projectId);

        return Activity::recent((int) $projectId)->map(fn ($e) => Activity::out($e));
    }

    public function camera($projectId)
    {
        return Site::cameraOut($this->ownProject($projectId));
    }

    /** Сохраняет ссылку на трансляцию. Принимаем только https: другие схемы отбрасываются. */
    public function setCamera(Request $request, $projectId)
    {
        $project = $this->ownProject($projectId);
        $data = $request->validate(['url' => 'sometimes|nullable|string|max:500', 'label' => 'sometimes|string|max:120', 'public' => 'sometimes|boolean']);
        $url = trim($data['url'] ?? '') ?: null;
        if ($url && ! preg_match('/^https:\/\/[^\s<>"\']{4,490}$/', $url)) {
            ApiError::abort(422, 'camera_url_invalid');
        }
        $project->camera_url = $url;
        $project->camera_label = trim($data['label'] ?? '');
        $project->camera_public = (bool) (($data['public'] ?? false) && $url);
        $project->save();

        return Site::cameraOut($project);
    }
}
