<?php

namespace App\Http\Controllers;

use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\DealItem;
use App\Models\Organization;
use App\Models\User;
use App\Support\ApiError;
use App\Support\Audit;
use App\Support\CrmEngine;
use App\Support\Fmt;
use App\Support\RateLimit;
use App\Support\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Расчёт стоимости и коммерческое предложение (КП): позиции в сделке, ссылка для клиента, «Принимаю». */
class OfferController extends Controller
{
    private function deal($id): Deal
    {
        return $this->own(Deal::class, $id, 'deal_not_found');
    }

    private static function items(int $dealId)
    {
        return DealItem::where('deal_id', $dealId)->orderBy('position')->orderBy('id')->get();
    }

    /** Заменяет позиции расчёта. Если позиции есть, бюджет сделки становится их суммой. */
    public function save(Request $request, $id)
    {
        $data = $request->validate([
            'items' => 'present|array|max:200',
            'items.*.title' => 'required',
            'items.*.quantity' => 'sometimes|numeric|gt:0|max:1000000000',
            'items.*.unit' => 'sometimes|string|max:30',
            'items.*.price' => 'sometimes|numeric|min:0|max:1000000000000',
            'note' => 'sometimes|string|max:3000',
            'valid_until' => 'sometimes|nullable|date_format:Y-m-d',
        ]);
        foreach ($data['items'] as $i => $item) {
            $title = is_string($item['title']) ? trim($item['title']) : '';
            if ($title === '' || mb_strlen($title) > 300) {
                throw ValidationException::withMessages(["items.$i.title" => 'title']);
            }
            $data['items'][$i]['title'] = $title;
        }
        $deal = $this->deal($id);
        $oldTotal = self::items($deal->id)->sum(fn ($i) => $i->quantity * $i->price);
        DealItem::where('deal_id', $deal->id)->delete();
        $total = 0.0;
        foreach ($data['items'] as $position => $item) {
            $quantity = (float) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);
            DealItem::create([
                'organization_id' => $deal->organization_id, 'deal_id' => $deal->id, 'position' => $position, 'title' => $item['title'],
                'quantity' => $quantity, 'unit' => trim($item['unit'] ?? ''), 'price' => $price,
            ]);
            $total += $quantity * $price;
        }
        $deal->offer_note = trim($data['note'] ?? '');
        $deal->offer_valid_until = $data['valid_until'] ?? null;
        if ($data['items']) {
            $deal->amount = round($total, 2);
        }
        $deal->save();
        if (abs($total - $oldTotal) > 0.0001) {
            CrmEngine::event($deal, 'note', 'Расчёт стоимости изменён: '.Fmt::money0($oldTotal).' → '.Fmt::money0($total).' ₽', $this->user()->id);
        }

        return DealController::detail($deal);
    }

    private static function link(Deal $deal): array
    {
        $base = config('stroy.base_url');

        return ['token' => $deal->offer_token, 'path' => "/offer.html?t={$deal->offer_token}", 'url' => $base ? "$base/offer.html?t={$deal->offer_token}" : ''];
    }

    public function share($id)
    {
        $deal = $this->deal($id);
        if (self::items($deal->id)->isEmpty()) {
            ApiError::abort(409, 'offer_is_empty');
        }
        if (! $deal->offer_token) {
            $deal->offer_token = Str::random(24);
            $deal->save();
            CrmEngine::event($deal, 'note', 'Ссылка на коммерческое предложение создана', $this->user()->id);
        }

        return self::link($deal);
    }

    public function unshare($id)
    {
        $deal = $this->deal($id);
        if ($deal->offer_token) {
            $deal->offer_token = null;
            $deal->save();
            CrmEngine::event($deal, 'note', 'Ссылка на коммерческое предложение отключена', $this->user()->id);
        }

        return $this->noContent();
    }

    // --- публичная часть: клиент по ссылке ---

    private static function publicDeal(string $token): Deal
    {
        $deal = $token !== '' && strlen($token) <= 60 ? Deal::where('offer_token', $token)->first() : null;
        if ($deal === null) {
            ApiError::abort(404, 'offer_not_found');
        }

        return $deal;
    }

    public function publicShow(Request $request, string $token)
    {
        RateLimit::take($request, 'offer:ip', 120, 3600);
        $deal = self::publicDeal($token);
        $org = Organization::find($deal->organization_id);
        $party = $deal->counterparty_id ? Counterparty::withTrashed()->find($deal->counterparty_id) : null;
        $items = self::items($deal->id);

        return [
            'company' => ['name' => $org->name, 'phone' => $org->phone, 'email' => $org->email, 'details' => $org->details ?? ''],
            'title' => $deal->title,
            'address' => $deal->address,
            'client' => $party ? $party->name : '',
            'date' => ($deal->created_at ?? now())->toDateString(),
            'valid_until' => Fmt::d($deal->offer_valid_until),
            'expired' => (bool) ($deal->offer_valid_until && $deal->offer_valid_until->lt(today())),
            'note' => $deal->offer_note ?? '',
            'items' => $items->map(fn ($i) => ['title' => $i->title, 'quantity' => (float) $i->quantity, 'unit' => $i->unit, 'price' => (float) $i->price, 'total' => (float) ($i->quantity * $i->price)])->all(),
            'total' => (float) $items->sum(fn ($i) => $i->quantity * $i->price),
            'accepted_at' => Fmt::dt($deal->offer_accepted_at),
        ];
    }

    public function publicAccept(Request $request, string $token)
    {
        $data = $request->validate(['name' => 'sometimes|string|max:120', 'comment' => 'sometimes|string|max:1000']);
        RateLimit::take($request, 'offer:accept', 10, 3600);
        $deal = self::publicDeal($token);
        if ($deal->offer_valid_until && $deal->offer_valid_until->lt(today())) {
            ApiError::abort(409, 'offer_expired');
        }
        if ($deal->offer_accepted_at !== null) {
            return ['ok' => true, 'accepted_at' => Fmt::dt($deal->offer_accepted_at), 'already' => true];
        }
        $deal->offer_accepted_at = now();
        $deal->save();
        $who = trim($data['name'] ?? '') ?: 'Клиент';
        $comment = trim($data['comment'] ?? '');
        $comment = $comment !== '' ? " Комментарий: $comment" : '';
        CrmEngine::event($deal, 'note', "$who принял(а) коммерческое предложение по ссылке.$comment");
        $people = User::where('organization_id', $deal->organization_id)->whereIn('role', ['owner', 'manager'])->pluck('id')->all();
        if ($deal->responsible_id) {
            $people[] = $deal->responsible_id;
        }
        Telegram::notifyUsers($people, "Клиент принял предложение: {$deal->title}");
        Audit::log(null, 'update', 'deal', $deal->id, "Клиент принял коммерческое предложение «{$deal->title}»", $deal->organization_id);

        return ['ok' => true, 'accepted_at' => Fmt::dt($deal->offer_accepted_at), 'already' => false];
    }

    // --- реквизиты компании для шапки КП ---

    private static function company(Organization $org): array
    {
        return ['name' => $org->name, 'phone' => $org->phone, 'email' => $org->email, 'details' => $org->details ?? ''];
    }

    public function companyShow()
    {
        return self::company(Organization::find($this->org()));
    }

    public function companyUpdate(Request $request)
    {
        $data = $request->validate(['phone' => 'sometimes|string|max:50', 'email' => 'sometimes|string|max:200', 'details' => 'sometimes|string|max:2000']);
        $org = Organization::find($this->org());
        $org->phone = trim($data['phone'] ?? '');
        $org->email = trim($data['email'] ?? '');
        $org->details = trim($data['details'] ?? '');
        $org->save();
        Audit::log($this->user(), 'update', 'settings', null, 'Изменены реквизиты компании для коммерческих предложений');

        return self::company($org);
    }
}
