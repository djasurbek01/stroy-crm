<?php

use Illuminate\Support\Facades\Schedule;

/*
| Расписание (нужен cron: * * * * * php artisan schedule:run).
| Telegram-бот работает отдельным процессом: php artisan stroy:telegram-poll
*/
Schedule::command('stroy:reminders')->dailyAt('08:00');
Schedule::command('stroy:purge')->dailyAt('03:30');
