<?php

namespace App\Support;

use App\Models\RateEvent;
use Illuminate\Http\Request;

/** Ограничение частоты через базу: работает при нескольких процессах и переживает перезапуск. */
class RateLimit
{
    /** За прокси адрес берём из X-Forwarded-For, но только если прокси доверенный. */
    public static function ip(Request $request): string
    {
        if (config('stroy.trust_proxy')) {
            $forwarded = (string) $request->header('x-forwarded-for', '');
            if ($forwarded !== '') {
                return mb_substr(trim(explode(',', $forwarded)[0]), 0, 100);
            }
        }

        return mb_substr($request->server('REMOTE_ADDR') ?? 'unknown', 0, 100);
    }

    public static function count(string $bucket, string $key, int $seconds): int
    {
        return RateEvent::where('bucket', $bucket)->where('key', $key)
            ->where('created_at', '>=', now()->subSeconds($seconds))->count();
    }

    public static function hit(string $bucket, string $key): void
    {
        RateEvent::create(['bucket' => $bucket, 'key' => mb_substr($key, 0, 200)]);
    }

    public static function clear(string $bucket, string $key): void
    {
        RateEvent::where('bucket', $bucket)->where('key', $key)->delete();
    }

    /** 429, если за окно уже было limit и больше событий. */
    public static function check(string $bucket, string $key, int $limit, int $seconds): void
    {
        if (self::count($bucket, $key, $seconds) >= $limit) {
            ApiError::abort(429, 'too_many_attempts', ['Retry-After' => (string) $seconds]);
        }
    }

    /** Проверить и сразу засчитать по адресу клиента (для публичных адресов). */
    public static function take(Request $request, string $bucket, int $limit, int $seconds): void
    {
        $ip = self::ip($request);
        self::check($bucket, $ip, $limit, $seconds);
        self::hit($bucket, $ip);
    }
}
