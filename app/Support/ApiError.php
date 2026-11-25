<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;

/** Ошибка API в прежнем формате: {"detail": "код"}. Интерфейс переводит код в текст. */
class ApiError extends HttpResponseException
{
    public function __construct(int $status, string $code, array $headers = [])
    {
        parent::__construct(response()->json(['detail' => $code], $status, $headers));
    }

    public static function abort(int $status, string $code, array $headers = []): never
    {
        throw new self($status, $code, $headers);
    }
}
