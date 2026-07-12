<?php

use App\Models\ProxySetting;
use App\Services\ProxyRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('worker proxy endpoint returns disabled when rotating proxy is off', function () {
    config()->set('services.worker.token', 'worker-secret');

    ProxySetting::query()->create([
        'scope' => 'worker',
        'is_enabled' => false,
        'provider' => 'proxyxoay.shop',
        'api_url' => 'https://proxyxoay.shop/api/get.php',
        'request_method' => 'GET',
        'carrier' => 'random',
        'province_code' => '0',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer worker-secret')
        ->getJson('/api/worker/proxy');

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'enabled' => false,
            'proxy' => [
                'enabled' => false,
                'provider' => 'proxyxoay.shop',
            ],
        ]);
});

test('worker proxy endpoint returns the current stored proxy', function () {
    config()->set('services.worker.token', 'worker-secret');

    ProxySetting::query()->create([
        'scope' => 'worker',
        'is_enabled' => true,
        'provider' => 'proxyxoay.shop',
        'last_proxy_http' => 'http://42.117.243.215:10836',
        'last_proxy_socks5' => 'http://42.117.243.215:30836',
        'last_network' => 'fpt',
        'last_location' => 'HaNoi1',
        'last_expires_in_seconds' => 1777,
        'last_resolved_at' => now(),
    ]);

    $response = $this->withHeader('Authorization', 'Bearer worker-secret')
        ->getJson('/api/worker/proxy');

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'enabled' => true,
            'proxy' => [
                'enabled' => true,
                'provider' => 'proxyxoay.shop',
                'server' => 'http://42.117.243.215:10836',
                'socks5_server' => 'http://42.117.243.215:30836',
                'network' => 'fpt',
                'location' => 'HaNoi1',
                'expires_in_seconds' => 1777,
            ],
        ]);
});

test('refresh worker proxy stores a new proxy from provider response', function () {
    ProxySetting::query()->create([
        'scope' => 'worker',
        'is_enabled' => true,
        'provider' => 'proxyxoay.shop',
        'api_url' => 'https://proxyxoay.shop/api/get.php',
        'request_method' => 'GET',
        'api_key' => 'proxy-key',
        'carrier' => 'random',
        'province_code' => '0',
        'whitelist' => '1.2.3.4',
    ]);

    Http::fake([
        'https://proxyxoay.shop/api/get.php*' => Http::response([
            'status' => 100,
            'message' => 'proxy nay se die sau 1777s',
            'proxyhttp' => '42.117.243.215:10836::',
            'proxysocks5' => '42.117.243.215:30836::',
            'Nha Mang' => 'fpt',
            'Vi Tri' => 'HaNoi1',
        ], 200),
    ]);

    $resolved = app(ProxyRotationService::class)->refreshWorkerProxy();

    expect($resolved['server'])->toBe('http://42.117.243.215:10836');
    expect($resolved['refresh_skipped'])->toBeFalse();

    $settings = ProxySetting::query()->where('scope', 'worker')->first();

    expect($settings)->not->toBeNull();
    expect($settings->last_proxy_http)->toBe('http://42.117.243.215:10836');
    expect($settings->last_error_message)->toBeNull();
});

test('refresh worker proxy keeps the current proxy when provider reports cooldown', function () {
    ProxySetting::query()->create([
        'scope' => 'worker',
        'is_enabled' => true,
        'provider' => 'proxyxoay.shop',
        'api_url' => 'https://proxyxoay.shop/api/get.php',
        'request_method' => 'GET',
        'api_key' => 'proxy-key',
        'carrier' => 'random',
        'province_code' => '0',
        'last_proxy_http' => 'http://42.117.243.215:10836',
        'last_proxy_socks5' => 'http://42.117.243.215:30836',
        'last_network' => 'fpt',
        'last_location' => 'HaNoi1',
        'last_expires_in_seconds' => 1777,
        'last_resolved_at' => now(),
    ]);

    Http::fake([
        'https://proxyxoay.shop/api/get.php*' => Http::response([
            'status' => 101,
            'message' => 'Con 48s moi co the doi proxy',
        ], 200),
    ]);

    $resolved = app(ProxyRotationService::class)->refreshWorkerProxy();

    expect($resolved['server'])->toBe('http://42.117.243.215:10836');
    expect($resolved['refresh_skipped'])->toBeTrue();
    expect($resolved['provider_message'])->toBe('Con 48s moi co the doi proxy');
    Http::assertSentCount(1);
});
