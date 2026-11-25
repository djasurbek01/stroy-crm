<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Вход обязателен. Версия сессии хранится у пользователя: смена пароля или «выйти везде»
 * увеличивает её, и прежние входы перестают работать.
 */
class StroyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user === null || (int) $request->session()->get('token_version', -1) !== (int) $user->token_version) {
            ApiError::abort(401, 'not_authenticated');
        }

        return $next($request);
    }
}
