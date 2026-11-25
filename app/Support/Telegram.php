<?php

namespace App\Support;

use App\Models\TelegramOutbox;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Уведомления в Telegram через очередь.
 *
 * notifyUsers только кладёт сообщения в таблицу, а отправляет фоновый процесс (php artisan stroy:telegram-poll).
 * Поэтому сбой Telegram не задерживает запросы, а сообщения не теряются: неудачные пробуются снова
 * через 1 минуту, 5 минут, 30 минут и 2 часа, после пятой неудачи помечаются как не доставленные.
 * Без токена (STROY_TG_TOKEN) ничего не ставится в очередь.
 */
class Telegram
{
    /** Паузы между повторами, в секундах. */
    public const BACKOFF = [60, 300, 1800, 7200];

    /** Подменяется в тестах: fn(int $chatId, string $text): bool */
    public static $sender = null;

    public static function enabled(): bool
    {
        return config('stroy.tg_token') !== '' && config('stroy.tg_bot') !== '';
    }

    public static function apiCall(string $method, array $payload, int $timeout = 40): array
    {
        $token = config('stroy.tg_token');

        return Http::timeout($timeout)->post("https://api.telegram.org/bot{$token}/{$method}", $payload)->throw()->json() ?? [];
    }

    public static function sendMessage(int $chatId, string $text): bool
    {
        if (self::$sender) {
            return (bool) (self::$sender)($chatId, $text);
        }
        if (! config('stroy.tg_token')) {
            return false;
        }
        try {
            self::apiCall('sendMessage', ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4000)], 10);

            return true;
        } catch (\Throwable $e) { // токен в тексте ошибки не логируем
            Log::warning('telegram send failed: '.class_basename($e));

            return false;
        }
    }

    public static function enqueue(int $chatId, string $text): void
    {
        TelegramOutbox::create(['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4000), 'next_try_at' => now()]);
    }

    /** Ставит сообщение в очередь тем из сотрудников, кто подключил Telegram. Сам ничего не отправляет. */
    public static function notifyUsers(iterable $userIds, string $text): void
    {
        if (! config('stroy.tg_token')) {
            return;
        }
        $ids = array_unique(array_filter(is_array($userIds) ? $userIds : iterator_to_array($userIds)));
        foreach (User::whereIn('id', $ids)->whereNotNull('telegram_chat_id')->get() as $user) {
            self::enqueue($user->telegram_chat_id, $text);
        }
    }

    /** Отправляет то, что пора отправить. Возвращает счётчики: sent, retry, failed. */
    public static function flushOutbox(int $limit = 50): array
    {
        $now = now();
        $rows = TelegramOutbox::whereNull('sent_at')->where('failed', false)->where('next_try_at', '<=', $now)
            ->orderBy('id')->limit($limit)->get();
        $result = ['sent' => 0, 'retry' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            if (self::sendMessage($row->chat_id, $row->text)) {
                $row->sent_at = $now;
                $row->save();
                $result['sent']++;

                continue;
            }
            $row->attempts++;
            if ($row->attempts >= count(self::BACKOFF) + 1) {
                $row->failed = true;
                $result['failed']++;
            } else {
                $row->next_try_at = $now->copy()->addSeconds(self::BACKOFF[$row->attempts - 1]);
                $result['retry']++;
            }
            $row->save();
        }

        return $result;
    }
}
