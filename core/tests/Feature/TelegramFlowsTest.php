<?php

use App\Jobs\SendTelegramMarkedItemAlert;
use App\Models\CompanyLead;
use App\Models\IngestionBatch;
use App\Models\IngestionBatchItem;
use App\Models\TelegramDestination;
use App\Models\TelegramDestinationDelivery;
use App\Services\MasothueIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('telegram webhook capture creates an inactive destination by default', function () {
    config()->set('services.telegram.webhook_secret', 'secret-123');

    $response = $this->postJson('/api/telegram/webhook', [
        'my_chat_member' => [
            'chat' => [
                'id' => -100111222333,
                'title' => 'Nhóm Test',
                'type' => 'supergroup',
            ],
        ],
    ], [
        'X-Telegram-Bot-Api-Secret-Token' => 'secret-123',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'captured' => true,
        ]);

    $this->assertDatabaseHas('telegram_destinations', [
        'chat_id' => '-100111222333',
        'label' => 'Nhóm Test',
        'source' => 'telegram_webhook',
        'telegram_chat_type' => 'supergroup',
        'is_active' => 0,
    ]);
});

test('telegram marked-item alert is idempotent per batch item and destination', function () {
    config()->set('services.telegram.bot_token', 'bot-token-for-test');

    $destination = TelegramDestination::query()->create([
        'label' => 'Group A',
        'chat_id' => '-100555666777',
        'source' => 'manual',
        'is_active' => true,
    ]);

    $lead = CompanyLead::query()->create([
        'source' => 'masothue',
        'tax_code' => '0101010101',
        'company_name' => 'CÔNG TY TEST',
        'detail_url' => 'https://example.com/detail',
    ]);

    $batch = IngestionBatch::query()->create([
        'source' => 'masothue',
        'batch_key' => 'batch-test-1',
        'status' => 'processed',
        'company_count' => 1,
        'processed_company_count' => 1,
        'new_marked_count' => 1,
    ]);

    $item = IngestionBatchItem::query()->create([
        'ingestion_batch_id' => $batch->id,
        'company_lead_id' => $lead->id,
        'source' => 'masothue',
        'comparison_key' => sha1('masothue|0101010101|https://example.com/detail'),
        'tax_code' => '0101010101',
        'company_name' => 'CÔNG TY TEST',
        'detail_url' => 'https://example.com/detail',
        'phone' => '0912345678',
        'phone_signature' => '0912345678',
        'phone_numbers' => ['0912345678'],
        'is_new_since_previous_batch' => true,
        'marked_at' => now(),
    ]);

    Http::fake([
        'https://api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => [
                'message_id' => 999,
            ],
        ], 200),
    ]);

    $job = new SendTelegramMarkedItemAlert($item->id);
    $job->handle(app(\App\Services\OperationsLogService::class));
    $job->handle(app(\App\Services\OperationsLogService::class));

    Http::assertSentCount(1);

    $delivery = TelegramDestinationDelivery::query()
        ->where('ingestion_batch_item_id', $item->id)
        ->where('telegram_destination_id', $destination->id)
        ->first();

    expect($delivery)->not->toBeNull();
    expect($delivery->status)->toBe('sent');
    expect($delivery->attempt_count)->toBe(1);
    expect($delivery->telegram_message_id)->toBe('999');
});

test('phone comparison only uses the first primary phone number', function () {
    $service = app(MasothueIngestionService::class);

    $first = $service->ingest('masothue', 'worker-a', [
        'company_name' => 'CÔNG TY TEST PHONE',
        'tax_code' => '0909090909',
        'detail_url' => 'https://example.com/phone',
        'phone' => '0914036567',
        'phone_raw' => '0914036567',
        'phone_list' => ['0914036567'],
        'active_date' => '2026-07-09',
    ]);

    $second = $service->ingest('masothue', 'worker-a', [
        'company_name' => 'CÔNG TY TEST PHONE',
        'tax_code' => '0909090909',
        'detail_url' => 'https://example.com/phone',
        'phone' => '0914036567',
        'phone_raw' => '0914036567 09841255',
        'phone_list' => ['0914036567', '09841255'],
        'active_date' => '2026-07-09',
    ]);

    expect($first['phone_changed'])->toBeFalse();
    expect($second['phone_changed'])->toBeFalse();

    $lead = CompanyLead::query()->where('tax_code', '0909090909')->first();

    expect($lead)->not->toBeNull();
    expect($lead->phone)->toBe('0914036567');
    expect($lead->phone_signature)->toBe('0914036567');
    expect($lead->phone_numbers)->toBe(['0914036567', '09841255']);
});

test('worker logs can be pushed to core log files endpoint', function () {
    config()->set('services.worker.token', 'worker-secret');

    Log::shouldReceive('channel')->once()->with('worker_remote')->andReturnSelf();
    Log::shouldReceive('log')->once()->with(
        'warning',
        'Worker warning',
        \Mockery::on(fn (array $context) => $context['worker_name'] === 'bot-mst-worker'
            && $context['source'] === 'masothue'
            && $context['event'] === 'worker.cloudflare_listing')
    );

    $response = $this->withHeader('Authorization', 'Bearer worker-secret')
        ->postJson('/api/logs/worker', [
            'entries' => [[
                'level' => 'warning',
                'message' => 'Worker warning',
                'event' => 'worker.cloudflare_listing',
                'worker_name' => 'bot-mst-worker',
                'source' => 'masothue',
                'timestamp' => now()->toIso8601String(),
                'context' => [
                    'rayId' => 'abc123',
                ],
            ]],
        ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'accepted' => 1,
        ]);
});
