<?php

use App\Jobs\SendTelegramMarkedItemAlert;
use App\Models\CompanyLead;
use App\Models\CompanyLeadEvent;
use App\Models\IngestionBatch;
use App\Models\IngestionBatchItem;
use App\Models\TelegramDestination;
use App\Models\TelegramDestinationDelivery;
use App\Services\MasothueIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

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

test('batch comparison only marks a new tax code when a primary phone exists', function () {
    $service = app(MasothueIngestionService::class);
    $source = 'masothue-top10';

    $firstBatch = $service->queueBatch($source, 'worker-a', [
        [
            'company_name' => 'CÔNG TY A',
            'tax_code' => 'A001',
            'detail_url' => 'https://example.com/a-1',
            'phone' => '0901',
            'active_date' => '2026-07-09',
            'observed_at' => '2026-07-09T03:00:00Z',
        ],
        [
            'company_name' => 'CÔNG TY B',
            'tax_code' => 'B001',
            'detail_url' => 'https://example.com/b-1',
            'phone' => '0902',
            'active_date' => '2026-07-09',
            'observed_at' => '2026-07-09T03:00:00Z',
        ],
    ]);
    $service->processQueuedBatch($firstBatch['batch_key']);

    $secondBatch = $service->queueBatch($source, 'worker-a', [
        [
            'company_name' => 'CÔNG TY A',
            'tax_code' => 'A001',
            'detail_url' => 'https://example.com/a-2',
            'phone' => '0901',
            'active_date' => '2026-07-09',
            'observed_at' => '2026-07-09T03:05:00Z',
        ],
        [
            'company_name' => 'CÔNG TY C',
            'tax_code' => 'C001',
            'detail_url' => 'https://example.com/c-1',
            'phone' => null,
            'active_date' => '2026-07-09',
            'observed_at' => '2026-07-09T03:05:00Z',
        ],
        [
            'company_name' => 'CÔNG TY D',
            'tax_code' => 'D001',
            'detail_url' => 'https://example.com/d-1',
            'phone' => '0904000004',
            'active_date' => '2026-07-09',
            'observed_at' => '2026-07-09T03:05:00Z',
        ],
    ]);

    $result = $service->processQueuedBatch($secondBatch['batch_key']);

    expect($result[0]['is_new_tax_code_since_previous_batch'])->toBeFalse();
    expect($result[0]['is_new_since_previous_batch'])->toBeFalse();

    expect($result[1]['is_new_tax_code_since_previous_batch'])->toBeTrue();
    expect($result[1]['has_primary_phone'])->toBeFalse();
    expect($result[1]['is_new_since_previous_batch'])->toBeFalse();

    expect($result[2]['is_new_tax_code_since_previous_batch'])->toBeTrue();
    expect($result[2]['has_primary_phone'])->toBeTrue();
    expect($result[2]['is_new_since_previous_batch'])->toBeTrue();

    $latestBatch = IngestionBatch::query()->where('source', $source)->latest('id')->first();

    expect($latestBatch)->not->toBeNull();
    expect($latestBatch->new_marked_count)->toBe(1);
});

test('clear comparison data removes transient state but keeps telegram destinations', function () {
    $service = app(MasothueIngestionService::class);

    $destination = TelegramDestination::query()->create([
        'label' => 'Group Keep',
        'chat_id' => '-100123123123',
        'source' => 'manual',
        'is_active' => true,
    ]);

    $batch = IngestionBatch::query()->create([
        'source' => 'masothue',
        'batch_key' => 'masothue:batch:clear-data',
        'status' => 'processed',
        'company_count' => 1,
        'processed_company_count' => 1,
        'new_marked_count' => 1,
    ]);

    $lead = CompanyLead::query()->create([
        'source' => 'masothue',
        'tax_code' => 'CLEAR001',
        'company_name' => 'CÔNG TY CLEAR',
        'detail_url' => 'https://example.com/clear',
    ]);

    $item = IngestionBatchItem::query()->create([
        'ingestion_batch_id' => $batch->id,
        'company_lead_id' => $lead->id,
        'source' => 'masothue',
        'comparison_key' => sha1('masothue|CLEAR001'),
        'tax_code' => 'CLEAR001',
        'company_name' => 'CÔNG TY CLEAR',
        'detail_url' => 'https://example.com/clear',
        'is_new_since_previous_batch' => true,
        'marked_at' => now(),
    ]);

    CompanyLeadEvent::query()->create([
        'company_lead_id' => $lead->id,
        'source' => 'masothue',
        'dedupe_key' => sha1('clear-event'),
        'observed_at' => now(),
        'payload' => [],
    ]);

    TelegramDestinationDelivery::query()->create([
        'ingestion_batch_item_id' => $item->id,
        'telegram_destination_id' => $destination->id,
        'status' => 'sent',
        'attempt_count' => 1,
        'sent_at' => now(),
    ]);

    Redis::connection('default')->setex('masothue:batch:clear-data', 300, 'payload');

    $summary = $service->clearComparisonData('masothue');

    expect($summary['batches_deleted'])->toBe(1);
    expect($summary['batch_items_deleted'])->toBe(1);
    expect($summary['events_deleted'])->toBe(1);
    expect($summary['leads_deleted'])->toBe(1);
    expect($summary['redis_batch_keys_deleted'])->toBeGreaterThanOrEqual(1);

    expect(IngestionBatch::query()->count())->toBe(0);
    expect(IngestionBatchItem::query()->count())->toBe(0);
    expect(CompanyLeadEvent::query()->count())->toBe(0);
    expect(CompanyLead::query()->count())->toBe(0);
    expect(TelegramDestinationDelivery::query()->count())->toBe(0);
    expect(TelegramDestination::query()->count())->toBe(1);
});

test('clear redis batch keys keeps the current latest batch key by default', function () {
    $service = app(MasothueIngestionService::class);

    IngestionBatch::query()->create([
        'source' => 'masothue',
        'batch_key' => 'masothue:batch:older',
        'status' => 'processed',
        'company_count' => 1,
    ]);

    IngestionBatch::query()->create([
        'source' => 'masothue',
        'batch_key' => 'masothue:batch:current',
        'status' => 'processed',
        'company_count' => 1,
    ]);

    Redis::connection('default')->setex('masothue:batch:older', 300, 'older');
    Redis::connection('default')->setex('masothue:batch:current', 300, 'current');

    $summary = $service->clearRedisBatchKeys('masothue');

    expect($summary['kept_batch_key'])->toBe('masothue:batch:current');
    expect((bool) Redis::connection('default')->exists('masothue:batch:current'))->toBeTrue();
    expect((bool) Redis::connection('default')->exists('masothue:batch:older'))->toBeFalse();
});
