<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Organization;
use App\Models\Stage;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\CrmEngine;
use App\Support\Leads;
use App\Support\RateLimit;
use App\Support\Telegram;
use Illuminate\Http\Request;

/** Заявки: публичная форма (без входа), настройки ссылки, принять или отклонить заявку. */
class LeadController extends Controller
{
    public function publicLead(Request $request, string $key)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'phone' => 'sometimes|string|max:50',
            'email' => 'sometimes|string|max:200',
            'message' => 'sometimes|string|max:2000',
            'website' => 'sometimes|string|max:200',  // ловушка для ботов: люди это поле не видят
        ]);
        $ip = RateLimit::ip($request);
        RateLimit::check('lead:ip', $ip, 6, 3600);
        RateLimit::check('lead:key', mb_substr($key, 0, 60), 120, 3600);
        RateLimit::hit('lead:ip', $ip);
        RateLimit::hit('lead:key', mb_substr($key, 0, 60));

        if (trim($data['website'] ?? '') !== '') {  // заполнила ловушка: молча «принимаем», ничего не создавая
            return response()->json(['ok' => true], 202);
        }
        $org = Leads::orgByKey($key);
        if ($org === null) {
            ApiError::abort(404, 'lead_form_not_found');
        }
        $phoneDigits = preg_replace('/\D/', '', $data['phone'] ?? '');
        if (strlen($phoneDigits) < 6 && ! str_contains($data['email'] ?? '', '@')) {
            ApiError::abort(422, 'contact_required');
        }
        Leads::createLead($org, $data['name'] ?? '', $data['phone'] ?? '', $data['email'] ?? '', $data['message'] ?? '', 'site');

        return response()->json(['ok' => true], 202);
    }

    private function orgModel(): Organization
    {
        return Organization::find($this->org());
    }

    /** Полный адрес известен, только если задан STROY_BASE_URL; иначе интерфейс достроит его по адресу страницы. */
    private static function settings(Organization $org): array
    {
        $base = config('stroy.base_url');
        $key = $org->public_key;
        $form = $key && $base ? "$base/lead.html?k=$key" : '';
        $bot = $key && Telegram::enabled() ? 'https://t.me/'.config('stroy.tg_bot')."?start=lead_$key" : '';

        return [
            'key' => $key,
            'form_path' => $key ? "/lead.html?k=$key" : '',
            'form_url' => $form,
            'iframe' => $key ? '<iframe src="'.($form ?: "/lead.html?k=$key").'" width="100%" height="460" style="border:0"></iframe>' : '',
            'telegram_url' => $bot,
        ];
    }

    public function settingsShow()
    {
        $org = $this->orgModel();
        if (! $org->public_key) {
            $org->public_key = Leads::newKey();
            $org->save();
        }

        return self::settings($org);
    }

    /** Выдаёт новую ссылку. Старая перестаёт работать. */
    public function rotate()
    {
        $org = $this->orgModel();
        $org->public_key = Leads::newKey();
        $org->save();
        Audit::log($this->user(), 'update', 'settings', null, 'Ссылка приёма заявок заменена на новую');

        return self::settings($org);
    }

    private function unsortedDeal($id): Deal
    {
        $deal = $this->own(Deal::class, $id, 'deal_not_found');
        if (! $deal->unsorted) {
            ApiError::abort(409, 'not_unsorted');
        }

        return $deal;
    }

    public function accept(Request $request, $id)
    {
        $data = $request->validate(['responsible_id' => 'sometimes|nullable|integer', 'stage_id' => 'sometimes|nullable|integer']);
        $deal = $this->unsortedDeal($id);
        $this->checkUser($data['responsible_id'] ?? null);
        if (($data['stage_id'] ?? null) !== null) {
            $stage = Stage::find($data['stage_id']);
            if ($stage === null || $stage->pipeline_id !== $deal->pipeline_id) {
                ApiError::abort(404, 'stage_not_found');
            }
            $deal->stage_id = $stage->id;
        }
        $deal->unsorted = false;
        $deal->responsible_id = ($data['responsible_id'] ?? null) ?: $this->user()->id;
        $deal->save();
        CrmEngine::event($deal, 'moved', 'Заявка принята в работу', $this->user()->id);

        return DealController::detail($deal);
    }

    public function reject(Request $request, $id)
    {
        $data = $request->validate(['reason' => 'sometimes|string|max:300']);
        $deal = $this->unsortedDeal($id);
        $lost = Stage::where('pipeline_id', $deal->pipeline_id)->where('kind', 'lost')->orderBy('position')->first();
        CrmEngine::event($deal, 'note', trim('Заявка отклонена. '.($data['reason'] ?? '')), $this->user()->id);
        if ($lost !== null) {
            $deal->stage_id = $lost->id;
            $deal->closed_at = now();
            $deal->unsorted = false;
            $deal->save();
        } else {
            $deal->delete();  // нет этапа «Отказ»: уходит в корзину
        }

        return $this->noContent();
    }
}
