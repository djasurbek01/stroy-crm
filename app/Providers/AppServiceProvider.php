<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Почта для сброса пароля: настройки STROY_SMTP_* как в прежней версии (порт 465 = SSL).
        if (config('stroy.smtp_host') !== '' && config('stroy.smtp_from') !== '' && ! $this->app->runningUnitTests()) {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => config('stroy.smtp_host'),
                'mail.mailers.smtp.port' => config('stroy.smtp_port'),
                'mail.mailers.smtp.username' => config('stroy.smtp_user') ?: null,
                'mail.mailers.smtp.password' => config('stroy.smtp_pass') ?: null,
                'mail.mailers.smtp.scheme' => config('stroy.smtp_port') === 465 ? 'smtps' : 'smtp',
                'mail.from.address' => config('stroy.smtp_from'),
            ]);
        }

        // В боевом режиме нельзя работать с ключом по умолчанию и без HTTPS-cookie.
        if (config('stroy.production')) {
            config(['session.secure' => true]);
        }
    }
}
