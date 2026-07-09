<div>
    <div class="topbar">
        <div>
            <div class="brand">Bảng điều khiển Telegram</div>
            <div class="muted">Quản lý chat ID nhận cảnh báo và xem danh sách dữ liệu mới được đánh dấu.</div>
        </div>

        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <a href="{{ url('/admin/logs') }}" class="btn-secondary" style="text-decoration:none;">Xem log</a>
            <button class="btn-secondary" type="submit">Đăng xuất</button>
        </form>
    </div>

    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
    @endif

    <div class="grid">
        <section class="panel stack">
            <div>
                <h2>{{ $editingId ? 'Cập nhật chat ID' : 'Thêm chat ID mới' }}</h2>
                <div class="muted">Nhập chat ID group hoặc channel mà bot được phép gửi tới.</div>
            </div>

            <label>
                Nhãn
                <input type="text" wire:model="label" placeholder="Ví dụ: Group Cảnh Báo Chính">
                @error('label') <span class="error">{{ $message }}</span> @enderror
            </label>

            <label>
                Chat ID
                <input type="text" wire:model="chatId" placeholder="Ví dụ: -1001234567890">
                @error('chatId') <span class="error">{{ $message }}</span> @enderror
            </label>

            <label>
                Ghi chú
                <textarea wire:model="notes" placeholder="Mô tả ngắn, link group, vai trò..."></textarea>
                @error('notes') <span class="error">{{ $message }}</span> @enderror
            </label>

            <label style="grid-template-columns: 20px 1fr; align-items: center; gap: 10px;">
                <input type="checkbox" wire:model="isActive">
                <span>Kích hoạt gửi đến chat ID này</span>
            </label>

            <div class="actions">
                <button class="btn-primary" type="button" wire:click="saveDestination">
                    {{ $editingId ? 'Lưu thay đổi' : 'Thêm chat ID' }}
                </button>

                @if ($editingId)
                    <button class="btn-secondary" type="button" wire:click="cancelEditing">Hủy sửa</button>
                @endif
            </div>
        </section>

        <section class="panel stack">
            <div>
                <h2>Danh sách chat ID</h2>
                <div class="muted">Các group hoặc channel khi bot được thêm vào sẽ tự xuất hiện ở đây dưới dạng chờ kích hoạt.</div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Nhãn</th>
                            <th>Chat ID</th>
                            <th>Nguồn</th>
                            <th>Loại chat</th>
                            <th>Trạng thái</th>
                            <th>Thấy gần nhất</th>
                            <th>Lần gửi gần nhất</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($destinations as $destination)
                            <tr wire:key="destination-{{ $destination->id }}">
                                <td>
                                    <strong>{{ $destination->label }}</strong>
                                    @if ($destination->notes)
                                        <div class="muted">{{ $destination->notes }}</div>
                                    @endif
                                </td>
                                <td><code>{{ $destination->chat_id }}</code></td>
                                <td>{{ $destination->source === 'telegram_webhook' ? 'Tự phát hiện' : 'Thủ công' }}</td>
                                <td>{{ $destination->telegram_chat_type ?: '-' }}</td>
                                <td>
                                    <span class="pill {{ $destination->is_active ? 'active' : 'inactive' }}">
                                        {{ $destination->is_active ? 'Đang bật' : 'Đang tắt' }}
                                    </span>
                                </td>
                                <td>{{ $destination->last_seen_at?->format('d/m/Y H:i') ?? '-' }}</td>
                                <td>{{ $destination->last_sent_at?->format('d/m/Y H:i') ?? 'Chưa gửi' }}</td>
                                <td>
                                    <div class="inline-actions">
                                        <button class="btn-secondary" type="button" wire:click="editDestination({{ $destination->id }})">Sửa</button>
                                        <button class="btn-warning" type="button" wire:click="toggleDestination({{ $destination->id }})">
                                            {{ $destination->is_active ? 'Tắt' : 'Bật' }}
                                        </button>
                                        <button class="btn-danger" type="button" wire:click="deleteDestination({{ $destination->id }})">Xóa</button>
                                    </div>
                                    @if ($destination->last_error_message)
                                        <div class="error">{{ $destination->last_error_message }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="muted">Chưa có chat ID nào được khai báo hoặc tự phát hiện.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="panel stack" style="margin-top: 20px;">
        <div>
            <h2>Dữ liệu mới được đánh dấu</h2>
            <div class="muted">So sánh với batch ngay trước đó. Những item ở đây là các dữ liệu mới sẽ được đẩy qua Telegram tới các chat ID đang bật.</div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Đánh dấu lúc</th>
                        <th>MST</th>
                        <th>Tên doanh nghiệp</th>
                        <th>Người đại diện</th>
                        <th>Chi tiết</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($markedItems as $item)
                        <tr wire:key="marked-item-{{ $item->id }}">
                            <td>{{ $item->marked_at?->format('d/m/Y H:i:s') ?? '-' }}</td>
                            <td><code>{{ $item->tax_code }}</code></td>
                            <td>
                                <strong>{{ $item->company_name }}</strong>
                                <div class="muted">Batch #{{ $item->ingestion_batch_id }}</div>
                                <div class="muted">{{ data_get($item->payload, 'listed_address') ?: '-' }}</div>
                            </td>
                            <td>{{ data_get($item->payload, 'legal_representative') ?: '-' }}</td>
                            <td>
                                <a class="link-cut" href="{{ $item->detail_url }}" target="_blank" rel="noreferrer">
                                    {{ $item->detail_url }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="muted">Chưa có item mới nào được đánh dấu.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
