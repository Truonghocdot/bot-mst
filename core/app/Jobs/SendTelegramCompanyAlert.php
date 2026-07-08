<?php

namespace App\Jobs;

use App\Models\CompanyLead;
use App\Models\CompanyLeadEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTelegramCompanyAlert implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $tries = 3;

    public function __construct(
        public int $companyLeadId,
        public int $eventId,
    ) {
    }

    public function handle(): void
    {
        $token = (string) config('services.telegram.bot_token');
        $chatId = (string) config('services.telegram.chat_id');

        if ($token === '' || $chatId === '') {
            Log::info('Skipping Telegram alert because bot token or chat id is missing.', [
                'company_lead_id' => $this->companyLeadId,
                'event_id' => $this->eventId,
            ]);

            return;
        }

        $company = CompanyLead::query()->find($this->companyLeadId);
        $event = CompanyLeadEvent::query()->find($this->eventId);

        if (! $company || ! $event) {
            Log::warning('Skipping Telegram alert because company or event could not be loaded.', [
                'company_lead_id' => $this->companyLeadId,
                'event_id' => $this->eventId,
            ]);

            return;
        }

        $message = implode("\n", array_filter([
            'Doanh nghiep moi tu MaSoThue',
            'Ten: '.$company->company_name,
            'MST: '.$company->tax_code,
            $company->phone ? 'SDT: '.$company->phone : null,
            $company->legal_representative ? 'Nguoi dai dien: '.$company->legal_representative : null,
            $company->active_date ? 'Ngay hoat dong: '.$company->active_date->toDateString() : null,
            $company->detail_url,
        ]));

        Http::asForm()
            ->timeout(10)
            ->retry(3, 500)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'disable_web_page_preview' => true,
            ])
            ->throw();
    }
}
