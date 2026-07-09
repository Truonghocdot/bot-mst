<?php

namespace App\Services;

use App\Jobs\SendTelegramMarkedItemAlert;
use App\Models\CompanyLead;
use App\Models\CompanyLeadEvent;
use App\Models\IngestionBatch;
use App\Models\IngestionBatchItem;
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
        $results = [];
        $processedCompanyCount = 0;
        $newMarkedCount = 0;

        try {
            foreach ($companies as $company) {
                $comparisonKey = $this->buildComparisonKey($source, $company);
                $phoneData = $this->normalizePhonePayload($company);
                $result = $this->ingest(
                    source: $source,
                    workerName: $workerName,
                    payload: $company,
                );
                $hasPrimaryPhone = $phoneData['phone'] !== null;
                $isNewTaxCodeSincePreviousBatch = $batch->previous_batch_id !== null
                    && ! isset($previousComparisonKeys[$comparisonKey]);
                $isNewSincePreviousBatch = $isNewTaxCodeSincePreviousBatch && $hasPrimaryPhone;

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
                        'phone' => $phoneData['phone'],
                        'phone_signature' => $phoneData['phone_signature'],
                        'phone_numbers' => $phoneData['phone_numbers'],
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
                } elseif ($isNewTaxCodeSincePreviousBatch && ! $hasPrimaryPhone) {
                    $this->operationsLog->warning('Skipped new tax code because the company has no primary phone.', [
                        'batch_key' => $batchKey,
                        'source' => $source,
                        'tax_code' => $company['tax_code'] ?? null,
                        'company_name' => $company['company_name'] ?? null,
                    ]);
                }

                $processedCompanyCount++;
                $results[] = array_merge($result, [
                    'ingestion_batch_id' => $batch->id,
                    'ingestion_batch_item_id' => $batchItem->id,
                    'is_new_tax_code_since_previous_batch' => $isNewTaxCodeSincePreviousBatch,
                    'has_primary_phone' => $hasPrimaryPhone,
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
     * @param  array<string, mixed>  $payload
     * @return array<string, bool|int>
     */
    public function ingest(string $source, ?string $workerName, array $payload): array
    {
        $phoneData = $this->normalizePhonePayload($payload);
        $normalizedPhone = $phoneData['phone'];
        $phoneNumbers = $phoneData['phone_numbers'];
        $observedAt = isset($payload['observed_at'])
            ? CarbonImmutable::parse((string) $payload['observed_at'])
            : CarbonImmutable::now();
        $activeDate = ! empty($payload['active_date'])
            ? CarbonImmutable::parse((string) $payload['active_date'])->toDateString()
            : null;
        $dedupeKey = sha1(implode('|', [
            $source,
            (string) ($payload['tax_code'] ?? ''),
            $normalizedPhone ?? '',
            (string) ($payload['detail_url'] ?? ''),
            $activeDate ?? '',
        ]));

        return DB::transaction(function () use ($activeDate, $dedupeKey, $normalizedPhone, $phoneNumbers, $observedAt, $payload, $source, $workerName): array {
            $lead = CompanyLead::query()->firstOrNew([
                'source' => $source,
                'tax_code' => (string) $payload['tax_code'],
            ]);

            $isNewLead = ! $lead->exists;
            $previousPhone = $lead->phone;

            $lead->fill([
                'company_name' => $payload['company_name'],
                'detail_url' => $payload['detail_url'],
                'detail_path' => $payload['detail_path'] ?? null,
                'listed_address' => $payload['listed_address'] ?? null,
                'tax_address' => $payload['tax_address'] ?? null,
                'registered_address' => $payload['registered_address'] ?? null,
                'legal_representative' => $payload['legal_representative'] ?? null,
                'international_name' => $payload['international_name'] ?? null,
                'managed_by' => $payload['managed_by'] ?? null,
                'company_type' => $payload['company_type'] ?? null,
                'main_business' => $payload['main_business'] ?? null,
                'phone' => $normalizedPhone,
                'phone_signature' => $normalizedPhone,
                'phone_numbers' => $phoneNumbers,
                'tax_status' => $payload['tax_status'] ?? null,
                'source_url' => $payload['source_url'] ?? null,
                'active_date' => $activeDate,
                'last_seen_at' => $observedAt,
                'worker_name' => $workerName,
                'raw_payload' => $payload['raw_payload'] ?? $payload,
            ]);

            if ($isNewLead) {
                $lead->first_seen_at = $observedAt;
            }

            $phoneChanged = $lead->exists
                && ($previousPhone ?? '') !== ($normalizedPhone ?? '');

            if ($phoneChanged) {
                $lead->phone_changed_at = $observedAt;
            }

            $lead->save();

            $event = CompanyLeadEvent::query()->firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'company_lead_id' => $lead->id,
                    'source' => $source,
                    'phone' => $normalizedPhone,
                    'observed_at' => $observedAt,
                    'payload' => $payload,
                ],
            );

            return [
                'is_new_lead' => $isNewLead,
                'is_new_event' => $event->wasRecentlyCreated,
                'phone_changed' => $phoneChanged,
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array{phone: ?string, phone_signature: ?string, phone_numbers: array<int, string>}
     */
    private function normalizePhonePayload(array $payload): array
    {
        $phoneNumbers = [];

        if (isset($payload['phone_list']) && is_array($payload['phone_list'])) {
            $phoneNumbers = array_values(array_filter(array_map(
                fn (mixed $phone): ?string => $this->normalizePhone($phone),
                $payload['phone_list'],
            )));
        }

        if ($phoneNumbers === []) {
            $phoneNumbers = $this->extractPhoneCandidates($payload['phone_raw'] ?? $payload['phone'] ?? null);
        }

        $phoneNumbers = array_values(array_unique(array_filter($phoneNumbers)));

        return [
            'phone' => $phoneNumbers[0] ?? null,
            'phone_signature' => $phoneNumbers[0] ?? null,
            'phone_numbers' => $phoneNumbers,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractPhoneCandidates(mixed $value): array
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return [];
        }

        $segments = preg_split('/[|\/;,]+/u', $raw) ?: [];
        $candidates = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            $compactPhone = $this->normalizePhone($segment);

            if ($compactPhone !== null && strlen($compactPhone) >= 8 && strlen($compactPhone) <= 11) {
                $candidates[] = $compactPhone;
                continue;
            }

            $tokens = preg_split('/\s+/u', $segment) ?: [];
            $current = '';

            foreach ($tokens as $token) {
                $digits = preg_replace('/\D+/', '', (string) $token) ?: '';

                if ($digits === '') {
                    continue;
                }

                $next = $current.$digits;

                if ($current === '') {
                    $current = $digits;
                    continue;
                }

                if (strlen($next) <= 11) {
                    $current = $next;
                    continue;
                }

                if (strlen($current) >= 8) {
                    $candidates[] = $current;
                }

                $current = $digits;
            }

            if (strlen($current) >= 8) {
                $candidates[] = $current;
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $candidate): ?string => $this->normalizePhone($candidate),
            $candidates,
        ))));
    }

    private function normalizePhone(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0084') && strlen($digits) >= 10) {
            return '0'.substr($digits, 4);
        }

        if (str_starts_with($digits, '84') && ! str_starts_with($digits, '840') && strlen($digits) >= 10) {
            return '0'.substr($digits, 2);
        }

        return $digits;
    }
}
