<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMasothueBatch;
use App\Services\MasothueIngestionService;
use App\Services\OperationsLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MasothueIngestionController extends Controller
{
    public function store(Request $request, MasothueIngestionService $ingestionService, OperationsLogService $operationsLog): JsonResponse
    {
        $expectedToken = (string) config('services.worker.token');
        $providedToken = (string) $request->bearerToken();

        if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'message' => 'Unauthorized worker token.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $validated = $request->validate([
            'source' => ['required', 'string', 'max:100'],
            'worker_name' => ['nullable', 'string', 'max:100'],
            'companies' => ['required', 'array', 'min:1'],
            'companies.*.company_name' => ['required', 'string', 'max:255'],
            'companies.*.tax_code' => ['required', 'string', 'max:50'],
            'companies.*.detail_url' => ['required', 'url', 'max:2048'],
            'companies.*.detail_path' => ['nullable', 'string', 'max:255'],
            'companies.*.listed_address' => ['nullable', 'string', 'max:1000'],
            'companies.*.tax_address' => ['nullable', 'string', 'max:1000'],
            'companies.*.registered_address' => ['nullable', 'string', 'max:1000'],
            'companies.*.legal_representative' => ['nullable', 'string', 'max:255'],
            'companies.*.international_name' => ['nullable', 'string', 'max:255'],
            'companies.*.managed_by' => ['nullable', 'string', 'max:255'],
            'companies.*.company_type' => ['nullable', 'string', 'max:255'],
            'companies.*.main_business' => ['nullable', 'string'],
            'companies.*.phone' => ['nullable', 'string', 'max:50'],
            'companies.*.phone_raw' => ['nullable', 'string', 'max:255'],
            'companies.*.phone_signature' => ['nullable', 'string', 'max:255'],
            'companies.*.phone_list' => ['nullable', 'array'],
            'companies.*.phone_list.*' => ['nullable', 'string', 'max:50'],
            'companies.*.tax_status' => ['nullable', 'string', 'max:255'],
            'companies.*.source_url' => ['nullable', 'url', 'max:2048'],
            'companies.*.active_date' => ['nullable', 'date'],
            'companies.*.observed_at' => ['nullable', 'date'],
            'companies.*.listing_position' => ['nullable', 'integer', 'min:1'],
            'companies.*.raw_payload' => ['nullable', 'array'],
        ]);

        $queuedBatch = $ingestionService->queueBatch(
            source: $validated['source'],
            workerName: $validated['worker_name'] ?? null,
            companies: $validated['companies'],
        );

        $operationsLog->info('Accepted worker ingestion batch.', [
            'source' => $validated['source'],
            'worker_name' => $validated['worker_name'] ?? null,
            'queued' => $queuedBatch['queued'],
            'batch_key' => $queuedBatch['batch_key'],
        ]);

        ProcessMasothueBatch::dispatch(
            batchKey: $queuedBatch['batch_key'],
        )->onConnection('redis')->onQueue('ingest');

        return response()->json([
            'message' => 'Batch accepted.',
            'queued' => $queuedBatch['queued'],
            'batch_key' => $queuedBatch['batch_key'],
            'source' => $validated['source'],
        ], Response::HTTP_ACCEPTED);
    }
}
