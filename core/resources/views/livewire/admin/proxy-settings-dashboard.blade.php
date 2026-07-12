<div class="page-stack">
    <section class="hero-card">
        <div>
            <div class="eyebrow">Worker Proxy Control</div>
            <h1>Cấu hình proxy xoay cho collector</h1>
            <p class="hero-copy">
                Worker sẽ gọi core trước mỗi vòng crawl để lấy proxy mới. Core dùng token cấu hình ở đây để hỏi nhà cung cấp
                proxy xoay, sau đó trả lại proxy HTTP cho worker chạy qua.
            </p>
        </div>

        <div class="hero-aside">
            <div class="hero-stat">
                <span class="hero-stat-label">Nhà cung cấp</span>
                <strong>{{ $provider }}</strong>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-label">Trạng thái</span>
                <strong>{{ $isEnabled ? 'Đang bật' : 'Đang tắt' }}</strong>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-label">Phương thức</span>
                <strong>{{ $requestMethod }}</strong>
            </div>
        </div>
    </section>

    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
    @endif

    <div class="grid grid-wide">
        <section class="panel stack">
            <div>
                <h2>Thông tin nhà cung cấp</h2>
                <div class="muted">Theo tài liệu hiện tại, `proxyxoay.shop` hỗ trợ GET hoặc POST tới `api/get.php`.</div>
            </div>

            <div class="form-grid">
                <label class="checkbox-line">
                    <input type="checkbox" wire:model="isEnabled">
                    <span>Bật proxy xoay cho worker</span>
                </label>

                <label>
                    Nhà cung cấp
                    <input type="text" wire:model="provider" placeholder="proxyxoay.shop">
                    @error('provider') <span class="error">{{ $message }}</span> @enderror
                </label>

                <label>
                    API URL
                    <input type="text" wire:model="apiUrl" placeholder="https://proxyxoay.shop/api/get.php">
                    @error('apiUrl') <span class="error">{{ $message }}</span> @enderror
                </label>

                <label>
                    Request method
                    <select wire:model="requestMethod">
                        <option value="GET">GET</option>
                        <option value="POST">POST</option>
                    </select>
                    @error('requestMethod') <span class="error">{{ $message }}</span> @enderror
                </label>
            </div>

            <label>
                Token / API key
                <textarea wire:model="apiKey" rows="4" placeholder="Nhập key proxy xoay cấp bởi nhà cung cấp"></textarea>
                @error('apiKey') <span class="error">{{ $message }}</span> @enderror
            </label>

            <div class="form-grid">
                <label>
                    Nhà mạng
                    <input type="text" wire:model="carrier" placeholder="random">
                    <span class="field-help">Ví dụ: `random`, `viettel`, `fpt`, `vnpt`.</span>
                    @error('carrier') <span class="error">{{ $message }}</span> @enderror
                </label>

                <label>
                    Tỉnh / Thành
                    <input type="text" wire:model="provinceCode" placeholder="0">
                    <span class="field-help">Theo docs: `0` là random.</span>
                    @error('provinceCode') <span class="error">{{ $message }}</span> @enderror
                </label>
            </div>

            <label>
                Whitelist IPv4
                <input type="text" wire:model="whitelist" placeholder="1.2.3.4,5.6.7.8">
                <span class="field-help">Để trống nếu nhà cung cấp không yêu cầu.</span>
                @error('whitelist') <span class="error">{{ $message }}</span> @enderror
            </label>

            <label>
                Ghi chú vận hành
                <textarea wire:model="notes" rows="3" placeholder="Lưu lại mô tả, thông tin gói cước, thời gian xoay..."></textarea>
                @error('notes') <span class="error">{{ $message }}</span> @enderror
            </label>

            @error('proxyResolution')
                <div class="error">{{ $message }}</div>
            @enderror

            <div class="actions">
                <button class="btn-primary" type="button" wire:click="save">Lưu cấu hình</button>
                <button class="btn-secondary" type="button" wire:click="resolveNow">Test lấy proxy</button>
            </div>
        </section>

        <section class="panel stack">
            <div>
                <h2>Proxy gần nhất cấp cho worker</h2>
                <div class="muted">Dùng để kiểm tra worker đang được core trả về proxy gì trước mỗi vòng crawl.</div>
            </div>

            <div class="metric-grid">
                <div class="metric-card">
                    <span class="metric-label">HTTP Proxy</span>
                    <strong>{{ data_get($lastResolvedProxy, 'server') ?: 'Chưa có' }}</strong>
                </div>
                <div class="metric-card">
                    <span class="metric-label">SOCKS5</span>
                    <strong>{{ data_get($lastResolvedProxy, 'socks5_server') ?: 'Chưa có' }}</strong>
                </div>
                <div class="metric-card">
                    <span class="metric-label">Nhà mạng</span>
                    <strong>{{ data_get($lastResolvedProxy, 'network') ?: '-' }}</strong>
                </div>
                <div class="metric-card">
                    <span class="metric-label">Vị trí</span>
                    <strong>{{ data_get($lastResolvedProxy, 'location') ?: '-' }}</strong>
                </div>
                <div class="metric-card">
                    <span class="metric-label">TTL</span>
                    <strong>{{ data_get($lastResolvedProxy, 'expires_in_seconds') ? data_get($lastResolvedProxy, 'expires_in_seconds').'s' : '-' }}</strong>
                </div>
                <div class="metric-card">
                    <span class="metric-label">Lấy lúc</span>
                    <strong>{{ $settings->last_resolved_at?->format('d/m/Y H:i:s') ?? '-' }}</strong>
                </div>
            </div>

            <div class="stack stack-tight">
                <div class="info-row">
                    <span class="info-label">Lỗi gần nhất</span>
                    <code>{{ $settings->last_error_message ?: 'Không có' }}</code>
                </div>
                <div class="info-row">
                    <span class="info-label">Worker API Route</span>
                    <code>{{ url('/api/worker/proxy') }}</code>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <tbody>
                        <tr>
                            <th>Scope</th>
                            <td>{{ $settings->scope }}</td>
                        </tr>
                        <tr>
                            <th>Provider response</th>
                            <td>
                                <pre class="payload-preview">{{ json_encode($settings->last_provider_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'Chưa có phản hồi nào được lưu.' }}</pre>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
