<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_leads', function (Blueprint $table) {
            $table->string('phone_signature')->nullable()->after('phone');
            $table->json('phone_numbers')->nullable()->after('phone_signature');
        });

        Schema::table('ingestion_batch_items', function (Blueprint $table) {
            $table->string('phone_signature')->nullable()->after('phone');
            $table->json('phone_numbers')->nullable()->after('phone_signature');
        });

        Schema::create('telegram_destination_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingestion_batch_item_id')->constrained('ingestion_batch_items')->cascadeOnDelete();
            $table->foreignId('telegram_destination_id')->constrained('telegram_destinations')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('telegram_message_id')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('last_error_message', 1000)->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['ingestion_batch_item_id', 'telegram_destination_id'], 'telegram_delivery_unique_pair');
            $table->index(['telegram_destination_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_destination_deliveries');

        Schema::table('ingestion_batch_items', function (Blueprint $table) {
            $table->dropColumn(['phone_signature', 'phone_numbers']);
        });

        Schema::table('company_leads', function (Blueprint $table) {
            $table->dropColumn(['phone_signature', 'phone_numbers']);
        });
    }
};
