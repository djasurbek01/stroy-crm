<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\Fmt;
use Illuminate\Http\Request;

/** Центр уведомлений: что произошло и требует внимания сотрудника. */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->user();
        $limit = max(1, min($this->qInt($request, 'limit', 50), 200));
        $query = Notification::where('user_id', $user->id);
        if ($this->qBool($request, 'unread', false)) {
            $query->whereNull('read_at');
        }
        $rows = $query->orderByDesc('id')->limit($limit)->get();

        return [
            'items' => $rows->map(fn ($n) => [
                'id' => $n->id, 'project_id' => $n->project_id, 'text' => $n->text, 'link' => $n->link,
                'created_at' => Fmt::dt($n->created_at), 'read' => $n->read_at !== null,
            ]),
            'unread' => Notification::where('user_id', $user->id)->whereNull('read_at')->count(),
        ];
    }

    /** Отметить прочитанными: список ids или все сразу (all). */
    public function read(Request $request)
    {
        $data = $request->validate(['ids' => 'sometimes|array|max:200', 'ids.*' => 'integer', 'all' => 'sometimes|boolean']);
        $query = Notification::where('user_id', $this->user()->id)->whereNull('read_at');
        if (empty($data['all'])) {
            $query->whereIn('id', $data['ids'] ?? [0] ?: [0]);
        }
        $query->update(['read_at' => now()]);

        return $this->noContent();
    }
}
