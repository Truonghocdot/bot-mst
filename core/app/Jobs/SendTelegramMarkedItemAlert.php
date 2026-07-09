<?php

namespace App\Jobs;

use App\Models\IngestionBatchItem;
use App\Models\TelegramDestination;
use App\Models\TelegramDestinationDelivery;
use App\Services\OperationsLogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTelegramMarkedItemAlert implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $tries = 3;

    public function __construct(
        public int $ingestionBatchItemId,
    ) {
    }

    public function handle(OperationsLogService $operationsLog): void
    {
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            Log::info('Skipping Telegram marked-item alert because bot token is missing.', [
                'ingestion_batch_item_id' => $this->ingestionBatchItemId,
            ]);

            return;
        }

        $item = IngestionBatchItem::query()->find($this->ingestionBatchItemId);

        if (! $item) {
            Log::warning('Skipping Telegram marked-item alert because batch item was not found.', [
                'ingestion_batch_item_id' => $this->ingestionBatchItemId,
            ]);

            return;
        }

        $destinations = TelegramDestination::query()
            ->where('is_active', true)
            ->get();

        if ($destinations->isEmpty()) {
            Log::info('Skipping Telegram marked-item alert because no active Telegram destination exists.', [
                'ingestion_batch_item_id' => $this->ingestionBatchItemId,
            ]);

            return;
        }

        $message = implode("\n", array_filter([
            'Dữ liệu mới từ MaSoThue',
            'Ten: '.$item->company_name,
            'MST: '.$item->tax_code,
            $item->phone ? 'SDT: '.$item->phone : null,
            $item->active_date ? 'Ngay hoat dong: '.$item->active_date->toDateString() : null,
            $item->detail_url,
        ]));
        $hadFailure = false;

        foreach ($destinations as $destination) {
            $delivery = TelegramDestinationDelivery::query()->firstOrCreate(
                [
                    'ingestion_batch_item_id' => $item->id,
                    'telegram_destination_id' => $destination->id,
                ],
                [
                    'status' => 'pending',
                ],
            );

            if ($delivery->status === 'sent') {
                $operationsLog->info('Skipped Telegram marked-item alert because it was already sent.', [
                    'ingestion_batch_item_id' => $item->id,
                    'telegram_destination_id' => $destination->id,
                    'chat_id' => $destination->chat_id,
                    'telegram_message_id' => $delivery->telegram_message_id,
                    'sent_at' => $delivery->sent_at?->toIso8601String(),
                ]);

                continue;
            }

            try {
                $operationsLog->info('Sending Telegram marked-item alert.', [
                    'ingestion_batch_item_id' => $item->id,
                    'telegram_destination_id' => $destination->id,
                    'chat_id' => $destination->chat_id,
                    'label' => $destination->label,
                    'delivery_status' => $delivery->status,
                    'attempt_count' => $delivery->attempt_count,
                    'message_preview' => $message,
                ]);

                $response = Http::asForm()
                    ->timeout(10)
                    ->retry(3, 500)
                    ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                        'chat_id' => $destination->chat_id,
                        'text' => $message,
                        'disable_web_page_preview' => true,
                    ])
                    ->throw();

                $responsePayload = $response->json();
                $messageId = data_get($responsePayload, 'result.message_id');
                $responseChatId = data_get($responsePayload, 'result.chat.id');
                $responseChatType = data_get($responsePayload, 'result.chat.type');
                $responseChatTitle = data_get($responsePayload, 'result.chat.title');

                $delivery->forceFill([
                    'status' => 'sent',
                    'attempt_count' => $delivery->attempt_count + 1,
                    'telegram_message_id' => $messageId,
                    'response_payload' => $responsePayload,
                    'last_error_message' => null,
                    'last_attempt_at' => now(),
                    'sent_at' => now(),
                ])->save();

                $destination->forceFill([
                    'last_sent_at' => now(),
                    'last_error_message' => null,
                ])->save();

                $operationsLog->info('Sent Telegram marked-item alert.', [
                    'ingestion_batch_item_id' => $item->id,
                    'telegram_destination_id' => $destination->id,
                    'chat_id' => $destination->chat_id,
                    'telegram_message_id' => $messageId,
                    'response_chat_id' => $responseChatId,
                    'response_chat_type' => $responseChatType,
                    'response_chat_title' => $responseChatTitle,
                    'response_ok' => data_get($responsePayload, 'ok'),
                    'response_payload' => $responsePayload,
                ]);
            } catch (\Throwable $exception) {
                $hadFailure = true;

                $delivery->forceFill([
                    'status' => 'failed',
                    'attempt_count' => $delivery->attempt_count + 1,
                    'last_error_message' => $exception->getMessage(),
                    'last_attempt_at' => now(),
                ])->save();

                $destination->forceFill([
                    'last_error_message' => $exception->getMessage(),
                ])->save();

                Log::warning('Telegram send failed for destination.', [
                    'telegram_destination_id' => $destination->id,
                    'ingestion_batch_item_id' => $item->id,
                    'error' => $exception->getMessage(),
                ]);

                $operationsLog->error('Failed to send Telegram marked-item alert.', [
                    'ingestion_batch_item_id' => $item->id,
                    'telegram_destination_id' => $destination->id,
                    'chat_id' => $destination->chat_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($hadFailure) {
            throw new \RuntimeException('One or more Telegram destinations failed and will be retried.');
        }
    }
}
