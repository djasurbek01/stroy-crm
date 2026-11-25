<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/** Защитные заголовки и проверка источника запросов (вместо CSRF-токенов: интерфейс шлёт JSON с того же адреса). */
class SecurityHeaders
{
    private const UNSAFE = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const CSP = "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
        ."script-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'; object-src 'none'";

    private const NO_STORE = ['auth', 'home', 'deals', 'documents', 'payments', 'export', 'audit', 'trash', 'company', 'ai', 'leads'];

    public function handle(Request $request, Closure $next): Response
    {
        $path = '/'.ltrim($request->path(), '/');
        if (in_array($request->method(), self::UNSAFE, true) && ! str_starts_with($path, '/public/')) {
            $origin = $request->header('origin');
            if ($origin && parse_url($origin, PHP_URL_HOST).(parse_url($origin, PHP_URL_PORT) ? ':'.parse_url($origin, PHP_URL_PORT) : '') !== $request->header('host')) {
                return response()->json(['detail' => 'bad_origin'], 403);
            }
        }

        $response = $next($request);
        $headers = $response->headers;
        $set = function (string $name, string $value) use ($headers) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        };
        $set('X-Content-Type-Options', 'nosniff');
        $set('Referrer-Policy', 'same-origin');
        $set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        // при разработке скрипты отдаёт сервер Vite с другого адреса: строгий CSP ему мешает
        if (! Vite::isRunningHot()) {
            $embeddable = str_starts_with($path, '/lead') || str_starts_with($path, '/offer');
            $set('Content-Security-Policy', self::CSP.($embeddable ? '; frame-ancestors *' : "; frame-ancestors 'none'"));
            if (! $embeddable) {
                $set('X-Frame-Options', 'DENY');
            }
        }
        if (config('stroy.production')) {
            $set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $first = explode('/', trim($path, '/'))[0] ?? '';
        if (in_array($first, self::NO_STORE, true)) {
            $headers->set('Cache-Control', 'no-store, private');
        } elseif ($request->isMethod('GET') && ($path === '/' || str_ends_with($path, '.html') || str_ends_with($path, '.webmanifest') || $path === '/sw.js')) {
            // страница проверяется на каждое открытие, чтобы после обновления не висела старая версия
            $headers->set('Cache-Control', 'no-cache, private');
        }

        return $response;
    }
}
