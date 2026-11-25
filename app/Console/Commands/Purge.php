<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\DocumentVersion;
use App\Models\PasswordReset;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\RateEvent;
use App\Models\TelegramOutbox;
use App\Models\Todo;
use App\Support\Files;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Окончательное удаление того, что лежит в корзине дольше срока, и чистка служебных таблиц. Журнал действий не трогается. */
#[Signature('stroy:purge {--days=30 : сколько дней хранить удалённое}')]
#[Description('Очистить корзину (старше 30 дней) и служебные таблицы')]
class Purge extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        $expired = fn (string $class) => $class::onlyTrashed()->where('deleted_at', '<', $cutoff)->pluck('id')->all();
        $files = [];
        $counts = DB::transaction(function () use ($expired, &$files) {
            $counts = [];
            $deals = $expired(Deal::class);
            DealEvent::whereIn('deal_id', $deals)->delete();
            Todo::whereIn('deal_id', $deals)->delete();
            Deal::withTrashed()->whereIn('id', $deals)->forceDelete();
            $counts['deals'] = count($deals);

            $docs = $expired(Document::class);
            $files = DocumentVersion::whereIn('document_id', $docs)->pluck('stored_path')->all();
            Payment::whereIn('document_id', $docs)->update(['document_id' => null, 'match' => '']);
            DocumentApproval::whereIn('document_id', $docs)->delete();
            DocumentVersion::whereIn('document_id', $docs)->delete();
            Document::withTrashed()->whereIn('id', $docs)->forceDelete();
            $counts['documents'] = count($docs);

            $contracts = $expired(Contract::class);
            Document::withTrashed()->whereIn('contract_id', $contracts)->update(['contract_id' => null]);
            Purchase::withTrashed()->whereIn('contract_id', $contracts)->update(['contract_id' => null]);
            Contract::withTrashed()->whereIn('id', $contracts)->forceDelete();
            $counts['contracts'] = count($contracts);

            $purchases = $expired(Purchase::class);
            Purchase::withTrashed()->whereIn('id', $purchases)->forceDelete();
            $counts['purchases'] = count($purchases);

            $parties = $expired(Counterparty::class);
            foreach ([Deal::class, Document::class, Contract::class, Purchase::class] as $class) {
                $class::withTrashed()->whereIn('counterparty_id', $parties)->update(['counterparty_id' => null]);
            }
            Payment::whereIn('counterparty_id', $parties)->update(['counterparty_id' => null]);
            Counterparty::withTrashed()->whereIn('id', $parties)->forceDelete();
            $counts['contacts'] = count($parties);

            return $counts;
        });
        // файлы удаляем после успешной фиксации базы
        foreach ($files as $relative) {
            Files::delete($relative);
        }

        $now = now();
        $service = [
            'rate_events' => RateEvent::where('created_at', '<', $now->copy()->subDays(2))->delete(),
            'password_resets' => PasswordReset::where('expires_at', '<', $now->copy()->subDay())->orWhereNotNull('used_at')->delete(),
            'outbox' => TelegramOutbox::where(fn ($q) => $q->whereNotNull('sent_at')->where('sent_at', '<', $now->copy()->subDays(30)))
                ->orWhere(fn ($q) => $q->where('failed', true)->where('created_at', '<', $now->copy()->subDays(30)))->delete(),
        ];
        $this->info('Удалено навсегда: '.http_build_query($counts, '', ', '));
        $this->info('Служебные таблицы очищены: '.http_build_query($service, '', ', '));

        return self::SUCCESS;
    }
}
