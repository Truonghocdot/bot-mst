<?php

use App\Models\ProxySetting;
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

test('worker proxy endpoint resolves a rotating proxy from provider response', function () {
    config()->set('services.worker.token', 'worker-secret');

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

    $settings = ProxySetting::query()->where('scope', 'worker')->first();

    expect($settings)->not->toBeNull();
    expect($settings->last_proxy_http)->toBe('http://42.117.243.215:10836');
    expect($settings->last_error_message)->toBeNull();
});
