<?php

use App\Services\MasothueIngestionService;
use App\Services\ProxyRotationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('masothue:clear-comparison-data {source=masothue}', function (string $source) {
    $summary = app(MasothueIngestionService::class)->clearComparisonData($source);

    $this->info('Cleared comparison data successfully.');
    $this->table(
        ['source', 'deliveries_deleted', 'batch_items_deleted', 'events_deleted', 'batches_deleted', 'leads_deleted', 'redis_batch_keys_deleted'],
        [[
            $summary['source'],
            $summary['deliveries_deleted'],
            $summary['batch_items_deleted'],
            $summary['events_deleted'],
            $summary['batches_deleted'],
            $summary['leads_deleted'],
            $summary['redis_batch_keys_deleted'],
        ]]
    );
})->purpose('Clear Masothue comparison data so the next crawl starts a fresh day');

Artisan::command('masothue:clear-redis-batch-keys {source=masothue} {--drop-current : Also delete the latest current batch key}', function (string $source) {
    $summary = app(MasothueIngestionService::class)->clearRedisBatchKeys(
        $source,
        ! $this->option('drop-current'),
    );

    $this->info('Cleared Redis batch keys successfully.');
    $this->table(
        ['source', 'kept_batch_key', 'deleted', 'scanned'],
        [[
            $summary['source'],
            $summary['kept_batch_key'] ?? '-',
            $summary['deleted'],
            $summary['scanned'],
        ]]
    );
})->purpose('Clear Redis batch keys immediately and keep only the latest current batch key by default');

Artisan::command('worker:refresh-proxy', function () {
    $proxy = app(ProxyRotationService::class)->refreshWorkerProxy();

    $this->info(data_get($proxy, 'refresh_skipped')
        ? 'Provider did not rotate yet, current proxy is kept.'
        : 'Resolved proxy successfully.');

    $this->table(
        ['enabled', 'server', 'network', 'location', 'expires_in_seconds', 'provider_message', 'refresh_skipped'],
        [[
            $proxy['enabled'] ?? false ? 'yes' : 'no',
            $proxy['server'] ?? '-',
            $proxy['network'] ?? '-',
            $proxy['location'] ?? '-',
            $proxy['expires_in_seconds'] ?? '-',
            $proxy['provider_message'] ?? '-',
            data_get($proxy, 'refresh_skipped') ? 'yes' : 'no',
        ]]
    );
})->purpose('Refresh the rotating worker proxy from the provider and keep the current proxy if cooldown is active');

if ((bool) env('MASOTHUE_CLEAR_DATA_ENABLED', false)) {
    Schedule::command('masothue:clear-comparison-data')
        ->dailyAt((string) env('MASOTHUE_CLEAR_DATA_AT', '23:59'))
        ->timezone(config('app.timezone'))
        ->withoutOverlapping();
}

if ((bool) env('MASOTHUE_CLEAR_REDIS_KEYS_ENABLED', true)) {
    Schedule::command('masothue:clear-redis-batch-keys')
        ->cron((string) env('MASOTHUE_CLEAR_REDIS_KEYS_CRON', '0 */2 * * *'))
        ->timezone(config('app.timezone'))
        ->withoutOverlapping();
}

if ((bool) env('WORKER_PROXY_REFRESH_ENABLED', true)) {
    Schedule::command('worker:refresh-proxy')
        ->cron((string) env('WORKER_PROXY_REFRESH_CRON', '* * * * *'))
        ->timezone(config('app.timezone'))
        ->withoutOverlapping();
}
