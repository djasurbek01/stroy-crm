<?php

/*
| Настройки «Стройки». Те же переменные окружения, что и в прежней версии (STROY_*).
*/

return [
    'production' => env('STROY_ENV') === 'production',
    'trust_proxy' => env('STROY_TRUST_PROXY') === '1',
    // документация API: /docs и /openapi.json (STROY_DOCS=0 закрывает их на боевом сервере)
    'docs' => env('STROY_DOCS', '1') !== '0',
    'base_url' => rtrim((string) env('STROY_BASE_URL', ''), '/'),

    // Почта для сброса пароля: считается настроенной, если задан SMTP-сервер и адрес отправителя.
    'smtp_host' => env('STROY_SMTP_HOST', ''),
    'smtp_port' => (int) env('STROY_SMTP_PORT', 587),
    'smtp_user' => env('STROY_SMTP_USER', ''),
    'smtp_pass' => env('STROY_SMTP_PASS', ''),
    'smtp_from' => env('STROY_SMTP_FROM', ''),

    // ИИ-разбор документов
    'ai_key' => env('STROY_AI_KEY', ''),
    'ai_model' => env('STROY_AI_MODEL', 'claude-haiku-5-5'),
    'ai_daily_limit' => (int) env('STROY_AI_DAILY_LIMIT', 30),

    // Telegram
    'tg_token' => env('STROY_TG_TOKEN', ''),
    'tg_bot' => env('STROY_TG_BOT', ''),

    // Файлы: папка внутри storage/app/private
    'storage_dir' => env('STROY_STORAGE', 'stroy'),
    'max_file_bytes' => 25 * 1024 * 1024,

    'plan_limits' => [
        'start' => ['projects' => 3, 'users' => 5, 'pipelines' => 1],
        'business' => ['projects' => 100, 'users' => 100, 'pipelines' => 20],
    ],
];
