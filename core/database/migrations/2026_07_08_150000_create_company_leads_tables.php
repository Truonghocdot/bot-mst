<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_leads', function (Blueprint $table) {
            $table->id();
            $table->string('source', 100);
            $table->string('tax_code', 50);
            $table->string('company_name');
            $table->string('detail_url', 2048);
            $table->string('detail_path')->nullable();
            $table->string('listed_address', 1000)->nullable();
            $table->string('tax_address', 1000)->nullable();
            $table->string('registered_address', 1000)->nullable();
            $table->string('legal_representative')->nullable();
            $table->string('international_name')->nullable();
            $table->string('managed_by')->nullable();
            $table->string('company_type')->nullable();
            $table->text('main_business')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('tax_status')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->date('active_date')->nullable();
            $table->string('worker_name')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('phone_changed_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['source', 'tax_code']);
            $table->index(['source', 'last_seen_at']);
        });

        Schema::create('company_lead_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_lead_id')->constrained()->cascadeOnDelete();
            $table->string('source', 100);
            $table->string('dedupe_key', 64)->unique();
            $table->string('phone', 50)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['company_lead_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_lead_events');
        Schema::dropIfExists('company_leads');
    }
};
