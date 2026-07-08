<?php

namespace App\Livewire\Admin;

use App\Models\IngestionBatchItem;
use App\Models\TelegramDestination;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('Quản lý Telegram')]
class TelegramAdminDashboard extends Component
{
    public ?int $editingId = null;

    public string $label = '';

    public string $chatId = '';

    public string $notes = '';

    public bool $isActive = true;

    public function saveDestination(): void
    {
        $validated = $this->validate([
            'label' => ['required', 'string', 'max:255'],
            'chatId' => [
                'required',
                'string',
                'max:255',
                Rule::unique('telegram_destinations', 'chat_id')->ignore($this->editingId),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'isActive' => ['boolean'],
        ]);

        $attributes = [
            'label' => $validated['label'],
            'chat_id' => $validated['chatId'],
            'notes' => $validated['notes'],
            'is_active' => $validated['isActive'],
        ];

        if (! $this->editingId) {
            $attributes['source'] = 'manual';
        }

        TelegramDestination::query()->updateOrCreate(
            ['id' => $this->editingId],
            $attributes,
        );

        session()->flash('status', $this->editingId
            ? 'Da cap nhat chat id Telegram.'
            : 'Da them chat id Telegram.');

        $this->resetForm();
    }

    public function editDestination(int $destinationId): void
    {
        $destination = TelegramDestination::query()->findOrFail($destinationId);

        $this->editingId = $destination->id;
        $this->label = $destination->label;
        $this->chatId = $destination->chat_id;
        $this->notes = $destination->notes ?? '';
        $this->isActive = $destination->is_active;
    }

    public function toggleDestination(int $destinationId): void
    {
        $destination = TelegramDestination::query()->findOrFail($destinationId);

        $destination->update([
            'is_active' => ! $destination->is_active,
        ]);

        session()->flash('status', 'Da cap nhat trang thai chat id.');
    }

    public function deleteDestination(int $destinationId): void
    {
        TelegramDestination::query()->findOrFail($destinationId)->delete();

        if ($this->editingId === $destinationId) {
            $this->resetForm();
        }

        session()->flash('status', 'Da xoa chat id Telegram.');
    }

    public function cancelEditing(): void
    {
        $this->resetForm();
    }

    public function render()
    {
        return view('livewire.admin.telegram-admin-dashboard', [
            'destinations' => TelegramDestination::query()
                ->orderByDesc('is_active')
                ->orderByDesc('last_seen_at')
                ->orderBy('label')
                ->get(),
            'markedItems' => IngestionBatchItem::query()
                ->where('is_new_since_previous_batch', true)
                ->latest('marked_at')
                ->limit(50)
                ->get(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'label', 'chatId', 'notes']);
        $this->isActive = true;
        $this->resetErrorBag();
        $this->resetValidation();
    }
}
