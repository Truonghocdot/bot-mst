<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_destinations', function (Blueprint $table) {
            $table->string('source', 50)->default('manual')->after('chat_id');
            $table->string('telegram_chat_type', 50)->nullable()->after('source');
            $table->timestamp('last_seen_at')->nullable()->after('last_sent_at');
            $table->json('raw_update')->nullable()->after('last_error_message');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_destinations', function (Blueprint $table) {
            $table->dropColumn([
                'source',
                'telegram_chat_type',
                'last_seen_at',
                'raw_update',
            ]);
        });
    }
};
