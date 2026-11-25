<?php

namespace App\Http\Controllers;

use App\Models\AiUsage;
use App\Models\Counterparty;
use App\Support\Ai;
use App\Support\AiError;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\Files;
use Illuminate\Http\Request;

class AiController extends Controller
{
    private function todayCount(): int
    {
        return AiUsage::where('organization_id', $this->org())->where('created_at', '>=', now()->subDay())->count();
    }

    public function status()
    {
        $limit = config('stroy.ai_daily_limit');

        return ['enabled' => Ai::enabled(), 'limit' => $limit, 'left' => max($limit - $this->todayCount(), 0)];
    }

    /** Подсказка для формы документа. Файл не сохраняется и ничего не создаётся. */
    public function recognize(Request $request)
    {
        $request->validate(['file' => 'required|file']);
        if (! Ai::enabled()) {
            ApiError::abort(503, 'ai_not_configured');
        }
        if ($this->todayCount() >= config('stroy.ai_daily_limit')) {
            ApiError::abort(429, 'ai_limit_reached');
        }
        $upload = $request->file('file');
        $data = Files::read($upload, Ai::MAX_BYTES + 1);
        $error = null;
        try {
            [$fields, $spent] = Ai::recognize($upload->getClientOriginalName() ?? '', $data);
        } catch (AiError $e) {
            $error = $e->codeName;
        }
        // попытка засчитывается в лимит, если запрос ушёл к ИИ (то есть он стоил денег)
        if ($error === null || in_array($error, ['ai_unavailable', 'ai_bad_answer'], true)) {
            AiUsage::create([
                'organization_id' => $this->org(), 'user_id' => $this->user()->id, 'ok' => $error === null,
                'input_tokens' => $error === null ? $spent['input_tokens'] : 0, 'output_tokens' => $error === null ? $spent['output_tokens'] : 0,
            ]);
        }
        if ($error !== null) {
            $status = ['ai_file_type' => 422, 'file_empty' => 422, 'file_too_large' => 413, 'ai_unavailable' => 502, 'ai_bad_answer' => 502][$error] ?? 500;
            ApiError::abort($status, $error);
        }

        $match = null;
        if ($fields['counterparty_inn']) {
            $match = Counterparty::org($this->org())->where('inn', $fields['counterparty_inn'])->first();
        }
        if ($match === null && $fields['counterparty_name']) {
            $needle = mb_strtolower($fields['counterparty_name']);
            $match = Counterparty::org($this->org())->get()->first(fn ($c) => mb_strtolower($c->name) === $needle);
        }
        Audit::log($this->user(), 'ai', 'document', null, 'Документ распознан ИИ (подсказка для формы)', null, ['tokens' => $spent['input_tokens'] + $spent['output_tokens']]);

        return $fields + ['counterparty_id' => $match?->id];
    }
}
