<?php

use App\Http\Middleware\RequireCap;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StroyAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Пробелы по краям и пустые строки обрабатываются в самих операциях, как в прежней версии.
        $middleware->remove([TrimStrings::class, ConvertEmptyStringsToNull::class]);
        // Вместо CSRF-токенов: проверка заголовка Origin (SecurityHeaders) и cookie с SameSite=Lax.
        $middleware->validateCsrfTokens(except: ['*']);
        $middleware->appendToGroup('web', SecurityHeaders::class);
        $middleware->alias([
            'stroy.auth' => StroyAuth::class,
            'cap' => RequireCap::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Ошибки в прежнем формате {"detail": ...}: интерфейс показывает понятный текст по коду.
        $exceptions->render(function (ValidationException $e, Request $request) {
            $detail = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $detail[] = ['loc' => ['body', $field], 'msg' => $message, 'type' => 'value_error'];
                }
            }

            return response()->json(['detail' => $detail], 422);
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $status = $e->getStatusCode();
            $text = match ($status) {
                404 => 'Not Found',
                405 => 'Method Not Allowed',
                413 => 'file_too_large',
                default => $e->getMessage() ?: 'error',
            };

            return response()->json(['detail' => $text], $status, $e->getHeaders());
        });
    })->create();
