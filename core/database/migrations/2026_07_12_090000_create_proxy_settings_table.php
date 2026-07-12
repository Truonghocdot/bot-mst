<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 50)->unique()->default('worker');
            $table->boolean('is_enabled')->default(false);
            $table->string('provider', 100)->default('proxyxoay.shop');
            $table->string('api_url', 2048)->default('https://proxyxoay.shop/api/get.php');
            $table->string('request_method', 10)->default('GET');
            $table->text('api_key')->nullable();
            $table->string('carrier', 50)->default('random');
            $table->string('province_code', 20)->default('0');
            $table->string('whitelist', 1000)->nullable();
            $table->text('notes')->nullable();
            $table->string('last_proxy_http', 255)->nullable();
            $table->string('last_proxy_socks5', 255)->nullable();
            $table->string('last_network', 100)->nullable();
            $table->string('last_location', 100)->nullable();
            $table->unsignedInteger('last_expires_in_seconds')->nullable();
            $table->timestamp('last_resolved_at')->nullable();
            $table->string('last_error_message', 1000)->nullable();
            $table->json('last_provider_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_settings');
    }
};
