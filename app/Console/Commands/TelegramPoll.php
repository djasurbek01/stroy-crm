<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\TelegramLead;
use App\Models\User;
use App\Support\Leads;
use App\Support\RateLimit;
use App\Support\Telegram;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Фоновый процесс Telegram: принимает /start <код> (привязка сотрудника), заявки клиентов по ссылке
 * и рассылает очередь уведомлений. Запуск под supervisor. Нужны STROY_TG_TOKEN и STROY_TG_BOT.
 */
#[Signature('stroy:telegram-poll')]
#[Description('Telegram-бот: привязка сотрудников, приём заявок и отправка очереди уведомлений')]
class TelegramPoll extends Command
{
    public function handle(): int
    {
        if (! Telegram::enabled()) {
            $this->error('STROY_TG_TOKEN и STROY_TG_BOT не заданы');

            return self::FAILURE;
        }
        $offset = 0;
        $lastFlush = 0;
        while (true) {
            if (time() - $lastFlush >= 3) {
                try {
                    Telegram::flushOutbox();
                } catch (\Throwable $e) {
                    Log::warning('outbox failed: '.class_basename($e));
                }
                $lastFlush = time();
            }
            try {
                // короткий опрос, чтобы очередь отправлялась без задержек
                $result = Telegram::apiCall('getUpdates', ['offset' => $offset, 'timeout' => 3, 'allowed_updates' => ['message']], 10);
                foreach ($result['result'] ?? [] as $update) {
                    $offset = $update['update_id'] + 1;
                    $this->handleUpdate($update);
                }
            } catch (\Throwable $e) {
                Log::warning('poll failed: '.class_basename($e));
                sleep(5);
            }
        }
    }

    public function handleUpdate(array $update): void
    {
        $message = $update['message'] ?? [];
        $text = trim($message['text'] ?? '');
        $chatId = $message['chat']['id'] ?? null;
        if (! $chatId) {
            return;
        }
        if (str_starts_with($text, '/start')) {
            $arg = trim(substr($text, 6));
            if (str_starts_with($arg, 'lead_')) {
                $this->startLeadChat($chatId, substr($arg, 5));

                return;
            }
            $user = $arg !== '' ? User::where('telegram_link_code', $arg)->first() : null;
            if ($user === null) {
                Telegram::enqueue($chatId, 'Не получилось подключить. Откройте Стройку, раздел «Команда», и нажмите «Подключить Telegram» ещё раз.');

                return;
            }
            $user->telegram_chat_id = $chatId;
            $user->telegram_link_code = null;
            $user->save();
            Telegram::enqueue($chatId, "Готово, {$user->name}. Сюда будут приходить уведомления из Стройки.");

            return;
        }
        if ($text !== '' && ! str_starts_with($text, '/')) {
            $this->leadMessage($chatId, $text, $message['from'] ?? []);
        }
    }

    private function startLeadChat(int $chatId, string $key): void
    {
        $org = Leads::orgByKey($key);
        if ($org === null) {
            Telegram::enqueue($chatId, 'Эта ссылка больше не работает.');

            return;
        }
        TelegramLead::updateOrCreate(['chat_id' => $chatId], ['organization_id' => $org->id]);
        Telegram::enqueue($chatId, "Здравствуйте! Это {$org->name}. Опишите, что нужно построить, и оставьте телефон: мы свяжемся с вами.");
    }

    /** Сообщение от клиента, пришедшего по ссылке приёма заявок. От чужих чатов ничего не берём. */
    private function leadMessage(int $chatId, string $text, array $sender): void
    {
        $row = TelegramLead::find($chatId);
        if ($row === null || RateLimit::count('lead:chat', (string) $chatId, 3600) >= 10) {
            return;
        }
        RateLimit::hit('lead:chat', (string) $chatId);
        $org = Organization::find($row->organization_id);
        $name = trim(($sender['first_name'] ?? '').' '.($sender['last_name'] ?? '')) ?: ($sender['username'] ?? 'Клиент из Telegram');
        $extra = ! empty($sender['username']) ? '@'.$sender['username'] : '';
        Leads::createLead($org, $name, Leads::findPhoneInText($text), '', $text, 'telegram', $extra);
        Telegram::enqueue($chatId, 'Спасибо! Заявка принята, мы свяжемся с вами.');
    }
}
