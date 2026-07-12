<?php

namespace App\Livewire\Admin;

use App\Services\ProxyRotationService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Cấu hình Proxy')]
class ProxySettingsDashboard extends Component
{
    public bool $isEnabled = false;

    public string $provider = 'proxyxoay.shop';

    public string $apiUrl = 'https://proxyxoay.shop/api/get.php';

    public string $requestMethod = 'GET';

    public string $apiKey = '';

    public string $carrier = 'random';

    public string $provinceCode = '0';

    public string $whitelist = '';

    public string $notes = '';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $lastResolvedProxy = null;

    public function mount(ProxyRotationService $proxyRotationService): void
    {
        $this->fillFromSettings($proxyRotationService);
    }

    public function save(ProxyRotationService $proxyRotationService): void
    {
        $validated = $this->validate([
            'isEnabled' => ['boolean'],
            'provider' => ['required', 'string', 'max:100'],
            'apiUrl' => ['required', 'url', 'max:2048'],
            'requestMethod' => ['required', 'string', 'in:GET,POST'],
            'apiKey' => ['nullable', 'string', 'max:2000'],
            'carrier' => ['required', 'string', 'max:50'],
            'provinceCode' => ['required', 'string', 'max:20'],
            'whitelist' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $proxyRotationService->updateSettings([
            'is_enabled' => $validated['isEnabled'],
            'provider' => $validated['provider'],
            'api_url' => $validated['apiUrl'],
            'request_method' => $validated['requestMethod'],
            'api_key' => $validated['apiKey'],
            'carrier' => $validated['carrier'],
            'province_code' => $validated['provinceCode'],
            'whitelist' => $validated['whitelist'],
            'notes' => $validated['notes'],
        ]);

        $this->fillFromSettings($proxyRotationService);
        session()->flash('status', 'Đã lưu cấu hình proxy xoay.');
    }

    public function resolveNow(ProxyRotationService $proxyRotationService): void
    {
        $this->resetErrorBag();

        try {
            $this->lastResolvedProxy = $proxyRotationService->refreshWorkerProxy();
            session()->flash('status', data_get($this->lastResolvedProxy, 'refresh_skipped')
                ? 'Nhà cung cấp chưa cho đổi proxy mới, đang giữ proxy hiện tại.'
                : 'Đã lấy proxy mới từ nhà cung cấp.');
        } catch (\Throwable $exception) {
            $this->addError('proxyResolution', $exception->getMessage());
        } finally {
            $this->fillFromSettings($proxyRotationService);
        }
    }

    public function render()
    {
        $settings = app(ProxyRotationService::class)->getSettings();

        return view('livewire.admin.proxy-settings-dashboard', [
            'settings' => $settings,
        ]);
    }

    private function fillFromSettings(ProxyRotationService $proxyRotationService): void
    {
        $settings = $proxyRotationService->getSettings();

        $this->isEnabled = $settings->is_enabled;
        $this->provider = (string) $settings->provider;
        $this->apiUrl = (string) $settings->api_url;
        $this->requestMethod = strtoupper((string) $settings->request_method);
        $this->apiKey = (string) ($settings->api_key ?? '');
        $this->carrier = (string) $settings->carrier;
        $this->provinceCode = (string) $settings->province_code;
        $this->whitelist = (string) ($settings->whitelist ?? '');
        $this->notes = (string) ($settings->notes ?? '');
        $this->lastResolvedProxy = $settings->last_proxy_http
            ? [
                'server' => $settings->last_proxy_http,
                'socks5_server' => $settings->last_proxy_socks5,
                'network' => $settings->last_network,
                'location' => $settings->last_location,
                'expires_in_seconds' => $settings->last_expires_in_seconds,
                'resolved_at' => $settings->last_resolved_at?->toIso8601String(),
            ]
            : null;
    }
}
