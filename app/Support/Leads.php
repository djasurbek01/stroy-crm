<?php

namespace App\Support;

use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Stage;
use App\Models\User;
use Illuminate\Support\Str;

/** Приём заявок: общая логика для публичной формы и Telegram-бота. */
class Leads
{
    public const SOURCES = ['site' => 'Сайт', 'telegram' => 'Telegram', 'manual' => 'Вручную'];

    public static function digits(?string $phone): string
    {
        return preg_replace('/\D/', '', $phone ?? '');
    }

    /** +7 900 123-45-67, 8 900..., 9001234567 приводятся к одному виду для поиска дублей. */
    public static function normalizePhone(?string $phone): string
    {
        $d = self::digits($phone);
        if (strlen($d) === 11 && in_array($d[0], ['7', '8'], true)) {
            $d = substr($d, 1);
        }

        return $d;
    }

    public static function newKey(): string
    {
        return Str::random(24);
    }

    public static function orgByKey(?string $key): ?Organization
    {
        if (! $key || strlen($key) > 60) {
            return null;
        }

        return Organization::where('public_key', $key)->first();
    }

    public static function findPhoneInText(string $text): string
    {
        if (preg_match('/(?:\+?7|8)?[\s(\-]*\d{3}[\s)\-]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}/u', $text, $m)) {
            return trim($m[0]);
        }

        return '';
    }

    /**
     * Создаёт заявку в «Неразобранном». Повторная заявка того же человека за сутки дописывается в прежнюю.
     * Возвращает [сделка, создана_новая].
     */
    public static function createLead(Organization $org, ?string $name, ?string $phone, ?string $email, ?string $message, string $source, string $extra = ''): array
    {
        CrmEngine::ensureDefault($org->id);
        $name = mb_substr(trim($name ?? ''), 0, 120) ?: 'Без имени';
        $phone = mb_substr(trim($phone ?? ''), 0, 50);
        $email = mb_substr(trim($email ?? ''), 0, 200);
        $message = mb_substr(trim($message ?? ''), 0, 2000);

        $key = self::normalizePhone($phone);
        $party = null;
        foreach (Counterparty::where('organization_id', $org->id)->orderBy('id')->get() as $item) {
            if ($key !== '' && self::normalizePhone($item->phone) === $key) {
                $party = $item;
                break;
            }
            if ($email !== '' && $item->email !== '' && mb_strtolower($item->email) === mb_strtolower($email)) {
                $party = $item;
                break;
            }
        }
        if ($party === null) {
            $party = Counterparty::create(['organization_id' => $org->id, 'name' => $name, 'kind' => 'customer', 'phone' => $phone, 'email' => $email]);
        }

        $existing = Deal::where('organization_id', $org->id)->where('counterparty_id', $party->id)->where('unsorted', true)
            ->where('created_at', '>=', now()->subDay())->orderByDesc('id')->first();
        $details = implode(' · ', array_filter([self::SOURCES[$source] ?? $source, $phone, $email, $extra], fn ($x) => $x !== ''));
        if ($existing !== null) {
            CrmEngine::event($existing, 'note', 'Повторная заявка: '.($message ?: 'без текста')." ($details)");
            self::notify($org, "Повторная заявка от $name: ".(mb_substr($message, 0, 100) ?: 'без текста'));

            return [$existing, false];
        }

        $pipeline = CrmEngine::firstPipeline($org->id);
        $stage = Stage::where('pipeline_id', $pipeline->id)->orderBy('position')->orderBy('id')->first();
        $deal = Deal::create([
            'organization_id' => $org->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id,
            'title' => mb_substr("Заявка: $name", 0, 300), 'counterparty_id' => $party->id, 'unsorted' => true, 'source' => $source,
        ]);
        CrmEngine::event($deal, 'created', "Заявка получена ($details)");
        if ($message !== '') {
            CrmEngine::event($deal, 'note', $message);
        }
        self::notify($org, trim("Новая заявка: $name. ".mb_substr($message, 0, 100)));

        return [$deal, true];
    }

    private static function notify(Organization $org, string $text): void
    {
        $people = User::where('organization_id', $org->id)->whereIn('role', ['owner', 'manager'])->pluck('id')->all();
        Telegram::notifyUsers($people, $text);
    }
}
