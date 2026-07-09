<?php

namespace App\Services;

use App\Jobs\SendTelegramMarkedItemAlert;
use App\Models\CompanyLead;
use App\Models\CompanyLeadEvent;
use App\Models\IngestionBatch;
use App\Models\IngestionBatchItem;
use App\Models\TelegramDestinationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class MasothueIngestionService
{
    private const REDIS_BATCH_KEY_PREFIX = 'masothue:batch:';

    private const REDIS_BATCH_TTL_SECONDS = 3600;

    public function __construct(
        private readonly OperationsLogService $operationsLog,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $companies
     * @return array{batch_key: string, queued: int}
     */
    public function queueBatch(string $source, ?string $workerName, array $companies): array
    {
        $batchKey = self::REDIS_BATCH_KEY_PREFIX.Str::uuid()->toString();

        Redis::connection('default')->setex($batchKey, self::REDIS_BATCH_TTL_SECONDS, json_encode([
            'source' => $source,
            'worker_name' => $workerName,
            'companies' => $companies,
            'queued_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->operationsLog->info('Queued batch into Redis.', [
            'source' => $source,
            'worker_name' => $workerName,
            'queued' => count($companies),
            'batch_key' => $batchKey,
        ]);

        return [
            'batch_key' => $batchKey,
            'queued' => count($companies),
        ];
    }

    /**
     * @return array<int, array<string, bool|int>>
     */
    public function processQueuedBatch(string $batchKey): array
    {
        $rawBatch = Redis::connection('default')->get($batchKey);

        if ($rawBatch === null) {
            throw new \RuntimeException("Queued batch [{$batchKey}] was not found in Redis.");
        }

        $decodedBatch = json_decode($rawBatch, true, 512, JSON_THROW_ON_ERROR);
        $source = (string) ($decodedBatch['source'] ?? '');
        $workerName = $decodedBatch['worker_name'] ?? null;
        $companies = $decodedBatch['companies'] ?? [];
        $observedAt = $this->resolveBatchObservedAt($companies);
        $previousBatch = IngestionBatch::query()
            ->where('source', $source)
            ->where('status', 'processed')
            ->latest('id')
            ->first();
        $batch = IngestionBatch::query()->firstOrCreate(
            ['batch_key' => $batchKey],
            [
                'source' => $source,
                'worker_name' => $workerName,
                'previous_batch_id' => $previousBatch?->id,
                'status' => 'processing',
                'company_count' => count($companies),
                'observed_at' => $observedAt,
                'metadata' => [
                    'queued_at' => $decodedBatch['queued_at'] ?? null,
                ],
            ],
        );
        $previousComparisonKeys = $batch->previous_batch_id
            ? array_fill_keys(
                IngestionBatchItem::query()
                    ->where('ingestion_batch_id', $batch->previous_batch_id)
                    ->pluck('comparison_key')
                    ->all(),
                true,
            )
            : [];
        $previousTaxCodes = $batch->previous_batch_id
            ? IngestionBatchItem::query()
                ->where('ingestion_batch_id', $batch->previous_batch_id)
                ->orderBy('id')
                ->pluck('tax_code')
                ->all()
            : [];
        $currentTaxCodes = array_map(
            static fn (array $company): string => (string) ($company['tax_code'] ?? ''),
            $companies,
        );
        $hasReachedPreviousBatchItems = false;
        $results = [];
        $processedCompanyCount = 0;
        $newMarkedCount = 0;

        $this->operationsLog->info('Masothue batch payload received by service.', [
            'batch_key' => $batchKey,
            'source' => $source,
            'worker_name' => $workerName,
            'company_count' => count($companies),
            'previous_batch_id' => $batch->previous_batch_id,
            'current_tax_codes' => $currentTaxCodes,
            'previous_tax_codes' => $previousTaxCodes,
        ]);

        try {
            foreach ($companies as $company) {
                $comparisonKey = $this->buildComparisonKey($source, $company);
                $result = $this->ingest(
                    source: $source,
                    workerName: $workerName,
                    payload: $company,
                );
                $existsInPreviousBatch = isset($previousComparisonKeys[$comparisonKey]);

                if ($existsInPreviousBatch) {
                    $hasReachedPreviousBatchItems = true;
                }

                $isNewTaxCodeSincePreviousBatch = $batch->previous_batch_id === null
                    || (! $hasReachedPreviousBatchItems && ! $existsInPreviousBatch);
                $isNewSincePreviousBatch = $isNewTaxCodeSincePreviousBatch;

                $batchItem = IngestionBatchItem::query()->updateOrCreate(
                    [
                        'ingestion_batch_id' => $batch->id,
                        'comparison_key' => $comparisonKey,
                    ],
                    [
                        'company_lead_id' => $result['company_lead_id'],
                        'source' => $source,
                        'tax_code' => (string) ($company['tax_code'] ?? ''),
                        'company_name' => (string) ($company['company_name'] ?? ''),
                        'detail_url' => (string) ($company['detail_url'] ?? ''),
                        'phone' => isset($company['phone']) ? (string) $company['phone'] : null,
                        'phone_signature' => isset($company['phone_signature']) ? (string) $company['phone_signature'] : null,
                        'phone_numbers' => isset($company['phone_numbers']) && is_array($company['phone_numbers'])
                            ? array_values($company['phone_numbers'])
                            : [],
                        'active_date' => ! empty($company['active_date'])
                            ? CarbonImmutable::parse((string) $company['active_date'])->toDateString()
                            : null,
                        'is_new_since_previous_batch' => $isNewSincePreviousBatch,
                        'marked_at' => $isNewSincePreviousBatch ? now() : null,
                        'observed_at' => isset($company['observed_at'])
                            ? CarbonImmutable::parse((string) $company['observed_at'])
                            : $observedAt,
                        'payload' => $company,
                    ],
                );

                if ($isNewSincePreviousBatch) {
                    $newMarkedCount++;
                    SendTelegramMarkedItemAlert::dispatch($batchItem->id)
                        ->onConnection('redis')
                        ->onQueue('telegram');
                }

                $this->operationsLog->info('Masothue batch item comparison result.', [
                    'batch_key' => $batchKey,
                    'source' => $source,
                    'listing_position' => $company['listing_position'] ?? null,
                    'tax_code' => $company['tax_code'] ?? null,
                    'company_name' => $company['company_name'] ?? null,
                    'detail_url' => $company['detail_url'] ?? null,
                    'exists_in_previous_batch' => $existsInPreviousBatch,
                    'has_reached_previous_batch_items' => $hasReachedPreviousBatchItems,
                    'is_new_tax_code_since_previous_batch' => $isNewTaxCodeSincePreviousBatch,
                    'is_new_since_previous_batch' => $isNewSincePreviousBatch,
                ]);

                $processedCompanyCount++;
                $results[] = array_merge($result, [
                    'ingestion_batch_id' => $batch->id,
                    'ingestion_batch_item_id' => $batchItem->id,
                    'is_new_tax_code_since_previous_batch' => $isNewTaxCodeSincePreviousBatch,
                    'is_new_since_previous_batch' => $isNewSincePreviousBatch,
                ]);
            }

            $batch->update([
                'status' => 'processed',
                'processed_company_count' => $processedCompanyCount,
                'new_marked_count' => $newMarkedCount,
                'processed_at' => now(),
            ]);

            $this->operationsLog->info('Processed ingestion batch.', [
                'batch_key' => $batchKey,
                'source' => $source,
                'processed_company_count' => $processedCompanyCount,
                'new_marked_count' => $newMarkedCount,
                'previous_batch_id' => $batch->previous_batch_id,
            ]);
        } catch (\Throwable $exception) {
            $batch->update([
                'status' => 'failed',
                'processed_company_count' => $processedCompanyCount,
                'new_marked_count' => $newMarkedCount,
            ]);

            $this->operationsLog->error('Failed to process ingestion batch.', [
                'batch_key' => $batchKey,
                'source' => $source,
                'processed_company_count' => $processedCompanyCount,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        Redis::connection('default')->del($batchKey);

        return $results;
    }

    /**
     * Clear transient comparison data so the next crawl starts fresh.
     *
     * @return array<string, int|string|null>
     */
    public function clearComparisonData(string $source = 'masothue'): array
    {
        $summary = DB::transaction(function () use ($source): array {
            $batchIds = IngestionBatch::query()
                ->where('source', $source)
                ->pluck('id');

            $deliveryCount = TelegramDestinationDelivery::query()
                ->whereIn('ingestion_batch_item_id', IngestionBatchItem::query()
                    ->whereIn('ingestion_batch_id', $batchIds)
                    ->select('id'))
                ->delete();

            $batchItemCount = IngestionBatchItem::query()
                ->whereIn('ingestion_batch_id', $batchIds)
                ->delete();

            $eventCount = CompanyLeadEvent::query()
                ->where('source', $source)
                ->delete();

            $batchCount = IngestionBatch::query()
                ->where('source', $source)
                ->delete();

            $leadCount = CompanyLead::query()
                ->where('source', $source)
                ->delete();

            return [
                'source' => $source,
                'deliveries_deleted' => $deliveryCount,
                'batch_items_deleted' => $batchItemCount,
                'events_deleted' => $eventCount,
                'batches_deleted' => $batchCount,
                'leads_deleted' => $leadCount,
            ];
        });

        $redisSummary = $this->clearRedisBatchKeys($source, false);
        $summary['redis_batch_keys_deleted'] = $redisSummary['deleted'];

        $this->operationsLog->info('Cleared Masothue comparison data.', $summary);

        return $summary;
    }

    /**
     * Clear Redis batch keys immediately and optionally keep the latest current batch key.
     *
     * @return array{source: string, kept_batch_key: ?string, deleted: int, scanned: int}
     */
    public function clearRedisBatchKeys(string $source = 'masothue', bool $keepCurrentBatch = true): array
    {
        $currentBatchKey = null;
        $prefix = (string) config('database.redis.options.prefix', '');

        if ($keepCurrentBatch) {
            $currentBatchKey = IngestionBatch::query()
                ->where('source', $source)
                ->latest('id')
                ->value('batch_key');
        }

        $keys = Redis::connection('default')->keys(self::REDIS_BATCH_KEY_PREFIX.'*');
        $deleted = 0;

        foreach ($keys as $key) {
            $plainKey = $prefix !== '' && str_starts_with($key, $prefix)
                ? substr($key, strlen($prefix))
                : $key;

            if ($keepCurrentBatch && $currentBatchKey !== null && $plainKey === $currentBatchKey) {
                continue;
            }

            $deleted += (int) Redis::connection('default')->del($plainKey);
        }

        $summary = [
            'source' => $source,
            'kept_batch_key' => $keepCurrentBatch ? $currentBatchKey : null,
            'deleted' => $deleted,
            'scanned' => count($keys),
        ];

        $this->operationsLog->info('Cleared Redis batch keys.', $summary);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, bool|int>
     */
    public function ingest(string $source, ?string $workerName, array $payload): array
    {
        $observedAt = isset($payload['observed_at'])
            ? CarbonImmutable::parse((string) $payload['observed_at'])
            : CarbonImmutable::now();
        $activeDate = ! empty($payload['active_date'])
            ? CarbonImmutable::parse((string) $payload['active_date'])->toDateString()
            : null;
        $dedupeKey = sha1(implode('|', [
            $source,
            (string) ($payload['tax_code'] ?? ''),
        ]));

        return DB::transaction(function () use ($activeDate, $dedupeKey, $observedAt, $payload, $source, $workerName): array {
            $lead = CompanyLead::query()->firstOrNew([
                'source' => $source,
                'tax_code' => (string) $payload['tax_code'],
            ]);

            $isNewLead = ! $lead->exists;

            $lead->fill([
                'company_name' => $payload['company_name'],
                'detail_url' => $payload['detail_url'],
                'detail_path' => $payload['detail_path'] ?? null,
                'listed_address' => $payload['listed_address'] ?? null,
                'legal_representative' => $payload['legal_representative'] ?? null,
                'source_url' => $payload['source_url'] ?? null,
                'last_seen_at' => $observedAt,
                'worker_name' => $workerName,
                'raw_payload' => $payload['raw_payload'] ?? $payload,
            ]);

            foreach ([
                'tax_address',
                'registered_address',
                'international_name',
                'managed_by',
                'company_type',
                'main_business',
                'tax_status',
                'phone',
                'phone_signature',
            ] as $attribute) {
                if (array_key_exists($attribute, $payload)) {
                    $lead->{$attribute} = $payload[$attribute];
                }
            }

            if (array_key_exists('phone_numbers', $payload)) {
                $lead->phone_numbers = is_array($payload['phone_numbers'])
                    ? array_values($payload['phone_numbers'])
                    : null;
            }

            if ($activeDate !== null || array_key_exists('active_date', $payload)) {
                $lead->active_date = $activeDate;
            }

            if ($isNewLead) {
                $lead->first_seen_at = $observedAt;
            }

            $lead->save();

            $event = CompanyLeadEvent::query()->firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'company_lead_id' => $lead->id,
                    'source' => $source,
                    'phone' => isset($payload['phone']) ? (string) $payload['phone'] : null,
                    'observed_at' => $observedAt,
                    'payload' => $payload,
                ],
            );

            return [
                'is_new_lead' => $isNewLead,
                'is_new_event' => $event->wasRecentlyCreated,
                'phone_changed' => false,
                'company_lead_id' => $lead->id,
                'event_id' => $event->id,
            ];
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $companies
     */
    private function resolveBatchObservedAt(array $companies): CarbonImmutable
    {
        $firstObservedAt = $companies[0]['observed_at'] ?? null;

        return $firstObservedAt
            ? CarbonImmutable::parse((string) $firstObservedAt)
            : CarbonImmutable::now();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildComparisonKey(string $source, array $payload): string
    {
        return sha1(implode('|', [
            $source,
            (string) ($payload['tax_code'] ?? ''),
        ]));
    }
}
