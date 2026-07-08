<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source', 100);
            $table->string('batch_key')->unique();
            $table->string('worker_name')->nullable();
            $table->foreignId('previous_batch_id')->nullable()->constrained('ingestion_batches')->nullOnDelete();
            $table->string('status', 20)->default('processing');
            $table->unsignedInteger('company_count')->default(0);
            $table->unsignedInteger('processed_company_count')->default(0);
            $table->unsignedInteger('new_marked_count')->default(0);
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source', 'status', 'id']);
        });

        Schema::create('ingestion_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingestion_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 100);
            $table->string('comparison_key', 64);
            $table->string('tax_code', 50);
            $table->string('company_name');
            $table->string('detail_url', 2048);
            $table->string('phone', 50)->nullable();
            $table->date('active_date')->nullable();
            $table->boolean('is_new_since_previous_batch')->default(false);
            $table->timestamp('marked_at')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['ingestion_batch_id', 'comparison_key']);
            $table->index(['ingestion_batch_id', 'is_new_since_previous_batch']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_batch_items');
        Schema::dropIfExists('ingestion_batches');
    }
};
