<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('chat_id')->unique();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 1000)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_error_message', 1000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_destinations');
    }
};
